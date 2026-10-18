<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Parcela: un bloque del viñedo, con una variedad.
 *
 * La unidad de la trazabilidad. Todo el modulo arranca aqui: de la parcela
 * sale la vendimia, de la vendimia el lote, del lote el vino. Si la parcela no
 * esta escrita con su nombre y su variedad, no hay nada que decir despues
 * de donde vino el vino.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $codigo
 * @property string $nombre
 * @property string $variedad
 * @property float|null $superficie_hectareas
 * @property int|null $ano_plantacion
 * @property string|null $comuna
 * @property bool $activo
 */
class Parcela extends Model
{
    use HasUuids;

    protected $table = 'parcelas';

    protected $fillable = [
        'tenant_id',
        'codigo',
        'nombre',
        'variedad',
        'superficie_hectareas',
        'ano_plantacion',
        'comuna',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'superficie_hectareas' => 'float',
            'ano_plantacion' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function vendimias(): HasMany
    {
        return $this->hasMany(Vendimia::class);
    }

    /** Una parcela inactiva no aparece para elegir al vendimiar. */
    public function estaActiva(): bool
    {
        return $this->activo;
    }
}