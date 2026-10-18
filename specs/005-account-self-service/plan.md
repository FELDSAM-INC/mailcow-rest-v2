# Implementation Plan: Account Self-Service

**Branch**: `main` (no feature branches, constitution 1.1.0) | **Date**: 2026-10-18 |
**Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/005-account-self-service/spec.md`

## Summary

Feature 005 lets every signed-in account look after itself and lets administrators and domain
administrators manage the self-service objects of the mailboxes in their scope:

- own password for every role, including the forced password change, with other sessions of this
  API ended by default;
- own TOTP, WebAuthn and Yubico OTP second factors, compatible with legacy storage, including the
  forced second-factor setup;
- recovery address and password reset through it, sharing tokens with the legacy reset page,
  anti-enumeration and a second-factor step;
- app passwords, temporary aliases, push notifications (Pushover), tagged-mail handling and TLS
  enforcement (also in bulk), recent logins with countries, ActiveSync and groupware resets, Sieve
  filters and the active Sieve script;
- the mailbox user's overview, webmail single sign-on from the new web app, the Apple connection
  profile and the client configuration;
- the self-service capabilities read, also before sign-in for the reset availability.

The technical approach:

- The OpenAPI 3.1 contract was authored first: 51 operations in the module
  `api/modules/self-service/`, lint-clean.
- Everything runs on feature 001's foundation (Laravel 13 / PHP 8.4, Redis sessions, CSRF, route
  modes, audit log, `MailcowConfig`) and reuses the mailbox, password policy, hashing and
  static-view services of features 002–004. 001 is extended in three small places: a session
  registry (end all sessions of an account), a pending-action mode `includes`, and a read-only
  POST marker for the key-scope check.
- Legacy business logic is ported function by function: `edit_user_account`, `admin('edit')`,
  `domain_admin('edit')`, `set_tfa`, `unset_tfa_key`, `get_tfa`, `reset_password`, `app_passwd`,
  `mailbox(… 'time_limited_alias' | 'filter' | 'delimiter_action' | 'tls_policy' | 'eas_cache' |
  'sogo_profile' | 'active_user_sieve')`, `pushover`, `last_login`, `user_get_alias_details`,
  `mobileconfig.php`. The legacy Sieve parser is copied unmodified from the upstream tag.
- Webmail SSO creates, for the requesting browser, a legacy PHP session in mailcow's Redis that
  only `sogo-auth.php` honours, isolated in one service and tested against the legacy script.
- No new table; API-owned state lives in Redis (`restv2:`). One new Composer dependency
  (`bacon/bacon-qr-code`, legacy's QR library).

## Technical Context

**Language/Version**: PHP 8.4, Laravel 13.x (as 001).

**Primary Dependencies**: those of 001 (`lbuchs/webauthn`, `robthree/twofactorauth`, Laravel's
HTTP client and mailer); new: `bacon/bacon-qr-code` (BSD-2-Clause) for the TOTP QR code (research
R7, R24). The legacy Sieve parser is vendored from the upstream tag (R13). Dev image: Alpine
package `fcgi` (`cgi-fcgi`) for the `sogo-auth.php` compatibility harness (R3).

**Storage**: mailcow's MariaDB (upstream `2026-09a` schema): `mailbox`, `admin`, `tfa`,
`app_passwd`, `spamalias`, `sieve_filters`, `pushover`, `reset_password`, `sasl_log`, `logs`,
`sogo_*`, read-only `alias`, `alias_domain`, `sender_acl`, `quota2`/`quota2replica`. mailcow's
Redis: `RCPT_WANTS_*`, `IP_SHORTCOUNTRY`, `PW_RESET_*`, `PASSWD_POLICY` and, for the webmail
hand-off, `PHPREDIS_SESSION:<id>`; API-owned `restv2:` keys (session registry, hand-offs, reset
throttles). No API-owned table, no migration (data-model.md).

**Testing**: PHPUnit 12 feature and unit tests written first, on MariaDB with the schema fixture
and a real Redis, every request and response validated against the bundled contract (001-R13).
Fakes only for outbound services: dockerapi (`SieveScriptReader`), Pushover, country lookup,
Yubico; the reset mail uses Laravel's `array` mailer. The legacy `sogo-auth.php` runs unmodified
in a throw-away php-fpm pool. A timing test checks the reset acknowledgement (SC-005).

**Target Platform**: 001's container (`php:8.4-fpm-alpine`, non-root, read-only root filesystem),
plus the read-only mount of `data/assets/templates` and an ofelia job label for the hand-off
sweeper.

**Project Type**: web service (REST API).

**Performance Goals**: SC-010: a page of 25 app passwords, temporary aliases, filters or recent
logins in < 1 s (p95), a recent-login page with country lookups in < 2 s (lookups pooled, 1.5 s
budget). SC-008: webmail hand-off < 3 s. SC-005: reset acknowledgements with medians within 50 ms
regardless of the account (work deferred after the response). Password hashing (bcrypt, ~50–100 ms)
dominates password operations.

**Constraints**:

- No change to upstream tables, views or containers; the hand-off writes only a session that
  legacy's own `sogo-auth.php` reads.
- Legacy-compatible storage of every second factor, token, filter, alias, app password and setting.
- Secrets (passwords, TOTP secrets after the start, Yubico credentials, codes, WebAuthn responses,
  reset tokens, app passwords, Pushover credentials) never in responses (apart from the stated
  exceptions), logs or audit records (FR-076); request logging redacts them (001's
  `RedactSecrets` plus this feature's keys).
- Errors not localised; client address from `REMOTE_ADDR` only (001).

**Scale/Scope**: up to ~10,000 mailboxes, tens of app passwords and temporary aliases per mailbox,
a few hundred Sieve filters, `sasl_log` up to a few hundred thousand rows. 51 operations, 12 user
stories, 0 new tables.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design (below).*

| Principle | Gate | Status |
|---|---|---|
| I. Contract-first | All 51 operations authored in `api/modules/self-service/` with components in `api/components/`, lint-clean (`npx @redocly/cli@2.64.1 lint`), before any code. Every test request and response is validated (001-R13); the contract coverage check includes the new operations. Additive only. | PASS |
| II. HTTP contract | Plural nouns and sub-resources (`/me/second-factors/removal`, `/password-resets/completion`, `/mailboxes/{mailbox}/push-notifications/verification`); PATCH with JSON Merge Patch; `201` + `Location` for app passwords, temporary aliases, Sieve filters, second factors and the profile's app password; `204` for deletes and resets; actions return `200` with their result. `{data, meta}` lists with `limit`/`offset`/`sort`/`order`/`q`. RFC 9457 problems. `ETag`/`If-Match` (`412`) on app passwords, temporary aliases, filters, push settings, mail settings and the recovery address; every list entry carries `etag`. The `503` status is justified by 001's Complexity Tracking. | PASS |
| III. Upstream schema | No migration at all. The `sogo_*` tables are in the schema fixture (created by `init_db_schema()`). The webmail hand-off depends on the legacy session format; `scripts/legacy/check-handoff-assumptions.sh` and the compatibility test run per supported release (R3). | PASS |
| IV. Parity | Every write goes through a service in `app/Services/` that reproduces the legacy side effects: `tfa` rows, `JSON_SET` attributes, static view and memcached flush, `RCPT_WANTS_*` hashes, `sasl_log` `SSO`, `reset_password` lifecycle, `sogo_*` deletes, `IP_SHORTCOUNTRY` writes. Deviations are those of the spec's parity table, completed with the plan-level rows (below). | PASS |
| V. Coexistence | Own container only; the template directory is mounted read-only; the hand-off is the spec-sanctioned link between the two implementations' sessions (FR-067, FR-068), webmail-only, and it never grants the legacy UI or `/api/v1` writes. Legacy sessions are otherwise untouched. | PASS (spec-sanctioned link) |
| VI. Security | Object-level authorisation for admin, domain admin (in and out of scope), mailbox user (own and foreign), read-only and read-write keys of each owner role, and restricted sessions, in table-driven tests per operation. Own-account functions need a session; flags gate changes (FR-006). Wrong current passwords are throttled and audited; reset requests are anti-enumeration and throttled; TOTP secrets stay server-side. Every state change and every refusal of an authenticated caller is audited (the shared `RefusalAuditor`, routes declare `restv2.audit`), except the ceremony requests (TOTP start, WebAuthn options, reset challenge), justified by 001's Complexity Tracking. Version tags use an HMAC for secrets. Secrets redacted from logs. | PASS (justified) |
| VII. Test-first | `tasks.md` puts the failing tests first in every phase; MariaDB with the real schema, real Redis; fakes only behind `app/Clients/` interfaces. | PASS |
| VIII. Simplicity | One new runtime package (legacy's QR library). No queue, no table: deferred callbacks and Redis keys. Interfaces only where a fake is needed (`SieveScriptReader`, `PushoverClient`, `CountryLookup`). Shared logic reused from 001–004 (R1). | PASS |
| Cross-feature conventions | Capabilities at `/capabilities/self-service` (`getSelfServiceCapabilities`); history through `targetType`/`targetId` only (no own filter); `AuditFailureReason` appended; refusal auditing; HMAC for secret inputs of version tags; code-only uniqueness under `GET_LOCK` (or the shared domain row lock for addresses, R12). Out-of-scope objects `404`; flags `403 permission-flag-required` with `requiredFlags`; bulk at `POST /mailboxes/mail-settings/bulk-actions` with `action` and `targets`; whole request refused with every bad field named; legacy coercions become field errors; bulk TLS per conventions (100, per-item outcomes and audit, duplicates once, item versions); tags over stored settings, every list entry carries one; secrets write-only (Pushover) or shown once (app passwords, TOTP enrolment); legacy PHP variables through 001's `MailcowConfig`; lists 25/1,000 with `q`; reads not audited; no caching by time; dockerapi synchronous with `DOCKER_TIMEOUT`. | PASS |
| Runtime constraints | Stateless php-fpm (state in Redis); the sweeper runs as a short artisan command through ofelia; JSON logs with request ID. | PASS |

**Post-design re-check (after Phase 1)**: PASS. The decisions that touched gates:

- The webmail hand-off (V, III): isolated in `WebmailHandOffService`, marker `mailcow_cc_api_access
  = 'ro'` blocks `/api/v1` writes, compatibility test against legacy `sogo-auth.php`, assumption
  check per release.
- Deferred reset work (VI, SC-005): no queue, so no extra container (V, VIII).
- No table (III, VIII): pending enrolments in the session, hand-offs and the registry in Redis.
- Reuse of 003's password rule codes and policy schema, and of shared failure reasons (conventions).

## Parity notes added to the spec

The spec's parity table was completed with these plan-level rows (constitution IV):

1. Removing one second factor is refused only when that entry is the only active one; legacy also
   refuses removing an inactive entry while exactly one entry is active.
2. Legacy's `force_tfa` removal check never matches the stored string `"1"`; the API applies the
   rule.
3. A webmail hand-off whose API session expires (no request reveals the expiry) ends within one
   minute, through the sweeper; FR-068 states it.
4. Presentation-only differences: SVG QR code, 300 s WebAuthn timeout hint, `https` reset links,
   v4 payload UUIDs, no Thunderbird connector version, 10 s Pushover timeout.

## Contract Operations

All paths are in `api/modules/self-service/`. "Session" = `sessionCookie` (+ `csrfToken` on unsafe
methods); "key" = `apiKeyAuth`. Pending-action modes per research R5.

| Method and path | operationId | Auth | Pending mode | Story / FR |
|---|---|---|---|---|
| GET `/capabilities/self-service` | getSelfServiceCapabilities | public, session, key | allow | US1–US12, FR-080 |
| PUT `/me/password` | changeOwnPassword | session | includes,passwordChange | US1, FR-008–FR-015 |
| GET `/me/password-policy` | getOwnPasswordPolicy | session | includes,passwordChange | US1, FR-014 |
| GET `/me/second-factors` | listOwnSecondFactors | session | includes,secondFactorSetup | US2, FR-027 |
| POST `/me/second-factors` | enrolOwnSecondFactor | session | includes,secondFactorSetup | US2, FR-016, FR-029–FR-033, FR-036 |
| POST `/me/second-factors/totp-enrolment` | startOwnTotpEnrolment | session | includes,secondFactorSetup | US2, FR-030 |
| POST `/me/second-factors/webauthn-registration-options` | createOwnSecondFactorWebAuthnOptions | session | includes,secondFactorSetup | US2, FR-031 |
| POST `/me/second-factors/removal` | removeAllOwnSecondFactors | session | enforce | US2, FR-035 |
| GET `/me/second-factors/{secondFactorId}` | getOwnSecondFactor | session | enforce | US2, FR-027 |
| DELETE `/me/second-factors/{secondFactorId}` | deleteOwnSecondFactor | session | enforce | US2, FR-034 |
| GET `/me/recovery-email` | getOwnRecoveryEmail | session | enforce | US3, FR-018 |
| PUT `/me/recovery-email` | replaceOwnRecoveryEmail | session | enforce | US3, FR-018, FR-070 |
| GET `/me/mailbox` | getOwnMailboxOverview | session | enforce | US5, FR-069 |
| POST `/me/webmail-handoff` | createWebmailHandOff | session | enforce | US5, FR-067, FR-068 |
| GET `/me/mailbox/client-configuration` | getOwnClientConfiguration | session | enforce | US12, FR-083 |
| GET `/me/mailbox/apple-profile` | getOwnAppleProfile | session | enforce | US12, FR-082 |
| POST `/me/mailbox/apple-profile` | createOwnAppleProfileWithAppPassword | session | enforce | US12, FR-082, FR-038 |
| POST `/password-resets` | requestPasswordReset | browser session | allow | US3, FR-019–FR-022, FR-026 |
| POST `/password-resets/token-check` | checkPasswordResetToken | browser session | allow | US3, FR-023, FR-014 |
| POST `/password-resets/webauthn-challenge` | createPasswordResetWebAuthnChallenge | browser session | allow | US3, FR-024 |
| POST `/password-resets/completion` | completePasswordReset | browser session | allow | US3, FR-024–FR-026 |
| POST `/mailboxes/mail-settings/bulk-actions` | applyMailboxMailSettingsBulkAction | session, rw key | enforce | US7, FR-060 |
| GET `/mailboxes/{mailbox}/app-passwords` | listAppPasswords | session, key | enforce | US4, FR-037 |
| POST `/mailboxes/{mailbox}/app-passwords` | createAppPassword | session, rw key | enforce | US4, FR-038, FR-041 |
| GET `/mailboxes/{mailbox}/app-passwords/{appPasswordId}` | getAppPassword | session, key | enforce | US4, FR-037 |
| PATCH `/mailboxes/{mailbox}/app-passwords/{appPasswordId}` | updateAppPassword | session, rw key | enforce | US4, FR-039 |
| DELETE `/mailboxes/{mailbox}/app-passwords/{appPasswordId}` | deleteAppPassword | session, rw key | enforce | US4, FR-040 |
| GET `/mailboxes/{mailbox}/temporary-aliases` | listTemporaryAliases | session, key | enforce | US6, FR-042 |
| POST `/mailboxes/{mailbox}/temporary-aliases` | createTemporaryAlias | session, rw key | enforce | US6, FR-043, FR-044 |
| GET `/mailboxes/{mailbox}/temporary-aliases/{temporaryAlias}` | getTemporaryAlias | session, key | enforce | US6, FR-042, FR-046 |
| PATCH `/mailboxes/{mailbox}/temporary-aliases/{temporaryAlias}` | updateTemporaryAlias | session, rw key | enforce | US6, FR-045 |
| DELETE `/mailboxes/{mailbox}/temporary-aliases/{temporaryAlias}` | deleteTemporaryAlias | session, rw key | enforce | US6, FR-046 |
| GET `/mailboxes/{mailbox}/temporary-alias-domains` | listTemporaryAliasDomains | session, key | enforce | US6, FR-081 |
| GET `/mailboxes/{mailbox}/push-notifications` | getPushNotificationSettings | session, key | enforce | US7, FR-054 |
| PUT `/mailboxes/{mailbox}/push-notifications` | replacePushNotificationSettings | session, rw key | enforce | US7, FR-055 |
| DELETE `/mailboxes/{mailbox}/push-notifications` | deletePushNotificationSettings | session, rw key | enforce | US7, FR-056 |
| POST `/mailboxes/{mailbox}/push-notifications/verification` | verifyPushNotificationSettings | session, rw key | enforce | US7, FR-057 |
| GET `/mailboxes/{mailbox}/mail-settings` | getMailboxMailSettings | session, key | enforce | US7, FR-058 |
| PATCH `/mailboxes/{mailbox}/mail-settings` | updateMailboxMailSettings | session, rw key | enforce | US7, FR-059 |
| GET `/mailboxes/{mailbox}/recent-logins` | listRecentLogins | session, key | enforce | US8, FR-061, FR-062 |
| DELETE `/mailboxes/{mailbox}/recent-logins` | clearRecentLogins | session, rw key | enforce | US8, FR-063 |
| DELETE `/mailboxes/{mailbox}/activesync-cache` | resetActiveSyncCache | session, rw key | enforce | US10, FR-064, FR-066 |
| DELETE `/mailboxes/{mailbox}/groupware-profile` | resetGroupwareProfile | session, rw key | enforce | US10, FR-065, FR-066 |
| GET `/sieve-filters` | listSieveFilters | session, key | enforce | US9, FR-047 |
| POST `/sieve-filters/validation` | validateSieveScript | session, key (read-only too) | enforce | US9, FR-052 |
| GET `/mailboxes/{mailbox}/sieve-filters` | listMailboxSieveFilters | session, key | enforce | US9, FR-047 |
| POST `/mailboxes/{mailbox}/sieve-filters` | createSieveFilter | session, rw key | enforce | US9, FR-048, FR-051 |
| GET `/mailboxes/{mailbox}/sieve-filters/{sieveFilterId}` | getSieveFilter | session, key | enforce | US9, FR-047 |
| PATCH `/mailboxes/{mailbox}/sieve-filters/{sieveFilterId}` | updateSieveFilter | session, rw key | enforce | US9, FR-049, FR-051 |
| DELETE `/mailboxes/{mailbox}/sieve-filters/{sieveFilterId}` | deleteSieveFilter | session, rw key | enforce | US9, FR-050 |
| GET `/mailboxes/{mailbox}/active-sieve-script` | getActiveSieveScript | session, key | enforce | US9, FR-053 |

US11 (audit, FR-073, FR-074) uses `GET /audit-events` with the `targetType` and `targetId` filters
that feature 002 adds (002 FR-058); this feature appends its event types, target types and failure
reasons to the shared enumerations. Problem types are documented in `docs/problems.md`.

Shared components reused: `ListMeta`, `Problem`, `ValidationProblem`, `PendingActionProblem`,
`Limit`, `Offset`, `Order`, `Search`, `IfMatch`, `BulkTarget`, `BulkResultResponse`,
`WebAuthnRegistrationResponse`, `WebAuthnCredentialDescriptor`, `WebAuthnChallengeResponse`,
`SecondFactorVerification`, `SecondFactorMethod`, `Role`, `Base64Url` (001); `PasswordPolicy`,
`PasswordPolicyResponse`, `MailboxAddress`, `MailboxTaggedMailHandling`, `MailboxProtocolAccess`,
`MailboxAuthSource` (003); the standard responses.

## Project Structure

### Documentation (this feature)

```text
specs/005-account-self-service/
├── spec.md              # Feature specification (clarifications incl. orchestrator decisions)
├── plan.md              # This file
├── research.md          # Phase 0: decisions R1–R25 (feature-specific only)
├── data-model.md        # Phase 1: tables, Redis keys, outbound calls
├── quickstart.md        # Phase 1: validation guide
├── contracts/README.md  # Phase 1: pointer to api/modules/self-service/
├── checklists/
└── tasks.md             # Phase 2 (the /speckit-analyze summary is reported, not stored)
```

### Source Code (repository root)

001's layout; only the files this feature adds or changes are listed.

```text
api/
├── modules/self-service/                 # _index.yaml + 34 path files (51 operations)
└── components/{schemas,parameters,responses}/   # Own*, SelfService*, AppPassword*, TemporaryAlias*,
                                                 # PushNotification*, MailboxMailSettings*, RecentLogin*,
                                                 # SieveFilter*, SieveScript*, ActiveSieveScript*,
                                                 # PasswordReset*, AppleProfile*, MailboxOverview*,
                                                 # MailboxClient*, WebmailHandOff*

app/
├── Clients/
│   ├── Dovecot/{SieveScriptReader,DockerApiSieveScriptReader,ActiveSieveScript}.php
│   ├── Pushover/{PushoverClient,HttpPushoverClient,PushoverVerdict}.php
│   └── Geo/{CountryLookup,HttpCountryLookup}.php
├── Console/Commands/SweepWebmailHandOffsCommand.php      # restv2:webmail-handoffs:sweep
├── Http/
│   ├── Controllers/Api/V2/   # SelfServiceCapabilities, OwnPassword, OwnPasswordPolicy,
│   │                         # OwnSecondFactor, OwnTotpEnrolment, OwnSecondFactorWebAuthnOptions,
│   │                         # OwnSecondFactorRemoval, OwnRecoveryEmail, OwnMailboxOverview,
│   │                         # WebmailHandOff, OwnClientConfiguration, OwnAppleProfile,
│   │                         # PasswordReset, PasswordResetTokenCheck, PasswordResetWebAuthnChallenge,
│   │                         # PasswordResetCompletion, AppPassword, TemporaryAlias,
│   │                         # TemporaryAliasDomain, PushNotificationSettings,
│   │                         # PushNotificationVerification, MailboxMailSettings,
│   │                         # MailboxMailSettingsBulkAction, RecentLogin, ActiveSyncCache,
│   │                         # GroupwareProfile, SieveFilter, MailboxSieveFilter,
│   │                         # SieveScriptValidation, ActiveSieveScript controllers
│   ├── Middleware/           # EnforcePendingActions (+ mode includes), EnforceKeyScope (+ readOnly marker),
│   │                         # EnsureSessionIntegrity (+ registry TTL refresh) — 001 files, extended
│   ├── Requests/Api/V2/      # Form Requests per operation (ApiFormRequest)
│   └── Resources/Api/V2/     # JsonResources per contract schema
├── Models/Mailcow/{AppPassword,SieveFilter,PasswordResetToken}.php   # SpamAlias, Pushover, SenderAcl: 003's
├── Services/
│   ├── Auth/{SessionRegistry}.php                         # + SessionManager, SecondFactorService (001, extended)
│   ├── Account/{OwnPasswordService,RecoveryEmailService,MailboxOverviewService,
│   │           ClientConfigurationService,AppleProfileBuilder,AccountCapabilities}.php
│   ├── SecondFactors/{SecondFactorQueryService,SecondFactorEnrolmentService,SecondFactorRemovalService}.php
│   ├── PasswordReset/{PasswordResetService,PasswordResetMailer,ResetLinkBuilder}.php
│   ├── Webmail/{WebmailHandOffService,WebmailHandOff}.php
│   ├── AppPasswords/{AppPasswordService,AppPasswordGenerator}.php
│   ├── TemporaryAliases/{TemporaryAliasService,TemporaryAliasAddressGenerator}.php
│   ├── SieveFilters/SieveFilterService.php
│   ├── PushNotifications/PushNotificationService.php
│   ├── MailSettings/MailSettingsService.php
│   ├── RecentLogins/RecentLoginService.php
│   ├── Groupware/GroupwareResetService.php
│   ├── SelfService/{MailboxAccess,PermissionGate,SelfServiceCapabilitiesService,SelfServiceAuditTargets}.php
│   └── Config/MailcowConfig.php                           # 001, extended with this feature's variables
├── Support/
│   ├── Database/NamedLock.php                             # GET_LOCK helper (009 reuses it)
│   ├── LegacySession/PhpSessionEncoder.php
│   ├── Passwords/{PreHashedPasswordRule,NewPasswordValidator}.php
│   ├── Sieve/SieveValidator.php
│   └── Totp/QrCodeProvider.php                            # BaconQrCodeProvider, svg
resources/
├── bin/export-mailcow-vars.php                            # 001, extended (R23)
└── legacy/sieve/                                          # copied from the upstream tag (R13)
config/restv2.php                                          # + R23 settings
routes/api.php                                             # + 51 routes, specific before general
database/factories/MailcowRows.php                         # + rows of this feature's tables

tests/
├── Feature/
│   ├── SelfService/            # capabilities, authorisation matrices
│   ├── OwnAccount/             # password, password policy, recovery address, overview, client config, Apple profile
│   ├── SecondFactors/          # list, TOTP, WebAuthn, Yubico, removal, forced setup, legacy interop
│   ├── PasswordReset/          # request, timing, token check, challenge, completion
│   ├── Webmail/                # hand-off, session ending, sweeper, SogoAuthCompatibilityTest
│   ├── AppPasswords/, TemporaryAliases/, PushNotifications/, MailSettings/,
│   ├── RecentLogins/, Groupware/, SieveFilters/, Audit/
├── Unit/{LegacySession,Passwords,Sieve,Services,Clients}/
├── Support/Legacy/LegacyFpmRunner.php
└── fixtures/legacy/sogo-auth/2026-09a/                     # extracted legacy files

scripts/legacy/{extract-sieve-parser.sh,extract-sogo-auth.sh,check-handoff-assumptions.sh}
docker/php/Dockerfile                                      # dev stage: + fcgi
deploy/docker-compose.override.yml                         # + templates mount, env, ofelia label
```

**Structure Decision**: The single Laravel project of 001. Business logic per aggregate in
`app/Services/` (one service per resource family, owning its upstream writes, side effects and audit
records); outbound services behind `app/Clients/` interfaces; legacy ports without side effects
(session encoding, Sieve validation, password rules) in `app/Support/`. Controllers are thin, one
per resource, with `index`, `show`, `store`, `update`, `destroy` (and `__invoke` for actions).

## Dependencies on earlier features

Features are implemented in order (001 → 004, then 005). Research R1 lists every reused piece with
its owner and planned class. When a piece is missing at implementation time, the 005 Foundational
phase creates it at the named path (`NamedLock` in T022, `RefusalAuditor` in T024, the
`PermissionFlagRequired` problem case in T026) instead of duplicating logic later.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Writing a legacy PHP session (`PHPREDIS_SESSION:<id>`) in mailcow's Redis (constitution V: sessions of the two implementations are independent "unless a spec says otherwise") | FR-067/FR-068 require webmail without a second sign-in and without changing any container; mailcow's webmail SSO only trusts `sogo-auth.php`, which reads the legacy session | A SOGo-accepted signed URL does not exist; patching `sogo-auth.php` or nginx violates constitution V; asking users to sign in to webmail again fails the spec's clarification |
| Ceremony requests not audited: `startOwnTotpEnrolment`, `createOwnSecondFactorWebAuthnOptions`, `createPasswordResetWebAuthnChallenge` | Same reason as 001's Complexity Tracking: they change only the caller's own short-lived session data; the enrolment or reset they lead to is audited | Auditing them lets clients (anonymous ones for the reset challenge) flood the audit log without recording anything the following event does not show |
| Background sweep (ofelia, every minute) for hand-offs of expired API sessions | A Redis TTL expiry raises no event the API can act on | Keyspace notifications need a Redis configuration change (constitution V); extending the legacy session's lifetime control is impossible because `sogo-auth.php` refreshes it |
