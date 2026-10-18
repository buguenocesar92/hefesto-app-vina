<script setup lang="ts">
import type { Venta } from '~/tipos/api';

// ---------------------------------------------------------------------------
// Ventas del piso de ventas.
// ---------------------------------------------------------------------------
//
// LO QUE ESTA PANTALLA PROTEGE
// ----------------------------
// Que cada venta apunte a un EMBOTELLADO, y por eso que las botellas que
// salen de la bodega conserven la trazabilidad hasta la parcela.
//
// Abajo, en cada linea, se ve el origen del vino. Esa linea no es adorno:
// es lo que permite responder, meses después, "de que parcela era el vino que
// vendimos en esta venta".
// ---------------------------------------------------------------------------

const { puedeVender, puedeAnular } = useSesion();

const soloAbiertas = ref(false);

const { data: ventas, pending, refresh } = await useFetch<{ ventas: Venta[] }>('/api/v1/ventas', {
  query: computed(() => (soloAbiertas.value ? { abiertas: '1' } : {})),
});

const cobrando = ref<string | null>(null);
const montoCobro = ref<number | null>(null);
const metodoCobro = ref<'efectivo' | 'tarjeta' | 'transferencia'>('efectivo');

const error = ref<string | null>(null);
const exito = ref<string | null>(null);

async function cobrar(venta: Venta): Promise<void> {
  if (montoCobro.value === null || montoCobro.value <= 0) {
    return;
  }

  cobrando.value = venta.id;
  error.value = null;
  exito.value = null;

  try {
    await $fetch(`/api/v1/ventas/${venta.id}/pagar`, {
      method: 'POST',
      body: {
        metodo: metodoCobro.value,
        monto: montoCobro.value,
      },
    });

    exito.value = `Venta ${venta.numero} cobrada.`;
    montoCobro.value = null;

    await refresh();
  } catch (e) {
    const detalle = e as { data?: { mensaje?: string } };

    error.value = detalle.data?.mensaje ?? 'No se pudo cobrar la venta.';
  } finally {
    cobrando.value = null;
  }
}

async function anular(venta: Venta): Promise<void> {
  error.value = null;
  exito.value = null;

  try {
    await $fetch(`/api/v1/ventas/${venta.id}/anular`, { method: 'POST' });

    exito.value = `Venta ${venta.numero} anulada. Las botellas volvieron al embotellado.`;
    await refresh();
  } catch (e) {
    const detalle = e as { data?: { mensaje?: string } };

    error.value = detalle.data?.mensaje ?? 'No se pudo anular la venta.';
  }
}

const dinero = (valor: number): string =>
  new Intl.NumberFormat('es-CL', {
    style: 'currency',
    currency: 'CLP',
    maximumFractionDigits: 0,
  }).format(valor);

const fechaHora = (valor: string | null): string => {
  if (valor === null) {
    return '-';
  }

  return new Intl.DateTimeFormat('es-CL', {
    dateStyle: 'short',
    timeStyle: 'short',
  }).format(new Date(valor));
};

function claseEstado(estado: string): string {
  return `estado estado--${estado}`;
}
</script>

<template>
  <section>
    <header class="panel__cabecera">
      <div>
        <h1 class="panel__titulo">Ventas</h1>
        <p class="panel__subtitulo">
          Cada venta apunta a un embotellado. Por eso el vino que sale del mostrador conserva su
          origen.
        </p>
      </div>

      <label class="interruptor">
        <input v-model="soloAbiertas" type="checkbox" @change="refresh()" />
        <span>Solo las abiertas</span>
      </label>
    </header>

    <p v-if="error" class="alerta alerta--error" role="alert">{{ error }}</p>
    <p v-if="exito" class="alerta alerta--exito" role="status">{{ exito }}</p>

    <p v-if="pending" class="vacio">Cargando...</p>

    <p v-else-if="(ventas?.ventas.length ?? 0) === 0" class="vacio">
      No hay ventas{{ soloAbiertas ? ' abiertas' : '' }}.
    </p>

    <article v-for="venta in ventas?.ventas ?? []" :key="venta.id" class="bloque">
      <header class="bloque__cabecera">
        <div>
          <h2 class="bloque__titulo">
            {{ venta.numero }}
            <span :class="claseEstado(venta.estado)">{{ venta.estado }}</span>
          </h2>

          <p class="bloque__pie">
            {{ venta.cliente_nombre ?? 'Cliente sin nombre' }} - abierta el
            {{ fechaHora(venta.abierta_en) }}
            <template v-if="venta.estado === 'abierta'">
              (hace {{ venta.minutos_abierta }} minutos)
            </template>
          </p>
        </div>

        <p class="bloque__total">{{ dinero(venta.total) }}</p>
      </header>

      <table class="tabla tabla--interna">
        <thead>
          <tr>
            <th>Lote</th>
            <th>Origen del vino</th>
            <th>Botellas</th>
            <th>Precio</th>
            <th>Subtotal</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in venta.items" :key="item.embotellado_id">
            <td>{{ item.lote_codigo ?? '-' }}</td>
            <td>
              <span v-if="item.linea_de_origen" class="tabla__origen">
                {{ item.linea_de_origen }}
              </span>
              <span v-else class="aviso-sin-dato">sin trazabilidad</span>
            </td>
            <td>{{ item.botellas }}</td>
            <td>{{ dinero(item.precio_unitario) }}</td>
            <td>{{ dinero(item.subtotal) }}</td>
          </tr>
        </tbody>
      </table>

      <!-- Cobrar -->
      <form v-if="puedeVender && venta.estado === 'abierta'" class="cobro" @submit.prevent="cobrar(venta)">
        <label class="campo">
          <span class="campo__etiqueta">Monto</span>
          <input v-model.number="montoCobro" type="number" min="0" class="campo__control" required />
        </label>

        <label class="campo">
          <span class="campo__etiqueta">Metodo</span>
          <select v-model="metodoCobro" class="campo__control">
            <option value="efectivo">Efectivo</option>
            <option value="tarjeta">Tarjeta</option>
            <option value="transferencia">Transferencia</option>
          </select>
        </label>

        <button class="boton boton--principal" type="submit" :disabled="cobrando === venta.id">
          {{ cobrando === venta.id ? 'Cobrando...' : 'Cobrar' }}
        </button>
      </form>

      <footer class="bloque__pie">
        <span v-if="venta.pagos.length > 0">
          Pago: {{ venta.pagos.map((p) => `${p.metodo} ${dinero(p.monto)}`).join(', ') }}
        </span>

        <button
          v-if="puedeAnular && venta.estado === 'abierta'"
          class="boton boton--chico boton--peligro"
          type="button"
          @click="anular(venta)"
        >
          Anular
        </button>
      </footer>
    </article>

    <p v-if="puedeAnular" class="nota">
      Anular una venta devuelve las botellas al embotellado. Una venta anulada no borra el
      registro: queda, porque fue una operacion que existio.
    </p>
  </section>
</template>

<style scoped>
.interruptor {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.9rem;
  color: var(--color-texto-suave);
}

.bloque {
  padding: 1.1rem;
  margin-bottom: 1rem;
  background: var(--color-superficie);
  border: 1px solid var(--color-borde);
  border-radius: 0.6rem;
}

.bloque__cabecera {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
}

.bloque__titulo {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  margin: 0 0 0.25rem;
  font-size: 1.05rem;
}

.bloque__total {
  margin: 0;
  font-size: 1.25rem;
  font-weight: 600;
}

.bloque__pie {
  margin: 0.5rem 0 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  font-size: 0.85rem;
  color: var(--color-texto-suave);
}

.tabla--interna {
  margin-top: 0.85rem;
}

.tabla__origen {
  font-size: 0.85rem;
  color: var(--color-texto-suave);
}

.cobro {
  display: grid;
  grid-template-columns: 1fr 1fr auto;
  gap: 0 1rem;
  align-items: end;
  margin-top: 0.85rem;
}

.boton--peligro {
  color: var(--color-error);
  border-color: #eec6c6;
}

.nota {
  margin: 1.5rem 0 0;
  font-size: 0.85rem;
  color: var(--color-texto-suave);
  max-width: 48rem;
}

@media (max-width: 640px) {
  .cobro {
    grid-template-columns: 1fr;
  }
}
</style>