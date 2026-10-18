<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Embotellado;
use App\Models\Usuario;

/**
 * Embotellados: el ultimo eslabon.
 *
 * Ver un embotellado ES ver la trazabilidad, porque el embotellado lleva al
 * lote, a la vendimia y a la parcela. Por eso TODOS pueden verlo: no hay
 * razon para que quien esta en la bodega no pueda preguntar de donde salio
 * el vino que esta probando.
 */
class EmbotelladoPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Embotellado $embotellado): bool
    {
        return true;
    }
}