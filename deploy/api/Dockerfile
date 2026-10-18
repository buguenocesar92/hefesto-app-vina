# ---------------------------------------------------------------------------
# Imagen de la API (Laravel)
# ---------------------------------------------------------------------------
# Aplicacion PROPIA de Hefesto Studio. NO es un kit de despliegue de una
# herramienta de terceros: el `composer.json` de esta aplicacion no lleva
# dependencias de este repositorio.

# La imagen de PHP esta fijada por tag exacto. FALTA fijarla tambien por
# digest SHA256 antes de produccion (docs/10).
FROM php:8.4-fpm-alpine AS base

# `intl` lo necesita la conversion de fechas y de texto; `pdo_pgsql`, la
# conexion a PostgreSQL. Sin los dos, la aplicacion no arranca.
RUN apk add --no-cache \
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
RUN chmod +x /usr/local/bin/entrypoint.sh

RUN addgroup -g 1000 vina \
    && adduser -u 1000 -G vina -h /var/www/html -s /bin/sh -D vina \
    && chown -R vina:vina /var/www/html

USER vina

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "--nodaemonize"]