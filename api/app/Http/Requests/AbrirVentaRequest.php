<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Abrir una venta de vino embotellado.
 *
 * LAS REGLAS MAS IMPORTANTES
 * -------------------------
 *  - `items` es obligatorio y no puede estar vacio: una venta sin botellas no
 *    es una venta.
 *  - Cada item apunta a un EMBOTELLADO, no a un lote. Ver la nota del
 *    `RegistradorDeVenta`: es lo que mantiene la trazabilidad viva dentro de
 *    la venta.
 *  - `precio_unitario` tiene que ser mayor que cero. Un vino regalado se
 *    registra con un monto explicito de cero; no con el campo vacio, que
 *    pareceria un error de tipeo.
 */
class AbrirVentaRequest extends PeticionDeVina
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.embotellado_id' => ['required', 'uuid', 'exists:embotellados,id'],
            'items.*.botellas' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.precio_unitario' => ['required', 'numeric', 'min:0'],
            'cliente_nombre' => ['nullable', 'string', 'max:160'],
            'cliente_contacto' => ['nullable', 'string', 'max:160'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return $this->mensajes() + [
            'items.min' => 'Una venta tiene que tener al menos una botella.',
            'items.*.precio_unitario.min' => 'El precio por botella no puede ser negativo. Para regalar vino, escribe 0.',
        ];
    }

    public function attributes(): array
    {
        return $this->atributos();
    }

    /** @return array<string, mixed> */
    public function datos(): array
    {
        return $this->validated();
    }
}