<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Lote;
use App\Models\Usuario;

/**
 * La trazabilidad, que comparte su regla con el embotellado.
 *
 * Este archivo no lo invoca Laravel como policy de un modelo: la
 * autorizacion real de estas rutas la hacen `EmbotelladoPolicy` y
 * `TrazabilidadController`. Existe para dejar escrito, en un solo lugar, POR
 * QUE ver la trazabilidad esta permitido a todo el mundo: no hay informacion
 * sensible en el camino de vuelta, y el dato es precisamente el que la viña
 * quiere mostrar.
 */
class TrazabilidadPolicy
{
    /**
     * Ver de donde viene el vino: todos.
     *
     * El nombre de la parcela y la variedad no son un secreto comercial para
     * el personal, y cuando lo son, quien vende vino en la sala ya lo sabe.
     */
    public function ver(Usuario $usuario, Lote $lote): bool
    {
        return true;
    }
}