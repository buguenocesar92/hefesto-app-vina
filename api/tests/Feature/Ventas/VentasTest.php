<?php

declare(strict_types=1);

namespace Tests\Feature\Ventas;

use App\Exceptions\ErrorDeVina;
use App\Models\Embotellado;
use App\Services\Ventas\RegistradorDeVenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ventas: donde la trazabilidad llega al vino que se llevo el cliente.
 *
 * POR QUE ESTO TIENE SU PROPIA SUITE
 * ----------------------------------
 * Una venta es el ultimo tramo del camino, y es el que mas se pierde: si el
 * mostrador vende sin apuntar al embotellado, el vino sale de la bodega sin
 * que nadie pueda decir despues de que parcela era.
 *
 * Y hay dos numeros que este modulo tiene que mantener ciertos:
 *
 *  - No se venden mas botellas de las que hay.
 *  - Una venta anulada DEVOLVE las botellas. Si no, un error de tipeo en el
 *    mostrador deja el inventario corto para siempre.
 */
class VentasTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_venta_apunta_al_embotellado_y_arrastra_la_trazabilidad(): void
    {
        $vina = $this->crearVina();
        $completo = $this->loteCompleto($vina, botellas: 12);

        $venta = app(RegistradorDeVenta::class)->abrir([
            'items' => [[
                'embotellado_id' => $completo['embotellado']->id,
                'botellas' => 2,
                'precio_unitario' => 15000,
            ]],
            'cliente_nombre' => 'Restaurante La Vega',
        ], $vina['piso']);

        $this->assertSame(30000.0, (float) $venta->total);

        // El origen sale de las relaciones reales, no de un dato copiado.
        $origenes = app(RegistradorDeVenta::class)->origenesDe($venta);
        $this->assertStringContainsString('Litoral', (string) $origenes[0]['linea_de_origen']);
        $this->assertStringContainsString('Cabernet Sauvignon', (string) $origenes[0]['linea_de_origen']);
    }

    public function test_no_se_venden_mas_botellas_de_las_que_hay(): void
    {
        $vina = $this->crearVina();
        $completo = $this->loteCompleto($vina, botellas: 6);

        try {
            app(RegistradorDeVenta::class)->abrir([
                'items' => [[
                    'embotellado_id' => $completo['embotellado']->id,
                    'botellas' => 7,
                    'precio_unitario' => 15000,
                ]],
            ], $vina['piso']);

            $this->fail('Se esperaba que no se vendieran mas botellas de las embotelladas.');
        } catch (ErrorDeVina $e) {
            $this->assertSame('sin_botellas', $e->codigo());
        }
    }

    public function test_no_se_vende_vino_de_un_lote_que_no_esta_embotellado(): void
    {
        $vina = $this->crearVina();

        // Un lote recien destajado, sin botella: no hay vino en botella.
        $vendimia = \App\Models\Vendimia::factory()->de($vina['vina'])->en($vina['parcela'])->create([
            'fecha_vendimia' => now()->subMonth()->toDateString(),
        ]);

        $lote = app(\App\Services\Vinificacion\RegistradorDeEtapa::class)
            ->abrirLoteDesde($vendimia, [], $vina['bodega']);

        $embotellado = Embotellado::query()->create([
            'tenant_id' => $lote->tenant_id,
            'lote_id' => $lote->id,
            'fecha_embotellado' => now()->toDateString(),
            'formato_ml' => 750,
            'botellas' => 10,
        ]);

        // El lote sigue en `recepcion`: se creo la fila del embotellado a
        // mano, pero el vino no esta embotellado de verdad.
        try {
            app(RegistradorDeVenta::class)->abrir([
                'items' => [[
                    'embotellado_id' => $embotellado->id,
                    'botellas' => 1,
                    'precio_unitario' => 15000,
                ]],
            ], $vina['piso']);

            $this->fail('Se esperaba que no se vendiera vino de un lote sin embotellar.');
        } catch (ErrorDeVina $e) {
            $this->assertSame('lote_no_venduible', $e->codigo());
        }
    }

    public function test_una_venta_anulada_devuelve_las_botellas(): void
    {
        $vina = $this->crearVina();
        $completo = $this->loteCompleto($vina, botellas: 10);

        $registrador = app(RegistradorDeVenta::class);

        $venta = $registrador->abrir([
            'items' => [[
                'embotellado_id' => $completo['embotellado']->id,
                'botellas' => 4,
                'precio_unitario' => 15000,
            ]],
        ], $vina['piso']);

        $this->assertSame(6, $registrador->disponiblesDe($completo['embotellado']));

        $registrador->anular($venta);

        $this->assertSame(
            10,
            $registrador->disponiblesDe($completo['embotellado']),
            'Una venta anulada devuelve el vino: un error de tipeo en el mostrador no puede dejar el inventario corto para siempre.'
        );
    }

    public function test_cobrar_cierra_la_venta(): void
    {
        $vina = $this->crearVina();
        $completo = $this->loteCompleto($vina, botellas: 12);

        $registrador = app(RegistradorDeVenta::class);

        $venta = $registrador->abrir([
            'items' => [[
                'embotellado_id' => $completo['embotellado']->id,
                'botellas' => 3,
                'precio_unitario' => 15000,
            ]],
        ], $vina['piso']);

        $venta = $registrador->cobrar($venta, ['metodo' => 'efectivo', 'monto' => 45000]);

        $this->assertSame('pagada', $venta->estado->value);
        $this->assertNotNull($venta->cerrada_en);
        $this->assertCount(1, $venta->pagos);
    }

    public function test_una_venta_anulada_no_se_puede_cobrar(): void
    {
        $vina = $this->crearVina();
        $completo = $this->loteCompleto($vina, botellas: 12);

        $registrador = app(RegistradorDeVenta::class);

        $venta = $registrador->abrir([
            'items' => [[
                'embotellado_id' => $completo['embotellado']->id,
                'botellas' => 1,
                'precio_unitario' => 15000,
            ]],
        ], $vina['piso']);

        $venta = $registrador->anular($venta);

        try {
            $registrador->cobrar($venta, ['metodo' => 'efectivo', 'monto' => 15000]);

            $this->fail('Se esperaba que una venta anulada no se pudiera cobrar.');
        } catch (ErrorDeVina $e) {
            $this->assertSame('estado_invalido', $e->codigo());
        }
    }

    public function test_una_venta_sin_botellas_se_rechaza(): void
    {
        $vina = $this->crearVina();
        $completo = $this->loteCompleto($vina, botellas: 12);

        try {
            app(RegistradorDeVenta::class)->abrir([
                'items' => [],
            ], $vina['piso']);

            $this->fail('Se esperaba que una venta sin botellas fuera rechazada.');
        } catch (ErrorDeVina $e) {
            $this->assertSame('venta_sin_items', $e->codigo());
        }
    }

    public function test_una_venta_abierta_demasiado_tiempo_no_se_cobra(): void
    {
        $vina = $this->crearVina();
        $completo = $this->loteCompleto($vina, botellas: 12);

        $registrador = app(RegistradorDeVenta::class);

        $venta = $registrador->abrir([
            'items' => [[
                'embotellado_id' => $completo['embotellado']->id,
                'botellas' => 1,
                'precio_unitario' => 15000,
            ]],
        ], $vina['piso']);

        // Se simula una venta que se quedo abierta toda la tarde.
        $venta->abierta_en = now()->subHours(3);
        $venta->save();

        try {
            $registrador->cobrar($venta->fresh(), ['metodo' => 'efectivo', 'monto' => 15000]);

            $this->fail('Se esperaba que una venta abierta hace horas no se pudiera cobrar.');
        } catch (ErrorDeVina $e) {
            $this->assertSame('venta_abierta_demasiado_tiempo', $e->codigo());
        }
    }

    public function test_el_endpoint_de_disponibles_dice_la_trazabilidad(): void
    {
        $vina = $this->crearVina();
        $completo = $this->loteCompleto($vina, botellas: 12);

        $respuesta = $this->actingAs($vina['piso'])
            ->getJson('/api/v1/embotellados/' . $completo['embotellado']->id . '/disponibles');

        $respuesta->assertOk();
        $respuesta->assertJsonPath('botellas_totales', 12);
        $respuesta->assertJsonPath('botellas_disponibles', 12);
        $respuesta->assertJsonPath('lote_codigo', $completo['lote']->codigo);
    }
}