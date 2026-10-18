# syntax=docker/dockerfile:1
#
# mailcow REST API v2 container image (research R1, R23).
#
#   base        php-fpm 8.4 with the runtime extensions, non-root (uid 82)
#   dev         base plus the toolchain (Composer, Node, database and Redis clients, pcov)
#   assets      the bundled contract and the Swagger UI assets (Node)
#   vendor      the application with its production dependencies (built in the dev stage)
#   production  the deployable image: read-only root filesystem, php-fpm on port 9000
#
# Build context: the repository root (`docker build -f docker/php/Dockerfile .`).

ARG PHP_IMAGE=php:8.4-fpm-alpine

FROM ${PHP_IMAGE} AS base

ARG PHPREDIS_VERSION=6.2.0

RUN set -eux; \
    apk add --no-cache icu-libs; \
    # Feature 011 (research R20, R29): ban rules must compile in the ban service's dialect,
    # Python `re`, which only Python decides reliably (PSF-2.0, GPL-compatible).
    apk add --no-cache python3; \
    python3 -c "import re, json"; \
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
# The bundled contract served at /api/v2/openapi.yaml and the Swagger UI assets of /api/v2/docs.
FROM node:22-alpine AS assets

ARG REDOCLY_CLI=@redocly/cli@2.64.1
ARG SWAGGER_UI_DIST=5.33.0

WORKDIR /build
COPY api/ api/
COPY redocly.yaml .redocly.lint-ignore.yaml ./

RUN set -eux; \
    npx --yes "${REDOCLY_CLI}" bundle api/openapi.yaml --output resources/contract/openapi.yaml; \
    mkdir -p swagger resources/docs; \
    cd swagger; \
    npm pack --silent "swagger-ui-dist@${SWAGGER_UI_DIST}" > /dev/null; \
    tar -xzf "swagger-ui-dist-${SWAGGER_UI_DIST}.tgz"; \
    cp package/swagger-ui.css package/swagger-ui-bundle.js package/LICENSE /build/resources/docs/

# ---------------------------------------------------------------------------------------------
# The application with its production dependencies, installed with the dev stage's toolchain.
FROM dev AS vendor

USER root
WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist

COPY . .
RUN set -eux; \
    composer dump-autoload --no-dev --classmap-authoritative --no-interaction; \
    rm -rf tests docker scripts phpunit.xml phpstan.neon pint.json \
        .env.example .editorconfig .gitattributes .gitignore .dockerignore redocly.yaml .redocly.lint-ignore.yaml \
        compose.yaml CLAUDE.md storage/logs/* bootstrap/cache/*.php

# ---------------------------------------------------------------------------------------------
# The deployable image (R23). It runs as www-data (uid 82) with a read-only root filesystem:
# /app/storage, /app/bootstrap/cache and /tmp are tmpfs mounts (deploy/docker-compose.override.yml).
# The route and event caches are built here, outside the tmpfs; the configuration cache is built
# by the entrypoint from the container's environment.
FROM base AS production

USER root

ENV APP_ENV=production \
    APP_ROUTES_CACHE=/app/bootstrap/image/routes-v7.php \
    APP_EVENTS_CACHE=/app/bootstrap/image/events.php \
    LOG_LEVEL=info

COPY --from=vendor --chown=82:82 /app /app
COPY --from=assets --chown=82:82 /build/resources/contract /app/resources/contract
COPY --from=assets --chown=82:82 /build/resources/docs /app/resources/docs
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN set -eux; \
    chmod 0755 /usr/local/bin/entrypoint.sh; \
    mkdir -p /app/bootstrap/image /app/storage/framework/cache /app/storage/logs; \
    php /app/artisan route:cache; \
    php /app/artisan event:cache; \
    rm -f /app/bootstrap/cache/*.php; \
    chown -R 82:82 /app/bootstrap /app/storage

USER www-data
WORKDIR /app
EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
