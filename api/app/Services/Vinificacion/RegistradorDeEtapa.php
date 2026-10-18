<?php

declare(strict_types=1);

namespace App\Services\Vinificacion;

use App\Enums\EtapaVinificacion;
use App\Enums\EstadoLote;
use App\Exceptions\ErrorDeVina;
use App\Models\EtapaVinificacion as ModeloEtapa;
use App\Models\Lote;
use App\Models\Usuario;
use App\Models\Vendimia;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Registrar las etapas de vinificacion de un lote.
 *
 * LA REGLA QUE NO SE ROMPE
 * ------------------------
 * Las etapas se AGREGAN en orden. Nunca se editan y nunca se borran.
 *
 * "Un lote mal criado no se puede deshacer" no es una frase de este sistema:
 * es la razon por la que existe la tabla `etapas_vinificacion` con un gatillo
 * que revienta cualquier `UPDATE` o `DELETE`. Aqui solo se hace `INSERT`.
 *
 * QUE PUEDE Y QUE NO PUEDE SALTAR
 * --------------------------------
 * Una etapa no se puede registrar si la anterior no esta, y eso lo decide
 * `EtapaVinificacion::siguiente()`, no esta clase. Que la secuencia este en
 * el codigo y no en la base es lo que hace que nadie pueda saltar de
 * `fermentacion` a `embotellado` con una llamada a la API.
 *
 * Las etapas OPCIONALES (`barriqueado`, `crianzas`) son la excepcion
 * consciente: un vino joven no pasa por barrica y es un vino legitimo. El
 * sistema permite saltarlas, pero solo si la etapa siguiente se puede
 * registrar de verdad.
 *
 * Ver docs/02-MODELO-DE-DATOS.md.
 */
class RegistradorDeEtapa
{
    /**
     * Registra una etapa del proceso de un lote.
     *
     * TODO PASA EN UNA SOLA TRANSACCION
     * ---------------------------------
     * Si la etapa se escribiera y el estado del lote no llegara a cambiar, o
     * al reves, el lote podria mostrar una etapa que no ocurrio o quedar en
     * `en_vinificacion` con el proceso ya terminado. Por eso el metodo abre
     * su propia transaccion y escribe las dos cosas o no escribe ninguna.
     *
     * @param  array{
     *     etapa: string,
     *     fecha?: string|null,
     *     responsable_id?: string|null,
     *     notas?: string|null
     * }  $datos
     */
    public function registrar(Lote $lote, array $datos, ?Usuario $quien = null): ModeloEtapa
    {
        if ($lote->estaCerrado()) {
            throw ErrorDeVina::loteCerrado($lote->codigo);
        }

        $etapa = EtapaVinificacion::from($datos['etapa']);

        // Una etapa, una vez. El indice unico de la base tambien lo impide,
        // pero aqui el error es de proceso y merece un mensaje que diga cual.
        $yaEsta = ModeloEtapa::query()
            ->where('lote_id', $lote->id)
            ->where('etapa', $etapa->value)
            ->exists();

        if ($yaEsta) {
            throw ErrorDeVina::etapaYaRegistrada($etapa->etiqueta());
        }

        $esperada = $this->siguienteEtapaDe($lote);

        // Solo se aceptan la siguiente obligatoria o una opcional que este
        // disponible. Todo lo demas se rechaza. Ver `esEtapaValida`.
        if (! $this->esEtapaValida($lote, $etapa)) {
            // El mensaje dice cual era la etapa que si correspondia. Un error
            // de proceso se corrige leyendo el mensaje, no adivinando.
            [$pedida, $esperada] = $this->mensajeDeDesvio($lote, $etapa);

            throw ErrorDeVina::etapaFueraDeOrden($pedida, $esperada);
        }

        $fecha = isset($datos['fecha']) && $datos['fecha'] !== null
            ? Carbon::parse($datos['fecha'])
            : Carbon::today();

        // Una etapa no puede tener una fecha que todavia no ocurrio. Sin este
        // control se podria "registrar" una clarificacion con fecha de manana
        // y el historial temporal del lote dejaria de ser real.
        if ($fecha->isFuture()) {
            throw new ErrorDeVina(
                'No se puede registrar una etapa con fecha de futuro.',
                'fecha_de_etapa_futura'
            );
        }

        return DB::transaction(function () use ($lote, $etapa, $fecha, $datos, $quien): ModeloEtapa {
            $registro = ModeloEtapa::query()->create([
                'tenant_id' => $lote->tenant_id,
                'lote_id' => $lote->id,
                'etapa' => $etapa,
                'fecha' => $fecha->toDateString(),
                'responsable_id' => $datos['responsable_id'] ?? $quien?->id,
                'notas' => $datos['notas'] ?? null,
            ]);

            // El estado del lote acompana al proceso. Se recalcula desde las
            // etapas que ya existen, no desde el numero de etapas contadas: la
            // etapa que define el estado es la ultima en la SECUENCIA, no la
            // ultima por fecha de registro.
            $lote->estado = $this->estadoSegunEtapas($lote, $etapa);
            $lote->save();

            return $registro;
        });
    }

    /**
     * Crea el lote a partir de una vendimia.
     *
     * ESTA ES LA TRAZABILIDAD EN SU ORIGEN
     * ------------------------------------
     * Un lote nace SIEMPRE de una vendimia, y la vendimia se marca como
     * `en_bodega` en la misma transaccion. Si esas dos cosas no ocurrieran
     * juntas, la base podria tener una vendimia que ya esta en la bodega y
     * todavia sin lote, y nadie sabria si se perdio o se esta procesando.
     *
     * El codigo del lote lleva la parcela y el ano de la vendimia: `LT-CB-2026-001`.
     * Va con esos datos porque es lo que permite reconstruir el origen leyendo
     * la etiqueta, sin consultar la base. Ver `codigoDeLote`.
     *
     * @param  array{litros?: float|null, nombre?: string|null, notas?: string|null}  $datos
     */
    public function abrirLoteDesde(Vendimia $vendimia, array $datos = [], ?Usuario $quien = null): Lote
    {
        if ($vendimia->estado->estaCerrada()) {
            throw ErrorDeVina::vendimiaDescartada($vendimia->numero);
        }

        // Una vendimia, un lote. Si ya tiene, no se abre otro: la uva de esa
        // vendimia esta en el primer lote, y decir lo contrario seria perder
        // la trazabilidad.
        if ($vendimia->yaFueVinificada()) {
            throw ErrorDeVina::vendimiaYaVinificada($vendimia->numero);
        }

        return DB::transaction(function () use ($vendimia, $datos, $quien): Lote {
            $parcela = $vendimia->parcela()->firstOrFail();

            $lote = Lote::query()->create([
                'tenant_id' => $vendimia->tenant_id,
                'vendimia_id' => $vendimia->id,
                'codigo' => $this->codigoDeLote($parcela->codigo, (int) $vendimia->fecha_vendimia->format('Y')),
                'anio' => (int) $vendimia->fecha_vendimia->format('Y'),
                'nombre' => $datos['nombre'] ?? null,
                'estado' => EstadoLote::RECEPCION,
                // Los litros iniciales son los kilos cosechados: el mosto de
                // un kilo de uva ronda un litro. Es una APROXIMACION
                // declarada, no una medida. Si la bodega pesa el mosto despues
                // en la decantadora, se corrige con la etapa de destaje.
                'litros' => (float) $vendimia->kilos,
            ]);

            // La vendimia pasa a bodega en la MISMA transaccion. Ver la nota
            // de arriba sobre por que.
            $vendimia->estado = \App\Enums\EstadoVendimia::EN_BODEGA;
            $vendimia->save();

            // La recepcion de la uva es la primera etapa y se registra sola:
            // el hecho de abrir el lote ES el ingreso de la uva al lagar.
            $this->registrar($lote, [
                'etapa' => EtapaVinificacion::RECEPCION_UVA->value,
                'fecha' => $vendimia->fecha_vendimia->toDateString(),
                'responsable_id' => $vendimia->responsable_id,
                'notas' => $datos['notas'] ?? null,
            ], $quien);

            return $lote->fresh(['vendimia.parcela']);
        });
    }

    /**
     * La etapa obligatoria que sigue, segun lo que el lote ya tiene.
     *
     * Son las OBLIGATORIAS las que marcan el avance, y no la secuencia
     * completa. La razon es `barriqueado` y `crianzas`: son opcionales, y si
     * la secuencia las tomara como paso siguiente, un lote estabilizado
     * quedaria bloqueado esperando una barrica que ese vino jamas va a
     * tener. Un vino joven no pasa por barrica y es un vino legitimo.
     *
     * Devuelve `null` cuando el lote ya completo todo lo obligatorio. No es
     * un error: significa que ya no queda nada que el proceso exija.
     */
    public function siguienteEtapaDe(Lote $lote): ?EtapaVinificacion
    {
        $registradas = $this->etapasRegistradas($lote);

        foreach (EtapaVinificacion::secuencia() as $etapa) {
            if (! $etapa->esObligatoria()) {
                continue;
            }

            if (in_array($etapa, $registradas, true)) {
                continue;
            }

            return $etapa;
        }

        return null;
    }

    /**
     * Las etapas OPCIONALES que el lote todavia puede tomar.
     *
     * Se ofrecen despues de la ultima etapa obligatoria registrada: no tiene
     * sentido meter una guarda en barrica antes de estabilizar. Ver docs/02.
     *
     * @return array<int, EtapaVinificacion>
     */
    public function etapasOpcionalesDisponibles(Lote $lote): array
    {
        $registradas = $this->etapasRegistradas($lote);
        $ultimaObligatoria = $this->ultimaEtapaObligatoria($registradas);

        if ($ultimaObligatoria === null) {
            // Todavia no se hizo nada obligatorio: no se ofrece nada.
            return [];
        }

        $posicionUltima = $this->posicionDe($ultimaObligatoria);

        return array_values(array_filter(
            EtapaVinificacion::secuencia(),
            fn (EtapaVinificacion $e): bool => ! $e->esObligatoria()
                && $this->posicionDe($e) > $posicionUltima
                && ! in_array($e, $registradas, true)
        ));
    }

    /**
     * `true` si la etapa que se quiere registrar es razonable para este lote.
     *
     * Acepta DOS cosas, y solo dos:
     *
     *  - La siguiente obligatoria. Es el camino normal.
     *  - Una opcional que este disponible. Que es cuando el enologo decide
     *    hacer la guarda en barrica o las crianzas.
     *
     * Todo lo demas se rechaza, que es justamente lo que impide que un lote
     * mal criado se "arregle" saltandose etapas en la base.
     */
    private function esEtapaValida(Lote $lote, EtapaVinificacion $pedida): bool
    {
        $siguiente = $this->siguienteEtapaDe($lote);

        if ($pedida === $siguiente) {
            return true;
        }

        return in_array($pedida, $this->etapasOpcionalesDisponibles($lote), true);
    }

    /**
     * Que se le dice cuando la etapa no corresponde.
     *
     * Si el lote tiene una etapa opcional disponible, el mensaje la ofrece:
     * "este lote va en barriqueado" seria verdad solo a medias, y dejaria
     * al enologo creyendo que el proceso se trabo.
     *
     * @return array{0: string, 1: string}
     */
    private function mensajeDeDesvio(Lote $lote, EtapaVinificacion $pedida): array
    {
        $siguiente = $this->siguienteEtapaDe($lote);
        $opcionales = $this->etapasOpcionalesDisponibles($lote);

        if ($siguiente === null && $opcionales !== []) {
            return [
                $pedida->etiqueta(),
                'sin etapa obligatoria pendiente; puede tomar '
                    . implode(' o ', array_map(
                        fn (EtapaVinificacion $e): string => $e->etiqueta(),
                        $opcionales
                    )),
            ];
        }

        return [
            $pedida->etiqueta(),
            $siguiente?->etiqueta() ?? 'ninguna etapa pendiente',
        ];
    }

    /**
     * @return array<int, EtapaVinificacion>
     */
    private function etapasRegistradas(Lote $lote): array
    {
        return ModeloEtapa::query()
            ->where('lote_id', $lote->id)
            ->pluck('etapa')
            ->map(fn ($e) => EtapaVinificacion::from($e))
            ->all();
    }

    /**
     * @param  array<int, EtapaVinificacion>  $registradas
     */
    private function ultimaEtapaObligatoria(array $registradas): ?EtapaVinificacion
    {
        $posicion = -1;
        $ultima = null;

        foreach ($registradas as $etapa) {
            if (! $etapa->esObligatoria()) {
                continue;
            }

            $actual = $this->posicionDe($etapa);

            if ($actual > $posicion) {
                $posicion = $actual;
                $ultima = $etapa;
            }
        }

        return $ultima;
    }

    /** La posicion de una etapa dentro de la secuencia del proceso. */
    private function posicionDe(EtapaVinificacion $etapa): int
    {
        return array_search($etapa, EtapaVinificacion::secuencia(), true);
    }

    /**
     * El estado del lote a partir de la etapa que acaba de registrarse.
     *
     * POR QUE NO SE USA "LA ULTIMA ETAPA" SINO EL ESTADO MAS AVANZADO
     * ---------------------------------------------------------------
     * El estado NO es "donde quedo el lote" sino "cuanto del proceso
     * obligatorio tiene hecho". Por eso se mira si el lote YA paso la
     * estabilizacion, y no si la ultima etapa registrada fue esa:
     *
     *  - Un lote que pasa `estabilizacion` y despues `barriqueado` sigue
     *    estabilizado. No vuelve a `en_vinificacion` porque haya pasado quince
     *    meses en barrica: ya era embotellable y lo sigue siendo.
     *
     *  - Un lote al que se le registra `embotellado` queda embotellado.
     *
     * Ojo con lo que pasa si esto se escribiera mal: si `ESTABILIZADO` nunca
     * se asignara, `EstadoLote::puedeEmbotellarse()` (que solo es `true` en
     * `ESTABILIZADO`) rechazaria todos los embotellados, y el producto entero
     * no tendria salida. Ver `LoteController::embotellar()`.
     */
    private function estadoSegunEtapas(Lote $lote, EtapaVinificacion $ultima): EstadoLote
    {
        if ($ultima === EtapaVinificacion::EMBOTELLADO) {
            return EstadoLote::EMBOTELLADO;
        }

        // Una vez estabilizado, se queda estabilizado: las etapas que vengan
        // despues (barrica, crianzas) no lo hacen retroceder.
        $registradas = $this->etapasRegistradas($lote);

        if (in_array(EtapaVinificacion::ESTABILIZACION, $registradas, true)) {
            return EstadoLote::ESTABILIZADO;
        }

        return EstadoLote::EN_VINIFICACION;
    }

    /**
     * `LT-<parcela>-<anio>-<consecutivo>`.
     *
     * Va con el codigo de la parcela y el anio, no con un correlativo suelto,
     * porque es lo que permite reconstruir el origen leyendo el codigo. Es
     * el mismo codigo de parcela que esta en la vendimia, asi que no hay dos
     * formas de nombrar el mismo vino.
     */
    private function codigoDeLote(string $codigoParcela, int $anio): string
    {
        $prefijo = sprintf('LT-%s-%d-', $codigoParcela, $anio);

        $ultimo = Lote::query()
            ->where('codigo', 'like', $prefijo . '%')
            ->orderByDesc('codigo')
            ->value('codigo');

        $consecutivo = $ultimo === null ? 1 : ((int) substr($ultimo, strlen($prefijo))) + 1;

        return $prefijo . str_pad((string) $consecutivo, 3, '0', STR_PAD_LEFT);
    }
}