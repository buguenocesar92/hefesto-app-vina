<script setup lang="ts">
import type { Trazabilidad } from '~/tipos/api';

// ---------------------------------------------------------------------------
// La pantalla que sostiene la promesa del sistema.
// ---------------------------------------------------------------------------
//
// UNA CONSULTA Y UN COMPROMISO
// ----------------------------
// Se busca un codigo de lote o de embotellado y se responde de que parcela y
// de que fecha de vendimia es. NADA MAS.
//
// El compromiso con el que esta escrita: si la cadena esta rota, la pantalla
// LO DICE. No completa el hueco con el nombre de la parcela "de memoria" ni
// deduce el origen del codigo del lote.
//
// Por que importa: una trazabilidad que se inventa es peor que ninguna. Una
// persona que decide comprar un vino por el nombre de su bloque tiene que
// poder confiar en lo que lee aca.
// ---------------------------------------------------------------------------

const codigo = ref<string>('');

const resultado = ref<Trazabilidad | null>(null);
const tipoResultado = ref<string | null>(null);
const consultando = ref(false);
const error = ref<string | null>(null);

const errores: Record<string, string> = {
  codigo_ausente: 'Escribe un codigo de lote o de embotellado.',
  codigo_no_encontrado: 'No hay ningun vino con ese codigo.',
};

async function consultar(): Promise<void> {
  if (codigo.value.trim() === '' || consultando.value) {
    return;
  }

  consultando.value = true;
  error.value = null;
  resultado.value = null;
  tipoResultado.value = null;

  try {
    const respuesta = await $fetch<Trazabilidad & { tipo?: string }>('/api/v1/trazabilidad', {
      query: { codigo: codigo.value.trim() },
    });

    resultado.value = respuesta;
    tipoResultado.value = respuesta.tipo ?? null;
  } catch (e) {
    const detalle = e as { data?: { codigo_error?: string } };

    if (detalle.data?.codigo_error === 'codigo_no_encontrado') {
      error.value = errores.codigo_no_encontrado;
    } else if (detalle.data?.codigo_error === 'codigo_ausente') {
      error.value = errores.codigo_ausente;
    } else {
      error.value = 'No se pudo consultar la trazabilidad.';
    }
  } finally {
    consultando.value = false;
  }
}

const formatoKilos = (valor: number | null): string =>
  valor === null ? '-' : `${new Intl.NumberFormat('es-CL').format(valor)} kg`;

/**
 * El brix en la linea de trazabilidad.
 *
 * `null` se muestra como "sin medir", en cursiva. No como "-", porque el guion
 * parece un dato que se perdio al traerlo; y no como "0", porque cero seria un
 * analisis que alguien midio y dio cero.
 */
function brixTexto(valor: number | null): { texto: string; sinDato: boolean } {
  if (valor === null) {
    return { texto: 'sin medir', sinDato: true };
  }

  return { texto: `${valor}°`, sinDato: false };
}
</script>

<template>
  <section>
    <header class="panel__cabecera">
      <div>
        <h1 class="panel__titulo">Trazabilidad</h1>
        <p class="panel__subtitulo">
          De que parcela y de que fecha de vendimia es este vino.
        </p>
      </div>
    </header>

    <form class="consulta" @submit.prevent="consultar">
      <label class="campo consulta__campo">
        <span class="campo__etiqueta">Codigo del lote o del embotellado</span>
        <input
          v-model="codigo"
          class="campo__control"
          placeholder="LT-LIT-2024-001"
          autocomplete="off"
        />
      </label>

      <button class="boton boton--principal" type="submit" :disabled="consultando">
        {{ consultando ? 'Buscando...' : 'Consultar' }}
      </button>
    </form>

    <p v-if="error" class="alerta alerta--error" role="alert">{{ error }}</p>

    <template v-if="resultado">
      <!-- LA ADVERTENCIA PRINCIPAL -->
      <p
        v-if="!resultado.completa"
        class="alerta alerta--error"
        role="alert"
      >
        <strong>La trazabilidad de este vino esta incompleta.</strong> Falta un eslabon entre el
        lote y la parcela, asi que este sistema <em>no</em> puede decir de que vendimia viene.
        No se esta suponiendo nada: falta el dato y hay que encontrarlo.
      </p>

      <div v-if="resultado.completa && resultado.linea_de_origen" class="origen">
        <p class="origen__etiqueta">Origen del vino</p>
        <p class="origen__texto">{{ resultado.linea_de_origen }}</p>
      </div>

      <div class="rejilla">
        <!-- Lote -->
        <article class="tarjeta">
          <h2 class="tarjeta__titulo">Lote</h2>

          <template v-if="resultado.lote">
            <p class="tarjeta__codigo">{{ resultado.lote.codigo }}</p>
            <p v-if="resultado.lote.nombre" class="tarjeta__nombre">
              {{ resultado.lote.nombre }}
            </p>

            <dl class="datos">
              <div class="datos__par">
                <dt>Vendimia</dt>
                <dd>{{ resultado.lote.anio }}</dd>
              </div>

              <div class="datos__par">
                <dt>Estado</dt>
                <dd>
                  <span class="estado" :class="`estado--${resultado.lote.estado}`">
                    {{ resultado.lote.estado.replace('_', ' ') }}
                  </span>
                </dd>
              </div>

              <div class="datos__par">
                <dt>Litros</dt>
                <dd>{{ new Intl.NumberFormat('es-CL').format(resultado.lote.litros) }}</dd>
              </div>
            </dl>
          </template>

          <p v-else class="aviso-sin-dato">Sin lote registrado.</p>
        </article>

        <!-- Vendimia: ACUI esta la fecha que responde la pregunta -->
        <article class="tarjeta">
          <h2 class="tarjeta__titulo">Vendimia</h2>

          <template v-if="resultado.vendimia">
            <p class="tarjeta__codigo">{{ resultado.vendimia.numero }}</p>

            <dl class="datos">
              <div class="datos__par">
                <dt>Fecha de vendimia</dt>
                <dd class="datos__destacado">
                  {{ resultado.vendimia.fecha_vendimia ?? 'sin registro' }}
                </dd>
              </div>

              <div class="datos__par">
                <dt>Kilos</dt>
                <dd>{{ formatoKilos(resultado.vendimia.kilos) }}</dd>
              </div>

              <div class="datos__par">
                <dt>Brix</dt>
                <dd :class="{ 'aviso-sin-dato': brixTexto(resultado.vendimia.brix).sinDato }">
                  {{ brixTexto(resultado.vendimia.brix).texto }}
                </dd>
              </div>
            </dl>
          </template>

          <p v-else class="aviso-sin-dato">Sin vendimia asociada.</p>
        </article>

        <!-- Parcela -->
        <article class="tarjeta">
          <h2 class="tarjeta__titulo">Parcela</h2>

          <template v-if="resultado.parcela">
            <p class="tarjeta__codigo">{{ resultado.parcela.codigo }}</p>
            <p class="tarjeta__nombre">{{ resultado.parcela.nombre }}</p>

            <dl class="datos">
              <div class="datos__par">
                <dt>Variedad</dt>
                <dd>{{ resultado.parcela.variedad }}</dd>
              </div>

              <div class="datos__par">
                <dt>Comuna</dt>
                <dd>{{ resultado.parcela.comuna ?? '-' }}</dd>
              </div>

              <div class="datos__par">
                <dt>Superficie</dt>
                <dd>
                  {{
                    resultado.parcela.superficie_hectareas !== null
                      ? `${resultado.parcela.superficie_hectareas} ha`
                      : '-'
                  }}
                </dd>
              </div>

              <div class="datos__par">
                <dt>Plantado</dt>
                <dd>
                  {{ resultado.parcela.ano_plantacion ?? '-' }}
                </dd>
              </div>
            </dl>
          </template>

          <p v-else class="aviso-sin-dato">Sin parcela asociada.</p>
        </article>
      </div>

      <!-- El embotellado, si hay uno -->
      <article v-if="resultado.embotellado" class="bloque">
        <h2 class="bloque__titulo">Embotellado</h2>

        <p class="bloque__texto">
          {{ resultado.embotellado.botellas }} botella(s) de
          {{ resultado.embotellado.formato_ml }} ml, el
          {{ resultado.embotellado.fecha_embotellado }}.
        </p>
      </article>
    </template>

    <p v-else-if="!consultando && !error" class="vacio">
      Escribe el codigo de un lote o de un embotellado para saber de que parcela y de que fecha de
      vendimia es.
    </p>
  </section>
</template>

<style scoped>
.consulta {
  display: flex;
  align-items: flex-end;
  gap: 0.75rem;
  margin-bottom: 1.5rem;
}

.consulta__campo {
  flex: 1;
  margin-bottom: 0;
}

.origen {
  padding: 1.25rem;
  margin-bottom: 1.5rem;
  background: var(--color-acento);
  color: #fff;
  border-radius: 0.6rem;
}

.origen__etiqueta {
  margin: 0 0 0.3rem;
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  opacity: 0.85;
}

.origen__texto {
  margin: 0;
  font-size: 1.35rem;
  font-weight: 600;
}

.rejilla {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
  gap: 1rem;
  margin-bottom: 1.5rem;
}

.tarjeta {
  padding: 1.1rem;
  background: var(--color-superficie);
  border: 1px solid var(--color-borde);
  border-radius: 0.6rem;
}

.tarjeta__titulo {
  margin: 0 0 0.5rem;
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-texto-suave);
}

.tarjeta__codigo {
  margin: 0;
  font-size: 1.15rem;
  font-weight: 600;
}

.tarjeta__nombre {
  margin: 0.15rem 0 0;
  color: var(--color-texto-suave);
}

.datos {
  margin: 0.85rem 0 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.datos__par {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  font-size: 0.9rem;
}

.datos__par dt {
  color: var(--color-texto-suave);
}

.datos__par dd {
  margin: 0;
  text-align: right;
}

/* La fecha de vendimia se destaca: es LA que responde la pregunta. */
.datos__destacado {
  font-weight: 600;
}

.bloque {
  padding: 1.25rem;
  background: var(--color-superficie);
  border: 1px solid var(--color-borde);
  border-radius: 0.6rem;
}

.bloque__titulo {
  margin: 0 0 0.4rem;
  font-size: 1.05rem;
}

.bloque__texto {
  margin: 0;
  color: var(--color-texto-suave);
}

@media (max-width: 640px) {
  .consulta {
    flex-direction: column;
    align-items: stretch;
  }
}
</style>