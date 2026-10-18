<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Parcela;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Las parcelas del viñedo.
 *
 * La parcela es el ORIGEN de toda la trazabilidad: si el nombre o la variedad
 * estan mal, todo el vino que salga de ahi quedara mal identificado. Por eso
 * editarlas no es un detalle de inventario.
 */
class ParcelaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Parcela::class);

        $parcelas = Parcela::query()
            ->withCount('vendimias')
            ->when($request->query('variedad'), fn ($q, $v) => $q->where('variedad', $v))
            ->when($request->query('activas') === '1', fn ($q) => $q->where('activo', true))
            ->orderBy('codigo')
            ->get();

        return response()->json([
            'parcelas' => $parcelas->map(fn ($p) => $this->fila($p))->all(),
            // Las variedades que hay cargadas. Sirve para el filtro y para
            // saber que se puede sembrar.
            'variedades' => Parcela::query()
                ->where('tenant_id', $request->user()->tenant_id)
                ->distinct()
                ->orderBy('variedad')
                ->pluck('variedad')
                ->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Parcela::class);

        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:32'],
            'nombre' => ['required', 'string', 'max:160'],
            'variedad' => ['required', 'string', 'max:80'],
            'superficie_hectareas' => ['nullable', 'numeric', 'gt:0'],
            'ano_plantacion' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'comuna' => ['nullable', 'string', 'max:120'],
        ]);

        $parcela = Parcela::query()->create($datos + [
            'tenant_id' => $request->user()->tenant_id,
            'activo' => true,
        ]);

        return response()->json(['parcela' => $this->fila($parcela)], 201);
    }

    public function show(Parcela $parcela): JsonResponse
    {
        $this->authorize('view', $parcela);

        return response()->json(['parcela' => $this->fila($parcela->loadCount('vendimias'))]);
    }

    /**
     * Editar una parcela.
     *
     * El nombre y la variedad SI se pueden corregir: corregirlos antes de que
     * haya vendimias es legitimo. Lo que no se puede es cambiarlos cuando ya
     * hay vino formado, y eso lo protege el historial de vendimias, no esta
     * pantalla.
     */
    public function update(Request $request, Parcela $parcela): JsonResponse
    {
        $this->authorize('update', $parcela);

        $datos = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:160'],
            'variedad' => ['sometimes', 'string', 'max:80'],
            'superficie_hectareas' => ['nullable', 'numeric', 'gt:0'],
            'ano_plantacion' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'comuna' => ['nullable', 'string', 'max:120'],
        ]);

        $parcela->update($datos);

        return response()->json(['parcela' => $this->fila($parcela->fresh())]);
    }

    /**
     * Desactivar una parcela.
     *
     * NO SE BORRA: una parcela con vendimias no se borra, porque sus vendimias
     * son el origen de lotes que talvez ya se vendieron. Se desactiva y deja
     * de ofrecerse al vendimiar, pero su historial queda.
     */
    public function desactivar(Request $request, Parcela $parcela): JsonResponse
    {
        $this->authorize('update', $parcela);

        $parcela->activo = false;
        $parcela->save();

        return response()->json(['parcela' => $this->fila($parcela->fresh())]);
    }

    /** @return array<string, mixed> */
    private function fila(Parcela $parcela): array
    {
        return [
            'id' => $parcela->id,
            'codigo' => $parcela->codigo,
            'nombre' => $parcela->nombre,
            'variedad' => $parcela->variedad,
            'superficie_hectareas' => $parcela->superficie_hectareas,
            'ano_plantacion' => $parcela->ano_plantacion,
            'comuna' => $parcela->comuna,
            'activo' => $parcela->activo,
            'vendimias' => $parcela->relationLoaded('vendimias')
                ? $parcela->vendimias->count()
                : ($parcela->vendimias_count ?? null),
        ];
    }
}