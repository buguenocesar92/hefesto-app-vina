<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoVenta;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Venta de vino embotellado.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $vendedor_id
 * @property string $numero
 * @property float $total
 * @property EstadoVenta $estado
 * @property \Illuminate\Support\Carbon $abierta_en
 * @property \Illuminate\Support\Carbon|null $cerrada_en
 * @property string|null $cliente_nombre
 * @property string|null $cliente_contacto
 * @property string|null $notas
 */
class Venta extends Model
{
    use HasUuids;

    protected $table = 'ventas';

    protected $fillable = [
        'tenant_id',
        'vendedor_id',
        'numero',
        'total',
        'estado',
        'abierta_en',
        'cerrada_en',
        'cliente_nombre',
        'cliente_contacto',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'float',
            'estado' => EstadoVenta::class,
            'abierta_en' => 'datetime',
            'cerrada_en' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'vendedor_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(VentaItem::class, 'venta_id');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'venta_id');
    }

    public function estaCerrada(): bool
    {
        return $this->estado->estaCerrada();
    }

    /** Los minutos que lleva abierta. Se usa para avisar de la caja abierta. */
    public function minutosAbierta(): int
    {
        if ($this->cerrada_en !== null) {
            return 0;
        }

        return (int) $this->abierta_en->diffInMinutes(now());
    }
}