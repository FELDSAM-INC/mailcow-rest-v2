# Implementation Plan: Monitoring and Operations

**Branch**: `main` (no feature branches, constitution 1.1.0) | **Date**: 2026-10-18 |
**Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/011-monitoring-operations/spec.md`

## Summary

Feature 011 gives administrators what the legacy dashboard ("System & Containers" and the log
tabs), the Queue Manager and the fail2ban section show today:

- the system overview, mail storage usage, installed version, update status and public addresses,
  plus a monitoring capabilities read (orchestrator decision);
- mailcow's containers with state, health, role and protection, their resource usage, and start,
  stop and restart;
- all twelve legacy logs with server-side paging, filters, search, duplicate-free cursors and
  masking of secrets in the legacy activity and request logs;
- Rspamd's scan history and statistics;
- the Postfix mail queue: listing with per-queue counts, audited content views, deliver, hold,
  release and delete for up to 1,000 queue IDs, flush and delete all;
- the ban service settings, allowlist and denylist, rules (validated in the ban service's Python
  dialect), rule reset, active and permanent bans with lift, allowlist and denylist actions, and
  the ban list ID rotation.

The technical approach:

- The contract was authored first: 42 operations in six modules (`host`, `containers`, `logs`,
  `rspamd-stats`, `mail-queue`, `fail2ban`), lint-clean with Redocly 2.64.1.
- Everything is administrator-only and runs in 001's pipeline; read-only keys may read everything,
  including message content, and nothing else.
- Container, host, queue and storage data come from mailcow's container service (dockerapi) through
  the client feature 002 introduces, with the exact dockerapi tasks legacy uses. Live data is
  collected once for all clients in a Redis snapshot cache with the spec's maximum ages
  (5/10/15/60 s).
- Logs are read from mailcow's Redis lists and from the `logs` and `sasl_log` tables; Rspamd data
  from the controller socket (client from feature 006); ban data from the `F2B_*` Redis keys, with
  value-based version tags and atomic `WATCH`/`MULTI` writes.
- No API-owned table is added and no upstream table is written. Audit records go to 001's
  `restv2_audit_events`, with retry and an `Audit-Recorded: false` header for irreversible
  operations whose record cannot be written afterwards.
- Tests are written first on MariaDB with the `2026-09a` schema fixture and a real Redis; dockerapi,
  the Rspamd controller, the release source and the IP check service are faked behind interfaces
  and the fakes assert every call.

## Technical Context

**Language/Version**: PHP 8.4 (constitution: 8.3 or newer), Laravel 13.x (from feature 001)

**Primary Dependencies**: Feature 001's stack, unchanged. No new Composer package. The Laravel HTTP
client (Guzzle) for dockerapi, the Rspamd socket, GitHub and the IP check service; Symfony
`Process` for the Python validator. New OS package in the image: Alpine `python3` (PSF-2.0,
GPL-compatible) to compile ban rules in the ban service's dialect ([R20](./research.md#r20-ban-rules-and-python-regular-expression-validation),
[R29](./research.md#r29-dependencies-constitution-viii)).

**Storage**: Reads the upstream tables `logs` and `sasl_log` (schema `2026-09a`, `db_version
18082026_1200`); writes none. Reads mailcow's Redis log lists (`*_LOG`, `*_MAILLOG`) and `IP_CHECK`;
reads and writes `F2B_OPTIONS`, `F2B_WHITELIST`, `F2B_BLACKLIST`, `F2B_REGEX` and `F2B_QUEUE_UNBAN`;
reads `F2B_ACTIVE_BANS` and `F2B_PERM_BANS`. API-owned Redis keys under `restv2:monitoring:*` and
`restv2:update-check:*`. Audit records in 001's `restv2_audit_events`. No migration.

**Testing**: PHPUnit 12 feature and unit tests, written first, on MariaDB 10.11 with
`tests/fixtures/schema/2026-09a.sql` and a real Redis 7.4 (database 15), every request and response
validated against the contract by 001's `OpenApiContract`. dockerapi (`FakeDockerApiClient`), the
Rspamd controller (`FakeRspamdController`), the release source and the IP check service are faked;
the fakes record and assert every call. `F2B_*` and log keys are asserted on the real Redis. The
Python validator runs for real (python3 is in the dev image).

**Target Platform**: 001's `restv2-mailcow` container (php-fpm 8.4, Alpine, non-root, read-only
root filesystem) in mailcow-dockerized, with two additions to the override: mailcow.conf variables
`COMPOSE_PROJECT_NAME`, `SKIP_CLAMD`, `SKIP_OLEFY`, `LOG_LINES`, and the read-only `rspamd-vol-1`
mount (if 006 did not add it).

**Project Type**: web service (REST API).

**Performance Goals**: SC-003: p95 under 1 s for the overview, the container list and a page of 100
out of 10,000 log entries while mailcow answers normally. SC-004: ten polling administrators cause
no more host measurements than one. SC-009: container and queue actions answer within
`DOCKER_TIMEOUT` + 5 s.

**Constraints**:

- No change to upstream tables, containers, dockerapi or netfilter; legacy UI and `/api/v1` keep
  working on the same data.
- Administrator-only; read-only keys read only.
- Secrets never appear in responses, logs or audit records: container environments are dropped at
  parse time, legacy log secrets are masked, message content and ban list IDs are never audited.
- Long operations are synchronous and bounded by `DOCKER_TIMEOUT` (60 s), with nginx and php-fpm
  timeouts raised above it ([R28](./research.md#r28-deployment-and-runtime-limits)).

**Scale/Scope**: One mailcow host with about 20 containers, up to 10,000 entries per in-memory log,
an unbounded legacy UI activity log, up to 1,000 Rspamd history rows, queues of tens of thousands of
messages (listing capped at 10,000), allowlists and denylists of up to 10,000 entries. 42
operations in 6 modules, 8 user stories, no API-owned table.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design (below).*

| Principle | Gate | Status |
|---|---|---|
| I. Contract-first | All 42 operations are authored in `api/modules/{host,containers,logs,rspamd-stats,mail-queue,fail2ban}/` before code; `npx @redocly/cli@2.64.1 lint api/openapi.yaml` passes (only 001's four known system warnings remain). Every test request and response is validated (001 R13); the contract coverage check includes the new operations. | PASS |
| II. HTTP contract | Plural nouns and sub-resource actions (`/containers/{containerName}/restart`, `/fail2ban/rules/reset`), bulk queue actions at `POST /mail-queue/messages/bulk-actions` with `{action, targets}`; `PATCH` with merge patch for settings, `PUT` for the rule list. `{data, meta}` lists (the queue's `meta` extends `ListMeta`), `{data}` singles, RFC 9457 problems with documented types, `ETag`/`If-Match` on the two mutable resources (ban settings and rules). camelCase, booleans, RFC 3339 UTC, bytes and seconds as explicit units. Statuses 502 and 504 are justified below. | PASS (justified) |
| III. Upstream schema | No migration. `logs` and `sasl_log` are read through read-only models. | PASS |
| IV. Parity | The legacy code was read ([research](./research.md)): `functions.{docker,mailq,fail2ban}.inc.php`, `get_logs()`, `json_api.php`, `container_ctrl.php`, `update_check.php`, `dashboard.php`, dockerapi and netfilter. Ban data is written exactly as legacy writes it (keys, JSON shape, list hashes), dockerapi tasks are legacy's. Every deviation is in the spec's parity table; plan-level details are recorded under "Parity notes" below. | PASS |
| V. Coexistence | Own container; dockerapi, netfilter, Rspamd and php-fpm unchanged; the public ban list endpoint stays in legacy. The override gains environment variables and a read-only volume only. | PASS |
| VI. Security | Object-level authorisation: administrators only, tested for every role and key type on every operation. Read-only keys never change anything. No secret in responses (container `Env` dropped, log masking per FR-028, content views audited, ban list IDs not audited). Every state change is audited, refusals of authenticated callers included (008's `RefusalAuditor`, `restv2.audit` route defaults), with a pre-check and retry for irreversible operations. dockerapi's certificate is not verified, as in legacy (justified below). | PASS (justified) |
| VII. Test-first | `tasks.md` puts tests before implementation in every phase; MariaDB with the real schema; real Redis; fakes only for dockerapi, the Rspamd controller and the outbound HTTP services, all asserting their calls. | PASS |
| VIII. Simplicity | No new Composer package; one OS package (python3) justified in R29. One snapshot cache class for four data kinds; reuse of 002's dockerapi client, 006's Rspamd client and 001's audit, config and ETag code. | PASS |
| Cross-feature conventions (`docs/conventions.md`) | Out-of-installation containers, absent queue IDs, unbanned networks and unknown log types answer `404`. The capabilities read is `GET /capabilities/monitoring` (`getMonitoringCapabilities`). History uses the generic `targetType`/`targetId` audit filters with new `AuditTarget` values; no filter parameter is added. Refusals are audited per "Refusal auditing". Bulk queue actions use `BulkTarget` (extended, no version) and `BulkResultResponse` with `BulkItemOutcome` values, at most 1,000 IDs. Lists default to 25 (max 1,000) with `q`. Version tags cover stored values only. Reads are not audited except content views. Maximum ages are the spec's. Long dockerapi operations are synchronous with "outcome unknown" on timeout. Legacy PHP configuration (`DOCKER_TIMEOUT`, `app_info.inc.php`) via `MailcowConfig`; fork behaviour as settings (`RESTV2_UPDATE_CHECK`, 001's `RESTV2_SASL_LOG_SERVICE`). Shared problem types `temporary-failure` and `outcome-unknown` are reused. | PASS |
| Runtime constraints | Stateless; the snapshot cache lives in Redis. JSON logs with the request ID; container environments and message content never logged. | PASS |

**Post-design re-check (after Phase 1)**: PASS. The contract, data model and research add no
violation beyond the Complexity Tracking rows. The decisions that touched gates: value-based
version tags with `WATCH`/`MULTI` (II, IV), the audit probe and retry (VI), dropping container
environments at parse time (VI), and validating rules with Python (IV, VIII).

## Parity notes (plan level)

These details refine rows of the spec's parity table without changing behaviour the spec states:

1. Legacy `container_ctrl.php` start cannot find a stopped container, because `docker('get_id')`
   lists only running containers; the API resolves IDs from the full list, so start works as the
   spec requires ([R4](./research.md#r4-container-actions)).
2. Queue IDs are de-duplicated and checked against a fresh listing before the Postfix command; an ID
   delivered between that check and the command is reported `done` (Postfix ignores it)
   ([R17](./research.md#r17-queue-operations-through-dockerapi-exec)).
3. The ban list address uses `MAILCOW_HOSTNAME` instead of the request's host name
   ([R18](./research.md#r18-settings-storage-version-tag-and-atomic-writes)).
4. The ban service's unguarded read-then-write of `F2B_OPTIONS` can overwrite a change stored in the
   same instant, as in legacy; it cannot be closed without changing netfilter (R18).
5. Rate limit log entries whose name or hash legacy recorded as `err` report `null`
   ([R12](./research.md#r12-redis-list-logs)).

## Contract Operations

| Method and path | operationId | Auth | Story / FR |
|---|---|---|---|
| GET `/host` | getHostOverview | admin session or key (ro too) | US1, FR-005, FR-006, FR-012, FR-059 |
| GET `/host/mail-storage` | getMailStorageUsage | admin session or key (ro too) | US1, FR-007 |
| GET `/host/public-addresses` | getPublicAddresses | admin session or key (ro too) | US7, FR-021 |
| GET `/installation` | getInstallationVersion | admin session or key (ro too) | US7, FR-018 |
| GET `/installation/update-status` | getUpdateStatus | admin session or key (ro too) | US7, FR-019, FR-020 |
| GET `/capabilities/monitoring` | getMonitoringCapabilities | admin session or key (ro too) | US1, FR-069 |
| GET `/containers` | listContainers | admin session or key (ro too) | US1, FR-008, FR-009 |
| GET `/containers/{containerName}` | getContainer | admin session or key (ro too) | US1, FR-010 |
| GET `/containers/{containerName}/resource-usage` | getContainerResourceUsage | admin session or key (ro too) | US1, FR-011 |
| POST `/containers/{containerName}/start` | startContainer | admin session + CSRF or rw key | US2, FR-013–FR-016 |
| POST `/containers/{containerName}/stop` | stopContainer | admin session + CSRF or rw key | US2, FR-013–FR-017 |
| POST `/containers/{containerName}/restart` | restartContainer | admin session + CSRF or rw key | US2, FR-013–FR-017 |
| GET `/logs/postfix` | listPostfixLog | admin session or key (ro too) | US3, FR-022–FR-027 |
| GET `/logs/dovecot` | listDovecotLog | admin session or key (ro too) | US3 |
| GET `/logs/sogo` | listSogoLog | admin session or key (ro too) | US3 |
| GET `/logs/netfilter` | listNetfilterLog | admin session or key (ro too) | US3 |
| GET `/logs/autodiscover` | listAutodiscoverLog | admin session or key (ro too) | US3 |
| GET `/logs/watchdog` | listWatchdogLog | admin session or key (ro too) | US3, FR-023 |
| GET `/logs/acme` | listAcmeLog | admin session or key (ro too) | US3, FR-023 |
| GET `/logs/cron` | listCronLog | admin session or key (ro too) | US3 |
| GET `/logs/rate-limits` | listRateLimitLog | admin session or key (ro too) | US3, FR-031 |
| GET `/logs/legacy-api-requests` | listLegacyApiRequestLog | admin session or key (ro too) | US3, FR-028 |
| GET `/logs/legacy-ui-activity` | listLegacyUiActivityLog | admin session or key (ro too) | US3, FR-028, FR-029 |
| GET `/logs/logins` | listLoginLog | admin session or key (ro too) | US3, FR-030 |
| GET `/rspamd/history` | listRspamdHistory | admin session or key (ro too) | US6, FR-033, FR-034, FR-036 |
| GET `/rspamd/statistics` | getRspamdStatistics | admin session or key (ro too) | US6, FR-035, FR-036 |
| GET `/mail-queue/messages` | listMailQueueMessages | admin session or key (ro too) | US4, FR-037, FR-038, FR-043 |
| GET `/mail-queue/messages/{queueId}/content` | getMailQueueMessageContent | admin session or key (ro too) | US4, FR-039, FR-062 |
| POST `/mail-queue/messages/bulk-actions` | bulkMailQueueMessages | admin session + CSRF or rw key | US4, FR-040, FR-042 (deliver, hold, release, delete) |
| POST `/mail-queue/flush` | flushMailQueue | admin session + CSRF or rw key | US4, FR-041, FR-042 |
| POST `/mail-queue/purge` | purgeMailQueue | admin session + CSRF or rw key | US4, FR-041, FR-042 |
| GET `/fail2ban/settings` | getFail2BanSettings | admin session or key (ro too) | US5, FR-044 |
| PATCH `/fail2ban/settings` | updateFail2BanSettings | admin session + CSRF or rw key | US5, FR-045–FR-048, FR-057, FR-061 |
| POST `/fail2ban/ban-list-rotation` | rotateFail2BanBanListId | admin session + CSRF or rw key | US5, FR-055 |
| GET `/fail2ban/rules` | getFail2BanRules | admin session or key (ro too) | US5, FR-049 |
| PUT `/fail2ban/rules` | replaceFail2BanRules | admin session + CSRF or rw key | US5, FR-050, FR-057 |
| POST `/fail2ban/rules/reset` | resetFail2BanRules | admin session + CSRF or rw key | US5, FR-051, FR-064 |
| GET `/fail2ban/active-bans` | listFail2BanActiveBans | admin session or key (ro too) | US5, FR-052 |
| POST `/fail2ban/active-bans/{network}/lift` | liftFail2BanBan | admin session + CSRF or rw key | US5, FR-053 |
| POST `/fail2ban/active-bans/{network}/allowlist` | allowlistFail2BanBan | admin session + CSRF or rw key | US5, FR-053 |
| POST `/fail2ban/active-bans/{network}/denylist` | denylistFail2BanBan | admin session + CSRF or rw key | US5, FR-053 |
| GET `/fail2ban/permanent-bans` | listFail2BanPermanentBans | admin session or key (ro too) | US5, FR-052, FR-054 |

US8 (trace operations) uses 001's `GET /audit-events` with 002's `targetType`/`targetId` filters
and adds no operation. Problem types are documented in [`docs/problems.md`](../../docs/problems.md).

## Project Structure

### Documentation (this feature)

```text
specs/011-monitoring-operations/
├── spec.md              # Feature specification (with clarifications, incl. orchestrator decisions)
├── plan.md              # This file
├── research.md          # Phase 0: decisions R1–R33
├── data-model.md        # Phase 1: tables read, Redis keys, dockerapi and Rspamd calls
├── quickstart.md        # Phase 1: validation guide
├── contracts/README.md  # Phase 1: pointer to api/modules (the contract is not duplicated)
├── checklists/
└── tasks.md             # Phase 2 (/speckit-tasks); the /speckit-analyze summary is in the design report
```

### Source Code (repository root)

New and changed files of this feature, in 001's layout. Files marked *(002)*, *(006)* or *(001)*
are created by that feature and extended here.

```text
api/
├── modules/{host,containers,logs,rspamd-stats,mail-queue,fail2ban}/   # authored (42 operations)
└── components/{schemas,parameters,responses}/                         # new resource-named files

app/
├── Clients/
│   ├── DockerApi/                          # (002) extended
│   │   ├── DockerApiClient.php             # + containers, start, stop, containerStats, hostStats (restart, exec: 002)
│   │   ├── HttpDockerApiClient.php         # lookup switched to ?all=true
│   │   ├── DockerCallResult.php            # (002/003) + body if not yet present
│   │   ├── ContainerSnapshot.php
│   │   └── {DockerApiUnreachable,DockerApiTimeout}.php
│   ├── Rspamd/{RspamdController,SocketRspamdController}.php       # (006) + history()
│   ├── Releases/{ReleaseSource,GitHubReleaseSource}.php
│   ├── PublicAddress/{PublicAddressLookup,HttpPublicAddressLookup}.php
│   └── Fail2Ban/Fail2BanStore.php          # F2B_* keys (001's Fail2BanReporter stays)
├── Http/
│   ├── Controllers/Api/V2/
│   │   ├── {HostOverview,MailStorageUsage,PublicAddresses,Installation,UpdateStatus,MonitoringCapabilities}Controller.php
│   │   ├── {Container,ContainerResourceUsage,ContainerStart,ContainerStop,ContainerRestart}Controller.php
│   │   ├── LogEntryController.php          # one index for the 12 log types (route default `logType`)
│   │   ├── {RspamdHistory,RspamdStatistics}Controller.php
│   │   ├── {MailQueueMessage,MailQueueMessageContent,MailQueueBulkAction,MailQueueFlush,MailQueuePurge}Controller.php
│   │   └── {Fail2BanSettings,Fail2BanBanListRotation,Fail2BanRules,Fail2BanRulesReset,Fail2BanActiveBan,Fail2BanBanLift,Fail2BanBanAllowlist,Fail2BanBanDenylist,Fail2BanPermanentBan}Controller.php
│   ├── Requests/Api/V2/
│   │   ├── {ListContainers,ListLogEntries,ListRspamdHistory,ListMailQueueMessages,MailQueueBulkAction}Request.php
│   │   └── {UpdateFail2BanSettings,ReplaceFail2BanRules,ListFail2BanBans}Request.php
│   └── Resources/Api/V2/                   # Container, ContainerResourceUsage, HostOverview, MailStorageUsage, … resources
├── Models/Mailcow/LegacyUiLogEntry.php     # `logs`, read only (001's SaslLogEntry reused)
├── Policies/MonitoringPolicy.php           # gate operate-monitoring: administrators only
├── Rules/{Fail2BanAllowlistEntry,Fail2BanDenylistEntry,MailQueueId,LogCursorFormat}.php
├── Services/
│   ├── Audit/IrreversibleOperationAudit.php            # probe + retry + Audit-Recorded (FR-064)
│   ├── Config/MailcowConfig.php                         # (001) + app_info.inc.php values, DOCKER_TIMEOUT
│   ├── Monitoring/
│   │   ├── SharedSnapshotCache.php, Snapshot.php
│   │   ├── ContainerRegistry.php, ContainerRoleMap.php, ContainerControlService.php
│   │   ├── ContainerResourceUsageService.php, DockerStatsCalculator.php
│   │   ├── HostOverviewService.php, MailStorageService.php, DfOutputParser.php
│   │   ├── InstallationVersionService.php, UpdateCheckService.php, PublicAddressService.php
│   │   └── MonitoringCapabilitiesService.php
│   ├── Logs/
│   │   ├── LogType.php (enum), LogQuery.php, LogPage.php, LogCursor.php
│   │   ├── RedisListLogSource.php, LegacyUiActivityLogSource.php, LoginLogSource.php
│   │   ├── Mappers/{Syslog,Netfilter,Autodiscover,Watchdog,Acme,Cron,RateLimit,LegacyApiRequest}EntryMapper.php
│   │   ├── LogSeverity.php, Base64Message.php
│   │   └── SecretMasker.php, LegacyApiDataParser.php
│   ├── Rspamd/{RspamdHistoryService,RspamdStatisticsService}.php
│   ├── MailQueue/{MailQueueService,PostqueueJsonParser,MailQueueListing}.php
│   └── Fail2Ban/
│       ├── Fail2BanSettingsService.php, Fail2BanSettingsValues.php, Fail2BanVersionTag.php
│       ├── Fail2BanRulesService.php, Fail2BanBanService.php
│       └── BanServiceStatus.php
└── Support/
    ├── Regex/PythonRegexValidator.php
    └── Network/{IpNetwork,NetworkSort}.php

config/restv2.php                          # (001) + R27 settings, monitoring constants
resources/bin/export-mailcow-vars.php      # (001) + app_info.inc.php, DOCKER_TIMEOUT
resources/bin/validate-python-regex.py
routes/api.php                             # + the 42 routes in one admin-only group
docker/php/Dockerfile                      # (001) + python3
docker/php/php-fpm.conf                    # (001) + request_terminate_timeout
docker/nginx/dev.conf, deploy/nginx/site.restv2.custom   # (001) fastcgi_read_timeout 90s
deploy/docker-compose.override.yml         # (001) + env and rspamd-vol-1 (ro)

tests/
├── Support/
│   ├── Fakes/{FakeDockerApiClient,FakeRspamdController,FakeReleaseSource,FakePublicAddressLookup}.php
│   ├── Monitoring/MonitoringFixtures.php  # loads recorded dockerapi, postqueue, postcat and Rspamd payloads
│   └── Redis/MailcowLogs.php              # pushes legacy-format log lines and F2B_* keys
├── fixtures/monitoring/                   # containers.json, host-stats.json, stats-*.json, postqueue-j.txt, postcat-*.txt, rspamd-history.json, rspamd-stat.json, df-*.txt
├── Feature/{Monitoring,Containers,Logs,Rspamd,MailQueue,Fail2Ban}/
├── Feature/Security/MonitoringAuthorizationTest.php
├── Feature/Audit/MonitoringAuditTrailTest.php
├── Feature/Performance/MonitoringLatencyTest.php
└── Unit/{Monitoring,Logs,MailQueue,Fail2Ban}/
```

**Structure Decision**: The single Laravel project of feature 001. Business logic lives in
`app/Services/` per aggregate (monitoring snapshots and container control, logs, Rspamd, mail queue,
ban service); every external system sits behind `app/Clients/` and is faked in tests; the `F2B_*`
keys are a Redis-backed subsystem behind `Fail2BanStore`, tested on the real Redis. Controllers are
thin, one per resource or sub-resource action (`index`, `show`, `store`, `update`), as in 001.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Status `502` (not in constitution II's list) for `container-action-failed` and `queue-action-failed` | The container service or Postfix answered with a failure: the API worked, an upstream failed, and clients must show the upstream message (FR-016, FR-042). RFC 9110 defines 502 for exactly this. | `500` means "bug" to clients and monitoring and would hide actionable upstream messages; `409` would claim a conflict with the resource state. |
| Status `504` (not in the list) for `outcome-unknown` (shared with 003) and `ban-rules-not-restored` | After `DOCKER_TIMEOUT` the outcome is unknown, and after a rule reset the ban service did not answer in time (FR-016, FR-042, FR-051). RFC 9110 defines 504 for an upstream that did not answer in time. | `503` with `Retry-After` invites an automatic retry of an operation that may have completed (a restart, a deletion, a reset). |
| dockerapi's TLS certificate is not verified (constitution VI baseline) | dockerapi serves mailcow's mail certificate, whose names never match `dockerapi`; legacy disables verification for the same reason, and dockerapi is reachable only on mailcow's internal network. | Verifying would need a certificate and name dockerapi does not have, i.e. a change to dockerapi (constitution V). |
| Queue actions write one audit record per request, not one per queued message (`docs/conventions.md`) | The spec's clarification 5 decides it: queued messages are transient system objects, and up to 1,000 records per request would bury the audit log. The record lists every ID with its result. | One record per message, rejected by the spec. |
| `Audit-Recorded: false` on bulk queue actions is described in the operation text, not declared as a response header | Queue bulk actions must use the shared `BulkResultResponse`, and OpenAPI 3.1 cannot add a header to a referenced response. The header is declared formally on every other irreversible operation. | A queue-specific copy of `BulkResultResponse` would break the shared-component convention. |
| An OS package (python3) in the API image | FR-050 and SC-008 require rules to compile in the ban service's Python `re` dialect; only Python decides that reliably. | A PCRE approximation accepts rules Python rejects and the reverse (R20). |
