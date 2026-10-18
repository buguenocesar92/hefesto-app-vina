<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Niveles de autorizacion de la viña.
 *
 * LA SEPARACION QUE IMPORTA
 * -------------------------
 * Un `bodega` vinifica y registra etapas, pero no puede descartar un lote ni
 * cambiar los datos del campo. Un `piso` (encargado de ventas) vende, pero
 * no toca la trazabilidad: si pudiera cambiar una parcela o una vendimia,
 * podria mover el origen de un vino y ahi la trazabilidad deja de significar
 * nada.
 *
 * `ENCARGADO` es el unico rol que descarta lotes y edita el campo.
 * `ADMINISTRADOR` el unico que toca el catalogo y las personas.
 *
 * El rol decide que se MUESTRA en el menu; la autorizacion real la hace la
 * Policy del servidor. Ver docs/03-MULTITENANCY-Y-ROLES.md.
 */
enum RolOperador: string
{
    /** Ve todo y no cambia nada. Sirve para auditar. */
    case AUDITOR = 'auditor';

    /** Vende en el piso de ventas y cobra. */
    case PISO = 'piso';

    /** Vendimia y vinifica: registra etapas y embotella. */
    case BODEGA = 'bodega';

    /** Ademas de lo anterior: descarta lotes y edita parcelas. */
    case ENCARGADO = 'encargado';

    /** Configura el catalogo, las parcelas y las personas con su rol. */
    case ADMINISTRADOR = 'administrador';

    /** Cosechar y registrar etapas de vinificacion. */
    public function puedeVendimiar(): bool
    {
        return in_array(
            $this,
            [self::BODEGA, self::ENCARGADO, self::ADMINISTRADOR],
            true
        );
    }

    /** Registrar una etapa del proceso. */
    public function puedeVinificar(): bool
    {
        return in_array(
            $this,
            [self::BODEGA, self::ENCARGADO, self::ADMINISTRADOR],
            true
        );
    }

    /** Editar parcelas, vendimias y el anada. */
    public function puedeEditarCampo(): bool
    {
        return in_array($this, [self::ENCARGADO, self::ADMINISTRADOR], true);
    }

    public function puedeVender(): bool
    {
        return in_array(
            $this,
            [self::PISO, self::BODEGA, self::ENCARGADO, self::ADMINISTRADOR],
            true
        );
    }

    /**
     * Descartar un lote: declararlo perdido.
     *
     * `ENCARGADO` y `ADMINISTRADOR` nomas. Es una decision con consecuencia
     * economica y de registro: el lote queda marcado como perdido para
     * siempre, y el ano que viene se mira esta columna para saber cuanto se
     * perdio.
     */
    public function puedeDescartarLote(): bool
    {
        return in_array($this, [self::ENCARGADO, self::ADMINISTRADOR], true);
    }

    /** Anular una venta. */
    public function puedeAnular(): bool
    {
        return in_array($this, [self::ENCARGADO, self::ADMINISTRADOR], true);
    }

    /** Tocar el catalogo, las parcelas y las personas. */
    public function puedeAdministrarConfiguracion(): bool
    {
        return $this === self::ADMINISTRADOR;
    }

    public function puedeAdministrarPersonas(): bool
    {
        return $this === self::ADMINISTRADOR;
    }
}