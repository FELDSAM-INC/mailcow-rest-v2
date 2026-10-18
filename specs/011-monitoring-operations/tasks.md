---
description: "Task list for feature 011: monitoring and operations"
---

# Tasks: Monitoring and Operations

**Input**: Design documents from `specs/011-monitoring-operations/`: [plan.md](./plan.md),
[spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md),
[quickstart.md](./quickstart.md), [contracts/README.md](./contracts/README.md). The contract is
`api/` (modules `host`, `containers`, `logs`, `rspamd-stats`, `mail-queue`, `fail2ban`, already
authored and lint-clean). Problem types are in `docs/problems.md`.

**Prerequisites from earlier features** (implemented in number order, research R1):

- **001**: the `api.v2` pipeline, `Principal`, `ProblemException`/`ProblemType`, `ETag`,
  `AuditLogger` and `restv2_audit_events`, `MailcowConfig` and
  `resources/bin/export-mailcow-vars.php`, the Redis connections `mailcow` and `restv2`, the cache
  store `restv2`, `MailcowDateTime`, `SaslLogEntry`, `Sleeper`, the test toolkit
  (`tests/TestCase.php` with `signInAs()`, `OpenApiContract`, `MailcowFactory`, the contract
  coverage check) and the deployment files under `deploy/` and `docker/`.
- **002**: `App\Clients\DockerApi\DockerApiClient` and its HTTP adapter and test fake, the
  `ProblemType` case `TemporaryFailure`, and the audit filters `targetType`/`targetId` on
  `GET /audit-events`.
- **003**: the `ProblemType` case `OutcomeUnknown` (504, extension `step`).
- **006**: `App\Clients\Rspamd\RspamdController`, its socket adapter and test fake, and the
  `rspamd-vol-1` mount in `deploy/docker-compose.override.yml`.
- **008**: `App\Services\Audit\RefusalAuditor` with its `restv2.audit` route default and
  per-target resolvers (refusal auditing, `docs/conventions.md`).

Where these exist under other names, T001 records the mapping and every later task uses the
existing names with the method contracts of research R2 and R16.

**Tests**: Mandatory (constitution VII). In every phase the test tasks come first and must be
observed **failing** before the implementation tasks start. Feature tests run on MariaDB with the
`2026-09a` schema fixture and on a real Redis (database 15, where the `mailcow` connection also
points in tests). Every request and response is validated against the contract by
`tests/TestCase.php`. dockerapi, the Rspamd controller, the release source and the IP check
service are faked; the fakes record every call and tests assert them.

**Contract-violating input**: every case that sends input the contract forbids (a `limit` above
1,000, an unknown `sort` or enum value, a non-numeric number, more than 100 rules, a rule over 1,000
characters, more than 1,000 targets, a `version` on a queue target, a non-hexadecimal queue ID in a
body) lives in a test method whose name contains `Invalid` or `Malformed` and calls
`withoutRequestValidation()` (001 R13); the response is still validated.

**Organisation**: Phase 1 is setup, Phase 2 the foundation that blocks every story. Phases 3 to 10
are the user stories in spec priority order (US1–US4 P1, US5–US6 P2, US7–US8 P3). Phase 11 is
polish.

**Commands**: `docker compose run --rm app <command>` (001's dev stack).

## Format: `- [ ] [ID] [P?] [Story?] Description with file path`

- **[P]**: Can run in parallel with the other [P] tasks of the same block (different files, no
  dependency on unfinished tasks).
- **[USn]**: The user story. Setup, Foundational and Polish tasks have no story label.
- FR, SC and AS refer to `spec.md`; R refers to `research.md`.

---

## Phase 1: Setup (shared infrastructure)

**Purpose**: Reconcile with earlier features, runtime and deployment changes, configuration and
recorded fixtures. No business logic.

- [ ] T001 Reconcile the prerequisites (R1). Check that `app/Clients/DockerApi/DockerApiClient.php`
  with `restart()`, `exec()` and `DockerCallResult` (002, 003), `app/Clients/Rspamd/RspamdController.php`
  (006), `app/Services/Audit/RefusalAuditor.php` (008), the `ProblemType` cases `TemporaryFailure`
  and `OutcomeUnknown`, and the `targetType`/`targetId` filters of `GET /audit-events` exist. Where
  the implemented names differ from this document, replace them throughout this `tasks.md` before
  T007. If a prerequisite is missing, stop and implement it in its own feature first; 011 does not
  re-create 002's, 006's or 008's code.
- [ ] T002 [P] Update `docker/php/Dockerfile`, stage `base`: `apk add --no-cache python3` (R20,
  R29), and add a build-time check `python3 -c "import re, json"`. Update `docker/php/php-fpm.conf`
  with `request_terminate_timeout = 100s`. In `deploy/nginx/site.restv2.custom` and
  `docker/nginx/dev.conf`, change `fastcgi_read_timeout` from `60s` to `90s` (R28).
- [ ] T003 [P] Extend `deploy/docker-compose.override.yml` for service `restv2-mailcow`: environment
  `COMPOSE_PROJECT_NAME=${COMPOSE_PROJECT_NAME:-mailcow-dockerized}`, `SKIP_CLAMD=${SKIP_CLAMD:-n}`,
  `SKIP_OLEFY=${SKIP_OLEFY:-n}`, `LOG_LINES=${LOG_LINES:-9999}`, and the volume
  `rspamd-vol-1:/var/lib/rspamd:ro` if 006 did not add it (R28).
- [ ] T004 [P] Add the settings of R27 to `config/restv2.php` (`monitoring.*`, `fail2ban.*`,
  `update_check.*`, `ip_check.*`, `dockerapi.*`), with these constants in `monitoring`:
  `poll_interval = 5`, `max_age = [containers => 5, host => 10, usage_sample => 15, usage_cache =>
  5, mail_storage => 60]`, `queue_listing_max = 10000`, `queue_bulk_max = 1000`, `rules_max = 100`,
  `rule_max_length = 1000`, `ban_list_max_entries = 10000`, `ban_list_entry_max_length = 253`.
  Defaults: `RESTV2_DOCKERAPI_URL=https://dockerapi:443`, `RESTV2_SELF_SERVICE=restv2-mailcow`,
  `RESTV2_PROTECTED_CONTAINERS=dockerapi-mailcow,mysql-mailcow,redis-mailcow,nginx-mailcow,restv2-mailcow`,
  `RESTV2_CONTAINER_ROLES={}`, `RESTV2_POSTFIX_CONTAINER=postfix-mailcow`,
  `RESTV2_DOVECOT_CONTAINER=dovecot-mailcow`, `RESTV2_BAN_SERVICE_CONTAINER=netfilter-mailcow`,
  `RESTV2_UPDATE_CHECK=y` (006's `RESTV2_RSPAMD_SOCKET` is reused),
  `RESTV2_UPDATE_SOURCE_URL=` (empty → derived), `RESTV2_UPDATE_STABLE_BRANCH=master`,
  `RESTV2_IP_CHECK_IPV4_URL=http://ipv4.mailcow.email`,
  `RESTV2_IP_CHECK_IPV6_URL=http://ipv6.mailcow.email` (002's `RESTV2_FAKE_OUTBOUND` is reused for
  the dev fakes; no new setting).
  Add the same names to `.env.example` and `.env.testing` (testing: `COMPOSE_PROJECT_NAME=mailcowtest`,
  `LOG_LINES=9999`, `TZ=Europe/Prague`, `IPV4_NETWORK=172.22.1`,
  `IPV6_NETWORK=fd4d:6169:6c63:6f77::/64`).
- [ ] T005 [P] Record the fixtures of `tests/fixtures/monitoring/` from a `2026-09a` installation
  (anonymised, no secrets; each file starts with a comment line or a sibling `.md` naming its
  source command):
  - `containers.json` (dockerapi `/containers/json?all=true` with 19 mailcow services of project
    `mailcowtest`, one container of another project, `sogo-mailcow` exited, `clamd-mailcow`
    present, `Config.Env` populated with fake secrets such as `DBPASS=planted-secret`);
  - `host-stats.json`; `stats-postfix-1.json` (one sample) and `stats-postfix-3.json` (three samples
    5 s apart, cgroup v2), `stats-cgroup-v1.json`;
  - `df-ok.txt` (`"/dev/sda1,107G,27G,76G,26%,/var/vmail"`), `df-wrapped.txt`
    (`",1.1T,250G,800G,24%,/var/vmail"`), `df-failed.txt` (`"0,0,0,0,0,0"`);
  - `postqueue-j.txt` (3 deferred to `partner.example`, 2 active, 2 hold, 1 incoming, with delay
    reasons and `forced_expire`), `postqueue-j-12000.txt` generator script
    `tests/fixtures/monitoring/make-large-queue.php`;
  - `postcat-ok.txt` (starts with `*** ENVELOPE RECORDS deferred/…`), `postcat-missing.txt`
    (`postcat: fatal: open queue file 4F0A1C2B3D: No such file or directory`);
  - `rspamd-history.json` (version 2, 30 rows including a `reject` from `spammer@example.net`
    with positive, negative and zero symbols), `rspamd-stat.json`;
  - `github-latest.json`, `github-tag-2026-09a.json`.
- [ ] T006 [P] Create `tests/fixtures/monitoring/app_info.inc.php` and
  `tests/fixtures/monitoring/vars.local.inc.php` (sets `$DOCKER_TIMEOUT = 30;`) for the
  `MailcowConfig` tests (format of upstream `generate_config.sh`: `MAILCOW_GIT_VERSION="2026-09a"`,
  `MAILCOW_BRANCH="master"`, empty commit, `MAILCOW_GIT_URL`, owner `mailcow`, repo
  `mailcow-dockerized`).

**Checkpoint**: images build; `composer test` still green for features 001–010.

---

## Phase 2: Foundational (blocking prerequisites)

**Purpose**: Fakes and test helpers, the dockerapi operations of R2, the shared snapshot cache, the
container registry, the audit helper for irreversible operations, problem types, configuration
values, authorisation and the route group. **No user story starts before this phase is done.**

### Tests and toolkit for the foundation (write first, observe failing) ⚠️

- [ ] T007 [P] Extend 002's `tests/Support/Fakes/FakeDockerApiClient.php` (it implements
  `DockerApiClient`) with scripted answers per method and service (`withContainers(array)`,
  `withHostStats(array)`, `withStats(service, list)`, `withExec(service, cmd, task, answer)`,
  `failNext(method, 'unreachable'|'timeout'|'danger:<msg>')`), a call log (`calls()`,
  `assertCalled(method, …)`, `assertNotCalled(method)`, `assertCallCount(method, n)`), and
  state transitions for actions (`start` sets running, `stop` clears it, `restart` sets a new
  `StartedAt`), configurable to stay stopped for N attempts. Create
  `tests/Support/Monitoring/MonitoringFixtures.php` (loads `tests/fixtures/monitoring/*`) and
  `tests/Support/Redis/MailcowLogs.php` (pushes log lines in each producer's exact format to the
  `mailcow` connection, and writes `F2B_*` keys as legacy and netfilter write them, including
  Python `json.dumps` spacing for `F2B_OPTIONS`).
- [ ] T008 [P] Extend 006's `tests/Support/Rspamd/FakeRspamdController.php` with `history()` and
  `stat()` answers from fixtures, `unreachable()`, and a call log; create
  `tests/Support/Fakes/FakeReleaseSource.php` (`latest()`, `byTag()`, `fail()`, call counter); and
  `tests/Support/Fakes/FakePublicAddressLookup.php` (per-family answers, call counter).
- [ ] T009 [P] Extend 002's `tests/Unit/Clients/HttpDockerApiClientTest.php` (`Http::fake()`, R2):
  `containers()` calls `GET /containers/json?all=true`, keeps only project `mailcowtest`
  (case-insensitive), keys by service label, maps `0001-01-01T00:00:00Z`, absent health and
  health `none` to `null`, and the serialised `ContainerSnapshot` contains no `Env` value (assert `planted-secret` is
  absent from `serialize()` and `json_encode()`); the ID lookup of `start()`, `stop()` and
  `restart()` uses `?all=true`, so a stopped container is found, and they post to
  `/containers/{id}/{action}`; `containerStats()` posts to `/container/{id}/stats/update` with a 5 s
  timeout; `hostStats()` uses 5 s; `exec()` posts `{cmd, task, …}` and returns dockerapi's answer as
  `DockerCallResult::$body` (JSON, JSON string or text); TLS verification is off; connection errors
  → `DockerApiUnreachable` for the reads and `failed` with `unreachable` for actions and exec,
  timeouts → `DockerApiTimeout` for the reads and `unknown` for actions and exec,
  `{"type":"danger","msg":"x"}` → `failed('x')`; `DOCKER_TIMEOUT` is the default timeout. The
  existing 002/003 cases stay green.
- [ ] T010 [P] Write `tests/Unit/Monitoring/SharedSnapshotCacheTest.php` (R5) on the real Redis:
  a fresh snapshot is served without calling the loader; a snapshot older than the maximum age
  calls the loader once; two concurrent callers (simulated by holding the lock in the test and
  releasing it) cause one loader call; `forget()` invalidates; the stored TTL is `maxAge + 5`;
  a lock wait timeout falls back to a direct load.
- [ ] T011 [P] Write `tests/Unit/Monitoring/ContainerRoleMapTest.php` and
  `tests/Feature/Monitoring/ContainerRegistryTest.php` (R3): every upstream service maps to its role
  (`mysql-mailcow` → `database`, `redis-mailcow` → `keyValueStore`, `postfix-mailcow` →
  `mailTransfer`, `dovecot-mailcow` → `imap`, `sogo-mailcow` → `webmail`, `nginx-mailcow` → `proxy`,
  `ofelia-mailcow` → `scheduler`, `restv2-mailcow` → `api`, `foo-mailcow` → `other`);
  `RESTV2_CONTAINER_ROLES` overrides; `SKIP_CLAMD=y` and `SKIP_OLEFY=yes` hide the scanners;
  protected and own-container flags (also by host-name prefix of the container ID); available
  actions per state; `banServiceRunning()` returns `true`, `false` or `null` (dockerapi down).
- [ ] T012 [P] Write `tests/Feature/Config/MailcowConfigInstallationTest.php` (R9): with the T006
  fixtures mounted as the `inc` directory, `MailcowConfig` returns the `app_info.inc.php` values and
  `DOCKER_TIMEOUT = 30` from `vars.local.inc.php`; `RESTV2_DOCKER_TIMEOUT` overrides it; without the
  files the defaults are `null` and `60`; touching `app_info.inc.php` changes the fingerprint and
  the next call sees the new version; empty strings become `null`; `MAILCOW_GIT_COMMIT_DATE`
  `2026-09-15 10:11:12 +0200` becomes `2026-09-15T08:11:12Z`.
- [ ] T013 [P] Write `tests/Feature/Audit/IrreversibleOperationAuditTest.php` (R24, FR-064):
  `probe()` throws a `ProblemException(TemporaryFailure)` when the audit table cannot be read
  (simulate by revoking with a broken connection config in the test); `recordWithRetry()` inserts
  exactly one row; after an injected connection failure it reconnects and retries every second with
  the faked `Sleeper` until the deadline; a duplicate ULID on retry counts as success; after the
  deadline it logs at `error` without secrets and reports `recorded = false`, and the
  `withAuditRecorded(false)` response macro adds `Audit-Recorded: false`.
- [ ] T014 [P] Write `tests/Unit/Support/ProblemTypeMonitoringTest.php`: the `ProblemType` cases
  `ContainerProtected` (409), `ContainerNotRunning` (409), `ContainerActionFailed` (502),
  `MailServiceNotRunning` (409), `QueueActionFailed` (502), `BanRulesNotRestored` (504) and
  `IpCheckDisabled` (409) exist with the URIs of `docs/problems.md`; 001's
  `ProblemTypeConsistencyTest` stays green.
- [ ] T015 [P] Create `tests/Feature/Security/MonitoringAuthorizationTest.php` with a data provider
  `operations()` (method, path, body) that each story phase extends with its operations, and the
  cases: signed-in administrator → not `401`/`403`; domain administrator and mailbox user sessions →
  `403 forbidden`; read-write key owned by a domain administrator → `403 forbidden` on every
  method; read-only key owned by a domain administrator → `403 forbidden` on `GET` and `403
  insufficient-key-scope` on other methods (001's pipeline checks the key scope first, R26);
  read-only administrator key → allowed on every `GET`, `403 insufficient-key-scope` on every other
  method;
  read-write administrator key → allowed; restricted administrator session (`force_pw_update`) →
  `403 pending-action-required`; no credentials → `401 unauthenticated`; session without CSRF token
  on unsafe methods → `403 csrf-token-mismatch`. **Refusal auditing** (R24): every refused
  state-changing request of an authenticated caller (`forbidden`, `insufficient-key-scope`,
  `pending-action-required`) writes exactly one failure record with `permissionDenied`, the route's
  event type and target type, and `details.problemType`, and so does a refused message content view
  (the one read that declares `restv2.audit`); CSRF failures, `401`s and other refused reads write
  none. Seed with an empty provider row set so the class runs (and is skipped) until US1
  adds rows.

### Implementation of the foundation

- [ ] T016 Extend 002's `app/Clients/DockerApi/DockerApiClient.php` and `HttpDockerApiClient.php`
  with `containers()`, `start()`, `stop()`, `containerStats()` and `hostStats()`, switch the ID
  lookup to `GET /containers/json?all=true`, and add `body` to
  `app/Clients/DockerApi/DockerCallResult.php` if 003 or 005 did not (R2). Create
  `app/Clients/DockerApi/ContainerSnapshot.php` (readonly: `id`, `service`, `image`, `status`,
  `running`, `startedAt`, `health`; no environment) and the exceptions `DockerApiUnreachable` and
  `DockerApiTimeout` in the same directory. Extend `tests/Support/Fakes/FakeDockerApiClient.php`
  (002) as described in T007. This makes T009 pass.
- [ ] T017 [P] Create `app/Services/Monitoring/Snapshot.php` and `SharedSnapshotCache.php` (R5) on
  the `restv2` cache store with `Cache::lock()`. This makes T010 pass.
- [ ] T018 Create `app/Services/Monitoring/ContainerRoleMap.php` and `ContainerRegistry.php` (R3):
  `snapshot(): Snapshot` (cached 5 s), `fresh(): list<ContainerSnapshot>`,
  `find(string $service, bool $fresh = false): ?ContainerSnapshot` (hidden and foreign → `null`),
  `isProtected()`, `isOwnContainer()`, `role()`, `availableActions()`, `banServiceRunning(): ?bool`,
  `forget(string $service)`. Depends on T016 and T017. This makes T011 pass.
- [ ] T019 [P] Extend `resources/bin/export-mailcow-vars.php` and
  `app/Services/Config/MailcowConfig.php` (001) with `app_info.inc.php` (included when present) and
  the values of R9, and the three-file fingerprint; add `app/Services/Monitoring/InstallationVersionService.php`
  returning an `InstallationVersion` value (`tag`, `branch`, `commit`, `commitDate` UTC,
  `projectUrl`). This makes T012 pass.
- [ ] T020 [P] Create `app/Services/Audit/IrreversibleOperationAudit.php` (`probe()`,
  `recordWithRetry(AuditEventType, …, float $deadline): bool`) on top of 001's `AuditLogger`, and
  the response macro `withAuditRecorded(bool)` in `app/Providers/AppServiceProvider.php`. This makes
  T013 pass.
- [ ] T021 [P] Add the seven cases of T014 to `app/Support/Http/ProblemType.php` with their
  statuses and English titles, and render the extension members `container` and `serviceMessage`
  from `ProblemException` context. Map exceptions in `bootstrap/app.php`:
  `DockerApiUnreachable`, `DockerCallResult` with `unreachable`, and Redis/PDO connection failures
  inside the monitoring route group →
  `503 temporary-failure` with `Retry-After: 5`. This makes T014 pass.
- [ ] T022 Create `app/Policies/MonitoringPolicy.php` and register the gate `operate-monitoring`
  (role `admin` only) in `AppServiceProvider`; add an empty route group in `routes/api.php` with
  `restv2.auth:authenticated`, the default `restv2.pending:enforce` and `can:operate-monitoring`,
  under which every route of this feature is registered; every state-changing route declares
  `->defaults('restv2.audit', ['event' => …, 'target' => …])` (R24). Create
  `app/Services/Monitoring/MonitoringAuditTargetResolver.php` and register it with 008's
  `App\Services\Audit\RefusalAuditor` for the target types `container`, `mailQueue`,
  `mailQueueMessage`, `fail2banSettings`, `fail2banRules`, `fail2banActiveBan` and
  `fail2banBanListId` (R24: route
  parameter as sent, decoded network, or a `null` target with `details.itemIds` for queue bulk
  requests), and let services that write their own failure record mark the request as audited so
  the auditor skips it. Where 002's `RESTV2_FAKE_OUTBOUND=y` binds the outbound fakes in
  `APP_ENV=local`, also bind `FakeRspamdController`, `FakeReleaseSource` and
  `FakePublicAddressLookup` and load `tests/fixtures/monitoring/` into `FakeDockerApiClient`.

**Checkpoint**: Foundation ready; T009–T014 green; T015 runs with no rows.

---

## Phase 3: User Story 1 — See the state of the host and its containers (P1) 🎯 MVP

**Goal**: System overview, mail storage usage, container list and details with roles, resource
usage with rates, and the monitoring capabilities read, collected once for all clients.

**Independent test**: Spec US1 "Independent Test", automated with the fakes: read overview, list,
container and usage; compare with fixtures; count dockerapi calls with ten concurrent pollers.

### Tests for User Story 1 (write first, observe failing) ⚠️

- [ ] T023 [P] [US1] Write `tests/Feature/Monitoring/HostOverviewTest.php` (AS-1, FR-005, FR-006,
  R6): `GET /host` returns `hostName` (`MAILCOW_HOSTNAME`), `timeZone`, `architecture`, `version`
  (from T019), `currentTime` (UTC), `uptimeSeconds` (integer), `cpu`, `memory`, `swap` mapped from the
  psutil list, and `collectedAt` parsed from `system_time` in `Europe/Prague` (the fake stamps
  `system_time` with the current, travelled time unless a test sets it); a second request within
  10 s makes no dockerapi call; after 10 s (`travel`) one call; a dockerapi answer whose
  `system_time` is already 8 s old is served for only 2 more seconds; dockerapi unavailable →
  `503 temporary-failure` with `Retry-After`.
- [ ] T024 [P] [US1] Write `tests/Feature/Monitoring/MonitoringSharedCollectionTest.php` (SC-004):
  ten requests from ten different administrator sessions within 5 s cause exactly one
  `hostStats()` call and one `containers()` call; after `travel(6)` seconds the next container read
  calls `containers()` again but the host figures are still served from the snapshot; after 11 s
  `hostStats()` is called again; container actions invalidate the container snapshot (asserted in
  US2).
- [ ] T025 [P] [US1] Write `tests/Unit/Monitoring/DfOutputParserTest.php` and
  `tests/Feature/Monitoring/MailStorageUsageTest.php` (AS-2, FR-007, R8): `107G` → 107,000,000,000
  bytes with `approximate: true`; a 100 GB disk with 25 GB used reports 25%; the wrapped line has
  `device: null`; `0,0,0,0,0,0`, `null` and garbage → `measured: false` with `null` figures (never
  zeros); the exec call is `exec('dovecot-mailcow', ['cmd' => 'system', 'task' => 'df', 'dir' => '/var/vmail'])`; cached
  60 s; dockerapi unavailable → `503`.
- [ ] T026 [P] [US1] Write `tests/Feature/Containers/ListContainersTest.php` (AS-3, AS-6, FR-008,
  FR-009): stopped SOGo listed with `running: false`, `state: exited` and its last start; with
  `SKIP_CLAMD=y` the scanner is absent; the foreign project's container is absent; each item has
  `protected`, `role`, `availableActions` and `collectedAt`; sort by `name` (default), `state`,
  `startedAt` with ties by name; `running=false` filter; `q=postfix` matches name and `q=ghcr.io`
  matches image; paging and `meta`; `limit=0` and `sort=image` → `422`; no response contains
  `planted-secret`.
- [ ] T027 [P] [US1] Write `tests/Feature/Containers/GetContainerTest.php` (FR-010, FR-003):
  `GET /containers/postfix-mailcow`; a foreign container's service name, `clamd-mailcow` with
  `SKIP_CLAMD=y` and an unknown name → `404`; an invalid name pattern → `404` (route constraint).
- [ ] T028 [P] [US1] Write `tests/Unit/Monitoring/DockerStatsCalculatorTest.php` and
  `tests/Feature/Containers/ContainerResourceUsageTest.php` (AS-4, AS-5, FR-011, R7): one sample →
  counters and `null` rates and `previousSampledAt: null`; three samples → rates in bytes per second
  between the newest two (≥ 1 s apart); cgroup v1 `Read`/`Write` and v2 `read`/`write` ops; missing
  networks → 0; CPU percent per the docker formula, `null` when deltas are not positive; a
  container not running → `409 container-not-running` with `container` and **no** `containerStats()`
  call; a `containerStats()` timeout with the container still running → `503`, with the container
  stopped meanwhile → `409`; cached 5 s per service.
- [ ] T029 [P] [US1] Write `tests/Feature/Monitoring/MonitoringCapabilitiesTest.php` (FR-069,
  R32): `ipCheckEnabled` follows Redis `IP_CHECK`; `pollIntervalSeconds: 5`; `maxAgeSeconds`
  `{5, 10, 15, 60}`; `logRetention` gives `entries` 10,000 for the in-memory logs with
  `LOG_LINES=9999`, `entries` 101 for autodiscover, `unbounded` for `legacyUiActivity`,
  `cleanedDaily` for `logins`; `clientAddress` equals the request's `REMOTE_ADDR`; `limits` values;
  no dockerapi call is made; a read-only administrator key may read it.
- [ ] T030 [US1] Add the US1 operations (`GET /host`, `/host/mail-storage`,
  `/capabilities/monitoring`, `/containers`, `/containers/postfix-mailcow`,
  `/containers/postfix-mailcow/resource-usage`) to the provider of
  `tests/Feature/Security/MonitoringAuthorizationTest.php` (AS-7, FR-001, FR-002).

### Implementation for User Story 1

- [ ] T031 [P] [US1] Create `app/Services/Monitoring/HostOverviewService.php` (R6) and
  `app/Http/Resources/Api/V2/HostOverviewResource.php`.
- [ ] T032 [P] [US1] Create `app/Services/Monitoring/DfOutputParser.php` and
  `MailStorageService.php` (R8), and `app/Http/Resources/Api/V2/MailStorageUsageResource.php`.
- [ ] T033 [P] [US1] Create `app/Services/Monitoring/DockerStatsCalculator.php` and
  `ContainerResourceUsageService.php` (R7), and `ContainerResourceUsageResource.php`.
- [ ] T034 [P] [US1] Create `app/Services/Monitoring/MonitoringCapabilitiesService.php` (R32) and
  `MonitoringCapabilitiesResource.php`.
- [ ] T035 [US1] Create `app/Http/Requests/Api/V2/ListContainersRequest.php` (`limit` 1–1,000
  default 25, `offset` ≥ 0, `sort` in `name|state|startedAt`, `order`, `q` ≤ 255, `running`
  boolean; rule codes of 001) and `app/Http/Resources/Api/V2/ContainerResource.php` (fields of the
  contract, from `ContainerRegistry`).
- [ ] T036 [US1] Create the controllers `HostOverviewController`, `MailStorageUsageController`,
  `MonitoringCapabilitiesController` (`show`), `ContainerController` (`index`, `show`) and
  `ContainerResourceUsageController` (`show`) in `app/Http/Controllers/Api/V2/`, and register
  `GET /host`, `/host/mail-storage`, `/capabilities/monitoring`, `/containers`,
  `/containers/{containerName}` and `/containers/{containerName}/resource-usage` in the monitoring
  group of `routes/api.php` (`containerName` constrained to `[a-z0-9][a-z0-9_.-]{0,62}`). This
  makes T023–T030 pass.

**Checkpoint**: US1 green; contract coverage includes six operations of this feature.

---

## Phase 4: User Story 2 — Start, stop and restart a container (P1)

**Goal**: Container control with legacy's retries, protection, timeouts and one audit record per
action.

**Independent test**: Spec US2 "Independent Test" with the fake answering, failing and timing out.

### Tests for User Story 2 (write first, observe failing) ⚠️

- [ ] T037 [P] [US2] Write `tests/Feature/Containers/StartContainerTest.php` (AS-2, FR-014,
  FR-016, R4): a running container → `200 alreadyInState` and no `start()`/`stop()`/`restart()` call; a stopped
  container → `done`, `container.running: true`, calls `start($service)` once; a
  container that starts on the third attempt → three calls and two `Sleeper` sleeps of 1.5 s; one
  that never starts → four calls, then `502 container-action-failed` with `serviceMessage`; a
  protected container may be started; dockerapi timeout → `504` type `outcome-unknown` with
  `step: start`; dockerapi unavailable before the action → `503`, nothing called; dockerapi
  unreachable on the second attempt after a first attempt was sent → `504 outcome-unknown`; an
  unknown name, a foreign project's container and a hidden scanner → `404`.
- [ ] T038 [P] [US2] Write `tests/Feature/Containers/StopContainerTest.php` (AS-3, AS-4, FR-014,
  FR-015): `mysql-mailcow` → `409 container-protected` naming it, no dockerapi action; the own
  container (`restv2-mailcow`, and a container whose ID starts with the test's host name) →
  `409`; a stopped container → `alreadyInState`; `clamd-mailcow` running → `done`; retries as in
  T037; `RESTV2_PROTECTED_CONTAINERS` changes the list.
- [ ] T039 [P] [US2] Write `tests/Feature/Containers/RestartContainerTest.php` (AS-1, AS-3, AS-5,
  FR-014, FR-016, SC-009): SOGo running → `done` with a new `startedAt`; a stopped container is
  started by restart; `mysql-mailcow` may be restarted; the own container → `409`; timeout →
  `504 outcome-unknown` (`step: restart`); `danger` → `502`; after any action the next
  `GET /containers` makes a fresh `containers()` call (snapshot invalidated).
- [ ] T040 [P] [US2] Write `tests/Feature/Containers/ContainerActionAuditTest.php` (AS-7, FR-062,
  FR-064, R24): every outcome of T037–T039 (done, alreadyInState, protected, failed, outcome
  unknown) writes exactly one `restv2_audit_events` row with event `containerStart`/`Stop`/`Restart`,
  target `container` and the service name, `details.stateBefore` and `details.stateAfter`, and the
  failure reason (`conflict` with `details.problemType`, `serviceFailure`, `outcomeUnknown`);
  an audit probe failure → `503` and **no** dockerapi action; an audit insert that fails after a
  restart of `mysql-mailcow` and keeps failing until `DOCKER_TIMEOUT` → `200` with header
  `Audit-Recorded: false` and an `error` log line; read-only key → `403 insufficient-key-scope`
  (AS-6) with exactly one failure row (`permissionDenied`, `details.problemType`) and no dockerapi
  call; a domain administrator's attempt → one `permissionDenied` row; dockerapi unavailable before
  the action → one row with `temporaryFailure`; an unknown container → one row with `notFound`.
- [ ] T041 [US2] Add `POST /containers/{name}/start|stop|restart` to the provider of
  `MonitoringAuthorizationTest`.

### Implementation for User Story 2

- [ ] T042 [US2] Create `app/Services/Monitoring/ContainerControlService.php` (R4):
  `start|stop|restart(Principal, string $service): ContainerActionOutcome`, using
  `ContainerRegistry::fresh()`, `IrreversibleOperationAudit`, the `Sleeper`, the request deadline
  (`DOCKER_TIMEOUT` from `MailcowConfig`) and `ContainerRegistry::forget()`; it raises
  `ProblemException(ContainerProtected|ContainerActionFailed|OutcomeUnknown)` with `container`,
  `serviceMessage` and `step`.
- [ ] T043 [US2] Create `app/Http/Resources/Api/V2/ContainerActionResultResource.php` and the
  controllers `ContainerStartController`, `ContainerStopController`, `ContainerRestartController`
  (`store`, calling `set_time_limit(DOCKER_TIMEOUT + 20)`, adding `Audit-Recorded: false` when the
  outcome says so) in `app/Http/Controllers/Api/V2/`; register the three routes in
  `routes/api.php`, each with `restv2.audit` (`containerStart`/`Stop`/`Restart`, target
  `container`). This makes T037–T041 pass.

**Checkpoint**: US2 green.

---

## Phase 5: User Story 3 — Read and search mailcow's logs (P1)

**Goal**: The twelve logs with normalised entries, paging, time range, severity, type-specific
filters, search on masked data and duplicate-free cursors.

**Independent test**: Spec US3 "Independent Test": fill each log with known entries (encoded,
secret-bearing, every priority, more than a page) and compare every filter and page.

### Tests for User Story 3 (write first, observe failing) ⚠️

- [ ] T044 [P] [US3] Write `tests/Unit/Logs/SecretMaskerTest.php` and
  `tests/Unit/Logs/LegacyApiDataParserTest.php` (FR-028, R15): names containing `pass`,
  `secret`, `private`, `token` (any case) and the exact name `key` are masked at any depth,
  including whole nested objects; `api_key`, `keyboard` and list elements are not masked by name;
  `attr='{"password":"x","dkim":{"private_key_file":"-----BEGIN"}}', items='["a"]'` parses into
  `{attr: {...}, items: [...]}` and masks both values; a value containing `', x='` inside a JSON
  string parses correctly; an unsplittable string → `null`.
- [ ] T045 [P] [US3] Write `tests/Unit/Logs/LogCursorTest.php` and `tests/Unit/Logs/Base64MessageTest.php`
  (R12): cursor encode/decode per source (Redis `<time>.<hash16>.<n>`, `logs` id, `sasl_log`
  base64url tuple), malformed cursors rejected; `base64,` + valid base64 of UTF-8 → decoded,
  `base64,` + text that is not base64 or decodes to invalid UTF-8 → text without the prefix.
- [ ] T046 [P] [US3] Write `tests/Feature/Logs/RedisLogTypesTest.php` with a data provider over the
  ten Redis types (`postfix`, `dovecot`, `sogo`, `netfilter`, `autodiscover`, `watchdog`, `acme`,
  `cron`, `rate-limits`, `legacy-api-requests`), pushing lines with `MailcowLogs` in the producers'
  exact formats: each entry has the contract fields; watchdog text numbers become integers; a
  watchdog message entry has `null` health fields; rate-limit `rcpt` becomes a list and `err`
  becomes `null`; netfilter `warn` → severity `warning`; an unparseable line is left out of the list
  and the total (FR-032); an ACME `base64,` entry is decoded or stripped (AS-4); every
  type-specific filter of FR-022 (autodiscover `service`/`user`, watchdog `service`, cron
  `task`/`priority`, rate limits `rateLimitName`/`sender`/`user`, legacy API `method`/`clientAddress`,
  syslog `priority`/`program`) matches case-insensitively as a substring with OR across values.
- [ ] T047 [P] [US3] Write `tests/Feature/Logs/LogPagingAndFiltersTest.php` (AS-1, AS-2, AS-3,
  AS-6, FR-024–FR-027): 10,000 Postfix entries → 25 newest, newest first, `total: 10000`;
  `order=asc`; equal times keep stored order; `q=ALICE@example.org` matches case-insensitively;
  `severity=error` returns `err`, `emerg`, `alert`, `crit` only; `priority` and `program` filters
  as substrings with OR across values; `from`/`to` inclusive; `before=<oldest id>` after 50 new
  pushes returns the next 100 without duplicates; a cursor whose entry was trimmed returns nothing
  older; a cursor whose entry was removed from the middle (`LREM`) falls back to strictly older
  times; malformed `before` → `422 invalidFormat` (`format: logCursor`); `limit=1001` →
  `422 outOfRange`; Redis unavailable → `503`.
- [ ] T048 [P] [US3] Write `tests/Feature/Logs/LegacyLogMaskingTest.php` (AS-5, FR-028, SC-006):
  an `API_LOG` entry with a DKIM private key and a password returns both as `*`; `q` equal to the
  masked password matches nothing and `total` is 0; `logs` rows whose `call` holds `client_secret`,
  `token`, `bind_password` and `private_key_file`, and a `msg` with parameters, are masked;
  a scan of every log response body of this test for the planted values finds none.
- [ ] T049 [P] [US3] Write `tests/Feature/Logs/LegacyUiActivityLogTest.php` (FR-022, FR-029, R13):
  rows inserted with `MailcowFactory` in legacy's format (`msg` JSON string and list, `call` list,
  dual-login user `admin => alice@example.org`); `messageKey` and `messageParameters`; `call` as a
  list; order by id; `before` = id keyset; `type`, `user`, `role` filters; `q` verified on masked
  entries with the SQL pre-filter (a row matching only in a masked value is not counted); exact
  page sizes for ranges (the legacy `LIMIT` bug is not reproduced); a row whose `msg` and `call` are
  not valid JSON is listed with `messageKey: null`, no parameters and `call: []`, and counts in the
  total.
- [ ] T050 [P] [US3] Write `tests/Feature/Logs/LoginLogTest.php` (FR-030, R14): `sasl_log` rows with
  local `datetime` in `Europe/Prague` are returned in UTC; with `RESTV2_SASL_LOG_SERVICE=WEBUI` a
  `MAILCOWUI` row is reported as `WEBUI` and `service=webui` matches it; upstream label keeps
  `MAILCOWUI`; `appPasswordUsed`; keyset cursor survives a `REPLACE` of an older row without
  duplicates; five rows sharing one `datetime`, paged two at a time with `before`, come back exactly
  once each in `service`, `real_rip`, `username` descending order; `from`/`to` converted from UTC.
- [ ] T051 [P] [US3] Write `tests/Feature/Logs/UnknownLogTypeTest.php` (AS-7, FR-003):
  `GET /logs/rspamd` and `/logs/unknown` → `404` problem (no route).
- [ ] T052 [US3] Add the twelve `GET /logs/*` operations to the provider of
  `MonitoringAuthorizationTest` (AS-8).

### Implementation for User Story 3

- [ ] T053 [P] [US3] Create `app/Services/Logs/SecretMasker.php` and `LegacyApiDataParser.php`
  (R15). This makes T044 pass.
- [ ] T054 [P] [US3] Create `app/Services/Logs/LogType.php` (enum of the twelve types with path,
  source, Redis key, severity support and type-specific filters), `LogQuery.php`, `LogPage.php`,
  `LogCursor.php`, `LogSeverity.php` and `Base64Message.php`. This makes T045 pass.
- [ ] T055 [P] [US3] Create the mappers in `app/Services/Logs/Mappers/`:
  `SyslogEntryMapper`, `NetfilterEntryMapper`, `AutodiscoverEntryMapper`, `WatchdogEntryMapper`,
  `AcmeEntryMapper`, `CronEntryMapper`, `RateLimitEntryMapper`, `LegacyApiRequestEntryMapper`
  (each `map(string $raw): ?array`, `null` for unparseable lines, masking applied where FR-028
  requires).
- [ ] T056 [US3] Create `app/Services/Logs/RedisListLogSource.php` (R12: full `LRANGE`, stable sort,
  cursor, filters, `q` on normalised entries, paging). Depends on T053–T055. This makes T046–T048
  (Redis parts) pass.
- [ ] T057 [P] [US3] Create `app/Models/Mailcow/LegacyUiLogEntry.php` (read only, throws on
  `saving` and `deleting`) and `app/Services/Logs/LegacyUiActivityLogSource.php` (R13, SQL paging
  without `q`, pre-filter plus `lazyById` verification with `q`).
- [ ] T058 [P] [US3] Create `app/Services/Logs/LoginLogSource.php` (R14) using 001's
  `SaslLogEntry` and `MailcowDateTime`.
- [ ] T059 [US3] Create `app/Http/Requests/Api/V2/ListLogEntriesRequest.php` (common rules plus the
  type-specific filters from `LogType`: arrays of at most 20 strings of 1–255 characters, `severity`
  only for types with a priority, `before` validated by `app/Rules/LogCursorFormat.php`), the
  resources `app/Http/Resources/Api/V2/LogEntryResource.php` (per-type shapes), and
  `app/Http/Controllers/Api/V2/LogEntryController.php` (`index`, dispatching by the route default
  `logType`); register the twelve `GET /logs/<type>` routes in `routes/api.php`. This makes
  T046–T052 pass.

**Checkpoint**: US3 green.

---

## Phase 6: User Story 4 — Manage the mail queue (P1)

**Goal**: Queue listing with counts, audited content views, selected-message actions with per-ID
results, flush and delete all.

**Independent test**: Spec US4 "Independent Test" with recorded `postqueue -j` output and the fake
acting on a simulated queue.

### Tests for User Story 4 (write first, observe failing) ⚠️

- [ ] T060 [P] [US4] Write `tests/Unit/MailQueue/PostqueueJsonParserTest.php` (R17, R33): the
  fixture parses into messages with recipients and deferral reasons and `forced_expire`; empty lines
  and the line `1` are skipped; the 12,000-line generated queue yields 10,000 messages,
  `truncated: true`, `queueCounts` over all 12,000 lines, and the IDs of all 12,000 lines for the
  membership check (an action on an ID from line 11,000 is `done`, not `notFound`); unparseable lines
  are skipped.
- [ ] T061 [P] [US4] Write `tests/Feature/MailQueue/ListMailQueueTest.php` (AS-1, AS-8, FR-037,
  FR-038, FR-043): `queue=deferred&recipient=partner.example` → exactly 3; `meta.queueCounts`
  (`active`, `deferred`, `hold`, `incoming`, `maildrop`, `total`) independent of filters; sort by
  `arrivalTime` (default asc), `size`, `sender`, `queue`, `id` with ties by id; `q` over id, sender,
  recipients, reasons; `from`/`to` on arrival time; `queue=bounce` → `422 notInList`; Postfix not
  running in the snapshot →
  `409 mail-service-not-running` with `container: postfix-mailcow` and no exec; snapshot running
  but exec answers `null` and a fresh read shows it stopped → `409`; `danger` → `502
  queue-action-failed`; the exec call is `exec('postfix-mailcow', ['cmd' => 'mailq', 'task' => 'list'])`.
- [ ] T062 [P] [US4] Write `tests/Feature/MailQueue/MailQueueContentTest.php` (AS-2, FR-039, FR-062):
  content returned as `{id, content}`; an audit row `mailQueueMessageView` with target
  `mailQueueMessage` and the queue ID and **no** content in any column; a missing message → `404` and
  an audit row with failure reason `notFound`; a read-only administrator key may view and is
  audited with its key ID; when the audit insert fails the content is not returned (`503`);
  `queueId` not hexadecimal → `404` (route constraint, unaudited); a domain administrator's and a
  restricted session's attempt → `403` with one `permissionDenied` row (`mailQueueMessageView`,
  target `mailQueueMessage`).
- [ ] T063 [P] [US4] Write `tests/Feature/MailQueue/MailQueueBulkActionsTest.php` (AS-3, AS-4, AS-7,
  FR-040, FR-042, R17), all through `POST /mail-queue/messages/bulk-actions` with
  `{"action": …, "targets": [{"id": …}]}`: four IDs of which one is no longer listed →
  `action: hold`: three `done`, one
  `notFound`, `summary` counts, the exec `exec('postfix-mailcow', ['cmd' => 'mailq', 'task' => 'hold', 'items' => [three ids]])`
  (the absent ID is not sent); release sends `unhold`; delete sends `delete`; deliver sends
  `deliver`; duplicates processed once, results in request order; 1,001 IDs → `422 tooMany`
  (`max: 1000`, key `targets`) and an ID `XYZ` → `422 invalidFormat` (`format: queueId`, key
  `targets.0.id`), an unknown `action` → `422 notInList`, all
  with no exec and one `validationFailed` audit row with `details.itemIds` (tests named
  `…Invalid…` with request validation off); the audit event follows the action
  (`mailQueueDelivery`, `mailQueueHold`, `mailQueueRelease`, `mailQueueDeletion`); a target with
  `version` →
  `422`; `danger` → `502`; timeout → `504 outcome-unknown` (`step: hold`); Postfix stopped → `409`.
- [ ] T064 [P] [US4] Write `tests/Feature/MailQueue/MailQueueFlushPurgeTest.php` (AS-5, AS-6,
  FR-041, FR-042): flush sends `flush`, purge sends `super_delete`, both answer
  `MailQueueActionResult` with `completedAt`; held messages are not part of flush (documented, fake
  queue keeps them); purge empties every queue of the fake including hold.
- [ ] T065 [P] [US4] Write `tests/Feature/MailQueue/MailQueueAuditTest.php` (AS-3, FR-062, FR-064):
  one audit row per bulk request listing every ID with its result, with the request ID as bulk
  request ID; flush and purge one row each; probe failure → `503` and no exec; audit insert failing
  until the deadline → `200` with `Audit-Recorded: false`.
- [ ] T066 [US4] Add the five mail-queue operations (bulk actions once per action value) to the provider of
  `MonitoringAuthorizationTest`.

### Implementation for User Story 4

- [ ] T067 [P] [US4] Create `app/Services/MailQueue/PostqueueJsonParser.php` and
  `MailQueueListing.php` (R17, R33). This makes T060 pass.
- [ ] T068 [US4] Create `app/Services/MailQueue/MailQueueService.php` (R17): `list(MailQueueQuery)`,
  `content(Principal, string $id)`, `apply(Principal, action, list $ids): MailQueueBulkOutcome`,
  `flush(Principal)`, `purge(Principal)`, with the Postfix running check via `ContainerRegistry`,
  the pre-list membership check, `IrreversibleOperationAudit` for actions and `AuditLogger` for
  views.
- [ ] T069 [US4] Create `app/Rules/MailQueueId.php`, `app/Http/Requests/Api/V2/ListMailQueueMessagesRequest.php`
  and `MailQueueBulkActionRequest.php` (`action` "one of deliver, hold, release, delete" →
  `notInList`; `targets` "between 1 and 1,000" → `required`/`tooMany`; `targets.*.id`
  "hexadecimal characters only" → `invalidFormat` `queueId`; `targets.*.version` prohibited), the resources `MailQueueMessageResource`, `MailQueueMessageContentResource` and a
  `BulkResultResource` (reuse 002's if present) in `app/Http/Resources/Api/V2/`.
- [ ] T070 [US4] Create the controllers `MailQueueMessageController` (`index`),
  `MailQueueMessageContentController` (`show`, route with `restv2.audit` `mailQueueMessageView`,
  target `mailQueueMessage`), `MailQueueBulkActionController`,
  `MailQueueFlushController`, `MailQueuePurgeController` (`store`) in
  `app/Http/Controllers/Api/V2/`, with `set_time_limit(DOCKER_TIMEOUT + 20)` on the actions, and
  register the routes in `routes/api.php` (`POST /mail-queue/messages/bulk-actions` with
  `restv2.audit` `['event' => 'fromBulkAction', 'target' => 'mailQueue']`, mapping `deliver`,
  `hold`, `release`, `delete` to the four event types; flush and purge with `mailQueueFlush` and
  `mailQueuePurge`; the literal `bulk-actions` before `{queueId}`; `queueId` constrained to
  `[0-9A-Fa-f]{1,32}`). This makes
  T061–T066 pass.

**Checkpoint**: US4 green; all P1 stories done.

---

## Phase 7: User Story 5 — Configure the ban service and act on bans (P2)

**Goal**: Ban settings with value-based version tags and atomic writes, validated lists, Python-
validated rules, rule reset, active and permanent bans with lift, allowlist and denylist, ban list
ID rotation.

**Independent test**: Spec US5 "Independent Test" on the real Redis with legacy- and
netfilter-formatted keys and the fake for the netfilter restart.

### Tests for User Story 5 (write first, observe failing) ⚠️

- [ ] T071 [P] [US5] Write `tests/Unit/Fail2Ban/Fail2BanVersionTagTest.php` (FR-057, R18): the tag
  is equal for PHP-encoded and Python-encoded `F2B_OPTIONS` with the same values, for `"1800"` and
  `1800`, for `true` and `1`, and for lists in different order; it changes when any value or list
  entry or the ban list ID changes; the settings tag is an HMAC keyed with the app key and does
  not equal the plain SHA-256 of the canonical JSON; rules tag changes with order or content only.
- [ ] T072 [P] [US5] Write `tests/Unit/Fail2Ban/BanListEntryRulesTest.php` (FR-047, R19): allowlist
  accepts `192.0.2.1`, `2001:db8::/32`, `10.0.0.5/24`, `mail.example.org`, refuses `not a host!`,
  `10.0.0.0/33`, `10.0.0.0/-1`, `10.0.0.0/x`; denylist refuses host names and `0.0.0.0`,
  `0.0.0.0/0`, `0.0.0.0/00`, `::/0`, `172.22.1.0/24`, `172.22.1.0`, `fd4d:6169:6c63:6f77::/64` with
  `reserved`; entries are trimmed.
- [ ] T073 [P] [US5] Write `tests/Unit/Fail2Ban/PythonRegexValidatorTest.php` (FR-050, R20, real
  python3): netfilter's nine default rules pass; `(?P<ip>[0-9.]+)` passes; `(?<ip>x)` and `[a-`
  fail with Python's message; `foo bar` reports zero groups; a missing interpreter path throws.
- [ ] T074 [P] [US5] Write `tests/Feature/Fail2Ban/Fail2BanSettingsReadTest.php` (FR-044,
  FR-056): nothing stored → defaults 1,800 / 10,000 / `true` / 10 / 600 / 32 / 128 / `false`,
  `banListId: null`, `stored: false`; with only the old `F2B_BAN_TIME` key → 3,600 from it; stored
  netfilter JSON maps to the contract; allowlist and denylist sorted naturally; `banListUrl` built
  from `MAILCOW_HOSTNAME`; `banServiceRunning` true, false, or `null` with dockerapi unavailable
  (the read still succeeds); `ETag` header equals `data.etag`.
- [ ] T075 [P] [US5] Write `tests/Feature/Fail2Ban/Fail2BanSettingsUpdateTest.php` (AS-1, AS-10,
  FR-045, FR-061): `{"banTime":3600}` changes only `ban_time` and keeps both lists and every other
  value; `F2B_OPTIONS` is written with legacy's key order and types; a sent list replaces the whole
  hash; `If-Match` with a stale tag → `412` and nothing written; a tag read before netfilter
  re-stored the same values (rewrite `F2B_OPTIONS` with Python spacing between read and write) is
  accepted; a concurrent change of a watched key between `WATCH` and `EXEC` (simulated with a
  second connection in a test hook) is retried and the final state contains both changes; nothing
  stored → the first save writes a complete set with a new UUID v4 `banlist_id`; Redis unavailable
  → `503` and nothing written.
- [ ] T076 [P] [US5] Write `tests/Feature/Fail2Ban/Fail2BanSettingsValidationInvalidTest.php` (AS-2,
  AS-3, FR-046, FR-047, SC-010): `banTime: 30` → `outOfRange` `{min: 60, max: 2147483647}`;
  `ipv4PrefixLength: 4` → `outOfRange` `{min: 8, max: 32}`; `maxBanTime` below the ban time →
  `outOfRange` with `minField: banTime`; only `banTime` above the stored `maxBanTime` →
  `mustNotExceed` on `banTime`; stored `banTime: 30` (legacy) plus a patch that changes only
  `maxAttempts` → accepted; the same patch sending `banTime: 30` unchanged → accepted;
  `denylist: ["0.0.0.0/0"]` → `reserved` on `denylist.0`; `allowlist: ["not a host!"]` →
  `invalidFormat` (`ipNetworkOrHostname`); an entry on both lists → `conflictsWith`; 10,001 entries
  → `tooMany`; `null` → `invalidType`; `{}` → `required` on `body`; every invalid entry is named,
  both lists stay unchanged in Redis, and each refused request writes one `validationFailed` audit
  row (rule codes only, never the values).
- [ ] T077 [P] [US5] Write `tests/Feature/Fail2Ban/Fail2BanRulesTest.php` (AS-7, FR-049, FR-050):
  `GET` returns rules numbered from 1 in key order and `stored`; `PUT` with two rules stores
  `{"1":…,"2":…}` with unescaped slashes; a rule without a group → `422 missingCaptureGroup` on
  `rules.1`; a rule that does not compile → `invalidRegex` with `error`; `[]` → `required`; 101
  rules → `tooMany`; a 1,001-character rule → `tooLong`; stale `If-Match` → `412`; the stored rules
  are unchanged after every refusal.
- [ ] T078 [P] [US5] Write `tests/Feature/Fail2Ban/Fail2BanRulesResetTest.php` (AS-8, FR-051,
  FR-064, R21): `F2B_REGEX` is deleted, `restart('netfilter-mailcow')` is called, and
  when the fake writes the defaults during the restart the response is `200` with them; when nothing
  appears within ten faked 1 s polls → `504 ban-rules-not-restored` and an audit row with
  `rulesNotRestored`; dockerapi unavailable → `503` and `F2B_REGEX` kept; restart timeout → `504
  outcome-unknown` (`step: rulesReset`), and dockerapi unreachable on the restart after the `DEL` →
  `504 outcome-unknown` too (never `503`); audit probe failure → `503`, nothing deleted.
- [ ] T079 [P] [US5] Write `tests/Feature/Fail2Ban/Fail2BanBanListsTest.php` (FR-052): active bans
  with `endsAt`, `remainingSeconds` and `queuedForLifting`; permanent bans with `startedAt`; sort by
  `endsAt` (default asc) and `network` (address then prefix, IPv4 before IPv6); `startedAt` default
  desc; `q` on the network; `queuedForLifting` filter; paging; `sort=address` → `422`.
- [ ] T080 [P] [US5] Write `tests/Feature/Fail2Ban/Fail2BanBanActionsTest.php` (AS-4, AS-5, AS-6,
  FR-053, FR-054): lift `203.0.113.0%2F24` → `F2B_QUEUE_UNBAN` has it and the response shows
  `queuedForLifting: true`; allowlist → the network is in `F2B_WHITELIST`, removed from
  `F2B_BLACKLIST`, queued for lifting, in one transaction; denylist → in `F2B_BLACKLIST`, removed from
  `F2B_WHITELIST`; an IPv6 network `2001%3Adb8%3A%3A%2F64` works; a network that is not actively
  banned → `404`; `banServiceRunning: false` when netfilter is stopped and the lift stays queued;
  allowlisting or denylisting when that list already holds 10,000 other entries → `422 tooMany`
  on `network`.
- [ ] T081 [P] [US5] Write `tests/Feature/Fail2Ban/Fail2BanBanListRotationTest.php` (AS-9, FR-055):
  a new UUID v4 replaces `banlist_id` in `F2B_OPTIONS`, all other values unchanged; the response
  carries the new `banListUrl` and a new `ETag`; stale `If-Match` → `412`.
- [ ] T082 [P] [US5] Write `tests/Feature/Fail2Ban/Fail2BanAuditTest.php` (FR-062, US8 AS-2, AS-3):
  settings change → one row with `changes.banTime {old, new}` and `allowlistAdded`/`Removed`;
  rules replacement and reset → `oldRules`/`newRules`; ban actions → network and list changes;
  rotation → no ID anywhere in the row; the first save that generates a `banlist_id` records no ID
  in `changes`; a refused `412` → a failure row with `versionOutdated`; Redis unavailable → a
  failure row with `temporaryFailure`.
- [ ] T083 [US5] Add the eleven fail2ban operations to the provider of
  `MonitoringAuthorizationTest`.

### Implementation for User Story 5

- [ ] T084 [P] [US5] Create `app/Support/Network/IpNetwork.php` (port of legacy `valid_network()`,
  canonical form, `inet_pton` sort key) and `NetworkSort.php`, and the rules
  `app/Rules/Fail2BanAllowlistEntry.php` and `Fail2BanDenylistEntry.php` (codes of R19). This makes
  T072 pass.
- [ ] T085 [P] [US5] Create `resources/bin/validate-python-regex.py` (reads a JSON list of strings
  on stdin, prints `[{ok, groups, error}]`) and `app/Support/Regex/PythonRegexValidator.php`
  (Symfony `Process`, `python3 -I -S -B`, 5 s timeout). This makes T073 pass.
- [ ] T086 [US5] Create `app/Clients/Fail2Ban/Fail2BanStore.php` (R18: reads with fallbacks and
  normalisation, `transaction(callable)` with `WATCH`/`MULTI`/`EXEC` and 5 retries, list hashes,
  `F2B_REGEX`, ban hashes) and `app/Services/Fail2Ban/Fail2BanSettingsValues.php` and
  `Fail2BanVersionTag.php` (`banServiceRunning` comes from `ContainerRegistry`). This makes T071
  pass.
- [ ] T087 [US5] Create `app/Services/Fail2Ban/Fail2BanSettingsService.php` (read, update with the
  stored-value exemption and cross-field rules, rotation; audit in a DB transaction around `EXEC`,
  R24) and `app/Http/Requests/Api/V2/UpdateFail2BanSettingsRequest.php` (merge patch types, list
  limits) with `app/Http/Resources/Api/V2/Fail2BanSettingsResource.php`.
- [ ] T088 [US5] Create `app/Services/Fail2Ban/Fail2BanRulesService.php` (read, replace, reset per
  R20 and R21 with `IrreversibleOperationAudit` for the reset) and
  `app/Http/Requests/Api/V2/ReplaceFail2BanRulesRequest.php` with `Fail2BanRulesResource.php`.
- [ ] T089 [US5] Create `app/Services/Fail2Ban/Fail2BanBanService.php` (lists, lift, allowlist,
  denylist per R22) and `app/Http/Requests/Api/V2/ListFail2BanBansRequest.php` with
  `Fail2BanActiveBanResource.php`, `Fail2BanPermanentBanResource.php` and
  `Fail2BanBanActionResultResource.php`.
- [ ] T090 [US5] Create the controllers `Fail2BanSettingsController` (`show`, `update`),
  `Fail2BanBanListRotationController`, `Fail2BanRulesController` (`show`, `update`),
  `Fail2BanRulesResetController` (calling `set_time_limit(DOCKER_TIMEOUT + 20)`),
  `Fail2BanActiveBanController` (`index`),
  `Fail2BanBanLiftController`, `Fail2BanBanAllowlistController`, `Fail2BanBanDenylistController`
  (`store`) and `Fail2BanPermanentBanController` (`index`) in `app/Http/Controllers/Api/V2/`;
  register the routes in `routes/api.php`, the state-changing ones with `restv2.audit` and their
  target type, and `->where('network', '.+')` for the three action
  routes (R30), registered before `/fail2ban/active-bans`. Extend 001's
  `tests/Support/Contract/OpenApiContract.php` to match templated segments on the raw (encoded)
  path if it does not already. This makes T074–T083 pass.

**Checkpoint**: US5 green.

---

## Phase 8: User Story 6 — Review Rspamd's scan history and statistics (P2)

**Goal**: History with the dashboard's symbol order, filters and sorting; statistics subset.

**Independent test**: Spec US6 "Independent Test" with recorded controller answers.

### Tests for User Story 6 (write first, observe failing) ⚠️

- [ ] T091 [P] [US6] Write `tests/Feature/Rspamd/RspamdHistoryTest.php` (AS-1, AS-2, FR-033, FR-034,
  R16): 1,000 rows → 25 newest with `total: 1000`; `q=spammer@example.net` returns the `reject` row
  with `score`, `requiredScore` and symbols ordered positive descending, negative ascending, zero
  last; `action` filter (case-insensitive, multiple values); `minScore`/`maxScore` and `from`/`to`
  inclusive; sort by `score`, `size`, `scanTime`; `user: null` for none; `minScore=abc` → `422`;
  the fake records one `history()` call per request (no caching).
- [ ] T092 [P] [US6] Write `tests/Feature/Rspamd/RspamdStatisticsTest.php` (AS-3, FR-035): the
  documented fields only (an extra `pools_allocated` in the fixture is absent), `actions` as
  Rspamd names them, `statfiles` and `fuzzyHashes`, `collectedAt`; missing numbers → 0.
- [ ] T093 [P] [US6] Write `tests/Feature/Rspamd/RspamdUnreachableTest.php` (AS-4, FR-036): an
  unreachable controller → `503 temporary-failure` for both operations.
- [ ] T094 [US6] Add `GET /rspamd/history` and `/rspamd/statistics` to the provider of
  `MonitoringAuthorizationTest`.

### Implementation for User Story 6

- [ ] T095 [US6] Add `history(): array` to `app/Clients/Rspamd/RspamdController.php` and 006's
  `UnixSocketRspamdController.php`, mapping connection failures, timeouts (5 s connect, 10 s total)
  and non-200 to 006's unavailable result, which renders `503 temporary-failure`. Write its
  `Http::fake()` unit test in `tests/Unit/Clients/UnixSocketRspamdControllerHistoryTest.php` first
  (asserting `CURLOPT_UNIX_SOCKET_PATH` and the `/history` path).
- [ ] T096 [US6] Create `app/Services/Rspamd/RspamdHistoryService.php`,
  `RspamdStatisticsService.php`, `app/Http/Requests/Api/V2/ListRspamdHistoryRequest.php`,
  `app/Http/Resources/Api/V2/{RspamdHistoryEntryResource,RspamdStatisticsResource}.php` and the
  controllers `RspamdHistoryController` (`index`) and `RspamdStatisticsController` (`show`); register
  the routes. This makes T091–T094 pass.

**Checkpoint**: US6 green.

---

## Phase 9: User Story 7 — Check for updates and look up the public addresses (P3)

**Goal**: Installed version, update status with caching and the fork setting, public addresses
gated by `IP_CHECK`.

**Independent test**: Spec US7 "Independent Test" with the release source and IP check fakes.

### Tests for User Story 7 (write first, observe failing) ⚠️

- [ ] T097 [P] [US7] Write `tests/Feature/Monitoring/InstallationVersionTest.php` (FR-018):
  `GET /installation` returns tag, branch, commit and commit date from `MailcowConfig`; `null`
  values when unknown; no dockerapi call.
- [ ] T098 [P] [US7] Write `tests/Feature/Monitoring/UpdateStatusTest.php` (AS-1, AS-2, AS-3,
  FR-019, FR-020, R10): a newer release (later `created_at`) → `updateAvailable` with tag, `url`,
  `publishedAt`, `notes` and `installedReleaseNotes`; same or older → `upToDate`; source failure →
  `checkFailed`, and a second request within 5 minutes makes no source call while one after 5
  minutes does; success cached 1 hour; `RESTV2_UPDATE_CHECK=n` → `disabled` and zero source calls;
  branch `nightly` → `disabled`; no installed tag → `disabled`; the source URL defaults to
  `https://api.github.com/repos/mailcow/mailcow-dockerized`; a changed version uses a new cache key;
  Redis unavailable → `503` and no source call.
- [ ] T099 [P] [US7] Write `tests/Unit/Clients/GitHubReleaseSourceTest.php` with `Http::fake()`:
  `User-Agent: mailcow-update-check`, `Accept: application/vnd.github+json`, 10 s timeout, non-200
  → failure.
- [ ] T100 [P] [US7] Write `tests/Feature/Monitoring/PublicAddressesTest.php` (AS-4, FR-021, R11):
  `IP_CHECK` unset or `0` → `409 ip-check-disabled` and zero lookups; `1` → both addresses; an
  invalid IPv6 answer → `ipv6: null`; one family failing → that address `null`; and
  `tests/Unit/Clients/HttpPublicAddressLookupTest.php` with `Http::fake()` asserting both requests,
  10 s connect and 15 s total timeouts.
- [ ] T101 [US7] Add `GET /installation`, `/installation/update-status` and
  `/host/public-addresses` to the provider of `MonitoringAuthorizationTest`.

### Implementation for User Story 7

- [ ] T102 [P] [US7] Create `app/Clients/Releases/ReleaseSource.php` and `GitHubReleaseSource.php`,
  and `app/Services/Monitoring/UpdateCheckService.php` (R10, cache with single-flight lock), with
  `InstallationVersionResource.php` and `InstallationUpdateStatusResource.php`.
- [ ] T103 [P] [US7] Create `app/Clients/PublicAddress/PublicAddressLookup.php` and
  `HttpPublicAddressLookup.php` (`Http::pool()`), and `app/Services/Monitoring/PublicAddressService.php`
  with `PublicAddressesResource.php`.
- [ ] T104 [US7] Create the controllers `InstallationController`, `UpdateStatusController` and
  `PublicAddressesController` (`show`) and register the three routes. This makes T097–T101 pass.

**Checkpoint**: US7 green.

---

## Phase 10: User Story 8 — Trace operations (P3)

**Goal**: Every operation of this feature is traceable in the audit log by target type and target.

**Independent test**: Spec US8 "Independent Test": perform each state-changing operation, including
refused ones, and each content view, then filter the audit log.

### Tests for User Story 8 (write first, observe failing) ⚠️

- [ ] T105 [US8] Write `tests/Feature/Audit/MonitoringAuditTrailTest.php` (US8 AS-1 to AS-3, FR-062,
  FR-063, SC-006): run every state-changing operation of the feature once with success and once
  refused (protected container, `412`, absent ban, Postfix stopped, `422`, a read-only key and a
  domain administrator), plus one content view and one
  ordinary read of each kind; assert exactly one record per state-changing request and per content
  view, none for other reads; `GET /audit-events?targetType=container&targetId=clamd-mailcow`
  (002's filters) shows actor, time, client address, action, outcome and states; `targetType` values
  `mailQueue`, `mailQueueMessage`, `fail2banSettings`, `fail2banRules`, `fail2banActiveBan` and
  `fail2banBanListId` each filter correctly; no record contains message content, a ban list ID, a
  container environment value or a masked secret.

### Implementation for User Story 8

- [ ] T106 [US8] Add the `details` whitelist entries of research R24 for the seventeen new event
  types to `app/Services/Audit/AuditLogger.php` (001), and fix any service that writes a key outside
  it. This makes T105 pass.

**Checkpoint**: US8 green; all stories done.

---

## Phase 11: Polish and cross-cutting concerns

- [ ] T107 Run `tests/Feature/Security/MonitoringAuthorizationTest.php` with all 42 operations in
  its provider and verify the provider matches the contract's operations of the six modules
  (a test that reads `build/contract/openapi.json` and fails on a missing operation) (SC-005).
- [ ] T108 [P] Write `tests/Feature/Performance/MonitoringLatencyTest.php` (group `performance`,
  SC-003, R31): with recorded fixtures and the fakes, p95 of 20 iterations under 1 s for `GET /host`,
  `GET /containers` and `GET /logs/postfix?limit=100` over 10,000 entries.
- [ ] T109 [P] Write `tests/Feature/Containers/ContainerEnvironmentLeakTest.php` (constitution VI):
  after exercising every container operation, scan the `restv2:monitoring:*` Redis values, every
  response body and the captured log output for the planted environment values of
  `containers.json`; none may appear.
- [ ] T110 [P] Write `tests/Unit/Architecture/MonitoringWritesTest.php`: no class under
  `app/Http/Controllers/Api/V2/` of this feature calls `Redis::`, `DB::` or a dockerapi method
  directly (services only, constitution IV), and `LegacyUiLogEntry` is never written.
- [ ] T111 [P] Document the new settings of R27 in `README.md` (configuration table, FeldHost:
  `RESTV2_UPDATE_CHECK=n`; raising `DOCKER_TIMEOUT` needs `fastcgi_read_timeout` ≥ `DOCKER_TIMEOUT + 30`
  and `request_terminate_timeout` ≥ `DOCKER_TIMEOUT + 40`) and add the feature's contract changes to `CHANGELOG.md` under the next
  release (42 operations, shared enum values, problem types).
- [ ] T112 Run quickstart Part A and Part B (`specs/011-monitoring-operations/quickstart.md`),
  `composer test` with contract coverage of all 42 operations, `vendor/bin/pint --test` and
  `vendor/bin/phpstan analyse`; fix every finding.

---

## Dependencies and execution order

### Phase dependencies

- **Setup (Phase 1)**: T001 first (it can stop the feature if a prerequisite is missing). T002–T006
  in parallel afterwards.
- **Foundational (Phase 2)**: depends on Setup. Tests T007–T015 first (T009–T013 need T007/T008 for
  fakes and helpers), then T016 → T018 (T017 in parallel with T016), T019–T021 in parallel, T022
  last. **Blocks all user stories.**
- **User stories (Phases 3–10)** depend on Foundational only, with these exceptions:
  - US2's snapshot-invalidation assertion (T039) uses US1's `GET /containers`; implement US1 first
    or run that case after US1.
  - US5's `banServiceRunning` uses `ContainerRegistry` (Foundational) only.
  - US8 (T105) exercises every other story's operations, so it runs last.
- **Polish (Phase 11)** depends on every story.

### Within each story

Tests first and failing, then pure helpers (parsers, calculators, rules), then clients and
services, then requests, resources and controllers, then routes. Stop at each checkpoint and run
the story's tests and `MonitoringAuthorizationTest`.

### Parallel opportunities

- Setup: T002–T006.
- Foundational tests: T007–T015 (T009–T013 after T007/T008).
- Foundational implementation: T017, T019, T020, T021 in parallel with T016.
- After Phase 2, with several developers: US1, US3, US4, US5, US6 and US7 in parallel; US2 after
  US1; US8 last.

## Parallel example: User Story 3

```bash
# All US3 test tasks together (different files):
Task: "T044 SecretMaskerTest + LegacyApiDataParserTest"
Task: "T045 LogCursorTest + Base64MessageTest"
Task: "T046 RedisLogTypesTest", "T047 LogPagingAndFiltersTest", "T048 LegacyLogMaskingTest"
Task: "T049 LegacyUiActivityLogTest", "T050 LoginLogTest", "T051 UnknownLogTypeTest"

# Then the independent building blocks together:
Task: "T053 SecretMasker + LegacyApiDataParser", "T054 LogType, LogQuery, LogCursor …",
      "T055 mappers", "T057 LegacyUiActivityLogSource", "T058 LoginLogSource"
```

## Implementation strategy

### MVP first (User Story 1)

1. Phase 1, then Phase 2.
2. Phase 3 (US1): the dashboard's overview, storage, containers, usage and capabilities.
3. **Stop and validate**: quickstart Part B's US1 commands with `RESTV2_FAKE_OUTBOUND=y`.

### Incremental delivery

1. US2 (container control), US3 (logs) and US4 (mail queue) complete the P1 scope.
2. US5 (ban service) and US6 (Rspamd), then US7 (updates, addresses) and US8 (audit trail).
3. Polish: authorisation matrix over all 42 operations, performance, leak scans, docs.

Each story ends with its checkpoint green and the contract coverage growing towards 42 of 42 for
this feature.

## Notes

- Never use `RefreshDatabase`, `migrate:fresh`, `migrate:refresh` or `db:wipe` (001 T040).
- This feature writes no upstream table. Redis writes to `F2B_*` go only through `Fail2BanStore`
  and the `Fail2Ban*Service` classes; dockerapi calls only through the services of
  `app/Services/Monitoring/` and `app/Services/MailQueue/`.
- Container environments are dropped in `HttpDockerApiClient` and must never reach a cache, a log,
  a response or an audit record (T009, T109).
- Commit after each task or logical group, with short imperative subjects and no AI attribution.
