#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Respaldo de la base de datos de Vina y Bodega.
# ---------------------------------------------------------------------------
#
# LAS SEIS REGLAS QUE ESTE SCRIPT RESPETA
# ---------------------------------------
# Son reglas que se aprendieron de errores reales, no preferencias:
#
#  1. MANIFIESTO con el SHA-256 de cada archivo. Sirve para saber si el
#     respaldo esta completo y para detectar corrupcion al restaurar.
#
#  2. MARCAR EL RESPALDO COMO COMPLETO SOLO AL FINAL. La marca se escribe al
#     ultimo, despues del manifiesto. Un respaldo sin la marca esta
#     incompleto, aunque tenga todos los archivos: se cayo a mitad.
#
#  3. VALIDAR RUTAS Y ESPACIO ANTES DE ESCRIBIR. Se comprueba que el destino
#     este dentro del directorio permitido y que el volumen tenga espacio.
#
#  4. RECHAZAR DESTINO OCUPADO SALVO QUE SE PASE `--force`. Un respaldo
#     encima de otro es como se pierde la historia sin que nadie lo note.
#
#  5. NUNCA DROP, NUNCA SOBRESCRITURA AUTOMATICA. No hay ninguna sentencia
#     DROP en este archivo. Para limpiar se usa TRUNCATE, que deja la base
#     en pie con su propietario y sus permisos.
#
#  6. DEJAR EL SERVICIO DETENIDO TRAS RESTAURAR. Esto es del script de
#     restauracion, no de este.
#
# QUE HAY DENTRO DE UN RESPALDO DE UNA BODEGA
# -------------------------------------------
# Lo que un respaldo de esta aplicacion tiene que recuperar no son solo
# tablas: son las fechas de vendimia y las etapas de vinificacion. Un respaldo
# que restaura las parcelas pero no las etapas deja a la bodega sin poder
# responder de que parcela es un vino. Por eso el respaldo es de TODA la base,
# y no de un subconjunto de tablas.
#
# Ver docs/08-RESPALDOS.md.
#
# USO
#   ./respaldo.sh                       respaldo con fecha y hora
#   ./respaldo.sh --destino /ruta      otra carpeta
#   ./respaldo.sh --etiqueta previa    nombre legible
set -euo pipefail

# --- Configuracion ----------------------------------------------------------

DESTINO_PADRE="${VINA_RESPALDOS:-/respaldos}"
ETIQUETA=""
FUERZA="false"

# Espacio minimo libre que se exige, en kilobytes. Un respaldo que no entra es
# un respaldo que se escribe a medias.
ESPACIO_MINIMO_KB=10240

MARCA_COMPLETO=".completo"

# --- Utilidades ------------------------------------------------------------

registrar() {
    printf '[respaldo] %s\n' "$*"
}

fallar() {
    printf '[respaldo] ERROR: %s\n' "$*" >&2
    exit 1
}

# --- Argumentos ------------------------------------------------------------

while [ $# -gt 0 ]; do
    case "$1" in
        --destino)
            [ $# -ge 2 ] || fallar "--destino necesita un valor."
            DESTINO_PADRE="$2"
            shift 2
            ;;
        --etiqueta)
            [ $# -ge 2 ] || fallar "--etiqueta necesita un valor."
            ETIQUETA="$2"
            shift 2
            ;;
        --force)
            FUERZA="true"
            shift
            ;;
        --ayuda|-h)
            sed -n '2,40p' "$0"
            exit 0
            ;;
        *)
            fallar "Argumento desconocido: $1"
            ;;
    esac
done

# --- Regla 3: validar rutas ANTES de escribir ------------------------------

SCRIPT="$(cd "$(dirname "$0")" && pwd -P)"
CASE_RAIZ="$(dirname "$(dirname "$SCRIPT")")"

# Se resuelve la ruta ABSOLUTA. Sin esto, un destino como `../../etc` pasaria
# el chequeo de texto y escribiria donde no debe.
PADRE_ABSOLUTO="$(cd "$DESTINO_PADRE" 2>/dev/null && pwd -P || true)"

if [ -z "$PADRE_ABSOLUTO" ]; then
    fallar "El destino no existe: $DESTINO_PADRE"
fi

# El destino no puede estar dentro de la aplicacion: un respaldo escrito en el
# arbol de codigo termina en el repositorio.
case "$PADRE_ABSOLUTO" in
    "$CASE_RAIZ"*)
        fallar "El destino no puede estar dentro de la aplicacion: $PADRE_ABSOLUTO"
        ;;
esac

# --- Regla 3 (parte 2): espacio disponible --------------------------------

ESPACIO_LIBRE_KB="$(df -Pk "$PADRE_ABSOLUTO" | awk 'NR==2 {print $4}')"

if [ -z "$ESPACIO_LIBRE_KB" ]; then
    fallar "No se pudo medir el espacio disponible en $PADRE_ABSOLUTO."
fi

if [ "$ESPACIO_LIBRE_KB" -lt "$ESPACIO_MINIMO_KB" ]; then
    fallar "Espacio insuficiente: hay ${ESPACIO_LIBRE_KB} KB libres y se necesitan ${ESPACIO_MINIMO_KB} KB."
fi

registrar "Espacio disponible: ${ESPACIO_LIBRE_KB} KB"

# --- Regla 4: rechazar destino ocupado salvo --force -----------------------

MARCA_TIEMPO="$(date +%Y%m%d-%H%M%S)"

if [ -n "$ETIQUETA" ]; then
    NOMBRE="vina-${MARCA_TIEMPO}-${ETIQUETA}"
else
    NOMBRE="vina-${MARCA_TIEMPO}"
fi

DESTINO="${PADRE_ABSOLUTO}/${NOMBRE}"

if [ -e "$DESTINO" ]; then
    if [ "$FUERZA" != "true" ]; then
        fallar "El destino ya existe: $DESTINO. Usa --force solo si estas seguro."
    fi

    # Con `--force` NO se borra nada: se aparta con otro nombre. Borrar un
    # respaldo entero para hacer otro es justo el modo de fallo que se quiere
    # evitar.
    DESTRINO_ANTIGUO="${DESTINO}.anterior-${MARCA_TIEMPO}"
    mv "$DESTINO" "$DESTRINO_ANTIGUO"
    registrar "El destino anterior se movio a: $DESTRINO_ANTIGUO"
fi

mkdir -p "$DESTINO"
chmod 700 "$DESTINO"

# --- Datos de la conexion --------------------------------------------------

# Se leen del contenedor de la base de datos. Si el contenedor no esta
# corriendo, se dice claro y se termina con codigo distinto de cero.
if ! docker ps --format '{{.Names}}' | grep -q 'vina-postgres-1'; then
    fallar "No se encontro el contenedor de la base de datos. Esta corriendo el proyecto?"
fi

NOMBRE_BD="${POSTGRES_DB:-vina}"
USUARIO_BD="${POSTGRES_USER:-vina_app}"
CONTENEDOR_BD="vina-postgres-1"

registrar "Respaldo de la base '${NOMBRE_BD}' hacia ${DESTINO}"

ARCHIVO_DUMP="${DESTINO}/vina-${MARCA_TIEMPO}.dump"

# El respaldo es EN FORMATO PROPIO de PostgreSQL (`pg_dump -Fc`). Es
# comprimido, y ademas permite restaurar con `pg_restore` sin depender de que
# el servidor destino tenga exactamente la misma version.
if ! docker exec "$CONTENEDOR_BD" pg_dump \
        --username="$USUARIO_BD" \
        --dbname="$NOMBRE_BD" \
        --format=custom \
        --no-owner \
        --no-privileges \
        --file="/tmp/${ARCHIVO_DUMP##*/}"; then
    failar "Fallo el respaldo de la base de datos."
fi

if ! docker cp "${CONTENEDOR_BD}:/tmp/${ARCHIVO_DUMP##*/}" "$ARCHIVO_DUMP" >/dev/null; then
    failar "No se pudo traer el archivo de respaldo al host."
fi

docker exec "$CONTENEDOR_BD" rm -f "/tmp/${ARCHIVO_DUMP##*/}" >/dev/null 2>&1 || true

if [ ! -s "$ARCHIVO_DUMP" ]; then
    failar "El archivo de respaldo quedo vacio: ${ARCHIVO_DUMP}"
fi

# --- Regla 1: manifiesto con SHA-256 de cada archivo -----------------------

MANIFIESTO="${DESTINO}/manifiesto.txt"

{
    printf '# Respaldo de Vina y Bodega\n'
    printf '# Aplicacion: vina\n'
    printf '# Contenido: parcelas, vendimias, lotes, etapas y ventas\n'
    printf '# Base de datos: %s\n' "$NOMBRE_BD"
    printf '# Fecha: %s\n' "$(date -Iseconds)"
    printf '# Contenedor: %s\n' "$CONTENEDOR_BD"
    printf '# Formato: pg_dump custom (pg_restore)\n'
    printf '\n'
    printf '# Formato del manifiesto: <SHA-256>  <archivo>\n'
    printf '# Verificacion: cd al directorio y correr sha256sum -c manifiesto.txt\n'
    printf '\n'
} > "$MANIFIESTO"

# El checksum va sobre el archivo, no sobre si mismo.
( cd "$DESTINO" && sha256sum "${ARCHIVO_DUMP##*/}" >> "$MANIFIESTO" )

# El manifiesto se escribe con permisos de solo lectura despues de llenarse,
# para que nadie lo cambie por accidente.
chmod 400 "$MANIFIESTO"

registrar "Manifiesto escrito con el SHA-256 del respaldo."

# --- Regla 2: marcar como completo SOLO al final ---------------------------

# OJO: la marca se escribe DESPUES del manifiesto. Un respaldo sin la marca
# esta incompleto aunque tenga todos los archivos: el proceso se cayo entre
# el volcado y el manifiesto, o entre el manifiesto y esta linea.
printf 'completo %s\n' "$(date -Iseconds)" > "${DESTINO}/${MARCA_COMPLETO}"

chmod -R a-w "$DESTINO"

TAMANO="$(du -sh "$DESTINO" | cut -f1)"

registrar "Respaldo completo: ${NOMBRE} (${TAMANO})"
registrar "Para verificarlo: sha256sum -c ${DESTINO}/manifiesto.txt"

exit 0