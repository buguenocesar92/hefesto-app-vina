<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RolOperador;
use App\Models\Tenant;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Usuario>
 */
class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => Tenant::factory(),
            'email' => $this->faker->unique()->safeEmail(),
            'nombre' => $this->faker->name(),
            // Por omision, el rol mas restringido que opera. Nadie nace con
            // permisos de encargo.
            'rol' => RolOperador::PISO->value,
            'activo' => true,
        ];
    }

    public function rol(RolOperador $rol): static
    {
        return $this->state(fn (): array => ['rol' => $rol->value]);
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }

    public function de(Tenant $vina): static
    {
        return $this->state(fn (): array => ['tenant_id' => $vina->id]);
    }

    public function administrador(): static
    {
        return $this->rol(RolOperador::ADMINISTRADOR);
    }

    public function encargado(): static
    {
        return $this->rol(RolOperador::ENCARGADO);
    }

    public function bodega(): static
    {
        return $this->rol(RolOperador::BODEGA);
    }

    public function piso(): static
    {
        return $this->rol(RolOperador::PISO);
    }

    public function auditor(): static
    {
        return $this->rol(RolOperador::AUDITOR);
    }
}