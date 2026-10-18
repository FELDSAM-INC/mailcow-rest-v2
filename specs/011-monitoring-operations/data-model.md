# Data Model: Monitoring and Operations

**Feature**: `specs/011-monitoring-operations` | **Date**: 2026-10-18 | **Plan**: [plan.md](./plan.md)

This feature owns **no table**. It reads two upstream tables, reads and writes mailcow's `F2B_*`
Redis keys, reads mailcow's log lists and settings keys, calls mailcow's container service
(dockerapi), the Rspamd controller and two optional external services, and writes audit records
into feature 001's `restv2_audit_events`. Upstream tables are never migrated (constitution III).
JSON shapes are defined in the contract (`api/components/schemas/`); this document maps them to
storage and calls. Research references (R*n*) point to [research.md](./research.md).

---

## Upstream tables (read only)

### LegacyUiLogEntry (`logs`), read only

Model `App\Models\Mailcow\LegacyUiLogEntry`: `$table = 'logs'`, `$primaryKey = 'id'`,
`$timestamps = false`, `$guarded = ['*']`, and it throws on `saving` and `deleting` (this API never
writes legacy logs, FR-068). Columns from the `2026-09a` schema fixture:

| Column | Type | Contract (`LegacyUiActivityLogEntry`) |
|---|---|---|
| `id` | INT AUTO_INCREMENT PK | `id` (decimal string cursor); order and `before` keyset |
| `task` | CHAR(32) NOT NULL DEFAULT `'000000'` | `task` |
| `type` | VARCHAR(32) DEFAULT `''` | `type` (`success`, `danger`, `warning`, `info`) |
| `msg` | TEXT (JSON) | `messageKey`, `messageParameters` (masked) |
| `call` | TEXT (JSON list) | `call` (masked) |
| `user` | VARCHAR(64) NOT NULL | `user` |
| `role` | VARCHAR(32) NOT NULL | `role` |
| `remote` | VARCHAR(39) NOT NULL | `clientAddress` |
| `time` | INT(11) NOT NULL (Unix seconds) | `time` (UTC, no zone conversion) |

Queries (R13): `ORDER BY id DESC|ASC`; `time BETWEEN ? AND ?`; `id < ?` for `before`; `type LIKE ?`,
`user LIKE ?`, `role LIKE ?` (escaped `%v%`, OR within one filter); with `q`, the pre-filter
`(type LIKE ? OR task LIKE ? OR user LIKE ? OR role LIKE ? OR remote LIKE ? OR msg LIKE ? OR
call LIKE ?)` followed by verification on the masked entry in PHP. The table is never trimmed.

### SaslLogEntry (`sasl_log`), read only here

Feature 001's model (`app/Models/Mailcow/SaslLogEntry.php`; 001 writes it for UI logins).

| Column | Type | Contract (`LoginLogEntry`) |
|---|---|---|
| `service` | VARCHAR(32), PK part | `service`; `MAILCOWUI` reported as `WEBUI` when `restv2.sasl_log_service` is `WEBUI` |
| `app_password` | INT NULL | `appPasswordUsed` = `app_password > 0` |
| `username` | VARCHAR(255), PK part | `username` |
| `real_rip` | VARCHAR(64), PK part | `clientAddress` |
| `datetime` | DATETIME(0) DEFAULT NOW(0) (mailcow local time) | `time` (via `MailcowDateTime`, UTC) |

Queries (R14): `ORDER BY datetime DESC, service DESC, real_rip DESC, username DESC`; keyset cursor
`(datetime, service, real_rip, username) < (?, ?, ?, ?)`; `from`/`to` converted to `TZ`; filters on
`username`, the reported service and `real_rip`. mailcow's MariaDB event `clean_sasl_log` cleans it
daily.

---

## Redis (connection `mailcow`, database 0, no prefix)

### Log lists (read only, `LRANGE key 0 -1`)

| Key | Producer | Stored JSON fields | Retention |
|---|---|---|---|
| `POSTFIX_MAILLOG` | syslog-ng in postfix and postfix-tlspol | `time` (text Unix seconds), `priority`, `program`, `message` | `LTRIM 0 LOG_LINES` every minute (dovecot `trim_logs.sh`) |
| `DOVECOT_MAILLOG` | syslog-ng in dovecot | same | same |
| `SOGO_LOG` | syslog-ng in sogo | same | same |
| `NETFILTER_LOG` | netfilter `Logger` | `time` (int), `priority`, `message` | same |
| `AUTODISCOVER_LOG` | `autodiscover.php` | `time`, `ua`, `user`, `ip`, `service` | `LTRIM 0 100` on write |
| `WATCHDOG_LOG` | `watchdog.sh` | `time`, `service`, `lvl`, `hpnow`, `hptotal`, `hpdiff` (all text) or `time`, `message` | `LOG_LINES` |
| `ACME_LOG` | acme `functions.sh` | `time`, `message` (optionally `base64,`-prefixed) | `LOG_LINES` |
| `CRON_LOG` | `keycloak-sync.php`, `ldap-sync.php` | `time`, `priority`, `task`, `message` | `LOG_LINES` |
| `RL_LOG` | `pipe_rl.php` | `time`, `rcpt` (`", "`-joined), `from`, `user`, `rl_info`, `rl_name`, `rl_hash` (`err` when unknown), `qid`, `ip`, `message_id`, `header_subject`, `header_from` | `LOG_LINES`; `LREM` by rate-limit reset (feature 006) |
| `API_LOG` | `json_api.php` `api_log()` | `time`, `uri`, `method`, `remote`, `data` (`name='<json>', …`) | `LOG_LINES` |

Normalisation (R12, R15): numbers stored as text become integers; `base64,` messages are decoded
or stripped; priorities get a severity class; `API_LOG.data` is parsed and masked; lines that are
not JSON objects with a numeric `time` are dropped. Entry ID: `<time>.<sha256(raw)[0..16]>.<n>`.

### Settings keys (read only)

| Key | Value | Use |
|---|---|---|
| `IP_CHECK` | `1` or `0` (legacy `customize('edit', 'ip_check')`, feature 013 edits it) | Public address lookup allowed; `MonitoringCapabilities.ipCheckEnabled` |

### Ban service keys (`F2B_*`)

| Key | Type | Read | Written by this API | Written by others |
|---|---|---|---|---|
| `F2B_OPTIONS` | string (JSON) | settings, tag | settings change, ban list rotation (`SET`, in `MULTI`) | netfilter every 10 s and on each ban (same values), legacy |
| `F2B_BAN_TIME`, `F2B_MAX_BAN_TIME`, `F2B_BAN_TIME_INCREMENT`, `F2B_MAX_ATTEMPTS`, `F2B_RETRY_WINDOW`, `F2B_NETBAN_IPV4`, `F2B_NETBAN_IPV6` | string | fallback when `F2B_OPTIONS` is absent (as netfilter) | never | old mailcow releases |
| `F2B_WHITELIST` | hash (field = entry, value `1`) | allowlist, tag | settings change (`DEL` + `HSET`), allowlist action (`HSET`), denylist action (`HDEL`) | legacy |
| `F2B_BLACKLIST` | hash (field = entry, value `1`) | denylist, tag | settings change (`DEL` + `HSET`), denylist action (`HSET`), allowlist action (`HDEL`) | legacy |
| `F2B_REGEX` | string (JSON object `{"1": rule, …}`) | rules, tag | replace (`SET`), reset (`DEL`) | netfilter stores defaults when absent; legacy |
| `F2B_ACTIVE_BANS` | hash (network → end epoch) | active bans, ban action precondition | never | netfilter |
| `F2B_QUEUE_UNBAN` | hash (network → `1`) | `queuedForLifting` | lift and allowlist actions (`HSET`) | netfilter removes processed entries; legacy |
| `F2B_PERM_BANS` | hash (network → start epoch) | permanent bans | never | netfilter |

`F2B_OPTIONS` as written (legacy key order and types, R18):

```json
{"ban_time":1800,"ban_time_increment":true,"max_ban_time":10000,"netban_ipv4":32,
 "netban_ipv6":128,"max_attempts":10,"retry_window":600,
 "banlist_id":"3f2c…-uuid-v4","manage_external":0}
```

Mapping to `Fail2BanSettings`: `ban_time` → `banTime`, `max_ban_time` → `maxBanTime`,
`ban_time_increment` → `banTimeIncrement` (truthy), `max_attempts` → `maxAttempts`,
`retry_window` → `retryWindow`, `netban_ipv4` → `ipv4PrefixLength`, `netban_ipv6` →
`ipv6PrefixLength`, `manage_external` → `externallyEnforced` (`== 1`), `banlist_id` → `banListId`
and `banListUrl` = `https://<MAILCOW_HOSTNAME>/f2b-banlist?id=<banlist_id>`.

**Defaults** when nothing is stored (FR-044, netfilter `verifyF2boptions`): 1,800 / 10,000 / `true`
/ 10 / 600 / 32 / 128 / external `0`; `banListId` `null` until the ban service or the first save
generates a UUID v4; `stored: false`.

**Validation** (`UpdateFail2BanSettingsRequest` and `Fail2BanSettingsService`, R19), applied only
to sent values that differ from the stored ones:
- `banTime`: "a whole number of seconds from 60 to 2,147,483,647" (`outOfRange`, `min: 60`,
  `max: 2147483647`);
- `maxBanTime`: "from 60 to 2,147,483,647, and not less than the ban time" (`outOfRange` with
  `minField: banTime`, or `mustNotExceed` on `banTime` with `field: maxBanTime`);
- `maxAttempts`, `retryWindow`: "from 1 to 2,147,483,647" (`outOfRange`);
- `ipv4PrefixLength`: "from 8 to 32"; `ipv6PrefixLength`: "from 8 to 128" (`outOfRange`);
- `allowlist.<n>`: "an IPv4 or IPv6 address, a network in prefix notation, or a host name"
  (`invalidFormat`, `format: ipNetworkOrHostname`); `denylist.<n>`: "an address or a network in
  prefix notation" (`invalidFormat`, `format: ipNetwork`), "not `0.0.0.0`, `0.0.0.0/0`, `::/0`,
  `<IPV4_NETWORK>.0/24`, `<IPV4_NETWORK>.0` or `<IPV6_NETWORK>`" (`reserved`);
- "an entry on both lists MUST be refused" (`conflictsWith` on the denylist entry);
- lists: "at most 10,000 entries" (`tooMany`), entries "at most 253 characters" (`tooLong`),
  trimmed, empty refused (`required`), exact duplicates stored once;
- `null` for any member → `invalidType`; an empty patch → `required` on `body`.

**Version tags** (FR-057):
- settings: strong ETag, HMAC-SHA256 keyed with the app key (base64url, quoted; the values include
  the ban list ID, so no plain hash, `docs/conventions.md`), over the canonical JSON
  of the normalised values `{allowlist, banListId, banTime, banTimeIncrement, denylist,
  externallyEnforced, ipv4PrefixLength, ipv6PrefixLength, maxAttempts, maxBanTime, retryWindow}`
  with both lists sorted and unique. It changes when a value changes, whoever changes it, and not
  when netfilter re-stores the same values or when bans come and go;
- rules: strong ETag over the JSON list of patterns in numeric key order.

**Rules validation** (`ReplaceFail2BanRulesRequest`, `PythonRegexValidator`, R20):
`rules` "1 to 100 rules" (`required` when empty, `tooMany` with `max: 100`); `rules.<n>` "between 1
and 1,000 characters" (`tooShort`, `tooLong`), "compiles in Python `re`" (`invalidRegex`, param
`error`), "at least one capture group" (`missingCaptureGroup`). Stored as
`json_encode(['1' => r1, …], JSON_UNESCAPED_SLASHES)`.

**Atomicity** (FR-061, R18): every multi-key write is one `WATCH`/`MULTI`/`EXEC` transaction on one
connection, retried up to 5 times when a watched key changed.

---

## Redis (connection `restv2`, prefix `restv2:`), API-owned

| Key | Value | TTL | Owner |
|---|---|---|---|
| `restv2:monitoring:containers` | `{payload: list<ContainerSnapshot>, collectedAt}` (never `Config.Env`) | 10 s (served while ≤ 5 s old) | `SharedSnapshotCache` / `ContainerRegistry` |
| `restv2:monitoring:host` | `{payload: host_stats document, collectedAt}` | 15 s (served while ≤ 10 s old) | `HostOverviewService` |
| `restv2:monitoring:usage:<service>` | `{payload: ContainerResourceUsage, collectedAt}` | 10 s (served while ≤ 5 s old) | `ContainerResourceUsageService` |
| `restv2:monitoring:mail-storage` | `{payload: MailStorageUsage, collectedAt}` | 65 s (served while ≤ 60 s old) | `MailStorageService` |
| `restv2:update-check:<sha1(source)>:<version>` | `{status, latestRelease, installedReleaseNotes, checkedAt}` | 3,600 s after success, 300 s after failure | `UpdateCheckService` |
| `restv2:lock:monitoring:<key>`, `restv2:lock:update-check:<…>` | Laravel cache lock owner | loader timeout + 5 s | `SharedSnapshotCache` |

Container actions delete `restv2:monitoring:containers` and `restv2:monitoring:usage:<service>`.

---

## API-owned table used: `restv2_audit_events` (feature 001)

No migration. New values of the shared enumerations (contract):
- `AuditEventType`: `containerStart`, `containerStop`, `containerRestart`, `mailQueueDelivery`,
  `mailQueueHold`, `mailQueueRelease`, `mailQueueDeletion`, `mailQueueFlush`, `mailQueuePurge`,
  `mailQueueMessageView`, `fail2banSettingsUpdate`, `fail2banRulesReplacement`,
  `fail2banRulesReset`, `fail2banBanLift`, `fail2banBanAllowlisting`, `fail2banBanDenylisting`,
  `fail2banBanListIdRotation`;
- `AuditTarget.type`: `container`, `mailQueue`, `mailQueueMessage`, `fail2banSettings`,
  `fail2banRules`, `fail2banActiveBan`, `fail2banBanListId`;
- `AuditFailureReason`: `serviceFailure`, `rulesNotRestored` (plus the existing `conflict`,
  `notFound`, `versionOutdated`, `temporaryFailure`, `outcomeUnknown`).

Refusals of authenticated callers on the state-changing routes (role, read-only key, restricted
session, `422`, malformed `400`) are recorded by 008's `RefusalAuditor` through the route default
`restv2.audit` and 011's `MonitoringAuditTargetResolver`, with `permissionDenied`, `notFound` or
`validationFailed`. The `details` whitelist per event is in research R24. Content views record the queue ID only; ban
list rotations record no ID; settings changes record old and new values of each changed parameter
and the list entries added and removed.

---

## Container service (dockerapi) calls

| Purpose | Call | Payload / answer |
|---|---|---|
| Container list and state | `GET /containers/json?all=true` | map `Id → inspect`; filtered by `com.docker.compose.project` |
| Start, stop, restart | `POST /containers/{id}/start\|stop\|restart` | `{"type": "success"\|"danger", "msg"}` (always HTTP 200) |
| Resource samples | `POST /container/{id}/stats/update` (5 s timeout) | list of up to 3 raw `docker stats` samples |
| Host figures | `GET /host/stats` (5 s timeout) | `{cpu: {cores, usage}, memory: {total, usage, swap: [total, used, free, percent, sin, sout]}, uptime, system_time, architecture}` |
| Mail storage | `POST /containers/{dovecot}/exec` `{cmd: system, task: df, dir: /var/vmail}` | JSON string `"dev,size,used,avail,use%,mount"`, `"0,0,0,0,0,0"` or `null` |
| Queue list | `POST /containers/{postfix}/exec` `{cmd: mailq, task: list}` | text, one `postqueue -j` object per line |
| Message content | `… {cmd: mailq, task: cat, items: [id]}` | text (`postcat -q`) |
| Hold, release, delete, deliver | `… {cmd: mailq, task: hold\|unhold\|delete\|deliver, items: [ids]}` | `{"type", "msg"}` |
| Flush, delete all | `… {cmd: mailq, task: flush\|super_delete}` | `{"type", "msg"}` |
| Ban service restart (rule reset) | `POST /containers/{netfilter}/restart` | `{"type", "msg"}` |

Every call goes to `https://dockerapi:443` without certificate verification (as legacy), bounded by
`DOCKER_TIMEOUT` unless noted.

## Rspamd controller calls (socket `/var/lib/rspamd/rspamd.sock`)

| Purpose | Call | Fields used |
|---|---|---|
| History | `GET /history` | `rows[]`: `unix_time`, `ip`, `sender_smtp`, `sender_mime`, `rcpt_smtp`, `rcpt_mime`, `subject`, `action`, `score`, `required_score`, `symbols{name: {score, options}}`, `size`, `time_real`, `time_virtual`, `message-id`, `user` |
| Statistics | `GET /stat` | `scanned`, `learned`, `spam_count`, `ham_count`, `actions{}`, `connections`, `control_connections`, `statfiles[].{symbol, type, revision, users}`, `fuzzy_hashes{}` |

## External services (optional)

| Purpose | Call | When |
|---|---|---|
| Update check | `GET <source>/releases/latest`, `GET <source>/releases/tags/<installed>` (10 s) | update check enabled and installed branch = stable branch, cache miss |
| Public addresses | `GET http://ipv4.mailcow.email`, `GET http://ipv6.mailcow.email` (10 s connect, 15 s total, in parallel) | `IP_CHECK = 1`, on request |

---

## Value objects and state (no storage)

- `ContainerSnapshot`: `id`, `service`, `image`, `status`, `running`, `startedAt`, `health`.
- `Snapshot`: `payload`, `collectedAt` (shared cache entry).
- `LogQuery`: `type`, `limit`, `offset`, `order`, `q`, `from`, `to`, `before`, `severity[]`,
  `filters` (name → values).
- `MailQueueListing`: `messages` (≤ 10,000), `counts` per queue, `truncated`.
- `Fail2BanSettingsValues`: the normalised parameters, lists, `banListId`, `stored`.

### Container action state transitions

```
             start (≤ 4 attempts, 1.5 s apart)
 not running ───────────────────────────────► running
     ▲   │                                      │  │
     │   └── restart ──────────────────────────►│  │
     │                                          │  │ restart (stop + start)
     └───────── stop (≤ 4 attempts; refused ◄───┘  │
                for protected / own container)     ▼
                                                running (new startedAt)
 start on running, stop on not running → alreadyInState (no change)
 restart of the own container, stop of a protected one → 409 container-protected
 timeout → 504 outcome-unknown (state unknown; read again)
```

### Ban lifecycle (owned by netfilter; API actions in brackets)

```
 failed logins ≥ maxAttempts in retryWindow ──► active ban (F2B_ACTIVE_BANS)
 active ban ── [lift / allowlist] ──► queued for lifting (F2B_QUEUE_UNBAN) ── ≤ 10 s ──► lifted
 active ban ── end time passes ──────────────────────────────────────────────── ≤ 10 s ──► lifted
 [denylist] / denylist entry ── ≤ 60 s ──► permanent ban (F2B_PERM_BANS)
 denylist entry removed [settings] ── ≤ 60 s ──► permanent ban lifted
```
