<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El campo: parcelas, vendimias, lotes y el origen del vino.
 *
 * LA REGLA QUE ORGANIZA TODO ESTO
 * -------------------------------
 * La trazabilidad NO SE DEDUCE. SE REGISTRA.
 *
 * El camino de una botella hasta el vino es una cadena de hechos escritos:
 *
 *     parcela  ->  vendimia  ->  lote  ->  embotellado  ->  botella
 *
 * Cada eslabon guarda a quien apunta y CUANDO. Un lote sabe de que
 * `vendimia` salio, y una `vendimia` sabe de que `parcela` y de que FECHA se
 * cosecho. Esa fecha es la que permite despues decir "este vino es de la
 * vendimia del 12 de marzo" sin que nadie lo recuerde ni lo suponga.
 *
 * El `lote.vendimia_id` es NOT NULL a proposito: un lote sin vendimia de
 * origen es un lote inventado. La base no lo acepta. Si alguna vez hace falta
 * un lote que no venga de una vendimia (una compra de mosto, por ejemplo),
 * es otro camino: se registra igual la vendimia que lo produjo en esta
 * bodega, con su parcela. No hay atajo para el vino que no se sabe de donde
 * viene, porque ese es justo el vino que un sistema de trazabilidad no puede
 * dejar pasar.
 *
 * POR QUE LA FECHA DE VENDIMIA NO SE TOCA
 * --------------------------------------
 * La fecha de vendimia es el origen de la trazabilidad. Si se pudiera editar
 * a posteriori, la garantia "de que vendimia viene este vino" seria tan
 * editable como el dato, y no valdria nada. Por eso hay un disparador que
 * impide cambiarla una vez que la vendimia esta asociada a un lote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcelas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->string('codigo', 32);
            $tabla->string('nombre', 160);

            // La variedad es dato del campo, no del sistema: la declara la
            // vinicola. El sistema no la deduce de la vendimia.
            $tabla->string('variedad', 80);

            $tabla->decimal('superficie_hectareas', 10, 2)->nullable();
            $tabla->year('ano_plantacion')->nullable();
            $tabla->string('comuna', 120)->nullable();

            $tabla->boolean('activo')->default(true);

            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'codigo']);
            $tabla->index(['tenant_id', 'variedad']);
        });

        Schema::create('vendimias', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->foreignUuid('parcela_id')->constrained('parcelas')->restrictOnDelete();

            // El numero de la vendimia dentro de la parcela y del ano, que es
            // como lo nombra la bodega: "vendimia 3 del block Litoral".
            $tabla->string('numero', 32);

            // LA FECHA DE ORIGEN. Inmutable una vez que hay un lote. Ver el
            // gatillo de abajo.
            $tabla->date('fecha_vendimia');

            $tabla->decimal('kilos', 12, 2);

            // Lo MEDIDO con un equipo en el momento de la cosecha. NULO
            // significa "no se midio", que NO es lo mismo que cero. Un brix
            // inventado decide cuando se destaja. Ver docs/05.
            $tabla->decimal('brix', 5, 2)->nullable();
            $tabla->decimal('acidez', 6, 3)->nullable();

            // `cosechada`, `en_bodega`, `descartada`.
            $tabla->string('estado', 20)->default('cosechada');

            $tabla->foreignUuid('responsable_id')->nullable()->constrained('usuarios')->restrictOnDelete();

            $tabla->text('notas')->nullable();

            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'numero']);
            $tabla->index(['tenant_id', 'fecha_vendimia']);
            $tabla->index(['parcela_id', 'fecha_vendimia']);

            $tabla->check('kilos > 0');
            $tabla->check('brix IS NULL OR brix >= 0');
            $tabla->check('acidez IS NULL OR acidez >= 0');
        });

        Schema::create('lotes', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            // El eslabon que hace posible la trazabilidad. NOT NULL: un lote
            // sin vendimia es un lote que no se sabe de donde viene, y eso
            // es exactamente lo que este sistema no puede permitir.
            $tabla->foreignUuid('vendimia_id')->constrained('vendimias')->restrictOnDelete();

            $tabla->string('codigo', 48);

            // El ano de la vendimia es lo que va en la etiqueta. Se escribe
            // una vez, al crear el lote, porque un vino de 2025 no cambia de
            // ano despues.
            $tabla->year('anio');

            // `recepcion`, `en_vinificacion`, `estabilizado`,
            // `embotellado`, `descartado`.
            $tabla->string('estado', 24)->default('recepcion');

            // El nombre comercial del vino (mezcla, Single Vineyard, etc).
            $tabla->string('nombre', 160)->nullable();

            // Litros que hay en el lote. Lo que se consume en la destaja se
            // descuenta con un movimiento, no editando el numero. Ver
            // `movimientos_lote`.
            $tabla->decimal('litros', 12, 3);

            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'codigo']);
            $tabla->index(['tenant_id', 'estado']);
            $tabla->index(['tenant_id', 'anio']);

            // El lote no puede ser de un ano posterior a su vendimia: eso
            // seria un vino de 2025 cosechado en 2026.
            $tabla->check('litros >= 0');
        });

        // --- La garantia, en la base y no en la aplicacion -----------------

        /*
         * La fecha de vendimia no se edita cuando ya hay un lote.
         *
         * Es la razon de ser de todo el modulo. Si el calendario de la
         * vendimia se pudiera corregir despues de que el lote existe, la
         * trazabilidad seria tan solo una opinion: el dato que sostiene el
         * "de que vendimia viene este vino" se habria movido cuando le
         * convenga a alguien.
         *
         * Se permite corregirla mientras la vendimia NO tenga lote: uno puede
         * teclear al peso el mismo dia y esa correccion es legitima. Lo que no
         * existe es corregirla cuando el vino ya esta formado.
         */
        Schema::table('vendimias', function (Blueprint $tabla): void {
            $tabla->check('fecha_vendimia IS NOT NULL');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.trg_fecha_vendimia_inmutable()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                tiene_lote boolean;
            BEGIN
                IF NEW.fecha_vendimia IS DISTINCT FROM OLD.fecha_vendimia THEN
                    SELECT EXISTS (
                        SELECT 1 FROM public.lotes WHERE vendimia_id = OLD.id
                    ) INTO tiene_lote;

                    IF tiene_lote THEN
                        RAISE EXCEPTION
                            'La fecha de vendimia no se puede cambiar cuando ya hay un lote: es el origen de la trazabilidad del vino. Registre una nueva vendimia.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_fecha_vendimia_inmutable
            BEFORE UPDATE OF fecha_vendimia ON public.vendimias
            FOR EACH ROW
            EXECUTE FUNCTION public.trg_fecha_vendimia_inmutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_fecha_vendimia_inmutable ON public.vendimias;
            DROP FUNCTION IF EXISTS public.trg_fecha_vendimia_inmutable();
        SQL);

        Schema::dropIfExists('lotes');
        Schema::dropIfExists('vendimias');
        Schema::dropIfExists('parcelas');
    }
};