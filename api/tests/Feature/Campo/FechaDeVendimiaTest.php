<?php

declare(strict_types=1);

namespace Tests\Feature\Campo;

use App\Exceptions\ErrorDeVina;
use App\Models\Vendimia;
use App\Services\Vinificacion\RegistradorDeEtapa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La fecha de vendimia: el origen de la trazabilidad.
 *
 * POR QUE ESTO TIENE SU PROPIA PRUEBA
 * -----------------------------------
 * Toda la promesa de "de que vendimia viene este vino" descansa en una
 * columna: `vendimias.fecha_vendimia`. Si esa columna se puede corregir
 * cuando ya hay un lote, la trazabilidad deja de ser un hecho y pasa a ser una
 * opinion que alguien puede cambiar a voluntad.
 *
 * Por eso hay un gatillo en la base, y por eso estas pruebas se hacen yendo al
 * motor de la base y no solo por la aplicacion.
 */
class FechaDeVendimiaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Antes de que exista el lote, corregir la fecha es legitimo: uno puede
     * teclear de mas el mismo dia de la cosecha, y esa correccion es honesta.
     */
    public function test_la_fecha_se_puede_corregir_si_todavia_no_hay_lote(): void
    {
        $vina = $this->crearVina();

        $vendimia = Vendimia::factory()->de($vina['vina'])->en($vina['parcela'])->create([
            'fecha_vendimia' => now()->subMonth()->toDateString(),
        ]);

        $vendimia->fecha_vendimia = now()->subDays(25)->toDateString();
        $vendimia->save();

        $this->assertSame(
            now()->subDays(25)->toDateString(),
            $vendimia->fresh()->fecha_vendimia->toDateString(),
            'Sin lote todavia, corregir la fecha es una correccion legitima.'
        );
    }

    /**
     * UNA VEZ QUE HAY LOTE, LA FECHA ES INMUTABLE.
     *
     * Esta es la garantia. Se prueba yendo a la base, porque si dependiera de
     * que la aplicacion no mande el `UPDATE`, bastaria una consulta a mano
     * para reescribir el origen del vino.
     */
    public function test_la_fecha_no_se_puede_cambiar_cuando_ya_hay_lote(): void
    {
        $vina = $this->crearVina();

        $vendimia = Vendimia::factory()->de($vina['vina'])->en($vina['parcela'])->create([
            'fecha_vendimia' => now()->subMonths(8)->toDateString(),
        ]);

        app(RegistradorDeEtapa::class)->abrirLoteDesde($vendimia, [], $vina['bodega']);

        $original = $vendimia->fresh()->fecha_vendimia->toDateString();

        $this->esperarFalloDeBase(function (): void {
            DB::statement("UPDATE vendimias SET fecha_vendimia = DATE '2020-01-01'");
        });

        $this->assertSame(
            $original,
            $vendimia->fresh()->fecha_vendimia->toDateString(),
            'La fecha de vendimia es el origen de la trazabilidad y no se toca.'
        );
    }

    /**
     * Una vendimia no se puede registrar con fecha de manana.
     *
     * OJO sobre donde vive esta regla: la base NO tiene un `CHECK` para esto,
     * y a proposito. La fecha de una vendimia se puede corregir en cualquier
     * momento mientras no haya lote, asi que un `CHECK` en la tabla tendria que
     * comprobar el "hoy" en cada escritura, que es una regla de negocio
     * expressada como si fuera de integridad.
     *
     * La regla vive en el controlador, que es donde se valida lo que escribe
     * una persona. La garantia fuerte (que la fecha no se cambia DESPUES, con
     * lote) si esta en la base, y eso es lo que prueban las otras pruebas.
     */
    public function test_la_api_rechaza_una_vendimia_con_fecha_de_futuro(): void
    {
        $vina = $this->crearVina();

        $respuesta = $this->actingAs($vina['bodega'])->postJson('/api/v1/vendimias', [
            'parcela_id' => $vina['parcela']->id,
            'fecha_vendimia' => now()->addWeek()->toDateString(),
            'kilos' => 1000,
        ]);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('codigo', 'fecha_de_vendimia_futura');

        $this->assertSame(0, Vendimia::query()->count());
    }

    public function test_el_lote_toma_el_anio_de_la_vendimia(): void
    {
        $vina = $this->crearVina();

        $vendimia = Vendimia::factory()->de($vina['vina'])->en($vina['parcela'])->create([
            'fecha_vendimia' => '2024-03-12',
        ]);

        $lote = app(RegistradorDeEtapa::class)->abrirLoteDesde($vendimia, [], $vina['bodega']);

        $this->assertSame(
            2024,
            (int) $lote->anio,
            'El ano del lote es el ano de la vendimia, no el de hoy.'
        );

        $this->assertStringStartsWith(
            'LT-LIT-2024-',
            $lote->codigo,
            'El codigo del lote lleva la parcela y el anio de la vendimia.'
        );
    }

    public function test_una_vendimia_descartada_no_se_vinifica(): void
    {
        $vina = $this->crearVina();

        $vendimia = Vendimia::factory()->de($vina['vina'])->en($vina['parcela'])->descartada()->create([
            'fecha_vendimia' => now()->subMonth()->toDateString(),
        ]);

        try {
            app(RegistradorDeEtapa::class)->abrirLoteDesde($vendimia, [], $vina['bodega']);

            $this->fail('Se esperaba que una vendimia descartada no se vinificara.');
        } catch (ErrorDeVina $e) {
            $this->assertSame('vendimia_descartada', $e->codigo());
        }
    }

    public function test_una_vendimia_sin_analisis_guarda_null_y_no_cero(): void
    {
        $vina = $this->crearVina();

        $vendimia = Vendimia::factory()->de($vina['vina'])->en($vina['parcela'])->sinAnalisis()->create([
            'fecha_vendimia' => now()->subMonth()->toDateString(),
        ]);

        $guardada = $vendimia->fresh();

        $this->assertNull($guardada->brix, 'Sin analisis es NULL. Un cero seria un dato falso.');
        $this->assertNull($guardada->acidez);

        // Y NULL y cero no son lo mismo en la base: el `CHECK` lo permite,
        // pero un brix de cero es una lectura real que aqui no ocurrio.
        $this->assertNotSame(0.0, (float) $guardada->brix);
    }
}