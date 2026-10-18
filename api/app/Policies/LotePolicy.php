<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Lote;
use App\Models\Usuario;

/**
 * Lotes: la vinificacion.
 *
 * QUIEN HACE QUE
 * --------------
 * El rol `bodega` registra etapas y embotella: es su trabajo y es su
 * responsabilidad. Pero NO descarta un lote, y la razon es la misma por la que
 * no anula una venta: descartar un lote es declararlo perdido para siempre.
 *
 * Descartar es del encargado. Anular una venta tambien.
 *
 * Ver docs/03-MULTITENANCY-Y-ROLES.md.
 */
class LotePolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Lote $lote): bool
    {
        return true;
    }

    /**
     * Registrar una etapa del proceso.
     *
     * El enologo que esta en la bodega. Es la accion mas frecuente del
     * sistema y la que menos deliberacion lleva: se registra lo que ocurrio.
     */
    public function registrarEtapa(Usuario $usuario, Lote $lote): bool
    {
        return $usuario->rol->puedeVinificar();
    }

    /** Sacar el vino a botella. */
    public function embotellar(Usuario $usuario, Lote $lote): bool
    {
        return $usuario->rol->puedeVinificar();
    }

    /**
     * Descartar el lote.
     *
     * NO es del rol `bodega`: es una decision con consecuencia economica y de
     * registro, y el lote queda marcado como perdido para siempre.
     */
    public function descartar(Usuario $usuario, Lote $lote): bool
    {
        return $usuario->rol->puedeDescartarLote();
    }
}