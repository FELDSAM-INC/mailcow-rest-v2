#!/bin/sh
# Entrypoint of the production image (research R23).
#
# With arguments it runs them instead, for one-off commands such as
#   docker run --rm ghcr.io/feldsam-inc/mailcow-rest-v2 php /app/artisan restv2:health --live
#
# Otherwise it caches the configuration from the container's environment into the tmpfs
# /app/bootstrap/cache, applies this API's own migrations (only restv2_ tables; --isolated takes a
# lock, so that one container migrates at a time) and runs php-fpm in the foreground.
set -eu

if [ "$#" -gt 0 ]; then
    exec "$@"
fi

php /app/artisan config:cache --no-interaction
php /app/artisan migrate --force --isolated --no-interaction

exec php-fpm -F
