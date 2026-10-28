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

ARG PHP_IMAGE=php:8.4-fpm-alpine@sha256:93aa3f61216b73cc6f878235c9772849449f4efc18dfe3d6678eed645076c00d

FROM ${PHP_IMAGE} AS base

ARG PHPREDIS_VERSION=6.2.0
ARG MAILPARSE_VERSION=3.2.0

# ldap (feature 012, research R2): the LDAP identity provider. libldap and the CA bundle stay at
# runtime; openldap-dev is only needed to compile the extension.
RUN set -eux; \
    apk upgrade --no-cache; \
    apk add --no-cache icu-libs libldap ca-certificates; \
    # Feature 011 (research R20, R29): ban rules must compile in the ban service's dialect,
    # Python `re`, which only Python decides reliably (PSF-2.0, GPL-compatible).
    # Require the CVE-2026-115557 fix in both dev and production images.
    apk add --no-cache 'python3>=3.14.10-r0'; \
    python3 -c "import re, json"; \
    # Feature 006 (research R7, R23): the legacy quarantine notifier's template engine, sandbox and
    # HTML-to-text converter (Jinja2 and MarkupSafe BSD-3-Clause, html2text GPL-3.0), so that a
    # notification template is checked exactly as quarantine_notify.py renders it.
    apk add --no-cache py3-jinja2 py3-markupsafe py3-html2text; \
    python3 -I -c "import jinja2.sandbox, html2text"; \
    # Feature 013 (research R3, R27): GD decodes uploaded raster logos to check that they are valid
    # images (legacy uses Imagick). Only libpng and libjpeg-turbo stay at runtime.
    apk add --no-cache libpng libjpeg-turbo; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev linux-headers openldap-dev libpng-dev libjpeg-turbo-dev zlib-dev; \
    docker-php-ext-configure gd --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql intl ldap gd; \
    php -m | grep -qi '^gd$'; \
    # OPcache is compiled into the official PHP 8.4 images; install it only where it is not.
    if ! php -m | grep -qi '^Zend OPcache$'; then docker-php-ext-install opcache; fi; \
    pecl install "redis-${PHPREDIS_VERSION}"; \
    docker-php-ext-enable redis; \
    # Feature 006 (research R5, R23): PECL mailparse (PHP-3.01), required by legacy's MIME parser
    # php-mime-mail-parser, which parses quarantined messages exactly as the legacy detail view.
    pecl install "mailparse-${MAILPARSE_VERSION}"; \
    docker-php-ext-enable mailparse; \
    php -m | grep -qi '^mailparse$'; \
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
    # Feature 010 (research R21, R25): named-checkzone for the zone-file test (BIND, MPL-2.0,
    # development only; the production stage does not contain it).
    apk add --no-cache bind-tools; \
    # cgi-fcgi: the harness that runs legacy sogo-auth.php in the tests (feature 005, R3).
    apk add --no-cache fcgi; \
    # Writable home for whichever uid runs the container (compose maps the host user).
    mkdir -p /home/dev; \
    chmod 1777 /home/dev

COPY --from=composer:2@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc /usr/bin/composer /usr/bin/composer

ENV HOME=/home/dev \
    COMPOSER_HOME=/home/dev/.composer \
    COMPOSER_CACHE_DIR=/home/dev/.composer/cache \
    npm_config_cache=/home/dev/.npm

USER 82

# ---------------------------------------------------------------------------------------------
# The bundled contract served at /api/v2/openapi.yaml and the Swagger UI assets of /api/v2/docs.
FROM node:22-alpine@sha256:fe4f6d74e20370acb04ad889077d14d0f64a9c8ea6a42bc60cddf1e844699914 AS assets

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
