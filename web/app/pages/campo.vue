<script setup lang="ts">
import type { Parcela, Vendimia } from '~/tipos/api';

// ---------------------------------------------------------------------------
// El campo: parcelas y vendimias.
// ---------------------------------------------------------------------------
//
// LO QUE ESTA PANTALLA PROTEGE
// ----------------------------
// La fecha de vendimia. Es el origen de la trazabilidad de todo el vino que
// sale de cada cuadra, y la base impide cambiarla cuando la vendimia ya tiene
// un lote. Por eso aca el campo de fecha no tiene un boton de guardar: la
// vendimia se REGISTRA una vez, con la fecha con que ocurrio.
//
// Y el brix: si no se midio, queda vacio. La pantalla no pone un valor por
// omision, porque un brix inventado decide cuando se destaja.
// ---------------------------------------------------------------------------

const { puedeVendimiar, puedeEditarCampo } = useSesion();

const { data: parcelas, refresh: refrescarParcelas } = await useFetch<{
  parcelas: Parcela[];
  variedades: string[];
}>('/api/v1/parcelas');

const anio = ref<number>(new Date().getFullYear() - 1);

const { data: vendimias, refresh: refrescarVendimias } = await useFetch<{ vendimias: Vendimia[] }>(
  '/api/v1/vendimias',
  { query: computed(() => ({ anio: anio.value })) },
);

const parcelaSeleccionada = ref<string>('');
const fecha = ref<string>(new Date().toISOString().slice(0, 10));
const kilos = ref<number | null>(null);
const brix = ref<number | null>(null);
const acidez = ref<number | null>(null);
const notas = ref<string>('');

const guardando = ref(false);
const error = ref<string | null>(null);
const exito = ref<string | null>(null);

const parcelaActual = computed(() =>
  parcelas.value?.parcelas.find((p) => p.id === parcelaSeleccionada.value) ?? null,
);

/**
 * Si la parcela elegida no admite brix.
 *
 * Las blancas y rosadas no siguen la misma curva de maduracion que las
 * tintas: un "brix de mas" de referencia no aplica a un Chardonnay. Por eso
 * el campo se desactiva en vez de mostrar un numero que no significa nada.
 *
 * PENDIENTE DE CONFIRMAR CON LA VIÑA: esta regla esta puesta por criterio
 * tecnico general, no por un criterio declarado por la bodega. Ver docs/05.
 */
function admiteBrix(parcela: Parcela | null): boolean {
  if (parcela === null) {
    return true;
  }

  return !['Chardonnay', 'Sauvignon Blanc', 'Pinot Noir'].includes(parcela.variedad);
}

const botonDeshabilitado = computed(
  () => guardando.value || parcelaSeleccionada.value === '' || !kilos.value || kilos.value <= 0,
);

async function registrar(): Promise<void> {
  if (botonDeshabilitado.value) {
    return;
  }

  guardando.value = true;
  error.value = null;
  exito.value = null;

  try {
    await $fetch('/api/v1/vendimias', {
      method: 'POST',
      body: {
        parcela_id: parcelaSeleccionada.value,
        fecha_vendimia: fecha.value,
        kilos: kilos.value,
        // `null` y no `0`: si no se midio, no se manda nada.
        brix: admiteBrix(parcelaActual.value) ? brix.value : null,
        acidez: acidez.value,
        notas: notas.value === '' ? null : notas.value,
      },
    });

    exito.value = 'Vendimia registrada. Ya es el origen del lote que salga de aca.';
    kilos.value = null;
    brix.value = null;
    acidez.value = null;
    notas.value = '';

    await refrescarVendimias();
  } catch (e) {
    const detalle = e as { data?: { mensaje?: string } };

    error.value = detalle.data?.mensaje ?? 'No se pudo registrar la vendimia.';
  } finally {
    guardando.value = false;
  }
}

const formatoKilos = (valor: number): string =>
  `${new Intl.NumberFormat('es-CL').format(valor)} kg`;

function brixTexto(valor: number | null): string {
  return valor === null ? 'sin medir' : `${valor}°`;
}
</script>

<template>
  <section>
    <header class="panel__cabecera">
      <div>
        <h1 class="panel__titulo">El campo</h1>
        <p class="panel__subtitulo">
          Parcelas y vendimias. La fecha que se registra aca es el origen de la trazabilidad del
          vino.
        </p>
      </div>

      <select v-model.number="anio" class="campo__control campo__control--corta" @change="refrescarVendimias()">
        <option :value="new Date().getFullYear() - 1">{{ new Date().getFullYear() - 1 }}</option>
        <option :value="new Date().getFullYear() - 2">{{ new Date().getFullYear() - 2 }}</option>
        <option :value="new Date().getFullYear() - 3">{{ new Date().getFullYear() - 3 }}</option>
      </select>
    </header>

    <!-- Registrar vendimia -->
    <article v-if="puedeVendimiar" class="bloque">
      <h2 class="bloque__titulo">Registrar una vendimia</h2>

      <p class="bloque__texto">
        La fecha y los kilos son los que se registran. El brix y la acidez son opcionales: si
        no se midieron, quedan sin medir.
      </p>

      <p v-if="error" class="alerta alerta--error" role="alert">{{ error }}</p>
      <p v-if="exito" class="alerta alerta--exito" role="status">{{ exito }}</p>

      <form class="formulario" @submit.prevent="registrar">
        <label class="campo">
          <span class="campo__etiqueta">Parcela</span>
          <select v-model="parcelaSeleccionada" class="campo__control" required>
            <option value="" disabled>Elige una parcela</option>
            <option v-for="p in parcelas?.parcelas ?? []" :key="p.id" :value="p.id">
              {{ p.codigo }} - {{ p.nombre }} ({{ p.variedad }})
            </option>
          </select>
        </label>

        <label class="campo">
          <span class="campo__etiqueta">Fecha de la vendimia</span>
          <input v-model="fecha" type="date" class="campo__control" :max="new Date().toISOString().slice(0, 10)" required />
        </label>

        <label class="campo">
          <span class="campo__etiqueta">Kilos cosechados</span>
          <input v-model.number="kilos" type="number" min="0" step="0.1" class="campo__control" required />
        </label>

        <label class="campo">
          <span class="campo__etiqueta">
            Brix
            <small v-if="!admiteBrix(parcelaActual)"> - no aplica a esta variedad</small>
          </span>
          <input
            v-model.number="brix"
            type="number"
            min="0"
            max="45"
            step="0.1"
            class="campo__control"
            :disabled="!admiteBrix(parcelaActual)"
            :placeholder="admiteBrix(parcelaActual) ? 'Si se midio' : 'No aplica'"
          />
        </label>

        <label class="campo">
          <span class="campo__etiqueta">Acidez (g/L)</span>
          <input
            v-model.number="acidez"
            type="number"
            min="0"
            max="20"
            step="0.01"
            class="campo__control"
            placeholder="Si se midio"
          />
        </label>

        <label class="campo campo--ancho">
          <span class="campo__etiqueta">Notas</span>
          <textarea v-model="notas" class="campo__control" rows="2" maxlength="2000"></textarea>
        </label>

        <button class="boton boton--principal" type="submit" :disabled="botonDeshabilitado">
          {{ guardando ? 'Registrando...' : 'Registrar vendimia' }}
        </button>
      </form>

      <p class="nota">
        Una vez que la vendimia tiene un lote, su fecha deja de poder editarse. Es a proposito: es
        lo que permite decir despues "este vino es de la vendimia del 12 de marzo".
      </p>
    </article>

    <!-- El listado -->
    <h2 class="seccion__titulo">Vendimias de {{ anio }}</h2>

    <p v-if="(vendimias?.vendimias.length ?? 0) === 0" class="vacio">
      No hay vendimias registradas en {{ anio }}.
    </p>

    <table v-else class="tabla">
      <thead>
        <tr>
          <th>Numero</th>
          <th>Parcela</th>
          <th>Fecha</th>
          <th>Kilos</th>
          <th>Brix</th>
          <th>Acidez</th>
          <th>Estado</th>
          <th>Lote</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="vendimia in vendimias?.vendimias ?? []" :key="vendimia.id">
          <td>{{ vendimia.numero }}</td>
          <td>
            {{ vendimia.parcela }}
            <small class="tabla__pie">{{ vendimia.variedad }}</small>
          </td>
          <td>{{ vendimia.fecha_vendimia }}</td>
          <td>{{ formatoKilos(vendimia.kilos) }}</td>
          <td :class="{ 'aviso-sin-dato': vendimia.brix === null }">
            {{ brixTexto(vendimia.brix) }}
          </td>
          <td :class="{ 'aviso-sin-dato': vendimia.acidez === null }">
            {{ vendimia.acidez === null ? 'sin medir' : `${vendimia.acidez} g/L` }}
          </td>
          <td>
            <span class="estado" :class="{ 'estado--descartado': vendimia.estado === 'descartada' }">
              {{ vendimia.estado.replace('_', ' ') }}
            </span>
          </td>
          <td>
            <span v-if="vendimia.lote_codigo">{{ vendimia.lote_codigo }}</span>
            <span v-else class="aviso-sin-dato">sin vinificar</span>
          </td>
        </tr>
      </tbody>
    </table>
  </section>
</template>

<style scoped>
.formulario {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));
  gap: 0 1rem;
  margin-top: 1.25rem;
}

.campo--ancho,
.boton {
  grid-column: 1 / -1;
}

.campo--ancho {
  margin-top: 0.5rem;
}

.nota {
  margin: 1rem 0 0;
  font-size: 0.85rem;
  color: var(--color-texto-suave);
}

.seccion__titulo {
  margin: 1.5rem 0 0.75rem;
  font-size: 1.05rem;
}

.tabla__pie {
  display: block;
  color: var(--color-texto-suave);
}

.campo__control--corta {
  width: auto;
}
</style>