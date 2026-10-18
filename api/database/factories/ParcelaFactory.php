<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Parcela;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Parcela>
 */
class ParcelaFactory extends Factory
{
    protected $model = Parcela::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => Tenant::factory(),
            'codigo' => strtoupper($this->faker->unique()->lexify('???')),
            'nombre' => 'Block ' . $this->faker->lastName(),
            // Las variedades Chileanas que de verdad se plantan.
            'variedad' => $this->faker->randomElement([
                'Cabernet Sauvignon',
                'Carmenere',
                'Merlot',
                'Chardonnay',
                'Sauvignon Blanc',
                'Syrah',
                'Pinot Noir',
                'Malbec',
            ]),
            'superficie_hectareas' => $this->faker->randomFloat(2, 0.5, 25),
            'ano_plantacion' => $this->faker->numberBetween(1985, 2020),
            'comuna' => $this->faker->randomElement([
                'Maipú',
                'Colchagua',
                'Rancagua',
                'Casablanca',
                'Valle Central',
            ]),
            'activo' => true,
        ];
    }

    public function de(Tenant $vina): static
    {
        return $this->state(fn (): array => ['tenant_id' => $vina->id]);
    }

    public function inactiva(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}