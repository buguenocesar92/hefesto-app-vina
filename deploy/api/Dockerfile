# ---------------------------------------------------------------------------
# Imagen de la API (Laravel)
# ---------------------------------------------------------------------------
# Aplicacion PROPIA de Hefesto Studio. NO es un kit de despliegue de una
# herramienta de terceros: el `composer.json` de esta aplicacion no lleva
# dependencias de este repositorio.

# La imagen de PHP esta fijada por tag exacto. FALTA fijarla tambien por
# digest SHA256 antes de produccion (docs/10).
# =============================================================================
# CVE-2026-109618 / CVE-2026-109619 (PHP2Shell, 2026-10-22)
# =============================================================================
# La imagen se construye con PHP 8.4.27, que es la version PARCHEADA
# de la serie 8.4. Las versiones parcheadas de PHP son
# 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12.
#
# Verificado el 2026-10-22 en el servidor de desarrollo, con el tag exacto:
#
#     $ docker run --rm php:8.4-fpm-alpine php -v
#     PHP 8.4.27 (cli) (built: Oct 21 2026 22:52:58)
#
# El tag `php:8.4-fpm-alpine` es FLOTANTE: no se cambia, se deja constancia.
# El tag resuelve hoy a PHP 8.4.27, que es la version parcheada de la serie
# 8.4. Se deja escrito porque un tag flotante puede mudar sin que nadie lo
# note: la comprobacion va en el candado (api/tests/Unit/), que falla si el
# tag vuelve a quedar por debajo de la version parcheada.
#
# OJO: LA IMAGEN DESPLIEGADA HAY QUE RECONSTRUIRLA. El parche de PHP entra en
# el contenedor por la IMAGEN, no por este repositorio: mientras no se
# reconstruya y se redespliegue, el contenedor sigue con el PHP viejo. El
# endurecimiento de mas abajo (el ini y el pool de php-fpm) es la red de seguridad
# para ese mientras, y lo que sobrevive a un redeploy.
FROM php:8.4-fpm-alpine AS base

# `intl` lo necesita la conversion de fechas y de texto; `pdo_pgsql`, la
# conexion a PostgreSQL. Sin los dos, la aplicacion no arranca.
RUN apk update \
    && apk upgrade \
    && apk add --no-cache \
        icu-dev \
        libpq-dev \
        postgresql-dev \
        oniguruma-dev \
        libzip-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure intl --enable-intl \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        zip \
    && apk del --no-network $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Se copian primero los manifiestos para reutilizar la capa de instalacion:
# si solo cambian los archivos de codigo, Composer no se vuelve a ejecutar.
COPY api/composer.json api/composer.lock* ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

# Se copian primero los manifiestos del esqueleto. El archivo `artisan` lo
# genera Composer, asi que todavia no existe en este punto: se copia despues
# con el codigo.
COPY api/bootstrap/cache/ ./.bootstrap-cache/

COPY api/ ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
    && composer dump-autoload --optimize --classmap-authoritative \
    && rm -rf .bootstrap-cache/

COPY deploy/api/docker/entrypoint.sh /usr/local/bin/entrypoint.sh

# Endurecimiento PHP2Shell (CVE-2026-109618 / CVE-2026-109619):
# la via CONFIRMADA (session.upload_progress) apagada, y los
# trabajadores de php-fpm reciclados cada 100 peticiones.
COPY deploy/api/docker/99-php2shell-hardening.ini /usr/local/etc/php/conf.d/99-php2shell-hardening.ini
COPY deploy/api/docker/zz-php2shell-fpm.conf /usr/local/etc/php-fpm.d/zz-php2shell-hardening.conf
RUN chmod +x /usr/local/bin/entrypoint.sh

RUN addgroup -g 1000 vina \
    && adduser -u 1000 -G vina -h /var/www/html -s /bin/sh -D vina \
    && chown -R vina:vina /var/www/html

USER vina

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "--nodaemonize"]