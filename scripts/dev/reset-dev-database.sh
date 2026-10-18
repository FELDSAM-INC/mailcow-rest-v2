#!/usr/bin/env bash
#
# Resets the dev database `mailcow` of the dev stack: the upstream schema fixture, the restv2_
# migrations and the development accounts of quickstart.md Part B.
#
#   docker compose run --rm app scripts/dev/reset-dev-database.sh

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

tests/bin/prepare-database.sh mailcow local

if [[ -f database/seeders/DevelopmentSeeder.php ]]; then
    DBNAME=mailcow php artisan db:seed --class=DevelopmentSeeder --force --no-interaction --env=local
else
    echo "notice: database/seeders/DevelopmentSeeder.php does not exist yet (T111); nothing seeded" >&2
fi
