<script setup lang="ts">
import type { ResumenDeConfiguracion } from '~/tipos/api';

const { usuario, puedeEditarCampo } = useSesion();

const anio = ref<number>(new Date().getFullYear());

const { data: resumen, pending, refresh } = await useFetch<ResumenDeConfiguracion>(
  '/api/v1/configuracion',
  { query: computed(() => ({ anio: anio.value })) },
);

/**
 * El brix promedio puede ser `null`, y eso NO es un error de la pantalla.
 *
 * Significa que ninguna de las vendimias del ano tiene analisis registrado, y
 * por eso hay que decirlo de forma explicita en vez de mostrar un guion o un
 * cero: cero significaria "se midio y dio cero", que es un dato falso.
 */
const brixTexto = computed(() => {
  const valor = resumen.value?.vendimia.brix_promedio;

  if (valor === null || valor === undefined) {
    return 'sin medir';
  }

  return `${valor}°`;
});

const acidezTexto = computed(() => {
  const valor = resumen.value?.vendimia.acidez_promedio;

  if (valor === null || valor === undefined) {
    return 'sin medir';
  }

  return `${valor} g/L`;
});

const formato = (valor: number | null | undefined): string =>
  valor === null || valor === undefined ? '-' : new Intl.NumberFormat('es-CL').format(valor);

const dinero = (valor: number | null | undefined): string =>
  new Intl.NumberFormat('es-CL', {
    style: 'currency',
    currency: 'CLP',
    maximumFractionDigits: 0,
  }).format(valor ?? 0);

const anios = computed(() => {
  const actual = new Date().getFullYear();
  return [actual, actual - 1, actual - 2];
});
</script>

<template>
  <section>
    <header class="panel__cabecera">
      <div>
        <h1 class="panel__titulo">Resumen de la temporada</h1>
        <p class="panel__subtitulo">
          {{ usuario?.vina }} - vendimia y vinificacion del {{ anio }}
        </p>
      </div>

      <select v-model.number="anio" class="campo__control campo__control--corta" @change="refresh()">
        <option v-for="a in anios" :key="a" :value="a">{{ a }}</option>
      </select>
    </header>

    <p v-if="pending" class="vacio">Cargando...</p>

    <template v-else-if="resumen">
      <!-- El campo -->
      <div class="rejilla">
        <article class="tarjeta">
          <h2 class="tarjeta__titulo">Campo</h2>

          <p class="tarjeta__cifra">{{ resumen.campo.parcelas_activas }}</p>
          <p class="tarjeta__pie">parcelas activas</p>

          <p class="tarjeta__detalle">
            {{ resumen.campo.variedades }} variedad{{ resumen.campo.variedades === 1 ? '' : 'es' }}
            plantada{{ resumen.campo.variedades === 1 ? '' : 's' }}
          </p>
        </article>

        <article class="tarjeta">
          <h2 class="tarjeta__titulo">Vendimia</h2>

          <p class="tarjeta__cifra">{{ formato(resumen.vendimia.kilos_totales) }}</p>
          <p class="tarjeta__pie">kilos cosechados</p>

          <p class="tarjeta__detalle">
            {{ resumen.vendimia.vendimias }} vendimia{{
              resumen.vendimia.vendimias === 1 ? '' : 's'
            }}
            <span v-if="resumen.vendimia.descartadas > 0" class="tarjeta__alerta">
              - {{ resumen.vendimia.descartadas }} descartada{{
                resumen.vendimia.descartadas === 1 ? '' : 's'
              }}
            </span>
          </p>
        </article>

        <article class="tarjeta">
          <h2 class="tarjeta__titulo">Lotes</h2>

          <p class="tarjeta__cifra">{{ resumen.lotes.lotes }}</p>
          <p class="tarjeta__pie">
            {{ resumen.lotes.embotellados }} embotellado{{
              resumen.lotes.embotellados === 1 ? '' : 's'
            }}
          </p>

          <p v-if="resumen.lotes.descartados > 0" class="tarjeta__alerta">
            {{ resumen.lotes.descartados }} perdido{{
              resumen.lotes.descartados === 1 ? '' : 's'
            }}
          </p>
        </article>

        <article class="tarjeta">
          <h2 class="tarjeta__titulo">Botellas</h2>

          <p class="tarjeta__cifra">{{ formato(resumen.lotes.botellas_embotelladas) }}</p>
          <p class="tarjeta__pie">embotelladas en {{ anio }}</p>
        </article>
      </div>

      <!-- El analisis, con el null tratado como lo que es -->
      <article class="bloque">
        <h2 class="bloque__titulo">Analisis de la vendimia</h2>

        <p class="bloque__texto">
          El brix y la acidez se miden con un equipo en el momento de la cosecha. Si una vendimia no
          tiene analisis, queda <strong>sin medir</strong>: el sistema no le pone un numero, porque
          un brix inventado decide cuando se destaja.
        </p>

        <dl class="analisis">
          <div class="analisis__par">
            <dt>Brix promedio</dt>
            <dd :class="{ 'aviso-sin-dato': brixTexto === 'sin medir' }">{{ brixTexto }}</dd>
          </div>

          <div class="analisis__par">
            <dt>Acidez promedio</dt>
            <dd :class="{ 'aviso-sin-dato': acidezTexto === 'sin medir' }">{{ acidezTexto }}</dd>
          </div>

          <div class="analisis__par">
            <dt>Vendimias analizadas</dt>
            <dd>
              {{ resumen.vendimia.con_brix }} de {{ resumen.vendimia.vendimias }}
            </dd>
          </div>
        </dl>
      </article>

      <!-- La venta -->
      <article class="bloque">
        <h2 class="bloque__titulo">Venta</h2>

        <p class="bloque__cifra">{{ dinero(resumen.ventas.total_vendido) }}</p>

        <p class="bloque__texto">
          {{ resumen.ventas.pagadas }} venta{{ resumen.ventas.pagadas === 1 ? '' : 's' }} pagada{{
            resumen.ventas.pagadas === 1 ? '' : 's'
          }}.
          <span v-if="resumen.ventas.anuladas > 0">
            {{ resumen.ventas.anuladas }} anulada{{
              resumen.ventas.anuladas === 1 ? '' : 's'
            }} (el vino volvió al embotellado).
          </span>
          <span v-if="resumen.ventas.abiertas > 0">
            {{ resumen.ventas.abiertas }} abierta{{
              resumen.ventas.abiertas === 1 ? '' : 's'
            }} sin cobrar.
          </span>
        </p>
      </article>

      <p class="aviso-eslabon">
        <template v-if="puedeEditarCampo">
          Todo sale de las filas reales. Un lote perdido queda a la vista a proposito: una perdida
          sin registro es una perdida que se repite el ano que viene.
        </template>
        <template v-else>
          Cada numero sale de las filas reales. Para ver el detalle de un vino, entra en
          Trazabilidad.
        </template>
      </p>
    </template>
  </section>
</template>

<style scoped>
.rejilla {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));
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
  font-size: 0.82rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-texto-suave);
}

.tarjeta__cifra {
  margin: 0;
  font-size: 1.9rem;
  font-weight: 600;
}

.tarjeta__pie {
  margin: 0.15rem 0 0;
  font-size: 0.85rem;
  color: var(--color-texto-suave);
}

.tarjeta__detalle {
  margin: 0.5rem 0 0;
  font-size: 0.85rem;
  color: var(--color-texto-suave);
}

.tarjeta__alerta {
  color: var(--color-error);
  font-weight: 500;
}

.bloque {
  padding: 1.25rem;
  margin-bottom: 1.5rem;
  background: var(--color-superficie);
  border: 1px solid var(--color-borde);
  border-radius: 0.6rem;
}

.bloque__titulo {
  margin: 0 0 0.5rem;
  font-size: 1.05rem;
}

.bloque__texto {
  margin: 0;
  color: var(--color-texto-suave);
  max-width: 48rem;
}

.bloque__cifra {
  margin: 0 0 0.5rem;
  font-size: 1.9rem;
  font-weight: 600;
}

.analisis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
  gap: 1rem;
  margin: 1.25rem 0 0;
}

.analisis__par dt {
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--color-texto-suave);
}

.analisis__par dd {
  margin: 0.2rem 0 0;
  font-size: 1.15rem;
  font-weight: 500;
}

.campo__control--corta {
  width: auto;
}

.aviso-eslabon {
  font-size: 0.85rem;
  color: var(--color-texto-suave);
  max-width: 48rem;
}
</style>