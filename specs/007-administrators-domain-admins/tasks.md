---
description: "Task list for feature 007: administrators and domain administrators"
---

# Tasks: Administrators and Domain Administrators

**Input**: Design documents from `specs/007-administrators-domain-admins/`: [plan.md](./plan.md),
[spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md),
[quickstart.md](./quickstart.md). The contract is `api/` (modules `admins`, `domain-admins`,
`sso-tokens`, `impersonation`; already authored and lint-clean). Problem types are in
`docs/problems.md`.

**Tests**: Mandatory (constitution VII). In every phase the test tasks come first and must be
observed **failing** before the implementation tasks of that phase start. Every feature test runs on
MariaDB with the upstream `2026-09a` schema fixture and on a real Redis, and every request and
response is validated against the contract by 001's `tests/TestCase.php`. Tests that send
deliberately invalid bodies have `Invalid` in their method name (001 T017).

**Prerequisites**: Features 001, 002, 003 and 005 are implemented (features are implemented in
number order). Tasks that change their code say so; the classes named are those of the plans of
those features, and where a delivered class has another name the delivered one is used (plan,
"Dependencies on earlier features").

**Organisation**: Phase 1 is setup, Phase 2 the foundation that blocks every story, Phases 3 to 9
one user story each in spec priority order (US1–US3 P1, US4–US5 P2, US6–US7 P3), Phase 10 polish.

**Commands**: `docker compose run --rm app <command>` (001).

## Format: `- [ ] [ID] [P?] [Story?] Description with file path`

- **[P]**: can run in parallel with the other [P] tasks of the same block (different files, no
  dependency on unfinished tasks).
- **[USn]**: the user story. Setup, Foundational and Polish tasks have none.
- FR, SC and AS references point to `spec.md` ("AS US1-4" is acceptance scenario 4 of US1), R
  references to `research.md`.

---

## Phase 1: Setup

**Purpose**: Configuration, problem types and test helpers. No business logic.

- [X] T001 [P] Add to `config/restv2.php`: `allow_admin_email_login` from env
  `ALLOW_ADMIN_EMAIL_LOGIN`, `true` only when it matches `^([yY][eE][sS]|[yY])+$` (legacy
  `sogo-auth.php`), default `false`; `account_bulk_limit` = 100; `sso_token_lifetime` = 30 (not
  configurable). Add `ALLOW_ADMIN_EMAIL_LOGIN=n` to `.env.example` and `.env.testing`, and pass
  `ALLOW_ADMIN_EMAIL_LOGIN=${ALLOW_ADMIN_EMAIL_LOGIN:-n}` to the API service in
  `deploy/docker-compose.override.yml` (R21).
- [X] T002 [P] Add the cases `AccountExists` (409), `LastActiveAdmin` (409), `CannotDeleteOwnAccount`
  (409), `AlreadyImpersonating` (409), `ImpersonationEnded` (403), `NotAllowedWhileImpersonating`
  (403), `WebmailOnBehalfDisabled` (403) and `AccountInactive` (409) to
  `app/Support/Http/ProblemType.php`, with URIs `/api/v2/problems/<name>` and English titles (R23).
  Extend 001's test that checks every `ProblemType` case against `docs/problems.md` (or create
  `tests/Unit/Support/ProblemTypeDocumentedTest.php` if 001 has none), so that every new case has a
  `## <name>` section (FR-070).
- [X] T003 [P] Extend `database/factories/MailcowRows.php` and
  `tests/Support/Mailcow/MailcowFactory.php` with: an optional `created` for `admin()` and
  `mailbox()`; `domainAdmin(username, domains[], daAcl = [], active = 1, withDaAcl = true,
  assignmentActive = [])`; `assignmentOnly(username, domain, active = 1)` (orphaned or non-domain
  rows such as `ALL`); `daAclOnly(username, flags = [])`; `aliasDomain(alias, target)`;
  `daSso(username, token, createdSecondsAgo = 0)`; mailboxes with `active = 2` and resource kinds.
  Rows are written exactly as legacy writes them (data-model.md).
- [X] T004 [P] Create `tests/Support/Accounts/AccountAssertions.php` (trait used by the feature
  tests): `assertAdminRow(username, superadmin, active, forcePw, forceTfa)`,
  `assertAssignments(username, [[domain, active, created?]])`, `assertDaAcl(username, flags|null)`,
  `assertNothingLeftFor(username)` (no row in `admin`, `domain_admins`, `da_acl`, `tfa`, `fido2`,
  `da_sso`, `restv2_preferences`, and no unrevoked `restv2_api_keys`),
  `assertAudit(eventType, outcome, targetType, targetName, detailsSubset, impersonated = null)` and
  `assertNoSecretIn(string ...$secrets)` over `restv2_audit_events` and the captured log.
- [X] T005 Make sure 001's `tests/Support/Contract/OpenApiContract.php` matches a concrete path
  only when it defines the request's method and otherwise falls back to the templated path, so that
  `GET /admins/bulk-actions` validates as `getAdmin` (`docs/conventions.md`, "Bulk paths"); extend
  its unit test with that case. Run `composer contract:lint` and `composer contract:bundle` (FR-071); confirm that
  `build/contract/openapi.json` contains the 21 operationIds of plan.md "Contract Operations" and
  the additive changes to `Identity`, `Session`, `AuthMethod`, `AuditEvent`, `ApiKey` and
  `listAuditEvents`.

---

## Phase 2: Foundational (blocking prerequisites)

**Purpose**: Changes to feature 001's principal, session, key and audit code that every story
needs (FR-036 binding, impersonation columns, revocation reason), the account version tag, the
username rule and the policies. **No user story can start before this phase is complete.**

### Tests for the foundation (write first, observe failing) ⚠️

- [X] T006 [P] Write `tests/Unit/Accounts/AccountUsernameRuleTest.php` for `App\Rules\AccountUsername`
  (R4, FR-014): the value is trimmed and lower-cased; `""` → `required`; 256 characters → `tooLong`
  `{max: 255}`; `a b`, `bö`, `...`, `-_.` → `invalidFormat` `{format: accountUsername}`; `API` and
  ` api ` → `reserved` `{reserved: [api]}`; `ops.1-x_y` and `R1` (→ `r1`) pass.
- [X] T007 [P] Write `tests/Unit/Accounts/AccountVersionTest.php` for
  `App\Services\Accounts\AccountVersion` (R3, FR-064): the tag covers "`username`, `created`,
  `active`, `force_pw_update`, `force_tfa`; for domain administrators also the assignments ordered
  by domain as `[domain, active, created]` and the 19 `da_acl` values (or `null` without a row)";
  it changes when any of them changes, never with `modified`, `tfa` or `fido2`; a missing `da_acl`
  row and an all-zero row give different tags; equal input gives equal tags; the format is 001's
  strong ETag.
- [X] T008 [P] Write `tests/Unit/Accounts/SecondFactorStatusTest.php` (FR-008, R2): no entry →
  `none`; one active `totp`, `webauthn` or `yubi_otp` → `active`; entries all inactive →
  `suspended`; only `u2f` or `hotp` entries (active or not) → `none`.
- [X] T009 [P] Write `tests/Feature/Accounts/AccountBindingTest.php` (FR-036, R11): a session signed
  in as domain administrator `reseller1` gets `401 unauthenticated` at its next request when the
  `admin` row is deleted and re-inserted with a later `created` (a legacy deletion and
  re-creation), and the same for a mailbox session; an unchanged account's session keeps working.
  A key whose `owner_created_at` differs from the owner's current `created` is refused with `401
  api-key-invalid` and published to fail2ban (`ChannelSpy`); a key of the unchanged owner keeps
  working. `AccountGeneration::current()` is 0 for an unknown name and `bump()` increments it.
- [X] T010 [P] Write `tests/Feature/Accounts/AuditImpersonationColumnsTest.php` (FR-067, R22): when
  the request principal carries an impersonator, `AuditLogger::record()` writes the impersonator as
  `actor_username`/`actor_role` and the target in `impersonated_username`/`impersonated_role`;
  otherwise both columns are `NULL`. `GET /audit-events` returns `impersonatedAccount` as
  `{username, role}` or `null` (contract-validated).
- [X] T011 [P] Write `tests/Feature/Schema/Feature007MigrationsTest.php`: after the migrations,
  `restv2_audit_events` has `impersonated_username VARCHAR(255) NULL` (indexed with `occurred_at`)
  and `impersonated_role VARCHAR(16) NULL`; `restv2_api_keys` has `owner_created_at DATETIME NULL`
  and `revocation_reason VARCHAR(16) NULL`; a key inserted before the migration is back-filled with
  its owner's `admin.created`; 001's `MigrationPrefixTest` still passes.
- [X] T012 [P] Write `tests/Feature/ApiKeys/ApiKeyRevocationReasonTest.php` (FR-034): 001's
  `POST /api-keys/{id}/revocation` stores `revocation_reason = 'manual'` and returns
  `revocationReason: manual`; an active key returns `revocationReason: null`; the version tag
  changes on revocation; `ApiKeyService::revokeAllForOwner('reseller1', 'alice', 'ownerDeleted')`
  revokes only that owner's unrevoked keys and returns their IDs; `transferOwner('a', 'b')` moves
  `owner_username` and keeps `owner_created_at`.

### Implementation of the foundation

- [X] T013 [P] Create `database/migrations/2026_10_18_000701_add_impersonation_to_restv2_audit_events_table.php`
  (columns and index of data-model.md) and add the two attributes to `app/Models/AuditEvent.php`.
- [X] T014 [P] Create `database/migrations/2026_10_18_000702_add_owner_binding_to_restv2_api_keys_table.php`
  (`owner_created_at` DATETIME(0) NULL, `revocation_reason` VARCHAR(16) NULL, then the back-fill
  `UPDATE restv2_api_keys k JOIN admin a ON a.username = k.owner_username SET k.owner_created_at =
  a.created WHERE k.owner_created_at IS NULL`) and update `app/Models/ApiKey.php`. This makes T011
  pass.
- [X] T015 Change 001's `app/Auth/Principal.php` (add `?Principal $impersonator`,
  `?string $impersonationId`, `?CarbonImmutable $impersonationStartedAt`,
  `?string $accountCreatedAt`, and `actingAccount(): Principal` returning the impersonator when
  present) and `app/Auth/AuthMethod.php` (case `SsoToken = 'ssoToken'`). Create
  `app/Auth/ImpersonationEndReason.php` (`TargetDeletedOrRenamed`, `TargetOutOfScope`,
  `LoginAsRevoked`).
- [X] T016 [P] Create `app/Services/Accounts/AccountGeneration.php` on the `restv2` Redis connection:
  `current(kind, username): int` (`GET restv2:account-generation:<kind>:<username>`, absent = 0)
  and `bump(kind, username): int` (`INCR`, no TTL); `kind` is `admin` or `mailbox`, the username
  lower-cased. It is read only to validate impersonation targets (R11).
- [X] T017 Change 001's `app/Services/Auth/SessionManager.php` to store `accountCreatedAt` (raw
  `admin.created` or `mailbox.created`) in `restv2.principal` at every sign-in, and add
  `rebindPrincipal(string $newUsername)` (used by a self-rename; it also calls feature 005's
  `SessionRegistry::rename()` for the session's handle). Change 001's
  `app/Http/Middleware/EnsureSessionIntegrity.php` to end the session when the account's current
  `created` differs from the stored value. This makes T009's session cases pass.
- [X] T018 Change 001's `app/Services/ApiKeys/ApiKeyService.php` (write `owner_created_at` at
  creation; `revocation_reason = 'manual'` on revocation; new `revokeAllForOwner(string $owner,
  string $revokedBy, string $reason): array` and `transferOwner(string $old, string $new): void`;
  the version tag also covers `revocation_reason`), `app/Services/ApiKeys/ApiKeyAuthenticator.php`
  (owner `created` must equal `owner_created_at`, otherwise `api-key-invalid` with the fail2ban
  report) and `app/Http/Resources/Api/V2/ApiKeyResource.php` (`revocationReason`). This makes T009's
  key cases and T012 pass.
- [X] T019 [P] Change 001's `app/Services/Preferences/PreferencesService.php`: add
  `renameAccount(AccountKind $kind, string $old, string $new)` and
  `deleteForAccount(AccountKind $kind, string $username)` (data-model.md "restv2_preferences").
- [X] T020 Change 001's `app/Services/Audit/AuditLogger.php`: actor from
  `Principal::actingAccount()`, the target of an impersonating principal in
  `impersonated_username`/`impersonated_role`; add the event types of data-model.md "New events"
  with their `details` whitelists (`changes`, `passwordChanged`, `passwordPreHashed`,
  `domainsAdded`, `domainsRemoved`, `permissionsChanged`, `revokedApiKeyIds`,
  `removedSecondFactors`, `removedPasskeys`, `removedSsoTokens`, `suspendedCount`, `expiresAt`,
  `endedBy`, `endReason`, `durationSeconds`, `targetRole`, `trigger`, `bulkRequestId`,
  `problemType`, `domains`, `permissions`, `active`, `forcePasswordChange`,
  `forceSecondFactorSetup`) and the failure reasons it may use (`permissionDenied`, `notFound`,
  `validationFailed`, `versionOutdated`, `conflict`, `accountInactive`; `AuditFailureReason` is
  append-only and gets no new value). Mark every state-changing route of this feature for the
  shared refusal auditing of `docs/conventions.md` (`RefusalAuditor`, or the equivalent the first
  implemented feature created) when its routes are registered (T045, T049, T062, T077, T084,
  T086). Change
  `app/Http/Resources/Api/V2/AuditEventResource.php` to return `impersonatedAccount`. This makes
  T010 pass.
- [X] T021 [P] Create `app/Rules/AccountUsername.php` (rule codes of T006) and
  `app/Services/Accounts/SecondFactorStatus.php` (enum with `fromTfaRows()`). This makes T006 and
  T008 pass.
- [X] T022 [P] Create `app/Services/Accounts/AccountVersion.php` (`forAdmin(row)`,
  `forDomainAdmin(row, assignments, daAcl)`, canonical JSON then 001's `ETag`). This makes T007
  pass.
- [X] T023 Create `app/Services/Accounts/AccountRepresentation.php`: loads one account by username
  (lower-cased, case-insensitive match) and role (`superadmin`), with its `tfa` aggregate, active
  `fido2` count and, for domain administrators, its assignments (joined to `domain` for
  `domainExists`, `idn_to_utf8` UTS #46 for `domainUnicode`, ordered by domain) and `da_acl` row;
  returns `null` for an unknown username or the other role. Create
  `app/Http/Resources/Api/V2/AdminResource.php`, `DomainAdminResource.php` and
  `DomainAdminAssignmentResource.php` (contract `Admin`, `DomainAdmin`, `DomainAdminAssignment`;
  times through 001's `MailcowDateTime`; `etag` from `AccountVersion`).
- [X] T024 Create `app/Policies/AdminPolicy.php` and `app/Policies/DomainAdminPolicy.php` (R24):
  every ability needs an administrator principal (else `forbidden`); `AdminPolicy` write abilities
  (`create`, `update`, `delete`, `suspend`, `bulk`) need a session (a key gets `session-required`);
  read abilities accept keys; `DomainAdminPolicy` accepts sessions and administrator keys for every
  ability (read-only keys are already refused on writes by 001's `EnforceKeyScope`). Register them
  in `app/Providers/AppServiceProvider.php`.

**Checkpoint**: T006–T012 pass. 001's suite still passes (run `composer test`).

---

## Phase 3: User Story 1 — Manage domain administrators (P1) 🎯 MVP

**Goal**: Administrators and billing systems create, list, search, filter, inspect, change, rename,
suspend second factors of, bulk-process and delete domain administrators with their domains.

**Independent Test**: AS US1-1 to US1-12 through the API, comparing rows with legacy's and signing
the accounts in through feature 001.

### Tests for User Story 1 (write first, observe failing) ⚠️

- [X] T025 [P] [US1] Write `tests/Feature/DomainAdmins/CreateDomainAdminTest.php` for
  `createDomainAdmin` (AS US1-1 to US1-3, FR-014 to FR-020, R6):
  - `201`, `Location: /api/v2/domain-admins/reseller1`, `ETag`, representation without any password
    member; the username `Reseller1` is stored as `reseller1`; `active` defaults to `true` and the
    forced flags to `false`;
  - rows: `admin` (`superadmin = 0`, hash verifiable by 001's `LegacyPasswordVerifier`,
    `attributes = {"force_tfa":"0","force_pw_update":"0"}`), one `domain_admins` row per domain with
    the account's `active`, one `da_acl` row with the schema defaults; `reseller1` signs in through
    `POST /session` with `role: domainAdmin`;
  - `active: false` gives inactive assignments; sent forced flags are stored as `"1"`; sent
    `permissions` are stored and unsent flags get the defaults;
  - `domains` with `nope.example` → `422 notFound` on `domains.1` and no row at all; an alias domain
    name → `notEligible` `{kind: aliasDomain}`; `bad..name` → `invalidFormat` `{format: domainName}`;
    every bad entry named; `["A.example", "a.example "]` merged into one assignment; a Unicode
    domain stored in ASCII form;
  - username taken by an administrator or domain administrator in any letter case → `409
    account-exists`, nothing written; `api` → `reserved`;
  - password policy violations → `422` with 003's codes (`tooShort`, `missingCharacterClass`) on
    `password`; a `{SSHA512}…` value stored as given;
  - an orphaned `da_acl` row and orphaned `domain_admins` row of the username are replaced (the new
    account's flags are the defaults or the sent values, not the orphan's);
  - unknown member → `unknownField`; audit `domainAdminCreation` success without the password; when
    the audit insert fails (forced through a test double of the connection) nothing is stored.
- [X] T026 [P] [US1] Write `tests/Feature/DomainAdmins/ListDomainAdminsTest.php` for
  `listDomainAdmins` (AS US1-9, FR-010, FR-012, R14): default page 25, `limit` 1–1000 (`1001` →
  `outOfRange`), `meta.total`; every entry equals `getDomainAdmin`'s `data` including `etag`;
  accounts without assignments are listed with `domains: []`; assignments to `ALL`, a removed
  domain or an alias domain name have `domainExists: false`; assignment statuses as stored;
  `q` matches usernames, ASCII domain names and Unicode domain names (`q=bücher` finds an
  assignment to `xn--bcher-kva.example`), case-insensitively, with `%` and `_` literal; filters
  `active`, `secondFactorStatus` (all three values), `forcePasswordChange`,
  `forceSecondFactorSetup`, `domain` (repeatable, any of, Unicode accepted), `permission` +
  `permissionValue` (missing `da_acl` row counts as `false`; `permissionValue` without
  `permission` → `422 required` on `permission`); sorts `username`, `active`, `createdAt`,
  `modifiedAt` (never-modified first ascending), `domainCount`, ties by username; administrators
  never appear.
- [X] T027 [P] [US1] Write `tests/Feature/DomainAdmins/GetDomainAdminTest.php` for `getDomainAdmin`
  (FR-003, FR-007, FR-009, FR-013): any letter case finds the account; an administrator's username
  and an unknown username both answer the same `404 about:blank`; `ETag` header equals `data.etag`; `modifiedAt` is
  `null` for a never-modified row; `secondFactorStatus` and `activePasskeyCount` reflect `tfa` and
  `fido2`; a missing `da_acl` row reads as all flags `false` (FR-041).
- [X] T028 [P] [US1] Write `tests/Feature/DomainAdmins/UpdateDomainAdminTest.php` for
  `updateDomainAdmin` attributes (FR-021 to FR-024, FR-026, FR-065, R7):
  - unsent members keep their values; `{}` → `422 required` on `body`; unknown member →
    `unknownField`; `createdAt`, `modifiedAt`, `secondFactorStatus`, `activePasskeyCount` and `etag`
    sent unchanged are accepted, sent changed → `immutable`; `null` for `active` → `invalidType`;
  - forced flags are written with `JSON_SET` and other `attributes` keys survive;
  - `active: false` deactivates the account and every assignment (AS US1-5); the domain
    administrator's open session gets `401` at its next request and its key `401 api-key-invalid`;
    `active: true` restores both (keys not revoked, FR-035);
  - a password change stores a new hash, leaves the forced flags, the account's sessions and keys
    untouched;
  - `If-Match` with a stale tag → `412`, nothing changed, also after a raw `UPDATE` that simulates a
    legacy edit, and the next `GET` shows the legacy edit (FR-072, SC-010); a patch that changes nothing returns `200` and writes no audit record;
  - audit `domainAdminUpdate` with `changes` old/new and `passwordChanged: true` without the value.
- [X] T029 [P] [US1] Write `tests/Feature/DomainAdmins/DomainAdminDomainsTest.php` (AS US1-4,
  FR-023): replacing `[a, b]` with `[b, c]` keeps the `b` row's `id`, `created` and `active`,
  deletes `a`, inserts `c` with the account's status; the domain administrator's next `GET /me`
  shows the new scope; `domains: []` → `422 required`; one invalid entry refuses the whole request
  and changes nothing; a non-domain row (`ALL`) is dropped by any saved set; items sent as
  assignment objects with unchanged read-only members are accepted, changed members → `immutable`;
  audit `domainsAdded` and `domainsRemoved`.
- [X] T030 [P] [US1] Write `tests/Feature/DomainAdmins/RenameDomainAdminTest.php` (AS US1-7, FR-025,
  R9): with two passkeys, one TOTP entry (inactive) and one WebAuthn entry, assignments, a
  `da_acl` row, two keys and preferences, `PATCH {"username": "reseller-one"}` moves every one of
  them to `reseller-one` (assignment `created` kept, keys keep `owner_created_at`); unused `da_sso`
  rows of the old name are deleted; the account's open session gets `401` at its next request; a
  collision with an administrator's name → `409 account-exists` and nothing changed (all tables);
  `RESELLER1` → no change; audit records written before keep `reseller1`; a later account named
  `reseller1` sees none of the moved data.
- [X] T031 [P] [US1] Write `tests/Feature/DomainAdmins/DeleteDomainAdminTest.php` (AS US1-8, FR-031,
  FR-033, FR-034, FR-036, R10): `204`; `assertNothingLeftFor('reseller1')`; its keys are revoked
  with `revocationReason: ownerDeleted` and `revokedBy` = the caller; its session gets `401`; audit
  `domainAdminDeletion` with `revokedApiKeyIds`, `removedSecondFactors`, `removedPasskeys`,
  `removedSsoTokens`; an account without assignments is deletable; `404` for an unknown username
  and for an administrator's; stale `If-Match` → `412`; a new `reseller1` created afterwards has no
  key, passkey, second factor, preference or flag of the old one, and the old key stays revoked.
- [X] T032 [P] [US1] Write `tests/Feature/DomainAdmins/DomainAdminSecondFactorSuspensionTest.php`
  (AS US1-6, FR-027, R13): with an active TOTP and an active WebAuthn entry, the suspension answers
  `200` with `secondFactorStatus: suspended`, every `tfa` row has `active = 0`, `fido2` rows are
  unchanged; `POST /session` with the password alone then yields an active session (no pending
  second factor) and both entries are active again; without active entries → `200`, no change,
  audit `suspendedCount: 0`; stale `If-Match` → `412`.
- [X] T033 [P] [US1] Write `tests/Feature/DomainAdmins/DomainAdminBulkActionTest.php` for
  `applyDomainAdminBulkAction` (AS US1-11, US1-12, FR-075, FR-076, R15): three targets with list
  tags, one changed in between → two `done` (with new `version`) and deactivated, one `outdated`
  (`412` problem) unchanged; one audit record per processed account, each with `bulkRequestId` =
  `data.bulkRequestId`; 101 targets → `422 tooMany` `{max: 100}` on `targets`; a username twice
  (also in another letter case) → processed and reported once; an administrator's username →
  `notFound`; a domain administrator named `bulk-actions` is still read by
  `GET /domain-admins/bulk-actions`; `delete` applies every effect of T031; `suspendSecondFactors` as T032; `activate` on
  an active account → `done`; results in request order and `summary` counts; an `action` other than
  the four (for example `rename` or `setPassword`) → `422 notInList` (FR-073); every outdated item
  is left unchanged while the others proceed (SC-010).
- [X] T034 [P] [US1] Write `tests/Feature/DomainAdmins/DomainAdminAuthorizationTest.php` (AS US1-10,
  FR-001, FR-004, FR-005): for each of the seven domain administrator operations: administrator
  session → allowed; administrator read-write key → allowed; administrator read-only key → reads
  allowed, writes `403 insufficient-key-scope`; domain administrator session and key → `403
  forbidden` (also on its own account); mailbox user → `403 forbidden`; no credentials → `401`;
  restricted administrator session → `403 pending-action-required`; session write without CSRF
  token → `403 csrf-token-mismatch`.
- [X] T035 [P] [US1] Write `tests/Feature/DomainAdmins/DomainDeletionCascadeTest.php` (FR-032): 002's
  `DELETE /domains/{domain}` of a domain administrator's only domain deletes the account with the
  full cascade of T031 (also `tfa`, `fido2`, `da_sso`, keys revoked `ownerDeleted`, preferences),
  audited as `domainAdminDeletion` with `trigger: domainDeletion` in the same request; a domain
  administrator who keeps another domain is untouched.

### Implementation for User Story 1

- [X] T036 [P] [US1] Create `app/Rules/AssignableDomain.php`: normalises with feature 002's
  `App\Support\Domain\DomainName` (002 FR-006); reports `invalidFormat` `{format: domainName}`, `notEligible`
  `{kind: aliasDomain}` (row in `alias_domain`) or `notFound` per entry; exposes the normalised,
  de-duplicated list.
- [X] T037 [P] [US1] Create `app/Services/Accounts/AccountQuery.php` (R14): base query per role,
  the filters, the search (usernames by escaped `LIKE`; for domain administrators also ASCII domain
  names in SQL and Unicode names through `idn_to_utf8` + `mb_stripos` over the distinct assigned
  names), the sorts with the username tie-breaker, 001's `ListQuery` paging, and three batched
  loads per page (assignments with `domainExists`, `tfa` aggregate, active `fido2` counts).
- [X] T038 [P] [US1] Create `app/Services/Accounts/SecondFactorSuspender.php`:
  `suspend(username): int` runs `UPDATE tfa SET active = '0' WHERE username = ?` and returns the
  number of entries that were active.
- [X] T039 [P] [US1] Create `app/Services/Accounts/DomainAdminPermissionService.php` (R12): maps the
  19 contract names to `da_acl` columns through 001's `PermissionFlag`; `insertForNewAccount(username,
  sentFlags)` (delete orphan, insert only the sent columns); `apply(username, sentFlags): array`
  (update sent columns, or insert with every other flag `0` when no row exists; returns
  `{flag: {old, new}}`).
- [X] T040 [US1] Create `app/Services/Accounts/AccountRenamer.php` (R9): one transaction with the
  updates of `admin`, `domain_admins`, `da_acl`, `tfa`, `fido2`, `ApiKeyService::transferOwner()`,
  `PreferencesService::renameAccount()`, `DELETE FROM da_sso WHERE username = :old`; after commit
  feature 005's `SessionRegistry::endAll('admin', old, exceptHandle)` (the caller's handle when it
  renamed itself), `AccountGeneration::bump('admin', old)` and, for a self-rename,
  `SessionManager::rebindPrincipal(new)`. A duplicate-key error becomes `account-exists`.
- [X] T041 [US1] Create `app/Services/Accounts/AccountDeleter.php` (R10): `delete(username, Principal
  $actor, ?string $bulkRequestId, string $trigger = 'request')` counts `tfa`/`fido2` rows, deletes
  from `domain_admins`, `admin`, `da_acl`, `tfa`, `fido2`, `da_sso`, calls
  `ApiKeyService::revokeAllForOwner(..., 'ownerDeleted')` and
  `PreferencesService::deleteForAccount()`, writes the audit record, and after commit calls feature
  005's `SessionRegistry::endAll('admin', username)` and `AccountGeneration::bump('admin',
  username)`; `deleteOrphanedDomainAdmins(array $usernames, Principal $actor)` serves feature 002.
  It joins an outer transaction when one is open and defers the after-commit steps until the outer
  commit (`DB::afterCommit`).
- [X] T042 [US1] Create `app/Services/Accounts/DomainAdminAccountService.php`: `create()` (R6, one
  transaction: `admin` insert with normalised `attributes`, orphan cleanup, assignments,
  `DomainAdminPermissionService::insertForNewAccount()`, audit), `update()` (R7: lock row, compare
  `If-Match` with `AccountVersion` inside the transaction, read-only and no-op handling, forced
  flags with `JSON_SET`, domain set replacement keeping rows, status cascade, password through
  feature 003's `PasswordPolicyChecker` and `MailboxPasswordHasher`, `permissions` through `DomainAdminPermissionService::apply()`,
  rename through `AccountRenamer`, audit with changes), `delete()` (through `AccountDeleter`) and
  `suspendSecondFactors()` (through `SecondFactorSuspender`, audit). Every method throws the
  `ProblemException` of the documented problem; nothing is written on failure.
- [X] T043 [US1] Create `app/Services/Accounts/AccountBulkAction.php` (enum) and
  `app/Services/Accounts/AccountBulkActionRunner.php` (R15): normalises and de-duplicates targets,
  runs each through the role's service with its own transaction, maps outcomes (`done` with the new
  version, `notFound`, `outdated`, `refused` for `last-active-admin` and
  `cannot-delete-own-account`, `failed` with a logged `500` problem), passes `bulkRequestId` to
  every audit record, and builds `BulkResult` with `summary`.
- [X] T044 [US1] Create the Form Requests `app/Http/Requests/Api/V2/ListDomainAdminsRequest.php`
  (`ListQuery` with the sort enum and filters of the contract), `StoreDomainAdminRequest.php`
  ("`username`: required, `AccountUsername`; `password`: required, string 1–4096, feature 003's
  policy unless pre-hashed; `domains`: required array of 1+ strings, `AssignableDomain`;
  `active`/`forcePasswordChange`/`forceSecondFactorSetup`: optional booleans; `permissions`: object
  of the 19 boolean names, unknown → `unknownField`; unknown top-level member → `unknownField`"),
  `UpdateDomainAdminRequest.php` (the same rules, all optional, `minProperties` 1 → `required` on
  `body`, `domains` items string or assignment object, read-only members accepted only when
  unchanged → `immutable`) and `AccountBulkActionRequest.php` ("`action` in activate, deactivate,
  suspendSecondFactors, delete; `targets`: 1 to `config('restv2.account_bulk_limit')` items, more →
  `tooMany` `{max: 100}`; each `id` string 1–255, optional `version` string").
- [X] T045 [US1] Create `app/Http/Controllers/Api/V2/DomainAdminController.php` (`index`, `show`,
  `store`, `update`, `destroy`), `DomainAdminSecondFactorSuspensionController.php` (`store`) and
  `DomainAdminBulkActionController.php` (`store`), authorised through `DomainAdminPolicy`. Register
  in `routes/api.php`: `GET|POST /domain-admins`, `POST /domain-admins/bulk-actions` (before the
  `{username}` routes, which never accept `POST`),
  `GET|PATCH|DELETE /domain-admins/{username}` and
  `POST /domain-admins/{username}/second-factor-suspension` with `restv2.auth:authenticated`,
  `restv2.csrf` on writes and the `{username}` constraint `[A-Za-z0-9._-]+`. Responses carry
  `ETag` (single account) and `Location` (create). This makes T025–T034 pass.
- [X] T046 [US1] Change feature 002's `app/Services/DomainAdmins/OrphanedDomainAdminRemover.php`
  (002 D18, planned to be extended by 007): keep its selection of the orphaned usernames (for 002's
  audit record and deletion preview) and hand them to
  `AccountDeleter::deleteOrphanedDomainAdmins()` inside the domain deletion transaction instead of
  running its own `DELETE FROM admin` and `DELETE FROM da_acl`. This makes T035 pass; 002's deletion
  and preview tests stay green.

**Checkpoint**: US1 works on its own: domain administrators can be managed end to end.

---

## Phase 4: User Story 2 — Set a domain administrator's permissions (P1)

**Goal**: Administrators read the 19 flags and their server defaults and change only the flags they
send; nobody else can.

**Independent Test**: AS US2-1 to US2-6; each flag changed, read back, compared with `da_acl` and
with 001's identity of the domain administrator.

### Tests for User Story 2 (write first, observe failing) ⚠️

- [ ] T047 [P] [US2] Write `tests/Feature/DomainAdmins/DomainAdminPermissionsTest.php` (AS US2-1,
  US2-3 to US2-6, FR-037 to FR-041): a new account reads the schema defaults (`loginAs: true`,
  `unlimitedQuota: false`, all 19 compared with `information_schema`); for each of the 19 flags a
  `PATCH {"permissions": {<flag>: <negated>}}` changes exactly that `da_acl` column and the domain
  administrator's next `GET /me` (session and key) shows it (FR-002, FR-040); `{"permissions":
  {"fooBar": true}}` → `422 unknownField` on `permissions.fooBar`, nothing changed; a domain
  administrator patching itself → `403 forbidden`; two administrators with the same tag → the
  second `PATCH` gets `412`; without a `da_acl` row every flag reads `false`, and a change creates
  the row with the sent flags and every other flag `0` (FR-041); audit `permissionsChanged`.
- [ ] T048 [P] [US2] Write `tests/Feature/Admins/AccountCapabilitiesTest.php` for
  `getAccountCapabilities` (AS US2-2, FR-077, R12): `domainAdminPermissionDefaults` equals the
  `COLUMN_DEFAULT` values of `da_acl` in `information_schema`; `newAccountDefaults` is
  `{active: true, forcePasswordChange: false, forceSecondFactorSetup: false}`; `bulkLimit` 100,
  `pageSize` `{default: 25, maximum: 1000}`, `usernameMaxLength` 255, `ssoTokenLifetime` 30;
  administrator session and read-only administrator key → `200`; domain administrator and mailbox
  user → `403 forbidden`; no row is written and no audit record created.

### Implementation for User Story 2

- [ ] T049 [US2] Create `app/Services/Accounts/AccountCapabilitiesService.php` (the
  `information_schema` query of data-model.md, parsing `'1'`/`1`/`'0'`/`0`, and the configured
  limits), `app/Http/Resources/Api/V2/AccountCapabilitiesResource.php` and
  `app/Http/Controllers/Api/V2/AccountCapabilitiesController.php` (`show`, `AdminPolicy::viewAny`).
  Register `GET /capabilities/accounts` with `restv2.auth:authenticated` in `routes/api.php`. This
  makes T048 pass.
- [ ] T050 [US2] Complete the `permissions` paths of `DomainAdminAccountService::update()` and
  `UpdateDomainAdminRequest` where T047 still fails (partial change, missing record, unknown names,
  audit `permissionsChanged`). This makes T047 pass.

**Checkpoint**: US1 and US2 work independently.

---

## Phase 5: User Story 3 — Log in as another account and return (P1)

**Goal**: Administrators, and domain administrators with `loginAs`, impersonate eligible accounts
with a distinguishable identity, return with one action, and every impersonation ends safely when
it becomes invalid.

**Independent Test**: AS US3-1 to US3-10, as an administrator and as domain administrators with
and without the flag, with keys and restricted sessions.

### Tests for User Story 3 (write first, observe failing) ⚠️

- [ ] T051 [P] [US3] Write `tests/Feature/Impersonation/StartImpersonationTest.php` for
  `startImpersonation` (AS US3-1, US3-4, US3-6, FR-042 to FR-045, R16): administrator `alice` →
  `{"role":"mailboxUser","username":"Bob@A.example"}` answers `201` with
  `Location: /api/v2/session/impersonation`, `target.username` `bob@a.example`,
  `impersonator` `{alice, admin, password}`, `identity.role` `mailboxUser`; the session cookie value
  changed and the previous CSRF token is refused (`csrf-token-mismatch`) while `data.csrfToken.token`
  works; administrator → domain administrator `reseller1` works; domain administrator `reseller1`
  with `loginAs` → `carol@a.example` (its domain) works, `dave@b.example` (other domain) and an
  unknown address both answer `404` with identical bodies apart from `requestId` (FR-003); a resource →
  `404`; an administrator's username as a `domainAdmin` target → `404`; an inactive mailbox and a
  mailbox in an inactive domain → `201`; a second start → `409 already-impersonating`; audit
  `impersonationStart` with the impersonation ULID and `impersonatedAccount`; no
  `PHPREDIS_SESSION:*` key is written or changed by starting or ending (FR-053).
- [ ] T052 [P] [US3] Write `tests/Feature/Impersonation/ImpersonationIdentityTest.php` (FR-046 to
  FR-048, R18): during an impersonation `GET /me` has the target's `username`, `role` and `scope`,
  `impersonation` (impersonator, sign-in method, target, start time) and the impersonator's
  `authentication` and `preferences`; an administrator impersonator yields all 29 flags `true`;
  domain administrator `reseller1` (with `syncjobs` and `pushover` off and `unlimitedQuota` on)
  impersonating a mailbox user yields every `user_acl` flag `true` except `syncjobs`,
  `quarantine`, `appPasswds`, `pushover`, `spamPolicy`, which equal its own values, and the 14
  `da_acl`-only flags equal to its own values; a target with `force_pw_update` does not restrict the
  session; an impersonator who gets `force_pw_update` during the impersonation is restricted, can
  still `GET /me` and `DELETE /session/impersonation`; `PATCH /me/preferences` changes the
  impersonator's `restv2_preferences` row only; `GET /session` and `GET /session/impersonation`
  report the impersonation.
- [ ] T053 [P] [US3] Write `tests/Feature/Impersonation/EndImpersonationTest.php` for
  `endImpersonation` and sign-out (AS US3-3, US3-10, FR-050, FR-051): `DELETE` → `204`; `GET /me` is
  `alice` again with her original `authentication.method`; the session cookie and CSRF token were
  renewed; audit `impersonationEnd` with `endedBy: explicit` and `durationSeconds`; without an
  impersonation → `204`, no audit record; `DELETE /session` during an impersonation → `204`, every
  later request is anonymous, audit `impersonationEnd` (`endedBy: signOut`) then `signOut`; a
  password sign-in (`POST /session`) on an impersonating session audits `endedBy: sessionReplaced`;
  `GET /session/impersonation` afterwards → `404`; starting and ending each answer within 1 s
  (SC-007 budget of 10 s).
- [ ] T054 [P] [US3] Write `tests/Feature/Impersonation/ImpersonationValidityTest.php` (AS US3-8,
  FR-052, R17): for each cause — target mailbox deleted through 003's `DELETE /mailboxes/{mailbox}`,
  deleted as a raw row (legacy), renamed through 003's rename, re-created with the same address in
  the same second (generation bump), domain administrator target deleted through
  `DELETE /domain-admins/{username}`, the impersonating domain administrator's assignment
  deactivated (`targetOutOfScope`), its `login_as` turned off (`loginAsRevoked`) — the next request
  (a `GET /me` and, separately, a `PATCH /me/preferences`) answers `403 impersonation-ended`, the
  preference is unchanged, `GET /me` then shows the impersonator, the CSRF token was renewed, and
  audit `impersonationEnd` has `endedBy: automatic` and the reason; a deactivated target does not
  end it; a deactivated impersonator ends the whole session (`401`); `DELETE
  /session/impersonation` that detects an invalid impersonation answers `204`, ends it and audits
  `impersonationEnd` (`endedBy: automatic` with the reason), and `DELETE /session` that detects it
  answers `204`, audits the automatic end and `signOut`, and ends the session (FR-052 as amended);
  a flag change of an impersonating domain administrator applies to its next request (FR-002,
  FR-040).
- [ ] T055 [P] [US3] Write `tests/Feature/Impersonation/ImpersonationRefusalsTest.php` (AS US3-9,
  FR-049, R19): during an impersonation of a mailbox user, 001's
  `POST /me/passkeys/registration-options`, `POST /me/passkeys`, `PATCH` and
  `DELETE /me/passkeys/{passkeyId}`, and feature 005's own password change, TOTP, WebAuthn and Yubico
  OTP enrolment and removal, recovery e-mail change and own webmail hand-off all answer `403
  not-allowed-while-impersonating` and change nothing; feature 005's app password creation follows
  the effective `appPasswds` flag (allowed for an administrator impersonator, refused for a domain
  administrator impersonator whose `app_passwds` is off).
- [ ] T056 [P] [US3] Write `tests/Feature/Impersonation/ImpersonationAuthorizationTest.php` (AS US3-5,
  US3-7, FR-042, FR-043, R24): any API key on `startImpersonation` — administrator read-write →
  `403 session-required`, read-only → `403 insufficient-key-scope`, domain administrator key →
  `403 session-required`; a mailbox user → `403 forbidden`; a domain administrator without
  `loginAs` → `403 permission-flag-required` with `requiredFlags: [loginAs]` and the same answer for a target
  inside and outside its scope, also right after an administrator turned the flag off through
  `PATCH /domain-admins/{username}` (AS US2-3); a domain administrator targeting a domain
  administrator → `403 forbidden`; a restricted session → `403 pending-action-required`; a pending
  sign-in → `401 second-factor-required`; no session → `401`; zero impersonations can be started
  with any key (SC-002); `GET /session/impersonation` and
  `DELETE /session/impersonation` with a key → `403 session-required`.
- [ ] T057 [P] [US3] Write `tests/Feature/Impersonation/ImpersonationAuditTest.php` (AS US3-2,
  FR-067, SC-005): a change made during the impersonation through another feature (001
  `PATCH /me/preferences` and 003 `PATCH /mailboxes/{mailbox}` by an administrator impersonating a
  domain administrator) is audited with `actor` `alice`/`admin` and `impersonatedAccount`
  `bob@a.example`/`mailboxUser` (or the domain administrator); every audit record written during
  the impersonation carries both.

### Implementation for User Story 3

- [ ] T058 [US3] Create `app/Services/Auth/ImpersonationService.php` (R16, R17): `start(Principal
  $impersonator, string $role, string $username)` (eligibility and scope with 003's address
  normaliser and the `mailbox`/`admin` lookups of data-model.md, writes `restv2.impersonation` with
  the target's `created` and generation, renews the identifier and the CSRF token through
  `SessionManager`, which keeps 005's `SessionRegistry` in step, audits), `end(
  string $endedBy, ?ImpersonationEndReason $reason)` (removes the key, renews, audits),
  `validate(Principal $principal): ?ImpersonationEndReason`, and `effectivePrincipal(array
  $sessionData): Principal`. Create `app/Policies/ImpersonationPolicy.php`.
- [ ] T059 [US3] Change 001's `app/Services/Auth/SessionManager.php`: `principal()` returns
  `ImpersonationService::effectivePrincipal()` while `restv2.impersonation` exists; `promote()` and
  `end()` audit `impersonationEnd` with `endedBy: sessionReplaced` or `signOut` when an
  impersonation is replaced or ended. Change 001's
  `app/Http/Controllers/Api/V2/SessionController.php` (`destroy`) so that the impersonation end is
  audited before `signOut`.
- [ ] T060 [US3] Change 001's `app/Services/Identity/IdentityService.php` (R18 flags for an
  impersonating principal; pending actions of `actingAccount()`; `webmailOnBehalfEnabled` for
  administrators and domain administrators from `config('restv2.allow_admin_email_login')`) and
  `app/Http/Resources/Api/V2/IdentityResource.php` and `SessionResource.php` (`impersonation`,
  `webmailOnBehalfEnabled`). Change 001's `app/Services/Preferences/PreferencesService.php` and
  `PreferencesController.php` to use `actingAccount()` (FR-047).
- [ ] T061 [US3] Create `app/Http/Middleware/EnforceImpersonation.php` (R17: validation on every
  session request, automatic end with renewal and audit, then `403 impersonation-ended` for every
  detecting request except `DELETE /session/impersonation` and `DELETE /session`, which continue to
  their controllers and complete normally) and append it to the `api.v2` group right
  after `EnsureSessionIntegrity` in `bootstrap/app.php`. Create
  `app/Http/Middleware/RefuseDuringImpersonation.php` with the alias `restv2.not-impersonating`,
  running after `restv2.auth` and before `restv2.pending`.
- [ ] T062 [US3] Create `app/Http/Requests/Api/V2/StartImpersonationRequest.php` ("`role` in
  mailboxUser, domainAdmin (`notInList`); `username` required string 1–255; unknown member →
  `unknownField`"), `app/Http/Resources/Api/V2/ImpersonationResource.php` and
  `app/Http/Controllers/Api/V2/ImpersonationController.php` (`show`, `store`, `destroy`). Register
  `GET|POST|DELETE /session/impersonation` with `restv2.auth:session`, `restv2.csrf` on writes,
  `restv2.pending:allow` on `GET` and `DELETE`, in `routes/api.php`.
- [ ] T063 [US3] In `routes/api.php`, attach `restv2.not-impersonating` to 001's passkey management
  routes (`POST /me/passkeys/registration-options`, `POST /me/passkeys`,
  `PATCH|DELETE /me/passkeys/{passkeyId}`) and to feature 005's routes for the own password change,
  second-factor enrolment and removal, recovery e-mail change and own webmail hand-off (R19).
- [ ] T064 [US3] Change feature 003's `app/Services/Mailboxes/MailboxDeleter.php` and
  `MailboxRenamer.php` to call `AccountGeneration::bump('mailbox', $address)` (the old address for a
  rename) after commit (R11). This makes
  T051–T057 pass; 003's tests stay green.

**Checkpoint**: Impersonation works from the mailbox list and the domain administrator list, and
ends safely.

---

## Phase 6: User Story 4 — Manage administrator accounts (P2)

**Goal**: Administrators manage administrator accounts in a signed-in session; the server never
loses its last active administrator, and nobody deletes their own account.

**Independent Test**: AS US4-1 to US4-10, including concurrent requests and API keys.

### Tests for User Story 4 (write first, observe failing) ⚠️

- [ ] T065 [P] [US4] Write `tests/Feature/Admins/ListAdminsTest.php` and
  `tests/Feature/Admins/GetAdminTest.php` (FR-006, FR-008 to FR-011, FR-013): paging, `meta.total`,
  entries equal to `getAdmin`'s `data` with `etag`; `q` on usernames; filters `active`,
  `secondFactorStatus`, `forcePasswordChange`, `forceSecondFactorSetup`; sorts with username
  tie-breaker; domain administrators never listed; any letter case; a domain administrator's
  username → `404`; read-only administrator key allowed (AS US4-8).
- [ ] T066 [P] [US4] Write `tests/Feature/Admins/CreateAdminTest.php` (AS US4-1, US4-2, FR-014 to
  FR-017, FR-020, R6): `201` with `Location` and `ETag`; `admin` row with `superadmin = 1`, `active`
  default `1`, `attributes` `{"force_tfa":"0","force_pw_update":"0"}`; `bob` signs in through
  `POST /session`; policy violations name each unmet rule with 003's codes; pre-hashed values stored
  as given; `409 account-exists` across roles and letter case; orphaned `domain_admins`, `da_acl`
  and `da_sso` rows of the name are removed; audit `adminCreation` without the password.
- [ ] T067 [P] [US4] Write `tests/Feature/Admins/UpdateAdminTest.php` (AS US4-6, US4-7, FR-021,
  FR-022, FR-024, FR-026, FR-065): merge-patch rules as in T028; changing an inactive `bob`'s
  password while `alice` is the only active administrator succeeds; `forcePasswordChange: true`
  makes `bob`'s next `POST /session` answer `state: restricted` with `pendingActions:
  [passwordChange]`; stale `If-Match` → `412`; no-op → `200` without audit.
- [ ] T068 [P] [US4] Write `tests/Feature/Admins/RenameAdminTest.php` (FR-025, R9): second factors
  (active and inactive) and passkeys, keys and preferences move; `da_sso` and other leftovers of the
  old name are removed; `alice` renaming herself keeps her session under the new name (`GET /me`)
  while her second session gets `401`; collision with a domain administrator → `409
  account-exists`; audit `adminUpdate` with `changes.username`.
- [ ] T069 [P] [US4] Write `tests/Feature/Admins/DeleteAdminTest.php` (AS US4-5, FR-029, FR-030,
  FR-033, FR-034): `alice` deleting `alice` → `409 cannot-delete-own-account`; deleting `bob` →
  `204`, `assertNothingLeftFor('bob')` (also `domain_admins` and `da_acl` rows with his name),
  keys revoked `ownerDeleted`, sessions end, audit with counts; `404` for unknown and for a domain
  administrator's username.
- [ ] T070 [P] [US4] Write `tests/Feature/Admins/AdminSecondFactorSuspensionTest.php` (FR-027): as
  T032 for an administrator, in a session only.
- [ ] T071 [P] [US4] Write `tests/Feature/Admins/LastActiveAdminTest.php` (AS US4-3, FR-028, R8,
  edge cases): only `alice` active → `alice` deactivating herself → `409 last-active-admin`, no
  change; a service-level `AdminAccountService::delete()` of the only active administrator on
  behalf of another principal → `last-active-admin` (over HTTP this happens only concurrently,
  because the caller is always an active administrator and cannot delete itself; T072 covers it);
  deactivating an already inactive administrator and saving it unchanged are never refused;
  self-deactivation with another active administrator → `200`, then her session gets `401`; a
  rename combined with `active: false` of the last active administrator → `409`, name unchanged.
- [ ] T072 [P] [US4] Write `tests/Feature/Admins/LastActiveAdminConcurrencyTest.php` (AS US4-4,
  SC-003): 1,000 rounds; in each, two active administrators exist and two parallel PHP processes
  (`Symfony\Component\Process\Process` running `tests/bin/admin-race.php`, which calls
  `AdminAccountService` with its own database connection outside the test transaction) deactivate
  or delete each other; after every round at least one administrator is active and at most one
  request succeeded. The test cleans its rows itself (no `DatabaseTransactions`).
- [ ] T073 [P] [US4] Write `tests/Feature/Admins/AdminBulkActionTest.php` (AS US4-9, US4-10,
  FR-075, FR-076): three active administrators including `alice`; bulk `deactivate` of all three →
  first two `done`, the last `refused` with `last-active-admin`, one stays active; bulk `delete`
  including `alice` → her item `refused` (`cannot-delete-own-account`), the others processed; the
  same request with any key → whole request `403` (`session-required` or
  `insufficient-key-scope`); `outdated` and `notFound` items as in T033.
- [ ] T074 [P] [US4] Write `tests/Feature/Admins/AdminAuthorizationTest.php` (AS US4-8, FR-001,
  FR-004, FR-005): for each of the eight administrator operations: administrator session allowed;
  administrator read-write key allowed on `listAdmins`, `getAdmin`, `getAccountCapabilities` and
  `403 session-required` on every write; read-only administrator key → reads allowed, writes `403
  insufficient-key-scope`; domain administrator and mailbox user sessions and keys → `403
  forbidden`; no credentials → `401`; restricted session → `403 pending-action-required`.

### Implementation for User Story 4

- [ ] T075 [US4] Create `app/Services/Accounts/LastAdminGuard.php` (R8: `DB::transaction(..., 3)`,
  `SELECT username, active FROM admin WHERE superadmin = '1' ORDER BY username FOR UPDATE`, the
  remaining-active computation and `last-active-admin`).
- [ ] T076 [US4] Create `app/Services/Accounts/AdminAccountService.php`: `create()`, `update()`,
  `delete()` (own account → `cannot-delete-own-account` first), `suspendSecondFactors()`, with every
  deactivation, combined rename and deactivation, and deletion running inside
  `LastAdminGuard::within()`; renames through `AccountRenamer`, deletions through `AccountDeleter`.
  Extend `AccountBulkActionRunner` to dispatch administrator targets to it.
- [ ] T077 [US4] Create `app/Http/Requests/Api/V2/ListAdminsRequest.php`, `StoreAdminRequest.php`
  and `UpdateAdminRequest.php` (rules of T044 without domains and permissions), and
  `app/Http/Controllers/Api/V2/AdminController.php`, `AdminSecondFactorSuspensionController.php`
  and `AdminBulkActionController.php`, authorised through `AdminPolicy`. Register
  `GET /admins` and `GET /admins/{username}` with `restv2.auth:authenticated`, and `POST /admins`,
  `PATCH|DELETE /admins/{username}`, `POST /admins/{username}/second-factor-suspension` and
  `POST /admins/bulk-actions` (before the `{username}` routes, which never accept `POST`) with
  `restv2.auth:session` and `restv2.csrf`, in `routes/api.php`.
  Create `tests/bin/admin-race.php` for T072. This makes T065–T074 pass.

**Checkpoint**: Administrator accounts can be managed without the legacy UI.

---

## Phase 7: User Story 5 — Single sign-on tokens (P2)

**Goal**: A reseller panel issues a token with an administrator's read-write key and signs the
domain administrator in, in the legacy UI or the new web app, once, within 30 seconds.

**Independent Test**: AS US5-1 to US5-7, tokens issued through this API and inserted as `/api/v1`
writes them, consumed here and by a simulated legacy consumer.

### Tests for User Story 5 (write first, observe failing) ⚠️

- [ ] T078 [P] [US5] Write `tests/Feature/SsoTokens/IssueSsoTokenTest.php` for
  `issueDomainAdminSsoToken` (AS US5-1, US5-5, FR-057 to FR-059, R20): with an administrator
  read-write key → `200`, `Cache-Control: no-store`, `token` matching
  `^[0-9A-F]{6}(-[0-9A-F]{6}){4}$`, `username`, `expiresAt` = the `da_sso.created` (UTC) + 30 s; the
  `da_sso` row exists; rows older than 30 s are deleted; an inactive account → `409
  account-inactive` and no row; an unknown username and an administrator's username → `404`; an
  account without assignments → `200`; audit `ssoTokenIssue` with `expiresAt` and no token anywhere
  (`assertNoSecretIn($token)`).
- [ ] T079 [P] [US5] Write `tests/Feature/SsoTokens/SsoTokenSignInTest.php` for
  `createSessionWithSsoToken` (AS US5-2, US5-4, US5-6, FR-060 to FR-063, FR-036): `201` with
  `SessionCreated`, `role: domainAdmin`, `authMethod: ssoToken`, without a second factor even with an
  active TOTP entry, and the `tfa` rows unchanged; the row is consumed; the same token again, a
  token older than 30 s, a token of an inactive, deleted or renamed account, and a token older than
  its account's `created` → `401 sign-in-failed`; a lower-case token with spaces works; a malformed
  token fails without a database lookup; `force_pw_update` → `state: restricted`; a token inserted
  as `/api/v1` writes it (`INSERT INTO da_sso (username, token)`) works; an existing session,
  including an impersonating one, is replaced (old cookie anonymous) and `endedBy: sessionReplaced`
  audited; after 10 failures from one address within the window → `429 too-many-requests` with
  `Retry-After`; no failure is published to fail2ban (`ChannelSpy::assertNothingPublished()`);
  every success and failure is audited as `signIn` with `authMethod: ssoToken`; the session cookie
  changes at sign-in (FR-061); creating a domain administrator, issuing a token with the key and
  signing in with it completes in well under a minute (SC-006, asserted below 5 s).
- [ ] T080 [P] [US5] Write `tests/Feature/SsoTokens/SsoTokenConcurrencyTest.php` (edge case "same
  token twice at the same moment"): 200 rounds of two parallel processes
  (`tests/bin/sso-token-race.php`), one consuming through `SsoTokenService` and one running legacy's
  `domain_admin_sso('check')` statements (`SELECT … AND created > DATE_SUB(NOW(), INTERVAL 30
  SECOND)` then `DELETE` with `rowCount`), each with its own connection; exactly one succeeds in
  every round.
- [ ] T081 [P] [US5] Write `tests/Feature/SsoTokens/SsoTokenAuthorizationTest.php` (AS US5-7,
  FR-005): issue with a domain administrator session or key → `403 forbidden`; with a read-only
  administrator key → `403 insufficient-key-scope`; with a mailbox user → `403 forbidden`; with an
  administrator session → `200`; token sign-in with an `X-API-Key` header → `403
  session-required`; token sign-in without CSRF token → `403 csrf-token-mismatch`; issue from a
  restricted administrator session → `403 pending-action-required`.

### Implementation for User Story 5

- [ ] T082 [P] [US5] Create `app/Models/Mailcow/DaSsoToken.php` (`$table = 'da_sso'`, no
  incrementing key, read-only).
- [ ] T083 [US5] Create `app/Services/Auth/SsoTokenService.php` (R20): `issue(username, Principal
  $actor)` and `consume(string $token): ?array` with the statements of data-model.md "da_sso", and
  `signIn()` through 001's `SessionManager` (replace, renew, principal with `AuthMethod::SsoToken`
  and `accountCreatedAt`, registration in 005's `SessionRegistry`) and 001's
  `PendingActionEvaluator`. Add the throttle key kind
  `ssotoken:<sha256(ip)>` with 001's limits to `app/Services/Auth/SignInThrottle.php`.
- [ ] T084 [US5] Create `app/Http/Requests/Api/V2/SsoTokenSignInRequest.php` ("`token`: required
  string 1–255"), `app/Http/Resources/Api/V2/DomainAdminSsoTokenResource.php`,
  `app/Http/Controllers/Api/V2/DomainAdminSsoTokenController.php` (`store`, `DomainAdminPolicy`,
  `Cache-Control: no-store`) and `SsoTokenSessionController.php` (`store`). Register
  `POST /domain-admins/{username}/sso-token` (`restv2.auth:authenticated`, `restv2.csrf`) and
  `POST /session/sso-token` (`restv2.auth:browser`, `restv2.csrf`, `restv2.pending:allow`) in
  `routes/api.php`, before `/domain-admins/{username}`. Create `tests/bin/sso-token-race.php`. This
  makes T078–T081 pass.

**Checkpoint**: Billing panels can migrate token issue to `/api/v2`.

---

## Phase 8: User Story 6 — Webmail on behalf of a user (P3)

**Goal**: With `ALLOW_ADMIN_EMAIL_LOGIN` on, administrators and domain administrators with
`loginAs` open a mailbox's webmail in their scope; never while impersonating.

**Independent Test**: AS US6-1 to US6-5 with the setting on and off.

### Tests for User Story 6 (write first, observe failing) ⚠️

- [ ] T085 [P] [US6] Write `tests/Feature/Webmail/WebmailSignInOnBehalfTest.php` for
  `createWebmailSignInOnBehalf` (AS US6-1 to US6-5, FR-054 to FR-056, R21): setting on, an
  administrator → `200` with `Set-Cookie` (feature 005's hand-off cookie) and `Cache-Control:
  no-store`, `url`, a `sasl_log` row (`service = 'SSO'`, the mailbox, the client address), the
  caller's `GET /me` unchanged, audit `webmailSignInOnBehalf`; setting off → `403
  webmail-on-behalf-disabled`; `reseller1` with `loginAs` → own domain `200`, other domain `404`;
  without `loginAs` → `403 permission-flag-required` (`requiredFlags: [loginAs]`); mailbox user → `403
  forbidden`; `SKIP_SOGO=y` → feature 005's `403 groupware-disabled`; any key → `403
  session-required` (read-only: `insufficient-key-scope`); resource → `404`; mailbox with `active =
  0`, `active = 2` or in an inactive domain → `422 notEligible` on `mailbox` with the `reason`; while impersonating a mailbox user or a domain administrator → `403
  not-allowed-while-impersonating`, no `sasl_log` row, no cookie; a restricted session → `403
  pending-action-required`; `GET /me` reports
  `webmailOnBehalfEnabled` for administrators and domain administrators following the setting.

### Implementation for User Story 6

- [ ] T086 [US6] Create `app/Services/Webmail/WebmailOnBehalfService.php` (R21: the order of checks,
  eligibility of the mailbox, then feature 005's `WebmailHandOffService::handOff()` for the target
  mailbox, which writes the `SSO` recent-login entry and sets the cookie; audit for success and
  refusals),
  `app/Http/Resources/Api/V2/MailboxWebmailSignInResource.php` and
  `app/Http/Controllers/Api/V2/MailboxWebmailSignInController.php` (`store`,
  `ImpersonationPolicy`). Register `POST /mailboxes/{mailbox}/webmail-sign-in` with
  `restv2.auth:session`, `restv2.not-impersonating`, `restv2.csrf` in `routes/api.php`. This makes
  T085 pass.

**Checkpoint**: The "SOGo" button of the legacy mailbox list has its replacement.

---

## Phase 9: User Story 7 — Trace account changes and impersonations (P3)

**Goal**: Administrators find every change to an account and everything done on a customer's
behalf in the audit log, without secrets.

**Independent Test**: AS US7-1 to US7-3 after running every operation of this feature.

### Tests for User Story 7 (write first, observe failing) ⚠️

- [ ] T087 [P] [US7] Write `tests/Feature/Audit/AccountAuditTest.php` (AS US7-1, FR-066, FR-068,
  FR-069): changing `reseller1`'s domains and password gives one `domainAdminUpdate` record with
  `domainsAdded`, `domainsRemoved` and `passwordChanged: true` and no password;
  `GET /audit-events?targetType=domainAdmin&targetId=reseller1` returns every record of that
  account; each operation of the feature, successful or refused after authentication (`403`, `404`
  where an account is addressed, `409`, `412`, `422`), writes exactly one record per affected
  account with the failure reason and `details.problemType`, including refusals of read-only keys,
  keys on session-only operations and restricted sessions (refusal auditing of
  `docs/conventions.md`); CSRF failures, `401`s and reads write none.
- [ ] T088 [P] [US7] Write `tests/Feature/Audit/AuditImpersonationFilterTest.php` (AS US7-2,
  FR-069): impersonations of `bob@a.example` by `alice` and by `reseller1`, with a change each;
  `GET /audit-events?impersonatedAccount=bob@a.example` returns both starts, both ends and both
  changes with their impersonators; `impersonatedAccount` is `null` in records outside
  impersonations.
- [ ] T089 [P] [US7] Write `tests/Feature/Audit/AccountSecretScanTest.php` (AS US7-3, FR-074,
  SC-009): run every operation of the feature with known passwords (plain and pre-hashed) and
  tokens, then assert that no audit record, no response after the token's issue response and no
  captured log line contains a password, a password hash or a token.

### Implementation for User Story 7

- [ ] T090 [US7] Change 001's `app/Http/Requests/Api/V2/ListAuditEventsRequest.php` (`impersonatedAccount`:
  optional string, max 255, lower-cased) and `app/Http/Controllers/Api/V2/AuditEventController.php`
  (`impersonated_username = ?`). Fix any event of the feature that T087 or T089 finds missing or
  leaking. This makes T087–T089 pass.

**Checkpoint**: All seven user stories work independently.

---

## Phase 10: Polish and cross-cutting concerns

- [ ] T091 [P] Write `tests/Feature/Accounts/LegacyParityRowsTest.php` (SC-001): for each create,
  change, rename, flag change and delete case of the parity set, the rows of `admin`,
  `domain_admins`, `da_acl`, `tfa`, `fido2` and `da_sso` equal the rows legacy `admin()`,
  `domain_admin()` and `acl('edit')` write for the same input (expected rows written out in the test
  with the legacy line references), apart from timestamps, hashes and the deviations of the spec's
  parity table.
- [ ] T092 [P] Write `tests/Feature/DomainAdmins/DomainAdminListPerformanceTest.php` (SC-008):
  seeds 5,000 domains and 5,000 domain administrators with one to three assignments, flags and some
  `tfa`/`fido2` rows; 50 requests of `GET /domain-admins?limit=25` with varying offsets, sorts
  (`domainCount` included) and `q`; p95 under 1 s on the CI runner. Group `performance`.
- [ ] T093 [P] Write `tests/Feature/Security/Feature007AuthorizationMatrixTest.php`: one data
  provider over all 21 operations × {administrator session, administrator read-write key,
  administrator read-only key, domain administrator session, domain administrator key, mailbox user
  session, restricted session, impersonating session, no credentials}, asserting the status and
  problem type of the FR-005 matrix and research R24 (`forbidden` for roles and key types,
  `permission-flag-required` with `requiredFlags: [loginAs]` for a missing flag), and that objects outside a domain
  administrator's scope get the same answer as missing ones (SC-002; constitution quality gate 5).
- [ ] T094 [P] Write `tests/Feature/Accounts/AccountReuseTest.php` (SC-004): after deleting and after
  renaming an administrator and a domain administrator through the API, a new account with the old
  username inherits no session, key, passkey, second factor, preference, permission, impersonation
  or token, in every combination.
- [ ] T095 Run `composer test` and confirm that `build/contract/coverage.json` lists all 21
  operations of this feature and that the 001 operations changed by it still pass.
- [ ] T096 [P] Run `vendor/bin/pint --test` and `vendor/bin/phpstan analyse` (Larastan level 6) and
  fix every finding in the files of this feature.
- [ ] T097 [P] Add the feature's contract changes to `CHANGELOG.md` (21 operations; additive
  `Identity`, `Session`, `AuthMethod`, `AuditEvent`, `ApiKey`, `Forbidden` and `listAuditEvents`
  changes; eight problem types; `ALLOW_ADMIN_EMAIL_LOGIN`) and the setting to the README's
  configuration table, so that mailcowui-ng can bump its pinned contract (constitution workflow).
- [ ] T098 Walk through [quickstart.md](./quickstart.md) Parts A and B on the dev stack; Part C runs
  on a mailcow host before the release.

---

## Dependencies and execution order

### Phase dependencies

- **Setup (Phase 1)**: no dependency inside this feature; needs features 001, 002, 003 and 005
  implemented.
- **Foundational (Phase 2)**: depends on Setup. Tests T006–T012 first, then T013–T024. T015 comes
  before T017, T018 and T020; T016 before T017. **It blocks every user story.**
- **US1 (Phase 3)**: depends on Foundational. T036–T039 in parallel, then T040 and T041, then T042,
  T043, T044, T045; T046 needs T041.
- **US2 (Phase 4)**: depends on US1 (the domain administrator service and routes).
- **US3 (Phase 5)**: depends on Foundational. T054's deletion and rename causes and T056's flag
  change use US1's operations; T055 uses feature 005's routes. Run after US1 or mark those cases
  skipped until US1 is done.
- **US4 (Phase 6)**: depends on Foundational and reuses US1's `AccountRenamer`, `AccountDeleter`,
  `SecondFactorSuspender` and `AccountBulkActionRunner`.
- **US5 (Phase 7)**: depends on Foundational; T079's impersonating-session case needs US3.
- **US6 (Phase 8)**: depends on US3 (`restv2.not-impersonating`, `ImpersonationPolicy`).
- **US7 (Phase 9)**: depends on every other story (it audits their operations).
- **Polish (Phase 10)**: depends on every story.

### Within each story

Tests first, written and failing; then rules and services; then requests, resources and
controllers; then routes. Stop at each checkpoint and run the story's tests and 001's suite.

### Parallel opportunities

- Setup: T001–T004 in parallel.
- Foundational tests: T006–T012 in parallel. Implementation: T013, T014, T016, T019, T021, T022 in
  parallel.
- US1 tests T025–T035 in parallel; US1 implementation T036–T039 in parallel.
- After US1: US2, US3 and US4 can proceed in parallel with separate developers; US5 after
  Foundational.

## Parallel example: User Story 1

```bash
# All US1 test tasks together (different files):
Task: "T025 CreateDomainAdminTest", "T026 ListDomainAdminsTest", "T027 GetDomainAdminTest",
      "T028 UpdateDomainAdminTest", "T029 DomainAdminDomainsTest", "T030 RenameDomainAdminTest",
      "T031 DeleteDomainAdminTest", "T032 DomainAdminSecondFactorSuspensionTest",
      "T033 DomainAdminBulkActionTest", "T034 DomainAdminAuthorizationTest",
      "T035 DomainDeletionCascadeTest"

# Then the independent building blocks together:
Task: "T036 AssignableDomain", "T037 AccountQuery", "T038 SecondFactorSuspender",
      "T039 DomainAdminPermissionService"
```

## Implementation strategy

### MVP first (User Story 1)

1. Phase 1 and Phase 2.
2. Phase 3 (US1): domain administrators can be provisioned, changed and removed by administrators
   and billing systems.
3. **Stop and validate**: quickstart Part B's US1 commands; compare rows with the legacy UI.

### Incremental delivery

1. US2 (flags and their defaults), then US3 (login-as, the daily support tool).
2. US4 (administrator accounts), then US5 (tokens for billing panels).
3. US6 (webmail on behalf), then US7 (audit trace).
4. Polish: parity rows, performance, authorisation matrix, coverage, changelog.

Each story ends with its checkpoint green and the contract coverage growing to 21 of 21.

## Notes

- Never use `RefreshDatabase`, `migrate:fresh`, `migrate:refresh` or `db:wipe` (001). The
  concurrency tests (T072, T080) clean their own rows.
- Every upstream write goes through `app/Services/Accounts/`, `SsoTokenService` or feature 005's
  hand-off service. Controllers never write.
- Commit after each task or logical group, with short imperative subjects and no AI attribution.
