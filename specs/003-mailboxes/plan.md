# Implementation Plan: Mailboxes

**Branch**: `main` (no feature branches, constitution 1.1.0) | **Date**: 2026-10-18 |
**Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/003-mailboxes/spec.md`

## Summary

Feature 003 lets administrators and domain administrators manage mailcow's mailboxes through
`/api/v2`:

- a paginated, filterable, sortable and searchable mailbox list that needs no lookup per row, and
  single reads with usage figures, last logins and the largest allowed quota;
- creation with templates, domain limits that hold under concurrency, the password policy and
  mailcow's hash scheme, domain-administrator permission checks, and follow-up steps;
- changes by JSON Merge Patch (settings, password, protocol access, authentication source,
  recovery e-mail, tags, user permissions, custom attributes), guarded by version tags;
- deletion that first moves the mail to the garbage area and then removes everything legacy
  removes, plus the tagged-mail handling and recent logins that legacy leaves behind;
- a rename that runs as a compensated sequence of steps, carries over the data legacy forgets and
  undoes itself on failure;
- bulk actions with one result per mailbox, mailbox templates, a read-only password policy shared
  with feature 013, capabilities, the tags in use and a deletion preview.

The technical approach:

- The OpenAPI 3.1 contract was authored first: 16 operations in the modules `mailboxes`,
  `mailbox-templates` and `password-policy`. It lints clean.
- Every upstream write goes through services in `app/Services/Mailboxes/` and reproduces legacy's
  `mailbox()` side effects for `mailbox`, `mailbox_templates`, `mailbox_rename`,
  `mailbox_custom_attribute`, `tags_mailbox` and `delimiter_action`, and `acl('edit', 'user')`
  ([research](./research.md)).
- Feature 001 provides the pipeline, identity, permission flags, API keys, problems, audit,
  `MailcowConfig`, `ListQuery`, `ETag` and the legacy hash verifier. Feature 002 provides
  `DomainName`, `DomainUsageCalculator`, `SogoDirectory`, `MemcachedFlusher`, `DockerApiClient`,
  `RateLimitStore`, `SharedStore`, the audit follow-up completion and the audit target filters.
  This feature extends them where it needs more and adds no Composer package.
- Tests are written first and run on MariaDB 10.11 with the `2026-09a` schema fixture and on a
  real Redis. dockerapi and memcached are faked behind interfaces that record the calls. Every
  request and response is validated against the contract.

## Technical Context

**Language/Version**: PHP 8.4 (constitution: 8.3 or newer), Laravel 13.x, as decided in feature
001.

**Primary Dependencies**: Those of features 001 and 002 only (`laravel/framework` ^13, phpredis,
`intl`, `opis/json-schema` for contract tests). No new Composer package and no new PHP extension
([research R24](./research.md#r24-dependencies)).

**Storage**: mailcow's MariaDB (upstream schema `2026-09a`, `db_version 18082026_1200`), read and
written only through `app/Services/Mailboxes/` and the 002 services it reuses. No new API-owned
table and no migration: the feature uses 001's `restv2_audit_events` (with 002's target index) and
`restv2_preferences`. mailcow's Redis: `PASSWD_POLICY` (read), `RL_VALUE`,
`RCPT_WANTS_SUBJECT_TAG`, `RCPT_WANTS_SUBFOLDER_TAG` (read and written), `MC_CHANNEL` (published
in cluster mode), and the API-owned lock keys `restv2:lock:mailbox:*`. See
[data-model.md](./data-model.md).

**Testing**: PHPUnit 12 feature and unit tests, written first, on MariaDB with
`tests/fixtures/schema/2026-09a.sql` and a real Redis (database 15). `FakeDockerApiClient` and
`FakeMemcachedFlusher` (feature 002) record container and cache calls; this feature extends the
docker fake with scripted `exec` answers per command. Concurrency (SC-006) is tested with parallel
PHP processes; performance (SC-002) with a seeded data set in the `performance` group.

**Target Platform**: The `restv2-mailcow` container of feature 001 (php-fpm 8.4, non-root,
read-only root filesystem) on the mailcow network, reaching `dockerapi`, `memcached`,
`mysql-mailcow` and `redis-mailcow`.

**Project Type**: web service (REST API).

**Performance Goals**:

- SC-002: a page of 100 mailboxes out of 10,000 in 500 domains, with any filter, search and sort,
  in under 1 s (p95), and a page of 1,000 in under 5 s. A page costs a constant number of queries
  (page query, count query, five batch queries, three Redis `HMGET`s).
- SC-009: a bulk action on 100 mailboxes within 10 s, deletions within 60 s.
- SC-008: 95% of renames within 30 s (dominated by the container calls).

**Constraints**:

- No change to upstream tables, views or containers. php-fpm, the legacy UI and `/api/v1` keep
  working on the same rows.
- Stored values are byte-compatible with legacy: the `attributes` JSON holds strings, hashes use
  `MAILCOW_PASS_SCHEME`, Redis values use legacy's formats.
- Passwords and hashes never appear in responses, logs or audit records.
- Container calls are synchronous and bounded by `DOCKER_TIMEOUT` (60 s).

**Scale/Scope**: up to about 10,000 mailboxes in 500 domains per server, 16 operations, 8 user
stories, no new tables.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design (below).*

| Principle | Gate | Status |
|---|---|---|
| I. Contract-first | The 16 operations are authored in `api/modules/{mailboxes,mailbox-templates,password-policy}/` with `Mailbox*` and `PasswordPolicy` components before any code. `npx @redocly/cli@2.64.1 lint` passes (only 001's four known warnings). Every test request and response is validated (001 R13). | PASS |
| II. HTTP contract | Plural nouns, nested by ownership where real; the mailbox is addressed by its full address. Non-CRUD actions are sub-resources (`/rename`, `/deletion-preview`, `/bulk-actions`). PATCH takes JSON Merge Patch. `201` with `Location`, `200`, `204`. `{data, meta}` lists with `limit`/`offset`/`sort`/`order`/`q`. RFC 9457 problems. `ETag` and `If-Match` on mailboxes, templates and the policy read. Bytes for quotas, RFC 3339 UTC. Statuses `503` and `504` are justified under Complexity Tracking. | PASS (justified) |
| III. Upstream schema | No migration at all. Writes use the exact upstream columns of `init_db.inc.php` at `2026-09a` ([data-model](./data-model.md)). The rename's `SET FOREIGN_KEY_CHECKS = 0` is session-scoped, as legacy does, and alters no schema. | PASS |
| IV. Parity | Legacy was read (research R2–R19) and every side effect is reproduced and asserted in tests. Deviations are those of the spec's parity table, completed with the plan-level notes below. | PASS |
| V. Coexistence | Uses mailcow's dockerapi, memcached and Redis as legacy does. No other container is changed. | PASS |
| VI. Security | Object-level authorisation for admin, domain admin, mailbox user, read-only and read-write keys of each owner role. Out-of-scope mailboxes answer `404`. Domain-admin flags are enforced at creation, change and in bulk actions. Every state change and refusal is audited with one record per object. Passwords are write-only and redacted. | PASS |
| VII. Test-first | `tasks.md` puts tests before implementation in every phase. MariaDB with the real schema, real Redis; dockerapi and memcached faked with call assertions. | PASS |
| VIII. Simplicity | No new package. 002's `DomainTagSet` is generalised to a shared tag set on its second use, and 002's groupware, container, rate-limit and shared-store classes are extended rather than duplicated. | PASS |
| Cross-feature conventions | `404` for out-of-scope objects, `403` only for roles, flags and key scopes. Every bad entry named. Bulk actions per conventions (100, per-item outcome and version). Version tags over stored settings only, on every list entry; the policy tag equals 013's. Legacy PHP settings through 001's `MailcowConfig`. Creation follow-up steps after the atomic save, reported. Synchronous container calls with "outcome unknown" on timeout. Lists default to 25, at most 1,000, with `q`. | PASS |

**Post-design re-check (after Phase 1)**: PASS. The decisions that touched gates:

- The rename is a compensated sequence, not a transaction, because the mail store and SOGo are
  outside the database (IV, VI; Complexity Tracking).
- The rename's audit record is written after the last step (VI; Complexity Tracking).
- `503 temporary-failure` and `504 outcome-unknown` (II; Complexity Tracking).

## Parity notes added to the spec

The spec's parity table was completed with these plan-level rows (constitution IV requires every
deviation to be stated in the spec):

1. A rename also replaces the old address inside multi-recipient alias destinations. Legacy only
   updates aliases whose destination is exactly the old address.
2. The groupware directory entry gets a random unusable password hash. Legacy's hash is computed
   from undefined variables and is the same for every row (feature 002 decided the same).
3. With the groupware disabled (`SKIP_SOGO=y`), a rename skips the SOGo rename tool. Legacy calls
   it and ignores its result.
4. In replicated cluster mode, the maildir move of a deletion or rename is confirmed on the current
   node and broadcast to the others. Legacy only broadcasts, without any confirmation.
5. API-owned data of the mailbox user (feature 001's preferences) follows a rename and is removed
   by a deletion.
6. When no quota is sent and no template is named, a domain default of `0` that only results from
   an exhausted domain quota is not treated as "unlimited": the creation is refused with a quota
   validation error.

## Contract Operations

| Method and path | operationId | Auth | Story / FR |
|---|---|---|---|
| GET `/mailboxes` | listMailboxes | admin or domain admin, session or key (ro ok) | US1, FR-001–FR-016 |
| POST `/mailboxes` | createMailbox | admin or domain admin, session + CSRF or rw key | US2, FR-006, FR-018–FR-028 |
| GET `/mailboxes/capabilities` | getMailboxCapabilities | admin or domain admin, ro ok | US2, FR-064 |
| GET `/mailboxes/tags` | listMailboxTags | admin or domain admin, ro ok | US5, FR-066 |
| POST `/mailboxes/bulk-actions` | applyMailboxBulkAction | admin or domain admin, rw | US7, FR-054, FR-055, FR-067 |
| GET `/mailboxes/{mailbox}` | getMailbox | admin or domain admin, ro ok | US1, FR-017, FR-008–FR-011 |
| PATCH `/mailboxes/{mailbox}` | updateMailbox | admin or domain admin, rw | US3, US5, FR-029–FR-037 |
| DELETE `/mailboxes/{mailbox}` | deleteMailbox | admin or domain admin, rw | US4, FR-039–FR-042 |
| GET `/mailboxes/{mailbox}/deletion-preview` | previewMailboxDeletion | admin or domain admin, ro ok | US4, FR-065 |
| POST `/mailboxes/{mailbox}/rename` | renameMailbox | admin or domain admin, rw | US8, FR-043–FR-048 |
| GET `/mailbox-templates` | listMailboxTemplates | admin or domain admin, ro ok | US6, FR-049, FR-053 |
| POST `/mailbox-templates` | createMailboxTemplate | admin, rw | US6, FR-049–FR-051 |
| GET `/mailbox-templates/{mailboxTemplateId}` | getMailboxTemplate | admin or domain admin, ro ok | US6, FR-049 |
| PATCH `/mailbox-templates/{mailboxTemplateId}` | updateMailboxTemplate | admin, rw | US6, FR-050–FR-052 |
| DELETE `/mailbox-templates/{mailboxTemplateId}` | deleteMailboxTemplate | admin, rw | US6, FR-052, FR-053 |
| GET `/password-policy` | getPasswordPolicy | admin or domain admin, ro ok | US2, FR-063 |

The audit filters of FR-057 (`targetType`, `targetId` on `listAuditEvents`) were added to the
contract by feature 002; this feature adds the target types `mailbox` and `mailboxTemplate`. The
problem types are documented in [`docs/problems.md`](../../docs/problems.md): `address-in-use`,
`mailbox-limit-reached`, `mailbox-not-renamable`, `mailbox-rename-incomplete`, `outcome-unknown`,
`mailbox-template-name-exists` and `mailbox-template-protected` are new; `attribute-not-permitted`
and `temporary-failure` (002) and `domain-alias-limit-reached` (004) are reused with additional
extension members.

## Dependencies on earlier features

Features are implemented one after another. 003 starts when 001 and 002 are merged, and uses:

- **001**: the `api.v2` pipeline (`RequireAuth`, `EnforceKeyScope`, CSRF, `EnforcePendingActions`),
  `IdentityService` (scope and effective `PermissionFlag`s per request), `Principal`, `ProblemType`
  and `ProblemException`, `ApiFormRequest` with rule codes, `ListQuery`, `ETag`, `AuditLogger`,
  `MailcowConfig` (R14), `LegacyPasswordVerifier` (tests), `MailcowDateTime`, the models
  `Mailbox`, `Domain`, `DomainAdminAssignment`, `UserAcl`, `DaAcl`, `TfaEntry`, `Fido2Credential`,
  `SaslLogEntry`, `IdentityProviderSetting`, `Preference`, and the test toolkit (`TestCase`,
  `OpenApiContract`, `MailcowFactory`).
- **002**: `DomainName`, `DomainUsageCalculator`, `DomainTagSet`, `SogoDirectory`,
  `MemcachedFlusher`, `DockerApiClient` (`exec`), `RateLimitStore`, `SharedStore`,
  `AuditLogger::completeFollowUps()`, the audit target filters and index, the
  `RESTV2_BULK_LIMIT`, `RESTV2_TAGGING_LIMIT` and `RESTV2_DOCKER_TIMEOUT` settings, and the fakes
  `FakeDockerApiClient` and `FakeMemcachedFlusher`.

Pieces this feature adds for later features: `PasswordPolicyReader` (005, 007, 013),
`MailboxPasswordHasher` (005, 007, 012), `TaggedMailHandlingStore` (005), `SogoDirectory::refresh()`
(004, 005), the mailbox data mover used by rename (005 adds its own API-owned per-mailbox data to
it), and the shared `TagSet`.

## Project Structure

### Documentation (this feature)

```text
specs/003-mailboxes/
├── spec.md              # Feature specification (with clarifications and parity table)
├── plan.md              # This file
├── research.md          # Phase 0: decisions R1–R26
├── data-model.md        # Phase 1: upstream storage, Redis, container calls, state machines
├── quickstart.md        # Phase 1: validation guide
├── contracts/README.md  # Phase 1: pointer to api/ (the contract is not duplicated)
├── checklists/requirements.md
├── tasks.md             # Phase 2 (/speckit-tasks)
└── analysis.md          # /speckit-analyze summary
```

### Source Code (repository root)

Only the files this feature adds or changes are listed; the layout is feature 001's.

```text
api/
├── openapi.yaml                                   # tags and path references added
├── modules/mailboxes/{_index,mailboxes,mailbox,mailbox-capabilities,mailbox-tags,
│                      mailbox-bulk-actions,mailbox-deletion-preview,mailbox-rename}.yaml
├── modules/mailbox-templates/{_index,mailbox-templates,mailbox-template}.yaml
├── modules/password-policy/{_index,password-policy}.yaml
└── components/
    ├── schemas/Mailbox*.yaml, PasswordPolicy.yaml # plus appended shared enumerations
    ├── parameters/Mailbox{Address,Sort,TagSort,TemplateId,TemplateSort}.yaml
    └── responses/Mailbox*.yaml, PasswordPolicyResponse.yaml

app/
├── Clients/DockerApi/{DockerApiClient,HttpDockerApiClient,DockerCallResult}.php   # 002; + broadcast, reachability, per-request circuit breaker
├── Http/
│   ├── Controllers/Api/V2/
│   │   ├── MailboxController.php                  # index, show, store, update, destroy
│   │   ├── MailboxCapabilitiesController.php
│   │   ├── MailboxTagController.php               # index
│   │   ├── MailboxBulkActionController.php        # store
│   │   ├── MailboxDeletionPreviewController.php   # show
│   │   ├── MailboxRenameController.php            # store
│   │   ├── MailboxTemplateController.php          # index, show, store, update, destroy
│   │   └── PasswordPolicyController.php           # show
│   ├── Requests/Api/V2/
│   │   ├── ListMailboxesRequest.php, StoreMailboxRequest.php, UpdateMailboxRequest.php,
│   │   ├── RenameMailboxRequest.php, MailboxBulkActionRequest.php, ListMailboxTagsRequest.php,
│   │   └── ListMailboxTemplatesRequest.php, StoreMailboxTemplateRequest.php, UpdateMailboxTemplateRequest.php
│   └── Resources/Api/V2/{MailboxResource,MailboxCreatedResource,MailboxRenamedResource,
│                         MailboxTemplateResource,MailboxDeletionPreviewResource,
│                         MailboxCapabilitiesResource,MailboxTagUsageResource,PasswordPolicyResource}.php
├── Exceptions/{MailboxDependencyUnavailable,OutcomeUnknown}.php   # 503 temporary-failure, 504 outcome-unknown
├── Models/Mailcow/
│   ├── Mailbox.php                                # 001; + columns, casts, scopes (mailboxesOnly, inScope)
│   ├── {MailboxTemplate,MailboxTag,QuotaUsage,QuotaReplicaUsage,SpamAlias,
│   │    SenderAcl,Pushover}.php                   # new, read models (writes via services); Alias is 002's
├── Policies/{MailboxPolicy,MailboxTemplatePolicy}.php
├── Rules/{MailboxLocalPart,CustomAttributeName}.php
├── Services/
│   ├── Groupware/SogoDirectory.php                # 002; + refresh(array $usernames)
│   ├── Mailboxes/
│   │   ├── MailboxScope.php                       # scope resolution and 404 semantics
│   │   ├── MailboxAddress.php                     # value object: normalise, validate, split
│   │   ├── MailboxReader.php                      # representation, batch loading, list query
│   │   ├── MailboxListQuery.php                   # filters, search, sort expressions
│   │   ├── MailboxVersion.php                     # ETag inputs
│   │   ├── MailboxDefaults.php, ResolvedMailboxValues.php   # built-in defaults + template chain
│   │   ├── MailboxPermissionGuard.php             # FR-006/FR-035 choices and requiredFlags
│   │   ├── MailboxValidator.php                   # business rules, all violations collected
│   │   ├── AddressAvailability.php                # mailbox, resource, alias, temporary alias
│   │   ├── MailboxCreator.php, MailboxCreationReport.php
│   │   ├── MailboxUpdater.php
│   │   ├── MailboxDeleter.php
│   │   ├── MailboxDeletionPlanner.php, DeletionPlan.php     # shared by deletion and preview
│   │   ├── MailboxRenamer.php, RenameStep.php, RenameStepOutcome.php   # the compensated step sequence
│   │   ├── MailboxDataMover.php                   # rename's database part, both directions
│   │   ├── MailStoreCommands.php                  # maildir cleanup/move, doveadm ACLs, sogo rename
│   │   ├── MailboxBulkActions.php
│   │   ├── MailboxTagUsage.php
│   │   ├── MailboxCapabilities.php
│   │   ├── TaggedMailHandlingStore.php            # RCPT_WANTS_* hashes
│   │   └── MailboxTemplateService.php
│   ├── Passwords/{PasswordPolicyReader,EffectivePasswordPolicy,MailboxPasswordHasher,PasswordPolicyChecker}.php
│   ├── Config/MailcowConfig.php                   # 001; + showLastLogin(), mailboxDefaultAttributes()
│   └── Audit/AuditLogger.php                      # 001/002; + mailbox details whitelist
└── Support/Tags/TagSet.php                        # generalised from 002's DomainTagSet

config/restv2.php                                  # + mailbox settings (research R22)
resources/bin/export-mailcow-vars.php              # 001; + SHOW_LAST_LOGIN, MAILBOX_DEFAULT_ATTRIBUTES
routes/api.php                                     # + the 16 routes, literal paths before {mailbox}
deploy/nginx/site.restv2.custom                    # 001; fastcgi_read_timeout for long renames

tests/
├── Support/Fakes/FakeDockerApiClient.php          # 002; + scripted exec answers, broadcast log
├── Support/Mailcow/MailcowFactory.php             # 001/002; + mailbox rows exactly as legacy
├── Feature/Mailboxes/                             # one test class per operation and concern
├── Feature/Groupware/SogoDirectoryRefreshTest.php, Feature/Clients/DockerApiBroadcastTest.php
├── Feature/Security/MailboxAuthorizationMatrixTest.php
├── Feature/MailboxTemplates/
├── Feature/PasswordPolicy/
├── Feature/Performance/MailboxListPerformanceTest.php
├── Unit/Mailboxes/, Unit/Passwords/
└── fixtures/mailcow-inc/                          # 001; + vars.local.inc.php cases for 003
```

**Structure Decision**: The single Laravel project of feature 001. Mailbox business logic lives in
`app/Services/Mailboxes/`, split by operation so that the bulk actions reuse the single-operation
services. Password handling lives in `app/Services/Passwords/` because features 005, 007, 012 and
013 reuse it. Container and cache access stays behind 002's `app/Clients/` interfaces.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Status `503` (`temporary-failure`) and `504` (`outcome-unknown`), not in constitution II's list | FR-040, FR-048 and FR-058 require distinct, documented answers for "a mailcow service is down, nothing changed, retry" and "the container API did not answer, outcome unknown". RFC 9110 defines `503` with `Retry-After` and `504` for exactly these. Feature 001 already justified `503`; 002 uses it for the same type. | `500` cannot be told apart from a bug, so clients would neither retry a deletion safely nor warn about an unknown rename step. `409` would wrongly blame the request. |
| The rename is a compensated sequence of steps instead of one transaction (constitution IV and VI expect atomic changes) | The maildir, the IMAP ACLs and SOGo's data live outside MariaDB, and Dovecot needs the renamed `mailbox` row before ACLs can be set for the new address. FR-048 requires "complete or leave as it was". | One transaction cannot cover dockerapi calls. Legacy's order without undo leaves users without mail or shared folders when a step fails. |
| The rename's audit record is written after the last step, in its own transaction, instead of inside the change's transaction (001 R22) | The outcome (`done`, undone, incomplete) is only known after every step. A record written with the database step would claim success for a rename that is undone afterwards. If the final write fails, the steps are undone and the answer is `500`, so the rule "an unaudited change does not succeed" still holds. | Writing it inside the database step and completing it later (002's pending pattern) would need a mutable outcome, which the immutable audit model forbids. Two records would break SC-010 (exactly one per object). |
