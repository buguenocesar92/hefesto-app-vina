<?php

declare(strict_types=1);

use App\Models\Lote;
use App\Models\Parcela;
use App\Models\Vendimia;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tareas programadas
|--------------------------------------------------------------------------
|
| Se ejecutan dentro del contenedor de la API (`schedule:work`), no por cron
| del sistema. Ver docs/04.
|
*/

// Un resumen de la temporada, una vez al dia. Avisa cuantas parcelas quedan
// sin vendimiar y cuantos lotes llevan mucho tiempo parados en una etapa.
//
// Avisa, NO bloquea: un lote con una guarda larga en barrica es una operacion
// legitima, y el sistema informa en vez de decidir que esta mal.
Schedule::call(function (): void {
    $dias = (int) config('vina.vinificacion.dias_aviso_sin_actividad');

    if ($dias <= 0) {
        return;
    }

    $parcelas = Parcela::query()->where('activo', true)->pluck('id');

    $lotesParados = Lote::query()
        ->whereIn('estado', ['en_vinificacion', 'estabilizado'])
        ->where('updated_at', '<=', now()->subDays($dias))
        ->count();

    $vendimiasDelAnio = Vendimia::query()
        ->whereYear('fecha_vendimia', now()->year)
        ->whereIn('parcela_id', $parcelas)
        ->count();

    logger()->info('resumen de la temporada', [
        'parcelas_activas' => $parcelas->count(),
        'vendimias_del_ano' => $vendimiasDelAnio,
        'lotes_sin_actividad' => $lotesParados,
    ]);
})->dailyAt('07:00')->name('resumen-de-la-temporada');