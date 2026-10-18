<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Punto de entrada de los datos de ejemplo.
 *
 * `php artisan db:seed` corre esto.
 *
 * OJO: los datos que crea son de EJEMPLO. Las parcelas, las vendimias y las
 * cifras de la temporada son inventadas y no describen ninguna viña real.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(VinaEjemploSeeder::class);
    }
}