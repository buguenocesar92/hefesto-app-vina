<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Que paso con una venta de vino.
 *
 * El recorrido normal es `abierta` -> `pagada`. `anulada` es distinto de
 * `pagada`: una venta pagada entrego vino y cobro; una anulada devuelve el
 * vino y no quedo nada. Por eso anular no borra la venta, cambia su estado y
 * deja el registro de que existio.
 */
enum EstadoVenta: string
{
    case ABIERTA = 'abierta';
    case PAGADA = 'pagada';
    case ANULADA = 'anulada';

    public function estaCerrada(): bool
    {
        return in_array($this, [self::PAGADA, self::ANULADA], true);
    }

    /** Solo una venta abierta admite pagos. */
    public function admitePago(): bool
    {
        return $this === self::ABIERTA;
    }
}