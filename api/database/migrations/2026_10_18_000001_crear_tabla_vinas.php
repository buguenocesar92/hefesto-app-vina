<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vinas usuarias del sistema.
 *
 * Esta es la UNICA tabla sin `tenant_id` y sin RLS, a proposito: se consulta
 * durante el acceso, cuando todavia no hay sesion y por lo tanto no hay
 * `app.tenant_id` que poner.
 *
 * Se compensa de dos maneras, y las dos importan:
 *
 *  1. La vista `vw_vinas_publicas` (migracion 000008), que expone solo tres
 *     columnas y solo de vinas activas. La pantalla de acceso la USA de
 *     verdad: ver `AuthController::vinas()`.
 *
 *  2. El rol de la aplicacion no es superusuario ni tiene BYPASSRLS. Con
 *     cualquiera de las dos, PostgreSQL ignora todas las politicas.
 *
 * Ver docs/03-MULTITENANCY-Y-ROLES.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();

            // El `slug` es lo que viaja en el selector del acceso y lo que se
            // guarda en la sesion hasta el callback.
            $tabla->string('slug', 64)->unique();
            $tabla->string('nombre', 160);
            $tabla->string('razon_social', 160)->nullable();
            $tabla->boolean('activo')->default(true);

            $tabla->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};