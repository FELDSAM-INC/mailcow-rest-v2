# Implementation Plan: Sync Jobs

**Branch**: `main` (no feature branches, constitution 1.1.0) | **Date**: 2026-10-18 |
**Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/008-sync-jobs/spec.md`

## Summary

Feature 008 lets administrators, domain administrators and mailbox users manage mailcow's imapsync
jobs through `/api/v2`:

- list, search, filter and inspect jobs with their run status;
- read the run log;
- create, edit, activate, deactivate, reschedule and delete jobs;
- apply bulk actions to up to 100 jobs;
- read the sync-job capabilities (allow-list, limits, defaults).

The unchanged imapsync runner in the dovecot container keeps running the jobs. This API only
manages `imapsync` rows and reads the status columns the runner maintains.

The technical approach:

- The contract was authored first: 9 operations in `api/modules/sync-jobs/`, with `SyncJob*`
  components. It lints clean.
- Legacy `mailbox('add'|'edit'|'delete'|'get', 'syncjob…')` is ported into
  `app/Services/SyncJobs/`. The port keeps:
  - the legacy object-access rule (`hasMailboxObjectAccess`);
  - the custom-parameter split and allow-list, with `showpasswords` refused;
  - the host rule (`is_valid_domain_name`) and the PCRE check of the exclusion pattern;
  - the exact insert column list.

  It deviates only where the spec says so: write-only password, field errors instead of clamping,
  validation on create, an immutable target, version tags and bulk results.
- The version tag covers the stored configuration, including an HMAC of the password, and never
  the run status, so runs and reschedules never make an editor's version stale.
- A MariaDB named lock guards the `(mailbox, remote user, remote host)` uniqueness, which upstream
  enforces only in code.
- Every change and refusal is audited through 001's `AuditLogger`. The password appears only as
  "changed".
- The feature adds no API-owned table, no migration, no Redis key and no dependency.
- Tests come first and run on MariaDB with the `2026-09a` schema fixture. A runner simulator
  executes the runner's own SQL, and legacy oracles are extracted from the upstream tag to prove
  validation parity.

## Technical Context

**Language/Version**: PHP 8.4 and Laravel 13.x, as decided in feature 001 (constitution: PHP 8.3+).

**Primary Dependencies**: Those of feature 001. No new Composer or npm package
([research R22](./research.md#r22-dependencies-constitution-viii)). The feature uses `ext-intl`
(`idn_to_ascii`), PCRE, MariaDB `GET_LOCK()` and `Symfony\Component\Process` (tests).

**Storage**: mailcow's MariaDB 10.11 with the upstream `2026-09a` schema:

- `imapsync` is read and written through `SyncJobService` only;
- `mailbox`, `domain_admins`, `alias`, `alias_domain`, `user_acl` and `da_acl` are read.

001's `restv2_audit_events` receives the audit records. The feature adds no migration and uses no
Redis key.

**Testing**: PHPUnit 12 feature and unit tests, written first, on MariaDB with
`tests/fixtures/schema/2026-09a.sql` and a real Redis, every request and response validated against
the contract (001 R13). The tests use:

- `ImapsyncRunnerSimulator`, which runs the runner's SQL;
- legacy oracles extracted from the upstream tag (custom-parameter loop and `is_valid_domain_name`);
- a row-parity test against legacy's insert;
- a two-process concurrency test;
- an authorisation matrix over 9 operations and 13 caller types;
- performance tests for SC-006 and SC-009.

**Target Platform**: 001's Linux container (`php:8.4-fpm-alpine`) next to mailcow-dockerized,
reached by nginx-mailcow over FastCGI at `/api/v2/`.

**Project Type**: Web service (REST API).

**Performance Goals**:

- SC-006: a page of 25 jobs within 1 s at p95, with 5,000 jobs across 100,000 mailboxes. The list
  joins `mailbox` on its primary key and never reads `returned_text` for the sort.
- SC-009: a bulk action on 100 jobs within 10 s, at about 4 queries per item.

**Constraints**:

- The imapsync runner and the `imapsync` columns stay unchanged (FR-022), and no upstream table is
  altered (constitution III).
- The legacy UI and `/api/v1` keep working on the same rows.
- The remote password never leaves the database through this API: not in responses, logs or audit
  records, and not in a plain-hash ETag.
- `showpasswords` is always refused.

**Scale/Scope**: Up to about 5,000 jobs on 100,000 mailboxes per server. The feature has 9
operations in one module, 6 user stories and 0 new tables.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design (below).*

| Principle | Gate | Status |
|---|---|---|
| I. Contract-first | All 9 operations are in `api/modules/sync-jobs/`, with `SyncJob*` components, before any code. `npx @redocly/cli@2.64.1 lint api/openapi.yaml` passes. Tests validate every request and response, and the 9 operations join the coverage check. History uses the existing generic audit filters. | PASS |
| II. HTTP contract | Plural noun `/sync-jobs`; sub-resources `log`, `schedule-reset`, `bulk-actions`, and the capabilities read `/capabilities/sync-jobs`; JSON Merge Patch. 201 with `Location` and `ETag`, 200, 204. `{data, meta}` list with `limit`, `offset`, `sort`, `order`, `q` and filters. Problem+json with `errors` on 422. `ETag` and optional `If-Match` (412). camelCase, booleans, explicit units (`intervalMinutes`, `maxAgeDays`, `maxBytesPerSecond`, `…TimeoutSeconds`), RFC 3339 UTC. Status codes are all in the constitution's list (409 for duplicates). | PASS |
| III. Upstream schema | No migration touches an upstream table. Uniqueness uses a named lock instead of a unique index (R9). The feature adds no migration. `ImapsyncColumnsTest` fails if a release changes `imapsync`'s columns. | PASS |
| IV. Parity | The legacy add, edit, delete and get code, the forms, the JS tables, `vars.inc.php` and `imapsync_runner.pl` were read (research R1–R16). Every write goes through `SyncJobService` with legacy's column list and no extra side effect (legacy has none for sync jobs). Validation parity is proven against extracted legacy oracles. Deviations are the spec's, completed by the plan-level parity notes below, which were added to the spec's table. | PASS |
| V. Coexistence | Same container and routing as 001. The runner, php-fpm, the legacy UI and `/api/v1` are untouched and keep editing the same rows. Legacy writes stay unserialised, as before. | PASS |
| VI. Security | The permission flag is evaluated per request from mailcow data. Object-level scope means a foreign job is `404`. Keys act as their owner, and read-only keys are refused on writes. The password is write-only, hidden in the model, an HMAC in the tag, and "changed" in the audit. `showpasswords` is refused. Every change is audited in the change's transaction. The log is declared operator-authored plain text. Every refusal by an authenticated caller is audited too, read-only-key and restricted-session refusals included; CSRF failures and `401`s are not, to avoid forgeable and anonymous log flooding (R16). | PASS |
| VII. Test-first | `tasks.md` puts each phase's tests first, to be observed failing. They run on MariaDB with the real schema and real Redis. No external service is involved: the runner is simulated through its own SQL, not faked behind an interface, because the API never calls it. | PASS |
| VIII. Simplicity | No new dependency or table. Shared pieces from 001 and 002–004 are reused (configuration reader, host rule, bulk plumbing, alias models) before anything new is created. | PASS |
| Cross-feature conventions | Out-of-scope objects answer 404, and missing flags 403. All bad entries are named at once, nothing is clamped. Bulk follows the rules: at most 100, per-item outcomes, per-item version tags, dedupe. Tags cover stored settings only. The secret is write-only. `IMAPSYNC_OPTIONS` uses 001's configuration reader with `RESTV2_IMAPSYNC_OPTIONS` winning. One audit record per affected job, with the bulk request ID. Reads are not audited. Nothing is cached. Lists default to 25, at most 1,000, and take `q`. | PASS |

**Post-design re-check (after Phase 1)**: PASS. The design adds no violation. The decisions that touched gates:

- R4, the HMAC of the password in the version tag: VI.
- R5, `412` before `422` and "unchanged means accepted": IV, recorded in the spec.
- R9, the named lock instead of a unique index: III.
- R16, every refusal by an authenticated caller audited (pipeline refusals included, through a
  route-scoped `RefusalAuditor`), except CSRF failures and `401`s: VI and FR-025.

## Parity notes added to the spec

Constitution IV requires every deviation to be stated in the spec. These plan-level notes were added
to the spec's "Legacy Parity and Deliberate Deviations" table, as four rows marked "plan-level
note" (notes 3 and 4 share a row):

1. **Unchanged values are accepted**: on an edit, a member sent with its stored value, and every
   member not sent, is accepted without re-validation. Legacy edit re-validates every stored value,
   so it refuses to deactivate a legacy job whose host it never validated.
2. **Canonical target address**: the target mailbox is stored as the mailbox's canonical username,
   not as typed.
3. **All violations at once**: every custom-parameter violation is reported in one response.
   Legacy stops at the first bad option.
4. **Trimming on edit**: custom parameters are trimmed on edit as well as on create. Legacy edit
   does not trim and then refuses a leading space as an unknown option.
5. **Changed columns only**: an edit writes only the changed configuration columns. Legacy rewrites
   the whole row, including `last_run` and `success`, and can overwrite a status the runner wrote
   meanwhile.

## Contract Operations

| Method and path | operationId | Auth | Story / FR |
|---|---|---|---|
| GET `/sync-jobs` | listSyncJobs | session or key (read-only too), `syncjobs` flag | US1, FR-001–FR-003, FR-007, FR-008, FR-011–FR-015 |
| POST `/sync-jobs` | createSyncJob | session + CSRF, or read-write key; flag | US2, FR-005, FR-006, FR-016–FR-019, FR-025 |
| GET `/capabilities/sync-jobs` | getSyncJobsCapabilities | session or key (read-only too); flag | US2 AS-11, FR-006, FR-030 |
| POST `/sync-jobs/bulk-actions` | applySyncJobBulkAction | session + CSRF, or read-write key; flag | US5, FR-023, FR-024, FR-025 |
| GET `/sync-jobs/{syncJobId}` | getSyncJob | session or key; flag | US1, FR-007, FR-008, FR-020 |
| PATCH `/sync-jobs/{syncJobId}` | updateSyncJob | session + CSRF, or read-write key; flag | US3, FR-016–FR-021, FR-025 |
| DELETE `/sync-jobs/{syncJobId}` | deleteSyncJob | session + CSRF, or read-write key; flag | US4, FR-016, FR-021, FR-025 |
| GET `/sync-jobs/{syncJobId}/log` | getSyncJobLog | session or key; flag | US1, FR-009 |
| POST `/sync-jobs/{syncJobId}/schedule-reset` | rescheduleSyncJob | session + CSRF, or read-write key; flag | US3, FR-016, FR-021, FR-022, FR-025 |

History (FR-026) uses the generic `targetType` and `targetId` filters of `GET /audit-events`. This
feature adds no audit filter (`docs/conventions.md`).

The contract files are listed in [contracts/README.md](./contracts/README.md). The problem type
`sync-job-exists` is documented in [`docs/problems.md`](../../docs/problems.md#sync-job-exists).

## Project Structure

### Documentation (this feature)

```text
specs/008-sync-jobs/
├── spec.md              # Feature specification (clarifications, parity table)
├── plan.md              # This file
├── research.md          # Phase 0: decisions R1–R23
├── data-model.md        # Phase 1: imapsync mapping, reads, audit records, state transitions
├── quickstart.md        # Phase 1: validation guide
├── contracts/README.md  # Phase 1: pointer to api/modules/sync-jobs (the contract is not duplicated)
├── checklists/requirements.md
├── tasks.md             # Phase 2 (/speckit-tasks)
└── analysis.md          # /speckit-analyze summary
```

### Source Code (repository root)

Only the files this feature adds or changes are shown. The rest is 001's layout.

```text
api/                                         # Contract (source of truth), already authored
├── openapi.yaml                             # + tag "Sync jobs", + 6 path refs
├── modules/sync-jobs/{_index,sync-jobs,sync-jobs-capabilities,sync-jobs-bulk-actions,
│                      sync-job,sync-job-log,sync-job-schedule-reset}.yaml
└── components/
    ├── parameters/{SyncJobId,SyncJobSort}.yaml
    ├── responses/SyncJob{Response,CreatedResponse,ListResponse,LogResponse,
    │                     CapabilitiesResponse,Exists,ValidationFailed}.yaml
    └── schemas/SyncJob{,RunStatus,LastResult,Encryption,CreateRequest,Patch,Log,Capabilities,
                        Defaults,Range,BulkAction,BulkTarget,BulkActionRequest}.yaml
        (+ appended values in KnownProblemType, AuditEventType, AuditTarget,
           AuditFailureReason, ValidationRuleCode)

app/
├── Http/
│   ├── Controllers/Api/V2/
│   │   ├── SyncJobController.php                 # index, store, show, update, destroy
│   │   ├── SyncJobLogController.php              # show
│   │   ├── SyncJobScheduleResetController.php    # store
│   │   ├── SyncJobBulkActionController.php       # store
│   │   └── SyncJobCapabilitiesController.php     # show
│   ├── Requests/Api/V2/
│   │   ├── ListSyncJobsRequest.php
│   │   ├── StoreSyncJobRequest.php
│   │   ├── UpdateSyncJobRequest.php
│   │   └── SyncJobBulkActionRequest.php
│   └── Resources/Api/V2/{SyncJobResource,SyncJobLogResource,SyncJobCapabilitiesResource}.php
├── Models/Mailcow/SyncJob.php                    # imapsync, hidden password1/returned_text
├── Policies/SyncJobPolicy.php                    # syncjobs permission gate
├── Rules/{SyncJobHostName,SyncJobExcludePattern}.php
├── Services/SyncJobs/
│   ├── SyncJobService.php                        # create/update/reschedule/delete + audit (all upstream writes)
│   ├── SyncJobQuery.php                          # list/get query: scope, filters, q, sort, logAvailable
│   ├── SyncJobScope.php                          # hasMailboxObjectAccess port
│   ├── SyncJobTargetResolver.php                 # target mailbox: 404 / notEligible / canonical
│   ├── CustomParametersValidator.php             # legacy split + allow-list + restriction
│   ├── SyncJobDuplicateGuard.php                 # GET_LOCK + duplicate check → 409
│   ├── SyncJobVersion.php                        # ETag (HMAC of password)
│   ├── SyncJobBulkService.php                    # per-item processing, BulkResult
│   ├── SyncJobCapabilities.php
│   ├── SyncJobAuditDetails.php                   # details/changes builders without secrets
│   ├── SyncJobAuditTargetResolver.php            # target of refusal records (route id or mailbox member)
│   └── {SyncJobEncryption,SyncJobLastResult,SyncJobBulkAction,SyncJobDefaults,SyncJobLimits}.php
├── Services/Audit/RefusalAuditor.php             # audits refusals of routes declaring restv2.audit (reuse 002's if present)
├── Services/Config/MailcowConfig.php             # + imapsyncOptions() (001's reader, extended)
├── Support/Http/ProblemType.php                  # + SyncJobExists case (001's enum, extended)
├── Support/Validation/LegacyDomainName.php       # only if feature 002 left no port of is_valid_domain_name
└── Support/Bulk/BulkResultBuilder.php            # only if feature 002 left no bulk plumbing

config/restv2.php                                 # + sync_jobs.{custom_params_admin_only,bulk_limit}, vars_overrides IMAPSYNC_OPTIONS
resources/bin/export-mailcow-vars.php             # + IMAPSYNC_OPTIONS
database/factories/MailcowRows.php                # + syncJob(), alias()/aliasDomain() if 004 left none
database/seeders/DevelopmentSeeder.php            # + sync jobs for the quickstart
routes/api.php                                    # sync-job routes, specific before {syncJobId}
docs/problems.md                                  # + sync-job-exists

scripts/legacy/extract-syncjob-validation.sh      # legacy oracles from the upstream tag
tests/
├── fixtures/legacy/syncjob_validation.php        # generated
├── fixtures/mailcow-inc-imapsync/                # vars.local.inc.php overriding IMAPSYNC_OPTIONS
├── bin/create-sync-job-once.php                  # concurrency helper
├── Support/SyncJobs/ImapsyncRunnerSimulator.php
├── Support/Mailcow/MailcowFactory.php            # + syncJob()
├── Unit/SyncJobs/{CustomParametersValidatorTest,HostNameRuleTest,ExcludePatternRuleTest,
│                  SyncJobVersionTest,SyncJobEncryptionTest}.php
├── Unit/Services/MailcowConfigImapsyncOptionsTest.php
├── Feature/Schema/ImapsyncColumnsTest.php
├── Feature/SyncJobs/                              # one file per operation / concern (tasks.md)
├── Feature/Audit/SyncJobAuditTest.php
├── Feature/Security/{SyncJobAuthorizationMatrixTest,SyncJobSecretLeakTest}.php
└── Feature/Performance/{SyncJobListLatencyTest,SyncJobBulkLatencyTest}.php
```

**Structure Decision**: This is the single Laravel project of feature 001, following the
constitution's path table:

- the sync-job aggregate lives in `app/Services/SyncJobs/`, and only `SyncJobService` writes
  `imapsync`;
- controllers are thin, one per resource or sub-resource;
- validation lives in Form Requests, which delegate the legacy-specific checks to the services;
- feature enums live with the service namespace, as 001 keeps its auth enums with `app/Auth`.

## Cross-feature dependencies

Features are implemented in number order, so 008 builds on code that already exists
([research R21](./research.md#r21-dependencies-on-earlier-features)):

- **001**: the whole foundation, including `MailcowConfig` (extended here), `ProblemType`
  (extended here), `AuditLogger`, `IdentityService`, `ListQuery`, `ETag`, the middleware, the test
  toolkit and the `Mailbox`, `Domain` and `DomainAdminAssignment` models.
- **002**:
  - the generic `listAuditEvents` filters `targetType` and `targetId`;
  - the port of `is_valid_domain_name()`;
  - the bulk-result plumbing;
  - the audit failure reasons `versionOutdated`, `validationFailed` and `conflict`.
- **003**: the full `Mailbox` model (`kind`) and the rename and delete cascades of `imapsync` rows
  (not duplicated here).
- **004**: the `Alias` and `AliasDomain` read models and factory helpers.

If 002–004 named a shared piece differently, the tasks say to search for the ported legacy function
before creating anything ("implemented once and reused").

## Owner decisions applied

The decisions are recorded in `docs/conventions.md`, "Capabilities, history and refusals":

- capabilities live at `/capabilities/sync-jobs` (`getSyncJobsCapabilities`);
- history uses the generic `targetType` and `targetId` audit filters, and this feature adds no
  filter of its own;
- `AuditFailureReason` is a shared, append-only enum.

The page size is fixed project-wide, so the spec's assumption was aligned with the conventions.

## Complexity Tracking

No violations. The design needs no change to another feature's operation.
