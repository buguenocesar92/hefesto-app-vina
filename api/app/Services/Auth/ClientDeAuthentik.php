<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\ErrorDeVina;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cliente de Authentik: el proveedor de identidad de Hefesto Studio.
 *
 * QUE HACE ESTA CLASE Y QUE NO
 * ----------------------------
 * Hace tres cosas y ninguna mas: armar la URL de acceso, canjear el codigo
 * por tokens y verificar la firma del token de identidad.
 *
 * NO guarda contrasenas, NO decide roles y NO crea personas. El rol vive en
 * `usuarios.rol`; aca solo se verifica que el token sea autentico. Si esta
 * clase decidiera permisos, un cambio de permisos seria un despliegue.
 *
 * Ver docs/07-SEGURIDAD.md.
 */
class ClientDeAuthentik
{
    public function __construct(private readonly HttpFactory $http) {}

    /**
     * La URL a la que se manda a la persona para entrar.
     *
     * El `slug` de la viña elegida viaja como `login_hint`, que es el
     * parametro estandar de OpenID Connect para que el proveedor sepa a que
     * aplicacion se esta entrando. Se manda ADEMAS en la sesion, porque el
     * `login_hint` es una pista y la sesion es lo que despues resuelve de
     * verdad a que viña pertenece la cuenta.
     */
    public function urlDeAcceso(string $state, string $slugVina): string
    {
        $issuer = $this->issuer();

        $parametros = [
            'client_id' => (string) config('authentik.client_id'),
            'response_type' => 'code',
            'scope' => (string) config('authentik.scopes'),
            'redirect_uri' => (string) config('authentik.redirect_uri'),
            'state' => $state,
            'login_hint' => $slugVina,
        ];

        return $issuer . 'authorize?' . http_build_query($parametros);
    }

    /**
     * Canjea el codigo de autorizacion por tokens.
     *
     * @return array{id_token: string, access_token: string}
     */
    public function canjearCodigo(string $codigo, string $stateRecibido): array
    {
        // El `state` es lo que impide que alguien enganche el navegador a una
        // sesion ajena. Si no calza con lo que se guardo al partir, es un
        // intento de CSRF y se corta aqui.
        if (! hash_equals((string) session('auth.state'), $stateRecibido)) {
            throw new ErrorDeVina(
                'La solicitud de acceso no es valida. Vuelve a entrar desde el inicio.',
                'state_invalido'
            );
        }

        session()->forget('auth.state');

        $respuesta = $this->http
            ->asForm()
            ->timeout(15)
            ->post($this->issuer() . 'token/', [
                'grant_type' => 'authorization_code',
                'code' => $codigo,
                'redirect_uri' => (string) config('authentik.redirect_uri'),
                'client_id' => (string) config('authentik.client_id'),
                'client_secret' => (string) config('authentik.client_secret'),
            ]);

        if ($respuesta->failed()) {
            // El cuerpo puede traer datos del proveedor que no interesa
            // mostrar. Se registra el codigo y se responde un mensaje propio.
            Log::warning('Authentik rechazo el canje del codigo', [
                'estado' => $respuesta->status(),
            ]);

            throw new ErrorDeVina(
                'No se pudo completar el acceso. Intenta nuevamente.',
                'fallo_de_autenticacion'
            );
        }

        $cuerpo = $respuesta->json();

        if (! is_array($cuerpo) || empty($cuerpo['id_token'])) {
            throw new ErrorDeVina(
                'El proveedor de identidad no devolvio una sesion valida.',
                'respuesta_incompleta'
            );
        }

        return [
            'id_token' => (string) $cuerpo['id_token'],
            'access_token' => (string) ($cuerpo['access_token'] ?? ''),
        ];
    }

    /**
     * Verifica el token de identidad y devuelve lo que dice adentro.
     *
     * La firma se verifica contra las claves publicas del proveedor
     * (`jwks.json`), que se cachean. Sin esa verificacion, cualquiera
     * podria inventarse un token con el correo del administrador.
     *
     * @return array<string, mixed>
     */
    public function verificarToken(string $idToken): array
    {
        $issuer = $this->issuer();

        $decodificado = JWT::decode(
            $idToken,
            // `parseKeySet` convierte el jwks en el formato que espera
            // `JWT::decode`. Sin esto, la firma no se podria verificar.
            JWK::parseKeySet($this->clavesPublicas()),
            (string) config('authentik.algoritmo')
        );

        // `JWT::decode` ya valida `exp`, pero no el emisor: un token valido
        // de OTRA aplicacion de Authentik pasaria. Por eso se compara a mano.
        $emisorDelToken = $decodificado->iss ?? null;

        if ($emisorDelToken !== $issuer) {
            Log::warning('Token de identidad de otra aplicacion', [
                'esperado' => $issuer,
                'recibido' => $emisorDelToken,
            ]);

            throw new ErrorDeVina(
                'La sesion no pertenece a esta aplicacion.',
                'emisor_invalido'
            );
        }

        return (array) $decodificado;
    }

    /**
     * Las claves publicas del proveedor, desde la cache.
     *
     * Se cachean porque estan en una URL publica y no cambian seguido: ir a
     * buscarlas en cada entrada le carga el trabajo al proveedor sin ganar
     * nada.
     *
     * @return array<string, mixed>
     */
    private function clavesPublicas(): array
    {
        return Cache::remember('authentik:jwks', now()->addHours(6), function () {
            $respuesta = $this->http->timeout(10)->get($this->issuer() . 'jwks.json');

            if ($respuesta->failed()) {
                throw new ErrorDeVina(
                    'No se pudieron obtener las claves del proveedor de identidad.',
                    'claves_no_disponibles'
                );
            }

            $claves = $respuesta->json();

            if (! is_array($claves)) {
                throw new ErrorDeVina(
                    'El proveedor de identidad devolvio claves ilegibles.',
                    'claves_ilegibles'
                );
            }

            return $claves;
        });
    }

    /** El issuer, con barra final. Sin barra, ningun token calza. */
    private function issuer(): string
    {
        $issuer = rtrim((string) config('authentik.issuer'), '/');

        if ($issuer === '') {
            throw new ErrorDeVina(
                'El proveedor de identidad no esta configurado.',
                'authentik_sin_configurar'
            );
        }

        return $issuer . '/';
    }
}