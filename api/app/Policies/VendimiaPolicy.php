<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Usuario;
use App\Models\Vendimia;

/**
 * Vendimias: el origen de todo el vino.
 *
 * LA SEPARACION QUE IMPORTA
 * -------------------------
 * Quien vendimia registra la fecha y los kilos, que es lo que vio con sus
 * ojos. Quien descarta la uva perdida, que es una declaracion con
 * consecuencia economica y no una medicion.
 *
 * Y NADIE QUE NO SEA DEL ROL CORRECTO TOCA LA FECHA cuando ya hay un lote: de
 * hecho esa fecha es inmutable en la base, con independencia de quien llame.
 * La Policy no es la unica defensa: es la segunda linea.
 */
class VendimiaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Vendimia $vendimia): bool
    {
        return true;
    }

    /** Registrar la cosecha: lo hace quien estuvo en la cuadra. */
    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeVendimiar();
    }

    /**
     * Mandar la uva al lagar y abrir el lote.
     *
     * Es el momento en que el vino empieza a existir, y por eso es del rol
     * que vinifica, no del que vende.
     */
    public function enviarBodega(Usuario $usuario, Vendimia $vendimia): bool
    {
        return $usuario->rol->puedeVinificar();
    }

    /**
     * Descartar una vendimia.
     *
     * No es del rol de bodega: descartar es declarar una perdida, y eso lo
     * decide el encargado, igual que descartar un lote.
     */
    public function descartar(Usuario $usuario, Vendimia $vendimia): bool
    {
        return $usuario->rol->puedeDescartarLote();
    }
}