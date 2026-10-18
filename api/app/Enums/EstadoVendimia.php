<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Que paso con una vendimia.
 *
 * El recorrido es `cosechada` -> `en_bodega`. Son dos estados y no mas porque
 * la vendimia tiene una sola transicion que importa: la uva pasa del campo al
 * lagar. Todo lo que pase despues (destaje, fermentacion) ya no es cosa de
 * la vendimia sino del lote.
 *
 * `descartada` es la uva que se perdio ANTES de entrar: se Descarto en la
 * cuadra porque no estaba en estado, o se dejo para el otro dia. Se registra
 * con su motivo y no se borra, por la misma razon que los lotes: una perdida
 * invisible se repite el ano siguiente.
 */
enum EstadoVendimia: string
{
    /** Se cosecho y esta en la cuadra. */
    case COSECHADA = 'cosechada';

    /** Entro al lagar. Ya se puede crear el lote. */
    case EN_BODEGA = 'en_bodega';

    /** No se uso. Queda con su motivo. */
    case DESCARTADA = 'descartada';

    /** Solo una vendimia cosechada y sin lote puede pasar a bodega. */
    public function admiteLote(): bool
    {
        return $this === self::COSECHADA;
    }

    /** Una vendimia descartada no se procesa. */
    public function estaCerrada(): bool
    {
        return $this === self::DESCARTADA;
    }
}