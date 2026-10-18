<?php

declare(strict_types=1);

/**
 * Configuracion de la Vina y la Bodega.
 *
 * POR QUE LAS MAGNITUDES DEL VIÑEDO SE LEEN COMO NULL Y NO COMO CERO
 * -------------------------------------------------------------------
 * El brix, la acidez y el grado son datos MEDIDOS con un equipo. Si la
 * vendimia se registra sin analisis, el sistema guarda NULL: no le inventa un
 * numero. Mezclar "no medido" (NULL) con "medido en cero" (0) seria escribir un
 * dato falso en el lote, y un brix inventado decide cuando se destaja.
 *
 * Los ayudantes de abajo hacen esa distincion sin repetir el `env()` en cada
 * sitio: `null` y `0` llegan como cadenas distintas.
 */

/**
 * Entero del ambiente o NULL. `''` y `null` son "no definido".
 */
function vina_entero(?string $clave): ?int
{
    $valor = env($clave);

    if ($valor === null || $valor === '') {
        return null;
    }

    return (int) $valor;
}

/**
 * Decimal del ambiente o NULL.
 */
function vina_decimal(?string $clave): ?float
{
    $valor = env($clave);

    if ($valor === null || $valor === '') {
        return null;
    }

    return (float) $valor;
}

return [

    /*
    |--------------------------------------------------------------------------
    | Identidad de la aplicacion
    |--------------------------------------------------------------------------
    |
    | `web_url` es la direccion de la interfaz (Nuxt). Vive ACA y no en
    | `config/app.php` a proposito: el esqueleto de Laravel no trae esa clave
    | y leer `config('app.web_url')` devolveria NULL sin avisar. Ver el
    | AuthController.
    |
    */

    'web_url' => rtrim((string) env('APP_WEB_URL', 'http://localhost:3000'), '/'),

    /*
    |--------------------------------------------------------------------------
    | Vendimia
    |--------------------------------------------------------------------------
    |
    | Lo que se mide de la uva al cosechar y lo que NO se inventa. Ver la nota
    | de arriba: un analisis ausente es NULL, nunca un numero de relleno.
    |
    | `tolerancia_kilos` solo informa: avisa si el peso registrado se aparta
    | del declarado en la ficha de la parcela. No corrige el peso, porque el
    | peso real es el que dice la báscula.
    |
    */

    'vendimia' => [
        'brix_por_defecto' => vina_entero('VINA_BRIX_POR_DEFECTO'),
        'acidez_por_defecto' => vina_decimal('VINA_ACIDEZ_POR_DEFECTO'),
        'tolerancia_kilos' => (float) env('VINA_TOLERANCIA_KILOS', 0.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Vinificacion
    |--------------------------------------------------------------------------
    |
    | Las etapas NO se configuran desde el ambiente: la secuencia tecnica vive
    | en el codigo (enum `EtapaVinificacion`). Configurarla por variable
    | permitiria desordenar el proceso sin que nadie lo decidiera, y el ORDEN
    | es justamente lo que sostiene la trazabilidad.
    |
    | `dias_aviso_sin_actividad` es una AYUDA: avisa cuando un lote lleva
    | mucho tiempo parado. No bloquea. Una guarda larga en barrica es una
    | operacion legitima, y el sistema avisa, no decide.
    |
    */

    'vinificacion' => [
        'dias_aviso_sin_actividad' => (int) env('VINA_DIAS_AVISO_SIN_ACTIVIDAD', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ventas
    |--------------------------------------------------------------------------
    |
    | Una venta de vino se cierra sola pasado este tiempo: si alguien abre una
    | venta y se va sin cerrarla, la caja queda descuadrada. El valor por
    | omision son 30 minutos. **Pendiente de confirmar con la vina.**
    |
    */

    'ventas' => [
        'minutos_maximos' => (int) env('VINA_MINUTOS_MAXIMOS_VENTA', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Migraciones al arrancar
    |--------------------------------------------------------------------------
    |
    | En produccion tiene que ser "false". Un despliegue que cambia el
    | esquema sin que nadie lo decida es un despliegue que puede cortar el
    | servicio a mitad de una vendimia.
    |
    */

    'correr_migraciones' => (bool) env('VINA_CORRER_MIGRACIONES', false),
];