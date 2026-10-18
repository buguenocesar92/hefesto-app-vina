<?php

declare(strict_types=1);

namespace Tests\Feature\Vinificacion;

use App\Enums\EstadoLote;
use App\Enums\EtapaVinificacion;
use App\Exceptions\ErrorDeVina;
use App\Models\EtapaVinificacion as ModeloEtapa;
use App\Models\Vendimia;
use App\Services\Vinificacion\RegistradorDeEtapa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La vinificacion: las etapas se agregan en orden y no se tocan.
 *
 * POR QUE ESTO TIENE SU PROPIA SUITE
 * ----------------------------------
 * "Un lote mal criado no se puede deshacer" no es una frase de marketing: es
 * la razon por la que `etapas_vinificacion` tiene un gatillo en la base que
 * revienta cualquier `UPDATE` o `DELETE`. Si esa garantia fallara, el
 * historial de un proceso dejaria de ser un registro y pasaria a ser una hoja
 * de papel.
 *
 * Por eso hay aca tres familias de pruebas:
 *
 *  1. El ORDEN: no se puede saltar una etapa.
 *  2. La INMUTABILIDAD: una etapa registrada no se edita ni se borra, ni
 *     desde la aplicacion ni yendo a la base de datos.
 *  3. La PERDIDA: un lote que se pierde queda a la vista con su motivo.
 */
class EtapasDeVinificacionTest extends TestCase
{
    use RefreshDatabase;

    // --- El orden ---------------------------------------------------------

    public function test_abrir_un_lote_registra_la_recepcion_de_la_uva(): void
    {
        $vina = $this->crearVina();
        $lote = $this->abrirLote($vina);

        $etapas = ModeloEtapa::query()->where('lote_id', $lote->id)->pluck('etapa');

        $this->assertCount(1, $etapas);
        $this->assertSame(
            EtapaVinificacion::RECEPCION_UVA->value,
            $etapas->first()
        );
    }

    public function test_no_se_puede_saltar_una_etapa_obligatoria(): void
    {
        $vina = $this->crearVina();
        $lote = $this->abrirLote($vina);

        // El lote esta en `destaje`. Intentar la maceracion sin haber
        // fermentado es exactamente el salto que este sistema no permite.
        try {
            app(RegistradorDeEtapa::class)->registrar($lote, [
                'etapa' => EtapaVinificacion::MACERACION->value,
                'fecha' => now()->toDateString(),
            ], $vina['bodega']);

            $this->fail('Se esperaba que la maceracion fuera rechazada antes de fermentar.');
        } catch (ErrorDeVina $e) {
            $this->assertSame('etapa_fuera_de_orden', $e->codigo());
            $this->assertStringContainsString('Fermentacion', $e->getMessage());
        }

        $this->assertSame(
            1,
            ModeloEtapa::query()->where('lote_id', $lote->id)->count(),
            'Una etapa rechazada no puede dejar rastro.'
        );
    }

    public function test_una_etapa_no_se_registra_dos_veces(): void
    {
        $vina = $this->crearVina();
        $lote = $this->abrirLote($vina);

        app(RegistradorDeEtapa::class)->registrar($lote, [
            'etapa' => EtapaVinificacion::DESTAJE->value,
            'fecha' => now()->toDateString(),
        ], $vina['bodega']);

        try {
            app(RegistradorDeEtapa::class)->registrar($lote, [
                'etapa' => EtapaVinificacion::DESTAJE->value,
                'fecha' => now()->toDateString(),
            ], $vina['bodega']);

            $this->fail('Se esperaba que la etapa repetida fuera rechazada.');
        } catch (ErrorDeVina $e) {
            $this->assertSame('etapa_ya_registrada', $e->codigo());
        }
    }

    public function test_una_etapa_no_puede_tener_fecha_de_futuro(): void
    {
        $vina = $this->crearVina();
        $lote = $this->abrirLote($vina);

        try {
            app(RegistradorDeEtapa::class)->registrar($lote, [
                'etapa' => EtapaVinificacion::DESTAJE->value,
                'fecha' => now()->addWeek()->toDateString(),
            ], $vina['bodega']);

            $this->fail('Se esperaba que una etapa de manana fuera rechazada.');
        } catch (ErrorDeVina $e) {
            $this->assertSame('fecha_de_etapa_futura', $e->codigo());
        }
    }

    // --- Las opcionales ----------------------------------------------------

    /**
     * Un vino joven no pasa por barrica, y es un vino legitimo.
     *
     * Este caso es el que obliga a que las etapas OPCIONALES no bloqueen el
     * proceso. Si `barriqueado` fuera obligatorio, ningun lote poderia
     * embotellarse sin pasar antes por una barrica, y eso es falso.
     */
    public function test_un_vino_sin_barrica_se_puede_embotellar_igual(): void
    {
        $vina = $this->crearVina();
        $lote = $this->abrirLote($vina);

        foreach ([
            EtapaVinificacion::DESTAJE,
            EtapaVinificacion::FERMENTACION,
            EtapaVinificacion::MACERACION,
            EtapaVinificacion::CLARIFICACION,
            EtapaVinificacion::ESTABILIZACION,
        ] as $etapa) {
            app(RegistradorDeEtapa::class)->registrar($lote, [
                'etapa' => $etapa->value,
                'fecha' => now()->subDay()->toDateString(),
            ], $vina['bodega']);
        }

        $lote->refresh();

        // La siguiente OBLIGATORIA es el embotellado, no la barrica.
        $siguiente = app(RegistradorDeEtapa::class)->siguienteEtapaDe($lote);
        $this->assertSame(EtapaVinificacion::EMBOTELLADO, $siguiente);

        $opcionales = app(RegistradorDeEtapa::class)->etapasOpcionalesDisponibles($lote);
        $this->assertNotEmpty($opcionales, 'La barrica se OFRECE, no se impone.');
    }

    public function test_se_puede_registrar_la_garupa_en_barrica(): void
    {
        $vina = $this->crearVina();
        $lote = $this->abrirLote($vina);

        foreach ([
            EtapaVinificacion::DESTAJE,
            EtapaVinificacion::FERMENTACION,
            EtapaVinificacion::MACERACION,
            EtapaVinificacion::CLARIFICACION,
            EtapaVinificacion::ESTABILIZACION,
        ] as $etapa) {
            app(RegistradorDeEtapa::class)->registrar($lote, [
                'etapa' => $etapa->value,
                'fecha' => now()->subDays(10)->toDateString(),
            ], $vina['bodega']);
        }

        app(RegistradorDeEtapa::class)->registrar($lote, [
            'etapa' => EtapaVinificacion::BARRIQUEADO->value,
            'fecha' => now()->subDays(5)->toDateString(),
            'notas' => 'Quince meses en barrica de roble.',
        ], $vina['bodega']);

        $this->assertTrue(
            ModeloEtapa::query()
                ->where('lote_id', $lote->id)
                ->where('etapa', EtapaVinificacion::BARRIQUEADO->value)
                ->exists()
        );
    }

    // --- La inmutabilidad, que es la garantia central -----------------------

    /**
     * UNA ETAPA REGISTRADA NO SE EDITA.
     *
     * Se prueba yendo a la base de datos, no por la aplicacion, porque la
     * garantia tiene que estar en la base: si dependiera de que la aplicacion
     * no mande un `UPDATE`, bastaria una consulta a mano para reescribir la
     * historia del proceso.
     */
    public function test_una_etapa_registrada_no_se_puede_editar_en_la_base(): void
    {
        $vina = $this->crearVina();
        $lote = $this->abrirLote($vina);

        $etapa = ModeloEtapa::query()->where('lote_id', $lote->id)->firstOrFail();

        $this->esperarFalloDeBase(function () use ($etapa): void {
            DB::table('etapas_vinificacion')
                ->where('id', $etapa->id)
                ->update(['fecha' => now()->subYear()->toDateString()]);
        });

        $this->assertTrue(
            $etapa->fresh()->fecha->isSameDay(now()->subMonths(8)),
            'La fecha de la etapa tiene que seguir siendo la que se registro.'
        );
    }

    public function test_una_etapa_registrada_no_se_puede_borrar_en_la_base(): void
    {
        $vina = $this->crearVina();
        $lote = $this->abrirLote($vina);

        app(RegistradorDeEtapa::class)->registrar($lote, [
            'etapa' => EtapaVinificacion::DESTAJE->value,
            'fecha' => now()->toDateString(),
        ], $vina['bodega']);

        $antes = ModeloEtapa::query()->where('lote_id', $lote->id)->count();

        $this->esperarFalloDeBase(function () use ($lote): void {
            DB::table('etapas_vinificacion')
                ->where('lote_id', $lote->id)
                ->delete();
        });

        $this->assertSame(
            $antes,
            ModeloEtapa::query()->where('lote_id', $lote->id)->count(),
            'Ninguna etapa se borra: el proceso es un registro, no una hoja de papel.'
        );
    }

    // --- La perdida, que tambien es registro --------------------------------

    public function test_un_lote_descartado_queda_a_la_vista_con_su_motivo(): void
    {
        $vina = $this->crearVina();
        $lote = $this->abrirLote($vina);

        $lote->estado = EstadoLote::DESCARTADO;
        $lote->nombre = $lote->codigo . ' - DESCARTADO: fermentacion alcohola';
        $lote->save();

        $guardado = $lote->fresh();

        $this->assertSame(EstadoLote::DESCARTADO, $guardado->estado);
        $this->assertStringContainsString(
            'fermentacion alcohola',
            (string) $guardado->nombre,
            'Una perdida sin motivo es una perdida que se repite el ano que viene.'
        );
    }

    public function test_un_lote_descartado_no_acepta_mas_etapas(): void
    {
        $vina = $this->crearVina();
        $lote = $this->abrirLote($vina);

        $lote->estado = EstadoLote::DESCARTADO;
        $lote->save();

        try {
            app(RegistradorDeEtapa::class)->registrar($lote, [
                'etapa' => EtapaVinificacion::DESTAJE->value,
                'fecha' => now()->toDateString(),
            ], $vina['bodega']);

            $this->fail('Se esperaba que un lote descartado no admitiera etapas.');
        } catch (ErrorDeVina $e) {
            $this->assertSame('lote_cerrado', $e->codigo());
        }
    }

    // --- Una vendimia, un lote ----------------------------------------------

    public function test_una_vendimia_no_se_vinifica_dos_veces(): void
    {
        $vina = $this->crearVina();
        $registrador = app(RegistradorDeEtapa::class);

        $vendimia = Vendimia::factory()->de($vina['vina'])->en($vina['parcela'])->create([
            'fecha_vendimia' => now()->subMonth()->toDateString(),
        ]);

        $registrador->abrirLoteDesde($vendimia, [], $vina['bodega']);

        try {
            $registrador->abrirLoteDesde($vendimia->fresh(), [], $vina['bodega']);

            $this->fail('Se esperaba que la vendimia no admitiera un segundo lote.');
        } catch (ErrorDeVina $e) {
            $this->assertSame('vendimia_ya_vinificada', $e->codigo());
        }
    }

    public function test_al_abrir_el_lote_la_vendimia_pasa_a_bodega(): void
    {
        $vina = $this->crearVina();

        $vendimia = Vendimia::factory()->de($vina['vina'])->en($vina['parcela'])->create([
            'fecha_vendimia' => now()->subMonth()->toDateString(),
        ]);

        $this->assertSame('cosechada', $vendimia->estado->value);

        app(RegistradorDeEtapa::class)->abrirLoteDesde($vendimia, [], $vina['bodega']);

        $this->assertSame(
            'en_bodega',
            $vendimia->fresh()->estado->value,
            'La vendimia y el lote se mueven juntos: o entran los dos, o no entra ninguno.'
        );
    }

    /** @return \App\Models\Lote */
    private function abrirLote(array $vina): \App\Models\Lote
    {
        $vendimia = Vendimia::factory()->de($vina['vina'])->en($vina['parcela'])->create([
            'fecha_vendimia' => now()->subMonths(6)->toDateString(),
        ]);

        return app(RegistradorDeEtapa::class)->abrirLoteDesde($vendimia, [], $vina['bodega']);
    }
}