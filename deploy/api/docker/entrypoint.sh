#!/bin/sh
# ---------------------------------------------------------------------------
# Entrada del contenedor de la API.
# ---------------------------------------------------------------------------
#
# POR QUE LAS MIGRACIONES NO CORREN SIEMPRE
# ----------------------------------------
# Un despliegue que cambia el esquema sin que nadie lo decida es un despliegue
# que puede cortar el servicio a mitad de una vendimia.
# Por omision NO se corren migraciones: se aplican a mano, una vez.
#
# Para desarrollo, `VINA_CORRER_MIGRACIONES=true`.
#
# LO QUE NUNCA HACE ESTE ARCHIVO
# -----------------------------
# Nunca borra la base. Nunca corre `db:wipe`. Nunca limpia datos. Si algo
# falla, el contenedor muere y el despliegue se detiene.
set -e

echo "[entrada] esperando la base de datos..."

# Se espera a que PostgreSQL acepte conexiones. Sin esto, el contenedor
# arranca antes que la base y muere, y el orquestador lo reinicia en un
# bucle que puede lastedar minutos.
intentos=0
maximo=30

while [ "$intentos" -lt "$maximo" ]; do
    if php -r '
        $h = getenv("DB_HOST") ?: "postgres";
        $p = getenv("DB_PORT") ?: "5432";
        $u = getenv("DB_USERNAME") ?: "vina_app";
        $b = getenv("DB_DATABASE") ?: "vina";
        try {
            new PDO("pgsql:host=$h;port=$p;dbname=$b", $u, getenv("DB_PASSWORD") ?: "");
            exit(0);
        } catch (Throwable $e) {
            exit(1);
        }
    ' 2>/dev/null; then
        echo "[entrada] la base responde."
        break
    fi

    intentos=$((intentos + 1))
    echo "[entrada] la base todavia no responde (intento $intentos de $maximo)."
    sleep 2
done

if [ "$intentos" -ge "$maximo" ]; then
    echo "[entrada] ERROR: la base no respondio despues de $maximo intentos."
    exit 1
fi

if [ "${VINA_CORRER_MIGRACIONES:-false}" = "true" ]; then
    echo "[entrada] aplicando migraciones..."
    php artisan migrate --force
    echo "[entrada] migraciones aplicadas."
else
    echo "[entrada] migraciones omitidas (VINA_CORRER_MIGRACIONES no es true)."
fi

echo "[entrada] limpiando cache de configuracion y rutas..."
# La cache se limpia SIEMPRE: si se guardo con el `.env` de una imagen
# anterior, seguiria apuntando a la base equivocada.
php artisan config:clear
php artisan route:clear
php artisan view:clear

# El trabajo programado corre aca, dentro del contenedor, en vez de por
# cron del sistema. Ver docs/04.
echo "[entrada] arrancando el planificador..."
php artisan schedule:work &
PID_PLANIFICADOR=$!

echo "[entrada] arrancando la cola..."
php artisan queue:work --sleep=3 --tries=3 --max-time=3600 &
PID_COLA=$!

# Si alguno de los dos se cae, el contenedor se cae. Un contenedor vivo sin
# trabajo programado es una bodega que no revisa que lote quedo parado, y eso
# parece exactamente que todo esta bien.
echo "[entrada] arrancando php-fpm..."

# `trap` para que las senales de parada lleguen a los procesos hijos: sin
# esto, `docker stop` espera el tiempo de espera completo y despues mata todo
# a la fuerza.
trap 'kill -TERM $PID_PLANIFICADOR $PID_COLA 2>/dev/null || true' TERM INT

php-fpm --nodaemonize &
PID_FPM=$!

# Se vigila a los tres con un bucle. NO se usa `wait -n`: esa opcion es de
# bash y el `sh` de Alpine (busybox) puede no tenerla, y el contenedor
# terminaria sin avisar.
while kill -0 "$PID_PLANIFICADOR" 2>/dev/null \
   && kill -0 "$PID_COLA" 2>/dev/null \
   && kill -0 "$PID_FPM" 2>/dev/null; do
    sleep 5
done

echo "[entrada] un proceso termino. El contenedor se detiene."

# Si el proceso que cae es php-fpm (el que atiende la API), el codigo de
# salida es distinto de cero, para que el orquestador lo reinicie.
if ! kill -0 "$PID_FPM" 2>/dev/null; then
    CODIGO=1
else
    CODIGO=0
fi

kill -TERM $PID_PLANIFICADOR $PID_COLA $PID_FPM 2>/dev/null || true

exit "$CODIGO"