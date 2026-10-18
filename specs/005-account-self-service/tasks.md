---
description: "Task list for feature 005: account self-service"
---

# Tasks: Account Self-Service

**Input**: Design documents from `specs/005-account-self-service/`: [plan.md](./plan.md),
[spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md),
[quickstart.md](./quickstart.md). The contract is `api/modules/self-service/` plus the components it
references (lint-clean). Problem types are in `docs/problems.md`.

**Tests**: Mandatory (constitution VII). In every phase the test tasks come first and must be
observed **failing** before the implementation tasks start. Every feature test runs on MariaDB with
the upstream `2026-09a` schema fixture and on a real Redis (database 15); 001's `tests/TestCase.php`
validates every request and response against the bundled contract. Outbound services are faked
behind `app/Clients/` interfaces and the fakes assert the calls.

**Dependencies on earlier features**: 005 is implemented after 001–004 (research R1). It needs, from
**001**: `SessionManager`, `RequireAuth`, `VerifyCsrfToken`, `EnforcePendingActions`,
`EnforceKeyScope`, `EnsureSessionIntegrity`, `PendingActionEvaluator`, `IdentityService`,
`PermissionFlag`, `SignInThrottle`, `LegacyPasswordVerifier`, `SecondFactorService`,
`YubicoOtpVerifier`, `WebAuthnServerFactory`, `OptionsJson`, `RecentLoginRecorder`, `ProductName`,
`MailcowConfig` and its export script, `AuditLogger`, `ProblemType`, `ProblemException`, `ETag`,
`ListQuery`, `ApiFormRequest`, `RedactSecrets`, and the test toolkit (`TestCase::signInAs`,
`csrfToken`, `MailcowFactory`, `VirtualAuthenticator`, `OpenApiContract`, contract coverage); from
**002**: `DomainName`, `DockerApiClient` + `FakeDockerApiClient`, `SogoDirectory` (`rebuild()`),
`MemcachedFlusher` + `FakeMemcachedFlusher`, `SharedStore`, the audit `targetType`/`targetId`
filters; from **003**: `MailboxScope`, `MailboxAddress`, `MailboxReader`, `AddressAvailability`,
`TaggedMailHandlingStore`, `PasswordPolicyReader`, `EffectivePasswordPolicy`,
`PasswordPolicyChecker`, `MailboxPasswordHasher`, `SogoDirectory::refresh()`, the models `Mailbox`,
`SpamAlias`, `Pushover`, `SenderAcl`, `QuotaUsage`, `QuotaReplicaUsage`; from **004** (or earlier):
`Alias`, `AliasDomain`, `BulkActionRunner`. `RefusalAuditor` and `NamedLock` are reused if an
earlier feature created them, otherwise T013 and T014 create them. Tasks that touch 001's files are
marked "(extends 001)".

**Commands**: `docker compose run --rm app <command>` (the host PHP is too old).

## Format: `- [ ] [ID] [P?] [Story?] Description with file path`

- **[P]**: parallel with the other [P] tasks of the same block (different files, no unfinished
  dependency).
- **[USn]**: user story of spec.md. Setup, Foundational and Polish tasks have no story label.
- FR, SC and AS refer to spec.md; R to research.md; "DM" to data-model.md.

---

## Phase 1: Setup (shared infrastructure)

**Purpose**: dependency, legacy reference files, configuration and deployment. No business logic.

- [X] T001 Add the Alpine package `fcgi` (provides `cgi-fcgi`) to the `dev` stage of
  `docker/php/Dockerfile` (R3, test harness only; the `production` stage is unchanged).
- [X] T002 Run `composer require bacon/bacon-qr-code` in the version range accepted by
  `robthree/twofactorauth`'s `BaconQrCodeProvider` (R7, R24), and add `"classmap":
  ["resources/legacy/sieve/"]` to `composer.json` `autoload` (R13).
- [X] T003 [P] Create `scripts/legacy/extract-sieve-parser.sh <tag>` (refuses non-tags, reads
  `data/web/inc/lib/sieve/` with `git -C "${MAILCOW_REPO:-./source_code}" show <tag>:<path>`, writes
  the files unmodified to `resources/legacy/sieve/` plus `resources/legacy/sieve/ORIGIN` with the tag,
  the SHA-256 of each file and a note that the files are distributed in mailcow-dockerized under
  GPL-3.0). Run it for `2026-09a` and commit the output (R13).
- [X] T004 [P] Create `scripts/legacy/extract-sogo-auth.sh <tag>`, which copies `data/web/sogo-auth.php`,
  `data/web/inc/vars.inc.php` and `data/web/inc/sessions.inc.php` of the tag to
  `tests/fixtures/legacy/sogo-auth/<tag>/` (keeping the `inc/` sub-directory) with an `ORIGIN` file
  (tag, hashes). Run it for `2026-09a` (R3).
- [X] T005 [P] Create `scripts/legacy/check-handoff-assumptions.sh <tag>` (R3): fail unless the tag's
  `data/web/json_api.php` refuses `add`, `edit` and `delete` on `$_SESSION['mailcow_cc_api_access']
  == 'ro'`, `data/web/inc/functions.inc.php`'s `protect_route()` requires `mailcow_cc_role`, and
  `data/web/sogo-auth.php` grants on `mailcow_cc_username` ∈ `sogo-sso-user-allowed` without a role
  check; print `ok` otherwise. Add a step running it for every tag in `tests/fixtures/schema/` to the
  `contract` job of `.github/workflows/ci.yml`.
- [X] T006 [P] Copy `data/assets/templates/pw_reset_html.tpl` and `pw_reset_text.tpl` of `2026-09a`
  into `tests/fixtures/mailcow-tpls/` (the test value of `RESTV2_MAILCOW_TPLS_DIR`).
- [X] T007 Add the settings of R23 to `config/restv2.php` (`pw_reset_page` default
  `/ng/reset-password?token={token}`, `pw_reset_max_attempts` 10, `pw_reset_window` 900,
  `mailcow_tpls_dir` `/mailcow/tpls`, `smtp_host` `postfix-mailcow`, `smtp_port` 25,
  `totp_enrolment_ttl` 600, `totp_enrolment_max_attempts` 5, `country_lookup` true,
  `country_lookup_url` `https://dfdata.bella.network/country/`, `country_lookup_budget_ms` 1500,
  `pushover_validate_url` `https://api.pushover.net/1/users/validate.json`, and the `mailcow.conf`
  names `IMAP_PORT`, `IMAPS_PORT`, `POP_PORT`, `POPS_PORT`, `SMTPS_PORT`, `SUBMISSION_PORT`,
  `ADDITIONAL_SERVER_NAMES`, `MAILCOW_PASS_SCHEME`), the mailer `restv2_postfix` to
  `config/mail.php` (SMTP, host and port from those settings, no authentication, `verify_peer`
  false, timeout 10), and the test values to `.env.testing` (`MAIL_MAILER=array`,
  `RESTV2_MAILCOW_TPLS_DIR=tests/fixtures/mailcow-tpls`, `RESTV2_COUNTRY_LOOKUP=true`,
  `IMAPS_PORT=993`, `IMAP_PORT=143`, `POPS_PORT=995`, `POP_PORT=110`, `SMTPS_PORT=465`,
  `SUBMISSION_PORT=587`).
- [X] T008 [P] Extend `deploy/docker-compose.override.yml`: volume
  `./data/assets/templates:/mailcow/tpls:ro,z`, the R23 variables, and the ofelia labels
  `ofelia.job-exec.restv2_webmail_handoff_sweep.schedule: "@every 1m"` and `.command: php
  /app/artisan restv2:webmail-handoffs:sweep` (R3, R23).

**Checkpoint**: `composer install` and `composer contract:lint` pass; the legacy files exist.

---

## Phase 2: Foundational (blocking prerequisites)

**Purpose**: extensions of 001's pipeline and session, shared self-service building blocks,
problem types, configuration export, the capabilities read. **No story starts before this phase
is complete.**

### Tests for the foundation (write first, observe failing) ⚠️

- [X] T009 [P] Write `tests/Unit/LegacySession/PhpSessionEncoderTest.php`: the encoding of strings,
  integers, nested arrays and the hand-off payload equals PHP's own `session_encode()` output for the
  same `$_SESSION` (run in a subprocess with `session.serialize_handler=php`), keys containing `|`
  are refused, and the decoded payload has exactly the six keys of DM "Legacy hand-off session".
- [X] T010 [P] Write `tests/Feature/SelfService/SessionRegistryTest.php` (R2): a sign-in through
  `SessionManager` registers `handle → id` in `restv2:account-sessions:<kind>:<username>`; the
  handle survives `migrate(true)` and the field is rewritten; sign-out forgets it; `isAlive` is false
  for a destroyed session; `endAll(kind, username, exceptHandle)` destroys the other sessions (their
  next request is `401 unauthenticated`), prunes dead fields without counting them and returns the
  count; every session request refreshes the hash TTL to `SESSION_LIFETIME`.
- [X] T011 [P] Write `tests/Feature/SelfService/PipelineExtensionsTest.php` (R5), with routes under
  `/api/v2/__testing/`: `restv2.pending:includes,passwordChange` passes a session restricted by
  `passwordChange` alone or by both actions and refuses one restricted only by `secondFactorSetup`
  (`403 pending-action-required`); the same for `includes,secondFactorSetup`; a route with
  `->defaults('restv2.readOnly', true)` accepts `POST` from a read-only key while another `POST`
  answers `insufficient-key-scope`.
- [X] T012 [P] Write `tests/Feature/SelfService/MailboxAccessTest.php`: `MailboxAccess::resolve()`
  normalises the address (case, Unicode domain), answers `404` for unknown addresses, resources,
  mailboxes outside a domain administrator's active assignments and other mailboxes of a mailbox
  user; `PermissionGate::requireChange()` refuses with `403 permission-flag-required` (`requiredFlags`
  with the one flag) when the effective flag is off and a value changes, accepts values equal to the
  current one, maps a domain administrator to all user flags except `appPasswds`, `pushover` (own
  `da_acl`) and `filters` (own `da_acl`), and an administrator to every flag (FR-006).
- [X] T013 [P] Write `tests/Feature/SelfService/RefusalAuditTest.php` (conventions "Refusal
  auditing"): a refusal of an authenticated caller on a self-service route marked with
  `restv2.audit` writes one audit record with the event type, the target type, `target.id` = the
  mailbox address and the 008 reason (`permissionDenied` + `details.problemType` for a missing flag,
  a read-only key and a restricted session; `notFound`; `versionOutdated`; `validationFailed`); a
  CSRF failure and a `401` write none; a refusal a service already audited is not recorded twice.
  If `App\Services\Audit\RefusalAuditor` does not exist yet, also cover its generic behaviour here.
- [X] T014 [P] Write `tests/Unit/Support/NamedLockTest.php` (only if `App\Support\Database\NamedLock`
  does not exist): `GET_LOCK` before the transaction, release in `finally` after an exception, a
  second connection waits, a timeout raises `503 temporary-failure`.
- [X] T015 [P] Write `tests/Unit/Passwords/NewPasswordValidatorTest.php` (FR-009, R4): every prefix
  of the `hash_password()` list in any letter case gives `preHashedPassword` with `scheme`; policy
  failures give 003's `tooShort` (`min`) and `missingCharacterClass` (`characterClass`), all at once;
  a prefix value is not also policy-checked; a valid password passes.
- [X] T016 [P] Write `tests/Feature/SelfService/MailcowConfigSelfServiceTest.php` (R18, R23): with
  `tests/fixtures/mailcow-inc` variants, the export returns `PW_RESET_TOKEN_LIFETIME`,
  `PW_RESET_TOKEN_LIMIT`, `WEBAUTHN_UV_FLAG_REGISTER`, `SESSION_NAME`, `SESSION_SAMESITE_POLICY` and
  `autodiscover_config` merged with `vars.local.inc.php`; `RESTV2_<NAME>` overrides win; the HTTPS
  port placeholder is replaced by the request's port (443 without one); ports parse `ip:port`
  values like `vars.inc.php`; a missing port is `null`.
- [X] T017 [P] Write `tests/Feature/SelfService/SelfServiceCapabilitiesTest.php` for
  `getSelfServiceCapabilities` (FR-080): signed out → only `passwordReset` (`available` false when
  `PW_RESET_FROM` or `PW_RESET_SUBJ` is empty, `tokenLifetimeMinutes` from the config); a read-only
  key of a domain administrator → every member except `account`; a session → `account` with
  `secondFactorRequired` from `force_tfa` (`"1"` and `1`), `passwordChangeAvailable` and
  `secondFactorManagementAvailable` false for an LDAP mailbox; `groupware.enabled` false with
  `SKIP_SOGO=y`; a restricted session is allowed; the read changes nothing (no audit record, Redis
  and tables unchanged); no secret appears.

### Implementation for the foundation

- [X] T018 [P] Extend `database/factories/MailcowRows.php` and `tests/Support/Mailcow/MailcowFactory.php`
  (extends 001–004) with rows exactly as legacy writes them (DM): `appPasswd(mailbox, name (stored
  encoded), password, scheme, protocols[], active)`, `spamAlias(address, goto, description,
  validity, permanent)`, `sieveFilter(username, desc, kind, script, active)`, `pushover(username,
  key, token, attributes, title, text, senders, senders_regex, active)`, `resetToken(username,
  token, created)`, `saslLog(username, service, real_rip, app_password, datetime)`,
  `logsCheckLogin(username, type)` (as legacy `logger()` writes `call`), `sogoProfileRows(mailbox)`
  (one row in each `sogo_*` table of DM plus a foreign `sogo_acl` grant), `senderAcl(...)`,
  `quota2(username, bytes, messages)`.
- [X] T019 [P] Create `app/Support/LegacySession/PhpSessionEncoder.php` (`encode(array): string`,
  PHP `php` serialiser format, R3). This makes T009 pass.
- [X] T020 Create `app/Services/Auth/SessionRegistry.php` and extend
  `app/Services/Auth/SessionManager.php` (extends 001): `restv2.handle` (ULID) at principal
  creation, `register` after promotion and passkey sign-in, `rename` after every `migrate(true)`,
  `forget` in `end()`; record `restv2.principal.secondFactorId` in
  `app/Services/Auth/SecondFactorService.php` on promotion (extends 001); refresh the registry TTL in
  `app/Http/Middleware/EnsureSessionIntegrity.php` (extends 001). `SessionManager::end()` and
  `SessionRegistry::endAll()` call a `SessionEndListener` list, to which T076 adds the hand-off
  cleanup. This makes T010 pass.
- [X] T021 Extend `app/Http/Middleware/EnforcePendingActions.php` with the mode `includes,<action>` and
  `app/Http/Middleware/EnforceKeyScope.php` with the route default `restv2.readOnly` (both extend
  001, R5). This makes T011 pass.
- [X] T022 [P] If absent, create `app/Support/Database/NamedLock.php` (`run(string $name, int
  $timeout, Closure $fn)`; names `restv2_<area>_<40 hex of sha1>`; timeout → `ProblemException`
  `temporary-failure` with `dependency: database`). This makes T014 pass.
- [X] T023 Create `app/Services/SelfService/MailboxAccess.php` (wraps 003's `MailboxScope` and
  `MailboxAddress`; returns the `Mailbox` model or throws `404`) and
  `app/Services/SelfService/PermissionGate.php` (FR-006 mapping above, equal-value rule,
  `permission-flag-required` with `requiredFlags`). This makes T012 pass.
- [X] T024 If `App\Services\Audit\RefusalAuditor` does not exist, create it as 008's research
  describes (hook in 001's problem rendering, route default `restv2.audit`, resolver registry, no
  record for `csrf-token-mismatch` or `401`, skipped when the request already wrote a record).
  Create `app/Services/SelfService/SelfServiceAuditTargets.php`, which registers resolvers for the
  target types `accountPassword`, `secondFactor`, `recoveryEmail`, `appPassword`, `temporaryAlias`,
  `sieveFilter`, `pushNotificationSettings`, `mailSettings`, `recentLogins`, `groupwareData` and
  `webmailHandOff` (target id = the route's mailbox address or the principal's username, R22), and
  add the `details` whitelists of R22 to `app/Services/Audit/AuditLogger.php` (extends 001). This
  makes T013 pass.
- [X] T025 [P] Create `app/Support/Passwords/PreHashedPasswordRule.php` and
  `app/Support/Passwords/NewPasswordValidator.php` (prefix rule first, then 003's
  `PasswordPolicyChecker` with `EffectivePasswordPolicy`, violations on the given field). This makes
  T015 pass.
- [X] T026 [P] Add the cases `CurrentPasswordIncorrect`, `PasswordManagedExternally`,
  `PasswordResetUnavailable`, `InvalidResetToken`, `SecondFactorEnrolmentExpired`,
  `SecondFactorEnrolmentFailed`, `LastSecondFactor`, `SecondFactorRequiredByPolicy`,
  `PushNotificationsNotConfigured` and `GroupwareDisabled` to `app/Support/Http/ProblemType.php`
  (extends 001; status and title per `docs/problems.md`), plus `PermissionFlagRequired`
  (`/api/v2/problems/permission-flag-required`, 403) when no earlier feature added it (006 and 009,
  which define it in the contract, are implemented after 005), with `ProblemException` factories for the
  extensions `requiredFlags`, `attemptsRemaining`, `dependency` (+ `Retry-After: 30`) and
  `methods`. Add `currentPassword`, `newPassword`, `secretKey`, `userKey`, `generatedPassword` and
  `qrCode` to the key pattern of `app/Logging/RedactSecrets.php` (extends 001, FR-076).
- [X] T027 Extend `resources/bin/export-mailcow-vars.php` (run with `HTTP_HOST =
  <MAILCOW_HOSTNAME>:__RESTV2_HTTPS_PORT__`) and `app/Services/Config/MailcowConfig.php` (both extend
  001) with `pwResetTokenLifetime()`, `pwResetTokenLimit()`, `webauthnUvFlagRegister()`,
  `sessionName()`, `sessionSameSitePolicy()` and `autodiscoverConfig(Request)` (placeholder → request
  port), with `RESTV2_<NAME>` precedence and upstream defaults (R23). This makes T016 pass.
- [X] T028 Create `app/Services/SelfService/SelfServiceCapabilitiesService.php`,
  `app/Services/Account/AccountCapabilities.php` (the `account` block),
  `app/Http/Resources/Api/V2/SelfServiceCapabilitiesResource.php` and
  `app/Http/Controllers/Api/V2/SelfServiceCapabilitiesController.php` (`show`). Register `GET
  /capabilities/self-service` (`restv2.auth:public`, `restv2.pending:allow`) in `routes/api.php`
  with the other literal `/capabilities/*` routes. This makes T017 pass.
- [X] T029 [P] Update the descriptions that announce password change and second-factor enrolment as
  future work, now that this feature provides them (description-only, non-breaking contract
  change): `api/components/schemas/PendingAction.yaml` (`passwordChange` → `PUT /me/password`;
  `secondFactorSetup` → also `POST /me/second-factors`), the "Restricted sessions" paragraph of
  `api/openapi.yaml`, and the client action of `pending-action-required` in `docs/problems.md`.
  Re-run `composer contract:lint`.

**Checkpoint**: Foundation tests green; `GET /capabilities/self-service` works signed out, with a
session and with a key.

---

## Phase 3: User Story 1 - Change own password, including a forced change (Priority: P1) 🎯 MVP

**Goal**: every role changes its own password; a forced change is resolved in the restricted
session (FR-008 to FR-015, FR-017).

**Independent test**: for an administrator, a domain administrator and a mailbox user, change the
password through the API, sign in with the new one through 001 and verify the old one fails;
repeat with every refusal of AS-3 to AS-9.

### Tests for User Story 1 ⚠️

- [X] T030 [P] [US1] Write `tests/Feature/OwnAccount/OwnPasswordChangeTest.php` for
  `changeOwnPassword`:
  - AS-1: a mailcow-source mailbox user with two other sessions and an active app password changes
    the password → `200` with `sessionsEnded: 2`, `activeAppPasswords: 1`,
    `mailServerCacheSeconds: 300`; `mailbox.password` verifies with `LegacyPasswordVerifier` in
    `MAILCOW_PASS_SCHEME` (BLF-CRYPT, then SSHA512 via config); `attributes.force_pw_update` = `"0"`,
    `attributes.passwd_update` set; `FakeMemcachedFlusher` flushed once and `_sogo_static_view`
    rebuilt (row present with the new random hash); the other sessions answer `401`; the response
    sets a new session cookie and the old ID no longer works; the app password row is unchanged; one
    `ownPasswordChange` audit record with `sessionsEnded: 2` and no password.
  - AS-2: `endOtherSessions: false` keeps them (`sessionsEnded: 0`).
  - AS-3: a wrong current password → `403 current-password-incorrect`, nothing changed, audited
    (`currentPasswordIncorrect`), no fail2ban message (001's `ChannelSpy` sees none), the second
    failure is delayed by 0.5 s (001's fake sleeper), the 11th within 15 minutes → `429` with
    `Retry-After`; a correct password resets the counter.
  - AS-4: policy length 10 with digits → `422` with `tooShort` (`min: 10`) and
    `missingCharacterClass` (`digit`) on `newPassword`.
  - AS-5: `{SSHA512}…` → `422 preHashedPassword`.
  - AS-6: an administrator without `currentPassword` → `422 required`; with it → `200`,
    `admin.password` changed, `force_pw_update` `"0"`.
  - AS-7: a domain administrator restricted by `force_pw_update` changes without the current password
    → `200`, the flag cleared, `GET /session` shows `active`.
  - Both forced actions pending: the change lifts only `passwordChange`; the session stays restricted
    by `secondFactorSetup` (edge case).
  - AS-8: an LDAP mailbox signed in with a passkey → `403 password-managed-externally` (audited).
  - AS-9: a read-write key of an administrator → `403 session-required`.
  - `SKIP_SOGO=y` → no static-view rebuild, no flush.
  - Legacy hash formats for the current password (SSHA, MD5-CRYPT, PBKDF2-SHA512 from 001's vectors)
    verify.
  - The account is both a mailbox and an administrator: the session's account changes only.
- [X] T031 [P] [US1] Write `tests/Feature/OwnAccount/OwnPasswordPolicyTest.php` for
  `getOwnPasswordPolicy` (FR-014): every role gets 003's policy values and the same `ETag` as
  `getPasswordPolicy`; a session restricted by `passwordChange` may read it; one restricted only by
  `secondFactorSetup` gets `pending-action-required`; a key gets `session-required`.

### Implementation for User Story 1

- [X] T032 [P] [US1] Create `app/Http/Requests/Api/V2/ChangeOwnPasswordRequest.php`: `newPassword`
  "required, string, 1–4096"; `currentPassword` "string, 1–4096, required unless `passwordChange` is
  pending" (`required`); `endOtherSessions` "boolean, default true"; then `NewPasswordValidator`.
- [X] T033 [US1] Create `app/Services/Account/OwnPasswordService.php` (R4): eligibility, throttle
  check, current password, rules, hash with 003's `MailboxPasswordHasher`, the `mailbox` or `admin`
  update of DM in one transaction with the audit record, `SogoDirectory::rebuild()` for mailboxes
  unless `SKIP_SOGO=y`, `MemcachedFlusher` after commit, `SessionRegistry::endAll(…,
  exceptHandle)` when requested, `SessionManager::renew()` (`migrate(true)` + registry rename),
  active app password count.
- [X] T034 [US1] Create `app/Http/Resources/Api/V2/OwnPasswordChangeResultResource.php`,
  `app/Http/Controllers/Api/V2/OwnPasswordController.php` (`update`) and
  `OwnPasswordPolicyController.php` (`show`, 003's `PasswordPolicyReader` and
  `PasswordPolicyResource`). Register `PUT /me/password` (`restv2.auth:session`, `restv2.csrf`,
  `restv2.pending:includes,passwordChange`, `restv2.audit` event `ownPasswordChange` target
  `accountPassword`) and `GET /me/password-policy` (`restv2.auth:session`,
  `restv2.pending:includes,passwordChange`) in the route group `restv2.own` of `routes/api.php`. This
  makes T030 and T031 pass.

**Checkpoint**: US1 works; forced password changes no longer need the legacy UI.

---

## Phase 4: User Story 2 - Enrol and remove second factors (Priority: P1)

**Goal**: TOTP, WebAuthn and Yubico OTP enrolment, listing and removal compatible with legacy
storage, including the forced setup (FR-016, FR-027 to FR-036).

**Independent test**: enrol each type through the API for each role and sign in with it through
001; insert legacy-format rows and list and remove them through the API.

### Tests for User Story 2 ⚠️

- [X] T035 [P] [US2] Write `tests/Unit/Support/QrCodeProviderTest.php`: the data URI starts with
  `data:image/svg+xml;base64,`, decodes to SVG, and needs no `imagick`.
- [X] T036 [P] [US2] Write `tests/Feature/SecondFactors/OwnSecondFactorListTest.php` for
  `listOwnSecondFactors` and `getOwnSecondFactor` (FR-027, AS-8): rows of every `authmech` and both
  states are listed with `type`, `name`, `active`, `usable` (false for `u2f`/`hotp`),
  `yubicoPublicId` (12 modhex characters for Yubico, else `null`); the entry used for the current
  sign-in (001 sign-in with that TOTP) has `usedForCurrentSignIn: true`; no response contains the
  secret, key handle, public key or certificate; pagination, `sort` and `q`; another account's ID →
  `404`; restricted by `secondFactorSetup` → allowed, by `passwordChange` only → refused; key →
  `session-required`.
- [X] T037 [P] [US2] Write `tests/Feature/SecondFactors/TotpEnrolmentTest.php` (FR-030, AS-1, AS-2):
  start → `200` with a 32-character base32 `secret`, a `provisioningUri` with the username and
  `OTP_LABEL` (`RESTV2_OTP_LABEL=FeldHost™ Mailhosting UI` variant), a QR data URI, `expiresAt` +10
  min; confirm with a valid code, a name and the current password → `201`, `Location:
  /api/v2/me/second-factors/<id>`, row `authmech totp`, `secret` = the enrolment secret, `active 1`,
  `key_id` = trimmed name; the confirming code then fails a 001 sign-in (replay key
  `restv2:totp:last:<id>`); a wrong code → `400 second-factor-enrolment-failed` with
  `attemptsRemaining` 4…0, the fifth wrong code cancels (`400 second-factor-enrolment-expired`
  afterwards); after 10 minutes → expired; a second start replaces the first; an enrolment started
  in another session → expired; a wrong current password → `403 current-password-incorrect` with
  the enrolment intact and the throttle counted; the start is not audited, the enrolment is
  (`ownSecondFactorEnrolment`, no secret); an LDAP mailbox → `password-managed-externally`.
- [X] T038 [P] [US2] Write `tests/Feature/SecondFactors/WebAuthnSecondFactorEnrolmentTest.php` (FR-031,
  AS-3) with 001's `VirtualAuthenticator`: options exclude the account's existing WebAuthn second
  factors, `rp.id` = host, `authenticatorSelection.userVerification` per
  `WEBAUTHN_UV_FLAG_REGISTER`, `residentKey` not required, `expiresAt` +5 min; completion stores
  `keyHandle` = base64 credential ID, `publicKey` PEM, `counter 0`, `active 1`; the entry completes
  001's second-factor step; reused or expired options → `400 second-factor-enrolment-expired`; a
  response for another challenge → `400 second-factor-enrolment-failed` and the options are used up;
  the same key registered as a passkey too stays a separate `fido2` row.
- [X] T039 [P] [US2] Write `tests/Feature/SecondFactors/YubiOtpEnrolmentTest.php` (FR-032, AS-4) with
  a fake `YubicoOtpVerifier`: a valid OTP with credentials → row `secret` =
  `<clientId>:<secretKey>:<first 12 of OTP>`; enrolling the same key again replaces the row; a
  rejected OTP → `400 second-factor-enrolment-failed`, nothing stored; an unreachable service →
  `503 temporary-verification-failure` with `Retry-After`, nothing stored; `clientId` with letters →
  `422 invalidFormat` (`digits`); the verifier received the given credentials, not stored ones.
- [X] T040 [P] [US2] Write `tests/Feature/SecondFactors/SecondFactorRemovalTest.php` (FR-034,
  FR-035, AS-5, AS-6): removing one of two active entries → `204`, row gone, audited; removing the
  only active entry → `409 last-second-factor`; removing an inactive entry while one entry is active
  → `204` (plan parity note); `force_tfa` `"1"` and `1` → `403 second-factor-required-by-policy`
  for one and for all; remove all with the current password → `204`, every `tfa` row gone, `fido2`
  rows untouched, one audit record with the removed IDs; a wrong current password → `403` and
  throttled; two concurrent removals of the last two active entries leave one (named lock
  `restv2_tfa_…`, run with two parallel processes).
- [X] T041 [P] [US2] Write `tests/Feature/SecondFactors/ForcedSecondFactorSetupTest.php` (FR-016,
  FR-017, AS-7): an administrator with `force_tfa` and no second factor or passkey is restricted;
  the TOTP start, the WebAuthn options and the enrolment work without `currentPassword`; the next
  request shows `active`; the next sign-in is not restricted; `DELETE /me/second-factors/{id}` and
  the removal stay refused while restricted.
- [X] T042 [P] [US2] Write `tests/Feature/SecondFactors/SecondFactorAuthorizationTest.php`: for every
  `/me/second-factors*` operation, admin, domain admin and mailcow mailbox user succeed for
  themselves; Keycloak and generic OIDC mailboxes → `password-managed-externally` (AS-9); read-only
  and read-write keys of an admin and of a domain admin → `session-required`; refusals of
  authenticated callers are audited (T013 mechanism).

### Implementation for User Story 2

- [X] T043 [P] [US2] Create `app/Support/Totp/QrCodeProvider.php` (robthree's `BaconQrCodeProvider`
  with format `svg`), bound for enrolment only. This makes T035 pass.
- [X] T044 [P] [US2] Extend `app/Clients/Yubico/YubicoOtpVerifier.php` and
  `HttpYubicoOtpVerifier.php` with `verifyWithCredentials(clientId, secretKey, otp)` (extends 001,
  R9); the per-row verification calls it.
- [X] T045 [US2] Create `app/Services/SecondFactors/SecondFactorQueryService.php` (list query of DM,
  no secret columns, `usedForCurrentSignIn`) and
  `app/Http/Resources/Api/V2/OwnSecondFactorResource.php`.
- [X] T046 [US2] Create `app/Services/SecondFactors/SecondFactorEnrolmentService.php` (R7–R9):
  eligibility, current password (unless `secondFactorSetup` pending, through `OwnPasswordService`'s
  verifier and 001's throttle), TOTP start and confirmation (`restv2.totpEnrolment`, attempts,
  replay marker), WebAuthn options and completion (`restv2.secondFactorWebauthn`), Yubico
  replace-and-insert, all under `NamedLock` `restv2_tfa_<sha1(username)>`, inserts of DM, audit.
- [X] T047 [US2] Create `app/Services/SecondFactors/SecondFactorRemovalService.php` (R10, force_tfa
  via `JSON_UNQUOTE(JSON_EXTRACT(…))`, last-active rule, remove all, named lock, audit).
- [X] T048 [P] [US2] Create `app/Http/Requests/Api/V2/ListOwnSecondFactorsRequest.php` (`ListQuery`,
  sort `id|type|name`), `EnrolOwnSecondFactorRequest.php` (`type` in `totp|webauthn|yubiOtp`;
  `name` "required, trimmed, 1–255"; `code` `^[0-9]{6}$`; `credential` per 001's
  `WebAuthnRegistrationResponse` rules; `clientId` `^[0-9]{1,32}$` (`digits`); `secretKey` 1–128;
  `otp` `^[A-Za-z0-9]{44}$`; `currentPassword` 1–4096) and `RemoveAllOwnSecondFactorsRequest.php`.
- [X] T049 [US2] Create `app/Http/Resources/Api/V2/OwnTotpEnrolmentResource.php`, the controllers
  `OwnSecondFactorController.php` (`index`, `show`, `store`, `destroy`),
  `OwnTotpEnrolmentController.php`, `OwnSecondFactorWebAuthnOptionsController.php` and
  `OwnSecondFactorRemovalController.php` in `app/Http/Controllers/Api/V2/`, and register the routes
  of plan "Contract Operations" in group `restv2.own` (`restv2.auth:session`, `restv2.csrf` on unsafe
  methods, pending modes per R5, `restv2.audit` on enrolment and removals with target
  `secondFactor`), `/me/second-factors/totp-enrolment`, `/webauthn-registration-options` and
  `/removal` before `/{secondFactorId}`. This makes T036–T042 pass.

**Checkpoint**: US1 and US2 work; forced second-factor setup no longer needs the legacy UI.

---

## Phase 5: User Story 3 - Recover a forgotten password with the recovery e-mail (Priority: P1)

**Goal**: recovery address and password reset with legacy-compatible tokens, anti-enumeration,
throttling and the second-factor step (FR-018 to FR-026).

**Independent test**: set a recovery address, request resets for accounts in every state, complete
resets with and without second factors and with tokens issued by the legacy UI.

### Tests for User Story 3 ⚠️

- [X] T050 [P] [US3] Write `tests/Feature/OwnAccount/OwnRecoveryEmailTest.php` for
  `getOwnRecoveryEmail` and `replaceOwnRecoveryEmail` (FR-018, AS-1): read with `ETag`; set with the
  current password and `pw_reset` → `attributes.recovery_email` stored, new `ETag`, audited with old
  and new value; `""` and `null` remove it (stored `''`); an invalid address → `422 invalidFormat`
  (`email`); 256 characters → `tooLong`; without `pw_reset` → `403 permission-flag-required`
  (`requiredFlags: [pwReset]`); the same address without the flag → `200` (no change needed); stale `If-Match`
  → `412`; wrong current password → `403 current-password-incorrect`, throttled; admin and domain
  admin → `forbidden`; LDAP mailbox → `password-managed-externally`; key → `session-required`;
  restricted session → `pending-action-required`.
- [X] T051 [P] [US3] Write `tests/Feature/PasswordReset/PasswordResetRequestTest.php` for
  `requestPasswordReset` (FR-019 to FR-022, AS-2, AS-3, AS-7) with the `array` mailer:
  - an eligible mailbox → `200 {tokenLifetimeMinutes: 15}`; after the deferred callbacks ran, one
    `reset_password` row (token `^[0-9A-F]{6}(-[0-9A-F]{6}){4}$`), one message to the recovery
    address from `PW_RESET_FROM` with subject `PW_RESET_SUBJ`, every placeholder replaced
    (`{{link}}` = `https://mail.example.org/ng/reset-password?token=<token>`, `{{username}}`,
    `{{username2}}`, `{{date}}`, `{{token_lifetime}}`, `{{hostname}}`), HTML with text alternative,
    `X-Mailer: MooMail`; one `passwordResetRequest` audit record (outcome success, no token);
  - empty `PW_RESET_HTML` and `PW_RESET_TEXT` → the bodies of `tests/fixtures/mailcow-tpls/`;
  - the request through an `ADDITIONAL_SERVER_NAMES` host → that host in the link; through an
    unknown host → `MAILCOW_HOSTNAME`; `RESTV2_PW_RESET_PAGE=/reset-password?token={token}` → the
    legacy page;
  - unknown address, no recovery address, invalid stored recovery address, LDAP mailbox, inactive
    mailbox, resource, three outstanding tokens → the identical response body and headers, no
    message, audit reasons `unknownAccount`, `noRecoveryEmail`, `accountNotEligible`,
    `tokenLimitReached`; the `pw_reset` flag is not checked;
  - the transport fails → same response, the token row deleted again, audited `messageNotSent`;
  - expired tokens of any account are deleted at each request;
  - no sender or subject → `403 password-reset-unavailable` for every address;
  - not an e-mail → `422 invalidFormat`; with an API key → `session-required`; without CSRF token →
    `csrf-token-mismatch`;
  - ten concurrent requests for one account create at most three tokens (named lock);
  - the `restv2_postfix` mailer is configured as legacy's PHPMailer: host `postfix-mailcow`, port 25,
    no authentication, `verify_peer` false, timeout 10 s (configuration assertion, R6).
- [X] T052 [P] [US3] Write `tests/Feature/PasswordReset/PasswordResetTimingTest.php` (`@group timing`,
  SC-005): 200 requests each for an eligible address (with a slow fake transport of 300 ms), an
  ineligible one and an unknown one; the response bodies are byte-identical and the medians of the
  time to the response's end differ by less than 50 ms (deferred work measured separately).
- [X] T053 [P] [US3] Write `tests/Feature/PasswordReset/PasswordResetTokenCheckTest.php` for
  `checkPasswordResetToken` (FR-023, AS-4): a valid token → address, `expiresAt` (`created` + 15 min
  in UTC from `TZ`), 003's `PasswordPolicy`, `secondFactorRequired` and the usable methods in 001's
  order; lower-case token and characters outside `[A-Za-z0-9-]` handled as legacy; expired, unknown,
  inactive mailbox, non-mailcow source → `400 invalid-reset-token`; the check changes nothing and is
  not audited.
- [X] T054 [P] [US3] Write `tests/Feature/PasswordReset/PasswordResetCompletionTest.php` for
  `createPasswordResetWebAuthnChallenge` and `completePasswordReset` (FR-024, FR-025, AS-5, AS-6,
  AS-8):
  - no second factor, forced change pending → `200 {address}`; new hash in `MAILCOW_PASS_SCHEME`,
    `passwd_update` set, `force_pw_update` `"0"`, every `reset_password` row of the account gone,
    `SogoDirectory::refresh([mailbox])` and the memcached flush (fakes), every session of the account
    ended (two signed-in sessions get `401`) and their hand-off sessions deleted from Redis; the new
    password signs in through 001; audited without password or token;
  - TOTP account without `secondFactor` → `401 second-factor-required` with `methods`; with a valid
    code → `200`; with a wrong code → `401 second-factor-failed`, the token still valid, 001's
    `signin:` throttle counted; a reused code (replay) → `second-factor-failed`;
  - WebAuthn: challenge bound to the token and the session; an assertion for it → `200`; a challenge
    issued for another token, an expired one or one from another browser session →
    `second-factor-failed`;
  - Yubico: valid → `200`; outage → `503 temporary-verification-failure`, token valid;
  - only `u2f`/`hotp` entries → `403 second-factor-unavailable`; passkeys only → no second factor
    needed (edge case);
  - a token inserted as the legacy reset page inserts it (legacy format, `created` default) completes
    here; a token issued here is accepted by legacy's `reset_password('check')` query run verbatim;
  - policy failure and prefix → `422`, token still valid.
- [X] T055 [P] [US3] Write `tests/Feature/PasswordReset/PasswordResetThrottleTest.php` (FR-026): the
  11th request, the 11th check and the 11th completion from one address within 15 minutes → `429`
  with `Retry-After`, independently of each other; another address is not affected.

### Implementation for User Story 3

- [X] T056 [P] [US3] Create `app/Models/Mailcow/PasswordResetToken.php` (table `reset_password`, no
  primary key model writes; reads only) and `app/Services/Account/RecoveryEmailService.php` (read,
  `JSON_SET` write, tag, flag, current password, audit `ownRecoveryEmailChange`).
- [X] T057 [P] [US3] Create `app/Http/Requests/Api/V2/ReplaceOwnRecoveryEmailRequest.php` (`address`
  "null or string ≤ 255, empty or `FILTER_VALIDATE_EMAIL`", `currentPassword` "required, 1–4096"),
  `app/Http/Resources/Api/V2/OwnRecoveryEmailResource.php` and
  `app/Http/Controllers/Api/V2/OwnRecoveryEmailController.php` (`show`, `update`, `ETag`). Register
  the routes in group `restv2.own` (`restv2.auth:session`, `restv2.csrf`, `restv2.pending:enforce`,
  `restv2.audit` target `recoveryEmail`). This makes T050 pass.
- [X] T058 [US3] Create `app/Services/PasswordReset/ResetLinkBuilder.php` (host rule, page setting)
  and `app/Services/PasswordReset/PasswordResetMailer.php` (Redis template keys, file fallback,
  placeholders with `IntlDateFormatter` FULL/FULL in `TZ`, legacy send checks, mailer
  `restv2_postfix`, `X-Mailer: MooMail`).
- [X] T059 [US3] Create `app/Services/PasswordReset/PasswordResetService.php` (R6): availability,
  limiters `pwreset:{request|check|complete}`, the deferred request work through
  `Illuminate\Support\defer()` (make sure `InvokeDeferredCallbacks` is in the `api.v2` group,
  extends 001's `bootstrap/app.php`), token lifecycle of DM under `NamedLock`
  `restv2_pwreset_<sha1(username)>`, token check, WebAuthn challenge in the session, completion with
  the transaction and side effects of R6, `SessionRegistry::endAll('mailbox', …)`, audit records for
  requests and completions.
- [X] T060 [US3] Add `verifyForAccount(string $username, array $proof, ?array $webauthnChallenge)` to
  `app/Services/Auth/SecondFactorService.php` (extends 001; same verification code as the pending
  sign-in, replay protection, throttle hit on failure, no session promotion).
- [X] T061 [US3] Create `app/Http/Requests/Api/V2/{RequestPasswordResetRequest,CheckPasswordResetTokenRequest,PasswordResetWebAuthnChallengeRequest,CompletePasswordResetRequest}.php`
  (`address` "required, ≤ 255, e-mail after normalisation"; `token` "required, 1–64";
  `newPassword` via `NewPasswordValidator`; `secondFactor` per 001's
  `SecondFactorVerificationRequest` rules), `app/Http/Resources/Api/V2/PasswordResetTokenInfoResource.php`
  and the controllers `PasswordResetController.php`, `PasswordResetTokenCheckController.php`,
  `PasswordResetWebAuthnChallengeController.php`, `PasswordResetCompletionController.php`. Register
  the four routes (`restv2.auth:browser`, `restv2.csrf`, `restv2.pending:allow`). This makes
  T051–T055 pass.

**Checkpoint**: all P1 stories but app passwords work.

---

## Phase 6: User Story 4 - Manage app passwords (Priority: P1)

**Goal**: app password CRUD for the mailboxes in scope, legacy storage, flag enforced, generated
passwords shown once (FR-037 to FR-041).

**Independent test**: create, list, change and delete app passwords as every role with and without
`app_passwds`; compare the stored rows with a legacy-created one.

### Tests for User Story 4 ⚠️

- [X] T062 [P] [US4] Write `tests/Unit/Services/AppPasswordGeneratorTest.php`: length `max(32,
  policy minLength)`, at least one lower, upper, digit and special character of `!@#$%^&*()?=`,
  passes `PasswordPolicyChecker` for policies up to length 64 with every class required, uses
  `random_int`.
- [X] T063 [P] [US4] Write `tests/Feature/AppPasswords/AppPasswordCrudTest.php` (FR-037 to FR-041,
  AS-1, AS-3, AS-5, AS-6):
  - create with a password and `[imap, smtp]` → `201`, `Location`, `ETag`, no password in the body,
    row with BLF-CRYPT hash, `domain` = mailbox domain, `imap_access 1`, `smtp_access 1`, others 0,
    `active 1`; `generatedPassword: null`;
  - create without a password → `generatedPassword` of 32 characters, never returned again; the
    stored hash verifies it;
  - defaults: all six protocols, active; empty `protocols` accepted;
  - name `A&B` stored as `A&amp;B`, returned as `A&B`; a name whose encoded form exceeds 255 →
    `422 tooLong` (`stored: true`);
  - prefix and policy failures → `422`;
  - list: 40 rows → 25 with `meta.total 40`, each with `etag` equal to the single read's `ETag`;
    `sort`, `order`, `active` filter, `q` (encoded match); no entry has a password or hash;
  - patch name and protocols → only those columns change; `password` → new hash; `generatePassword:
    true` → returned once; both → `422 mutuallyExclusive`; empty body → `422 required` on `body`;
    stale `If-Match` → `412`;
  - an administrator patches a user's app password → `mailbox` unchanged and a new password applied
    (legacy bug fixed);
  - delete → `204`, row gone; each change audited with target id = mailbox, `details.appPasswordId`,
    passwords only as `set`/`changed`;
  - a row inserted or changed directly in the database between two requests, as the legacy UI would
    do it, is visible in the next response, and its `etag` changes (FR-078, no caching).
- [X] T064 [P] [US4] Write `tests/Feature/AppPasswords/AppPasswordAuthorizationTest.php` (AS-2, AS-4,
  SC-007): admin any mailbox; domain admin with `da_acl.app_passwds` inside scope → allowed, outside
  → `404` for every operation (also with a guessed `appPasswordId` of a foreign mailbox); domain
  admin without the flag → reads allowed, changes `403 permission-flag-required` (`requiredFlags: [appPasswds]`);
  mailbox user with the flag → own only, foreign mailbox `404`; without → reads allowed, changes
  refused; read-only keys (admin, domain admin) → reads allowed, changes `insufficient-key-scope`;
  read-write domain-admin key → inside scope only; resource address → `404`; restricted session →
  `pending-action-required`; every refusal audited.
- [X] T065 [P] [US4] Write `tests/Feature/AppPasswords/AppPasswordParityTest.php` (SC-009): a row
  created through the API equals, column by column apart from `id`, `password` and timestamps, the
  row legacy's `app_passwd('add')` inserts for the same input (fixture of the expected row from the
  legacy function at `2026-09a`).

### Implementation for User Story 4

- [X] T066 [P] [US4] Create `app/Models/Mailcow/AppPassword.php` (table `app_passwd`, `password`
  hidden, casts for the access flags) and `app/Services/AppPasswords/AppPasswordGenerator.php`. This
  makes T062 pass.
- [X] T067 [US4] Create `app/Services/AppPasswords/AppPasswordService.php` (R11: encoding, insert,
  update without rewriting `mailbox`, delete, `ETag` per R20 with `hmac(password)`, `PermissionGate`
  `appPasswds`, audit).
- [X] T068 [US4] Create `app/Http/Requests/Api/V2/{ListAppPasswordsRequest,StoreAppPasswordRequest,UpdateAppPasswordRequest}.php`
  (constraints of DM `app_passwd`), `app/Http/Resources/Api/V2/AppPasswordResource.php` and
  `app/Http/Controllers/Api/V2/AppPasswordController.php`; register the five routes
  (`restv2.auth:authenticated`, `restv2.csrf` on unsafe methods, `restv2.audit` target
  `appPassword`, `MailboxAccess` in every action). This makes T063–T065 pass.

**Checkpoint**: MVP complete (US1–US4).

---

## Phase 7: User Story 5 - Open webmail and see one's mailbox at a glance (Priority: P2)

**Goal**: the webmail hand-off through the legacy session format and the mailbox overview (FR-067
to FR-069).

**Independent test**: request a hand-off and run the legacy `sogo-auth.php` with the cookie; sign
out and run it again; compare the overview with the legacy user page data.

### Tests for User Story 5 ⚠️

- [X] T069 [P] [US5] Create `tests/Support/Legacy/LegacyFpmRunner.php`: writes a php-fpm pool config
  to a temp directory (listen `127.0.0.1:9901`, `session.save_handler=redis`, `session.save_path`
  to the test Redis with `database=15`, `clear_env=no`), starts `php-fpm -F -y` as a subprocess,
  sends a FastCGI request with `cgi-fcgi -bind -connect 127.0.0.1:9901` (`SCRIPT_FILENAME` =
  `tests/fixtures/legacy/sogo-auth/2026-09a/sogo-auth.php`, `DOCUMENT_ROOT` = that directory,
  `SOGO_AUTH_INTERNAL=1`, `HTTP_X_ORIGINAL_URI=/SOGo/so/`, `HTTP_COOKIE`, `HTTP_USER_AGENT`) and
  parses the response headers; stops the process in `tearDown`.
- [X] T070 [P] [US5] Write `tests/Feature/Webmail/WebmailHandOffTest.php` for `createWebmailHandOff`
  (FR-067, FR-068, AS-1, AS-4, AS-5, AS-7): a mailbox user's full session → `200 {mailbox, url:
  "/SOGo/so/"}`, `Cache-Control: no-store`, `Set-Cookie: MCSESSID=<32 hex>; Path=/; Secure; HttpOnly;
  SameSite=Lax` (name and policy from `SESSION_NAME`/`SESSION_SAMESITE_POLICY` overrides too);
  `PHPREDIS_SESSION:<id>` exists with TTL `SESSION_LIFETIME` and decodes to exactly the six keys of
  DM (no `mailcow_cc_role`, `mailcow_cc_api_access = 'ro'`); `sasl_log` row `SSO` with the client
  address; one `webmailHandOff` audit record; a second request replaces the first (old key gone);
  `SKIP_SOGO=y` → `403 groupware-disabled`; restricted session → `pending-action-required`; admin
  and domain admin → `forbidden`; key → `session-required`; Redis down → `503 temporary-failure`
  (`sharedStore`), no `sasl_log` row.
- [X] T071 [P] [US5] Write `tests/Feature/Webmail/WebmailHandOffEndingTest.php` (FR-011, FR-025,
  FR-068, AS-3): the legacy session key is deleted by sign-out (and the response expires the
  `MCSESSID` cookie), by session invalidation (user agent change, account deactivated), by another
  session's password change with `endOtherSessions`, by a completed reset, and by
  `restv2:webmail-handoffs:sweep` after the API session key expired (simulate the TTL); the sweeper
  also removes records whose legacy key expired and leaves live hand-offs alone; the current
  session's own hand-off survives its password change.
- [X] T072 [P] [US5] Write `tests/Feature/Webmail/SogoAuthCompatibilityTest.php` (R3) with
  `LegacyFpmRunner`: after a hand-off the unmodified legacy `sogo-auth.php` answers
  `X-User: <mailbox>`; after sign-out, `endAll` and the sweeper it answers an empty `X-User`; a
  session value with `pending_pw_update` (written by the test) answers empty; the run also proves
  the legacy script reads the API's encoding.
- [X] T073 [P] [US5] Write `tests/Feature/OwnAccount/MailboxOverviewTest.php` for
  `getOwnMailboxOverview` (FR-069, AS-6, AS-7): quota and usage from `quota2` (and `quota2replica`
  with `MASTER=n`), percent `null` for unlimited; protocol access with missing keys as `true`;
  `webmail` flags; `passwordChangedAt` converted from `TZ`; recovery address only for the mailcow
  source; two direct aliases, one shared alias with its public comment as plain text (`A &amp; B` →
  `A & B`), alias-domain addresses, a catch-all, send-as addresses, a `@domain` send-as with its
  alias domain, `*` → `anyAddress`; temporary-alias domains in order; counts of active app passwords
  and unexpired temporary aliases; `pushNotificationsActive`; admin and domain admin → `forbidden`;
  key → `session-required`.

### Implementation for User Story 5

- [X] T074 [US5] Create `app/Services/Webmail/WebmailHandOff.php` (value object) and
  `app/Services/Webmail/WebmailHandOffService.php` (R3: checks, ID generation with `SET NX EX`
  through `SharedStore`, `PhpSessionEncoder` payload, cookie, `RecentLoginRecorder` with service
  `SSO`, records `restv2:webmail-handoff:<id>` and `restv2:session-handoffs:<handle>`,
  `endForHandle()`, audit). Expose `handOff(Principal $creator, string $mailbox, Request $request)`
  for feature 007.
- [X] T075 [US5] Create `app/Console/Commands/SweepWebmailHandOffsCommand.php`
  (`restv2:webmail-handoffs:sweep`, `SCAN restv2:webmail-handoff:*`, `SessionRegistry::isAlive`,
  deletes per R3, logs a summary line).
- [X] T076 [US5] Register `WebmailHandOffService::endForHandle()` as a `SessionEndListener` (T020)
  and make 001's `SessionController@destroy` add `Set-Cookie: <SESSION_NAME>=deleted; Max-Age=0;
  Path=/` when the session had a hand-off (extends 001).
- [X] T077 [US5] Create `app/Http/Resources/Api/V2/WebmailHandOffResource.php` and
  `app/Http/Controllers/Api/V2/WebmailHandOffController.php`; register `POST /me/webmail-handoff`
  in group `restv2.own` (`restv2.auth:session`, `restv2.csrf`, `restv2.pending:enforce`,
  `restv2.audit` target `webmailHandOff`). This makes T070–T072 pass.
- [X] T078 [US5] Create `app/Services/Account/MailboxOverviewService.php` (queries of R18 through
  003's `MailboxReader`, 004's `Alias`/`AliasDomain`, 003's `SenderAcl`, `SpamAlias`, `Pushover`),
  `app/Http/Resources/Api/V2/MailboxOverviewResource.php` and
  `app/Http/Controllers/Api/V2/OwnMailboxOverviewController.php`; register `GET /me/mailbox` in group
  `restv2.own`. This makes T073 pass.

**Checkpoint**: users reach webmail from the new web app without a second sign-in.

---

## Phase 8: User Story 6 - Use temporary aliases (Priority: P2)

**Goal**: temporary alias CRUD and the domain list for every permitted caller (FR-042 to FR-046,
FR-081).

**Independent test**: create aliases in the mailbox domain, an alias domain and an unrelated
domain; change lifetimes; let them expire; repeat as each role.

### Tests for User Story 6 ⚠️

- [X] T079 [P] [US6] Write `tests/Unit/Services/TemporaryAliasAddressGeneratorTest.php` (FR-044):
  1,000 generated local parts match `^[bcdfghjklmnprstvwxyz][aeiou]...` alternating, two parts of 6–8
  letters joined by `.`; a candidate equal to an existing mailbox, resource, alias, temporary alias
  or (for an alias domain) target-domain address is skipped; ten collisions in a row → `503
  temporary-failure` (`addressGeneration`); the generator uses `random_int` (seeded fake).
- [X] T080 [P] [US6] Write `tests/Feature/TemporaryAliases/TemporaryAliasCrudTest.php` (FR-042 to
  FR-046, AS-1 to AS-3, AS-5): create in the mailbox domain → `201`, `Location` with the address,
  `validity` ≈ now + 8,760 h, `permanent 0`, `goto` = mailbox, description stored (`''` when absent);
  in an alias domain (Unicode input) → created; in an unrelated domain → `422 notInList` with
  `allowed`; `lifetimeHours` 0 and 87,601 → `outOfRange`; `permanent: true` → `expiresAt null`;
  description 256 characters → `tooLong`; patch to permanent → `validity 0, permanent 1`; patch to 1
  hour → `validity` ≈ now + 3,600; both members → `422`; after the expiry (time travel) the alias is
  not listed and get, patch and delete answer `404`; delete → row gone; list sort, order,
  `permanent` filter and `q`; each entry's `etag` equals the single read's `ETag`; changes audited
  with target id = mailbox and `details.temporaryAlias`.
- [X] T081 [P] [US6] Write `tests/Feature/TemporaryAliases/TemporaryAliasAuthorizationTest.php`
  (AS-4): mailbox user without `spam_alias` → reads allowed, changes `403 permission-flag-required`
  (`spamAlias`); domain admin inside scope → allowed (holds the flag), outside → `404`; admin any;
  keys per scope (read-only refused for changes); an alias of another mailbox addressed under the
  caller's mailbox → `404`; refusals audited.
- [X] T082 [P] [US6] Write `tests/Feature/TemporaryAliases/TemporaryAliasDomainsTest.php` for
  `listTemporaryAliasDomains` (FR-081): the mailbox domain first (`mailboxDomain`) then its alias
  domains by name (`aliasDomain`, ASCII and Unicode forms), equal to the overview's list; admin,
  domain admin inside scope (outside → `404`), the user themself and read-only keys may read; `q`
  filters.
- [X] T083 [P] [US6] Write `tests/Feature/TemporaryAliases/TemporaryAliasConcurrencyTest.php`: a
  temporary alias creation and a 004 alias creation for the same generated address (forced through a
  seeded generator) in parallel processes never both succeed (domain row lock).

### Implementation for User Story 6

- [X] T084 [P] [US6] Create `app/Services/TemporaryAliases/TemporaryAliasAddressGenerator.php` (uses
  003's `AddressAvailability` plus the alias-domain check). This makes T079 pass.
- [X] T085 [US6] Create `app/Services/TemporaryAliases/TemporaryAliasService.php` (R12: domains,
  domain row lock `FOR UPDATE`, insert, change, delete, visibility rule, `ETag`, `PermissionGate`
  `spamAlias`, audit) on 003's `SpamAlias` model.
- [X] T086 [US6] Create `app/Http/Requests/Api/V2/{ListTemporaryAliasesRequest,StoreTemporaryAliasRequest,UpdateTemporaryAliasRequest,ListTemporaryAliasDomainsRequest}.php`
  (constraints of DM `spamalias`), `app/Http/Resources/Api/V2/{TemporaryAliasResource,TemporaryAliasDomainResource}.php`
  and the controllers `TemporaryAliasController.php` and `TemporaryAliasDomainController.php`;
  register the six routes (`restv2.auth:authenticated`, `restv2.csrf` on unsafe methods,
  `restv2.audit` target `temporaryAlias`). This makes T080–T083 pass.

---

## Phase 9: User Story 7 - Adjust mailbox settings and push notifications (Priority: P2)

**Goal**: tagged-mail handling and TLS enforcement (single and bulk) and Pushover settings with
verification (FR-054 to FR-060).

**Independent test**: change each setting as every role, including the bulk TLS action; save,
verify and remove Pushover settings with valid and invalid values.

### Tests for User Story 7 ⚠️

- [X] T087 [P] [US7] Write `tests/Feature/MailSettings/MailboxMailSettingsTest.php` (FR-058, FR-059,
  FR-072, AS-1 to AS-3): read reflects `RCPT_WANTS_*` (subject wins when both are set) and
  `tls_enforce_*` stored as `1`, `"1"`, `true`; `subfolder` → `HSET RCPT_WANTS_SUBFOLDER_TAG`, `HDEL`
  the other; `none` → both removed; TLS → `JSON_SET` integers; `ETag` covers only the three values
  (changing the mailbox quota leaves it unchanged); a user without `tls_policy` changing TLS → `403
  permission-flag-required` (`requiredFlags: [tlsPolicy]`), sending the current TLS values with a changed tagging
  value → `200`; without `delimiter_action` likewise (`delimiterAction`); Redis down during a change
  with TLS and tagging → `503 temporary-failure` (`sharedStore`) and the TLS columns unchanged; stale
  `If-Match` → `412`; audited with old and new values; scope and keys as T064.
- [X] T088 [P] [US7] Write `tests/Feature/MailSettings/MailboxMailSettingsBulkActionTest.php` for
  `applyMailboxMailSettingsBulkAction` (FR-060, AS-4): 30 targets with one deleted mailbox → 29
  `done` (each with its new `version`), one `notFound`, summary counts, 29 audit records with the
  same `bulkRequestId`; a target with an outdated `version` → `outdated`, the others proceed; a
  mailbox outside a domain admin's scope → `notFound`; a duplicate address processed once; 101
  targets → `422 tooMany`; neither TLS member → `422`; a mailbox user → `403 forbidden`; a read-only
  key → `insufficient-key-scope`; a Redis-independent failure of one item → `failed` with its problem.
- [X] T089 [P] [US7] Write `tests/Feature/PushNotifications/PushNotificationSettingsTest.php`
  (FR-054 to FR-056, AS-5 to AS-8): unconfigured read → `configured false`, defaults (`sound
  pushover`, `active true`), no token/key fields; replace with 30-character token and key, title,
  text, one sender, sound `bike` → row as `pushover('edit')` writes it (`attributes` with string
  `"0"`/`"1"` and `sound`), read shows `tokenSet`/`userKeySet` true and never the values; omitting
  token and key later keeps them; first save without them → `422 secretRequired` on both; 29
  characters → `invalidFormat` (`pushoverCredential`); invalid sender → `invalidFormat` on
  `senders.0`; 101 senders → `tooMany`; `john.doe@gmail.com` stored `johndoe@gmail.com`; a pattern
  that does not compile → `invalidRegex`; sound `a b` → `invalidFormat` (`soundName`); delete →
  row gone; tag changes when only the token changes and never contains a plain hash of it; domain
  admin without `pushover` → `403 permission-flag-required`; audit with credentials as `set` or
  `changed` and texts as sizes.
- [X] T090 [P] [US7] Write `tests/Feature/PushNotifications/PushNotificationVerificationTest.php`
  (FR-057) with `FakePushoverClient`: accepted → `{accepted: true, reason: null}`; rejected →
  `accepted false` with Pushover's errors; unreachable → `503 temporary-failure` (`pushover`); no
  settings → `409 push-notifications-not-configured`; a read-only key → `insufficient-key-scope`;
  audited; the fake received the stored token and key.
- [X] T091 [P] [US7] Write `tests/Unit/Clients/HttpPushoverClientTest.php` with `Http::fake()`: `POST`
  form fields `token` and `user` to the configured URL, 10 s timeout; 200 `status: 1` → accepted; 400
  `status: 0` with `errors` → rejected with reason; 5xx and connection errors → temporary failure.

### Implementation for User Story 7

- [X] T092 [P] [US7] Create `app/Clients/Pushover/{PushoverClient,HttpPushoverClient,PushoverVerdict}.php`
  and `tests/Support/Fakes/FakePushoverClient.php`; bind in `app/Providers/AppServiceProvider.php`.
  This makes T091 pass.
- [X] T093 [US7] Create `app/Services/MailSettings/MailSettingsService.php` (R15, on 003's
  `TaggedMailHandlingStore` and 002's `SharedStore`, transaction with Redis last and compensation,
  `ETag`, flags, audit) and the bulk action through `BulkActionRunner`.
- [X] T094 [US7] Create `app/Http/Requests/Api/V2/{UpdateMailboxMailSettingsRequest,MailboxMailSettingsBulkActionRequest}.php`,
  `app/Http/Resources/Api/V2/MailboxMailSettingsResource.php` and the controllers
  `MailboxMailSettingsController.php` (`show`, `update`) and
  `MailboxMailSettingsBulkActionController.php`; register `POST /mailboxes/mail-settings/bulk-actions`
  before 003's `/mailboxes/{mailbox}` routes and the two mail-settings routes (`restv2.audit` target
  `mailSettings`). This makes T087 and T088 pass.
- [X] T095 [US7] Create `app/Services/PushNotifications/PushNotificationService.php` (R14, 003's
  `Pushover` model, `ETag` with `hmac(key)` and `hmac(token)`, flags, audit),
  `app/Http/Requests/Api/V2/ReplacePushNotificationSettingsRequest.php`,
  `app/Http/Resources/Api/V2/{PushNotificationSettingsResource,PushNotificationVerificationResource}.php`,
  and the controllers `PushNotificationSettingsController.php` and
  `PushNotificationVerificationController.php`; register the four routes (`restv2.audit` target
  `pushNotificationSettings`). This makes T089 and T090 pass.

---

## Phase 10: User Story 8 - Review and clear recent logins (Priority: P2)

**Goal**: recent logins with grouping, internal marking, countries within a time budget, and
clearing (FR-061 to FR-063).

**Independent test**: load `sasl_log` rows for several services and addresses, list them for 1–31
days as each role, clear them and compare with legacy's queries.

### Tests for User Story 8 ⚠️

- [ ] T096 [P] [US8] Write `tests/Feature/RecentLogins/RecentLoginListTest.php` (FR-061, AS-1 to
  AS-3, AS-5, AS-6): IMAP from one public address on three days plus a web sign-in → two entries,
  the IMAP one with the latest time; an app-password login names the app password (and `name: null`
  once it was deleted); `MAILCOWUI` and `WEBUI` both reported as `WEBUI` and both matched by
  `service=webui`; `10.0.0.5` and `172.22.1.1` → `internal: true`, `country: null`; `days` 32 →
  `422 outOfRange` (`max: 31`); a row 8 days old is excluded with the default; pagination after
  grouping, sort `service`, `clientAddress`, `q`; the result equals legacy's grouping query run
  verbatim on the same rows; domain admin outside scope → `404`; reads need no flag.
- [ ] T097 [P] [US8] Write `tests/Feature/RecentLogins/RecentLoginCountryTest.php` (FR-062, SC-010)
  with `FakeCountryLookup`: a cached `IP_SHORTCOUNTRY` value is used without a lookup; an uncached
  public address is looked up and written back with `HSET`; lookups of one page run concurrently and
  a slow address (3 s) is reported without a country while the response returns within 2 s;
  `RESTV2_COUNTRY_LOOKUP=false` → no lookup; Redis down → `503 temporary-failure`.
- [ ] T098 [P] [US8] Write `tests/Feature/RecentLogins/RecentLoginClearTest.php` (FR-063, AS-4): the
  mailbox user clears → `204`, every `sasl_log` row of the mailbox gone and the `logs` rows that
  legacy's statement matches gone; a domain admin clears → `sasl_log` gone, `logs` untouched; an
  administrator → both; audited (`recentLoginsClear`); a read-only key → `insufficient-key-scope`;
  a login recorded after the clear appears afterwards.
- [ ] T099 [P] [US8] Write `tests/Unit/Clients/HttpCountryLookupTest.php` with `Http::fake()`: `GET
  <url><ip>` with `User-Agent: Moocow`, pooled requests, `shortcountry` parsed, errors and timeouts
  give no country.

### Implementation for User Story 8

- [ ] T100 [P] [US8] Create `app/Clients/Geo/{CountryLookup,HttpCountryLookup}.php` and
  `tests/Support/Fakes/FakeCountryLookup.php`; bind in `AppServiceProvider`. This makes T099 pass.
- [ ] T101 [US8] Create `app/Services/RecentLogins/RecentLoginService.php` (R16: grouped query,
  service mapping, internal flag, cache and budgeted lookups after pagination, clear statements of
  DM, audit).
- [ ] T102 [US8] Create `app/Http/Requests/Api/V2/ListRecentLoginsRequest.php` (`days` 1–31 default
  7, `service` ≤ 32, `ListQuery`), `app/Http/Resources/Api/V2/RecentLoginResource.php` and
  `app/Http/Controllers/Api/V2/RecentLoginController.php` (`index`, `destroy`); register the routes
  (`restv2.audit` target `recentLogins` on `DELETE`). This makes T096–T098 pass.

---

## Phase 11: User Story 9 - Manage mailbox Sieve filters (Priority: P3)

**Goal**: administrator-managed filters with legacy validation and activation rules, the user's
read access, and the active script (FR-047 to FR-053).

**Independent test**: create, validate, activate, change and delete filters as administrator and
domain administrator with and without `filters`; list as the user; read the active script.

### Tests for User Story 9 ⚠️

- [ ] T103 [P] [US9] Write `tests/Unit/Sieve/SieveValidatorTest.php`: valid scripts pass; for a set
  of invalid scripts the message equals the exception message of the legacy parser loaded from the
  tag in a subprocess (`resources/legacy/sieve/` unchanged, `ORIGIN` hashes match).
- [ ] T104 [P] [US9] Write `tests/Feature/SieveFilters/SieveFilterCrudTest.php` (FR-048 to FR-051,
  AS-1, AS-2): create active prefilter while another is active → the old one `inactive`, the new one
  `active`, in `sieve_before` view output; changing an active prefilter to postfilter deactivates the
  active postfilter; syntax error → `422 sieveSyntax` with `message`, nothing saved; 65,536 bytes →
  `tooLong` (`unit: bytes`); description 0 or 256 characters → `tooShort`/`tooLong`; patch only sent
  members, mailbox never changes; delete; `ETag` and `412`; audit with the script as sizes; two
  concurrent activations leave one active filter (named lock).
- [ ] T105 [P] [US9] Write `tests/Feature/SieveFilters/SieveFilterAuthorizationTest.php` (AS-3, AS-4):
  domain admin without `filters` → create, patch, delete `403 permission-flag-required`
  (`filters`), lists allowed; mailbox user → own list allowed, create `403 forbidden`, foreign
  mailbox `404`; admin any; keys (read-only lists allowed, changes refused); refusals audited.
- [ ] T106 [P] [US9] Write `tests/Feature/SieveFilters/SieveFilterListTest.php` (FR-047, AS-6): 300
  filters across 120 mailboxes; `GET /sieve-filters` as admin with `mailbox` and `kind` filters →
  only matches, paginated; default order mailbox then description; a domain admin sees only the
  mailboxes of their domains; a mailbox user → `403 forbidden`; `q` on description and mailbox.
- [ ] T107 [P] [US9] Write `tests/Feature/SieveFilters/SieveScriptValidationTest.php` (FR-052):
  valid → `{valid: true, message: null}`; invalid → `{valid: false, message}`; a read-only key of an
  administrator → `200` (read-only marker); a mailbox user → `403 forbidden`; nothing is written or
  audited.
- [ ] T108 [P] [US9] Write `tests/Feature/SieveFilters/ActiveSieveScriptTest.php` (FR-053, AS-5)
  with `FakeSieveScriptReader` and, separately, `tests/Unit/Clients/DockerApiSieveScriptReaderTest.php`
  with 002's `FakeDockerApiClient`: list output `roundcube ACTIVE` → print called with
  `script_name roundcube`, first output line removed, `{name, script}` returned; no active line →
  `{name: null, script: null}`; dockerapi unreachable or timeout → `503 temporary-failure`
  (`mailStore`); scope as T105; the fake asserts the exec bodies of DM.

### Implementation for User Story 9

- [ ] T109 [P] [US9] Create `app/Support/Sieve/SieveValidator.php`. This makes T103 pass.
- [ ] T110 [P] [US9] Create `app/Clients/Dovecot/{SieveScriptReader,DockerApiSieveScriptReader,ActiveSieveScript}.php`
  (over 002's `DockerApiClient::exec`, `DOCKER_TIMEOUT`) and `tests/Support/Fakes/FakeSieveScriptReader.php`;
  bind in `AppServiceProvider`.
- [ ] T111 [US9] Create `app/Models/Mailcow/SieveFilter.php` and
  `app/Services/SieveFilters/SieveFilterService.php` (R13: validation, activation under `NamedLock`
  `restv2_sieve_<sha1(mailbox)>`, scope-joined lists, `ETag`, `filters` flag, audit).
- [ ] T112 [US9] Create `app/Http/Requests/Api/V2/{ListSieveFiltersRequest,ListMailboxSieveFiltersRequest,StoreSieveFilterRequest,UpdateSieveFilterRequest,ValidateSieveScriptRequest}.php`
  (constraints of DM `sieve_filters`), `app/Http/Resources/Api/V2/{SieveFilterResource,SieveScriptValidationResource,ActiveSieveScriptResource}.php`
  and the controllers `SieveFilterController.php` (`index`), `MailboxSieveFilterController.php`,
  `SieveScriptValidationController.php` and `ActiveSieveScriptController.php`; register the routes
  (`POST /sieve-filters/validation` with `->defaults('restv2.readOnly', true)`; `restv2.audit`
  target `sieveFilter` on changes). This makes T104–T108 pass.

---

## Phase 12: User Story 10 - Reset the ActiveSync cache and the groupware profile (Priority: P3)

**Goal**: the two groupware resets as legacy runs them (FR-064 to FR-066).

**Independent test**: reset both as each role with and without the flags and with the groupware
disabled; compare the remaining `sogo_*` rows.

### Tests for User Story 10 ⚠️

- [ ] T113 [P] [US10] Write `tests/Feature/Groupware/GroupwareResetTest.php` (AS-1 to AS-4): with
  `MailcowFactory::sogoProfileRows` for two mailboxes, the ActiveSync reset removes only the
  mailbox's `sogo_cache_folder` rows; the profile reset removes exactly the rows of DM (including
  `sogo_acl` grants to the mailbox and objects of the mailbox, with `_` and `%` in the address not
  over-matching) and leaves the other mailbox's rows; without `eas_reset`/`sogo_profile_reset` →
  `403 permission-flag-required`; domain admin inside scope allowed, outside `404`; `SKIP_SOGO=y` →
  `403 groupware-disabled`; read-only key refused; audited (`activeSyncCacheReset`,
  `groupwareProfileReset`).

### Implementation for User Story 10

- [ ] T114 [US10] Create `app/Services/Groupware/GroupwareResetService.php` (R17, one transaction
  with the audit record) and the controllers `ActiveSyncCacheController.php` and
  `GroupwareProfileController.php` (`destroy`); register the two routes (`restv2.audit` target
  `groupwareData`). This makes T113 pass.

---

## Phase 13: User Story 11 - Trace self-service changes (Priority: P3)

**Goal**: every change and attempt of this feature is in the audit log, filterable by target type
and the mailbox, without secrets (FR-073, FR-074).

**Independent test**: perform each kind of change and refusal, list the audit log filtered by
target, and scan every record for secrets.

### Tests for User Story 11 ⚠️

- [ ] T115 [P] [US11] Write `tests/Feature/Audit/SelfServiceAuditTest.php` (AS-1 to AS-3): after a
  password change and an app password creation, `GET /audit-events?targetId=<mailbox>` (002's
  filter) as admin returns both with actor, time and client address; `targetType=appPassword`
  narrows; a reset request for an unknown address appears with the attempted address and
  `unknownAccount`; every event type of R22 is produced once by its operation (table-driven over
  T030–T114 fixtures) and validates against `AuditEventType`, `AuditTarget` and
  `AuditFailureReason`; a mailbox user → `403 forbidden` (001).
- [ ] T116 [P] [US11] Write `tests/Feature/Security/SelfServiceSecretLeakTest.php` (FR-076, SC-006):
  runs a scripted pass over every operation with known secrets (passwords, TOTP secret after the
  start, Yubico key, codes, WebAuthn response, reset token, app password, Pushover token and key)
  and asserts that none appears in any response body after creation (except the TOTP start and the
  generated app password), in the captured log output (`stderr_json` channel to a test handler), in
  `restv2_audit_events` or in `ETag` values.

### Implementation for User Story 11

- [ ] T117 [US11] Complete the `details` whitelists and target resolvers of T024 for any event type
  the tests show missing, and add a data-provider of all self-service event types to
  `tests/Feature/Audit/SelfServiceAuditTest.php`. This makes T115 and T116 pass.

---

## Phase 14: User Story 12 - Set up mail clients (Priority: P3)

**Goal**: the Apple connection profile (with an optional embedded app password) and the client
configuration (FR-082, FR-083).

**Independent test**: download both profile variants with and without an app password and compare
them with legacy `mobileconfig.php`; read the client configuration with the groupware on and off.

### Tests for User Story 12 ⚠️

- [ ] T118 [P] [US12] Write `tests/Feature/OwnAccount/ClientConfigurationTest.php` for
  `getOwnClientConfiguration` (FR-083, AS-3, AS-4, AS-5): IMAP `tlsPort` 993 and `starttlsPort` 143
  from the env (`IMAPS_PORT=127.0.0.1:10993` → 10993); SMTP 465/587; POP3 995/110; CalDAV/CardDAV on
  443 with principal path `/SOGo/dav/<address>/`, and on 8443 when the request's host carries
  `:8443`; an operator override of `autodiscover_config` in `vars.local.inc.php` wins; `SKIP_SOGO=y`
  → `caldav`, `carddav`, `activeSync` `null`; `outlookUsesActiveSync` from `useEASforOutlook`;
  `documentationUrl` equals the legacy link built from `clientconfigstr`; admin → `forbidden`; key →
  `session-required`.
- [ ] T119 [P] [US12] Write `tests/Feature/OwnAccount/AppleProfileTest.php` for `getOwnAppleProfile`
  and `createOwnAppleProfileWithAppPassword` (FR-082, AS-1 to AS-3, AS-5):
  - `variant=email` → `200`, `Content-Type: application/x-apple-aspen-config`,
    `Content-Disposition: attachment; filename="<product name>.mobileconfig"`, `Cache-Control:
    no-store`; the plist parses, has one `com.apple.mail.managed` payload with the keys and order of
    legacy `mobileconfig.php`, IMAP and SMTP host and ports from the autodiscover settings, the
    identifier `org.example.anna.appleprofile.<ProductName alnum>`, no password; no app password row
    created and no audit record;
  - default variant → plus CalDAV (`<real>` port) and CardDAV (`<integer>` port) payloads;
    `SKIP_SOGO=y` → `403 groupware-disabled`;
  - a display name `A & <B>` is XML-escaped; the file compares equal to
    `tests/fixtures/legacy/mobileconfig/2026-09a-email.plist` and `-full.plist` (rendered from the
    legacy template with fixed UUIDs) after replacing UUIDs;
  - `POST` with `appPasswordName` → `201`, `Location` of the new app password, the profile embeds a
    generated 32-character password in `IncomingPassword`, `CalDAVPassword`, `CardDAVPassword`; the
    app password row has IMAP, SMTP and DAV, `active 1`, and verifies the embedded password; one
    `appPasswordCreation` audit record with `source: appleProfile` and no password;
  - `POST` without a name and `User-Agent` containing `iPhone` → name `iPhone`; an unknown 300-byte
    user agent → name cut to 255; email variant → no DAV protocol;
  - without `app_passwds` → `403 permission-flag-required` (`requiredFlags: [appPasswds]`); admin → `forbidden`;
    restricted session → `pending-action-required`; key → `session-required`.

### Implementation for User Story 12

- [ ] T120 [P] [US12] Create `tests/fixtures/legacy/mobileconfig/2026-09a-email.plist` and
  `2026-09a-full.plist` by rendering the tag's `mobileconfig.php` template body with fixed values
  (address, display name, product name, ports, UUIDs `00000000-…`), documented in a `README` next to
  them.
- [ ] T121 [US12] Create `app/Services/Account/ClientConfigurationService.php` (R18) and
  `app/Http/Resources/Api/V2/MailboxClientConfigurationResource.php` and
  `app/Http/Controllers/Api/V2/OwnClientConfigurationController.php`; register `GET
  /me/mailbox/client-configuration` in group `restv2.own`. This makes T118 pass.
- [ ] T122 [US12] Create `app/Services/Account/AppleProfileBuilder.php` (`XMLWriter`, legacy key
  order, identifiers, v4 UUIDs), `app/Http/Requests/Api/V2/{GetAppleProfileRequest,CreateAppleProfileRequest}.php`
  and `app/Http/Controllers/Api/V2/OwnAppleProfileController.php` (`show`; `store` creates the app
  password through `AppPasswordService` with `source: appleProfile`); register `GET` and `POST
  /me/mailbox/apple-profile` in group `restv2.own` (`restv2.audit` event `appPasswordCreation`
  target `appPassword` on `POST`). This makes T119 pass.

---

## Phase 15: Polish and cross-cutting concerns

- [ ] T123 [P] Write `tests/Feature/Performance/SelfServiceListLatencyTest.php` (`@group
  performance`, SC-010): 25-entry pages of app passwords, temporary aliases, filters and recent
  logins (with 200,000 `sasl_log` rows) answer within 1 s at p95 over 50 runs; a recent-login page
  needing lookups (fake lookup with 1.8 s latency) within 2 s.
- [ ] T124 [P] Write `tests/Feature/Security/SelfServiceAuthorizationMatrixTest.php` (SC-007): one
  data-driven test over all 51 operations × {admin, domain admin in scope, domain admin out of
  scope, mailbox user own, mailbox user foreign, read-only admin key, read-write admin key, read-only
  and read-write domain-admin keys, restricted session, anonymous} asserting the status of plan
  "Contract Operations" and FR-005 (`404` for out-of-scope objects, `403` for roles, flags and key
  scope, `401` without credentials).
- [ ] T125 Extend `database/seeders/DevelopmentSeeder.php` with the Part B data of quickstart.md
  (`PW_RESET_FROM`/`PW_RESET_SUBJ`, an alias domain, two app passwords, a filter, `sasl_log` rows).
- [ ] T126 Run quickstart.md Part A and Part B against the dev stack and fix any deviation; confirm
  `build/contract/coverage.json` lists the 51 operations and that no migration was added.
- [ ] T127 [P] Run `vendor/bin/pint --test` and `vendor/bin/phpstan analyse` (level 6) and fix all
  findings in the files of this feature.
- [ ] T128 [P] Add the release note entry for the contract additions of feature 005 (51 operations,
  shared enumeration values, problem types) to the release notes file the project keeps, so that
  mailcowui-ng can bump its pinned contract (constitution workflow).

---

## Dependencies and execution order

- **Setup (Phase 1)**: T001–T002 first (image and Composer), then T003–T008 in parallel.
- **Foundational (Phase 2)**: depends on Setup. Tests T009–T017 in parallel, then T018–T029
  (T020 before T076; T023 and T024 before every mailbox-object story; T027 before T028, T074,
  T121).
- **User stories** depend on Phase 2 only, except:
  - US5's ending tests (T071) use US1's password change and US3's reset; implement US5 after US1 and
    US3, or mark those cases incomplete until then.
  - US12's embedded app password uses US4's `AppPasswordService` (T067).
  - US11 (T115–T117) runs after the stories whose events it checks.
- **Within a story**: tests first (observed failing), then models and clients, services, requests
  and resources, controllers and routes.
- **Polish (Phase 15)**: after all stories.

Story order by priority: US1 → US2 → US3 → US4 (P1, MVP) → US5 → US6 → US7 → US8 (P2) → US9 → US10
→ US11 → US12 (P3).

## Parallel opportunities

- Phase 2 tests T009–T017 together; T019, T022, T025, T026 together.
- After Phase 2, US1, US2, US4, US6, US7, US8, US9 and US10 can proceed in parallel (different
  services and files); US3 after US1 (shares the new-password validator and current-password path);
  US5 after US1 and US3; US12 after US4.
- Inside each story every test task marked [P] runs together, e.g. US2: T035–T042.

## Implementation strategy

- **MVP**: Phases 1–6 (US1–US4): forced actions, own password and second factors, password reset and
  app passwords. This removes every remaining reason for mailbox users to open the legacy UI for
  credentials.
- **Increment 2**: US5–US8 (webmail hand-off, overview, temporary aliases, settings, recent logins).
- **Increment 3**: US9–US12 and Polish.
- Each story ends at a checkpoint where its independent test passes and the suite is green.

## Implementation notes (2026-10-18)

Deviations and decisions made while implementing, with the reason. Names in tasks and research
that differ from what exists map as listed.

- **Reused pieces (research R1) and their real names**: `App\Support\Database\NamedLock` (003),
  `App\Services\Audit\RefusalAuditor` (002, route default `restv2.audit`),
  `App\Exceptions\PermissionFlagRequiredException` and `ProblemType::PermissionFlagRequired` (002);
  `App\Services\Mailboxes\{MailboxScope, MailboxAddress, AddressLock, AddressAvailability,
  TaggedMailHandlingStore, MailboxReader}` and `App\Services\Passwords\{PasswordPolicyReader,
  EffectivePasswordPolicy, PasswordPolicyChecker, MailboxPasswordHasher}` (003);
  `App\Services\Aliases\AliasAddressAvailability` and
  `App\Services\SenderSettings\SenderSettingsReader::deliversTo()` (004);
  `App\Support\Bulk\BulkActionRunner`, `App\Clients\SharedStore\SharedStore`,
  `App\Clients\DockerApi\DockerApiClient` (+ `FakeDockerApiClient`),
  `App\Services\Groupware\{SogoDirectory, GroupwareRefresh, GroupwareCacheFlush}`,
  `App\Clients\Memcached\MemcachedFlusher` (002); 001's `SessionManager`, `SecondFactorService`,
  `SignInThrottle`, `LegacyPasswordVerifier`, `YubicoOtpVerifier`, `WebAuthnServerFactory`,
  `OptionsJson`, `RecentLoginRecorder`, `ProductName`, `MailcowConfig`, `AuditLogger`, `ETag`
  (what research calls `ETag` over a canonical string is `ETag::of()`, an HMAC keyed with the app
  key) and `ListQuery`. 003's `AddressAvailability` is reached through 004's
  `AliasAddressAvailability`, which adds legacy's alias-domain clause.
- **T014, T022**: `NamedLock` exists (003, with its tests), so neither was written again.
- **T018**: `MailcowRows` already had `appPassword()`, `spamalias()`/`temporaryAlias()`,
  `sieveFilter()`, `pushover()`, `saslLog()`, `sogoData()`, `senderAcl()`, `quota2()`; the rows this
  feature needs beyond them are `appPasswd()` (encoded name, protocols, state),
  `temporaryAliasRow()` (PHP method names are case-insensitive, so `spamAlias()` would clash with
  `spamalias()`), `sieveFilterRow()`, `pushoverSettings()`, `resetToken()`, `saslLogEntry()` and
  `logsCheckLogin()`.
- **T020**: the registry's lifetime is refreshed by `SessionManager::register()`, which
  `EnsureSessionIntegrity` calls on every request of a signed-in session; it also gives a handle
  to a session signed in before this feature. The `SessionEndListener`s are resolved from the
  container tag `restv2.session-listeners`; they are told when a session ends and when it served a
  request (the hand-off set's lifetime follows the session).
- **T024**: the detail whitelists live in `AuditEventType::detailKeys()` (001's design, as 004
  noted), not in `AuditLogger`. `PermissionFlagRequiredException` now carries `requiredFlags` as an
  audit detail, which feature 005's event types keep (`docs/audit-events.md`).
- **T028**: the capabilities need the session of a signed-in caller but must also answer signed-out
  callers and API keys. `restv2.auth:public` never starts a session, so `RequireAuth` gained the
  mode `optional` (anyone, with the browser session when there is one). No resource class: the
  controller returns the service's array, as 003's `PasswordPolicyController` does. Without
  mailcow's shared store the reset is reported unavailable instead of answering `503`, which the
  operation does not document.
- **T027**: the export runs with `HTTP_HOST = <MAILCOW_HOSTNAME>:__RESTV2_HTTPS_PORT__`; without the
  mounted files the autodiscover settings are built from `MAILCOW_HOSTNAME` and the mail ports in
  `config('restv2.ports')`, parsed as vars.inc.php parses them. Symfony's Process passes `$_ENV`
  before `getenv()`, so tests that change a port set all three.
- **Contract (T029, G25)**: besides the texts the task lists, `me-password.yaml` named a rule code
  `passwordPolicy` that does not exist; it now names 003's `tooShort` and `missingCharacterClass`,
  as research R4 decided.
- **Legacy Sieve parser**: copied unmodified under `resources/legacy/sieve/` and excluded from Pint.
  It triggers a compile warning (`continue` targeting `switch`) and dynamic-property deprecations
  under PHP 8.4, so `SieveValidator` loads and runs it under a scoped error handler.
- **T030–T034 (US1)**: the re-authentication shared by US1–US3 is
  `App\Services\Account\CurrentPasswordCheck` (001's `SignInThrottle::passwordKey()`, the same
  delay and `429`, no fail2ban). The route group `restv2.own` is an empty middleware group in
  `bootstrap/app.php`, which feature 007 fills. No `OwnPasswordChangeResultResource`: the controller
  returns the service's array. Other sessions are ended inside the transaction, so that the audit
  record carries `sessionsEnded`; the groupware rebuild (002's `SogoDirectory::rebuild()`, which
  flushes memcached itself) runs after the commit.
- **T040**: the concurrency of removals is shown by holding the account's named lock
  `restv2_tfa_<sha1(username)>` from a second database session (a removal then waits and fails
  with 003's lock timeout, `500`), as 003's `AddressLockTest` does, instead of two PHP processes:
  the tests run inside one database transaction that other processes cannot see. A lock timeout
  answers `500` as 003 decided, not `503` as data-model.md says.
- **T044**: 001's `YubicoOtpVerifier::verify()` already takes the API credentials as arguments, so
  no `verifyWithCredentials()` was needed; the enrolment passes the given ones.
- **T046**: the WebAuthn options keep lbuchs's authenticator selection
  (`OptionsJson::secondFactorCreation()`, no resident key), so `userVerification` is `preferred`
  for `WEBAUTHN_UV_FLAG_REGISTER = false`, exactly as legacy's options. The attestation check
  requires user verification only for `true` or `required` (legacy passes the flag through, so a
  `preferred` string would require it). A new sign-in discards open enrolments and options
  (`SessionManager::promote()`). `SecondFactorService::markTotpCodeUsed()` exposes 001's replay
  marker for the confirming code; `promote()` records the factor that completed a sign-in
  (`restv2.principal.secondFactorId`).
- **T048**: no `ListOwnSecondFactorsRequest`; the controller uses 001's `ListQuery`. Sorting by
  `type` sorts by the type name (`CAST(authmech AS CHAR)`), not by the ENUM's index.
- **T051, G27**: the default reset page is `/ng/reset-password#token={token}`: the token travels in
  the URL fragment, which browsers never send to a server, so it cannot reach an access log (UI 005's
  gap G27, later than the orchestrator's query-string decision in analysis.md). Operators keep the
  legacy page with `RESTV2_PW_RESET_PAGE=/reset-password?token={token}`.
- **T052**: the timing test runs 100 requests per class with a 100 ms fake transport (instead of 200
  with 300 ms), measures to the end of the response by driving the kernel itself and runs the
  deferred work in `terminate()`, outside the measurement. It is in the `performance` group, which
  main now runs apart from `composer test` (`composer test:performance`).
- **T053, T061**: token checks do not depend on the reset being configured: a token issued earlier
  can always be checked and used. `createPasswordResetWebAuthnChallenge` counts on the token-check
  limit. `InvokeDeferredCallbacks` is in Laravel's default global middleware, so `bootstrap/app.php`
  needed no change (T059).
- **T054, T060**: a failed reset second factor counts on 001's second-factor count
  (`restv2:secondfactor:<sha256(username|ip)>`), not on the password count: 001 split the two after
  005 was planned, and only a completed sign-in or reset clears the second-factor count.
  `SecondFactorService::verifyForAccount()` reuses the sign-in's checks (and their replay
  protection) without promoting a session; `issueWebAuthnChallengeFor()` issues the reset challenge
  under `restv2.passwordResetWebauthn`, bound to the token's SHA-256. A completion without a proof
  answers `401 second-factor-required` with `methods` and is not audited; failures are.
- **T056**: the recovery address is read from the decoded `attributes`; `RecoveryEmailService`
  checks, in this order, the role and source (`403`), the body (`422`), `If-Match` (`412`), the
  current password and the `pwReset` flag. The `EmailAddress` rule (`invalidFormat`, `format:
  email`) is shared with the reset request and the Pushover senders.
- **T065**: the expected rows are derived line by line from legacy app_passwd('add')
  (`tests/fixtures/legacy/app_passwd_add_expected.php`, with the source's SHA-256), as 002's
  `domain_add_expected.php` does, instead of running the legacy function.
- **T066–T068**: the version tag carries `SHA2(app key ‖ hash)` computed by MariaDB, so the hash
  never leaves the database, inside 001's HMAC tag. `generatedPassword` is `null` unless the system
  generated one. A change whose values equal the stored ones needs no flag and is audited with empty
  `changes`. `ExcludesMember` (a data-aware coded rule) reports `mutuallyExclusive` with `with`.
  No resource classes: the services build the contract arrays, as 004's presenters do.
  `MailcowDateTime::toUtcString()` converts query-builder DATETIME values.
- **T069–T072**: `LegacyFpmRunner` starts `php-fpm -F -y` with its own pool (phpredis sessions in
  the test Redis database) and asks the unmodified legacy `sogo-auth.php` through `cgi-fcgi`; the
  test passes, so legacy reads the API's encoding. The `Cache-Control` headers that 005's contract
  declared as `const: no-store` are now a pattern containing `no-store`: Symfony always answers
  `no-store, private` (WebmailHandOffResponse, AppleProfileFile, AppleProfileCreated).
- **T074–T076**: `RecentLoginRecorder::record()` takes an optional service (`SSO`). The sign-out
  answer expires the legacy cookie (`Max-Age=0`) when the session had a hand-off. The listener is
  tagged in `AppServiceProvider`.
- **T078**: the overview reuses 003's `MailboxReader::present()` (tolerating an unavailable shared
  store) for quota, usage, protocols, webmail preference, password-changed time, recovery address,
  temporary aliases and push notifications; aliases follow legacy user_get_alias_details().
- **T083, T085 (deviation from research R12)**: temporary aliases take 003's shared address lock
  (`AddressLock`) on the candidate and, in an alias domain, on the target-domain address, instead of
  the domain row lock: `docs/conventions.md` requires features 003–005 to take exactly this lock, and
  004's alias creation uses it. The concurrency test holds that lock from a second database session.
  The controller's `store()` resolves the service per request (Laravel caches controller instances
  on the route, which kept a seeded generator between test requests).
- **T086**: no `List…Request` or resource classes; the controllers use `ListQuery` and validate
  the boolean filters with `QueryBoolean`.
- **T087–T095**: one `MailboxMailSettingsController` (`show`, `update`, `bulk`) and one
  `PushNotificationSettingsController` (`show`, `update`, `destroy`, `verify`) instead of separate
  bulk and verification controllers, and no resource classes (the services build the contract
  arrays). The tagging read counts a present `RCPT_WANTS_*` field, as 003's `MailboxReader` does
  (legacy only writes `1`), so the mail-settings tag and the mailbox list agree. G22: the `Mailbox`
  representation carries `mailSettingsVersion`, equal to the mail-settings `etag`; a mailbox patch
  refuses it as `immutable` like the other read-only members. `DelimitedRegex` (a coded rule) checks
  the sender pattern with legacy's delimiters. Pushover is reached through `PushoverClient`
  (`HttpPushoverClient`, 10 s timeout, `FakePushoverClient` in tests).
