// ---------------------------------------------------------------------------
// Servidor intermedio: todo lo que va a la API pasa por aqui.
// ---------------------------------------------------------------------------
//
// EL NAVEGADOR NO HABLA NUNCA CON LA API
// --------------------------------------
// El navegador pide a este contenedor y este reenvia a la API. Es lo que se
// llama servidor intermedio, y sirve para tres cosas:
//
//  1. La cookie de sesion viaja en el mismo origen, asi que no hay que
//     abrir la API al exterior ni configurar CORS para el navegador.
//  2. El token CSRF se reenvia como cabecera `X-XSRF-TOKEN`.
//  3. La URL interna de la API no se publica: el navegador nunca la ve.
//
// El `...ruta` recibe el camino completo: `/api/v1/trazabilidad`. Ver docs/07.
// ---------------------------------------------------------------------------

export default defineEventHandler(async (event) => {
  const ruta = getRouterParam(event, 'ruta') ?? '';
  const consulta = getQuery(event);

  const config = useRuntimeConfig(event);

  // La cookie de sesion va al mismo origen que la interfaz. Sin esto, cada
  // peticion a la API llegaria sin sesion y el middleware de viña nunca
  // sabria de quien es la peticion.
  const cookie = getCookie(event, 'vina_sesion');

  const consultaApi = new URL(`${config.apiBase}/api/v1/${ruta}`);

  for (const [clave, valor] of Object.entries(consulta)) {
    if (valor !== undefined && valor !== null) {
      consultaApi.searchParams.set(clave, String(valor));
    }
  }

  // El protocolo se toma del header que pone el proxy, no se supone. En
  // desarrollo llega "http" y en produccion "https", y mandar siempre https
  // haria que Laravel creyera estar en HTTPS cuando no lo esta.
  const protocolo = getRequestHeader(event, 'x-forwarded-proto') ?? 'http';

  try {
    return await $fetch.raw(consultaApi.toString(), {
      method: event.method,
      // El cuerpo se reenvia tal cual. Quien valida es la API, no este
      // contenedor: duplicar las reglas de validacion aca seria tenerlas en
      // dos lugares donde se pueden desincronizar.
      body: ['GET', 'HEAD'].includes(event.method) ? undefined : await readBody(event),

      headers: {
        Accept: 'application/json',
        ...(cookie ? { Cookie: `vina_sesion=${cookie}` } : {}),
        // El reenvio es del servidor, no del navegador, asi que la direccion
        // real no se puede leer del cliente.
        'X-Forwarded-For': getRequestIP(event, { xForwardedFor: true }) ?? '',
        'X-Forwarded-Proto': protocolo,
      },

      // Un 401, un 404 o un 422 de la API se reenvian tal cual, no se
      // convierten en 500: si no, el navegador veria un error del servidor
      // donde en realidad la peticion fue correcta y la respuesta fue "no".
      // El 422 es el que importa mas: es la respuesta de "no, hazlo otra
      // vez asi", y el mensaje que trae dice QUE cambiar.
      ignoreResponseError: true,
    });
  } catch {
    throw createError({
      statusCode: 502,
      statusMessage: 'No se pudo comunicar con la API.',
    });
  }
});