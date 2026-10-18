# Implementation Plan: Domains

**Branch**: `main` (no feature branches, constitution 1.1.0) | **Date**: 2026-10-18 |
**Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/002-domains/spec.md`

## Summary

Feature 002 lets administrators manage the mail domains of an existing mailcow server through
`/api/v2`, and domain administrators see and partly edit their own:

- list, search, filter, sort and inspect domains with the usage figures of the legacy screens;
- create a domain from a template, with the legacy side effects (domain map, rate limit, DKIM key,
  optional groupware restart) and a per-step creation report;
- edit limits, quotas, relay options, Global Address List flag, description, tags and active state,
  with version tags and the domain administrators' permission rules;
- delete an empty domain with full cleanup, including its alias domains, BCC maps and footer, after
  an optional read-only deletion preview;
- bulk activate, deactivate and delete; domain templates; the domain-wide footer; domain
  capabilities; the distinct tag list; and audit filtering by target.

The technical approach:

- The OpenAPI 3.1 contract was authored first: 16 operations in two modules
  (`api/modules/domains/`, `api/modules/domain-templates/`) plus two filters on 001's
  `GET /audit-events`. It lints clean.
- Legacy logic of `mailbox('add'|'edit'|'delete'|'get', 'domain' | 'domain_templates' |
  'domain_wide_footer' | 'domain_details' | 'tags_domain')`, `ratelimit('edit', 'domain')`,
  `dkim('add')`, the fork's `dkim_update_in_dns()`, `update_sogo_static_view()` and `docker()` is
  ported into services under `app/Services/` and clients under `app/Clients/`.
- Writes to the domain row, its tags and mailcow's Redis `DOMAIN_MAP` are atomic (database
  transaction plus compensated Redis write). Follow-up steps run after the commit and are reported.
- Every write is audited in its transaction; creation and deletion complete their audit record with
  the post-commit outcomes.
- Feature 001's foundation is reused unchanged: pipeline, `ListQuery`, `ETag`, `AuditLogger`,
  `MailcowConfig`, schema fixture, contract-validated tests on MariaDB and real Redis.

## Technical Context

**Language/Version**: PHP 8.4, Laravel 13.x (feature 001).

**Primary Dependencies**: Feature 001's set, nothing new. The `intl` extension (IDN), `openssl`
(DKIM keys) and phpredis are already in 001's image. Laravel's HTTP client talks to dockerapi and
to ISPConfig's JSON remote API; memcached is flushed over a plain socket. See
[research D25](./research.md#d25-dependencies-constitution-viii).

**Storage**: mailcow's MariaDB (upstream schema `2026-09a`), tables `domain`, `tags_domain`,
`templates`, `domain_wide_footer`, `_sogo_static_view` and the cleanup tables of the
[data model](./data-model.md). mailcow's Redis: `DOMAIN_MAP`, `RL_VALUE`, `DKIM_SELECTORS`,
`DKIM_PUB_KEYS`, `DKIM_PRIV_KEYS`. No new API-owned table; one index on 001's
`restv2_audit_events`.

**Testing**: PHPUnit 12 feature and unit tests, written first, on MariaDB 10.11 with the schema
fixture and on a real Redis (database 15). dockerapi, memcached and ISPConfig are faked behind
`App\Clients\*` interfaces with call assertions; their adapters are tested with `Http::fake()` and
a local socket. Every request and response is validated against the contract.

**Target Platform**: 001's `restv2-mailcow` container. It additionally needs the
`COMPOSE_PROJECT_NAME` variable and network access to `dockerapi:443` and `memcached:11211` on
`mailcow-network`, which it already joins.

**Project Type**: web service (REST API).

**Performance Goals**: SC-005: 95% of list pages (25 domains with usage figures) within 1 s on
5,000 domains and 100,000 mailboxes, by computing usage for the page only (research D5). SC-010:
a bulk action on 100 domains within 10 s (deletions within 60 s), with one groupware rebuild per
request.

**Constraints**:

- No change to upstream tables, views or containers; the legacy UI and `/api/v1` keep working on
  the same data, and legacy keeps reading templates and footers written by the API.
- The domain row, tags and domain map entry are stored together or not at all (FR-024).
- Follow-up steps never undo a creation; a failed stored-mail move never stops a deletion.
- Outbound calls are bounded: dockerapi by `DOCKER_TIMEOUT` (60 s), ISPConfig by 15 s, memcached
  by 2 s.
- Secrets (ISPConfig password, DKIM private keys) never appear in logs, audit records or responses.

**Scale/Scope**: up to about 5,000 domains, 100,000 mailboxes and 100 templates per server. 16
operations, 8 user stories, 66 functional requirements.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design (below).*

| Principle | Gate | Status |
|---|---|---|
| I. Contract-first | The 16 operations are described in `api/modules/domains/` and `api/modules/domain-templates/` with `Domain*` components, before any code. `npx @redocly/cli@2.64.1 lint api/openapi.yaml` passes (only 001's four accepted warnings). Every test request and response is validated; the coverage check includes the new operations. Additive only. | PASS |
| II. HTTP contract | Plural nouns and sub-resources (`/domains/{domain}/footer`, `/domains/{domain}/deletion-preview`, `/domains/bulk-actions`); `PATCH` is JSON Merge Patch; `201` + `Location`, `200`, `204`; `{data, meta}` lists with `limit`, `offset`, `sort`, `order`, `q`; RFC 9457 problems with `errors` on `422`; `ETag`/`If-Match` on domains, footers and templates, `etag` on every list entry; bytes for quotas, booleans, RFC 3339 UTC. `503` is justified under Complexity Tracking. | PASS (justified) |
| III. Upstream schema | No migration touches an upstream table; the only migration adds an index to `restv2_audit_events` (001's `MigrationPrefixTest` covers it). | PASS |
| IV. Parity | Legacy code was read and ported (research D4–D22). Every upstream write goes through `app/Services/` (`DomainCreator`, `DomainUpdater`, `DomainDeleter`, `DomainFooterService`, `DomainTemplateService`, `AliasDomainCleanup`, `OrphanedDomainAdminRemover`, `SogoDirectory`, `DkimKeyGenerator`, `RateLimitStore`) and reproduces the side effects: tags, `sender_acl`, `DOMAIN_MAP`, `RL_VALUE`, DKIM hashes, `_sogo_static_view` plus memcached, the SOGo restart and the dovecot maildir cleanup through dockerapi, the ISPConfig push. Deviations are those of the spec's parity table, completed with three plan-level notes (below). | PASS |
| V. Coexistence | No change to other containers or files; the API container reaches dockerapi, memcached and ISPConfig as legacy's php-fpm does. Only `deploy/nginx/site.restv2.custom` (own file) raises its body size. | PASS |
| VI. Security | Object-level authorisation for the three roles, `domain_desc`, read-write and read-only keys: role checks first (`403`), scope next (`404`, also for alias domain names), attribute permissions (`403 attribute-not-permitted`). Read-only keys are refused on writes by 001's `EnforceKeyScope`. Every state change and every refusal after scope resolution is audited in its transaction; creation and deletion complete their record once (Complexity Tracking). The footer HTML is documented as operator-authored and to be rendered safely. Secrets stay out of logs. | PASS (justified) |
| VII. Test-first | `tasks.md` puts tests before implementation in every phase; MariaDB with the real schema, real Redis; outbound services faked with call assertions; side effects asserted on rows and Redis keys. | PASS |
| VIII. Simplicity | No new package or extension. Interfaces only for faked outbound services (`DockerApiClient`, `MemcachedFlusher`, `IspconfigDnsClient`). Shared services are created because later features need them (research D27), not speculatively. | PASS |
| Cross-feature conventions | Out-of-scope `404`; whole-request refusal with every bad entry named; field errors with rule codes; keys act as their owner; bulk actions ≤ limit with per-item results and versions; version tags over stored settings; legacy PHP settings as server settings (`TAGGING_LIMIT`, `DOCKER_TIMEOUT`); fork behaviour as settings with upstream defaults (DKIM selector, ISPConfig push); settings owned by others given only at creation (rate limit, DKIM); follow-up outcomes reported; alias domain cleanup implemented once; one audit record per object with `bulkRequestId`; reads not audited; no caching. | PASS |
| Runtime constraints | Stateless; configuration from environment variables (`COMPOSE_PROJECT_NAME`, `RESTV2_*`); structured logs. | PASS |

**Post-design re-check (after Phase 1)**: PASS. The contract, data model and research add no
unjustified violation. Decisions that touched gates: atomic domain map write with compensation
(IV, FR-024), audit completion after commit (VI), dockerapi without TLS verification as in legacy
(V, IV), and the deletion lock on the mailbox range (IV).

## Parity notes added to the spec

Constitution IV requires every deviation in the spec. The spec's parity table was completed with:

1. The groupware directory gets a fresh random placeholder password per rebuild; legacy builds its
   hash from undefined variables.
2. The stored-mail move is requested after the deletion is committed, not before.
3. Footer exclusions are stored trimmed and lower-cased with an ASCII domain part.

For consistency with feature 004 (FR-045 there), FR-037 now names the sender permissions and spam
lists that the shared alias domain cleanup removes.

## Contract Operations

| Method and path | operationId | Auth | Story / FR |
|---|---|---|---|
| GET `/domains` | listDomains | admin, domain admin (session or key) | US1, FR-011–FR-015 |
| POST `/domains` | createDomain | admin (session + CSRF, or read-write key) | US2, FR-017–FR-029 |
| POST `/domains/bulk-actions` | applyDomainBulkAction | admin (session + CSRF, or read-write key) | US6, FR-062, FR-063 |
| GET `/domains/{domain}` | getDomain | admin, domain admin | US1, FR-006–FR-010, FR-016 |
| PATCH `/domains/{domain}` | updateDomain | admin, domain admin (writes) | US3, FR-030–FR-034, FR-041–FR-044 |
| DELETE `/domains/{domain}` | deleteDomain | admin (writes) | US4, FR-035–FR-040 |
| GET `/domains/{domain}/deletion-preview` | previewDomainDeletion | admin (read-only keys too) | US4, FR-065 |
| GET `/domains/{domain}/footer` | getDomainFooter | admin, domain admin | US7, FR-051 |
| PUT `/domains/{domain}/footer` | replaceDomainFooter | admin, domain admin (writes) | US7, FR-052–FR-055 |
| GET `/capabilities/domains` | getDomainsCapabilities | admin, domain admin | US2, FR-064 |
| GET `/domain-tags` | listDomainTags | admin, domain admin | US1, FR-066 |
| GET `/domain-templates` | listDomainTemplates | admin | US5, FR-045, FR-049 |
| POST `/domain-templates` | createDomainTemplate | admin (writes) | US5, FR-045–FR-047 |
| GET `/domain-templates/{templateId}` | getDomainTemplate | admin | US5, FR-045 |
| PATCH `/domain-templates/{templateId}` | updateDomainTemplate | admin (writes) | US5, FR-047, FR-048 |
| DELETE `/domain-templates/{templateId}` | deleteDomainTemplate | admin (writes) | US5, FR-048, FR-050 |
| GET `/audit-events` (`targetType`, `targetId`) | listAuditEvents (001) | admin | US8, FR-058 |

"Writes" means a session with CSRF, or a read-write key. Mailbox users get `403 forbidden` on every
operation. Problem types are documented in [`docs/problems.md`](../../docs/problems.md).

## Project Structure

### Documentation (this feature)

```text
specs/002-domains/
├── spec.md              # Feature specification (clarifications, coverage and parity tables)
├── plan.md              # This file
├── research.md          # Phase 0: decisions D1–D27
├── data-model.md        # Phase 1: upstream tables, Redis keys, outbound calls
├── quickstart.md        # Phase 1: validation guide
├── contracts/README.md  # Phase 1: pointer to api/modules/domains and domain-templates
├── checklists/requirements.md
├── tasks.md             # Phase 2 (/speckit-tasks)
└── analysis.md          # /speckit-analyze summary
```

### Source Code (repository root, new or changed for this feature)

```text
api/
├── modules/domains/{_index,domains,domain,domain-deletion-preview,domain-footer,
│                    domain-bulk-actions,capabilities-domains,domain-tags}.yaml
├── modules/domain-templates/{_index,domain-templates,domain-template}.yaml
├── modules/audit/audit-events.yaml                    # + targetType, targetId
└── components/{schemas,parameters,responses}/Domain*.yaml

app/
├── Casts/Mebibytes.php
├── Clients/
│   ├── DockerApi/{DockerApiClient,HttpDockerApiClient,DockerCallResult}.php
│   ├── Ispconfig/{IspconfigDnsClient,JsonIspconfigDnsClient,IspconfigException}.php
│   ├── Memcached/{MemcachedFlusher,SocketMemcachedFlusher}.php
│   └── SharedStore/{SharedStore,SharedStoreUnavailable}.php
├── Http/
│   ├── Controllers/Api/V2/{Domain,DomainDeletionPreview,DomainFooter,DomainBulkAction,
│   │                       DomainCapabilities,DomainTag,DomainTemplate}Controller.php
│   ├── Requests/Api/V2/{ListDomains,StoreDomain,UpdateDomain,ReplaceDomainFooter,
│   │                    DomainBulkAction,ListDomainTags,ListDomainTemplates,
│   │                    StoreDomainTemplate,UpdateDomainTemplate}Request.php
│   └── Resources/Api/V2/{Domain,DomainCreated,DomainDeletionPreview,DomainFooter,
│                         DomainCapabilities,DomainTagSummary,DomainTemplate}Resource.php
├── Models/Mailcow/{Domain (extended),DomainTag,DomainTemplate,DomainFooter,AliasDomain,
│                   Alias,Relayhost}.php
├── Policies/{DomainPolicy,DomainTemplatePolicy}.php
├── Rules/{WholeMebibytes,DkimSelector}.php
├── Services/
│   ├── AliasDomains/AliasDomainCleanup.php           # shared with 004
│   ├── Audit/AuditLogger.php                         # + completeFollowUps(), new whitelists
│   ├── Config/MailcowConfig.php                      # + taggingLimit(), dockerTimeout()
│   ├── Dkim/{DkimKeyGenerator,DkimDnsPublisher,DkimDnsOutcome}.php   # shared with 010
│   ├── DomainAdmins/OrphanedDomainAdminRemover.php   # extended by 007
│   ├── Domains/{DomainQuery,DomainUsageCalculator,DomainUsage,DomainVersion,DomainPresenter,
│   │            DomainTagSet,DomainRules,DomainAttributeGuard,DomainCreator,DomainCreationReport,
│   │            DomainUpdater,DomainDeleter,DomainDeletionPlanner,DomainDeletionPlan,
│   │            DomainBulkActions,DomainFooterService,DomainCapabilitiesService,
│   │            DomainTagSummaryQuery,DomainScope}.php
│   ├── DomainTemplates/{DomainTemplateService,DomainTemplateAttributes}.php
│   ├── Groupware/SogoDirectory.php
│   └── RateLimits/RateLimitStore.php                 # extended by 006
└── Support/{Domain/DomainName,Http/FieldViolations}.php

config/restv2.php                                     # + settings of research D24
database/migrations/2026_10_19_000001_add_target_index_to_restv2_audit_events.php
database/seeders/DevelopmentSeeder.php                # + quickstart Part B data
resources/bin/export-mailcow-vars.php                 # + TAGGING_LIMIT, DOCKER_TIMEOUT
routes/api.php                                        # + 16 routes, specific before general
deploy/docker-compose.override.yml                    # + COMPOSE_PROJECT_NAME, RESTV2_* of D24
deploy/nginx/site.restv2.custom                       # client_max_body_size 5m

tests/
├── contract-scope.php                                # implemented OpenAPI tags for the coverage gate
├── bin/check-contract-coverage.php                   # (001) scoped by contract-scope.php
├── fixtures/legacy/{domain_details_queries,domain_add_expected}.php   # parity fixtures from 2026-09a
├── Support/Fakes/{FakeDockerApiClient,FakeMemcachedFlusher,FakeIspconfigDnsClient}.php
├── Support/Mailcow/MailcowFactory.php                # + domain, template, footer and cleanup rows
├── Feature/Domains/…                                 # per user story (tasks.md)
├── Feature/DomainTemplates/…
├── Feature/Security/DomainAuthorizationMatrixTest.php
├── Feature/Performance/DomainListLatencyTest.php
└── Unit/{Support/DomainNameTest,Services/…,Clients/…}.php
```

**Structure Decision**: Feature 001's single Laravel project and layout. Business logic lives in
`app/Services/Domains/` (one service per write path, so that bulk actions reuse them) and
`app/Services/DomainTemplates/`; pieces later features reuse sit in their own namespaces
(`AliasDomains`, `Dkim`, `DomainAdmins`, `Groupware`, `RateLimits`). Outbound services sit behind
`app/Clients/`. Controllers stay thin with `index`, `show`, `store`, `update`, `destroy`.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Status `503` (not in constitution II's list) for `temporary-failure` | FR-056 requires a distinct temporary failure when mailcow's shared store is down, so that clients retry; nothing was changed. RFC 9110 defines `503` with `Retry-After` for this. Feature 001 set the precedent. | `500` cannot be told apart from a bug, so clients would not retry; `409` would wrongly suggest a state conflict. |
| Audit records of creations and deletions are completed once after the commit (`AuditLogger::completeFollowUps`), although 001's records are otherwise immutable | FR-057 requires the creation report and the stored-mail outcome in the one audit record of the change, and the spec requires that a change whose audit record cannot be written does not succeed. The groupware restart and the stored-mail move must run after the commit. The record is written in the transaction with the keys marked `"pending"`, and only those keys can be replaced, once, from `"pending"`. | Holding the transaction across the follow-ups was rejected: the restarted groupware cannot see uncommitted rows, and 15–60 s external calls would hold row locks. A second record would break SC-007. Writing the record after the follow-ups would leave an unaudited domain when that write fails. |
| dockerapi is called without TLS certificate verification | Legacy `docker()` does the same: dockerapi presents mailcow's own certificate under another host name on the internal network. | Verifying would fail on every installation; shipping a CA bundle for dockerapi is not possible without changing mailcow. |
