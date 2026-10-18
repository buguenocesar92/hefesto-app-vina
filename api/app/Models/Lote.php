<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoLote;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lote: el vino que sale de una vendimia.
 *
 * UN LOTE, UNA VENDIMIA
 * ---------------------
 * `vendimia_id` no es una referencia decorativa: es el eslabon que hace
 * posible responder de donde viene el vino. Sin ella, un lote seria un
 * borrowing de vino sin origen, que es exactamente lo que un sistema de
 * trazabilidad no puede permitir.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $vendimia_id
 * @property string $codigo
 * @property int $anio
 * @property string|null $nombre
 * @property EstadoLote $estado
 * @property float $litros
 */
class Lote extends Model
{
    use HasUuids;

    protected $table = 'lotes';

    protected $fillable = [
        'tenant_id',
        'vendimia_id',
        'codigo',
        'anio',
        'nombre',
        'estado',
        'litros',
    ];

    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'estado' => EstadoLote::class,
            'litros' => 'float',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** El origen. Siempre presente: la base no permite un lote sin el. */
    public function vendimia(): BelongsTo
    {
        return $this->belongsTo(Vendimia::class);
    }

    /** Las etapas, en el orden en que se registraron. */
    public function etapas(): HasMany
    {
        return $this->hasMany(EtapaVinificacion::class, 'lote_id')->orderBy('fecha');
    }

    public function embotellados(): HasMany
    {
        return $this->hasMany(Embotellado::class, 'lote_id');
    }

    /** El origen en una linea, o null si la cadena esta rota. */
    public function lineaDeOrigen(): ?string
    {
        $vendimia = $this->vendimia()->with('parcela')->first();

        if ($vendimia === null || $vendimia->parcela === null) {
            return null;
        }

        return sprintf(
            '%s - %s, vendimia del %s',
            $vendimia->parcela->nombre,
            $vendimia->parcela->variedad,
            $vendimia->fecha_vendimia?->toDateString()
        );
    }

    public function estaCerrado(): bool
    {
        return $this->estado->estaCerrado();
    }

    public function puedeEmbotellarse(): bool
    {
        return $this->estado->puedeEmbotellarse();
    }
}