// ---------------------------------------------------------------------------
// Marco de la aplicacion: menu, cabecera y salida.
// ---------------------------------------------------------------------------
//
// EL MENU NO ES LA SEGURIDAD
// --------------------------
// El menu muestra lo que el rol puede hacer, pero la autorizacion real la
// hace la API. Si alguien entra a una pantalla por URL y no le corresponde,
// la API responde 404 y no le entrega nada. Ver docs/03.
// ---------------------------------------------------------------------------

<script setup lang="ts">
const { usuario, salir, puedeVendimiar, puedeVinificar, puedeEditarCampo, puedeVender } =
  useSesion();

const ruta = useRoute();

const menu = computed(() => {
  const items: Array<{ para: string; texto: string; visible: boolean }> = [
    { para: '/', texto: 'Resumen', visible: true },
    { para: '/campo', texto: 'Campo', visible: puedeVendimiar.value || puedeEditarCampo.value },
    { para: '/lotes', texto: 'Lotes', visible: puedeVinificar.value },
    { para: '/trazabilidad', texto: 'Trazabilidad', visible: true },
    { para: '/ventas', texto: 'Ventas', visible: puedeVender.value },
  ];

  return items.filter((i) => i.visible);
});

function activo(para: string): boolean {
  return para === '/' ? ruta.path === '/' : ruta.path.startsWith(para);
}
</script>

<template>
  <div class="marco">
    <header class="marco__cabecera">
      <span class="marco__marca">Vina y Bodega</span>

      <div class="marco__persona">
        <span v-if="usuario" class="marco__nombre">
          {{ usuario.nombre }}
          <small>{{ usuario.rol }}</small>
        </span>

        <button v-if="usuario" class="boton boton--chico" type="button" @click="salir">
          Salir
        </button>
      </div>
    </header>

    <nav class="marco__menu" aria-label="Secciones">
      <NuxtLink
        v-for="item in menu"
        :key="item.para"
        :to="item.para"
        class="marco__enlace"
        :class="{ 'marco__enlace--activo': activo(item.para) }"
      >
        {{ item.texto }}
      </NuxtLink>
    </nav>

    <main class="marco__contenido">
      <slot />
    </main>
  </div>
</template>

<style scoped>
.marco {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

.marco__cabecera {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem 1.5rem;
  background: var(--color-superficie);
  border-bottom: 1px solid var(--color-borde);
}

.marco__marca {
  font-weight: 600;
  color: var(--color-texto);
}

.marco__persona {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.marco__nombre {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  font-size: 0.9rem;
  color: var(--color-texto);
}

.marco__nombre small {
  color: var(--color-texto-suave);
}

.marco__menu {
  display: flex;
  gap: 0.25rem;
  padding: 0 1.5rem;
  background: var(--color-superficie);
  border-bottom: 1px solid var(--color-borde);
  overflow-x: auto;
}

.marco__enlace {
  padding: 0.75rem 1rem;
  color: var(--color-texto-suave);
  text-decoration: none;
  border-bottom: 2px solid transparent;
  white-space: nowrap;
}

.marco__enlace:hover {
  color: var(--color-texto);
}

.marco__enlace--activo {
  color: var(--color-texto);
  border-bottom-color: var(--color-acento);
}

.marco__contenido {
  flex: 1;
  padding: 1.5rem;
  max-width: 76rem;
  width: 100%;
  margin: 0 auto;
}
</style>