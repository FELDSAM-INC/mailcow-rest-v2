# Research: Sync Jobs

**Feature**: `specs/008-sync-jobs` | **Date**: 2026-10-18 | **Plan**: [plan.md](./plan.md)

This document settles only the decisions that feature 008 adds. Everything shared is decided in
feature 001 and reused unchanged: the Docker dev stack, Laravel 13 on PHP 8.4, the schema fixture
generated from upstream `init_db_schema()`, the single `mysql` connection, the `restv2_` table
prefix and `restv2:` Redis prefix, the request pipeline (R8 there), problem rendering, contract
validation of every test request and response, `ListQuery`, `ETag`, `AuditLogger`,
`IdentityService` and `MailcowConfig` (001 research R1–R29).

Decisions follow the owner's precedence: legacy behaviour first (constitution IV), then the
constitution and `docs/conventions.md`, then best practice. Legacy references are paths in upstream
mailcow-dockerized `2026-09a` (`git -C ~/Projects/mailhosting show 2026-09a:<path>`). The FeldHost
fork (`feldhost-202609a`) was diffed for every sync-job file (`functions.mailbox.inc.php`,
`json_api.php`, `vars.inc.php`, `imapsync_runner.pl`, `templates/edit/syncjob.twig`,
`inc/ajax/syncjob_logs.php`) and is identical, so nothing fork-specific needs a setting.

---

## R1. Resource paths and operations

- **Decision**: one flat collection, `/sync-jobs`, with nine operations in the module
  `api/modules/sync-jobs/`:

  | Method and path | operationId |
  |---|---|
  | GET `/sync-jobs` | listSyncJobs |
  | POST `/sync-jobs` | createSyncJob |
  | GET `/capabilities/sync-jobs` | getSyncJobsCapabilities |
  | POST `/sync-jobs/bulk-actions` | applySyncJobBulkAction |
  | GET `/sync-jobs/{syncJobId}` | getSyncJob |
  | PATCH `/sync-jobs/{syncJobId}` | updateSyncJob |
  | DELETE `/sync-jobs/{syncJobId}` | deleteSyncJob |
  | GET `/sync-jobs/{syncJobId}/log` | getSyncJobLog |
  | POST `/sync-jobs/{syncJobId}/schedule-reset` | rescheduleSyncJob |

  - The list spans the caller's scope and is narrowed to one mailbox with the `mailbox` filter.
  - The target mailbox is a body member at creation.
  - Activation and deactivation are `PATCH` of `active` (FR-016).
  - The run log is a sub-resource, so lists stay small (FR-015).
  - Rescheduling is the noun sub-resource `schedule-reset`, answered with `200` and the job.
- **Rationale**:
  - Sync jobs have a global integer identifier (`imapsync.id`). Legacy exposes both a global and a
    per-mailbox view (`get/syncjobs/all`, `get/syncjobs/{mailbox}`), and the administrator's main
    view is global. A flat collection with a filter serves both without depending on how feature
    003 shapes mailbox paths.
  - `DELETE /sync-jobs/{id}/last-run` would describe the reset well, but FR-016 requires the
    updated job in the response, and constitution II reserves `204` for deletes.
  - The bulk path follows the shape feature 002 uses (`/domains/bulk-actions`).
  - The capabilities read follows the project-wide pattern `/capabilities/{area}`
    (`docs/conventions.md`, "Capabilities, history and refusals"), with the operation ID
    `getSyncJobsCapabilities`.
- **Alternatives considered**:
  - Nesting under `/mailboxes/{mailbox}/sync-jobs` was rejected. It needs a second list route for
    the global view, and it couples this contract to 003's mailbox addressing.
  - `/sync-jobs/capabilities` was the first design. It was replaced by the project-wide
    `/capabilities/sync-jobs` (owner decision in `docs/conventions.md`).
  - `POST /sync-jobs/{id}/reschedule` was rejected: it is a verb. 001 uses nouns
    (`/revocation`).

## R2. Access: permission gate, scope and keys

- **Decision**: authorisation runs in this order. The first failure answers.
  1. **001's pipeline** (R8 there): `401`, CSRF `403`, `insufficient-key-scope` for a read-only key
     on any unsafe method, and `pending-action-required` for a restricted session. All nine routes
     use `restv2.auth:authenticated` and the default `restv2.pending:enforce`. Unsafe routes add
     `restv2.csrf`.
  2. **Permission gate**: `App\Policies\SyncJobPolicy::use(Principal)`. It is true when the
     effective flag `PermissionFlag::Syncjobs` from 001's `IdentityService` is set. That flag is
     always true for administrators (legacy `acl('to_session')`), comes from `da_acl.syncjobs` for
     domain administrators and from `user_acl.syncjobs` for mailbox users. A false gate answers
     `403 forbidden` on every operation, reads and the capabilities read included (FR-001).
  3. **Scope**: `App\Services\SyncJobs\SyncJobScope::apply(Builder, Principal)` restricts every
     query that loads jobs. It ports legacy `hasMailboxObjectAccess()` on `imapsync.user2`:

     ```sql
     FROM imapsync i JOIN mailbox m ON m.username = i.user2
     -- admin:        no further condition
     -- domain admin: AND m.domain IN (SELECT domain FROM domain_admins
     --                                WHERE username = ? AND active = '1')
     -- mailbox user: AND i.user2 = ?
     ```

     A job outside the scope behaves exactly like a missing one: `404 about:blank` for single
     operations and `notFound` for bulk items (FR-003).

  Key principals act as their owner (001 FR-033). Mailbox users cannot own keys, so the key types
  are administrator and domain administrator, each read-only or read-write.
- **Rationale**: This is legacy's object rule. For an administrator, `hasDomainAccess()` requires the
  mailbox's domain to exist, so the join gives the same result: legacy's global list also walks
  domains, then mailboxes. A job whose target mailbox no longer exists (possible only through manual
  database edits, because legacy and 003 delete jobs with their mailbox) stays invisible, as in
  legacy. Evaluating the gate before the scope means a caller without the flag learns nothing about
  which jobs exist.
- **Alternatives considered**: Letting administrators see orphan jobs (no join) was rejected. Legacy
  hides them, and the domain filter and the domain-administrator scope need the join anyway. Legacy
  reads without the flag check (`json_api.php` never checks `acl.syncjobs` on `get`), but the spec
  decides "forbidden" for every operation (FR-001 and its clarification).

## R3. Representation mapping

- **Decision**: the contract `SyncJob` maps the `imapsync` columns as follows (the full table is in
  [data-model.md](./data-model.md#synchronisation-job-imapsync)):
  - Names are camelCase with explicit units: `intervalMinutes`, `maxAgeDays`, `maxBytesPerSecond`,
    `remoteTimeoutSeconds` (`timeout1`) and `localTimeoutSeconds` (`timeout2`).
  - The booleans are `deleteFromSource` (`delete1`), `deleteFromDestination` (`delete2`),
    `deleteDuplicatesOnDestination`, `automap`, `skipCrossDuplicates`, `subscribeAll` and
    `dryRun`.
  - **Encryption** is `ssl | starttls | plain`, mapped to `SSL | TLS | PLAIN`. The runner turns `TLS`
    into `--tls1` (STARTTLS), and both legacy forms label `TLS` "STARTTLS". The enum
    `App\Services\SyncJobs\SyncJobEncryption` is backed by the stored value and offers
    `fromApi()` and `toApi()`.
  - `authMechanism` is the constant `plain` (FR-007). The runner never passes `authmech1` to
    imapsync, and legacy add always stores `PLAIN`.
  - `remotePasswordSet` is `password1 <> ''`. Legacy add does not refuse an empty password sent
    through `/api/v1`.
  - **Run status** (`runStatus`):
    - `lastRunAt` comes from `last_run`.
    - `lastResult` comes from `success`: 1 → `succeeded`, 0 → `failed`, NULL → `unknown`.
    - `exitStatus` is `exit_status`, but `null` whenever `lastResult` is `unknown`. Legacy's table
      blanks it in the same case, and a reschedule leaves the column untouched.
    - `running` comes from `is_running`.
    - `logAvailable` is `returned_text IS NOT NULL AND returned_text <> ''`.
  - **Times**: `created` (DATETIME) and `last_run` (TIMESTAMP, returned in the server time zone,
    because the connection leaves `time_zone` alone, 001 R5) are both local mailcow time. 001's
    `MailcowDateTime` cast reads them and emits UTC. `modified` (`ON UPDATE CURRENT_TIMESTAMP`)
    changes on every runner status write too. The contract states this, and the field is
    nullable.
  - **Never exposed**: `password1`, `returned_text` (only through the log read), `regextrans2`,
    `domain2` and `authmd51`. The model lists `password1` and `returned_text` in `$hidden`.
- **Rationale**: This follows constitution II (camelCase, booleans, units), FR-007, FR-008 and the
  legacy UI's own display rules (SC-001: what the API returns equals what the legacy UI shows).
- **Alternatives considered**: Exposing `TLS` as stored was rejected: every legacy screen calls it
  STARTTLS, and `tls` would be misread as implicit TLS. Upper-case enum values were rejected:
  001's enumerations are camelCase.

## R4. Version tag

- **Decision**: `App\Services\SyncJobs\SyncJobVersion::of(SyncJob)` passes 001's
  `App\Support\Http\ETag` the JSON encoding (`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`) of
  this ordered list. Integers are normalised with `(int)` and flags with `'0'` or `'1'`:

  `id`, `user2`, `host1`, `port1`, `enc1`, `user1`, `hash_hmac('sha256', password1, config('app.key'))`,
  `exclude`, `subfolder2`, `mins_interval`, `maxage`, `maxbytespersecond`, `timeout1`,
  `timeout2`, `delete1`, `delete2`, `delete2duplicates`, `automap`, `skipcrossduplicates`,
  `subscribeall`, `dry`, `custom_params`, `active`.

  The tag is returned as the `ETag` header of single reads and writes, and as `etag` on every
  representation, list entries included. `If-Match` follows 001: optional, `*` matches any existing
  job, and a mismatch answers `412`. Bulk items compare `version` with the tag without its
  surrounding quotes, and accept it with or without them.
- **Rationale**:
  - FR-020 covers the stored configuration, including the remote connection. A password changed in
    the legacy UI is a configuration change, so it must make an API editor's tag outdated.
  - The password enters only as an HMAC keyed with the application key. A plain hash inside a
    readable tag would let anyone who can read the job (a domain administrator, for example) test
    password guesses offline against the tag.
  - Run status, `modified`, `created` and the log stay out, because the runner rewrites them every
    cycle (FR-020, conventions "Version tags").
  - The unexposed columns `authmech1`, `regextrans2`, `domain2` and `authmd51` are left out. No
    legacy path changes them, and a tag covers what a client can see and change.
- **Alternatives considered**:
  - Using `modified` as the tag was rejected: every run changes it.
  - Leaving the password out entirely was rejected: concurrent password edits would then be lost
    silently.
  - A plain SHA-256 of the password was rejected because of the offline guessing risk.

## R5. Order of checks and edit semantics

- **Decision**:
  - **Order of checks** (the first failure answers):

    | Operation | Order |
    |---|---|
    | `createSyncJob` | gate `403`, target resolution `404` (R8), validation `422` (all members at once, `notEligible` included), duplicate `409`, insert `201` |
    | `updateSyncJob` | gate `403`, scope `404`, `If-Match` `412`, validation `422`, duplicate `409`, update `200` |
    | `deleteSyncJob` | gate `403`, scope `404`, `If-Match` `412`, delete `204` |
    | `rescheduleSyncJob` | gate `403`, scope `404`, update `200` |
    | bulk action | gate `403`, body `422`, then per item (R15) |

    The Form Requests implement this. `authorize()` runs the gate, resolves the job or target and
    checks `If-Match`, throwing `ProblemException`. `rules()` and an `after()` hook validate, and the
    service runs the duplicate guard.
  - **Merge patch** (RFC 7396):
    - Only sent members change.
    - `null` is refused for every member (`invalidType`), as 001 refuses it for preferences.
    - A body with none of the known members is refused (`required` on `body`).
    - Unknown and read-only members (`id`, `etag`, `runStatus`, `remotePasswordSet`,
      `authMechanism`, `createdAt`, `modifiedAt`) are ignored, so a client may send back what it
      read.
  - **Unchanged means accepted**: type checks always apply. Beyond them, a member is validated
    only when its value differs from the stored value (compared in contract form). A member sent
    unchanged, and every member not sent, is accepted as stored.
- **Rationale**:
  - `412` before `422`: field errors computed against a version the client has not seen would
    mislead it. A stale client should reload first.
  - Resolving the target before validating: an out-of-scope mailbox must reveal nothing, whatever
    else is wrong with the request (FR-003).
  - "Unchanged means accepted" applies FR-006's rule for custom parameters to every member. A
    client can send back a whole form even when a stored value predates today's rules. Examples are
    a host stored by legacy add, which does not validate it, or a custom option an operator later
    removed from the allow-list. Legacy edit re-validates every stored value, so a legacy job with
    an unvalidated host cannot even be deactivated there. The API lets it be deactivated and still
    refuses any new invalid value. This is recorded as a plan-level parity note in the spec.
- **Alternatives considered**:
  - Validating every sent member even when unchanged was rejected. It breaks whole-form
    round-trips and the activate and deactivate actions on legacy rows, and it contradicts FR-006's
    "unchanged" rule.
  - Treating `null` as "keep" was rejected: it is inconsistent with 001.

## R6. Field rules (port of legacy `mailbox('add'|'edit', 'syncjob')`)

- **Decision**: rule codes follow 001's `ApiFormRequest`.

  | Member | Rule | Code |
  |---|---|---|
  | `remoteHost` | required at creation; string 1–255; legacy `is_valid_domain_name()`: `idn_to_ascii(UTS46)`, then `^([a-z\d](-*[a-z\d])*)(\.([a-z\d](-*[a-z\d])*))*$`i, length 1–253 and labels 1–63. IPv4 literals pass this rule; IPv6 literals do not | `required`, `tooLong`, `invalidFormat {format: hostName}` |
  | `remotePort` | required at creation; integer 1–65535 | `outOfRange {min, max}` |
  | `remoteUser` | required at creation; string 1–255 | `required`, `tooLong` |
  | `remotePassword` | required at creation, 1–255; on edit `""` keeps the stored password, 1–255 replaces it | `required`, `tooLong` |
  | `encryption` | `ssl`, `starttls` or `plain` | `notInList {allowed}` |
  | `intervalMinutes` | 1–43800 | `outOfRange` |
  | `maxAgeDays` | 0–32000 | `outOfRange` |
  | `maxBytesPerSecond` | 0–125000000 | `outOfRange` |
  | `remoteTimeoutSeconds`, `localTimeoutSeconds` | 1–32000 | `outOfRange` |
  | `destinationSubfolder` | string 0–255 | `tooLong` |
  | `exclude` | string 0–500; `@preg_match('/' . $exclude . '/', '') !== false` | `tooLong`, `invalidRegex` |
  | `customParameters` | string 0–512; R7 | `tooLong`, `optionNotAllowed`, `optionContainsSpace`, `customParamsNotPermitted` |
  | booleans | JSON booleans only | `invalidType {expected: boolean}` |

  - Types are strict JSON types. A numeric string is `invalidType {expected: integer}`.
  - `remoteHost` is lower-cased at creation, as legacy add does (`strtolower`). On edit it is
    stored as sent, as legacy edit does.
  - Neither path trims host or user, as in legacy.
  - The host rule reuses feature 002's port of `is_valid_domain_name()`, which 002 needs for domain
    names. Only if 002 left none does this feature create `app/Support/Validation/LegacyDomainName.php`.
    Either way, `tests/Unit/SyncJobs/HostNameRuleTest.php` compares it with the extracted legacy
    function (R20).
- **Rationale**:
  - The ranges, lengths and checks are legacy's, from the code, the form `min`/`max` attributes and
    the column sizes.
  - Out-of-range values are refused instead of clamped (clarification 5), and the host is validated
    on creation too (clarification 4).
  - The regular-expression check keeps legacy's `/` delimiter, so legacy and the API accept exactly
    the same patterns. A literal `/` must be escaped (`\/`), and the contract says so.
  - The pattern is only compiled, never matched against input, so there is no ReDoS surface.
- **Alternatives considered**:
  - Validating `exclude` as a Perl regular expression (imapsync is Perl) was rejected. It would
    accept patterns that legacy refuses, and it needs a Perl runtime.
  - Accepting IPv6 literals was rejected: the spec's edge case keeps legacy's refusal.

## R7. Custom imapsync parameters

- **Decision**: `App\Services\SyncJobs\CustomParametersValidator::violations(string $value, ?string
  $stored, Principal $caller, bool $creating): list<FieldViolation>` works in four steps.
  1. **Restriction (FR-006)**: when `config('restv2.sync_jobs.custom_params_admin_only')` is true
     and the caller is not an administrator, a non-empty value at creation, or a value different
     from the stored one on edit, gives a single `customParamsNotPermitted` and no further checks.
  2. **Unchanged** (R5): a value equal to the stored one gives no violation.
  3. **Legacy split**: the value is trimmed (legacy add trims; legacy edit does not, and then
     refuses a leading space as an unknown option). Then each piece of `explode('-', $value)` is
     handled exactly as the legacy loop handles it:
     - pieces equal to `''` or `'0'` are skipped (PHP `empty()`);
     - otherwise the option name is the part before the first `=`, or the piece right-trimmed of
       spaces;
     - a name containing a space gives `optionContainsSpace {token: name}`;
     - a name whose lower case is not in the allow-list in effect gives `optionNotAllowed
       {option: name}`. An empty name gives `optionNotAllowed {option: ""}`, as in legacy.
  4. **All violations at once**: every violation is collected (deduplicated, in order), instead of
     stopping at the first like legacy. The stored value is the trimmed value.

  **Allow-list in effect**: `MailcowConfig::imapsyncOptions()['whitelist']` minus `showpasswords`
  (compared case-insensitively). Following R14 of 001, the source is chosen in this order:
  1. the server setting `RESTV2_IMAPSYNC_OPTIONS`, a JSON object `{"whitelist": [...],
     "blacklist": [...]}`;
  2. otherwise `$IMAPSYNC_OPTIONS` from the mounted `vars.inc.php` and `vars.local.inc.php`;
  3. otherwise upstream `2026-09a`'s list (114 entries, copied verbatim into `MailcowConfig`'s
     built-in defaults).

  `resources/bin/export-mailcow-vars.php` exports `IMAPSYNC_OPTIONS` alongside 001's variables.
  The blacklist is exported but not consulted, as in legacy: legacy only checks the whitelist, and
  its eight blacklisted options are absent from it.
- **Rationale**:
  - FR-019 asks for legacy's exact validation, and SC-004 for refusals that name the option. The
    split at `-` is kept even though it refuses values containing a hyphen (`--folder=a-b` yields
    the piece `b`): the runner splits the stored string itself (`qqw()` splits at `--` and at the
    first space), so a looser check could let through tokens that legacy never would.
  - `showpasswords` would make imapsync print the remote password into the log that every reader
    of the job can see (FR-019 hardening).
  - Reusing 001's configuration reader follows `docs/conventions.md` ("features reuse this reader;
    none writes its own").
- **Alternatives considered**:
  - Re-implementing the runner's `qqw()` split was rejected: the spec requires legacy's validation,
    not the runner's parsing.
  - Applying the blacklist as well was rejected: it would deviate from legacy without need.

## R8. Target mailbox resolution

- **Decision**: `App\Services\SyncJobs\SyncJobTargetResolver::resolve(?string $mailbox, Principal)`
  returns the canonical address or fails, in this order.
  1. **Mailbox user**: an absent `mailbox`, or one equal to their own address (case-insensitive),
     resolves to the principal's own username. Any other value answers `404`.
  2. **Administrator or domain administrator**:
     - an absent `mailbox` gives `422 required`;
     - a value that fails `FILTER_VALIDATE_EMAIL` or exceeds 255 characters gives `422
       invalidFormat {format: email}` or `tooLong`.
  3. `SELECT username, domain, kind FROM mailbox WHERE username = ?` (database collation, as legacy).
     When a row is found:
     - a domain outside the caller's scope answers `404`;
     - a non-empty `kind` (`location`, `thing` or `group`) gives `422 notEligible {kind: resource}`;
     - otherwise the result is the row's `username`.
  4. When no mailbox row exists:
     - an `alias` row with `address = ?` whose `domain` is in scope gives `notEligible {kind:
       alias}`;
     - otherwise, when the domain part is an `alias_domain.alias_domain` whose `target_domain` is in
       scope, it gives `notEligible {kind: aliasDomainAddress}`;
     - otherwise the answer is `404`.

  "In scope" uses R2: every domain for an administrator, the active assigned domains for a domain
  administrator. The job stores the resolved `mailbox.username`, not the typed string.
- **Rationale**:
  - FR-003 and US2 AS-9 make an unknown or foreign target "not found", and the spec's edge case
    makes a resource, an alias or an alias domain a validation error. A known alias outside the
    scope stays `404`, so the check reveals nothing.
  - Storing the canonical address keeps the mailbox user's scope condition (`user2 = username`)
    exact. That matters because legacy compares `$username == $object` case-sensitively.
- **Alternatives considered**:
  - Answering `422 notFound` for an unknown target was rejected: FR-003 asks for the same "not
    found" as an unknown job.
  - Storing the address as typed (legacy) was rejected. A mixed-case `user2` would hide the job from
    its own mailbox user in legacy too. This is recorded as a plan-level parity note.

## R9. Duplicate guard

- **Decision**: `App\Services\SyncJobs\SyncJobDuplicateGuard` serialises creations and identity
  edits per `(user2, user1, host1)` with a MariaDB named lock:

  ```
  name = 'restv2_syncjob_' . substr(sha1(mb_strtolower(user2."\0".user1."\0".host1)), 0, 40)
  SELECT GET_LOCK(name, 5)        -- 0 (timeout) → unexpected failure, 500
  BEGIN
    SELECT id FROM imapsync WHERE user2 = ? AND user1 = ? AND host1 = ? [AND id <> ?] LIMIT 1
      -- found → 409 sync-job-exists with syncJobId
    INSERT … / UPDATE …;  AuditLogger::record(…)
  COMMIT
  DO RELEASE_LOCK(name)           -- in finally
  ```

  On edit, the guard runs only when `remoteUser` or `remoteHost` changes. The comparison is legacy's
  SQL equality, so the column collation (case-insensitive) applies, as in legacy's `object_exists`
  check.
- **Rationale**:
  - The spec requires that exactly one of two concurrent identical creations succeeds. `imapsync`
    has no unique key, and upstream's schema must not change (constitution III).
  - Taking the lock before `BEGIN` makes the duplicate `SELECT` see the other request's committed
    row under `REPEATABLE READ`.
  - A named lock needs no table or Redis key, and it is released automatically if the connection
    dies.
  - Legacy writes are not serialised, as before (spec edge case "Concurrent edits from the legacy
    UI").
- **Alternatives considered**:
  - `SELECT … FOR UPDATE` was rejected. With no index on these columns it takes gap locks over the
    whole table scan, and it still lets two inserts race when no row matches.
  - A Redis lock (`Cache::lock`) was rejected: it works, but it ties correctness to a second store
    when the database itself offers the primitive.
  - A unique index was rejected: it would alter an upstream table.

## R10. Creation: defaults and row parity

- **Decision**:
  - **Defaults** for omitted members are clarification 8's values, in one place,
    `App\Services\SyncJobs\SyncJobDefaults`, which the capabilities read also uses: active, `ssl`,
    20 minutes, timeouts 600, age 0, speed 0, exclusion `(?i)spam|(?i)junk`, empty subfolder and
    parameters, subscribe-all, delete-duplicates and automap on, the rest off.
  - **Insert**: the service writes exactly legacy's column list: `user2`, `exclude`, `delete1`,
    `delete2`, `timeout1`, `timeout2`, `automap`, `skipcrossduplicates`, `maxbytespersecond` (as a
    decimal string, because the column is `VARCHAR(50)`), `subscribeall`, `dry`, `maxage`,
    `subfolder2`, `host1` (lower case), `authmech1 = 'PLAIN'`, `user1`, `password1`,
    `mins_interval`, `port1`, `enc1`, `delete2duplicates`, `custom_params` and `active`.
  - **Left to defaults**: every other column keeps its database default (`regextrans2 ''`,
    `authmd51 0`, `domain2 ''`, `is_running 0`, status columns `NULL`, `created NOW()`).
  - **No other side effects**: there are no SOGo, memcached, Redis or other-table effects. Legacy's
    closing `update_sogo_static_view` block skips the `syncjob` type.
- **Rationale**: SC-002 requires a row identical to the one the legacy modal produces, apart from
  the identifier and the timestamps. The runner then selects it the same way.

## R11. Edits: password, target and activation

- **Decision**:
  - `remotePassword`: `""` or omitted keeps `password1`, and any other value replaces it (FR-018).
    The audit records `remotePassword: "changed"` only when it was replaced with a different value.
  - `mailbox`: compared case-insensitively with `user2`. An equal value is accepted, and a
    different one gives `422 immutable` on `mailbox`, with nothing written (FR-016).
  - `active` is an ordinary member. Activating an active job is a successful no-op, which is still
    audited with empty `changes`.
  - The `UPDATE` touches only the configuration columns that changed. It never touches the
    run-status columns, so a run in progress is unaffected.
- **Rationale**: This is FR-016 and FR-018. Writing only the changed columns, instead of legacy's
  full-row `UPDATE`, avoids overwriting `last_run` and `success` that the runner may have written
  since the job was read. The legacy full-row update does exactly that.

## R12. Reschedule

- **Decision**: `UPDATE imapsync SET last_run = NULL, success = NULL WHERE id = ?`, in a transaction
  with its audit record. It needs no `If-Match` and checks none (FR-021). `exit_status` is left as
  legacy leaves it, and the representation reports `exitStatus: null` because `lastResult` is then
  `unknown` (R3). It is accepted while a run is in progress: the run finishes, writes `last_run =
  NOW()`, and the job runs again on the next cycle because the runner selects it when its interval
  has elapsed.
- **Rationale**: Legacy "Schedule next" sends `last_run` and `success` as empty, which `edit/syncjob`
  stores as NULL. The version tag does not include either column (R4), so it is unchanged, as
  FR-016 requires.

## R13. Deletion and the runner

- **Decision**: `DELETE FROM imapsync WHERE id = ?` with its audit record, after the `If-Match`
  check. A run in progress is not interrupted: the runner's final `UPDATE … WHERE id = ?` then
  matches no row and has no effect (US4 AS-3).
- **Rationale**: Legacy deletes the same way. Nothing else references `imapsync` rows.

## R14. Run log

- **Decision**: `GET /sync-jobs/{id}/log` reads `returned_text` for the one job in scope and
  returns `{syncJobId, runStatus, log}`. A NULL value becomes `""` (legacy shows `-`, the client
  decides). It is encoded with `JSON_INVALID_UTF8_SUBSTITUTE`, because imapsync output may contain
  bytes from remote folder names that are not valid UTF-8. The text is never truncated, and reads
  are not audited (`docs/conventions.md`). The log is never included in lists, and the list never
  selects `returned_text` (R19).
- **Rationale**: This is FR-009 and the spec's "Very large log" edge case. JSON keeps the
  project-wide `{data}` shape and lets contract validation cover the response. The log is the
  runner's text, so the contract tells clients to render it as plain text.
- **Alternatives considered**: `text/plain` was rejected: it breaks the single-resource shape and
  the response validation. Truncating to a tail was rejected: the spec returns the log as stored.

## R15. Bulk actions

- **Decision**:
  - **Request**: `{action, items[]}`. The items extend the shared `BulkTarget` with the integer
    pattern `^[1-9][0-9]{0,9}$`. At most `config('restv2.sync_jobs.bulk_limit')` items (default
    100) are allowed, counted as sent; more give `422 tooMany {max}` on `items`. Malformed items
    refuse the whole request with each bad entry named (`docs/conventions.md`).
  - **Processing**: `App\Services\SyncJobs\SyncJobBulkService` handles items in request order,
    skipping repeated `id`s, which keep the result of their first occurrence. Each item runs in its
    own transaction through the same service methods as the single operations, so it gets the same
    checks, side effects and audit record:
    1. load in scope (`notFound` otherwise);
    2. for `activate`, `deactivate` and `delete`, when `version` is present, compare it (without
       surrounding quotes) with the current tag (`outdated` otherwise);
    3. apply the action;
    4. record the audit entry with `details.bulkRequestId`.

    An unexpected exception becomes `failed` with an `about:blank 500` problem, and the loop goes
    on. `done` carries the new version tag without quotes, the form `BulkTarget.version` expects,
    except after `delete`.
  - **Response**: the shared `BulkResultResponse`. `bulkRequestId` is the request ID (001
    `AssignRequestId`).
  - **Whole-request refusals**: the gate (`403 forbidden`), 001's `insufficient-key-scope`, and
    body validation. There is no other.
  - **Shared plumbing**: if feature 002 (implemented earlier) created reusable bulk plumbing
    (building `BulkResult`, its summary and per-item problems), this feature uses it. Otherwise it
    creates `app/Support/Bulk/BulkResultBuilder.php`.
- **Rationale**: This follows FR-023, FR-024, `docs/conventions.md` and the shared components.
  Per-item transactions keep "one failure does not undo the others". SC-009 (100 jobs within 10 s)
  is trivially met by about 4 queries per item.

## R16. Audit

- **Decision**: records go through 001's `AuditLogger` in the transaction of the change. A refusal
  is recorded in its own transaction before the error response (001 R22).

  | Event type | When | `target` | `details` keys (whitelisted) |
  |---|---|---|---|
  | `syncJobCreation` | create succeeded or was refused | `{syncJob, id \| null, mailbox \| null}` | `mailbox`, `attributes` (every configuration member in contract form, without the password, plus `remotePasswordSet`) |
  | `syncJobUpdate` | `PATCH` or bulk `activate`/`deactivate` | `{syncJob, id, mailbox}` | `mailbox`, `changes` (`{member: {old, new}}`; `remotePassword: "changed"`), `bulkRequestId` |
  | `syncJobReschedule` | reschedule, single or bulk | same | `mailbox`, `cleared: [lastRunAt, lastResult]`, `previous: {lastRunAt, lastResult}`, `bulkRequestId` |
  | `syncJobDeletion` | delete, single or bulk | same | `mailbox`, `remoteHost`, `remoteUser`, `bulkRequestId` |

  - **Failures** have `outcome: failure`, a `failureReason` and the details `problemType`,
    `status`, `errors` (member → list of rule codes, never values), `mailbox` when known and
    `bulkRequestId`. The reasons map as follows:
    - missing flag (`forbidden`), read-only key (`insufficient-key-scope`) or restricted session
      (`pending-action-required`) → `permissionDenied`, told apart by `details.problemType`;
    - unknown or out of scope → `notFound`;
    - `412` or `outdated` → `versionOutdated`;
    - `422`, or a `400` malformed body → `validationFailed`;
    - `409` → `conflict`;
    - unexpected → `null`.

    The values from 002 (`versionOutdated`, `validationFailed`, `conflict`) are reused, and
    `permissionDenied` and `notFound` are appended to the shared `AuditFailureReason`.
  - **Recording refusals**: one mechanism records every refusal of the five state-changing
    operations (create, update, delete, reschedule, bulk), whether this feature's code raised it or
    001's pipeline did (key scope, pending actions).
    - `App\Services\Audit\RefusalAuditor` is called from 001's problem rendering in
      `bootstrap/app.php` for a `4xx` problem when three conditions hold: the matched route
      declares `->defaults('restv2.audit', ['event' => …, 'target' => 'syncJob'])`, the request
      has an authenticated principal, and the type is not `csrf-token-mismatch`.
    - A registered resolver for the target type `syncJob` supplies the target. For a route
      `{syncJob}` it looks up the ID and `user2`, regardless of scope, because the audit log is for
      administrators only. For a creation it uses the `mailbox` member as sent (truncated to 255),
      or the mailbox user's own address.
    - A whole-request bulk refusal gives one record with a `null` target and `details.itemIds`.
      Per-item outcomes are recorded by `SyncJobBulkService`.
    - If feature 002 already built such a hook, it is reused and only the `syncJob` resolver is
      registered.
  - **Not recorded**:
    - `401` requests have no principal. Recording them would let anonymous clients fill the log
      (001's Complexity Tracking).
    - CSRF failures can be triggered by any third-party site in a victim's browser, so recording
      them would let that site write audit rows in the victim's name at will. 001 does not audit
      them either.
    - Reads are not audited.
  - **History (FR-026)**: clients filter `GET /audit-events` with the generic `targetType=syncJob`
    and `targetId=<id>` parameters, which already exist (`docs/conventions.md`, "Capabilities,
    history and refusals"). This feature adds no audit filter of its own and no migration. The
    history of a mailbox's sync jobs is read per job, or through the mailbox's own history. The
    mailbox stays visible in `target.name` and `details.mailbox`, and searchable with `q`.
- **Rationale**: This is FR-025 ("every request … that changes state, or tries to") and FR-026,
  with the password only as "changed" (SC-007). A read-only key trying to delete a job is exactly
  what an administrator investigating misuse needs to see. That is why the pipeline refusals with a
  known principal are recorded, while the two unauthenticated or forgeable kinds are not. Values of
  refused input are never recorded, because a refused request may carry a password in any member.
  Putting the mailbox in `target.name` makes it visible in the audit list and searchable with 001's
  `q` immediately.
- **Alternatives considered**:
  - Separate `syncJobActivation` and `syncJobDeactivation` events were rejected. FR-016 defines both
    as ordinary edits, and `changes.active` shows them.
  - Recording refusals inside each Form Request was rejected: the pipeline's refusals (key scope,
    pending actions) never reach them.
  - Recording CSRF failures was rejected because of the forgery amplification described above.

## R17. Capabilities

- **Decision**: `App\Services\SyncJobs\SyncJobCapabilities::for(Principal)` returns:
  - `customParameters.allowed`: true unless the restriction applies to this caller;
  - `customParameters.restrictedToAdministrators`: the setting;
  - `customParameters.allowedOptions`: the allow-list in effect, as configured, in the server's
    order, without `showpasswords`;
  - `bulkLimit`;
  - `pageSize: {default: 25, maximum: 1000}`: 001's `ListQuery` constants, which the shared `Limit`
    parameter fixes;
  - the `limits` and `encryptions` of R6;
  - `defaults` from `SyncJobDefaults`.

  It is computed on every request, with no cache (`docs/conventions.md`). Read-only keys may use
  it.
- **Rationale**: This is FR-030, following 002's FR-064 pattern. The page sizes are part of the
  shared contract (`Limit` has `default: 25, maximum: 1000`), so they are reported, not configured.
- **Alternatives considered**: Making the page size an operator setting (the spec's assumptions
  listed it as changeable) was rejected. It would contradict the shared `Limit` parameter and
  `docs/conventions.md` ("the same for every list"), so the spec's assumption was aligned with the
  conventions during analysis.

## R18. Server settings added by this feature

| Variable | Default | Meaning |
|---|---|---|
| `RESTV2_IMAPSYNC_OPTIONS` | unset: the mounted `vars*.inc.php`, else the upstream `2026-09a` list | JSON `{"whitelist": [...], "blacklist": [...]}`, the legacy `$IMAPSYNC_OPTIONS` (R7). `showpasswords` is always removed |
| `RESTV2_SYNC_JOBS_CUSTOM_PARAMS_ADMIN_ONLY` | `n` | `y` restricts custom imapsync parameters to administrators (FR-006) |
| `RESTV2_SYNC_JOBS_BULK_LIMIT` | `100` | Maximum items per bulk action, 1–1000 (FR-023) |

They live in `config/restv2.php` under `sync_jobs`, with `IMAPSYNC_OPTIONS` added to
`vars_overrides`. The `y`/`n` form follows `mailcow.conf` (`SKIP_SOGO=y`).

## R19. List query and performance (SC-006)

- **Decision**: `App\Services\SyncJobs\SyncJobQuery` builds the list query:

  ```sql
  SELECT i.<every column except returned_text>
  FROM imapsync i JOIN mailbox m ON m.username = i.user2
  WHERE <scope R2>
    [AND i.user2 = ?]                         -- mailbox
    [AND m.domain = ?]                        -- domain
    [AND i.active = ?] [AND i.is_running = ?] -- active, running
    [AND i.success = 1 | = 0 | IS NULL]       -- lastResult
    [AND (i.user2 LIKE ? OR i.user1 LIKE ? OR i.host1 LIKE ?)]   -- q, escaped by ListQuery
  ORDER BY <sort column> <order>, i.id <order>
  LIMIT ? OFFSET ?
  ```

  - **Sort columns**: `id` → `i.id`, `mailbox` → `user2`, `remoteHost` → `host1`, `remoteUser` →
    `user1`, `intervalMinutes` → `mins_interval`, `active`, `lastRunAt` → `last_run`, `lastResult`
    → `success`, `createdAt` → `created`, `modifiedAt` → `modified`. In ascending order MariaDB puts
    NULL first, which gives the documented `unknown`, `failed`, `succeeded` and "never ran first".
  - **Count**: a `COUNT(*)` with the same `WHERE`.
  - **`logAvailable`**: computed by a second query over the page's IDs only (`SELECT id FROM
    imapsync WHERE id IN (…) AND returned_text IS NOT NULL AND returned_text <> ''`). A 5,000-row
    sort never touches the off-page `LONGTEXT` values.
  - **`password1`** is selected only to compute the tag, and never serialised (`$hidden`).
- **Rationale**: 5,000 jobs and a join on the `mailbox` primary key are cheap. The only expensive
  column is the log, which the list avoids. `tests/Feature/Performance/SyncJobListLatencyTest.php`
  verifies p95 < 1 s for 5,000 jobs across 100,000 mailboxes.

## R20. Test strategy specific to this feature

- **Runner simulator**: `tests/Support/SyncJobs/ImapsyncRunnerSimulator.php` runs the runner's own
  SQL, copied from `imapsync_runner.pl` at `2026-09a`:
  - `selectableIds()` runs the `SELECT … WHERE active = 1 AND is_running = 0 AND (… > mins_interval
    * 60 OR last_run IS NULL)`;
  - `startRun(id)` sets `is_running = 1, success = NULL, exit_status = NULL`;
  - `finishRun(id, stdout, success, exitStatus)` writes `returned_text`, `success` and
    `exit_status`, then `last_run = NOW(), is_running = 0`.

  Tests assert "the runner selects / no longer selects" (US3 AS-4 and AS-5, US4 AS-1) and "a final
  write for a deleted job has no effect" (US4 AS-3) against the real schema.
- **Legacy oracles**: `scripts/legacy/extract-syncjob-validation.sh <tag>` writes
  `tests/fixtures/legacy/syncjob_validation.php` with a header (tag, source SHA-256, GPL-3.0
  notice). It contains:
  - `legacy_syncjob_custom_params_ok(string $custom_params, array $whitelist): bool`: the add-path
    `// validate custom params` block copied verbatim from `functions.mailbox.inc.php` and wrapped
    so that its `return false` becomes the function's result;
  - `legacy_syncjob_is_valid_domain_name()`: copied from `functions.inc.php`.

  Unit tests compare the API's validators with these oracles over a corpus of inputs. The only
  expected difference is `showpasswords`. This mirrors 001's `verify_hash` extraction (001 R12).
- **Row parity**: `tests/Feature/SyncJobs/CreateSyncJobRowParityTest.php` asserts every column of the
  inserted row against the row legacy's `INSERT` produces for the same input, as derived from the
  legacy code. The other columns must hold their database defaults.
- **Concurrency**: `tests/Feature/SyncJobs/ConcurrentCreateSyncJobTest.php` (group `concurrency`)
  works without `DatabaseTransactions` and deletes its own rows. It starts two
  `Symfony\Component\Process\Process` runs of `tests/bin/create-sync-job-once.php`. Each boots the
  kernel and handles the same create request with an administrator key. The test asserts one `201`,
  one `409` and one row.
- **Contract coverage**: the nine operations join 001's coverage check.
- **Authorisation matrix**: `tests/Feature/Security/SyncJobAuthorizationMatrixTest.php` covers the
  nine operations against these callers:
  - anonymous;
  - restricted session;
  - admin, domain admin with and without `syncjobs`, and mailbox user with and without `syncjobs`
    (sessions);
  - admin keys, read-only and read-write;
  - domain-admin keys, read-only and read-write, with and without `syncjobs`.

  It also covers foreign-domain and foreign-mailbox objects.

## R21. Dependencies on earlier features

Features are implemented in number order, so the following already exist when 008 starts.

- **001** (required, foundational):
  - `ProblemType`, `ProblemException`, `ApiFormRequest` (rule codes), `ListQuery`, `ETag`;
  - `AuditLogger` and `AuditEvent`, `Principal`, `Role`, `PermissionFlag`, `IdentityService`
    (scope and flags);
  - the middleware `RequireAuth`, `EnforceKeyScope`, `VerifyCsrfToken` and
    `EnforcePendingActions`;
  - `MailcowConfig` and `resources/bin/export-mailcow-vars.php`, `MailcowDateTime`;
  - `MailcowFactory`, `OpenApiContract`, `ContractCoverageExtension` and `TestCase` (`signInAs`,
    keys);
  - the `Mailbox`, `Domain` and `DomainAdminAssignment` models.
- **002**:
  - the generic `targetType` and `targetId` filters of `listAuditEvents`, which US6 uses;
  - the port of `is_valid_domain_name()` (R6);
  - the bulk-result plumbing (R15);
  - the shared `AuditFailureReason` values `versionOutdated`, `validationFailed` and `conflict`.
- **003**:
  - the full `Mailbox` model, including `kind`;
  - the rename and delete cascades that move or remove `imapsync` rows (this feature does not
    duplicate them);
  - the `user_acl.syncjobs` edits.

  Tests seed flags directly through `MailcowFactory`.
- **004**: the read models of `alias` and `alias_domain` (`App\Models\Mailcow\Alias`,
  `AliasDomain`) and their `MailcowFactory` helpers, which R8 uses. If a helper is missing, this
  feature adds it.
- **007**: `da_acl.syncjobs` edits. Tests seed it through `MailcowFactory::domainAdmin(daAcl: …)`.

When a named shared piece from 002–004 does not exist under the expected name, the task says to
search `app/` for the legacy function it ports before creating a new one ("implemented once and
reused", `docs/conventions.md`).

## R22. Dependencies (constitution VIII)

No new Composer or npm dependency. The feature uses:

- the `intl` extension (`idn_to_ascii`), already in 001's image;
- PCRE;
- MariaDB `GET_LOCK()`;
- Laravel's query builder and Form Requests;
- `Symfony\Component\Process`, already present, in one test.

## R23. Problem types and rule codes

- **Decision**:
  - **New problem type**: `/api/v2/problems/sync-job-exists` (`409`, extension `syncJobId`), added
    to `KnownProblemType`, `docs/problems.md` and 001's `ProblemType` enum.
  - **Reused problem types**: `forbidden`, `insufficient-key-scope`, `validation-failed`, and
    `about:blank` for `404` and `412`.
  - **New rule codes**, appended to `ValidationRuleCode`: `optionNotAllowed`,
    `optionContainsSpace`, `customParamsNotPermitted`, `invalidRegex` and `immutable`.
  - **Reused rule codes with new params**: `invalidFormat` with `format: hostName` (and `email`) and
    `notEligible` with `kind`.
  - **Shared enumerations**: `syncJob` is appended to `AuditTarget.type`, the four event types to
    `AuditEventType`, and `permissionDenied` and `notFound` to `AuditFailureReason`.
- **Rationale**: FR-027 requires a documented type for "already exists" that clients act on (they
  can link to `syncJobId`). The other errors already have 001 types or are `about:blank` by
  convention (`docs/problems.md`).
