# Data Model: Sync Jobs

**Feature**: `specs/008-sync-jobs` | **Date**: 2026-10-18 | **Plan**: [plan.md](./plan.md)

Feature 008 stores nothing new in mailcow's schema and creates no table. It reads and writes the
upstream `imapsync` table through `App\Services\SyncJobs\SyncJobService` only (constitution III
and IV). It reads `mailbox`, `domain_admins`, `alias`, `alias_domain`, `user_acl` and `da_acl`. Its
audit records go into 001's `restv2_audit_events`, without any schema change. Column definitions are
quoted from upstream `2026-09a` `init_db.inc.php` (`db_version 18082026_1200`), the source of the
schema fixture. The JSON shapes are defined in the contract (`api/components/schemas/SyncJob*.yaml`);
this document maps them to storage.

---

## Upstream tables

### Synchronisation job (`imapsync`)

Read and written. Model `App\Models\Mailcow\SyncJob`:

- `$connection = 'mysql'`, `$table = 'imapsync'`, `$primaryKey = 'id'` (int, incrementing);
- `public $timestamps = false`, because mailcow maintains `created` and `modified`;
- `$hidden = ['password1', 'returned_text']`;
- writes only through `SyncJobService` (`$guarded = ['*']`; the service sets attributes
  explicitly with `forceFill`).

The table has **no index other than the primary key** and no unique key, so the uniqueness of
`(user2, user1, host1)` is enforced in code ([research R9](./research.md#r9-duplicate-guard)).

| Column | Definition | Contract (`SyncJob`) | Written by this API | Notes |
|---|---|---|---|---|
| `id` | INT NOT NULL AUTO_INCREMENT, PK | `id` | — | Path `syncJobId` |
| `user2` | VARCHAR(255) NOT NULL | `mailbox` | create (canonical `mailbox.username`) | Immutable afterwards (FR-016); scope join key |
| `host1` | VARCHAR(255) NOT NULL | `remoteHost` | create (lower case), edit (as sent) | Legacy host rule (R6) |
| `authmech1` | ENUM('PLAIN','LOGIN','CRAM-MD5') DEFAULT 'PLAIN' | `authMechanism` = `plain` (constant) | create: `'PLAIN'` | Never changed; not passed to imapsync by the runner |
| `regextrans2` | VARCHAR(255) DEFAULT '' | — | never | Not exposed (FR-007) |
| `authmd51` | TINYINT(1) NOT NULL DEFAULT 0 | — | never | Not exposed |
| `domain2` | VARCHAR(255) NOT NULL DEFAULT '' | — | never | Not exposed |
| `subfolder2` | VARCHAR(255) NOT NULL DEFAULT '' | `destinationSubfolder` | create, edit | |
| `user1` | VARCHAR(255) NOT NULL | `remoteUser` | create, edit | |
| `password1` | VARCHAR(255) NOT NULL | `remotePasswordSet` = `password1 <> ''` | create, edit (non-empty only) | Plain text for the runner; never serialised, logged or audited (FR-018); enters the version tag only as an HMAC |
| `exclude` | VARCHAR(500) NOT NULL DEFAULT '' | `exclude` | create (default `(?i)spam\|(?i)junk`), edit | PCRE check with `/` delimiters (R6) |
| `maxage` | SMALLINT NOT NULL DEFAULT '0' | `maxAgeDays` | create, edit | 0–32000 |
| `mins_interval` | SMALLINT UNSIGNED NOT NULL DEFAULT '0' | `intervalMinutes` | create (default 20), edit | 1–43800 |
| `maxbytespersecond` | VARCHAR(50) NOT NULL DEFAULT '0' | `maxBytesPerSecond` (integer) | create, edit (decimal string) | 0–125000000; cast `integer` |
| `port1` | SMALLINT UNSIGNED NOT NULL | `remotePort` | create, edit | 1–65535 |
| `enc1` | ENUM('TLS','SSL','PLAIN') DEFAULT 'TLS' | `encryption`: `SSL`→`ssl`, `TLS`→`starttls`, `PLAIN`→`plain` | create (default `SSL`), edit | Enum cast `SyncJobEncryption` |
| `delete2duplicates` | TINYINT(1) NOT NULL DEFAULT '1' | `deleteDuplicatesOnDestination` | create (default 1), edit | |
| `delete1` | TINYINT(1) NOT NULL DEFAULT '0' | `deleteFromSource` | create, edit | |
| `delete2` | TINYINT(1) NOT NULL DEFAULT '0' | `deleteFromDestination` | create, edit | |
| `automap` | TINYINT(1) NOT NULL DEFAULT '0' | `automap` | create (default 1), edit | |
| `skipcrossduplicates` | TINYINT(1) NOT NULL DEFAULT '0' | `skipCrossDuplicates` | create, edit | |
| `custom_params` | VARCHAR(512) NOT NULL DEFAULT '' | `customParameters` | create, edit (trimmed) | Allow-list (R7) |
| `timeout1` | SMALLINT NOT NULL DEFAULT '600' | `remoteTimeoutSeconds` | create, edit | 1–32000 |
| `timeout2` | SMALLINT NOT NULL DEFAULT '600' | `localTimeoutSeconds` | create, edit | 1–32000 |
| `subscribeall` | TINYINT(1) NOT NULL DEFAULT '1' | `subscribeAll` | create (default 1), edit | |
| `dry` | TINYINT(1) NOT NULL DEFAULT '0' | `dryRun` | create, edit | |
| `is_running` | TINYINT(1) NOT NULL DEFAULT '0' | `runStatus.running` | never | Runner-owned |
| `returned_text` | LONGTEXT | `SyncJobLog.log`; `runStatus.logAvailable` | never | Runner-owned; never selected by lists |
| `last_run` | TIMESTAMP NULL DEFAULT NULL | `runStatus.lastRunAt` | reschedule: `NULL` | Runner writes `NOW()` at the end of a run |
| `success` | TINYINT(1) UNSIGNED DEFAULT NULL | `runStatus.lastResult` (1 `succeeded`, 0 `failed`, NULL `unknown`) | reschedule: `NULL` | Runner sets NULL at run start |
| `exit_status` | VARCHAR(50) DEFAULT NULL | `runStatus.exitStatus` (`null` when `lastResult` is `unknown`) | never | Runner-owned |
| `created` | DATETIME(0) NOT NULL DEFAULT NOW(0) | `createdAt` (UTC) | database default | Cast `MailcowDateTime` |
| `modified` | DATETIME ON UPDATE CURRENT_TIMESTAMP | `modifiedAt` (UTC, nullable) | database | Changes on runner writes too |
| `active` | TINYINT(1) NOT NULL DEFAULT '0' | `active` | create (default 1), edit, bulk | Runner selects only `active = 1` |

**Writes** (all in `SyncJobService`, each in a transaction with its audit record):

| Operation | SQL (shape) | Columns |
|---|---|---|
| create | `INSERT INTO imapsync (user2, exclude, delete1, delete2, timeout1, timeout2, automap, skipcrossduplicates, maxbytespersecond, subscribeall, dry, maxage, subfolder2, host1, authmech1, user1, password1, mins_interval, port1, enc1, delete2duplicates, custom_params, active) VALUES (…)` | Exactly legacy's insert list (SC-002); everything else keeps its default |
| edit / activate / deactivate | `UPDATE imapsync SET <changed configuration columns> WHERE id = ?` | Never `is_running`, `returned_text`, `last_run`, `success`, `exit_status`, `user2` |
| reschedule | `UPDATE imapsync SET last_run = NULL, success = NULL WHERE id = ?` | Legacy "Schedule next" |
| delete | `DELETE FROM imapsync WHERE id = ?` | The runner's later status write matches no row |

**Version tag** ([research R4](./research.md#r4-version-tag)): a strong ETag over the JSON of
`id, user2, host1, port1, enc1, user1, HMAC-SHA256(app.key, password1), exclude, subfolder2,
mins_interval, maxage, maxbytespersecond, timeout1, timeout2, delete1, delete2, delete2duplicates,
automap, skipcrossduplicates, subscribeall, dry, custom_params, active`. Run-status columns,
`created`, `modified` and the unexposed columns are excluded, so runs and reschedules never change
it.

**Search** (`q`): `user2 LIKE ? OR user1 LIKE ? OR host1 LIKE ?` (001 `ListQuery`, escaped,
case-insensitive through the collation).

**Filters**: `mailbox` → `user2 = ?`; `domain` → `mailbox.domain = ?`; `active` → `active = ?`;
`running` → `is_running = ?`; `lastResult` → `success = 1 | = 0 | IS NULL`.

**Sort**: `id`, `mailbox` (`user2`), `remoteHost` (`host1`), `remoteUser` (`user1`),
`intervalMinutes` (`mins_interval`), `active`, `lastRunAt` (`last_run`), `lastResult` (`success`),
`createdAt` (`created`), `modifiedAt` (`modified`), then `id` in the same direction. Default `id`
ascending.

### Mailbox (`mailbox`), read only

001's `App\Models\Mailcow\Mailbox` (completed by 003). Columns used: `username` (PK, the join
target of `imapsync.user2`), `domain` (scope and `domain` filter) and `kind` (`''` is a mailbox;
`location`, `thing` and `group` are resources, refused as targets with `notEligible {kind:
resource}`). `active` is not checked: a job may target an inactive mailbox (spec edge case).

### Domain administrator assignments (`domain_admins`), read only

001's `DomainAdminAssignment`. A domain administrator's scope is
`SELECT domain FROM domain_admins WHERE username = ? AND active = '1'` (legacy `hasDomainAccess`).

### Alias (`alias`) and alias domain (`alias_domain`), read only

Used only to tell "not a mailbox" (`422 notEligible`) apart from "not found" (`404`) for a creation
target ([research R8](./research.md#r8-target-mailbox-resolution)). The models
`App\Models\Mailcow\Alias` and `AliasDomain` come from feature 004.

- `alias`: `address` (the looked-up value), `domain` (scope check).
- `alias_domain`: `alias_domain` (the domain part of the looked-up value), `target_domain` (scope
  check).

### Permission flags (`user_acl`, `da_acl`), read only

Through 001's `IdentityService` (`acl('to_session')` port). The flag is `PermissionFlag::Syncjobs`
(`user_acl.syncjobs`, legacy default 0; `da_acl.syncjobs`, legacy default 1; always true for
administrators). Features 003 and 007 edit these columns. This feature never writes them.

---

## API-owned storage

### Audit records (`restv2_audit_events`, from 001)

No new table and no migration.

Records written by this feature (`AuditLogger` whitelists the `details` keys per event type):

| `event_type` | `target_type` / `target_id` / `target_name` | `details` keys |
|---|---|---|
| `syncJobCreation` | `syncJob` / id or null / mailbox or null | `mailbox`, `attributes`, `problemType`, `status`, `errors` |
| `syncJobUpdate` | `syncJob` / id or null / mailbox or null | `mailbox`, `changes`, `bulkRequestId`, `bulkAction`, `targetIds`, `problemType`, `status`, `errors` |
| `syncJobReschedule` | `syncJob` / id or null / mailbox or null | `mailbox`, `cleared`, `previous`, `bulkRequestId`, `bulkAction`, `targetIds`, `problemType`, `status` |
| `syncJobDeletion` | `syncJob` / id or null / mailbox or null | `mailbox`, `remoteHost`, `remoteUser`, `bulkRequestId`, `bulkAction`, `targetIds`, `problemType`, `status` |

- `changes` maps contract member names to `{old, new}`. The remote password appears only as
  `remotePassword: "changed"`.
- `attributes` is the created configuration in contract form, without the password, plus
  `remotePasswordSet: true`.
- `errors` maps each member to its rule codes, never to submitted values.
- `failure_reason` is one of `permissionDenied` (missing flag, read-only key or restricted
  session, told apart by `details.problemType`), `notFound`, `versionOutdated`, `validationFailed`
  (`422` or a malformed `400` body) or `conflict`, or NULL for an unexpected failure. A
  whole-request bulk refusal has a NULL target and `details.targetIds`.
- Refusals are recorded by `App\Services\Audit\RefusalAuditor` for the routes that declare
  `restv2.audit`, for every authenticated caller, except CSRF failures. `401` requests have no
  principal and are not recorded (research R16).

**History**: clients filter with the generic `targetType=syncJob` and `targetId=<id>` parameters of
`GET /audit-events` (`docs/conventions.md`). This feature adds no audit filter.

### Redis

None. The feature adds no Redis key.

### MariaDB named lock

`restv2_syncjob_<first 40 hex of sha1(lower(user2 \0 user1 \0 host1))>`, held with
`GET_LOCK(name, 5)` around the duplicate check and the insert or identity update (R9). It is
released in `finally`, and automatically when the connection ends.

---

## Value objects and enumerations (no storage)

- `App\Services\SyncJobs\SyncJobEncryption`: string-backed enum (`Ssl = 'SSL'`, `Starttls = 'TLS'`,
  `Plain = 'PLAIN'`) with `fromApi(string)` and `toApi()`.
- `App\Services\SyncJobs\SyncJobLastResult`: `succeeded`, `failed`, `unknown`, with
  `fromStored(?int)`.
- `App\Services\SyncJobs\SyncJobBulkAction`: `activate`, `deactivate`, `reschedule`, `delete`.
- `App\Services\SyncJobs\SyncJobDefaults`: the creation defaults (clarification 8) as one constant
  map, used by creation and by the capabilities read.
- `App\Services\SyncJobs\SyncJobLimits`: the ranges and lengths of R6, used by validation and by the
  capabilities read.

## Validation rules (summary)

The full table is [research R6](./research.md#r6-field-rules-port-of-legacy-mailboxaddedit-syncjob).
In short:

- **Required at creation**: `remoteHost`, `remotePort`, `remoteUser` and `remotePassword`, plus
  `mailbox` for administrators and domain administrators.
- **Ranges**: port 1–65535, interval 1–43800, age 0–32000, speed 0–125000000 and timeouts 1–32000.
  A value outside them gives `outOfRange {min, max}`, and nothing is clamped.
- **Lengths**: host, user, password and subfolder 255, `exclude` 500, `customParameters` 512.
- **Format rules**:
  - the host follows legacy `is_valid_domain_name` (`invalidFormat {format: hostName}`);
  - `exclude` must compile as PCRE between `/` delimiters (`invalidRegex`);
  - `customParameters` follow the legacy split and the allow-list without `showpasswords`
    (`optionNotAllowed`, `optionContainsSpace`), plus the administrator-only restriction
    (`customParamsNotPermitted`).
- **Edits**:
  - a different `mailbox` gives `immutable`;
  - `null` gives `invalidType`;
  - a member sent with its stored value is accepted unchanged;
  - an empty `remotePassword` keeps the stored password.
- **Uniqueness**: `(user2, user1, host1)` must be unique, otherwise `409 sync-job-exists`.

## State transitions

**Configuration** (this API, the legacy UI, `/api/v1`):

```
            create (active default true)
   (none) ───────────────────────────────► active ◄──── PATCH active=true / bulk activate ────┐
                                             │                                                │
                                             │ PATCH active=false / bulk deactivate           │
                                             ▼                                                │
                                          inactive ───────────────────────────────────────────┘
   active | inactive ── DELETE / bulk delete ──► (gone; the runner's later status write is a no-op)
```

**Run status** (the imapsync runner, every minute on the master node; the API only resets it):

```
 never run ──(runner selects: active=1, is_running=0, last_run IS NULL)──► running
 (last_run NULL,                                                           (is_running=1,
  success NULL)                                                             success NULL,
     ▲                                                                      exit_status NULL)
     │ reschedule (API): last_run=NULL, success=NULL                            │
     │                                                                          │ run ends
 succeeded / failed ◄───────────────────────────────────────────────────────────┘
 (last_run=NOW(), success=1|0, exit_status, returned_text)
     │
     └─(interval elapsed since last_run, active=1, is_running=0)──► running …
```

The `is_running` flag of a job left set by an interrupted run is reset by the runner itself (`UPDATE
imapsync SET is_running = 0` at runner start), never by this API.
