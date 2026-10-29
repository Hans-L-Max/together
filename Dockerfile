# syntax=docker/dockerfile:1

# Together – production image.
#
#   docker compose build      (see compose.yaml)
#
# Targets:
#   app  PHP-FPM 8.5 with the application (also runs queue worker and scheduler)
#   web  Nginx serving static assets and passing PHP requests to "app"
#
# PHP_BASE and VENDOR let you swap in prebuilt stages (for example when
# building behind a mirror); by default everything is built from scratch.
# Behind a TLS-intercepting proxy, pass its CA certificate as a build secret:
#   docker build --secret id=ca,src=/path/to/ca.crt ...

ARG PHP_VERSION=8.5
ARG PHP_BASE=php-base
ARG VENDOR=vendor

# ---------------------------------------------------------------------------
# PHP runtime with the extensions Laravel needs
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-trixie AS php-base

RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends libicu-dev libzip-dev libpq-dev; \
    docker-php-ext-install -j"$(nproc)" intl zip bcmath pcntl pdo_pgsql; \
    # Keep only the shared libraries the compiled extensions link against.
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark; \
    find /usr/local -type f -executable -exec ldd '{}' ';' \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); printf "*%s\n", so }' \
        | sort -u | xargs -r dpkg-query --search | cut -d: -f1 | sort -u | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*

# ---------------------------------------------------------------------------
# Composer dependencies (no dev packages)
# ---------------------------------------------------------------------------
FROM ${PHP_BASE} AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock ./
RUN --mount=type=secret,id=ca,required=false \
    COMPOSER_CAFILE="$( [ -f /run/secrets/ca ] && echo /run/secrets/ca )" \
    composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts

# ---------------------------------------------------------------------------
# Frontend assets
# ---------------------------------------------------------------------------
FROM ${VENDOR} AS vendor-final

FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json .npmrc ./
RUN --mount=type=secret,id=ca,required=false \
    NODE_EXTRA_CA_CERTS="$( [ -f /run/secrets/ca ] && echo /run/secrets/ca )" \
    npm ci --no-audit --no-fund

COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
# Tailwind scans Flux's Blade stubs and imports its stylesheet.
COPY --from=vendor-final /app/vendor/livewire/flux ./vendor/livewire/flux
COPY --from=vendor-final /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN npm run build

# ---------------------------------------------------------------------------
# Application (PHP-FPM)
# ---------------------------------------------------------------------------
FROM ${PHP_BASE} AS app

WORKDIR /var/www/html

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/app.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/together-entrypoint

COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor-final /app/vendor ./vendor
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build

RUN set -eux; \
    rm -rf tests .github docker; \
    mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; \
    chown -R www-data:www-data storage bootstrap/cache; \
    su www-data -s /bin/sh -c 'php artisan package:discover --ansi'

USER www-data

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

EXPOSE 9000

ENTRYPOINT ["together-entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# Web server (Nginx)
# ---------------------------------------------------------------------------
FROM nginx:1.29-alpine AS web

COPY docker/nginx/default.conf.template /etc/nginx/templates/default.conf.template
COPY --from=app /var/www/html/public /var/www/html/public

# Uploaded files live in the shared storage volume (see compose.yaml).
RUN ln -s /var/www/html/storage/app/public /var/www/html/public/storage

ENV PHP_FPM_HOST=app:9000

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1:8080/up || exit 1
