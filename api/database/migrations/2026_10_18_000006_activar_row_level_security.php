<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row Level Security y la vista publica de viñas.
 *
 * LAS CUATRO PIEZAS Y POR QUE ESTAN LAS CUATRO
 * --------------------------------------------
 *
 *  1. `app_tenant_id()`: lee la viña de la peticion. Si no hay contexto,
 *     LANZA EXCEPCION. Falla cerrado: sin contexto no hay lectura, en vez de
 *     devolver todas las filas de todas las viñas.
 *
 *  2. `set_config('app.tenant_id', ..., true)`: el `true` es lo que la hace
 *     segura. Sin el, la marca vive en la sesion de PostgreSQL y sobrevive a
 *     la transaccion: en una conexion reutilizable, la peticion siguiente
 *     hereda la viña anterior y lee datos ajenos.
 *
 *  3. `USING` y `WITH CHECK`: `USING` filtra lo que ya esta escrito,
 *     `WITH CHECK` impide que se escriba de otra viña. Con solo `USING`, un
 *     `INSERT` de otra viña pasaria.
 *
 *  4. `FORCE ROW LEVEL SECURITY`: sin esto, el dueño de la tabla (el rol de
 *     la aplicacion) tiene permiso de saltarsela entera.
 *
 * Ver docs/03-MULTITENANCY-Y-ROLES.md.
 *
 * LA VISTA `vw_vinas_publicas`
 * ----------------------------
 * La tabla `tenants` no lleva RLS porque se consulta en el acceso, cuando no
 * hay `app.tenant_id`. La compensacion es esta vista: tres columnas, nada
 * mas, y solo viñas activas. Ni el `id`, ni el `razon_social`, ni los
 * sellos de tiempo.
 *
 * Se USA de verdad en `AuthController::vinas()`, que es lo que la
 * pantalla de entrada muestra. No es decoracion: es el unico lugar del
 * sistema donde se lee una viña sin identificarse.
 */
return new class extends Migration
{
    /**
     * Las 9 tablas con datos de una viña. Todas reciben exactamente el
     * mismo tratamiento: la lista se recorre en el bucle, asi que es
     * imposible que una se quede sin proteger por un olvido.
     *
     * @var list<string>
     */
    private const TABLAS = [
        'usuarios',
        'parcelas',
        'vendimias',
        'lotes',
        'etapas_vinificacion',
        'embotellados',
        'ventas',
        'venta_items',
        'pagos',
    ];

    public function up(): void
    {
        // --- 1. La funcion que falla cerrado -------------------------------

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.app_tenant_id()
            RETURNS uuid
            LANGUAGE plpgsql
            STABLE
            AS $$
            DECLARE
                valor text;
            BEGIN
                -- `missing_ok = true`: si la marca no esta, se devuelve NULL
                -- en vez de lanzar excepcion de PostgreSQL, para que el error
                -- sea el de esta funcion y no uno de PostgreSQL que nadie
                -- entendio.
                valor := current_setting('app.tenant_id', true);

                -- FALLA CERRADO. Sin contexto no hay lectura. La alternativa
                -- (devolver NULL y que `tenant_id = NULL` no casara con
                -- nada) tambien cierra, pero devuelve un error de PostgreSQL
                -- que no dice nada: el error tiene que DIR el por que.
                IF valor IS NULL OR valor = '' THEN
                    RAISE EXCEPTION
                        'No hay viña en el contexto. Se intento leer una tabla protegida sin viña: el aislamiento falla cerrado.'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN valor::uuid;
            END;
            $$;
        SQL);

        // --- 2. La vista publica de viñas ----------------------------------

        DB::unprepared(<<<'SQL'
            CREATE VIEW public.vw_vinas_publicas AS
            SELECT
                slug,
                nombre,
                activo
            FROM public.tenants
            WHERE activo = true;

            COMMENT ON VIEW public.vw_vinas_publicas IS
                'Tres columnas y solo viñas activas. Es lo unico que se puede leer sin sesion. La consulta esta en AuthController::vinas().';
        SQL);

        // --- 3. Las politicas, tabla por tabla ----------------------------

        foreach (self::TABLAS as $tabla) {
            DB::unprepared(<<<SQL
                ALTER TABLE public.{$tabla} ENABLE ROW LEVEL SECURITY;
                ALTER TABLE public.{$tabla} FORCE ROW LEVEL SECURITY;

                DROP POLICY IF EXISTS {$tabla}_aislamiento ON public.{$tabla};

                CREATE POLICY {$tabla}_aislamiento
                    ON public.{$tabla}
                    FOR ALL
                    USING (tenant_id = public.app_tenant_id())
                    WITH CHECK (tenant_id = public.app_tenant_id());
            SQL);
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            DB::unprepared(<<<SQL
                DROP POLICY IF EXISTS {$tabla}_aislamiento ON public.{$tabla};
                ALTER TABLE public.{$tabla} NO FORCE ROW LEVEL SECURITY;
                ALTER TABLE public.{$tabla} DISABLE ROW LEVEL SECURITY;
            SQL);
        }

        DB::unprepared('DROP VIEW IF EXISTS public.vw_vinas_publicas;');
        DB::unprepared('DROP FUNCTION IF EXISTS public.app_tenant_id();');
    }
};