<?php

declare(strict_types=1);

namespace App\Services\Ventas;

use App\Enums\EstadoVenta;
use App\Enums\MetodoPago;
use App\Exceptions\ErrorDeVina;
use App\Models\Embotellado;
use App\Models\Lote;
use App\Models\Pago;
use App\Models\Usuario;
use App\Models\Venta;
use App\Models\VentaItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Registrar y cobrar una venta de vino embotellado.
 *
 * POR QUE UNA VENTA APUNTA AL EMBOTELLADO Y NO AL LOTE
 * ---------------------------------------------------
 * Podria apuntar al lote, y seria mas corto. Apunta al embotellado porque
 * esa es la unidad real que sale de la bodega, y porque es lo que deja el
 * camino intacto hacia la trazabilidad sin duplicar ningun dato.
 *
 * El efecto practico es que, desde cualquier venta, se puede volver hasta la
 * parcela y la fecha de vendimia sin que nadie mantenga una columna de
 * procedencia aparte.
 *
 * TODO PASA EN UNA SOLA TRANSACCION
 * --------------------------------
 * Si los items se escribieran y el total no llegara a calcularse, o al reves,
 * la caja quedaria descuadrada en una de las dos mitades. Por eso el metodo
 * abre su propia transaccion.
 *
 * Ver docs/09-API.md.
 */
class RegistradorDeVenta
{
    /**
     * Abre una venta con sus items.
     *
     * @param  array{
     *     items: array<int, array{embotellado_id: string, botellas: int, precio_unitario: float}>,
     *     cliente_nombre?: string|null,
     *     cliente_contacto?: string|null,
     *     notas?: string|null
     * }  $datos
     */
    public function abrir(array $datos, Usuario $vendedor): Venta
    {
        if ($datos['items'] === []) {
            throw new ErrorDeVina(
                'Una venta tiene que tener al menos una botella.',
                'venta_sin_items'
            );
        }

        // Se comprueba TODO antes de escribir nada. Si a la tercera botella no
        // hay stock, no queda una venta a medio escribir.
        foreach ($datos['items'] as $item) {
            $this->comprobarBotellas($item['embotellado_id'], (int) $item['botellas']);
        }

        return DB::transaction(function () use ($datos, $vendedor): Venta {
            $venta = Venta::query()->create([
                'tenant_id' => $vendedor->tenant_id,
                'vendedor_id' => $vendedor->id,
                'numero' => $this->numeroDeVenta($vendedor->tenant_id),
                'total' => 0,
                'estado' => EstadoVenta::ABIERTA,
                'abierta_en' => now(),
                'cliente_nombre' => $datos['cliente_nombre'] ?? null,
                'cliente_contacto' => $datos['cliente_contacto'] ?? null,
                'notas' => $datos['notas'] ?? null,
            ]);

            $total = 0.0;

            foreach ($datos['items'] as $item) {
                $precio = (float) $item['precio_unitario'];

                VentaItem::query()->create([
                    'tenant_id' => $vendedor->tenant_id,
                    'venta_id' => $venta->id,
                    'embotellado_id' => $item['embotellado_id'],
                    'botellas' => (int) $item['botellas'],
                    'precio_unitario' => $precio,
                ]);

                $total += (int) $item['botellas'] * $precio;
            }

            $venta->total = $total;
            $venta->save();

            return $venta->fresh(['items.embotellado.lote.vendimia.parcela']);
        });
    }

    /**
     * Cobra una venta.
     *
     * Una venta abierta por mas del maximo se rechaza: si alguien abre una
     * venta y se va, la caja queda descuadrada. El mensaje dice cuantos
     * minutos lleva abierta.
     *
     * @param  array{metodo: string, monto: float}  $datos
     */
    public function cobrar(Venta $venta, array $datos): Venta
    {
        if (! $venta->estado->admitePago()) {
            throw ErrorDeVina::estadoInvalido(
                $venta->estado->value,
                EstadoVenta::PAGADA->value
            );
        }

        $minutos = $venta->abierta_en->diffInMinutes(Carbon::now());
        $maximo = (int) config('vina.ventas.minutos_maximos');

        if ($minutos > $maximo) {
            throw ErrorDeVina::ventaAbiertaDemasiadoTiempo((string) $minutos);
        }

        return DB::transaction(function () use ($venta, $datos): Venta {
            Pago::query()->create([
                'tenant_id' => $venta->tenant_id,
                'venta_id' => $venta->id,
                'metodo' => MetodoPago::from($datos['metodo']),
                'monto' => (float) $datos['monto'],
                'pagado_en' => now(),
            ]);

            $venta->estado = EstadoVenta::PAGADA;
            $venta->cerrada_en = now();
            $venta->save();

            return $venta->fresh(['items.embotellado.lote.vendimia.parcela', 'pagos']);
        });
    }

    /**
     * Anula una venta.
     *
     * NO BORRA NADA: la venta queda con su estado `anulada` y sus items. La
     * razon es la misma de siempre: una venta anulada es informacion de que
     * existio una operacion que no se llevo a cabo, y borrarla seria dejar
     * el mismo hueco invisible que el sistema existe para tapar.
     */
    public function anular(Venta $venta): Venta
    {
        if ($venta->estado === EstadoVenta::PAGADA) {
            throw new ErrorDeVina(
                "La venta {$venta->numero} ya esta pagada: no se anula. Registra el cobro contrario.",
                'venta_ya_pagada'
            );
        }

        $venta->estado = EstadoVenta::ANULADA;
        $venta->cerrada_en = now();
        $venta->save();

        return $venta->fresh(['items.embotellado.lote.vendimia.parcela']);
    }

    /**
     * Que no se venda vino que no sale de un lote embotellado.
     *
     * El `CHECK` de la base ya impide vender sin `embotellado_id` (la columna
     * es NOT NULL). Esto es la otra mitad: que ese embotellado sea REAL y que
     * el lote detras exista y este embotellado.
     */
    private function comprobarBotellas(string $embotelladoId, int $pedidas): void
    {
        $embotellado = Embotellado::query()->findOrFail($embotelladoId);

        $lote = $embotellado->lote()->first();

        if ($lote === null) {
            throw ErrorDeVina::ventaSinEmbotellado();
        }

        if (! $lote->estado->puedeVenderse()) {
            throw ErrorDeVina::loteNoVenduible($lote->codigo);
        }

        $vendidas = (int) VentaItem::query()
            ->where('embotellado_id', $embotelladoId)
            ->whereHas('venta', fn ($q) => $q->where('estado', '!=', EstadoVenta::ANULADA->value))
            ->sum('botellas');

        $disponibles = (int) $embotellado->botellas - $vendidas;

        if ($pedidas > $disponibles) {
            throw ErrorDeVina::sinBotellas($lote->codigo, $pedidas, $disponibles);
        }
    }

    /** `VE-<anio>-<consecutivo>`. */
    private function numeroDeVenta(string $tenantId): string
    {
        $prefijo = 'VE-' . now()->format('Y') . '-';

        $ultimo = Venta::query()
            ->where('tenant_id', $tenantId)
            ->where('numero', 'like', $prefijo . '%')
            ->orderByDesc('numero')
            ->value('numero');

        $consecutivo = $ultimo === null ? 1 : ((int) substr($ultimo, strlen($prefijo))) + 1;

        return $prefijo . str_pad((string) $consecutivo, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Las botellas disponibles de un embotellado.
     *
     * El numero que se le muestra a quien vende. Descuenta lo ya vendido en
     * ventas que NO estan anuladas: una venta anulada devolvio las botellas.
     */
    public function disponiblesDe(Embotellado $embotellado): int
    {
        $vendidas = (int) VentaItem::query()
            ->where('embotellado_id', $embotellado->id)
            ->whereHas('venta', fn ($q) => $q->where('estado', '!=', EstadoVenta::ANULADA->value))
            ->sum('botellas');

        return (int) $embotellado->botellas - $vendidas;
    }

    /** La trazabilidad de todos los lotes que aparecen en una venta. */
    public function origenesDe(Venta $venta): array
    {
        return $venta->items
            ->map(fn (VentaItem $item) => [
                'embotellado_id' => $item->embotellado_id,
                'botellas' => (int) $item->botellas,
                'lote_codigo' => $item->embotellado?->lote?->codigo,
                'linea_de_origen' => $item->embotellado?->lote?->lineaDeOrigen(),
            ])
            ->all();
    }

    /**
     * Todos los lotes de un mismo vino, para agrupar en el despacho.
     *
     * @return \Illuminate\Support\Collection<int, Lote>
     */
    public function lotesDe(Venta $venta)
    {
        return Lote::query()
            ->whereIn('id', $venta->items->pluck('embotellado.lote_id')->filter())
            ->get();
    }
}