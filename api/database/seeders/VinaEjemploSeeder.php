<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EtapaVinificacion;
use App\Models\Embotellado;
use App\Models\Lote;
use App\Models\Parcela;
use App\Models\Tenant;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Tenancy\ContextoTenant;
use App\Services\Vinificacion\RegistradorDeEtapa;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Una viña de ejemplo con una temporada completa.
 *
 * QUE CREA
 * --------
 * Una viña, las personas con cada rol, dos parcelas, una vendimia por
 * parcela, los lotes que salieron de ellas, sus etapas hasta donde llega cada
 * uno, un embotellado y una venta.
 *
 * POR QUE LA VENTA TAMBIEN
 * ------------------------
 * Para que al abrir la aplicacion se pueda recorrer el camino entero, desde
 * el campo hasta la botella que se vendio. Con datos sueltos, la pantalla de
 * trazabilidad se ve vacia y no hay forma de demostrar que hace lo que dice.
 *
 * LOS PRECIOS SON INVENTADOS
 * -------------------------
 * Los $18.500 por botella no son los de ninguna viña. Sirven para que la
 * caja calcule, no para tomar una decision.
 */
class VinaEjemploSeeder extends Seeder
{
    public function run(): void
    {
        $vina = Tenant::query()->create([
            'slug' => 'ejemplo-vina.cl',
            'nombre' => 'Viña Ejemplo',
            'razon_social' => 'Viña Ejemplo Ltda.',
            'activo' => true,
        ]);

        // El seeding escribe en varias tablas protegidas, asi que fija el
        // contexto de la viña que esta creando. Sin esto, la RLS rechazaria
        // cada escritura.
        DB::statement('SELECT set_config(?, ?, false)', [ContextoTenant::VARIABLE, $vina->id]);

        // --- Personas, una por rol -----------------------------------------

        $administrador = Usuario::factory()->administrador()->de($vina)->create([
            'email' => 'admin@ejemplo-vina.cl',
            'nombre' => 'Ana Pérez',
        ]);

        $encargado = Usuario::factory()->encargado()->de($vina)->create([
            'email' => 'encargado@ejemplo-vina.cl',
            'nombre' => 'Rodrigo Muñoz',
        ]);

        $bodega = Usuario::factory()->bodega()->de($vina)->create([
            'email' => 'bodega@ejemplo-vina.cl',
            'nombre' => 'María Fernández',
        ]);

        Usuario::factory()->piso()->de($vina)->create([
            'email' => 'piso@ejemplo-vina.cl',
            'nombre' => 'Javiera Soto',
        ]);

        // --- El campo ------------------------------------------------------

        $litoral = Parcela::factory()->de($vina)->create([
            'codigo' => 'LIT',
            'nombre' => 'Litoral',
            'variedad' => 'Cabernet Sauvignon',
            'superficie_hectareas' => 8.5,
            'ano_plantacion' => 1998,
            'comuna' => 'Maipú',
        ]);

        $cerroAlto = Parcela::factory()->de($vina)->create([
            'codigo' => 'CER',
            'nombre' => 'Cerro Alto',
            'variedad' => 'Carmenere',
            'superficie_hectareas' => 4.2,
            'ano_plantacion' => 2006,
            'comuna' => 'Maipú',
        ]);

        Parcela::factory()->de($vina)->create([
            'codigo' => 'QUE',
            'nombre' => 'Quebrada',
            'variedad' => 'Chardonnay',
            'superficie_hectareas' => 2.8,
            'ano_plantacion' => 2012,
            'comuna' => 'Casablanca',
        ]);

        // --- La vendimia del ano pasado -----------------------------------

        $anio = (int) now()->format('Y') - 1;

        $vendimiaLitoral = \App\Models\Vendimia::factory()->de($vina)->en($litoral)->create([
            'numero' => "VEN-LIT-{$anio}-001",
            'fecha_vendimia' => Carbon::create($anio, 3, 18)->toDateString(),
            'kilos' => 12400,
            'brix' => 24.8,
            'acidez' => 5.4,
            'responsable_id' => $encargado->id,
            'notas' => 'Cosecha en seco, sin lluvia en toda la semana.',
        ]);

        $vendimiaCerro = \App\Models\Vendimia::factory()->de($vina)->en($cerroAlto)->create([
            'numero' => "VEN-CER-{$anio}-001",
            'fecha_vendimia' => Carbon::create($anio, 4, 3)->toDateString(),
            'kilos' => 6300,
            // Esta vendimia NO tiene analisis. El ejemplo sirve para que se
            // vea en la interfaz que un brix sin medir se muestra como "sin
            // medir" y no como un numero inventado.
            'brix' => null,
            'acidez' => null,
            'responsable_id' => $encargado->id,
            'notas' => 'Se dejo dos dias mas en la uva por la lluvia del domingo.',
        ]);

        // --- Los lotes y su proceso ----------------------------------------

        $registrador = app(RegistradorDeEtapa::class);

        $loteReserva = $registrador->abrirLoteDesde(
            $vendimiaLitoral,
            ['nombre' => 'Reserva Litoral'],
            $encargado
        );

        // El Litoral va completo: pasa por barrica y sale embotellado.
        $this->avanzar(
            $registrador,
            $loteReserva,
            [
                EtapaVinificacion::DESTAJE => $anio,
                EtapaVinificacion::FERMENTACION => $anio,
                EtapaVinificacion::MACERACION => $anio,
                EtapaVinificacion::CLARIFICACION => $anio,
                EtapaVinificacion::ESTABILIZACION => $anio,
                EtapaVinificacion::BARRIQUEADO => $anio,
            ],
            $bodega
        );

        // La etapa `embotellado` va PRIMERO, igual que en
        // `LoteController::embotellar()`. El orden importa: el servicio rechaza
        // registrar una etapa en un lote cerrado, y forzar el estado antes
        // dejaria el lote en `embotellado` y la etapa rechazada.
        $this->registrarEtapa(
            $registrador,
            $loteReserva,
            EtapaVinificacion::EMBOTELLADO,
            Carbon::create($anio, 12, 10),
            $bodega,
            'Salida a botella del Litoral: 3600 botellas de 750 ml.'
        );

        $embotellado = Embotellado::query()->create([
            'tenant_id' => $loteReserva->tenant_id,
            'lote_id' => $loteReserva->id,
            'fecha_embotellado' => Carbon::create($anio, 12, 10)->toDateString(),
            'formato_ml' => 750,
            'botellas' => 3600,
        ]);

        // El Carmenere va a medias: esta estabilizado pero todavia no sale a
        // botella. Sirve para mostrar un lote en proceso en la pantalla.
        $loteCarmenere = $registrador->abrirLoteDesde(
            $vendimiaCerro,
            ['nombre' => 'Carmenere Cerro Alto'],
            $encargado
        );

        $this->avanzar(
            $registrador,
            $loteCarmenere,
            [
                EtapaVinificacion::DESTAJE => $anio,
                EtapaVinificacion::FERMENTACION => $anio,
                EtapaVinificacion::MACERACION => $anio,
                EtapaVinificacion::CLARIFICACION => $anio,
                EtapaVinificacion::ESTABILIZACION => $anio,
            ],
            $bodega
        );

        // No se fuerza el estado: al registrar `estabilizacion` el servicio ya
        // deja el lote en `estabilizado`. Ponerlo a mano seria acortar el
        // camino y mostrar que el seeder sabe algo que el servicio no.
        $loteCarmenere->refresh();

        // --- Una venta, para ver el camino completo ------------------------

        Venta::query()->create([
            'tenant_id' => $vina->id,
            'vendedor_id' => $bodega->id,
            'numero' => 'VE-' . now()->format('Y') . '-000001',
            'total' => 6 * 18500,
            'estado' => \App\Enums\EstadoVenta::PAGADA,
            'abierta_en' => now()->subDays(20),
            'cerrada_en' => now()->subDays(20)->addMinutes(12),
            'cliente_nombre' => 'Distribuidora Andes',
            'cliente_contacto' => 'pedidos@ejemplo.invalido',
        ]);

        $venta = Venta::query()->orderByDesc('created_at')->first();

        \App\Models\VentaItem::query()->create([
            'tenant_id' => $vina->id,
            'venta_id' => $venta->id,
            'embotellado_id' => $embotellado->id,
            'botellas' => 6,
            'precio_unitario' => 18500,
        ]);

        \App\Models\Pago::query()->create([
            'tenant_id' => $vina->id,
            'venta_id' => $venta->id,
            'metodo' => \App\Enums\MetodoPago::TRANSFERENCIA,
            'monto' => 6 * 18500,
            'pagado_en' => now()->subDays(20)->addMinutes(12),
        ]);

        // Lotes que se quedaron en la base sin embotellar, para que el
        // listado no salga todo en un solo estado.
        Lote::query()->where('id', $loteCarmenere->id)->update([
            'nombre' => 'Carmenere Cerro Alto (sin embotellar)',
        ]);

        $this->command?->info('Viña de ejemplo creada: ' . $vina->nombre);
        $this->command?->info('  Parcelas: 3. Vendimias de ' . $anio . ': 2.');
        $this->command?->info('  Lotes: ' . Lote::query()->count() . '. Embotellados: ' . $embotellado->botellas . ' botellas.');
        $this->command?->info('  Entra como admin@ejemplo-vina.cl (la cuenta debe existir tambien en Authentik).');
    }

    /**
     * Avanza un lote por las etapas indicadas.
     *
     * @param  array<EtapaVinificacion, int>  $etapasConAnio
     */
    private function avanzar(
        RegistradorDeEtapa $registrador,
        Lote $lote,
        array $etapasConAnio,
        Usuario $quien
    ): void {
        $mes = 4;

        foreach ($etapasConAnio as $etapa => $anio) {
            $mes++;

            $this->registrarEtapa(
                $registrador,
                $lote,
                $etapa,
                Carbon::create($anio, min($mes, 12), 10),
                $quien
            );
        }
    }

    private function registrarEtapa(
        RegistradorDeEtapa $registrador,
        Lote $lote,
        EtapaVinificacion $etapa,
        Carbon $fecha,
        Usuario $quien,
        ?string $notas = null
    ): void {
        $registrador->registrar($lote, [
            'etapa' => $etapa->value,
            'fecha' => $fecha->toDateString(),
            'notas' => $notas,
        ], $quien);

        $lote->refresh();
    }
}