# mailcow REST API v2

Contract-first REST API for mailcow-dockerized. Laravel 13 (PHP 8.3+), MariaDB (upstream mailcow
schema), Redis. Base path `/api/v2`. The Vue web app lives in a separate repo
(`../mailcowui-ng`) and consumes this API's released contract.

**Read first**: `.specify/memory/constitution.md`. Its non-negotiables:
- The OpenAPI spec in `api/` is the source of truth.
- Upstream mailcow tables are never migrated.
- Every upstream write goes through `app/Services/` and reproduces the legacy side effects.
- Lists return `{data, meta}`, errors are RFC 9457 problem+json, and write codes are 201/200/204.
- Tests come first and run on MariaDB with the real schema.

**Legacy reference**: `source_code/` (untracked symlink to a mailcow-dockerized checkout). Business
logic is in `source_code/data/web/inc/functions.*.inc.php` and the schema in
`source_code/data/web/inc/init_db.inc.php`. `docs/legacy-inventory.md` summarises the parity
surface. Never modify `source_code/`.

**Roadmap**: `docs/roadmap.md`.
