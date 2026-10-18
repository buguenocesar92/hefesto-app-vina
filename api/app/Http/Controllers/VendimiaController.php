<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EstadoVendimia;
use App\Exceptions\ErrorDeVina;
use App\Http\Requests\RegistrarVendimiaRequest;
use App\Models\Parcela;
use App\Models\Vendimia;
use App\Services\Vinificacion\RegistradorDeEtapa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * El campo: parcelas y vendimias.
 *
 * LA VENDIMIA ES UN HECHO QUE SE REGISTRA
 * ---------------------------------------
 * La fecha de la vendimia es el origen de la trazabilidad de todo el vino que
 * sale de aca. Por eso hay un gatillo en la base que impide cambiarla cuando
 * la vendimia ya tiene un lote: no es una minucia de edicion, es lo que hace
 * que la frase "este vino es de la vendimia del 12 de marzo" valga.
 *
 * Y lo que NO se mide, no se inventa: el brix y la acidez quedan en NULL
 * cuando no hay analisis. Un brix de relleno decidiria cuando se destaja.
 */
class VendimiaController extends Controller
{
    public function __construct(private readonly RegistradorDeEtapa $registrador) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Vendimia::class);

        $vendimias = Vendimia::query()
            ->with('parcela:id,codigo,nombre,variedad')
            ->when($request->query('parcela_id'), fn ($q, $p) => $q->where('parcela_id', $p))
            ->when($request->query('anio'), fn ($q, $a) => $q->whereYear('fecha_vendimia', (int) $a))
            ->when($request->query('estado'), fn ($q, $e) => $q->where('estado', $e))
            ->orderByDesc('fecha_vendimia')
            ->orderBy('numero')
            ->limit(200)
            ->get();

        return response()->json([
            'vendimias' => $vendimias->map(fn ($v) => $this->fila($v))->all(),
        ]);
    }

    /**
     * Registrar una vendimia.
     *
     * Lo que se escribe aca es el origen de todo lo demas. El numero
     * correlativo sale de la parcela y del ano, que es como lo nombra la
     * bodega.
     */
    public function store(RegistrarVendimiaRequest $request): JsonResponse
    {
        $this->authorize('create', Vendimia::class);

        $datos = $request->datos();
        $usuario = $request->user();

        $parcela = Parcela::query()->findOrFail($datos['parcela_id']);

        if (! $parcela->estaActiva()) {
            throw ErrorDeVina::parcelaInvalida();
        }

        $fecha = Carbon::parse($datos['fecha_vendimia']);

        if ($fecha->isFuture()) {
            throw new ErrorDeVina(
                'No se puede registrar una vendimia con fecha de futuro.',
                'fecha_de_vendimia_futura'
            );
        }

        $vendimia = Vendimia::query()->create([
            'tenant_id' => $usuario->tenant_id,
            'parcela_id' => $parcela->id,
            'numero' => $this->numeroDeVendimia($parcela, $fecha),
            'fecha_vendimia' => $fecha->toDateString(),
            'kilos' => $datos['kilos'],
            // NULL cuando no se midio. Un cero seria un dato falso.
            'brix' => $datos['brix'] ?? null,
            'acidez' => $datos['acidez'] ?? null,
            'estado' => EstadoVendimia::COSECHADA,
            'responsable_id' => $usuario->id,
            'notas' => $datos['notas'] ?? null,
        ]);

        return response()->json([
            'vendimia' => $this->fila($vendimia->fresh(['parcela'])),
        ], 201);
    }

    public function show(Vendimia $vendimia): JsonResponse
    {
        $this->authorize('view', $vendimia);

        return response()->json([
            'vendimia' => $this->fila($vendimia->load(['parcela', 'lote'])),
        ]);
    }

    /**
     * Pasar la vendimia a la bodega: se abre el lote.
     *
     * ESTA ES LA TRAZABILIDAD EN SU ORIGEN
     * ------------------------------------
     * La uva deja de estar en el campo y pasa al lagar. Aqui nace el lote, y
     * las dos cosas ocurren en la misma transaccion: si una de las dos
     * fallara, la vendimia quedaria en la bodega sin lote o el lote existira
     * sin que la vendimia se haya movido, y en ninguno de los dos casos
     * alguien sabria que paso con esa uva.
     */
    public function enviarABodega(Request $request, Vendimia $vendimia): JsonResponse
    {
        $this->authorize('enviarBodega', $vendimia);

        $datos = $request->validate([
            'nombre' => ['nullable', 'string', 'max:160'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ]);

        $lote = $this->registrador->abrirLoteDesde(
            $vendimia,
            $datos,
            $request->user()
        );

        return response()->json([
            'lote' => [
                'id' => $lote->id,
                'codigo' => $lote->codigo,
                'nombre' => $lote->nombre,
                'anio' => (int) $lote->anio,
                'estado' => $lote->estado->value,
                'litros' => (float) $lote->litros,
                'linea_de_origen' => $lote->lineaDeOrigen(),
            ],
            'vendimia' => $this->fila($vendimia->fresh(['parcela', 'lote'])),
        ], 201);
    }

    /**
     * Descartar una vendimia: la uva no se usa.
     *
     * Sin motivo obligatorio, por la misma razon que los lotes: una perdida
     * que no dice por que es una perdida que se repite el ano que viene.
     */
    public function descartar(Request $request, Vendimia $vendimia): JsonResponse
    {
        $this->authorize('descartar', $vendimia);

        $datos = $request->validate([
            'motivo' => ['required', 'string', 'max:1000'],
        ]);

        if ($vendimia->yaFueVinificada()) {
            throw ErrorDeVina::vendimiaYaVinificada($vendimia->numero);
        }

        $vendimia->estado = EstadoVendimia::DESCARTADA;
        // El motivo se guarda en las notas, con el motivo explicito al
        // principio: la columna es la unica que hay y el dato no se puede
        // perder.
        $vendimia->notas = trim(
            ($vendimia->notas ?? '') . "\n\nDescartada. Motivo: " . $datos['motivo']
        );
        $vendimia->save();

        return response()->json([
            'vendimia' => $this->fila($vendimia->fresh(['parcela'])),
        ]);
    }

    /** @return array<string, mixed> */
    private function fila(Vendimia $vendimia): array
    {
        return [
            'id' => $vendimia->id,
            'numero' => $vendimia->numero,
            'parcela_id' => $vendimia->parcela_id,
            'parcela' => $vendimia->parcela?->nombre,
            'variedad' => $vendimia->parcela?->variedad,
            'fecha_vendimia' => $vendimia->fecha_vendimia?->toDateString(),
            'kilos' => (float) $vendimia->kilos,
            // `null` aca es INFORMACION, no un dato faltante: significa que
            // no se midio. La interfaz lo tiene que mostrar como tal, porque
            // un brix de cero seria una mentira.
            'brix' => $vendimia->brix,
            'acidez' => $vendimia->acidez,
            'tiene_brix' => $vendimia->brix !== null,
            'tiene_acidez' => $vendimia->acidez !== null,
            'estado' => $vendimia->estado->value,
            'lote_id' => $vendimia->lote?->id,
            'lote_codigo' => $vendimia->lote?->codigo,
            'notas' => $vendimia->notas,
        ];
    }

    /**
     * `VEN-<parcela>-<anio>-<consecutivo>`.
     *
     * Va con el codigo de la parcela porque es el mismo dato que despues
     * aparece en el codigo del lote: no hay dos formas de nombrar la misma
     * vendimia.
     */
    private function numeroDeVendimia(Parcela $parcela, Carbon $fecha): string
    {
        $prefijo = sprintf('VEN-%s-%s-', $parcela->codigo, $fecha->format('Y'));

        $ultimo = Vendimia::query()
            ->where('numero', 'like', $prefijo . '%')
            ->orderByDesc('numero')
            ->value('numero');

        $consecutivo = $ultimo === null ? 1 : ((int) substr($ultimo, strlen($prefijo))) + 1;

        return $prefijo . str_pad((string) $consecutivo, 3, '0', STR_PAD_LEFT);
    }
}