<?php

declare(strict_types=1);

namespace App\Services\Traza;

use App\Models\Embotellado;
use App\Models\Lote;
use App\Models\Parcela;
use App\Models\Vendimia;
use Illuminate\Support\Collection;

/**
 * El camino de vuelta: de una botella hasta la parcela y la fecha.
 *
 * POR QUE ESTE SERVICIO EXISTE Y NO ES UN REPORTE MAS
 * ---------------------------------------------------
 * La trazabilidad de un vino no es un dato que uno tenga: es un camino de
 * hechos escritos. La pregunta que responde este servicio es la unica que de
 * verdad importa en una bodega chica:
 *
 *     "De que parcela y de que fecha de vendimia es esta botella?"
 *
 * Y hay una regla de fondo, que es la que hace que el sistema valga:
 *
 *     LA TRAZABILIDAD SE LEE, NO SE CALCULA.
 *
 * Accepable seria que el sistema adivinara el origen a partir del codigo del
 * lote, o que reconstruyera el anada con la fecha del embotellado. Las dos
 * cosas seria facile de escribir y las dos estaria equivocadas la mitad
 * de las veces. Aqui nada se deduce: este servicio RECORRE las relaciones que
 * la base ya tiene y las lee. Si el eslabon falta, devuelve `null` y la
 * interfaz dice que no hay trazabilidad, en vez de inventar una.
 *
 * Ver docs/02-MODELO-DE-DATOS.md.
 */
class TrazadorDeVino
{
    /**
     * De donde sale el lote de un embotellado.
     *
     * Devuelve `null` si el embotellado no existe. No lanza excepcion: quien
     * pregunta por la trazabilidad de algo que no existe quiere una respuesta
     * vacia, no un error.
     */
    public function loteDe(Embotellado $embotellado): ?Lote
    {
        return $embotellado->lote()->first();
    }

    /**
     * EL CAMINO COMPLETO, eslabon por eslabon.
     *
     * Devuelve la trazabilidad de un embotellado con TODA la cadena escrita:
     *
     *     embotellado -> lote -> vendimia -> parcela
     *
     * Cada eslabon puede venir en `null`, y esa es la respuesta honesta: si
     * no hay parcela, no hay trazabilidad de parcela. El sistema no completa
     * el hueco con un valor inventado.
     *
     * @return array{
     *     embotellado: array<string, mixed>|null,
     *     lote: array<string, mixed>|null,
     *     vendimia: array<string, mixed>|null,
     *     parcela: array<string, mixed>|null,
     *     completa: bool
     * }
     */
    public function deEmbotellado(Embotellado $embotellado): array
    {
        $lote = $this->loteDe($embotellado);
        $vendimia = $lote?->vendimia()->first();
        $parcela = $vendimia?->parcela()->first();

        return [
            'embotellado' => $this->datosEmbotellado($embotellado),
            'lote' => $lote === null ? null : $this->datosLote($lote),
            'vendimia' => $vendimia === null ? null : $this->datosVendimia($vendimia),
            'parcela' => $parcela === null ? null : $this->datosParcela($parcela),
            // `completa` es la respuesta de una sola pregunta: de este
            // embotellado, se puede decir de que parcela y de que vendimia
            // viene? Si es `false`, hay un eslabon roto y hay que ir a
            // buscarlo antes de decir nada.
            'completa' => $lote !== null && $vendimia !== null && $parcela !== null,
        ];
    }

    /**
     * Un lote y toda su cadena.
     *
     * @return array<string, mixed>
     */
    public function deLote(Lote $lote): array
    {
        $vendimia = $lote->vendimia()->first();
        $parcela = $vendimia?->parcela()->first();

        return [
            'lote' => $this->datosLote($lote),
            'vendimia' => $vendimia === null ? null : $this->datosVendimia($vendimia),
            'parcela' => $parcela === null ? null : $this->datosParcela($parcela),
            'completa' => $vendimia !== null && $parcela !== null,
        ];
    }

    /**
     * Una linea de texto con el origen, para la etiqueta y el despacho.
     *
     * Devuelve `null` si la cadena esta rota, en vez de devolver una frase
     * con huecos. Una etiqueta que dice "Parcela: " sin nombre es peor que
     * una etiqueta que no dice nada: parece que alguien se lo salto.
     */
    public function lineaDeOrigen(Embotellado $embotellado): ?string
    {
        $cadena = $this->deEmbotellado($embotellado);

        if ($cadena['completa'] === false) {
            return null;
        }

        /** @var array<string, mixed> $parcela */
        $parcela = $cadena['parcela'];
        /** @var array<string, mixed> $vendimia */
        $vendimia = $cadena['vendimia'];

        return sprintf(
            '%s - %s, vendimia del %s',
            $parcela['nombre'],
            $parcela['variedad'],
            $vendimia['fecha_vendimia']
        );
    }

    /**
     * Todos los lotes de una parcela en una temporada.
     *
     * Es la pregunta del enologo al empezar la vendimia: "que hizo el ano
     * pasado este bloque?". La respuesta sale de las relaciones reales, no de
     * un resumen guardado que se pueda desincronizar.
     *
     * @return Collection<int, Lote>
     */
    public function lotesDeParcela(Parcela $parcela, ?int $anio = null): Collection
    {
        return Lote::query()
            ->whereHas('vendimia', function ($q) use ($parcela, $anio): void {
                $q->where('parcela_id', $parcela->id);

                if ($anio !== null) {
                    $q->whereYear('fecha_vendimia', $anio);
                }
            })
            ->with('vendimia')
            ->orderByDesc('anio')
            ->get();
    }

    /**
     * Todas las vendimias de una parcela, con su lote si lo tienen.
     *
     * @return Collection<int, Vendimia>
     */
    public function vendimiasDeParcela(Parcela $parcela): Collection
    {
        return Vendimia::query()
            ->where('parcela_id', $parcela->id)
            ->with('lote')
            ->orderByDesc('fecha_vendimia')
            ->get();
    }

    /** @return array<string, mixed> */
    private function datosEmbotellado(Embotellado $embotellado): array
    {
        return [
            'id' => $embotellado->id,
            'fecha_embotellado' => $embotellado->fecha_embotellado?->toDateString(),
            'botellas' => (int) $embotellado->botellas,
            'formato_ml' => (int) $embotellado->formato_ml,
        ];
    }

    /** @return array<string, mixed> */
    private function datosLote(Lote $lote): array
    {
        return [
            'id' => $lote->id,
            'codigo' => $lote->codigo,
            'nombre' => $lote->nombre,
            'anio' => (int) $lote->anio,
            'estado' => $lote->estado->value,
            'litros' => (float) $lote->litros,
        ];
    }

    /** @return array<string, mixed> */
    private function datosVendimia(Vendimia $vendimia): array
    {
        return [
            'id' => $vendimia->id,
            'numero' => $vendimia->numero,
            // ESTA FECHA es la que responde "de que vendimia viene este
            // vino". Es un dato guardado, no el ano del lote ni la fecha del
            // embotellado: son cosas distintas y a veces no coinciden.
            'fecha_vendimia' => $vendimia->fecha_vendimia?->toDateString(),
            'kilos' => (float) $vendimia->kilos,
            'brix' => $vendimia->brix === null ? null : (float) $vendimia->brix,
            'acidez' => $vendimia->acidez === null ? null : (float) $vendimia->acidez,
            'estado' => $vendimia->estado->value,
        ];
    }

    /** @return array<string, mixed> */
    private function datosParcela(Parcela $parcela): array
    {
        return [
            'id' => $parcela->id,
            'codigo' => $parcela->codigo,
            'nombre' => $parcela->nombre,
            'variedad' => $parcela->variedad,
            'comuna' => $parcela->comuna,
            'superficie_hectareas' => $parcela->superficie_hectareas === null
                ? null
                : (float) $parcela->superficie_hectareas,
            'ano_plantacion' => $parcela->ano_plantacion === null
                ? null
                : (int) $parcela->ano_plantacion,
        ];
    }
}