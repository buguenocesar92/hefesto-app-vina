<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RolOperador;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Persona que opera la viña.
 *
 * La contrasena y el segundo factor los resuelve Authentik. Aqui solo esta el
 * correo con que se cruza y el rol con que se autoriza.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $email
 * @property string $nombre
 * @property RolOperador $rol
 * @property bool $activo
 */
class Usuario extends Model
{
    use HasUuids;

    protected $table = 'usuarios';

    protected $fillable = [
        'tenant_id',
        'email',
        'nombre',
        'rol',
        'activo',
    ];

    protected $hidden = [
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'rol' => RolOperador::class,
            'activo' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function vendimias(): HasMany
    {
        return $this->hasMany(Vendimia::class, 'responsable_id');
    }

    public function etapas(): HasMany
    {
        return $this->hasMany(EtapaVinificacion::class, 'responsable_id');
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class, 'vendedor_id');
    }

    /** Lo que la interfaz necesita para mostrar el menu y los botones. */
    public function aResumen(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'nombre' => $this->nombre,
            'rol' => $this->rol->value,
            'puede_vendimiar' => $this->rol->puedeVendimiar(),
            'puede_vinificar' => $this->rol->puedeVinificar(),
            'puede_editar_campo' => $this->rol->puedeEditarCampo(),
            'puede_vender' => $this->rol->puedeVender(),
            'puede_descartar_lote' => $this->rol->puedeDescartarLote(),
            'puede_anular' => $this->rol->puedeAnular(),
            'puede_administrar' => $this->rol->puedeAdministrarConfiguracion(),
        ];
    }
}