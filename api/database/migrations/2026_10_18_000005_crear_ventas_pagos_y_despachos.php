<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Venta de vino embotellado, pago y despacho.
 *
 * LA TRAZABILIDAD SIGUE VIVA ACA
 * ------------------------------
 * Una `venta_item` no guarda "cuantas botellas" a secas: guarda el
 * `embotellado_id`. Por eso, desde una venta se puede volver hacia atras
 * hasta la parcela y la fecha de vendimia sin que nadie tenga que mantener
 * una columna de procedencia aparte. El camino ya existe en el modelo.
 *
 * Y por eso no se puede vender una cantidad que no venga de un embotellado
 * real: `embotellado_id` es NOT NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ventas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->foreignUuid('vendedor_id')->nullable()->constrained('usuarios')->restrictOnDelete();

            $tabla->string('numero', 32);

            $tabla->decimal('total', 14, 2)->default(0);

            // `abierta`, `pagada`, `anulada`.
            $tabla->string('estado', 16)->default('abierta');

            $tabla->dateTimeTz('abierta_en');
            $tabla->dateTimeTz('cerrada_en')->nullable();

            $tabla->string('cliente_nombre', 160)->nullable();
            $tabla->string('cliente_contacto', 160)->nullable();
            $tabla->text('notas')->nullable();

            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'numero']);
            $tabla->index(['tenant_id', 'estado']);

            $tabla->check('total >= 0');
        });

        Schema::create('venta_items', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->foreignUuid('venta_id')->constrained('ventas')->restrictOnDelete();

            // El eslabon que conserva la trazabilidad dentro de la venta. NO
            // es opcional: una venta sin embotellado es una venta que no se
            // puede auditar hasta su parcela.
            $tabla->foreignUuid('embotellado_id')->constrained('embotellados')->restrictOnDelete();

            $tabla->unsignedInteger('botellas');

            $tabla->decimal('precio_unitario', 12, 2);

            $tabla->timestampsTz();

            $tabla->index(['venta_id']);
            $tabla->index(['embotellado_id']);

            $tabla->check('botellas > 0');
            $tabla->check('precio_unitario >= 0');
        });

        Schema::create('pagos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->foreignUuid('venta_id')->constrained('ventas')->restrictOnDelete();

            // `efectivo`, `tarjeta`, `transferencia`.
            $tabla->string('metodo', 24);

            $tabla->decimal('monto', 14, 2);

            $tabla->dateTimeTz('pagado_en');

            $tabla->timestampsTz();

            $tabla->index(['venta_id']);

            $tabla->check('monto > 0');
            $tabla->check("metodo IN ('efectivo', 'tarjeta', 'transferencia')");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos');
        Schema::dropIfExists('venta_items');
        Schema::dropIfExists('ventas');
    }
};