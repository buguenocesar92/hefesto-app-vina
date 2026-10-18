<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Parcela;
use App\Models\Tenant;
use App\Models\Usuario;
use App\Services\Tenancy\ContextoTenant;
use App\Services\Vinificacion\RegistradorDeEtapa;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/**
 * Base de las pruebas de la viña.
 *
 * Aporta lo que mas se prueba:
 *
 *  - `comoUsuario()`, que autentica a una persona Y fija su contexto de viña
 *    en la base. IMPORTANTE: `actingAs()` por si solo NO fija el contexto,
 *    porque el middleware `ResolverTenant` corre dentro del grupo `web`, que
 *    estas pruebas no ejecutan. Sin fijar el contexto, la RLS no tendria
 *    contra que filtrar y las pruebas de aislamiento no probarian nada.
 *
 *  - `crearVina()`, que arma una viña con personas y parcelas listas para
 *    operar.
 *
 *  - `loteCompleto()`, que lleva un lote hasta embotellado siguiendo las
 *    etapas reales, porque un lote embotellado a proposito no es un lote: es
 *    un atajo que ninguna prueba de proceso deberia usar.
 */
abstract class TestCase extends BaseTestCase
{
    /** Viña fijada para la prueba en curso. */
    protected ?string $vinaDePrueba = null;

    /**
     * Autentica a una persona y fija su contexto de viña en la base.
     */
    protected function comoUsuario(Usuario $usuario): static
    {
        $this->actingAs($usuario);

        return $this->fijarVina($usuario->tenant_id);
    }

    /**
     * Emula lo que hace `App\Http\Middleware\ResolverTenant`.
     *
     * Se usa `set_config(..., false)`, o sea con alcance de SESION y no de
     * transaccion. En la aplicacion real es al reves (alcance de
     * transaccion, para que el worker de colas no arrastre la viña de otra
     * peticion). En las pruebas cada caso abre su propia aplicacion y su
     * propia conexion, asi que el valor no sobrevive entre pruebas.
     */
    protected function fijarVina(?string $vinaId): static
    {
        if ($vinaId === null) {
            return $this;
        }

        DB::select('SELECT set_config(?, ?, false)', [ContextoTenant::VARIABLE, $vinaId]);

        $this->vinaDePrueba = $vinaId;

        return $this;
    }

    /**
     * Crea una viña con todo lo minimo para operar.
     *
     * @return array{
     *     vina: Tenant,
     *     administrador: Usuario,
     *     encargado: Usuario,
     *     bodega: Usuario,
     *     piso: Usuario,
     *     auditor: Usuario,
     *     parcela: Parcela,
     *     parcelaSecundaria: Parcela
     * }
     */
    protected function crearVina(string $slug = 'ejemplo.cl'): array
    {
        $vina = Tenant::factory()->create([
            'slug' => $slug,
            'nombre' => 'Viña ' . $slug,
        ]);

        $this->fijarVina($vina->id);

        $administrador = Usuario::factory()->administrador()->de($vina)->create([
            'email' => 'admin@' . $slug,
        ]);

        $encargado = Usuario::factory()->encargado()->de($vina)->create([
            'email' => 'encargado@' . $slug,
        ]);

        $bodega = Usuario::factory()->bodega()->de($vina)->create([
            'email' => 'bodega@' . $slug,
        ]);

        $piso = Usuario::factory()->piso()->de($vina)->create([
            'email' => 'piso@' . $slug,
        ]);

        $auditor = Usuario::factory()->auditor()->de($vina)->create([
            'email' => 'auditor@' . $slug,
        ]);

        // El Cabernet Sauvignon del Litoral: la parcela que produce la
        // vendimia y el lote en casi todas las pruebas.
        $parcela = Parcela::factory()->de($vina)->create([
            'codigo' => 'LIT',
            'nombre' => 'Litoral',
            'variedad' => 'Cabernet Sauvignon',
        ]);

        $parcelaSecundaria = Parcela::factory()->de($vina)->create([
            'codigo' => 'CER',
            'nombre' => 'Cerro Alto',
            'variedad' => 'Carmenere',
        ]);

        return [
            'vina' => $vina,
            'administrador' => $administrador,
            'encargado' => $encargado,
            'bodega' => $bodega,
            'piso' => $piso,
            'auditor' => $auditor,
            'parcela' => $parcela,
            'parcelaSecundaria' => $parcelaSecundaria,
        ];
    }

    /**
     * Lleva un lote hasta embotellado siguiendo las etapas de verdad.
     *
     * ESTA ES LA PRUEBA DE QUE EL PROCESO SE PUEDE HACER
     * ---------------------------------------------------
     * Va etapa por etapa con el servicio real, no insertando el lote ya
     * embotellado. Si el servicio dejara pasar una etapa, esta funcion se
     * caeria, y con ella todas las pruebas que necesitan un lote listo.
     *
     * @return array{
     *     lote: \App\Models\Lote,
     *     vendimia: \App\Models\Vendimia,
     *     embotellado: \App\Models\Embotellado
     * }
     */
    protected function loteCompleto(
        array $vina,
        int $botellas = 12,
        int $formatoMl = 750,
    ): array {
        $registrador = app(RegistradorDeEtapa::class);

        $vendimia = \App\Models\Vendimia::factory()->de($vina['vina'])->create([
            'parcela_id' => $vina['parcela']->id,
            'numero' => 'VEN-LIT-' . now()->format('Y') . '-001',
            'fecha_vendimia' => now()->subMonths(8)->toDateString(),
            'kilos' => 1500,
            'brix' => 24.5,
            'acidez' => 5.8,
            'responsable_id' => $vina['bodega']->id,
        ]);

        $lote = $registrador->abrirLoteDesde($vendimia, [
            'nombre' => 'Reserva Litoral',
        ], $vina['bodega']);

        // Las etapas obligatorias, en orden. Cada una la mueve el servicio,
        // incluido el estado: al registrar `estabilizacion` el lote queda
        // `estabilizado` solo. Forzar el estado a mano aca seria falsear el
        // proceso en la propia prueba que lo verifica.
        foreach (['destaje', 'fermentacion', 'maceracion', 'clarificacion', 'estabilizacion'] as $etapa) {
            $registrador->registrar($lote, [
                'etapa' => $etapa,
                'fecha' => now()->subMonths(2)->toDateString(),
                'notas' => 'Etapa de prueba: ' . $etapa,
            ], $vina['bodega']);

            $lote->refresh();
        }

        // El embotellado va por el mismo camino que el seeder y que el
        // controlador: primero la etapa, despues la fila.
        $registrador->registrar($lote, [
            'etapa' => 'embotellado',
            'fecha' => now()->subDays(30)->toDateString(),
            'notas' => 'Embotellado de prueba.',
        ], $vina['bodega']);

        $embotellado = \App\Models\Embotellado::query()->create([
            'tenant_id' => $lote->tenant_id,
            'lote_id' => $lote->fresh()->id,
            'fecha_embotellado' => now()->subDays(30)->toDateString(),
            'formato_ml' => $formatoMl,
            'botellas' => $botellas,
        ]);

        return [
            'lote' => $lote->fresh(['vendimia.parcela']),
            'vendimia' => $vendimia->fresh(),
            'embotellado' => $embotellado,
        ];
    }

    /**
     * Lanza la excepcion de base que se espera, sin repetir el try/catch.
     */
    protected function esperarFalloDeBase(callable $operacion): void
    {
        try {
            $operacion();
        } catch (QueryException) {
            return;
        }

        $this->fail('Se esperaba un fallo de PostgreSQL y la operacion paso sin problema.');
    }
}