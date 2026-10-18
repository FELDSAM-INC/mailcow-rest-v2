# Data Model: DKIM, MTA-STS and DNS Diagnostics

**Feature**: `specs/010-dkim-mta-sts-dns` | **Date**: 2026-10-18 | **Plan**: [plan.md](./plan.md)

This feature owns **no table**: it adds no Laravel migration. It writes one upstream table
(`mta_sts`) and three mailcow-owned Redis hashes (`DKIM_*`), reads three more upstream tables, and
keeps a few short-lived API-owned Redis keys (`restv2:` prefix). Audit records go to 001's
`restv2_audit_events`. Column definitions are quoted from upstream `2026-09a`
`data/web/inc/init_db.inc.php` (`db_version 18082026_1200`). JSON shapes are defined in the contract
(`api/components/schemas/`); this document maps them to storage.

---

## Upstream tables

Models live in `app/Models/Mailcow/` with `$connection = 'mysql'`, explicit `$table` and
`$primaryKey`, `public $timestamps = false` and `$guarded = ['*']`, except where a service writes.

### MtaStsPolicy (`mta_sts`), written by `App\Services\MtaSts\MtaStsPolicyService` only

| Column | Type (upstream) | Stored form | Contract |
|---|---|---|---|
| `id` | `BIGINT NOT NULL` | 14-digit `YmdHis` in mailcow's `TZ` | `policyId` (string) |
| `domain` | `VARCHAR(255) NOT NULL`, primary key | lower-case ASCII domain | `domain`; `displayDomain` = `idn_to_utf8(domain)` |
| `version` | `VARCHAR(255) NOT NULL` | `stsv1` (legacy lower-cases) | `version` = `STSv1` |
| `mode` | `VARCHAR(255) NOT NULL` | `enforce`, `testing` or `none` | `mode` |
| `mx` | `VARCHAR(255) NOT NULL` | normalised hosts joined by `,` (≤ 255 characters) | `mxHosts` (split on `,`) |
| `max_age` | `VARCHAR(255) NOT NULL` | decimal string, 1 to 31,557,600 | `maxAge` (integer) |
| `active` | `TINYINT(1) NOT NULL DEFAULT '1'` | `0` or `1`; this API writes `0` unless `active: true` is sent | `active` |

No `created`/`modified` columns exist. The model sets `$primaryKey = 'domain'`, `$keyType =
'string'`, `$incrementing = false`, and casts `active` to boolean. The policy identifier column is
named `id` but is not the key; the model exposes it as `policy_id` through an accessor to avoid
confusion with Eloquent's key.

**Derived values**:

- `policyUrl` = `https://mta-sts.<domain>/.well-known/mta-sts.txt`.
- `policyText` = `"version: STSv1\nmode: <mode>\nmax_age: <max_age>\n"` + `"mx: <host>\n"` per host,
  exactly as `data/web/mta-sts.php` prints it.
- `dnsRecords` = `[{name: "_mta-sts.<domain>", type: TXT, value: "v=STSv1;id=<id>;"}, {name:
  "mta-sts.<domain>", type: CNAME, value: <MAILCOW_HOSTNAME>}]`.

**Validation** (`StoreMtaStsPolicyRequest`, `UpdateMtaStsPolicyRequest`):

- `domain` (create only): "required, string, 1 to 255 characters"; resolved as a domain in the
  caller's scope after UTS #46 normalisation, otherwise `404` (an alias domain's name included).
- `version`: "optional, string, max 16, equal to `stsv1` case-insensitively" (`notInList`,
  `allowed: [STSv1]`). Omitted at creation → `stsv1`.
- `mode`: "required at creation, string, max 16, one of `enforce`, `testing`, `none`
  case-insensitively" (`required`, `notInList`). Stored lower-case.
- `maxAge`: "required at creation, integer, 1 to 31,557,600" (`required`, `invalidType`,
  `outOfRange` with `min: 1`, `max: 31557600`).
- `mxHosts`: "required at creation, array of 1 to 128 strings (`required`, `tooMany`), each at most
  255 characters; each trimmed, lower-cased and converted with UTS #46; an optional leading `*.`;
  the rest a valid domain name (`invalidFormat`, `format: mxHost`, key `mxHosts.<index>`); duplicates
  removed keeping the first occurrence and the order; joined by `,` at most 255 characters
  (`tooLong`, `max: 255`, key `mxHosts`)".
- `active`: "optional boolean" (`invalidType`). Omitted at creation → `0`.
- Patch: at least one member (`required` on key `body`); `null` for any member → `invalidType`.

**Policy identifier transitions**:

```
create                        → id = next(now)
patch version|mode|mx|max_age → id = next(now)      (mx order counts)
patch active only / no change → id unchanged
next(now) = now(TZ).format('YmdHis') if greater than the previous id,
            else (previous id as time + 1 s).format('YmdHis')
```

**Version tag**: 001's `App\Support\Http\ETag` over the JSON encoding of `[domain, version, mode,
mx, max_age, active, id]` as stored. Returned as `ETag` and `etag`, also on list entries.

**Concurrency**: creation is an `INSERT`; a duplicate key (including a concurrent creation) maps to
`409 mta-sts-policy-exists`. A patch runs `SELECT … FOR UPDATE`, compares `If-Match`, computes the
new row and writes it in the same transaction as the audit record.

**List** (`listMtaStsPolicies`): `SELECT m.* FROM mta_sts m JOIN domain d ON d.domain = m.domain`,
plus `WHERE m.domain IN (<scope>)` for domain administrators; `q` → `m.domain LIKE ?` (and the
Unicode form, matched in PHP for IDN domains); `active` → `m.active = ?`; `mode` → `m.mode = ?`; sort
`domain` → `m.domain`, `mode` → `m.mode`, `maxAge` → `CAST(m.max_age AS UNSIGNED)`, `policyId` →
`m.id`, ties by `m.domain`. Rows stored by legacy under an alias domain's name never join `domain`
and stay invisible.

**Deletion**: none through this API. Feature 002 deletes the row with its domain (FR-036 there).

### Domain (`domain`), read only

Columns used: `domain` (primary key) for the kind of a DKIM name, the DKIM entry list, policy
ownership and diagnostics. `active` is not consulted: inactive domains keep all operations (spec
edge case). Model from 001 (`App\Models\Mailcow\Domain`).

### AliasDomain (`alias_domain`), read only

| Column | Type | Use |
|---|---|---|
| `alias_domain` | `VARCHAR(255) NOT NULL`, primary key | DKIM name of kind `aliasDomain`, diagnostics name |
| `target_domain` | `VARCHAR(255) NOT NULL`, key | `DkimKey.targetDomain`, scope of domain administrators, MTA-STS policy that applies in diagnostics |
| `active` | `TINYINT(1)` | Not consulted |

Model `App\Models\Mailcow\AliasDomain` (created here if feature 004 has not created it yet; the same
class serves both).

### DomainAdminAssignment (`domain_admins`), read only

The scope of a domain administrator comes from 001's `IdentityService` (active rows, whatever the
domain's `active`). An alias domain is in scope when its `target_domain` is.

---

## Redis

### mailcow-owned DKIM hashes (connection `mailcow`, database 0, no prefix), written only by `App\Clients\SharedStore\DkimKeyStore`

| Hash | Field | Value | Contract |
|---|---|---|---|
| `DKIM_PUB_KEYS` | `<name>` | base64 DER `SubjectPublicKeyInfo` without armour or line breaks | `key.publicKey`, `key.keyLength`, `key.record` |
| `DKIM_SELECTORS` | `<name>` | selector (letters, digits, `-`, `_`, `.`) | `key.selector`, `key.record.name` |
| `DKIM_PRIV_KEYS` | `<selector>.<name>` | PEM private key, line feeds, trimmed | only `exportDkimPrivateKey` |

`<name>` is lower-case ASCII for every key this API writes. Legacy may have stored other spellings;
those are unused keys reachable by their exact stored name.

**Derived values of a DKIM entry**:

- `kind`: `domain` when `<name>` is in `domain`, `aliasDomain` when in `alias_domain`, else `none`.
- `state`: `valid` when the name has a key and `kind` is not `none`; `missing` when it has no key
  and `kind` is not `none`; `unused` when it has a key and `kind` is `none`. A name has a key when
  `DKIM_PUB_KEYS[name]` or `DKIM_SELECTORS[name]` exists.
- `key.complete`: all of `DKIM_PUB_KEYS[name]`, `DKIM_SELECTORS[name]` and
  `DKIM_PRIV_KEYS[selector.name]` exist; `missingParts` lists the absent ones.
- `key.keyLength`: bits of the parsed public key, `null` when it does not parse.
- `key.record`: `{name: "<selector>._domainkey.<name>", type: TXT, value:
  "v=DKIM1;k=rsa;t=s;s=email;p=<pub>", valueParts: str_split(value, 255)}`, `null` without selector
  or public key.
- `etag`: 001's `ETag` over the JSON encoding of `[name, selector, pub, hash_hmac('sha256', priv,
  app key)]` (absent values `null`); `null` when the name has no key. It never contains a plain hash
  of the private key.
- Internal fingerprint (never returned or logged): `sha1(name \0 selector \0 pub \0 priv)`, which
  the service passes to the script for compare-and-set and the script recomputes with
  `redis.sha1hex`.

**Writes** (one atomic Lua script each, see research R4):

| Change | Mode | Effect |
|---|---|---|
| Generation, import, copy (no key yet) | `create` | refuse when a key exists; `HSET` the three fields |
| Generation, import, copy with overwrite | `replace` | check the expected internal fingerprint (after the `If-Match` comparison); `HDEL DKIM_PRIV_KEYS <oldSel>.<name>`; `HSET` the three fields |
| Deletion | `delete` | refuse when no key; check the fingerprint; `HDEL` the three fields |
| Audit compensation | `restore` | only when the current fingerprint is the one just written: put the previous fields back |

**State transitions of a name's key**:

```
                generate / import / copy            delete
 (no key) ─────────────────────────────────► key ───────────► (no key)
                                               │  ▲
                                               └──┘ generate / import / copy with overwrite
                                                     (old private key removed, new key written atomically)
 domain or alias domain exists:  no key = missing, key = valid
 neither exists:                 no key = (no entry), key = unused
```

### API-owned keys (connection `restv2`, prefix `restv2:`)

| Key | Value | TTL | Owner |
|---|---|---|---|
| `restv2:dns-diagnostics:<sha256(caller)>` (+ `:timer`) | Laravel rate-limiter counter; caller `user:<username>` or `apiKey:<id>` | 60 s window | `DnsDiagnosticsController` via `RateLimiter` |
| `restv2:dns-facts:addresses` | JSON `{ipv4, ipv6, detectedAt}` | `RESTV2_DNS_FACTS_TTL` (600 s); failures not stored | `PublicAddressDetector` |
| `restv2:dns-facts:tlsa:<host>` | `3 1 1 <hex>` | 600 s; failures not stored | `ServerFactsCache` |
| `restv2:mailcow-vars:<fingerprint>` (001) | now also holds `SHOW_DKIM_PRIV_KEYS` and `autodiscover_config` | 001 | `MailcowConfig` |

The ISPConfig password is never stored in Redis.

---

## API-owned tables

None. Audit records use 001's `restv2_audit_events` with the event and target types listed in
[research R22](./research.md#r22-audit); refusals use the shared `AuditFailureReason` values and are
written by the shared `RefusalAuditor` (002/008), with resolvers for `dkimKey` and `mtaStsPolicy`.

---

## Value objects (no storage)

- `App\Services\Dkim\DkimName`: the stored name, the ASCII name, the Unicode name, the kind, the
  target domain.
- `App\Services\Dkim\DkimKeyMaterial`: selector, public key, private key (never serialised, never
  logged: `__debugInfo` hides the private key).
- `App\Services\Dkim\StoredDkimKey`: selector, public key, private key presence, fingerprint.
- `App\Services\Dkim\DkimKeyChange`: the entry after the change, `created` or `replaced`, the old
  selector and public key, the publication outcome.
- `App\Services\Dkim\DkimCreationStep`: `created`, `alreadyExisted` or `failed`, plus the publication
  outcome (for 002 and 004).
- `App\Services\Dkim\DkimDnsOutcome` (enum, 002 D10, extended with `removed` and
  `noRecordToRemove`) and `App\Services\Dkim\DkimDnsPublicationResult`: the outcome, record name,
  reason code and detail.
- `App\Services\DnsDiagnostics\DnsCheckResult`, `DnsCheckMessage`, `DnsDiagnosticsReport`: the
  contract shapes of a run.
- `App\Clients\Dns\DnsAnswer`: `records`, `noData`, `nxDomain`, `error(rcode)` or `timeout`, with
  the CNAME chain.
- `App\Support\Clock\Deadline`: start, limit, `remaining()`, `expired()`; built on an injectable
  clock so that tests can advance time.
- `App\Services\Mailcow\AutodiscoverConfig`: SMTP host and the IMAP, POP3, SMTP and Sieve ports.
- `App\Services\Dkim\IspconfigSettings`: enabled, URL, username, password (hidden from
  `__debugInfo` and serialisation), DNS server ID, TLS verification, time limit.
