<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Parcela;
use App\Models\Usuario;

/**
 * Parcelas: el campo.
 *
 * Ver cualquiera: TODOS ven el campo. El nombre de la parcela y su variedad
 * no son un secreto comercial para el personal de la bodega.
 *
 * Crearlas y editarlas es del encargado: es la cabeza de la cadena de
 * trazabilidad, y un nombre mal escrito se arrastra a todo el vino que salga
 * de esa parcela.
 */
class ParcelaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Parcela $parcela): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeEditarCampo();
    }

    public function update(Usuario $usuario, Parcela $parcela): bool
    {
        return $usuario->rol->puedeEditarCampo();
    }
}