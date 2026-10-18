<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Las etapas por las que pasa un lote de vino.
 *
 * ESTA ENUM ES EL ORDEN DEL PROCESO, Y ES LO MAS IMPORTANTE DEL MODULO
 * ---------------------------------------------------------------------
 * El orden no lo decide la aplicacion ni el que esta frente a la pantalla:
 * lo decide el ORDEN DE LAS CONSTANTES DE ESTE ARCHIVO. `siguiente()` es lo
 * unico que decide si una etapa se puede registrar, y sale de aqui.
 *
 * La razon es que "un lote mal criado no se puede deshacer". Si el avance
 * dependiera de un numero guardado en la base, bastaria un UPDATE para saltar
 * de `fermentacion` a `embotellado` y tener un vino que en la realidad jamas
 * existio. Con la secuencia en el codigo, el proceso solo avanza hacia
 * adelante y hacia adelante nomas.
 *
 * LAS ETAPAS OBLIGATORIAS Y LAS OPCIONALES
 * -----------------------------------------
 * `esObligatoria()` distingue las que no se pueden saltar (destajar sin
 * fermentar no tiene sentido) de las que dependen de la decision del
 * enologo (un vino joven no pasa por barrica; una pizca de cabernet puede
 * hacer crianzas o no). El sistema exige las obligatorias y respeta la
 * decision en las que no lo son: no inventa un metodo que la bodega no
 * declaro.
 *
 * Ver docs/02-MODELO-DE-DATOS.md.
 */
enum EtapaVinificacion: string
{
    /** Llega la uva al lagar. El origen del mosto. */
    case RECEPCION_UVA = 'recepcion_uva';

    /** Se separa el mosto del hollejo. */
    case DESTAJE = 'destaje';

    /** El mosto se convierte en vino. */
    case FERMENTACION = 'fermentacion';

    /** El hollejo aporta Extracto al vino. */
    case MACERACION = 'maceracion';

    /** Se retiran las lias y el vino queda limpio. */
    case CLARIFICACION = 'clarificacion';

    /** El vino se vuelve estable para la botella. */
    case ESTABILIZACION = 'estabilizacion';

    /** Tiempo en barrica de roble. */
    case BARRIQUEADO = 'barriqueado';

    /** Crianza en botella. */
    case CRIANZAS = 'crianzas';

    /** Sale a botella. */
    case EMBOTELLADO = 'embotellado';

    /**
     * La etapa que va despues de esta.
     *
     * `null` en la ultima etapa (`EMBOTELLADO`): despues de embotellar no
     * hay otra etapa del proceso. Un lote que ya esta embotellado esta en su
     * fin, y eso no es un error.
     */
    public function siguiente(): ?self
    {
        return match ($this) {
            self::RECEPCION_UVA => self::DESTAJE,
            self::DESTAJE => self::FERMENTACION,
            self::FERMENTACION => self::MACERACION,
            self::MACERACION => self::CLARIFICACION,
            self::CLARIFICACION => self::ESTABILIZACION,
            self::ESTABILIZACION => self::BARRIQUEADO,
            self::BARRIQUEADO => self::EMBOTELLADO,
            self::EMBOTELLADO, self::CRIANZAS => null,
        };
    }

    /**
     * Las etapas que no se pueden saltar.
     *
     * `barriqueado` y `crianzas` NO estan: son decision del enologo y
     * muchos vinos de buena reputacion no pasan por barrica. El sistema
     * ofrece la etapa; no obliga a tomarla.
     */
    public function esObligatoria(): bool
    {
        return ! in_array($this, [self::BARRIQUEADO, self::CRIANZAS], true);
    }

    /**
     * Que se mide en esta etapa.
     *
     * El dato es un hecho que el enologo registra con un equipo. NULO
     * significa "no se midio", nunca un numero inventado. Ver docs/05.
     */
    public function magnitud(): ?string
    {
        return match ($this) {
            self::RECEPCION_UVA => 'kilos',
            self::DESTAJE, self::MACERACION => 'litros',
            self::FERMENTACION => 'temperatura',
            self::CLARIFICACION, self::ESTABILIZACION, self::BARRIQUEADO => 'litros',
            self::EMBOTELLADO => 'botellas',
            self::CRIANZAS => null,
        };
    }

    /** La etiqueta que ve el enologo en la pantalla. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::RECEPCION_UVA => 'Recepcion de uva',
            self::DESTAJE => 'Destaje',
            self::FERMENTACION => 'Fermentacion',
            self::MACERACION => 'Maceracion',
            self::CLARIFICACION => 'Clarificacion',
            self::ESTABILIZACION => 'Estabilizacion',
            self::BARRIQUEADO => 'Barriqueado',
            self::CRIANZAS => 'Crianzas',
            self::EMBOTELLADO => 'Embotellado',
        };
    }

    /** Todas las etapas del proceso, en orden. */
    public static function secuencia(): array
    {
        return self::cases();
    }
}