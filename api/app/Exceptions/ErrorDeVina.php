<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Error del negocio de la viña: la etapa no es la siguiente, el lote no esta
 * estabilizado, la vendimia ya tiene lote.
 *
 * No es un error del sistema ni una falla de conexion: es una situacion que
 * la bodega tiene que resolver. Por eso se devuelve 422 con un codigo
 * estable, para que la interfaz pueda reaccionar sin adivinar. Ver
 * docs/09-API.md.
 */
class ErrorDeVina extends RuntimeException
{
    public function __construct(
        string $mensaje,
        private readonly string $codigo = 'error_de_vina'
    ) {
        parent::__construct($mensaje);
    }

    /** Codigo estable que la interfaz usa, no el texto. */
    public function codigo(): string
    {
        return $this->codigo;
    }

    /**
     * Se registro una etapa que no es la siguiente.
     *
     * El mensaje dice cual era la siguiente: un error de proceso se corrige
     * leyendo el mensaje, no adivinando.
     */
    public static function etapaFueraDeOrden(string $pedida, string $esperada): self
    {
        return new self(
            "No se puede registrar la etapa «{$pedida}» todavia. Este lote va en «{$esperada}».",
            'etapa_fuera_de_orden'
        );
    }

    /** La etapa ya se registro: una etapa, una vez. */
    public static function etapaYaRegistrada(string $etapa): self
    {
        return new self(
            "La etapa «{$etapa}» ya esta registrada en este lote. Las etapas no se repiten.",
            'etapa_ya_registrada'
        );
    }

    /** El lote ya termino o se perdio. */
    public static function loteCerrado(string $codigo): self
    {
        return new self(
            "El lote {$codigo} ya no admite etapas: esta embotellado o fue descartado.",
            'lote_cerrado'
        );
    }

    /** Un lote solo sale a botella cuando esta estabilizado. */
    public static function loteNoEstabilizado(string $codigo): self
    {
        return new self(
            "El lote {$codigo} todavia no esta estabilizado. Sin estabilizacion no sale a botella.",
            'lote_no_estabilizado'
        );
    }

    /** La vendimia ya tiene un lote: una vendimia, un lote. */
    public static function vendimiaYaVinificada(string $numeroVendimia): self
    {
        return new self(
            "La vendimia {$numeroVendimia} ya tiene un lote. Una vendimia no se vinifica dos veces.",
            'vendimia_ya_vinificada'
        );
    }

    /** La vendimia fue descartada. */
    public static function vendimiaDescartada(string $numeroVendimia): self
    {
        return new self(
            "La vendimia {$numeroVendimia} esta descartada: no se puede vinificar.",
            'vendimia_descartada'
        );
    }

    /** La fecha de vendimia es el origen de la trazabilidad. */
    public static function fechaVendimiaInmutable(): self
    {
        return new self(
            'La fecha de vendimia no se puede cambiar cuando ya hay un lote: es el origen de la trazabilidad.',
            'fecha_vendimia_inmutable'
        );
    }

    /** Solo se vende vino embotellado. */
    public static function ventaSinEmbotellado(): self
    {
        return new self(
            'Una venta tiene que apuntar a un embotellado: sin el no se puede saber de que parcela vino el vino.',
            'venta_sin_embotellado'
        );
    }

    /** No quedan botellas de ese embotellado. */
    public static function sinBotellas(string $codigoLote, int $pedidas, int $disponibles): self
    {
        return new self(
            "El lote {$codigoLote} tiene {$disponibles} botella(s) disponibles y se pidieron {$pedidas}.",
            'sin_botellas'
        );
    }

    /** El lote no es de vino embotellado. */
    public static function loteNoVenduible(string $codigo): self
    {
        return new self(
            "El lote {$codigo} no esta embotellado: no hay vino en botella para vender.",
            'lote_no_venduible'
        );
    }

    public static function ventaAbiertaDemasiadoTiempo(string $minutos): self
    {
        return new self(
            "La venta lleva {$minutos} minutos abierta y el maximo son 30. Cerra la caja o anulala.",
            'venta_abierta_demasiado_tiempo'
        );
    }

    public static function estadoInvalido(string $estadoActual, string $estadoPedido): self
    {
        return new self(
            "No se puede pasar de {$estadoActual} a {$estadoPedido}.",
            'estado_invalido'
        );
    }

    /** Sin motivo no se descarta. La perdida se declara, no se deduce. */
    public static function descarteSinMotivo(): self
    {
        return new self(
            'Descartar un lote tiene que decir por que se perdio. Una perdida sin motivo es una perdida que se repite el ano que viene.',
            'descarte_sin_motivo'
        );
    }

    /** La parcela no existe o es de otra vina. */
    public static function parcelaInvalida(): self
    {
        return new self(
            'La parcela no existe en esta viña.',
            'parcela_invalida'
        );
    }
}