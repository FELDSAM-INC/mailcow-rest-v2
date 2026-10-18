# Data Model: Administrators and Domain Administrators

**Feature**: `specs/007-administrators-domain-admins` | **Date**: 2026-10-18 | **Plan**: [plan.md](./plan.md)

Storage follows feature 001's rules ([001 data model](../001-auth-sessions-api-keys/data-model.md)):
upstream tables are never migrated and are written only by the services named here; API-owned
tables carry the `restv2_` prefix and Laravel migrations; API-owned Redis keys carry the `restv2:`
prefix. Column definitions are those of upstream `2026-09a` (`db_version 18082026_1200`),
`data/web/inc/init_db.inc.php`. JSON shapes are in the contract (`api/components/schemas/`).

Upstream `DATETIME` values are mailcow local time and go through 001's `MailcowDateTime` cast.
Writes leave `created` and `modified` to their database defaults, as legacy does.

---

## Upstream tables

### `admin`: administrator and domain administrator accounts

Written only by `App\Services\Accounts\AdminAccountService`, `DomainAdminAccountService`,
`AccountRenamer` and `AccountDeleter`. Model `App\Models\Mailcow\Admin` (001, read-only).

| Column | Type | Read | Written |
|---|---|---|---|
| `username` | VARCHAR(255) NOT NULL, PK (case-insensitive collation) | `username`; lookups lower-case the input | create (normalised, R4); rename |
| `password` | VARCHAR(255) NOT NULL | never returned | create; password change (003's hasher, `{SCHEME}hash`) |
| `superadmin` | TINYINT(1) NOT NULL DEFAULT 0 | `1` → administrator, `0` → domain administrator | create (`1` or `0`); never changed |
| `created` | DATETIME(0) NOT NULL DEFAULT NOW(0) | `createdAt`; version tag; FR-036 binding | default |
| `modified` | DATETIME ON UPDATE NOW(0) | `modifiedAt` (`null` when never modified) | by the database on any changed value |
| `active` | TINYINT(1) NOT NULL DEFAULT 1 | `active` | create; status change; bulk activate/deactivate |
| `attributes` | JSON | `force_pw_update`, `force_tfa` (`"1"` → `true`; missing → `false`) | create: `{"force_tfa": "0|1", "force_pw_update": "0|1"}`; change: `JSON_SET(COALESCE(attributes, '{}'), '$.force_tfa', ?, '$.force_pw_update', ?)` preserving other keys |

**Validation** (Form Requests and `App\Rules\AccountUsername`, FR-014 to FR-018):

- `username`: "required; trimmed and lower-cased; 1–255 characters (`tooLong` `{max: 255}`);
  only `a-z`, `0-9`, `.`, `_`, `-` with at least one letter or digit (`invalidFormat`
  `{format: accountUsername}`); not `api` (`reserved` `{reserved: [api]}`); unique among `admin`
  rows (`409 account-exists`)".
- `password`: "required at creation, string of 1–4096 characters; checked by 003's password policy
  (one violation per unmet rule, 003's codes) unless it starts with a recognised `{SCHEME}`
  prefix; hashed with `MAILCOW_PASS_SCHEME`".
- `active`, `forcePasswordChange`, `forceSecondFactorSetup`: "optional booleans; defaults `true`,
  `false`, `false`" (`invalidType` `{expected: boolean}`).

**Account state** (both roles):

```
            create (active: true, default)        create (active: false)
                    │                                     │
                    ▼        PATCH active / bulk          ▼
                 active  ◄─────────────────────────►  inactive
                    │   (deactivation refused when     │
                    │    it would leave no active      │
                    │    administrator, R8)            │
                    └────────────┬─────────────────────┘
                                 │ DELETE / bulk delete (own account refused)
                                 ▼
                              deleted  (keys revoked, sessions ended through 005's registry,
                                        impersonations of it end (generation bumped),
                                        preferences removed)
```

Inactive accounts can be read, changed, impersonated and deleted, but cannot sign in, and their
keys are refused (001). A rename keeps the state.

**Version tag** (R3): strong ETag over canonical JSON of `username`, `created`, `active`,
`force_pw_update`, `force_tfa`; for domain administrators also the assignments ordered by domain
as `[domain, active, created]` and the 19 `da_acl` values (or `null` without a row).

### `domain_admins`: assignments

Written only by `DomainAdminAccountService`, `AccountRenamer` and `AccountDeleter`. Model
`App\Models\Mailcow\DomainAdminAssignment` (001). The table has no `modified` column.

| Column | Type | Read | Written |
|---|---|---|---|
| `id` | INT AUTO_INCREMENT PK | — | default |
| `username` | VARCHAR(255) NOT NULL, KEY | owner | create; rename (`UPDATE … SET username`) |
| `domain` | VARCHAR(255) NOT NULL | `domains[].domain`; `domainUnicode` via `idn_to_utf8`; `domainExists` = row in `domain` | create; domain set change (insert new, delete removed) |
| `created` | DATETIME(0) NOT NULL DEFAULT NOW(0) | `domains[].createdAt` | default (kept for domains that stay) |
| `active` | TINYINT(1) NOT NULL DEFAULT 1 | `domains[].active` | the account's status at insert; every row on a status change |

**Validation of a domain set** (FR-018, FR-023): "array of 1 or more strings (`required` when
empty); each normalised as in 002 FR-006 (trim, lower-case, UTS #46 to ASCII); syntax error →
`invalidFormat` `{format: domainName}`; alias domain name → `notEligible`
`{kind: aliasDomain}`; unknown → `notFound`; duplicates merged; every bad entry named as
`domains.<index>`". In a change, an item may also be an assignment object whose read-only members
must equal the stored values (`immutable`).

**Scope**: only active rows whose domain is a row of `domain` grant scope (001 R20). Rows for other
names are listed with `domainExists: false` and dropped when the set is next saved.

### `da_acl`: permission flags

Written only by `DomainAdminAccountService`, `AccountRenamer` and `AccountDeleter`. Model
`App\Models\Mailcow\DaAcl` (001).

| Column (contract name) | Default `2026-09a` |
|---|---|
| `username` PK | — |
| `syncjobs` (`syncjobs`) | 1 |
| `quarantine` (`quarantine`) | 1 |
| `login_as` (`loginAs`) | 1 |
| `sogo_access` (`sogoAccess`) | 1 |
| `app_passwds` (`appPasswds`) | 1 |
| `bcc_maps` (`bccMaps`) | 1 |
| `pushover` (`pushover`) | 0 |
| `filters` (`filters`) | 1 |
| `ratelimit` (`ratelimit`) | 1 |
| `spam_policy` (`spamPolicy`) | 1 |
| `extend_sender_acl` (`extendSenderAcl`) | 0 |
| `unlimited_quota` (`unlimitedQuota`) | 0 |
| `protocol_access` (`protocolAccess`) | 1 |
| `smtp_ip_access` (`smtpIpAccess`) | 1 |
| `alias_domains` (`aliasDomains`) | 0 |
| `mailbox_relayhost` (`mailboxRelayhost`) | 1 |
| `domain_relayhost` (`domainRelayhost`) | 1 |
| `domain_desc` (`domainDesc`) | 0 |
| `alias_external_goto` (`aliasExternalGoto`) | 1 |

All flag columns are `TINYINT(1) NOT NULL DEFAULT <d>`.

- **Create**: delete any orphaned row, then `INSERT INTO da_acl (username, <sent flags>)`, so unsent
  flags get the schema defaults.
- **Change**: with a row, `UPDATE da_acl SET <sent columns> WHERE username = ?`; without a row,
  `INSERT` with the sent flags and every other flag `0` (FR-041).
- **Read without a row**: every flag `false`.
- **Defaults read** (FR-077): `information_schema.COLUMNS` for `TABLE_SCHEMA = DATABASE()` and
  `TABLE_NAME = 'da_acl'`, `COLUMN_DEFAULT` parsed (`'1'`/`1` → `true`).
- **Validation**: "object with any subset of the 19 names; each value boolean (`invalidType`
  `{expected: boolean}`); unknown name → `unknownField` on `permissions.<name>`".

### `da_sso`: single sign-on tokens (shared with legacy)

Written only by `App\Services\Auth\SsoTokenService`, `AccountRenamer` and `AccountDeleter`. New
model `App\Models\Mailcow\DaSsoToken` (read-only; writes through the query builder). The primary
key is (`token`, `created`).

| Column | Type | Use |
|---|---|---|
| `username` | VARCHAR(255) NOT NULL | domain administrator |
| `token` | VARCHAR(255) NOT NULL | `XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX`, upper-case hex from `random_bytes` |
| `created` | DATETIME(0) NOT NULL DEFAULT NOW(0) | validity: younger than 30 s; `expiresAt = created + 30 s` |

- **Issue**: `INSERT INTO da_sso (username, token) VALUES (?, ?)`, then
  `DELETE FROM da_sso WHERE created < DATE_SUB(NOW(), INTERVAL 30 SECOND)`.
- **Consume** (one transaction): `SELECT t.username, t.created FROM da_sso t JOIN admin a ON
  a.username = t.username WHERE t.token = ? AND t.created > DATE_SUB(NOW(), INTERVAL 30 SECOND) AND
  a.active = 1 AND a.superadmin = 0 AND a.created <= t.created FOR UPDATE`, then
  `DELETE FROM da_sso WHERE token = ?` with exactly one affected row required.
- **Rename and deletion**: `DELETE FROM da_sso WHERE username = ?`.

**Token state**:

```
 issued ──(sign-in here or in legacy, < 30 s, account active)──► consumed (row deleted)
   │
   ├──(30 s pass)──► expired (row deleted at the next issue)
   └──(account renamed or deleted through this API)──► removed
```

### `tfa`: second factors

Written by `SecondFactorSuspender` (suspend), `AccountRenamer` and `AccountDeleter`; 001's sign-in
re-activates.

| Column | Use in this feature |
|---|---|
| `id`, `key_id`, `secret`, `keyHandle`, `publicKey`, `counter`, `certificate` | untouched |
| `username` | owner; rename `UPDATE tfa SET username = :new WHERE username = :old` |
| `authmech` | `totp`, `webauthn`, `yubi_otp` count for `secondFactorStatus`; `u2f`, `hotp` never count |
| `active` | suspend: `UPDATE tfa SET active = '0' WHERE username = ?` |

Deletion: `DELETE FROM tfa WHERE username = ?` (count recorded first).

### `fido2`: passkeys

Written by `AccountRenamer` and `AccountDeleter` (001's `PasskeyService` owns the user's own
operations). `activePasskeyCount` = `COUNT(*) WHERE username = ? AND active = 1`. Rename:
`UPDATE fido2 SET username = :new WHERE username = :old`. Deletion:
`DELETE FROM fido2 WHERE username = ?`. Suspension never touches it.

### `domain`, `alias_domain`, `mailbox`: read only

- `domain` (`domain`, `active`): existence of assigned domains; domain status for webmail
  eligibility.
- `alias_domain` (`alias_domain`): distinguishes an alias domain name (`notEligible`) from an
  unknown name (`notFound`).
- `mailbox` (`username`, `domain`, `kind`, `active`, `created`): impersonation and webmail targets.
  Resources (`kind REGEXP 'location|thing|group'`) answer `404`. `active`: `1` active, `2` login
  disabled, `0` inactive.

### `sasl_log`: recent logins (webmail on behalf)

Written by feature 005's webmail hand-off service for the target mailbox:
`REPLACE INTO sasl_log (service, app_password, username, real_rip) VALUES ('SSO', 0, ?, ?)`, as
legacy `sogo-auth.php` does.

---

## API-owned tables (migrations, prefix `restv2_`)

### `restv2_audit_events` (001) — new columns

Migration `database/migrations/2026_10_18_000701_add_impersonation_to_restv2_audit_events_table.php`.

| Column | Type | Constraint | Contract |
|---|---|---|---|
| `impersonated_username` | VARCHAR(255) | NULL, INDEX (`impersonated_username`, `occurred_at`) | `impersonatedAccount.username` |
| `impersonated_role` | VARCHAR(16) | NULL | `impersonatedAccount.role` |

**New events** (whitelisted `details` keys in `AuditLogger`):

| `eventType` | Target | `details` |
|---|---|---|
| `adminCreation` | `admin`, username | `active`, `forcePasswordChange`, `forceSecondFactorSetup`, `passwordPreHashed` |
| `adminUpdate` | `admin`, username | `changes`, `passwordChanged`, `passwordPreHashed`, `bulkRequestId` |
| `adminDeletion` | `admin`, username | `revokedApiKeyIds`, `removedSecondFactors`, `removedPasskeys`, `bulkRequestId` |
| `adminSecondFactorSuspension` | `admin`, username | `suspendedCount`, `bulkRequestId` |
| `domainAdminCreation` | `domainAdmin`, username | as `adminCreation`, plus `domains`, `permissions` |
| `domainAdminUpdate` | `domainAdmin`, username | as `adminUpdate`, plus `domainsAdded`, `domainsRemoved`, `permissionsChanged` |
| `domainAdminDeletion` | `domainAdmin`, username | as `adminDeletion`, plus `domains`, `removedSsoTokens`, `trigger` (`request` or `domainDeletion`) |
| `domainAdminSecondFactorSuspension` | `domainAdmin`, username | `suspendedCount`, `bulkRequestId` |
| `ssoTokenIssue` | `ssoToken`, null id, username | `expiresAt` |
| `signIn` (method `ssoToken`) | `session` | `requestedRole: domainAdmin`, `step: ssoToken`, `restricted` |
| `impersonationStart` | `impersonation`, ULID, target username | `targetRole` |
| `impersonationEnd` | `impersonation`, ULID, target username | `endedBy` (`explicit`, `signOut`, `automatic`, `sessionReplaced`), `endReason` (`targetDeletedOrRenamed`, `targetOutOfScope`, `loginAsRevoked`), `durationSeconds` |
| `webmailSignInOnBehalf` | `mailbox`, address | — |

Every failure record of these events adds `problemType`. Records written while the principal is
impersonating (any feature) carry the impersonator as actor and the target in the new columns.

**New filter**: `impersonatedAccount` → `impersonated_username = ?`.

### `restv2_api_keys` (001) — new columns

Migration `database/migrations/2026_10_18_000702_add_owner_binding_to_restv2_api_keys_table.php`.

| Column | Type | Constraint | Use |
|---|---|---|---|
| `owner_created_at` | DATETIME(0) | NULL | verbatim copy of the owner's `admin.created` at creation (mailcow local time); back-filled by the migration with `UPDATE restv2_api_keys k JOIN admin a ON a.username = k.owner_username SET k.owner_created_at = a.created WHERE k.owner_created_at IS NULL` |
| `revocation_reason` | VARCHAR(16) | NULL | `manual` (001 revocation) or `ownerDeleted` (R10); contract `ApiKey.revocationReason` |

`ApiKeyAuthenticator` (001) treats a key whose owner's current `created` differs from
`owner_created_at` as `api-key-invalid` (FR-036). `AccountRenamer` moves `owner_username`;
`owner_created_at` stays, because a rename keeps `created`.

### `restv2_preferences` (001)

No new column. Rename: `UPDATE … SET username = :new WHERE account_kind = 'admin' AND username =
:old`. Deletion: `DELETE … WHERE account_kind = 'admin' AND username = ?`.

---

## Redis (connection `restv2`, prefix `restv2:`)

| Key | Value | TTL | Owner |
|---|---|---|---|
| `restv2:account-generation:<kind>:<username>` | integer, absent = 0; `INCR` when this API deletes or renames the account (`kind` = `admin` or `mailbox`); read only to validate impersonation targets | none | `App\Services\Accounts\AccountGeneration` |
| `restv2:account-sessions:<accountKind>:<username>` (feature 005) | hash `handle → session ID`; used through `SessionRegistry::endAll()` and `rename()` on deletion and rename | `SESSION_LIFETIME` | 005's `SessionRegistry` |
| `restv2:throttle:ssotoken:<sha256(ip)>` (+ `:timer`) | failed token sign-ins | `RESTV2_SIGNIN_FAILURE_WINDOW` | 001's `SignInThrottle`, new key kind |

### Session keys (001's Laravel session)

| Key | Change |
|---|---|
| `restv2.principal` | gains `accountCreatedAt` (raw `created`), written at every sign-in; `authMethod` may be `ssoToken` |
| `restv2.impersonation` (new) | present while impersonating: `id` (ULID), `startedAt` (UTC), `target` (`username`, `role`, `accountKind`, `accountCreatedAt`, `generation`) |

**Impersonation state**:

```
                   POST /session/impersonation
 not impersonating ───────────────────────────────► impersonating
        ▲          (full session, role and flag,           │
        │           target in scope; ID + CSRF renewed)    │
        │                                                  │
        ├──── DELETE /session/impersonation (ID + CSRF renewed, endedBy explicit)
        ├──── target deleted, renamed, replaced, out of scope, or login_as off:
        │     detected on the next request → 403 impersonation-ended, request not carried out,
        │     ID + CSRF renewed (endedBy automatic)
        │
 session ended ◄── DELETE /session (endedBy signOut), new sign-in on the same session
                   (endedBy sessionReplaced), impersonator deactivated or deleted (001)
```

---

## Value objects (no storage)

- `App\Auth\Principal` (001) gains `?Principal $impersonator`, `?string $impersonationId`,
  `?CarbonImmutable $impersonationStartedAt` and `?string $accountCreatedAt`.
- `App\Auth\AuthMethod` (001) gains `SsoToken` (`ssoToken`).
- `App\Auth\ImpersonationEndReason` (new): `TargetDeletedOrRenamed`, `TargetOutOfScope`,
  `LoginAsRevoked`.
- `App\Services\Accounts\AccountBulkAction` (new enum): `Activate`, `Deactivate`,
  `SuspendSecondFactors`, `Delete`.
- `App\Services\Accounts\SecondFactorStatus` (new enum): `None`, `Active`, `Suspended`.
- `App\Support\Http\ProblemType` (001) gains the eight cases of research R23.
