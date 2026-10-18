<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El momento en que un lote sale a botella.
 *
 * Es el ultimo eslabon de la trazabilidad y el unico que llega al cliente.
 * Un `embotellado` sabe de que lote vino, el lote de que vendimia, y la
 * vendimia de que parcela: ese camino es la promesa del sistema.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $lote_id
 * @property \Illuminate\Support\Carbon $fecha_embotellado
 * @property int $formato_ml
 * @property int $botellas
 */
class Embotellado extends Model
{
    use HasUuids;

    protected $table = 'embotellados';

    protected $fillable = [
        'tenant_id',
        'lote_id',
        'fecha_embotellado',
        'formato_ml',
        'botellas',
    ];

    protected function casts(): array
    {
        return [
            'fecha_embotellado' => 'date',
            'formato_ml' => 'integer',
            'botellas' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    /** Las ventas que salieron de este embotellado. */
    public function ventaItems(): HasMany
    {
        return $this->hasMany(VentaItem::class, 'embotellado_id');
    }
}