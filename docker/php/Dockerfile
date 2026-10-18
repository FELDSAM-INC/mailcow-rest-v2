# syntax=docker/dockerfile:1
#
# mailcow REST API v2 container image (research R1, R23).
#
#   base        php-fpm 8.4 with the runtime extensions, non-root (uid 82)
#   dev         base plus the toolchain (Composer, Node, database and Redis clients, pcov)
#   production  the deployable image (completed in T108)
#
# Build context: the repository root (`docker build -f docker/php/Dockerfile .`).

ARG PHP_IMAGE=php:8.4-fpm-alpine

FROM ${PHP_IMAGE} AS base

ARG PHPREDIS_VERSION=6.2.0

RUN set -eux; \
    apk add --no-cache icu-libs; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev linux-headers; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql intl; \
    # OPcache is compiled into the official PHP 8.4 images; install it only where it is not.
    if ! php -m | grep -qi '^Zend OPcache$'; then docker-php-ext-install opcache; fi; \
    pecl install "redis-${PHPREDIS_VERSION}"; \
    docker-php-ext-enable redis; \
    apk del .build-deps; \
    rm -rf /tmp/pear ~/.pearrc

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-restv2.ini
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/zz-restv2.conf

WORKDIR /app
USER www-data

# ---------------------------------------------------------------------------------------------
FROM base AS dev

ARG PCOV_VERSION=1.0.12

USER root

RUN set -eux; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS; \
    pecl install "pcov-${PCOV_VERSION}"; \
    docker-php-ext-enable pcov; \
    apk del .build-deps; \
    rm -rf /tmp/pear ~/.pearrc; \
    apk add --no-cache bash git unzip nodejs npm mariadb-client redis; \
    # Writable home for whichever uid runs the container (compose maps the host user).
    mkdir -p /home/dev; \
    chmod 1777 /home/dev

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV HOME=/home/dev \
    COMPOSER_HOME=/home/dev/.composer \
    COMPOSER_CACHE_DIR=/home/dev/.composer/cache \
    npm_config_cache=/home/dev/.npm

USER 82

# ---------------------------------------------------------------------------------------------
# Placeholder: the contract bundle, `composer install --no-dev`, route and event caches and the
# entrypoint are added in T108.
FROM base AS production
