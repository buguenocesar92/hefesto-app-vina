<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Las botellas de una venta.
 *
 * POR QUE GUARDA EL EMBOTELLADO Y NO EL LOTE
 * ------------------------------------------
 * Podria apuntar al lote, y seria mas corto. Apunta al EMBOTELLADO porque esa
 * es la unidad real que sale de la bodega: un lote puede embotellarse dos
 * veces (una en.predeterminada y otra en un formato especial) y cada
 * embotellado tiene su fecha y su cantidad de botellas. Guardar el
 * embotellado es lo que deja el camino intacto hacia la trazabilidad sin
 * ningun dato duplicado.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $venta_id
 * @property string $embotellado_id
 * @property int $botellas
 * @property float $precio_unitario
 */
class VentaItem extends Model
{
    use HasUuids;

    protected $table = 'venta_items';

    protected $fillable = [
        'tenant_id',
        'venta_id',
        'embotellado_id',
        'botellas',
        'precio_unitario',
    ];

    protected function casts(): array
    {
        return [
            'botellas' => 'integer',
            'precio_unitario' => 'float',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function embotellado(): BelongsTo
    {
        return $this->belongsTo(Embotellado::class);
    }

    public function subtotal(): float
    {
        return (float) $this->botellas * (float) $this->precio_unitario;
    }
}