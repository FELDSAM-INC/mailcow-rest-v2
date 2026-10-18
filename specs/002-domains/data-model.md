# Data Model: Domains

**Feature**: `specs/002-domains` | **Date**: 2026-10-18 | **Plan**: [plan.md](./plan.md)

Storage rules are feature 001's ([001 data model](../001-auth-sessions-api-keys/data-model.md)):
upstream tables are an external contract and are never migrated; every write goes through the
services named below; API-owned tables use `restv2_` and Laravel migrations; API-owned Redis keys
use `restv2:`. Column definitions are those of upstream `2026-09a` (`db_version 18082026_1200`,
`data/web/inc/init_db.inc.php`). JSON shapes are defined in the contract
(`api/components/schemas/Domain*.yaml`); this document maps them to storage.

**No new API-owned table.** One `restv2_` migration adds an index to 001's
`restv2_audit_events`. The feature writes no API-owned Redis key.

Upstream `DATETIME` values are local time (`TZ`); 001's `App\Casts\MailcowDateTime` emits UTC.
Writes leave `created` and `modified` to their database defaults, as legacy does.

---

## Upstream tables

Models live in `app/Models/Mailcow/` (connection `mysql`, explicit `$table` and `$primaryKey`,
`$timestamps = false`, `$guarded = ['*']`). Writes use the query builder inside the named service.

### Domain (`domain`): read by `DomainQuery`, written by `DomainCreator`, `DomainUpdater`, `DomainDeleter`

001's model `App\Models\Mailcow\Domain` is extended with every column and cast.

| Column | Type | Contract (`Domain`) | Cast and rules |
|---|---|---|---|
| `domain` | VARCHAR(255) PK | `name`, `unicodeName` (`idn_to_utf8`) | Lower-case ASCII; `DomainName::normalize()` |
| `description` | VARCHAR(255) NULL | `description` | `null` read as `''`; empty on write → the domain name; max 255 |
| `aliases` | INT(10) DEFAULT 0 | `maxAliases` | integer; write 0–2,147,483,647 |
| `mailboxes` | INT(10) DEFAULT 0 | `maxMailboxes` | integer; write 0–2,147,483,647 |
| `defquota` | BIGINT(20) DEFAULT 3072 | `defaultMailboxQuota` | `App\Casts\Mebibytes`: MiB × 1,048,576; write ≥ 1 MiB, whole MiB, ≤ `maxquota` |
| `maxquota` | BIGINT(20) DEFAULT 102400 | `maxMailboxQuota` | MiB ↔ bytes; write ≥ 1 MiB, whole MiB, ≤ `quota`, ≥ largest mailbox quota when changed |
| `quota` | BIGINT(20) DEFAULT 102400 | `domainQuota` | MiB ↔ bytes; write ≥ 1 MiB, whole MiB, ≥ quota allocated when changed |
| `relayhost` | VARCHAR(255) DEFAULT '0' | `relayhost` | `'0'`, empty or non-numeric → `null`; else `{id, hostname}` from `relayhosts`. Never written here except legacy's own value kept by the full-row `UPDATE` |
| `backupmx` | TINYINT(1) DEFAULT 0 | `backupMx` | boolean |
| `gal` | TINYINT(1) DEFAULT 1 | `globalAddressList` | boolean |
| `relay_all_recipients` | TINYINT(1) DEFAULT 0 | `relayAllRecipients` | boolean; implies `backupmx = 1` |
| `relay_unknown_only` | TINYINT(1) DEFAULT 0 | `relayUnknownOnly` | boolean; implies the other two |
| `created` | DATETIME DEFAULT NOW() | `createdAt` | `MailcowDateTime` |
| `modified` | DATETIME ON UPDATE | `modifiedAt` | `MailcowDateTime`, `null` when never modified |
| `active` | TINYINT(1) DEFAULT 1 | `active` | boolean |

**Writes**:

- Create: `INSERT INTO domain (domain, description, aliases, mailboxes, defquota, maxquota, quota,
  backupmx, gal, active, relay_unknown_only, relay_all_recipients) VALUES (…)` (legacy column list;
  `relayhost` keeps its default `'0'`).
- Update: `UPDATE domain SET relay_all_recipients, relay_unknown_only, backupmx, gal, active, quota,
  defquota, maxquota, mailboxes, aliases, description WHERE domain = ?` with the merged values
  (legacy's administrator statement without `relayhost`, which stays read-only).
- Delete: `DELETE FROM domain WHERE domain = ?` (cascades `tags_domain`).

**Relay normalisation** (FR-021, on create, edit and templates): `relay_unknown_only = 1` →
`relay_all_recipients = 1`; `relay_all_recipients = 1` → `backupmx = 1`.

**State**: `active` 1 ↔ 0 by edit or bulk action; deletion is final. An inactive domain stays in
scope for its domain administrators.

### DomainTag (`tags_domain`): written by `DomainTagSet` inside the domain transaction

| Column | Type | Rules |
|---|---|---|
| `tag_name` | VARCHAR(255) NOT NULL | Trimmed, 1–255 characters; unique per domain without regard to letter case (`utf8mb4_general_ci` unique key) |
| `domain` | VARCHAR(255) NOT NULL, FK → `domain.domain` ON DELETE CASCADE | — |

No primary key; the unique key is `(tag_name, domain)`. Replacement: `DELETE FROM tags_domain WHERE
domain = ?` then `INSERT INTO tags_domain (domain, tag_name) VALUES (?, ?)` per tag. Limit:
`MailcowConfig::taggingLimit()` (default 25); a set over the limit is accepted only when it equals
or is smaller than the current set.

**Distinct tags** (`listDomainTags`): `SELECT MIN(tag_name) name, COUNT(DISTINCT domain) domain_count
FROM tags_domain WHERE domain IN (<scope>) GROUP BY LOWER(tag_name)`.

### DomainTemplate (`templates`, `type = 'domain'`): written by `DomainTemplateService`

| Column | Type | Contract (`DomainTemplate`) |
|---|---|---|
| `id` | INT PK AUTO_INCREMENT | `id` |
| `template` | VARCHAR(255) NOT NULL | `name`; unique among `type = 'domain'` without regard to letter case; `isDefault` = `mb_strtolower(template) === 'default'` |
| `type` | VARCHAR(255) NOT NULL | always `'domain'`; rows of other types answer `404` |
| `attributes` | JSON | see below |
| `created` | DATETIME(0) DEFAULT NOW() | `createdAt` |
| `modified` | DATETIME ON UPDATE | `modifiedAt` |

`attributes` keys (legacy format, read tolerantly, written exactly):

| Key | Stored | Contract | Default (FR-046) |
|---|---|---|---|
| `tags` | array of strings | `tags` | `[]` |
| `max_num_aliases_for_domain` | int | `maxAliases` | 400 |
| `max_num_mboxes_for_domain` | int | `maxMailboxes` | 10 |
| `def_quota_for_mbox` | int **bytes** | `defaultMailboxQuota` | 3,221,225,472 (3,072 MiB) |
| `max_quota_for_mbox` | int bytes | `maxMailboxQuota` | 10,737,418,240 (10,240 MiB) |
| `max_quota_for_domain` | int bytes | `domainQuota` | 10,737,418,240 |
| `rl_value` | int, or `""` for none | `rateLimit.value` | `""` |
| `rl_frame` | `s`, `m`, `h`, `d` | `rateLimit.frame` | `s` |
| `active`, `gal` | int 0/1 | `active`, `globalAddressList` | 1, 1 |
| `backupmx`, `relay_all_recipients`, `relay_unknown_only` | int 0/1 | relay options | 0, 0, 0 |
| `dkim_selector` | string | `dkimSelector` | `config('restv2.dkim.default_selector')` |
| `key_size` | int | `dkimKeySize` | 2048 |

Writes: `INSERT INTO templates (type, template, attributes) VALUES ('domain', ?, ?)`; `UPDATE
templates SET template = ?, attributes = ? WHERE id = ? AND type = 'domain'`; `DELETE FROM
templates WHERE id = ? AND type = 'domain'`. Uniqueness check under `SELECT id, template FROM
templates WHERE type = 'domain' FOR UPDATE`. The Default row is seeded by mailcow, never by the
API; when it is missing, creation uses the defaults above (FR-022).

### DomainFooter (`domain_wide_footer`): written by `DomainFooterService` and `DomainDeleter`

| Column | Type | Contract (`DomainFooter`) |
|---|---|---|
| `domain` | VARCHAR(255) PK | `domain` |
| `html` | LONGTEXT NULL | `html` (`null` → `''`); ≤ 1,048,576 bytes on write |
| `plain` | LONGTEXT NULL | `plain` (`null` → `''`); ≤ 1,048,576 bytes on write |
| `mbox_exclude` | JSON NOT NULL DEFAULT '[]' | `excludedAddresses`; lower-case addresses of mailboxes or aliases in the domain or its alias domains |
| `alias_domain_exclude` | JSON NOT NULL DEFAULT '[]' | `excludedAliasDomains`; ASCII names of the domain's alias domains |
| `skip_replies` | TINYINT(1) DEFAULT 0 | `skipReplies` |

Replace: `DELETE FROM domain_wide_footer WHERE domain = ?` then `INSERT` (legacy), in one
transaction under the domain row lock. Deleted with its domain (FR-037). Read by mailcow's mail
filter (`data/conf/rspamd/dynmaps/footer.php`), which resolves alias domains to their target.

### Read-only upstream tables

| Table | Columns used | Use |
|---|---|---|
| `domain_admins` | `username`, `domain`, `active` | Scope (001); `domainAdmins` (all assignments of the domain, sorted); deleted per domain on deletion |
| `mailbox` | `username`, `domain`, `kind`, `quota` (bytes), `active` | Usage figures (`kind = ''` mailboxes, `kind <> ''` resources); deletion blockers; footer exclusions |
| `quota2` | `username`, `bytes`, `messages` | `storageUsed`, `messages` |
| `alias` | `address`, `domain` | Alias count; footer exclusions; deleted on deletion |
| `alias_domain` | `alias_domain`, `target_domain` | Alias-domain names answer `404`; counts; duplicate check at creation; removed on deletion |
| `relayhosts` | `id`, `hostname` | `relayhost.hostname` |
| `admin` | `username`, `superadmin` | Orphaned domain administrators (deleted on deletion) |

### Tables cleaned on deletion (written only by `DomainDeleter` and `AliasDomainCleanup`)

| Table | Statement (domain `d`) | Origin |
|---|---|---|
| `domain_admins` | `DELETE … WHERE domain = d` | legacy |
| `alias` | `DELETE … WHERE domain = d` | legacy |
| `sender_acl` | `DELETE … WHERE logged_in_as LIKE '%@d'` | legacy |
| `quota2`, `quota2replica`, `pushover` | `DELETE … WHERE username LIKE '%@d'` | legacy |
| `spamalias` | `DELETE … WHERE address LIKE '%@d'` | legacy |
| `filterconf` | `DELETE … WHERE object = d` | legacy |
| `mta_sts` | `DELETE … WHERE domain = d` | legacy |
| `bcc_maps` | `DELETE … WHERE local_dest IN ('d', '@d')` | FR-037 (legacy only `= 'd'`) |
| `domain_wide_footer` | `DELETE … WHERE domain = d` | FR-037 |
| `admin` | `DELETE … WHERE superadmin = 0 AND username NOT IN (SELECT username FROM domain_admins)` | legacy (`OrphanedDomainAdminRemover`) |
| `da_acl` | `DELETE … WHERE username NOT IN (SELECT username FROM domain_admins)` | legacy |
| `alias_domain` | `DELETE … WHERE target_domain = d` (after the per-alias-domain cleanup) | legacy |

Per alias domain `a` (`AliasDomainCleanup::deleteRows`, shared with feature 004 FR-045):
`sender_acl` with `send_as IN (SELECT address FROM alias WHERE domain = a)` or `send_as = '@a'`;
`alias` `WHERE domain = a`; `spamalias` `WHERE address LIKE '%@a'`; `bcc_maps` `WHERE local_dest IN
('a', '@a')`; `filterconf` `WHERE object = a`; `alias_domain` `WHERE alias_domain = a`.

`LIKE` patterns escape `%`, `_` and `\` in the name (domain names cannot contain them, but the
helper is shared).

### Creation side table

| Table | Statement | Origin |
|---|---|---|
| `sender_acl` | `DELETE … WHERE external = 1 AND send_as LIKE '%@d'` | legacy, before the insert |

### Groupware directory (`_sogo_static_view`): written by `SogoDirectory`

Full rebuild after every successful domain create, edit, delete or bulk request (unless
`SKIP_SOGO=y`): legacy's `INSERT … SELECT … ON DUPLICATE KEY UPDATE` over `mailbox` joined to the
views `grouped_mail_aliases`, `grouped_domain_alias_address` and `grouped_sender_acl_external`,
then `DELETE FROM _sogo_static_view WHERE c_uid NOT IN (SELECT username FROM mailbox WHERE active =
'1')`. Columns written: `c_uid`, `domain`, `c_name`, `c_password` (random `{SSHA256}` hash),
`c_cn`, `mail`, `aliases`, `ad_aliases`, `ext_acl`, `kind`, `multiple_bookings`.

---

## Redis (mailcow-owned keys, connection `mailcow`, database 0, no prefix)

Accessed through `App\Clients\SharedStore\SharedStore` (ping, `MULTI`/`EXEC`, compensation).

| Key | Type | Read | Written |
|---|---|---|---|
| `DOMAIN_MAP` | hash, field = domain, value `1` | — | `HSET` on create (in the transaction); `HDEL` of the domain and its alias domains on delete |
| `RL_VALUE` | hash, field = domain, value `"<n> / 1<s\|m\|h\|d>"` | `rateLimit` of every domain read | `HSET` after creation when a rate limit applies; `HDEL` of the domain and its alias domains on delete |
| `DKIM_SELECTORS` | hash, field = domain | key existence (create, preview) | `HSET` on key generation |
| `DKIM_PUB_KEYS` | hash, field = domain | key existence | `HSET` on key generation |
| `DKIM_PRIV_KEYS` | hash, field = `<selector>.<domain>` | — | `HSET` on key generation (never logged) |

DKIM keys are never deleted by this feature (FR-038).

---

## Outbound calls

| Service | Call | When | Client |
|---|---|---|---|
| dockerapi | `GET /containers/json`, then `POST /containers/<sogo-mailcow id>/restart` | Creation with `restartGroupware: true` and the groupware enabled | `DockerApiClient::restart()` |
| dockerapi | `POST /containers/<dovecot-mailcow id>/exec` with `{"cmd":"maildir","task":"cleanup","maildir":"<d>"}` | After each committed deletion | `DockerApiClient::exec()` |
| memcached | `flush_all` | After each groupware directory rebuild | `MemcachedFlusher` |
| ISPConfig JSON remote API | `login`, `dns_zone_get` (`origin = "<d>."`), `dns_rr_get_all_by_zone`, `dns_txt_update` or `dns_txt_add`, `logout` | After a DKIM key was generated, when the publication is enabled | `IspconfigDnsClient` |

No Rspamd controller or doveadm call: Rspamd reads `DOMAIN_MAP`, `RL_VALUE` and the DKIM hashes
from Redis directly, and Postfix and Dovecot read the domain tables directly.

---

## API-owned storage

| Object | Change |
|---|---|
| `restv2_audit_events` | Migration `database/migrations/2026_10_19_000001_add_target_index_to_restv2_audit_events.php`: index `(target_type, target_id, occurred_at)` for `targetType`/`targetId` filters |

**Audit record content** (001's table; `details` keys whitelisted per event type):

| `eventType` | `target` | `details` |
|---|---|---|
| `domainCreation` | `domain`, ASCII name, Unicode name | `values` (all stored attributes), `templateId`, `creationReport` (`"pending"` until completed) |
| `domainUpdate` | `domain` | `changes` (`{attribute: {old, new}}`, tags as sorted lists; empty for an unchanged edit, which is audited too), `bulkRequestId` |
| `domainDeletion` | `domain` | `removedAliasDomains`, `removedDomainAdmins`, `footerRemoved`, `maildirCleanup` (`"pending"`, then `done`/`failed`/`unknown` with a message), `bulkRequestId` |
| `domainFooterUpdate` | `domainFooter`, ASCII name | `changes` (`html`/`plain` as `{changed, oldSize, newSize}`, `skipReplies`, exclusions old/new) |
| `domainTemplateCreation`, `domainTemplateUpdate`, `domainTemplateDeletion` | `domainTemplate`, ID, name | `values` or `changes` |
| any of the above, `outcome: failure` | the addressed object | `problemType`, `errors` (field → codes), `bulkRequestId`; `failureReason` one of `attributeNotPermitted`, `versionOutdated`, `validationFailed`, `conflict`, `temporaryFailure` |

---

## Value objects (no storage)

- `App\Support\Domain\DomainName`: `normalize(string): ?string`, `isValid(string): bool`,
  `toUnicode(string): string`.
- `App\Services\Domains\DomainUsage`: the figures of `DomainUsage`.
- `App\Services\Domains\DomainCreationReport`: the five step outcomes and messages.
- `App\Services\Domains\DomainDeletionPlan`: blockers, alias domains, alias count and sample,
  footer flag, domain administrators to delete, DKIM flag, version tag.
- `App\Clients\DockerApi\DockerCallResult`: `success | failed(message) | unknown`.
- `DomainCapabilities` (`GET /capabilities/domains`): computed on every request from
  `config('restv2.*')` and `MailcowConfig` (research D23); no storage.
- `App\Services\Dkim\DkimDnsOutcome` enum: `published`, `noZone`, `failed`, `notConfigured`,
  `skipped` (feature 010 adds `removed`, `noRecordToRemove`).
