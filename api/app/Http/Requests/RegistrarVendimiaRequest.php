<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Parcela;
use Illuminate\Validation\Rule;

/**
 * Registrar una vendimia.
 *
 * LAS REGLAS MAS IMPORTANTES
 * -------------------------
 *  - `kilos` tiene que ser mayor que cero. Una vendimia de cero kilos es una
 *    vendimia que no ocurrio.
 *  - `brix` y `acidez` son OPCIONALES y su ausencia es valida. Si no se
 *    midieron, quedan en NULL. Este request NO pone un valor por omision:
 *    seria escribir un dato de laboratorio que nadie midio, y el brix decide
 *    cuando se destaja.
 *  - `parcela_id` tiene que ser de esta viña: el `exists` solo mira que
 *    exista, y el aislamiento lo pone el contexto de la sesion.
 */
class RegistrarVendimiaRequest extends PeticionDeVina
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'parcela_id' => ['required', 'uuid', 'exists:parcelas,id'],
            'fecha_vendimia' => ['required', 'date'],
            'kilos' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'brix' => ['nullable', 'numeric', 'min:0', 'max:45'],
            'acidez' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return $this->mensajes() + [
            'kilos.gt' => 'Una vendimia tiene que tener kilos cosechados mayores que cero.',
            'brix.max' => 'El brix de un vino esta entre 0 y 45. Revisa el numero.',
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

    /**
     * La parcela tiene que estar activa.
     *
     * No se resuelve en `rules()` porque el mensaje tiene que ser de negocio
     * ("la parcela esta desactivada") y no de validacion.
     */
    public function parcela(): Parcela
    {
        return Parcela::query()->findOrFail((string) $this->input('parcela_id'));
    }
}