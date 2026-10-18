<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\ErrorDeVina;
use App\Models\Tenant;
use App\Models\Usuario;
use Illuminate\Support\Facades\Log;

/**
 * Cruza la identidad de Authentik con la persona de la viña.
 *
 * LA REGLA QUE DEFINE A QUE VIÑA PERTENECE LA CUENTA
 * --------------------------------------------------
 * Por el `slug` que la persona eligio en la pantalla de acceso.
 *
 * Ese `slug` viaja en la sesion desde que se elige hasta que vuelve el
 * callback, y es el unico dato de empresa que existe antes de autenticarse.
 * El correo de Authentik NO decide la viña: con dominio compartido
 * ("@gmail.com") no sirve, y ademas seria adivinar.
 *
 * Que nadie tenga cuenta con ese correo significa que la persona entra al
 * sistema y ve una lista de viñas, en vez de entrar en la primera que se
 * parezca. Eso es informacion de mas, no un problema.
 */
class VinculadorDeIdentidad
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public function vincular(array $claims, ?string $slugVina): Usuario
    {
        $correo = strtolower(trim((string) ($claims['email'] ?? '')));

        if ($correo === '') {
            throw new ErrorDeVina(
                'El proveedor de identidad no devolvio el correo.',
                'correo_ausente'
            );
        }

        if (! empty($claims['email_verified']) && $claims['email_verified'] === false) {
            // Un correo sin verificar puede ser de cualquiera. Con una viña
            // que vende vino embotellado, no alcanza.
            throw new ErrorDeVina(
                'Tu correo no esta verificado en el proveedor de identidad.',
                'correo_no_verificado'
            );
        }

        $vina = $this->resolverVina($slugVina, $correo);

        $usuario = Usuario::query()
            ->where('tenant_id', $vina->id)
            ->where('email', $correo)
            ->first();

        if ($usuario === null) {
            Log::info('Acceso rechazado: la cuenta no existe en esa viña', [
                'correo' => $correo,
                'vina' => $vina->slug,
            ]);

            throw new ErrorDeVina(
                'No tienes una cuenta en esa viña. Pide al encargado que te cree el acceso.',
                'cuenta_no_existe'
            );
        }

        if (! $usuario->activo) {
            throw new ErrorDeVina(
                'Tu cuenta esta desactivada.',
                'cuenta_desactivada'
            );
        }

        return $usuario;
    }

    /**
     * Con que viña se entra.
     *
     * Si la persona eligio una, esa manda. Si no eligio ninguna, se buscan
     * todas las viñas donde ese correo tenga cuenta: si hay mas de una, la
     * eleccion tiene que ser explicita.
     */
    private function resolverVina(?string $slug, string $correo): Tenant
    {
        if ($slug !== null && $slug !== '') {
            $vina = Tenant::buscarPorSlug($slug);

            if ($vina === null || ! $vina->activo) {
                throw new ErrorDeVina(
                    'Esa viña no esta disponible.',
                    'vina_no_disponible'
                );
            }

            return $vina;
        }

        // Sin eleccion: se resuelve por el correo, que es unico dentro de
        // cada viña.
        $candidatas = Tenant::query()
            ->where('activo', true)
            ->whereHas('usuarios', fn ($q) => $q->where('email', $correo))
            ->get();

        if ($candidatas->count() === 1) {
            return $candidatas->first();
        }

        if ($candidatas->count() === 0) {
            throw new ErrorDeVina(
                'Tu correo no tiene acceso a ninguna viña.',
                'sin_acceso'
            );
        }

        throw new ErrorDeVina(
            'Tu correo tiene cuentas en varias viñas. Elige con cual quieres entrar.',
            'varias_vinas'
        );
    }
}