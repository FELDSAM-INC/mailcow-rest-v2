# Data Model: Quarantine, Spam Policy and Rate Limits

**Feature**: `specs/006-quarantine-spam-policy` | **Date**: 2026-10-18 | **Plan**: [plan.md](./plan.md)

Feature 006 owns **no table and no migration**. Everything it reads and writes is existing mailcow
data: upstream tables (never migrated, written only through the services named below), mailcow's
Redis keys, the Rspamd controller and Postfix. Its only API-owned state is a short-lived lock key
per quarantine item in Redis (`restv2:` prefix). Column definitions are quoted from the upstream
`2026-09a` schema (`db_version 18082026_1200`, `init_db.inc.php`). The JSON shapes are defined in
the contract (`api/components/schemas/`); this document maps them to storage.

Upstream `DATETIME` columns hold mailcow's local time (`TZ`) and are read with 001's
`MailcowDateTime` cast; inserts leave them to their `NOW()` defaults.

---

## Upstream tables

### QuarantineItem (`quarantine`): read, deleted by `QuarantineActionService` only

Model `App\Models\Mailcow\QuarantineItem` (`$table = 'quarantine'`, `$primaryKey = 'id'`,
`$timestamps = false`, `$guarded = ['*']`). The model never selects `msg` by default
(`$hidden`/explicit column lists), so that lists never load message bodies.

| Column | Type | Contract (`QuarantineItem`, `QuarantineItemDetails`) |
|---|---|---|
| `id` | INT AUTO_INCREMENT PK | `id` |
| `qid` | VARCHAR(30) NOT NULL | `queueId` |
| `subject` | VARCHAR(500) NULL | `subject` (`""` when NULL) |
| `score` | FLOAT(8,2) NULL | `score` |
| `ip` | VARCHAR(50) NULL | `sourceAddress` |
| `action` | CHAR(20) NOT NULL DEFAULT 'unknown' | `filterAction`; `action`: `reject` → `rejected`, `add header` → `junk`, `rewrite subject` → `subjectRewritten`, else `other` |
| `symbols` | JSON | `symbols` (`name`, `score` or 0, `options` or `[]`); `virus` = `LOCATE('VIRUS_FOUND', symbols) > 0` |
| `fuzzy_hashes` | JSON | `fuzzyHashes` |
| `sender` | VARCHAR(255) NOT NULL DEFAULT 'unknown' | `sender` |
| `rcpt` | VARCHAR(255) NULL | `recipient`; scope key (R15) |
| `msg` | LONGTEXT | never returned; `size` = `LENGTH(msg)`; parsed into `content` (R5); downloads; releases; learning |
| `domain` | VARCHAR(255) NULL | not used (the ingestion leaves it NULL) |
| `notified` | TINYINT(1) DEFAULT 0 | `notified`; never written by the API (FR-028) |
| `created` | DATETIME(0) DEFAULT NOW(0) | `arrivedAt` (UTC) |
| `user` | VARCHAR(255) NOT NULL DEFAULT 'unknown' | `authenticatedSender`, administrators only (`unknown` or empty → `null`) |
| `qhash` | VARCHAR(64) NULL, KEY | never returned, never logged (FR-015) |

**Writes**: `DELETE FROM quarantine WHERE id = ?` only (release, learning as spam, deletion).
No insert or update.

**Lifecycle** (per item, through this API):

```
            ingestion (pipe.php, legacy)
                     │
                     ▼
               ┌──────────┐  notifier sets notified = 1 (legacy)
               │ present  │◄──────────────────────────────┐
               └────┬─────┘                               │
   lock acquired    │ (restv2:quarantine-item:<id>)       │ failure before removal: delivery
                    ▼                                     │ refused/unreachable, spam learning
               ┌──────────┐ ─────────────────────────────►┘ failed, audit failed (spam, delete)
               │processing│   other requests: 409 quarantine-item-busy
               └────┬─────┘
                    │ release: delivered → DELETE;  learn as spam: learned → DELETE;  delete: DELETE
                    ▼
                 removed   (404 afterwards; also removed by legacy UI, quick links, retention, daily cleanup)
```

**Version tag**: strong ETag over `id|qid|rcpt|created`. Unchanged while the item exists.

### FilterPreference (`filterconf`): allow/block entries and spam scores

Model `App\Models\Mailcow\FilterPreference` (`$table = 'filterconf'`, `$primaryKey = 'prefid'`).
Writes: `SpamPolicyEntryService` (list entries) and `SpamScoreService` (scores) only.

| Column | Type | Use |
|---|---|---|
| `object` | VARCHAR(255) NOT NULL DEFAULT '', KEY | Owner: domain or alias domain (ASCII) or mailbox username |
| `option` | VARCHAR(50) NOT NULL DEFAULT '' | `whitelist_from` (allow), `blacklist_from` (block), `lowspamlevel` (junk score), `highspamlevel` (reject score) |
| `value` | VARCHAR(100) NOT NULL DEFAULT '' | Pattern, or score as decimal text |
| `created` | DATETIME(0) DEFAULT NOW(0) | `SpamPolicyEntry.createdAt` |
| `modified` | DATETIME ON UPDATE | not used |
| `prefid` | INT(11) AUTO_INCREMENT PK | `SpamPolicyEntry.id` |

**List entries** (`SpamPolicyEntry`):

- `list`: `whitelist_from` ↔ `allow`, `blacklist_from` ↔ `block`.
- `owner.kind`: `mailbox` when `object` contains `@`; otherwise `domain` or `aliasDomain` (looked up
  in `alias_domain`; in a domain view the own entries are `domain`, in an alias-domain view
  `aliasDomain`).
- `own`: `object` equals the owner in the path.
- Views (legacy `policy('get')`):
  - domain `d`: `option IN (…) AND (object = d OR object LIKE '%@d')`;
  - alias domain `a`: `option IN (…) AND (object = a OR object LIKE '%@a')`;
  - mailbox `m` of domain `d`: `option IN (…) AND (object = m OR object = d)`.
- Validation of a new pattern (FR-046), after `trim` and lower-casing: `^[a-z0-9@_.*-]+$`, at
  least one `[a-z0-9]`, at most 100 characters (`invalidFormat` with `format: senderPattern`,
  `tooLong` with `max: 100`). `list`: `notInList`.
- Uniqueness (FR-047): no row with the same `object` and `value` and `option IN
  ('whitelist_from','blacklist_from')`; otherwise `409 spam-policy-entry-exists` (`list`,
  `entryId` of the existing row). Check and insert run under `GET_LOCK('restv2:filterconf:<object>',
  10)` (conventions; no index is added to the upstream table).
- Insert: `INSERT INTO filterconf (object, option, value) VALUES (?, ?, ?)`. Delete:
  `DELETE FROM filterconf WHERE prefid = ? AND object = ? AND option IN (…)`.
- **Version tag**: strong ETag over `prefid|object|option|value`.

**Spam score** (`SpamScore`):

- Rows: `object = <username>`, `option` `lowspamlevel` (junk) and `highspamlevel` (reject).
- Read: custom when at least one row exists; a missing row takes the default.
- Defaults: Rspamd `GET /actions`: `add header` → junk, `reject` → reject; missing, `null` or `0`,
  or a controller failure → 5 and 15 with `fallback: true`.
- Validation (FR-040): each in [0, 5000] (`outOfRange`), at most one decimal (`notMultipleOf`,
  `factor: 0.1`), junk ≤ reject (`mustNotExceed`, `field: rejectScore`, `value`). Equal values →
  reject + 0.1.
- Set: `DELETE … option IN ('lowspamlevel','highspamlevel')`, `INSERT … 'highspamlevel'`,
  `INSERT … 'lowspamlevel'` (legacy order); values as the shortest decimal text (`12`, `6.5`).
- Reset: the same `DELETE`.
- **Version tag**: strong ETag over `username|low|high` (raw values, absence distinct).

### Mailbox (`mailbox`): read; `attributes` written by `QuarantineNotificationService` only

| Column | Type | Use |
|---|---|---|
| `username` | VARCHAR(255) PK | Mailbox identity, lists, scores, rate limits |
| `domain` | VARCHAR(255) | Scope; inherited domain list entries; domain rate limit fallback |
| `kind` | VARCHAR(100) | `location|thing|group` → resource → `404` for mailbox-level operations (FR-069) |
| `attributes` | JSON | `quarantine_notification` (`never`, `hourly`, `daily`, `weekly`), `quarantine_category` (`reject`, `add_header`, `all`) |

**Notification settings** (`QuarantineNotificationSettings`):

- `frequency`: stored value if in the enum, else `never`. `category`: `reject` → `rejected`,
  `add_header` → `junk`, `all` → `all`, else `null`.
- Write (legacy statement): `UPDATE mailbox SET attributes = JSON_SET(attributes,
  '$.quarantine_notification', ?) WHERE username = ?` and/or the same for `'$.quarantine_category'`.
- Validation: `frequency` and `category` `notInList`; at least one member (`required` on `body`).
- **Version tag**: strong ETag over `username|<raw quarantine_notification>|<raw quarantine_category>`.

### Read-only tables

| Table | Use |
|---|---|
| `domain` (`domain`, `active`) | Existence of domains (excluded domains, owners, rate limit objects); row lock for list entry creation |
| `alias_domain` (`alias_domain`, `target_domain`, `active`) | Existence and scope (through `target_domain`) of alias domains |
| `domain_admins`, `admin`, `user_acl`, `da_acl` | Scope and effective flags, through 001's `IdentityService` |

---

## Redis (connection `mailcow`, database 0, no prefix)

| Key | Type | Access | Format and use |
|---|---|---|---|
| `Q_RETENTION_SIZE` | string | read, write | integer; `retentionPerMailbox` |
| `Q_MAX_SIZE` | string | read, write | integer **MiB**; `maxMessageSize` = × 1048576 |
| `Q_MAX_SCORE` | string | read, write | decimal or `""`; `notificationScoreLimit` (`""` → `null`) |
| `Q_MAX_AGE` | string | read, write | integer days; unset/≤ 0 → 365 |
| `Q_EXCLUDE_DOMAINS` | string | read, write | JSON list of domain names (`json_encode`) |
| `Q_RELEASE_FORMAT` | string | read, write | `raw` ↔ `original`, `attachment`; unset → `original` |
| `Q_SENDER` | string | read, write | address or `""` (→ `quarantine@localhost`) |
| `Q_SUBJ` | string | read, write | text or `""` (→ "Spam Quarantine Notification") |
| `Q_BCC` | string | read, write | address or `""` (`copyRecipient`) |
| `Q_REDIRECT` | string | read, write | address or `""` (`redirectRecipient`) |
| `Q_HTML` | string | read, write | template or `""` (→ default template file) |
| `RL_VALUE` | hash | read, write | field = object (lower case, ASCII domain), value `<n> / 1<s|m|h|d>` |
| `RL_LOG` | list | read, `LREM` | JSON lines from `pipe_rl.php` (`time`, `rcpt`, `from`, `user`, `rl_info`, `rl_name`, `rl_hash`, `qid`, `ip`, `message_id`, `header_subject`, `header_from`) |
| `RL…` (bucket) | hash | `TYPE`, `DEL` | Rspamd's ratelimit bucket, named as `rl_hash` |
| `Q_LAST_NOTIFIED` | hash | never touched | the notifier's last notification times (FR-038) |

**Quarantine settings** (`QuarantineSettings`): validation (FR-030) in
`UpdateQuarantineSettingsRequest`:

| Member | Rule (rule code) |
|---|---|
| `retentionPerMailbox` | integer 0–2147483647 (`invalidType`, `outOfRange`) |
| `maxMessageSize` | integer 0–2251799812636672, multiple of 1048576 (`outOfRange`, `notMultipleOf` `factor: 1048576`) |
| `notificationScoreLimit` | number or `null` (`invalidType`) |
| `maxAgeDays` | integer 1–2147483647 (`outOfRange`) |
| `excludedDomains` | list ≤ 10000; each normalised (002), valid name (`invalidFormat` `domainName`), an existing domain or alias domain or an already stored entry (`notFound`); deduplicated |
| `releaseFormat` | `original` or `attachment` (`notInList`) |
| `notificationSender`, `copyRecipient`, `redirectRecipient` | `null`, `""` or a valid address of ≤ 255 characters (`invalidFormat` `email`, `tooLong`) |
| `notificationSubject` | `null`, `""` or ≤ 255 characters (`tooLong`) |
| `notificationTemplate` | `null`, `""` or ≤ 1048576 bytes (`tooLong` `unit: bytes`) and passes the template check (`invalidTemplate` `reason`, `line`) |
| body | at least one member (`required` on `body`) |

**Version tag**: strong ETag over the eleven raw values in the table's order (`null` distinct from
`""`).

**Rate limits** (`RateLimit`):

- Parse `^([1-9][0-9]{0,9}) / 1([smhd])$` (value ≤ 2147483647) → `limit {value, frame}`
  (`s|m|h|d` ↔ `second|minute|hour|day`); else `limit: null`, `invalidStoredValue` = raw text.
- Mailbox: `effectiveLimit`/`effectiveSource` = own (`mailbox`), else `RL_VALUE[mailbox.domain]`
  (`domain`), else `none`.
- Validation (FR-053): `value` integer 1–2147483647 (`outOfRange`), `frame` (`notInList`).
- **Version tag**: strong ETag over `object|<raw field value or absent>`.

**Bucket reset**: identifier `^RL[0-9A-Za-z=]+$`, at most 128 characters; Lua script (research
R13) returns `countersExisted` and `logEntriesRemoved`.

## Redis (connection `restv2`, prefix `restv2:`): API-owned

| Key | Value | TTL | Owner |
|---|---|---|---|
| `restv2:quarantine-item:<id>` | lock owner token (Laravel cache lock) | 300 s, released when the action ends | `QuarantineActionService` |

---

## Outbound calls

### Rspamd controller (`App\Clients\Rspamd\RspamdController`, Unix socket, 30 s)

| Call | Request | Used by |
|---|---|---|
| `learnHam` | `POST /learnham`, `Content-Type: text/plain`, body = `msg` | release with learning |
| `learnSpam` | `POST /learnspam`, body = `msg` | learning as spam |
| `fuzzyAdd(13)` | `POST /fuzzyadd`, `Flag: 13`, body = `msg` | release with learning, after `learnHam` succeeded or was already learned |
| `fuzzyAdd(11)` | `POST /fuzzyadd`, `Flag: 11`, body = `msg` | learning as spam, after `learnSpam` succeeded or was already learned |
| `actions` | `GET /actions` → `[{action, value}]` | spam score defaults |
| `stat` | `GET /stat` → `{actions: {...}, uptime, ...}` | action statistics |

Release with learning: `learnHam` failing (not "already learned") → `learning: failed`,
`fuzzy: notRequested`, no fuzzy call (legacy `continue`s after a failed ham learning).

### Postfix (`App\Clients\Mail\QuarantineReleaseMailer`, `postfix:590`, no TLS, 30 s)

`EHLO quarantine`, `MAIL FROM:<sender or sender-unknown@rspamd>`, `RCPT TO:<rcpt>`, `DATA` with the
`original` message (`X-Spam-Flag` renamed, CRLF) or the `attachment` wrapper. `250` → delivered;
`5xx` → refused; connection failure, timeout, `4xx` → temporary failure.

### Template renderer (`QuarantineTemplateRenderer`, subprocess, 5 s)

`python3 -I -B resources/bin/quarantine-template.py`, JSON on stdin (`template`, `datasets`), JSON
on stdout (`ok`, `renders[{html, text}]` or `reason`, `line`, `message`).

### Files

| Path | Use |
|---|---|
| `/mailcow/assets/templates/quarantine.tpl` (mount of `./data/assets/templates`) | Default notification template (legacy `/tpls/quarantine.tpl`) |
| `resources/legacy/quarantine.tpl` | Bundled `2026-09a` copy, used only when the mount is missing |
| `resources/legacy/quarantine-release-strings.json` | `release_subject`/`release_body` per language from `2026-09a` |

---

## Audit records (001's `restv2_audit_events`)

| `eventType` | `target.type` / `id` / `name` | `details` keys |
|---|---|---|
| `quarantineItemRelease` | `quarantineItem` / item id / queue id | `queueId`, `recipient`, `sender`, `action` (`release`/`releaseWithoutLearning`), `steps` {`delivery`, `learning`, `fuzzy`}, `bulkRequestId` |
| `quarantineItemSpamLearning` | same | `queueId`, `recipient`, `sender`, `action`, `steps`, `bulkRequestId` |
| `quarantineItemDeletion` | same | `queueId`, `recipient`, `sender`, `bulkRequestId` |
| `quarantineSettingsUpdate` | `quarantineSettings` / null / null | `changes` {field: {old, new}}; `notificationTemplate` as {`changed`, `oldSize`, `newSize`} |
| `quarantineNotificationSettingsUpdate` | `quarantineNotificationSettings` / username / username | `changes`, `bulkRequestId` |
| `spamScoreUpdate`, `spamScoreReset` | `spamScore` / username / username | `changes` {`junkScore`, `rejectScore`} |
| `spamPolicyEntryCreation`, `spamPolicyEntryRemoval` | `spamPolicyEntry` / prefid / pattern | `owner`, `ownerKind`, `list`, `pattern` |
| `rateLimitUpdate`, `rateLimitRemoval` | `rateLimit` / object / object | `kind`, `old`, `new` |
| `rateLimitBucketReset` | `rateLimitBucket` / bucket id / bucket id | `countersExisted`, `logEntriesRemoved` |

Failures (every refusal of an authenticated caller on a state-changing route, research R19) carry
`outcome: failure`, a shared `failureReason` (`permissionDenied`, `notFound`, `versionOutdated`,
`validationFailed`, `conflict`, `serviceFailure` for a refused delivery, `temporaryFailure`) and
`details.problemType`; `422` records list the invalid field names only.
Never recorded: subjects, bodies, attachments, `qhash`, template text.

---

## Value objects (no storage)

- `App\Services\Quarantine\ParsedMessage` (`from`, `subject`, `recipients`, `textPlain`,
  `textFromHtml`, `parserFallback`, `attachments[]` with content kept in memory for downloads).
- `App\Services\Quarantine\ActionReport` (`delivery`, `learning`, `fuzzy`, errors).
- `App\Clients\Rspamd\LearnOutcome` (`done`, `alreadyLearned`, `noFuzzyContent`, `failed` + text).
- `App\Clients\Mail\DeliveryOutcome` (`accepted`, `refused` + code/reply, `temporaryFailure` + reason).
- `App\Services\Quarantine\TemplateCheckResult` (`ok`, `reason`, `line`, `message`, renders).
- `App\Services\Quarantine\QuarantineCapabilities` (computed from configuration, FR-072).
