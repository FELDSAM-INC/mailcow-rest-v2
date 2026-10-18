# mailcow REST API v2

A standards-compliant REST API for [mailcow-dockerized](https://github.com/mailcow/mailcow-dockerized),
served at `/api/v2`. It runs next to the legacy mailcow UI and `/api/v1`, which keep working
unchanged, and it is the only backend of the separate [mailcowui-ng](../mailcowui-ng) web app.

The OpenAPI 3.1 contract in [`api/`](api/openapi.yaml) is the source of truth. A running server
serves it at `/api/v2/openapi.yaml` and renders it at `/api/v2/docs`. Problem types are documented
in [`docs/problems.md`](docs/problems.md) and served at `/api/v2/problems/{name}`.

Feature 001 (this release) covers password and passkey sign-in with legacy second factors,
sessions, "who am I" and preferences, administrator-managed API keys (`X-API-Key`), the public
branding configuration and the audit log. The roadmap is in [`docs/roadmap.md`](docs/roadmap.md).

## Supported mailcow releases

| mailcow-dockerized | `versions.db_schema` | Schema fixture |
|---|---|---|
| `2026-09a` (and the FeldHost fork `feldhost-202609a`) | `18082026_1200` | `tests/fixtures/schema/2026-09a.sql` |

The readiness check (`/api/v2/health/ready`, `restv2:health --ready`) fails on any other schema
version. A new upstream release is supported once the test suite passes on its schema
(`scripts/schema/generate-fixture.sh <tag>`, then add its `db_version` to
`config/restv2.php` `supported_schema_versions`).

## Architecture

- **One container** (`ghcr.io/feldsam-inc/mailcow-rest-v2`): Laravel 13 on PHP 8.4 under php-fpm,
  as the non-root user `www-data`, with a read-only root filesystem. It joins mailcow through
  `docker-compose.override.yml`; no other mailcow container or file is patched.
- **Routing**: nginx-mailcow reaches it over FastCGI through mailcow's `site.*.custom` include
  (`deploy/nginx/site.restv2.custom`), which also proxies `/ng/` to the mailcowui-ng container.
  The client address is nginx-mailcow's `REMOTE_ADDR`; no `X-Forwarded-*` header is trusted.
- **Data**: mailcow's MariaDB and Redis. Upstream tables are never migrated; the API reads them and
  writes only through services in `app/Services/`, reproducing legacy's side effects (second-factor
  re-activation, `sasl_log` recent logins, `fido2` passkeys, the fail2ban channel). The API's own
  tables (`restv2_api_keys`, `restv2_audit_events`, `restv2_preferences`, `restv2_migrations`) and
  Redis keys (`restv2:`) have their own prefix.
- **Sessions** are Redis sessions with a `__Host-` cookie and CSRF protection on every
  state-changing request. **API keys** are stored as SHA-256 hashes, act as their owner and can be
  read-only, expiring and network-restricted.
- **Configuration** comes from environment variables with `mailcow.conf` names; legacy PHP settings
  are read from mailcow's mounted `data/web/inc/vars{,.local}.inc.php`, and every one of them can be
  overridden with a `RESTV2_<NAME>` setting.

## Installation

On a mailcow-dockerized host (see also `specs/001-auth-sessions-api-keys/quickstart.md` Part C):

1. Merge [`deploy/docker-compose.override.yml`](deploy/docker-compose.override.yml) into the
   `docker-compose.override.yml` of your mailcow directory.
2. Add to `mailcow.conf`:
   - `RESTV2_APP_KEY=base64:…`, the output of
     `docker compose run --rm restv2-mailcow php artisan key:generate --show`;
   - for the FeldHost fork: `RESTV2_SASL_LOG_SERVICE=WEBUI`,
     `RESTV2_DEFAULT_UI_NAME=FeldHost™ Mailhosting UI`,
     `RESTV2_DEFAULT_LOGO_URL=/img/feldhost_logo.png` and
     `RESTV2_DEFAULT_LOGO_DARK_URL=/img/feldhost_logo_white.png`.
3. Copy [`deploy/nginx/site.restv2.custom`](deploy/nginx/site.restv2.custom) to
   `data/conf/nginx/site.restv2.custom`.
4. Run `docker compose up -d restv2-mailcow` and `docker compose restart nginx-mailcow`.
5. Check `docker compose ps restv2-mailcow` (healthy) and
   `curl -s https://$MAILCOW_HOSTNAME/api/v2/health/ready`.

The container applies its own migrations at start (`migrate --isolated`, only `restv2_` tables).
mailcow's ofelia scheduler runs the audit retention purge daily through the container's labels.

## Configuration

`mailcow.conf` variables used as they are: `MAILCOW_HOSTNAME`, `ADDITIONAL_SERVER_NAMES`, `TZ`,
`DBNAME`, `DBUSER`, `DBPASS`, `REDISPASS`, `REDIS_SLAVEOF_IP`, `REDIS_SLAVEOF_PORT`,
`IPV4_NETWORK`, `IPV6_NETWORK`, `WEBAUTHN_ONLY_TRUSTED_VENDORS` and `SKIP_SOGO`. `DBHOST`
(default `mysql-mailcow`) and `REDISHOST` (default `redis-mailcow`) locate the services.

Settings of this API:

| Variable | Default | Meaning | FeldHost |
|---|---|---|---|
| `RESTV2_APP_KEY` | (required) | Laravel application key (sessions, version tags) | |
| `RESTV2_SASL_LOG_SERVICE` | `MAILCOWUI` | Service label of the recent-login entries of web sign-ins | `WEBUI` |
| `RESTV2_DEFAULT_UI_NAME` | `<MAILCOW_HOSTNAME> - mail UI` | Default page title and product name | `FeldHost™ Mailhosting UI` |
| `RESTV2_DEFAULT_LOGO_URL` | `/img/cow_mailcow.svg` | Default light logo | `/img/feldhost_logo.png` |
| `RESTV2_DEFAULT_LOGO_DARK_URL` | `/img/cow_mailcow.svg` | Default dark logo | `/img/feldhost_logo_white.png` |
| `RESTV2_SIGNIN_MAX_FAILURES` | `10` | Failed sign-ins (and failed second factors) per username and address before `429` | |
| `RESTV2_SIGNIN_FAILURE_WINDOW` | `900` | Throttling window in seconds | |
| `RESTV2_CHALLENGE_TTL` | `300` | Lifetime in seconds of pending sign-ins, WebAuthn challenges and registration options | |
| `RESTV2_AUDIT_RETENTION_DAYS` | `365` | Audit retention | |
| `RESTV2_YUBICO_VERIFY_URL` | `https://api.yubico.com/wsapi/2.0/verify` | Yubico OTP verification service | |
| `RESTV2_MAILCOW_INC_DIR` | `/mailcow/web/inc` | Mount point of mailcow's `data/web/inc` (read-only) | |

Legacy PHP settings, read from mailcow's `vars.inc.php` and `vars.local.inc.php` when mounted, with
the upstream default otherwise; a non-empty `RESTV2_<NAME>` always wins (arrays as JSON):
`RESTV2_SESSION_LIFETIME`, `RESTV2_DEFAULT_LANG`, `RESTV2_AVAILABLE_LANGUAGES`,
`RESTV2_FIDO2_UV_FLAG_REGISTER`, `RESTV2_FIDO2_UV_FLAG_LOGIN`, `RESTV2_FIDO2_USER_PRESENT_FLAG`,
`RESTV2_FIDO2_FORMATS`, `RESTV2_WEBAUTHN_UV_FLAG_LOGIN`, `RESTV2_WEBAUTHN_USER_PRESENT_FLAG`,
`RESTV2_OTP_LABEL` and `RESTV2_MAILCOW_APPS`.

## Development

The host needs only Docker with Compose v2; every PHP, Composer and Node command runs in the dev
container.

```bash
docker compose up -d --build                                    # app, web, mariadb, redis
docker compose run --rm app composer install
docker compose run --rm app cp .env.example .env
docker compose run --rm app php artisan key:generate
docker compose run --rm app scripts/dev/reset-dev-database.sh   # dev database and accounts

docker compose run --rm app composer contract:lint              # Redocly lint
docker compose run --rm app composer test                       # bundle, MariaDB schema, PHPUnit, coverage
docker compose run --rm app vendor/bin/pint --test
docker compose run --rm app vendor/bin/phpstan analyse
docker compose run --rm app composer docs:assets                # Swagger UI assets for /api/v2/docs
docker build -f docker/php/Dockerfile --target production -t mailcow-rest-v2:local .
```

The dev stack serves `https://127.0.0.1:18443/api/v2` (self-signed certificate, generated at stack
start into a Docker volume; use `curl -k`) and `http://127.0.0.1:18080/api/v2` for health checks.
Tests run on MariaDB with the upstream schema fixture and on a real Redis, and validate every
request and response against the contract.

`scripts/dev/verify-nginx-snippet.sh [tag]` checks `deploy/nginx/site.restv2.custom` inside
mailcow's own nginx image: it renders the tag's `nginx.conf.j2` and `sites-default.conf.j2` with
the image's `bootstrap.py`, as its entrypoint does, with the snippet in place, and runs `nginx -t`.

The project follows Spec Kit (`specs/NNN-*/`) and the constitution in
`.specify/memory/constitution.md`; cross-feature rules are in `docs/conventions.md`.

## Licence

GPL-3.0-only, the same as mailcow. See [`LICENSE`](LICENSE). The documentation page bundles
Swagger UI (`swagger-ui-dist`, Apache-2.0), and the WebAuthn and TOTP libraries are MIT.
