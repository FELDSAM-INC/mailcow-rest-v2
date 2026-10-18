# Implementation Plan: Administrators and Domain Administrators

**Branch**: `main` (no feature branches, constitution 1.1.0) | **Date**: 2026-10-18 |
**Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/007-administrators-domain-admins/spec.md`

## Summary

Feature 007 lets administrators manage the server's administrator and domain administrator
accounts through `/api/v2`, and adds the account switching that support and reseller panels use:

- administrator accounts: list, search, filter, inspect, create, change, rename, deactivate,
  suspend second factors, delete and bulk actions, with the server never left without an active
  administrator;
- domain administrator accounts with their domains and 19 permission flags (`da_acl`), the same
  operations, and an account capabilities read with the server's flag defaults;
- single sign-on tokens shared with the legacy domain administrator login, and a token sign-in;
- impersonation (legacy "login as"/"dual login") with a distinguishable identity, an explicit end
  and automatic ends, and webmail sign-in on a user's behalf when the operator allows it.

The technical approach reuses every decision of feature 001 and adds:

- an OpenAPI 3.1 contract of 21 operations in four modules (`admins`, `domain-admins`,
  `sso-tokens`, `impersonation`), authored first and lint-clean, plus additive changes to 001's
  `Identity`, `Session`, `AuthMethod`, `AuditEvent`, `ApiKey`, `Forbidden` and `listAuditEvents`;
- services in `app/Services/Accounts/` that own every write to `admin`, `domain_admins`, `da_acl`,
  `tfa`, `fido2` and `da_sso`, with an atomic last-administrator guard (row locks), a single rename
  cascade and a single deletion cascade (also used by feature 002's domain deletion);
- an impersonation model on top of 001's session: the signed-in account stays the session
  principal, the target is a second session key, and the request principal becomes the target
  with the impersonator attached, validated on every request;
- binding of sessions, impersonations, API keys and tokens to the account's creation time and a
  Redis account generation, so nothing ever acts for a later account with the same username;
- two API-owned schema changes: impersonation columns on `restv2_audit_events`, and owner binding
  plus a revocation reason on `restv2_api_keys`.

Tests are written first and run on MariaDB with the upstream `2026-09a` schema fixture and on a real
Redis, with every request and response validated against the contract.

## Technical Context

**Language/Version**: PHP 8.4, Laravel 13.x (001 plan).

**Primary Dependencies**: no new Composer package. `ext-intl` (IDN conversion of domain names) is
already in 001's image. Feature 007 builds on code of features 001, 002, 003 and 005, listed under
"Dependencies on earlier features" below.

**Storage**: mailcow's MariaDB (upstream tables `admin`, `domain_admins`, `da_acl`, `da_sso`, `tfa`,
`fido2`, read-only `domain`, `alias_domain`, `mailbox`; `sasl_log` through feature 005), the
API-owned tables `restv2_audit_events` and `restv2_api_keys` (new columns) and
`restv2_preferences`. mailcow's Redis for the Laravel session (new key `restv2.impersonation`), the
account generation counters and the token sign-in throttle. Details in
[data-model.md](./data-model.md).

**Testing**: PHPUnit 12 feature and unit tests, written first, on MariaDB 10.11 with
`tests/fixtures/schema/2026-09a.sql` and on Redis 7.4 (001 R3). Every request and response is
validated against the bundled contract (001 R13). Concurrency tests run parallel PHP processes
against the test database. The webmail hand-off of feature 005 is used for real; nothing outbound
is faked except what 005 already fakes.

**Target Platform**: 001's `php:8.4-fpm-alpine` container behind nginx-mailcow.

**Project Type**: web service (REST API).

**Performance Goals**: SC-008: a page of 25 domain administrators with their domains and flags in
under 1 s (p95) with 5,000 domain administrators and 5,000 domains, through one paged query plus
three batched queries (research R14). SC-003: no lost administrator in 1,000 concurrent pairs of
deactivations and deletions (row locks, R8). Single-account operations p95 < 200 ms excluding
password hashing.

**Constraints**:

- No change to upstream tables or containers; the legacy UI, `/api/v1` and the legacy domain
  administrator login keep working on the same rows and tokens.
- Every upstream write goes through `app/Services/` and reproduces legacy's rows (spec parity
  table).
- No password, hash or token in logs, audit records or responses, apart from the token's issue
  response.
- An impersonation never survives a request that detects it is invalid, and nothing acts for a
  later account with a reused username.

**Scale/Scope**: up to about 10 administrators, 5,000 domain administrators and 5,000 domains per
server; 21 operations in 4 modules; 7 user stories.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design (below).*

| Principle | Gate | Status |
|---|---|---|
| I. Contract-first | All 21 operations are authored in `api/modules/{admins,domain-admins,sso-tokens,impersonation}/` before any code, with resource-named components. `npx @redocly/cli@2.64.1 lint api/openapi.yaml` passes. Changes to 001's components are additive (new optional members and enum values). Every test request and response is validated (001 R13); the contract coverage check includes the 21 operations. | PASS |
| II. HTTP contract | Plural nouns (`/admins`, `/domain-admins`); actions are sub-resources (`/second-factor-suspension`, `/sso-token`, `/session/impersonation`, `/webmail-sign-in`). `PATCH` is JSON Merge Patch. `201` with `Location` for creations (accounts, impersonation), `200` for reads, updates and actions, `204` for deletions and the impersonation end. `{data, meta}` lists with `limit`/`offset`/`sort`/`order`/`q`. RFC 9457 problems with eight documented types. `ETag` and `If-Match` on every account write; version tags in list entries and bulk items. camelCase, booleans, RFC 3339 UTC. Exceptions in Complexity Tracking. | PASS (justified) |
| III. Upstream schema | No migration touches an upstream table. The two migrations alter only `restv2_audit_events` and `restv2_api_keys` (001's `MigrationPrefixTest` enforces it). Table and column names are those of `init_db.inc.php` at `2026-09a`. | PASS |
| IV. Parity | Legacy `admin()`, `domain_admin()`, `domain_admin_sso()`, `acl()`, `triggers.global.inc.php`, `triggers.domainadmin.inc.php`, `sessions.inc.php` and `sogo-auth.php` were read. Writes reproduce legacy's rows and side effects (R6–R13, R20, R21). Every deviation is one of the spec's parity table; the plan adds none that contradicts it (see "Plan-level decisions"). | PASS |
| V. Coexistence | Own container, no patch to mailcow. Legacy sessions are never read or changed by impersonation (FR-053). Tokens use the legacy store on purpose (FR-058). The webmail sign-in uses feature 005's hand-off, which 005's spec allows explicitly. | PASS |
| VI. Security | Object-level authorisation for every role and key type (R24), with `404` for objects outside the caller's scope. Administrator account writes need an interactive administrator session. Impersonation is session-only, renews the identifier and the CSRF token, is re-validated on every request, and cannot manage the target's credentials. Every state change and every refused attempt after authentication is audited with actor and impersonated account. Tokens are single-use, 30 s, generated from `random_bytes`, never logged. | PASS |
| VII. Test-first | `tasks.md` puts every test task before its implementation, in every phase. Tests run on MariaDB with the real schema and on a real Redis, including concurrency and performance tests. | PASS |
| VIII. Simplicity | No new package. Shared abstractions (`AccountRenamer`, `AccountDeleter`, `LastAdminGuard`, `AccountBulkActionRunner`) each have at least two concrete users (the two roles, or the single and bulk operations). No permissions sub-resource duplicating the account `PATCH` (R1). | PASS |
| Cross-feature conventions | `404` for out-of-scope objects; whole-request refusal only for role, flag or key scope; field errors with rule codes; keys cannot write administrator accounts or impersonate; bulk actions of 100 with per-item version tags and outcomes; strong ETags over stored settings in every list entry; tokens shown once; one audit record per object with the bulk request ID; data read on every request; `ALLOW_ADMIN_EMAIL_LOGIN` reuses the `mailcow.conf` name; shared enumerations only appended; resource-named components; the deletion cascade is implemented once and reused by 002. | PASS |
| Runtime constraints | No new process or container. Configuration through environment variables (`ALLOW_ADMIN_EMAIL_LOGIN`). Logs keep 001's redaction (`token`, `pass`). | PASS |

**Post-design re-check (after Phase 1)**: PASS. The contract, data model and research introduce no
unjustified violation. The decisions that touched gates are: row locks for the last-administrator
guard (VI, SC-003), the account generation counter (VI, FR-036), automatic impersonation end on any
request including `GET` (II, justified below), and the token issue answering `200` (II, justified
below).

## Plan-level decisions

These decisions fill gaps the spec leaves open. None contradicts a clarification or the parity
table, so the spec is unchanged:

1. An automatic impersonation end also renews the session identifier and the CSRF token, like an
   explicit end, so that other tabs cannot run prepared writes as the impersonator (R17).
2. During an impersonation, the impersonator's own pending actions apply; the target's never do
   (R16). Ending the impersonation is allowed in a restricted session.
3. A domain administrator impersonating a mailbox user also gets its own values for the 14
   `da_acl`-only flags, exactly as legacy `acl('to_session')` (R18).
4. A token signs in only an account created no later than the token (R20, FR-036).
5. Refused attempts after authentication (`403`, `404`, `409`, `412`, `422`) are audited with the
   failure reasons other features already added (R22).
6. Bulk paths are `/admin-bulk-actions` and `/domain-admin-bulk-actions`, and the capabilities read
   is `/account-capabilities`, because `bulk-actions` and `capabilities` are valid usernames (R1).
   Features whose identifiers cannot collide use `/<collection>/bulk-actions`.

## Contract Operations

| Method and path | operationId | Auth | Story / FR |
|---|---|---|---|
| GET `/admins` | listAdmins | admin session or admin key (read-only too) | US4, FR-006, FR-008–FR-011 |
| POST `/admins` | createAdmin | admin session + CSRF | US4, FR-014–FR-017, FR-020 |
| GET `/admins/{username}` | getAdmin | admin session or admin key | US4, FR-013 |
| PATCH `/admins/{username}` | updateAdmin | admin session + CSRF | US4, FR-021, FR-022, FR-024–FR-026, FR-028, FR-064, FR-065 |
| DELETE `/admins/{username}` | deleteAdmin | admin session + CSRF | US4, FR-028–FR-030, FR-033–FR-036 |
| POST `/admins/{username}/second-factor-suspension` | suspendAdminSecondFactors | admin session + CSRF | US4, FR-027 |
| POST `/admin-bulk-actions` | applyAdminBulkAction | admin session + CSRF | US4, FR-075, FR-076 |
| GET `/account-capabilities` | getAccountCapabilities | admin session or admin key (read-only too) | US2, FR-077 |
| GET `/domain-admins` | listDomainAdmins | admin session or admin key (read-only too) | US1, FR-007–FR-012 |
| POST `/domain-admins` | createDomainAdmin | admin session + CSRF, or admin read-write key | US1, US2, FR-014–FR-020, FR-037 |
| GET `/domain-admins/{username}` | getDomainAdmin | admin session or admin key | US1, US2, FR-013, FR-038 |
| PATCH `/domain-admins/{username}` | updateDomainAdmin | admin session + CSRF, or admin read-write key | US1, US2, FR-021, FR-023–FR-026, FR-038–FR-041, FR-064, FR-065 |
| DELETE `/domain-admins/{username}` | deleteDomainAdmin | admin session + CSRF, or admin read-write key | US1, FR-031–FR-036 |
| POST `/domain-admins/{username}/second-factor-suspension` | suspendDomainAdminSecondFactors | admin session + CSRF, or admin read-write key | US1, FR-027 |
| POST `/domain-admin-bulk-actions` | applyDomainAdminBulkAction | admin session + CSRF, or admin read-write key | US1, FR-075, FR-076 |
| POST `/domain-admins/{username}/sso-token` | issueDomainAdminSsoToken | admin session + CSRF, or admin read-write key | US5, FR-057–FR-059 |
| POST `/session/sso-token` | createSessionWithSsoToken | any browser session + CSRF | US5, FR-060–FR-063 |
| GET `/session/impersonation` | getImpersonation | session | US3, FR-048 |
| POST `/session/impersonation` | startImpersonation | full session + CSRF | US3, FR-042–FR-047 |
| DELETE `/session/impersonation` | endImpersonation | session + CSRF (restricted allowed) | US3, FR-050 |
| POST `/mailboxes/{mailbox}/webmail-sign-in` | createWebmailSignInOnBehalf | full session + CSRF | US6, FR-054–FR-056 |

Changed operations and components of feature 001 (additive): `GET /me` and `GET /session` report
`impersonation` and `webmailOnBehalfEnabled`; `AuthMethod` and `Session.authMethod` gain `ssoToken`;
`AuditEvent` gains `impersonatedAccount` and `listAuditEvents` the `impersonatedAccount` filter
(US7, FR-067–FR-069); `ApiKey` gains `revocationReason` (FR-034); the shared `Forbidden` response
lists `impersonation-ended` and `not-allowed-while-impersonating`. Problem types are documented in
[`docs/problems.md`](../../docs/problems.md).

## Dependencies on earlier features

Features are implemented in number order, so the code below exists when 007 starts. Where a
delivered class has another name, the delivered one is used.

| Feature | Code used or extended by 007 | Why |
|---|---|---|
| 001 | `App\Auth\Principal`, `AuthMethod`, `PermissionFlag`; `SessionManager`; middleware `EnsureSessionIntegrity`, `RequireAuth`, `VerifyCsrfToken`, `EnforcePendingActions`; `IdentityService`, `PreferencesService`, `ApiKeyService`, `ApiKeyAuthenticator`, `AuditLogger`, `SignInThrottle`; `ListQuery`, `ETag`, `ProblemType`, `ApiFormRequest`; `IdentityResource`, `SessionResource`, `AuditEventResource`, `ApiKeyResource`; `SessionController@destroy`; `MailcowFactory` | Identity, sessions, keys, preferences, audit, lists and errors (R16–R18, R22) |
| 002 | Domain name normaliser (002 FR-006); domain deletion service | Domain set validation (R6); orphaned domain administrators deleted through `AccountDeleter` (R10, FR-032) |
| 003 | Password policy reader, checker and hasher (003 FR-024, FR-063); mailbox address normaliser (003 FR-007); mailbox deletion and rename service | Passwords (R5); impersonation and webmail targets (R16, R21); account generation bump (R11) |
| 005 | Webmail hand-off service and its route; own password, second-factor, recovery e-mail routes | Webmail on behalf (R21); impersonation guard on credential routes (R19) |

## Project Structure

### Documentation (this feature)

```text
specs/007-administrators-domain-admins/
├── spec.md              # Feature specification (with clarifications and parity table)
├── plan.md              # This file
├── research.md          # Phase 0: decisions R1–R25
├── data-model.md        # Phase 1: upstream and API-owned storage, Redis, state machines
├── quickstart.md        # Phase 1: validation guide
├── contracts/README.md  # Phase 1: pointer to the api/ modules (the contract is not duplicated)
├── checklists/requirements.md
├── tasks.md             # Phase 2
└── analysis.md          # /speckit-analyze summary
```

### Source Code (repository root)

Only the files that feature 007 adds or changes are listed; the layout is 001's.

```text
api/
├── openapi.yaml                                   # tags and path references added
├── modules/admins/{_index,admins,admin,admin-second-factor-suspension,admin-bulk-actions,account-capabilities}.yaml
├── modules/domain-admins/{_index,domain-admins,domain-admin,domain-admin-second-factor-suspension,domain-admin-bulk-actions}.yaml
├── modules/sso-tokens/{_index,domain-admin-sso-token,session-sso-token}.yaml
├── modules/impersonation/{_index,session-impersonation,mailbox-webmail-sign-in}.yaml
├── modules/audit/audit-events.yaml                # + impersonatedAccount filter
└── components/
    ├── schemas/   Admin, AdminCreateRequest, AdminPatch, DomainAdmin, DomainAdminAssignment,
    │              DomainAdminAssignmentInput, DomainAdminCreateRequest, DomainAdminPatch,
    │              DomainAdminPermissions, DomainAdminPermissionsPatch, DomainAdminPermissionName,
    │              AccountSecondFactorStatus, AccountBulkActionRequest, AccountCapabilities,
    │              DomainAdminSsoToken, SsoTokenSignInRequest, Impersonation,
    │              ImpersonationStartRequest, ImpersonationStarted, MailboxWebmailSignIn,
    │              AuditImpersonatedAccount; additive: Identity, Session, AuthMethod, AuditEvent,
    │              ApiKey, AuditEventType, AuditTarget, KnownProblemType, ValidationRuleCode
    ├── parameters/ AdminUsername, DomainAdminUsername, AdminSort, DomainAdminSort
    └── responses/  Admin{List,Created,}Response, DomainAdmin{List,Created,}Response,
                    AccountCapabilitiesResponse, DomainAdminSsoTokenResponse,
                    Impersonation{,Started}Response, MailboxWebmailSignInResponse, AdminConflict,
                    DomainAdminConflict, DomainAdminSsoTokenConflict, ImpersonationConflict,
                    MailboxWebmailSignInForbidden; additive: Forbidden

app/
├── Auth/{Principal,AuthMethod}.php                 # changed (001): impersonator, generation, ssoToken
├── Auth/ImpersonationEndReason.php
├── Http/
│   ├── Controllers/Api/V2/   AdminController, AdminSecondFactorSuspensionController,
│   │                         AdminBulkActionController, AccountCapabilitiesController,
│   │                         DomainAdminController, DomainAdminSecondFactorSuspensionController,
│   │                         DomainAdminBulkActionController, DomainAdminSsoTokenController,
│   │                         SsoTokenSessionController, ImpersonationController,
│   │                         MailboxWebmailSignInController; changed: SessionController (001)
│   ├── Middleware/           EnforceImpersonation, RefuseDuringImpersonation;
│   │                         changed: EnsureSessionIntegrity (001)
│   ├── Requests/Api/V2/      ListAdminsRequest, StoreAdminRequest, UpdateAdminRequest,
│   │                         ListDomainAdminsRequest, StoreDomainAdminRequest,
│   │                         UpdateDomainAdminRequest, AccountBulkActionRequest,
│   │                         StartImpersonationRequest, SsoTokenSignInRequest
│   └── Resources/Api/V2/     AdminResource, DomainAdminResource, DomainAdminAssignmentResource,
│                             AccountCapabilitiesResource, DomainAdminSsoTokenResource,
│                             ImpersonationResource, MailboxWebmailSignInResource;
│                             changed: IdentityResource, SessionResource, AuditEventResource,
│                             ApiKeyResource (001)
├── Models/Mailcow/DaSsoToken.php
├── Policies/{AdminPolicy,DomainAdminPolicy,ImpersonationPolicy}.php
├── Rules/{AccountUsername,AssignableDomain}.php
├── Services/
│   ├── Accounts/  AccountQuery, AccountRepresentation, AccountVersion, AccountGeneration,
│   │              LastAdminGuard, AccountRenamer, AccountDeleter, SecondFactorSuspender,
│   │              AdminAccountService, DomainAdminAccountService, DomainAdminPermissionService,
│   │              AccountCapabilitiesService, AccountBulkActionRunner, AccountBulkAction,
│   │              SecondFactorStatus
│   ├── Auth/      SsoTokenService, ImpersonationService; changed: SessionManager (001)
│   ├── Webmail/   WebmailOnBehalfService
│   └── changed:   Identity/IdentityService, Preferences/PreferencesService,
│                  ApiKeys/{ApiKeyService,ApiKeyAuthenticator}, Audit/AuditLogger (001);
│                  the domain deletion service (002); the mailbox deletion and rename service (003)
└── Support/Http/ProblemType.php                    # changed (001): eight cases

config/restv2.php                                    # allow_admin_email_login, bulk_limit
database/migrations/2026_10_18_000701_add_impersonation_to_restv2_audit_events_table.php
database/migrations/2026_10_18_000702_add_owner_binding_to_restv2_api_keys_table.php
database/factories/MailcowRows.php                   # changed: daSso(), orphan helpers
routes/api.php                                       # 21 routes; guard on 001/005 credential routes

tests/
├── Feature/Admins/            ListAdminsTest, GetAdminTest, CreateAdminTest, UpdateAdminTest,
│                              RenameAdminTest, DeleteAdminTest, AdminSecondFactorSuspensionTest,
│                              LastActiveAdminTest, LastActiveAdminConcurrencyTest,
│                              AdminBulkActionTest, AccountCapabilitiesTest, AdminAuthorizationTest
├── Feature/DomainAdmins/      ListDomainAdminsTest, GetDomainAdminTest, CreateDomainAdminTest,
│                              UpdateDomainAdminTest, DomainAdminDomainsTest,
│                              DomainAdminPermissionsTest, RenameDomainAdminTest,
│                              DeleteDomainAdminTest, DomainAdminSecondFactorSuspensionTest,
│                              DomainAdminBulkActionTest, DomainAdminAuthorizationTest,
│                              DomainDeletionCascadeTest, DomainAdminListPerformanceTest
├── Feature/Accounts/          AccountReuseTest, AccountVersionTest, LegacyParityRowsTest
├── Feature/SsoTokens/         IssueSsoTokenTest, SsoTokenSignInTest, SsoTokenConcurrencyTest,
│                              SsoTokenAuthorizationTest
├── Feature/Impersonation/     StartImpersonationTest, ImpersonationIdentityTest,
│                              EndImpersonationTest, ImpersonationValidityTest,
│                              ImpersonationRefusalsTest, ImpersonationAuditTest,
│                              ImpersonationAuthorizationTest
├── Feature/Webmail/           WebmailSignInOnBehalfTest
├── Feature/Audit/             AccountAuditTest, AuditImpersonationFilterTest, AccountSecretScanTest
└── Unit/Accounts/             AccountUsernameRuleTest, AccountVersionTest, SecondFactorStatusTest
```

**Structure Decision**: The single Laravel project of feature 001. Account writes live in
`app/Services/Accounts/`, one service per role over shared cascade classes; token and impersonation
logic in `app/Services/Auth/` next to 001's session code; controllers stay thin with one controller
per resource or action.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| A request that detects an invalid impersonation, including a `GET`, ends the impersonation and writes an audit record (constitution II: "GET is safe and never changes state") | FR-052 requires the first detecting request to end the impersonation and be refused. The state change is caused by an earlier change elsewhere (target deleted, scope or flag removed); the `GET` only observes it, exactly as 001's `EnsureSessionIntegrity` ends a session on any request after a deactivation. | Waiting for a state-changing request would let reads show the deleted target's data and keep the stale identity in every tab. Ending without an audit record would violate FR-067. |
| `POST /domain-admins/{username}/sso-token` answers `200`, not `201` with `Location`, although it stores a row | The token is a single-use credential that cannot be read back; a `Location` would point to nothing. The root document defines non-CRUD actions as sub-resources answering `200` with the ceremony data, as 001's passkey challenge does. | `201` with a fabricated `Location` would advertise a resource that does not exist. |
| The webmail sign-in sets mailcow's legacy session cookie (constitution V: sessions of the two implementations are independent) | FR-055 reuses feature 005's webmail hand-off, which 005's spec defines explicitly as a webmail-only legacy sign-in; constitution V allows it when a spec says so. Feature 007 adds no new coupling. | A separate webmail mechanism would need a change to SOGo or nginx (constitution V forbids patching containers). |
