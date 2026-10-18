<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EtapaVinificacion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una etapa que un lote ya paso.
 *
 * INMUTABLE, Y NO POR PURA BUENA VOLUNTAD
 * ---------------------------------------
 * Hay un gatillo en la base que revienta cualquier `UPDATE` o `DELETE` sobre
 * esta tabla. Es la garantia de que "un lote mal criado no se puede deshacer"
 * no es solo una frase: el historial de un proceso de vinificacion es un
 * registro, y un registro no se reescribe.
 *
 * Para corregir un error de tipeo en una fecha, se registra la etapa
 * correcta y la anterior queda como lo que ocurrio.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $lote_id
 * @property EtapaVinificacion $etapa
 * @property \Illuminate\Support\Carbon $fecha
 * @property string|null $responsable_id
 * @property string|null $notas
 */
class EtapaVinificacion extends Model
{
    use HasUuids;

    protected $table = 'etapas_vinificacion';

    protected $fillable = [
        'tenant_id',
        'lote_id',
        'etapa',
        'fecha',
        'responsable_id',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'etapa' => EtapaVinificacion::class,
            'fecha' => 'date',
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

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'responsable_id');
    }
}