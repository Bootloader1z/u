# syntax=docker/dockerfile:1.6
# =====================================================================
# Fast Upload Transfer - PHP-FPM container (paired with nginx)
# =====================================================================

# --------- Stage 1: Composer dependencies ----------------------------
FROM composer:2 AS deps
WORKDIR /build
COPY composer.json composer.lock* ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_HOME=/tmp/composer-cache \
    composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --prefer-dist \
        --optimize-autoloader \
        --classmap-authoritative

# --------- Stage 2: Runtime ------------------------------------------
FROM php:8.3-fpm-alpine AS runtime

# Build deps for native extensions, then purge.
# Keep `zz-docker.conf` in place -- it's what sets daemonize=no and
# routes the FPM master log to stderr for `docker logs`.
RUN set -eux; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libzip-dev; \
    apk add --no-cache libzip curl tini; \
    docker-php-ext-install -j"$(nproc)" zip opcache; \
    apk del --no-network .build-deps; \
    rm -rf /tmp/* /var/tmp/* /usr/src/*

# PHP runtime + FPM pool overrides. We REPLACE the shipped www.conf
# rather than adding a second pool file -- avoids collisions.
COPY docker/php.ini       /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php-fpm.conf  /usr/local/etc/php-fpm.d/www.conf

# Healthcheck helper: tiny TCP probe against php-fpm on :9000.
COPY docker/fpm-ping.php  /usr/local/bin/fpm-ping.php

WORKDIR /var/www/html

# Install PHP dependencies from the Composer stage.
COPY --from=deps --chown=www-data:www-data /build/vendor ./vendor

# Copy app source (respects .dockerignore).
COPY --chown=www-data:www-data . .

# Strip Docker/infra files, prep runtime dirs, tighten permissions.
# security.log becomes a symlink into the writable `logs` volume so
# the app can still append to it under a read-only root filesystem.
RUN set -eux; \
    rm -rf ./docker ./Dockerfile ./Dockerfile.* ./docker-compose.yml \
           ./.dockerignore ./Readme.md 2>/dev/null || true; \
    mkdir -p ./s ./chunks ./rate_limits ./logs; \
    rm -f ./security.log; \
    ln -s logs/security.log ./security.log; \
    chown -R www-data:www-data /var/www/html; \
    find /var/www/html -type d -exec chmod 750 {} \; ; \
    find /var/www/html -type f -exec chmod 640 {} \; ; \
    chmod -R 770 /var/www/html/s /var/www/html/chunks \
                 /var/www/html/rate_limits /var/www/html/logs; \
    chmod 755 /usr/local/bin/fpm-ping.php

USER www-data

EXPOSE 9000

ENTRYPOINT ["/sbin/tini", "--"]
CMD ["php-fpm", "-F", "-O"]

HEALTHCHECK --interval=10s --timeout=5s --start-period=20s --retries=5 \
    CMD php /usr/local/bin/fpm-ping.php || exit 1
