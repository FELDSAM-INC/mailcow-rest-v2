---
description: "Task list for feature 001: authentication, sessions, API keys, identity and branding"
---

# Tasks: Authentication, Sessions, API Keys, Identity and Branding Configuration

**Input**: Design documents from `specs/001-auth-sessions-api-keys/`: [plan.md](./plan.md),
[spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md),
[quickstart.md](./quickstart.md). The contract is `api/` (already authored and lint-clean). Problem
types are in `docs/problems.md`.

**Tests**: These are mandatory (constitution VII, test-first). In every phase the test tasks come
first and must be observed **failing** before the implementation tasks of that phase start. Every
feature test runs on MariaDB, with the upstream `2026-09a` schema fixture, and on a real Redis.
Every request and response is validated against the contract by `tests/TestCase.php`.

**Organisation**: Phase 1 covers setup and Phase 2 the foundational pieces that block every story.
Phases 3 to 8 cover one user story each, in spec priority order (US1–US4 are P1, US5–US6 are P2).
Phase 9 is polish and operations.

**Commands**: Every PHP, Composer and Node command runs in the dev container:
`docker compose run --rm app <command>`. The host has PHP 8.1 only.

## Format: `- [ ] [ID] [P?] [Story?] Description with file path`

- **[P]**: The task can run in parallel with the other [P] tasks of the same block. It touches
  different files and does not depend on unfinished tasks.
- **[USn]**: The user story the task belongs to. Setup, Foundational and Polish tasks have no
  story label.
- FR, SC and AS references point to `spec.md`. R references point to `research.md`.

---

## Phase 1: Setup (shared infrastructure)

**Purpose**: Dev stack, Laravel skeleton, toolchain, schema fixture and CI. Nothing here contains
business logic.

- [X] T001 Create `docker/php/Dockerfile` with three stages.
  - `base`: `FROM php:8.4-fpm-alpine`. Install `pdo_mysql`, `intl` and `opcache` with
    `docker-php-ext-install`, and `redis` (phpredis 6.x) with `pecl install` plus
    `docker-php-ext-enable`. Set `WORKDIR /app` and `USER www-data` (uid 82).
  - `dev`: from `base`, as root. Add `pcov` (PECL), `composer` (copied from `composer:2`), `nodejs`,
    `npm`, `mariadb-client`, `redis` (for `redis-cli`), `git` and `unzip`, then switch back to uid 82.
  - `production`: a placeholder, completed in T108.

  Also create `docker/php/php.ini` (`expose_php=0`, `memory_limit=256M`, `opcache.enable=1`,
  `display_errors=0`, `log_errors=1`, `error_log=/proc/self/fd/2`) and `docker/php/php-fpm.conf`
  (pool `www`: `listen = 0.0.0.0:9000`, `clear_env = no`, `catch_workers_output = yes`,
  `decorate_workers_output = no`, `pm = dynamic`, `access.log = /dev/null`). See R1.

  *Implementation note*: the build context is the repository root (`docker build -f
  docker/php/Dockerfile .`), so that the `production` stage can copy the application. OPcache is
  compiled into the official PHP 8.4 images, so `docker-php-ext-install opcache` runs only where it
  is missing. The `dev` stage also adds `bash` (for the scripts) and a world-writable `/home/dev`.
- [X] T002 Create `compose.yaml` (the dev stack, R1) with four services:
  - `app`: build `docker/php` target `dev`, volume `.:/app`, env file `.env`, network alias
    `restv2-mailcow` (so the production nginx snippet works unchanged in dev);
  - `web`: `nginx:1.28-alpine`, port `8080:80`, mounting `docker/nginx/dev.conf` and
    `deploy/nginx/site.restv2.custom`;
  - `mariadb`: `mariadb:10.11`, `MYSQL_ROOT_PASSWORD=root`, `MYSQL_DATABASE=mailcow`,
    `MYSQL_USER=mailcow`, `MYSQL_PASSWORD=mailcow`, with a healthcheck;
  - `redis`: `redis:7.4.11-alpine`, `command: redis-server --requirepass ${REDISPASS:-devredispass}`.

  Also create `docker/nginx/dev.conf`, which defines one `server { listen 80; }` block that
  `include`s the snippet, and the production snippet `deploy/nginx/site.restv2.custom` itself
  (R23):
  - `location ^~ /api/v2/` with `resolver 127.0.0.11 valid=30s;`,
    `set $restv2_upstream restv2-mailcow:9000;`, `include /etc/nginx/fastcgi_params;`,
    `fastcgi_param SCRIPT_FILENAME /app/public/index.php;`, `fastcgi_param SCRIPT_NAME /index.php;`,
    `fastcgi_param HTTP_PROXY "";`, `client_max_body_size 1m;`, `fastcgi_read_timeout 60s;` and
    `fastcgi_pass $restv2_upstream;`;
  - `location ^~ /ng/` with the same resolver, `set $ng_upstream http://ng-mailcow:8080;`,
    `proxy_pass $ng_upstream;` and the `Host`, `X-Real-IP` and `X-Forwarded-Proto` headers. The
    variable without a URI part passes the original request path (`/ng/...`) through unchanged;
  - `location = /ng { absolute_redirect off; return 301 /ng/$is_args$args; }` (added at the request
    of the UI 001 plan, whose smoke suite T150 relies on the redirect and the path pass-through).

  The same file serves production and dev. In dev, `restv2-mailcow` resolves to the `app` alias,
  and `/ng/` answers `502` until a UI container exists.

  *Implementation note (deviation)*: the compose project is named `restv2`. `web` publishes
  `127.0.0.1:${RESTV2_WEB_PORT:-18080}:80` instead of `8080:80` (the dev host runs unrelated stacks),
  and MariaDB and Redis publish no host ports. `app` runs as the host user
  (`${RESTV2_UID:-1000}:${RESTV2_GID:-1000}`) so that the bind mount stays writable, and gets the
  connection settings shared by both environments (`DBHOST`, `DBUSER`, `DBPASS`, `DBROOT`,
  `REDISHOST`, `REDISPASS`) through `environment:` instead of `env_file: .env`: real environment
  variables beat Laravel's env files, so an `env_file` would force `.env`'s `DBNAME=mailcow` onto
  the test run. `mariadb` mounts mailcow's `my.cnf` of `2026-09a` (`docker/mariadb/mailcow.cnf`)
  and runs with `TZ=Europe/Prague`, the zone of `.env.testing`.
- [X] T003 Scaffold Laravel 13 at the repository root. Run
  `composer create-project laravel/laravel:^13.0 /tmp/skeleton` in the `app` container, then copy
  the skeleton into `/app` **without overwriting** `api/`, `docs/`, `specs/`, `.specify/`,
  `.claude/`, `CLAUDE.md`, `LICENSE` or `.gitignore` (merge its entries in). Then:
  - delete `package.json`, `vite.config.js`, `resources/js/`, `resources/css/` and
    `resources/views/welcome.blade.php`;
  - empty `routes/web.php`;
  - set `"license": "GPL-3.0-only"` in `composer.json`;
  - remove `laravel/sail` and `laravel/pail`.

  *Implementation note*: the Laravel 13 skeleton ships no Sail; it ships `laravel/pao` (agent test
  output), which R28 does not justify, so it was removed too. The skeleton's `users`, `cache` and
  `jobs` migrations, `App\Models\User`, its factory, `config/auth.php`, `config/mail.php`,
  `config/services.php`, the example tests and the `/up` health route were removed: the migrations
  would create unprefixed tables (constitution III), and nothing else uses them.
- [X] T004 Add dependencies (R28). Run `composer require lbuchs/webauthn:^2.2
  robthree/twofactorauth:^3.0` and `composer require --dev larastan/larastan:^3.0
  opis/json-schema:^2.6`. Add these `composer.json` scripts:
  - `contract:lint` → `npx --yes @redocly/cli@2.64.1 lint api/openapi.yaml`;
  - `contract:bundle` → bundle to `build/contract/openapi.yaml`, and dereferenced
    (`--dereferenced --ext json`) to `build/contract/openapi.json`;
  - `test` → `@contract:bundle`, then `tests/bin/prepare-database.sh restv2_test`, then
    `phpunit`, then `php tests/bin/check-contract-coverage.php`;
  - `analyse` → `phpstan analyse`;
  - `format` → `pint`.

  *Implementation note*: the coverage check reads its scope from `tests/contract-coverage.json`
  (the operations of each implemented feature and the ones still pending), because the shared
  contract already holds operations of later features (T018).
- [X] T005 [P] Append `/build/`, `/.phpunit.cache/` and `/storage/framework/testing/` to
  `.gitignore`. Keep the existing entries, including `/source_code`.
- [X] T006 [P] Create `redocly.yaml` (`extends: [recommended]`) and `.redocly.lint-ignore.yaml`,
  which lists exactly the four `operation-4xx-response` findings in
  `api/modules/system/{health-live,health-ready,openapi-document,docs}.yaml`. With them,
  `composer contract:lint` reports zero problems.
- [X] T007 [P] Create `phpstan.neon` (include `vendor/larastan/larastan/extension.neon`, `level: 6`,
  paths `app`, `tests`, `config`, `routes`) and `pint.json` (`"preset": "laravel"`).
- [X] T008 [P] Create `config/restv2.php`, `.env.example` and `.env.testing`.
  - `config/restv2.php` holds every `RESTV2_*` setting of R14 with its default, plus
    `supported_schema_versions => ['18082026_1200']`, `mailcow_hostname => env('MAILCOW_HOSTNAME')`,
    `skip_sogo => env('SKIP_SOGO', 'n') === 'y'`, `timezone => env('TZ', 'UTC')`, and
    `vars_overrides`, which maps each exported legacy variable to its `RESTV2_<NAME>` server
    setting (R14).
  - `.env.example` holds the dev defaults: `APP_ENV=local`, `DBHOST=mariadb`, `DBNAME=mailcow`,
    `DBUSER=mailcow`, `DBPASS=mailcow`, `REDISHOST=redis`, `REDISPASS=devredispass`,
    `MAILCOW_HOSTNAME=localhost`, `SKIP_SOGO=n`, `SESSION_COOKIE=restv2_session` and
    `RESTV2_MAILCOW_INC_DIR=`.
  - `.env.testing` holds `APP_ENV=testing`, `DBNAME=restv2_test`, `REDIS_DB=15`,
    `MAILCOW_HOSTNAME=mail.example.org`, `TZ=Europe/Prague` (so that the time-zone conversion is
    exercised) and `RESTV2_MAILCOW_INC_DIR=tests/fixtures/mailcow-inc`.

  *Implementation note*: Laravel loads only `.env.testing` in the test environment, so the file is
  complete: it also holds a fixed test-only `APP_KEY`, the dev connection settings and
  `LOG_STREAM=storage/logs/testing.log` (keeps JSON logs out of the PHPUnit output).
  `.gitignore` un-ignores it. `config/restv2.php` also holds `vars_defaults`, the upstream
  defaults that `MailcowConfig` falls back to.
- [X] T009 Configure the framework (R5, R6, R24):
  - `config/database.php`: the `mysql` connection reads `DBHOST`, `DBNAME`, `DBUSER` and `DBPASS`,
    and has **no** `timezone` key. Set `'migrations' => ['table' => 'restv2_migrations']`. The Redis
    client is `phpredis`. Connection `restv2`: host `REDIS_SLAVEOF_IP` else `REDISHOST`, port
    `REDIS_SLAVEOF_PORT` else 6379, password `REDISPASS`, database `REDIS_DB` default 0, prefix
    `restv2:`. Connection `mailcow`: the same server, database `REDIS_DB` default 0, prefix `''`.
  - `config/cache.php`: store `restv2` (driver `redis`, connection `restv2`, prefix `''`) as the
    default.
  - `config/session.php`: driver `redis`, `store => 'sessions'` (an extra cache store in
    `config/cache.php`: driver `redis`, connection `restv2`, prefix `session:`, so session keys are
    `restv2:session:<id>`), connection `restv2`, cookie
    `env('SESSION_COOKIE', '__Host-restv2_session')`, `secure => true`, `http_only => true`,
    `same_site => 'strict'`, `path => '/'`, `domain => null`, `lifetime => 180` (overridden at boot
    by T040).
  - `config/logging.php`: channel `stderr_json` (Monolog `StreamHandler` on `php://stderr` with
    `JsonFormatter`), the default.
  - `config/app.php`: `timezone => 'UTC'`.

  *Implementation note*: the `mysql` connection uses Laravel's `mariadb` driver. `config/queue.php`
  is reduced to `sync` (nothing is queued, and mailcow's database has no job tables).
- [X] T010 Configure `phpunit.xml`: test suites `Unit` (`tests/Unit`) and `Feature`
  (`tests/Feature`), `<env name="APP_ENV" value="testing" force="true"/>`, and
  `cacheDirectory=".phpunit.cache"`. Leave a placeholder `<extensions>` element for T018.
- [X] T011 Create `scripts/schema/init-db-harness.php` and `scripts/schema/generate-fixture.sh`
  exactly as in R2.
  - Input is the tag; `MAILCOW_REPO` defaults to `./source_code`. The script refuses non-tag refs.
  - It reads `init_db.inc.php`, `vars.inc.php` and `docker-compose.yml` with `git show <tag>:…`,
    and reads the `mysql-mailcow` and `php-fpm-mailcow` images from that `docker-compose.yml`.
  - It runs on a throwaway Docker network. It dumps with `mariadb-dump --no-data --skip-comments
    --skip-add-drop-table --routines --triggers`, plus `--no-create-info versions`. It strips
    `DEFINER=` and `AUTO_INCREMENT=`.
  - It writes a header with the tag, the `db_version` and the source SHA-256 to
    `tests/fixtures/schema/<tag>.sql`, then removes its containers.
- [X] T012 Run `scripts/schema/generate-fixture.sh 2026-09a` and commit
  `tests/fixtures/schema/2026-09a.sql`. Verify that the header says `db_version 18082026_1200`,
  that there are 54 `CREATE TABLE` and 6 `CREATE … VIEW` statements, and that no `DEFINER=` remains.

  *Implementation note*: `mariadb-dump` writes each view twice (a placeholder `CREATE VIEW … AS
  SELECT NULL` and the final `CREATE ALGORITHM=UNDEFINED … VIEW`), so the file has 6 final view
  definitions. The dump also uses `--skip-tz-utc`, and the `versions` row's `created` value is
  written as `NOW()`, so that regenerating gives a byte-identical file.
- [X] T013 [P] Create `scripts/legacy/extract-verify-hash.sh <tag>`. It extracts
  `verify_salted_hash()` and `verify_hash()` from `git show <tag>:data/web/inc/functions.inc.php`,
  renames them `legacy_verify_salted_hash` and `legacy_verify_hash`, and writes them with a header
  (tag, source SHA-256, GPL-3.0 notice) to `tests/fixtures/legacy/verify_hash.php`. Run it for
  `2026-09a` and commit the result (R12).
- [X] T014 Create `tests/bin/prepare-database.sh <database>` and `scripts/dev/reset-dev-database.sh`.
  - `prepare-database.sh` connects as root to `$DBHOST` and runs `DROP DATABASE IF EXISTS`,
    `CREATE DATABASE … CHARACTER SET utf8mb4` and `GRANT ALL ON <db>.* TO $DBUSER`. It loads
    `tests/fixtures/schema/${MAILCOW_SCHEMA:-2026-09a}.sql`, then runs
    `php artisan migrate --force --env=testing` (or `local` for the dev database).
  - `reset-dev-database.sh` calls it for `mailcow` and then
    `php artisan db:seed --class=DevelopmentSeeder`.

  *Implementation note*: the root password is `$DBROOT` (mailcow.conf's name, default `root`), the
  client runs with `--skip-ssl` (the MariaDB 11 client of Alpine requires TLS by default), the
  environment is the optional second argument (`testing` or `local`), and the script refuses
  mailcow's own host names (`mysql-mailcow`, `mysql`). The seeding step is skipped with a notice
  until `DevelopmentSeeder` exists (T111).
- [X] T015 Create `.github/workflows/ci.yml` with the jobs `contract`, `quality`, `tests` and
  `image` exactly as in R25. `tests` uses the services `mariadb:10.11` and `redis:7.4.11-alpine`,
  and a matrix over `tests/fixtures/schema/*.sql`. `contract` runs
  `tufin/oasdiff breaking --fail-on ERR` against the latest `v*` tag's bundle, and skips with a
  notice when no tag exists. `image` builds target `production`, runs `restv2:health --live` in the container, and runs
  `nginx -t` inside `nginx:1.28-alpine` on `docker/nginx/dev.conf`, which includes
  `deploy/nginx/site.restv2.custom`.

**Checkpoint**: `docker compose up -d --build` works, `composer contract:lint` reports zero
problems, and the fixture exists.

---

## Phase 2: Foundational (blocking prerequisites)

**Purpose**: Contract validation, problem rendering, configuration, upstream models, the session
and CSRF pipeline, audit writing, fail2ban, and the test toolkit. **No user story can start before
this phase is complete.**

### Test toolkit and tests for the foundation (write first, observe failing) ⚠️

- [X] T016 [P] Create `tests/Support/Contract/OpenApiContract.php` (R13, FR-051). It loads
  `build/contract/openapi.json`, matches `{method, path}` to an operation (literal segments win
  over templates; the server base `/api/v2` is stripped), and validates with
  `Opis\JsonSchema\Validator` (draft 2020-12):
  - path, query (including `explode` arrays) and header parameters;
  - the request body for its content type;
  - that the status is documented;
  - the required response headers (`Location`, `ETag`, `Retry-After`, `Cache-Control`);
  - the response body (`application/json`, `application/problem+json`).

  A request that matches no operation (unknown path, or a known path with an undocumented
  method) is valid only if the response is a `404` or `405` `application/problem+json` that
  validates against `components/schemas/Problem.yaml`. The contract's info section documents
  these globally. Anything else fails the test. Paths under `/api/v2/__testing/` are exempt: tests
  may register such routes for framework behaviour that no contract operation exercises.
  Production code never registers them.

  Write its unit test `tests/Unit/Support/OpenApiContractTest.php` with literal-versus-template
  matching, an undocumented status, a missing required header, a schema violation and an unknown
  operation.

  *Implementation note*: undocumented query parameters are violations too, and
  `validateComponent($data, '<Schema>')` validates a value against a named component (used by
  T036). Only `OpenApiContract::shared()` reports coverage, so the unit test's synthetic contract
  never counts.
- [X] T017 Create `tests/TestCase.php`:
  - Override `call()` so that every request and response passes through `OpenApiContract`.
    Responses are always validated. Request validation can be skipped with
    `withoutRequestValidation()`, which throws unless the running test method name contains
    `Invalid` or `Malformed`.
  - In `setUp()`, run `FLUSHDB` on Redis, but only when `app()->environment('testing')` holds and
    the selected database index is 15.
  - Add the helpers `csrfToken(): string`, which calls `GET /api/v2/csrf-token` and keeps the
    cookie; `signInAs(string $username, Role $role, AuthMethod $method = AuthMethod::Password)`,
    which writes a principal through `SessionManager` and sets the cookie; and
    `travelPastChallengeTtl()`.

  *Implementation note*: the client keeps every cookie a response sets, like a browser
  (`forgetCookies()` drops them). `json()` sends no body for GET, HEAD, DELETE and OPTIONS without
  data (Laravel would send `[]`). The name check of `withoutRequestValidation()` is
  case-insensitive (`test_invalid_…`). Extra helpers: `signInPending()`, `sessionCookie()` and
  `mailcow()`.
- [X] T018 [P] Create `tests/Support/Contract/ContractCoverageExtension.php` (FR-051), a PHPUnit 12
  extension. It records every operation answered with a documented 2xx or 3xx status, sent to it
  by `OpenApiContract`, and on `ExecutionFinished` writes `build/contract/coverage.json`. Register
  it in `phpunit.xml`. Create `tests/bin/check-contract-coverage.php`, which exits non-zero and
  lists every contract operation missing from `coverage.json`.

  *Implementation note (orchestrator decision)*: the shared contract also holds the operations of
  planned features, so the scope is the registry `tests/feature-registry.json` (read by
  `tests/Support/FeatureRegistry.php`): per implemented feature its `operations` and
  `problemTypes`, plus `pendingOperations` that a later phase of the feature implements. The check
  fails for every registered, non-pending operation without coverage, for a registered operation
  missing from the contract, and for a pending operation that is already covered. Each phase
  removes its operations from `pendingOperations`; each new feature adds its entry.
  `tests/Unit/Architecture/RoutesMatchContractTest.php` also requires every `/api/v2` route to be
  an operation of a registered feature.
- [X] T019 [P] Create `database/factories/MailcowRows.php` (namespace `Database\Factories`, used by
  the tests and by the dev seeder) and `tests/Support/Mailcow/MailcowFactory.php`, a thin test
  wrapper around it. Together they insert upstream rows exactly as legacy writes them:
  - `admin(username, password, scheme = 'BLF-CRYPT', active = 1, attributes = [])`;
  - `domainAdmin(username, domains[], daAcl = [], active = 1)`, which writes `admin` with
    `superadmin = 0`, `domain_admins` and `da_acl`;
  - `domain(name, active = 1)`;
  - `mailbox(username, password, scheme, active, kind = '', authsource = 'mailcow', attributes,
    userAcl = [])`, which writes `mailbox` and `user_acl`;
  - `tfaTotp(username, secret, keyId, active = 1)`;
  - `tfaWebAuthn(username, VirtualCredential, keyId, active)`, with `keyHandle = base64(credentialId)`
    and `publicKey = PEM`;
  - `tfaYubi(username, clientId, key, modhex, keyId, active)`;
  - `tfaU2f(username)` and `tfaHotp(username)`;
  - `fido2(username, VirtualCredential, friendlyName = null, active = 1, certificateSubject = null)`,
    with raw `credentialId`, PEM key, `rpId` and raw AAGUID;
  - `identityProvider(authsource)`.

  Hashes are produced per scheme (`{BLF-CRYPT}` via `password_hash`, `{SSHA256}` and others as
  Dovecot formats them).

  *Implementation note*: `database/factories` cannot depend on test classes, so `tfaWebAuthn()`
  and `fido2()` take the raw credential ID, PEM key, `rpId` and AAGUID; the test wrapper adds
  `tfaWebAuthnCredential()`, `fido2Credential()`, `ensureDomain()` and `mailboxWithDomain()`.
- [X] T020 [P] Create `tests/Support/WebAuthn/CborEncoder.php` (unsigned and negative integers,
  byte and text strings, arrays, maps) and `tests/Support/WebAuthn/VirtualAuthenticator.php`.
  `VirtualAuthenticator` provides:
  - `createCredential(rpId, userHandle)`, which makes an EC P-256 key with OpenSSL and returns a
    `VirtualCredential` with its ID, PEM and COSE key;
  - `attest(options, origin, uv = true)`, which returns `RegistrationResponseJSON` with
    attestation `none`;
  - `assert(credential, options, origin, uv = true, up = true, userHandle)`, which returns
    `AuthenticationResponseJSON` with an ES256 signature.

  Add `tests/Unit/Support/VirtualAuthenticatorTest.php`, proving that
  `lbuchs\WebAuthn\WebAuthn::processCreate()` and `processGet()` accept its output.
- [X] T021 [P] Create `tests/Support/Redis/ChannelSpy.php`. It starts
  `redis-cli -h $REDISHOST -a $REDISPASS -n 0 SUBSCRIBE F2B_CHANNEL` through
  `Symfony\Component\Process\Process`, waits for the `subscribe` confirmation, and offers
  `messages(): array` and `assertPublished(string $exact)` and `assertNothingPublished()`, which
  wait up to 1 s.
- [X] T022 [P] Write `tests/Unit/Architecture/MigrationPrefixTest.php`. It parses every file in
  `database/migrations/` and fails if `Schema::create`, `table`, `drop`, `dropIfExists` or `rename`
  names a table not starting with `restv2_` (constitution III, R5). Write
  `tests/Unit/Architecture/DestructiveCommandsProhibitedTest.php`, which asserts that `db:wipe`,
  `migrate:fresh`, `migrate:refresh` and `migrate:reset` are prohibited in the `testing`, `local`
  and `production` environments.
- [X] T023 [P] Write `tests/Feature/Schema/SchemaFixtureTest.php`, asserting that
  `versions.db_schema` is in `config('restv2.supported_schema_versions')`, that there are 54 base
  tables and 6 views from the fixture, and that the `restv2_` tables exist. Write
  `tests/Feature/Schema/AclColumnsTest.php`, asserting that the columns of `user_acl` and `da_acl`
  minus `username` equal the source columns of `App\Auth\PermissionFlag` (R20).
- [X] T024 [P] Write `tests/Feature/System/ProblemRenderingTest.php` (FR-049, FR-055):
  - unknown path → `404`, `application/problem+json`, `type about:blank`, 32-hex `requestId`;
  - `PUT /api/v2/csrf-token` → `405` problem with an `Allow` header;
  - a forced exception on a test-only route under `/api/v2/__testing/` → `500` problem without a
    stack trace;
  - `Accept-Language: cs` changes nothing (FR-055).
- [X] T025 [P] Write `tests/Unit/Http/ValidationRuleCodeTest.php` against a test-only Form Request.
  Each Laravel or app rule must map to the contract code and params of
  `api/components/schemas/ValidationRuleCode.yaml`:
  - `required` → `required`;
  - wrong type → `invalidType` `{expected}`;
  - string `min` and `max` → `tooShort`/`tooLong` `{min|max}`;
  - array `max` → `tooMany` `{max}`;
  - numeric `between` → `outOfRange` `{min,max}`;
  - `in` → `notInList` `{allowed}`;
  - date format → `invalidFormat` `{format: dateTime}`;
  - `App\Rules\IpNetwork` → `invalidFormat` `{format: ipNetwork}`;
  - `App\Rules\FutureDateTime` → `mustBeInFuture`;
  - `App\Rules\EligibleKeyOwner` → `notFound` / `notEligible` `{allowedRoles}`.

  It also asserts the error keys: dot paths, query names, and `body` (FR-055). It also tests
  `App\Support\Http\ListQuery`: `limit` defaults to 25, accepts 1–1000 (`outOfRange` beyond), and
  `offset` ≥ 0; `sort` and `order` are checked against each list's allowed values (`notInList`);
  `q` is at most 255 characters, trimmed, empty means no search (shared `Search.yaml`), with `%`,
  `_` and `\` escaped in the generated `LIKE` pattern; `meta`
  is `{total, limit, offset}` (FR-058, R29).
- [X] T026 [P] Write `tests/Feature/Auth/CsrfTokenTest.php` for `getCsrfToken` (FR-011, R6, R7).
  It must return `200` with `data.token` (at least 40 characters) and `headerName "X-CSRF-Token"`,
  and the same token on a second call with the cookie. `Set-Cookie` must be
  `__Host-restv2_session` (`.env.testing` keeps the default name) with
  `Secure`, `HttpOnly`, `SameSite=Strict`, `Path=/` and no `Domain`. No `XSRF-TOKEN` cookie may
  be set.
- [X] T027 [P] Write `tests/Unit/Http/Middleware/VerifyCsrfTokenTest.php`:
  - `POST`, `PUT`, `PATCH` and `DELETE` without a token, or with a wrong one, → `403
    csrf-token-mismatch`, also with `Content-Type: text/plain` and `multipart/form-data`;
  - `GET`, `HEAD` and `OPTIONS` pass;
  - `Sec-Fetch-Site: cross-site` with a valid token → `403`;
  - requests marked as authenticated by an API key pass without a token;
  - the token is read only from `X-CSRF-Token`, never from a `_token` body field or an
    `X-XSRF-TOKEN` header.
- [X] T028 [P] Write `tests/Feature/Auth/GetSessionTest.php` for `getSession`:
  - no cookie → `anonymous`, with `idleTimeout` equal to `SESSION_LIFETIME` from the vars fixture;
  - `signInAs(admin)` → `active`, with `username`, `role`, `authMethod` and `signedInAt`;
  - a mailbox with `force_pw_update` → `restricted` and `[passwordChange]`;
  - a session idle longer than the lifetime (`travel`) → `anonymous`, with the old session key
    gone from Redis (FR-009);
  - a different `User-Agent` → `anonymous` and the session destroyed (FR-012);
  - the account deactivated in `admin` → `anonymous` (FR-029);
  - `signedInAt` is UTC.
- [X] T029 [P] Write `tests/Unit/Services/MailcowConfigTest.php` with the fixtures
  `tests/fixtures/mailcow-inc/vars.inc.php` (a copy of upstream `2026-09a`'s) and
  `tests/fixtures/mailcow-inc/vars.local.inc.php`. The local file sets
  `$SESSION_LIFETIME = 7200`, `$DEFAULT_LANG = 'cs-cz'` and
  `$AVAILABLE_LANGUAGES = ['cs-cz' => 'Čeština (Czech)', 'en-gb' => 'English', 'sk-sk' => 'Slovenčina (Slovak)']`.
  - The values must come from the local override.
  - With the directory missing, the upstream defaults of R14 apply and one warning is logged.
  - A syntax error in the local file falls back to the defaults.
  - `MAILCOW_APPS` is exported: the upstream default Webmail entry, or a local override with a
    second entry.
  - A `RESTV2_SESSION_LIFETIME=600` server setting beats the files, and `RESTV2_MAILCOW_APPS`
    (JSON) replaces the apps (precedence of R14).
  - The result is cached in Redis under `restv2:mailcow-vars:<fingerprint>`. Touching
    `vars.local.inc.php` (`touch()` with a new modification time) makes the next call re-export,
    so there is no time-based staleness.
  - The subprocess does not change the parent's `error_reporting`.
- [X] T030 [P] Write `tests/Unit/Services/PendingActionEvaluatorTest.php` (FR-014, FR-054, R16):
  - `passwordChange` iff `attributes.force_pw_update` equals `"1"` or `1`, for admin, domain admin
    and mailbox accounts;
  - `secondFactorSetup` iff `force_tfa` is 1 **and** no `tfa` row exists (an inactive one counts
    as existing) **and** no `fido2` row with `active = 1` exists;
  - both can apply together.
- [X] T031 [P] Write `tests/Feature/Audit/AuditLoggerTest.php` (FR-046, FR-048, R22):
  - `record()` writes every column of `restv2_audit_events` (ULID, `occurred_at` UTC with
    microseconds, request ID from the request, client address, user agent truncated to 512);
  - inside `DB::transaction` with a later exception, both the audited change and the record roll
    back;
  - a failed audit insert makes the surrounding transaction fail;
  - `AuditEvent::update()` and `delete()` throw;
  - `details` keys outside the per-event whitelist are dropped.
- [X] T032 [P] Write `tests/Feature/Fail2Ban/RedisFail2BanReporterTest.php` with `ChannelSpy`
  (FR-006, FR-040, R18):
  - exact `mailcow UI: Invalid password for alice@example.org by 192.0.2.10`;
  - `API_USER` variant;
  - IPv6 `2001:0db8::0001` published as `2001:db8::1`;
  - a username containing `\n` and `\r` published with `?`;
  - a 300-byte username cut to 255 bytes;
  - with Redis unreachable (a bad port in config) no exception, an `error` log entry, and the caller
    continues.
- [X] T033 [P] Write `tests/Unit/Logging/StructuredLoggingTest.php` (R24):
  - a log call produces one JSON line with `request_id`, `route`, `method`, `principal` and
    `client_ip`;
  - context keys `password`, `code`, `otp`, `secret`, `credential` and `X-API-Key` are removed;
  - a valid incoming `traceparent` gives its trace ID as `request_id`, an invalid one gives a new
    32-hex ID.

  *Implementation note*: the request context is in the record's `extra` member.
- [X] T034 [P] Write `tests/Unit/Http/Middleware/RequireAuthTest.php` and
  `tests/Unit/Http/Middleware/EnforcePendingActionsTest.php`:
  - modes `session` and `authenticated` without a principal → `401 unauthenticated`;
  - modes `session` and `authenticated` during a pending sign-in → `401 second-factor-required`;
  - modes `session` and `browser` with a key principal → `403 session-required`;
  - mode `browser` without any session, and mode `public` with a key, pass;
  - a key principal is never refused by `EnforcePendingActions`;
  - a restricted session on an unmarked route → `403 pending-action-required` with
    `pendingActions`;
  - `restv2.pending:allow` passes;
  - `restv2.pending:secondFactorSetup` passes only when the sole pending action is
    `secondFactorSetup`.

- [X] T035 [P] Write `tests/Unit/Services/SignInThrottleTest.php` and
  `tests/Feature/Auth/RecentLoginRecorderTest.php` (shared by US1 and US2, R17, FR-027):
  - `hit()` returns the previous count, and `delayFor(n)` is `0.5 × n` s.
  - `assertNotThrottled()` throws `too-many-requests` with `retryAfter` ≥ 1 after
    `RESTV2_SIGNIN_MAX_FAILURES` hits within the window.
  - `clear()` resets the count. The keys are `restv2:signin:<hash>` and `restv2:passkey:<hash>`.
  - `RecentLoginRecorder::record()` writes exactly one `sasl_log` row per `(username, real_rip,
    service)` with `app_password = 0`, replacing an older one and refreshing `datetime`.
- [X] T036 [P] Write `tests/Unit/Support/WebAuthnOptionsJsonTest.php` (R9). Against the installed
  lbuchs version, `App\Support\WebAuthn\OptionsJson`:
  - always emits `allowCredentials` (empty for the passkey challenge);
  - emits `residentKey: "required"` and `requireResidentKey: true` for registration;
  - converts every binary member to unpadded base64url;
  - drops `extensions.exts`, keeps `extensions.appid` for the second-factor challenge;
  - sets `timeout` to 300000.

  The output validates against `WebAuthnRequestOptions` and `WebAuthnCreationOptions` of the
  contract.

### Implementation of the foundation

- [X] T037 Create `app/Support/Http/ProblemType.php`, `Problem.php`, `ETag.php` and `Base64Url.php`,
  `app/Exceptions/ProblemException.php`, and the exception rendering in `bootstrap/app.php`.
  - `ProblemType` is an enum with one case per section of `docs/problems.md`, holding its URI
    `/api/v2/problems/<name>`, status and English title.
  - `Problem` builds `application/problem+json` with `type`, `title`, `status`, `detail`,
    `requestId` and extensions.
  - `ETag` gives a strong, quoted base64url SHA-256 and handles `If-Match` (including `*`) and
    `If-None-Match`.
  - `Base64Url` encodes and decodes without padding.

  *Implementation note (conventions)*: `docs/conventions.md` now requires version tags to be an
  HMAC keyed with the app key, so `ETag::of()` is HMAC-SHA-256 (keyed with `APP_KEY`), base64url,
  quoted. The rendering lives in `app/Exceptions/ProblemRenderer.php`, and request attributes
  (request ID, principal, API-key mark, live session) in `app/Http/RequestContext.php`.
  - The rendering maps `ValidationException` → `validation-failed` with codes from T038,
    `NotFoundHttpException` and `ModelNotFoundException` → `404 about:blank`,
    `MethodNotAllowedHttpException` → `405` with `Allow`, a JSON parse error → `400`, and any other
    `Throwable` → `500 about:blank` (logged with its trace, never rendered). This makes T024 pass.
- [X] T038 Create `app/Http/Requests/Api/V2/ApiFormRequest.php`, which turns validator failures into
  `errors[field] = [{code, params, message}]` per T025, and the rule objects
  `app/Rules/IpNetwork.php`, `app/Rules/FutureDateTime.php`, `app/Rules/EnabledLanguage.php` and
  `app/Rules/EligibleKeyOwner.php`. Each rule reports its rule code and params. Create
  `app/Support/Http/ListQuery.php`, which validates `limit` (default 25, 1–1000), `offset`, `sort`,
  `order` and `q`, applies a case-insensitive escaped `LIKE` over the fields a list names, and
  builds the `{data, meta}` envelope. This makes T025 pass.

  *Implementation note*: Laravel's `date_format` cannot accept both `Z` and offsets, so
  `app/Rules/Rfc3339DateTime.php` validates RFC 3339 date-times (`invalidFormat`,
  `format: dateTime`) and `FutureDateTime` reuses it. Rules extend `app/Rules/CodedRule.php`.
  `ApiFormRequest` sets `$requiresNonEmptyBody` for merge patches (error key `body`).
- [X] T039 Create `app/Http/Middleware/AssignRequestId.php` (parses `traceparent` or generates 16
  random bytes as hex, stored in request attributes and the `Log::withContext()`), and
  `app/Logging/RequestContextProcessor.php` and `app/Logging/RedactSecrets.php`, registered on the
  `stderr_json` channel. This makes T033 pass.

  *Implementation note*: the processor reads the request ID from the request, so
  `Log::withContext()` is not used (it would duplicate the value in `context`).
- [X] T040 Create `resources/bin/export-mailcow-vars.php` and
  `app/Services/Config/MailcowConfig.php` (R14). It has typed getters `sessionLifetime()`,
  `defaultLanguage()`, `availableLanguages()`, `fido2UvRegister()`, `fido2UvLogin()`,
  `fido2UserPresent()`, `fido2Formats()`, `webauthnUvLogin()`, `webauthnUserPresent()`,
  `otpLabel()` and `mailcowApps()`. It applies the precedence of R14 (server setting `RESTV2_<NAME>`,
  then the mounted files, then the upstream defaults) and caches by file fingerprint. In
  `app/Providers/AppServiceProvider.php`:
  - bind `MailcowConfig` as a singleton;
  - set `config(['session.lifetime' => (int) ceil(sessionLifetime() / 60)])` at boot;
  - call `DB::prohibitDestructiveCommands()` unconditionally (T022).

  This makes T029 pass.
- [X] T041 [P] Create the upstream models in `app/Models/Mailcow/`: `Admin.php`,
  `DomainAdminAssignment.php`, `Domain.php`, `Mailbox.php`, `DaAcl.php`, `UserAcl.php`,
  `Fido2Credential.php`, `TfaEntry.php`, `SaslLogEntry.php`, `IdentityProviderSetting.php` and
  `SchemaVersion.php`, each with the explicit `$table`, `$primaryKey`, key type,
  `$timestamps = false` and casts of data-model.md. Create `app/Casts/MailcowDateTime.php`, which
  parses in `config('restv2.timezone')` and returns UTC `CarbonImmutable`.
- [X] T042 [P] Create `app/Auth/Role.php` (`admin`, `domainAdmin`, `mailboxUser`), `AuthMethod.php`,
  `AccountKind.php` (`admin`, `mailbox`), `PendingAction.php`, `PermissionFlag.php` (29 cases, each
  with its `user_acl` and/or `da_acl` source column) and `Principal.php` (readonly value object of
  data-model.md).
- [X] T043 Create `app/Services/Auth/PendingActionEvaluator.php` (R16), which makes T030 pass.
- [X] T044 Create `app/Services/Auth/AccountResolver.php`, with
  `accountStillActive(Principal): bool` (admin: `admin.active = 1` with the same `superadmin`;
  mailbox: `mailbox.active = 1`, `domain.active = 1` and not a resource kind) and
  `findAdminAccount(username)` / `findMailboxAccount(username)` (read-only queries of
  data-model.md). The password resolution comes in T063.
- [X] T045 Create `database/migrations/2026_10_18_000002_create_restv2_audit_events_table.php` with
  the columns and indexes of data-model.md, `app/Models/AuditEvent.php` (`HasUlids`, no timestamps,
  throws in the `updating` and `deleting` events), and `app/Services/Audit/AuditLogger.php`.
  `AuditLogger` provides `record(AuditEventType $type, bool $success, ?Principal|array $actor,
  ?array $target, ?string $authMethod, ?string $failureReason, array $details)` with the per-event
  `details` whitelist of data-model.md. This makes T031 pass.

  *Implementation note*: the event enum is `app/Audit/AuditEventType.php` (detail keys per case),
  ULIDs are upper-case (contract pattern), `details` uses `app/Casts/JsonObject.php` so that an
  empty value is `{}`, and an index on (`target_type`, `target_id`) serves the conventions' history
  filters.
- [X] T046 [P] Create `app/Clients/Fail2Ban/Fail2BanReporter.php` (interface:
  `invalidPassword(string $username, string $ip)`, `invalidApiKey(string $ip)`) and
  `app/Clients/Fail2Ban/RedisFail2BanReporter.php` (R18), and bind the implementation in
  `AppServiceProvider`. This makes T032 pass.
- [X] T047 [P] Create `app/Support/Clock/Sleeper.php` (interface `sleepSeconds(float)`) and
  `UsleepSleeper.php`, bound in `AppServiceProvider`, and `app/Services/Auth/SignInThrottle.php`
  (R17). It offers `assertNotThrottled(key)`, which throws a `ProblemException(TooManyRequests)`
  with `retryAfter`; `hit(key)`, which returns the previous failure count; `delayFor(count)`;
  `clear(key)`; `passwordKey(username, ip)`; and `passkeyKey(ip)`, using
  `RateLimiter` on store `restv2`. `retryAfter` is clamped to at least 1. This makes T035 pass.

  *Implementation note*: `SignInThrottle::delay($previousFailures)` waits through the `Sleeper`.
- [X] T048 [P] Create `app/Services/Auth/RecentLoginRecorder.php` (`record(string $mailbox,
  string $ip)`). It runs the `REPLACE INTO sasl_log` of data-model.md with
  `config('restv2.sasl_log_service')`. This makes T035 pass.
- [X] T049 Create `app/Services/Auth/SessionManager.php`. It reads and writes the session keys of
  data-model.md (`restv2.principal`, `restv2.pending`, `restv2.passkeyChallenge`,
  `restv2.passkeyRegistration`, `restv2.lastActivity`), offers `state()`, `principal()`,
  `beginPending()` and `promote()` (both call `migrate(true)`, FR-008), `end()` (calls
  `invalidate()`) and the
  challenge helpers (`issue`, `consume`; single-use, `RESTV2_CHALLENGE_TTL`), and stores the
  user-agent SHA-256.
- [X] T050 Create the middleware `app/Http/Middleware/StartSessionUnlessApiKey.php` (extends
  Laravel's `StartSession`; the API-key skip is added in T088), `EnsureSessionIntegrity.php`,
  `VerifyCsrfToken.php` (R7), `RequireAuth.php` and `EnforcePendingActions.php` (R8). In
  `bootstrap/app.php`:
  - define the group `api.v2` in the order `AssignRequestId`, `StartSessionUnlessApiKey`,
    `EnsureSessionIntegrity`; T088 inserts `AuthenticateApiKey` and `EnforceKeyScope` after
    `AssignRequestId`;
  - add the route middleware aliases `restv2.auth:<mode>` (RequireAuth, modes `public`,
    `browser`, `session` and `authenticated` per R8), `restv2.csrf`
    (VerifyCsrfToken) and `restv2.pending:<mode>` (EnforcePendingActions, mode `enforce` by default,
    `allow` or `secondFactorSetup`), applied per route in that order;
  - configure `TrustProxies` with **no** trusted proxies (R23).

  This makes T027 and T034 pass.

  *Implementation note*: Laravel's global `HandleCors` is removed (the API must send no CORS
  headers, R7), and so are `TrimStrings` and `ConvertEmptyStringsToNull` (passwords must reach the
  services unchanged). `StartSessionUnlessApiKey` starts each request from a clean store and records
  whether the request had a live session; `restv2.csrf:live` exempts requests without one, for
  `DELETE /session` (R7).
- [X] T051 [P] Create `app/Support/WebAuthn/WebAuthnServerFactory.php`. Its
  `make(Request $r): lbuchs\WebAuthn\WebAuthn` uses `rpId` = lower-cased host without port,
  `rpName` = product name from `app/Services/Branding/ProductName.php` (created here: reads
  `MAIN_NAME` from the `mailcow` Redis connection, decoded, else the default of R14), formats from
  `MailcowConfig`, and base64url mode on. When `WEBAUTHN_ONLY_TRUSTED_VENDORS=y` it calls
  `addRootCertificates(resource_path('webauthn/rootCertificates'))`. Create
  `app/Support/WebAuthn/OptionsJson.php` (R9), which makes T036 pass. Copy
  `source_code/data/web/inc/lib/WebAuthn/rootCertificates/*.pem` into
  `resources/webauthn/rootCertificates/`.
- [X] T052 Create `routes/api.php` with the prefix `api/v2` and the group `api.v2`, and register it
  in `bootstrap/app.php` (`withRouting(api: …, apiPrefix: '')`). Add
  `GET /csrf-token` → `App\Http\Controllers\Api\V2\CsrfTokenController@show` and
  `GET /session` → `SessionController@show`, both with `restv2.auth:browser` and
  `restv2.pending:allow`.
  Create `app/Http/Resources/Api/V2/SessionResource.php` (contract `Session`). This makes T026 and
  T028 pass.

  *Implementation note*: `getCsrfToken` answers with `Cache-Control: no-store`.

**Checkpoint**: The foundation tests are green. `GET /csrf-token` and `GET /session` work under
contract validation. User stories can start, in parallel if staffed.

---

## Phase 3: User Story 1 — Sign in with password and second factor, then sign out (P1) 🎯 MVP

**Goal**: Password sign-in for all roles with legacy resolution, hashes, throttling and fail2ban.
The second-factor step supports TOTP, WebAuthn and Yubico OTP. Pending sign-ins can be cancelled,
and sessions can be signed out.

**Independent test**: Seed accounts of all three roles with each hash scheme and each second
factor. Sign each in through the API, use `GET /session`, then sign out. Confirm the exact
fail2ban messages, `sasl_log` and `tfa` side effects, and the audit records.

### Tests for User Story 1 (write first, observe failing) ⚠️

- [X] T053 [P] [US1] Write `tests/Unit/Hashing/LegacyPasswordVerifierTest.php` (FR-004, SC-001, R12). For
  every scheme in `ARGON2I`, `ARGON2ID`, `BLF-CRYPT`, `CRYPT`, `DES-CRYPT`, `MD5-CRYPT`, `MD5`,
  `SHA256-CRYPT`, `SHA512-CRYPT`, `CLEAR`, `CLEARTEXT`, `PLAIN`, `LDAP-MD5`, `PBKDF2`,
  `PBKDF2-SHA512`, `PLAIN-MD4`, `PLAIN-MD5`, `PLAIN-TRUNC`, `SHA`, `SHA1`, `SHA256`, `SHA512`,
  `SMD5`, `SSHA`, `SSHA256` and `SSHA512`:
  - a correct password passes and a wrong one fails;
  - the vectors were generated with `doveadm pw -s <scheme>` in `ghcr.io/mailcow/dovecot` of
    `2026-09a`, with the command recorded in a comment;
  - the result equals `legacy_verify_hash()` from `tests/fixtures/legacy/verify_hash.php`;
  - a lower-case scheme name works, and an unknown scheme or a missing `{…}` fails.

  *Implementation note*: Dovecot (`ghcr.io/mailcow/dovecot:2.3.21.1-2`) has no FreeIPA-style
  `PBKDF2-SHA512` scheme, so that vector is computed with `hash_pbkdf2()` in the FreeIPA form, and
  the `{PLAIN-TRUNC}<length>-<password>` form is written by hand. DES-CRYPT uses only the first 8
  characters, so the wrong passwords differ at the start. Where legacy throws (a non-numeric
  PLAIN-TRUNC length, a truncated PBKDF2 value), the port returns false.
- [X] T054 [P] [US1] Write `tests/Feature/Auth/PasswordSignInTest.php` for `createSession`:
  - AS-1: admin without second factor → `201`, `Location: /api/v2/session`, `state active`,
    `role admin`, `authMethod password`.
  - AS-6: a username that is an admin and a mailbox → admin without `role`, mailbox with
    `role: mailboxUser`. Also `role: domainAdmin` on an admin → `sign-in-failed`.
  - Resolution order admin → domain admin → mailbox (FR-002).
  - `" Alice@Example.ORG "` is normalised (FR-001).
  - A malformed username (`"a b!"`) → `sign-in-failed` (FR-005).
  - Generic failure for: an inactive admin, an inactive domain admin, an inactive mailbox, a mailbox
    in an inactive domain, kind `location`, `thing` and `group`, and an app password used as the
    password (FR-003).
  - `authsource` `ldap`, `keycloak` and `generic-oidc` → `sign-in-failed` (FR-003).
  - An unknown account still runs one dummy `BLF-CRYPT` verification (assert through a spy on
    `LegacyPasswordVerifier`) (FR-005).
  - The session cookie value changes on success and the old session ID no longer exists in Redis
    (FR-008).
  - A second `POST /session` replaces the first principal.
  - A missing CSRF token → `403 csrf-token-mismatch` (FR-011).
  - Missing fields → `422` `required`, not counted towards throttling.
  - A malformed JSON body (with a valid CSRF token) → `400` `about:blank` problem.
- [X] T055 [P] [US1] Write `tests/Feature/Auth/PasswordSignInSideEffectsTest.php`. Use
  `ChannelSpy`, and assert database rows exactly:
  - `UPDATE tfa SET active = '1'` re-activates all of the user's rows, including `u2f` and `hotp`
    rows, after a sign-in of an account with no active `tfa` row of any type (FR-026). An account
    whose only active rows are `u2f`/`hotp` is not re-activated (it gets
    `second-factor-unavailable`).
  - One `sasl_log` row `(username, real_rip = client IP, service = 'MAILCOWUI', app_password = 0)`
    for a mailbox user, also when a second factor is pending. With `RESTV2_SASL_LOG_SERVICE=WEBUI`
    it is `WEBUI`. No row for admins or domain admins (FR-027).
  - `mailbox.password` and `admin.password` are unchanged (FR-004).
  - A wrong password publishes exactly `mailcow UI: Invalid password for <normalised username> by
    <ip>` (FR-006); the external-authsource case publishes nothing (FR-003).
  - Audit `signIn` success and failure with `failureReason` `invalidCredentials`,
    `accountInactive` or `externalIdentitySource` and `details.requestedRole` (FR-045, FR-046).
  - `force_pw_update` → `state restricted` and `[passwordChange]`.
  - `force_tfa` without `tfa` rows and without an active passkey → `[secondFactorSetup]`; with an
    inactive `tfa` row → not restricted; with an active `fido2` row → not restricted (FR-014,
    FR-054).
- [X] T056 [P] [US1] Write `tests/Feature/Auth/SignInThrottleTest.php` (FR-007, AS-7, R17). Bind a
  fake `App\Support\Clock\Sleeper` that records durations.
  - Failures 1..10 for `(user, ip)` sleep 0, 0.5, 1.0, …, 4.5 s.
  - The 11th attempt (even with the correct password) → `429 too-many-requests`, with
    `Retry-After` equal to `retryAfter`, and no password verification.
  - Throttled attempts are still published to fail2ban and audited with reason `throttled`.
  - Another IP or another username is unaffected.
  - A spoofed `X-Forwarded-For: 203.0.113.9` header is ignored. Throttling, fail2ban and audit use
    `REMOTE_ADDR` (R23, edge case "request through the mailcow reverse proxy").
  - A new cookie (a new session) does not reset the count.
  - A verified password resets it.
  - `RESTV2_SIGNIN_MAX_FAILURES=3` and `RESTV2_SIGNIN_FAILURE_WINDOW=60` are honoured, and the
    window expires (`travel`).

  *Implementation note*: Laravel's rate limiter keeps its keys with a real Redis TTL, which `travel()`
  cannot move. `SignInThrottle` therefore also ends the window when the end time that the limiter
  records with the first failure has passed on the application clock (same result in
  production). Tests bind `Tests\Support\Clock\FakeSleeper` for every test (`sleeper()`).
- [X] T057 [P] [US1] Write `tests/Feature/Auth/SecondFactorTotpTest.php` for `verifySecondFactor`
  (FR-019 order within the second-factor step, SC-001):
  - AS-2: a mailbox with WebAuthn, TOTP and Yubico rows → `state pendingSecondFactor`, methods
    ordered `webauthn`, `totp`, `yubiOtp`, with tiers `primary`, `primary`, `advanced`, `id` from
    `tfa.id`, `name` from `key_id`, no secrets, and `expiresAt` = now + 300 s (FR-020).
  - The password step that opens the pending sign-in renews the session ID (FR-008).
  - A valid TOTP → `200`, `state active`, the session ID renewed, audit with
    `authMethod passwordAndSecondFactor` and `details.secondFactorType totp` (FR-021).
  - A wrong code → `401 second-factor-failed`. The pending sign-in is gone (`GET /session` →
    `anonymous`) and one throttle failure is counted (FR-024).
  - The same valid code in a fresh sign-in within 90 s → `second-factor-failed` (FR-023).
  - A `methodId` of another account or of a `yubi_otp` row → failed.
  - A method deactivated after the password step → failed, not counted.
  - `u2f` and `hotp` rows are never listed (FR-025).
  - An account whose active rows are only `u2f`/`hotp` → `403 second-factor-unavailable`, audited
    `secondFactorUnavailable`.
  - A code of `"12345"` → `422 invalidFormat` (`format: totpCode`), and the pending sign-in is
    kept.

  *Orchestrator decision (Phase 6, FR-024)*: second-factor failures count on their own key,
  `restv2:secondfactor:<sha256(username|ip)>`, which a correct password does not reset and only a
  completed sign-in (password only, password plus second factor, or passkey) clears. Reaching the
  limit refuses the pair's password steps (before the password is checked, published and audited
  `throttled` like FR-007) and second-factor steps with `429`.
  `tests/Feature/Auth/SecondFactorThrottleTest.php` covers it; the TOTP, WebAuthn and Yubico tests
  read the new key.
- [X] T058 [P] [US1] Write `tests/Feature/Auth/SecondFactorWebAuthnTest.php` for
  `createSecondFactorWebAuthnChallenge` and `verifySecondFactor` with `type: webauthn` (AS-3,
  FR-021, FR-022):
  - The challenge has `allowCredentials` = the base64url IDs of the active `tfa` webauthn rows
    only, `extensions.appid = "https://mail.example.org"`, `userVerification` from
    `$WEBAUTHN_UV_FLAG_LOGIN` (`false` → `preferred`), `rpId` = the request host, and `expiresAt`.
  - An assertion of a legacy-stored row (`keyHandle` base64, `publicKey` PEM, created with
    `MailcowFactory::tfaWebAuthn`) → `active`.
  - An assertion by a credential of another account → `second-factor-failed`.
  - The challenge reused after a failure → `sign-in-expired`; after 301 s → `sign-in-expired`.
  - Without a pending sign-in → `401 sign-in-expired`.
  - A pending sign-in without webauthn methods → `409` (`about:blank`).
- [X] T059 [P] [US1] Write `tests/Feature/Auth/SecondFactorYubicoTest.php`, with
  `YubicoOtpVerifier` bound to a fake:
  - A valid OTP selects the row by modhex prefix → `active`.
  - An unknown modhex → `second-factor-failed`.
  - The fake returning `temporaryFailure` → `503 temporary-verification-failure` with
    `Retry-After`. The pending sign-in is kept, nothing is counted, and an audit failure
    `verificationUnavailable` is written.

  Write `tests/Unit/Clients/HttpYubicoOtpVerifierTest.php` with `Http::fake()` (R11):
  - The request has `id`, `otp`, a 32-hex `nonce` and `h` = base64 HMAC-SHA1 of the sorted query
    with the decoded key.
  - The response `h` is verified.
  - `status=OK` with an echoed `otp` and `nonce` → valid.
  - `REPLAYED_OTP`, `BAD_OTP` or a bad response signature → invalid.
  - HTTP 500, `BACKEND_ERROR`, a connection exception or a 5 s timeout → temporary failure.
- [X] T060 [P] [US1] Write `tests/Feature/Auth/PendingSignInLifecycleTest.php`:
  - `GET /session` during the pending sign-in shows `pendingSignIn.methods` and `expiresAt`.
  - After 301 s, `verifySecondFactor` → `401 sign-in-expired` (FR-024).
  - `cancelPendingSignIn` → `204` and `anonymous`, keeping the same CSRF token.
  - `cancelPendingSignIn` without a pending sign-in → `204`.
  - A new password sign-in replaces the pending one.
- [X] T061 [P] [US1] Write `tests/Feature/Auth/SignOutTest.php` for `deleteSession` (AS-5, FR-010):
  - Signed in → `204`, an audit `signOut`, then `GET /session` → `anonymous` and the old session
    key deleted.
  - No cookie → `204` with no audit.
  - A live session without a token → `403 csrf-token-mismatch`.
  - A new `GET /csrf-token` after sign-out returns a different token.
  - Signing out during a pending sign-in ends it, with no audit.

### Implementation for User Story 1

- [X] T062 [P] [US1] Create `app/Support/Hashing/LegacyPasswordVerifier.php`, the port of
  `verify_hash()` and `verify_salted_hash()` (R12), which makes T053 pass.
- [X] T063 [US1] Extend `app/Services/Auth/AccountResolver.php` with
  `resolveForPassword(string $normalisedUsername, ?Role $role): iterable<Candidate>`. It yields the
  admin, domain-admin and mailbox candidates in legacy order and filters by `role`, applies
  legacy's malformed-username check, and tags each candidate with its inactive, resource or
  external-authsource status (R16).

  *Implementation note*: candidates are `App\Services\Auth\AccountCandidate` with a
  `CandidateStatus` (`Usable`, `Inactive` for inactive accounts, inactive domains and resources,
  `ExternalIdentitySource`). `accountInactive` is audited only when the inactive account's password
  verified; otherwise the reason is `invalidCredentials`.
- [X] T064 [US1] Create `app/Services/Auth/PasswordSignInService.php`, which implements the
  `createSession` flow. The success path (re-activation, `sasl_log`, audit) runs in one
  `DB::transaction`. A failure's audit record is written in its own transaction after any partial
  work has been rolled back, so that it is never lost with the failure (R22). The steps:
  1. throttle check;
  2. candidate loop with `LegacyPasswordVerifier`, or the dummy verification for an unknown
     account;
  3. on failure: fail2ban (except external authsource), throttle hit, delay, audit;
  4. on success: throttle clear, `sasl_log` (mailbox), second-factor lookup, which leads to one of
     `second-factor-unavailable` (only `u2f`/`hotp` active), `beginPending` (usable methods), or,
     when no `tfa` row is active, `UPDATE tfa SET active = '1'` + `promote()` + audit;
  5. restricted state via `PendingActionEvaluator`.

  *Implementation note*: `App\Services\Auth\SignInAudit` writes the sign-in records (success inside
  the caller's transaction, failure in its own) for password, second-factor and passkey sign-in.
  The session is promoted only after the audited transaction commits. The pending sign-in keeps
  `requestedRole` for the audit record (`SessionManager::beginPending()` takes it as an optional
  argument). A `second-factor-unavailable` sign-in keeps its `sasl_log` row, as legacy writes it
  once the password verified.

  Use `RESTV2_*` settings from `config/restv2.php`. FR-001–FR-008, FR-014, FR-026, FR-027.
- [X] T065 [P] [US1] Create `app/Clients/Yubico/YubicoOtpVerifier.php` (interface),
  `YubicoVerificationResult.php` (`valid`, `invalid`, `temporaryFailure`) and
  `HttpYubicoOtpVerifier.php` (R11, endpoint `config('restv2.yubico_verify_url')`, 5 s timeout),
  bound in `AppServiceProvider`. Create `app/Support/Totp/NoQrCodeProvider.php`. This makes the
  unit part of T059 pass.
- [X] T066 [US1] Create `app/Services/Auth/SecondFactorService.php`. It provides:
  - `usableMethods(username)`: active `tfa` rows without `u2f` and `hotp`, ordered `webauthn`,
    `totp`, `yubi_otp`, then by `id`;
  - `hasOnlyUnusableActiveMethods(username)`;
  - `issueWebAuthnChallenge(pending, Request)`: lbuchs `getGetArgs` with the active key handles,
    plus `appid`;
  - `verify(pending, SecondFactorVerification, Request)`:
    - TOTP through `RobThree\Auth\TwoFactorAuth(new NoQrCodeProvider(), $otpLabel, 6, 30,
      Algorithm::Sha1)`, `verifyCode(…, 1, null, $timeslice)`, with the replay check-and-set as a
      Lua script on `restv2:totp:last:<id>` (TTL 120 s);
    - WebAuthn through `processGet` with the row's PEM, the `$WEBAUTHN_UV_FLAG_LOGIN` value
      unchanged and `$WEBAUTHN_USER_PRESENT_FLAG`;
    - Yubico through `YubicoOtpVerifier`, with the row selected by `secret LIKE '%<modhex>'`.

  *Implementation note*: lbuchs 2.2 has an extra `allowHybrid` transport parameter, so legacy's
  `getGetArgs($ids, …, false, false, false, false, $uv)` is
  `getGetArgs($ids, 300, false, false, false, false, false, $uv)` (no transports, as legacy). The
  replay script runs through `command('eval', …)`, and phpredis prefixes its key like any other.
  A second factor completed for an account that was deactivated after the password step answers
  `sign-in-failed` (audited `accountInactive`). `verifySecondFactor` without a pending sign-in is
  audited as `challengeExpired`.

  On success it calls `promote()` and audits. On failure it cancels the pending sign-in, counts a
  throttle failure (except for a vanished method or a temporary failure) and audits.
  FR-020–FR-025, R9, R10.
- [X] T067 [P] [US1] Create `app/Http/Requests/Api/V2/PasswordSignInRequest.php`: `username`
  "required, string, 1–255", `password` "required, string, 1–4096", `role` "optional, in admin,
  domainAdmin, mailboxUser". Create `app/Http/Requests/Api/V2/SecondFactorVerificationRequest.php`:
  `type` "required, in totp, webauthn, yubiOtp"; for `totp`, `methodId` "required, integer ≥ 1"
  and `code` "required, regex ^[0-9]{6}$"; for `yubiOtp`, `otp` "required, regex
  ^[A-Za-z0-9]{44}$"; for `webauthn`, `credential.id`, `rawId` and `response.{clientDataJSON,
  authenticatorData, signature}` "required, base64url". All extend `ApiFormRequest`.

  *Implementation note*: `App\Rules\MatchesFormat` reports `invalidFormat` with the contract's
  format name (`totpCode`, `yubicoOtp`, `base64url`), and the trait
  `App\Http\Requests\Api\V2\Concerns\WebAuthnCredentialRules` validates WebAuthn responses (also
  used by US2). Phase 2 fixes found here: an empty `params` of a field violation is rendered as a
  JSON object (the contract's type), and the contract coverage now also records operations whose
  documented 2xx response has no body (204).
- [X] T068 [US1] Add `SessionController@store` (`createSession`, `201` + `Location`) and
  `@destroy` (`deleteSession`, `204`, audit when a principal existed) in
  `app/Http/Controllers/Api/V2/SessionController.php`, and create
  `PendingSignInController.php` (`@destroy`), `SecondFactorChallengeController.php` (`@store`) and
  `SecondFactorController.php` (`@store`). Register the routes in `routes/api.php` with
  `restv2.auth:browser`, `restv2.csrf` and `restv2.pending:allow`. `DELETE /session` uses CSRF only
  when a live session exists (R7). This makes T054–T061 pass.

**Checkpoint**: US1 is fully functional. The MVP can be demonstrated with password sign-in, second
factors and sign-out.

---

## Phase 4: User Story 2 — Register and sign in with a passkey (P1)

**Goal**: Usernameless passkey sign-in with legacy FIDO2 credentials and new ones, and own-passkey
registration, listing, renaming and removal stored in `fido2`.

**Independent test**: For each role, sign in with a legacy-stored passkey and with an API-registered
passkey using the virtual authenticator. Test deactivated accounts and credentials, removed and
unknown credentials, and role mismatches. Register, rename and remove passkeys and check the
`fido2` rows byte by byte.

### Tests for User Story 2 (write first, observe failing) ⚠️

- [X] T069 [P] [US2] Write `tests/Feature/Auth/PasskeySignInTest.php` (FR-015–FR-019, SC-001) for `createPasskeyChallenge`
  and `createSessionWithPasskey`:
  - The challenge has empty `allowCredentials`, `userVerification` from `$FIDO2_UV_FLAG_LOGIN`
    (`preferred`), `rpId` = host, timeout 300000, and is replaced by a new request.
  - AS-1: a mailbox with a legacy-stored `fido2` row (raw ID, PEM, `certificateSubject` null) →
    `201`, `state active`, `authMethod passkey`, the session renewed, a `sasl_log` row, no `tfa`
    change, nothing published to fail2ban (FR-015, FR-018, FR-027).
  - An admin and a domain admin resolve to their roles.
  - AS-2: an inactive admin → `sign-in-failed`, audited `accountInactive` (FR-016).
  - An inactive mailbox, an inactive domain or a resource kind → failed.
  - `fido2.active = 0` → failed `passkeyInactive`.
  - AS-3: an unknown credential → failed `unknownPasskey`.
  - AS-5: `role: domainAdmin` with a mailbox user's passkey → failed `roleMismatch`.
  - A username in both `admin` and `mailbox` with `role: mailboxUser` → mailbox session.
  - An `authsource: ldap` mailbox signs in (FR-003).
  - An assertion without the UV flag fails with the default `'preferred'`, and passes with
    `$FIDO2_UV_FLAG_LOGIN = false` in the vars fixture (R9).
  - AS-4: `force_pw_update` → `restricted`.
  - The challenge is single-use (a second assertion → `sign-in-expired`) and expires after 300 s.
  - Failures count per IP: the 11th → `429` (FR-018).
  - A tampered signature → `passkeyVerificationFailed`.
  - A passkey registered for `other.example.org` (`fido2.rpId` and the authenticator's RP ID hash)
    fails on `mail.example.org` (edge case "registered under a different host name").

  *Implementation note*: the UV case is switched with the server setting
  `RESTV2_FIDO2_UV_FLAG_LOGIN=false` (R14 precedence) instead of editing the shared vars fixture. A
  passkey whose account no longer exists in either table is audited `invalidCredentials`. A
  missing or expired challenge (`sign-in-expired`, `challengeExpired`) is not counted.
- [X] T070 [P] [US2] Write `tests/Feature/Passkeys/PasskeyRegistrationTest.php` (FR-052, FR-054) for
  `createPasskeyRegistrationOptions` and `registerPasskey`:
  - The options have `residentKey required`, `requireResidentKey true`, `user.id` = base64url of
    the username, `user.name` = `displayName` = the username, `rp.id` = host, `rp.name` = the
    product name, `excludeCredentials` = all `fido2` IDs of the account, `userVerification` from
    `$FIDO2_UV_FLAG_REGISTER`, and `attestation` `indirect` (formats list), `direct` (only trusted
    vendors) or `none` (formats `['none']`).
  - AS-6: register → `201`, `Location: /api/v2/me/passkeys/<sha256 hex>`, `ETag`. The `fido2` row
    has `username`, `rpId`, `credentialPublicKey` starting `-----BEGIN PUBLIC KEY-----`,
    `credentialId` equal to the raw bytes, `AAGUID` 16 bytes, `signatureCounter`, `friendlyName`
    equal to the given name or `NULL`, and `active` 1. The passkey then signs in through
    `createSessionWithPasskey`.
  - A credential ID already stored → `400 passkey-registration-failed`.
  - Options reused or older than 300 s → `400 passkey-registration-failed`.
  - During a pending sign-in → `401 second-factor-required`.
  - AS-8: a `force_tfa` account without second factors signs in as `restricted` with
    `[secondFactorSetup]`, registers → the next `GET /session` is `active`, and audit
    `details.restrictionLifted` is true (FR-054).
  - With `[passwordChange, secondFactorSetup]` → `403 pending-action-required`.
  - Audit `passkeyRegistration` success, and failure `registrationFailed`.

  *Implementation note*: without an attestation certificate lbuchs reports `certificateSubject`
  and `certificateIssuer` as empty strings, which legacy stores as they are; the API stores them the
  same way and reports an empty subject as `authenticatorDescription: null`. The duplicate check and
  insert run under `GET_LOCK('restv2_fido2_<cid>')`. *Orchestrator decision (Phase 6)*: the options
  keep lbuchs's attestation conveyance, as legacy sends it: `indirect` by default (no browser
  privacy prompt), `direct` only with `WEBAUTHN_ONLY_TRUSTED_VENDORS=y`, `none` when only the
  `none` format is accepted. `OptionsJson` no longer maps `indirect` to `direct`, and the contract's
  `WebAuthnCreationOptions.attestation` description says so.
- [X] T071 [P] [US2] Write `tests/Feature/Passkeys/PasskeyManagementTest.php` for `listPasskeys`,
  `getPasskey`, `updatePasskey` and `deletePasskey` (FR-053, AS-7):
  - The list shows legacy and API passkeys with `id` = `SHA2(credentialId)`, `name`,
    `authenticatorDescription` (null when `certificateSubject` is null), `registeredAt` converted
    from `TZ` to UTC, `hostName`, `active` and `etag`. Each entry's `etag` equals the `ETag` of
    `getPasskey` for that passkey. Sort by `registeredAt`/`name`, `limit`/`offset` (`limit=1000`
    accepted, `1001` → `outOfRange`), `q` over the name and authenticator description, and `meta`.
  - `getPasskey` returns an `ETag`.
  - `PATCH` with `If-Match` → `200` and a new `ETag`. A stale `If-Match` → `412` with the row
    unchanged. A name over 255 characters → `422 tooLong`.
  - `DELETE` → `204`; the row is gone and the passkey no longer signs in. Removing the last passkey
    is allowed.
  - Another account's passkey ID → `404` for every role, including admin.
  - Each change writes an audit record (`passkeyRename`, `passkeyRemoval`).
  - A restricted session → `403 pending-action-required`.

### Implementation for User Story 2

- [X] T072 [US2] Create `app/Services/Auth/PasskeySignInService.php` (R9, R16, FR-015–FR-018). It
  issues the challenge (`getGetArgs(null, 300, false, false, false, false, uvLogin)`, stored
  through `SessionManager`) and verifies. Verification consumes the challenge, looks up
  `fido2.credentialId = rawId`, runs `processGet` with the configured UV and UP flags, checks
  `fido2.active`, resolves the account (admin/domain admin, then mailbox, or the named role only),
  checks `AccountResolver` activity, then `promote()`, `sasl_log` for a mailbox, clears the
  throttle and audits. On failure it hits the passkey throttle, delays and audits with its reason.

  *Implementation note*: with lbuchs 2.2 the call is `getGetArgs([], 300, false, false, false,
  false, false, $uvLogin)` (the extra `allowHybrid` parameter, see T066).
- [X] T073 [US2] Create `app/Services/Passkeys/PasskeyService.php`, the only writer of `fido2`.
  It issues registration options (lbuchs `getCreateArgs` per R9, stored through `SessionManager`),
  registers (consumes the options, runs `processCreate`, checks for duplicates, inserts per
  data-model.md, audits `restrictionLifted`), lists and finds with a parameterised
  `SHA2(credentialId, 256)` comparison, renames (`friendlyName`, `If-Match` against
  `ETag::forPasskey`) and removes. Each write and its audit share one transaction.

  *Implementation note*: the tag is `PasskeyService::etag()` (`ETag::of([cid, friendlyName,
  active])`). Renaming to the current name changes and audits nothing. `deletePasskey` honours
  `If-Match` too, as the contract documents. Rename and removal lock the row (`FOR UPDATE`) between
  the `If-Match` check and the write.
- [X] T074 [P] [US2] Create the Form Requests `app/Http/Requests/Api/V2/PasskeySignInRequest.php`
  (`credential` per `WebAuthnAuthenticationResponse`, `role` optional enum),
  `RegisterPasskeyRequest.php` (`credential` per `WebAuthnRegistrationResponse`, `name` "optional,
  string, 1–255 after trim"), `UpdatePasskeyRequest.php` (`name` "required, string, 1–255 after
  trim") and `ListPasskeysRequest.php` (through `ListQuery`: `limit` 1–1000, default 25,
  `offset` ≥ 0, `sort` in registeredAt, name, `order` in asc, desc, `q` over `friendlyName` and
  `certificateSubject`).

  *Implementation note*: names are trimmed before validation and storage (trait
  `Concerns\TrimsName`); `ListPasskeysRequest` builds its `ListQuery` after validation. Tests use
  the new `TestCase` helpers `postEmpty()` (operations without a request body) and `patchMerge()`
  (`application/merge-patch+json`).
- [X] T075 [US2] Create `app/Http/Resources/Api/V2/PasskeyResource.php` (with `etag`) and the controllers
  `PasskeyChallengeController.php` (`@store`), `PasskeyAssertionController.php` (`@store`, `201` +
  `Location`), `PasskeyRegistrationOptionsController.php` (`@store`) and `PasskeyController.php`
  (`index`, `show`, `store`, `update`, `destroy`, with `ETag` headers). Register the routes in
  `routes/api.php`: `/me/passkeys/registration-options` **before** `/me/passkeys/{passkeyId}`
  (`where passkeyId [0-9a-f]{64}`). The passkey challenge and assertion use
  `restv2.auth:browser`, `restv2.csrf` and `restv2.pending:allow`. The `/me/passkeys*` routes use
  `restv2.auth:session` (plus `restv2.csrf` on unsafe methods). The two registration routes carry
  `restv2.pending:secondFactorSetup`, the others the default `enforce`. This makes T069–T071 pass.

**Checkpoint**: Passkeys work end to end, independently of US3 and US4.

---

## Phase 5: User Story 3 — Ask "who am I" and manage own preferences (P1)

**Goal**: `GET /me` with role, scope, effective flags, authentication method, pending actions and
preferences, evaluated from current data on every request. Per-account language and theme.

**Independent test**: Sign in as each role (with `signInAs`) and compare identity with the
database. Change `da_acl` and `domain_admins` between requests. Update preferences and read them
from a new session.

### Tests for User Story 3 (write first, observe failing) ⚠️

- [X] T076 [P] [US3] Write `tests/Feature/Identity/IdentityTest.php` for `getIdentity` (FR-028, FR-029):
  - AS-1: a domain admin with two active assignments and one inactive → `scope {type: domains,
    domains: [the two, sorted]}`, and flags per `da_acl`.
  - AS-2: a mailbox user → `scope {type: mailbox, mailbox, domain}`, and `user_acl` flags.
  - An admin → `allDomains`, with every flag true.
  - `authentication.method` matches the sign-in method (password, passwordAndSecondFactor,
    passkey).
  - `preferences` is present for sessions.
  - FR-029: `UPDATE da_acl SET login_as = 0` between two requests is reflected, and so is an
    assignment deactivated in `domain_admins`.
  - AS-5: a restricted session can read `/me` with `pendingActions`.
  - The account deactivated mid-session → `401 unauthenticated` and the session is gone.
  - FR-013: after a sign-in, no Redis key outside the `restv2:` prefix was created or changed
    (`SCAN` before and after), so a legacy `PHPREDIS_SESSION:*` session stays untouched.
  - A pending sign-in → `401 second-factor-required`.
  - No session → `401 unauthenticated`.
  - Times are UTC.

  *Implementation note*: `Identity` has no date-time member, so "times are UTC" has nothing to
  check here (`signedInAt` is covered by T028). The FR-013 check compares every key outside
  `restv2:`, with its value, before and after a sign-in, an identity read and a sign-out.
- [X] T077 [P] [US3] Write `tests/Feature/Identity/PermissionFlagsTest.php`, an exhaustive matrix
  of `acl('to_session')` (R20):
  - admin → all 29 true;
  - domain admin with a `da_acl` row that has every value 0 → every `user_acl`-only flag true and
    every `da_acl` flag false;
  - domain admin without a `da_acl` row → `da_acl` flags false;
  - mailbox with a `user_acl` row that has every value 0 → all false;
  - mailbox without a `user_acl` row → all false;
  - the overlapping flags `syncjobs`, `quarantine`, `appPasswds`, `pushover` and `spamPolicy`
    taken from `da_acl` for domain admins.

  *Implementation note (orchestrator decision, Phase 6)*: a domain administrator without a
  `da_acl` row has the flags of the row legacy would insert (`INSERT INTO da_acl (username)`, so
  every column default of the schema), not every flag off. The test reads the defaults from the
  fixture and spot-checks `loginAs` on, `pushover` and `unlimitedQuota` off. Legacy always writes
  the row when it creates a domain administrator.
- [X] T078 [P] [US3] Write `tests/Feature/Identity/PreferencesTest.php` for `getPreferences` and
  `updatePreferences` (FR-030, AS-3, AS-4):
  - The defaults are `DEFAULT_LANG` from the vars fixture (`cs-cz`) and `system`.
  - The body carries `etag`, equal to the `ETag` header. The tag covers the stored values, so it
    does not change when the server's default language changes.
  - `PATCH {language: sk-sk, theme: dark}` → `200` with a new `ETag`. A new session for the same
    account sees the values. The admin and mailbox namespaces are separate for the same username.
  - AS-4: `language: de-de` (not enabled in the fixture) → `422` `language` `notInList` with
    `params.allowed` = the enabled codes.
  - `theme: blue` → `notInList`; `{}` → `422 body required`; `language: null` → `invalidType`.
  - A stale `If-Match` → `412`.
  - Audit `preferencesUpdate` with `details.changedFields`.
  - A stored language that is later disabled reads as the default.
  - A restricted session → `403 pending-action-required`.

### Implementation for User Story 3

- [X] T079 [P] [US3] Create `app/Services/Identity/Scope.php` (value object) and
  `app/Services/Identity/IdentityService.php`. It computes the scope with the legacy query, the
  flags per R20 using `App\Auth\PermissionFlag`, and the pending actions, for a `Principal` from a
  session or a key.
- [X] T080 [P] [US3] Create
  `database/migrations/2026_10_18_000003_create_restv2_preferences_table.php` (columns and unique
  key of data-model.md), `app/Models/Preference.php` (`$table = 'restv2_preferences'`) and
  `app/Services/Preferences/PreferencesService.php` (effective values, upsert, `ETag`, audit in
  one transaction).

  *Implementation note*: the first update of an account inserts an empty row (`insertOrIgnore`),
  which reads like no row, and then locks it, so that concurrent first updates serialise instead of
  failing on the unique key. Only changed stored values are written and audited
  (`details.changedFields`, documented in `docs/audit-events.md`).
- [X] T081 [US3] Create `app/Http/Requests/Api/V2/UpdatePreferencesRequest.php`: body "at least one
  of language, theme"; `language` "string, max 16, `EnabledLanguage`"; `theme` "in light, dark,
  system"; `null` → `invalidType`. Create `app/Http/Resources/Api/V2/IdentityResource.php` and
  `PreferencesResource.php` (with `etag`), and `app/Http/Controllers/Api/V2/IdentityController.php` (`show`) and
  `PreferencesController.php` (`show`, `update`). Register `GET /me` (`restv2.auth:authenticated`,
  `restv2.pending:allow`) and `GET`/`PATCH /me/preferences` (`restv2.auth:session`, `restv2.csrf`
  on `PATCH`) in
  `routes/api.php`. This makes T076–T078 pass.

**Checkpoint**: Identity and preferences work for sessions. Key-based identity follows in US4.

---

## Phase 6: User Story 4 — Manage API keys and authenticate automation clients (P1)

**Goal**: Administrator-managed keys with owner, access level, expiry and networks, `X-API-Key`
authentication acting as the owner, precise problem types, and fail2ban reporting.

**Independent test**: Create keys for both owner roles and every combination of access level,
expiry and networks. Use them from allowed and disallowed addresses for reads and writes, before
and after expiry and revocation, and check every outcome and fail2ban message.

### Tests for User Story 4 (write first, observe failing) ⚠️

- [X] T082 [P] [US4] Write `tests/Feature/ApiKeys/ApiKeyAuthenticationTest.php` (FR-033, FR-039, SC-005,
  FR-038–FR-041, R19). Use `ChannelSpy`, and test with `REMOTE_ADDR` set through `withServerVariables`.
  - AS-2: a key owned by a domain admin → `GET /me` gives the owner's role, scope and flags, plus
    `authentication {method: apiKey, apiKey {id, name, prefix, accessLevel}}`.
  - AS-3: a read-only key on `GET /me` → `200`; on `DELETE /session` and `POST /api-keys` →
    `403 insufficient-key-scope`, not published.
  - AS-7: an unknown key, a malformed value, a legacy `/api/v1`-style key (`ABCDEF-123456-…`) and a
    300-character value → `401 api-key-invalid`, each published exactly as `mailcow UI: Invalid
    password for API_USER by <ip>`.
  - AS-6: expired → `401 api-key-expired`, published.
  - AS-5: revoked → `401 api-key-revoked`, published.
  - Owner deactivated, or owner deleted → `api-key-invalid`; reactivated → works again.
  - AS-4: networks `["192.0.2.0/24", "2001:db8::/32"]` from `198.51.100.1` →
    `403 api-key-source-not-allowed`, published; from `192.0.2.7` and `2001:db8::5` → `200`.
  - A key plus a session cookie → the key decides, and no `Set-Cookie` is sent.
  - Key requests need no CSRF token and create no Redis session key.
  - `last_used_at` and `last_used_from` are set on first use, not updated within 60 s from the same
    IP, updated from a new IP or after 60 s (`travel`).
  - A domain admin's key with no domains → `scope.domains = []`.
  - A key whose owner has `force_pw_update` is not restricted, and `GET /me` shows
    `pendingActions: []` (R8).
  - Legacy's `api` table is unchanged after all of the above (FR-041).

  *Implementation note*: keys are inserted directly by the test trait
  `Tests\Support\ApiKeys\ApiKeys` (no creation audit). Extra cases: a revoked key wins over an
  expired one, the owner's role is read on every request, and an empty header is no key.
- [X] T083 [P] [US4] Write `tests/Feature/ApiKeys/ApiKeyManagementTest.php` (FR-032–FR-037, SC-010) for `createApiKey`,
  `listApiKeys`, `getApiKey` and `revokeApiKey`:
  - AS-1: an admin session creates a key → `201`, `Location`, `ETag`, `secret` matching
    `^mcv2_[A-Za-z0-9]{40}$`, and `prefix` = its first 12 characters. The row stores only
    `secret_hash` = sha256. Listing and `getApiKey` never contain `secret`.
  - Validation (FR-032):
    - name missing or empty → `required`; 256 characters → `tooLong`;
    - `ownerUsername` unknown → `notFound`; a mailbox-only username → `notEligible` with
      `allowedRoles`;
    - `accessLevel: admin` → `notInList`;
    - `expiresAt` in the past → `mustBeInFuture`; `expiresAt: "tomorrow"` → `invalidFormat`;
    - `allowedNetworks: ["10.0.0.0/33"]` → `allowedNetworks.0 invalidFormat ipNetwork`;
    - 101 networks → `tooMany`;
    - networks are normalised (`198.51.100.7` → `198.51.100.7/32`) and deduplicated.
  - List filters `ownerUsername`, `status` (`active`, `expired`, `revoked`) and `accessLevel`, sort
    `createdAt`/`name`/`expiresAt`/`lastUsedAt`, `q` over name, prefix and owner username, and
    `meta` (FR-035, FR-058).
  - Every list entry carries `etag`, equal to the `ETag` of `getApiKey`. A key use that updates
    `lastUsedAt`, and an expiry passing, leave the `etag` unchanged; a revocation changes it.
  - `getApiKey` returns every field of FR-035, including `owner.role` and `owner.active`.
  - AS-5: revocation → `200` with `status revoked`, `revokedAt` and `revokedBy`. The key stays
    listed. A second revocation → `200` unchanged, with no second audit (FR-036), also when it
    sends the `If-Match` read before the first revocation. A stale `If-Match` on a key that is not
    yet revoked → `412`.
  - AS-8: a read-write admin key on `POST /api-keys` and on the revocation → `403 session-required`
    (FR-037).
  - An admin key can list and get.
  - AS-9: a domain-admin session and a mailbox session on list, get, create and revoke →
    `403 forbidden`. A domain-admin key on list and get → `403 forbidden`; on create and revoke →
    `403 session-required`, because the authentication step refuses keys before any policy runs
    (R8).
  - Audits `apiKeyCreation` and `apiKeyRevocation` contain no secret: assert by searching the
    serialised record for the secret.

  *Implementation note*: the contract-invalid bodies (missing or too long name, unknown access
  level, `expiresAt: "tomorrow"`, 101 networks) are sent by `test_invalid_request_bodies_…`
  without request validation. The owner is stored with mailcow's spelling of the username
  (`RESELLER` → `reseller`).
- [X] T084 [P] [US4] Write `tests/Feature/ApiKeys/SessionOnlyOperationsWithKeyTest.php` (FR-031, FR-037). A
  read-write admin key on every session-only operation must answer `403 session-required`:
  `getCsrfToken`, `getSession`, `createSession`, `deleteSession`, `cancelPendingSignIn`, both
  challenges, `verifySecondFactor`, `createSessionWithPasskey`, preferences, and all passkey
  operations. A read-only key on the unsafe ones → `403 insufficient-key-scope`, because the scope
  check comes first (R8).

  *Implementation note*: a data provider over the 17 operations; a key request never gets a
  session cookie.
- [X] T085 [P] [US4] Write `tests/Feature/ApiKeys/ApiKeyOwnerCandidatesTest.php` for
  `listApiKeyOwnerCandidates` (FR-057, AS-10):
  - An admin session lists the active admins and domain admins, sorted by username, with `role`.
    Inactive admin rows and mailbox users are absent, also when a mailbox has the same username as
    nothing in `admin`.
  - `q=res` matches `reseller` and `res-admin`, case-insensitively, and treats `%` and `_`
    literally.
  - `role=domainAdmin` filters, and `limit`, `offset` and `meta` work.
  - An admin read-only key and an admin read-write key → `200`.
  - A domain-admin session, a mailbox session and a domain-admin key → `403 forbidden`.
  - The route `/api-keys/owner-candidates` is not captured by `/api-keys/{apiKeyId}`.
  - No audit record is written.

### Implementation for User Story 4

- [X] T086 [P] [US4] Create
  `database/migrations/2026_10_18_000001_create_restv2_api_keys_table.php` (columns, unique
  `secret_hash` and indexes of data-model.md) and `app/Models/ApiKey.php` (`HasUlids`,
  `$table = 'restv2_api_keys'`, casts `allowed_networks` array, datetimes UTC; `status()`;
  scopes `active()`, `expired()`, `revoked()`).
- [X] T087 [US4] Create `app/Services/ApiKeys/ApiKeyAuthenticator.php` (R19). It runs the format
  check, the hash lookup, then revoked → expired → owner → network in that order, calls
  `Fail2BanReporter::invalidApiKey` on each failure, and does the throttled last-use update. It
  returns a `Principal` with `AuthMethod::ApiKey`.
- [X] T088 [US4] Create `app/Http/Middleware/AuthenticateApiKey.php` and `EnforceKeyScope.php`.
  `AuthenticateApiKey` skips the `system.*` routes (health, contract, docs, problem pages) and
  marks the request. Update
  `StartSessionUnlessApiKey.php` to skip marked requests, `VerifyCsrfToken.php` to skip them, and
  `RequireAuth.php` to accept a key principal (`authenticated`) or answer `session-required`
  (`session`). Insert the two middleware right after `AssignRequestId` in the `api.v2` group in
  `bootstrap/app.php` (R8 order). This makes T082 and T084 pass.

  *Implementation note*: `StartSessionUnlessApiKey`, `VerifyCsrfToken` and `RequireAuth` already
  honoured the request mark since Phase 2. Laravel sorts route middleware by its priority list,
  which hoisted `StartSession` (and so `StartSessionUnlessApiKey`) above the unlisted key
  middleware; `AssignRequestId`, `AuthenticateApiKey` and `EnforceKeyScope` are therefore
  prepended to the priority list before `StartSession`. The test stand-in `MarkAsApiKeyRequest` is
  removed, and the middleware unit tests use real keys.
- [X] T089 [US4] Create `app/Services/ApiKeys/ApiKeyService.php`. `create` generates the secret
  with `random_bytes` base62, stores the hash and prefix, normalises the networks and audits, in
  one transaction, and returns the secret once. `revoke` is idempotent, compares `If-Match`, and
  audits only the first revocation. `list` applies the filters, sort and pagination.

  *Implementation note*: the secret is drawn with `random_int` (the CSPRNG) over the base62
  alphabet. `ListQuery::paginate()` takes a tie-breaker column (`id`), so that equal sort values
  keep a stable order across pages.
- [X] T090 [US4] Create `app/Policies/ApiKeyPolicy.php` (`viewAny`/`view`: principal role admin,
  session or key; `create`/`revoke`: admin with a session, else `session-required` for keys and
  `forbidden` for other roles), and the Form Requests
  `app/Http/Requests/Api/V2/StoreApiKeyRequest.php` (rules quoted from data-model.md "ApiKey ›
  Validation") and `ListApiKeysRequest.php`. Create `app/Http/Resources/Api/V2/ApiKeyResource.php`
  (with `secret` only when created) and the controllers `ApiKeyController.php` (`index`, `show`,
  `store`) and `ApiKeyRevocationController.php` (`store`). Register the routes in `routes/api.php`:
  list and get with `restv2.auth:authenticated`; create and revoke with `restv2.auth:session` and
  `restv2.csrf`. `ListApiKeysRequest` uses `ListQuery` (`limit` 1–1000, `q`), and `ApiKeyResource`
  includes `etag` (tag over the stored settings of R19). Also create
  `app/Http/Requests/Api/V2/ListApiKeyOwnerCandidatesRequest.php` (`ListQuery`, `sort` username,
  `role` in admin, domainAdmin), `app/Http/Resources/Api/V2/ApiKeyOwnerCandidateResource.php`, and
  `app/Http/Controllers/Api/V2/ApiKeyOwnerCandidateController.php` (`index`, the query of
  data-model.md "Possible key owners", authorised through `ApiKeyPolicy::viewAny`). Register
  `GET /api-keys/owner-candidates` with `restv2.auth:authenticated` **before**
  `/api-keys/{apiKeyId}`, which is constrained to the ULID pattern. This makes T083 and T085
  pass.

  *Implementation note*: the policy runs through Laravel's Gate (`Gate::forUser($principal)`,
  policy auto-discovered for `App\Models\ApiKey`). The list and create Form Requests authorise in
  `authorize()`, so that the policy (`403`) comes before validation (`422`), as R8 orders. The
  model has an `owner` relation to mailcow's `admin` row for `owner.role` and `owner.active`.

**Checkpoint**: Keys work. US3 identity now also works with keys.

---

## Phase 7: User Story 5 — Read the public branding configuration before sign-in (P2)

**Goal**: The public configuration and logos come from mailcow's Redis customisation keys and
languages, with legacy defaults for upstream and FeldHost.

**Independent test**: Without signing in, compare `GET /public-config` with the Redis keys written
the way legacy's `customize('edit', …)` writes them. Change a key and read again.

### Tests for User Story 5 (write first, observe failing) ⚠️

- [X] T091 [P] [US5] Write `tests/Feature/PublicConfig/PublicConfigTest.php` (SC-008) for `getPublicConfig`
  (FR-042–FR-044, AS-1–AS-3, R15):
  - With empty Redis: `pageTitle` = `productName` = `"mail.example.org - mail UI"`, logos with
    `url /img/cow_mailcow.svg` and `isDefault: true`, help, footer and announcement null and
    inactive, `loginPage` all false, `externalIdentityProvider.configured` false, `languages` and
    `defaultLanguage` from the vars fixture, `appsLabel` null, and `appLinks` equal to the
    fixture's `$MAILCOW_APPS` (app links are tested in depth in T093).
  - With `RESTV2_DEFAULT_UI_NAME="FeldHost™ Mailhosting UI"` and the FeldHost logo URLs, those
    defaults apply.
  - With keys written like legacy (`TITLE_NAME` = `htmlspecialchars("A & B")`, `MAIN_NAME`,
    `HELP_TEXT` HTML, `UI_FOOTER`, announcement text, type `warning`, active `1`, `CUSTOM_LOGIN`
    JSON, `MAIN_LOGO` data URI): the decoded `"A & B"`, HTML passed through unchanged, the
    `UI_IMPRESS` fallback when `UI_FOOTER` is empty, an invalid announcement type → `null`, and a
    logo URL with `?v=<16 hex>` and `isDefault: false`.
  - An `identity_provider` `authsource` of `keycloak` → `configured: true`.
  - AS-3: a changed announcement is visible on the next request.
  - `ETag` and `Cache-Control: no-cache`; `If-None-Match` → `304`.
  - No password, secret or account data appears (scan the payload for `REDISPASS`, `DBPASS` and
    usernames).

  *Implementation note*: the scan leaves out `DBPASS`, because the dev value `mailcow` is part of
  the default logo's file name; it checks `REDISPASS`, the app key and usernames. Extra cases: a
  stored `"0"` or an unknown announcement type falls back like legacy, a logo that is not an
  accepted data URI counts as unset, and an invalid `X-API-Key` is refused (R8).
- [X] T092 [P] [US5] Write `tests/Feature/PublicConfig/PublicConfigLogoTest.php` for
  `getPublicConfigLogo`:
  - PNG, JPEG, GIF and SVG data URIs are served with their media type.
  - `image/x-png` and `image/pjpeg` are normalised.
  - A matching `v` gives `Cache-Control: public, max-age=31536000, immutable`; no `v` gives
    `no-cache`.
  - `ETag` and `304`.
  - The CSP `default-src 'none'; style-src 'unsafe-inline'; sandbox` is set.
  - An unset variant → `404`. An unknown variant → `404` (route constraint).
- [X] T093 [P] [US5] Write `tests/Feature/PublicConfig/PublicConfigAppLinksTest.php` (FR-056, AS-4,
  R15):
  - The upstream default `$MAILCOW_APPS` gives one `builtIn` Webmail link with `url` and
    `signedInUrl` `/SOGo/so` and `showOnLoginPage: false`.
  - A vars override with a second built-in app that has a `description` and a `user_link`
    containing `%u` keeps the order and returns the template unchanged, with `%u` unsubstituted.
  - `APP_LINKS` written as legacy's `customize('edit', 'app_links')` writes it
    (`[{"Helpdesk": {"link": "https://h.example/", "user_link": "https://h.example/?u=%u",
    "hide": true}}]`), plus an old-style entry `{"Wiki": "https://wiki.example/"}`, gives two
    `operator` links after the built-ins. Helpdesk has `showOnLoginPage: false`. Wiki has
    `url = signedInUrl` and `showOnLoginPage: true`.
  - `SKIP_SOGO=y` drops the Webmail entry (and any built-in whose `link` or `user_link` matches
    `/SOGo/i`), but keeps operator links that point to SOGo, as legacy does.
  - `APPS_NAME` = `htmlspecialchars("Tools & Apps")` gives `appsLabel "Tools & Apps"`.
  - Malformed `APP_LINKS` JSON gives the built-ins only and logs a warning.

  *Implementation note*: an operator entry with an empty `user_link` uses its `link` for signed-in
  users, as legacy's header does.

### Implementation for User Story 5

- [X] T094 [US5] Create `app/Services/Branding/PublicConfigService.php` (R15: Redis connection
  `mailcow`, entity decoding, defaults from `config/restv2.php`, languages from `MailcowConfig`,
  IdP flag from `IdentityProviderSetting`, logo decoding with the MIME whitelist). It reuses
  `ProductName` from T051 for `productName` and the title default. Add `appsLabel` (`APPS_NAME`,
  decoded) and `appLinks`, built from `MailcowConfig::mailcowApps()` and `APP_LINKS` per
  data-model.md "App links", including the old-style conversion and the `SKIP_SOGO` rule.

  *Implementation note*:
  - A non-empty `UI_IMPRESS` wins over `UI_FOOTER`: legacy copies it into `UI_FOOTER` whenever it
    reads the texts, so that is what legacy shows. The API only reads. Values legacy treats as
    falsy (`""`, `"0"`) fall back to the default; `ProductName` now does the same.
  - `externalIdentityProvider.singleSignOnAvailable` (added to the contract by feature 012) is
    always `false`: single sign-on through this API (`POST /session/single-sign-on`) arrives with
    feature 012, which computes it.
- [X] T095 [US5] Create `app/Http/Resources/Api/V2/PublicConfigResource.php` and the controllers
  `PublicConfigController.php` (`show`, with `ETag`, `304`, `Cache-Control`) and
  `PublicConfigLogoController.php` (`show`). Register the routes (`restv2.auth:public`,
  `restv2.pending:allow`, `variant` constrained to `light|dark`) in `routes/api.php`. The resource
  includes `appsLabel` and `appLinks` (contract `AppLink`). This makes T091, T092 and T093 pass.

  *Implementation note*:
  - Symfony always adds `private` to a bare `no-cache`, so the contract's
    `Cache-Control: const no-cache` could not be met. The API sends `no-cache, public` (any cache
    may store the account-free configuration but must revalidate), and the contract now requires
    the `no-cache` directive (pattern) instead of the exact value; the logo's description names
    `no-cache, public` too.
  - Routes in auth mode `public` no longer start a session (`StartSessionUnlessApiKey`), so that
    signed-out page loads and monitors create no Redis session.
  - Logos also carry `X-Content-Type-Options: nosniff`.

**Checkpoint**: Branding is readable before sign-in.

---

## Phase 8: User Story 6 — Review the authentication audit log (P2)

**Goal**: Administrators list the immutable audit records with filters. Retention purge.

**Independent test**: Run the flows of US1, US2 and US4, then list with each filter, and scan every
record for secrets.

### Tests for User Story 6 (write first, observe failing) ⚠️

- [X] T096 [P] [US6] Write `tests/Feature/Audit/AuditEventListTest.php` for `listAuditEvents`
  (FR-047):
  - Newest first by default; `order=asc` works.
  - The filters `from`, `to`, repeated `eventType` (`?eventType=signIn&eventType=signOut` returns
    both types), `outcome`, `actor` (an attempted username and
    an API key ID) and `clientAddress` (an exact IPv4, an exact IPv6, and the prefix
    `192.0.2.0/24`) work.
  - `limit` and `offset` give `meta.total`.
  - `q` searches the actor username, target name and user agent.
  - Invalid filters: `limit=0` and `limit=1001` → `outOfRange`; `eventType=foo` → `notInList`;
    `from=yesterday` → `invalidFormat`; `clientAddress=999.1.1.1` → `invalidFormat`.
  - Entries carry no `etag`, because audit records never change.
  - AS-1: a failed sign-in of `alice@example.org` from `192.0.2.10` is listed with the attempted
    username, time, address and `failureReason`.
  - AS-2: a key creation and revocation listed with actor, key name and time, without the secret.
  - AS-3: a mailbox user, a domain admin and a domain-admin key → `403 forbidden`; an admin key →
    `200`.

  *Implementation note (deviation from the task text)*: `eventType=foo` is not refused.
  `AuditEventType`, `AuthMethod` and the target types are shared enumerations that every feature
  extends, so a value this server does not record (for example one of a feature it does not
  implement yet) matches no records instead of answering `422`; clients built against a later
  contract keep working (FR-047 now says so). Values are limited to 64 characters (`tooLong`) and
  20 per repeated parameter (`tooMany`). Added for G18 and G19 (FR-059, FR-060): the repeatable
  `authMethod` filter and the `requestId` filter (`invalidFormat`, `format: requestId`, which
  `ValidationRuleCode` documents). The contract's generic `targetType` and `targetId` filters are
  implemented as exact matches (the domain-name forms are feature 002's), and
  `impersonatedAccount` (feature 007) matches nothing, because no record is written during an
  impersonation before 007. `occurredAt` carries microseconds.
- [X] T097 [P] [US6] Write `tests/Feature/Security/SecretLeakScanTest.php` (SC-009, FR-050). It
  runs every audited flow once with known secrets (password `S3cret-Pass!`, a TOTP code, an OTP,
  WebAuthn assertion bytes as base64url, a key secret), captures the `stderr_json` log through a
  test handler, and asserts that none of these strings appear in any `restv2_audit_events` row, any
  log line or any response body other than the single `createApiKey` response.

  *Implementation note*: the log capture is a Monolog `TestHandler` pushed onto the `stderr_json`
  logger. One-time codes are searched as whole tokens, so that six digits inside a request ID or
  a timestamp cannot give a false positive. The scan also checks that every 001 event type was
  written.
- [X] T098 [P] [US6] Write `tests/Feature/Audit/AuditPurgeCommandTest.php` (FR-048): with
  `RESTV2_AUDIT_RETENTION_DAYS=30`, `php artisan restv2:audit-purge` deletes only the records older
  than 30 days, in batches of 1000, and prints the count. No HTTP route modifies or deletes records:
  assert that `PUT`, `PATCH` and `DELETE` on `/api/v2/audit-events` answer `405`.

  *Implementation note*: the loop stops after a partial batch, so 2,501 old records take three
  `DELETE … LIMIT 1000` statements.

### Implementation for User Story 6

- [X] T099 [US6] Create `app/Policies/AuditEventPolicy.php` (admin principal, session or key),
  `app/Http/Requests/Api/V2/ListAuditEventsRequest.php` (`from`/`to` RFC 3339, `eventType`
  repeatable and read from the raw `QUERY_STRING` because PHP keeps only the last repeated key,
  each in the enum, at most 20 values, `outcome` enum, `actor` max 255, `clientAddress` `IpNetwork` or
  address, plus `ListQuery` pagination and `q`), `app/Http/Resources/Api/V2/AuditEventResource.php` and
  `app/Http/Controllers/Api/V2/AuditEventController.php` (`index`, with the prefix filter of
  data-model.md). Register `GET /audit-events` (`restv2.auth:authenticated`) in `routes/api.php`.
  This makes T096 pass.

  *Implementation note*: the prefix filter runs in MariaDB instead of PHP, so that paging stays in
  SQL: IPv4 prefixes compare `INET_ATON(client_address) & mask`, IPv6 prefixes the leading hex
  digits of `HEX(INET6_ATON(client_address))` (plus the masked next digit), and a single address
  compares `INET6_ATON()` of both sides. `AuditLogger` now stores the canonical text form of the
  client address.
- [X] T100 [US6] Create `app/Console/Commands/AuditPurgeCommand.php` (`restv2:audit-purge`,
  batched deletes). This makes T098 pass. T097 passes once US1, US2 and US4 are implemented.

**Checkpoint**: All six user stories work independently.

---

## Phase 9: Polish, operations and cross-cutting concerns

- [X] T101 [P] Write `tests/Feature/System/HealthTest.php` for `getLiveness` and `getReadiness`:
  - live → `200` even with the database configured to a bad host;
  - ready → `200` with `checks` all `ok`;
  - `UPDATE versions SET version = '01012000_0000'` → `503 service-unavailable` with
    `checks.schema = failed`;
  - a pending migration (a temporary migration file) → `migrations: failed`;
  - a Redis on a bad port → `redis: failed`;
  - `X-API-Key: bogus` is ignored.

  Write `tests/Feature/System/HealthCommandTest.php`: `restv2:health --live` and `--ready` give exit
  code 0 when healthy and 1 when not.

  *Implementation note*: the bad database is a `broken` default connection while the test's
  transaction stays on `mysql` (`connectionsToTransact()`); readiness then also reports
  `database: failed` (and `schema` and `migrations`, which need it). The Redis manager is rebuilt
  after the port change. The pending migration is a temporary file on an extra migrator path.
  Health routes start no session.
- [X] T102 Create the single-action controllers `app/Http/Controllers/Api/V2/LivenessController.php`
  and `ReadinessController.php` (each with `show`, constitution path table) and
  `app/Console/Commands/HealthCommand.php` (`restv2:health`), sharing
  `app/Services/Health/ReadinessChecker.php`. Register the routes named `system.liveness` and
  `system.readiness`, which `AuthenticateApiKey` skips, in `routes/api.php`. This makes T101 pass.

  *Implementation note*: readiness is the default of `restv2:health`; it prints each check.
- [X] T103 [P] Write `tests/Feature/System/DocsTest.php`:
  - `getOpenApiDocument` → `200 application/yaml`, byte-equal to `build/contract/openapi.yaml`;
  - `getApiDocs` → `200 text/html`, referencing only same-origin assets;
  - `getProblemTypeDocumentation` → `200 text/html` for every `ProblemType` case, containing the
    section heading of `docs/problems.md`; `unknown-type` → `404`.
  - `X-API-Key: bogus` on all three is ignored, with no fail2ban message.

  *Implementation note*: when the Swagger UI assets are installed they are inlined, so the
  same-origin check scans the markup around the inline `<script>` and `<style>` blocks.
- [X] T104 Create the single-action controllers (each with `show`) in
  `app/Http/Controllers/Api/V2/`:
  - `OpenApiDocumentController.php` serves `resources/contract/openapi.yaml`, built by the image or
    copied from `build/contract/` in dev;
  - `ApiDocsController.php` serves an HTML page with Swagger UI (`swagger-ui-dist`, Apache-2.0),
    whose JS and CSS are inlined from `resources/docs/` at build time;
  - `ProblemTypeController.php` renders the `## <name>` section of `docs/problems.md` with
    `Str::markdown()`, with HTML escaped.

  Their routes are named `system.openapi`, `system.docs` and `system.problem`, which
  `AuthenticateApiKey` skips.

  Add `resources/docs/.gitkeep` and a `composer docs:assets` script that copies the
  `swagger-ui-dist` files with `npx`. Register the routes. This makes T103 pass.

  *Implementation note*: `OpenApiDocumentController` serves `resources/contract/openapi.yaml`
  (the image) or else `build/contract/openapi.yaml` (a development checkout). `composer
  docs:assets` uses `npm pack swagger-ui-dist@5.33.0` (pinned) and copies `swagger-ui.css`,
  `swagger-ui-bundle.js` and its `LICENSE`; `resources/contract/` and `resources/docs/*` are
  generated and ignored by git. Without the assets the page links to the contract. Every
  `## <name>` section of `docs/problems.md` is served, planned features' types included; the
  problem pages carry a restrictive CSP.
- [X] T105 [P] Write `tests/Feature/Security/AuthorizationMatrixTest.php` (SC-005, SC-010, constitution VI, quality
  gate 5). It is a data provider over **all 31 operations** × the callers anonymous, pending sign-in,
  restricted (`passwordChange`), restricted (`secondFactorSetup`), admin session, domain-admin
  session, mailbox session, admin read-write key, admin read-only key and domain-admin read-write
  key. It asserts the expected status and problem `type` from a table kept in the test. That table
  is derived from the security and descriptions of `api/`, R8 and FR-014. It includes an invalid
  `X-API-Key` on the public `GET /public-config` → `401 api-key-invalid`. Object-level cases:
  another account's `passkeyId` → `404`; an unknown `apiKeyId` → `404` for admins and
  `403 forbidden` for others.

  *Implementation note*: 312 cases (31 × 10, plus the invalid key and a completeness check that
  the table lists exactly the registry's 31 operations). The pending sign-in has a TOTP method
  (the contract requires at least one); the accounts use a fast hash scheme, because no password is
  verified.
- [X] T106 [P] Write `tests/Unit/Support/ProblemTypeConsistencyTest.php` (FR-049). Every `ProblemType`
  case must be a `## <name>` section of `docs/problems.md` and a `KnownProblemType` value in
  `build/contract/openapi.json`, with the status stated in its section. Every problem type that
  `tests/feature-registry.json` lists for an implemented feature must have a case; sections of
  planned features need none (orchestrator decision: `docs/problems.md` also documents planned
  features).
- [X] T107 [P] Write `tests/Feature/Performance/SignInLatencyTest.php` (SC-007). Over 20 iterations
  each, measure the server time of `createSession` without a second factor, with a pending TOTP and
  `verifySecondFactor`, and `createSessionWithPasskey`, excluding the faked `Sleeper`. Assert that
  the 95th percentile is under 1 s with a `BLF-CRYPT` cost of 10. The test is in the group
  `performance`, which CI runs.
- [X] T108 Complete the `production` stage of `docker/php/Dockerfile` (R23):
  1. a `node:22-alpine` build stage runs `npx @redocly/cli@2.64.1 bundle` into
     `resources/contract/openapi.yaml` and copies the Swagger UI assets;
  2. `composer install --no-dev --classmap-authoritative --no-interaction`;
  3. `php artisan route:cache` and `event:cache`;
  4. `USER www-data`, `EXPOSE 9000`, `ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]`.

  Create `docker/php/entrypoint.sh`: `set -eu`, `php artisan config:cache`,
  `php artisan migrate --force --isolated`, then `exec php-fpm -F`.

  *Implementation note*: Composer runs in a `vendor` stage built from `dev` (the base image has no
  unzip or zip extension); Swagger UI (`swagger-ui-dist@5.33.0`) comes from the Node stage too. The
  route and event caches are written to `/app/bootstrap/image/` (`APP_ROUTES_CACHE`,
  `APP_EVENTS_CACHE`), because `/app/bootstrap/cache` is a tmpfs at runtime. With arguments the
  entrypoint runs them instead (`docker run … php /app/artisan restv2:health --live`, as the CI
  image job does). Verified locally: the image builds (226 MB), the liveness command prints `live`,
  and with `--read-only` and the tmpfs mounts on the dev network the entrypoint caches the
  configuration, migrates, starts php-fpm, and answers health, public configuration, docs, contract
  and problem pages through nginx's FastCGI.
- [X] T109 Create `deploy/docker-compose.override.yml` with the service `restv2-mailcow` exactly as
  in R23:
  - image `${RESTV2_IMAGE:-ghcr.io/feldsam-inc/mailcow-rest-v2:0.1.0}`, `restart: always`, `read_only: true`;
  - tmpfs `/app/storage` and `/app/bootstrap/cache` (`uid=82,gid=82`) and `/tmp`;
  - every environment variable of R14, with `APP_KEY=${RESTV2_APP_KEY}`;
  - volume `./data/web/inc:/mailcow/web/inc:ro,z`;
  - `depends_on: [mysql-mailcow, redis-mailcow]`;
  - the ofelia labels `ofelia.enabled: "true"`,
    `ofelia.job-exec.restv2_audit_purge.schedule: "@daily"` and
    `ofelia.job-exec.restv2_audit_purge.command: "php /app/artisan restv2:audit-purge"`;
  - healthcheck `["CMD", "php", "/app/artisan", "restv2:health", "--ready"]`;
  - network `mailcow-network` with alias `restv2`.

  *Implementation note*: also `dns: ${IPV4_NETWORK}.254` (mailcow's unbound, for the Yubico
  service), like php-fpm-mailcow, and the empty-by-default `RESTV2_<legacy variable>` overrides.
  `docker compose config` with upstream `2026-09a`'s `docker-compose.yml` merges it cleanly.
- [X] T110 Verify `deploy/nginx/site.restv2.custom` (created in T002) inside mailcow's own nginx
  image. Render `sites-default.conf.j2` and `nginx.conf.j2` of upstream `2026-09a` the way the
  `ghcr.io/mailcow/nginx:1.30.4-1` entrypoint does, mount the snippet at
  `/etc/nginx/conf.d/site.restv2.custom`, and run `nginx -t`. Confirm that
  `http://127.0.0.1:18080/api/v2/health/live` answers `200` on the dev stack. Record the procedure
  in `README.md` (T112).

  *Implementation note*: `scripts/dev/verify-nginx-snippet.sh [tag]` does it: the tag's templates,
  the image's `bootstrap.py`, the snippet at `/etc/nginx/conf.d/site.restv2.custom`, a throwaway
  certificate (`scripts/dev/generate-dev-certificate.php`), and the upstream hosts nginx resolves
  at load time mapped to 127.0.0.1. `nginx -t` passes in `ghcr.io/mailcow/nginx:1.30.4-1` (its only
  warning is upstream's own `listen … http2`), and `/api/v2/health/live` answers `200` on both dev
  listeners.
- [X] T111 [P] Create `database/seeders/DevelopmentSeeder.php`, which seeds the Part B accounts of
  quickstart.md through `database/factories/MailcowRows.php`. It refuses to run unless
  `APP_ENV=local`.

  *Implementation note*: `tests/Feature/Database/DevelopmentSeederTest.php` covers the refusal and
  the accounts. `scripts/dev/reset-dev-database.sh` was run: the dev database has every `restv2_`
  migration, `restv2_preferences` and `restv2_api_keys` included.
- [X] T112 [P] Write `README.md`:
  - a supported-releases table: mailcow `2026-09a`, `db_version 18082026_1200`, fixture
    `tests/fixtures/schema/2026-09a.sql`;
  - architecture;
  - installation per quickstart Part C;
  - every configuration variable of R14, with the FeldHost values;
  - development commands;
  - licence.

  Write `CHANGELOG.md` with "0.1.0 — contract: the 31 operations of feature 001", for
  mailcowui-ng to pin.

  *Implementation note*: the changelog also lists the contract changes made during implementation
  (G18, G19, `format: requestId`, the attestation text, the `Cache-Control` pattern, the
  second-factor throttling texts).
- [X] T113 Run `composer test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` and
  `composer contract:lint` in the dev container. Fix every finding until all are green and
  `build/contract/coverage.json` lists 31 of 31 operations (`pendingOperations` of
  `tests/feature-registry.json` is empty).

  *Implementation note*: green: 841 tests, coverage 31 of 31, Pint, PHPStan level 6 and the
  contract lint clean. The production image build and the CI image job's two checks were run
  locally too. The CI breaking-change check pinned `tufin/oasdiff:v1.15.3`, which cannot load the
  shared contract (feature 011's `MailQueueBulkTarget` has a boolean property schema); it now pins
  `v1.32.1`, which reports no breaking change between the contract of 3e10ee0 and this one.
- [X] T114 Run quickstart.md Part A and Part B against the dev stack and confirm every expected
  output. Correct quickstart.md where commands drifted from the implementation, keeping it a
  validation guide.

  *Implementation note (orchestrator decision)*: Part B failed before, because the `__Host-`
  session cookie is always `Secure` and curl never sends it over plain HTTP. The dev stack now has
  an HTTPS listener on `127.0.0.1:18443` (`RESTV2_WEB_TLS_PORT`) with a self-signed certificate
  that the one-shot compose service `certs` generates at stack start into the volume `dev-certs`
  (never committed); plain HTTP on 18080 stays for health checks. Part B uses it with `curl -k`
  and the default cookie name (`.env.example` no longer renames it). Every Part B command was run
  and printed its expected value; the methodId of the TOTP step is now read from
  `GET /session`, and the tinker output is trimmed.
- [ ] T115 Run quickstart.md Part C on a test host with mailcow `2026-09a` (and the FeldHost fork
  if available) before the first release. Check SC-003 (legacy UI, `/api/v1` and SOGo unchanged,
  only `restv2_` tables added), SC-004 (an unmodified fail2ban bans after the same number of failed
  sign-ins through `/api/v2` as through the legacy UI), SC-002 (passkey sign-in under 10 s and a
  first passkey registered and used within 1 minute, by stopwatch) and SC-006 (key created and
  first read within 2 minutes). Record the results in `CHANGELOG.md` under the release.

  *Staging run (2026-10-18), partly done*: Part C ran on maildev.feldhost.cz, the FeldHost fork at
  `153698f` (upstream `2026-09a` plus fork changes, `db_schema` `18082026_1200`), with the
  production image built from 3a35973. `restv2-mailcow` runs from the deployment override, with
  three changes: a local image tag, `pull_policy: never`, and `APP_KEY` plus the FeldHost
  `RESTV2_*` values in a mode-600 `env_file`, because `mailcow.conf` stays untouched.
  `site.restv2.custom` is unchanged and was loaded with `nginx -t` and `nginx -s reload`. No other
  container was restarted. Test accounts were created through legacy `/api/v1` and the legacy UI.
  - SC-003 passes. The legacy UI (admin, domain admin, mailbox user, TOTP), `/api/v1` and
    `/SOGo/so/` behave as before the deployment. Only the four `restv2_` tables were added, and
    `db_schema` is unchanged. IMAP and SMTP sign-ins of the test mailboxes work before and after
    the API operations.
  - SC-004 passes on the API side. Ten failed `/api/v2` sign-ins published ten
    `mailcow UI: Invalid password for <user> by <address>` messages on `F2B_CHANNEL`, the same text
    and count as ten failed legacy sign-ins (`max_attempts` is 10). The throttled 11th attempt
    answered 429 and was published too (R18). An invalid key publishes `API_USER`, as legacy
    `/api/v1` does. The ban itself could not be checked: the host's netfilter container
    crash-loops with a python-iptables error that predates the deployment.
  - SC-002 passes with the virtual authenticator, not by stopwatch. Registration took 69 ms, the
    password sign-in to the first passkey sign-in 262 ms, and a passkey sign-in 40–80 ms. Passkeys
    registered through `/api/v2` also sign in to the legacy UI.
  - SC-006 passes: admin sign-in, key creation and the first read with the key took 196 ms.
  - Also checked: readiness, `/api/v2/docs`, the FeldHost branding, audit records with the real
    client address (an external `X-Forwarded-For` is ignored), recent logins labelled `WEBUI`, and
    no secret in the API logs.

  Still open: the ban on a host with a working netfilter, the passkey stopwatch in a real browser,
  a host on plain upstream `2026-09a`, and the change half of SC-008 (skipped to leave the shared
  announcement alone). Deployment gap: `mcuadros/ofelia` reads labels only when it starts, so
  `restv2_audit_purge` is not registered until `ofelia-mailcow` restarts. Part C step 7 expects the
  job right after `docker compose up -d restv2-mailcow`.

---

## Dependencies and execution order

### Phase dependencies

- **Setup (Phase 1)** has no dependency. T001 → T002 → T003 → T004 come first. T005–T010 and
  T013 can follow in parallel. T011 → T012. T014 needs T009. T015 is last.
- **Foundational (Phase 2)** depends on Setup. Tests T016–T036 come first (T017 needs T016; T019
  needs T020 for `VirtualCredential`), then implementation T037–T052. T037 and T038 come before
  everything that returns errors. T041 and T042 come before T043–T049. T049 comes before T050 and
  T052. **It blocks all user stories.**
- **User stories (Phases 3–8)** all depend on Foundational only:
  - US1, US2, US3, US5 and US6 are independent of each other, thanks to `signInAs()` and
    `MailcowFactory`.
  - US4 changes the middleware of Foundational (T088). Its key-related assertions on other
    stories' operations (T084) need those operations to exist, so run T084 after US1–US3 or mark
    those cases skipped until then.
  - US6's secret scan (T097) exercises flows of US1, US2 and US4, so implement it after them.
- **Polish (Phase 9)** depends on every story. T105 needs all 31 operations. T108–T110 can start
  once the Foundational phase exists.

### Within each story

Tests come first, written and failing. Then models and migrations, then services, then requests,
resources and controllers, then routes. Stop at each checkpoint and run the story's tests.

### Parallel opportunities

- Setup: T005, T006, T007, T008 and T013 in parallel.
- Foundational tests: T016 and T018–T036 in parallel (different files), with T017 after T016.
- Foundational implementation: T041, T042, T046, T047, T048 and T051 in parallel.
- With several developers after Phase 2: US1 (Phase 3), US2 (Phase 4), US3 (Phase 5) and US5
  (Phase 7) in parallel. US4 (Phase 6) and US6 (Phase 8) follow.

## Parallel example: User Story 1

```bash
# All US1 test tasks together (different files):
Task: "T053 LegacyPasswordVerifierTest in tests/Unit/Hashing/LegacyPasswordVerifierTest.php"
Task: "T054 PasswordSignInTest in tests/Feature/Auth/PasswordSignInTest.php"
Task: "T055 PasswordSignInSideEffectsTest in tests/Feature/Auth/PasswordSignInSideEffectsTest.php"
Task: "T056 SignInThrottleTest in tests/Feature/Auth/SignInThrottleTest.php"
Task: "T057 SecondFactorTotpTest in tests/Feature/Auth/SecondFactorTotpTest.php"
Task: "T058 SecondFactorWebAuthnTest in tests/Feature/Auth/SecondFactorWebAuthnTest.php"
Task: "T059 SecondFactorYubicoTest + HttpYubicoOtpVerifierTest"
Task: "T060 PendingSignInLifecycleTest", "T061 SignOutTest"

# Then the independent building blocks together:
Task: "T062 LegacyPasswordVerifier", "T065 Yubico client + NoQrCodeProvider", "T067 Form Requests"
```

## Implementation strategy

### MVP first (User Story 1)

1. Phase 1 Setup, then Phase 2 Foundational.
2. Phase 3 (US1): password sign-in, second factors and sign-out work for every role.
3. **Stop and validate**: run quickstart Part B's US1 commands, and check fail2ban and
   `sasl_log` on the dev stack.

### Incremental delivery

1. US2 (passkeys, the owner's primary sign-in), then US3 (identity, needed by every later feature),
   then US4 (keys).
2. Then US5 (branding) and US6 (audit listing).
3. Polish: health, docs, deployment, authorisation matrix, README and CHANGELOG.

Each story ends with its checkpoint green and the contract coverage growing towards 31 of 31.

## Notes

- Never use `RefreshDatabase`, `migrate:fresh`, `migrate:refresh` or `db:wipe`, because they drop
  mailcow's tables. They are prohibited in code (T040).
- Every upstream write goes through `app/Services/`: `PasswordSignInService` (`tfa`),
  `RecentLoginRecorder` (`sasl_log`) and `PasskeyService` (`fido2`). Controllers never write.
- Commit after each task or logical group, with short imperative subjects and no AI attribution.
