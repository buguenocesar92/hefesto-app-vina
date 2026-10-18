<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EstadoLote;
use App\Enums\EtapaVinificacion;
use App\Exceptions\ErrorDeVina;
use App\Models\Embotellado;
use App\Models\Lote;
use App\Services\Vinificacion\RegistradorDeEtapa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Los lotes y el avance de la vinificacion.
 *
 * LO QUE ESTA EN ESTE CONTROLADOR ES LA REGLA DEL PRODUCTO
 * -------------------------------------------------------
 * Las etapas se REGISTRAN en orden y no se tocan. El endpoint `etapas` solo
 * hace `INSERT`: no hay ruta para editar una etapa ni para borrarla, y en la
 * base hay un gatillo que revienta los dos intentos.
 *
 * "Un lote mal criado no se puede deshacer" no es una recomendacion: es lo
 * unico que el sistema sabe hacer con el proceso. Se registra lo que ocurrio,
 * en la fecha en que ocurrio, y eso queda.
 */
class LoteController extends Controller
{
    public function __construct(private readonly RegistradorDeEtapa $registrador) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Lote::class);

        $lotes = Lote::query()
            ->with('vendimia.parcela')
            ->when($request->query('anio'), fn ($q, $a) => $q->where('anio', (int) $a))
            ->when($request->query('estado'), fn ($q, $e) => $q->where('estado', $e))
            ->orderByDesc('anio')
            ->orderBy('codigo')
            ->limit(200)
            ->get();

        return response()->json([
            'lotes' => $lotes->map(fn ($l) => $this->fila($l))->all(),
        ]);
    }

    public function show(Lote $lote): JsonResponse
    {
        $this->authorize('view', $lote);

        return response()->json([
            'lote' => $this->fila($lote->load(['vendimia.parcela', 'etapas.responsable', 'embotellados'])),
        ]);
    }

    /**
     * Registrar una etapa del proceso.
     *
     * La unica forma de avanzar un lote. No acepta `PUT` ni `DELETE`: registrar
     * una etapa es agregar un hecho, no editar una fila.
     */
    public function registrarEtapa(Request $request, Lote $lote): JsonResponse
    {
        $this->authorize('registrarEtapa', $lote);

        $datos = $request->validate([
            'etapa' => ['required', 'string', \Illuminate\Validation\Rule::in(
                array_map(fn (EtapaVinificacion $e) => $e->value, EtapaVinificacion::cases())
            )],
            'fecha' => ['sometimes', 'date'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ]);

        $registro = $this->registrador->registrar(
            $lote,
            $datos,
            $request->user()
        );

        $lote->refresh();

        return response()->json([
            'etapa' => [
                'id' => $registro->id,
                'etapa' => $registro->etapa->value,
                'etiqueta' => $registro->etapa->etiqueta(),
                'fecha' => $registro->fecha?->toDateString(),
                'notas' => $registro->notas,
            ],
            'lote' => $this->fila($lote->fresh(['vendimia.parcela', 'etapas'])),
            // Que sigue ahora. Si viene en `null`, el lote completo todo lo
            // obligatorio: no hay mas etapas que el proceso exija.
            'siguiente_etapa' => $this->nombreDeSiguiente($this->registrador->siguienteEtapaDe($lote)),
            'opcionales_disponibles' => array_map(
                fn (EtapaVinificacion $e) => [
                    'etapa' => $e->value,
                    'etiqueta' => $e->etiqueta(),
                ],
                $this->registrador->etapasOpcionalesDisponibles($lote)
            ),
        ], 201);
    }

    /**
     * Embotellar un lote.
     *
     * Solo un lote ESTABILIZADO sale a botella. La regla la aplica el servicio
     * y la interfaz la muestra: un vino que no esta estabilizado no es vino
     * que se pueda garantizar.
     *
     * Va en una transaccion con la etapa de embotellado: si una de las dos
     * cosas falla, no queda ni un embotellado sin etapa ni una etapa sin
     * botellas.
     */
    public function embotellar(Request $request, Lote $lote): JsonResponse
    {
        $this->authorize('embotellar', $lote);

        $datos = $request->validate([
            'fecha_embotellado' => ['sometimes', 'date'],
            'formato_ml' => ['sometimes', 'integer', \Illuminate\Validation\Rule::in([750, 375, 1500])],
            'botellas' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        if ($lote->estado === EstadoLote::EMBOTELLADO) {
            // El estado se pone solo cuando el lote se embotella, asi que
            // llegar aca significa que ya hubo un embotellado. Sin esta
            // comprobacion, el servicio responderia "la etapa ya esta
            // registrada", que es cierto pero no dice que hacer.
            //
            // Un lote se embotella UNA vez a proposito: la etapa `embotellado`
            // es unica por lote, y por eso un lote no puede sacar dos
            // embotellados con formatos distintos. El camino para mas botellas
            // es abrir otro lote desde otra vendimia, no reembotellar este.
            return response()->json([
                'mensaje' => "El lote {$lote->codigo} ya fue embotellado. Un lote sale a botella una sola vez: para sacar mas botellas, abre otro lote desde otra vendimia.",
                'codigo' => 'lote_ya_embotellado',
            ], 422);
        }

        if (! $lote->estado->puedeEmbotellarse()) {
            throw ErrorDeVina::loteNoEstabilizado($lote->codigo);
        }

        $fecha = isset($datos['fecha_embotellado'])
            ? Carbon::parse($datos['fecha_embotellado'])
            : Carbon::today();

        $embotellado = DB::transaction(function () use ($lote, $datos, $fecha, $request) {
            // La etapa `embotellado` va PRIMERO. Si el `INSERT` del
            // embotellado fallara despues, la transaccion revierte las dos
            // cosas juntas y el lote queda como estaba: sin etapa y sin
            // botellas, que es la realidad de un embotellado que no salio.
            $this->registrador->registrar($lote, [
                'etapa' => EtapaVinificacion::EMBOTELLADO->value,
                'fecha' => $fecha->toDateString(),
                'notas' => 'Embotellado de ' . $datos['botellas'] . ' botella(s).',
            ], $request->user());

            return Embotellado::query()->create([
                'tenant_id' => $lote->tenant_id,
                'lote_id' => $lote->id,
                'fecha_embotellado' => $fecha->toDateString(),
                'formato_ml' => $datos['formato_ml'] ?? 750,
                'botellas' => $datos['botellas'],
            ]);
        });

        return response()->json([
            'embotellado' => [
                'id' => $embotellado->id,
                'fecha_embotellado' => $embotellado->fecha_embotellado?->toDateString(),
                'formato_ml' => (int) $embotellado->formato_ml,
                'botellas' => (int) $embotellado->botellas,
            ],
            'lote' => $this->fila($lote->fresh(['vendimia.parcela', 'etapas', 'embotellados'])),
        ], 201);
    }

    /**
     * Descartar un lote: declararlo perdido.
     *
     * SIN MOTIVO NO SE DESCARTA. Un lote perdido sin razon es una perdida que
     * el ano que viene se vuelve a repetir porque nadie sabe que la produjo.
     *
     * El lote NO SE BORRA: cambia de estado y queda. Un lote mal criado es
     * informacion, no un error que esconder.
     */
    public function descartar(Request $request, Lote $lote): JsonResponse
    {
        $this->authorize('descartar', $lote);

        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        if ($lote->estado === EstadoLote::DESCARTADO) {
            throw ErrorDeVina::estadoInvalido(
                EstadoLote::DESCARTADO->value,
                EstadoLote::DESCARTADO->value
            );
        }

        if ($lote->estado === EstadoLote::EMBOTELLADO) {
            // Un lote embotellado ya esta en el mercado. "Descartarlo" sin
            // mas seria hacer desaparecer vino que ya se vendio.
            return response()->json([
                'mensaje' => 'Este lote ya esta embotellado: el vino salio de la bodega. Anula las ventas en vez de descartar el lote.',
                'codigo' => 'lote_ya_embotellado',
            ], 422);
        }

        $lote->estado = EstadoLote::DESCARTADO;
        // El motivo se guarda en el nombre del lote porque no hay columna
        // propia: el lote queda con la marca y el motivo a la vista.
        $lote->nombre = trim(($lote->nombre ?? $lote->codigo) . ' - DESCARTADO: ' . $datos['motivo']);
        $lote->save();

        return response()->json(['lote' => $this->fila($lote->fresh(['vendimia.parcela', 'etapas']))]);
    }

    /** @return array<string, mixed> */
    private function fila(Lote $lote): array
    {
        return [
            'id' => $lote->id,
            'codigo' => $lote->codigo,
            'nombre' => $lote->nombre,
            'anio' => (int) $lote->anio,
            'estado' => $lote->estado->value,
            'litros' => (float) $lote->litros,
            'vendimia_id' => $lote->vendimia_id,
            'fecha_vendimia' => $lote->vendimia?->fecha_vendimia?->toDateString(),
            'parcela' => $lote->vendimia?->parcela?->nombre,
            'variedad' => $lote->vendimia?->parcela?->variedad,
            // La linea de origen se calcula desde las relaciones reales. Es
            // `null` si la cadena esta rota, y eso hay que mostrarlo.
            'linea_de_origen' => $lote->lineaDeOrigen(),
            'etapas' => $lote->relationLoaded('etapas')
                ? $lote->etapas->map(fn ($e) => [
                    'etapa' => $e->etapa->value,
                    'etiqueta' => $e->etapa->etiqueta(),
                    'fecha' => $e->fecha?->toDateString(),
                    'responsable' => $e->responsable?->nombre,
                    'notas' => $e->notas,
                ])->all()
                : [],
            'embotellados' => $lote->relationLoaded('embotellados')
                ? $lote->embotellados->map(fn ($e) => [
                    'id' => $e->id,
                    'fecha_embotellado' => $e->fecha_embotellado?->toDateString(),
                    'formato_ml' => (int) $e->formato_ml,
                    'botellas' => (int) $e->botellas,
                ])->all()
                : [],
        ];
    }

    /** @return array<string, mixed>|null */
    private function nombreDeSiguiente(?EtapaVinificacion $etapa): ?array
    {
        if ($etapa === null) {
            return null;
        }

        return [
            'etapa' => $etapa->value,
            'etiqueta' => $etapa->etiqueta(),
        ];
    }
}