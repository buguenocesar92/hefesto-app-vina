<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EstadoVenta;
use App\Http\Requests\AbrirVentaRequest;
use App\Models\Embotellado;
use App\Models\Venta;
use App\Services\Ventas\RegistradorDeVenta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ventas de vino embotellado.
 *
 * CADA VENTA APUNTA A UN EMBOTELLADO, Y ESO NO ES UN DETALLE
 * --------------------------------------------------------
 * Es lo que permite responder, meses despues, "de que parcela era el vino que
 * se vendio en esta venta". El camino ya existe en el modelo; aqui no se
 * agrega ningun dato de procedencia, que es lo que se desincroniza.
 */
class VentaController extends Controller
{
    public function __construct(private readonly RegistradorDeVenta $registrador) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Venta::class);

        $ventas = Venta::query()
            ->with(['items.embotellado.lote.vendimia.parcela', 'pagos'])
            ->when($request->query('estado'), fn ($q, $e) => $q->where('estado', $e))
            ->when($request->query('abiertas') === '1', fn ($q) => $q->where('estado', EstadoVenta::ABIERTA->value))
            ->orderByDesc('abierta_en')
            ->limit(200)
            ->get();

        return response()->json([
            'ventas' => $ventas->map(fn ($v) => $this->fila($v))->all(),
        ]);
    }

    public function store(AbrirVentaRequest $request): JsonResponse
    {
        $this->authorize('create', Venta::class);

        $venta = $this->registrador->abrir($request->datos(), $request->user());

        return response()->json([
            'venta' => $this->fila($venta),
        ], 201);
    }

    public function show(Venta $venta): JsonResponse
    {
        $this->authorize('view', $venta);

        $venta->load(['items.embotellado.lote.vendimia.parcela', 'pagos']);

        return response()->json([
            'venta' => $this->fila($venta),
            // La trazabilidad de lo vendido. Sale de los relaciones reales:
            // por eso esta en la venta y no en un informe aparte.
            'origenes' => $this->registrador->origenesDe($venta),
        ]);
    }

    /**
     * Las botellas que quedan de un embotellado.
     *
     * Es lo que el mostrador necesita antes de abrir una venta: cuanto hay y
     * de que parcela es.
     */
    public function disponibles(Request $request, Embotellado $embotellado): JsonResponse
    {
        $this->authorize('view', $embotellado);

        return response()->json([
            'embotellado_id' => $embotellado->id,
            'botellas_totales' => (int) $embotellado->botellas,
            'botellas_disponibles' => $this->registrador->disponiblesDe($embotellado),
            'formato_ml' => (int) $embotellado->formato_ml,
            'lote_codigo' => $embotellado->lote?->codigo,
            'linea_de_origen' => $embotellado->lote?->lineaDeOrigen(),
        ]);
    }

    public function pagar(Request $request, Venta $venta): JsonResponse
    {
        $this->authorize('pagar', $venta);

        $datos = $request->validate([
            'metodo' => ['required', 'string', \Illuminate\Validation\Rule::in(['efectivo', 'tarjeta', 'transferencia'])],
            'monto' => ['required', 'numeric', 'gt:0'],
        ]);

        $venta = $this->registrador->cobrar($venta, $datos);

        return response()->json(['venta' => $this->fila($venta)]);
    }

    public function anular(Request $request, Venta $venta): JsonResponse
    {
        $this->authorize('anular', $venta);

        $venta = $this->registrador->anular($venta);

        return response()->json(['venta' => $this->fila($venta)]);
    }

    /** @return array<string, mixed> */
    private function fila(Venta $venta): array
    {
        return [
            'id' => $venta->id,
            'numero' => $venta->numero,
            'estado' => $venta->estado->value,
            'total' => (float) $venta->total,
            'abierta_en' => $venta->abierta_en?->toIso8601String(),
            'cerrada_en' => $venta->cerrada_en?->toIso8601String(),
            'minutos_abierta' => $venta->minutosAbierta(),
            'cliente_nombre' => $venta->cliente_nombre,
            'cliente_contacto' => $venta->cliente_contacto,
            'items' => $venta->relationLoaded('items')
                ? $venta->items->map(fn ($i) => [
                    'embotellado_id' => $i->embotellado_id,
                    'botellas' => (int) $i->botellas,
                    'precio_unitario' => (float) $i->precio_unitario,
                    'subtotal' => $i->subtotal(),
                    'lote_codigo' => $i->embotellado?->lote?->codigo,
                    'linea_de_origen' => $i->embotellado?->lote?->lineaDeOrigen(),
                ])->all()
                : [],
            'pagos' => $venta->relationLoaded('pagos')
                ? $venta->pagos->map(fn ($p) => [
                    'metodo' => $p->metodo->value,
                    'monto' => (float) $p->monto,
                    'pagado_en' => $p->pagado_en?->toIso8601String(),
                ])->all()
                : [],
            'notas' => $venta->notas,
        ];
    }
}