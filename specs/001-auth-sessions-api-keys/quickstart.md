# Quickstart and Validation Guide: Feature 001

**Feature**: `specs/001-auth-sessions-api-keys` | **Plan**: [plan.md](./plan.md) |
**Contract**: [`api/openapi.yaml`](../../api/openapi.yaml)

This guide proves that the feature works. Part A covers the dev stack and the automated suite.
Part B covers manual end-to-end checks against the dev stack. Part C covers checks on a real
mailcow host. It references the contract and the data model instead of repeating them.

## Prerequisites

- Docker with Compose v2, run without sudo. The host needs no PHP, Composer, MariaDB or Redis.
- For Part C: a mailcow-dockerized installation on upstream `2026-09a` (or the FeldHost fork
  `feldhost-202609a`), reachable over HTTPS under `MAILCOW_HOSTNAME`.
- `curl` and `jq` on the host for the manual checks.

## Part A: Dev stack and automated suite

```bash
# 1. Build and start MariaDB 10.11, Redis 7.4.11, php-fpm 8.4 (app) and nginx (web).
docker compose up -d --build

# 2. Install dependencies and create the testing environment file.
docker compose run --rm app composer install
docker compose run --rm app cp .env.example .env
docker compose run --rm app php artisan key:generate

# 3. Lint and bundle the contract (Redocly CLI inside the app container).
docker compose run --rm app composer contract:lint
docker compose run --rm app composer contract:bundle

# 4. Run the whole suite: prepare the database (load the 2026-09a schema fixture, then the
#    restv2_ migrations), run PHPUnit with contract validation of every request and response,
#    then check that every contract operation was covered.
docker compose run --rm app composer test

# 5. Quality gates.
docker compose run --rm app vendor/bin/pint --test
docker compose run --rm app vendor/bin/phpstan analyse
```

**Expected**:

- `contract:lint` reports no errors. The four `operation-4xx-response` findings on the system
  endpoints are listed in `.redocly.lint-ignore.yaml`.
- `composer test` ends green.
- `build/contract/coverage.json` lists all 31 operations as covered.
- Pint and PHPStan report nothing.

### Regenerating the schema fixture (only for a new upstream release)

```bash
scripts/schema/generate-fixture.sh 2026-09a     # MAILCOW_REPO defaults to ./source_code
git diff --stat tests/fixtures/schema/          # expect no change for 2026-09a
```

**Expected**: The header of `tests/fixtures/schema/2026-09a.sql` names `db_version 18082026_1200`.
The file holds 54 tables and 6 views and contains no `DEFINER=` clause.

## Part B: Manual end-to-end checks against the dev stack

The dev stack serves the API at `http://localhost:8080/api/v2`. Run
`docker compose run --rm app scripts/dev/reset-dev-database.sh` first. It loads the schema fixture
into the dev database `mailcow`, runs the `restv2_` migrations and calls
`php artisan db:seed --class=DevelopmentSeeder`, which creates:

- `admin` (`BLF-CRYPT`, no second factor);
- `reseller` (domain admin of `example.org` and `example.net`);
- `alice@example.org` (mailbox with TOTP secret `JBSWY3DPEHPK3PXP`);
- `bob@example.org` (mailbox with `force_pw_update`).

Every password is `Passw0rd!dev`. The seeder refuses to run outside `APP_ENV=local`. In the dev
stack, `SESSION_COOKIE=restv2_session` is set, because browsers drop `__Host-` cookies on plain
HTTP. Production keeps `__Host-restv2_session`.

```bash
B=http://localhost:8080/api/v2; J='-H Content-Type:application/json'; C='-c jar -b jar'

# US5: public configuration without authentication, then a conditional revalidation.
curl -s $B/public-config | jq '.data | {productName, defaultLanguage, logos}'
ETAG=$(curl -sI $B/public-config | awk -F': ' 'tolower($1)=="etag"{print $2}' | tr -d '\r')
curl -s -o /dev/null -w '%{http_code}\n' -H "If-None-Match: $ETAG" $B/public-config  # 304
curl -s $B/public-config | jq '.data | {appsLabel, appLinks}'   # Webmail (builtIn), hidden on login

# CSRF bootstrap (US1). Without the token, sign-in is refused.
T=$(curl -s $C $B/csrf-token | jq -r .data.token)
curl -s $C $J -X POST $B/session -d '{"username":"admin","password":"Passw0rd!dev"}' | jq .type
#   -> "/api/v2/problems/csrf-token-mismatch"

# US1: administrator password sign-in, identity, sign-out.
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/session \
  -d '{"username":" ADMIN ","password":"Passw0rd!dev"}' | jq '.data.state'   # "active"
curl -s $C $B/me | jq '.data | {role, scope, authentication}'
curl -s $C -H "X-CSRF-Token: $T" -X DELETE -o /dev/null -w '%{http_code}\n' $B/session  # 204
curl -s $C $B/me | jq .type                                     # ".../unauthenticated"

# US1: second factor (TOTP), with the code computed in the app container.
T=$(curl -s $C $B/csrf-token | jq -r .data.token)
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/session \
  -d '{"username":"alice@example.org","password":"Passw0rd!dev"}' | jq '.data.pendingSignIn.methods'
CODE=$(docker compose run --rm app php artisan tinker --execute \
  'echo (new RobThree\Auth\TwoFactorAuth(new App\Support\Totp\NoQrCodeProvider()))->getCode("JBSWY3DPEHPK3PXP");')
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/session/pending-sign-in/second-factor \
  -d "{\"type\":\"totp\",\"methodId\":<id from methods>,\"code\":\"$CODE\"}" | jq '.data.state'
#   -> "active". Sending the same code again in a new sign-in -> ".../second-factor-failed"

# US3: restricted session for a forced password change.
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/session \
  -d '{"username":"bob@example.org","password":"Passw0rd!dev"}' | jq '.data | {state, pendingActions}'
curl -s $C $B/me/preferences | jq '{type, pendingActions}'   # pending-action-required

# US1 AS-7: throttling. The 11th wrong attempt within 15 minutes answers 429 with Retry-After.
for i in $(seq 1 11); do
  curl -s $C $J -H "X-CSRF-Token: $T" -o /dev/null -w '%{http_code} ' -X POST $B/session \
    -d '{"username":"nobody","password":"wrong"}'; done; echo

# Fail2ban: watch the channel while signing in wrongly.
docker compose exec redis redis-cli -a "$REDISPASS" SUBSCRIBE F2B_CHANNEL
#   -> "mailcow UI: Invalid password for nobody by 172.x.y.z"

# US4: API keys. As admin, create a key, use it, revoke it.
#   (Sign in as admin first, as above.)
KEY=$(curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/api-keys \
  -d '{"name":"billing","ownerUsername":"reseller","accessLevel":"readOnly"}' | jq -r .data.secret)
curl -s $C "$B/api-keys/owner-candidates?q=res" | jq '.data'     # [{"username":"reseller","role":"domainAdmin"}]
curl -s -H "X-API-Key: $KEY" $B/me | jq '.data | {role, scope, authentication}'
curl -s -H "X-API-Key: $KEY" $J -X POST $B/api-keys -d '{}' | jq .type          # session-required
curl -s -H "X-API-Key: $KEY" -H 'X-CSRF-Token: x' -X DELETE $B/session | jq .type # session-required
curl -s -H "X-API-Key: mcv2_0000000000000000000000000000000000000000" $B/me | jq .type  # api-key-invalid

# US6: audit log, filtered.
curl -s $C "$B/audit-events?outcome=failure&eventType=signIn&limit=5" | jq '.data[] | {eventType, failureReason, actor, clientAddress}'
```

**Expected**: Each command prints the value in its comment. No response or audit record contains
a password, a code, a key secret or a session ID. You can check this with
`docker compose logs app | grep -c Passw0rd`, which must print `0`.

Passkey flows (US2) need a browser. Open `http://localhost:8080/api/v2/docs`, or use the
mailcowui-ng dev server against this stack. The automated suite covers them with the virtual
authenticator (`tests/Feature/Passkeys/*`, `tests/Feature/Auth/PasskeySignIn*`).

## Part C: Validation on a mailcow host

1. **Install**:
   1. Build or pull the image.
   2. Append `deploy/docker-compose.override.yml` to the mailcow directory's
      `docker-compose.override.yml`.
   3. Add these lines to `mailcow.conf`:
      - `RESTV2_APP_KEY=base64:...`, the output of
        `docker compose run --rm restv2-mailcow php artisan key:generate --show`;
      - for the FeldHost fork only: `RESTV2_SASL_LOG_SERVICE=WEBUI`,
        `RESTV2_DEFAULT_UI_NAME=FeldHost™ Mailhosting UI`,
        `RESTV2_DEFAULT_LOGO_URL=/img/feldhost_logo.png` and
        `RESTV2_DEFAULT_LOGO_DARK_URL=/img/feldhost_logo_white.png`.
   4. Copy `deploy/nginx/site.restv2.custom` to `data/conf/nginx/`.
   5. Run `docker compose up -d restv2-mailcow` and `docker compose restart nginx-mailcow`.
2. **Health**: `docker compose ps restv2-mailcow` shows `healthy`.
   `curl -s https://$MAILCOW_HOSTNAME/api/v2/health/ready` shows
   `{"data":{"status":"ok","checks":{...}}}`.
3. **Coexistence (SC-003)**:
   - The legacy UI, `/api/v1` and `/SOGo` behave unchanged.
   - `docker compose exec mysql-mailcow mariadb -e "SHOW TABLES LIKE 'restv2\_%'" $DBNAME` lists
     only `restv2_api_keys`, `restv2_audit_events`, `restv2_migrations` and `restv2_preferences`.
   - The `versions.db_schema` value is unchanged.
4. **Parity (SC-001, SC-004)**:
   - Sign in through `/api/v2` with an existing admin, domain admin and mailbox user, using their
     existing passwords, TOTP and passkeys.
   - Sign in with the same accounts in the legacy UI.
   - Fail a password sign-in through `/api/v2` as many times as mailcow's fail2ban
     `max_attempts`. The client address must appear in the legacy UI's fail2ban ban list.
   - The mailbox user's "recent logins" in the legacy UI show the sign-in with the configured
     service label.
5. **Branding (SC-008)**: Change the announcement in the legacy "Customize" screen. The next
   `GET /api/v2/public-config` returns it.
6. **Real client address**: The audit records of the sign-ins show the browser's public address,
   not nginx's container address.
7. **Retention job**: `docker compose logs ofelia-mailcow | grep restv2_audit_purge` shows the job
   registered. `docker compose exec restv2-mailcow php artisan restv2:audit-purge` runs cleanly.
