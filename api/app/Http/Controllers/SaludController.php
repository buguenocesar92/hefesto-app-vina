<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * La salud de la API.
 *
 * NO consulta la base a proposito. Esta ruta se usa en el `healthcheck` de
 * Docker, y una consulta a la base haria que un problema de PostgreSQL
 * pareciera un problema de la API: dos cosas distintas con una sola senal.
 *
 * Para comprobar la base esta `vina:verificar-aislamiento`.
 */
class SaludController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'estado' => 'ok',
            'aplicacion' => 'vina',
            'hora' => now()->toIso8601String(),
        ]);
    }
}