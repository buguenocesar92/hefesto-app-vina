<script setup lang="ts">
import type { Lote, RespuestaDeEtapa } from '~/tipos/api';

// ---------------------------------------------------------------------------
// Los lotes y el avance de la vinificacion.
// ---------------------------------------------------------------------------
//
// LO QUE ESTA PANTALLA PROTEGE
// ----------------------------
// Que las etapas se registren EN ORDEN y no se tocan.
//
// No hay boton de editar ni de borrar en ninguna etapa, y no es una omision:
// la base tiene un gatillo que revienta cualquier cambio sobre una etapa ya
// registrada. Un lote mal criado no se puede deshacer.
//
// Y hay una diferencia importante con el resto de la aplicacion: aqui NO se
// puede saltar al embotellado si el lote no esta estabilizado. Un vino sin
// estabilizar no sale a botella, y esa regla la dice la API, no esta pantalla.
// ---------------------------------------------------------------------------

const { puedeVinificar, puedeDescartar } = useSesion();

const anio = ref<number>(new Date().getFullYear() - 1);

const { data: lotes, pending, refresh } = await useFetch<{ lotes: Lote[] }>('/api/v1/lotes', {
  query: computed(() => ({ anio: anio.value })),
});

const seleccionado = ref<Lote | null>(null);
const cargandoDetalle = ref(false);

const nuevaEtapa = ref<string>('');
const fechaEtapa = ref<string>(new Date().toISOString().slice(0, 10));
const notasEtapa = ref<string>('');

const error = ref<string | null>(null);
const exito = ref<string | null>(null);

/** Las etapas que ya se registraron, en orden. */
const etapasRegistradas = computed(() => new Set(seleccionado.value?.etapas.map((e) => e.etapa) ?? []));

async function abrir(lote: Lote): Promise<void> {
  cargandoDetalle.value = true;
  error.value = null;
  exito.value = null;

  try {
    const detalle = await $fetch<{ lote: Lote }>(`/api/v1/lotes/${lote.id}`);

    seleccionado.value = detalle.lote;
    nuevaEtapa.value = '';
    fechaEtapa.value = new Date().toISOString().slice(0, 10);
    notasEtapa.value = '';
  } catch {
    error.value = 'No se pudo abrir el lote.';
  } finally {
    cargandoDetalle.value = false;
  }
}

async function registrarEtapa(): Promise<void> {
  if (seleccionado.value === null || nuevaEtapa.value === '') {
    return;
  }

  error.value = null;
  exito.value = null;

  try {
    const respuesta = await $fetch<RespuestaDeEtapa>(`/api/v1/lotes/${seleccionado.value.id}/etapas`, {
      method: 'POST',
      body: {
        etapa: nuevaEtapa.value,
        fecha: fechaEtapa.value,
        notas: notasEtapa.value === '' ? null : notasEtapa.value,
      },
    });

    seleccionado.value = respuesta.lote;
    exito.value = `Etapa registrada: ${respuesta.etapa.etiqueta}.`;
    nuevaEtapa.value = '';
    notasEtapa.value = '';

    await refresh();
  } catch (e) {
    const detalle = e as { data?: { mensaje?: string } };

    // El mensaje de la API dice cual era la etapa que si correspondia, y
    // eso es lo que se muestra tal cual: un error de proceso se corrige
    // leyendo el mensaje.
    error.value = detalle.data?.mensaje ?? 'No se pudo registrar la etapa.';
  }
}

const embotellando = ref(false);
const botellas = ref<number | null>(null);
const formatoMl = ref<number>(750);

async function embotellar(): Promise<void> {
  if (seleccionado.value === null || !botellas.value || botellas.value <= 0) {
    return;
  }

  embotellando.value = true;
  error.value = null;
  exito.value = null;

  try {
    await $fetch(`/api/v1/lotes/${seleccionado.value.id}/embotellar`, {
      method: 'POST',
      body: { botellas: botellas.value, formato_ml: formatoMl.value },
    });

    exito.value = 'Lote embotellado. Las botellas conservan la trazabilidad del lote.';
    botellas.value = null;

    await abrir(seleccionado.value);
    await refresh();
  } catch (e) {
    const detalle = e as { data?: { mensaje?: string } };

    error.value = detalle.data?.mensaje ?? 'No se pudo embotellar el lote.';
  } finally {
    embotellando.value = false;
  }
}

function claseEstado(estado: string): string {
  return `estado estado--${estado}`;
}

const anios = computed(() => {
  const actual = new Date().getFullYear();
  return [actual, actual - 1, actual - 2, actual - 3];
});
</script>

<template>
  <section>
    <header class="panel__cabecera">
      <div>
        <h1 class="panel__titulo">Lotes</h1>
        <p class="panel__subtitulo">
          El proceso se registra en orden y no se edita. Un lote mal criado no se puede deshacer.
        </p>
      </div>

      <select v-model.number="anio" class="campo__control campo__control--corta" @change="refresh()">
        <option v-for="a in anios" :key="a" :value="a">{{ a }}</option>
      </select>
    </header>

    <p v-if="pending" class="vacio">Cargando...</p>

    <div class="rejilla">
      <!-- El listado -->
      <div class="rejilla__columna">
        <h2 class="seccion__titulo">Lotes de {{ anio }}</h2>

        <p v-if="(lotes?.lotes.length ?? 0) === 0" class="vacio">
          No hay lotes en {{ anio }}.
        </p>

        <table v-else class="tabla">
          <thead>
            <tr>
              <th>Codigo</th>
              <th>Nombre</th>
              <th>Origen</th>
              <th>Estado</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="lote in lotes?.lotes ?? []"
              :key="lote.id"
              :class="{ 'tabla__fila--activa': seleccionado?.id === lote.id }"
            >
              <td>{{ lote.codigo }}</td>
              <td>{{ lote.nombre ?? '-' }}</td>
              <td>
                <span v-if="lote.linea_de_origen" class="tabla__origen">
                  {{ lote.linea_de_origen }}
                </span>
                <span v-else class="aviso-sin-dato">sin trazabilidad</span>
              </td>
              <td>
                <span :class="claseEstado(lote.estado)">{{ lote.estado.replace('_', ' ') }}</span>
              </td>
              <td>
                <button class="boton boton--chico" type="button" @click="abrir(lote)">Ver</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- El detalle -->
      <div v-if="seleccionado" class="rejilla__columna">
        <h2 class="seccion__titulo">{{ seleccionado.codigo }}</h2>

        <p v-if="error" class="alerta alerta--error" role="alert">{{ error }}</p>
        <p v-if="exito" class="alerta alerta--exito" role="status">{{ exito }}</p>

        <!-- La trazabilidad del lote -->
        <article class="bloque">
          <h3 class="bloque__titulo">Origen</h3>

          <p v-if="seleccionado.linea_de_origen" class="bloque__destacado">
            {{ seleccionado.linea_de_origen }}
          </p>
          <p v-else class="aviso-sin-dato">
            La trazabilidad de este lote esta incompleta. Falta un eslabon hacia la parcela.
          </p>

          <p class="bloque__pie">
            {{ seleccionado.litros }} litros - vendimia del {{ seleccionado.fecha_vendimia }}
          </p>
        </article>

        <!-- El historial de etapas -->
        <article class="bloque">
          <h3 class="bloque__titulo">Proceso</h3>

          <ol class="proceso">
            <li
              v-for="etapa in seleccionado.etapas"
              :key="etapa.etapa"
              class="proceso__paso"
            >
              <span class="proceso__etiqueta">{{ etapa.etiqueta }}</span>
              <span class="proceso__fecha">{{ etapa.fecha }}</span>
              <span v-if="etapa.responsable" class="proceso__quien">{{ etapa.responsable }}</span>
              <span v-if="etapa.notas" class="proceso__nota">{{ etapa.notas }}</span>
            </li>

            <li v-if="seleccionado.etapas.length === 0" class="aviso-sin-dato">
              Todavia no se registro ninguna etapa.
            </li>
          </ol>

          <p class="nota">
            Las etapas registradas no se pueden editar ni borrar. Para corregir una fecha mal
            tipeada, se registra la etapa correcta y la anterior queda como lo que ocurrio.
          </p>
        </article>

        <!-- Registrar una etapa -->
        <article v-if="puedeVinificar && seleccionado.estado !== 'embotellado' && seleccionado.estado !== 'descartado'" class="bloque">
          <h3 class="bloque__titulo">Registrar una etapa</h3>

          <form class="formulario" @submit.prevent="registrarEtapa">
            <label class="campo">
              <span class="campo__etiqueta">Etapa</span>
              <select v-model="nuevaEtapa" class="campo__control" required>
                <option value="" disabled>Elige la etapa que ocurrio</option>
                <option
                  v-for="nombre in ['recepcion_uva', 'destaje', 'fermentacion', 'maceracion', 'clarificacion', 'estabilizacion', 'barriqueado', 'crianzas']"
                  :key="nombre"
                  :value="nombre"
                  :disabled="etapasRegistradas.has(nombre)"
                >
                  {{ nombre.replace('_', ' ') }}
                  <template v-if="etapasRegistradas.has(nombre)"> - ya registrada</template>
                </option>
              </select>
            </label>

            <label class="campo">
              <span class="campo__etiqueta">Fecha real</span>
              <input
                v-model="fechaEtapa"
                type="date"
                class="campo__control"
                :max="new Date().toISOString().slice(0, 10)"
                required
              />
            </label>

            <label class="campo campo--ancho">
              <span class="campo__etiqueta">Notas</span>
              <textarea v-model="notasEtapa" class="campo__control" rows="2" maxlength="2000"></textarea>
            </label>

            <button
              class="boton boton--principal"
              type="submit"
              :disabled="nuevaEtapa === ''"
            >
              Registrar etapa
            </button>
          </form>

          <p class="nota">
            La API rechaza las etapas que no van en orden. Si dice que el lote va en otra etapa,
            es porque esa es la que sigue.
          </p>
        </article>

        <!-- Embotellar -->
        <article v-if="puedeVinificar && seleccionado.estado === 'estabilizado'" class="bloque">
          <h3 class="bloque__titulo">Embotellar</h3>

          <p class="bloque__texto">
            Este lote esta estabilizado, asi que ya puede salir a botella. Solo un lote
            estabilizado sale: un vino que todavia no esta listo no se embotella.
          </p>

          <form class="formulario formulario--linea" @submit.prevent="embotellar">
            <label class="campo">
              <span class="campo__etiqueta">Botellas</span>
              <input v-model.number="botellas" type="number" min="1" class="campo__control" required />
            </label>

            <label class="campo">
              <span class="campo__etiqueta">Formato (ml)</span>
              <select v-model.number="formatoMl" class="campo__control">
                <option :value="750">750</option>
                <option :value="375">375</option>
                <option :value="1500">1500</option>
              </select>
            </label>

            <button
              class="boton boton--principal"
              type="submit"
              :disabled="!botellas || botellas <= 0 || embotellando"
            >
              {{ embotellando ? 'Embotellando...' : 'Embotellar' }}
            </button>
          </form>
        </article>

        <!-- Descartar -->
        <article v-if="puedeDescartar && seleccionado.estado !== 'descartado'" class="bloque bloque--peligro">
          <h3 class="bloque__titulo">Descartar este lote</h3>

          <p class="bloque__texto">
            Un lote se descarta cuando se perdio. <strong>No se borra</strong>: queda a la vista
            con su motivo, porque una perdida que no se registra es una perdida que se repite el
            ano que viene.
          </p>
        </article>
      </div>
    </div>
  </section>
</template>

<style scoped>
.rejilla {
  display: grid;
  grid-template-columns: 1fr;
  gap: 1.5rem;
}

.rejilla__columna {
  min-width: 0;
}

.seccion__titulo {
  margin: 0 0 0.75rem;
  font-size: 1.05rem;
}

.tabla__fila--activa {
  background: #f9f4f0;
}

.tabla__origen {
  font-size: 0.85rem;
  color: var(--color-texto-suave);
}

.bloque {
  padding: 1.1rem;
  margin-bottom: 1rem;
  background: var(--color-superficie);
  border: 1px solid var(--color-borde);
  border-radius: 0.6rem;
}

.bloque--peligro {
  border-color: #f0c8c8;
}

.bloque__titulo {
  margin: 0 0 0.5rem;
  font-size: 1rem;
}

.bloque__texto {
  margin: 0;
  color: var(--color-texto-suave);
  font-size: 0.9rem;
}

.bloque__destacado {
  margin: 0;
  font-size: 1.1rem;
  font-weight: 600;
}

.bloque__pie {
  margin: 0.5rem 0 0;
  font-size: 0.85rem;
  color: var(--color-texto-suave);
}

.proceso {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.proceso__paso {
  display: flex;
  align-items: baseline;
  gap: 0.6rem;
  padding-bottom: 0.5rem;
  border-bottom: 1px solid var(--color-borde);
  font-size: 0.9rem;
}

.proceso__paso:last-child {
  border-bottom: none;
}

.proceso__etiqueta {
  font-weight: 500;
  min-width: 9rem;
}

.proceso__fecha {
  color: var(--color-texto-suave);
  font-size: 0.85rem;
}

.proceso__quien,
.proceso__nota {
  color: var(--color-texto-suave);
  font-size: 0.82rem;
}

.formulario {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 1rem;
  margin-top: 0.75rem;
}

.formulario--linea {
  grid-template-columns: 1fr 1fr auto;
  align-items: end;
}

.campo--ancho,
.formulario .boton {
  grid-column: 1 / -1;
}

.nota {
  margin: 0.9rem 0 0;
  font-size: 0.82rem;
  color: var(--color-texto-suave);
}

.campo__control--corta {
  width: auto;
}

@media (min-width: 1100px) {
  .rejilla {
    grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr);
  }
}
</style>