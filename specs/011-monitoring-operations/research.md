# Research: Monitoring and Operations

**Feature**: `specs/011-monitoring-operations` | **Date**: 2026-10-18 | **Plan**: [plan.md](./plan.md)

This document settles the open points of the plan that feature 001 did not already decide. Every
shared technical decision (Laravel 13 on PHP 8.4, the Docker dev stack, the schema fixture, the
single `mysql` connection, the `restv2_` table prefix and `restv2:` Redis prefix, problem URIs,
contract validation in tests, the request pipeline, `MailcowConfig`, `AuditLogger`, `ETag`) is
reused from [feature 001's research](../001-auth-sessions-api-keys/research.md) and is not
repeated. Decisions follow the owner's precedence: legacy behaviour (constitution IV), then the
constitution and `docs/conventions.md`, then best practice. Each entry has a **Decision**, its
**Rationale** and the **Alternatives considered**.

Legacy references are paths in mailcow-dockerized `2026-09a`
(`git -C ~/Projects/mailhosting show 2026-09a:<path>`). For this feature the FeldHost fork differs
only in the three points the spec lists (update check, `WEBUI` label, branding); the container
service (`data/Dockerfiles/dockerapi/`), the ban service (`data/Dockerfiles/netfilter/`) and
`functions.{docker,mailq,fail2ban}.inc.php`, `get_logs()` and `json_api.php` were diffed and are
identical.

---

## 1. Dependencies on earlier features

### R1. Code this feature reuses

- **Decision**: Features are implemented in number order, so 011 builds on code that exists when
  its implementation starts:
  - **001**: the `api.v2` pipeline (`AuthenticateApiKey`, `EnforceKeyScope`, `RequireAuth`,
    `VerifyCsrfToken`, `EnforcePendingActions`), `Principal`, `ProblemException` and
    `App\Support\Http\ProblemType`, `ETag`, `AuditLogger` and `restv2_audit_events`,
    `MailcowConfig` and `resources/bin/export-mailcow-vars.php`, the Redis connections `mailcow`
    (database 0, no prefix) and `restv2` (prefix `restv2:`), the cache store `restv2`, the
    `MailcowDateTime` cast, `SaslLogEntry`, `config('restv2.sasl_log_service')`, the `Sleeper`
    clock, the test toolkit (`OpenApiContract`, `TestCase::signInAs()`, `MailcowFactory`,
    contract coverage) and `deploy/`.
  - **002**: the dockerapi client (`App\Clients\DockerApi\`, first used for the SOGo restart and
    the maildir move), the problem types `temporary-failure` (503) and the audit target filters
    `targetType` and `targetId` on `GET /audit-events` (002 FR-058), which US8 uses.
  - **003**: the problem type `outcome-unknown` (504, extension `step`) and its audit failure
    reason `outcomeUnknown`.
  - **006**: the Rspamd controller client (`App\Clients\Rspamd\`, first used for learning and
    `/actions`) and the Rspamd socket mount in the deployment override.

  Where an earlier feature already created a class this plan names, the 011 tasks **extend** it
  with the methods below instead of creating a second one; T003 reconciles the names before any
  011 test is written. If an earlier feature chose different class names, the 011 tasks use those
  names, keeping the method contracts of this document.
- **Rationale**: Constitution VIII (one client per external system, abstractions after the second
  use) and the number-order implementation rule. The interfaces below are the minimum 011 needs,
  so a different earlier shape costs only renames.
- **Alternatives considered**: A separate 011-only dockerapi client was rejected: two clients for
  one service would drift in TLS, timeout and error handling.

---

## 2. Container service (dockerapi)

### R2. dockerapi client operations

- **Decision**: `App\Clients\DockerApi\DockerApiClient` (interface) and `HttpDockerApiClient`
  (Laravel HTTP client) offer, for this feature:

  | Method | dockerapi call | Used by |
  |---|---|---|
  | `listContainers(): list<ContainerSnapshot>` | `GET /containers/json?all=true` | container list, inspect, state checks |
  | `containerAction(string $id, 'start'\|'stop'\|'restart'): void` | `POST /containers/{id}/{action}` | container control, rule reset |
  | `containerStats(string $id, float $timeout): list<array>` | `POST /container/{id}/stats/update` | resource usage |
  | `hostStats(): array` | `GET /host/stats` | system overview |
  | `exec(string $id, string $cmd, string $task, array $payload = [], float $timeout): string\|array\|null` | `POST /containers/{id}/exec` with `{cmd, task, …}` | `system df`, `mailq list/cat/hold/unhold/deliver/delete/flush/super_delete` |

  - **Endpoint and TLS**: `https://dockerapi:443` (`RESTV2_DOCKERAPI_URL`). dockerapi serves
    mailcow's mail certificate, whose names do not match, so certificate verification is off,
    exactly as legacy `docker()` (`CURLOPT_SSL_VERIFYHOST/PEER = 0`). dockerapi is reachable only
    on `mailcow-network` and has no authentication, as in legacy.
  - **Timeouts**: every call is bounded by `DOCKER_TIMEOUT` (R27, 60 s by default) except the two
    polled reads: `hostStats()` and `containerStats()` use 5 s, because the spec requires the
    first resource sample within 5 seconds and dockerapi's stats handler loops forever for a
    container that stopped (R7).
  - **Error mapping**: connection refused, DNS failure or TLS error → `DockerApiUnavailable`
    (→ `503 temporary-failure`); timeout → `DockerApiTimeout` (→ `504 outcome-unknown` for
    actions, `503 temporary-failure` for reads); a JSON body `{"type": "danger", "msg": …}` (which
    dockerapi returns with HTTP 200 for every handler exception) → `DockerApiFailure($msg)`
    (→ `502 container-action-failed` or `queue-action-failed`).
  - **Project filtering**: `listContainers()` keeps only containers whose
    `Config.Labels["com.docker.compose.project"]` equals `COMPOSE_PROJECT_NAME`
    case-insensitively, keyed by `Config.Labels["com.docker.compose.service"]`, as legacy
    `docker('info')`. `ContainerSnapshot` holds only `id`, `service`, `image` (`Config.Image`),
    `status` (`State.Status`), `running` (`State.Running`), `startedAt` (`State.StartedAt`,
    `0001-01-01T00:00:00Z` → `null`) and `health` (`State.Health.Status`, absent → `null`).
    **`Config.Env` and every other field are dropped at parse time**: the environment holds
    `DBPASS`, `REDISPASS` and other secrets, and nothing else is ever cached or logged.
- **Rationale**: These are the only dockerapi endpoints legacy uses for the allocated surface.
  Wrapping exec per `{cmd, task}` keeps the command whitelist in dockerapi, so the API can never
  run an arbitrary command.
- **Alternatives considered**: The Docker socket in the API container was rejected
  (constitution V and a root-equivalent privilege). Verifying dockerapi's certificate was
  rejected: it would need the mail certificate and a matching name that dockerapi does not have.

### R3. Container identity, protection and roles

- **Decision**:
  - A container is addressed by its **service name** (`postfix-mailcow`), never by Docker ID.
    Unknown names, containers of other projects and hidden optional containers answer `404`.
  - **Hidden**: `clamd-mailcow` when `SKIP_CLAMD` matches `^([yY][eE][sS]|[yY])+$`, and
    `olefy-mailcow` when `SKIP_OLEFY` matches it, as `admin/dashboard.php`. Both are read from the
    environment (mailcow.conf names).
  - **This API's own container**: the service named by `RESTV2_SELF_SERVICE` (default
    `restv2-mailcow`, the service of 001's override), and in addition any container whose ID
    starts with this process's host name (Docker sets the host name to the short container ID
    unless `hostname:` is set). Both checks guard against a renamed service.
  - **Protected**: `RESTV2_PROTECTED_CONTAINERS`, a comma-separated list with the default
    `dockerapi-mailcow,mysql-mailcow,redis-mailcow,nginx-mailcow,restv2-mailcow`, plus the own
    container whatever the list says. Stop is refused for protected containers; stop and restart
    are refused for the own container (`409 container-protected`).
  - **Roles** (orchestrator item 7): `App\Services\Monitoring\ContainerRoleMap` maps service names
    to `ContainerRole`: `unbound` → `dnsResolver`, `mysql` → `database`, `redis` →
    `keyValueStore`, `clamd` → `antivirus`, `rspamd` → `spamFilter`, `php-fpm` → `legacyWebApp`,
    `sogo` → `webmail`, `dovecot` → `imap`, `postfix` → `mailTransfer`, `postfix-tlspol` →
    `tlsPolicy`, `memcached` → `cache`, `nginx` → `proxy`, `acme` → `certificates`, `netfilter` →
    `banService`, `watchdog` → `healthMonitor`, `dockerapi` → `containerService`, `olefy` →
    `macroScanner`, `ofelia` → `scheduler`, `ng` → `webApp` (each `<name>-mailcow`), the own
    container → `api`, anything else → `other`. `RESTV2_CONTAINER_ROLES` (JSON map) adds or
    overrides entries for renamed services.
  - **Available actions**: `start` when not running; `stop` when running and not protected;
    `restart` unless it is the own container.
- **Rationale**: Legacy accepts any `*-mailcow` service, but a stopped dockerapi, database, Redis
  or nginx cannot be started again through the API, and a restart of the API's own container could
  never be answered (spec clarification 2). The role map is a lookup table, not inference, so it is
  stable across releases; an unknown service degrades to `other`.
- **Alternatives considered**: Reading a role from a container label was rejected: it would need
  changes to upstream's compose file (constitution V).

### R4. Container actions

- **Decision**: `App\Services\Monitoring\ContainerControlService`:
  1. Resolve the container from a **fresh** `listContainers()` (not the cache) and record the
     state before. Apply R3 (protected, own container) → `409` with an audit record.
  2. Probe the audit log (R24). If it cannot be written → `503 temporary-failure`, nothing done.
  3. **start**: if running → `alreadyInState`. Otherwise up to 4 attempts, 1.5 s apart (the
     `Sleeper` of 001, faked in tests): `containerAction(id, 'start')`, then a fresh state read;
     running → `done`. After 4 attempts not running → `502 container-action-failed` with the last
     dockerapi message (or "container did not start").
     **stop**: the mirror image, deciding by `State.Running`, as legacy `container_ctrl.php`.
     **restart**: one `containerAction(id, 'restart')` whatever the state, then a fresh read.
  4. The whole action is bounded by `DOCKER_TIMEOUT` measured from the start of the request; a
     dockerapi timeout → `504 outcome-unknown` with `step` = the action.
  5. Write the audit record (R24) with the state before and after, then invalidate the container
     cache key (R5) and return `ContainerActionResult` built from the fresh read.
  - **Legacy defect not reproduced**: legacy `docker('get_id')` lists only running containers
    (`/containers/json` without `all`), so legacy `container_ctrl.php?action=start` cannot find a
    stopped container. The API resolves IDs from `?all=true`, so start works on stopped containers
    as the spec requires; dockerapi's start handler already lists with `all=True`.
  - dockerapi's POST handlers report success even when no container matched the ID, so the
    outcome is always decided by the fresh read, never by dockerapi's message alone.
- **Rationale**: Spec FR-014 to FR-016 with legacy's retry loop. Deciding by a fresh read avoids
  reporting `done` for a no-op.
- **Alternatives considered**: Docker events or polling until healthy were rejected: legacy does
  not wait for health, and a restart answer must come within `DOCKER_TIMEOUT`.

### R5. Shared collection cache with maximum ages

- **Decision**: `App\Services\Monitoring\SharedSnapshotCache::get(string $key, int $maxAge,
  float $lockWait, callable $loader): Snapshot` stores `{payload, collectedAt}` in the `restv2`
  cache store (Redis, `restv2:` prefix):
  1. If a stored snapshot exists and `now − collectedAt ≤ maxAge`, return it.
  2. Otherwise take the lock `restv2:lock:monitoring:<key>` with `Cache::lock(…, ttl =
     loader timeout + 5 s)->block($lockWait)`. Inside, check again (another request may have
     refreshed it), else call the loader and store the snapshot with a TTL of `maxAge + 5 s`.
  3. If the lock wait times out, call the loader without the lock (a degraded but correct path).

  | Data | Key | Max age | Loader | `collectedAt` |
  |---|---|---|---|---|
  | Container states | `monitoring:containers` | 5 s | `listContainers()` | when dockerapi answered |
  | Host figures | `monitoring:host` | 10 s | `hostStats()` | dockerapi's `system_time` (R6) |
  | Resource usage | `monitoring:usage:<service>` | 5 s (sample ≤ 15 s, R7) | `containerStats()` | newest sample's `read` |
  | Mail storage | `monitoring:mail-storage` | 60 s | `exec(dovecot, system, df)` | when `df` answered |

  The maximum ages are constants in `config/restv2.php` (`monitoring.max_age.*`), also reported
  by the capabilities read (R32). Container actions delete `monitoring:containers` and the
  container's usage key. Logs, the queue, bans, Rspamd data and the update check are not in this
  cache (spec clarification 6); the update check has its own cache (R10).
- **Rationale**: Spec FR-006, FR-007, FR-010, FR-011 and SC-004: ten polling administrators cause
  no more dockerapi calls than one, and `docs/conventions.md` allows caching only where the spec
  sets a maximum age. The lock prevents a stampede when a snapshot expires.
- **Alternatives considered**: Laravel's `Cache::flexible()` (stale-while-revalidate) was
  rejected: it serves values older than the maximum age. A per-process static cache was rejected:
  php-fpm workers do not share memory.

### R6. Host figures

- **Decision**: `hostStats()` returns dockerapi's `host_stats` document: `cpu.cores`,
  `cpu.usage` (percent), `memory.total` (bytes), `memory.usage` (percent), `memory.swap` (psutil's
  `sswap` tuple serialised as a list: `[total, used, free, percent, sin, sout]`), `uptime` (float
  seconds), `system_time` (`dd.mm.YYYY HH:MM:SS` in dockerapi's local time, which is mailcow's
  `TZ`) and `architecture`. `HostOverviewService` parses `system_time` in `TZ` to a UTC
  `collectedAt`, truncates `uptime` to whole seconds, maps the swap list, and adds `hostName`
  (`MAILCOW_HOSTNAME`), `timeZone` (`TZ`), `version` (R9) and `currentTime` (now, UTC; the API
  runs on the same host kernel clock). dockerapi itself caches the figures for 10 s in Redis
  (`host_stats`, `ex=10`), so its answer is never older than 10 s.
- **Rationale**: Legacy returns the same document and formats the time as local text; the spec
  wants RFC 3339 UTC plus the time zone.
- **Alternatives considered**: Reading dockerapi's Redis key `host_stats` directly was rejected:
  it is dockerapi's private cache and is empty until someone calls the endpoint.

### R7. Container resource usage

- **Decision**: `ContainerResourceUsageService`:
  - Checks the container's state in the shared container snapshot. Not running → `409
    container-not-running` at once (legacy blocks until the timeout, because dockerapi's
    `/container/{id}/stats/update` loops until a sample exists).
  - Calls `containerStats(id, 5 s)`. dockerapi starts a sampling task (one sample now, a second
    5 s later, keeping at most 3 raw `docker stats` samples for 60 s) and returns the stored
    samples. A timeout re-reads the state fresh: stopped → `409`, else `503 temporary-failure`.
  - `DockerStatsCalculator` (a pure class, unit-tested with recorded samples) computes from the
    newest sample, like the `docker stats` CLI:
    - `cpuPercent` = `(Δ cpu_usage.total_usage / Δ system_cpu_usage) × online_cpus × 100`, from
      `cpu_stats` and `precpu_stats`; `null` when the deltas are not positive;
    - memory used = `memory_stats.usage − (stats.inactive_file ?? stats.total_inactive_file ??
      stats.cache ?? 0)`, limit = `memory_stats.limit`;
    - disk read and written = the sums of `blkio_stats.io_service_bytes_recursive` entries with
      `op` `Read` and `Write` (case-insensitive, cgroup v1 and v2), 0 when the list is `null`;
    - network received and sent = the sums of `rx_bytes` and `tx_bytes` over `networks`, 0 when
      absent;
    - rates between the newest sample and the newest earlier sample at least 1 s older (by
      `read`), else `null`.
  - The response is cached per service for 5 s (R5). While a client polls every 5 s, dockerapi
    keeps sampling, so the newest sample is at most about 10 s old, within the 15 s limit.
- **Rationale**: Spec FR-011 moves rate computation from the browser into the API and fixes the
  blocking behaviour.
- **Alternatives considered**: Computing rates from two of our own polls was rejected: the
  dockerapi samples are already timestamped and shared.

### R8. Mail storage usage

- **Decision**: `exec(dovecot-mailcow, 'system', 'df', ['dir' => '/var/vmail'])`. dockerapi runs
  `df -H '<dir>' | tail -n1 | tr -s [:blank:] | tr ' ' ','` as `nobody` and returns the line as
  a JSON string, or `"0,0,0,0,0,0"` when `df` fails, or `null` when the container is not running.
  `DfOutputParser` takes the **last five** comma-separated fields as size, used, available, use%
  and mount point (a long device name makes `df` wrap, leaving an empty first field) and the
  remainder as the device. Sizes use SI suffixes (`k`, `M`, `G`, `T`, `P`, `E`, powers of 1000,
  decimals allowed) and are converted to bytes with `approximate: true`. `0,0,0,0,0,0`, `null`,
  or an unparseable line → `measured: false` with every figure `null`. dockerapi unreachable →
  `503`. Cached for 60 s (R5).
- **Rationale**: dockerapi's `system df` task is the only measurement it offers, and its `-H` is
  fixed, so exact bytes are impossible; the spec requires marking them approximate and never
  reporting zeros.
- **Alternatives considered**: Mounting `/var/vmail` into the API container to call `statvfs()`
  was rejected: it widens the API's file access to all mail (constitution VI).

---

## 3. Version, updates, public addresses

### R9. Installed version

- **Decision**: `MailcowConfig` (001 R14) is extended: `resources/bin/export-mailcow-vars.php`
  also includes `app_info.inc.php` from the mounted `inc` directory when it exists (legacy
  `prerequisites.inc.php` does the same) and exports `MAILCOW_GIT_VERSION`, `MAILCOW_GIT_OWNER`,
  `MAILCOW_GIT_REPO`, `MAILCOW_GIT_URL`, `MAILCOW_GIT_COMMIT`, `MAILCOW_GIT_COMMIT_DATE`,
  `MAILCOW_BRANCH` and, from `vars.inc.php`, `DOCKER_TIMEOUT`. The fingerprint now covers the
  three files, so `update.sh` (which rewrites `app_info.inc.php`) is picked up by the next request.
  Precedence as in 001: `RESTV2_<name>` server setting, then the files, then a default (`null`
  for the version values, 60 for `DOCKER_TIMEOUT`). `MAILCOW_GIT_COMMIT_DATE` (`git log
  --format=%ci`, for example `2026-09-15 10:11:12 +0200`) is parsed to UTC, `null` when it does not
  parse; empty strings become `null`.
- **Rationale**: Legacy reads these globals; the API must not need a second place to configure
  them (001 R14).
- **Alternatives considered**: Asking dockerapi for image tags was rejected: the mailcow version is
  a repository tag, not an image tag.

### R10. Update check

- **Decision**: `App\Services\Monitoring\UpdateCheckService` with
  `App\Clients\Releases\ReleaseSource` (interface) and `GitHubReleaseSource`:
  - Settings (R27): `RESTV2_UPDATE_CHECK` (`y` default, FeldHost `n`), `RESTV2_UPDATE_SOURCE_URL`
    (default `https://api.github.com/repos/<MAILCOW_GIT_OWNER>/<MAILCOW_GIT_REPO>`, falling back
    to `mailcow/mailcow-dockerized`), `RESTV2_UPDATE_STABLE_BRANCH` (`master`).
  - Disabled, or `MAILCOW_BRANCH` ≠ the stable branch, or no installed version → `disabled`, with
    **no outbound request**.
  - Otherwise, as legacy `update_check.php`: `GET <source>/releases/latest` and
    `GET <source>/releases/tags/<installed>`, `User-Agent: mailcow-update-check`,
    `Accept: application/vnd.github+json`, 10 s timeout. Any non-200, missing `tag_name` or
    `created_at`, or a network error → `checkFailed`. Otherwise `updateAvailable` when the latest
    release's `created_at` is later than the installed release's, else `upToDate`. The latest
    release's `html_url`, `published_at` and `body`, and the installed release's `body`, are
    returned. The comparison uses `created_at` exactly as legacy does; the response's
    `publishedAt` is GitHub's publication time.
  - Cache: `restv2:update-check:<sha1(source)>:<installed version>`, 3,600 s after success and
    300 s after failure, with a single-flight lock like R5. The legacy key
    `MAILCOW_UPDATE_CHECK/<version>` is not shared, because its value lacks the release notes.
- **Rationale**: Spec FR-019 and FR-020; the fork disables the check by setting.
- **Alternatives considered**: Sharing legacy's cache key was rejected (different payload).
  Returning release notes as HTML was rejected: they are untrusted Markdown, which the contract
  tells clients to render safely.

### R11. Public addresses

- **Decision**: `PublicAddressService` reads Redis `IP_CHECK` (connection `mailcow`); anything
  other than `1` → `409 ip-check-disabled` without an outbound request. Otherwise
  `App\Clients\PublicAddress\HttpPublicAddressLookup` sends `GET http://ipv4.mailcow.email` and
  `GET http://ipv6.mailcow.email` (`RESTV2_IP_CHECK_IPV4_URL`, `RESTV2_IP_CHECK_IPV6_URL`) in
  parallel with `Http::pool()`, `connectTimeout(10)` and `timeout(15)`, as legacy. Each body is
  trimmed and accepted only when `filter_var` validates it as an address of that family; anything
  else → `null`. No caching. Redis unavailable → `503`.
- **Rationale**: Spec FR-021, including the deviation that `ip_check` is honoured by the API (legacy
  honours it only in the dashboard button).
- **Alternatives considered**: Sequential requests were rejected: two 15 s timeouts would exceed
  the 15 s total.

---

## 4. Logs

### R12. Redis list logs

- **Decision**: `App\Services\Logs\RedisListLogSource` serves the ten Redis-backed types:

  | Path | Key | Mapper |
  |---|---|---|
  | `postfix`, `dovecot`, `sogo` | `POSTFIX_MAILLOG`, `DOVECOT_MAILLOG`, `SOGO_LOG` | `time`, `priority`, `program`, `message` (syslog-ng `format-json`) |
  | `netfilter` | `NETFILTER_LOG` | `time` (int), `priority` (`crit`, `warn`, `info`), `message` |
  | `autodiscover` | `AUTODISCOVER_LOG` | `time`, `ua`, `user`, `ip`, `service` |
  | `watchdog` | `WATCHDOG_LOG` | `time`, then `service`, `lvl`, `hpnow`, `hptotal`, `hpdiff` (text → int) or `message` |
  | `acme` | `ACME_LOG` | `time`, `message` (base64 handling) |
  | `cron` | `CRON_LOG` | `time`, `priority`, `task`, `message` |
  | `rate-limits` | `RL_LOG` | `time`, `rl_name`, `rl_hash`, `from`, `rcpt` (`", "`-joined → list), `user`, `message_id`, `header_from`, `header_subject`, `qid`, `ip`; `err` name or hash → `null` |
  | `legacy-api-requests` | `API_LOG` | `time`, `uri`, `method`, `remote`, `data` (R15) |

  - Each request reads the whole list with `LRANGE key 0 -1` (at most `LOG_LINES` + 1 entries,
    10,000 by default; 101 for autodiscover) on the `mailcow` connection, decodes each line, and
    **drops lines that are not a JSON object or lack a numeric `time`** (FR-032).
  - Entries are sorted by time, newest first, with a **stable** sort over the list order (index 0
    is the newest push), so equal times keep mailcow's order. `order=asc` reverses the result.
  - **Entry ID (cursor)**: `<time>.<first 16 hex of sha256(raw line)>.<n>`, where `n` counts
    earlier identical raw lines from the oldest end. `before=<id>` locates the entry in the
    sorted, unfiltered list and keeps only the entries after it; when it is not found (trimmed, or
    removed by a rate-limit reset, which `LREM`s `RL_LOG`), entries strictly older than the
    cursor's `time` are kept. Either way the next page has no duplicates.
  - Then filters (`from`/`to` inclusive, `severity`, type-specific substring filters with OR
    within one filter), then `q` (case-insensitive substring over every text field of the
    **normalised and masked** entry), then `total`, `offset`, `limit`.
  - **Severity**: `emerg|alert|crit|err` → `error`, `warning|warn` → `warning`,
    `notice|info|debug` → `info`, other → `null` (legacy dashboard classes).
  - **Base64**: a message starting with `base64,` is decoded with `base64_decode($rest, true)`;
    when that fails or the result is not valid UTF-8, the message is returned without the prefix
    (the dashboard's `atob` fallback). The ACME producer writes the prefix without encoding, so in
    practice the prefix is stripped.
  - Redis unavailable → `503 temporary-failure`.
- **Rationale**: Exact totals need every entry parsed (FR-032), and 10,000 short JSON lines decode
  in a few tens of milliseconds (SC-003). A content-derived cursor survives new pushes at the
  head and trimming at the tail.
- **Alternatives considered**: `LRANGE` of only the requested page was rejected: it cannot count
  unparseable entries or filter. Time-only cursors were rejected: mailcow's times have one-second
  resolution, so an exclusive time cursor skips entries and an inclusive one duplicates them.

### R13. Legacy UI activity log (`logs` table)

- **Decision**: `LegacyUiActivityLogSource` queries the upstream `logs` table through the
  read-only model `App\Models\Mailcow\LegacyUiLogEntry`, ordered by `id` (insertion order, which
  is also time order; `time` is `UNIX_TIMESTAMP()` and needs no time-zone conversion).
  - `from`/`to` → `time BETWEEN ? AND ?`; `before` → `id < ?` (the entry ID is the row `id`);
    `type`, `user`, `role` → `LIKE %v%` per value (escaped), OR within a filter.
  - **Without `q`**, paging and `COUNT(*)` run in SQL.
  - **With `q`**, the search must run on the masked entry (R15), so SQL can only pre-filter:
    `type`, `task`, `user`, `role`, `remote` LIKE `%q%`, or `msg`/`call` LIKE the JSON-escaped forms
    of `q` (as `json_encode` with the flags legacy used for each column writes it). The
    pre-filtered rows are then streamed in `id` order in chunks of 1,000 (`lazyById`), mapped,
    masked and matched in PHP; `total` and the page come from the verified rows. Rows that matched
    only inside a masked value are therefore never counted.
  - `msg` (JSON): a string → `messageKey`, no parameters; a list → first element `messageKey`,
    the rest `messageParameters`; `null` → `null`. `call` (JSON list) → `call`. Both masked.
  - DB unavailable → `503`.
- **Rationale**: Spec FR-028 requires that searches cannot reveal masked values; counting
  pre-filter matches would leak through `total`. The table is never trimmed, so unbounded scans
  are avoided except for a `q` search, whose cost is proportional to its pre-filter matches.
- **Alternatives considered**: A masked copy of the table was rejected: it is an API-owned mirror
  of upstream data that must be kept in sync. Searching raw columns was rejected (oracle).

### R14. Login log (`sasl_log` table)

- **Decision**: `LoginLogSource` reads 001's `SaslLogEntry` model. `datetime` holds mailcow's local
  time (`TZ`) and is converted with 001's `MailcowDateTime`; `from`/`to` are converted from UTC to
  `TZ` for SQL. The reported service is
  `CASE WHEN service = 'MAILCOWUI' AND <label> = 'WEBUI' THEN 'WEBUI' ELSE service END` (FR-030,
  label `config('restv2.sasl_log_service')`), and the `service` filter and `q` match the reported
  value. Order: `datetime DESC, service, real_rip, username` (the primary key breaks ties
  deterministically). The cursor is base64url of `[datetime, service, real_rip, username]` with
  keyset paging. `appPasswordUsed` = `app_password > 0`.
- **Rationale**: The table has no insertion ID and rows are `REPLACE`d, so keyset paging is the
  only duplicate-free option; a replaced row moves to the newest page.
- **Alternatives considered**: Offset paging only was rejected (FR-026).

### R15. Secret masking

- **Decision**: `App\Services\Logs\SecretMasker::mask(mixed $value): mixed` walks arrays and
  objects recursively. A member whose **name** contains `pass`, `secret`, `private` or `token`
  (case-insensitive), or whose name is `key` (case-insensitive), gets the value `*`, whatever its
  type (a nested object is replaced as a whole). List elements are not names and are only
  descended into. `LegacyApiDataParser` turns `API_LOG.data` back into a structure: legacy writes
  `name='<json>', name2='<json>'` (`api_log()` re-encodes each `$_POST` value after masking only
  top-level `pass` keys). The parser finds each `<name>='` start (`[A-Za-z0-9_\-\[\]]+`) and takes
  the shortest value up to a `'` followed by `, <name>='` or the end that decodes as JSON; an empty
  value is `null`. When the string cannot be split this way, `requestData` is `null` (nothing of it
  is returned). The masked structure is what `q` and filters see. The patterns are listed in the
  contract.
- **Rationale**: FR-028 and SC-006: legacy logs hold DKIM private keys (`private_key_file`),
  OAuth2 and identity-provider secrets (`client_secret`), Pushover tokens (`token`), bind and app
  passwords. Withholding unparseable data fails closed.
- **Alternatives considered**: Masking by value patterns (PEM headers, long base64) was rejected as
  incomplete; the spec defines name patterns.

---

## 5. Rspamd

### R16. Rspamd controller client and mapping

- **Decision**: `App\Clients\Rspamd\RspamdController` (from 006) gains `history(): array` and uses
  006's `stat(): array`. The adapter talks HTTP over mailcow's controller socket
  `/var/lib/rspamd/rspamd.sock` (`RESTV2_RSPAMD_SOCKET`; the override mounts `rspamd-vol-1` at
  `/var/lib/rspamd`, read-only; connecting to a socket works on a read-only mount) with
  `CURLOPT_UNIX_SOCKET_PATH`, exactly as legacy `get_logs('rspamd-history')`. The socket is a
  trusted controller connection, so no password is sent. Timeouts: 5 s to connect, 10 s total.
  Connection failure, timeout or non-200 → `503 temporary-failure`.
  - `GET /history` returns `{"version": 2, "rows": [...]}` with up to `nrows` (1,000) rows.
    `RspamdHistoryService` maps `unix_time` → `time`, `ip`, `sender_smtp`, `sender_mime`,
    `rcpt_smtp`, `rcpt_mime`, `subject`, `action`, `score`, `required_score`, `size`,
    `time_real`, `time_virtual`, `message-id` and `user`; `symbols` (an object keyed by name with
    `score` and `options`) becomes a list in the dashboard order: positive scores descending, then
    negative ascending, then zero (ties by name). Filters, sorting (ties keep Rspamd's order),
    `q` and paging run in PHP over the at most 1,000 rows.
  - `GET /stat` → `scanned`, `learned`, `spam_count`, `ham_count`, `actions`, `connections`,
    `control_connections`, `statfiles[].{symbol, type, revision, users}`, `fuzzy_hashes`; missing
    numbers → 0, missing maps → `{}`; nothing else is passed through.
- **Rationale**: Same transport and endpoints as legacy; a documented subset per FR-035.
- **Alternatives considered**: HTTP to `rspamd-mailcow:11334` was rejected: it needs the
  controller password, which the API must not hold.

---

## 6. Mail queue

### R17. Queue operations through dockerapi exec

- **Decision**: `App\Services\MailQueue\MailQueueService` uses dockerapi's `mailq` tasks on
  `postfix-mailcow` (`RESTV2_POSTFIX_CONTAINER`):
  - **Precondition**: the container snapshot (R5) says Postfix runs; otherwise `409
    mail-service-not-running` before any exec. dockerapi's mailq handlers list running containers
    only and return `null` or a `danger` message for a stopped one; when that happens despite a
    "running" snapshot, the state is re-read fresh and the same `409` is returned if it stopped,
    else `502 queue-action-failed`.
  - **List** (`list`): `postqueue -j` as user `postfix`, one JSON object per line.
    `PostqueueJsonParser` decodes the **first 10,000** non-empty lines (legacy's limit, also
    skipping the line `1`) into `MailQueueMessage` (`queue_id`, `queue_name`, `arrival_time`,
    `message_size`, `sender`, `recipients[].{address, delay_reason}`, `forced_expire`), and only
    counts the remaining lines per queue with a `"queue_name"` regular expression.
    `meta.queueCounts` covers every line; `meta.truncated` is true beyond 10,000. Filters, sort
    and paging run in PHP. Unparseable lines are skipped.
  - **View** (`cat`): `postcat -q <id>` as user `postfix`, returned as text. Output starting with
    `*** ENVELOPE RECORDS` → content; output containing `No such file or directory` → `404`;
    anything else → `502` with the first line (at most 500 characters) as `serviceMessage`. The
    content is never logged. The view is audited (R24) before the content is returned; when the
    audit record cannot be written, the content is not returned (`503`).
  - **Selected messages**: the IDs are de-duplicated case-sensitively in request order. A fresh
    `list` determines which IDs are in the queue now (all lines, not only the first 10,000);
    absent IDs get `notFound`. The present IDs go to one `exec`: `hold` (`postsuper -h …`),
    `unhold` (`postsuper -H …`), `delete` (`postsuper -d …`), or `deliver` (dockerapi runs
    `postqueue -i <id>` once per ID and always reports success). A `danger` answer fails the whole
    request (`502`, each item's audit result `failed`), a timeout → `504 outcome-unknown`. Present
    IDs get `done`. Results use the shared `BulkItemOutcome` values; queue items carry no version.
    The short window between the membership check and the command is documented: an ID delivered
    in between is reported `done`, which Postfix treats as a no-op.
  - **Flush** (`flush`, `postqueue -f`) and **purge** (`super_delete`, `postsuper -d ALL`) need
    no list.
  - Timeouts: `DOCKER_TIMEOUT` for actions; list and view use it too and map a timeout to `503`.
- **Rationale**: These are exactly legacy's dockerapi tasks; dockerapi re-filters IDs with
  `^[0-9a-fA-F]+$`, and the API validates the same format before sending (FR-040). The pre-list is
  the only way to give per-ID results, because postsuper reports only totals.
- **Alternatives considered**: One exec per ID to read individual results was rejected: up to
  1,000 dockerapi calls per request. Post-action verification by a second listing was rejected as
  doubling the cost for a race that Postfix already makes harmless.

---

## 7. Ban service (fail2ban)

### R18. Settings storage, version tag and atomic writes

- **Decision**: `App\Clients\Fail2Ban\Fail2BanStore` (Redis connection `mailcow`) owns the `F2B_*`
  keys:
  - **Read**: `F2B_OPTIONS` (JSON: `ban_time`, `ban_time_increment`, `max_ban_time`,
    `netban_ipv4`, `netban_ipv6`, `max_attempts`, `retry_window`, `banlist_id`,
    `manage_external`). When it is absent, the netfilter fallbacks `F2B_BAN_TIME`,
    `F2B_MAX_BAN_TIME`, `F2B_BAN_TIME_INCREMENT`, `F2B_MAX_ATTEMPTS`, `F2B_RETRY_WINDOW`,
    `F2B_NETBAN_IPV4`, `F2B_NETBAN_IPV6` are read like `refreshF2boptions()`, and missing values
    take the ban service defaults (1,800, 10,000, `true`, 10, 600, 32, 128, external `0`,
    `banlist_id` `null`) with `stored: false`. Values are normalised as the ban service uses them:
    `int()` for numbers (an unparseable value reports the default), truthiness for the two flags.
    `F2B_WHITELIST` and `F2B_BLACKLIST` are hashes whose field names are the entries; they are
    returned sorted naturally (legacy `natsort`).
  - **Version tag**: `ETag::strong()` over the canonical JSON `{allowlist (sorted, unique),
    banListId, banTime, banTimeIncrement, denylist (sorted, unique), externallyEnforced,
    ipv4PrefixLength, ipv6PrefixLength, maxAttempts, maxBanTime, retryWindow}` of the normalised
    values. The ban service rewrites `F2B_OPTIONS` every 10 s and on every ban with Python's
    `json.dumps` (different bytes, same values), so a tag over bytes would change constantly; a
    tag over values does not (FR-057).
  - **Write** (settings change, ban list rotation, allowlist and denylist actions): optimistic
    transaction on one connection: `WATCH F2B_OPTIONS F2B_WHITELIST F2B_BLACKLIST`, read and
    normalise, compare `If-Match`, validate against the current values (R19), then
    `MULTI; SET F2B_OPTIONS <json>; DEL F2B_WHITELIST; HSET F2B_WHITELIST e 1 …; DEL F2B_BLACKLIST;
    HSET F2B_BLACKLIST e 1 …; EXEC`. A `nil` EXEC (a watched key changed, typically the ban
    service's periodic rewrite) retries up to 5 times, then `503 temporary-failure`. The JSON uses
    legacy's key order and types (`ban_time_increment` boolean, `manage_external` 0/1). A missing
    `banlist_id` is generated (UUID v4) on the first save, as the ban service would.
  - **Inherited race**: the ban service's `refreshF2boptions()` reads and rewrites `F2B_OPTIONS`
    without a transaction. A change stored between its read and its write is overwritten. Legacy
    has the same window (microseconds every 10 s); the API cannot close it without changing the
    ban service (constitution V). It is documented in the quickstart's operator notes.
  - **Ban list address**: `https://<MAILCOW_HOSTNAME>/f2b-banlist?id=<banListId>` (legacy builds it
    from the request host; the configured host name is stable for automation clients).
- **Rationale**: FR-045, FR-057 and FR-061: legacy deletes both lists and rewrites them in
  separate commands, so a failure can leave them empty; `MULTI` makes the change atomic for the
  ban service's `HGETALL`.
- **Alternatives considered**: A Lua script was rejected: `WATCH`/`MULTI` expresses the
  compare-and-set with the version check in PHP, where validation already runs. Pessimistic
  locking was rejected: the ban service does not take the lock.

### R19. Settings validation

- **Decision**: `UpdateFail2BanSettingsRequest` (JSON Merge Patch) plus service-level rules that
  need the current values:
  - Types per the contract; `null` → `invalidType`; empty patch → `required` on `body`.
  - Ranges (FR-046), applied **only to sent values that differ from the stored value**:
    `banTime` and `maxBanTime` 60 to 2,147,483,647, `maxAttempts` and `retryWindow` 1 to
    2,147,483,647, `ipv4PrefixLength` 8 to 32, `ipv6PrefixLength` 8 to 128 (`outOfRange`).
    `maxBanTime ≥ banTime` is checked when either changes: on `maxBanTime` (`outOfRange`,
    `min` = ban time, `minField: banTime`) when it was sent, else on `banTime` (`mustNotExceed`,
    `field: maxBanTime`).
  - Lists: entries trimmed, empty entries refused (`required`), at most 10,000 per list
    (`tooMany`) and 253 characters per entry (`tooLong`), exact duplicates stored once.
    Allowlist entries: `valid_network()` (an address per `FILTER_VALIDATE_IP`, or
    `address/prefix` with a decimal prefix within the family's range) or `valid_hostname()`
    (`FILTER_VALIDATE_DOMAIN` with `FILTER_FLAG_HOSTNAME`), ported from legacy
    (`invalidFormat`, `format: ipNetworkOrHostname`). Denylist entries: `valid_network()` only
    (`format: ipNetwork`) and not one of `0.0.0.0`, `0.0.0.0/0`, `::/0`, `<IPV4_NETWORK>.0/24`,
    `<IPV4_NETWORK>.0`, `<IPV6_NETWORK>`, compared after canonicalising address and prefix (so
    `0.0.0.0/00` is refused too) (`reserved`, param `reserved` = the list). An entry on both lists,
    compared case-insensitively with canonical IP literals, → `conflictsWith` on the denylist entry
    (`field: allowlist`, `value`). Every violation is reported; nothing is stored.
  - Stored entries that would fail these rules (from legacy) are kept when a list is not sent.
- **Rationale**: Spec clarification 7 and FR-046/FR-047; legacy's `valid_network()` semantics keep
  accepted entries identical (`10.0.0.5/24` with host bits stays valid, as the ban service accepts
  it with `strict=False`).
- **Alternatives considered**: Normalising entries to canonical networks was rejected: legacy and
  the ban service store and show entries as typed.

### R20. Ban rules and Python regular-expression validation

- **Decision**:
  - Storage: `F2B_REGEX` is a JSON object `{"1": "<rule>", "2": …}`, written like legacy
    (`json_encode($rules, JSON_UNESCAPED_SLASHES)` with keys from 1). Reading orders by numeric
    key. Absent or non-JSON → `rules: []`, `stored: false`. Tag: `ETag::strong()` over the JSON
    list of patterns in order.
  - Validation: 1 to 100 rules (`required` for an empty list, `tooMany`), each 1 to 1,000
    characters (`tooShort`, `tooLong`). Each rule must **compile in Python `re`**, the ban
    service's dialect, and have at least one group: `App\Support\Regex\PythonRegexValidator` runs
    `python3 -I -S -B resources/bin/validate-python-regex.py` through Symfony `Process` (5 s
    timeout), passing the rules as JSON on stdin; the script returns
    `[{ok, groups, error}]` from `re.compile()`. Not compiling → `invalidRegex` (`error` =
    Python's message); `groups == 0` → `missingCaptureGroup`. A missing interpreter or a timeout is
    a server fault (`500`, logged).
  - Writes use `WATCH F2B_REGEX` / `MULTI` / `SET` with `If-Match`, like R18. The ban service
    reloads `F2B_REGEX` on every log line, so no restart is needed.
- **Rationale**: PCRE and Python `re` differ (`(?<name>…)`, `\K`, possessive quantifiers by
  version, inline flags), so only Python decides FR-050's "compiles in the dialect of the ban
  service" reliably. A rule without a group makes `result.group(1)` raise inside the ban service's
  watch loop, which ends the ban service (SC-008). The ban service runs Python on Alpine 3.23; the
  API image installs Alpine's `python3`, the same family.
- **Alternatives considered**: A PCRE compile plus a blacklist of non-Python constructs was
  rejected as a two-sided approximation. Semantic checks that group 1 captures an address were
  rejected as undecidable; the contract states the requirement and recommends testing.

### R21. Rule reset

- **Decision**: `Fail2BanRulesService::reset()`: check `If-Match` against the rules tag; probe the
  audit log (R24); resolve `netfilter-mailcow` (`RESTV2_BAN_SERVICE_CONTAINER`) in a fresh
  container list (dockerapi down → `503`, nothing deleted); `DEL F2B_REGEX`;
  `containerAction(id, 'restart')` (timeout → `504 outcome-unknown`, `step: rulesReset`;
  `danger` → `502 container-action-failed`); then poll `F2B_REGEX` every 1 s up to 10 times
  (`Sleeper`). Rules found → `200` with them; otherwise `504 ban-rules-not-restored`. The audit
  record holds the old rules and the new ones (or `null`).
- **Rationale**: Legacy `fail2ban('edit', reset-regex)`: delete, restart, poll 10 × 1 s.
- **Alternatives considered**: Writing mailcow's default rules ourselves was rejected: they belong
  to the ban service and change between releases (spec assumption).

### R22. Ban actions and ban lists

- **Decision**:
  - Lists: `HGETALL F2B_ACTIVE_BANS` (network → end epoch) with `HGETALL F2B_QUEUE_UNBAN`
    (network → 1), and `HGETALL F2B_PERM_BANS` (network → start epoch). `address` is the part
    before `/`; `remainingSeconds = max(0, end − now)`. Network sort: by packed address
    (`inet_pton`, IPv4 before IPv6), then prefix length. `q` matches the network text.
  - The path's network is percent-decoded and matched against `F2B_ACTIVE_BANS` fields after
    canonicalisation (lower case, compressed IPv6); not found → `404`.
  - **lift**: `HSET F2B_QUEUE_UNBAN <net> 1`.
  - **allowlist**: in one `MULTI`: `HSET F2B_WHITELIST <net> 1`, `HDEL F2B_BLACKLIST <net>`,
    `HSET F2B_QUEUE_UNBAN <net> 1`.
  - **denylist**: denylist rules of R19 (`reserved` on `network`); `MULTI`: `HSET F2B_BLACKLIST
    <net> 1`, `HDEL F2B_WHITELIST <net>`.
  - The ban service processes the unban queue every 10 s (`autopurge`) and reloads the lists
    every 60 s, resolving host names each time.
- **Rationale**: Legacy `fail2ban('edit')` actions, restricted to active bans (FR-053) with the
  denylist protection legacy applies.
- **Alternatives considered**: A body-addressed action (`{network}` in JSON) was rejected: the
  ban is a sub-resource identified by its network; percent-encoding `/` is standard (R30).

### R23. Ban service running indicator

- **Decision**: `banServiceRunning` comes from the shared container snapshot (R5) for
  `netfilter-mailcow`: `true`/`false`, or `null` when dockerapi is unavailable or the container is
  not listed. Reads and saves of settings, rules and bans never fail because dockerapi is down.
- **Rationale**: FR-056; the ban data lives in Redis and does not need dockerapi.

---

## 8. Audit, errors and authorisation

### R24. Audit records of this feature

- **Decision**: One record per state-changing request that reaches the operation (after
  authentication, role and key-scope checks and request validation), whatever its outcome, and
  one per message content view. Refusals by the pipeline (`401`, `403`, CSRF) and `422`
  validation failures are not audited, as in feature 001. Records use 001's `AuditLogger` with
  the new event types and targets of the contract:

  | Event | Target | `details` (whitelisted) |
  |---|---|---|
  | `containerStart`, `containerStop`, `containerRestart` | `container`, service name | `outcome` (`done`, `alreadyInState`), `stateBefore`, `stateAfter`, `serviceMessage`, `step` |
  | `mailQueueDelivery`, `mailQueueHold`, `mailQueueRelease`, `mailQueueDeletion` | `mailQueue`, null | `results`: every distinct ID with `done`/`notFound`/`failed` |
  | `mailQueueFlush`, `mailQueuePurge` | `mailQueue`, null | `serviceMessage` on failure |
  | `mailQueueMessageView` | `mailQueueMessage`, queue ID | — (never the content) |
  | `fail2banSettingsUpdate` | `fail2banSettings` | `changes` (`{field: {old, new}}`), `allowlistAdded`, `allowlistRemoved`, `denylistAdded`, `denylistRemoved` |
  | `fail2banRulesReplacement`, `fail2banRulesReset` | `fail2banRules` | `oldRules`, `newRules` |
  | `fail2banBanLift`, `fail2banBanAllowlisting`, `fail2banBanDenylisting` | `fail2banActiveBan`, network | `network`, list entries added and removed |
  | `fail2banBanListIdRotation` | `fail2banBanListId`, null | — (never an ID) |

  Failure reasons: `conflict` (with `details.problemType`), `notFound`, `versionOutdated`,
  `temporaryFailure`, `outcomeUnknown`, and the new `serviceFailure` and `rulesNotRestored`. The
  bulk request ID is the record's `requestId`, as the shared `BulkResult` defines.

  **Irreversible operations** (container actions, queue actions, rule reset; FR-064) use
  `App\Services\Audit\IrreversibleOperationAudit`:
  1. `probe()`: `SELECT 1 FROM restv2_audit_events LIMIT 1` on the `mysql` connection. Failure →
     `503 temporary-failure`; nothing is done.
  2. Run the operation.
  3. `recordWithRetry()`: insert the record with a ULID chosen up front; on a connection error,
     `DB::reconnect()` and retry every 1 s (`Sleeper`) until `DOCKER_TIMEOUT` after the start of
     the request; a duplicate key counts as success. If the deadline passes, log the event at
     `error` level (without secrets) and add the header `Audit-Recorded: false` to the response.

  **Redis-backed changes** (settings, rules, bans, rotation) insert the record in a database
  transaction around the Redis `EXEC`: begin, insert, `EXEC`, commit. An insert failure aborts
  before `EXEC` (`503`); a failed `EXEC` rolls back. If the commit fails after `EXEC` succeeded, the
  change stays, the event is logged at `error` and `Audit-Recorded: false` is sent.
- **Rationale**: FR-062 to FR-064 and constitution VI. The queue's single record per request is the
  spec's clarification 5, which governs over the per-object convention.
- **Alternatives considered**: Writing a "pending" record before and an outcome record after was
  rejected: two records per request contradict FR-062, and records are immutable.

### R25. Status codes and problem types

- **Decision**: New types `container-protected` (409), `container-not-running` (409),
  `container-action-failed` (502), `mail-service-not-running` (409), `queue-action-failed` (502),
  `ban-rules-not-restored` (504) and `ip-check-disabled` (409). Reused: `temporary-failure` (503
  with `Retry-After: 5`, from 002), `outcome-unknown` (504, `step` = the action, from 003), and
  `412` `about:blank` for an outdated `If-Match` (the spec's "outdated version").
  - `409` for refusals caused by state or configuration, because `docs/conventions.md` reserves
    `403` for roles, flags and key scopes.
  - `502` when the container service or Postfix answered with a failure: the API worked and an
    upstream failed (RFC 9110 15.6.3); clients show `serviceMessage`.
  - `504` when the outcome is unknown after `DOCKER_TIMEOUT` or the ban service did not restore
    its rules in time (RFC 9110 15.6.5).
- **Rationale**: Each type is one thing clients act on (FR-065). `502` and `504` are outside the
  constitution's list and are justified in the plan's Complexity Tracking.
- **Alternatives considered**: `500` for upstream failures was rejected: it means "bug" to clients
  and monitoring. `503` for an unknown outcome was rejected: a retry of a possibly completed restart
  is not what `Retry-After` suggests.

### R26. Authorisation

- **Decision**: All routes of this feature form one group with `restv2.auth:authenticated`, the
  default `restv2.pending:enforce`, and `can:operate-monitoring`. The gate (defined in
  `App\Policies\MonitoringPolicy`, registered in `AppServiceProvider`) allows only principals with
  role `admin` (sessions and keys owned by an administrator). Domain administrators, mailbox users
  and keys owned by domain administrators get `403 forbidden`. 001's `EnforceKeyScope` refuses
  read-only keys on every non-`GET` method with `403 insufficient-key-scope` before the gate, and
  read-only keys may use every `GET`, including message content. Restricted sessions get
  `403 pending-action-required`.
- **Rationale**: FR-001, FR-002, FR-004 with 001's pipeline order (R8 there).

### R27. Configuration

- **Decision**: New settings in `config/restv2.php`, read from the environment:

  | Variable | Default | Meaning |
  |---|---|---|
  | `COMPOSE_PROJECT_NAME` | `mailcow-dockerized` | mailcow.conf name; containers of this project |
  | `SKIP_CLAMD`, `SKIP_OLEFY` | `n` | mailcow.conf names; hide the optional containers |
  | `LOG_LINES` | `9999` | mailcow.conf name; in-memory logs keep `LOG_LINES + 1` entries |
  | `RESTV2_DOCKER_TIMEOUT` | from `vars.inc.php`, else `60` | container-service timeout (legacy `DOCKER_TIMEOUT`) |
  | `RESTV2_DOCKERAPI_URL` | `https://dockerapi:443` | container service |
  | `RESTV2_SELF_SERVICE` | `restv2-mailcow` | this API's service name |
  | `RESTV2_PROTECTED_CONTAINERS` | `dockerapi-mailcow,mysql-mailcow,redis-mailcow,nginx-mailcow,restv2-mailcow` | containers that cannot be stopped |
  | `RESTV2_CONTAINER_ROLES` | `{}` | JSON map of extra service → role entries |
  | `RESTV2_POSTFIX_CONTAINER`, `RESTV2_DOVECOT_CONTAINER`, `RESTV2_BAN_SERVICE_CONTAINER` | `postfix-mailcow`, `dovecot-mailcow`, `netfilter-mailcow` | containers this feature execs into or restarts |
  | `RESTV2_RSPAMD_SOCKET` | `/var/lib/rspamd/rspamd.sock` | Rspamd controller socket (shared with 006) |
  | `RESTV2_UPDATE_CHECK` | `y` | update check on or off (FeldHost: `n`) |
  | `RESTV2_UPDATE_SOURCE_URL` | GitHub releases API of `MAILCOW_GIT_OWNER/REPO` | release source |
  | `RESTV2_UPDATE_STABLE_BRANCH` | `master` | branch the update check runs on |
  | `RESTV2_IP_CHECK_IPV4_URL`, `RESTV2_IP_CHECK_IPV6_URL` | `http://ipv4.mailcow.email`, `http://ipv6.mailcow.email` | external IP check service |

  For the dev stack only, `RESTV2_MONITORING_FAKES=recorded` binds the test fakes with the
  recorded payloads of `tests/fixtures/monitoring/`; `AppServiceProvider` refuses the setting
  outside `APP_ENV=local`.

  `MAILCOW_HOSTNAME`, `TZ`, `IPV4_NETWORK` and `IPV6_NETWORK` come from 001 R14. The polling
  interval (5 s), maximum ages, the 10,000-message listing limit and the bulk and rule limits are
  constants, reported by the capabilities read.
- **Rationale**: `docs/conventions.md`: mailcow.conf names where they exist, `RESTV2_*` server
  settings with upstream defaults otherwise, fork behaviour as settings.

### R28. Deployment and runtime limits

- **Decision**: 011 changes 001's deployment artefacts:
  - `deploy/docker-compose.override.yml`: environment `COMPOSE_PROJECT_NAME`, `SKIP_CLAMD`,
    `SKIP_OLEFY`, `LOG_LINES` (mailcow.conf names), and the volume
    `rspamd-vol-1:/var/lib/rspamd:ro` unless 006 added it.
  - `deploy/nginx/site.restv2.custom` and `docker/nginx/dev.conf`: `fastcgi_read_timeout 90s`
    (001 sets 60 s, shorter than an action bounded by `DOCKER_TIMEOUT` plus retries and the audit
    retry).
  - `docker/php/Dockerfile` (`base` stage): `apk add --no-cache python3` for R20.
  - The long operations call `set_time_limit(DOCKER_TIMEOUT + 20)`; `docker/php/php-fpm.conf` sets
    `request_terminate_timeout = 100s`.
- **Rationale**: SC-009 (an answer within the timeout plus 5 s) needs every hop to wait longer than
  the operation.

### R29. Dependencies (constitution VIII)

- **Decision**: No new Composer package. The Laravel HTTP client (Guzzle, already a framework
  dependency) talks to dockerapi, GitHub, the IP check service and the Rspamd socket; Symfony
  `Process` (already present) runs the Python validator. The only new runtime component is
  Alpine's `python3` package in the image, under the Python Software Foundation License 2.0,
  which the FSF lists as GPL-compatible.
- **Rationale**: Every other need is met by Laravel and PHP built-ins (`inet_pton`, `filter_var`,
  `hash`, `base64_decode`).
- **Alternatives considered**: A PHP port of Python's `re` parser was rejected as large and
  error-prone (R20).

### R30. Network path parameters with `/`

- **Decision**: `{network}` carries a `/`, sent percent-encoded (`203.0.113.0%2F24`). nginx passes
  `REQUEST_URI` unchanged over FastCGI; Laravel's router matches on the decoded path, so the route
  uses `->where('network', '.+')` and the three action routes are registered before any route
  that could swallow them. The test contract matcher (`OpenApiContract`) splits the **raw** path
  into segments, so the encoded segment matches `{network}`.
- **Rationale**: Standard percent-encoding of a reserved character; generated clients encode path
  parameters by default (`allowReserved: false`).
- **Alternatives considered**: Replacing `/` with another character was rejected as a private
  encoding clients must learn.

### R31. Performance

- **Decision**: SC-003: the overview and container list are served from the shared cache in a few
  milliseconds after the first request; a page of 100 out of 10,000 Redis log entries costs one
  `LRANGE` and about 10,000 JSON decodes; the `logs` table is paged in SQL unless `q` is used.
  `tests/Feature/Performance/MonitoringLatencyTest.php` (group `performance`) measures them with
  recorded fixtures.
- **Rationale**: Measurable targets with realistic data sizes.

### R32. Monitoring capabilities (orchestrator items 1 to 5)

- **Decision**: `GET /monitoring/capabilities` (`MonitoringCapabilitiesService`) returns
  `ipCheckEnabled` (Redis `IP_CHECK`), `pollIntervalSeconds` (5), `maxAgeSeconds` (5, 10, 15, 60),
  `logRetention` (`entries` with `LOG_LINES + 1` for the in-memory logs, `entries` 101 for
  autodiscover, `unbounded` for the legacy UI activity log, `cleanedDaily` for logins),
  `clientAddress` (`$request->ip()`, which is nginx-mailcow's `REMOTE_ADDR` after its trusted
  proxies, 001 R23), and `limits` (25, 1,000, 1,000, 10,000, 100, 1,000, 10,000). It needs no
  dockerapi; Redis unavailable → `503`.
- **Rationale**: The orchestrator's UI-found gaps, on the pattern of 002 FR-064, recorded as
  spec clarifications and FR-069.

### R33. Mail queue counts (orchestrator item 6)

- **Decision**: `meta` of the queue listing extends `ListMeta` with `truncated` and
  `queueCounts` (`active`, `deferred`, `hold`, `incoming`, `maildrop`, `total`), counted over every
  line `postqueue -j` returned (R17). `maildrop` is included because Postfix reports it as a queue
  (FR-037).
- **Rationale**: One request gives the Queue Manager both the page and the header counts.
