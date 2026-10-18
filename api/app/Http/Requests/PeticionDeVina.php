<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Clase base de las peticiones de la viña.
 *
 * `authorize()` delega en la Policy del modelo. Cuando la Policy rechaza,
 * `bootstrap/app.php` lo convierte en 404 y no en 403: un 403 confirmaria
 * que el recurso existe, y eso ya es filtrar informacion.
 */
abstract class PeticionDeVina extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Los mensajes en el mismo estilo del resto del sistema: en espanol, y
     * diciendo QUE hacer, no solo que algo esta mal.
     *
     * @return array<string, string>
     */
    protected function mensajes(): array
    {
        return [
            'required' => 'Falta el campo :attribute.',
            'numeric' => 'El campo :attribute debe ser un numero.',
            'integer' => 'El campo :attribute debe ser un numero entero.',
            'min' => [
                'numeric' => 'El campo :attribute no puede ser menor que :min.',
                'array' => 'El campo :attribute necesita al menos :min elemento(s).',
                'string' => 'El campo :attribute necesita al menos :min caracteres.',
            ],
            'array' => 'El campo :attribute debe ser una lista.',
            'boolean' => 'El campo :attribute debe ser si o no.',
            'date' => 'El campo :attribute debe ser una fecha.',
            'date_format' => 'El campo :attribute no tiene el formato de fecha esperado.',
            'exists' => 'El campo :attribute no corresponde a un registro existente.',
            'unique' => 'Ya existe un registro con ese valor en :attribute.',
            'in' => 'El campo :attribute tiene un valor que no es valido.',
        ];
    }

    /**
     * Los atributos con nombre de verdad, para que el mensaje no diga "el
     * campo parcela_id" sino "la parcela".
     *
     * @return array<string, string>
     */
    protected function atributos(): array
    {
        return [
            'codigo' => 'codigo de la parcela',
            'nombre' => 'nombre',
            'variedad' => 'variedad',
            'superficie_hectareas' => 'superficie en hectareas',
            'ano_plantacion' => 'ano de plantacion',
            'comuna' => 'comuna',
            'parcela_id' => 'parcela',
            'vendimia_id' => 'vendimia',
            'lote_id' => 'lote',
            'embotellado_id' => 'embotellado',
            'fecha_vendimia' => 'fecha de vendimia',
            'kilos' => 'kilos cosechados',
            'brix' => 'brix medido',
            'acidez' => 'acidez medida',
            'etapa' => 'etapa',
            'fecha' => 'fecha',
            'litros' => 'litros',
            'nombre' => 'nombre',
            'botellas' => 'cantidad de botellas',
            'formato_ml' => 'formato en mililitros',
            'fecha_embotellado' => 'fecha de embotellado',
            'motivo' => 'motivo',
            'numero' => 'numero',
            'total' => 'total',
            'estado' => 'estado',
            'cliente_nombre' => 'nombre del cliente',
            'cliente_contacto' => 'contacto del cliente',
            'metodo' => 'metodo de pago',
            'monto' => 'monto',
            'items' => 'botellas de la venta',
            'items.*.embotellado_id' => 'embotellado',
            'items.*.botellas' => 'cantidad de botellas',
            'items.*.precio_unitario' => 'precio por botella',
            'rol' => 'rol',
            'email' => 'correo',
        ];
    }
}