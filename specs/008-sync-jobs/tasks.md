---
description: "Task list for feature 008: sync jobs"
---

# Tasks: Sync Jobs

**Input**: Design documents from `specs/008-sync-jobs/`: [plan.md](./plan.md), [spec.md](./spec.md),
[research.md](./research.md), [data-model.md](./data-model.md), [quickstart.md](./quickstart.md),
[contracts/README.md](./contracts/README.md). The contract is `api/modules/sync-jobs/` (already
authored and lint-clean). The problem type `sync-job-exists` is in `docs/problems.md`.

**Tests**: These are mandatory (constitution VII). In every phase the test tasks come first and
must be observed **failing** before the implementation tasks of that phase start. Every feature
test runs on MariaDB with the upstream `2026-09a` schema fixture and on a real Redis, and
`tests/TestCase.php` (001) validates every request and response against the contract.

**Dependencies on earlier features**: features are implemented in number order, so the following
already exist when this list starts.

- **001**: the whole foundation:
  - `ProblemType`, `ProblemException`, `ApiFormRequest`, `ListQuery`, `ETag`;
  - `AuditLogger`, `AuditEvent` and the PHP `AuditEventType` enum;
  - `Principal`, `Role`, `PermissionFlag` and `IdentityService`;
  - the middleware `RequireAuth`, `EnforceKeyScope`, `VerifyCsrfToken` and
    `EnforcePendingActions`;
  - `MailcowConfig` with `resources/bin/export-mailcow-vars.php`, and `MailcowDateTime`;
  - the models `Mailbox`, `Domain` and `DomainAdminAssignment`;
  - `MailcowFactory`, `OpenApiContract`, `ContractCoverageExtension` and `TestCase` (`signInAs`,
    key helpers).
- **002**:
  - the generic `targetType` and `targetId` filters of `listAuditEvents`, which US6 uses;
  - its port of legacy `is_valid_domain_name()`;
  - its bulk-result plumbing;
  - the audit failure reasons `versionOutdated`, `validationFailed` and `conflict`.
- **003**: the complete `Mailbox` model (`kind`), and the rename and delete cascades of `imapsync`
  rows (not duplicated here).
- **004**: the read models `App\Models\Mailcow\Alias` and `AliasDomain`, and their
  `MailcowFactory` helpers.
- **007**: `da_acl` editing. Tests seed `da_acl.syncjobs` directly.

Where a task says "reuse X from 00N", search `app/` for the legacy function or shared component
first. Create a new one only when none exists (`docs/conventions.md`: implemented once and reused).

**Commands**: run every PHP, Composer and Node command in the dev container:
`docker compose run --rm app <command>`.

## Format: `- [ ] [ID] [P?] [Story?] Description with file path`

- **[P]**: can run in parallel with the other [P] tasks of the same block (different files, no
  dependency on unfinished tasks).
- **[USn]**: the user story of `spec.md`. Setup, Foundational and Polish tasks have no story label.
- FR, SC and AS refer to `spec.md`. R refers to `research.md`.

---

## Phase 1: Setup

**Purpose**: Settings, legacy oracles and fixtures. No business logic.

- [ ] T001 Check the prerequisites and record the names you find in the PR description. Search
  `app/` for each of the following:
  - 002's port of `is_valid_domain_name`;
  - 002's bulk-result plumbing (a class building `BulkResult`);
  - the 004 models `App\Models\Mailcow\Alias` and `App\Models\Mailcow\AliasDomain`;
  - the `MailcowFactory` helpers `alias()` and `aliasDomain()`;
  - the generic `targetType` and `targetId` parameters in `api/modules/audit/audit-events.yaml`
    (they must exist; this feature adds no audit filter).

  Also search for a refusal-audit hook 002 may have built (a class recording audit records for
  refused requests from the problem rendering in `bootstrap/app.php`). Record the names you find in
  a short "Resolved shared components" note at the end of this file, so later tasks use them. Then
  run `composer contract:lint`. It must report no problem in `api/modules/sync-jobs/`.
- [ ] T002 Add the sync-job settings to `config/restv2.php` (R18):
  - `'sync_jobs' => ['custom_params_admin_only' => env('RESTV2_SYNC_JOBS_CUSTOM_PARAMS_ADMIN_ONLY', 'n') === 'y', 'bulk_limit' => max(1, min(1000, (int) env('RESTV2_SYNC_JOBS_BULK_LIMIT', 100)))]`;
  - `'IMAPSYNC_OPTIONS' => 'RESTV2_IMAPSYNC_OPTIONS'` in `vars_overrides`.

  Document the three variables in `.env.example`, commented out.
- [ ] T003 [P] Create `scripts/legacy/extract-syncjob-validation.sh <tag>`, modelled on 001's
  `scripts/legacy/extract-verify-hash.sh` (R20). It works from two upstream files:
  - from `git show <tag>:data/web/inc/functions.mailbox.inc.php`: the first block from the line
    `// validate custom params` to the line before `if (empty($subfolder2)) {` (the add path). It
    wraps the block in `function legacy_syncjob_custom_params_ok(string $custom_params, array
    $whitelist): bool`, which sets `$GLOBALS['IMAPSYNC_OPTIONS']['whitelist'] = $whitelist`, sets
    `$_SESSION['return'] = []` and `$_action = $_type = $_data_log = $_attr = null`, runs the block
    and returns `true`. It installs a no-op error handler around the block and restores it
    afterwards: PHP 8 warns on `$param[0]` for an empty option name, and Laravel's test error
    handler would turn that warning into an exception;
  - from `functions.inc.php`: `is_valid_domain_name()`, renamed
    `legacy_syncjob_is_valid_domain_name()`.

  It writes both to `tests/fixtures/legacy/syncjob_validation.php` with a header (tag, SHA-256 of
  both source files, GPL-3.0 notice). Run it for `2026-09a` and commit the output. The script
  refuses non-tag refs.
- [ ] T004 [P] Create `tests/fixtures/mailcow-inc-imapsync/vars.inc.php` (a copy of 001's
  `tests/fixtures/mailcow-inc/vars.inc.php`) and `tests/fixtures/mailcow-inc-imapsync/vars.local.inc.php`.
  The local file sets `$IMAPSYNC_OPTIONS['whitelist'] = ['maxsize', 'minage', 'showpasswords',
  'MixedCase']`, used by T007.

**Checkpoint**: settings exist, the oracle fixture is generated, and the contract lints.

---

## Phase 2: Foundational (blocking prerequisites)

**Purpose**: The model, the run-status mapping, the version tag, the scope, the permission gate,
the representation, the audit wiring, the configuration reader extension and the test toolkit.
**No user story can start before this phase is complete.**

### Tests for the foundation (write first, observe failing) ⚠️

- [ ] T005 [P] Write `tests/Feature/Schema/ImapsyncColumnsTest.php`. It asserts that `SHOW COLUMNS
  FROM imapsync` in the loaded fixture equals exactly the 34 columns of data-model.md, with their
  types (for example `maxbytespersecond` "VARCHAR(50) NOT NULL DEFAULT '0'", `enc1`
  "ENUM('TLS','SSL','PLAIN') DEFAULT 'TLS'", `last_run` "TIMESTAMP NULL DEFAULT NULL"). It also
  asserts that the only index is `PRIMARY (id)`. The test fails when an upstream release changes the
  table, so that the model and contract get updated deliberately (constitution III).
- [ ] T006 [P] Create `tests/Support/SyncJobs/ImapsyncRunnerSimulator.php` (R20). It runs the
  runner's SQL, copied from `data/Dockerfiles/dovecot/imapsync_runner.pl` at `2026-09a` with a
  comment naming the source:
  - `selectableIds(): list<int>` runs `SELECT id FROM imapsync WHERE active = 1 AND is_running = 0
    AND (UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(last_run) > mins_interval * 60 OR last_run IS NULL)
    ORDER BY last_run`;
  - `startRun(int $id)` runs `UPDATE imapsync SET is_running = 1, success = NULL, exit_status =
    NULL WHERE id = ?`;
  - `finishRun(int $id, string $stdout, int $success, ?string $exitStatus): int` runs the two
    final `UPDATE`s and returns the affected rows of the last one;
  - `restart()` runs `UPDATE imapsync SET is_running = 0`.

  Add `syncJob(array $overrides = [])` to `database/factories/MailcowRows.php`, and expose it
  through `tests/Support/Mailcow/MailcowFactory.php`. It inserts an `imapsync` row exactly as legacy
  add does: the insert column list of data-model.md, `authmech1 = 'PLAIN'`, the defaults of
  clarification 8 and `password1 = 'remote-secret'` unless overridden. Status columns can be set
  through the overrides. If 004 left no `alias()` or `aliasDomain()` helper, add them here (rows in
  `alias` and `alias_domain` as legacy writes them). Write
  `tests/Unit/Support/ImapsyncRunnerSimulatorTest.php` covering never run, run, finish, interval not
  elapsed, inactive and running.
- [ ] T007 [P] Write `tests/Unit/Services/MailcowConfigImapsyncOptionsTest.php` (R7, R18):
  - with 001's `tests/fixtures/mailcow-inc`, `imapsyncOptions()['whitelist']` equals upstream
    `2026-09a`'s 114 entries in order, and `blacklist` its 8 entries;
  - with `tests/fixtures/mailcow-inc-imapsync`, the local override applies;
  - `RESTV2_IMAPSYNC_OPTIONS='{"whitelist":["maxsize"],"blacklist":[]}'` beats the files;
  - with the directory missing, the built-in upstream default applies;
  - malformed `RESTV2_IMAPSYNC_OPTIONS` JSON falls back to the files and logs one warning.
- [ ] T008 [P] Write `tests/Unit/SyncJobs/SyncJobEncryptionTest.php` and
  `tests/Unit/SyncJobs/SyncJobLastResultTest.php`:
  - `SSL` ↔ `ssl`, `TLS` ↔ `starttls`, `PLAIN` ↔ `plain`, and `fromApi('tls')` throws;
  - `success` 1 → `succeeded`, 0 → `failed`, NULL → `unknown`.
- [ ] T009 [P] Write `tests/Unit/SyncJobs/SyncJobVersionTest.php` (R4, FR-020):
  - the tag is a quoted strong ETag;
  - changing any one of the 23 input columns changes it, including `password1` and `user2`;
  - changing `is_running`, `last_run`, `success`, `exit_status`, `returned_text`, `modified`,
    `created`, `authmech1`, `regextrans2`, `domain2` or `authmd51` does not;
  - `maxbytespersecond` `'0'` and `0` give the same tag;
  - the password's plain SHA-256 never appears in the tag input (the HMAC with `config('app.key')`
    does);
  - two different application keys give different tags for the same row.
- [ ] T010 [P] Write `tests/Feature/SyncJobs/SyncJobScopeTest.php` (R2, FR-002). Seed jobs on
  mailboxes of `example.org`, `example.net` and `example.com`, a job whose `user2` has no `mailbox`
  row (orphan), and a job on a resource mailbox. Using `SyncJobScope` directly:
  - an admin sees every job except the orphan;
  - a domain admin assigned `example.org` (active) and `example.net` (`domain_admins.active = 0`)
    sees only `example.org`;
  - a mailbox user sees only jobs with `user2` equal to their username;
  - a domain admin without domains sees nothing.
- [ ] T011 [P] Write `tests/Feature/SyncJobs/SyncJobPolicyTest.php` (FR-001):
  - an admin always passes;
  - a domain admin passes with `da_acl.syncjobs = 1` and fails with 0;
  - a domain admin without a `da_acl` row fails (001 R20);
  - a mailbox user passes with `user_acl.syncjobs = 1` and fails with 0 or without a row;
  - a key principal is evaluated through its owner;
  - a flag change in the database takes effect on the next request (no caching).
- [ ] T012 [P] Write `tests/Feature/SyncJobs/SyncJobResourceTest.php` (R3, FR-007, FR-008, FR-010). A seeded
  row is rendered as `SyncJob`, with each field per data-model.md:
  - `authMechanism` is `plain`;
  - `remotePasswordSet` is false for `password1 = ''`;
  - `exitStatus` is `null` when `success` is NULL even if `exit_status` is set;
  - `lastRunAt` and `createdAt` are UTC with `TZ=Europe/Prague`, and `modifiedAt` is `null` for a
    never-modified row;
  - the output has no `password1` or `returned_text` key and no `password` substring in any key.

  The JSON validates against `api/components/schemas/SyncJob.yaml`.

### Implementation of the foundation

- [ ] T013 Extend 001's configuration reader (R7):
  - `resources/bin/export-mailcow-vars.php` also exports `IMAPSYNC_OPTIONS`;
  - `app/Services/Config/MailcowConfig.php` gets `imapsyncOptions(): array{whitelist: list<string>,
    blacklist: list<string>}` with the precedence `RESTV2_IMAPSYNC_OPTIONS` → files → built-in
    default;
  - the built-in default is upstream `2026-09a`'s `$IMAPSYNC_OPTIONS`, copied verbatim (114
    whitelist and 8 blacklist entries) into the reader's defaults map, with a comment naming the
    source.

  This makes T007 pass.
- [ ] T014 [P] (FR-027) Add the case `SyncJobExists` (`/api/v2/problems/sync-job-exists`, 409, "Sync job
  already exists") to `app/Support/Http/ProblemType.php`. Add `SyncJobCreation`, `SyncJobUpdate`,
  `SyncJobReschedule` and `SyncJobDeletion` to the PHP `AuditEventType` enum (001), and
  `PermissionDenied` and `NotFound` to its failure-reason enum, if 001 keeps one. 001's
  `ProblemTypeConsistencyTest` must stay green.
- [ ] T015 [P] Create the enums in `app/Services/SyncJobs/` and make T008 pass:
  - `SyncJobEncryption.php`: string-backed `Ssl = 'SSL'`, `Starttls = 'TLS'`, `Plain = 'PLAIN'`,
    with `fromApi()` and `toApi()`;
  - `SyncJobLastResult.php`: `fromStored(?int)`;
  - `SyncJobBulkAction.php`;
  - `SyncJobDefaults.php`: one constant map of clarification 8: `encryption ssl`,
    `intervalMinutes 20`, `maxAgeDays 0`, `maxBytesPerSecond 0`, `remoteTimeoutSeconds 600`,
    `localTimeoutSeconds 600`, `exclude '(?i)spam|(?i)junk'`, `destinationSubfolder ''`,
    `customParameters ''`, `active true`, `subscribeAll true`,
    `deleteDuplicatesOnDestination true`, `automap true`, and the rest `false`;
  - `SyncJobLimits.php`: the ranges "port 1–65535, interval 1–43800, age 0–32000, speed
    0–125000000, timeouts 1–32000" and the lengths "host, user, password and subfolder 255,
    exclude 500, customParameters 512".
- [ ] T016 Create `app/Models/Mailcow/SyncJob.php` (data-model.md):
  - `$connection = 'mysql'`, `$table = 'imapsync'`, `$primaryKey = 'id'`;
  - `$timestamps = false`, `$guarded = ['*']`;
  - `$hidden = ['password1', 'returned_text']`;
  - casts: `enc1` → `SyncJobEncryption`; the flags → `boolean`; `maxbytespersecond`, `maxage`,
    `mins_interval`, `port1`, `timeout1` and `timeout2` → `integer`; `created`, `modified` and
    `last_run` → `MailcowDateTime`;
  - the relation `mailbox()` → `belongsTo(Mailbox::class, 'user2', 'username')`.

  Create `app/Services/SyncJobs/SyncJobVersion.php` (R4), which makes T009 pass.
- [ ] T017 Create `app/Services/SyncJobs/SyncJobScope.php` (R2): `apply(Builder $query, Principal
  $p): Builder`. It joins `mailbox m ON m.username = imapsync.user2`, adds no condition for an
  admin, adds `m.domain IN (SELECT domain FROM domain_admins WHERE username = ? AND active = '1')`
  for a domain admin and `imapsync.user2 = ?` for a mailbox user. Also create
  `findInScope(int $id, Principal $p): ?SyncJob` and `findInScopeForUpdate()` (`lockForUpdate`).
  This makes T010 pass.
- [ ] T018 Create `app/Policies/SyncJobPolicy.php` with `use(Principal): bool`, true when
  `IdentityService` reports `PermissionFlag::Syncjobs`. Register it in
  `app/Providers/AppServiceProvider.php` as the gate `syncJobs.use`. A failing gate throws
  `ProblemException(ProblemType::Forbidden)`. This makes T011 pass.
- [ ] T019 Create `app/Http/Resources/Api/V2/SyncJobResource.php` (R3). It renders the contract
  `SyncJob` from a model plus an optional `logAvailable` value and the tag from `SyncJobVersion`,
  and never touches `password1` beyond `remotePasswordSet`. This makes T012 pass.
- [ ] T020 Create `app/Services/SyncJobs/SyncJobAuditDetails.php` (R16):
  - `attributes(SyncJob)` and `changes(array $before, array $after, bool $passwordReplaced)`, in
    contract member names, with `remotePassword: "changed"` only;
  - `refusal(ProblemType|int $status, array $errors)`, which keeps rule codes per member and drops
    values.

  Register the per-event `details` whitelists of data-model.md for the four event types in 001's
  `app/Services/Audit/AuditLogger.php`. Write
  `tests/Unit/SyncJobs/SyncJobAuditDetailsTest.php` asserting that no output contains a password
  value, whatever the input.
- [ ] T021 Create the refusal recording of R16 (reuse 002's hook if T001 found one, and add only
  the resolver):
  - `app/Services/Audit/RefusalAuditor.php`: `record(Request, Problem): void` writes one failure
    record in its own transaction. It runs only when the matched route has the default
    `restv2.audit` (`['event' => AuditEventType, 'target' => string]`), the request has an
    authenticated principal, the status is 4xx and the type is not `csrf-token-mismatch`. It maps
    reasons as in R16 and calls the resolver registered for the target type.
  - Wire it into 001's problem rendering in `bootstrap/app.php`.
  - `app/Services/SyncJobs/SyncJobAuditTargetResolver.php`, registered for `syncJob`:
    - for a route `{syncJob}`: the ID and `imapsync.user2`, looked up without scope;
    - for `POST sync-jobs`: the `mailbox` member truncated to 255, or the mailbox user's own
      address;
    - for `POST sync-jobs/bulk-actions`: a `null` target with `details.itemIds` (at most the bulk
      limit, IDs only), and `details.bulkAction`. The event type is derived from the body's
      `action` when the route declares `'event' => 'fromBulkAction'`.

  Write `tests/Feature/Audit/RefusalAuditorTest.php` against a test-only route under
  `/api/v2/__testing/` that declares `restv2.audit`:
  - `forbidden`, `insufficient-key-scope`, `pending-action-required`, `404`, `409`, `412`, `422`
    and a malformed-JSON `400` each write exactly one failure record with the mapped reason and
    `details.problemType`;
  - `csrf-token-mismatch` and `401` write none;
  - a route without `restv2.audit` writes none;
  - no record contains request body values.

**Checkpoint**: the foundation tests (T005–T012) pass. User stories can start.

---

## Phase 3: User Story 1: List, inspect sync jobs and read their run status and logs (P1) 🎯 MVP

**Goal**: Every role with the sync-jobs permission lists and inspects the jobs in its scope, with
run status, and reads a job's log. The remote password never appears.

**Independent test**: Seed jobs in every run state across several domains. List and inspect them
as each role, with and without the flag, and as each key type. Compare with the legacy table's
values for the same rows (spec US1, Independent Test).

### Tests for User Story 1 (write first, observe failing) ⚠️

- [ ] T022 [P] [US1] Write `tests/Feature/SyncJobs/ListSyncJobsTest.php` (FR-011–FR-015, AS-1,
  AS-2, AS-5, AS-8):
  - **Scope**:
    - a user's 3 jobs are returned with remote server, interval, active, run status and no
      password (AS-1);
    - an admin sees 25 of 40 jobs across 10 mailboxes in 5 domains, sorted by `id`, with
      `meta.total = 40` (AS-2);
    - a domain admin of 2 of 10 domains sees only those (AS-5);
    - a read-only key of that domain admin sees the same set (AS-8).
  - **Paging**: `limit` and `offset`; `limit=1001` → `422 outOfRange`.
  - **Sorting**: every `sort` value, ascending and descending. Ties are broken by `id` in the same
    direction. Ascending `lastRunAt` puts never-run jobs first. Ascending `lastResult` gives
    `unknown`, `failed`, `succeeded`. An unknown `sort` → `422 notInList`.
  - **Filters**:
    - `mailbox` (exact, case-insensitive);
    - `domain`;
    - `active=true|false`;
    - `running=true|false`;
    - `lastResult=succeeded|failed|unknown`;
    - a `mailbox` outside the scope → empty list, not `404`.
  - **Search**: `q` matches `user2`, `user1` and `host1` as substrings, case-insensitively, with
    `%` and `_` escaped.
  - **Entries**:
    - every entry's `etag` equals the `ETag` header of `GET /sync-jobs/{id}`;
    - `logAvailable` is correct for a job with a log, an empty log and NULL;
    - the response body never contains `password1`, the seeded passwords or `returned_text`
      content.
- [ ] T023 [P] [US1] Write `tests/Feature/SyncJobs/GetSyncJobTest.php` (FR-007, FR-008, AS-3, AS-4):
  - a job whose runner finished with `success = 0` and `EXIT_AUTHENTICATION_FAILURE_USER1` (through
    `ImapsyncRunnerSimulator`) shows `lastResult failed`, `exitStatus
    EXIT_AUTHENTICATION_FAILURE_USER1` and `logAvailable true` (AS-3);
  - a never-run job shows `lastRunAt null`, `lastResult unknown` and `running false` (AS-4);
  - a running job shows `running true` and `lastResult unknown`;
  - the `ETag` header equals `data.etag`;
  - a domain admin requesting a foreign-domain job gets `404 about:blank`, identical in status,
    type and body shape to a non-existent ID (AS-5);
  - a non-numeric ID → `404` (route constraint).
- [ ] T024 [P] [US1] Write `tests/Feature/SyncJobs/SyncJobLogTest.php` (FR-009, R14):
  - the stored `returned_text` is returned unchanged in `data.log`, with `runStatus`;
  - NULL → `""`;
  - a log containing invalid UTF-8 bytes is returned with U+FFFD and valid JSON;
  - a 5 MB log is returned whole;
  - a foreign job → `404`;
  - no flag → `403`;
  - reading writes no audit record.
- [ ] T025 [P] [US1] Write `tests/Feature/SyncJobs/SyncJobReadAccessTest.php` (FR-001, FR-003,
  FR-004, AS-6, AS-7). For `listSyncJobs`, `getSyncJob` and `getSyncJobLog`:
  - a domain admin without `da_acl.syncjobs` → `403 forbidden` (AS-6);
  - a mailbox user without `user_acl.syncjobs` → `403 forbidden` (AS-7), also for a job in their
    own mailbox;
  - anonymous → `401 unauthenticated`;
  - a restricted session (`force_pw_update`) → `403 pending-action-required`;
  - admin read-only and read-write keys → `200`;
  - a domain-admin key whose owner lacks the flag → `403 forbidden`.

### Implementation for User Story 1

- [ ] T026 [US1] Create `app/Services/SyncJobs/SyncJobQuery.php` (R19). It provides:
  - `list(Principal, ListParams): array{items, total}`, which applies `SyncJobScope`, the filters
    `mailbox` (`user2 = ?`), `domain` (`m.domain = ?`), `active`, `running` and `lastResult` (`=
    1`, `= 0`, `IS NULL`), and `q` over `user2`, `user1` and `host1` through 001's `ListQuery`;
  - the sort map of data-model.md, then `id` in the same direction;
  - selection of every column except `returned_text`;
  - `COUNT(*)` with the same conditions;
  - `logAvailability(list<int> $ids): array<int, bool>`, which runs one query over the page's IDs
    (`returned_text IS NOT NULL AND returned_text <> ''`);
  - `findWithLog(int $id, Principal)`.
- [ ] T027 [P] [US1] Create `app/Http/Requests/Api/V2/ListSyncJobsRequest.php` on 001's
  `ApiFormRequest` and `ListQuery`. It accepts `sort` from the `SyncJobSort` enum (default `id`),
  `order` (default `asc`), `mailbox` and `domain` (strings ≤ 255), `active` and `running`
  (`true`/`false`) and `lastResult` (enum). `authorize()` calls the `syncJobs.use` gate. If 001's
  `OpenApiContract` does not coerce boolean query values, extend
  `tests/Support/Contract/OpenApiContract.php` so that `true` and `false` validate as JSON
  booleans.
- [ ] T028 [P] [US1] Create `app/Http/Resources/Api/V2/SyncJobLogResource.php`, which renders
  `{syncJobId, runStatus, log}` and encodes with `JSON_INVALID_UTF8_SUBSTITUTE` (R14).
- [ ] T029 [US1] Create `app/Http/Controllers/Api/V2/SyncJobController.php` with `index`, which
  returns the `{data, meta}` envelope through `ListQuery`, and `show`, which returns `200` with an
  `ETag` header, or `404` through `SyncJobScope::findInScope`. Create
  `app/Http/Controllers/Api/V2/SyncJobLogController.php` (`show`). Both run the gate first.
- [ ] T030 [US1] Register in `routes/api.php`, inside the `api.v2` group, with
  `restv2.auth:authenticated`:
  - `GET sync-jobs` → `index`;
  - `GET sync-jobs/{syncJob}` → `show`, `whereNumber('syncJob')`;
  - `GET sync-jobs/{syncJob}/log`.

  Leave room above for the specific route `sync-jobs/bulk-actions`
  (T060). T022–T025 must pass.

**Checkpoint**: US1 works on its own. Run quickstart Part B's US1 commands.

---

## Phase 4: User Story 2: Create a sync job (P1)

**Goal**: Mailbox users create jobs for their own mailbox, and administrators and domain
administrators for any mailbox in their scope, with legacy's defaults, validation and uniqueness.
Clients can read the capabilities first.

**Independent test**: Create jobs as each role, with minimal and full input, invalid custom
parameters, a duplicate and out-of-range values. Compare the stored rows with legacy's insert
(SC-002).

### Tests for User Story 2 (write first, observe failing) ⚠️

- [ ] T031 [P] [US2] Write `tests/Unit/SyncJobs/CustomParametersValidatorTest.php` (R7, FR-019,
  SC-004). Use a corpus of at least 40 values: `''`, `'--maxsize=1000'`, `'--maxsize=1000
  --nofoldersizesatend'`, `'--MaxSize=1'`, `'maxsize=5'`, `'--maxsize 1000'`, `'--maxsize =5'`,
  `'--folder=a-b'`, `'--=5'`, `'-0'`, `' --maxsize=1'`, `'--showpasswords'`, `'--SHOWPASSWORDS'`,
  every blacklisted option, and unknown options. For each value:
  - the validator's accept or refuse decision equals `legacy_syncjob_custom_params_ok()` from
    `tests/fixtures/legacy/syncjob_validation.php` with the upstream whitelist, except that every
    value containing `showpasswords` is refused by the API;
  - refused values carry `optionNotAllowed {option}` or `optionContainsSpace {token}` for every bad
    piece, in order and deduplicated.

  Restriction cases (FR-006):
  - with `custom_params_admin_only = true`, a mailbox user's non-empty value at creation gives one
    `customParamsNotPermitted`, and so does a domain admin's;
  - an admin's value is checked normally;
  - on edit, a value equal to the stored one gives no violation, a different one gives
    `customParamsNotPermitted`, and an empty string differing from a non-empty stored value is
    refused.
- [ ] T032 [P] [US2] Write `tests/Unit/SyncJobs/HostNameRuleTest.php` and
  `tests/Unit/SyncJobs/ExcludePatternRuleTest.php` (R6).
  - **Host rule**: for a corpus of at least 25 values, the rule agrees with
    `legacy_syncjob_is_valid_domain_name()`. The corpus includes `imap.example.com`, `localhost`,
    `192.0.2.10`, `mäil.example.de`, `-bad.example`, `a..b`, `2001:db8::1`, `[2001:db8::1]`, a
    253-character name, a 254-character name, a 63-character label, a 64-character label, a
    trailing dot and an underscore. A refusal gives `invalidFormat {format: hostName}`.
  - **Exclusion rule**: `(?i)spam|(?i)junk`, `''`, `^INBOX\/Junk$` and `Trash|Spam` are valid;
    `(`, `[a-` and `INBOX/Junk` (unescaped `/`, refused as legacy refuses it) give `invalidRegex`.
- [ ] T033 [P] [US2] Write `tests/Feature/SyncJobs/SyncJobTargetResolverTest.php` (R8, FR-003,
  FR-017, AS-9):
  - **Mailbox user**: an omitted `mailbox`, or their own address in another case, resolves to their
    own username; another existing address → `404`.
  - **Admin**:
    - an existing mailbox resolves to the stored username (`ALICE@example.org` → `alice@example.org`);
    - a non-existent address → `404`;
    - a resource (`kind = 'location'`) → `422 notEligible {kind: resource}`;
    - an alias → `notEligible {kind: alias}`;
    - an address of an alias domain → `notEligible {kind: aliasDomainAddress}`;
    - an omitted `mailbox` → `422 required`;
    - `example.org` (no `@`) → `422 invalidFormat {format: email}`.
  - **Domain admin**: a mailbox of a foreign domain → `404`, and an alias of a foreign domain →
    `404`, not `notEligible`.
  - **Inactive target mailbox**: accepted.
- [ ] T034 [P] [US2] Write `tests/Feature/SyncJobs/CreateSyncJobTest.php` (FR-016–FR-018, AS-1,
  AS-2, AS-9, AS-10):
  - **Minimal user creation** (AS-1): a user with the flag sends only `remoteHost`, `remotePort`,
    `remoteUser` and `remotePassword`. The answer is `201` with `Location: /api/v2/sync-jobs/<id>`
    and an `ETag` equal to `data.etag`. Every default of clarification 8 is in the body,
    `remotePasswordSet` is true, and the password appears nowhere in the body or headers.
  - **Admin creation** (AS-2): an admin naming a mailbox in scope gets the job on that mailbox; a
    domain admin likewise for their domain.
  - **Refusals**: a user without the flag → `403 forbidden`; an admin read-only key →
    `403 insufficient-key-scope` (AS-10); nothing is stored in either case.
  - **Audit**:
    - one `syncJobCreation` success record with `target {syncJob, id, mailbox}` and `attributes`
      without the password;
    - failure records for the `403 forbidden` (`permissionDenied`), `404` (`notFound`), `422`
      (`validationFailed`, `errors` with codes only) and `409` (`conflict`) refusals;
    - a failure record for the read-only key refusal too (`permissionDenied`, `details.problemType`
      `insufficient-key-scope`), but none for a CSRF failure (R16).
  - **Atomic audit** (spec edge case "Audit record cannot be written"): when the audit insert fails
    (a test listener on `AuditEvent::creating` throws), the answer is `500` and no `imapsync` row
    exists.
- [ ] T035 [P] [US2] Write `tests/Feature/SyncJobs/CreateSyncJobValidationTest.php` (FR-017, AS-4 to
  AS-8, clarifications 4 and 5):
  - **Ranges**: `intervalMinutes` 0 and 43801, `remotePort` 0 and 65536, `maxAgeDays` 32001,
    `maxBytesPerSecond` 125000001, and timeouts 0 and 32001 each give `outOfRange` with `min` and
    `max`. The boundaries 1, 43800, 65535, 0, 32000, 125000000 and 1 are accepted.
  - **Types**: a numeric string (`"20"`) → `invalidType {expected: integer}`; `"true"` for a
    boolean → `invalidType {expected: boolean}`; `null` for any member → `invalidType`.
  - **Accepted edge values**: `encryption: "plain"`, a host given as an IPv4 literal, and an
    explicitly empty `destinationSubfolder` and `customParameters` are accepted (spec edge cases).
  - **Lengths and enums**: `encryption: "tls"` → `notInList {allowed: [ssl, starttls, plain]}`;
    each length limit + 1 → `tooLong`; a missing required member → `required`; an empty
    `remotePassword` → `required`.
  - **Formats**: an IPv6 host → `invalidFormat {format: hostName}` (AS-8); `exclude: "("` →
    `invalidRegex` (AS-6); `customParameters: "--bogus=1 --maxsize 5"` → two violations (AS-4);
    `--showpasswords` → `optionNotAllowed` (AS-5).
  - **One response**: a request with five bad members names all five, and stores nothing (row count
    unchanged).
  - **Normalisation**: `remoteHost: "IMAP.Example.COM"` is stored as `imap.example.com`;
    `customParameters: "  --maxsize=1 "` is stored trimmed; `exclude: ""` is stored as `''`, not
    the default.
- [ ] T036 [P] [US2] Write `tests/Feature/SyncJobs/CreateSyncJobRowParityTest.php` (SC-002, R10):
  - **Minimal input**: the inserted row equals, column by column, the row legacy's `INSERT`
    produces from the add modal's defaults. Legacy's row is built in the test from data-model.md's
    insert list with a comment naming `functions.mailbox.inc.php` `mailbox('add', 'syncjob')`.
    `maxbytespersecond` is the string `'0'`, `authmech1` is `'PLAIN'`, `regextrans2` is `''`,
    `authmd51` is 0, `domain2` is `''`, `is_running` is 0 and the status columns are NULL.
  - **Every option set**: the same check, with every option given explicitly.
  - **Runner selection**: `ImapsyncRunnerSimulator::selectableIds()` contains the active new job
    and not an inactive one (SC-008).
  - **No other effect**: no other table changes, apart from `restv2_audit_events` (row counts of
    `mailbox`, `alias`, `user_acl` and `_sogo_static_view`), and no Redis key is written (scan of
    database 15).
- [ ] T037 [P] [US2] Write `tests/Feature/SyncJobs/CreateSyncJobDuplicateTest.php` (FR-017, AS-3):
  - an identical `(mailbox, remoteUser, remoteHost)` → `409 sync-job-exists` with `syncJobId` of
    the existing job, and no new row;
  - `remoteHost` in other case and `remoteUser` in other case are also duplicates (collation, as
    legacy);
  - the same remote account for another mailbox is accepted.
- [ ] T038 [P] [US2] Write `tests/Feature/SyncJobs/ConcurrentCreateSyncJobTest.php` (group
  `concurrency`, no `DatabaseTransactions`; it deletes its rows in `tearDown`). It seeds an admin and
  a key, starts two `Process` runs of `tests/bin/create-sync-job-once.php` with the same body, waits
  for both, and asserts one `201`, one `409` and exactly one `imapsync` row (spec edge case
  "Concurrent creation", R9).
- [ ] T039 [P] [US2] Write `tests/Feature/SyncJobs/SyncJobCapabilitiesTest.php` (FR-030, AS-11):
  - with the defaults, every caller with the flag gets `customParameters.allowed: true`,
    `restrictedToAdministrators: false`, the 113 upstream options (114 minus `showpasswords`),
    `bulkLimit: 100`, `pageSize {25, 1000}`, the limits of R6, `encryptions [ssl, starttls,
    plain]` and the defaults of clarification 8 (`exclude: "(?i)spam|(?i)junk"`);
  - with `RESTV2_SYNC_JOBS_CUSTOM_PARAMS_ADMIN_ONLY=y`, a mailbox user and a domain admin get
    `allowed: false`, and an admin `true`;
  - with `RESTV2_SYNC_JOBS_BULK_LIMIT=50`, `bulkLimit` is 50;
  - with an allow-list override containing `showpasswords`, it is still absent;
  - read-only keys may read it; no flag → `403`;
  - nothing is written (no audit record).

  Restriction on creation (AS-11): with the restriction on, a user creating with
  `customParameters: "--maxsize=1"` gets `422 customParamsNotPermitted` and nothing is stored,
  while an admin creating the same job succeeds.

### Implementation for User Story 2

- [ ] T040 [P] [US2] Create `app/Rules/SyncJobHostName.php`. It wraps 002's port of
  `is_valid_domain_name()`. Only if T001 found none, create
  `app/Support/Validation/LegacyDomainName.php` (line-by-line port with `idn_to_ascii(…, 0,
  INTL_IDNA_VARIANT_UTS46)`). It reports `invalidFormat {format: hostName}`. Create
  `app/Rules/SyncJobExcludePattern.php`, which reports `invalidRegex` when `@preg_match('/' . $v .
  '/', '') === false`. This makes T032 pass.
- [ ] T041 [P] [US2] Create `app/Services/SyncJobs/CustomParametersValidator.php` exactly as in R7:
  - the restriction is checked first, then "unchanged", then the trim, `explode('-')`, skipping of
    `''` and `'0'`, the option name before `=` or right-trimmed, the space check and the
    lower-cased allow-list check;
  - the allow-list in effect is `MailcowConfig::imapsyncOptions()['whitelist']` minus
    `showpasswords` (case-insensitive);
  - it returns every violation.

  This makes T031 pass.
- [ ] T042 [US2] Create `app/Services/SyncJobs/SyncJobTargetResolver.php` (R8). It uses `Mailbox`,
  and 004's `Alias` and `AliasDomain`, read only. It returns the canonical username, throws
  `ProblemException` 404 for unknown or out-of-scope targets, and returns a `notEligible {kind}`
  violation for resources, aliases and alias-domain addresses. This makes T033 pass.
- [ ] T043 [US2] Create `app/Services/SyncJobs/SyncJobDuplicateGuard.php` (R9):
  - `run(string $user2, string $user1, string $host1, ?int $exceptId, Closure $write)` takes
    `GET_LOCK('restv2_syncjob_' . substr(sha1(mb_strtolower("$user2\0$user1\0$host1")), 0, 40), 5)`;
  - a lock timeout is a `RuntimeException`, which becomes `500`;
  - it then opens `DB::transaction`, selects a duplicate with legacy's equality (`AND id <> ?` on
    edit) and throws `ProblemException(SyncJobExists, ['syncJobId' => …])` when one exists;
  - otherwise it runs `$write` and commits;
  - it releases the lock in `finally`.
- [ ] T044 [US2] Create `app/Services/SyncJobs/SyncJobService.php` with `create(Principal, array
  $input, string $mailbox): SyncJob`:
  - it applies `SyncJobDefaults`, lower-cases `host1`, trims `custom_params` and maps
    `encryption` through `SyncJobEncryption::fromApi`;
  - inside `SyncJobDuplicateGuard`, it inserts exactly the column list of data-model.md (with
    `authmech1 = 'PLAIN'` and `maxbytespersecond` as a decimal string);
  - it records `syncJobCreation` with `SyncJobAuditDetails::attributes()` in the same transaction.

  Refusals need no code here: `RefusalAuditor` (T021) records them for every route that declares
  `restv2.audit`.
- [ ] T045 [US2] Create `app/Http/Requests/Api/V2/StoreSyncJobRequest.php`, which follows the order
  of R5:
  - `authorize()` runs the gate (403), then resolves the target through `SyncJobTargetResolver`
    (404);
  - `rules()` enforces the R6 table with strict types, `SyncJobHostName`, `SyncJobExcludePattern`
    and the `SyncJobLimits` constants;
  - `after()` adds `CustomParametersValidator` violations and the resolver's `notEligible`.

  Create `app/Services/SyncJobs/SyncJobCapabilities.php` (R17),
  `app/Http/Resources/Api/V2/SyncJobCapabilitiesResource.php` and
  `app/Http/Controllers/Api/V2/SyncJobCapabilitiesController.php`. Add `store` to
  `SyncJobController` (`201`, `Location`, `ETag`). Register in `routes/api.php`, **above**
  `sync-jobs/{syncJob}`:
  - `GET capabilities/sync-jobs` with `restv2.auth:authenticated` (top-level path,
    `docs/conventions.md`);
  - `POST sync-jobs` with `restv2.auth:authenticated`, `restv2.csrf` and
    `->defaults('restv2.audit', ['event' => AuditEventType::SyncJobCreation, 'target' => 'syncJob'])`.
- [ ] T046 [US2] Create `tests/bin/create-sync-job-once.php`. It boots the application with
  `APP_ENV=testing`, handles one `POST /api/v2/sync-jobs` request with the key and body passed as
  arguments through the HTTP kernel, and prints the status code. T031–T039 must pass.

**Checkpoint**: US1 and US2 work. A job created through the API is selected by the runner
simulator and equals legacy's row.

---

## Phase 5: User Story 3: Edit, activate, deactivate and reschedule a sync job (P1)

**Goal**: Merge-patch edits with optional `If-Match`, a write-only password, an immutable target,
activation and deactivation as edits, and a reschedule that never touches the version tag.

**Independent test**: Edit each field, activate, deactivate and reschedule, as an administrator and
a mailbox user, with and without a new password, with a stale tag and with invalid values. Compare
the rows and the runner's selection with legacy's edit form (spec US3).

### Tests for User Story 3 (write first, observe failing) ⚠️

- [ ] T047 [P] [US3] Write `tests/Feature/SyncJobs/UpdateSyncJobTest.php` (FR-016–FR-018, AS-1, AS-2,
  AS-4, AS-6 to AS-9):
  - **Partial edit** (AS-1): sending only `intervalMinutes` changes only `mins_interval`, and every
    other column is byte-equal, `password1` included.
  - **Password** (AS-2): `remotePassword: "new"` replaces it; `""` keeps it.
  - **Activation** (AS-4): `active: false` removes the job from
    `ImapsyncRunnerSimulator::selectableIds()`, and `active: true` brings it back. Deactivating a
    running job (`startRun` first) answers `200` and leaves `is_running = 1` until `finishRun`
    (spec edge case).
  - **Invalid values** (AS-6): an unknown custom option, `exclude: "("`, an IPv6 host and
    `remotePort: 0` each give `422` naming the member, and the row is unchanged.
  - **Refusals** (AS-7, AS-8):
    - a user without the flag → `403 forbidden`;
    - an admin read-only key → `403 insufficient-key-scope`;
    - a domain admin editing a foreign job → `404`.
  - **Target mailbox** (AS-9): `mailbox: "b@example.org"` on an `a@example.org` job →
    `422 immutable` and no change; `mailbox: "A@example.org"` with `intervalMinutes: 30` saves the
    interval.
  - **Body handling**:
    - `{}` → `422 required` on `body`;
    - `null` for `intervalMinutes` → `invalidType`;
    - unknown and read-only members (`id`, `etag`, `runStatus`, `createdAt`) are ignored;
    - sending back the full representation from `GET` is accepted unchanged.
  - **Unchanged values** (R5): a legacy row with `host1 = 'bad_host'` (seeded directly) accepts
    `{"active": false}`, and also accepts a full round-trip that sends `remoteHost: "bad_host"`
    unchanged; `remoteHost: "other_bad"` → `invalidFormat`.
  - **Write scope** (R11): `remoteHost` is stored as sent (not lower-cased) on edit. The update
    does not overwrite status written by the runner between read and write: call `finishRun`, then
    PATCH, and `last_run`, `success`, `exit_status` and `returned_text` keep the runner's values.
  - **Audit**: one `syncJobUpdate` with `changes.intervalMinutes {old: 20, new: 30}`, and
    `changes.remotePassword: "changed"` only when the password was replaced. A no-op `PATCH` writes
    a success record with empty `changes`. Refusals are recorded per R16. When the audit insert
    fails, the answer is `500` and the row is unchanged.
- [ ] T048 [P] [US3] Write `tests/Feature/SyncJobs/UpdateSyncJobVersionTest.php` (FR-020, FR-021,
  AS-3, SC-005):
  - **Stale tag** (AS-3): two clients read the tag; the first `PATCH` with it succeeds and the
    second gets `412 about:blank`, with the row unchanged.
  - **Other writers**: a legacy-side change of `mins_interval` or of `password1` (direct SQL)
    changes the tag, so an old tag gives `412`. The next `GET` shows the new interval, and still only
    `remotePasswordSet: true` (FR-028). A runner status write (`startRun` and `finishRun`)
    and a reschedule leave the tag unchanged, so the old tag still succeeds.
  - **Wildcard and order**: `If-Match: *` succeeds. `412` is answered before `422`: a stale tag
    with an invalid body → `412`.
- [ ] T049 [P] [US3] Write `tests/Feature/SyncJobs/UpdateSyncJobDuplicateTest.php` (FR-017): changing
  `remoteUser` and `remoteHost` to those of another job of the same mailbox → `409 sync-job-exists`
  with that job's `syncJobId`, and no change. Changing them to values of a job of another mailbox
  is accepted. Changing `intervalMinutes` only never runs the duplicate check (no `GET_LOCK` in the
  query log).
- [ ] T050 [P] [US3] Write `tests/Feature/SyncJobs/UpdateSyncJobCustomParamsRestrictionTest.php`
  (FR-006, spec edge case). With the restriction on, for a job whose parameters an admin set:
  - a user's `PATCH` without `customParameters` → `200`;
  - the stored value sent unchanged → `200`;
  - a different value → `422 customParamsNotPermitted` and no change;
  - an admin's different value → `200`.
- [ ] T051 [P] [US3] Write `tests/Feature/SyncJobs/RescheduleSyncJobTest.php` (FR-016, FR-021,
  FR-022, AS-5):
  - **Reset** (AS-5): a job that last ran an hour ago gets `last_run` and `success` NULL, `200` with
    `runStatus.lastRunAt null` and `lastResult unknown`, `exitStatus null`, and an `ETag`
    unchanged from before. `ImapsyncRunnerSimulator::selectableIds()` contains it.
  - **No version check**: a stale `If-Match` is ignored (no `412`).
  - **During a run**: while running, the reset succeeds; `finishRun` then sets `last_run`, and the
    job is selectable again once its interval elapses.
  - **Refusals**: a foreign job → `404`; no flag → `403`; read-only key → `403
    insufficient-key-scope`.
  - **Audit**: one `syncJobReschedule` with `cleared` and `previous`.

### Implementation for User Story 3

- [ ] T052 [US3] Add `update(Principal, SyncJob $locked, array $patch): SyncJob` to
  `app/Services/SyncJobs/SyncJobService.php` (R11):
  - it computes the changed configuration columns only (a non-empty `remotePassword` replaces
    `password1`);
  - it runs `SyncJobDuplicateGuard` only when `user1` or `host1` changes;
  - it writes `UPDATE imapsync SET <changed> WHERE id = ?`, never touching the status columns or
    `user2`;
  - it records `syncJobUpdate` with `SyncJobAuditDetails::changes()` in the same transaction.

  Add `reschedule(Principal, SyncJob): SyncJob`, which runs `UPDATE imapsync SET last_run = NULL,
  success = NULL WHERE id = ?` and records `syncJobReschedule`.
- [ ] T053 [US3] Create `app/Http/Requests/Api/V2/UpdateSyncJobRequest.php` (R5, R6):
  - `authorize()` runs the gate (403), then `SyncJobScope::findInScopeForUpdate` (404), then
    `If-Match` through 001's `ETag` against `SyncJobVersion` (412);
  - `rules()` covers the patch members with strict types and refuses `null`;
  - `after()` handles an empty body (`required` on `body`), the `mailbox` comparison
    (case-insensitive → `immutable`) and "unchanged means accepted" (a member equal to the stored
    contract value skips its rules, type checks excepted), and runs `CustomParametersValidator` with
    the stored value.
- [ ] T054 [US3] Add `update` to `SyncJobController` (`200` with the `ETag` header). Create
  `app/Http/Controllers/Api/V2/SyncJobScheduleResetController.php` (`store`, which runs the gate
  then `findInScope` and returns `200`). Register in `routes/api.php`:
  - `PATCH sync-jobs/{syncJob}`, with the `restv2.audit` default event `SyncJobUpdate`;
  - `POST sync-jobs/{syncJob}/schedule-reset`, with the event `SyncJobReschedule`.

  Both use `restv2.auth:authenticated` and `restv2.csrf`, with `whereNumber`. T047–T051 must pass.

**Checkpoint**: US1–US3, the P1 scope, are complete.

---

## Phase 6: User Story 4: Delete a sync job (P2)

**Goal**: Deleting a job removes the row at once. A run in progress is not interrupted, and its
final write has no effect.

**Independent test**: Delete jobs in every run state as an administrator and a mailbox user, and a
foreign job (spec US4).

### Tests for User Story 4 (write first, observe failing) ⚠️

- [ ] T055 [P] [US4] Write `tests/Feature/SyncJobs/DeleteSyncJobTest.php` (FR-016, FR-021, AS-1 to
  AS-4):
  - **Owner delete** (AS-1): `204` with no body; the row is gone and is not in `selectableIds()`.
  - **Foreign or missing** (AS-2): `404`.
  - **During a run** (AS-3): `startRun`, then delete, then `finishRun(...)`. The delete returns
    `204`, `finishRun` affects 0 rows, and no row exists afterwards.
  - **Refusals** (AS-4): no flag → `403`; read-only key → `403 insufficient-key-scope`.
  - **Version check**: a stale `If-Match` → `412` and the row stays; a current one → `204`.
  - **Every run state**: never run, succeeded and failed jobs delete alike.
  - **Audit**: one `syncJobDeletion` record with `mailbox`, `remoteHost` and `remoteUser`, and no
    password.

### Implementation for User Story 4

- [ ] T056 [US4] Add `delete(Principal, SyncJob $locked): void` to
  `app/Services/SyncJobs/SyncJobService.php`. It runs `DELETE FROM imapsync WHERE id = ?` and records
  `syncJobDeletion` in the same transaction. Add `destroy` to `SyncJobController`, which runs the
  gate, then `findInScopeForUpdate`, then `If-Match` (412), and returns `204`. Register `DELETE
  sync-jobs/{syncJob}` (`restv2.auth:authenticated`, `restv2.csrf`, `whereNumber`, `restv2.audit`
  event `SyncJobDeletion`) in `routes/api.php`. T055 must pass.

**Checkpoint**: US4 works on its own.

---

## Phase 7: User Story 5: Apply an action to many sync jobs at once (P2)

**Goal**: Activate, deactivate, reschedule or delete up to 100 jobs in one request, with one result
per job.

**Independent test**: Run each action on a selection mixing in-scope and foreign jobs, one job
deleted just before processing, and stale and missing version tags (spec US5).

### Tests for User Story 5 (write first, observe failing) ⚠️

- [ ] T057 [P] [US5] Write `tests/Feature/SyncJobs/SyncJobBulkActionTest.php` (FR-023, FR-024,
  AS-1 to AS-7, SC-005):
  - **Deactivate many** (AS-1): an admin deactivates 50 active jobs. The answer is `200` with 50
    `done` results in request order, `summary {total: 50, done: 50, …}` and `bulkRequestId` equal
    to the request ID. There are 50 `syncJobUpdate` records, each with `details.bulkRequestId`.
  - **Mixed scope** (AS-2): a domain admin reschedules 3 own and 2 foreign jobs. The 3 are reset
    and the 2 are `notFound`, and the foreign rows are byte-equal.
  - **Whole-request refusals** (AS-3): no flag → `403 forbidden`; read-only key →
    `403 insufficient-key-scope`. Nothing changes.
  - **Item limit** (AS-4): 101 items → `422 tooMany {max: 100}` on `items`. With
    `RESTV2_SYNC_JOBS_BULK_LIMIT=50`, 51 items are refused.
  - **Vanished job** (AS-5): a bulk delete of 5, one deleted just before, gives 4 `done` and 1
    `notFound`, with 5 results.
  - **Own jobs** (AS-6): a user activates their own jobs.
  - **Version tags** (AS-7): a bulk deactivation of 3 with listed tags, one changed by direct SQL,
    gives that one `outdated` (with a problem) and unchanged, while the other two are deactivated.
    The same three without tags are all deactivated.
  - **Item handling**:
    - quoted and unquoted versions are both accepted;
    - a stale version on a `reschedule` item still gives `done`;
    - a `done` result carries the new version, unquoted, except after `delete`;
    - an ID named twice is processed and reported once;
    - `{"id":"abc"}` or `{"id":"0"}` → `422` naming `items.N.id`;
    - `items: []` → `422`;
    - an unknown `action` → `422 notInList`.
  - **Failure isolation**: bind a decorator of `SyncJobService` in the container that throws for
    one ID. That item is `failed` with an `about:blank` 500 problem, and the others are `done`.

### Implementation for User Story 5

- [ ] T058 [P] [US5] Create `app/Http/Requests/Api/V2/SyncJobBulkActionRequest.php`:
  - `authorize()` runs the gate;
  - `action` uses the `SyncJobBulkAction` enum;
  - `items` must be a list with at least 1 and at most `config('restv2.sync_jobs.bulk_limit')`
    entries (`tooMany {max}`);
  - `items.*.id` must match `^[1-9][0-9]{0,9}$` (`invalidFormat`), and `items.*.version` is an
    optional string.
- [ ] T059 [US5] Create `app/Services/SyncJobs/SyncJobBulkService.php` (R15):
  - it processes items in order, deduplicates by `id` and keeps the first occurrence;
  - per item, it runs one transaction: `findInScopeForUpdate` (`notFound`); for `activate`,
    `deactivate` and `delete` with a version, an unquoted comparison with `SyncJobVersion`
    (`outdated`); then `SyncJobService::update(['active' => …])`, `reschedule()` or `delete()`,
    with `details.bulkRequestId`;
  - every `Throwable` becomes `failed`;
  - it builds the shared `BulkResult` through 002's plumbing, or, only if T001 found none, through
    a new `app/Support/Bulk/BulkResultBuilder.php`.
- [ ] T060 [US5] Create `app/Http/Controllers/Api/V2/SyncJobBulkActionController.php` (`store`,
  `200` with `BulkResultResponse`). Register `POST sync-jobs/bulk-actions`
  (`restv2.auth:authenticated`, `restv2.csrf`) in `routes/api.php` **above**
  `sync-jobs/{syncJob}`. Its `restv2.audit` default is `['event' => 'fromBulkAction', 'target' =>
  'syncJob']`. `SyncJobAuditTargetResolver` maps the body's `action` to the event type (`activate`
  and `deactivate` → `SyncJobUpdate`, `reschedule` → `SyncJobReschedule`, `delete` →
  `SyncJobDeletion`) and falls back to `SyncJobUpdate` when `action` is missing or invalid. Extend
  `RefusalAuditorTest` (T021) with this case. T057 must pass.

**Checkpoint**: US5 works. Legacy's four mass actions are covered.

---

## Phase 8: User Story 6: Trace sync-job changes (P3)

**Goal**: Administrators list a sync job's history with the generic `targetType` and `targetId`
filters of `GET /audit-events`, and see every change and refusal without the password. This
feature adds no audit filter of its own (`docs/conventions.md`, "Capabilities, history and
refusals").

**Independent test**: Make each kind of change, including refused ones, then list the audit log by
target type and identifier (spec US6).

### Tests for User Story 6 (write first, observe failing) ⚠️

- [ ] T061 [P] [US6] Write `tests/Feature/Audit/SyncJobAuditTest.php` (FR-025, FR-026, AS-1 to AS-3,
  SC-007):
  - **Change record** (AS-1): after an admin changes a job's interval and password, another admin
    lists `?targetType=syncJob&targetId=<id>`. The entry shows actor, `occurredAt`,
    `clientAddress`, `changes.intervalMinutes {old, new}` and `changes.remotePassword: "changed"`.
  - **Refusal record** (AS-2): after a user's refused `PATCH` of a job (no flag), both
    `?targetType=syncJob&targetId=<id>` and `?actor=<user>` list the refusal, with `outcome
    failure`, `failureReason permissionDenied` and the job's mailbox in `target.name`.
  - **Access** (AS-3): domain admins and mailbox users → `403 forbidden`.
  - **Coverage**: every event type of R16 appears once per affected job, and a bulk action's
    records share `details.bulkRequestId`.
  - **Secret scan** (SC-007): after a scenario exercising every operation with the password
    `Remote-S3cret!`, no audit row's `details`, `target_name` or `user_agent` contains it.

### Implementation for User Story 6

- [ ] T062 [US6] Make the generic history filter accept sync jobs. If the `targetType` validation
  of the audit list (`app/Http/Requests/Api/V2/ListAuditEventsRequest.php`) uses a PHP list of
  target types rather than the contract's `AuditTarget.type`, add `syncJob` to that list. No new
  parameter and no migration. T061 must pass.

**Checkpoint**: all six stories work.

---

## Phase 9: Polish and cross-cutting concerns

- [ ] T063 [P] Write `tests/Feature/Security/SyncJobAuthorizationMatrixTest.php` (constitution VI,
  quality gate 5, FR-005, SC-003). It is a data provider over the 9 operations × 13 callers:
  - anonymous;
  - a restricted session (`force_pw_update`);
  - admin;
  - domain admin with and without `syncjobs`;
  - mailbox user with and without `syncjobs`;
  - admin read-only and read-write keys;
  - domain-admin read-only and read-write keys, each with and without `syncjobs`.

  It asserts the status and problem `type` from a table derived from FR-005 and the contract's
  security. Object-level cases: a foreign-domain job → `404` for a domain admin; another mailbox's
  job → `404` for a mailbox user; a foreign target mailbox at creation → `404`.
- [ ] T064 [P] Write `tests/Feature/Security/SyncJobSecretLeakTest.php` (FR-018, SC-001, SC-007). It
  runs every operation with the password `Remote-S3cret!`, including validation failures that echo
  members and a forced `500`. The password must not appear in any response body or header, in the
  `stderr_json` log output (captured through a test log handler), in `restv2_audit_events` or in an
  exception render. `ETag` values must not equal `SHA-256(password)` in any encoding.
- [ ] T065 [P] Write `tests/Feature/Performance/SyncJobListLatencyTest.php` (group `performance`,
  SC-006):
  - seed 100,000 mailboxes over 200 domains and 5,000 jobs, with `INSERT … SELECT` from MariaDB's
    `seq_1_to_100000`, and 1 MB `returned_text` on 500 jobs;
  - measure 20 requests each of: an admin listing sorted by `lastRunAt`, a domain admin with 2
    domains, and `q` plus `lastResult` filters;
  - assert p95 < 1 s.

  Write `tests/Feature/Performance/SyncJobBulkLatencyTest.php` (SC-009): a bulk `deactivate` of
  100 jobs finishes in under 10 s, with exactly 100 results and 100 audit records.
- [ ] T066 [P] Extend `database/seeders/DevelopmentSeeder.php` with the quickstart Part B data:
  - `user_acl.syncjobs = 1` for `alice@example.org` and 0 for `bob@example.org`;
  - jobs #1 (never run) and #2 (failed, `EXIT_AUTHENTICATION_FAILURE_USER1`, with a log) for alice;
  - `carol@example.net` with job #3;
  - `reseller` with `da_acl.syncjobs = 1`.
- [ ] T067 [P] Update `README.md`: the configuration variables `RESTV2_IMAPSYNC_OPTIONS`,
  `RESTV2_SYNC_JOBS_CUSTOM_PARAMS_ADMIN_ONLY` and `RESTV2_SYNC_JOBS_BULK_LIMIT`. Update
  `CHANGELOG.md`: "contract: the 9 sync-job operations (feature 008)", for mailcowui-ng to pin.
- [ ] T068 Review `routes/api.php`:
  - `sync-jobs/bulk-actions` is registered before `sync-jobs/{syncJob}`, and the capabilities read
    is at `capabilities/sync-jobs`;
  - every unsafe route has `restv2.csrf` and a `restv2.audit` default;
  - every route uses `restv2.auth:authenticated` with the default pending-action enforcement.
- [ ] T069 (FR-029) Run `composer test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` and
  `composer contract:lint` in the dev container. Fix every finding until all are green and
  `build/contract/coverage.json` lists all 9 sync-job operations, alongside the earlier features'.
  Run the `concurrency` and `performance` groups.
- [ ] T070 Run quickstart.md Part A and Part B against the dev stack and confirm every expected
  output. Correct quickstart.md where commands drifted, keeping it a validation guide.

---

## Dependencies and execution order

### Phase dependencies

- **Setup (Phase 1)** needs features 001–007 implemented. T001 comes first. T002–T004 can then run
  in parallel.
- **Foundational (Phase 2)** depends on Setup:
  - tests T005–T012 come first; T006 must exist before the US tests that use the simulator;
  - then the implementation T013–T021: T015 comes before T016; T016 before T017, T019 and T020;
    T020 before T021; T013 before the capabilities and custom-parameter work.

  **It blocks every user story.**
- **User stories**:
  - US1 (Phase 3) depends on Foundational only.
  - US2 (Phase 4) depends on Foundational. Its tests read jobs back through US1's `GET`, so
    implement US1 first or read rows directly.
  - US3 (Phase 5) reuses US2's validators (T040–T042) and `SyncJobDuplicateGuard` (T043).
  - US4 (Phase 6) depends on Foundational only, plus `SyncJobService` from T044.
  - US5 (Phase 7) needs `update`, `reschedule` and `delete` from T052 and T056.
  - US6 (Phase 8) needs records from US2–US5 and the generic `targetType` and `targetId` audit
    filters. T061 precedes T062.
- **Polish (Phase 9)** depends on every story. T063 and T069 need all 9 operations.

### Within each story

Tests come first, written and failing. Then services, then requests, resources and controllers,
then routes. Stop at each checkpoint and run the story's tests.

### Parallel opportunities

- Setup: T002, T003 and T004.
- Foundational tests: T005–T012, all different files.
- Foundational implementation: T013, T014 and T015 in parallel, then T016 → T017, T018, T019 and
  T020, then T021.
- US2 tests T031–T039 in parallel; US2 implementation T040 and T041 in parallel.
- US3 tests T047–T051 in parallel.
- With two developers after US3: US4 (Phase 6) and US5's tests (T057) in parallel.

## Parallel example: User Story 2

```bash
# All US2 test tasks together (different files):
Task: "T031 CustomParametersValidatorTest in tests/Unit/SyncJobs/CustomParametersValidatorTest.php"
Task: "T032 HostNameRuleTest + ExcludePatternRuleTest in tests/Unit/SyncJobs/"
Task: "T033 SyncJobTargetResolverTest in tests/Feature/SyncJobs/SyncJobTargetResolverTest.php"
Task: "T034 CreateSyncJobTest", "T035 CreateSyncJobValidationTest", "T036 CreateSyncJobRowParityTest"
Task: "T037 CreateSyncJobDuplicateTest", "T038 ConcurrentCreateSyncJobTest", "T039 SyncJobCapabilitiesTest"

# Then the independent building blocks together:
Task: "T040 SyncJobHostName + SyncJobExcludePattern rules"
Task: "T041 CustomParametersValidator"
```

## Implementation strategy

### MVP first (User Story 1)

1. Phase 1 Setup, then Phase 2 Foundational.
2. Phase 3 (US1): every role reads its jobs, run status and logs. This replaces the legacy tables'
   reads and is safe because it changes nothing.
3. **Stop and validate**: quickstart Part B's US1 commands, and compare with the legacy table for
   the same rows.

### Incremental delivery

1. US2 (create) and US3 (edit, activate, deactivate, reschedule) complete the P1 scope that the web
   app's sync-job screens need.
2. US4 (delete) and US5 (bulk), then US6 (audit filter).
3. Polish: the authorisation matrix, the secret-leak scan, performance, README and CHANGELOG.

Each story ends with its checkpoint green and the contract coverage growing towards all 9 sync-job
operations.

## Notes

- Never use `RefreshDatabase`, `migrate:fresh`, `migrate:refresh` or `db:wipe`: they drop mailcow's
  tables (001).
- Only `SyncJobService` writes `imapsync`, and controllers never write. No migration targets an
  upstream table. The only migration touches `restv2_audit_events`.
- Never log or audit request bodies of this feature: they can contain the remote password.
- Commit after each task or logical group, with short imperative subjects and no AI attribution.
