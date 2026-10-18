<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MetodoPago;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un pago de una venta.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $venta_id
 * @property MetodoPago $metodo
 * @property float $monto
 * @property \Illuminate\Support\Carbon $pagado_en
 */
class Pago extends Model
{
    use HasUuids;

    protected $table = 'pagos';

    protected $fillable = [
        'tenant_id',
        'venta_id',
        'metodo',
        'monto',
        'pagado_en',
    ];

    protected function casts(): array
    {
        return [
            'metodo' => MetodoPago::class,
            'monto' => 'float',
            'pagado_en' => 'datetime',
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
}