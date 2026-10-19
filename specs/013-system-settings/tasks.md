---
description: "Task list for feature 013: system settings"
---

# Tasks: System Settings

**Input**: Design documents from `specs/013-system-settings/`: [plan.md](./plan.md),
[spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md),
[quickstart.md](./quickstart.md), [contracts/README.md](./contracts/README.md). The contract is
`api/` (already authored and lint-clean). Problem types are in `docs/problems.md` (feature 013
section).

**Tests**: Mandatory (constitution VII). In every phase the test tasks come first and must be
observed **failing** before the implementation tasks of that phase start. Every feature test runs
on MariaDB with the `2026-09a` schema fixture and on a real Redis (database 15); every request and
response is validated against the contract by `tests/TestCase.php` (001). dockerapi, LMTP and the
settings map endpoint are faked behind interfaces and the fakes assert the calls; the license
service is faked with `Http::fake()`; shared files live in a per-test sandbox directory.

**Dependencies on earlier features** (implemented before 013, in number order; research section 0):

- **001**: the whole skeleton and toolchain; `tests/TestCase.php` (`signInAs`, `csrfToken`,
  contract validation, coverage), `MailcowFactory`, `ProblemType`, `ProblemException`, `ETag`,
  `ListQuery`, `ApiFormRequest`, `AuditLogger`, `MailcowConfig`, `PublicConfigService`,
  `ProductName`, `Sleeper`, the route modes `restv2.auth:*`, `restv2.csrf`, `restv2.pending:*`,
  `EnforceKeyScope`, the deployment files.
- **002**: `App\Clients\DockerApi\DockerApiClient` + `tests/Support/Fakes/FakeDockerApiClient`,
  `App\Support\Domains\DomainName`, the domain-in-scope lookup, the `temporary-failure` problem and
  the "Redis down" test technique, the `targetType`/`targetId` audit filters.
- **003**: `App\Services\PasswordPolicy\PasswordPolicyService`, `GET /password-policy` and its tag,
  the `outcome-unknown` problem.
- **005**: `App\Support\Sieve\SieveValidator` with the legacy parser in `resources/legacy/sieve/`,
  the rule code `sieveSyntax`, the `restv2.readOnly` route marker honoured by `EnforceKeyScope`.
- **008** (or whichever earlier feature built it first): `App\Services\Audit\RefusalAuditor`, called
  from 001's problem rendering for routes that declare `->defaults('restv2.audit', [...])`
  (`docs/conventions.md`, "Refusal auditing").
- **011**: the `container-action-failed` problem and `ContainerProblem`; the IP check lookup that
  reads `IP_CHECK`.

If one of these differs from what a task assumes, adapt the task to the existing component; never
duplicate it.

**Organisation**: Phase 1 setup, Phase 2 foundational pieces that block every story, Phases 3 to 10
one user story each in spec priority order (US1 P1; US2–US5 P2; US6–US8 P3), Phase 11 polish.

**Commands**: every PHP, Composer and Node command runs in the dev container:
`docker compose run --rm app <command>`.

## Format: `- [ ] [ID] [P?] [Story?] Description with file path`

- **[P]**: can run in parallel with the other [P] tasks of the same block (different files, no
  dependency on unfinished tasks).
- **[USn]**: the user story. Setup, Foundational and Polish tasks have no story label.
- FR, SC and AS references point to `spec.md`; R references point to this feature's `research.md`.

**Role and key matrix used by the authorisation tests** ("the matrix"): unauthenticated; admin
session; admin read-write key; admin read-only key; domain administrator session (of `example.org`);
domain administrator read-write key; mailbox user session. Expected per operation: plan.md
"Contract Operations" and spec FR-005. Read-only keys get `403 insufficient-key-scope` on writes
(except the preview); keys get `403 session-required` on `setRspamdUiPassword`, `createMassMailJob`
and `cancelMassMailJob`; domain administrators and mailbox users get `403 forbidden`, except the
quota copy recipients of their own domains (foreign or alias domain → `404`) and the sieve presets.

---

## Phase 1: Setup (shared infrastructure)

**Purpose**: Dependencies, configuration, deployment mounts and test fixtures. No business logic.

- [x] T001 Add the dependencies of R27: `composer require symfony/yaml:^7.0 symfony/mime:^7.0`; add
  the `gd` extension to the `base` stage of `docker/php/Dockerfile`
  (`apk add libpng-dev libjpeg-turbo-dev`, `docker-php-ext-configure gd --with-jpeg`,
  `docker-php-ext-install gd`, keeping only the runtime libraries in the final layer); set
  `post_max_size = 20M` in `docker/php/php.ini`. Rebuild the dev image and check
  `php -m | grep gd`.
- [x] T002 [P] Extend `config/restv2.php`, `.env.example` and `.env.testing` with the settings of R2:
  `demo_mode => env('DEMO_MODE', 'n') === 'y'`, `rspamd_map_dir`, `global_sieve_dir`,
  `template_dir`, `mailcow_lang_dir`, `rspamd_settings_map_url`, `rspamd_ui_url`, `lmtp.host`,
  `lmtp.port`, `mass_mail.rate` (integer 1–100, default 10), `mass_mail.delivery_deadline` (5),
  `license.enabled` (`RESTV2_LICENSE_VERIFICATION`, default `true`), `license.verify_url`. In
  `.env.testing` point the four directory settings to `storage/framework/testing/mailcow/…` (the
  sandbox, T008) and `RESTV2_MAILCOW_INC_DIR` / `RESTV2_MAILCOW_LANG_DIR` to the fixtures of T005.
- [x] T003 [P] Extend `deploy/docker-compose.override.yml` (R26): volumes
  `./data/conf/rspamd/custom:/mailcow/rspamd_custom_maps:z`,
  `./data/conf/dovecot/global_sieve_before:/mailcow/global_sieve/before:z`,
  `./data/conf/dovecot/global_sieve_after:/mailcow/global_sieve/after:z`,
  `./data/assets/templates:/mailcow/templates:ro,z`, `./data/web/lang:/mailcow/web/lang:ro,z`; the
  environment variables `DEMO_MODE=${DEMO_MODE:-n}`, `RESTV2_LICENSE_VERIFICATION`,
  `RESTV2_MASS_MAIL_RATE`; the ofelia labels `ofelia.job-exec.restv2_mass_mail.schedule: "@every 10s"`,
  `ofelia.job-exec.restv2_mass_mail.no-overlap: "true"`,
  `ofelia.job-exec.restv2_mass_mail.command: "php /app/artisan restv2:mass-mail-run --max-time=290"`,
  and `ofelia.job-exec.restv2_mass_mail_purge.schedule: "@daily"` with
  `command: "php /app/artisan restv2:mass-mail-purge"`.
- [x] T004 [P] Extend `deploy/nginx/site.restv2.custom` (R3, R26): `client_max_body_size 5m;` in
  `location ^~ /api/v2/`, and a nested `location ^~ /api/v2/settings/logos/` with
  `client_max_body_size 16m;`, `include /etc/nginx/fastcgi_params;`, the same `fastcgi_param` lines
  and `fastcgi_pass $restv2_upstream;`. Verify with `nginx -t` in `nginx:1.28-alpine` on
  `docker/nginx/dev.conf` (001's CI job) and with a 10 MiB `PUT` reaching PHP.
- [x] T005 [P] Create the legacy fixtures copied from upstream `2026-09a` with
  `scripts/legacy/copy-settings-fixtures.sh <tag>` (new; `git -C "$MAILCOW_REPO" show <tag>:<path>`,
  refuses fork branches, writes a `SOURCE` file with the tag and SHA-256 of each file):
  `tests/fixtures/mailcow/rspamd-custom/*.map` (the 15 shipped map files),
  `tests/fixtures/mailcow/global-sieve/{global_sieve_before,global_sieve_after}`,
  `tests/fixtures/mailcow/templates/{pw_reset_text,pw_reset_html,quota}.tpl`,
  `tests/fixtures/mailcow-inc/presets/{rspamd,sieve}/*.yml` (into 001's inc fixture directory) and
  `tests/fixtures/mailcow/lang/{lang.en-gb.json,lang.de-de.json}`. Mount the same directories into
  the dev stack's `app` service in `compose.yaml` for quickstart Part B.
- [x] T006 [P] Create the logo fixtures of SC-003 in `tests/fixtures/logos/`: `valid/` (a 300 × 80
  PNG, a JPEG, an animated GIF, an SVG with `width="300" height="80"`, an SVG without dimensions, an
  Inkscape SVG with `sodipodi:`/`inkscape:` metadata, an SVG with an embedded PNG data URL and
  `#id` references, a 1920 × 1920 PNG) and `hostile/` (SVGs with `<script>`, `onload=`,
  `href="javascript:…"`, `xlink:href="https://…"`, `<foreignObject>`, `<html:div>`, `<!DOCTYPE` with
  an entity, `<?xml-stylesheet ?>`, `style="background:url(https://…)"`, `@import`,
  `<set attributeName="href" to="javascript:…"/>`, a nested `data:image/svg+xml`, a root `html`
  element, a not-well-formed document; a PDF named `logo.png`; a 1921 × 10 PNG; a truncated PNG; a
  16 MiB file). Each hostile file name states the expected `construct`.
- [x] T007 [P] Create `tests/Support/Fakes/FakeLmtpClient.php` (implements `App\Clients\Lmtp\LmtpClient`
  once T024 exists; records `from`, `to`, the raw message and time; programmable replies per address
  and per attempt: `delivered`, `temporaryFailure(code, text)`, `permanentFailure(code, text)`,
  `throw`, `hang(seconds)`) and `tests/Support/Fakes/FakeSettingsMapClient.php` (returns a given text
  or throws). Bind both in `tests/TestCase.php` next to 002's fakes.
- [x] T008 [P] Create `tests/Support/Settings/SharedFilesSandbox.php`: per test, creates
  `storage/framework/testing/mailcow/{rspamd_custom_maps,global_sieve,templates}` from the fixtures of
  T005 (map files mode 0644 in a 0755 directory, like rspamd's entrypoint), points the
  `restv2.*_dir` settings at it, offers `mapContent()`, `mapMtime()`, `removeMap()`, `sieve()`,
  `makeDirectoryReadOnly()` and cleans up in `tearDown()`. Use it from `tests/TestCase.php` through a
  trait `UsesSharedFiles`.
- [x] T009 [P] Extend `tests/Support/Mailcow/MailcowFactory.php` (001) with
  `settingsRule(desc, content, active = 0)` (inserts `settingsmap` like legacy `rsettings('add')`),
  `aliasDomain(alias, target)` (if 004 has not added it), `guid(value)` (REPLACE INTO `versions`
  `('GUID', value)`) and `redisLegacy(array $keys)` (writes raw legacy values on the `mailcow`
  connection, for parity fixtures).

**Checkpoint**: `composer install`, the image with `gd`, the fixtures and the fakes exist.

---

## Phase 2: Foundational (blocking prerequisites)

**Purpose**: Shared helpers every story uses. Tests first.

### Tests (write first, observe failing)

- [x] T010 [P] Write `tests/Unit/Services/RedisSettingsStoreTest.php` (R1; FR-007–FR-009, FR-011,
  FR-091): a write applies all keys
  in one MULTI (a reader never sees half — simulated with a second connection inside the callback);
  a concurrent change of a watched key between read and EXEC makes the store retry with fresh values;
  an `If-Match` that does not match the current raw values throws the 412 problem and writes nothing;
  three consecutive aborted EXECs end in 503 `temporary-failure`; a failing DB commit after EXEC
  restores the old raw values; a Redis connection error yields 503 with `Retry-After: 5` (connection
  pointed at a closed port, 002's technique).
- [x] T011 [P] Write `tests/Unit/Support/SharedFileWriterTest.php` (R13, R14): `replaceInPlace()`
  keeps the inode, owner and mode of the existing file, writes the exact bytes, holds `flock` during
  the write (a second writer blocks), refuses a missing file (`SharedFileMissing`); with
  `touchAfter: true` it waits through the injected `Sleeper` until the clock is one second past the
  write's mtime and then sets a later mtime (fake clock, no real sleep).
- [x] T012 [P] Write `tests/Unit/Rules/SettingsRulesTest.php` for the rule objects of T016:
  `SingleLineText` (control characters and line breaks → `invalidFormat`, `format: singleLine`),
  `MailcowEmail` (`FILTER_VALIDATE_EMAIL` plus no `\x00-\x1F\x7F` → `invalidFormat`, `format: email`;
  empty allowed when `nullable`), `ByteLength` (`tooLong` with `max` and `unit: bytes`, counting
  UTF-8 bytes), `AppLinkUrl` (`https://h/x`, `http://h`, `/path`, `/path?u=%u` valid; `//h`,
  `javascript:alert(1)`, `ftp://h`, `data:…`, `mailto:`, whitespace invalid → `format: appLinkUrl`),
  `HexColor` (`#1d4ed8`, `#1D4ED8` valid; `#fff`, `1d4ed8`, `#gggggg` invalid → `format: hexColor`),
  `LegacyOrigin` (port of `valid_origin()`: `https://portal.example.org`, `http://[2001:db8::1]:8080`,
  `*` valid; `https://portal.example.org/app/`, `https://u:p@h`, `ftp://h`, `portal.example.org`,
  `https://h/?q`, `https://h#f` invalid → `format: origin`).
- [x] T013 [P] Write `tests/Feature/Settings/SettingsProblemTypesTest.php` (FR-089): each new `ProblemType`
  case of T015 (`demo-mode` 403, `feature-disabled` 403, `regex-map-unavailable` 409,
  `global-sieve-filter-unavailable` 409, `mass-mail-already-running` 409 with `runningJobId`,
  `mass-mail-recipient-count-changed` 409 with `actualRecipients`) renders as problem+json with its
  status and English title, and `GET /api/v2/problems/{name}` serves its documentation section
  (001's consistency test then covers `KnownProblemType`). Extend 008's
  `tests/Feature/Audit/RefusalAuditorTest.php` with a test-only route declaring
  `restv2.audit` with a 013 target: `demo-mode` → reason `demoMode`, `feature-disabled` →
  `featureDisabled`, `regex-map-unavailable` / `global-sieve-filter-unavailable` → `fileUnavailable`,
  the two mass mail conflicts → `conflict`; a request with the attribute `restv2.auditSkip` writes no
  record; `SettingsAuditTargetResolver` gives the identifier and name of R24 for each of the 17
  target types.
- [x] T014 [P] Write `tests/Unit/Services/MailcowConfigSettingsTest.php` (R2): `logoLimits()` and
  `rspamdRegexMaps()` come from `RESTV2_LOGO_LIMITS` / `RESTV2_RSPAMD_MAPS` when set, else from the
  mounted `vars.inc.php` / `vars.local.inc.php` fixtures (a local override changing `max_width` and
  removing one map), else the upstream defaults (15 MiB, 1920, 1920; the 13 maps in order).

### Implementation

- [x] T015 Add the cases `DemoMode`, `FeatureDisabled`, `RegexMapUnavailable`,
  `GlobalSieveFilterUnavailable`, `MassMailAlreadyRunning`, `MassMailRecipientCountChanged` to
  `app/Support/Http/ProblemType.php` (001), with URI, status and title as in `docs/problems.md`, and
  the extension members of the last two. This makes T013 pass.
- [x] T016 [P] Create `app/Rules/{SingleLineText,MailcowEmail,ByteLength,AppLinkUrl,HexColor,LegacyOrigin}.php`,
  each reporting its rule code and params to 001's `ApiFormRequest`, and
  `app/Services/Settings/SettingsLimits.php` with the constants of `SettingsLimits` in the contract
  (255, 65535, 1048576 ×4, 50, 100, 100, 3, 64, 6, 256, 25, 1000, and the mass mail values 1000
  entries, 1048576 bytes, 3 retries, 300 s). This makes T012 pass.
- [x] T017 Create `app/Services/Settings/RedisSettingsStore.php` (R1):
  `transaction(array $keys, ?string $ifMatch, callable $etagOf, callable $mutate, callable $audit)`
  with WATCH / read / tag compare / mutate / validate / audit insert inside `DB::transaction` /
  MULTI-EXEC / commit, up to three retries, compensation on commit failure, and the mapping of
  `RedisException` to `ProblemException(temporary-failure)` with `Retry-After: 5`. Also
  `read(array $keys): array` (raw values, `null` for missing) with the same error mapping. This makes
  T010 pass.
- [x] T018 [P] Create `app/Support/Files/SharedFileWriter.php` (R13, R14) with
  `read(string $path): ?string`, `replaceInPlace(string $path, string $bytes, ?string $ifMatch,
  callable $etagOf, bool $touchAfter = false)` (`c+`, `flock`, tag compare under the lock,
  `ftruncate`, single `fwrite`, `fflush`, `fsync`, unlock; the legacy touch through 001's `Sleeper`)
  and the exception `SharedFileMissing`. This makes T011 pass.
- [x] T019 Extend `resources/bin/export-mailcow-vars.php` and `app/Services/Config/MailcowConfig.php`
  (001) with `LOGO_LIMITS` and `RSPAMD_MAPS` (`logoLimits(): array{max_size:int,max_width:int,max_height:int}`,
  `rspamdRegexMaps(): array<string,string>` name → file, filtered to file names matching
  `^[A-Za-z0-9_.-]{1,128}$`), with the precedence of 001-R14. This makes T014 pass.
- [x] T020 [P] Create `app/Policies/SettingsPolicy.php` (administrator role required; `demoMode()`
  helper throwing `ProblemException(demo-mode)` when `config('restv2.demo_mode')`), register it in
  `app/Providers/AppServiceProvider.php`, and add to `app/Services/Audit/AuditLogger.php` (001) the
  `details` whitelists of data-model.md section 7 for the 22 new event types and the target types
  of `AuditTarget`. Extend `app/Services/Audit/RefusalAuditor.php` (008) with the type-specific
  reasons of R24 and the `restv2.auditSkip` request attribute, and create
  `app/Services/Settings/SettingsAuditTargetResolver.php`, registered for the 17 target types. This
  makes the auditor part of T013 pass.
- [x] T021 [P] Extend `tests/Support/Contract/OpenApiContract.php` (001) so that request bodies with a
  non-JSON media type (`image/*`, `application/octet-stream`) are matched by media type only and not
  schema-validated as JSON, and responses with `oneOf` data under `200` are validated as usual. Add
  a unit test in `tests/Unit/Support/OpenApiContractBinaryBodyTest.php`.
- [x] T022 Create the route group skeleton for this feature in `routes/api.php`
  (`capabilities/settings`, `Route::prefix('settings')`, `presets`, `mass-mail-jobs`, `license`, and
  `domains/{domain}/quota-notification-recipients`), with 001's middleware order and route modes per
  R25. Every state-changing route declares `->defaults('restv2.audit', ['event' => …, 'target' => …])`
  with its event type and target type of data-model.md section 7 (R24); reads and the preview do
  not. Route modes: reads `restv2.auth:authenticated`;
  writes `restv2.auth:authenticated` + `restv2.csrf`; `PUT settings/rspamd-ui-password`,
  `POST mass-mail-jobs`, `POST mass-mail-jobs/{jobId}/cancellation` with `restv2.auth:session` +
  `restv2.csrf`; `POST mass-mail-jobs/recipient-preview` with `->defaults('restv2.readOnly', true)`
  (005). The literal `recipient-preview` route is declared before `{jobId}`. Controllers are added
  per story.

**Checkpoint**: foundation ready; user stories can proceed in parallel.

---

## Phase 3: User Story 1 — Brand the web apps (Priority: P1) 🎯 MVP

**Goal**: Administrators manage logos, UI texts, login page options, app links and brand colours;
signed-out clients see them in 001's public configuration; the capabilities tell clients the limits.

**Independent test**: Upload accepted and hostile logos, change every UI text, option, app link and
colour as an administrator, read `GET /public-config` signed out after each change, and compare the
stored Redis bytes with what legacy writes (spec US1).

### Tests for User Story 1 (write first, observe failing)

- [x] T023 [P] [US1] Write `tests/Unit/Support/ImageInspectorTest.php` (R3) over `tests/fixtures/logos/`:
  detection by magic bytes (a PNG declared as JPEG is PNG; the PDF named `logo.png` →
  `unsupportedImageType` with `allowed`), dimensions of PNG/JPEG/GIF and of SVGs (`width="300"`,
  `300px`, `2in` → 192, `50%` → null, missing → null), size limit (`tooLong`, `max`, `unit: bytes`),
  dimension limit checked before decoding (`imageDimensionsExceeded` with `maxWidth`, `maxHeight`,
  `width`, `height`; the decoder is never called for the 1921 px file), truncated PNG → `invalidImage`,
  animated GIF accepted, SVG without dimensions accepted.
- [x] T024 [P] [US1] Write `tests/Unit/Support/SvgSafetyInspectorTest.php` (R4): every file in
  `hostile/` is refused with the `construct` named in its file name and a line number; every SVG in
  `valid/` is accepted; the inspector never modifies its input (byte comparison); XXE is impossible
  (an entity referencing `/etc/passwd` is refused as `doctype` without reading the file).
- [x] T025 [P] [US1] Write `tests/Unit/Support/WcagContrastTest.php` (R5): luminance and ratio of
  `#ffffff`/`#000000` (21:1), `#1d4ed8` (6.70 on white, 2.19 on `#1d2939`), `#ffd700` (1.40 on
  white); the boundary (a colour with exact ratio 4.4999 refused and reported as 4.49; 4.5 accepted);
  effective colours of FR-031 for every combination of light/dark set/unset/passing; foreground
  selection and the ≥ 4.5:1 guarantee for 1,000 random accepted colours (SC-004).
- [x] T026 [P] [US1] Write `tests/Feature/Settings/LogoSettingTest.php` (FR-012–FR-018, AS-1.1–1.4):
  `PUT` of each valid fixture as admin session and rw key → 200 with type, size, dimensions, `ETag`;
  Redis `MAIN_LOGO` equals `data:<detected type>;base64,<bytes>`; `GET /public-config` signed out
  shows the logo URL; the dark logo is independent; each hostile fixture → 422 on `body` with the
  rule code and Redis unchanged; `DELETE` → 204, key removed, public config falls back to the default
  logo; `DELETE` of an unset logo → 204 without audit; `If-Match` mismatch → 412 unchanged; a logo
  stored by legacy as `data:image/x-png;…` or `image/pjpeg` reads normalised as `image/png` /
  `image/jpeg` (as 001 serves it); a legacy-stored hostile SVG stays readable (edge case); demo mode → 403 `demo-mode`
  for `PUT` and `DELETE`; Redis down → 503. Audit: `logoReplacement` / `logoDeletion` with old and new
  metadata, never the bytes.
- [x] T027 [P] [US1] Write `tests/Feature/Settings/UiTextsTest.php` (FR-019–FR-023, AS-1.5, AS-1.6):
  PATCH `productName: "Acme & Co Mail"` → Redis `MAIN_NAME` = `Acme &amp; Co Mail` (the expression
  `htmlspecialchars('Acme & Co Mail')` of legacy, asserted byte-exact), response and public config
  return the plain text; `<b>` stored escaped; help text and footer stored raw; announcement
  deactivation stores `UI_ANNOUNCEMENT_ACTIVE = 0` and public config reports inactive; unsent
  members keep their values (legacy would empty them); 256 characters or a line break in a single-line
  field → 422; 65,536 characters in `footerHtml` → 422; type `critical` → 422; empty `pageTitle`
  returns `pageTitleDefault`; `UI_IMPRESS` only → read as `footerHtml`, next PATCH (of any member)
  writes `UI_FOOTER` and deletes `UI_IMPRESS` in the same transaction (`footerMigrated: true` in the
  audit); outdated tag → 412; a change in Redis by "legacy" between read and PATCH with the old tag →
  412; demo mode → 403. Audit `uiTextsUpdate` with plain old/new values and HTML fields as sizes.
- [x] T028 [P] [US1] Write `tests/Feature/Settings/LoginPageSettingsTest.php` (FR-024): PATCH writes
  `CUSTOM_LOGIN` byte-identical to legacy's `json_encode` (key order and integer values), unsent
  flags keep their values, `forceSingleSignOn: true` is stored without an identity provider and
  password sign-in keeps working (001's `POST /session`), public config reflects the flags; demo mode
  → 403; audit `loginPageUpdate`.
- [x] T029 [P] [US1] Write `tests/Feature/Settings/AppLinkSettingsTest.php` (FR-025–FR-028, AS-1.9,
  AS-1.10): GET returns custom links in order and the built-in links read-only (also with
  `SKIP_SOGO=y`, where 001's rule applies); old-style legacy entries are returned converted; PATCH
  with "Helpdesk" and `signedInUrl: https://help.example.org/?u=%u` writes `APP_LINKS` exactly as
  legacy's `json_encode` (escaped slashes, `hide` boolean, `user_link` `""` for `null`); public config
  then contains it with `showOnLoginPage` and the `%u` placeholder; `javascript:alert(1)` → 422 on
  `links.0.url` and nothing stored; 51 entries → 422 `tooMany`; duplicate names accepted; demo mode →
  403; audit `appLinksUpdate`.
- [x] T030 [P] [US1] Write `tests/Feature/Settings/BrandColorsTest.php` (FR-029–FR-034, AS-1.7,
  AS-1.8): `#FFD700` light → 422 `insufficientContrast` with `{ratio: 1.4, minimum: 4.5, surface:
  "#ffffff"}`; `#1D4ED8` → stored `#1d4ed8` in `restv2:brand-colors`, `effective.light` with white
  foreground, `effective.dark` null; a passing dark colour applies to dark; `null` clears; public
  config `brandColors` follows on the next read and its `ETag` changes; demo mode → 403; audit
  `brandColorsUpdate`.
- [x] T031 [P] [US1] Write `tests/Feature/Settings/SettingsCapabilitiesTest.php` (FR-094): admin
  session, rw and ro keys → 200 with `hostname` = `mail.example.org`, `massMail.suggestedSender` =
  `noreply@mail.example.org`, `demoMode` and `licenseVerificationEnabled` following the settings,
  `logos` following `RESTV2_LOGO_LIMITS`, the contrast constants, the limits equal to
  `SettingsLimits`; no secret or host name other than `hostname` appears; domain admin and mailbox
  user → 403.
- [x] T032 [US1] Add the US1 operations to `tests/Feature/Settings/SettingsAuthorizationMatrixTest.php`
  (FR-001–FR-006, SC-010; new; a data provider over operation × matrix entry, see the header): `getSettingsCapabilities`,
  the three logo operations, UI texts, login page, app links, brand colours. Each refused case
  changes nothing (Redis snapshot compared).

### Implementation for User Story 1

- [x] T033 [P] [US1] Create `app/Support/Images/{ImageInspector,ImageInspection}.php` (R3). This makes
  T023 pass.
- [x] T034 [P] [US1] Create `app/Support/Images/SvgSafetyInspector.php` (R4). This makes T024 pass.
- [x] T035 [P] [US1] Create `app/Support/Color/WcagContrast.php` (R5). This makes T025 pass.
- [x] T036 [US1] Create `app/Services/Settings/BrandingService.php` with `logo(variant)`,
  `replaceLogo(variant, bytes, ifMatch, principal)`, `deleteLogo(variant, ifMatch, principal)`,
  `uiTexts()`, `updateUiTexts(patch, ifMatch, principal)` (escaping, `UI_IMPRESS` migration),
  `loginPage()`, `updateLoginPage(…)`, `appLinks()`, `updateAppLinks(…)` (legacy `json_encode`
  format, old-style conversion on read), each through `RedisSettingsStore`, each checking demo mode
  through `SettingsPolicy`, each auditing per data-model section 7.
- [x] T037 [US1] Create `app/Services/Settings/BrandColorService.php` (hash `restv2:brand-colors` on
  the `restv2` connection, contrast validation, effective colours) and extend
  `app/Services/Branding/PublicConfigService.php` (001) to add `brandColors` and include it in the
  public configuration's `ETag`.
- [x] T038 [P] [US1] Create the Form Requests in `app/Http/Requests/Api/V2/Settings/`:
  `UpdateUiTextsRequest`, `UpdateLoginPageSettingsRequest`, `UpdateAppLinkSettingsRequest`,
  `UpdateBrandColorsRequest` (merge patch: at least one member, `required` on `body` when empty;
  `null` allowed only for `announcement.type` and the colours), with the rules of data-model.md
  section 1 and the rule objects of T016.
- [x] T039 [US1] Create `app/Services/Settings/SettingsCapabilitiesService.php` (R23) and the
  controllers `app/Http/Controllers/Api/V2/{SettingsCapabilitiesController,LogoSettingController,
  UiTextsController,LoginPageSettingsController,AppLinkSettingsController,BrandColorsController}.php`
  (thin: authorise with `SettingsPolicy`, call the service, return the resource with `ETag`; the logo
  controller reads `$request->getContent()` and maps inspector violations to `body`), and register
  the routes in `routes/api.php`. This makes T026–T032 pass.

**Checkpoint**: US1 is functional and testable on its own (MVP).

---

## Phase 4: User Story 2 — Password policy and password reset notification (Priority: P2)

**Goal**: Administrators change the policy (sharing 003's tag) and the reset e-mail.

**Independent test**: change the policy and confirm 003's mailbox password checks and the legacy hash
follow; save reset notifications with valid and invalid senders and templates (spec US2).

### Tests for User Story 2 (write first, observe failing)

- [ ] T040 [P] [US2] Write `tests/Feature/Settings/PasswordPolicyUpdateTest.php` (FR-036–FR-038, SC-011,
  AS-2.1, AS-2.2, AS-2.6): PATCH `{minLength: 10, requireDigits: true, requireMixedCase: true}` →
  200 with the whole policy; Redis `PASSWD_POLICY` = `length 10, chars 0, special_chars 0,
  lowerupper 1, numbers 1` (all five fields, as legacy's `hMSet`); the response `ETag` equals the
  `ETag` of 003's `GET /password-policy`; 003's `createMailbox` then refuses `abcdefghij`
  (`missingCharacterClass`); 2 and 65 → 422 `outOfRange` (`min: 3`, `max: 64`) and nothing changes
  (test name contains `Invalid` for the contract-violating values); with no stored policy the merge
  starts from the defaults 6/0/0/0/0; two administrators with the same tag: the second → 412; Redis
  down → 503; audit `passwordPolicyUpdate` with each changed rule.
- [ ] T041 [P] [US2] Write `tests/Feature/Settings/PasswordResetNotificationTest.php` (FR-039–FR-042,
  AS-2.3–2.5): sender `no-reply@` → 422 `invalidFormat` (`format: email`) and nothing stored (legacy
  would store `""`); valid values stored in `PW_RESET_FROM`/`SUBJ`/`TEXT`/`HTML`; both templates empty
  → the read returns the fixture default templates with `templatesAreDefault: true`; empty subject →
  `passwordResetAvailable: false`; a template of 1,048,577 bytes → 422 `tooLong` `unit: bytes`; a
  template without `{{link}}` accepted; tag changes after a "legacy" write; audit with template sizes
  only.

### Implementation for User Story 2

- [ ] T042 [US2] Extend `app/Services/PasswordPolicy/PasswordPolicyService.php` (003) with
  `update(array $patch, ?string $ifMatch, Principal $actor)` per R8 (003's tag, merge on effective
  values, `HMSET` of all five fields through `RedisSettingsStore`, audit), and add
  `update()` to `app/Http/Controllers/Api/V2/PasswordPolicyController.php` (003) with
  `app/Http/Requests/Api/V2/Settings/UpdatePasswordPolicyRequest.php`; register
  `PATCH password-policy` (admin write) in `routes/api.php`. This makes T040 pass.
- [ ] T043 [US2] Create `app/Services/Settings/PasswordResetNotificationService.php` (R9, default
  templates from `restv2.template_dir`), `app/Http/Requests/Api/V2/Settings/UpdatePasswordResetNotificationRequest.php`
  and `app/Http/Controllers/Api/V2/PasswordResetNotificationController.php`; register the routes.
  This makes T041 pass.
- [ ] T044 [US2] Add `updatePasswordPolicy` and the two reset notification operations to
  `tests/Feature/Settings/SettingsAuthorizationMatrixTest.php` (domain admin and mailbox user → 403;
  003's read stays open to domain admins).

**Checkpoint**: US2 works independently of US1.

---

## Phase 5: User Story 3 — Rspamd settings rules, global regex maps and presets (Priority: P2)

**Goal**: Safe editing of settings rules and regex maps, the effective settings map and the presets.

**Independent test**: CRUD rules including broken content, compare the effective map; save every map
with valid and invalid lines and check the shared files (spec US3).

### Tests for User Story 3 (write first, observe failing)

- [ ] T045 [P] [US3] Write `tests/Unit/Support/UclStructureCheckerTest.php` (R11): the three upstream
  rspamd presets pass; `apply "default" {` without closing → `unclosedBrace` with the opening line;
  `}` without opener → `unexpectedBrace`; `[` `}` nesting → `unexpectedBrace`; an unterminated
  `"…` → `unclosedString`; braces inside strings (`"/^((?!.*@domain\.tld).)*$/"`), `#` comments,
  `/* */` comments and heredocs are ignored; escaped quotes inside strings handled.
- [ ] T046 [P] [US3] Write `tests/Unit/Support/RegexMapValidatorTest.php` (R13): every shipped map of
  `tests/fixtures/mailcow/rspamd-custom/` passes; `# comment`, `/.*@spam\.example$/i` and an empty
  line pass; `/[unclosed/i` → line 1 `doesNotCompile`; `spam.example` → `notSlashDelimited`;
  `/x/im` → `tooManyFlags`; `/a/b/` → `innerSlash`; a whitespace-only line fails; CRLF input is
  accepted; the reported line is 1-based and the first failing one.
- [ ] T047 [P] [US3] Write `tests/Feature/Rspamd/RspamdSettingsRuleTest.php` (FR-050–FR-055, FR-093, SC-006,
  AS-3.2, AS-3.3, AS-3.8): create from preset 4 adapted to `example.org` as active → 201 with
  `Location`, row in `settingsmap` with trimmed content and `active = 1`; created without `active` →
  inactive; unbalanced content → 422 `unbalancedStructure` and no row; `validateOnly=true` → 200
  `{valid: true}`, no row, no audit; list paging, sorting by every field, `active` filter, `q` over
  description and content, each entry with its `etag` equal to the single read's; PATCH with empty
  description → 422 (legacy keeps the old value); PATCH `validateOnly`; DELETE → 204 and row gone;
  unknown id → 404; outdated tag → 412; the effective settings map (fake) is unaffected by refused
  writes; audit creation, update and deletion with content sizes only.
- [ ] T048 [P] [US3] Write `tests/Feature/Rspamd/RspamdRegexMapTest.php` (FR-056–FR-059, FR-093, SC-006,
  AS-3.4–3.6):
  list returns the 13 configured maps in configured order with `available`, content and `etag`
  (sort by name and id, `q` on name); a map removed from the configuration (local override) is
  absent and its id answers 404; PUT the lines of AS-3.4 → file content = trimmed + `\n`, inode
  unchanged, mtime touched again after the write (fake clock), `etag` = sha256 of the new content;
  `/[unclosed/i` → 422 `invalidRegexLine` with `line` and `reason`, file unchanged (bytes and mtime);
  missing file → 409 `regex-map-unavailable`, nothing created; `validateOnly` → no write; 1 MiB + 1
  byte → 422; If-Match mismatch (file changed by "legacy" in between) → 412; audit with old and new
  size.
- [ ] T049 [P] [US3] Write `tests/Feature/Rspamd/RspamdSettingsMapTest.php` (FR-060): admin → 200
  with the fake's text unchanged; fake throwing or non-200 → 503 `temporary-failure`; and
  `tests/Unit/Clients/HttpSettingsMapClientTest.php` with `Http::fake()` (URL, no
  `If-Modified-Since`, timeouts, status mapping).
- [ ] T050 [P] [US3] Write `tests/Feature/Rspamd/PresetTest.php` (FR-061, AS-3.1, AS-3.7): admin lists
  `rspamd` (3 presets, `translationKey` `admin.rsettings_preset_N`, English headline from the
  `lang.en-gb.json` fixture, content) and `sieve` (8, `mailbox.sieve_preset_N`), sorted by id with
  paging and `q`; a domain administrator gets `sieve` and 403 for `rspamd`; mailbox user → 403;
  unknown kind → 404; a preset with a literal headline → `translationKey: null`.
- [ ] T051 [US3] Add the US3 operations to `tests/Feature/Settings/SettingsAuthorizationMatrixTest.php`.

### Implementation for User Story 3

- [ ] T052 [P] [US3] Create `app/Support/Rspamd/{UclStructureChecker,StructureViolation}.php`. This
  makes T045 pass.
- [ ] T053 [P] [US3] Create `app/Support/Rspamd/{RegexMapValidator,RegexLineViolation}.php`. This makes
  T046 pass.
- [ ] T054 [P] [US3] Create `app/Models/Mailcow/RspamdSettingsRule.php` (`$table = 'settingsmap'`,
  casts per data-model.md) and `app/Services/Rspamd/RspamdSettingsRuleService.php` (create, update,
  delete, list with `ListQuery`, validate-only path, tag `id|desc|sha256(content)|active`, audit).
- [ ] T055 [P] [US3] Create `app/Services/Rspamd/RspamdRegexMapService.php` (configured maps from
  `MailcowConfig`, files under `restv2.rspamd_map_dir`, `SharedFileWriter` with `touchAfter`,
  validate-only path, audit).
- [ ] T056 [P] [US3] Create `app/Clients/Rspamd/{SettingsMapClient,HttpSettingsMapClient}.php` (R12),
  bound in `AppServiceProvider`.
- [ ] T057 [P] [US3] Create `app/Services/Presets/PresetCatalog.php` (R22, `symfony/yaml`, natural
  sort, English headlines) and `app/Policies/PresetPolicy.php`.
- [ ] T058 [US3] Create the Form Requests `StoreRspamdSettingsRuleRequest`,
  `UpdateRspamdSettingsRuleRequest`, `ReplaceRspamdRegexMapRequest` in
  `app/Http/Requests/Api/V2/Settings/` (each sets the request attribute `restv2.auditSkip` when
  `validateOnly=true`, R24) and the controllers
  `app/Http/Controllers/Api/V2/{RspamdSettingsRuleController,RspamdRegexMapController,
  RspamdSettingsMapController,PresetController}.php`; register the routes. This makes T047–T051 pass.

**Checkpoint**: US3 works independently.

---

## Phase 6: User Story 4 — Global Sieve filters (Priority: P2)

**Goal**: Validated global pre- and post-filters written to the shared files, with an explicit
Dovecot restart.

**Independent test**: read both scripts, save valid and invalid scripts with and without deferred
restart, and check the files and the dockerapi calls (spec US4).

### Tests for User Story 4 (write first, observe failing)

- [ ] T059 [P] [US4] Write `tests/Feature/GlobalFilters/GlobalSieveFilterTest.php` (FR-062–FR-065, FR-093, SC-006,
  AS-4.1–4.6): list returns pre-filter then post-filter with their `etag`; GET each; PUT a valid
  pre-filter → file written (CRLF normalised, bytes exact, inode unchanged),
  `FakeDockerApiClient` recorded exactly one `restart('dovecot-mailcow')`, `meta.dovecotRestart.outcome =
  done`; missing semicolon → 422 `sieveSyntax` with `message` starting `line N:` and `line`, file
  unchanged, no dockerapi call; `deferRestart: true` → written, no call, `deferred`; fake returning
  `failed` → 200 with `failed` and the message, file stays written, audit records the failure; fake
  timing out (`unknown`) → `failed` with the "may still happen" detail; an empty or comment-only
  script accepted; missing file → 409 `global-sieve-filter-unavailable` and no call;
  `validateOnly=true` → 200 `{valid: true}`, no write, no call, no audit, and the same 422 for an
  invalid script; outdated tag → 412; 1 MiB + 1 byte → 422; a failing audit commit writes the old
  content back.

### Implementation for User Story 4

- [ ] T060 [US4] Create `app/Services/GlobalFilters/GlobalSieveFilterService.php` (R14: normalise,
  validate with 005's `SieveValidator` unless empty or comments only, `SharedFileWriter`, restart via
  002's `DockerApiClient` unless deferred, audit with the outcome, rollback of the file on commit
  failure; the restart outcome goes to the response's `meta.dovecotRestart`),
  `app/Http/Requests/Api/V2/Settings/ReplaceGlobalSieveFilterRequest.php` (sets `restv2.auditSkip`
  when `validateOnly=true`) and
  `app/Http/Controllers/Api/V2/GlobalSieveFilterController.php`; register the routes and add the
  three operations to the authorisation matrix test. This makes T059 pass.

**Checkpoint**: US4 works independently.

---

## Phase 7: User Story 5 — Quota notifications (Priority: P2)

**Goal**: The quota notification e-mail and each domain's copy recipients, for administrators and
domain administrators.

**Independent test**: save settings and recipients, compare Redis with what `quota_notify.py` reads,
and try foreign and alias domains as a domain administrator (spec US5).

### Tests for User Story 5 (write first, observe failing)

- [ ] T061 [P] [US5] Write `tests/Feature/Settings/QuotaNotificationSettingsTest.php` (FR-043–FR-045,
  AS-5.1, AS-5.2): with nothing stored → `quota-warning@localhost`, `Quota warning` and the fixture
  `quota.tpl`, each marked default; PATCH sender, subject and a template with `{{username}}` and
  `{{percent}}` → `QW_SENDER`, `QW_SUBJ`, `QW_HTML` written, markers false; `""` restores the default
  marker; invalid sender → 422 (legacy stores `""`); subject with a line break → 422; template with a
  Jinja syntax error accepted; audit with template sizes.
- [ ] T062 [P] [US5] Write `tests/Feature/Settings/QuotaNotificationRecipientsTest.php` (FR-046–FR-049,
  AS-5.3–5.6): a domain administrator of `example.org` saves `it@example.org` and `boss@example.net`
  active → `QW_BCC` field `example.org` = `{"bcc_rcpts":["it@example.org","boss@example.net"],"active":1}`
  (a JSON list, integer active); `IT@example.org` after `it@example.org` stored once; `not-an-address`
  → 422 on `recipients.0`, nothing stored; empty list with `active: true` → stored inactive; 101
  entries → 422; foreign domain, unknown domain and alias domain → 404 for read and write, for domain
  administrators and administrators alike (alias); a domain administrator who loses the domain between
  two requests → 404; a legacy value written as an object (`{"0":"a@x","2":"b@x"}`) reads as a list;
  a domain without settings → empty and inactive; deleting and recreating the domain (002) shows the
  old settings again; audit `quotaNotificationRecipientsUpdate`.

### Implementation for User Story 5

- [ ] T063 [US5] Create `app/Services/Settings/QuotaNotificationService.php`,
  `app/Services/Settings/QuotaNotificationRecipientsService.php` (R10, scope through 002's lookup,
  alias domains → 404), `app/Policies/QuotaNotificationRecipientsPolicy.php`, the Form Requests
  `UpdateQuotaNotificationSettingsRequest`, `UpdateQuotaNotificationRecipientsRequest` and the
  controllers `app/Http/Controllers/Api/V2/{QuotaNotificationSettingsController,
  QuotaNotificationRecipientsController}.php`; register the routes and add the four operations to the
  authorisation matrix test. This makes T061 and T062 pass.

**Checkpoint**: US5 works independently.

---

## Phase 8: User Story 6 — Mass mail (Priority: P3)

**Goal**: Confirmed, rate-limited, cancellable background mass mail into the mailboxes over LMTP,
with per-recipient outcomes.

**Independent test**: preview each kind of selection, send with matching and outdated counts, run the
runner with the fake LMTP client, cancel, interrupt, and compare outcomes (spec US6).

### Tests for User Story 6 (write first, observe failing)

- [ ] T064 [P] [US6] Write `tests/Unit/Clients/SocketLmtpClientTest.php` (R18) against
  `tests/Support/Lmtp/LmtpStubServer.php` (create it: a PHP child process on an ephemeral port that
  scripts replies and records the dialogue): greeting, `LHLO mail.example.org`, `MAIL FROM`,
  `RCPT TO`, `DATA`, dot-stuffing of a line starting with `.`, CRLF endings, one reply per recipient;
  `250` → delivered, `452` → temporaryFailure, `550` → permanentFailure, connection refused →
  temporaryFailure; the connection is reused with `RSET` and reopened after an error; a server that
  stalls past the 5 s deadline before the final `.` → temporaryFailure "timeout" and no `.` sent.
- [ ] T065 [P] [US6] Write `tests/Unit/Services/MassMailMessageFactoryTest.php`: `From`, a single
  `To`, `Subject` (UTF-8 encoded), `Date`, `Message-ID` `<jobid.recipientid@mail.example.org>`,
  `X-Mailer: MooMassMail`; text only → `text/plain`; with HTML → `multipart/alternative` with both
  parts.
- [ ] T066 [P] [US6] Write `tests/Feature/MassMail/MassMailPreviewTest.php` (FR-072, FR-073, AS-6.1,
  AS-6.6): 3 domains with 120 receiving mailboxes, 2 inactive (`active = 0`), 1 with `active = 2`
  (counted) and 4 resources → `recipientCount` 121 for `{}`, rows sorted by address and paged;
  `q=alice` filters rows while `recipientCount` stays; domain limit; include and exclude;
  include + exclude → 422 `mutuallyExclusive`; `ghost@example.org` → 422 `notFound` on
  `selection.exclude.0`; an inactive mailbox → `notEligible` `reason: notReceiving`; a mailbox outside
  the chosen domains → `notEligible` `reason: outsideSelectedDomains`; an alias domain in `domains`
  → `notFound`; 1,001 entries → 422; admin ro key → 200 (`restv2.readOnly`); no audit record, no row.
- [ ] T067 [P] [US6] Write `tests/Feature/MassMail/MassMailCreateTest.php` (FR-074, FR-075, AS-6.2–6.4,
  AS-6.7): admin session with `expectedRecipients` 121 → 201 with `Location`, a `running` job and 121
  `pending` recipient rows; a mailbox created after the preview → 409
  `mass-mail-recipient-count-changed` with `actualRecipients` 122 and no rows; a second job while one
  runs → 409 `mass-mail-already-running` with `runningJobId` (also when the unique `running_slot`
  index catches a race: two creations in parallel transactions); zero recipients → 422 `tooFew`;
  invalid sender, empty subject or text, a 1 MiB + 1 byte body → 422; any API key (rw or ro, admin
  owner) → 403 `session-required`; audit `massMailJobCreation` with sender, subject, selection
  summary and count.
- [ ] T068 [P] [US6] Write `tests/Feature/MassMail/MassMailRunnerTest.php` (FR-076, FR-079, FR-087,
  SC-008, SC-009) calling `MassMailRunner` with `FakeLmtpClient`, a fake `Sleeper` and
  `Carbon::setTestNow()`: every recipient gets exactly one message addressed only to itself; the
  sleeper enforces ≥ 100 ms between delivery starts at rate 10 and 1/rate for `RESTV2_MASS_MAIL_RATE`
  = 50; a `452` is retried after 30, 60 and 120 s and then `failed`; a `550` (over quota) is `failed`
  with Dovecot's text; a mailbox deactivated after creation fails with the fake's `550`; a
  `delivering` row left by a crashed run becomes `failed` `interrupted` and is never delivered again;
  the job completes when no row is pending and writes exactly one `massMailJobCompletion` audit record
  (actor null, creation `requestId` and client address, counts); a second runner while the lock is
  held exits without delivering; with no running job the runner exits at once; 10,000 recipients at
  rate 10 complete in ≤ 1,050 s of simulated time (SC-009, fake clock).
- [ ] T069 [P] [US6] Write `tests/Feature/MassMail/MassMailCancellationTest.php` (FR-078, AS-6.5): a
  running job with 60 delivered → POST cancellation → 200 `cancelled`, the remaining rows `skipped`,
  counts frozen, audit `massMailJobCancellation` with counts; a runner iteration after the commit
  claims nothing; a delivery in flight at cancellation (fake hanging up to the 5 s deadline) is
  recorded as delivered or, for a temporary reply, `skipped`; cancelling again → 200 unchanged and no
  audit; cancelling a completed job → 409; any key → 403 `session-required`; unknown id → 404.
- [ ] T070 [P] [US6] Write `tests/Feature/MassMail/MassMailReadTest.php` (FR-077, FR-080, FR-081,
  AS-6.8): list newest first, sort by status, `status` filter, `q` on subject; single read with
  sender, subject, bodies, selection, creator, times and counts (`delivering` counted as `pending`);
  recipients list sorted by address, `outcome` filter (`pending` includes `delivering`), `q` on the
  address, failure reasons; admin ro key may read; domain admin and mailbox user → 403.
- [ ] T071 [P] [US6] Write `tests/Feature/MassMail/MassMailPurgeTest.php`: `restv2:mass-mail-purge`
  deletes non-running jobs older than `RESTV2_AUDIT_RETENTION_DAYS` with their recipients and keeps
  running and recent ones.

### Implementation for User Story 6

- [ ] T072 [US6] Create `database/migrations/2026_10_18_013001_create_restv2_mass_mail_jobs_table.php`
  with both tables, columns, indexes, the unique `running_slot` and the cascading foreign key of
  data-model.md section 4, and the models `app/Models/{MassMailJob,MassMailRecipient}.php`. Run 001's
  `MigrationPrefixTest`.
- [ ] T073 [P] [US6] Create `app/Clients/Lmtp/{LmtpClient,SocketLmtpClient,LmtpResult}.php` (R18),
  bound in `AppServiceProvider`. This makes T064 pass.
- [ ] T074 [P] [US6] Create `app/Services/MassMail/MassMailMessageFactory.php` (`symfony/mime`). This
  makes T065 pass.
- [ ] T075 [US6] Create `app/Services/MassMail/{Selection,SelectionResolver}.php` (R19: starting set,
  validation with field paths and reasons, preview query with `ListQuery`, count) and
  `app/Http/Controllers/Api/V2/MassMailRecipientPreviewController.php` with
  `app/Http/Requests/Api/V2/Settings/PreviewMassMailRecipientsRequest.php`. This makes T066 pass.
- [ ] T076 [US6] Create `app/Services/MassMail/MassMailJobService.php` (create with the count check,
  the running-slot guard, the batch insert of recipients and the creation audit in one transaction;
  cancel per R17; list and read with computed counts), `app/Policies/MassMailJobPolicy.php`,
  `app/Http/Requests/Api/V2/Settings/StoreMassMailJobRequest.php` and the controllers
  `app/Http/Controllers/Api/V2/{MassMailJobController,MassMailJobRecipientController,
  MassMailJobCancellationController}.php`; register the routes. This makes T067, T069 and T070 pass.
- [ ] T077 [US6] Create `app/Services/MassMail/MassMailRunner.php` (R17: lock, recovery, claim with
  the job row locked, rate through `Sleeper`, delivery through `LmtpClient` with the deadline, retry
  schedule, completion with audit) and `app/Console/Commands/MassMailRunCommand.php`
  (`restv2:mass-mail-run --max-time=290`). This makes T068 pass.
- [ ] T078 [P] [US6] Create `app/Console/Commands/MassMailPurgeCommand.php` (`restv2:mass-mail-purge`).
  This makes T071 pass.
- [ ] T079 [US6] Add the six mass mail operations to
  `tests/Feature/Settings/SettingsAuthorizationMatrixTest.php`.

**Checkpoint**: US6 works independently.

---

## Phase 9: User Story 7 — Remaining server settings (Priority: P3)

**Goal**: Rspamd web interface password, legacy API CORS, IP check, license and GUID.

**Independent test**: set the Rspamd password (fake dockerapi), save CORS settings, toggle the IP
check, verify the GUID with the service reachable, unreachable and disabled (spec US7).

### Tests for User Story 7 (write first, observe failing)

- [ ] T080 [P] [US7] Write `tests/Feature/Rspamd/RspamdUiPasswordTest.php` (FR-006, FR-066, FR-067,
  AS-7.1, AS-7.2): admin session with a 12-character passphrase containing a quote and a space → 204
  after the fake confirmed one `exec('rspamd-mailcow', {cmd: rspamd, task: worker_password, raw:
  <exact passphrase>})`; 5 characters → 422 `tooShort` (`min: 6`); 257 → 422; a newline → 422
  `invalidFormat` `singleLine`; fake `failed` → 502 `container-action-failed` with
  `container: rspamd-mailcow` and no `serviceMessage`; fake `unknown` → 504 `outcome-unknown`
  `step: rspamdUiPassword`; any key → 403 `session-required` and no call; the passphrase appears in
  no response, no audit row (`passwordChanged: true` only) and not in the captured log output.
- [ ] T081 [P] [US7] Write `tests/Feature/Settings/LegacyApiCorsTest.php` (FR-068–FR-071, AS-7.3,
  AS-7.4): nothing stored → `allowedOrigins: []`, `serverOrigin: https://mail.example.org`, the four
  methods; PATCH origins `https://Portal.example.org`, `*` and methods GET, POST → `CORS_SETTINGS`
  `allowed_origins` = `https://portal.example.org, *`, `allowed_methods` = `GET, POST` (legacy's
  `implode`); `https://portal.example.org/app/` → 422 on `allowedOrigins.0`; empty methods → 422;
  `PATCH` → 422 for an unknown method; a stored legacy bare host name is returned as stored and a PATCH
  of only the methods → 422 on that origin until it is replaced; duplicates stored once; 101 origins →
  422; audit `legacyApiCorsUpdate`.
- [ ] T082 [P] [US7] Write `tests/Feature/Settings/IpCheckSettingTest.php` (FR-035, AS-7.8): default
  off; PATCH on → `IP_CHECK = 1`, and 011's public address lookup is no longer refused with
  `ip-check-disabled` (its HTTP client faked); off again → `0`; demo mode → 403; audit
  `ipCheckUpdate`.
- [ ] T083 [P] [US7] Write `tests/Feature/Settings/LicenseTest.php` (FR-082–FR-085, AS-7.5–7.7):
  GUID row → `guid` and `guidValid: true`; `INVALID` → `guid: null`, `guidValid: false`, still
  verifiable; never verified → `neverVerified`; `POST /license/verification` with `Http::fake()`
  answering `{"response":"ok","c":"C1","s":"S1","m":"Mooore"}` → valid, `supportLevel` 3, Redis
  `LICENSE_STATUS_CACHE` = legacy's JSON with `"valid":"true"` and three cows, and
  `restv2:license:last-verification` with `verifiedAt`; only the field `guid` was posted and the
  connect timeout is 10 s; `{"response":"invalid"}` → invalid with `"No"`; connection error or
  garbage → 200 `unreachable` with `"Temporary error"`; a `LICENSE_STATUS_CACHE` written by legacy in
  German (`danger.temp_error` of the `de-de` fixture) reads as `unreachable`, a legacy valid entry as
  valid with `verifiedAt: null`; `RESTV2_LICENSE_VERIFICATION=false` → both operations 403
  `feature-disabled` and `Http::assertNothingSent()`; `GET /license` never sends a request; audit
  `licenseVerification` with the result.

### Implementation for User Story 7

- [ ] T084 [P] [US7] Create `app/Services/Rspamd/RspamdUiPasswordService.php` (R16),
  `app/Http/Requests/Api/V2/Settings/SetRspamdUiPasswordRequest.php` (`password` in `$dontFlash`)
  and `app/Http/Controllers/Api/V2/RspamdUiPasswordController.php`. This makes T080 pass.
- [ ] T085 [P] [US7] Create `app/Services/Settings/LegacyApiCorsService.php` (R21),
  `app/Http/Requests/Api/V2/Settings/UpdateLegacyApiCorsRequest.php` and
  `app/Http/Controllers/Api/V2/LegacyApiCorsController.php`. This makes T081 pass.
- [ ] T086 [P] [US7] Add `ipCheck()` / `updateIpCheck()` to `app/Services/Settings/BrandingService.php`,
  create `app/Http/Requests/Api/V2/Settings/UpdateIpCheckSettingRequest.php` and
  `app/Http/Controllers/Api/V2/IpCheckSettingController.php`. This makes T082 pass.
- [ ] T087 [P] [US7] Create `app/Services/Settings/LicenseService.php` (R20) and
  `app/Http/Controllers/Api/V2/{LicenseController,LicenseVerificationController}.php`. This makes T083
  pass.
- [ ] T088 [US7] Register the US7 routes in `routes/api.php` and add the eight operations to
  `tests/Feature/Settings/SettingsAuthorizationMatrixTest.php`.

**Checkpoint**: every operation of the contract is implemented.

---

## Phase 10: User Story 8 — Trace settings changes (Priority: P3)

**Goal**: Every change and every refused attempt is findable in the audit log by target.

**Independent test**: make each kind of change, including refused ones, and list the audit log by
target type and identifier (spec US8).

### Tests for User Story 8 (write first, observe failing)

- [ ] T089 [P] [US8] Write `tests/Feature/Settings/SettingsAuditTest.php` (FR-086–FR-088, AS-8.1–8.3,
  SC-012, conventions "Refusal auditing"): for every write operation, one success → exactly one record
  with the event type, target type and id of data-model.md section 7, actor, client address,
  `requestId` and the whitelisted details; one refusal per reason (`permissionDenied` by a domain
  admin, a read-only key's `insufficient-key-scope`, a key's `session-required` on the three
  session-only operations, a restricted session's `pending-action-required`, `notFound` for a foreign
  domain's copy recipients, `versionOutdated`, `validationFailed` with field names and codes but no
  values, `demoMode`, `featureDisabled`, `fileUnavailable`, `conflict`, `temporaryFailure`,
  `serviceFailure`, `outcomeUnknown`) → exactly one failure record; a CSRF failure and a `401` → none;
  reads, previews, validate-only requests (also refused ones) and no-ops → none; `GET /audit-events?targetType=rspamdRegexMap&targetId=global_rcpt_blacklist.map` (002's
  filters) lists the map change with old and new sizes; a mass mail job yields a creation and a
  completion record; an audit insert failure makes the change fail and leaves Redis, files and rows
  unchanged.
- [ ] T090 [P] [US8] Write `tests/Feature/Settings/SecretScanTest.php` (SC-012, R16): after the suite's
  Rspamd password tests, scan the captured log channel, every audit row and every recorded response
  body of the run for the test passphrases; none is found.

### Implementation for User Story 8

- [ ] T091 [US8] Close the gaps T089 and T090 reveal in the services and in `AuditLogger`'s
  whitelists (refusal records written in their own transaction, 001-R22; `details.errors` without
  values), without changing the contract.

---

## Phase 11: Polish and cross-cutting concerns

- [ ] T092 [P] Write `tests/Feature/Settings/LegacyCoexistenceTest.php` (SC-001, FR-010): for each
  Redis-backed setting, values written by "legacy" (raw keys exactly as legacy's functions write them)
  are returned by the API, and values written by the API are read back by ports of legacy's `get`
  branches in the test (`customize('get', …)`, `quota_notification('get')`, `cors('get')`,
  `password_complexity('get')`) with identical results; the tag changes after each legacy write.
- [ ] T093 [P] Write `tests/Feature/Settings/SharedStoreUnavailableTest.php` (FR-091): with the
  `mailcow` Redis connection pointed at a closed port, every Redis-backed read and write of this
  feature answers 503 `temporary-failure` with `Retry-After`, no audit success record and no file or
  row change.
- [ ] T094 [P] Write `tests/Feature/Settings/SettingsPerformanceTest.php` (SC-013, informational
  budget, skipped in CI unless `RESTV2_PERF=1`): 100 reads of each settings resource, of a page of 25
  rules and of 25 recipients with 10,000 rows stay under 1 s at the 95th percentile.
- [ ] T095 (FR-090) Run `composer contract:lint`, `composer test` (including the contract coverage check for the
  45 operations), `vendor/bin/pint --test` and `vendor/bin/phpstan analyse`; fix every finding.
- [ ] T096 [P] Update `specs/013-system-settings/quickstart.md` if a command or output changed, and add
  the 013 settings to the operator notes in `deploy/README.md` (if 001 created it) or the
  docker-compose override comments: the new mounts, `DEMO_MODE`, `RESTV2_LICENSE_VERIFICATION`,
  `RESTV2_MASS_MAIL_RATE`, the nginx logo limit.
- [ ] T097 Validate quickstart.md Part C on a mailcow host (upstream `2026-09a` and FeldHost fork;
  SC-001, SC-002, SC-005, SC-007, SC-008) and
  record the results in the release notes, listing the contract changes of this feature (new
  operations and the additive `PublicConfig.brandColors`) so that mailcowui-ng can bump its pinned
  contract.

---

## Dependencies & Execution Order

### Phase dependencies

- **Setup (Phase 1)**: needs features 001–012 implemented. T001 before anything that uses GD or the
  new packages; T005–T009 are independent of each other.
- **Foundational (Phase 2)**: needs Phase 1; blocks every story. T015–T022 after their tests
  T010–T014; T017 needs T016 only for limits; T022 needs T015.
- **User stories (Phases 3–10)**: each needs Phase 2. US1–US7 are independent of each other; US8
  (Phase 10) verifies the records the other stories write and runs after them.
- **Polish (Phase 11)**: after all stories.

### User story dependencies

- **US1 (P1)**: none besides Phase 2. MVP.
- **US2 (P2)**: needs 003's password policy code (an earlier feature, not another story).
- **US3 (P2)**: none besides Phase 2.
- **US4 (P2)**: needs 005's `SieveValidator` and 002's `DockerApiClient` (earlier features).
- **US5 (P2)**: needs 002's domain lookup (earlier feature).
- **US6 (P3)**: none besides Phase 2 (its LMTP client and tables are its own).
- **US7 (P3)**: US7's IP check reuses `BrandingService` from US1 (T036); if US7 is built before US1,
  create `BrandingService` with only the IP check methods first.
- **US8 (P3)**: after US1–US7.

### Within each story

Tests first and failing → helpers in `app/Support` → models and services → Form Requests →
controllers and routes → authorisation matrix entries.

### Parallel opportunities

- Phase 1: T002–T009 in parallel after T001.
- Phase 2: T010–T014 in parallel; then T016, T018, T020, T021 in parallel; T017, T019, T022 after.
- US1: T023–T031 in parallel; T033–T035 and T038 in parallel; then T036, T037, T039.
- US3: T045–T050 in parallel; T052–T057 in parallel; then T058.
- US6: T064–T071 in parallel; T073, T074, T078 in parallel after T072; then T075–T077.
- US7: T080–T083 in parallel; T084–T087 in parallel; then T088.
- Different stories can be staffed in parallel once Phase 2 is done.

## Parallel example: User Story 1

```text
# Tests together (all fail first):
T023 ImageInspectorTest   T024 SvgSafetyInspectorTest   T025 WcagContrastTest
T026 LogoSettingTest      T027 UiTextsTest              T028 LoginPageSettingsTest
T029 AppLinkSettingsTest  T030 BrandColorsTest          T031 SettingsCapabilitiesTest
# Then helpers together:
T033 ImageInspector       T034 SvgSafetyInspector       T035 WcagContrast       T038 Form Requests
```

## Implementation strategy

### MVP first (User Story 1)

1. Phase 1 and Phase 2.
2. Phase 3 (US1): branding with capabilities and brand colours in the public configuration.
3. Validate US1 alone (tests, quickstart Part B branding commands), then continue.

### Incremental delivery

US1 → US2 → US3 → US4 → US5 (the P2 group can be reordered) → US6 → US7 → US8 → Polish. Each story
adds operations without changing earlier ones; the contract coverage check passes only when all 45
operations are covered (T095).

## Notes

- `[P]` tasks touch different files and have no unfinished dependency.
- FR-092 (no bulk actions) needs no task: no bulk route exists, and the contract has none.
- FR-089, FR-090 and FR-093 are covered by T013, T095 and T047, T048, T059.
- Never use `RefreshDatabase`, `migrate:fresh` or other destructive commands (001-R3).
- Every side effect is asserted in the test that triggers it: Redis bytes, file bytes and mtimes,
  rows, and the calls recorded by the fakes.
- Commit after each task or logical group, with short imperative messages and no AI attribution.

## Implementation notes

Adaptations to existing components (tasks.md: "adapt the task to the existing component; never
duplicate it"), recorded as they were made:

- **T001**: Laravel 13 ships Symfony 8, so the constraints are `symfony/yaml:^8.0` and
  `symfony/mime:^8.0` (the research's `^7` cannot be installed next to Laravel 13). GD is built in
  the `base` stage with `libpng-dev`, `libjpeg-turbo-dev` and `zlib-dev` as build dependencies;
  only `libpng` and `libjpeg-turbo` stay at runtime.
- **T002, T005**: the default templates (`pw_reset_*.tpl`, `quota.tpl`) are read from feature
  005's existing setting `restv2.mailcow_tpls_dir` (`RESTV2_MAILCOW_TPLS_DIR`, mount `/mailcow/tpls`
  of `./data/assets/templates`), not from a second `template_dir` setting and mount. `quota.tpl`
  joins 005's copies in `tests/fixtures/mailcow-tpls/`. The dev stack already mounts the repository
  at `/app`, so `compose.yaml` needs no extra mounts: `.env.example` points the shared directories
  at `storage/dev-mailcow/` (gitignored), which quickstart Part B fills from the fixtures, so that
  manual saves never rewrite tracked fixture files.
- **T006**: the 16 MiB oversize file is generated in the test rather than committed. Hostile file
  names start with the expected `construct` or rule code (`scriptElement--script.svg`).
- **T010, T017**: `RedisSettingsStore` is built on feature 006's `SharedStore::transactionWatching()`
  (WATCH, plan, audit insert inside the database transaction, MULTI/EXEC, commit), not on a second
  transaction loop; it adds the `If-Match` comparison on every attempt, three attempts, and the
  compensation of a failed commit (`SettingsWrite::$restore`, then `500`). Redis failures stay
  006's `SharedStoreUnavailable`, which 001's problem renderer already answers with
  `503 temporary-failure`, `dependency: sharedStore` and `Retry-After: 10` (the research said 5).
- **T013, T020**: the refusal auditor needs no new code. Its validate-only skip is feature 006's
  route option `skipWhenQueryTrue: validateOnly` (instead of a `restv2.auditSkip` request attribute),
  and the type-specific failure reasons are registered with `RefusalAuditor::mapProblemType()` by
  `SettingsAuditTargetResolver::register()`. 5xx problems thrown as `ProblemException`
  (`container-action-failed`, `outcome-unknown`) are recorded by the auditor too, because the
  exception carries audit details (feature 003's rule). The auditor checks of 013 are in their own
  file, `tests/Feature/Settings/SettingsRefusalAuditorTest.php`, next to 008's unchanged test. The
  `details` whitelists live in `AuditEventType::detailKeys()` (001's mechanism), not in
  `AuditLogger`.
- **T016**: the byte-length rule is feature 005's `CharacterLength(bytes: true)`, and the e-mail
  rule is the shared `EmailAddress` with the new option `rejectControlCharacters`; no
  `ByteLength` or `MailcowEmail` class was added.
- **T019**: `resources/bin/export-mailcow-vars.php` exports every name listed in
  `restv2.vars_overrides` and `restv2.vars_defaults`, so only those two config lists gained
  `LOGO_LIMITS` and `RSPAMD_MAPS`. Map file names must also not start with a dot.
- **T021**: 001's contract harness already matches non-JSON request bodies by media type only;
  the new unit test keeps that behaviour.
- **T022**: the routes are registered with the controllers of each story (a route to a controller
  that does not exist yet would fail static analysis and the route cache); the 013 feature entry in
  `tests/feature-registry.json` lists its 45 operations as pending until each story lands.
- **T036**: the UI texts read the footer key of older releases (`UI_IMPRESS`) whenever it holds a
  value, not only while `UI_FOOTER` is empty, because legacy's `customize('get', 'ui_texts')` and
  001's public configuration both let it win; the next change moves it to `UI_FOOTER` and deletes it,
  as FR-023 says. A stored text `0` reads as empty, as legacy tests the values for truthiness.
  `BrandingService` also holds the IP check from the start (T086).
- **T037**: `restv2:brand-colors` is written through `RedisSettingsStore` on the `mailcow`
  connection with its full name, so that it shares the transaction, tag and audit mechanics of the
  other settings; it is the same Redis key as `brand-colors` behind the `restv2` connection's
  `restv2:` prefix (both connections use `REDIS_DB`). 001's `PublicConfigService` gained the
  `brandColors` member, public `builtInApps()` (the app link settings show them read-only) and the
  static `logoUrl()`/`logoVersion()` helpers shared with the logo settings; its `ETag` covers the
  colours because it is computed over the representation. 001's `PublicConfigTest` expects the new
  member.
- **T038**: the four Form Requests share `App\Http\Requests\Api\V2\Settings\SettingsRequest`
  (authorisation before the rules, `unknownField` for members the schema does not define, the
  `validateOnly` query flag). The contrast rule is `App\Rules\BrandColorContrast`.
