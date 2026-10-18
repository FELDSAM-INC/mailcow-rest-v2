# Implementation Plan: Quarantine, Spam Policy and Rate Limits

**Branch**: `main` (no feature branches, constitution 1.1.0) | **Date**: 2026-10-18 |
**Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/006-quarantine-spam-policy/spec.md`

## Summary

Feature 006 replaces the legacy quarantine page, its detail view and downloads, the quarantine
settings tab, the spam filter tabs of the user and domain pages, the rate limit tabs, the mailbox
list's notification mass actions, the dashboard's Rspamd action chart and its rate-limit reset:

- quarantine items: scoped listing with filters, search and sorting; inspection with the parsed
  content (never HTML, never the stored message or the quick-action hash); attachment and
  whole-message downloads as files to save; release (with or without ham learning), learning as
  spam and deletion, singly and in bulk, each item processed at most once;
- quarantine settings (stored in mailcow's Redis `Q_*` keys), with a notification template that is
  checked by the legacy notifier's own engine and can be previewed as text;
- per-mailbox notification frequency and category, singly and in bulk;
- mailbox spam scores and allow/block lists of domains, alias domains and mailboxes (`filterconf`);
- sending rate limits of domains, alias domains and mailboxes (`RL_VALUE`) and bucket resets
  (`RL_LOG`);
- the spam filter's action statistics with their counting period, and a capabilities read.

Technical approach:

- The OpenAPI contract was authored first: 42 operations in the modules `quarantine`,
  `spam-policy` and `rate-limits`, lint-clean (see [Contract Operations](#contract-operations)).
- Legacy logic is ported from `functions.quarantine.inc.php`, `functions.policy.inc.php`,
  `functions.ratelimit.inc.php`, `functions.mailbox.inc.php` (`spam_score`,
  `quarantine_notification`, `quarantine_category`), `functions.rspamd.inc.php` and
  `ajax/qitem_details.php`, with the spec's deviations.
- The Rspamd controller is called over its Unix socket like legacy (Laravel HTTP client); releases go
  to Postfix port 590 through Symfony Mailer; message parsing uses legacy's own libraries; the
  template check runs the notifier's Jinja2 sandbox in a Python subprocess.
- Per-item Redis locks guarantee at-most-once release and learning; Redis writes use
  `WATCH`/`MULTI` or Lua scripts for atomic, version-checked changes.
- No API-owned table. Tests run on MariaDB with the `2026-09a` schema fixture and a real Redis; the
  Rspamd controller and Postfix are faked behind interfaces; the template engine runs for real.

## Technical Context

**Language/Version**: PHP 8.4, Laravel 13.x (001). Python 3 (Alpine package) for the template
check only.

**Primary Dependencies**: 001's stack, plus `php-mime-mail-parser/php-mime-mail-parser` (MIT) with
PECL `mailparse` and `soundasleep/html2text` (MIT), legacy's parsing libraries; Alpine
`python3`, `py3-jinja2`, `py3-markupsafe`, `py3-html2text` (the notifier's engine). Symfony Mailer
and Mime and Laravel's HTTP client are already present. Justifications:
[research R23](./research.md#r23-dependencies-constitution-viii).

**Storage**: Upstream tables `quarantine` (read, delete), `filterconf` (read, insert, delete),
`mailbox` (read, `attributes` JSON_SET), `domain`, `alias_domain` and the ACL tables (read). Redis
`Q_*` (eleven keys), `RL_VALUE`, `RL_LOG` and bucket hashes on the `mailcow` connection; lock keys
`restv2:quarantine-item:<id>` on the `restv2` connection. No migration.
See [data-model.md](./data-model.md).

**Testing**: PHPUnit 12 feature and unit tests, written first, on MariaDB 10.11 with
`tests/fixtures/schema/2026-09a.sql` and a real Redis 7.4, every request and response validated
against the contract. Fakes: `FakeRspamdController`, `FakeQuarantineReleaseMailer`. The SMTP
adapter is tested against a scripted SMTP server process; the Rspamd adapter with `Http::fake()`.
Parity goldens are generated with the legacy images (parser: php-fpm; templates: Dovecot)
([research R22](./research.md#r22-test-strategy-specifics)).

**Target Platform**: 001's container (`php:8.4-fpm-alpine`, non-root, read-only root filesystem),
plus `mailparse`, `python3`, `py3-jinja2`, `py3-html2text`, and the override volumes
`rspamd-vol-1:/var/lib/rspamd:ro` and `./data/assets/templates:/mailcow/assets/templates:ro`.

**Project Type**: web service (REST API).

**Performance Goals**: SC-007: p95 < 1 s for a page of 25 items with 100,000 quarantine items
(one indexed-free scan without `msg`, R16). SC-008: a bulk release of 100 items within 60 s when
the services respond normally (R18). Inspection parses at most 10 MiB per request.

**Constraints**:

- No change to upstream tables, containers or files; legacy UI, `/api/v1`, the ingestion, the
  notifier, the cleanup, the quick links and the Rspamd maps keep working on the same data
  (FR-068).
- An item is delivered and learned at most once through this API (FR-026).
- Never return or log `msg`, `qhash`, subjects in audit records, or HTML content.
- Downloads never render or run in the API's origin (FR-018).
- Spam filter requests time out after 30 s (FR-062).

**Scale/Scope**: One mailcow server with up to 100,000 quarantine items, 10,000 mailboxes, a few
thousand list entries and rate limits. 42 operations in 3 modules, 0 API-owned tables, 8 user
stories.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design (below).*

| Principle | Gate | Status |
|---|---|---|
| I. Contract-first | All 42 operations are in `api/modules/{quarantine,spam-policy,rate-limits}/` with their components, lint-clean (`npx @redocly/cli@2.64.1 lint`), before any code. Every test request and response is validated by 001's `OpenApiContract`; the coverage check fails on an untested operation. | PASS |
| II. HTTP contract | Plural nouns and real nesting (`/mailboxes/{mailbox}/spam-score`, `/domains/{domain}/rate-limit`); non-CRUD actions are sub-resources (`POST /quarantine/{itemId}/release`, `/spam-learning`, `/quarantine/bulk-actions`). 200/201 (with `Location`)/204. `{data, meta}` lists, `{data}` single resources, RFC 9457 problems with `errors` on 422. `ETag`/`If-Match` on settings, notification settings, spam scores, rate limits, items and entries. JSON Merge Patch for settings and notification settings. camelCase, booleans, UTC, bytes. `503` reused from 001 (Complexity Tracking). | PASS (justified) |
| III. Upstream schema | No migration at all. Writes only through `app/Services/` to `quarantine`, `filterconf` and `mailbox.attributes`, in legacy's formats. | PASS |
| IV. Parity | Legacy code read and ported (research R2–R14); every side effect reproduced: Postfix delivery on port 590 with the `X-Spam-Flag` rename, `learnham`/`learnspam` and `fuzzyadd` with flags 13/11, `Q_*` keys, `RL_VALUE`, `RL_LOG`, bucket keys, `filterconf` rows, mailbox attributes. Deviations are only those in the spec's parity table, completed with the plan-level rows below. | PASS |
| V. Coexistence | Same container (001). The override adds two read-only mounts and an environment variable; no other mailcow file or container is changed. The ingestion, the notifier, the cleanup, the quick links, `settings.php` and `DYN_RL` stay in place. | PASS |
| VI. Security | Object-level authorisation for all three roles, both key types and the flags (FR-006), flag check before lookup, `404` outside scope. Read-only keys refused on every unsafe method by 001's pipeline. `qhash` and `msg` never returned; HTML converted to text; downloads `attachment` + `nosniff` + sandbox CSP. The template check runs in Jinja2's sandbox, in a subprocess with time and output limits. Every change audited; refusals audited. | PASS |
| VII. Test-first | `tasks.md` puts the tests of every phase first; MariaDB with the real schema; real Redis; Rspamd and Postfix faked behind interfaces with call assertions; parity goldens from legacy images. | PASS |
| VIII. Simplicity | Two Composer packages, both legacy's own; Python only because nothing else can check a Jinja2 template the way the notifier does (Complexity Tracking). Interfaces only for the two outbound services. Shared code from 001–004 reused, not duplicated (R1): 002's `RateLimitStore`, `SharedStore`, `DomainName`, `DomainScope`; 003's `MailboxScope`; 004's `AliasDomainQuery`. | PASS (justified) |
| Cross-feature conventions | `404` outside scope, `403` only for role/flag/key scope. Capabilities at `/capabilities/quarantine` (`getQuarantineCapabilities`). History through the generic `targetType`/`targetId` filters (only `AuditTarget` values added). Refusals of authenticated callers audited through route declarations. List entry uniqueness under `GET_LOCK()`, no new index. Lists: `limit` 25/1000, `q`, `sort`, `order`. Bulk: limit 100, whole-request refusal only for role/flag/key scope, per-item `version` with `outdated`, one result and one audit record per item, `bulkRequestId`. Version tags over stored settings only, on every list entry. Reads not audited. No caching. Server settings with upstream defaults (R20); 001's configuration reader for `DEFAULT_LANG`. | PASS |
| Runtime constraints | Stateless php-fpm, read-only root filesystem (no temp files: parsing in memory, Python with `-B`), JSON logs with request ID; configuration from environment variables. | PASS |

**Post-design re-check (after Phase 1)**: PASS. The decisions that touched gates:

- Python runtime for the template check: VIII, justified below.
- Quarantine bulk result schema that mirrors `BulkResult` plus `report`: conventions, justified
  below.
- Release audit after an irreversible delivery: VI, recorded in the spec's edge cases.

## Parity notes added to the spec

The spec's clarifications and parity table were completed with the orchestrator decisions
(bulk version tags, statistics period, template validation and preview, capabilities) and these
plan-level rows (constitution IV requires every deviation to be stated in the spec):

1. A stored envelope sender that is not a valid address (for example the column default `unknown`)
   is released with `sender-unknown@rspamd`; legacy sends the stored text.
2. The release dialogue uses `EHLO quarantine` and CRLF line endings (legacy: `HELO quarantine`,
   LF); the delivered message is the same.
3. `GET /rate-limits` lists limits of existing objects only; a field stored in upper case by
   another tool is not found (the spam filter does not match it either).
4. Every refusal of an authenticated caller on a state-changing route is audited (read-only keys,
   restricted sessions, missing flags, out-of-scope objects); CSRF failures and `401`s are not
   (conventions "Refusal auditing").

## Contract Operations

| Method and path | operationId | Roles (flags) | Story / FR |
|---|---|---|---|
| GET `/quarantine` | listQuarantineItems | all, scoped | US1, US2, FR-007–FR-011 |
| POST `/quarantine/bulk-actions` | applyQuarantineBulkAction | admin; DA, user (`quarantine`) | US2, FR-027 |
| GET `/quarantine/settings` | getQuarantineSettings | all (non-admin: public subset) | US3, FR-029–FR-034 |
| PATCH `/quarantine/settings` | updateQuarantineSettings | admin | US3, FR-029–FR-033, FR-070 |
| POST `/quarantine/settings/template-preview` | previewQuarantineTemplate | admin | US3, FR-071 |
| GET `/quarantine/{itemId}` | getQuarantineItem | all, scoped | US1, FR-012–FR-015 |
| DELETE `/quarantine/{itemId}` | deleteQuarantineItem | admin; DA, user (`quarantine`) | US1, FR-024, FR-026 |
| POST `/quarantine/{itemId}/release` | releaseQuarantineItem | admin; DA, user (`quarantine`) | US1, FR-019–FR-022, FR-025, FR-026 |
| POST `/quarantine/{itemId}/spam-learning` | learnQuarantineItemAsSpam | admin; DA, user (`quarantine`) | US1, FR-023, FR-025, FR-026 |
| GET `/quarantine/{itemId}/message` | downloadQuarantineMessage | admin; DA (`quarantine`); user (`quarantine`, `quarantine_attachments`) | US1, US2, FR-017, FR-018 |
| GET `/quarantine/{itemId}/attachments/{position}` | downloadQuarantineAttachment | admin, DA; user (`quarantine_attachments`) | US1, US2, FR-016, FR-018 |
| GET `/capabilities/quarantine` | getQuarantineCapabilities | all | US2, FR-072 |
| POST `/quarantine-notification-settings/bulk-actions` | applyQuarantineNotificationBulkAction | admin, DA | US4, FR-037 |
| GET `/mailboxes/{mailbox}/quarantine-notification-settings` | getMailboxQuarantineNotificationSettings | all, scoped | US4, FR-035 |
| PATCH `/mailboxes/{mailbox}/quarantine-notification-settings` | updateMailboxQuarantineNotificationSettings | admin, DA; user (`quarantine_notification`/`quarantine_category`) | US4, FR-036, FR-038 |
| GET `/domains/{domain}/spam-policy-entries` | listDomainSpamPolicyEntries | admin, DA | US5, FR-043, FR-045 |
| POST `/domains/{domain}/spam-policy-entries` | createDomainSpamPolicyEntry | admin; DA (`spam_policy`) | US5, FR-046, FR-047 |
| GET `/domains/{domain}/spam-policy-entries/{entryId}` | getDomainSpamPolicyEntry | admin, DA | US5, FR-045 |
| DELETE `/domains/{domain}/spam-policy-entries/{entryId}` | deleteDomainSpamPolicyEntry | admin; DA (`spam_policy`) | US5, FR-048 |
| GET `/alias-domains/{aliasDomain}/spam-policy-entries` | listAliasDomainSpamPolicyEntries | admin, DA | US5, FR-043 |
| POST `/alias-domains/{aliasDomain}/spam-policy-entries` | createAliasDomainSpamPolicyEntry | admin; DA (`spam_policy`) | US5, FR-046 |
| GET `/alias-domains/{aliasDomain}/spam-policy-entries/{entryId}` | getAliasDomainSpamPolicyEntry | admin, DA | US5, FR-045 |
| DELETE `/alias-domains/{aliasDomain}/spam-policy-entries/{entryId}` | deleteAliasDomainSpamPolicyEntry | admin; DA (`spam_policy`) | US5, FR-048 |
| GET `/mailboxes/{mailbox}/spam-policy-entries` | listMailboxSpamPolicyEntries | all, scoped | US5, FR-044, FR-045 |
| POST `/mailboxes/{mailbox}/spam-policy-entries` | createMailboxSpamPolicyEntry | admin; DA, user (`spam_policy`) | US5, FR-046, FR-047 |
| GET `/mailboxes/{mailbox}/spam-policy-entries/{entryId}` | getMailboxSpamPolicyEntry | all, scoped | US5, FR-045 |
| DELETE `/mailboxes/{mailbox}/spam-policy-entries/{entryId}` | deleteMailboxSpamPolicyEntry | admin; DA, user (`spam_policy`) | US5, FR-048 |
| GET `/mailboxes/{mailbox}/spam-score` | getMailboxSpamScore | all, scoped | US5, FR-039 |
| PUT `/mailboxes/{mailbox}/spam-score` | setMailboxSpamScore | admin, DA; user (`spam_score`) | US5, FR-040, FR-042 |
| DELETE `/mailboxes/{mailbox}/spam-score` | resetMailboxSpamScore | admin, DA; user (`spam_score`) | US5, FR-041 |
| GET `/spam-filter-action-statistics` | getSpamFilterActionStatistics | admin | US7, FR-058 |
| GET `/rate-limits` | listRateLimits | admin, DA | US6, FR-051 |
| GET `/domains/{domain}/rate-limit` | getDomainRateLimit | admin, DA | US6, FR-050, FR-052 |
| PUT `/domains/{domain}/rate-limit` | setDomainRateLimit | admin; DA (`ratelimit`) | US6, FR-053, FR-055 |
| DELETE `/domains/{domain}/rate-limit` | removeDomainRateLimit | admin; DA (`ratelimit`) | US6, FR-054 |
| GET `/alias-domains/{aliasDomain}/rate-limit` | getAliasDomainRateLimit | admin, DA | US6, FR-052 |
| PUT `/alias-domains/{aliasDomain}/rate-limit` | setAliasDomainRateLimit | admin; DA (`ratelimit`) | US6, FR-053 |
| DELETE `/alias-domains/{aliasDomain}/rate-limit` | removeAliasDomainRateLimit | admin; DA (`ratelimit`) | US6, FR-054 |
| GET `/mailboxes/{mailbox}/rate-limit` | getMailboxRateLimit | admin, DA | US6, FR-050, FR-052 |
| PUT `/mailboxes/{mailbox}/rate-limit` | setMailboxRateLimit | admin; DA (`ratelimit`) | US6, FR-053 |
| DELETE `/mailboxes/{mailbox}/rate-limit` | removeMailboxRateLimit | admin; DA (`ratelimit`) | US6, FR-054 |
| DELETE `/rate-limit-buckets/{bucketId}` | resetRateLimitBucket | admin | US6, FR-057 |

Every unsafe operation accepts a session with `X-CSRF-Token` or a read-write key; reads also accept
read-only keys. US8 (audit trail) uses 001's `GET /audit-events` with 002's target filters and the
event and target types appended by this feature. Problem types: [`docs/problems.md`](../../docs/problems.md)
(`quarantine-item-busy`, `quarantine-delivery-refused`, `quarantine-message-too-large`,
`spam-policy-entry-exists`; reused `permission-flag-required`, `temporary-failure`).

## Project Structure

### Documentation (this feature)

```text
specs/006-quarantine-spam-policy/
├── spec.md              # Feature specification (clarifications, parity table)
├── plan.md              # This file
├── research.md          # Phase 0: decisions R1–R23
├── data-model.md        # Phase 1: upstream tables, Redis keys, outbound calls, audit records
├── quickstart.md        # Phase 1: validation guide
├── contracts/README.md  # Phase 1: pointer to api/ (the contract is not duplicated)
├── checklists/requirements.md
└── tasks.md             # Phase 2 (/speckit-tasks); the analysis summary is in the agent report
```

### Source Code (repository root)

Only the files this feature adds or extends are listed; the rest is 001's layout.

```text
api/
├── modules/quarantine/      # 12 path files + _index.yaml
├── modules/spam-policy/     # 8 path files + _index.yaml
├── modules/rate-limits/     # 5 path files + _index.yaml
└── components/{schemas,parameters,responses}/Quarantine*, SpamPolicy*, SpamScore*,
    SpamFilter*, RateLimit*, PolicyTarget*

app/
├── Clients/
│   ├── Rspamd/{RspamdController,UnixSocketRspamdController,LearnOutcome}.php
│   └── Mail/{QuarantineReleaseMailer,SmtpQuarantineReleaseMailer,DeliveryOutcome}.php
├── Http/
│   ├── Controllers/Api/V2/
│   │   ├── QuarantineItemController.php              # index, show, destroy
│   │   ├── QuarantineItemReleaseController.php       # store
│   │   ├── QuarantineItemSpamLearningController.php  # store
│   │   ├── QuarantineItemDownloadController.php      # message, attachment
│   │   ├── QuarantineBulkActionController.php        # store
│   │   ├── QuarantineSettingsController.php          # show, update
│   │   ├── QuarantineTemplatePreviewController.php   # store
│   │   ├── QuarantineCapabilitiesController.php      # show
│   │   ├── QuarantineNotificationSettingsController.php  # show, update
│   │   ├── QuarantineNotificationBulkActionController.php # store
│   │   ├── SpamPolicyEntryController.php             # index, store, show, destroy (per owner kind)
│   │   ├── SpamScoreController.php                   # show, update, destroy
│   │   ├── SpamFilterStatisticsController.php        # show
│   │   ├── RateLimitController.php                   # index, show, update, destroy (per object kind)
│   │   └── RateLimitBucketController.php             # destroy
│   ├── Requests/Api/V2/
│   │   ├── ListQuarantineItemsRequest.php, ReleaseQuarantineItemRequest.php,
│   │   ├── QuarantineBulkActionRequest.php, UpdateQuarantineSettingsRequest.php,
│   │   ├── PreviewQuarantineTemplateRequest.php, UpdateQuarantineNotificationSettingsRequest.php,
│   │   ├── QuarantineNotificationBulkRequest.php, ListSpamPolicyEntriesRequest.php,
│   │   ├── StoreSpamPolicyEntryRequest.php, SetSpamScoreRequest.php,
│   │   └── ListRateLimitsRequest.php, SetRateLimitRequest.php
│   └── Resources/Api/V2/
│       ├── QuarantineItemResource.php, QuarantineItemDetailsResource.php,
│       ├── QuarantineActionReportResource.php, QuarantineSettingsResource.php,
│       ├── QuarantineNotificationSettingsResource.php, SpamPolicyEntryResource.php,
│       └── SpamScoreResource.php, RateLimitResource.php
├── Models/Mailcow/{QuarantineItem,FilterPreference}.php
├── Policies/{QuarantinePolicy,SpamPolicyPolicy,RateLimitPolicy}.php
├── Services/Access/SpamFilterScope.php   # item scope predicate; mailbox/domain/alias resolvers (R15)
├── Services/Audit/RefusalAuditor.php     # route-declared refusal hook; created only if no earlier feature did (R19)
├── Rules/{SenderPattern,MailboxSize,OneDecimal,NotificationTemplate}.php
├── Services/
│   ├── Quarantine/{QuarantineQuery,QuarantineMessageParser,ParsedMessage,QuarantineActionService,
│   │               ActionReport,QuarantineItemLock,QuarantineSettingsService,
│   │               QuarantineTemplateRenderer,TemplateCheckResult,QuarantineNotificationService,
│   │               QuarantineCapabilities,ReleaseMessageFactory,DownloadFileName}.php
│   ├── SpamPolicy/{SpamPolicyEntryService,SpamScoreService,SpamFilterStatisticsService}.php
│   ├── Quarantine/{QuarantineBulkActions,QuarantineNotificationBulkActions}.php
│   └── RateLimits/{RateLimitStore (002, extended),RateLimitService,RateLimitBucketService}.php
└── Support/Redis/LuaScripts.php       # compare-and-set field, bucket reset

config/restv2.php                      # + rspamd, release_smtp, bulk_limit, quarantine.* keys
resources/
├── bin/quarantine-template.py
└── legacy/{quarantine.tpl,quarantine-release-strings.json}
routes/api.php                         # + 006 routes (literal segments before {itemId})
scripts/legacy/{extract-quarantine-release-strings.sh,generate-qitem-goldens.sh,
                generate-template-goldens.sh}
docker/php/Dockerfile                  # + mailparse, python3, py3-jinja2, py3-html2text
deploy/docker-compose.override.yml     # + rspamd-vol-1 (ro), data/assets/templates (ro), SKIP_CLAMD
deploy/nginx/site.restv2.custom        # fastcgi_read_timeout 300s
docker/php/php-fpm.conf                # request_terminate_timeout = 300s

tests/
├── Support/
│   ├── Rspamd/FakeRspamdController.php
│   ├── Mail/FakeQuarantineReleaseMailer.php
│   ├── Smtp/{ScriptedSmtpServer,scripted-smtp-server}.php
│   ├── Quarantine/LargeMessage.php
│   └── Commands/TestReleaseCommand.php   # testing environment only (concurrency test)
├── Feature/Quarantine/      # items, actions, bulk, downloads, settings, template, notifications,
│                            # capabilities, concurrency, audit, performance
├── Feature/SpamPolicy/      # entries (3 owners), spam score, statistics
├── Feature/RateLimits/      # limits (3 kinds), list, bucket reset
├── Unit/Quarantine/         # parser, file names, renderer, release message factory
├── Unit/Clients/            # Rspamd adapter, SMTP adapter
└── fixtures/quarantine/{messages,legacy,templates}/
```

**Structure Decision**: The single Laravel project of 001. One service per aggregate:
quarantine items (`QuarantineActionService`), quarantine settings, notification settings, list
entries, spam scores, rate limits and buckets. Outbound services sit behind `app/Clients/`.
Controllers are thin and per resource; mailbox, domain and alias domain variants of list entries
and rate limits share one controller each, parameterised by the owner kind from the route.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Status `503` (not in constitution II's list) for `temporary-failure` | FR-021, FR-023, FR-058 and FR-061 require a distinct, retryable failure when Redis, the spam filter or the mail server is unavailable; the same justification as 001's Complexity Tracking, and the type is 002's. | `500` cannot be told apart from a bug; clients would not retry. |
| Python 3 with Jinja2 in a PHP service (constitution VIII) | FR-070/FR-071 and SC-011 require checking and previewing a template exactly as the legacy notifier (`quarantine_notify.py`, Jinja2 `SandboxedEnvironment`) parses and renders it. | Twig or a hand-written check would accept templates the notifier rejects and vice versa; dockerapi offers no way to run the check in the Dovecot container; patching that container violates constitution V. |
| `QuarantineBulkResult` mirrors the shared `BulkResult` plus a per-item `report` instead of reusing `BulkResultResponse` (conventions "Shared contract components") | FR-027 requires each `done` item to carry its action report, so that a client sees a release whose learning failed. | `BulkItemResult` has `additionalProperties: false` and cannot be extended with `allOf`; editing the shared schema belongs to its owner; dropping the report would hide learning failures. |
| A release's audit record is written after the irreversible delivery (constitution VI, spec edge case "audit cannot be written") | The record must contain the outcome of every step (FR-063), and the delivery cannot be rolled back. | Writing it before the delivery cannot record the outcome; two records per release break SC-010; holding a transaction across the network calls blocks the ingestion. A failed write answers `500` and logs the full record. |
