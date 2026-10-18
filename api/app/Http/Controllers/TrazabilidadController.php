<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Embotellado;
use App\Models\Lote;
use App\Models\Parcela;
use App\Services\Traza\TrazadorDeVino;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La trazabilidad: de donde viene este vino.
 *
 * ESTE ES EL ENDPOINT QUE SOSTIENE LA PROMESA DEL SISTEMA
 * -------------------------------------------------------
 * Responde una sola pregunta, y la responde leyendo hechos registrados:
 *
 *     "De que parcela y de que fecha de vendimia es esta botella?"
 *
 * Lo que NO hace, y es lo importante: no deduce el origen del codigo del
 * lote, no lo calcula a partir del anada y no completa los huecos. Si a la
 * cadena le falta un eslabon, la respuesta trae `completa: false` y la
 * interfaz lo dice. Un origen inventado seria peor que ningun origen.
 *
 * Ver docs/02-MODELO-DE-DATOS.md.
 */
class TrazabilidadController extends Controller
{
    public function __construct(private readonly TrazadorDeVino $trazador) {}

    /**
     * La trazabilidad de un embotellado, con toda la cadena.
     */
    public function deEmbotellado(Embotellado $embotellado): JsonResponse
    {
        $this->authorize('view', $embotellado);

        $cadena = $this->trazador->deEmbotellado($embotellado);

        // La linea de origen es para la etiqueta y el despacho. Es `null`
        // cuando la cadena esta rota, y eso hay que decirlo de forma explicita
        // en vez de devolver una frase con huecos.
        $cadena['linea_de_origen'] = $this->trazador->lineaDeOrigen($embotellado);

        return response()->json($cadena);
    }

    /**
     * La trazabilidad de un lote.
     */
    public function deLote(Lote $lote): JsonResponse
    {
        $this->authorize('view', $lote);

        $cadena = $this->trazador->deLote($lote);

        $embotellado = $lote->embotellados()->latest('fecha_embotellado')->first();

        $cadena['linea_de_origen'] = $embotellado === null
            ? null
            : $this->trazador->lineaDeOrigen($embotellado);

        return response()->json($cadena);
    }

    /**
     * La trazabilidad de una parcela: que se hizo con ella.
     *
     * Es la pregunta del enologo al empezar la vendimia ("que dio el ano
     * pasado este bloque?") y la del que audita ("este vino, de aqui?").
     */
    public function deParcela(Request $request, Parcela $parcela): JsonResponse
    {
        $this->authorize('view', $parcela);

        $anio = $request->query('anio');

        $lotes = $this->trazador->lotesDeParcela(
            $parcela,
            $anio === null ? null : (int) $anio
        );

        return response()->json([
            'parcela' => [
                'id' => $parcela->id,
                'codigo' => $parcela->codigo,
                'nombre' => $parcela->nombre,
                'variedad' => $parcela->variedad,
                'comuna' => $parcela->comuna,
            ],
            'lotes' => $lotes->map(fn (Lote $lote) => [
                'id' => $lote->id,
                'codigo' => $lote->codigo,
                'nombre' => $lote->nombre,
                'anio' => (int) $lote->anio,
                'estado' => $lote->estado->value,
                'litros' => (float) $lote->litros,
                'fecha_vendimia' => $lote->vendimia?->fecha_vendimia?->toDateString(),
            ])->all(),
            'vendimias' => $this->trazador->vendimiasDeParcela($parcela)
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'numero' => $v->numero,
                    'fecha_vendimia' => $v->fecha_vendimia?->toDateString(),
                    'kilos' => (float) $v->kilos,
                    'brix' => $v->brix,
                    'acidez' => $v->acidez,
                    'estado' => $v->estado->value,
                    'tiene_lote' => $v->lote !== null,
                    'lote_codigo' => $v->lote?->codigo,
                ])->all(),
        ]);
    }

    /**
     * Buscar por codigo de lote o de embotellado.
     *
     * Es el atajo de la duda del dia a dia: alguien trae una botella con un
     * codigo y hay que responder de donde viene sin navegar menus.
     */
    public function buscar(Request $request): JsonResponse
    {
        $codigo = trim((string) $request->query('codigo', ''));

        if ($codigo === '') {
            return response()->json([
                'mensaje' => 'Falta el codigo que se quiere consultar.',
                'codigo_error' => 'codigo_ausente',
            ], 422);
        }

        $lote = Lote::query()->where('codigo', $codigo)->first();

        if ($lote !== null) {
            $this->authorize('view', $lote);

            return response()->json(['tipo' => 'lote'] + $this->trazador->deLote($lote));
        }

        $embotellado = Embotellado::query()
            ->whereHas('lote', fn ($q) => $q->where('codigo', $codigo))
            ->first();

        if ($embotellado !== null) {
            $this->authorize('view', $embotellado);

            return response()->json(
                ['tipo' => 'embotellado'] + $this->trazador->deEmbotellado($embotellado)
            );
        }

        // Un codigo que no existe se responde 404, no con una lista de
        // codigos parecidos: eso confirmaria que existen otros y no hace
        // falta para responder la pregunta.
        return response()->json([
            'mensaje' => 'No encontrado.',
            'codigo_error' => 'codigo_no_encontrado',
        ], 404);
    }
}