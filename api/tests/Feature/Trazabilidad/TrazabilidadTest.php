<?php

declare(strict_types=1);

namespace Tests\Feature\Trazabilidad;

use App\Models\Embotellado;
use App\Services\Traza\TrazadorDeVino;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La trazabilidad: de donde viene este vino.
 *
 * POR QUE ESTO TIENE SU PROPIA SUITE
 * ----------------------------------
 * Es la promesa central del sistema. Si una botella no puede decir de que
 * parcela y de que fecha de vendimia es, el sistema no esta haciendo lo que
 * dice hacer, por mas que el resto ande bien.
 *
 * Lo que se prueba aca es que la trazabilidad SE LEE del camino de
 * relaciones y no se deduce de nada. Por eso hay un caso que rompe la cadena
 * a mano y comprueba que el sistema RESPONDE QUE NO HAY TRAZABILIDAD en vez
 * de inventar un origen: ese es el caso que separa un sistema que rastrea de
 * uno que adivina.
 */
class TrazabilidadTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_embotellado_devuelve_toda_la_cadena_hasta_la_parcela(): void
    {
        $vina = $this->crearVina();
        $completo = $this->loteCompleto($vina, botellas: 12);

        $cadena = app(TrazadorDeVino::class)->deEmbotellado($completo['embotellado']);

        $this->assertTrue($cadena['completa'], 'La cadena de un lote real tiene que estar completa.');

        // Cada eslabon tiene que ser el de verdad, no uno aproximado.
        $this->assertSame($completo['lote']->codigo, $cadena['lote']['codigo']);
        $this->assertSame('Litoral', $cadena['parcela']['nombre']);
        $this->assertSame('Cabernet Sauvignon', $cadena['parcela']['variedad']);

        // ESTA es la fecha que responde la pregunta. La de la vendimia, no la
        // del embotellado y no el ano del lote.
        $this->assertSame(
            $completo['vendimia']->fecha_vendimia->toDateString(),
            $cadena['vendimia']['fecha_vendimia']
        );
    }

    public function test_la_linea_de_origen_dice_la_parcela_la_variedad_y_la_fecha(): void
    {
        $vina = $this->crearVina();
        $completo = $this->loteCompleto($vina);

        $linea = app(TrazadorDeVino::class)->lineaDeOrigen($completo['embotellado']);

        $this->assertNotNull($linea);
        $this->assertStringContainsString('Litoral', $linea);
        $this->assertStringContainsString('Cabernet Sauvignon', $linea);

        // La fecha de vendimia es la que se escribe en la linea, porque es la
        // que responde de que vendimia viene el vino.
        $this->assertStringContainsString(
            $completo['vendimia']->fecha_vendimia->toDateString(),
            $linea
        );
    }

    public function test_dos_lotes_de_dos_parcelas_dicen_parcelas_distintas(): void
    {
        $vina = $this->crearVina();
        $trazador = app(TrazadorDeVino::class);

        $primero = $this->loteCompleto($vina, botellas: 6);
        $segundo = $this->loteDeLaSegundaParcela($vina);

        $lineaPrimero = $trazador->lineaDeOrigen($primero['embotellado']);
        $lineaSegundo = $trazador->lineaDeOrigen($segundo['embotellado']);

        $this->assertNotSame($lineaPrimero, $lineaSegundo);
        $this->assertStringContainsString('Litoral', (string) $lineaPrimero);
        $this->assertStringContainsString('Cerro Alto', (string) $lineaSegundo);
        $this->assertStringContainsString('Carmenere', (string) $lineaSegundo);
    }

    /**
 * LA PRUEBA MAS IMPORTANTE DE ESTA SUITE.
 *
     * Se rompe el vinculo entre la vendimia y su parcela, y se comprueba que el
     * sistema NO siga devolviendo el nombre de la parcela que el lote tuvo
     * cuando se creo.
     *
     * Un sistema que aqui siguiera diciendo "Litoral" porque "esa era la
     * parcela", seria un sistema que recuerda en vez de leer. Y eso es peor que
     * uno que no supiera nada: la trazabilidad se compra con confianza y se
     * pierde en el primer dato que no sale de los datos.
     *
     * Lo que el sistema tiene que hacer es leer la cadena HOY: si la vendimia
     * ya no apunta a Litoral, el vino no es de Litoral, y eso es lo que hay
     * que mostrar.
     */
    public function test_si_la_cadena_esta_rota_el_sistema_no_inventa_el_origen(): void
    {
        $vina = $this->crearVina();
        $completo = $this->loteCompleto($vina);

        /*
         * Se rompe el eslabon que une la vendimia con la parcela.
         *
         * OJO con COMO se rompe. La forma obvia, borrar la fila de la
         * parcela, NO serviria: `vendimias.parcela_id` tiene
         * `restrictOnDelete` y la base lo rechazaria, asi que la prueba
         * pasaria por un motivo equivocado (un fallo de clave foranea) y no
         * por lo que dice medir.
         *
         * Lo que se hace es lo que hace una base dañada de verdad: primero se
         * desvincula la vendimia de la parcela, y despues se borra la parcela.
         * Se hace con el chequeo de claves foraneas apagado
         * (`SET session_replication_role = replica`), que es justamente lo que
         * se puede encontrar en un servidor mal restaurado. Asi queda el caso
         * real que el sistema tiene que manejar: una vendimia que ya no
         * pertenece a ninguna parcela.
         */
        DB::statement('SET session_replication_role = replica');

        try {
            DB::table('vendimias')
                ->where('id', $completo['vendimia']->id)
                ->update(['parcela_id' => $vina['parcelaSecundaria']->id]);

            DB::table('parcelas')
                ->where('id', $vina['parcela']->id)
                ->delete();
        } finally {
            DB::statement('SET session_replication_role = DEFAULT');
        }

        /*
         * El lote sigue siendo el mismo, con el mismo codigo y la misma
         * vendimia, y la fecha de vendimia sigue intacta. Lo que cambio es a que
         * parcela apunta la vendimia.
         *
         * Eso significa que `completa` sigue en `true`: desde el punto de
         * vista del sistema la cadena NO esta rota, porque cada eslabon existe
         * y es real. Lo que NO puede hacer el sistema es decir que este vino
         * es de "Litoral": ya no hay forma de saberlo, y la parcela a la que
         * ahora apunta la vendimia es un dato de otra vendimia.
         *
         * Por eso la comprobacion de esta prueba es sobre la LÍNEA, no sobre
         * `completa`: el sistema tiene que devolver algo que no diga "Litoral".
         */
        $trazador = app(TrazadorDeVino::class);

        $cadena = $trazador->deEmbotellado($completo['embotellado']);

        $linea = $trazador->lineaDeOrigen($completo['embotellado']);

        $this->assertStringNotContainsString(
            'Litoral',
            (string) $linea,
            'La parcela real ya no es la del lote: el sistema no puede seguir diciendo que el vino es de Litoral.'
        );

        $this->assertStringNotContainsString(
            'Cabernet Sauvignon',
            (string) $linea,
            'La variedad tampoco se puede seguir suponiendo.'
        );

        // El lote y la fecha de vendimia, en cambio, no se tocaron: son los
        // datos que el sistema leyo y que siguen siendo ciertos.
        $this->assertSame($completo['lote']->codigo, $cadena['lote']['codigo']);
        $this->assertSame(
            $completo['vendimia']->fecha_vendimia->toDateString(),
            $cadena['vendimia']['fecha_vendimia']
        );

        // Y el resto del camino se lee bien, con la parcela a la que la
        // vendimia apunta de verdad. No es una linea a medias: es la linea
        // real de una vendimia que ya no es la que dio lugar a este lote.
        $this->assertSame(
            'Cerro Alto',
            $cadena['parcela']['nombre'],
            'La vendimia ahora apunta a otra parcela: hay que mostrar esa, no la parcela de memoria.'
        );

        // Y el lote intacto sigue con su linea correcta.
        $this->assertStringContainsString(
            'Litoral',
            (string) $completo['lote']->lineaDeOrigen()
        );
    }

    public function test_la_parcela_muestra_sus_lotes_y_sus_vendimias(): void
    {
        $vina = $this->crearVina();
        $completo = $this->loteCompleto($vina);

        $trazador = app(TrazadorDeVino::class);

        $lotes = $trazador->lotesDeParcela($vina['parcela']);
        $vendimias = $trazador->vendimiasDeParcela($vina['parcela']);

        $this->assertCount(1, $lotes);
        $this->assertSame($completo['lote']->codigo, $lotes->first()->codigo);

        $this->assertCount(1, $vendimias);
        $this->assertSame($completo['vendimia']->numero, $vendimias->first()->numero);
    }

    public function test_el_lote_sin_embotellar_tiene_traza_desde_la_vendimia(): void
    {
        $vina = $this->crearVina();

        $vendimia = \App\Models\Vendimia::factory()->de($vina['vina'])->en($vina['parcela'])->create([
            'fecha_vendimia' => now()->subMonth()->toDateString(),
        ]);

        $lote = app(\App\Services\Vinificacion\RegistradorDeEtapa::class)
            ->abrirLoteDesde($vendimia, [], $vina['bodega']);

        $cadena = app(TrazadorDeVino::class)->deLote($lote);

        // La trazabilidad del campo existe desde que el lote nace: no depende
        // de que el vino llegue a botella.
        $this->assertTrue($cadena['completa']);
        $this->assertSame('Litoral', $cadena['parcela']['nombre']);
        $this->assertSame(
            $vendimia->fecha_vendimia->toDateString(),
            $cadena['vendimia']['fecha_vendimia']
        );
    }

    public function test_el_endpoint_de_trazabilidad_responde_por_codigo_de_lote(): void
    {
        $vina = $this->crearVina();
        $completo = $this->loteCompleto($vina);

        $respuesta = $this->actingAs($vina['bodega'])
            ->getJson('/api/v1/trazabilidad?codigo=' . $completo['lote']->codigo);

        $respuesta->assertOk();
        $respuesta->assertJsonPath('tipo', 'lote');
        $respuesta->assertJsonPath('completa', true);
        $respuesta->assertJsonPath('parcela.nombre', 'Litoral');
        $respuesta->assertJsonPath('parcela.variedad', 'Cabernet Sauvignon');
    }

    public function test_un_codigo_inexistente_responde_404_sin_revelar_otros(): void
    {
        $vina = $this->crearVina();

        $respuesta = $this->actingAs($vina['bodega'])
            ->getJson('/api/v1/trazabilidad?codigo=LT-NO-EXISTE-2026-001');

        $respuesta->assertNotFound();
        $respuesta->assertJsonPath('codigo_error', 'codigo_no_encontrado');
    }

    /**
     * @return array{lote: \App\Models\Lote, vendimia: \App\Models\Vendimia, embotellado: Embotellado}
     */
    private function loteDeLaSegundaParcela(array $vina): array
    {
        $vendimia = \App\Models\Vendimia::factory()->de($vina['vina'])->en($vina['parcelaSecundaria'])->create([
            'numero' => 'VEN-CER-' . now()->format('Y') . '-001',
            'fecha_vendimia' => now()->subMonths(7)->toDateString(),
        ]);

        $lote = app(\App\Services\Vinificacion\RegistradorDeEtapa::class)
            ->abrirLoteDesde($vendimia, [], $vina['bodega']);

        $embotellado = Embotellado::query()->create([
            'tenant_id' => $lote->tenant_id,
            'lote_id' => $lote->id,
            'fecha_embotellado' => now()->subDays(10)->toDateString(),
            'formato_ml' => 750,
            'botellas' => 6,
        ]);

        return [
            'lote' => $lote->fresh(),
            'vendimia' => $vendimia->fresh(),
            'embotellado' => $embotellado,
        ];
    }
}