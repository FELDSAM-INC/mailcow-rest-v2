# Research: Authentication, Sessions, API Keys, Identity and Branding Configuration

**Feature**: `specs/001-auth-sessions-api-keys` | **Date**: 2026-10-18 | **Plan**: [plan.md](./plan.md)

Every open point of the plan is settled here, in the precedence order the owner set: legacy
behaviour (constitution IV) first, then the constitution, then best practice. Each entry has a
**Decision**, its **Rationale** and the **Alternatives considered**. Legacy references are paths in
mailcow-dockerized `2026-09a` (`git -C ~/Projects/mailhosting show 2026-09a:<path>`). The FeldHost
fork (`feldhost-202609a`) differs from it, for this feature, only in the `sasl_log` service label
(`WEBUI`), the default product name, the default logos and `OTP_LABEL`. Everything else in
`functions.auth.inc.php`, `sessions.inc.php`, `functions.inc.php`, `json_api.php`,
`init_db.inc.php` and `netfilter/main.py` was diffed and is identical.

---

## 1. Development and test environment

### R1. Docker Compose dev stack

- **Decision**: A `compose.yaml` at the repository root with four services. Tests and every PHP,
  Composer and Node command run inside the `app` container
  (`docker compose run --rm app composer test`).
  - `app`: built from `docker/php/Dockerfile`, target `dev`. Base image `php:8.4-fpm-alpine`,
    with the extensions `pdo_mysql`, `redis` (phpredis 6, PECL), `intl`, `opcache` and `pcov`.
    It adds Composer 2, `nodejs`/`npm` (for the Redocly CLI), `mariadb-client`, `redis` (for
    `redis-cli`) and `git`. The repository is mounted at `/app`.
  - `web`: `nginx:1.28-alpine`, which serves `http://localhost:8080`. Its configuration
    (`docker/nginx/dev.conf`) includes the same location block as the production snippet
    `deploy/nginx/site.restv2.custom`, so the snippet is exercised in development.
  - `mariadb`: `mariadb:10.11`, the image of `mysql-mailcow` in `2026-09a` `docker-compose.yml`.
  - `redis`: `redis:7.4.11-alpine`, the image of `redis-mailcow` in `2026-09a`, started with
    `--requirepass` like mailcow's `redis-conf.sh`.
- **Rationale**: The host has only PHP 8.1 and no Composer, MariaDB or Redis. Constitution VII
  demands MariaDB with the real schema and a real Redis. Pinning the images to mailcow's versions
  makes the tests meaningful for the deployed release. PHP 8.4 satisfies Laravel 13 (8.3+) and is
  supported well beyond upstream's PHP 8.2 end of life on 2026-12-31.
- **Alternatives considered**: Laravel Sail was rejected, because it adds a package and its
  images differ from mailcow's. Testcontainers for PHP was rejected as immature and needing a
  Docker socket inside the test container. Running the tests on the host was rejected, because
  the host PHP is too old and has no database.

### R2. Upstream schema fixture

- **Decision**: `scripts/schema/generate-fixture.sh <tag>` regenerates
  `tests/fixtures/schema/<tag>.sql` from an upstream mailcow tag. The script works in five steps:
  1. It reads `init_db.inc.php` and `docker-compose.yml` of the tag with
     `git -C "$MAILCOW_REPO" show <tag>:<path>`. `MAILCOW_REPO` defaults to `./source_code`. The
     script refuses fork branches and needs a tag.
  2. It starts the tag's `mysql-mailcow` image (`mariadb:10.11` for `2026-09a`) on a throwaway
     Docker network.
  3. In the tag's `php-fpm-mailcow` image (`ghcr.io/mailcow/phpfpm:8.2.29-3`, entrypoint
     overridden to `php`), it runs `scripts/schema/init-db-harness.php`. The harness:
     - cuts the trailing `if (php_sapi_name() == "cli") {…}` block off `init_db.inc.php`;
     - sets `MASTER=y` and the `$MAILBOX_DEFAULT_ATTRIBUTES` defaults of the tag's `vars.inc.php`;
     - opens a PDO connection with the legacy options;
     - calls the unmodified `init_db_schema()`.
  4. It dumps with
     `mariadb-dump --no-data --skip-comments --skip-add-drop-table --routines --triggers`, plus
     `--no-create-info` data for `versions` only. It removes `DEFINER=…` clauses (only the six
     views carry one) and removes `AUTO_INCREMENT=` counters, so that the file is deterministic.
  5. It writes a header with the tag, the `db_version` and the SHA-256 of the source
     `init_db.inc.php`.

  The fixture is committed. A test (`tests/Feature/Schema/SchemaFixtureTest.php`) asserts that
  the loaded schema's `versions.db_schema` is in `config('restv2.supported_schema_versions')`.
- **Verified**: A dry run on 2026-10-18 created 54 base tables and 6 views with
  `db_version = 18082026_1200`, using the upstream tag in the upstream php-fpm image against
  `mariadb:10.11`. Its only output was a harmless warning (`$tls_options` is undefined on an empty
  database). The dump needs `DEFINER` stripping for the views only.
- **Rationale**: The fixture is the real schema, produced by upstream's own code at upstream's own
  PHP version, so it has no phantom columns (constitution I and III). The upstream tag is used,
  not the fork, because the fork's `init_db.inc.php` differs only in a default DKIM selector, and
  supported releases are upstream releases. The script makes the next release a one-line change:
  `generate-fixture.sh 2026-11` plus adding its `db_version` to the supported list.
- **Alternatives considered**: Hand-written migrations mirroring upstream were rejected: they
  drift, and the constitution forbids migrations of upstream tables. Dumping a live mailcow was
  rejected as not reproducible and possibly containing personal data. Running the harness in the
  dev image (PHP 8.4) was rejected, because upstream runs PHP 8.2 and deprecation behaviour could
  differ.

### R3. Running tests on MariaDB and Redis

- **Decision**:
  - `tests/bin/prepare-database.sh` (run by `composer test` before PHPUnit) works in four steps.
    It recreates the test database `restv2_test`, loads `tests/fixtures/schema/${MAILCOW_SCHEMA:-2026-09a}.sql`,
    runs `php artisan migrate --env=testing --force` (which creates only the `restv2_` tables) and
    loads nothing else.
  - Feature tests use `Illuminate\Foundation\Testing\DatabaseTransactions` on the single `mysql`
    connection. Each test seeds its own rows through `tests/Support/Mailcow/MailcowFactory.php`,
    which inserts upstream rows exactly as legacy does: hashes in their scheme, `fido2` binary
    columns, `tfa` secrets, and `user_acl`/`da_acl` rows with their defaults.
  - `RefreshDatabase`, `migrate:fresh`, `migrate:refresh`, `db:wipe` and `migrate:reset` are never
    used. `AppServiceProvider` calls `DB::prohibitDestructiveCommands()` in every environment.
    These commands drop every table of the database, including mailcow's.
  - Redis tests use database index 15 (`REDIS_DB=15` in `.env.testing`). `tests/TestCase.php`
    runs `FLUSHDB` on that index in `setUp()`, and the flush refuses to run unless
    `app()->environment('testing')` holds and the index is 15.
  - Fail2ban side effects are asserted on the real Redis. `tests/Support/Redis/ChannelSpy.php`
    runs `redis-cli SUBSCRIBE F2B_CHANNEL` through `Symfony\Component\Process\Process`, waits for
    the subscription confirmation and collects the published messages.
  - The Yubico verification service is the only outbound service of this feature. It is faked
    behind `App\Clients\Yubico\YubicoOtpVerifier`, and the HTTP adapter is tested with `Http::fake()`.
  - WebAuthn uses the real library. `tests/Support/WebAuthn/VirtualAuthenticator.php` creates EC
    P-256 key pairs with OpenSSL. It builds `clientDataJSON`, `authenticatorData` with the UP, UV
    and AT flags, CBOR attestation objects (format `none`, with a minimal CBOR encoder in the same
    directory) and ES256 assertions.
  - Every feature-test request and response is validated against the contract (R13).
- **Rationale**: This follows constitution VII literally: MariaDB with the real schema, real
  Redis, and fakes only for external services. Transactions keep the tests fast and isolated
  without DDL.
- **Alternatives considered**: SQLite was rejected (forbidden; the schema needs MariaDB views and
  JSON). Faking Redis pub/sub was rejected (constitution VII: Redis runs for real). Database-per-test
  was rejected as too slow.

---

## 2. Application structure and storage

### R4. Laravel 13 scaffold

- **Decision**: Laravel 13 (`laravel/framework ^13.0`, `laravel/laravel` skeleton) at the
  repository root. Sail and Pail are removed. There is no frontend tooling. Routes live in
  `routes/api.php` with the prefix `api/v2` and a dedicated middleware group `api.v2`
  (`bootstrap/app.php`). PHPUnit 12 is used, as shipped with the skeleton (the constitution asks
  for 11 or newer).
- **Rationale**: This is the constitution's stack. The skeleton's own dependencies count as
  "Laravel's own features" (constitution VIII).
- **Alternatives considered**: Lumen was rejected (discontinued). Slim was rejected: it was not
  the owner's choice, and upstream contributors know Laravel better.

### R5. One database connection, API-owned table prefix `restv2_`

- **Decision**: There is one MariaDB connection, `mysql`, with no global table prefix. It reads
  and writes the upstream tables by their real names and the API-owned tables by their explicit
  `restv2_` names:
  - `restv2_api_keys`, `restv2_audit_events`, `restv2_preferences`;
  - the migrations table `restv2_migrations` (`config('database.migrations.table')`).

  Models of API-owned tables set `$table = 'restv2_…'` explicitly. A test
  (`tests/Unit/Architecture/MigrationPrefixTest.php`) parses every file in `database/migrations/`
  and fails if `Schema::create`, `Schema::table`, `Schema::drop*` or `Schema::rename` names a table
  without the prefix.

  The connection does not set `time_zone`, so that `NOW()` defaults in upstream tables match what
  legacy writes (R16).
- **Rationale**:
  - No upstream table or view starts with `restv2_` (checked against the 54 tables and 6 views of
    `2026-09a`). The name is specific to this project, so a future upstream table is unlikely to
    collide with it.
  - A single connection lets one transaction cover an upstream write (`tfa`, `sasl_log`, `fido2`)
    and its audit record, which the spec requires: "Audit record cannot be written → operation
    does not succeed".
  - A Laravel connection prefix would also prefix the upstream tables.
- **Alternatives considered**: Two connections, one prefixed, were rejected, because no
  transaction can span both connections. The prefix `api_` was rejected: it is too close to
  upstream's `api` table and too generic for the future. A separate database was rejected: it needs
  extra grants on mailcow's MariaDB user and breaks atomicity.

### R6. Sessions

- **Decision**:
  - **Store**: The Laravel `redis` session driver runs on the Redis connection `restv2`, which
    points to mailcow's Redis (`redis-mailcow:6379`, password `REDISPASS`, or
    `REDIS_SLAVEOF_IP`/`REDIS_SLAVEOF_PORT` like legacy). Its key prefix is `restv2:`, and every
    API-owned Redis key starts with it. Legacy PHP sessions use phpredis's `PHPREDIS_SESSION:`
    prefix in the same database 0, so the two never collide (FR-013, constitution V).
  - **Cookie**: `__Host-restv2_session`, with `Secure`, `HttpOnly`, `SameSite=Strict`, `Path=/` and
    no `Domain`. `config('session.secure')` is always `true`, independent of the request scheme. The
    name can be changed with `SESSION_COOKIE` for local development only.
  - **Idle lifetime**: mailcow's `$SESSION_LIFETIME` (R14, default 10800 s), applied as the Redis
    TTL that every request renews. `EnsureSessionIntegrity` also checks a stored `lastActivity`
    to the second (FR-009).
  - **User-agent pinning**: The SHA-256 of `User-Agent` is stored at sign-in. A mismatch
    invalidates the session and answers `401 unauthenticated` (FR-012, legacy
    `$_SESSION['SESS_REMOTE_UA']`).
  - **Identifier renewal**: `$request->session()->migrate(true)` runs after the password step,
    after the second-factor step and after a passkey sign-in (FR-008). Sign-out calls
    `invalidate()`, which also issues a new CSRF token (FR-010).
  - **Contents** (see [data-model.md](./data-model.md#session-redis)): the principal (username,
    role, account kind, method, `signedInAt`, UA hash), the pending sign-in, the passkey challenge,
    the passkey registration options and the CSRF token.
  - **API-key requests** never start a session. `AuthenticateApiKey` runs before `StartSession`
    and marks the request, and a custom `StartSessionUnlessApiKey` skips the session for marked
    requests (FR-038).
- **Rationale**: Redis is mailcow's session store already, and it gives expiry for free. A
  `__Host-` cookie cannot be set or shadowed by sibling hosts. `SameSite=Strict` works for a
  same-origin SPA whose HTML is static, and it adds defence in depth to CSRF tokens.
- **Alternatives considered**: SQL sessions were rejected: they need a table and a purge, and they
  add load to MariaDB. A separate Redis logical database was rejected: the branding keys and
  `F2B_CHANNEL` live in database 0 anyway, and a prefix isolates the keys just as well with one
  connection pool. `SameSite=Lax` (legacy) was rejected: it is not needed, because no top-level
  cross-site navigation needs the cookie. Sanctum SPA authentication was rejected: it adds a
  package for what the session guard plus a token check already do.

### R7. CSRF scheme

- **Decision**: A synchronizer token bound to the session, sent in the `X-CSRF-Token` header.
  - `GET /api/v2/csrf-token` starts an anonymous session if needed and returns the token.
  - `App\Http\Middleware\VerifyCsrfToken` extends Laravel's `ValidateCsrfToken`. It changes four
    things:
    - It applies to every method except `GET`, `HEAD` and `OPTIONS`, whatever the content type.
    - It is skipped only for requests authenticated by an API key.
    - It never sets the `XSRF-TOKEN` cookie (`$addHttpCookie = false`) and accepts only the header.
    - A failure renders `403 /api/v2/problems/csrf-token-mismatch`.
  - **Sign-in is covered**: `POST /session`, the passkey challenge and assertion, and all
    pending-sign-in operations need the token. That makes login CSRF impossible (FR-011).
  - `DELETE /session` without a live session is a `204` no-op and needs no token. A forged sign-out
    of a non-existent session cannot do anything (FR-010). A *live session* is a session record that
    exists in Redis and has not expired, whether anonymous (it holds only the CSRF token or a
    challenge) or signed in. With a live session the token is required, and the client has it.
  - As defence in depth, unsafe requests whose `Sec-Fetch-Site` header is `cross-site` are refused
    with the same problem. Same-origin and same-site requests, and requests without the header,
    pass on to the token check.
  - The token survives sign-in: `migrate()` keeps the session data. It is replaced only when the
    session ends.
- **Rationale**: Legacy checks the token only on form posts, and JSON writes skip it (spec parity
  table). The spec requires a token on every state-changing session request. A header-only token
  cannot be sent cross-site without CORS, and the API sends no CORS headers. Keeping the token
  across sign-in avoids a second bootstrap, while the renewed session identifier blocks fixation
  (OWASP CSRF cheat sheet: a per-session token is sufficient).
- **Alternatives considered**: The double-submit `XSRF-TOKEN` cookie was rejected, because it
  needs a script-readable cookie and the UI constitution forbids script-readable credentials in
  storage. Checking only `Origin` was rejected: some privacy proxies strip it. Rotating the token
  on every request was rejected: it breaks parallel requests.

### R8. Request pipeline and order of checks

- **Decision**: The `api.v2` middleware group runs in this order. The first failure answers.
  1. `AssignRequestId`: parses a W3C `traceparent` or creates a trace ID, and puts it in the log
     context and in the problem `requestId`.
  2. `AuthenticateApiKey`: if `X-API-Key` is non-empty, it resolves the key (R19). An invalid,
     expired or revoked key, or a disallowed address, answers `401` or `403` with its own type and
     is published to fail2ban. The System routes skip this step: health, the contract, the docs
     page and the problem pages (route names `system.*`).
  3. `EnforceKeyScope`: a read-only key on any method other than `GET` and `HEAD` answers
     `403 insufficient-key-scope`, before any other authorisation. The access level is a property
     of the key, and no unsafe operation of feature 001 is open to keys, so this is the only place
     where FR-039's "insufficient key scope" can be observed and tested.
  4. `StartSessionUnlessApiKey`.
  5. `EnsureSessionIntegrity` (sessions only): idle timeout, UA pinning, and account still
     existing and active. Any failure invalidates the session.
  6. Route middleware `restv2.auth:<mode>` (`RequireAuth`), with four modes:
     - `public`: anyone, keys included. Used by the public configuration, logos, docs and problem
       pages.
     - `browser`: anonymous or signed-in browser sessions. Key requests get
       `403 session-required`. Used by `GET /csrf-token`, `GET`, `POST` and `DELETE /session`, the
       pending-sign-in operations, and the passkey challenge and assertion.
     - `session`: a signed-in session only. No session gives `401 unauthenticated`, a pending
       sign-in gives `401 second-factor-required`, and a key gives `403 session-required`. Used by
       preferences, passkey management, and creating and revoking keys.
     - `authenticated`: a signed-in session or a key. Without either the answer is
       `401 unauthenticated` (or `second-factor-required`). Used by `GET /me`, listing and reading
       keys, and listing audit records.

     Key principals are never restricted by pending actions: FR-014 restricts sessions only, and
     `Identity.pendingActions` is `[]` for a key.
  7. `VerifyCsrfToken` (sessions, unsafe methods): `403 csrf-token-mismatch`. It runs after step
     6, so that a request without a live session on a protected route gets `401`, not a CSRF
     error. On public unsafe routes (sign-in, passkey challenge and assertion, pending-sign-in
     operations) it applies to the anonymous session.
  8. `EnforcePendingActions` (route middleware `restv2.pending:<mode>`): a restricted session
     answers `403 pending-action-required`, except on routes with mode `allow` (every `public` and
     `browser` route, and `GET /me`) and with mode `secondFactorSetup` (passkey registration
     options and registration, allowed only when that is the sole pending action). The default
     mode is `enforce`.
  9. Policies or gates in controllers: `403 forbidden`, and `404` for objects outside the caller's
     scope.
  10. Form Request validation: `422 validation-failed`.

- **Rationale**: Each step maps to exactly one documented problem type. The key is checked first
  because the key decides when both a key and a cookie are present (spec edge case). Checking the
  key on every route is legacy parity: `sessions.inc.php` checks `X-API-Key` on every request and
  reports bad keys.
- **Alternatives considered**: Using Laravel's `auth` and `guest` middleware directly was
  rejected: they answer with redirects or framework exceptions and cannot express pending
  sign-ins.

---

## 3. Credentials and second factors

### R9. WebAuthn and passkeys: `lbuchs/webauthn`

- **Decision**: `lbuchs/webauthn ^2.2` (MIT), the library legacy vendors in
  `data/web/inc/lib/WebAuthn/`. `App\Support\WebAuthn\WebAuthnServerFactory` builds
  `new \lbuchs\WebAuthn\WebAuthn($rpName, $rpId, $formats, true)`, which uses base64url in JSON.
  - **rpId**: the request host, lower-cased without the port, like legacy's
    `parse_url('https://' . $_SERVER['HTTP_HOST'], PHP_URL_HOST)`. Credentials therefore stay bound
    to the host name they were registered on.
  - **rpName**: the branding `productName` (R15). Legacy uses `'WebAuthn Library'`. The name is
    display-only and does not affect verification.
  - **Formats**: mailcow's `$FIDO2_FORMATS`. When `WEBAUTHN_ONLY_TRUSTED_VENDORS=y`, the API adds
    the root certificates that legacy ships, copied into `resources/webauthn/rootCertificates/`.
  - **Legacy storage, read and written identically**:
    - `fido2`: `credentialId` holds the raw credential ID bytes (BLOB). `credentialPublicKey`
      holds the PEM public key (`AuthenticatorData::getPublicKeyPem()`). `rpId` holds the host.
      `AAGUID` holds the raw 16 bytes. `certificateChain`, `certificate`, `certificateIssuer` and
      `certificateSubject` come from `processCreate()`, and `signatureCounter` holds the counter at
      registration. `friendlyName` is `NULL` unless a name is given, `active` is 1, and `created`
      is the database default. The non-secret ID is `SHA2(credentialId, 256)`, the legacy `cid`.
    - `tfa` (`authmech = 'webauthn'`, read only in this feature): `keyHandle` is the base64
      (standard alphabet) of the credential ID, and `publicKey` is the PEM key.
  - **Registration** (FR-052) mirrors legacy `fido2-registration`. It calls
    `getCreateArgs(userId = username, name = username, displayName = username, timeout = 300,
    requireResidentKey = true, uv = $FIDO2_UV_FLAG_REGISTER, crossPlatformAttachment = null,
    exclude = the account's fido2 credential IDs)`. Then `processCreate(..., $FIDO2_UV_FLAG_REGISTER,
    $FIDO2_USER_PRESENT_FLAG)`, and the row is inserted exactly as `fido2('register')` does. A
    credential ID that is already in `fido2` is rejected (`passkey-registration-failed`).
  - **Passkey sign-in** (FR-015) mirrors `fido2-get-args`. It calls `getGetArgs(null, 300, false,
    false, false, false, $FIDO2_UV_FLAG_LOGIN)`, which gives an empty `allowCredentials`. It looks
    the row up by `credentialId` equal to the raw `rawId`, verifies with
    `processGet(..., pem, challenge, null, $FIDO2_UV_FLAG_LOGIN, $FIDO2_USER_PRESENT_FLAG)` and
    then applies the checks of FR-016 that legacy lacks.
  - **Second-factor WebAuthn** mirrors `webauthn-tfa-get-args`. Its `allowCredentials` are the
    account's active `tfa` webauthn key handles, `extensions.appid = "https://<rpId>"`, and it uses
    `$WEBAUTHN_UV_FLAG_LOGIN` and `$WEBAUTHN_USER_PRESENT_FLAG`. Verification looks the row up by
    `keyHandle = base64(rawId)` among the pending account's active webauthn rows.
  - **User-verification semantics**: The options carry the configured value (`required`,
    `preferred`, `discouraged`, or a boolean mapped to `required`/`preferred`). Verification passes
    the configured value to lbuchs unchanged, as legacy does. lbuchs requires the UV flag whenever
    that value is truthy. With the default `'preferred'` (a non-empty string), legacy therefore
    effectively *requires* UV for passkey sign-in and registration. The API reproduces this
    exactly. WebAuthn second factors (default `false`) do not require it.
  - **Signature counter**: not enforced and not updated, as in legacy (`processGet(..., null, ...)`;
    legacy never updates `signatureCounter`). Synced passkeys report 0 anyway.
  - **Challenges**: single-use, stored in the session with `expiresAt = now + 300 s`, and removed
    on the first verification attempt, successful or not (FR-017, FR-052). The options' `timeout`
    is 300000 ms, where legacy sends 30 s (a parity row in the spec records it).
  - **JSON shape**: `App\Support\WebAuthn\OptionsJson` normalises lbuchs's argument objects to
    the contract's WebAuthn Level 3 JSON. It always emits `allowCredentials` (an empty array when
    there are no IDs, where lbuchs omits the member), adds `residentKey: "required"` next to
    `requireResidentKey: true` (the legacy copy of lbuchs sets only the latter), drops lbuchs's
    private `extensions.exts`, and keeps `extensions.appid` for the second-factor challenge. Unit
    tests pin this mapping against the installed lbuchs version.
- **Rationale**: The same library guarantees byte-compatible storage and identical verification
  semantics, so credentials work in both UIs in both directions (SC-001, SC-003). MIT is
  compatible with GPL-3.0. The library is small, has no dependencies and supports PHP 8.
- **Alternatives considered**: `web-auth/webauthn-lib` was rejected. It is heavy (Symfony
  serializer, PSR-17 factories) and stores COSE keys, so it would need PEM conversion and a second
  storage format. Hand-written verification was rejected because of the security risk.

### R10. TOTP: `robthree/twofactorauth`

- **Decision**: `robthree/twofactorauth ^3.0` (MIT), the library legacy uses (1.8.1 there). It is
  configured like legacy's `new TwoFactorAuth($OTP_LABEL, 6, 30, 'sha1')`. Version 3 requires a QR
  provider, so `App\Support\Totp\NoQrCodeProvider` throws if it is ever used. Feature 005 brings
  QR codes. `verifyCode($secret, $code, 1, null, $timeslice)` uses legacy's default discrepancy of
  1 (±30 s). The method is selected by `methodId` among the account's active `totp` rows, as
  legacy selects by `id`.
- **Replay protection (FR-023)**: After a successful verification, Redis key
  `restv2:totp:last:<tfa.id>` stores the matched timeslice with a TTL of 120 s. A code whose
  timeslice is less than or equal to the stored one is rejected (`second-factor-failed`). The
  check-and-set uses a Lua script, so that concurrent requests cannot both pass.
- **Rationale**: The same library and parameters give identical acceptance, including its base32
  handling of secrets created in the legacy UI. The `$timeslice` output parameter makes replay
  tracking exact.
- **Alternatives considered**: A hand-written RFC 6238 implementation was rejected: it is small,
  but it risks base32 and edge-case differences from legacy-enrolled secrets. Replay tracking in
  SQL was rejected, because upstream tables cannot get new columns.

### R11. Yubico OTP

- **Decision**: No package. `App\Clients\Yubico\HttpYubicoOtpVerifier` implements
  `YubicoOtpVerifier` and ports the verification logic of legacy `inc/lib/Yubico.php`
  (Auth_Yubico 2.0, BSD) onto Laravel's HTTP client:
  - It selects the key by the OTP's first 12 characters (the modhex public ID) among the account's
    active `yubi_otp` rows, matching `secret LIKE '%<modhex>'`. The secret format is
    `<client id>:<base64 key>:<modhex>`.
  - It sends `GET https://api.yubico.com/wsapi/2.0/verify?id=…&otp=…&nonce=<32 random hex>` with
    the parameter signature `h` (HMAC-SHA1 over the sorted query, keyed with the base64-decoded
    key). The timeout is 5 s. The URL can be changed with `RESTV2_YUBICO_VERIFY_URL`.
  - It accepts only `status=OK` with a valid response signature `h` and an echoed `otp` and `nonce`.
  - `REPLAYED_OTP`, `BAD_OTP`, `BAD_SIGNATURE`, `NO_SUCH_CLIENT`, a missing key row or a malformed
    OTP become `second-factor-failed`.
  - A connection error, a timeout, HTTP 5xx or `BACKEND_ERROR` become
    `temporary-verification-failure` (`503`, `Retry-After: 30`). The pending sign-in stays open and
    nothing is counted.
- **Rationale**: The legacy class depends on PEAR error objects and globals. A port is about 80
  lines, testable with `Http::fake()`, and needs no dependency (constitution VIII). It keeps
  legacy's per-key credentials (FR-022).
- **Alternatives considered**: `enygma/yubikey` was rejected as unmaintained. Vendoring
  `Yubico.php` was rejected, because of the PEAR dependency and because it cannot be faked
  cleanly.

### R12. Port of `verify_hash`

- **Decision**: `App\Support\Hashing\LegacyPasswordVerifier::verify(string $hash, string $password): bool`
  is a line-by-line port of legacy `verify_hash()` (`functions.inc.php`). The input is
  `{SCHEME}hash`, and the scheme is matched case-insensitively.
  - `ARGON2I`, `ARGON2ID`, `BLF-CRYPT`, `CRYPT`, `DES-CRYPT`, `MD5-CRYPT`, `MD5`, `SHA256-CRYPT` and
    `SHA512-CRYPT` use `password_verify`.
  - `CLEAR`, `CLEARTEXT` and `PLAIN` use loose string equality, as legacy does.
  - `LDAP-MD5` compares a base64 raw MD5.
  - `PBKDF2` uses the `$`-separated sha1 form. `PBKDF2-SHA512` uses the FreeIPA form
    `iterations$b64salt$b64hash`.
  - `PLAIN-MD4`, `PLAIN-MD5` and `PLAIN-TRUNC` are supported.
  - `SHA`, `SHA1`, `SHA256` and `SHA512` compare a base64 raw digest.
  - `SMD5`, `SSHA`, `SSHA256` and `SSHA512` use the salted form, with legacy's exact salt
    extraction (`str_replace` of the digest prefix).
  - Any other scheme, or no `{SCHEME}` prefix, returns `false`.
  - The verifier never rehashes, and the sign-in never writes `password` (FR-004).

  For an unknown account, the sign-in still verifies the password against a fixed dummy
  `BLF-CRYPT` hash, so that its timing does not reveal whether the account exists (FR-005, ASVS
  2.2).
- **Rationale**: Parity with every format Dovecot and legacy accept (SC-001). The spec's list is
  a summary, and the code is the reference. All primitives are PHP built-ins (`hash`, `hash_pbkdf2`,
  `hash_equals`, `password_verify`, and `md4` in ext/hash).
- **Test vectors**: `tests/Unit/Hashing/LegacyPasswordVerifierTest.php` covers each scheme with a
  correct and a wrong password. The vectors are generated with `doveadm pw -s <scheme>` in the
  `ghcr.io/mailcow/dovecot` image of `2026-09a`, and are recorded with the generating command. The
  test also asserts that the verifier's result equals the legacy function's for every vector.
  `scripts/legacy/extract-verify-hash.sh <tag>` copies the legacy `verify_hash()` and
  `verify_salted_hash()` of the tag, renamed to `legacy_verify_hash()` and
  `legacy_verify_salted_hash()`, into `tests/fixtures/legacy/verify_hash.php`, with the tag and the
  source hash in a header comment. Both projects are GPL-3.0.

### R13. Contract validation in feature tests

- **Decision**: `opis/json-schema ^2.6` (Apache-2.0, JSON Schema 2020-12, the OpenAPI 3.1 dialect)
  plus a small in-repository matcher, `tests/Support/Contract/OpenApiContract.php`.
  - `composer contract:bundle` runs `npx --yes @redocly/cli@2.64.1 bundle api/openapi.yaml
    --dereferenced --ext json -o build/contract/openapi.json`. It also writes
    `build/contract/openapi.yaml` without `--dereferenced`, which is the release artifact served at
    `GET /api/v2/openapi.yaml`.
  - The matcher finds the operation by method and templated path (literal segments win over
    templates). It validates path, query and header parameters, the request body for its content
    type, and that the status is documented. It checks the documented required response headers
    (`Location`, `ETag`, `Retry-After`, `Cache-Control`) and validates the JSON or problem+json body
    against the schema.
  - `tests/TestCase.php` overrides `call()` so that every test request is validated. Response
    validation cannot be turned off. Request validation can be turned off per call with
    `$this->withoutRequestValidation()`, but only in tests whose name contains `Invalid` or
    `Malformed`, which deliberately send contract-violating input.
  - The PHPUnit extension `tests/Support/Contract/ContractCoverageExtension.php`, registered in
    `phpunit.xml`, records every operation answered with a documented 2xx or 3xx status. When the
    run finishes, it writes `build/contract/coverage.json`. `composer test` then runs
    `php tests/bin/check-contract-coverage.php`, which fails when any operation of the contract was
    never covered.
- **Rationale**: `league/openapi-psr7-validator` 0.24 relies on `devizzent/cebe-php-openapi`,
  whose OpenAPI 3.1 support is incomplete (type arrays, `const`, `oneOf` with `type: 'null'`).
  Opis implements the exact JSON Schema dialect of OpenAPI 3.1. Apache-2.0 is GPL-3.0-compatible.
- **Alternatives considered**: `osteel/openapi-httpfoundation-testing` was rejected: it is the same
  league validator underneath. Spectral or Prism as a proxy in tests was rejected: it adds a Node
  process in the test path and is slower.

---

## 4. mailcow configuration and branding

### R14. Reading mailcow configuration

- **Decision**:
  - **Environment variables reuse `mailcow.conf` names**: `MAILCOW_HOSTNAME`, `TZ`, `DBNAME`,
    `DBUSER`, `DBPASS`, `REDISPASS`, `REDIS_SLAVEOF_IP`, `REDIS_SLAVEOF_PORT`, `IPV4_NETWORK`,
    `IPV6_NETWORK`, `WEBAUTHN_ONLY_TRUSTED_VENDORS`, `SKIP_SOGO` (default `n`, for app links) and
    `ADDITIONAL_SERVER_NAMES`. `DBHOST`
    defaults to `mysql-mailcow`, and `REDISHOST` (an existing name) to `redis-mailcow`.
  - **New settings** use the prefix `RESTV2_`:

    | Variable | Default | Meaning |
    |---|---|---|
    | `RESTV2_APP_KEY` | — (required) | Laravel `APP_KEY` |
    | `RESTV2_SASL_LOG_SERVICE` | `MAILCOWUI` | `sasl_log.service` label (FR-027; FeldHost: `WEBUI`) |
    | `RESTV2_DEFAULT_UI_NAME` | empty → `<lower(MAILCOW_HOSTNAME)> - mail UI` | Default title and product name (FR-043; FeldHost: `FeldHost™ Mailhosting UI`) |
    | `RESTV2_DEFAULT_LOGO_URL` | `/img/cow_mailcow.svg` | Default light logo (FeldHost: `/img/feldhost_logo.png`) |
    | `RESTV2_DEFAULT_LOGO_DARK_URL` | `/img/cow_mailcow.svg` | Default dark logo (FeldHost: `/img/feldhost_logo_white.png`) |
    | `RESTV2_SIGNIN_MAX_FAILURES` | `10` | Throttling threshold (FR-007) |
    | `RESTV2_SIGNIN_FAILURE_WINDOW` | `900` | Throttling window in seconds |
    | `RESTV2_CHALLENGE_TTL` | `300` | Pending sign-in, challenge and registration-option lifetime |
    | `RESTV2_AUDIT_RETENTION_DAYS` | `365` | Audit retention (FR-048) |
    | `RESTV2_YUBICO_VERIFY_URL` | `https://api.yubico.com/wsapi/2.0/verify` | Yubico endpoint |
    | `RESTV2_MAILCOW_INC_DIR` | `/mailcow/web/inc` | Mount point of mailcow's `data/web/inc` |

  - **`vars.inc.php` and `vars.local.inc.php`**: The override mounts mailcow's `./data/web/inc`
    read-only at `/mailcow/web/inc`. `App\Services\Config\MailcowConfig` obtains the values by
    running `php -n -d display_errors=stderr resources/bin/export-mailcow-vars.php <dir>` as a
    subprocess (`Symfony\Component\Process\Process`, 5 s timeout). The script reproduces legacy's
    include sequence from `prerequisites.inc.php`:
    1. It sets `$_SERVER['HTTP_HOST']` to `MAILCOW_HOSTNAME`, which `vars.inc.php` reads.
    2. It includes `vars.inc.php`, then `vars.local.inc.php` if it exists.
    3. It prints JSON with `SESSION_LIFETIME`, `DEFAULT_LANG`, `AVAILABLE_LANGUAGES`,
       `FIDO2_UV_FLAG_REGISTER`, `FIDO2_UV_FLAG_LOGIN`, `FIDO2_USER_PRESENT_FLAG`, `FIDO2_FORMATS`,
       `WEBAUTHN_UV_FLAG_LOGIN`, `WEBAUTHN_USER_PRESENT_FLAG`, `OTP_LABEL` and `MAILCOW_APPS`.

    **Precedence**, per value: an explicit API server setting `RESTV2_<legacy variable name>`
    (for example `RESTV2_SESSION_LIFETIME`, or `RESTV2_MAILCOW_APPS` and
    `RESTV2_AVAILABLE_LANGUAGES` as JSON) wins. Otherwise the value read from the mounted legacy
    files applies. Otherwise the built-in default of upstream `2026-09a` applies: 10800, `en-gb`,
    the 31 languages, `'preferred'`, `'preferred'`, `true`, the seven formats, `false`, `true`,
    `mailcow UI` and the single Webmail entry (`/SOGo/so`, hidden on the login page).

    **Freshness**: the exported JSON is cached in Redis under a key that contains a fingerprint of
    the two files (path, size, modification time and inode, from `stat()` on every request), so any
    change to the files is picked up by the next request without a time-based cache
    (`docs/conventions.md`: data is read on every request). When the directory is missing, or the
    script fails or times out, the defaults apply and a warning is logged once per fingerprint.
    Laravel's `session.lifetime` (minutes) is set from `SESSION_LIFETIME` at boot, rounded up.

    The mount is optional. `RESTV2_*` settings are authoritative, and the plan's Complexity
    Tracking records reading the files as a deviation from "configuration comes from environment
    variables".

    **Relation to `docs/conventions.md`**: the conventions make legacy PHP configuration "API
    server settings with upstream defaults that the operator keeps aligned". This design keeps that
    rule, because every value is a `RESTV2_*` server setting with the upstream default. It also
    satisfies spec 001's assumption that the values "come from the operator's existing mailcow
    configuration, including local overrides", by reading the deployed files when they are mounted.
    Later features can reuse `MailcowConfig` for their own legacy variables, so that operators
    need not copy values.
- **Rationale**: The operator keeps configuring mailcow in one place: `mailcow.conf` plus
  `vars.local.inc.php`. A subprocess evaluates the operator's PHP exactly like legacy does, without
  polluting the API process. `vars.inc.php` calls `error_reporting(E_ERROR)` and defines globals.
  Reading the deployed `vars.inc.php` keeps the upstream and fork defaults right automatically.
- **Alternatives considered**: An in-process `include` was rejected because of global and
  error-reporting side effects. Parsing the PHP files with a parser was rejected: operators may
  compute values. Duplicating every value as an environment variable was rejected: it creates a
  second configuration place and drifts from `vars.local.inc.php`.

### R15. Branding from Redis

- **Decision**: `App\Services\Branding\PublicConfigService` reads on every request, with no
  cache, from the Redis connection `mailcow` (database 0, no prefix). It is read-only. Unlike
  legacy, it does not perform the `UI_IMPRESS` → `UI_FOOTER` migration write.

  | Field | Redis key | Conversion and default |
  |---|---|---|
  | `pageTitle` | `TITLE_NAME` | `htmlspecialchars_decode(ENT_QUOTES)` (legacy stores it encoded and renders `|raw`); default per R14 |
  | `productName` | `MAIN_NAME` | same |
  | `loginHelpHtml` | `HELP_TEXT` | as stored, `null` when empty |
  | `footerHtml` | `UI_FOOTER`, else `UI_IMPRESS` | as stored, `null` when empty |
  | `announcement.text` | `UI_ANNOUNCEMENT_TEXT` | `null` when empty |
  | `announcement.type` | `UI_ANNOUNCEMENT_TYPE` | `info`, `warning`, `danger`, else `null` |
  | `announcement.active` | `UI_ANNOUNCEMENT_ACTIVE` | `== 1` |
  | `logos.light` / `logos.dark` | `MAIN_LOGO` / `MAIN_LOGO_DARK` | data URI `data:<mime>;base64,<data>`. When set, `url = /api/v2/public-config/logos/<variant>?v=<first 16 hex of sha256(value)>`; otherwise the default URL (R14) with `isDefault: true` |
  | `loginPage.*` | `CUSTOM_LOGIN` (JSON) | `hide_admin_quicklink`, `hide_domainadmin_quicklink`, `hide_user_quicklink`, `force_sso` `== 1`; all `false` when unset |
  | `languages`, `defaultLanguage` | — | `AVAILABLE_LANGUAGES` and `DEFAULT_LANG` (R14) |
  | `externalIdentityProvider.configured` | table `identity_provider` | `true` when the `authsource` row is `keycloak`, `generic-oidc` or `ldap` |
  | `appsLabel` | `APPS_NAME` | `htmlspecialchars_decode(ENT_QUOTES)`; `null` when unset (legacy falls back to its translated "Apps", which the client translates instead) |
  | `appLinks` (built-in) | `$MAILCOW_APPS` (R14) | In array order. `name`, `url` = `link`, `signedInUrl` = `user_link` or else `link`, `description`, `showOnLoginPage` = `!hide`, `source: builtIn`. With `SKIP_SOGO=y`, entries whose `link` or `user_link` matches `/SOGo/i` are left out (legacy `is_uri('SOGo', …)`) |
  | `appLinks` (operator) | `APP_LINKS` (JSON list of one-key maps `{name: {link, user_link, hide}}`) | After the built-in apps, in stored order. Old-style entries `{name: "address"}` become `link = user_link = address`, `hide = false`, as legacy's `customize('get', 'app_links')` converts them. `user_link` empty → `link`. `description: null`, `source: operator` |

  `GET /public-config/logos/{variant}` decodes the data URI. It serves only the MIME types legacy
  accepts on upload (`image/gif`, `image/jpeg`, `image/pjpeg`, `image/png`, `image/x-png`,
  `image/svg+xml`), normalising `image/pjpeg` and `image/x-png`, and it sets a sandboxing CSP so
  that SVGs cannot run scripts. The public configuration has a strong `ETag` and
  `Cache-Control: no-cache`, so changes are visible on the next request (FR-043: within one minute).
  Legacy's header uses `link` instead of `user_link` for `$MAILCOW_APPS` entries, because
  `header.inc.php` tests `$app_links_processed[$i]['user_link']` by mistake. The API returns the
  configured `user_link`, as recorded in the spec's parity table. `%u` is never substituted by the
  API, so that the public configuration stays free of account data (FR-044).
- **Rationale**: Legacy keys and defaults make the branding identical in both UIs (US5). Reading
  without a cache takes about 12 Redis GETs, a few milliseconds. Logos can reach 15 MB
  (`$LOGO_LIMITS`), so they are served separately, not inlined into JSON.
- **Alternatives considered**: Inlining data URIs was rejected because of the payload size. A
  server cache of up to 60 s was rejected: it is not needed and makes tests time-dependent.

---

## 5. Sign-in semantics (legacy parity details)

### R16. Accounts, resolution and side effects

- **Decision**: `App\Services\Auth\AccountResolver` and `PasswordSignInService` port
  `check_login`, `admin_login`, `domainadmin_login` and `user_login`.
  - **Normalisation**: The username is trimmed and lower-cased (the triggers do it before
    `check_login`). A username that is neither a valid e-mail address
    (`FILTER_VALIDATE_EMAIL`) nor alphanumeric after removing `_.-` is malformed. It gets the
    generic failure, counts towards throttling and is published to fail2ban, as in legacy. A
    username longer than 255 characters is refused by the contract with `422` before any check: no
    mailcow account can have one (`VARCHAR(255)`), so nothing is counted or published.
  - **Order without role**: admin (`admin.superadmin = 1 AND active = 1`), then domain admin
    (`superadmin = 0 AND active = 1`), then mailbox user. The first account whose password verifies
    wins. The app-password step of legacy is never part of a UI sign-in (legacy skips it when the
    service is `NONE`, and FR-003 forbids it).
  - **Role mapping**: request `admin` = legacy `admin`, `domainAdmin` = `domain_admin`,
    `mailboxUser` = `user`.
  - **Mailbox user**: the row is selected with `kind NOT REGEXP 'location|thing|group'` joined to
    `domain`. When `authsource` is not `mailcow` (`ldap`, `keycloak` or `generic-oidc`), the result
    is the generic failure with reason `externalIdentitySource`, it is not published to fail2ban,
    and it still counts towards throttling. `generic-oidc` is treated like LDAP and Keycloak: it is
    an external identity provider, and legacy `user_login` has no branch for it. Legacy publishes
    such failures; the spec does not (FR-003). Then `mailbox.active = 1`, `domain.active = 1`
    and the password are checked.
  - **Pending actions**:
    - `passwordChange` when `JSON attributes.force_pw_update = 1`.
    - `secondFactorSetup` when `attributes.force_tfa = 1`, no `tfa` row exists for the username
      (any `authmech`, any `active`, legacy `tfa_exists`), and no `fido2` row with `active = 1`
      exists (FR-054).

    Both are re-evaluated on every request by `PendingActionEvaluator`, so a restriction lifts as
    soon as it is resolved in either UI. Legacy evaluates them once at login.
  - **Second factors**: `get_tfa` lists active `tfa` rows. Rows with `authmech` `u2f` or `hotp`
    are filtered out (FR-025). Active rows that leave nothing usable lead to
    `403 second-factor-unavailable`, audited as `secondFactorUnavailable`. That happens after the
    password verified, so the throttle is reset. The usable methods are ordered `webauthn`, `totp`,
    `yubi_otp`, and by `id` within a type.
  - **Re-activation (FR-026)**: When the password verifies and the account has **no active `tfa`
    row of any type** (legacy: `get_tfa()` returns no active rows), `UPDATE tfa SET active = '1'
    WHERE username = ?` runs in the sign-in transaction. An account whose active rows are all
    `u2f`/`hotp` is not re-activated; it gets `second-factor-unavailable` instead.
  - **Recent login (FR-027)**: `REPLACE INTO sasl_log (username, real_rip, service, app_password)
    VALUES (?, ?, <RESTV2_SASL_LOG_SERVICE>, 0)` runs for a mailbox user whose password verified,
    whether or not a second factor follows (legacy writes it in `check_login`). It also runs after
    a successful passkey sign-in. `real_rip` is the client address.
  - **Passkey account resolution (FR-015)**: The credential's `username` is looked up in `admin`
    (`superadmin` 1 → admin, 0 → domain admin) and otherwise in `mailbox`. With a named role, only
    that type is tried. **Deviation**: legacy fails a `user`-role FIDO2 login when the username is
    also in `admin`. The spec's FR-002 semantics ("only that type is tried") let the mailbox account
    sign in.
  - **Times**: Upstream `DATETIME` columns hold mailcow's local time (`TZ`), because MariaDB's
    `NOW()` uses the container time zone. The `MailcowDateTime` cast parses them in `TZ` and
    returns UTC, and the connection never overrides `time_zone`.
- **Rationale**: This is a faithful port, with only the spec's recorded deviations. The dummy-hash
  verification and the reason codes keep FR-005 externally while the audit stays precise
  internally.
- **Alternatives considered**: Evaluating pending actions only at sign-in (legacy) was rejected,
  because FR-029 asks for current data on every request. Distinguishing unknown accounts from
  wrong passwords in the audit was rejected: the spec lists them as one reason.

### R17. Throttling

- **Decision**: Laravel's `RateLimiter` on the `restv2` Redis cache store.
  - **Keys**: `signin:` + sha256(username|client address) for password and second-factor failures,
    and `passkey:` + sha256(client address) for passkey failures.
  - **Delay**: before answering a failure, the request sleeps `0.5 × (failures before this one)`
    seconds with `usleep`. The first failure is not delayed (FR-007). Legacy truncates its float
    delay to whole seconds through `sleep()`. The spec's 0.5 s steps are used instead.
  - **Hard limit**: when the key has reached `RESTV2_SIGNIN_MAX_FAILURES` within
    `RESTV2_SIGNIN_FAILURE_WINDOW` (a fixed window from the first failure), the answer is `429` with
    `Retry-After` = `max(1, availableIn())`, without checking credentials, audited as `throttled`.
    The Redis keys are `restv2:signin:<hash>` and `restv2:passkey:<hash>` (plus Laravel's `:timer`
    keys).
  - **Reset**: a verified password clears its key (legacy `unset($_SESSION['ldelay'])`), and a
    successful passkey sign-in clears the passkey key.
  - **What counts**: a failed second factor counts on the `signin:` key of the pending username.
    A Yubico outage, a vanished method and a `422` do not count.
  - **Fail2ban**: throttled *password* attempts are still published to fail2ban with the normal
    message.
- **Rationale**: Counting per username and address cannot be reset by dropping the cookie, which
  is the spec's deviation from legacy's per-session counter. Publishing throttled attempts keeps
  SC-004 true for any fail2ban threshold, even above 10: fail2ban sees every attempt from the
  address, as with legacy.
- **Alternatives considered**: Not publishing throttled attempts was rejected: an operator with a
  fail2ban threshold above 10 would never ban an attacker stuck on one username. Sliding-window
  counters were rejected: `RateLimiter` already gives the spec's semantics.

### R18. Fail2ban reporting

- **Decision**: `App\Clients\Fail2Ban\RedisFail2BanReporter` implements `Fail2BanReporter`. It
  runs `PUBLISH F2B_CHANNEL <message>` on the `mailcow` Redis connection.
  - The messages are exactly `mailcow UI: Invalid password for <username> by <client address>`
    for passwords and `mailcow UI: Invalid password for API_USER by <client address>` for keys.
    netfilter matches them with `mailcow UI: Invalid password for .+ by ([0-9a-f\.:]+)`.
  - `<client address>` is the canonical form of the client address (`inet_ntop(inet_pton())`,
    which gives lower-case, compressed IPv6).
  - Before the username is inserted, every character in `\x00-\x1F\x7F` is replaced by `?` and the
    result is cut to 255 bytes. A username containing a newline could otherwise inject a second
    line that bans an arbitrary address: the regex's `.+` does not cross newlines. Legacy has this
    flaw. Valid usernames are unaffected, so the message format is unchanged for them.
  - The same message is logged at `warning` level, as legacy calls `error_log` too.
  - When the publish fails (Redis down), the failure is logged at `error` and the client still
    gets its error (spec edge case).
- **Rationale**: This is the exact text and channel that mailcow's unmodified fail2ban rules match
  (FR-006, FR-040, SC-004), hardened against log injection.

### R19. API keys

- **Decision**:
  - **Secret**: `mcv2_` + 40 characters of base62 from `random_bytes` (about 238 bits). It is
    stored as a hex SHA-256 in `secret_hash` (unique index), and its first 12 characters are stored
    as `prefix` for display. The ID is a ULID (`HasUlids`). The `X-API-Key` value is trimmed. Values
    over 256 characters, or not matching `^mcv2_[A-Za-z0-9]{40}$`, are invalid without a lookup.
    Legacy keys (`[A-Za-z0-9-]` without the prefix) can never match, so they are rejected as
    `api-key-invalid` and reported, which is parity with an unknown key (FR-041).
  - **Resolution order** (every outcome publishes to fail2ban except insufficient scope, FR-040):
    1. No row → `api-key-invalid`.
    2. Revoked → `api-key-revoked`.
    3. Expired → `api-key-expired`.
    4. Owner missing from `admin`, or `admin.active = 0` → `api-key-invalid`.
    5. Address outside a non-empty `allowed_networks` → `api-key-source-not-allowed`.

    Revoked and expired are checked before the owner, so that an administrator recognises them.
    Matching uses `Symfony\Component\HttpFoundation\IpUtils::checkIp()`.
  - **Principal**: the owner's username. The role comes from the current `superadmin` value. Scope
    and flags are evaluated per request (FR-033). A domain administrator without domains gets an
    empty scope.
  - **Read-only**: methods other than `GET` and `HEAD` → `insufficient-key-scope` (not reported).
  - **Last use**: `last_used_at` and `last_used_from` are updated when more than 60 s have passed or
    the address changed, with one `UPDATE … WHERE last_used_at IS NULL OR last_used_at < ?`, so
    there is at most about one write per key per minute.
  - **Status**: `revoked` if `revoked_at` is set, else `expired` if `expires_at <= now`, else
    `active`. The list filter `status` translates to the same SQL conditions.
  - **Creation rules (FR-032)**: `ownerUsername` must exist in `admin` (`notFound` otherwise). A
    username that exists only as a mailbox gets `notEligible` with
    `allowedRoles: [admin, domainAdmin]`. `expiresAt` must be later than now (`mustBeInFuture`).
    Each `allowedNetworks` entry must parse with `IpUtils` as an address or prefix (IPv4 0–32, IPv6
    0–128) and is normalised to `address/prefix`, with duplicates removed.
  - **Revocation and `If-Match`**: an already revoked key answers `200` unchanged **before**
    `If-Match` is evaluated, so a retried revocation with the tag read before the first one
    succeeds (FR-036). For a key that is not yet revoked, a mismatching `If-Match` answers `412`.
  - **Version tag**: strong ETag over the stored settings only: `id`, `name`, `owner_username`,
    `access_level`, `allowed_networks`, `expires_at`, `revoked_at` and `revoked_by`. It excludes
    `last_used_*` and the derived `status`, per `docs/conventions.md`. The same value is returned as
    `etag` on every list entry.
  - **Possible owners (FR-057)**: `GET /api-keys/owner-candidates` runs
    `SELECT username, superadmin FROM admin WHERE active = '1'`, with `q` as
    `username LIKE %q%` (escaped) and `role` as the `superadmin` value, ordered by username, paged.
    It needs an administrator principal, from a session or a key, read-only keys included. Reads
    are not audited.
- **Rationale**: Only an irreversible form is stored (constitution VI, FR-034). A recognisable
  prefix helps secret scanners and administrators. Per-request owner evaluation implements
  FR-029 and FR-033.
- **Alternatives considered**: bcrypt or Argon2 hashing of keys was rejected. High-entropy random
  secrets do not need slow hashing, and a slow hash would make every request expensive. A
  prefix-based lookup was rejected: the full-secret hash with a unique index is simpler and has
  constant cost.

### R20. Identity, scope and permission flags

- **Decision**: `App\Services\Identity\IdentityService` computes per request:
  - **Scope**: `allDomains` for an admin; for a domain admin, `SELECT domain FROM domain WHERE
    domain IN (SELECT domain FROM domain_admins WHERE active = '1' AND username = ?)`, the legacy
    `mailbox('get', 'domains')` query (ordered by domain); `mailbox` with `mailbox.domain` for a
    mailbox user. "Active managed domains" in FR-028 therefore means active assignments, as in
    legacy. An inactive domain that is still assigned stays in scope, as in legacy.
  - **Flags**: legacy `acl('to_session')`. An admin gets every column of `user_acl` and `da_acl`
    set to `true`. A domain admin gets every `user_acl` column `true`, overwritten by its `da_acl`
    row, and `false` for `da_acl` columns when the row is missing. A mailbox user gets its
    `user_acl` row, and every column `false` when the row is missing, plus `false` for the
    `da_acl`-only columns. The column lists are fixed in `App\Auth\PermissionFlag` (enum, 29
    cases) and checked against the schema fixture by `tests/Feature/Schema/AclColumnsTest.php`.
    The test fails when a new upstream release adds a column, so that the contract gets updated
    deliberately.
- **Rationale**: Identical effective permissions in both UIs (US3), and an explicit contract
  instead of legacy's dynamic `SHOW COLUMNS`.

### R21. Preferences

- **Decision**: The API-owned table `restv2_preferences` has a unique key `(account_kind,
  username)`, where `account_kind` is `admin` for admin and domain-admin accounts (both live in
  `admin`) and `mailbox` for mailbox users. A row is created on first update. Reads fall back to
  `DEFAULT_LANG` and `system`, and a stored language that is no longer enabled reads as the
  default. The version tag is a strong ETag over the stored `language` and `theme` (null
  included), per `docs/conventions.md`.
- **Rationale**: Legacy keeps the language in a cookie and the theme in browser storage, so
  nothing per account exists upstream (spec parity table). The kind-plus-username key survives a
  domain admin being promoted to admin.

### R22. Audit log

- **Decision**:
  - **Table**: `restv2_audit_events` (see the data model), written by
    `App\Services\Audit\AuditLogger::record()` inside the same database transaction as the audited
    change. A failed insert rolls back the change and answers `500` (spec edge case). For failures,
    the record is written in its own transaction before the error response is sent. If that write
    fails, the response is `500`.
  - **Immutability**: The model throws on `updating` and `deleting`. There is no route that
    changes records.
  - **Retention purge**: the artisan command `restv2:audit-purge` deletes records older than
    `RESTV2_AUDIT_RETENTION_DAYS`, in batches of 1000. It runs daily through mailcow's existing
    ofelia scheduler, using labels on the API container (`ofelia.enabled`,
    `ofelia.job-exec.restv2_audit_purge.schedule: "@daily"`). mailcow's `ofelia-mailcow` already
    watches every container of the compose project
    (`daemon --docker -f label=com.docker.compose.project=…`).
  - **What is not audited**: ceremony requests that touch only the caller's own short-lived
    session data: the CSRF bootstrap, challenges, registration options and cancelling a pending
    sign-in. The plan's Complexity Tracking justifies this against constitution VI.
  - **What is audited**: exactly the events of FR-045. Sign-out is audited only when a signed-in
    principal existed. Idempotent no-ops are not audited: revoking a revoked key, cancelling with
    nothing pending, and signing out without a session.
  - **No secrets**: `details` gets only whitelisted keys per event type.
- **Rationale**: Atomicity with the audited change, reuse of mailcow's scheduler (no second
  container, constitution V), and immutability through the API (FR-048).
- **Alternatives considered**: Writing to legacy's `logs` table was rejected (spec deviation,
  constitution V). `schedule:work` in a sidecar container was rejected as an extra container. A
  MariaDB trigger forbidding updates was rejected as overkill for an API-owned table.

---

## 6. Deployment and operations

### R23. Container and routing

- **Decision**:
  - **Image**: `docker/php/Dockerfile`, target `production`. It is based on `php:8.4-fpm-alpine`,
    with the same extensions as `dev` (without `pcov`). It runs `composer install --no-dev
    --classmap-authoritative`. A Node build stage creates the bundled contract
    `resources/contract/openapi.yaml` and the Swagger UI assets. `route:cache` and `event:cache`
    run at build time.
    - The image runs as the non-root user `www-data` (uid 82) with `read_only: true`. tmpfs mounts
      cover `/app/storage` and `/app/bootstrap/cache` (uid 82) and `/tmp`.
    - php-fpm listens on `0.0.0.0:9000`, with `clear_env = no` so that the container environment
      reaches PHP.
    - The entrypoint (`docker/php/entrypoint.sh`) runs `php artisan config:cache` into the tmpfs
      and `php artisan migrate --force --isolated` (only `restv2_` tables, R5), then execs
      `php-fpm`.
  - **Override**: `deploy/docker-compose.override.yml` is merged into the operator's
    `docker-compose.override.yml` and adds the service `restv2-mailcow`:
    - environment variables per R14;
    - the volume `./data/web/inc:/mailcow/web/inc:ro,z`;
    - the network `mailcow-network` with the alias `restv2`;
    - `depends_on` `mysql-mailcow` and `redis-mailcow`;
    - the ofelia labels;
    - a healthcheck `php /app/artisan restv2:health --ready`.

    The override changes no other mailcow service (constitution V).
  - **nginx routing**: mailcow's `sites-default.conf.j2` includes
    `/etc/nginx/conf.d/site.*.custom` inside every server block, and nginx-mailcow already mounts
    `./data/conf/nginx/` there. `deploy/nginx/site.restv2.custom` is copied to
    `data/conf/nginx/site.restv2.custom`. It contains:
    - `location ^~ /api/v2/` with `resolver 127.0.0.11 valid=30s;`,
      `set $restv2_upstream restv2-mailcow:9000;`, `include /etc/nginx/fastcgi_params;`,
      `fastcgi_param SCRIPT_FILENAME /app/public/index.php;`, `fastcgi_param HTTP_PROXY "";`,
      `client_max_body_size 1m;` and `fastcgi_pass $restv2_upstream;`;
    - `location ^~ /ng/`, which proxies with the same variable technique to `http://ng-mailcow:8080`.
      That is the static image of mailcowui-ng, which the UI repository adds to the same override.
      Until it is deployed, `/ng/` answers `502`.

    The `^~` prefix match beats the legacy regex locations, and `/api/v1` is untouched. The
    variable plus `resolver` makes nginx start even when the API container is absent. The dev
    stack uses the same file unchanged: its `app` service has the network alias `restv2-mailcow`
    and the same `/app` path.
  - **Trusted proxy and real client address**: nginx-mailcow is the only hop. Its `real_ip` module
    already resolves `X-Forwarded-For` or the PROXY protocol from mailcow's trusted ranges and
    `TRUSTED_PROXIES`, and `fastcgi_params` passes the result as `REMOTE_ADDR`. Legacy reads the
    same `REMOTE_ADDR`. The API therefore trusts **no** `X-Forwarded-*` header: `TrustProxies`
    has an empty list, and `$request->ip()` is `REMOTE_ADDR`. Clients cannot spoof the address
    used for throttling, fail2ban, network checks and audit.
  - **HTTPS**: The cookie is always `Secure`. `Location` headers are absolute paths, so the scheme
    does not matter.
- **Rationale**: FastCGI from the existing nginx mirrors how legacy is served, so no extra web
  server is needed in the container. The real address arrives the same way as for legacy, and
  the `site.*.custom` include is mailcow's documented extension point, so no upstream file is
  patched. A separate UI image keeps UI releases independent and puts nothing into upstream's
  `data/web`.
- **Alternatives considered**:
  - FrankenPHP or nginx inside the API container with `proxy_pass` was rejected: it is a second
    web server, and the API would have to trust `X-Forwarded-For` from the mailcow subnet.
  - Mounting the UI build into nginx-mailcow was rejected: it changes the nginx service definition.
  - Copying the UI into `data/web/ng` was rejected: it puts files into the upstream tree and
    conflicts with `update.sh`.
  - Octane was rejected: its long-lived workers are a statefulness risk for little gain.

### R24. Logs, request IDs and health

- **Decision**:
  - **Logs**: the log channel `stderr_json` uses Monolog's `JsonFormatter` on `php://stderr`, one
    line per event. `App\Logging\RequestContextProcessor` adds `request_id`, `route`, `method`,
    `principal` (username and role, or `apiKey:<id>`) and `client_ip`. `App\Logging\RedactSecrets`
    drops context keys matching `/pass|secret|token|code|otp|credential|assertion|authorization|cookie|x-api-key/i`.
    Request bodies are never logged.
  - **Request ID**: the trace ID of an incoming valid `traceparent`
    (`00-<32 hex>-<16 hex>-<2 hex>`), else 16 random bytes in hex.
  - **Health**: `GET /api/v2/health/live` answers `200` without touching dependencies.
    `GET /api/v2/health/ready` checks four things:
    - `SELECT 1`;
    - that `versions.db_schema` is in `config('restv2.supported_schema_versions')`
      (`['18082026_1200']`);
    - Redis `PING`;
    - that no migration is pending.

    It answers `200` or `503 service-unavailable` with `checks`. The command
    `restv2:health --ready|--live` runs the same checks for the Docker healthcheck, because php-fpm
    speaks only FastCGI.
- **Rationale**: These are the constitution's runtime requirements. A JSON line per event works
  with `docker logs` and any collector.

### R25. CI workflow

- **Decision**: `.github/workflows/ci.yml` has four jobs:
  1. `contract`: on Node 22, runs `npx @redocly/cli@2.64.1 lint api/openapi.yaml` and `bundle`, then
     `oasdiff breaking` (`tufin/oasdiff` Docker image, pinned) against the bundled contract of the
     latest `v*` tag, failing on `ERR`. Before the first tag the breaking check is skipped with a
     notice.
  2. `quality`: builds the `dev` image and runs `composer install`, `vendor/bin/pint --test` and
     `vendor/bin/phpstan analyse` (Larastan, level 6).
  3. `tests`: uses the services `mariadb:10.11` and `redis:7.4.11-alpine`. Its matrix entry
     `schema` covers every file in `tests/fixtures/schema/` (today `2026-09a`). It runs
     `composer test`: bundle, prepare the database, then PHPUnit with contract validation and the
     coverage check.
  4. `image`: builds the `production` target, runs the container's `restv2:health --live`, and runs
     `nginx -t` in `nginx:1.28-alpine` on `docker/nginx/dev.conf`, which includes
     `deploy/nginx/site.restv2.custom`.
- **Rationale**: This covers the constitution's quality gates: lint, breaking changes,
  contract-validated tests on each supported schema, Pint and static analysis.
- **Alternatives considered**: Spectral was rejected because Redocly is already the bundler. PHPStan
  without Larastan was rejected: it does not understand Eloquent.

### R26. Status codes outside the constitution's list

- **Decision**:
  - `503` is used for `temporary-verification-failure` (Yubico unreachable) and for readiness.
  - `405` is Laravel's answer for a known path with an unsupported method, rendered as
    `about:blank` problem+json.
  - `304` answers conditional GETs of the public configuration and logos. It is not an error.

  Wrong request content types are not rejected with `415`. A body sent with a JSON content type
  that does not parse answers `400` (`about:blank`). A body with a non-JSON content type is not
  parsed, so its fields are missing and validation answers `422`. See Complexity Tracking in the
  plan.
- **Rationale**: `503` with `Retry-After` is the RFC 9110 meaning of a temporary dependency
  failure, and FR-049 requires a distinct type that clients act on. `405` is framework behaviour
  for undefined methods.

### R27. Problem type URIs

- **Decision**: Problem types are relative references `/api/v2/problems/<name>`, documented in
  `docs/problems.md` and served as HTML by `GET /api/v2/problems/{problemType}`. The HTML is
  rendered from the Markdown section with `Illuminate\Support\Str::markdown`, which uses
  `league/commonmark`, already a Laravel dependency.
- **Rationale**: The project has no public documentation domain yet. A relative URI dereferences
  on every installation and stays identical across them. RFC 9457 allows relative references
  (it recommends absolute ones), and clients compare the string exactly.
- **Alternatives considered**: `https://<some-domain>/problems/<name>` was rejected: no domain is
  owned by the project, and inventing one would be wrong. `tag:` URIs were rejected: they need a
  domain owner too. Changing the type URIs after the first release would break clients, so this is
  listed as an owner-level question in the plan.

### R28. Composer dependencies (constitution VIII)

| Package | Licence | Scope | Justification |
|---|---|---|---|
| `laravel/framework` ^13 and the skeleton's defaults (`laravel/tinker`, `laravel/pint`, `phpunit/phpunit`, `mockery/mockery`, `fakerphp/faker`, `nunomaduro/collision`) | MIT, BSD-3 | runtime / dev | Constitution stack |
| `lbuchs/webauthn` ^2.2 | MIT | runtime | Legacy's own WebAuthn library; byte-compatible credentials (R9) |
| `robthree/twofactorauth` ^3.0 | MIT | runtime | Legacy's own TOTP library; identical acceptance (R10) |
| `larastan/larastan` ^3 | MIT | dev | Static analysis gate (constitution quality gate 6) |
| `opis/json-schema` ^2.6 | Apache-2.0 | dev | JSON Schema 2020-12 validation of every test request and response against the 3.1 contract (R13) |

Without a package: the Yubico client (R11), `verify_hash` (R12), CBOR and the virtual
authenticator in tests (R3), IP matching (Symfony `IpUtils`, already present), ULIDs (Laravel),
JSON logs (Monolog, already present) and Markdown (`league/commonmark`, already present). The
PHP extension `redis` (phpredis) is part of the runtime image, as in mailcow's php-fpm image.

### R29. Cross-feature conventions (`docs/conventions.md`)

- **Decision**: Feature 001 follows the conventions document as follows:
  - **Scope**: objects outside the caller's scope answer `404`. Another account's passkey and an
    unknown key ID are examples. `403 forbidden` is used only for a role that may never use an
    operation, for example a domain administrator listing API keys.
  - **Lists**: `limit` defaults to 25 with a maximum of 1,000, `offset` starts at 0, and every list
    accepts `sort`, `order` and `q` (shared parameter `api/components/parameters/Search.yaml`).
    The search fields: passkeys search the name and authenticator description; API keys the
    name, prefix and owner username; possible owners the username; audit records the actor
    username, target name and user agent. `q` is the shared parameter: at most 255 characters,
    trimmed, empty meaning no search. It becomes a case-insensitive `LIKE` with `%`, `_` and `\`
    escaped. Per-resource `sort` parameters, and values appended to the shared enumerations
    (`KnownProblemType`, `AuditEventType`, `ValidationRuleCode`, `AuditTarget`), follow the
    "Shared contract components" section.
  - **Version tags**: strong ETags over stored settings only (R19, R21, data-model). Every
    representation of a mutable resource carries the same value in `etag`, list entries included.
    `If-Match` is optional, and a mismatch answers `412`.
  - **Keys**: creating and revoking keys needs an interactive administrator session (FR-037).
  - **Secrets**: the key secret is shown exactly once and never logged or audited (FR-034,
    FR-050).
  - **Audit**: one record per state change. Reads are not audited.
  - **Freshness**: no time-based caching (R14 fingerprint cache, R15 uncached branding).
  - **Legacy PHP configuration**: R14.
  - **FeldHost behaviour**: server settings with upstream defaults (`RESTV2_SASL_LOG_SERVICE`,
    `RESTV2_DEFAULT_UI_NAME`, `RESTV2_DEFAULT_LOGO_URL`, `RESTV2_DEFAULT_LOGO_DARK_URL`).
  - There are no bulk actions: legacy offers no mass actions for this feature's resources.
- **Rationale**: The conventions apply wherever spec 001 is silent, and the next spec change
  aligns any contradiction. FR-058 records the list rules in the spec.
