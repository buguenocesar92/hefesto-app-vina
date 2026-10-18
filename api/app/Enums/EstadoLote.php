<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Que paso con un lote de vino.
 *
 * El recorrido normal es `recepcion` -> `en_vinificacion` -> `estabilizado`
 * -> `embotellado`.
 *
 * `descartado` es distinto de todos los demas y vale la pena entenderlo: un
 * lote descartado es un lote que se perdio, y la razon por la que existe ese
 * estado es que la perdida NO se borra. Un lote que se descarta por una falla
 * de fermentacion queda a la vista con su motivo, para que el ano que viene se
 * sepa que se perdio y por que. Borrarlo seria dejar la misma perdida
 * invisible, y es justo lo que una bodega honesta no puede hacer.
 */
enum EstadoLote: string
{
    /** Llego la uva, todavia no se destajo. */
    case RECEPCION = 'recepcion';

    /** Esta pasando por alguna etapa del proceso. */
    case EN_VINIFICACION = 'en_vinificacion';

    /** Paso estabilizacion: se puede bottledar. */
    case ESTABILIZADO = 'estabilizado';

    case EMBOTELLADO = 'embotellado';

    /** Se perdio. Queda con su motivo. No se borra. */
    case DESCARTADO = 'descartado';

    /** No se le puede cambiar el estado: ya termino o ya se perdio. */
    public function estaCerrado(): bool
    {
        return in_array($this, [self::EMBOTELLADO, self::DESCARTADO], true);
    }

    /** Un lote cerrado no se destaja ni se macerara mas. */
    public function admiteEtapa(): bool
    {
        return ! $this->estaCerrado();
    }

    /** Solo un lote estabilizado sale a botella. */
    public function puedeEmbotellarse(): bool
    {
        return $this === self::ESTABILIZADO;
    }

    /** Solo un lote embotellado se vende. */
    public function puedeVenderse(): bool
    {
        return $this === self::EMBOTELLADO;
    }
}