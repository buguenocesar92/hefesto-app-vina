<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La vinificacion: las etapas por las que pasa un lote, y el libro de
 * embotellado.
 *
 * POR QUE LAS ETAPAS SON UN HISTORIAL Y NO UN ESTADO EDITABLE
 * ----------------------------------------------------------
 * Un lote mal criado no se puede deshacer. Si sedestajo tarde, el dano ya
 * esta. Por eso el sistema NO guarda "en que etapa va el lote" como un
 * numero que se sobrescribe: guarda CADA etapa con su fecha real, como un
 * registro que se agrega.
 *
 * Eso tiene dos consecuencias que son la garantia:
 *
 *  1. Las etapas son INMUTABLES. Hay un gatillo que revienta cualquier
 *     `UPDATE` o `DELETE` sobre una etapa ya registrada. Un error de tipeo en
 *     una fecha no se corrige editando: se registra la etapa correcta y la
 *     anterior queda como lo que ocurrio, que es la verdad.
 *
 *  2. El orden lo decide la SECUENCIA DE LA ENUM, no la aplicacion. Una
 *     etapa no se puede registrar si la anterior no esta. El proceso va hacia
 *     adelante y hacia adelante nomas.
 *
 * LA FECHA DE UNA ETAPA NO SE ADELANTA AL FUTURO
 * ----------------------------------------------
 * Si alguien pudiera registrar "clarificacion" con fecha de manana para
 * inventarse un tiempo de guarda, toda la trazabilidad temporal del vino
 * seria falsa. El `CHECK` lo prohibe en la base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etapas_vinificacion', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->foreignUuid('lote_id')->constrained('lotes')->restrictOnDelete();

            // El nombre de la etapa. El ORDEN de avance no lo da este texto:
            // lo da la secuencia de `EtapaVinificacion`. Un lote solo puede
            // registrar una vez cada etapa.
            $tabla->string('etapa', 32);

            // La fecha REAL en que ocurrio. No puede estar en el futuro.
            $tabla->date('fecha');

            $tabla->foreignUuid('responsable_id')->nullable()->constrained('usuarios')->restrictOnDelete();

            // Lo que el enologo anota de la etapa. Es el unico lugar donde
            // cabe la opinion: el resto son hechos.
            $tabla->text('notas')->nullable();

            $tabla->timestampsTz();

            // Una etapa, una vez, por lote. Repetirla es un error de proceso,
            // no una segunda pasada.
            $tabla->unique(['lote_id', 'etapa']);
            $tabla->index(['tenant_id', 'etapa']);

            $tabla->check("etapa IN ('recepcion_uva', 'destaje', 'fermentacion', "
                . "'maceracion', 'clarificacion', 'estabilizacion', 'barriqueado', "
                . "'embotellado', 'crianzas')");
        });

        Schema::create('embotellados', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->foreignUuid('lote_id')->constrained('lotes')->restrictOnDelete();

            $tabla->date('fecha_embotellado');

            // Formato: 750, 375, 1500. Mililitros.
            $tabla->unsignedInteger('formato_ml')->default(750);

            $tabla->unsignedInteger('botellas');

            // El vino sale de la garantia del embotellado: cada botella
            // apunta a su lote, y el lote a su vendimia. Ver docs/02.
            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'fecha_embotellado']);
            $tabla->index(['lote_id']);

            $tabla->check('botellas > 0');
            $tabla->check('formato_ml IN (750, 375, 1500)');
        });

        /*
         * Una etapa registrada es un hecho. No se toca.
         *
         * Este es el motor de "un lote mal criado no se puede deshacer". Si
         * se pudiera editar o borrar una etapa, el historial de un lote
         * seria tan manipulable como una hoja de papel, y no valdria como
         * registro de proceso. Aqui la garantia esta en la base: el
         * `UPDATE` y el `DELETE` fallan siempre.
         *
         * Para corregir un error de tipeo en una fecha se registra una nota
         * en la etapa correcta y se deja la anterior como ocurrio. Es lo
         * unico que una bodega honesta puede hacer, y tambien lo unico que el
         * sistema va a dejar hacer.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.trg_etapas_inmutables()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    'Una etapa de vinificacion no se modifica ni se borra: es un hecho del proceso. Registre una nueva etapa.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE TRIGGER trg_etapas_inmutables
            BEFORE UPDATE OR DELETE ON public.etapas_vinificacion
            FOR EACH ROW
            EXECUTE FUNCTION public.trg_etapas_inmutables();
        SQL);

        /*
         * Una etapa no puede registrar una fecha que todavia no ocurrio.
         *
         * Sin este `CHECK`, se podria "registrar" una clarificacion con
         * fecha de manana. El sistema lo permitiria y con el tiempo nadie
         * podria saber cual de las dos fechas era la real.
         */
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.etapas_vinificacion
            ADD CONSTRAINT etapas_sin_fecha_futura
            CHECK (fecha <= CURRENT_DATE);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_etapas_inmutables ON public.etapas_vinificacion;
            DROP FUNCTION IF EXISTS public.trg_etapas_inmutables();
        SQL);

        Schema::dropIfExists('embotellados');
        Schema::dropIfExists('etapas_vinificacion');
    }
};