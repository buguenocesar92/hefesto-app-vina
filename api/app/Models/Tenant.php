<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Viña usuaria del sistema.
 *
 * Es la unica tabla sin `tenant_id` y sin Row Level Security: se lee en el
 * acceso, antes de que exista sesion. Ver docs/03-MULTITENANCY-Y-ROLES.md.
 *
 * @property string $id
 * @property string $slug
 * @property string $nombre
 * @property string|null $razon_social
 * @property bool $activo
 */
class Tenant extends Model
{
    use HasUuids;

    protected $table = 'tenants';

    protected $fillable = [
        'slug',
        'nombre',
        'razon_social',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class);
    }

    public function parcelas(): HasMany
    {
        return $this->hasMany(Parcela::class);
    }

    public function vendimias(): HasMany
    {
        return $this->hasMany(Vendimia::class);
    }

    public function lotes(): HasMany
    {
        return $this->hasMany(Lote::class);
    }

    /**
     * Lee SOLO la vista publica.
     *
     * Es el unico punto del sistema donde se leen datos de una viña sin
     * sesion, y lo hace por la vista de tres columnas, no por la tabla.
     * Quien llama a este metodo tiene que ser el selector del acceso.
     */
    public static function publicasActivas()
    {
        return DB::table('vw_vinas_publicas')
            ->select(['slug', 'nombre'])
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();
    }

    public function buscarPorSlug(string $slug): ?self
    {
        return self::query()->where('slug', $slug)->first();
    }
}