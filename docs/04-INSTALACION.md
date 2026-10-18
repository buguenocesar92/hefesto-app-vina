# 04. Instalación

## Qué se necesita

- Docker y Docker Compose.
- Para probar sin Docker: PHP 8.4 con `pdo_pgsql` e `intl`, Composer, Node
  22.22.3 o superior, y un PostgreSQL 14 o superior.

**PostgreSQL, no SQLite.** El aislamiento entre viñas usa Row Level Security y
la inmutabilidad del proceso usa gatillos de la base. En SQLite, esas pruebas
pasarían sin comprobar nada. Ver
[10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).

## Desarrollo

```bash
cd apps/vina/deploy

# Levantar PostgreSQL, Redis, la API y la interfaz.
docker compose -f docker-compose.dev.yml up -d

# Crear el archivo de ambiente de la API.
cp ../api/.env.example ../api/.env

# Generar la clave.
docker compose -f docker-compose.dev.yml exec api php artisan key:generate
```

Las migraciones corren solas: en desarrollo `VINA_CORRER_MIGRACIONES=true`.

Datos de ejemplo, para ver el sistema con una temporada completa:

```bash
docker compose -f docker-compose.dev.yml exec api php artisan db:seed
```

Queda una viña con tres parcelas, dos vendimias, dos lotes (uno embotellado y
uno estabilizado) y una venta pagada. Los precios y las cifras son
**inventados**.

| Dirección | Qué es |
|---|---|
| `http://localhost:3000` | La interfaz |
| `http://localhost:8000/api/v1/salud` | La API |

## Producción

### 1. El archivo de ambiente

```bash
cd apps/vina/deploy
cp .env.example .env
```

Y reemplazar **todo**. El `docker-compose.prod.yml` se niega a arrancar si
falta `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, las URLs o la configuración
de Authentik: el `?` en la variable lo hace explícito.

Generar la clave:

```bash
docker compose -f docker-compose.prod.yml run --rm api php artisan key:generate --show
```

El resultado va en `APP_KEY` del `.env`.

### 2. Levantar

```bash
docker compose -f docker-compose.prod.yml up -d --build
```

Las migraciones **no** corren solas en producción. Ver más abajo.

### 3. Las migraciones, a mano y una vez

```bash
docker compose -f docker-compose.prod.yml run --rm api php artisan migrate --force
```

Por qué a mano: un despliegue que cambia el esquema sin que nadie lo decida es
un despliegue que puede cortar el servicio a mitad de una vendimia. Además,
correr migraciones al arrancar implica que con dos réplicas, las dos las
corrarían.

### 4. Comprobar el aislamiento ANTES de dar por buena la instalación

```bash
docker compose -f docker-compose.prod.yml exec api php artisan vina:verificar-aislamiento
```

Hace cuatro comprobaciones reales:

1. que el rol de la conexión no sea superusuario,
2. que no tenga `BYPASSRLS`,
3. que las tablas protegidas tengan RLS activada, **forzada**, y con política
   de lectura y de escritura,
4. que leyendo sin contexto la consulta **falle**, y que dos viñas de prueba no
   se vean entre sí.

Sale con código distinto de cero si algo falla, y ahí hay que detener el
despliegue.

### 5. La primera persona

Las personas existen en Authentik **y** en la base. El orden importa:

1. Crear el usuario en Authentik y asignarlo al grupo de operadores.
2. Correr el seeder de ejemplo, que crea las personas de ejemplo.
3. Crear las reales:

```bash
docker compose -f docker-compose.prod.yml exec api php artisan db:seed --class=VinaEjemploSeeder
```

Para una viña nueva se usa el mismo seeder como punto de partida y se cambian
los correos.

## Authentik

En el lado de Authentik:

- Una aplicación de tipo **OpenID Connect** con el proveedor (provider) de
  tipo OpenID Connect.
- **Issuer**: debe terminar en barra. `https://id.ejemplo.cl/application/o/vina/`.
  Una diferencia de una barra hace que todos los tokens parezcan de otro emisor
  y el acceso falle siempre.
- **Redirect URI**: la de `AUTHENTIK_REDIRECT_URI`, que es la ruta de callback
  de la API.
- El grupo `vina-operadores` (o el que se defina en `AUTHENTIK_GRUPO_OPERADORES`).

## Los tres procesos de la API

El contenedor corre `php-fpm`, `schedule:work` y `queue:work`, y se detiene si
alguno se cae. Ver [01-ARQUITECTURA.md](01-ARQUITECTURA.md).

Las tareas programadas corren dentro del contenedor, no por cron del sistema.
Para verlas:

```bash
docker compose -f docker-compose.prod.yml exec api php artisan schedule:list
```

## Los respaldos

Un respaldo manual:

```bash
./respaldos/respaldo.sh
```

Y para automatizarlo, un cron en el host que lo llame. Ver
[08-RESPALDOS.md](08-RESPALDOS.md).

## Comprobar que todo anda

```bash
# La API responde.
curl http://localhost/api/v1/salud

# El aislamiento.
docker compose -f docker-compose.prod.yml exec api php artisan vina:verificar-aislamiento

# Las pruebas.
docker compose -f docker-compose.prod.yml run --rm api php artisan test
```

Las pruebas necesitan una base `vina_test` separada. Ver
[10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).