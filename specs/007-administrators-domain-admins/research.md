# Research: Administrators and Domain Administrators

**Feature**: `specs/007-administrators-domain-admins` | **Date**: 2026-10-18 | **Plan**: [plan.md](./plan.md)

This document settles only the decisions that feature 007 adds. Every shared technical decision of
feature 001 applies unchanged and is not repeated: the Laravel 13 / PHP 8.4 stack, the Docker dev
stack, the schema fixture generated from upstream `init_db_schema()` (001 R1–R3), the single
`mysql` connection with the `restv2_` table prefix and the `restv2:` Redis prefix (001 R5, R6), the
CSRF scheme and request pipeline (001 R7, R8), contract validation in tests (001 R13), mailcow
configuration (001 R14), problem URIs (001 R27) and the audit log (001 R22).

The order of precedence is the one the owner set: legacy behaviour (constitution IV) first, then
the constitution and `docs/conventions.md`, then best practice. Each entry has a **Decision**, its
**Rationale** and the **Alternatives considered**. Legacy references are paths in upstream
mailcow-dockerized `2026-09a` (`git -C ~/Projects/mailhosting show 2026-09a:<path>`). The FeldHost
fork is identical for this feature (spec, "Legacy Parity").

---

## 1. Resources, paths and representation

### R1. Resource layout and paths

- **Decision**: Four contract modules, 21 operations (list in [plan.md](./plan.md#contract-operations)):
  - `admins`: `/admins`, `/admins/{username}`, `/admins/{username}/second-factor-suspension`,
    `/admin-bulk-actions` and `/capabilities/accounts`;
  - `domain-admins`: `/domain-admins`, `/domain-admins/{username}`,
    `/domain-admins/{username}/second-factor-suspension` and `/domain-admin-bulk-actions`;
  - `sso-tokens`: `/domain-admins/{username}/sso-token` (issue) and `/session/sso-token` (sign in);
  - `impersonation`: `/session/impersonation` (`GET`, `POST`, `DELETE`) and
    `/mailboxes/{mailbox}/webmail-sign-in`.

  Accounts are addressed by username in any letter case (FR-013). The permission flags are part of
  the domain administrator representation and are changed with the account's `PATCH`, which is a
  JSON Merge Patch, so a nested `permissions` object changes exactly the flags it names (FR-038).
- **Rationale**:
  - A username may be any string of `a-z`, `0-9`, `.`, `_` and `-` (FR-014), so `bulk-actions` is a
    valid username. Under `/admins/` it would collide with `/admins/{username}`, and OpenAPI
    matches concrete paths first. A top-level hyphenated noun cannot collide and needs no reserved
    name beyond legacy's `api`. The capabilities read follows `docs/conventions.md`
    ("Capabilities reads use the top-level path `/capabilities/{area}`"): `/capabilities/accounts`,
    operation `getAccountCapabilities`.
  - Second-factor suspension, token issue and webmail sign-in are non-CRUD actions, so they are
    sub-resources answering `200` (root document conventions; 001 uses the same pattern for
    `/api-keys/{id}/revocation` and `/session/passkey-challenge`). A token cannot be read back, so
    a `201` with a `Location` would point nowhere.
  - The token sign-in and the impersonation change the caller's session, so they are
    sub-resources of `/session`, like 001's `/session/passkey-assertion`.
  - The webmail sign-in acts on a mailbox. Feature 003 addresses mailboxes by their full address
    (003 FR-007), so `/mailboxes/{mailbox}/webmail-sign-in` sits under that resource.
  - One `PATCH` for every account value keeps the version tag single (FR-064) and avoids two ways
    of changing the same flag.
- **Alternatives considered**:
  - `/admins/bulk-actions` was rejected because of the username collision. Reserving the name
    would deviate from FR-014 and could hide a legacy account.
  - A separate `/domain-admins/{username}/permissions` resource (legacy `edit/da-acl`) was
    rejected: it duplicates the account `PATCH`, and its version tag would have to equal the
    account's anyway (FR-064).
  - `POST /domain-admins/{username}/sso-tokens` with `201` was rejected because nothing could be
    fetched at the `Location`.
  - An impersonation collection (`/impersonations/{id}`) was rejected: at most one impersonation
    exists per session (FR-043), so a singleton sub-resource is the natural form.

### R2. Account representation

- **Decision**: `Admin` and `DomainAdmin` (contract `api/components/schemas/`) map to storage as
  follows. The full mapping is in [data-model.md](./data-model.md).
  - `username`: `admin.username` as stored. Legacy renames do not lower-case, so a stored name may
    contain upper-case letters. Lookups lower-case the input, and the table's case-insensitive
    collation (`utf8mb4_general_ci`) matches any stored case.
  - `active`: `admin.active = 1`. For a domain administrator this is the account's own status, not
    the first assignment's as legacy shows it (FR-007).
  - `forcePasswordChange` and `forceSecondFactorSetup`: `JSON_VALUE(attributes, '$.force_pw_update')`
    and `'$.force_tfa'` equal to `"1"` (string or number). A missing attribute reads as `false`,
    as legacy's `?? array('force_tfa' => '0', …)` does.
  - `secondFactorStatus` (FR-008): computed over `tfa` rows with `authmech IN ('totp', 'webauthn',
    'yubi_otp')`: `active` when at least one has `active = 1`, `suspended` when rows exist but none
    is active, `none` when there is no row. `u2f` and `hotp` rows never count.
  - `activePasskeyCount`: `COUNT(*) FROM fido2 WHERE username = ? AND active = 1`.
  - `createdAt`: `admin.created`. `modifiedAt`: `admin.modified`, `null` when never modified.
    Both go through 001's `MailcowDateTime` cast (mailcow local time to UTC). `modifiedAt` follows
    the `admin` row only: assignment and flag changes do not touch it, as in legacy, whose
    `UPDATE admin` is a no-op when the values are unchanged.
  - `domains` (domain administrators): every `domain_admins` row of the username, ordered by
    domain, with `domain` (stored ASCII name), `domainUnicode` (`idn_to_utf8` UTS #46),
    `active` (the row's own status), `createdAt` (the row's `created`) and `domainExists`
    (`false` when the name is not a row of `domain`, for example an alias domain name, a removed
    domain or legacy's `ALL`).
  - `permissions` (domain administrators): the 19 `da_acl` columns as camelCase booleans, every
    flag `false` when the row is missing (FR-041).
  - `etag`: R3.
- **Rationale**: FR-006 to FR-009. The second-factor status is evaluated over the types 001 accepts
  (001 FR-025), so a suspended or deprecated-only account is shown truthfully, unlike legacy's
  `tfa_active` of the first joined row.
- **Alternatives considered**: Returning legacy's `tfa_active` integer was rejected (spec
  deviation). Deriving `domainUnicode` in SQL was rejected because MariaDB has no IDNA function.

### R3. Version tag (FR-064)

- **Decision**: A strong ETag, built with 001's `App\Support\Http\ETag`, over the canonical JSON of
  the stored settings:
  - administrators: `username`, `created`, `active`, `force_pw_update`, `force_tfa`;
  - domain administrators: the same, plus the assignments ordered by domain as
    `[domain, active, created]` triples, plus the 19 `da_acl` values in column order, or `null`
    when the row is missing.

  Values are taken as normalised booleans and raw `DATETIME` strings. The tag never covers
  `modified`, `tfa`, `fido2` or anything derived from them (`secondFactorStatus`,
  `activePasskeyCount`), per `docs/conventions.md`. It never covers the password hash or any other
  secret, so the conventions' rule that tags over stored secrets use an HMAC keyed with the app key
  does not come into play; a password change therefore does not change the tag. The same value is the `ETag` header of every
  single-account response and `etag` in every representation, including list entries and the
  `version` of bulk results.
- **Rationale**: `domain_admins` and `da_acl` have no modification column, so a timestamp cannot
  detect their changes. A content hash detects changes made through this API, the legacy UI and
  `/api/v1` alike, and it ignores no-op saves. `created` is included so that a tag read from a
  deleted account never matches a new account with the same name and values (FR-036 in spirit).
  A legacy edit that deletes and re-inserts the assignments changes their `created` and therefore
  the tag, which is correct: the stored rows changed.
- **Alternatives considered**: Hashing `admin.modified` was rejected (blind to assignments and
  flags, and it changes on no-op values like `attributes` key order). A weak ETag was rejected:
  `If-Match` needs strong comparison (RFC 9110).

### R4. Usernames (FR-014, FR-015)

- **Decision**: `App\Rules\AccountUsername` trims and lower-cases the value, then reports:
  - empty → `required`;
  - longer than 255 characters → `tooLong` `{max: 255}`;
  - characters outside `a-z0-9._-`, or no letter or digit → `invalidFormat`
    `{format: accountUsername}`;
  - `api` → `reserved` `{reserved: ["api"]}`.

  Uniqueness is checked against `admin` (both roles share the table) inside the creating or
  renaming transaction with `SELECT … FOR UPDATE`. The `admin` primary key with its
  case-insensitive collation is the final arbiter: a duplicate-key error (SQLSTATE 23000, 1062)
  inside the transaction becomes `409 account-exists`. Because upstream enforces this uniqueness
  with its own primary key, the conventions' `GET_LOCK()` rule for uniqueness that upstream
  enforces only in code is not needed here. Mailbox addresses are not checked, because
  a username cannot contain `@`. A rename to the same name in another letter case normalises to
  the current name and is no change.
- **Rationale**: Legacy checks `ctype_alnum(str_replace(['_', '.', '-'], '', $u))`, which rejects
  names made only of punctuation. The `API` check never matches after legacy's lower-casing; the
  spec reserves `api` in any case. The primary key closes the race between two concurrent
  creations that legacy leaves open.
- **Alternatives considered**: Checking `domain_admins` for uniqueness as legacy does was rejected:
  an orphaned assignment row without an account is dropped by legacy at start-up and must not
  block a new account. Instead, a creation deletes orphaned `domain_admins` and `da_acl` rows of the
  same username in its transaction (R6), so the new account inherits nothing.

### R5. Passwords (FR-016, FR-026)

- **Decision**: Reuse feature 003's password services (003 FR-024, FR-063): the policy reader of
  `PASSWD_POLICY` with legacy defaults, the checker that returns one violation per unmet rule with
  003's rule codes and parameters, and the hasher that applies `MAILCOW_PASS_SCHEME` and stores a
  value with a recognised `{SCHEME}` prefix as given (legacy `hash_password()`). The audit records
  only `passwordChanged: true` and `passwordPreHashed`. A password change does not touch the forced
  flags, sessions or keys (FR-026), as in legacy.
- **Rationale**: One implementation of the password rules for every role (constitution IV), and
  the hash is verified by 001's `LegacyPasswordVerifier` and by legacy.
- **Alternatives considered**: A second hasher in feature 007 was rejected (duplication).

## 2. Writes

### R6. Creating accounts (FR-017 to FR-020)

- **Decision**:
  - **Administrator**: one transaction inserts `admin (username, password, superadmin = 1, active,
    attributes)` with `attributes = {"force_tfa": "0|1", "force_pw_update": "0|1"}`; `created` is
    left to its default. It also deletes orphaned `domain_admins`, `da_acl` and `da_sso` rows of the
    username, so the new account inherits nothing (FR-036).
  - **Domain administrator**: one transaction:
    1. validates and normalises every domain (feature 002's normaliser, 002 FR-006), merges
       duplicates and requires each to be a row of `domain` (`notFound` for unknown names,
       `notEligible` `{kind: aliasDomain}` for alias domain names, `invalidFormat`
       `{format: domainName}` for syntax errors), all before any write;
    2. inserts the `admin` row with `superadmin = 0`;
    3. deletes orphaned `domain_admins` rows of the username and inserts one row per domain with
       the account's `active` and the default `created`;
    4. deletes any orphaned `da_acl` row and inserts `da_acl (username, <sent flags>)`, so that every
       unsent flag takes the column default of the server's schema (FR-018, FR-037).
  - The response is `201` with `Location: /api/v2/admins/{username}` or
    `/api/v2/domain-admins/{username}`, the `ETag` and the representation.
- **Rationale**: Legacy `admin('add')` and `domain_admin('add')`, made atomic. Legacy inserts
  `attributes = NULL`, and its start-up migration rewrites it to the explicit `"0"` values; writing
  the normalised form directly is equivalent for both UIs. Inserting only the sent flags lets the
  database apply its own defaults, which is exactly what FR-077 reports.
- **Alternatives considered**: Writing all 19 flags with values hard-coded from `2026-09a` was
  rejected: a later schema with other defaults would diverge from legacy.

### R7. Changing accounts (FR-021 to FR-024)

- **Decision**:
  - The body is JSON Merge Patch (`application/merge-patch+json`). An empty body is `required` on
    `body` (001 convention). Unknown members are refused with `unknownField`. A read-only member
    (`createdAt`, `modifiedAt`, `secondFactorStatus`, `activePasskeyCount`, `etag`, and in an
    assignment object anything but `domain`) is accepted when equal to the current value and
    otherwise refused with `immutable`. A `null` for a required value is `invalidType`.
  - Sent values are applied in one transaction with `SELECT … FOR UPDATE` on the account row, after
    the `If-Match` comparison against the tag computed inside the transaction (`412` when it
    differs).
  - Forced flags: `UPDATE admin SET attributes = JSON_SET(COALESCE(attributes, '{}'),
    '$.force_tfa', ?, '$.force_pw_update', ?)` with the current value for an unsent flag, so other
    attributes are preserved (FR-024).
  - Domains (domain administrators): the sent list replaces the set and must contain at least one
    domain (`required`). Rows whose domain stays are kept untouched (creation time and status);
    rows whose domain leaves are deleted; new rows get the account's status. Rows that are not
    existing primary domains are never kept, because the new set only accepts primary domains.
  - Status: `admin.active` and, for a domain administrator, every `domain_admins` row of the
    username are set to the new value (FR-023). Last-administrator protection applies (R8).
  - Permission flags: R12.
  - Rename: R9.
- **Rationale**: FR-021 forbids silently ignoring a value. Legacy's edit resets omitted forced
  flags and re-creates every assignment; the spec deviates deliberately.
- **Alternatives considered**: `PUT` with the whole representation was rejected: clients would
  have to send passwords and flags they do not change.

### R8. Atomic last-administrator protection (FR-028, FR-029)

- **Decision**: `App\Services\Accounts\LastAdminGuard::within(callable $change)` runs in a
  transaction (`DB::transaction($cb, attempts: 3)`, which retries on deadlock):
  1. `SELECT username, active FROM admin WHERE superadmin = '1' ORDER BY username FOR UPDATE`
     locks every administrator row in primary-key order.
  2. It computes the active administrators after the intended change (deactivation, deletion,
     or a combined rename and deactivation of the target).
  3. If none would remain, it rolls back and answers `409 last-active-admin`. Otherwise the change
     runs in the same transaction.

  Changes that do not reduce the number of active administrators never reach the refusal, so an
  inactive administrator's password can always be changed (AS US4-6). Deleting the caller's own
  account is refused before the guard with `409 cannot-delete-own-account`; self-deactivation is
  allowed when another active administrator remains. In a bulk action each item runs its own
  guarded transaction in request order, so the item that would remove the last active
  administrator is the one refused (AS US4-9).
- **Rationale**: Locking all administrator rows in a fixed order serialises any two concurrent
  deactivations or deletions: the second one waits, then re-reads the committed state and is
  refused (SC-003). The table holds a handful of administrator rows, so the lock is cheap. Legacy
  writes to the same rows wait for the lock too, although legacy itself never checks atomically.
- **Alternatives considered**: `GET_LOCK()` named locks were rejected: they do not serialise
  against legacy writes, and they are connection-scoped, which is fragile with php-fpm.
  `SERIALIZABLE` isolation was rejected: it affects every query of the request and adds
  deadlocks. A conditional `UPDATE … WHERE (SELECT COUNT(*) …) > 1` was rejected: MariaDB cannot
  select from the table being updated in a subquery.

### R9. Rename (FR-025)

- **Decision**: `App\Services\Accounts\AccountRenamer::rename(AccountKind::Admin, $old, $new)` runs in
  one transaction, after the username rules (R4) and an exclusive check of the new name:
  1. `UPDATE admin SET username = :new WHERE username = :old` (forced flags live in `attributes`
     and follow the row);
  2. `UPDATE domain_admins`, `da_acl`, `tfa` and `fido2 SET username = :new WHERE username = :old`
     (assignments keep their `id` and `created`; every second factor and passkey follows, active or
     not);
  3. `UPDATE restv2_api_keys SET owner_username = :new WHERE owner_username = :old` and
     `UPDATE restv2_preferences SET username = :new WHERE account_kind = 'admin' AND username = :old`;
  4. `DELETE FROM da_sso WHERE username = :old` (unused tokens);
  5. after commit, feature 005's `SessionRegistry::endAll('admin', old, exceptHandle)` ends the
     account's other sessions at once (005 R2), and `AccountGeneration::bump(admin, old)` (R11)
     ends every impersonation of it at its next request. When the caller renamed its own account,
     `SessionRegistry::rename()` moves its handle and `SessionManager::rebindPrincipal(new)` rewrites
     its principal, so it continues under the new name (`exceptHandle` is its handle).

  Audit records keep the username of their time (they are never updated). Any error rolls back the
  whole rename.
- **Rationale**: The spec's "what legacy deletes with an account moves with it" (003's rule). Legacy
  re-creates assignments, renames `tfa` only without `disable_tfa`, never renames `fido2`, and fails
  half-way on a cross-role collision; all of this is fixed by one transaction.
- **Alternatives considered**: Keeping old sessions alive under the new name was rejected:
  FR-025 ends them, so that a rename cannot silently extend a stolen session.

### R10. Deletion and its effect on keys, sessions and preferences (FR-030 to FR-036)

- **Decision**: `App\Services\Accounts\AccountDeleter::delete(AccountKind::Admin, $username, $actor)` is
  the single cascade for both roles and for feature 002's orphan cleanup. In one transaction:
  1. `DELETE FROM domain_admins`, `admin`, `da_acl`, `tfa`, `fido2` and `da_sso WHERE username = ?`
     (numbers of second factors and passkeys counted first, for the audit);
  2. revoke every active key of the owner: `UPDATE restv2_api_keys SET revoked_at = now,
     revoked_by = :actor, revocation_reason = 'ownerDeleted' WHERE owner_username = ? AND
     revoked_at IS NULL` (ids collected for the audit);
  3. `DELETE FROM restv2_preferences WHERE account_kind = 'admin' AND username = ?`;
  4. the audit record (one per account).

  After commit, feature 005's `SessionRegistry::endAll('admin', username)` ends the account's
  sessions at once, and `AccountGeneration::bump(admin, username)` ends every impersonation of it at
  its next request. Feature 002's `App\Services\DomainAdmins\OrphanedDomainAdminRemover`, which 002
  planned to be extended here (002 D18), selects the orphaned usernames as before and hands each to
  `AccountDeleter::deleteOrphanedDomainAdmins(array $usernames, $actor)` instead of running its own
  `admin`/`da_acl` deletion (FR-032), so accounts left without a domain get the full cascade.
  Deactivation never revokes keys: 001's `ApiKeyAuthenticator` already refuses keys of an inactive
  owner and accepts them again on reactivation (FR-035).
- **Rationale**: Legacy deletes the same upstream rows (plus `da_acl` for domain administrators),
  but leaves `da_sso`, keys and sessions. Explicit revocation with a reason makes the key list show
  why a key stopped working, and it keeps a revoked key dead even if an account with the same name
  appears later (FR-036). `docs/conventions.md`: cleanup that two features perform is implemented
  once.
- **Alternatives considered**: Deleting the keys was rejected: 001 keeps revoked keys listed with
  their revocation details (001 FR-036).

### R11. Never acting for a later account with the same username (FR-036)

- **Decision**: Deletions and renames made through this API end what belongs to the account
  immediately: sessions through feature 005's `SessionRegistry::endAll()` (005 R2), keys by
  revocation (R10), unused tokens by deletion. What survives a change made by legacy, or lives in
  another account's session, is bound to the account itself by two signals:
  1. **Creation time**: `admin.created` (or `mailbox.created`), copied verbatim as the raw
     `DATETIME` string.
  2. **Account generation** (impersonation targets only): a Redis counter
     `restv2:account-generation:<kind>:<username>` (kind `admin` or `mailbox`, no TTL, absent = 0)
     that this API increments when it deletes or renames the account.

  They are bound as follows:
  - **Sessions**: 001's principal gains `accountCreatedAt`, written at sign-in; 001's
    `EnsureSessionIntegrity`, which already reads the account row on every request, ends the
    session when `created` differs (a legacy deletion followed by a re-creation).
  - **Impersonations**: the target's creation time and generation are stored at start and checked
    on every impersonating request (R17). The target's own sessions are not the impersonator's, so
    `SessionRegistry::endAll()` cannot reach them; the generation covers a deletion and re-creation
    within the same second through this API.
  - **API keys**: `restv2_api_keys.owner_created_at` (new column) is written at key creation and
    back-filled by the migration; 001's `ApiKeyAuthenticator` treats a key whose owner's
    `created` differs as `api-key-invalid`. Deletion through this API also revokes the keys (R10).
  - **Single sign-on tokens**: a token signs in only when `admin.created <= da_sso.created`
    (R20), and deletion and rename remove unused tokens.

  Feature 003's `MailboxDeleter` and `MailboxRenamer` call `AccountGeneration::bump(mailbox,
  address)`, so an impersonation of a deleted or renamed mailbox ends even when a mailbox with the
  same address is created within the same second.
- **Rationale**: Feature 005 already indexes sessions per account and rejected a per-request
  generation check for sessions; reusing its registry ends sessions at once and frees them. The
  creation time costs nothing extra, because the account row is read anyway. `created` has
  one-second precision, so the generation covers the remaining case for impersonations, read only
  while a session is impersonating. If Redis loses a counter, the stored and current values differ
  and the impersonation ends, which fails safe.
- **Alternatives considered**: A generation check for every session was rejected (005 R2: a read
  per request, no memory freed). An index of impersonations per target, with revocation markers,
  was rejected: it needs bookkeeping at every start and end for the same single read per request.
  An upstream column was rejected (constitution III).

### R12. Permission flags (FR-037 to FR-041, FR-077)

- **Decision**:
  - The 19 flags are the `da_acl` cases of 001's `App\Auth\PermissionFlag`, exposed as
    `DomainAdminPermissions` (camelCase, the same names as 001's `PermissionFlags`).
    `tests/Feature/Schema/AclColumnsTest.php` (001) already fails when upstream adds a column.
  - A change sends a partial `permissions` object. Unknown names are `unknownField` on
    `permissions.<name>`, non-booleans `invalidType` `{expected: boolean}`. With a `da_acl` row:
    `UPDATE da_acl SET <col> = ? … WHERE username = ?` for the sent flags only. Without a row:
    `INSERT INTO da_acl (username, <all 19>)` with the sent flags and every other flag `0`
    (FR-041). Flags are applied at the next request because 001 evaluates them per request.
  - A domain administrator can never change flags: every account operation is administrator-only
    (FR-001, FR-039).
  - **Defaults** (`GET /capabilities/accounts`, FR-077): `SELECT COLUMN_NAME, COLUMN_DEFAULT FROM
    information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'da_acl'`, with
    MariaDB's quoted literals (`'1'`) parsed to booleans, read on every request.
- **Rationale**: Legacy `acl('edit', 'domainadmin')` replaces the whole set and silently ignores
  unknown names; the spec deviates. Reading `information_schema` reports the defaults of the
  deployed schema, which can differ between supported releases, without hard-coding them.
- **Alternatives considered**: Creating a temporary `da_acl` row to read the defaults was rejected:
  it writes an upstream table for a read.

### R13. Second-factor suspension (FR-027)

- **Decision**: `UPDATE tfa SET active = '0' WHERE username = ?` (every type, as legacy
  `disable_tfa` does), inside a transaction with the `If-Match` check and the audit record, which
  carries the number of entries that were active. `fido2` is not touched. 001's password sign-in
  re-activates the entries (`UPDATE tfa SET active = '1'`, 001 FR-026), and legacy does the same.
  Suspending an account without active entries succeeds with `200`, and the audit records
  `suspendedCount: 0`.
- **Rationale**: Exact legacy semantics, as its own operation (spec parity table).

## 3. Reads

### R14. Lists, search and filters (FR-010 to FR-012, SC-008)

- **Decision**:
  - Base query: `admin WHERE superadmin = '1'` (administrators) or `'0'` (domain administrators,
    including those without assignments). Filters: `active`, `forcePasswordChange`,
    `forceSecondFactorSetup` (on `JSON_VALUE(attributes, …)`), `secondFactorStatus` (a derived table
    `SELECT username, MAX(active) AS any_active FROM tfa WHERE authmech IN (…) GROUP BY username`
    left-joined: `none` = no row, `active` = `any_active = 1`, `suspended` = `any_active = 0`).
    Domain administrators also accept `domain` (repeatable, normalised as in 002; `EXISTS` on
    `domain_admins` with any of the names) and `permission` plus `permissionValue` (default
    `true`; a missing `da_acl` row counts as `false`).
  - Search `q` (001's shared `Search` parameter and `ListQuery`): usernames by escaped `LIKE`.
    Domain administrators additionally match an assigned domain: ASCII names by `LIKE` in SQL, and
    readable names by loading the distinct assigned domain names (at most a few thousand),
    converting them with `idn_to_utf8` in PHP, keeping those whose Unicode form contains `q`
    case-insensitively (`mb_stripos`), and adding `OR EXISTS (… domain IN (…))`.
  - Sort: `username`, `active`, `createdAt`, `modifiedAt`, and for domain administrators
    `domainCount` (correlated `COUNT(*)` on the indexed `domain_admins.username`); ties broken by
    `username` ascending; default `username` ascending. `NULL` modification times sort first in
    ascending order.
  - Page assembly: after the paged query returns at most `limit` usernames, three batched queries
    load their assignments (joined to `domain` for `domainExists`), their `tfa` aggregate and their
    active `fido2` counts. `da_acl` is left-joined in the paged query.
- **Rationale**: Legacy returns every account and one detail query per account (N+1). The paged
  query plus three batched queries keeps a page of 25 within budget at 5,000 domain administrators
  and 5,000 domains (SC-008, verified by a performance test with that data set). `tfa` and `fido2`
  have no index on `username`, but the batched queries touch them once per page.
- **Alternatives considered**: Storing Unicode names in an API-owned table was rejected (sync
  problem with legacy writes). A generated column was rejected (upstream schema).

## 4. Bulk actions

### R15. Bulk actions (FR-073, FR-075, FR-076)

- **Decision**: `POST /admin-bulk-actions` and `POST /domain-admin-bulk-actions` take
  `{action, targets}`, where `action` is `activate`, `deactivate`, `suspendSecondFactors` or
  `delete`, and `targets` is 1–100 `BulkTarget` items (`id` = username, optional `version`).
  `App\Services\Accounts\AccountBulkActionRunner`:
  1. authorises the whole request first (administrator principal; for administrator accounts a
     signed-in session, otherwise `403 session-required`; a read-only key is already refused by
     001's `EnforceKeyScope`);
  2. validates the body (`tooMany` `{max: 100}` on `targets`);
  3. normalises the ids (trim, lower-case) and drops repeats, keeping the first position;
  4. processes each target in order, each in its own transaction through the same service method
     as the single operation (same checks, guard, side effects and audit record, with
     `details.bulkRequestId`), mapping the outcome: success → `done` (with the new `version` unless
     deleted), unknown or wrong-role account → `notFound`, `412` → `outdated`, the documented
     refusals (`last-active-admin`, `cannot-delete-own-account`) → `refused` with the problem, any
     other exception → `failed` with an `about:blank` `500` problem (logged with its trace);
  5. answers `200` with `BulkResultResponse`, `bulkRequestId` being the request ID.
- **Rationale**: `docs/conventions.md` and legacy's sequential mass actions. Reusing the single
  operation keeps the semantics identical by construction.
- **Alternatives considered**: One transaction for the whole bulk was rejected: one failure must
  not undo the others (FR-076).

## 5. Impersonation

### R16. Impersonation session model (FR-042 to FR-053)

- **Decision**:
  - **Storage**: the 001 session key `restv2.principal` keeps the authenticated account (the
    impersonator) unchanged, and 005's session handle stays the same, so 005's `SessionRegistry`
    keeps indexing the session under the impersonator. A new key `restv2.impersonation` holds `id`
    (ULID), `startedAt`, and the target's `username`, `role` (`mailboxUser` or `domainAdmin`),
    `accountKind`, `accountCreatedAt` and `generation`.
  - **Effective principal**: 001's `SessionManager::principal()` returns, during an impersonation,
    a `Principal` for the target (username, role, account kind) whose new `impersonator` property
    holds the impersonator's principal, and whose `authMethod` is the impersonator's. Every
    feature already authorises against the request's principal, so every operation acts with the
    target's role and scope without change.
  - **Start** (`POST /session/impersonation`): checks in this order, the first failure answering:
    API key → `403 session-required` (001 `RequireAuth` mode `session`); pending sign-in →
    `401 second-factor-required`; restricted → `403 pending-action-required`; already
    impersonating → `409 already-impersonating`; caller role or flag (mailbox user, domain
    administrator without `login_as`, domain administrator targeting a domain administrator) →
    `403 forbidden`; body validation → `422`; target lookup → `404` when it does not exist, is a
    resource, is an administrator, or is a mailbox outside the domain administrator's active
    assignments. Then `restv2.impersonation` is written, the identifier and the CSRF token are
    renewed through `SessionManager` (`migrate(true)` and `regenerateToken()`; 005 keeps its session
    registry in step with the new identifier), `impersonationStart` is audited,
    and the answer is `201` with `Location: /api/v2/session/impersonation` and
    `ImpersonationStarted` (the impersonation, the new identity and the new CSRF token).
  - **End** (`DELETE /session/impersonation`): removes the key, renews the identifier and the CSRF
    token, audits `impersonationEnd` with `endedBy: explicit`, and answers `204`. Without an
    impersonation it answers `204` and changes nothing (not audited, like 001's no-ops).
  - **Sign-out** (`DELETE /session`, 001): ends the whole session; 001's controller additionally
    audits `impersonationEnd` with `endedBy: signOut` before `signOut`. A password, passkey or
    token sign-in that replaces an impersonating session audits `endedBy: sessionReplaced`.
  - **Identity** (001 `GET /me`): username, role, scope and flags of the target (R18), the
    impersonator's pending actions and preferences, and a new `impersonation` member
    (impersonator username, role and sign-in method; target; start time). `GET /session` reports
    the same `impersonation` member.
  - **Pending actions**: evaluated for the impersonator only (the target's forced actions never
    restrict, FR-047). An impersonator restricted during the impersonation can still read, sign out
    and end the impersonation (`DELETE /session/impersonation` has pending mode `allow`).
  - **Preferences**: 001's preferences operations use the impersonator's account (FR-047).
  - **Independence**: nothing is written to legacy sessions (FR-053).
- **Rationale**: Keeping the authenticated account in `restv2.principal` lets 001's
  `EnsureSessionIntegrity` keep checking the account that signed in, which is exactly "when the
  impersonator can no longer sign in, the whole session ends" (FR-052). Renewing the CSRF token
  makes requests prepared in another tab under the previous identity fail instead of running as
  the wrong account (edge case "several browser tabs"). Returning the new token in the start
  response saves a round trip; after an end, the client fetches it with `GET /csrf-token`.
- **Alternatives considered**: Swapping `restv2.principal` to the target, as legacy swaps
  `mailcow_cc_username`, was rejected: every integrity check would then have to look in two
  places, and a forgotten check would keep a deactivated impersonator's session alive.
  Nested impersonation stacks were rejected by FR-043.

### R17. Validity of an impersonation on every request (FR-052)

- **Decision**: New middleware `App\Http\Middleware\EnforceImpersonation`, appended to the `api.v2`
  group right after 001's `EnsureSessionIntegrity`, runs for every session request with
  `restv2.impersonation`. It ends the impersonation when:
  - the target no longer exists with the stored role, or its `created` or generation differs
    (reason `targetDeletedOrRenamed`);
  - a mailbox target is no longer in an active assignment of an impersonating domain
    administrator, or became unreachable (reason `targetOutOfScope`);
  - an impersonating domain administrator's `login_as` is off (reason `loginAsRevoked`).

  Ending removes the key, renews the identifier and the CSRF token, and audits `impersonationEnd`
  with `endedBy: automatic` and the reason. The request is then answered `403 impersonation-ended`
  and **not** carried out, whatever it is, including `DELETE /session/impersonation` and
  `DELETE /session`, as the clarification requires ("the request that detects it is refused"); a
  repeated end then succeeds as a no-op and a repeated sign-out signs out. A deactivated target does
  not end the impersonation (FR-052).
- **Rationale**: Legacy never re-checks; the spec requires that a write meant for the target never
  runs as the impersonator. Renewing the CSRF token on an automatic end protects other tabs the
  same way an explicit end does. `403` is used because the refusal can hit any operation, and every
  operation already documents `403` through the shared `Forbidden` response, whose description
  lists the new type.
- **Alternatives considered**: `409` was rejected because it would have to be added to every
  operation of every feature. Ending silently and carrying the request out as the impersonator
  was rejected (FR-052). Exempting the end and the sign-out from the refusal was rejected: it would
  contradict FR-052 and its clarification, although their intent is fulfilled either way.

### R18. Effective permission flags during an impersonation (FR-046)

- **Decision**: 001's `IdentityService` computes the flags of an impersonating principal as legacy
  `acl('to_session')` does with `$_SESSION['dual-login']`:
  - administrator impersonator: all 29 flags `true`, whatever the target's role;
  - domain administrator impersonator (target always a mailbox user): every `user_acl` flag
    `true`, then every `da_acl` column of the **impersonator's** row overwrites its flag. The five
    overlapping flags (`syncjobs`, `quarantine`, `appPasswds`, `pushover`, `spamPolicy`) therefore
    take the impersonator's values, and the 14 `da_acl`-only flags too (all `false` when the row is
    missing).
- **Rationale**: Exact legacy semantics, as the clarification requires. The `da_acl`-only flags are
  not mailbox user flags, so FR-046's rule for mailbox user flags is unaffected; legacy sets them to
  the impersonator's values, and so does this API.
- **Alternatives considered**: Using the target's own `user_acl` row was rejected by the
  clarification.

### R19. Refusals during an impersonation (FR-049, FR-054)

- **Decision**: Route middleware `restv2.not-impersonating`
  (`App\Http\Middleware\RefuseDuringImpersonation`) answers `403 not-allowed-while-impersonating`.
  Feature 007 attaches it to:
  - feature 001: `POST /me/passkeys/registration-options`, `POST /me/passkeys`,
    `PATCH /me/passkeys/{passkeyId}` and `DELETE /me/passkeys/{passkeyId}`;
  - feature 005: the own password change, TOTP, WebAuthn and Yubico OTP enrolment and removal,
    the recovery e-mail change, and the own webmail hand-off;
  - feature 007: `POST /mailboxes/{mailbox}/webmail-sign-in`.

  It runs after `RequireAuth` and before `EnforcePendingActions`, so that an impersonating session
  gets this error and not a role error. App passwords are not guarded (FR-049).
- **Rationale**: A list of routes in `routes/api.php` is explicit and testable, and features 001
  and 005 exist when feature 007 is implemented (number order).
- **Alternatives considered**: Checks inside each controller were rejected (easy to miss).

## 6. Single sign-on tokens

### R20. Tokens in the legacy store (FR-057 to FR-063)

- **Decision**:
  - **Issue** (`POST /domain-admins/{username}/sso-token`, administrator session or read-write
    administrator key): the account must exist with `superadmin = 0` (else `404`) and be active
    (else `409 account-inactive`). The token is five groups of `strtoupper(bin2hex(random_bytes(3)))`
    joined by `-`, as legacy. One transaction inserts `da_sso (username, token)` (`created`
    defaults to `NOW()`), deletes rows older than 30 s (legacy cleanup), reads the inserted
    row's `created` to compute `expiresAt = created + 30 s` in UTC, and writes the
    `ssoTokenIssue` audit record with the target and expiry only. The answer is `200` with
    `DomainAdminSsoToken`.
  - **Sign-in** (`POST /session/sso-token`, any browser session with the CSRF token): the token is
    trimmed and upper-cased; anything not matching `^[0-9A-F]{6}(-[0-9A-F]{6}){4}$` fails without
    a lookup. Otherwise one transaction runs `SELECT t.username, t.created FROM da_sso t JOIN admin a
    ON a.username = t.username WHERE t.token = ? AND t.created > DATE_SUB(NOW(), INTERVAL 30
    SECOND) AND a.active = 1 AND a.superadmin = 0 AND a.created <= t.created FOR UPDATE`, then
    `DELETE FROM da_sso WHERE token = ?` and requires one affected row, as legacy's
    `domain_admin_sso('check')` does. Exactly one of two concurrent consumers (this API or the
    legacy `/domainadmin?sso_token=`) sees the row deleted.
  - **Session**: on success, 001's `SessionManager` replaces any existing session state (including
    an impersonation, audited as `sessionReplaced`), renews the identifier, writes the principal
    with `authMethod = ssoToken` and the account's `created` (005 registers the session), and the
    answer is `201`
    with 001's `SessionCreated`. No second factor is asked and `tfa` is not re-activated (legacy
    does neither). 001's `PendingActionEvaluator` applies, so forced actions restrict the session
    (FR-061).
  - **Failure**: `401 sign-in-failed`, audited as `signIn` failure with `authMethod: ssoToken` and
    reason `invalidCredentials`, counted on a throttle key `restv2:throttle:ssotoken:<sha256(ip)>`
    with 001's limits (`RESTV2_SIGNIN_MAX_FAILURES` in `RESTV2_SIGNIN_FAILURE_WINDOW`), `429` when
    exceeded, never published to fail2ban.
  - The token appears only in the issue response; 001's `RedactSecrets` already drops context keys
    matching `token`.
- **Rationale**: Sharing `da_sso` keeps billing panels' existing redirect target working and lets
  `/api/v1` tokens work here (FR-058). The `DELETE` with a row count is the same arbiter legacy
  uses, so single use holds across both implementations. `a.created <= t.created` ensures a token
  never signs in an account created after it (FR-036); a billing panel that creates the account and
  issues the token within one second still works, because the comparison is inclusive.
- **Alternatives considered**: An API-owned token table was rejected: tokens would not work in the
  legacy login, which FR-058 requires. Hashing tokens was rejected for the same reason.

## 7. Webmail on behalf of a user

### R21. Webmail sign-in on behalf (FR-054 to FR-056)

- **Decision**:
  - **Setting**: `ALLOW_ADMIN_EMAIL_LOGIN`, the `mailcow.conf` variable that legacy reads, parsed
    with legacy's regex `^([yY][eE][sS]|[yY])+$`; default off. `config('restv2.allow_admin_email_login')`.
  - **Order of checks** for `POST /mailboxes/{mailbox}/webmail-sign-in`: API key →
    `403 session-required`; impersonating → `403 not-allowed-while-impersonating` (R19); restricted
    → `403 pending-action-required`; mailbox user, or domain administrator without `login_as` →
    `403 forbidden`; setting off → `403 webmail-on-behalf-disabled`; mailbox missing, a resource, or
    outside the domain administrator's active assignments → `404`; mailbox not active
    (`active` 0 or 2), domain inactive, or `SKIP_SOGO=y` → `422` with `notEligible` on `mailbox`
    and `{reason: mailboxInactive | loginDisabled | domainInactive | webmailDisabled}`.
  - **Effect**: feature 005's `WebmailHandOffService::handOff($caller, $mailbox, $request)`
    (005 R3, FR-067, FR-068), called only after the checks above (so its own `groupware-disabled`
    check is never reached), grants a webmail-only legacy sign-in for the target mailbox to the
    requesting browser, ends any previous hand-off of the same API session, and records the legacy
    recent-login entry `REPLACE INTO sasl_log (service, app_password, username, real_rip) VALUES
    ('SSO', 0, :mailbox, :ip)`. The caller's session in this API is not changed (FR-055). The
    response is `200` with `MailboxWebmailSignIn` (`mailbox`, `url`) and the hand-off's cookie.
    `webmailSignInOnBehalf` is audited for success and refusals after authentication.
  - **Identity**: 001's identity gains `webmailOnBehalfEnabled` for administrators and domain
    administrators (FR-056).
- **Rationale**: Legacy `sogo-auth.php?login=` gating and its `sasl_log` entry, through 005's
  mechanism. Refusing ineligible mailboxes avoids legacy's redirect to a webmail login that then
  fails.
- **Alternatives considered**: Starting an impersonation together with the webmail sign-in, as
  legacy does, was rejected (FR-055: the caller's session is unchanged).

## 8. Audit, errors and authorisation

### R22. Audit (FR-066 to FR-069)

- **Decision**:
  - **Events** (appended to `AuditEventType`): `adminCreation`, `adminUpdate`, `adminDeletion`,
    `adminSecondFactorSuspension`, `domainAdminCreation`, `domainAdminUpdate`,
    `domainAdminDeletion`, `domainAdminSecondFactorSuspension`, `ssoTokenIssue`,
    `impersonationStart`, `impersonationEnd` and `webmailSignInOnBehalf`. A token sign-in is a
    `signIn` with `authMethod: ssoToken`.
  - **Targets** (appended to `AuditTarget.type`): `admin`, `domainAdmin`, `ssoToken` (id `null`,
    name = username), `impersonation` (id = impersonation ULID, name = target username), `mailbox`.
  - **Details** (whitelisted per event in 001's `AuditLogger`): `changes` (`{field: {old, new}}`
    for username, active, forced flags), `passwordChanged`, `passwordPreHashed`, `domainsAdded`,
    `domainsRemoved`, `permissionsChanged` (`{flag: {old, new}}`), `revokedApiKeyIds`,
    `removedSecondFactors`, `removedPasskeys`, `suspendedCount`, `expiresAt`, `endedBy`
    (`explicit`, `signOut`, `automatic`, `sessionReplaced`), `endReason`, `bulkRequestId`,
    `problemType` (for refusals).
  - **Refusals** (`docs/conventions.md`, "Refusal auditing"): every state-changing route of this
    feature is marked for refusal auditing with the shared mechanism (`RefusalAuditor` as the
    conventions name it, or the equivalent the first implemented feature created), so that every
    refusal of an authenticated caller writes one failure record: read-only keys, keys on
    session-only operations, restricted sessions, missing flags, out-of-scope objects (`404`),
    conflicts (`409`), outdated versions (`412`) and invalid input (`422`). The record carries a
    `failureReason` that is already in the shared, append-only `AuditFailureReason`
    (`permissionDenied`, `notFound`, `validationFailed`, `versionOutdated`, `conflict`, and 001's
    `accountInactive` for a token requested for an inactive account) and `details.problemType`.
    Feature 007 adds no failure reason. CSRF failures and `401` are not audited.
  - **Impersonated account**: `restv2_audit_events` gains `impersonated_username` and
    `impersonated_role` (migration). `AuditLogger` fills them, and sets the actor to the
    impersonator, whenever the principal is impersonating, for every feature's records (FR-067).
    `AuditEvent` gains `impersonatedAccount` (`null` outside impersonations), and
    `listAuditEvents` gains the `impersonatedAccount` filter, the one feature-specific audit filter
    that `docs/conventions.md` allows. History of an account uses the generic `targetType`
    (`admin`, `domainAdmin`, `ssoToken`, `impersonation`, `mailbox`) and `targetId` filters of
    feature 002 (002 FR-058), which is implemented first.
  - Passwords, hashes and tokens are never in `details` (the whitelist excludes them, and a test
    scans every record, SC-009).
- **Rationale**: Exactly one record per affected account, including refusals (FR-066), in the same
  transaction as the change (001 R22).
- **Alternatives considered**: Writing legacy `logs` rows with `impersonator => target` was rejected
  (spec deviation, constitution V).

### R23. Problem types and status codes (FR-070)

- **Decision**:

  | Type | Status | When |
  |---|---|---|
  | `account-exists` | 409 | username taken by an administrator or domain administrator (create, rename) |
  | `last-active-admin` | 409 | the change or deletion would leave no active administrator |
  | `cannot-delete-own-account` | 409 | an administrator deletes their own account |
  | `already-impersonating` | 409 | a start while an impersonation is active |
  | `impersonation-ended` | 403 | the request detected an invalid impersonation and was not carried out |
  | `not-allowed-while-impersonating` | 403 | a credential operation or webmail sign-in during an impersonation |
  | `webmail-on-behalf-disabled` | 403 | `ALLOW_ADMIN_EMAIL_LOGIN` is off |
  | `account-inactive` | 409 | a token is requested for an inactive domain administrator |

  "Outdated version" is `412` with `about:blank`, as 001 documents it in the conventions of
  `docs/problems.md`; a bulk item carries the same problem with outcome `outdated`. Validation
  codes: `unknownField` is appended to `ValidationRuleCode`; `reserved`, `immutable` and
  `notEligible` (`kind: aliasDomain`) are reused from features 002 and 003, and password rules use
  003's `tooShort` and `missingCharacterClass`; the format `accountUsername` is added to the
  `invalidFormat` description (`domainName` exists from 002).
- **Rationale**: `409` for refusals caused by the current state of the target, `403` for refusals
  caused by the caller's situation, following 001 (`forbidden`, `session-required`).
- **Alternatives considered**: `422` for `last-active-admin` was rejected: the input is valid, the
  server state is not.

### R24. Authorisation (FR-001 to FR-005)

- **Decision**: `App\Policies\AdminPolicy`, `DomainAdminPolicy` and `ImpersonationPolicy`:
  - every administrator and domain administrator operation needs an administrator principal;
    domain administrators and mailbox users get `403 forbidden`, also for their own account;
  - administrator writes (create, update, delete, suspend, bulk) need a session: a key gets
    `403 session-required` (001's route mode `session`). Reads (`listAdmins`, `getAdmin`,
    `getAccountCapabilities`) accept a session or a key of an administrator, read-only included;
  - domain administrator operations and token issue accept a session or an administrator key; a
    read-only key on a write is already refused by 001's `EnforceKeyScope`
    (`403 insufficient-key-scope`);
  - impersonation and webmail on behalf need a session (`session-required` for keys) and are
    authorised by `ImpersonationPolicy` (R16, R21);
  - the token sign-in is a `browser` route (001 R8): anonymous or signed-in sessions, keys refused
    with `session-required`.
- **Rationale**: The FR-005 matrix and `docs/conventions.md` ("writing admin accounts" needs an
  interactive administrator session).

### R25. Dependencies on earlier features' code and new packages

- **Decision**: No new Composer package. `ext-intl` (IDN) is in 001's image. Feature 007 uses and
  extends code delivered by:
  - 001: `Principal`, `SessionManager` (with 005's handle and `SessionRegistry`), `EnsureSessionIntegrity`, `RequireAuth`,
    `EnforcePendingActions`, `IdentityService`, `PreferencesService`, `ApiKeyService`,
    `ApiKeyAuthenticator`, `AuditLogger`, `ListQuery`, `ETag`, `ApiFormRequest`, `ProblemType`,
    `SignInThrottle`, `MailcowFactory`;
  - 002: `App\Support\Domain\DomainName` (normalisation) and
    `App\Services\DomainAdmins\OrphanedDomainAdminRemover` (orphan cleanup delegation, 002 D18);
  - 003: `App\Services\Passwords\{PasswordPolicyReader, PasswordPolicyChecker,
    MailboxPasswordHasher}`, `App\Services\Mailboxes\MailboxAddress`, and
    `MailboxDeleter`/`MailboxRenamer` (generation bump);
  - 005: `App\Services\Auth\SessionRegistry` (ending an account's sessions),
    `App\Services\Webmail\WebmailHandOffService` and the routes of its credential operations
    (impersonation guard).

  Where a class delivered by those features has another name than the plan expects, the delivered
  name is used; the tasks name the behaviour they need.
- **Rationale**: Constitution VIII; features are implemented in number order.
