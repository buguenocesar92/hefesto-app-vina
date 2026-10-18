<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use App\Models\Parcela;
use App\Models\Vendimia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Que una viña no vea los datos de otra.
 *
 * POR QUE ESTO TIENE SU PROPIA SUITE
 * ----------------------------------
 * El aislamiento entre clientes es la garantia estructural del sistema: sin
 * ela, una bodega ve los datos de otra y no hay forma de recuperarlo.
 *
 * Lo que se prueba aca NO es la autorizacion de la aplicacion (eso lo hacen
 * las Policies). Es lo que hay DEBAJO: que la base filtra, que leer sin
 * contexto falla, y que una lectura normal trae solo lo de la propia viña.
 *
 * Ver docs/03-MULTITENANCY-Y-ROLES.md.
 */
class AislamientoDeDatosTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_vina_no_ve_las_parcelas_de_otra(): void
    {
        $primera = $this->crearVina('primera.cl');
        $segunda = $this->crearVina('segunda.cl');

        // Se esta en el contexto de la segunda y se cuentan las parcelas.
        $this->fijarVina($segunda['vina']->id);

        $visibles = Parcela::query()->count();

        $this->assertSame(
            2,
            $visibles,
            'Solo las dos parcelas de la segunda viña: no la de la primera.'
        );

        $this->assertNull(
            Parcela::query()->find($primera['parcela']->id),
            'Una parcela de otra viña no existe mientras se esta en este contexto.'
        );
    }

    public function test_leer_sin_contexto_falla(): void
    {
        $this->crearVina();

        // Se borra la marca del contexto y se intenta leer.
        DB::statement("SELECT set_config('app.tenant_id', '', false)");

        $this->esperarFalloDeBase(function (): void {
            DB::select('SELECT * FROM parcelas LIMIT 1');
        });
    }

    public function test_la_funcion_de_contexto_falla_cerrada(): void
    {
        $this->crearVina();

        DB::statement("SELECT set_config('app.tenant_id', '', false)");

        // `app_tenant_id()` tiene que LANZAR excepcion, no devolver NULL.
        // Devolver NULL cerraria el filtro por otra via, pero con un error de
        // PostgreSQL que no dice nada.
        $this->esperarFalloDeBase(function (): void {
            DB::selectOne('SELECT public.app_tenant_id()');
        });
    }

    public function test_no_se_puede_escribir_una_fila_de_otra_vina(): void
    {
        $primera = $this->crearVina('primera.cl');
        $segunda = $this->crearVina('segunda.cl');

        // Se esta en el contexto de la segunda y se intenta crear una parcela
        // que pertenece a la primera.
        $this->fijarVina($segunda['vina']->id);

        $this->esperarFalloDeBase(function () use ($primera): void {
            Parcela::query()->create([
                'tenant_id' => $primera['vina']->id,
                'codigo' => 'INTRUSA',
                'nombre' => 'Parcela intrusa',
                'variedad' => 'Cabernet Sauvignon',
                'activo' => true,
            ]);
        });
    }

    public function test_las_vendimias_tambien_estan_aisladas(): void
    {
        $primera = $this->crearVina('primera.cl');
        $segunda = $this->crearVina('segunda.cl');

        Vendimia::factory()->de($primera['vina'])->en($primera['parcela'])->create([
            'numero' => 'VEN-PRIMERA-001',
        ]);

        $this->fijarVina($segunda['vina']->id);

        $this->assertSame(0, Vendimia::query()->count());
    }

    public function test_la_vista_publica_expone_solo_tres_columnas(): void
    {
        $this->crearVina('visible.cl');

        $columnas = DB::select(
            "SELECT column_name FROM information_schema.columns
             WHERE table_name = 'vw_vinas_publicas' AND table_schema = 'public'"
        );

        $nombres = array_map(fn ($c) => $c->column_name, $columnas);

        sort($nombres);

        $this->assertSame(['activo', 'nombre', 'slug'], $nombres);
    }

    public function test_la_vista_publica_no_muestra_vinas_inactivas(): void
    {
        $this->crearVina('activa.cl');

        \App\Models\Tenant::factory()->inactivo()->create([
            'slug' => 'inactiva.cl',
            'nombre' => 'Viña inactiva',
        ]);

        $visibles = \App\Models\Tenant::publicasActivas()->pluck('slug')->all();

        $this->assertContains('activa.cl', $visibles);
        $this->assertNotContains('inactiva.cl', $visibles);
    }
}