<script setup lang="ts">
// ---------------------------------------------------------------------------
// Pantalla de error.
// ---------------------------------------------------------------------------
//
// QUE DICE UN 404
// ----------------
// "No encontrado". Y no "no tenes permiso".
//
// La diferencia importa: un 403 confirmaria que el recurso existe y que es de
// otra viña. Con un mensaje de permiso, alguien podria deducir que existe un
// lote en otra bodega. Ver docs/07.
// ---------------------------------------------------------------------------

defineProps<{
  error: {
    statusCode?: number;
    statusMessage?: string;
    message?: string;
  };
}>();
</script>

<template>
  <main class="error">
    <p class="error__codigo">{{ error.statusCode ?? 500 }}</p>

    <h1 class="error__titulo">
      {{ error.statusCode === 404 ? 'No encontrado' : 'Algo no salio bien' }}
    </h1>

    <p class="error__texto">
      {{
        error.statusCode === 404
          ? 'No encontramos lo que estabas buscando. Si es un lote o una botella, revisa el codigo.'
          : 'Vuelve a intentar en un momento. Si el problema sigue, avisale al encargado.'
      }}
    </p>

    <NuxtLink class="boton boton--principal" to="/">Volver al resumen</NuxtLink>
  </main>
</template>

<style scoped>
.error {
  display: grid;
  place-items: center;
  align-content: center;
  gap: 0.5rem;
  min-height: 100vh;
  padding: 2rem;
  text-align: center;
}

.error__codigo {
  margin: 0;
  font-size: 3.5rem;
  font-weight: 700;
  color: var(--color-acento);
}

.error__titulo {
  margin: 0;
  font-size: 1.4rem;
}

.error__texto {
  margin: 0 0 1rem;
  max-width: 30rem;
  color: var(--color-texto-suave);
}
</style>