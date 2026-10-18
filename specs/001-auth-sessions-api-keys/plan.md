# Implementation Plan: Authentication, Sessions, API Keys, Identity and Branding Configuration

**Branch**: `main` (no feature branches, constitution 1.1.0) | **Date**: 2026-10-18 |
**Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/001-auth-sessions-api-keys/spec.md`

## Summary

Feature 001 builds the foundation of mailcow REST API v2 and covers the following:

- password sign-in for administrators, domain administrators and mailbox users, with their existing
  hashes and second factors (TOTP, WebAuthn, Yubico OTP);
- usernameless passkey sign-in on legacy FIDO2 credentials, and registration, renaming and removal
  of one's own passkeys;
- same-origin cookie sessions with CSRF protection on every state-changing request, including
  sign-in;
- "who am I" with role, scope, permission flags and pending actions, plus per-account preferences;
- administrator-managed, scoped, expiring, network-restricted API keys (`X-API-Key`);
- the public branding configuration read from mailcow's customisation;
- an immutable audit log;
- the app links of the legacy login page and apps menu, and a list of possible key owners for the
  key form (orchestrator additions FR-056 and FR-057).

The technical approach:

- The OpenAPI 3.1 contract in `api/` was authored first. It has 31 operations and lints clean.
- A Laravel 13 / PHP 8.4 service runs as its own php-fpm container next to mailcow. nginx-mailcow
  reaches it over FastCGI through mailcow's `site.*.custom` include.
- Legacy business logic is ported line by line: `check_login`, `verify_hash`, `fido2`,
  `verify_tfa_login`, `acl('to_session')` and `customize('get')`. The same WebAuthn and TOTP
  libraries are used, so stored credentials stay byte-compatible.
- Upstream tables are read, and written only through services: `tfa` re-activation, `sasl_log`
  recent logins and `fido2` passkeys.
- API-owned data lives in three `restv2_` tables and in `restv2:`-prefixed Redis keys.
- Tests are written first and run on MariaDB 10.11, loaded with the real `2026-09a` schema
  produced by upstream's own `init_db_schema()`, and on a real Redis 7.4. Every request and
  response is validated against the contract.

## Technical Context

**Language/Version**: PHP 8.4 (constitution: 8.3 or newer), Laravel 13.x

**Primary Dependencies**: `laravel/framework` ^13; `lbuchs/webauthn` ^2.2 (WebAuthn and passkeys,
legacy's library); `robthree/twofactorauth` ^3.0 (TOTP, legacy's library); the PHP extensions
`pdo_mysql`, `redis` (phpredis), `intl`, `opcache`. Dev: `phpunit/phpunit` 12,
`laravel/pint`, `larastan/larastan` ^3, `opis/json-schema` ^2.6. Contract tooling:
`@redocly/cli` 2.64.1 and `tufin/oasdiff` (CI). Justifications are in
[research R28](./research.md#r28-composer-dependencies-constitution-viii).

**Storage**: mailcow's MariaDB 10.11, with the upstream schema of release `2026-09a`
(`db_version 18082026_1200`), read and written through services. It also holds the API-owned tables
`restv2_api_keys`, `restv2_audit_events`, `restv2_preferences` and `restv2_migrations`. mailcow's
Redis 7.4 holds the sessions, throttle counters, TOTP replay markers and configuration cache
(`restv2:` prefix), and is read for mailcow's branding keys. The fail2ban channel is published
there.

**Testing**: PHPUnit 12 feature and unit tests, written first, run inside the dev container on
MariaDB with the upstream schema fixture `tests/fixtures/schema/2026-09a.sql` and on a real Redis.
Every test request and response is validated against the bundled contract (opis/json-schema).
A software WebAuthn authenticator replaces devices. The Yubico service is faked. The fail2ban
channel is observed with `redis-cli SUBSCRIBE`. A contract coverage check runs after the suite.

**Target Platform**: A Linux container (`php:8.4-fpm-alpine`, non-root, read-only root
filesystem) added to mailcow-dockerized through `docker-compose.override.yml`. It is reached by
nginx-mailcow over FastCGI at `/api/v2/`. The image is `ghcr.io/feldsam-inc/mailcow-rest-v2`
(GitHub repository `FELDSAM-INC/mailcow-rest-v2`); the UI's static image, proxied at `/ng/`, is
`ghcr.io/feldsam-inc/mailcowui-ng`.

**Project Type**: web service (REST API), with no UI apart from the API documentation page.

**Performance Goals**: SC-007: 95% of sign-in steps answer within 1 s, excluding deliberate
throttling delays. The bcrypt verification dominates at about 50–100 ms. Identity and
public-configuration reads take p95 < 100 ms. Key authentication adds one indexed lookup and at
most one `UPDATE` per key per minute.

**Constraints**:

- No change to upstream tables, views or containers.
- Legacy UI, `/api/v1` and php-fpm keep working unchanged.
- Credentials stay byte-compatible in both directions.
- Errors are not localised.
- The client address comes only from nginx-mailcow's `REMOTE_ADDR`.
- Secrets never appear in logs, audit records or responses, apart from the one-time key secret.
- All dependencies are GPL-3.0-compatible.

**Scale/Scope**: One mailcow server with up to about 10,000 accounts, a few hundred API keys, and
up to about 1 million audit records a year, kept for 365 days. The feature has 31 operations in
7 modules, 3 API-owned tables and 6 user stories.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design (below).*

| Principle | Gate | Status |
|---|---|---|
| I. Contract-first | The contract for all 31 operations is authored in `api/` before any code. `npx @redocly/cli lint` passes. Every test request and response is validated (R13). The breaking-change check runs in CI (R25). The bundled contract is a release artifact served at `/api/v2/openapi.yaml`. | PASS |
| II. HTTP contract | Base path `/api/v2`; plural nouns; actions are sub-resources (`/api-keys/{id}/revocation`, `/session/passkey-challenge`); PATCH uses JSON Merge Patch. 201 with `Location`, 200, 204. `{data, meta}` lists and `{data}` single resources. RFC 9457 problems with `errors` on 422. `ETag` and `If-Match` on preferences, passkeys and API keys. camelCase, booleans, RFC 3339 UTC. Status codes 503 and 405 are justified under Complexity Tracking. | PASS (justified) |
| III. Upstream schema | No migration touches an upstream table (architecture test `MigrationPrefixTest`). The prefix is `restv2_`, also for `restv2_migrations`. Destructive database commands are prohibited. The fixture is generated from upstream `init_db_schema()` (R2). The CI matrix runs over every supported schema. | PASS |
| IV. Parity | The legacy code was read and ported (R9–R12, R16). Every upstream write goes through `app/Services/` and reproduces legacy's side effects: `tfa` re-activation, `sasl_log`, `fido2` insert, update and delete, and the fail2ban publish. Deviations are only those stated in the spec's parity table, which this plan completes with the plan-level rows (see "Parity notes added to the spec"). | PASS |
| V. Coexistence | Own container via the override. nginx routing uses mailcow's `site.*.custom` include, so no upstream file is patched. php-fpm is untouched. Sessions, keys and audit are independent of legacy. | PASS |
| VI. Security | Object-level authorisation for every role and key type. Keys are stored as SHA-256, shown once, scoped, expiring and network-restricted. Invalid keys go to fail2ban. The cookie is `__Host-`, `Secure`, `HttpOnly` and `SameSite=Strict`, and CSRF applies to every unsafe session request. Existing second factors keep working. Every state-changing request is audited in the same transaction, except the ceremony requests justified under Complexity Tracking. Secrets are redacted from logs. Unknown accounts get timing equalisation. | PASS (justified) |
| VII. Test-first | `tasks.md` orders tests before implementation in every phase. Tests run on MariaDB with the real schema and on a real Redis. Only the Yubico HTTP service is faked. | PASS |
| VIII. Simplicity | Three runtime packages, two of them legacy's own (R28). No package for Yubico, `verify_hash`, CBOR or IP matching. Interfaces exist only where a fake or a second adapter is needed (`Fail2BanReporter`, `YubicoOtpVerifier`). | PASS |
| Cross-feature conventions (`docs/conventions.md`) | Out-of-scope objects answer `404`. Lists default to 25 entries (at most 1,000) and take `q`. Version tags cover stored settings and appear as `etag` on every representation. Keys cannot create or revoke keys. Secrets are shown once. Reads are not audited. Nothing is cached by time. Legacy PHP settings are server settings with upstream defaults (R14, R29). | PASS |
| Runtime constraints | Stateless php-fpm, non-root, read-only root filesystem. Liveness and readiness endpoints plus a CLI health check. JSON logs with the request ID (`traceparent`). Configuration from environment variables with `mailcow.conf` names, plus the optional mounted legacy `vars` files justified under Complexity Tracking (R14, R23, R24). | PASS (justified) |

**Post-design re-check (after Phase 1)**: PASS. The contract, data model and research introduce
no new violation. The decisions that touched gates are these:

- Single connection and explicit prefix: III, and atomic audit for VI.
- FastCGI with `REMOTE_ADDR` and no trusted forwarded headers: VI.
- Per-request evaluation of pending actions: IV, recorded in the spec.
- Fail2ban message sanitising: IV, recorded in the spec.

## Parity notes added to the spec

The spec's "Legacy Parity and Deliberate Deviations" table was completed with these plan-level
rows (constitution IV requires every deviation to be stated in the spec):

1. Mailboxes with `authsource = generic-oidc` are treated like LDAP and Keycloak: generic failure,
   not reported to fail2ban (FR-003 wording updated).
2. Pending actions are re-evaluated on every request, not only at sign-in.
3. Control characters in the username part of the fail2ban message are replaced by `?`.
4. Throttled password attempts are still published to fail2ban.
5. New passkeys get the product name as the WebAuthn relying-party name instead of legacy's
   `WebAuthn Library`, may be named at registration, and a duplicate credential ID is refused.
6. App links honour the signed-in address of built-in apps, which legacy's header ignores because
   of a bug. Possible key owners are a new list, since legacy keys have no owner.
7. Rows for behaviours the spec already required but the table did not list as deviations: exact
   0.5 s delay steps (legacy truncates them to whole seconds), a Yubico outage keeping the pending
   sign-in open, passkey failures being throttled, and the 300 s ceremony timeout instead of 30 s.
8. FR-014 now names the operations that need no signed-in account and therefore stay available in
   a restricted session (found by `/speckit-analyze`).

## Contract Operations

| Method and path | operationId | Auth | Story / FR |
|---|---|---|---|
| GET `/csrf-token` | getCsrfToken | public | US1, FR-011 |
| GET `/session` | getSession | public / session | US1, US2 |
| POST `/session` | createSession | anonymous session + CSRF | US1, FR-001–FR-008, FR-014, FR-026, FR-027 |
| DELETE `/session` | deleteSession | session + CSRF, or none | US1, FR-010 |
| DELETE `/session/pending-sign-in` | cancelPendingSignIn | session + CSRF | US1, FR-024 |
| POST `/session/pending-sign-in/webauthn-challenge` | createSecondFactorWebAuthnChallenge | session + CSRF | US1, FR-021 |
| POST `/session/pending-sign-in/second-factor` | verifySecondFactor | session + CSRF | US1, FR-020–FR-025 |
| POST `/session/passkey-challenge` | createPasskeyChallenge | session + CSRF | US2, FR-015, FR-017 |
| POST `/session/passkey-assertion` | createSessionWithPasskey | session + CSRF | US2, FR-015–FR-018, FR-027 |
| GET `/me` | getIdentity | session or key | US3, US4, FR-028, FR-029 |
| GET `/me/preferences` | getPreferences | session | US3, FR-030 |
| PATCH `/me/preferences` | updatePreferences | session + CSRF | US3, FR-030, FR-031 |
| GET `/me/passkeys` | listPasskeys | session | US2, FR-053 |
| POST `/me/passkeys` | registerPasskey | session + CSRF (full or `secondFactorSetup`) | US2, FR-052, FR-054 |
| POST `/me/passkeys/registration-options` | createPasskeyRegistrationOptions | session + CSRF | US2, FR-052 |
| GET `/me/passkeys/{passkeyId}` | getPasskey | session | US2, FR-053 |
| PATCH `/me/passkeys/{passkeyId}` | updatePasskey | session + CSRF | US2, FR-053 |
| DELETE `/me/passkeys/{passkeyId}` | deletePasskey | session + CSRF | US2, FR-053 |
| GET `/api-keys` | listApiKeys | admin session or admin key | US4, FR-035, FR-037 |
| POST `/api-keys` | createApiKey | admin session + CSRF | US4, FR-032, FR-034, FR-037 |
| GET `/api-keys/owner-candidates` | listApiKeyOwnerCandidates | admin session or admin key (read-only too) | US4, FR-057 |
| GET `/api-keys/{apiKeyId}` | getApiKey | admin session or admin key | US4, FR-035 |
| POST `/api-keys/{apiKeyId}/revocation` | revokeApiKey | admin session + CSRF | US4, FR-036, FR-037 |
| GET `/public-config` | getPublicConfig | public | US5, FR-042–FR-044, FR-056 |
| GET `/public-config/logos/{variant}` | getPublicConfigLogo | public | US5, FR-042 |
| GET `/audit-events` | listAuditEvents | admin session or admin key | US6, FR-047 |
| GET `/health/live` | getLiveness | public | constitution runtime |
| GET `/health/ready` | getReadiness | public | constitution runtime |
| GET `/openapi.yaml` | getOpenApiDocument | public | constitution I |
| GET `/docs` | getApiDocs | public | constitution (docs UI) |
| GET `/problems/{problemType}` | getProblemTypeDocumentation | public | constitution II, FR-049 |

Every operation with a body or effect is described in its path file under `api/modules/`. Problem
types are documented in [`docs/problems.md`](../../docs/problems.md).

## Project Structure

### Documentation (this feature)

```text
specs/001-auth-sessions-api-keys/
├── spec.md              # Feature specification (with clarifications and parity table)
├── plan.md              # This file
├── research.md          # Phase 0: decisions R1–R28
├── data-model.md        # Phase 1: upstream and API-owned storage, Redis, state machines
├── quickstart.md        # Phase 1: validation guide
├── contracts/README.md  # Phase 1: pointer to api/ (the contract is not duplicated)
├── checklists/requirements.md
├── tasks.md             # Phase 2 (/speckit-tasks)
└── analysis.md          # /speckit-analyze summary
```

### Source Code (repository root)

```text
api/                                   # Contract (source of truth), already authored
├── openapi.yaml
├── modules/{auth,identity,passkeys,api-keys,public-config,audit,system}/
│   ├── _index.yaml                    # path → path-file map of the module
│   └── *.yaml                         # kebab-case path files
└── components/{schemas,parameters,responses}/   # PascalCase files

app/
├── Auth/                              # Principal, Role, AuthMethod, PendingAction, PermissionFlag, AccountKind
├── Audit/AuditEventType.php           # enum of the contract's AuditEventType
├── Casts/MailcowDateTime.php
├── Clients/
│   ├── Fail2Ban/{Fail2BanReporter,RedisFail2BanReporter}.php
│   └── Yubico/{YubicoOtpVerifier,HttpYubicoOtpVerifier,YubicoVerificationResult}.php
├── Console/Commands/{AuditPurgeCommand,HealthCommand}.php
├── Exceptions/                        # ProblemException + renderer (bootstrap/app.php)
├── Http/
│   ├── Controllers/Api/V2/            # CsrfToken, Session, PendingSignIn, SecondFactorChallenge,
│   │                                  # SecondFactor, PasskeyChallenge, PasskeyAssertion, Identity,
│   │                                  # Preferences, Passkey, PasskeyRegistrationOptions, ApiKey,
│   │                                  # ApiKeyRevocation, ApiKeyOwnerCandidate, PublicConfig, PublicConfigLogo,
│   │                                  # AuditEvent, Liveness, Readiness, OpenApiDocument,
│   │                                  # ApiDocs, ProblemType controllers (show only)
│   ├── Middleware/                    # AssignRequestId, AuthenticateApiKey, StartSessionUnlessApiKey,
│   │                                  # EnsureSessionIntegrity, VerifyCsrfToken, RequireAuth,
│   │                                  # EnforceKeyScope, EnforcePendingActions
│   ├── Requests/Api/V2/               # ApiFormRequest base + Form Requests (validation with rule codes)
│   └── Resources/Api/V2/              # JsonResources → contract shapes
├── Logging/{RequestContextProcessor,RedactSecrets}.php
├── Models/
│   ├── Mailcow/{Admin,DomainAdminAssignment,Domain,Mailbox,DaAcl,UserAcl,Fido2Credential,
│   │            TfaEntry,SaslLogEntry,IdentityProviderSetting,SchemaVersion}.php
│   └── {ApiKey,AuditEvent,Preference}.php
├── Policies/{ApiKeyPolicy,AuditEventPolicy}.php
├── Providers/AppServiceProvider.php
├── Rules/                             # IpNetwork, EnabledLanguage, FutureDateTime, EligibleKeyOwner (rule-code aware)
├── Services/
│   ├── Audit/AuditLogger.php
│   ├── Auth/{AccountResolver,PasswordSignInService,SecondFactorService,PasskeySignInService,
│   │         SignInThrottle,PendingActionEvaluator,RecentLoginRecorder,SessionManager}.php
│   ├── ApiKeys/{ApiKeyService,ApiKeyAuthenticator}.php
│   ├── Branding/{PublicConfigService,ProductName}.php
│   ├── Health/ReadinessChecker.php
│   ├── Config/MailcowConfig.php
│   ├── Identity/{IdentityService,Scope}.php
│   ├── Passkeys/PasskeyService.php
│   └── Preferences/PreferencesService.php
└── Support/
    ├── Clock/{Sleeper,UsleepSleeper}.php   # injectable delay for sign-in throttling (faked in tests)
    ├── Hashing/LegacyPasswordVerifier.php
    ├── Http/{ProblemType,Problem,ETag,Base64Url,ListQuery}.php
    ├── Totp/NoQrCodeProvider.php
    └── WebAuthn/{WebAuthnServerFactory,OptionsJson}.php

bootstrap/app.php                      # middleware group api.v2, exception rendering
config/restv2.php                      # RESTV2_* settings, supported schema versions
database/factories/MailcowRows.php     # inserts upstream rows exactly like legacy (tests, dev seeder)
database/migrations/                   # only restv2_ tables
database/seeders/DevelopmentSeeder.php # local only
resources/bin/export-mailcow-vars.php
resources/webauthn/rootCertificates/   # copied from legacy inc/lib/WebAuthn/rootCertificates
routes/api.php                         # all /api/v2 routes, specific before general

tests/
├── TestCase.php                       # contract validation, Redis flush, helpers
├── Support/
│   ├── Contract/{OpenApiContract,ContractCoverageExtension}.php
│   ├── Mailcow/MailcowFactory.php     # test wrapper around database/factories/MailcowRows.php
│   ├── Redis/ChannelSpy.php
│   └── WebAuthn/{VirtualAuthenticator,CborEncoder}.php
├── Feature/{Auth,Passkeys,Identity,ApiKeys,PublicConfig,Audit,System,Schema,Security}/
├── Unit/{Hashing,Architecture,Support,Services}/
├── fixtures/{schema/2026-09a.sql,legacy/verify_hash.php,mailcow-inc/vars{,.local}.inc.php}
└── bin/{prepare-database.sh,check-contract-coverage.php}

scripts/
├── schema/{generate-fixture.sh,init-db-harness.php}
├── legacy/extract-verify-hash.sh
└── dev/reset-dev-database.sh

docker/php/{Dockerfile,entrypoint.sh,php-fpm.conf,php.ini}
docker/nginx/dev.conf
docker/mariadb/mailcow.cnf             # mailcow's my.cnf of 2026-09a, for the dev and test database
compose.yaml                           # dev stack (project restv2): app, web, mariadb, redis
deploy/
├── docker-compose.override.yml        # restv2-mailcow service (ghcr.io/feldsam-inc/mailcow-rest-v2)
└── nginx/site.restv2.custom           # /api/v2/ (FastCGI) and /ng/ (ghcr.io/feldsam-inc/mailcowui-ng)
.github/workflows/ci.yml
redocly.yaml, .redocly.lint-ignore.yaml
```

**Structure Decision**: This is a single Laravel project at the repository root, following the
constitution's path table. Business logic lives in `app/Services/` per aggregate: sign-in, passkeys,
API keys, preferences, audit and branding. Remote and Redis-backed subsystems sit behind
`app/Clients/`. Controllers are thin, one per resource, with `index`, `show`, `store`, `update` and
`destroy`. Legacy ports with no side effects (`verify_hash`, problem rendering, ETags) live in
`app/Support/`.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Status `503` (not in constitution II's error list) for `temporary-verification-failure` and readiness | FR-049 requires a distinct, retryable error when the Yubico service is unreachable, with the pending sign-in kept open. Readiness must signal "not ready" to monitors. RFC 9110 defines 503 with `Retry-After` for exactly this. | `500` cannot be told apart from a bug, so clients would not retry or offer another method. `401 second-factor-failed` would wrongly cancel the pending sign-in. |
| Ceremony requests are not audited (constitution VI: "every state-changing request writes an audit record"): `GET /csrf-token`, `POST /session/passkey-challenge`, `POST /session/pending-sign-in/webauthn-challenge`, `POST /me/passkeys/registration-options` and `DELETE /session/pending-sign-in` | These requests change only the caller's own short-lived session data (a challenge or a pending sign-in that expires within 5 minutes). They change no mailcow data, no API-owned table and nothing another caller can see. Most are anonymous. The outcome they lead to is always audited: the sign-in, its failure, or the passkey registration (FR-045). | Auditing them would let anonymous clients write unbounded audit rows (one per challenge), flooding the log that administrators use to investigate attacks, while recording nothing that the subsequent audited event does not already show. |
| Incidental server state on safe methods (constitution II: "GET is safe and never changes state"): `GET /csrf-token` and `GET /session` start an anonymous session; every session request renews the idle timer, and a request after the idle timeout or with another user agent ends the session; a key's `GET` records its last use at most once a minute | RFC 9110 §9.2.1 defines "safe" as not *requesting* a change and explicitly allows incidental side effects. These effects are session bookkeeping that the client does not ask for, and they are idempotent. Last use is required by FR-035, idle expiry by FR-009 and user-agent pinning by FR-012. | `POST /csrf-token` would still need an exemption from the token check it bootstraps, and it would not remove the other effects, which every session or key request has whatever its method. |
| Configuration also read from mailcow's mounted `data/web/inc/vars{,.local}.inc.php` (constitution runtime: "configuration comes from environment variables") | Spec 001 assumes that session lifetime, user verification, languages and built-in app links come from the operator's existing configuration, local overrides included. Reading the deployed files keeps both UIs aligned without copying values (R14). The mount is optional, and every value is also a `RESTV2_*` environment setting that wins. | Environment variables only would make the operator maintain every value twice, and the copies drift from `vars.local.inc.php`. |
| Status `405` (framework) for a known path with an unsupported method | Laravel's router answers it, with an `Allow` header, as RFC 9110 requires. It is rendered as an `about:blank` problem. | Mapping it to `404` would hide the existing resource and break HTTP semantics for generic clients. |
