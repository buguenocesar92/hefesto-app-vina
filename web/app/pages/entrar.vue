// ---------------------------------------------------------------------------
// Pantalla de entrada.
// ---------------------------------------------------------------------------
//
// SE ELIGE LA VIÑA ANTES DE ENTRAR
// ---------------------------------
// La pantalla pide la viña y recien ahi manda a Authentik. Ese `slug` viaja
// en la sesion hasta el callback, y es lo UNICO que existe antes de
// autenticarse que diga a que empresa pertenece la cuenta.
//
// Por que no se resuelve solo con el correo: con dominio compartido
// ("@gmail.com") no sirve, y ademas seria adivinar. Ver docs/03.
//
// El selector lo alimenta la vista publica `vw_vinas_publicas`, que expone
// SOLO tres columnas y solo viñas activas.
// ---------------------------------------------------------------------------

<script setup lang="ts">
import type { VinaPublica } from '~/tipos/api';

const config = useRuntimeConfig();

const { data: vinas } = await useFetch<{ vinas: VinaPublica[] }>('/api/v1/vinas');

const elegida = ref<string>('');
const entrando = ref(false);

const errores: Record<string, string> = {
  elige_una_vina: 'Elige una viña para entrar.',
  vina_no_disponible: 'Esa viña no esta disponible.',
  respuesta_incompleta: 'La respuesta del proveedor de identidad no fue completa.',
  varias_vinas: 'Tu correo tiene cuentas en varias viñas. Elige con cual entrar.',
  sin_acceso: 'Tu correo no tiene acceso a ninguna viña.',
  cuenta_no_existe: 'No tienes una cuenta en esa viña. Pide al encargado que te cree el acceso.',
  cuenta_desactivada: 'Tu cuenta esta desactivada.',
  correo_no_verificado: 'Tu correo no esta verificado en el proveedor de identidad.',
  state_invalido: 'La solicitud de acceso no es valida. Vuelve a entrar desde el inicio.',
};

const ruta = useRoute();
const mensaje = computed(() => {
  const error = ruta.query.error;

  return typeof error === 'string' ? (errores[error] ?? 'No se pudo entrar.') : null;
});

function entrar(): void {
  if (elegida.value === '' || entrando.value) {
    return;
  }

  entrando.value = true;

  const destino = `${config.public.apiBase}/api/v1/acceso?empresa=${encodeURIComponent(elegida.value)}`;

  window.location.href = destino;
}
</script>

<template>
  <main class="entrada">
    <div class="entrada__caja">
      <h1 class="entrada__titulo">Vina y Bodega</h1>

      <p class="entrada__bajada">
        Vendimia, vinificacion y trazabilidad. Cada vino sabe de que parcela y de que fecha de
        vendimia viene.
      </p>

      <p v-if="mensaje" class="alerta alerta--error" role="alert">{{ mensaje }}</p>

      <form class="entrada__formulario" @submit.prevent="entrar">
        <label class="campo">
          <span class="campo__etiqueta">Tu viña</span>

          <select
            v-model="elegida"
            class="campo__control"
            required
            :disabled="entrando || (vinas?.vinas.length ?? 0) === 0"
          >
            <option value="" disabled>
              {{ (vinas?.vinas.length ?? 0) === 0 ? 'No hay viñas disponibles' : 'Elige una viña' }}
            </option>

            <option v-for="vina in vinas?.vinas ?? []" :key="vina.slug" :value="vina.slug">
              {{ vina.nombre }}
            </option>
          </select>
        </label>

        <button class="boton boton--principal" type="submit" :disabled="entrando">
          {{ entrando ? 'Entrando...' : 'Entrar' }}
        </button>
      </form>

      <p class="entrada__nota">
        El acceso lo resuelve Authentik. La viña no guarda contrasenas.
      </p>
    </div>
  </main>
</template>

<style scoped>
.entrada {
  display: grid;
  place-items: center;
  min-height: 100vh;
  padding: 2rem 1rem;
}

.entrada__caja {
  width: 100%;
  max-width: 26rem;
  padding: 2rem;
  background: var(--color-superficie);
  border: 1px solid var(--color-borde);
  border-radius: 0.75rem;
}

.entrada__titulo {
  margin: 0 0 0.25rem;
  font-size: 1.5rem;
  color: var(--color-texto);
}

.entrada__bajada {
  margin: 0 0 1.5rem;
  color: var(--color-texto-suave);
  line-height: 1.5;
}

.entrada__nota {
  margin: 1.5rem 0 0;
  font-size: 0.85rem;
  color: var(--color-texto-suave);
}
</style>