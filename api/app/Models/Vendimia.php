<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoVendimia;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Vendimia: la uva de una parcela, en una fecha.
 *
 * LA FECHA ES EL DATO QUE SOSTIENE LA TRAZABILIDAD
 * -----------------------------------------------
 * `fecha_vendimia` es la fecha real de la cosecha, y hay un gatillo en la base
 * que impide cambiarla cuando la vendimia ya tiene un lote. No es una
 * minucia de orden: es lo que permite decir despues "este vino es de la
 * vendimia del 12 de marzo", y esa frase no valeria nada si el dato se
 * pudiera corregir cuando deja de convenir.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $parcela_id
 * @property string $numero
 * @property \Illuminate\Support\Carbon $fecha_vendimia
 * @property float $kilos
 * @property float|null $brix
 * @property float|null $acidez
 * @property EstadoVendimia $estado
 * @property string|null $responsable_id
 * @property string|null $notas
 */
class Vendimia extends Model
{
    use HasUuids;

    protected $table = 'vendimias';

    protected $fillable = [
        'tenant_id',
        'parcela_id',
        'numero',
        'fecha_vendimia',
        'kilos',
        'brix',
        'acidez',
        'estado',
        'responsable_id',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'fecha_vendimia' => 'date',
            'kilos' => 'float',
            // Un brix sin medir es NULL, no cero. Un cero seria un dato
            // falso: significaria "se midio y dio cero".
            'brix' => 'float',
            'acidez' => 'float',
            'estado' => EstadoVendimia::class,
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function parcela(): BelongsTo
    {
        return $this->belongsTo(Parcela::class);
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'responsable_id');
    }

    /**
     * El lote que salio de esta vendimia.
     *
     * `hasOne`, no `hasMany`: una vendimia da UN lote. Vinificar dos veces la
     * misma uva es una perdida de trazabilidad disfrazada de prioridad.
     */
    public function lote(): HasOne
    {
        return $this->hasOne(Lote::class);
    }

    /** `true` si esta vendimia ya se vinifico. */
    public function yaFueVinificada(): bool
    {
        return $this->lote()->exists();
    }
}