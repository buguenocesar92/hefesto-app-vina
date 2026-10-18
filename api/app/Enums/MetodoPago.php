<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Como se cobro una venta.
 *
 * Los tres que ve una bodega chica en Chile. La lista es corta a proposito:
 * agregar un metodo de pago que la bodega no usa es inventar una regla.
 */
enum MetodoPago: string
{
    case EFECTIVO = 'efectivo';
    case TARJETA = 'tarjeta';
    case TRANSFERENCIA = 'transferencia';
}