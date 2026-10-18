# Data Model: Routing and Address Rewriting

**Feature**: `specs/009-routing-address-rewriting` | **Date**: 2026-10-18 | **Plan**: [plan.md](./plan.md)

Feature 009 owns no table. It reads and writes upstream mailcow tables and two mailcow Redis hashes,
and it adds records to 001's audit table. Upstream tables are never migrated (constitution III), and
every write goes through the services named below (constitution IV). The JSON shapes are defined in
the contract (`api/components/schemas/`); this document maps them to storage.

Column definitions are quoted from upstream `2026-09a` `data/web/inc/init_db.inc.php`
(`db_version 18082026_1200`), the source of 001's schema fixture. Every table is
`ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`, so string comparisons ignore letter case. Upstream `DATETIME`
columns hold mailcow's local time and are read through 001's `App\Casts\MailcowDateTime`. Writes leave
`created` and `modified` to their database defaults, as legacy does.

All models live in `app/Models/Mailcow/`, with `$connection = 'mysql'`, an explicit `$table` and
`$primaryKey`, `public $timestamps = false` and `$guarded = ['*']`. Writes use the query builder inside
the owning service.

---

## Upstream tables

### Relayhost (`relayhosts`): written by `App\Services\Routing\RelayhostService` only

| Column | Type | Contract (`Relayhost`) |
|---|---|---|
| `id` | INT AUTO_INCREMENT PK | `id` |
| `hostname` | VARCHAR(255) NOT NULL, KEY | `host` |
| `username` | VARCHAR(255) NOT NULL | `username` (`null` when `''`) |
| `password` | VARCHAR(255) NOT NULL | never returned; `passwordSet = password != ''` |
| `active` | TINYINT(1) NOT NULL DEFAULT 1 | `active` |

Model `Relayhost` (002's read model, extended): `$hidden = ['password']`, casts `active` → bool.

Derived: `usage.domains` and `usage.mailboxes` (research R10). No times exist.

- **Create**: `INSERT INTO relayhosts (hostname, username, password, active)`. `active` defaults to
  `1` (FR-018).
- **Edit**: `UPDATE relayhosts SET … WHERE id = ?` with only the changed columns.
- **Delete** (one transaction, research R12):
  1. `SELECT domain FROM domain WHERE relayhost = ?` and `SELECT username FROM mailbox WHERE
     JSON_UNQUOTE(JSON_VALUE(attributes, '$.relayhost')) = ?` (for the audit record);
  2. `DELETE FROM relayhosts WHERE id = ?`;
  3. `UPDATE domain SET relayhost = '0' WHERE relayhost = ?`;
  4. `UPDATE mailbox SET attributes = JSON_SET(attributes, '$.relayhost', '0') WHERE
     JSON_UNQUOTE(JSON_VALUE(attributes, '$.relayhost')) = ?`.
- **Search** (`q`): `hostname LIKE ? OR username LIKE ?`. **Filters**: `active`, `inUse`.
  **Sort**: `id` (default), `host`, `username`, `active`, `domainCount`, `mailboxCount`; ties by `id`.
- **Version tag**: `ETag::strong()` over `id|hostname|username|HMAC(password)|active` (research R2).
- **Validation** (`StoreRelayhostRequest`, `UpdateRelayhostRequest`):
  - `host`: required at creation. Parsed by `App\Support\Routing\NextHop` (`required`, `tooLong`
    `{max: 255}`, `invalidFormat` `{format: nextHop}`).
  - `username`: string, trimmed, at most 255 (`tooLong`), no `:` (`invalidCharacter`).
  - `password`: string, trimmed, at most 255 (`tooLong`). `""` removes it.
  - A resulting password with an empty resulting username → `required` on `username`
    (`{because: password}`). An empty username without a password removes the password.
  - `active`: boolean (`invalidType`).
  - Patch only: `id` and `usage` are read-only (`immutable` when different). At least one member
    (`required` on `body`).

### Domain (`domain`): the relayhost assignment column, written by `App\Services\Routing\RelayhostAssignmentService`

| Column | Type | Use |
|---|---|---|
| `domain` | VARCHAR(255) PK | `DomainRelayhostAssignment.domain` |
| `relayhost` | VARCHAR(255) NOT NULL DEFAULT `'0'` | assigned relayhost id as a decimal string; `'0'` = none |
| `modified` | DATETIME ON UPDATE CURRENT_TIMESTAMP | updated by MariaDB on every write |

Only `relayhost` is written: `UPDATE domain SET relayhost = ? WHERE domain = ?` (FR-028). The value
applies to the domain and its alias domains through `mysql_sender_dependent_default_transport_maps.cf`
and `mysql_sasl_passwd_maps_sender_dependent.cf` (FR-027).

- **Version tag**: `ETag::strong(domain|relayhost)`.
- **Lookup**: by the normalised name in `domain` only. A name found only in `alias_domain` → `404`
  (002 FR-006).

### Mailbox (`mailbox`): the relayhost attribute, written by `App\Services\Routing\RelayhostAssignmentService`

| Column | Type | Use |
|---|---|---|
| `username` | VARCHAR(255) PK | `MailboxRelayhostAssignment.mailbox` |
| `domain` | VARCHAR(255) | the domain whose assignment is shown next to the mailbox's |
| `kind` | VARCHAR(100) | `''` only; resources (`location`, `thing`, `group`) → `404` (003 FR-003) |
| `attributes` | JSON | `$.relayhost`: JSON string id, `"0"` = none (legacy `strval()`) |
| `modified` | DATETIME ON UPDATE CURRENT_TIMESTAMP | updated by MariaDB |

Only the attribute is written: `UPDATE mailbox SET attributes = JSON_SET(attributes, '$.relayhost', ?)
WHERE username = ?` with a string value. No `attribute_hash` reset and no `_sogo_static_view` update
(spec parity table).

- **Version tag**: `ETag::strong(username|relayhost)`, where `relayhost` is the stored attribute as read
  (missing → `""`).
- **Derived values** (research R11): `relayhost` (mailbox), `domainRelayhost`, `effectiveRelayhost`
  and `credentialsRelayhost`, each with `state` `active`, `inactive` or `dangling`.

### Assignment state machine

```
                PUT {relayhostId: n} (n exists)
     none ───────────────────────────────────────────► assigned(n)
      ▲  ◄─────────────────────────────────────────── │   │
      │       PUT {relayhostId: null}                  │   │ relayhosts.active changes
      │       DELETE /relayhosts/{n} (clears)          │   ▼
      │                                          active ⇄ inactive
      │                                                │
      │   PUT {relayhostId: null | m}                  │ row removed outside this API
      └──────────────────────────────── dangling(n) ◄──┘ (legacy, /api/v1, restore)
```

`dangling` is never produced by this API: its relayhost deletion clears every reference.

### Transport (`transports`): written by `App\Services\Routing\TransportService` only

| Column | Type | Contract (`Transport`) |
|---|---|---|
| `id` | INT AUTO_INCREMENT PK | `id` |
| `destination` | VARCHAR(255) NOT NULL, KEY (not unique) | `destination` |
| `nexthop` | VARCHAR(255) NOT NULL, KEY | `nextHop` |
| `username` | VARCHAR(255) NOT NULL DEFAULT `''` | `username` (`null` when `''`) |
| `password` | VARCHAR(255) NOT NULL DEFAULT `''` | `passwordSet` |
| `is_mx_based` | TINYINT(1) NOT NULL DEFAULT 0 | `mxBased` |
| `active` | TINYINT(1) NOT NULL DEFAULT 1 | `active` (API default at creation: `false`, FR-034) |

Model `Transport`: `$hidden = ['password']`, casts `is_mx_based`, `active` → bool.

Derived: `nextHopSharedWith` = `COUNT(*) - 1` of the rows with the same `nexthop`, from one derived
table `SELECT nexthop, COUNT(*) FROM transports GROUP BY nexthop` joined on `nexthop`.

- **Create**: `INSERT INTO transports (nexthop, destination, is_mx_based, username, password,
  active)`, then the credential propagation `UPDATE transports SET password = ? WHERE nexthop = ? AND
  id <> ?` when research R7 says so. Everything runs under `NamedLock('restv2:routing:transports')`
  in one transaction with the audit record.
- **Edit**: `UPDATE transports SET … WHERE id = ?`, then the propagation `UPDATE transports SET
  username = ?, password = ? WHERE nexthop = ? AND id <> ?` when credentials were sent (R7).
- **Delete**: `DELETE FROM transports WHERE id = ?` (FR-040). Other transports of the next hop keep
  their credentials.
- **Search** (`q`): `destination`, `nexthop`, `username`. **Filters**: `active`, `mxBased`, `nextHop`
  (exact, case-insensitive through the collation). **Sort**: `id` (default), `destination`,
  `nextHop`, `username`, `active`, `mxBased`.
- **Version tag**: `ETag::strong()` over `id|destination|nexthop|username|HMAC(password)|is_mx_based|active`.
- **Validation**:
  - `destination`: required at creation, trimmed, at most 255. Not MX-based: `*`, `domain`,
    `.domain` or an e-mail address (`invalidFormat` `{format: transportDestination}`). MX-based: a
    MariaDB `REGEXP` pattern (`invalidRegex`). Unique among all
    transports → `409 routing-entry-exists`.
  - `nextHop`: required at creation, `NextHop` grammar, interference → `409 next-hop-interferes`.
  - `mxBased`, `active`: booleans. On edit, a changed `mxBased` re-validates the destination.
  - `username`, `password`: as for relayhosts. A different username at creation for an existing next
    hop → `409 next-hop-credentials-differ`.
  - Patch only: `id` and `nextHopSharedWith` are read-only.

### BccMap (`bcc_maps`): written by `App\Services\Routing\BccMapService` only

| Column | Type | Contract (`BccMap`) |
|---|---|---|
| `id` | INT AUTO_INCREMENT PK | `id` |
| `local_dest` | VARCHAR(255) NOT NULL, KEY | `localDestination` (`@domain` or address) |
| `bcc_dest` | VARCHAR(255) NOT NULL | `bccDestination` |
| `domain` | VARCHAR(255) NOT NULL | `domain` (decides the scope) |
| `type` | ENUM('sender','rcpt') | `type` (`sender`, `recipient`) |
| `created` | DATETIME(0) NOT NULL DEFAULT NOW(0) | `createdAt` |
| `modified` | DATETIME ON UPDATE CURRENT_TIMESTAMP | `modifiedAt` (`null` when never modified) |
| `active` | TINYINT(1) NOT NULL DEFAULT 0 | `active` (API default at creation: `true`, FR-049) |

Derived: `orphaned` (research R18).

- **Create**: `INSERT INTO bcc_maps (local_dest, bcc_dest, domain, active, type)` under
  `NamedLock('restv2:routing:bcc_maps')`.
- **Edit**: `UPDATE bcc_maps SET bcc_dest = ?, active = ?, type = ? WHERE id = ?`. `local_dest` and
  `domain` are read-only (FR-051, FR-054).
- **Delete**: `DELETE FROM bcc_maps WHERE id = ?`.
- **Scope** (domain administrators): `domain IN (:scopeDomains) OR domain IN (SELECT alias_domain
  FROM alias_domain WHERE target_domain IN (:scopeDomains))`. Out of scope → `404`.
- **Search** (`q`): `local_dest`, `bcc_dest`. **Filters**: `type`, `active`, `domain[]` (any),
  `orphaned`. **Sort**: `id` (default), `type`, `localDestination`, `bccDestination`, `domain`,
  `active`, `createdAt`, `modifiedAt`.
- **Version tag**: `ETag::strong(id|local_dest|bcc_dest|domain|type|active)`.
- **Validation**: `localDestination` (required, research R18, `notFound` for unknown or out of
  scope, `tooLong` `{max: 255}`), `type` (required, `notInList` `{allowed: [sender, recipient]}`),
  `bccDestination` (required, `tooMany` `{max: 1}`, `invalidFormat` `{format: email}`,
  `tooLong`), `active` (boolean). Unique `(local_dest, type)` → `409 routing-entry-exists`.

### RecipientMap (`recipient_maps`): written by `App\Services\Routing\RecipientMapService` only

| Column | Type | Contract (`RecipientMap`) |
|---|---|---|
| `id` | INT AUTO_INCREMENT PK | `id` |
| `old_dest` | VARCHAR(255) NOT NULL, KEY | `originalDestination` |
| `new_dest` | VARCHAR(255) NOT NULL | `newDestination` |
| `created` | DATETIME(0) NOT NULL DEFAULT NOW(0) | `createdAt` |
| `modified` | DATETIME ON UPDATE CURRENT_TIMESTAMP | `modifiedAt` |
| `active` | TINYINT(1) NOT NULL DEFAULT 0 | `active` (API default: `true`, FR-061) |

- **Writes**: `INSERT`, `UPDATE … SET old_dest, new_dest, active`, `DELETE`, under
  `NamedLock('restv2:routing:recipient_maps')` for inserts and edits of `old_dest`.
- **Search**: `old_dest`, `new_dest`. **Filter**: `active`. **Sort**: `id` (default),
  `originalDestination`, `newDestination`, `active`, `createdAt`, `modifiedAt`.
- **Version tag**: `ETag::strong(id|old_dest|new_dest|active)`.
- **Validation**: each destination required at creation, normalised (research R19), at most 255 as
  stored, `invalidFormat` `{format: recipientMapDestination}`. Unique `old_dest` → `409
  routing-entry-exists`.

### TlsPolicyMap (`tls_policy_override`): written by `App\Services\Routing\TlsPolicyMapService` only

| Column | Type | Contract (`TlsPolicyMap`) |
|---|---|---|
| `id` | INT AUTO_INCREMENT PK | `id` |
| `dest` | VARCHAR(255) NOT NULL, UNIQUE | `destination` |
| `policy` | ENUM('none','may','encrypt','dane','dane-only','fingerprint','verify','secure') NOT NULL | `policy` |
| `parameters` | VARCHAR(255) DEFAULT `''` | `parameters` (`""` when none; `NULL` read as `""`) |
| `created` | DATETIME(0) NOT NULL DEFAULT NOW(0) | `createdAt` |
| `modified` | DATETIME ON UPDATE CURRENT_TIMESTAMP | `modifiedAt` |
| `active` | TINYINT(1) NOT NULL DEFAULT 1 | `active` (API default: `true`) |

Model `TlsPolicyOverride` (contract name `TlsPolicyMap`).

- **Writes**: `INSERT`, `UPDATE`, `DELETE`. A duplicate `dest` also raises MariaDB error 1062, which
  is mapped to `409 routing-entry-exists` (research R9).
- **Search**: `dest`, `parameters`. **Filters**: `policy[]` (any), `active`. **Sort**: `id`
  (default), `destination`, `policy`, `active`, `createdAt`, `modifiedAt`.
- **Version tag**: `ETag::strong(id|dest|policy|parameters|active)`.
- **Validation**: `destination` (required, `invalidFormat` `{format: tlsPolicyDestination}`,
  `tooLong`), `policy` (required, case-insensitive, `notInList` with the eight values),
  `parameters` (`invalidFormat` `{format: tlsPolicyParameters}`, `tooLong` `{max: 255}` after
  joining), `active`.

### Read-only upstream tables

| Table | Columns used | Why |
|---|---|---|
| `alias_domain` | `alias_domain`, `target_domain`, `active` | BCC scope, local destinations, choices, the 404 for alias domains |
| `alias` | `address`, `goto`, `domain`, `active` | BCC local destinations (not catch-all, `goto != address`), `deliversTo` |
| `domain_admins` | `username`, `domain`, `active` | domain administrator scope (001 R20) |
| `da_acl` | `domain_relayhost`, `mailbox_relayhost`, `bcc_maps` | permission flags (001 R20), all defaulting to `1` upstream |
| `forwarding_hosts` | — | unused, as in legacy |

Models `Relayhost`, `AliasDomain` and `Alias` exist from feature 002 (009 extends `Relayhost` with
`$hidden` and casts). 001 provides `Domain`, `Mailbox`, `DomainAdminAssignment` and `DaAcl`.

---

## Redis (connection `mailcow`, database 0, no prefix)

Written only by `App\Services\Routing\ForwardingHostStore` (through 002's
`App\Clients\SharedStore\SharedStore`), called by `App\Services\Routing\ForwardingHostService`.

| Key | Type | Field → value | Meaning | Contract (`ForwardingHost`) |
|---|---|---|---|---|
| `WHITELISTED_FWD_HOST` | hash | address or prefix → source | listed forwarding host | `address`, `source` |
| `KEEP_SPAM` | hash | address or prefix → `1` | spam filter **disabled** for this entry | `spamFilter = !HEXISTS` |

Readers outside this API: Rspamd multimap `WHITELISTED_FWD_HOST` and `force_actions`, the Rspamd
`KEEP_SPAM` prefilter (`rspamd.local.lua`), greylisting and Postfix postscreen through
`forwardinghosts.php`.

- **List and read**: `HGETALL WHITELISTED_FWD_HOST`, `HGETALL KEEP_SPAM` (two calls per request).
- **Create**: one `MULTI`: per address `HSET WHITELISTED_FWD_HOST a source`, then `HDEL KEEP_SPAM a`
  (filter on) or `HSET KEEP_SPAM a 1` (filter off); `EXEC`.
- **Edit**: `HSET KEEP_SPAM a 1` or `HDEL KEEP_SPAM a`.
- **Delete**: `MULTI`, `HDEL WHITELISTED_FWD_HOST a`, `HDEL KEEP_SPAM a`, `EXEC`.
- **Compensation**: the previous `source` and `KEEP_SPAM` presence of every touched field are read
  before a write and restored in one `MULTI`/`EXEC` when the audit transaction fails to commit
  (research R17).
- **Search**: address, source. **Filters**: `spamFilter`, `source` (exact). **Sort**: `address`
  (default, IPv4 then IPv6, numeric, then prefix length), `source`, `spamFilter`.
- **Version tag**: `ETag::strong(address|source|keepSpam)`.
- **Validation** (`StoreForwardingHostRequest`): `host` required, at most 255; address literals must
  parse (`invalidFormat` `{format: ipNetwork}`); names that resolve to nothing → `notResolvable`;
  `spamFilter` boolean (default `true`, FR-076). Patch: `spamFilter`; `address` and `source`
  read-only.

### Forwarding host creation outcomes

```
input ──► literal ──────────────────────────────┐
  │                                              ▼
  └────► name ──► SPF ──► MX ──► A/AAAA ──► canonical addresses ──► per address:
                  (≤ 10 lookups)                                      matches stored entry? ─ yes ─► "updated" (keeps stored form)
                                                                                           └ no ──► "added"
          none ──► 422 notResolvable;  DNS SERVFAIL/timeout ──► 503 temporary-failure (dns)
```

No API-owned Redis key is added. The named locks of research R9 live in MariaDB (`GET_LOCK`), not in
Redis.

---

## API-owned tables

None. Audit records go to 001's `restv2_audit_events` through `App\Services\Audit\AuditLogger`.

### Audit events of this feature (extends 001's per-event `details` whitelist)

| `eventType` | `target.type`, `target.id` | `details` keys |
|---|---|---|
| `relayhostCreation` | `relayhost`, id | `values` (`host`, `username`, `password` (`set` or `null`), `active`) |
| `relayhostUpdate` | `relayhost`, id | `changes` (`{field: {old, new}}`; `password`: `set`, `changed`, `removed`) |
| `relayhostDeletion` | `relayhost`, id | `host`, `clearedDomains`, `clearedMailboxes` |
| `relayhostTest` | `relayhost`, id | `sender`, `recipient`, `accepted`, `step`, `failureReason` |
| `relayhostAssignmentUpdate` | `domainRelayhostAssignment` (domain) or `mailboxRelayhostAssignment` (address) | `changes.relayhostId` |
| `transportCreation` | `transport`, id | `values` (`destination`, `nextHop`, `mxBased`, `active`, `username`, `password`), `affectedTransportIds` |
| `transportUpdate` | `transport`, id | `changes`, `affectedTransportIds` |
| `transportDeletion` | `transport`, id | `destination`, `nextHop` |
| `transportTest` | `transport`, id | as `relayhostTest` |
| `bccMapCreation` / `bccMapUpdate` / `bccMapDeletion` | `bccMap`, id | `values` (`localDestination`, `type`, `bccDestination`, `domain`, `active`) / `changes` / `localDestination`, `type` |
| `recipientMapCreation` / `…Update` / `…Deletion` | `recipientMap`, id | `values` (the destinations and `active`) / `changes` / `originalDestination` |
| `tlsPolicyMapCreation` / `…Update` / `…Deletion` | `tlsPolicyMap`, id | `values` (`destination`, `policy`, `parameters`, `active`) / `changes` / `destination` |
| `forwardingHostCreation` | `forwardingHost`, the source | `entries` (`[{address, outcome}]`), `spamFilter` |
| `forwardingHostUpdate` / `forwardingHostDeletion` | `forwardingHost`, address | `changes.spamFilter` / `source` |

Creation records carry the stored attributes in `values`, as the creation records of features 002
to 004 do (implementation decision, `tasks.md`). `docs/audit-events.md` is the reference for every
key. Every event may also carry `bulkRequestId`. Failures are written by 008's `RefusalAuditor` (or the
bulk item handlers) with `failureReason` and the details `problemType`, `status`, `errors` (member →
rule codes, never values) and, for `permission-flag-required`, `requiredFlags`. `target.name` is the host, destination, local destination or address,
for 001's `q` search; for `forwardingHostCreation` it is the stored addresses joined by spaces (cut to
255 characters), so that `q=<address>` finds the creation that added an address while `targetId`
finds it by its source. Passwords appear only as `set`, `changed` or `removed`.

---

## External calls

- **dockerapi**: none. No operation restarts or reloads a service (FR-092).
- **SMTP**: `App\Clients\Smtp\SmtpProbe` opens one outbound TCP connection per connection test
  (research R13).
- **DNS**: `App\Clients\Dns\DnsResolver` (`TXT`, `MX`, `A`, `AAAA`) for forwarding host names and
  test targets, through unbound-mailcow (research R15).

## Value objects (no storage)

- `App\Support\Routing\NextHop`: parsed next hop (`stored`, `bracketed`, `host`, `port`,
  `cleanHost`).
- `App\Support\Routing\TransportDestination`: normalised destination and its kind (`wildcard`,
  `domain`, `subdomains`, `address`, `pattern`).
- `App\Support\Routing\IpNetworkLiteral`: canonical address or prefix and its sort key.
- `App\Clients\Smtp\SmtpProbeRequest` and `SmtpProbeResult` (`accepted`, `step`, `failure`, `tls`,
  `login`, `lastReply`, `transcript`, `durationMs`).
- `App\Services\Routing\RelayhostReference`: `id`, `host`, `state` (`active`, `inactive`,
  `dangling`).
