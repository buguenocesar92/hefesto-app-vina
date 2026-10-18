// ---------------------------------------------------------------------------
// Sesion de la persona que esta operando la viña.
// ---------------------------------------------------------------------------
//
// El rol decide QUE SE MUESTRA en el menu, pero NO decide QUE SE PUEDE
// HACER: la autorizacion real la hace la API. Esto es solo para no mostrar
// botones que van a ser rechazados. Es una cortesia, no una proteccion.
//
// Ver docs/03-MULTITENANCY-Y-ROLES.md.
// ---------------------------------------------------------------------------

import type { Usuario } from '~/tipos/api';

export function useSesion() {
  const usuario = useState<Usuario | null>('sesion:usuario', () => null);
  const cargado = useState<boolean>('sesion:cargada', () => false);

  async function cargar(): Promise<Usuario | null> {
    if (cargado.value) {
      return usuario.value;
    }

    try {
      usuario.value = await $fetch<Usuario>('/api/v1/yo');
    } catch {
      usuario.value = null;
    }

    cargado.value = true;

    return usuario.value;
  }

  async function salir(): Promise<void> {
    await $fetch('/api/v1/salir', { method: 'POST' });

    usuario.value = null;
    cargado.value = true;

    await navigateTo('/entrar');
  }

  return {
    usuario,
    cargado,
    cargar,
    salir,

    esAdministrador: computed(() => usuario.value?.puede_administrar === true),

    /** Puede cosechar y registrar la vendimia. */
    puedeVendimiar: computed(
      () => usuario.value?.puede_vendimiar === true || usuario.value?.puede_administrar === true,
    ),

    /** Puede registrar etapas de vinificacion y embotellar. */
    puedeVinificar: computed(
      () => usuario.value?.puede_vinificar === true || usuario.value?.puede_administrar === true,
    ),

    /** Puede editar parcelas y el catalogo. */
    puedeEditarCampo: computed(
      () => usuario.value?.puede_editar_campo === true || usuario.value?.puede_administrar === true,
    ),

    /** Puede vender en el piso de ventas. */
    puedeVender: computed(
      () => usuario.value?.puede_vender === true || usuario.value?.puede_administrar === true,
    ),

    /**
     * Puede descartar un lote o una vendimia.
     *
     * Descartar es DECLARAR una perdida, y deja el registro a la vista para
     * siempre: por eso no lo puede hacer quien solo opera.
     */
    puedeDescartar: computed(
      () =>
        usuario.value?.puede_descartar_lote === true || usuario.value?.puede_administrar === true,
    ),

    /** Puede anular una venta. */
    puedeAnular: computed(
      () => usuario.value?.puede_anular === true || usuario.value?.puede_administrar === true,
    ),
  };
}