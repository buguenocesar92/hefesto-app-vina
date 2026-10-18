<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Parcela;
use App\Models\Usuario;
use App\Models\Venta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lo que la viña ajusta: el campo, las parcelas y las personas.
 *
 * Aqui vive el resumen del sistema, que es lo que responde la pregunta de si
 * el ano salio bien: cuantas hectareas se vendimiaron, cuantos kilos
 * entraron, cuantos lotes se hicieron, cuantos se perdieron y cuanto se
 * vendio.
 *
 * Los numeros salen de las filas, no de un resumen guardado. Un resumen
 * guardado se desincroniza del dia que un lote cambia de estado.
 */
class ConfiguracionController extends Controller
{
    public function resumen(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Parcela::class);

        $anio = $request->query('anio', (string) now()->format('Y'));
        $anioNumero = (int) $anio;

        return response()->json([
            'anio' => $anioNumero,
            'campo' => [
                'parcelas_activas' => Parcela::query()->where('activo', true)->count(),
                'variedades' => Parcela::query()->distinct()->count('variedad'),
            ],
            'vendimia' => $this->resumenDeVendimia($anioNumero),
            'lotes' => $this->resumenDeLotes($anioNumero),
            'ventas' => $this->resumenDeVentas($anioNumero),
        ]);
    }

    /** @return array<string, mixed> */
    private function resumenDeVendimia(int $anio): array
    {
        $vendimias = \App\Models\Vendimia::query()
            ->whereYear('fecha_vendimia', $anio)
            ->get(['kilos', 'estado', 'brix', 'acidez']);

        // La produccion media por kilo NO se inventa: se calcula sobre las
        // vendimias que tienen analisis. Las que no lo tienen quedan fuera del
        // promedio y se cuentan aparte, para que se vea cuantas son.
        $conAnalisis = $vendimias->filter(fn ($v) => $v->brix !== null);
        $conAcidez = $vendimias->filter(fn ($v) => $v->acidez !== null);

        return [
            'vendimias' => $vendimias->count(),
            'kilos_totales' => (float) $vendimias->sum('kilos'),
            'descartadas' => $vendimias->where('estado', \App\Enums\EstadoVendimia::DESCARTADA)->count(),
            'con_brix' => $conAnalisis->count(),
            'brix_promedio' => $conAnalisis->isEmpty()
                ? null
                : round((float) $conAnalisis->avg('brix'), 2),
            'con_acidez' => $conAcidez->count(),
            'acidez_promedio' => $conAcidez->isEmpty()
                ? null
                : round((float) $conAcidez->avg('acidez'), 2),
        ];
    }

    /** @return array<string, mixed> */
    private function resumenDeLotes(int $anio): array
    {
        $lotes = \App\Models\Lote::query()->where('anio', $anio)->get();

        $descartados = $lotes->where('estado', \App\Enums\EstadoLote::DESCARTADO);

        return [
            'lotes' => $lotes->count(),
            'embotellados' => $lotes->where('estado', \App\Enums\EstadoLote::EMBOTELLADO)->count(),
            // Los lotes que se perdieron. Que sean visibles es el punto: una
            // perdida invisible se repite el ano que viene.
            'descartados' => $descartados->count(),
            'litros_totales' => (float) $lotes->sum('litros'),
            'litros_perdidos' => (float) $descartados->sum('litros'),
            'botellas_embotelladas' => (int) \App\Models\Embotellado::query()
                ->whereHas('lote', fn ($q) => $q->where('anio', $anio))
                ->sum('botellas'),
        ];
    }

    /** @return array<string, mixed> */
    private function resumenDeVentas(int $anio): array
    {
        $ventas = Venta::query()
            ->whereYear('abierta_en', $anio)
            ->get(['total', 'estado']);

        $pagadas = $ventas->where('estado', \App\Enums\EstadoVenta::PAGADA);

        return [
            'ventas' => $ventas->count(),
            'pagadas' => $pagadas->count(),
            'anuladas' => $ventas->where('estado', \App\Enums\EstadoVenta::ANULADA)->count(),
            'abiertas' => $ventas->where('estado', \App\Enums\EstadoVenta::ABIERTA)->count(),
            // El total vendido es el de las PAGADAS. Sumar tambien las
            // anuladas seria vender vino que no salio.
            'total_vendido' => (float) $pagadas->sum('total'),
        ];
    }

    /** Las personas de la viña con su rol. */
    public function personas(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Usuario::class);

        return response()->json([
            'personas' => Usuario::query()
                ->orderBy('nombre')
                ->get()
                ->map(fn (Usuario $u) => $u->aResumen())
                ->all(),
        ]);
    }

    /**
     * Cambiar el rol de una persona.
     *
     * Es de `administrador` solamente. Un encargado puede mandar a alguien al
     * lagar, pero no darle acceso a la administracion: si pudiera, la
     * separacion entre quien opera y quien administra no existiria.
     */
    public function cambiarRol(Request $request, Usuario $usuario): JsonResponse
    {
        $this->authorize('update', $usuario);

        $datos = $request->validate([
            'rol' => ['required', \Illuminate\Validation\Rule::in(
                array_map(fn ($r) => $r->value, \App\Enums\RolOperador::cases())
            )],
        ]);

        $usuario->rol = \App\Enums\RolOperador::from($datos['rol']);
        $usuario->save();

        return response()->json(['persona' => $usuario->fresh()->aResumen()]);
    }
}