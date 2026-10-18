<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Fija la viña de la peticion para que la Row Level Security sepa de quien
 * son las filas.
 *
 * LA REGLA QUE NO SE ROMPE
 * ------------------------
 * La marca se pone con `set_config('app.tenant_id', ..., true)`. El tercer
 * `true` es `is_local`: la marca vive SOLO dentro de la transaccion y
 * desaparece al terminar.
 *
 * Sin ese `true`, `set_config` escribe en la sesion de PostgreSQL, que
 * sobrevive al `COMMIT`. Con una conexion reutilizable del pool, la peticion
 * siguiente hereda la viña anterior y lee datos de otra bodega sin ningun
 * aviso. Es el error mas grave posible en este sistema y el mas facil de
 * introducir por descuido.
 *
 * Ver docs/03-MULTITENANCY-Y-ROLES.md.
 */
class ContextoTenant
{
    /**
     * El nombre de la marca en PostgreSQL.
     *
     * Va como constante y no como texto suelto en el codigo: la usan el
     * servicio, los gatillos y las pruebas, y si los tres escribieran el
     * nombre a mano, una diferencia de una letra dejaria el aislamiento
     * abierto sin que nadie lo notara.
     */
    public const VARIABLE = 'app.tenant_id';

    public function ejecutarAislado(string $tenantId, callable $operacion): mixed
    {
        /** @var ConnectionInterface $conexion */
        $conexion = DB::connection();

        // Verificar que la viña existe ANTES de abrir la transaccion evita
        // abrirla para nada cuando el identificador vino manipulado.
        $existe = $conexion->table('tenants')->where('id', $tenantId)->exists();

        if (! $existe) {
            abort(404, 'No encontrado.');
        }

        return $conexion->transaction(function () use ($conexion, $tenantId, $operacion): mixed {
            // (is_local = true) es lo que acota la marca a esta transaccion.
            $conexion->statement(
                'SELECT set_config(?, ?, true)',
                [self::VARIABLE, $tenantId]
            );

            return $operacion();
        });
    }
}