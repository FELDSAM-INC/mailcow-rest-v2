#!/usr/bin/env bash
#
# Recreates a database with mailcow's upstream schema and the API's own tables (research R3).
#
#   tests/bin/prepare-database.sh <database> [testing|local]
#
#   1. Connects as root to $DBHOST (password $DBROOT, mailcow.conf's name) and recreates the
#      database (DROP DATABASE IF EXISTS, CREATE DATABASE ... utf8mb4), granting it to $DBUSER.
#   2. Loads tests/fixtures/schema/${MAILCOW_SCHEMA:-2026-09a}.sql.
#   3. Runs `php artisan migrate --force` in the given environment (default testing), which
#      creates only restv2_ tables.
#
# Runs inside the dev container (`docker compose run --rm app tests/bin/prepare-database.sh
# restv2_test`) or in CI. It destroys the database, so it refuses mailcow's production host name.

set -euo pipefail

DATABASE="${1:-}"
APP_ENVIRONMENT="${2:-testing}"

if [[ ! "$DATABASE" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "usage: $0 <database> [testing|local]" >&2
    exit 64
fi
if [[ "$APP_ENVIRONMENT" != "testing" && "$APP_ENVIRONMENT" != "local" ]]; then
    echo "error: the environment must be testing or local, not '$APP_ENVIRONMENT'" >&2
    exit 64
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCHEMA="${MAILCOW_SCHEMA:-2026-09a}"
FIXTURE="$ROOT/tests/fixtures/schema/$SCHEMA.sql"

: "${DBHOST:?DBHOST is not set}"
: "${DBUSER:?DBUSER is not set}"
DBROOT="${DBROOT:-root}"
DBPORT="${DBPORT:-3306}"

if [[ "$DBHOST" == "mysql-mailcow" || "$DBHOST" == "mysql" ]]; then
    echo "error: refusing to recreate a database on mailcow's own server ($DBHOST)" >&2
    exit 1
fi
if [[ ! -f "$FIXTURE" ]]; then
    echo "error: schema fixture $FIXTURE not found" >&2
    exit 1
fi

sql() { mariadb --skip-ssl --protocol=tcp -h"$DBHOST" -P"$DBPORT" -uroot -p"$DBROOT" "$@"; }

sql -e "DROP DATABASE IF EXISTS \`$DATABASE\`;
        CREATE DATABASE \`$DATABASE\` CHARACTER SET utf8mb4;
        GRANT ALL ON \`$DATABASE\`.* TO '$DBUSER'@'%';"
sql "$DATABASE" <"$FIXTURE"

cd "$ROOT"
DBNAME="$DATABASE" php artisan migrate --force --no-interaction --env="$APP_ENVIRONMENT"

echo "prepared database $DATABASE (schema $SCHEMA, environment $APP_ENVIRONMENT)" >&2
