<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Usuario;
use App\Models\Venta;

/**
 * Ventas.
 *
 * `piso` cobra y anula su propia venta. Anular una venta ajena no: si cualquiera
 * pudiera anular lo que otro vendio, el cierre de caja no diria nada, porque
 * dependeria de quien tiene el turno.
 *
 * La separacion real esta en `anular`: el piso cierra su caja, el encargado
 * deshace lo que quedo mal.
 */
class VentaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Venta $venta): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeVender();
    }

    /** Cobrar. */
    public function pagar(Usuario $usuario, Venta $venta): bool
    {
        return $usuario->rol->puedeVender();
    }

    /**
     * Anular una venta.
     *
     * Devuelve el vino al embotellado. Lo hace el encargado: es una
     * correccion del registro de caja, no una operacion de mostrador.
     */
    public function anular(Usuario $usuario, Venta $venta): bool
    {
        return $usuario->rol->puedeAnular();
    }
}