<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoVendimia;
use App\Models\Parcela;
use App\Models\Tenant;
use App\Models\Vendimia;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Vendimia>
 */
class VendimiaFactory extends Factory
{
    protected $model = Vendimia::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => Tenant::factory(),
            'parcela_id' => Parcela::factory(),
            'numero' => 'VEN-' . strtoupper($this->faker->unique()->lexify('???')) . '-001',
            'fecha_vendimia' => now()->subMonths(8)->toDateString(),
            'kilos' => $this->faker->randomFloat(2, 200, 5000),
            // El analisis va en la mitad de las vendimias. Lo demas queda en
            // NULL, que es lo que pasa en la realidad: no todos los blocks se
            // analizan todos los dias.
            'brix' => $this->faker->boolean(50)
                ? $this->faker->randomFloat(1, 18, 28)
                : null,
            'acidez' => $this->faker->boolean(50)
                ? $this->faker->randomFloat(2, 4, 8)
                : null,
            'estado' => EstadoVendimia::COSECHADA->value,
            'notas' => null,
        ];
    }

    public function de(Tenant $vina): static
    {
        return $this->state(fn (): array => ['tenant_id' => $vina->id]);
    }

    public function en(Parcela $parcela): static
    {
        return $this->state(fn (): array => ['parcela_id' => $parcela->id]);
    }

    /** Sin analisis: el brix y la acidez quedan en NULL, no en cero. */
    public function sinAnalisis(): static
    {
        return $this->state(fn (): array => ['brix' => null, 'acidez' => null]);
    }

    public function deHoy(): static
    {
        return $this->state(fn (): array => ['fecha_vendimia' => now()->toDateString()]);
    }

    public function descartada(): static
    {
        return $this->state(fn (): array => ['estado' => EstadoVendimia::DESCARTADA->value]);
    }
}