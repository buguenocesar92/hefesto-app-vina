<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personas que operan la viña.
 *
 * La contrasena y el segundo factor los resuelve Authentik. Aqui solo esta el
 * correo con que se cruza y el rol con que se autoriza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->string('email', 190);
            $tabla->string('nombre', 160);

            // `auditor`, `piso`, `bodega`, `encargado`, `administrador`.
            $tabla->string('rol', 24)->default('bodega');

            $tabla->boolean('activo')->default(true);

            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'email']);
            $tabla->index(['tenant_id', 'rol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};