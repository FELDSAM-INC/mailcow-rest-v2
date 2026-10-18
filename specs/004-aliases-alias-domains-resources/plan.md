# Implementation Plan: Aliases, Alias Domains, Resources and Sender Permissions

**Branch**: `main` (no feature branches, constitution 1.1.0) | **Date**: 2026-10-18 |
**Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/004-aliases-alias-domains-resources/spec.md`

## Summary

Feature 004 lets administrators, domain administrators and their API keys manage, on an existing
mailcow server:

- aliases and catch-all aliases, delivering to addresses or performing a special action, with
  list filters, search, sorting, single-object create/change/delete, copies over alias domains and
  the legacy mass actions as bulk actions;
- alias domains, with the legacy creation side effects (domain map, initial rate limit, DKIM key)
  and the alias domain cleanup that feature 002 built for both features;
- groupware resources (rooms, equipment, groups);
- what a mailbox may send as: explicit sender permissions, "any address" and external sender
  addresses;
- a capabilities read with the server settings these forms need.

The technical approach reuses everything features 001 to 003 decided and built, and adds:

- the OpenAPI contract first: 23 operations in four modules (`aliases`, `alias-domains`,
  `resources`, `sender-settings`), lint-clean, following `docs/conventions.md` (capabilities at
  `/capabilities/aliases`, the top-level helper list `/sender-permission-candidates`, bulk bodies
  with `targets`, the shared permission refusals);
- services per write path (`Alias{Creator,Updater,Deleter,Copier}`,
  `AliasDomain{Creator,Updater,Deleter}`, `Resource{Creator,Updater,Deleter}`,
  `SenderSettingsUpdater`) that port legacy `mailbox()` line by line, reproduce its writes to
  `alias`, `alias_domain`, `mailbox`, `sender_acl` and the `sogo_*` tables, and reuse 002's and
  003's classes for the domain map, rate limits, DKIM keys, groupware directory, alias domain
  cleanup and address checks;
- one transaction per object with the audit record inside it, the primary domain row lock for the
  alias limit, 003's `GET_LOCK` address lock for uniqueness, version tags over stored settings, and
  refusal auditing through the shared `RefusalAuditor`;
- tests first, on MariaDB with the `2026-09a` schema fixture and a real Redis, with contract
  validation of every request and response.

No API-owned table, migration or Composer package is added.

## Technical Context

**Language/Version**: PHP 8.4 (constitution: 8.3 or newer), Laravel 13.x, as in feature 001.

**Primary Dependencies**: Those of feature 001 only; the `intl`, `openssl` and phpredis extensions
are in its image. Feature 002's and 003's classes are reused ([research R24](./research.md#r24-dependencies-on-features-001-to-003)).
No new package ([R25](./research.md#r25-dependencies-constitution-viii)).

**Storage**: mailcow's MariaDB 10.11 (upstream schema `2026-09a`, `db_version 18082026_1200`):
`alias`, `alias_domain`, `mailbox` (resources), `sender_acl`, `domain_wide_footer` (exclusion
cleanup), `_sogo_static_view` and the `sogo_*` tables are written through services, and through
002's `AliasDomainCleanup` also `spamalias`, `bcc_maps` and `filterconf`; `domain`,
`domain_admins`, `admin`, `da_acl` are read. mailcow's Redis (connection `mailcow`, database 0):
`DOMAIN_MAP`, `RL_VALUE`, `DKIM_PUB_KEYS`, `DKIM_SELECTORS`, `DKIM_PRIV_KEYS`. memcached is flushed
after groupware changes. MariaDB named locks guard address uniqueness. Audit records go to 001's
`restv2_audit_events`. See [data-model.md](./data-model.md).

**Testing**: PHPUnit 12 feature and unit tests, written first and observed failing, run in the dev
container on MariaDB with `tests/fixtures/schema/2026-09a.sql` and a real Redis (001 R3). Every
request and response is validated against the bundled contract (001 R13). memcached, dockerapi and
the ISPConfig DNS client are faked by 002's fakes, which record calls. A performance test (group
`performance`) seeds 200,000 aliases for SC-005 and SC-010.

**Target Platform**: The API container of feature 001 (`php:8.4-fpm-alpine`, non-root, read-only
root filesystem), reached by nginx-mailcow over FastCGI at `/api/v2/`.

**Project Type**: web service (REST API).

**Performance Goals**:

- SC-005: p95 of a page of 25 aliases under 1 s with 5,000 domains, 100,000 mailboxes and 200,000
  aliases (primary-key ordered page, anti-joined count, no added index).
- SC-010: a bulk action on 100 aliases within 10 s (per target: a few indexed statements and an
  incremental groupware refresh); alias domain bulk actions rebuild the groupware once.
- SC-003: mail to a new alias or alias domain accepted within 1 minute; in practice at once,
  because Postfix and the mail filter read the database and `DOMAIN_MAP` directly.

**Constraints**:

- No change to upstream tables, views, indexes or containers; legacy UI and `/api/v1` keep working
  on the same rows.
- Every legacy side effect is reproduced, apart from the deviations stated in the spec.
- Each object's changes are atomic with its audit record; alias domain creation and deletion are
  atomic with their Redis entries (FR-062); creation follow-ups never undo the creation.
- Concurrent requests never exceed an alias limit or hand out one address twice (FR-022, R6).
- Errors are RFC 9457 problems, not localised; permission refusals use only `forbidden`,
  `permission-flag-required` and `attribute-not-permitted`.

**Scale/Scope**: 23 operations in 4 modules, 8 user stories (3 × P1, 3 × P2, 2 × P3), 68
functional requirements. Target servers: up to 5,000 domains, 100,000 mailboxes, 200,000 aliases.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design (below).*

| Principle | Gate | Status |
|---|---|---|
| I. Contract-first | All 23 operations are authored in `api/modules/{aliases,alias-domains,resources,sender-settings}/` with feature-named components before any code; `npx @redocly/cli@2.64.1 lint api/openapi.yaml` reports no error or warning for them. Every test request and response is validated (001 R13); the coverage check includes the new operations. | PASS |
| II. HTTP contract | Plural nouns; non-CRUD actions are sub-resources (`/aliases/{aliasId}/alias-domain-copies`, `/{collection}/bulk-actions`, `/mailboxes/{mailbox}/sender-settings`). PATCH takes JSON Merge Patch. 201 with `Location`, 200, 204. `{data, meta}` lists, `{data}` single resources (with `meta.copies` for the bulk copy). RFC 9457 problems with `errors` on 422. `ETag` and `If-Match` on aliases, alias domains, resources and sender settings, and a version per bulk target. camelCase, booleans, RFC 3339 UTC. `503 temporary-failure` is justified under Complexity Tracking. | PASS (justified) |
| III. Upstream schema | No migration, table, column or index is added. Code-only uniqueness uses `GET_LOCK()`. The feature writes only existing upstream tables through services and adds no API-owned table. | PASS |
| IV. Parity | Legacy `mailbox()` (alias, alias domain, resource, sender ACL branches, getters, groupware update), `ratelimit()`, `dkim('add')`, the access helpers and the Postfix maps were read (research). Writes reproduce every legacy side effect, reusing 002's and 003's ports; deviations are those of the spec's parity table, which this plan completed with plan-level rows (below). | PASS |
| V. Coexistence | No change to other containers. Postfix, Dovecot, Rspamd and SOGo read the same rows and hashes as for legacy writes. | PASS |
| VI. Security | Object-level authorisation for administrators, domain administrators (active assignments, alias domains of their domains) and their keys, with flags `aliasDomains`, `aliasExternalGoto`, `extendSenderAcl`, `ratelimit` evaluated per request. Out-of-scope objects answer `404`. Read-only keys are refused on writes. Every state change is audited in its transaction, and every refusal of an authenticated caller (read-only key, restricted session, role, flag, out of scope, validation, conflict) through the shared `RefusalAuditor`; only `401`s and CSRF failures are not. No secrets are handled. | PASS (justified) |
| VII. Test-first | `tasks.md` orders tests before implementation in every phase; MariaDB with the real schema and real Redis; only outbound services are faked. Side effects (rows, Redis hashes, groupware rows, memcached flush, audit records) are asserted. | PASS |
| VIII. Simplicity | No new package. Shared pieces of 002 and 003 reused unchanged; bulk actions reuse the single-object service methods through the shared `BulkActionRunner`. | PASS |
| Cross-feature conventions | `404` for out-of-scope objects; `forbidden`, `permission-flag-required` (`requiredFlags`) and `attribute-not-permitted` (`attributes`, `requiredFlags`) for permission refusals. Whole-request refusal naming every bad entry. Bulk actions at `POST /{collection}/bulk-actions` with `{action, targets}`, ≤ 100 targets, per-target authorisation, outcome, version and audit. Version tags over stored settings on every representation and list entry, with no secret. Lists 25/1,000 with `q`. Capabilities at `/capabilities/aliases`; helper list at `/sender-permission-candidates`. History through the generic `targetType`/`targetId` filters, with four new `AuditTarget` values. Refusal auditing through `RefusalAuditor`. `GET_LOCK` for code-only uniqueness. Settings owned by other features given only at creation (initial rate limit, DKIM key), follow-ups reported. Shared alias domain cleanup (002). Legacy PHP settings (`ALIAS_DISABLE_EXTERNAL_DOMAINS`) as server settings with upstream defaults. | PASS |

**Post-design re-check (after Phase 1)**: PASS. The decisions that touch gates:

- Alias domain creation follows 002's flow: row, map entry and audit record (with
  `creationReport: "pending"`) atomic, follow-ups after the commit, the record completed once with
  002's justified `AuditLogger::completeFollowUps()` (VI, Complexity Tracking).
- Incremental groupware refresh for aliases with external destinations instead of legacy's full
  rebuild (IV): identical rows, recorded as a plan-level deviation.
- External destinations: a missing `aliasExternalGoto` is `attribute-not-permitted` and the
  server-wide switch is a validation rule, so no feature-specific permission problem exists.

## Parity notes added to the spec

The spec's "Legacy Parity and Deliberate Deviations" table was completed with these plan-level
rows (constitution IV), and FR-006, FR-027, FR-043, FR-045, FR-065, SC-008 and an assumption were
aligned:

1. An alias change whose destinations include an address that is not an active local mailbox
   refreshes the affected mailboxes' groupware rows instead of rebuilding all rows; the resulting
   rows are identical (research R9).
2. Resource changes trim the description and refuse an empty one; resource deletion accepts only
   resource rows (research R13).
3. Alias domain deletion removes the name from the target domain's footer exclusions (FR-045,
   research R11). SC-008 now states that external sender addresses naming the alias domain are
   kept and that address-level data of other features is out of its scope.
4. The permission refusals follow `docs/conventions.md` (FR-006, FR-027, FR-065), the DKIM
   publication outcomes use feature 002's values (FR-043), and the bulk limit and page sizes are
   fixed API-wide (assumptions).

## Contract Operations

| Method and path | operationId | Auth | Story / FR |
|---|---|---|---|
| GET `/aliases` | listAliases | admin or domain admin; session or key | US1, FR-011–FR-017, FR-019 |
| POST `/aliases` | createAlias | admin or domain admin; session + CSRF or read-write key | US2, FR-008–FR-010, FR-020–FR-028, FR-032–FR-034 |
| POST `/aliases/bulk-actions` | applyAliasBulkAction | as the single change, deletion or copy | US6, FR-036, FR-037, FR-058, FR-059 |
| GET `/aliases/{aliasId}` | getAlias | admin or domain admin | US1, FR-011–FR-013, FR-018 |
| PATCH `/aliases/{aliasId}` | updateAlias | admin or domain admin; write | US3, FR-025–FR-034, FR-060, FR-061 |
| DELETE `/aliases/{aliasId}` | deleteAlias | admin or domain admin; write | US3, FR-033–FR-035 |
| POST `/aliases/{aliasId}/alias-domain-copies` | copyAliasOverAliasDomains | admin or domain admin; write | US6, FR-036, FR-037 |
| GET `/capabilities/aliases` | getAliasesCapabilities | admin or domain admin; session or key (read-only too) | FR-068 |
| GET `/alias-domains` | listAliasDomains | admin or domain admin | US4, FR-038, FR-039 |
| POST `/alias-domains` | createAliasDomain | admin, or domain admin with `aliasDomains` (`ratelimit` for a rate limit); write | US4, FR-040–FR-043, FR-062 |
| POST `/alias-domains/bulk-actions` | applyAliasDomainBulkAction | as the single change or deletion | US6, FR-058, FR-059 |
| GET `/alias-domains/{aliasDomain}` | getAliasDomain | admin or domain admin | US4, FR-038, FR-039 |
| PATCH `/alias-domains/{aliasDomain}` | updateAliasDomain | admin or domain admin; write | US4, FR-044 |
| DELETE `/alias-domains/{aliasDomain}` | deleteAliasDomain | admin or domain admin; write | US4, FR-045, FR-062 |
| GET `/resources` | listResources | admin or domain admin | US7, FR-046, FR-047 |
| POST `/resources` | createResource | admin or domain admin; write | US7, FR-048–FR-050 |
| POST `/resources/bulk-actions` | applyResourceBulkAction | as the single change or deletion | US6, US7, FR-058, FR-059 |
| GET `/resources/{resourceAddress}` | getResource | admin or domain admin | US7, FR-046, FR-047 |
| PATCH `/resources/{resourceAddress}` | updateResource | admin or domain admin; write | US7, FR-051 |
| DELETE `/resources/{resourceAddress}` | deleteResource | admin or domain admin; write | US7, FR-052 |
| GET `/mailboxes/{mailbox}/sender-settings` | getMailboxSenderSettings | admin or domain admin | US5, FR-053 |
| PATCH `/mailboxes/{mailbox}/sender-settings` | updateMailboxSenderSettings | admin or domain admin (`extendSenderAcl` for external addresses); write | US5, FR-054, FR-055, FR-057 |
| GET `/sender-permission-candidates` | listSenderPermissionCandidates | admin or domain admin | US5, FR-056 |

US8 (FR-063, FR-064) is served by the audit records of every write and refusal and by 001's
`listAuditEvents` with the generic `targetType`/`targetId` filters. Mailbox users get
`403 forbidden` on every operation. Problem types are documented in
[`docs/problems.md`](../../docs/problems.md).

## Project Structure

### Documentation (this feature)

```text
specs/004-aliases-alias-domains-resources/
├── spec.md              # Feature specification (with clarifications and parity table)
├── plan.md              # This file
├── research.md          # Phase 0: decisions R1–R26
├── data-model.md        # Phase 1: upstream tables, Redis hashes, locks, statements, validation, tags
├── quickstart.md        # Phase 1: validation guide
├── contracts/README.md  # Phase 1: pointer to api/ (the contract is not duplicated)
├── checklists/
└── tasks.md             # Phase 2 (/speckit-tasks); the analysis summary is kept by the orchestrator
```

### Source Code (repository root)

Only the files this feature adds or changes are listed; the rest is the layout of features 001 to
003, whose shared classes are reused unchanged (research R24).

```text
api/                                         # Contract (source of truth), already authored
├── openapi.yaml                             # + 4 tags, + 13 path entries
├── modules/
│   ├── aliases/{_index,aliases,alias,alias-alias-domain-copies,aliases-bulk-actions,
│   │            capabilities-aliases}.yaml
│   ├── alias-domains/{_index,alias-domains,alias-domain,alias-domains-bulk-actions}.yaml
│   ├── resources/{_index,resources,resource,resources-bulk-actions}.yaml
│   └── sender-settings/{_index,mailbox-sender-settings,sender-permission-candidates}.yaml
└── components/
    ├── parameters/{AliasId,AliasSort,AliasDomainName,AliasDomainSort,ResourceAddress,ResourceSort,
    │               SenderSettingsMailbox,MailboxSenderPermissionCandidateSort}.yaml
    ├── schemas/Alias*.yaml, Resource*.yaml, MailboxSenderSettings*.yaml,
    │           MailboxSenderPermission*.yaml, MailboxImplicitSenderAddress.yaml
    │           (+ values appended to KnownProblemType, AuditEventType, AuditTarget, ValidationRuleCode)
    └── responses/Alias*.yaml, Resource*.yaml, MailboxSender*.yaml

app/
├── Http/
│   ├── Controllers/Api/V2/
│   │   ├── AliasController.php                      # index, show, store, update, destroy
│   │   ├── AliasCopyController.php                  # store (single copy)
│   │   ├── AliasBulkActionController.php            # store (incl. copyOverAliasDomains)
│   │   ├── AliasesCapabilitiesController.php        # show
│   │   ├── AliasDomainController.php                # index, show, store, update, destroy
│   │   ├── AliasDomainBulkActionController.php      # store
│   │   ├── ResourceController.php                   # index, show, store, update, destroy
│   │   ├── ResourceBulkActionController.php         # store
│   │   ├── MailboxSenderSettingsController.php      # show, update
│   │   └── SenderPermissionCandidateController.php  # index
│   ├── Requests/Api/V2/
│   │   ├── ListAliasesRequest.php, StoreAliasRequest.php, UpdateAliasRequest.php,
│   │   │   AliasBulkActionRequest.php
│   │   ├── ListAliasDomainsRequest.php, StoreAliasDomainRequest.php, UpdateAliasDomainRequest.php,
│   │   │   AliasDomainBulkActionRequest.php
│   │   ├── ListResourcesRequest.php, StoreResourceRequest.php, UpdateResourceRequest.php,
│   │   │   ResourceBulkActionRequest.php
│   │   └── UpdateMailboxSenderSettingsRequest.php, ListSenderPermissionCandidatesRequest.php
│   └── Resources/Api/V2/
│       ├── AliasResource.php, AliasCopyReportResource.php, AliasesCapabilitiesResource.php
│       ├── AliasDomainResource.php, AliasDomainCreatedResource.php
│       ├── GroupwareResourceResource.php            # contract `Resource`
│       └── MailboxSenderSettingsResource.php, SenderPermissionCandidateResource.php
├── Models/Mailcow/
│   ├── Alias.php                                    # 002's model; + scope notOwnAlias, accessors
│   └── Resource.php                                 # new: table mailbox, resource kinds only
├── Policies/{AliasPolicy,AliasDomainPolicy,ResourcePolicy,MailboxSenderSettingsPolicy}.php
├── Services/
│   ├── Aliases/{AliasAddress,AliasDestination,AliasAddressAvailability,AliasQuery,AliasLimit,
│   │            AliasVersion,AliasCreator,AliasUpdater,AliasDeleter,AliasCopier,AliasCopyOutcome,
│   │            AliasSenderAclEffects,AliasesCapabilitiesService}.php
│   ├── AliasDomains/{AliasDomainQuery,AliasDomainVersion,AliasDomainCreator,
│   │                 AliasDomainCreationReport,AliasDomainUpdater,AliasDomainDeleter}.php
│   │                                                # AliasDomainCleanup is 002's
│   ├── Resources/{ResourceAddressDeriver,ResourceQuery,ResourceVersion,ResourceCreator,
│   │              ResourceUpdater,ResourceDeleter}.php
│   ├── SenderSettings/{SenderEntry,SenderSettingsReader,SenderSettingsUpdater,
│   │                   PermissionCandidateQuery}.php
│   ├── Audit/RefusalTargets/{AliasTargetResolver,AliasDomainTargetResolver,
│   │                         ResourceTargetResolver,MailboxSenderSettingsTargetResolver}.php
│   └── Config/MailcowConfig.php                     # + aliasDisableExternalDomains()
└── Support/
    ├── Bulk/BulkActionRunner.php                    # shared (created here at the latest)
    └── Mailcow/UnicodeSearch.php                    # readable-form search for IDN values

resources/bin/export-mailcow-vars.php                # + ALIAS_DISABLE_EXTERNAL_DOMAINS
routes/api.php                                       # + the 23 routes (literal paths first),
                                                     #   each write with ->defaults('restv2.audit', …)
database/factories/MailcowRows.php                   # + alias, resource, sender ACL and footer rows
                                                     #   not yet provided by 002/003
tests/
├── Support/Mailcow/MailcowFactory.php               # + the same helpers
├── Feature/Dependencies/Feature004DependenciesTest.php
├── Feature/Aliases/…Test.php
├── Feature/AliasDomains/…Test.php
├── Feature/Resources/…Test.php
├── Feature/SenderSettings/…Test.php
├── Feature/Groupware/AliasGroupwareRefreshTest.php
├── Feature/Audit/AliasFeatureAuditTest.php
├── Feature/Security/Feature004AuthorizationMatrixTest.php
├── Feature/Performance/AliasListPerformanceTest.php # group "performance"
└── Unit/Services/{AliasAddressTest,AliasDestinationTest,AliasVersionTagTest,
                   ResourceAddressDeriverTest,SenderEntryTest,UnicodeSearchTest}.php
```

**Structure Decision**: The single Laravel project of feature 001. One service per write path
owns the upstream writes and side effects of this feature (so bulk actions reuse them); the shared
pieces of 002 and 003 are called, not copied. Query objects build the scoped, filtered, sorted
list queries. Controllers are thin, one per resource or action sub-resource.

### Dependencies on earlier features

Features are implemented in order 001, 002, 003, 004. This feature calls, unchanged:

- **001**: pipeline, roles, scope and flags; `ListQuery`, `ETag`, problem rendering,
  `ApiFormRequest`; `AuditLogger`; `MailcowConfig`; test toolkit and `MailcowFactory`.
- **002**: `DomainName`, `DomainScope`, `DomainUsageCalculator`, the `AliasDomain` and `Alias`
  models, `SharedStore`, `RateLimitStore`, `DkimKeyGenerator`, `DkimDnsPublisher`,
  `SogoDirectory::rebuild()`, `MemcachedFlusher`, `AliasDomainCleanup`,
  `AuditLogger::completeFollowUps()`, the `targetType`/`targetId` audit filters, the fakes, and the
  `attribute-not-permitted` and `temporary-failure` problem types.
- **003**: `AddressAvailability`, `AddressLock`, `MailboxScope`, `SogoDirectory::refresh()`, the
  `SenderAcl` and `SpamAlias` models and the `address-in-use` problem type.
- **008, or the first implemented feature**: `RefusalAuditor` (route default `restv2.audit`).

Phase 2 of `tasks.md` pins each of them with a test before any story starts.

## Owner-level questions (defaults applied)

1. Deleting a catch-all alias removes the explicit domain permissions for its domain, because both
   are stored as `@domain` (legacy behaviour, research R8). **Default: legacy parity**, documented in
   the contract. The alternative is to exclude catch-all addresses from FR-030/FR-032/FR-035.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Status `503` (not in constitution II's list) for `temporary-failure` on alias domain creation, deletion and reads | FR-062 requires a distinct, retryable refusal when mailcow's shared store is down, with no partial change. RFC 9110 defines 503 with `Retry-After` for this; features 001 and 002 use it the same way. | `500` cannot be told apart from a bug, so clients would not retry; answering alias domain reads without the rate limit would show wrong data. |
| The audit record of an alias domain creation is completed once after the commit (002's `AuditLogger::completeFollowUps()`) | FR-063 wants the creation report in the one record of the creation; the follow-ups (DNS publication up to 15 s, the groupware rebuild) run after the commit, as feature 002 justified for domains. | Holding the transaction across the follow-ups would hold row locks during external calls; a second record would break SC-007. |
