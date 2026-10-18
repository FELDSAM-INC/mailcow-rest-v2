# Data Model: Aliases, Alias Domains, Resources and Sender Permissions

**Feature**: `specs/004-aliases-alias-domains-resources` | **Date**: 2026-10-18 | **Plan**: [plan.md](./plan.md)

This feature owns no storage of its own. It reads and writes upstream mailcow tables (never
migrated; constitution III), writes mailcow-owned Redis hashes, flushes memcached, and writes
feature 001's audit log. **It adds no API-owned table, no Laravel migration and no `restv2:` Redis
key.** The JSON shapes are defined in the contract (`api/components/schemas/`); this document maps
them to storage. Column definitions are quoted from the schema fixture of upstream `2026-09a`
(`db_version 18082026_1200`). Date and time handling follows 001: upstream `DATETIME` columns hold
mailcow's local time and are read through `App\Casts\MailcowDateTime`; writes leave `created` and
`modified` to their database defaults.

All models live in `app/Models/Mailcow/`, with `$connection = 'mysql'`, an explicit `$table` and
`$primaryKey`, `$timestamps = false` and `$guarded = ['*']`. Writes go only through the services
named below (constitution IV), mostly with the query builder inside those services.

---

## Upstream tables

### Alias (`alias`): written by `App\Services\Aliases\{AliasCreator, AliasUpdater, AliasDeleter}` and 002's `AliasDomainCleanup`

| Column | Type | Contract (`Alias`) | Written |
|---|---|---|---|
| `id` | INT AUTO_INCREMENT PK | `id` | by the database |
| `address` | VARCHAR(255) NOT NULL, UNIQUE (case-insensitive collation) | `address`, `addressUnicode`, `kind` (`@…` → `catchAll`) | normalised address (research R3) |
| `goto` | TEXT NOT NULL | `destinationType`, `destinations` | comma-joined destinations, or `null@localhost` / `spam@localhost` / `ham@localhost` |
| `domain` | VARCHAR(255) NOT NULL, INDEX | `domain`, `domainUnicode`, `inAliasDomain`, `primaryDomain*`, `orphaned` | domain part of `address` |
| `created` | DATETIME(0) DEFAULT NOW(0) | `createdAt` | default |
| `modified` | DATETIME ON UPDATE CURRENT_TIMESTAMP | `modifiedAt` (`null` when never modified) | by the database |
| `private_comment` | TEXT NULL | `privateComment` (`null` → `""`) | as sent |
| `public_comment` | TEXT NULL | `publicComment` (`null` → `""`) | as sent |
| `sogo_visible` | TINYINT(1) DEFAULT 1 | `groupwareVisible` | 0/1 |
| `internal` | TINYINT(1) DEFAULT 0 | `internal` | 0/1 |
| `sender_allowed` | TINYINT(1) DEFAULT 1 | `senderAllowed` | 0/1 |
| `active` | TINYINT(1) DEFAULT 1 | `active` (any non-zero value reads as `true`; Postfix accepts `1` and `2`) | 0/1 |

Model: feature 002's `App\Models\Mailcow\Alias`, to which this feature adds the local scope `notOwnAlias`:
`NOT EXISTS (SELECT 1 FROM mailbox m WHERE m.username = alias.address)` (research R2). Derived
values:

- `primaryDomain` = `alias_domain.target_domain` when `alias.domain` is an alias domain, else
  `alias.domain`.
- `inAliasDomain` = an `alias_domain` row exists for `alias.domain`.
- `orphaned` = no `domain` row and no `alias_domain` row exists for `alias.domain`.

**Statements**:

- Create: `INSERT INTO alias (address, public_comment, private_comment, goto, domain, sogo_visible, internal, sender_allowed, active) VALUES (…)` (legacy's column list).
- Change: `UPDATE alias SET address, public_comment, private_comment, domain, goto, sogo_visible, internal, sender_allowed, active WHERE id = ?`, after `SELECT … FOR UPDATE`.
- Delete: `DELETE FROM alias WHERE id = ?`.
- Bulk flags: the same `UPDATE` with only `active` or `sogo_visible` changed.

**Validation** (contract `AliasCreateRequest`, `AliasPatch`; codes from `ValidationRuleCode`):

| Field | Rule | Code and params |
|---|---|---|
| `address` | required on creation; ≤ 255 characters | `required`; `tooLong` `{max: 255}` |
| `address` | valid address (`FILTER_VALIDATE_EMAIL`) or `@` + valid domain name, after normalisation | `invalidFormat` `{format: aliasAddress}` |
| `address` | domain is an existing domain or alias domain in the caller's scope | `notFound` `{resource: domain}` |
| `address` | not in use (R7) | `409 address-in-use` |
| `destinationType` | one of the four values | `notInList` |
| `destinations` | present (≥ 1) when the result is `addresses` | `required` |
| `destinations` | absent when `destinationType` is a special action | `conflictsWith` `{field: destinationType}` |
| `destinations.N` | valid address after normalisation | `invalidFormat` `{format: email}` |
| `destinations.N` | differs from the resulting `address` | `conflictsWith` `{field: address, value}` |
| `destinations.N` | not a resource address | `notEligible` `{kind: resource}` |
| `destinations` | joined length ≤ 65,535 | `tooLong` `{max: 65535, unit: characters}` |
| added external destinations | server setting off | `notEligible` `{kind: externalDestination}` on `destinations.N` |
| added external destinations of a domain administrator | `aliasExternalGoto` | `403 attribute-not-permitted` `{attributes: [destinations.N], requiredFlags: {destinations.N: aliasExternalGoto}}` |
| `publicComment`, `privateComment` | ≤ 160 bytes UTF-8, checked only when the value changes | `tooLong` `{max: 160, unit: bytes}` |
| read-only members | equal to the current (or resulting) value; `etag` ignored | `immutable` |
| primary domain | aliases in use + added ≤ `domain.aliases` | `409 domain-alias-limit-reached` |
| orphaned alias | a change must move it to an existing domain | `notFound` `{resource: domain}` on `address` |

**State**: an alias has no lifecycle beyond its flags: `active` 1 ↔ 0 (mail accepted or not),
`sender_allowed`, `sogo_visible` and `internal` independently. Deletion is final.

### AliasDomain (`alias_domain`): written by `App\Services\AliasDomains\{AliasDomainCreator, AliasDomainUpdater, AliasDomainDeleter}` and 002's `AliasDomainCleanup`

Model: feature 002's `App\Models\Mailcow\AliasDomain`.

| Column | Type | Contract (`AliasDomain`) |
|---|---|---|
| `alias_domain` | VARCHAR(255) PK | `name`, `nameUnicode` |
| `target_domain` | VARCHAR(255) NOT NULL, INDEX | `targetDomain`, `targetDomainUnicode` |
| `created` | DATETIME(0) DEFAULT NOW(0) | `createdAt` |
| `modified` | DATETIME ON UPDATE | `modifiedAt` |
| `active` | TINYINT(1) DEFAULT 1, INDEX | `active` |

Derived: `targetIsRelayDomain` = `domain.backupmx = 1` of the target; `aliasCount` =
`COUNT(*) FROM alias WHERE domain = alias_domain` (own-alias rows cannot live in an alias domain);
`rateLimit` = Redis `RL_VALUE[alias_domain]` parsed as `<value> / 1<frame>` with frames
`s`→`second`, `m`→`minute`, `h`→`hour`, `d`→`day` (`null` when absent).

**Statements**: `INSERT INTO alias_domain (alias_domain, target_domain, active)`;
`UPDATE alias_domain SET target_domain = ?, active = ? WHERE alias_domain = ?`; deletion through
002's `AliasDomainCleanup::deleteRows()` (below).

**Validation** (`AliasDomainCreateRequest`, `AliasDomainPatch`):

| Field | Rule | Code and params |
|---|---|---|
| `name` | required; valid domain name after normalisation (labels ≤ 63, total ≤ 253) | `required`; `invalidFormat` `{format: domainName}` |
| `name` | not the server's host name (`MAILCOW_HOSTNAME`, case-insensitive) | `reserved` |
| `name` | differs from `targetDomain` | `conflictsWith` `{field: targetDomain, value}` |
| `name` | not a `domain` and not an `alias_domain` | `409 alias-domain-already-exists` `{existingKind}` |
| `targetDomain` | an existing `domain` in the caller's scope | `notFound` `{resource: domain}` |
| `targetDomain` | not an alias domain; not a relay domain (`backupmx = 1`) | `notEligible` `{kind: aliasDomain}` / `{kind: relayDomain}` |
| `rateLimit.value` | 1 to 2,147,483,647 | `outOfRange` `{min: 1, max: 2147483647}` |
| `rateLimit.frame` | `second`, `minute`, `hour`, `day` | `notInList` |
| `dkimSelector` | letters, digits, `-`, `_`, `.`; ≤ 255; `""` = no key | `invalidFormat` `{format: dkimSelector}`; `tooLong` |
| `dkimKeySize` | 1024, 2048, 3072, 4096 | `notInList` |
| creation by a domain administrator | `aliasDomains` | `403 permission-flag-required` `{requiredFlags: [aliasDomains]}` |
| `rateLimit` from a domain administrator | `ratelimit` | `403 attribute-not-permitted` `{attributes: [rateLimit], requiredFlags: {rateLimit: ratelimit}}` |
| target change | aliases in use of the new target + `aliasCount` ≤ its limit | `409 domain-alias-limit-reached` |
| read-only members on change (`name`, `nameUnicode`, `targetDomainUnicode`, `targetIsRelayDomain`, `aliasCount`, `rateLimit`, `createdAt`, `modifiedAt`) | equal to current or resulting value; `etag` ignored | `immutable` |

### Resource (`mailbox` rows with `kind` in `location|thing|group`): written by `App\Services\Resources\{ResourceCreator, ResourceUpdater, ResourceDeleter}`

| Column | Type | Contract (`Resource`) | Written at creation |
|---|---|---|---|
| `username` | VARCHAR(255) PK | `address`, `addressUnicode` | derived address (R13) |
| `password` | VARCHAR(255) | — | `'RESOURCE'` |
| `name` | VARCHAR(255) | `description` | trimmed description |
| `quota` | BIGINT | — | `0` |
| `local_part` | VARCHAR(255) | — | derived local part |
| `domain` | VARCHAR(255) | `domain`, `domainUnicode` | normalised domain |
| `kind` | VARCHAR(100) | `kind` | `location` / `thing` / `group` |
| `multiple_bookings` | INT DEFAULT -1 | `bookingRule`, `bookingLimit` | `-1`, `0` or N |
| `active` | TINYINT(1) | `active` | 0/1 |
| `created`, `modified` | DATETIME | `createdAt`, `modifiedAt` | defaults |
| `attributes`, `custom_attributes`, `authsource`, `description`, `mailbox_path_prefix` | — | — | defaults (legacy writes none of them) |

Model `App\Models\Mailcow\Resource` on table `mailbox` with a global scope
`kind REGEXP 'location|thing|group'`. Booking rule mapping: `0` → `alwaysFree`; `< 0` →
`unlimited` (writes store `-1`); `N > 0` → `limited` with `bookingLimit = N`.

**Statements**: legacy's `INSERT` (research R13); `UPDATE mailbox SET active, name, kind, multiple_bookings WHERE username = ? AND kind REGEXP 'location|thing|group'`;
deletion of the `mailbox` row and the groupware rows below.

**Validation** (`ResourceCreateRequest`, `ResourcePatch`):

| Field | Rule | Code and params |
|---|---|---|
| `description` | required; trimmed non-empty; ≤ 255 characters | `required`; `tooLong` `{max: 255}` |
| `description` (creation) | derived local part non-empty and ≤ 64 characters | `invalidFormat` `{format: resourceDescription}` |
| `domain` | an existing `domain` (not an alias domain) in scope | `notFound` `{resource: domain}` / `notEligible` `{kind: aliasDomain}` |
| `kind` | `location`, `thing`, `group` | `notInList` |
| `bookingRule` | `alwaysFree`, `unlimited`, `limited` | `notInList` |
| `bookingLimit` | required for `limited`; 1 to 2,147,483,647; absent or `null` otherwise | `required`; `outOfRange`; `conflictsWith` `{field: bookingRule}` |
| derived address | not in use (R7) | `409 address-in-use` |
| read-only members | equal to current; `etag` ignored | `immutable` |

### SenderAcl (`sender_acl`): written by `App\Services\SenderSettings\SenderSettingsUpdater`, the alias services, `AliasDomainCreator` and 002's `AliasDomainCleanup`

Model: feature 003's `App\Models\Mailcow\SenderAcl`.

| Column | Type | Meaning |
|---|---|---|
| `id` | INT AUTO_INCREMENT PK | — |
| `logged_in_as` | VARCHAR(255) | the mailbox that may send |
| `send_as` | VARCHAR(255) | an address, `@domain`, or `*` |
| `external` | TINYINT(1) DEFAULT 0 | `0` explicit permission, `1` external sender address |

No unique key exists, so services deduplicate before inserting. Contract mapping:
`MailboxSenderSettings.explicitPermissions` = rows with `external = 0` (`MailboxSenderPermission`:
`value` = `send_as`, `type` from its form, `changeable` and `stale` per research R14);
`senderCheckDisabled` = a row with `send_as = '*'`; `externalSenderAddresses` = `send_as` of rows
with `external = 1`.

**Writes** (every statement is legacy's unless marked):

| Trigger | Statement |
|---|---|
| Replace explicit permissions | `DELETE … WHERE external = 0 AND logged_in_as = :mailbox`, then `INSERT (send_as, logged_in_as)` per resulting entry |
| Replace external sender addresses | `DELETE … WHERE external = 1 AND logged_in_as = :mailbox`, then `INSERT (send_as, logged_in_as, external = 1)` per entry |
| Alias created or changed with `senderAllowed` | `DELETE … WHERE send_as = :address AND logged_in_as IN (:destinations)` (legacy ran it on every edit) |
| Alias address changed | *(new)* `DELETE` duplicates, then `UPDATE … SET send_as = :new WHERE send_as = :old AND external = 0` |
| Alias deleted | `DELETE … WHERE send_as = :address` |
| Alias domain created | `DELETE … WHERE external = 1 AND send_as LIKE '%@<name>'` (legacy intended, never worked) |
| Alias domain deleted | *(new)* through 002's `AliasDomainCleanup::deleteRows()`: the rows for the removed aliases' addresses and for `@<name>` |

**Validation** (`MailboxSenderSettingsPatch`):

| Field | Rule | Code and params |
|---|---|---|
| `explicitPermissions.N` | address, `domain`, `@domain` or `*` after normalisation | `invalidFormat` `{format: senderPermission}` |
| `explicitPermissions.N` | an `alias.address`, `domain` or `alias_domain` in the caller's scope, or an unchangeable entry already stored | `notFound` `{resource: address}` / `{resource: domain}` |
| `explicitPermissions.N` = `*` | administrators only | `notEligible` `{allowedRoles: [admin]}` |
| `externalSenderAddresses.N` | valid address or domain name | `invalidFormat` `{format: emailOrDomain}` |
| `externalSenderAddresses.N` | domain is not a `domain` or `alias_domain` of the server | `notEligible` `{kind: localDomain}` |
| `externalSenderAddresses` changed by a domain administrator | `extendSenderAcl` | `403 attribute-not-permitted` `{attributes: [externalSenderAddresses], requiredFlags: {externalSenderAddresses: extendSenderAcl}}` |

### Read-only and cleanup tables

| Table | Use | Written by this feature |
|---|---|---|
| `domain` (`domain`, `aliases`, `backupmx`, `active`) | scope, existence, alias limit, relay check, external check (`active`), row lock `FOR UPDATE` (R6) | no |
| `domain_admins` (`username`, `domain`, `active`) | domain administrator scope (001 R20) | no |
| `admin`, `da_acl` | role and flags through 001's `IdentityService` | no |
| `mailbox` (non-resource rows) | own-alias exclusion, sender settings, address-in-use, destinations that are resources | no |
| `spamalias` (`address`) | address-in-use; cleanup `DELETE … WHERE address LIKE '%@<alias domain>'` | cleanup only |
| `bcc_maps` (`local_dest`) | cleanup `DELETE … WHERE local_dest IN ('@<alias domain>', '<alias domain>')` | cleanup only |
| `filterconf` (`object`) | cleanup `DELETE … WHERE object = '<alias domain>'` (spam allow and block lists) | cleanup only |
| `domain_wide_footer` (`domain`, `alias_domain_exclude` JSON) | cleanup removes the alias domain from the target's exclusion array | cleanup only |
| `_sogo_static_view` | groupware directory refresh (R9) | through the groupware directory service |
| `sogo_user_profile`, `sogo_cache_folder`, `sogo_acl`, `sogo_store`, `sogo_quick_contact`, `sogo_quick_appointment`, `sogo_folder_info` | resource deletion (R13) | resource deletion only |

### Alias domain deletion (shared cleanup of feature 002)

`App\Services\AliasDomains\AliasDomainDeleter` (research R11) runs, inside one transaction:

```
SELECT … FROM alias_domain WHERE alias_domain = :ad FOR UPDATE      -- then If-Match
SELECT id, address, goto FROM alias WHERE domain = :ad              -- for the audit record
AliasDomainCleanup::deleteRows(:ad)                                 -- 002 D19: alias rows and their
    -- sender_acl rows, sender_acl '@:ad', spamalias LIKE '%@:ad', bcc_maps IN (':ad', '@:ad'),
    -- filterconf object = ':ad', the alias_domain row
UPDATE domain_wide_footer SET alias_domain_exclude = <array without ':ad'> WHERE domain = :target
SharedStore MULTI: HDEL of AliasDomainCleanup::redisFields(:ad)     -- DOMAIN_MAP, RL_VALUE; old values kept
INSERT audit record (aliasDomainDeletion, details.removed)
COMMIT                                                              -- on failure: restore the Redis fields
-- after the commit: SogoDirectory::rebuild() (once per bulk request), memcached flush
```

Kept: `DKIM_*` entries and `sender_acl` rows with `external = 1`.

## Redis (connection `mailcow`, database 0, no prefix)

All writes go through feature 002's classes: `SharedStore` (ping, writes with compensation,
`MULTI`), `RateLimitStore` and `DkimKeyGenerator`.

| Key | Type | Read | Written |
|---|---|---|---|
| `DOMAIN_MAP` | hash `<domain> → 1` | — | `HSET <alias domain> 1` at creation (atomic with the row); `HDEL` at deletion |
| `RL_VALUE` | hash `<object> → "<n> / 1<s|m|h|d>"` | alias domain representation (`HMGET` per page) | at creation when a rate limit is given (`RateLimitStore`); `HDEL` at deletion |
| `DKIM_PUB_KEYS` | hash `<domain> → public key (base64 body)` | "already existed" check | at creation, through `DkimKeyGenerator` |
| `DKIM_SELECTORS` | hash `<domain> → selector` | "already existed" check | same |
| `DKIM_PRIV_KEYS` | hash `<selector>.<domain> → PEM` | — | same |

No API-owned (`restv2:`) key is added.

## Named locks (MariaDB `GET_LOCK`)

| Lock name | Taken by | Purpose |
|---|---|---|
| `'restv2_address_' . substr(sha1(<lower-cased normalised address>), 0, 40)`, 5 s | feature 003's `AddressLock`, here for alias creation, each copy, an alias address change (new address) and resource creation | address uniqueness across `mailbox`, `alias` and `spamalias`, shared with 003 and 005 (`docs/conventions.md`) |

Row locks complete them: `domain` row `FOR UPDATE` of the primary domain for the alias limit, and
the `domain` and `alias_domain` keys of a new alias domain's name (002's convention).

## Other services

| Service | Call | When |
|---|---|---|
| memcached (`memcached:11211`) | `flush_all` through 002's `MemcachedFlusher` | after the commit of every change that refreshed or rebuilt the groupware directory, unless `SKIP_SOGO=y` |
| ISPConfig remote API (fork-only) | publish the new DKIM TXT record through 002's `DkimDnsPublisher` (≤ 15 s) | alias domain creation, after the commit, when the publication is enabled and a key was created |
| dockerapi, doveadm, Rspamd controller | none | Postfix, Dovecot and Rspamd read aliases, alias domains, resources and `sender_acl` directly from the database; Rspamd's settings map builds the internal-alias rules from `alias.internal` on each request |

## API-owned storage

None. Audit records go to 001's `restv2_audit_events` with the event and target types of research
R20.

## Version tags

Strong ETags (001 `ETag`) over a canonical JSON array of stored values (research R18):

| Resource | Inputs |
|---|---|
| Alias | `id, address, goto, domain, active, sender_allowed, sogo_visible, internal, public_comment, private_comment` |
| Alias domain | `alias_domain, target_domain, active` |
| Resource | `username, name, kind, multiple_bookings, active, domain` |
| Mailbox sender settings | sorted `[external, lower(send_as)]` pairs of `sender_acl` rows with `logged_in_as = mailbox` |

## Value objects (no storage)

- `App\Services\Aliases\AliasDestination`: `type` (`addresses`, `discard`, `learnSpam`,
  `learnHam`) and `addresses`; `fromGoto(string)`, `toGoto()`, `addedComparedTo(AliasDestination)`.
- `App\Services\Aliases\AliasCopyOutcome`: one entry of `AliasCopyReport` and of the bulk `meta.copies`.
- `App\Services\AliasDomains\AliasDomainCreationReport`: the four step outcomes, with 002's values
  (`dkimDnsPublication`: `published`, `noZone`, `failed`, `notConfigured`, `skipped`).
- `App\Services\SenderSettings\SenderEntry`: a normalised explicit permission or external sender
  address with its `type`.
- One `BulkItemResult` per target, produced by the shared `App\Support\Bulk\BulkActionRunner`
  (created here unless an earlier feature created it).
