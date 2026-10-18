<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Auth\ClientDeAuthentik;
use App\Services\Auth\VinculadorDeIdentidad;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Acceso, salida y quien soy.
 *
 * LA PANTALLA DE ENTRADA USA LA VISTA, DE VERDAD
 * ----------------------------------------------
 * `vinas()` lee `vw_vinas_publicas`, la vista de TRES columnas que expone
 * solo `slug`, `nombre` y `activo`. Es la compensacion de que la tabla
 * `tenants` no lleve Row Level Security: antes de autenticarse no hay
 * `app.tenant_id`, asi que la lectura previa se hace por la vista.
 *
 * La vista no se creo "por si acaso": el `slug` que devuelve este endpoint
 * viaja en la sesion y es lo que despues resuelve a que viña pertenece la
 * cuenta. Si la vista no existiera, este endpoint no podria funcionar.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly ClientDeAuthentik $authentik,
        private readonly VinculadorDeIdentidad $vinculador,
        private readonly ContextoTenant $contexto,
    ) {}

    /**
     * El selector de viña. PUBLICO, y a proposito.
     *
     * Sin sesion no hay `app.tenant_id`, asi que no se puede leer `tenants`.
     * Lo que se puede leer es la vista publica, que por construccion expone
     * tres columnas y solo viñas activas. Ni el identificador interno, ni el
     * razon social, ni los sellos de tiempo salen por aqui.
     */
    public function vinas(): JsonResponse
    {
        $lista = Tenant::publicasActivas()
            ->map(fn ($fila) => [
                'slug' => $fila->slug,
                'nombre' => $fila->nombre,
            ])
            ->values();

        return response()->json(['vinas' => $lista]);
    }

    /**
     * Parte al proveedor de identidad.
     *
     * El `slug` se valida contra la MISMA vista que alimenta el selector: si
     * no aparece ahi, la viña no existe o esta inactiva, y no se acepta.
     */
    public function entrar(Request $request): RedirectResponse
    {
        $slug = (string) $request->query('empresa', '');

        if ($slug === '') {
            return redirect()->away($this->urlWeb() . '/entrar?error=elige_una_vina');
        }

        $existe = Tenant::publicasActivas()->contains('slug', $slug);

        if (! $existe) {
            return redirect()->away($this->urlWeb() . '/entrar?error=vina_no_disponible');
        }

        // El `state` es un valor aleatorio que viaja de ida y vuelta: es lo
        // que impide que alguien enganche el navegador de otra persona a una
        // sesion ajena. Ver `ClientDeAuthentik`.
        $state = Str::random(40);

        session()->put('auth.state', $state);
        session()->put('auth.vina', $slug);

        return redirect()->away($this->authentik->urlDeAcceso($state, $slug));
    }

    /** Vuelve del proveedor de identidad con el codigo. */
    public function callback(Request $request): RedirectResponse
    {
        $error = $request->query('error');

        if ($error !== null) {
            return redirect()->away($this->urlWeb() . '/entrar?error=' . rawurlencode((string) $error));
        }

        $codigo = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');

        if ($codigo === '' || $state === '') {
            return redirect()->away($this->urlWeb() . '/entrar?error=respuesta_incompleta');
        }

        $tokens = $this->authentik->canjearCodigo($codigo, $state);
        $claims = $this->authentik->verificarToken($tokens['id_token']);

        $usuario = $this->vinculador->vincular($claims, session('auth.vina'));

        session()->forget('auth.vina');

        // `regenerate()` cambia el identificador de la sesion al entrar. Sin
        // esto, un identificador robado antes del acceso sirve despues.
        Auth::login($usuario, true);
        $request->session()->regenerate();

        return redirect()->away($this->urlWeb() . '/');
    }

    public function salir(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->away($this->urlWeb() . '/entrar');
    }

    /** Quien soy. La interfaz lo usa para el menu. */
    public function yo(): JsonResponse
    {
        $usuario = request()->user();

        $respuesta = $usuario->aResumen();
        $respuesta['vina'] = $usuario->tenant?->nombre;

        return response()->json($respuesta);
    }

    /**
     * Diagnostico del aislamiento, para la persona que instala.
     *
     * Dice SI la fila que esta leyendo la ve la sesion aislada, no la que
     * veria una consulta sin contexto. Comparar las dos es la unica forma de
     * comprobar que la RLS esta puesta de verdad.
     */
    public function diagnostico(): JsonResponse
    {
        $usuario = request()->user();

        $contexto = $this->contexto->ejecutarAislado($usuario->tenant_id, function () use ($usuario) {
            return [
                // Lo que ve la sesion normal.
                'parcelas_visibles' => \App\Models\Parcela::query()->count(),
                // Lo que veria una consulta SIN contexto. Tiene que fallar:
                // si devuelve un numero, la RLS no esta puesta.
                'lectura_sin_contexto_falla' => $this->laRlsFallaCerrada(),
            ];
        });

        return response()->json([
            'vina' => $usuario->tenant?->nombre,
            'vina_id' => $usuario->tenant_id,
            'parcelas_visibles' => $contexto['parcelas_visibles'],
            'lectura_sin_contexto_falla' => $contexto['lectura_sin_contexto_falla'],
        ]);
    }

    /**
     * `true` si la lectura sin contexto fue rechazada, que es lo correcto.
     *
     * Se COMPRUEBA de verdad, no de memoria. Se borra la marca de la
     * transaccion y se llama a `app_tenant_id()`: si en algun momento esa
     * funcion dejara de lanzar excepcion, esto devuelve `false` y el
     * diagnostico lo delata en el momento.
     *
     * Ojo: hay que borrar la marca primero. Si se llamara la funcion con la
     * viña puesta, devolveria el identificador y el diagnostico diria "todo
     * bien" sin haber comprobado nada: un falso positivo, que es peor que
     * no tener el diagnostico.
     */
    private function laRlsFallaCerrada(): bool
    {
        try {
            DB::statement("SELECT set_config('app.tenant_id', '', true)");
            DB::selectOne('SELECT public.app_tenant_id()');

            return false;
        } catch (\Throwable) {
            return true;
        }
    }

    private function urlWeb(): string
    {
        // Vive en `config/vina.php` y no en `config/app.php`: el esqueleto de
        // Laravel no trae esa clave, y leer una que no existe devuelve NULL
        // sin avisar, que es la peor forma de fallar.
        return (string) config('vina.web_url');
    }
}