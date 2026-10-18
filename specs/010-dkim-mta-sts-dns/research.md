# Research: DKIM, MTA-STS and DNS Diagnostics

**Feature**: `specs/010-dkim-mta-sts-dns` | **Date**: 2026-10-18 | **Plan**: [plan.md](./plan.md)

This document records only the decisions this feature adds. Everything else is inherited from
feature 001's design ([plan](../001-auth-sessions-api-keys/plan.md),
[research](../001-auth-sessions-api-keys/research.md)): Laravel 13 on PHP 8.4, the Docker dev stack,
the `2026-09a` schema fixture, the `restv2_` table prefix and `restv2:` Redis prefix, the request
pipeline (R8 there), the problem URIs (R27), contract validation in tests (R13), the `mailcow` and
`restv2` Redis connections (T009 there), the configuration reader `MailcowConfig` (R14), the audit
logger (R22) and the identity and scope service (R20).

Decisions follow the owner's precedence: legacy behaviour first (constitution IV), then the
constitution and `docs/conventions.md`, then best practice. Legacy references are paths in upstream
mailcow-dockerized `2026-09a` (`git -C ~/Projects/mailhosting show 2026-09a:<path>`). Fork-only
behaviour comes from `git -C ~/Projects/mailhosting diff 2026-09a HEAD` on branch
`feldhost-202609a`.

---

## 1. Contract shape

### R1. Resources and operations

- **Decision**: 14 operations in three modules.

  | Module | Method and path | operationId |
  |---|---|---|
  | `dkim` | GET `/dkim-keys` | listDkimKeys |
  | `dkim` | POST `/dkim-keys` | generateDkimKey |
  | `dkim` | GET `/dkim-keys/{dkimName}` | getDkimKey |
  | `dkim` | DELETE `/dkim-keys/{dkimName}` | deleteDkimKey |
  | `dkim` | POST `/dkim-keys/{dkimName}/import` | importDkimKey |
  | `dkim` | POST `/dkim-keys/{dkimName}/copy` | copyDkimKey |
  | `dkim` | GET `/dkim-keys/{dkimName}/private-key` | exportDkimPrivateKey |
  | `dkim` | POST `/dkim-keys/{dkimName}/dns-publication` | publishDkimDnsRecord |
  | `dkim` | GET `/capabilities/dns` | getDnsCapabilities |
  | `mta-sts` | GET `/mta-sts-policies` | listMtaStsPolicies |
  | `mta-sts` | POST `/mta-sts-policies` | createMtaStsPolicy |
  | `mta-sts` | GET `/mta-sts-policies/{policyDomain}` | getMtaStsPolicy |
  | `mta-sts` | PATCH `/mta-sts-policies/{policyDomain}` | updateMtaStsPolicy |
  | `dns-diagnostics` | GET `/dns-diagnostics/{diagnosticsName}` | runDnsDiagnostics |

  - DKIM entries are a collection keyed by name. Generation is the collection's create, with the
    name in the body (spec FR-014 answers an unknown or out-of-scope name with `404`, which the
    contract states). Import, copy, export and publishing again are sub-resources of one name, as
    001's `/api-keys/{id}/revocation` is (constitution II).
  - **No literal segment directly under `/dkim-keys/`**: legacy accepts single-label domain names
    (`is_valid_domain_name` allows them), so a path such as `/dkim-keys/capabilities` would collide
    with a domain named `capabilities`. The capabilities read uses the conventions' top-level
    `/capabilities/{area}` path instead.
  - MTA-STS policies are a flat collection keyed by domain, with no `DELETE` (spec FR-037). PATCH
    takes JSON Merge Patch, like 001's preferences.
  - Diagnostics is a safe `GET`: it changes nothing (FR-039), read-only keys may run it, and
    `Cache-Control: no-store` keeps results out of caches.
  - The capabilities read (`GET /capabilities/dns`, `getDnsCapabilities`; orchestrator item 4 and
    `docs/conventions.md` "Capabilities, history and refusals") follows 002 FR-064 and its
    `/capabilities/domains`. Area `dns` covers DKIM, MTA-STS and diagnostics. It includes
    `defaultPageSize` and `maxPageSize` as 002's `DomainCapabilities` does.
- **Rationale**: Every path is a noun, every write targets exactly one object (FR-059), and every
  operation maps to one controller action.
- **Alternatives considered**:
  - One `POST /dkim-keys` with a discriminated body (`generate`, `import`, `copy`) was rejected: the
    three operations have different roles, name rules and errors, and one operation would hide that
    from generated clients and the docs.
  - `PUT /dkim-keys/{name}/private-key` for import was rejected: an import refuses an existing key by
    default (FR-020), which is not PUT's replace semantics.
  - Nesting MTA-STS under `/domains/{domain}/mta-sts-policy` was rejected: the list across domains
    (FR-038) would need a second path, and the flat collection gives plain CRUD.
  - `POST /dns-diagnostics` was rejected: a run is safe and idempotent.

### R2. Machine-readable diagnostics and publication outcomes (orchestrator items 1, 2)

- **Decision**:
  - `DnsCheck.id` is a stable identifier with the pattern `^[a-z0-9]+(-[a-z0-9]+)*(:[A-Za-z0-9._-]+)?$`
    and the documented values `a`, `ptr:ipv4`, `aaaa`, `ptr:ipv6`, `tlsa:25`, `mx`, `autodiscover`,
    `srv:autodiscover`, `autoconfig`, `mta-sts`, `mta-sts-record`, `tls-rpt`, `spf`, `dmarc`,
    `dkim:<selector>`, `srv:pop3`, `srv:pop3s`, `srv:imap`, `srv:imaps`, `srv:submission`,
    `srv:smtps` and `srv:sieve`. A loose pattern lets later releases add identifiers without a
    breaking change.
  - Reasons, notes and guidance are `DnsCheckMessage {code, params, message}`. The 23 codes of
    `DnsCheckMessageCode` and their parameters are documented in that schema. Result-level notes
    (`noPublicIpv6`, `serverChecksOmitted`, `propagationDelay`) sit in `DnsDiagnosticsResult.notes`.
  - `DkimDnsPublicationOutcome.reason` is `{code, detail}`. `code` is one of the six
    `DkimDnsPublicationReasonCode` values; `detail` is at most 500 characters of technical text
    (ISPConfig's answer message or a transport error), scrubbed of the remote password.
- **Rationale**: Feature 001 FR-055 requires clients to translate from codes; the UI 010 spec found
  that free text could not be translated.

### R3. Responses of key changes

- **Decision**: Generation, import, copy and publishing again return
  `data: DkimKeyChangeResult`, which is `DkimKey` (`allOf`) plus `dnsPublication`. `201` with
  `Location: /api/v2/dkim-keys/{rawurlencode(name)}` when the name had no key, `200` when a key was
  overwritten or a record published again. Deletion returns `204` without a body; the publication
  outcome is in the audit record, and the contract tells clients to read it from the audit history
  (orchestrator item 6).
- **Rationale**: `data` stays the project-wide single-resource shape; a client that parses
  `DkimKey` ignores the extra member. The constitution requires `204` for deletions.

---

## 2. DKIM keys

### R4. Storage in mailcow's Redis

- **Decision**: Keys stay exactly where legacy and Rspamd keep them, on the `mailcow` Redis
  connection (database 0, no prefix):

  | Hash | Field | Value | Read by |
  |---|---|---|---|
  | `DKIM_PUB_KEYS` | `<name>` | base64 DER `SubjectPublicKeyInfo`, no PEM armour or line breaks | legacy UI, this API |
  | `DKIM_SELECTORS` | `<name>` | selector | Rspamd `dkim_signing.conf` and `arc.conf` (`selector_prefix`), legacy, this API |
  | `DKIM_PRIV_KEYS` | `<selector>.<name>` | PEM private key, trimmed | Rspamd (`key_prefix`), legacy, this API |

  - **Atomic writes (FR-015, FR-020, FR-052, FR-053)**: every change runs as one Lua script
    (`EVALSHA`, loaded with `SCRIPT LOAD`, falling back to `EVAL`) in
    `App\Clients\SharedStore\DkimKeyStore`. Redis executes a script atomically, so a change is all or
    nothing and concurrent changes are serialised. The script:
    1. reads `pub = HGET DKIM_PUB_KEYS name`, `sel = HGET DKIM_SELECTORS name` and, when `sel` is
       set, `priv = HGET DKIM_PRIV_KEYS sel..'.'..name`;
    2. computes the internal fingerprint `redis.sha1hex(name.."\0"..(sel or "").."\0"..(pub or "").."\0"..(priv or ""))`;
    3. mode `create`: returns `exists` when `pub` or `sel` is set (FR-008: a name has a key when a
       public key or a selector is stored); mode `replace` and `delete`: returns `outdated` when an
       expected fingerprint was passed and differs, and mode `delete` returns `absent` when there is
       no key;
    4. deletes the old private key field `sel..'.'..name` (FR-020: the overwrite removes the private
       key under the old selector, which legacy's copy leaves behind);
    5. writes the three new fields (or, for `delete`, removes `DKIM_PUB_KEYS[name]` and
       `DKIM_SELECTORS[name]`);
    6. returns `ok` with the old selector and public key, which the publication and the audit
       record need.
  - **Version tag (FR-051)**: 001's `App\Support\Http\ETag` over the JSON encoding
    (`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`) of `[name, selector, publicKey,
    hash_hmac('sha256', privateKey, config('app.key'))]`, absent values as `null`, as 008's R4 does
    for sync job passwords. It changes whenever any UI or API changes the selector, the public key
    or the private key, and it never contains a plain hash of the private key (conventions:
    version tags). `If-Match: *` matches any existing key.
  - **Compare-and-set**: for an `If-Match` request the service reads the key, compares the tag
    (`412` on mismatch) and passes the script the internal fingerprint `sha1(name \0 selector \0 pub
    \0 priv)` of what it read; the script recomputes it with `redis.sha1hex` and refuses with
    `outdated` when the key changed in between. The internal fingerprint stays between PHP and
    Redis and is never returned or logged.
  - **Failures**: a `RedisException` (connection refused, timeout, `READONLY`) is mapped to
    `503 temporary-failure` with `Retry-After: 5`; nothing was written, because the script either
    ran completely or not at all.
- **Rationale**: Rspamd reads the same hashes on every message, so no reload is needed, and legacy,
  `/api/v1` and this API see each other's changes at once (FR-058). The script removes legacy's
  partial-write window (spec parity table: atomicity deviation) without changing the storage.
- **Alternatives considered**: `MULTI`/`EXEC` with `WATCH` on the three hashes was rejected: any
  change to any domain's key aborts the transaction, so busy servers would retry in a loop, and the
  conflict check needs a read inside the transaction. A distributed lock was rejected: it does not
  protect against legacy writers, while the script's compare-and-set does.

### R5. Key generation

- **Decision**: `App\Services\Dkim\DkimKeyGenerator` calls
  `openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $bits,
  'digest_alg' => 'sha256'])`, exports the private key with `openssl_pkey_export()` (PKCS #8,
  `-----BEGIN PRIVATE KEY-----`, as legacy's PHP 8.2 and OpenSSL 3 produce) and trims it. The public
  key is `openssl_pkey_get_details()['key']` with the first and last line removed and the lines
  joined, which is exactly legacy's `implode(array_slice(array_filter(explode(PHP_EOL, …)), 1, -1))`.
  Allowed sizes are 1024, 2048, 3072 and 4096 bits (FR-014); the default is 2048.
- **Key length (FR-007)**: `DkimPublicKey::bits()` wraps the stored base64 in PEM armour, parses it
  with `openssl_pkey_get_public()` and returns `details['bits']`, or `null` when the text does not
  parse or is not RSA. It replaces legacy's guess from the text length.
- **Record value**: `v=DKIM1;k=rsa;t=s;s=email;p=<public key>`, as legacy builds it, and
  `str_split($value, 255)` for `valueParts`, which is legacy's `SPLIT_DKIM_255` split.
- **Rationale**: Byte-compatible with legacy-generated keys; Rspamd accepts both PKCS #1 and PKCS #8.
- **Alternatives considered**: `phpseclib` was rejected: OpenSSL is already in the image, and legacy
  uses it.

### R6. Import validation (FR-019, FR-027)

- **Decision**: `App\Rules\DkimPrivateKey` (rule-code aware, 001 FR-055) checks in this order and
  stops at the first failure:
  1. size ≤ 16,384 bytes → otherwise `tooLong` (`max: 16384`, `unit: bytes`);
  2. normalise `\r\n` and `\r` to `\n` and trim (legacy `preg_replace('~\r\n?~', "\n", …)`);
  3. exactly one PEM block. `ENCRYPTED PRIVATE KEY`, or a `Proc-Type: 4,ENCRYPTED` header →
     `encryptedPrivateKey`. `EC PRIVATE KEY`, `DSA PRIVATE KEY` or `OPENSSH PRIVATE KEY` →
     `unsupportedKeyType` (`allowed: [rsa]`). Any other label, or no block, or several blocks →
     `invalidPrivateKey`;
  4. `openssl_pkey_get_private($text)` after draining `openssl_error_string()`. Failure →
     `invalidPrivateKey`. A PKCS #8 key of another algorithm → `unsupportedKeyType`;
  5. `details['bits']` between 1,024 and 8,192 → otherwise `keyLengthOutOfRange` (`min`, `max`).

  The stored text is the normalised text, exactly what legacy stores. Violations carry only the
  code and its parameters; the English `message` never quotes the input.
- **No leaks**: the request body is never logged (001 R24); the field is named `privateKey`, which
  `App\Logging\RedactSecrets` already drops (`/…key…/` is added to its pattern list for context keys
  `privateKey` and `private_key`); `ImportDkimKeyRequest` overrides `failedValidation()` so that
  the exception carries no input; Laravel's `dontFlash` gets `privateKey`; and the audit details
  whitelist (R20) has no key-material member. A test scans the log output, the audit table and every
  response of the suite for PEM armour (SC-005).
- **Rationale**: RFC 8301 sets 1,024 bits as the minimum for verifiers, and 8,192 bits bounds CPU
  cost. Legacy accepts any parseable key and logs the request, which the spec lists as deviations.

### R7. Names, scope and lookup (FR-001, FR-003, FR-006)

- **Decision**: `App\Services\Dkim\DkimNameResolver` resolves a path or body name for a principal:
  1. **Exact stored name first** (administrators only): when the raw name is a field of
     `DKIM_PUB_KEYS` or `DKIM_SELECTORS` and is neither a domain nor an alias domain, it is that
     unused key, even if it is not normalised (`Example.ORG`, `bücher.example`).
  2. Otherwise **normalise** like 002 FR-006: trim, lower-case, `idn_to_ascii($name,
     IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46)`; failure → `404` for lookups and
     `invalidFormat` (`domainName`) for an import name or a copy target.
  3. **Kind**: `SELECT domain FROM domain WHERE domain = ?`, else `SELECT alias_domain,
     target_domain FROM alias_domain WHERE alias_domain = ?`, else `none`.
  4. **Scope**: administrators reach everything. Domain administrators reach a domain in their
     scope (001 `IdentityService`: active `domain_admins` rows, whatever the domain's `active`) and
     an alias domain whose `target_domain` is in that scope (legacy `hasDomainAccess`). `none`
     names are out of scope for them. Out of scope → `404`, identical to a missing name.
- **Rationale**: Legacy stored names as typed, so upper-case or Unicode leftovers exist and are only
  reachable by their stored spelling. Comparing the exact spelling first, for administrators only,
  keeps them deletable without letting a domain administrator probe raw Redis fields.

### R8. Listing and performance (FR-009, FR-010, SC-012)

- **Decision**: `App\Services\Dkim\DkimEntryQuery` builds the entry set per request without
  caching (conventions: no time-based caching):
  1. names and kinds from MariaDB: `SELECT domain FROM domain` and `SELECT alias_domain,
     target_domain FROM alias_domain`, restricted to the scope for domain administrators;
  2. key existence from `HKEYS DKIM_PUB_KEYS` and `HKEYS DKIM_SELECTORS` (names only);
  3. for administrators, unused names = key names minus domain and alias domain names;
  4. selectors (`HGETALL DKIM_SELECTORS`) are loaded only when the request filters or sorts by
     selector or filters by completeness; public keys (`HGETALL DKIM_PUB_KEYS`) only when it sorts
     by key length or filters by completeness; private key existence uses one pipelined `HEXISTS`
     per candidate only for the completeness filter;
  5. search, filters, sort (ties by name), then `offset`/`limit`;
  6. for the page only: one pipeline of `HGET` for public key and selector and `HEXISTS` for the
     private key per entry, then the representation.

  `q` matches the ASCII name and its Unicode form (`idn_to_utf8`), case-insensitively. `domain`
  matches the domain and every alias domain whose target is that domain.
- **Rationale**: With 5,000 domains and 5,000 alias domains the default request moves about 10,000
  short names from Redis and two indexed queries from MariaDB, well within SC-012's 1 s. Only key
  length sorting needs all public keys (about 4 MB for 10,000 2,048-bit keys), which stays within the
  budget on the internal network; parsed lengths are memoised per public key hash within the request.
- **Alternatives considered**: An API-owned index table was rejected: it would drift from legacy
  writes. Loading all hashes on every request was rejected as needless traffic.

### R9. Private key export (FR-026)

- **Decision**: `SHOW_DKIM_PRIV_KEYS` is read through 001's `MailcowConfig`: `RESTV2_SHOW_DKIM_PRIV_KEYS`
  wins, then the mounted `vars.local.inc.php`/`vars.inc.php`, then the upstream default `false`.
  The export script of 001 (`resources/bin/export-mailcow-vars.php`) gets `SHOW_DKIM_PRIV_KEYS` and
  `autodiscover_config` (R17) added to its exported names. The order of checks is: administrator
  (otherwise `403 forbidden`) → setting enabled (otherwise `403 dkim-private-key-export-disabled`)
  → name resolution (`404`) → key present (`404`) → audit record written → response with
  `Cache-Control: no-store`. A refused attempt is audited (`dkimPrivateKeyExport`, outcome
  `failure`). If the audit insert fails, the key is not returned (`500`).
- **Rationale**: The spec clarification restricts the secret to administrators; a read stays open
  to read-only administrator keys (001 FR-033).

### R10. One DKIM service for features 002, 004 and 010

- **Decision**: `App\Services\Dkim\DkimKeyService` is the single entry point for every DKIM write.
  Features 002 and 004 call it rather than writing Redis themselves (constitution IV: one service
  per aggregate):

  ```php
  // Follow-up step of domain (002 FR-027) and alias domain (004 FR-042) creation.
  public function generateForNewName(string $asciiName, string $selector, int $bits, AuditContext $ctx): DkimCreationStep;
  // DkimCreationStep: outcome created|alreadyExisted|failed, plus the DkimDnsOutcome
  ```

  It skips when the name already has a key (`alreadyExisted`; legacy checks `DKIM_SELECTORS` in
  `mailbox('add', …)` and `DKIM_PUB_KEYS` in `dkim('add')`, this API checks both, FR-008), never
  throws for Redis or publication failures (`failed`), and writes its own `dkimKeyGeneration` audit
  record with `details.trigger = domainCreation | aliasDomainCreation` and the creator's request ID,
  so that the key's history is complete (US8; conventions: one audit record per affected object).
  Feature 002's "not requested" outcome (explicitly empty selector) is decided by the caller, which
  then does not call the service.
- **Alignment with feature 002's plan**: 002's research D10 names the shared classes
  `App\Services\Dkim\DkimKeyGenerator`, `App\Services\Dkim\DkimDnsPublisher`,
  `App\Services\Dkim\DkimDnsOutcome` (enum) and `App\Clients\Ispconfig\IspconfigDnsClient` with the
  adapter `JsonIspconfigDnsClient`, and the settings `RESTV2_DEFAULT_DKIM_SELECTOR`,
  `RESTV2_DKIM_DNS_PUBLICATION` and `RESTV2_ISPCONFIG_*`. This design keeps those names and adds:
  - `App\Clients\SharedStore\DkimKeyStore` (R4). FR-016 requires keys generated at domain or alias
    domain creation to be stored "all together or not at all", so the three `HSET`s that 002's D10
    describes are performed by the store's `create` script instead; `DkimKeyGenerator` only
    creates the key pair (R5).
  - `DkimKeyService` as the facade above generator, store, publisher and audit;
    `generateForNewName()` is what 002's D10 calls `DkimKeyGenerator::generate(domain, selector,
    bits)` plus the publication.
  - `removed` and `noRecordToRemove` in `DkimDnsOutcome` (002 has `published`, `noZone`, `failed`,
    `notConfigured` and `skipped`), and the reason codes of R2.
- **Delivery**: features are implemented in number order, but 002 needs this core. The store,
  generator, publisher, ISPConfig client, settings and `generateForNewName()` form Phase 2 of
  [tasks.md](./tasks.md), to be implemented together with feature 002, before 002's creation tasks.
  Feature 010 later adds the operations on top.
- **Default selector**: 002 FR-028 owns the setting `RESTV2_DEFAULT_DKIM_SELECTOR`
  (`config('restv2.dkim.default_selector')`, default `dkim`, FeldHost `feldhost`). Generation and
  import fall back to it (spec FR-013, FR-017).

---

## 3. FeldHost DKIM DNS publication

### R11. ISPConfig client

- **Decision**: The interface `App\Clients\Ispconfig\IspconfigDnsClient` with the adapter
  `JsonIspconfigDnsClient` (002 D10) and the test fake `FakeIspconfigDnsClient`.
  - **Protocol**: ISPConfig's JSON remote API (`<RESTV2_ISPCONFIG_URL>?<function>`, a JSON body
    whose member names are the remoting function's parameter names) through Laravel's HTTP client.
    It calls the same remoting functions as the fork's SOAP client in `inc/lib/ispconfig.php`:
    `login {username, password}`, `dns_zone_get {session_id, primary_id: {origin: "<name>."}}`,
    `dns_rr_get_all_by_zone {session_id, zone_id}`, `dns_txt_add {session_id, client_id, params}`,
    `dns_txt_update {session_id, client_id, primary_id, params}`, `dns_txt_delete {session_id,
    primary_id}` and `logout {session_id}`. An answer is `{code, message, response}`; `code: ok` is
    success. The adapter signs in once per publication and signs out in `finally` (the fork signs in
    on every call and never signs out).
  - **Time limit (FR-030)**: one `Deadline` of `RESTV2_ISPCONFIG_TIMEOUT` (15 s) per publication;
    each call gets `connectTimeout(min(5, remaining))` and `timeout(remaining)`, and no call starts
    with less than 0.5 s left (`timeout`).
  - **TLS**: verification on unless `RESTV2_ISPCONFIG_VERIFY_TLS=n` (002 D24).
  - **Errors → reason codes**: connection or TLS error → `unreachable`; Guzzle timeout or deadline
    → `timeout`; `login` not `ok` → `authenticationFailed`; any other call not `ok` →
    `remoteFault`; HTTP status other than 200, a body that is not JSON, or a `response` of the wrong
    shape → `invalidResponse`. `detail` is ISPConfig's `message` or the transport error, cut to 500
    characters, with the password replaced by `***` if it occurs.
  - **Configuration** (002 D24): `RESTV2_DKIM_DNS_PUBLICATION` (`y`/`n`, default `n`),
    `RESTV2_ISPCONFIG_URL` (the `json.php` endpoint; for the fork's `inc/ispconfig.inc.php`, its
    `soap_location` with `index.php` replaced by `json.php`), `RESTV2_ISPCONFIG_USERNAME`,
    `RESTV2_ISPCONFIG_PASSWORD` (secret, never logged, returned or cached),
    `RESTV2_ISPCONFIG_DNS_SERVER_ID` (default `1`), `RESTV2_ISPCONFIG_VERIFY_TLS` (default `y`), and
    this feature's `RESTV2_ISPCONFIG_TIMEOUT` (default `15`). The publication counts as enabled only
    when the flag is `y` and the URL, user and password are set; otherwise every outcome is
    `notConfigured`, `publishDkimDnsRecord` answers `dkim-dns-publication-disabled`, and the
    capabilities report `dnsPublicationEnabled: false` (002 D24 logs a warning once).
- **Rationale**: Same remoting functions, remote user and permissions as the fork, so the zone ends
  up identical; no new PHP extension; the adapter is tested with `Http::fake()` and every feature
  test uses the asserting fake (constitution VII).
- **Alternatives considered**: PHP's `SoapClient`, as in the fork, was rejected with 002: it needs
  `ext-soap` and `libxml2` in the image and cannot be faked at the HTTP level. Reading the fork's
  `ispconfig.inc.php` through 001's reader was rejected: its endpoint is the SOAP one, and the
  reader's Redis cache would hold the remote password.

### R12. Publication algorithm and outcomes (FR-029, FR-030, FR-031)

- **Decision**: `App\Services\Dkim\DkimDnsPublisher` (002 D10, extended), returning a
  `DkimDnsOutcome` with reason code and detail:
  1. not enabled (R11) → `notConfigured`, no connection;
  2. sign in; `dns_zone_get(['origin' => "<name>."])`; no zone with exactly that origin →
     `noZone` (a name inside a parent zone has no zone of its own, as in the fork);
  3. `dns_rr_get_all_by_zone`; a record matches a selector when `type = TXT` and `name` equals
     `<selector>._domainkey` or `<selector>._domainkey.<name>.` (case-insensitive);
  4. **publish** (after generation, import, copy, or on request): `params = {server_id: <dns server
     id>, zone, name: "<selector>._domainkey", type: TXT, data: "v=DKIM1;k=rsa;t=s;s=email;p=<pub>",
     ttl: 7200, active: Y, stamp: <now in TZ, Y-m-d H:i:s>}`, client ID `sys_groupid - 1`, as the
     fork does. Update the first matching record, delete further duplicates (SC-009: exactly one
     record), or add one. When the selector changed, delete every record of the old selector first;
     a failure there after a successful publish → `failed` with `staleRecordRemovalFailed`. Outcome
     `published`;
  5. **remove** (after deletion): delete every record of the selector → `removed`, or
     `noRecordToRemove`;
  6. sign out in `finally`.
- **Rationale**: Follows the fork's matching, TTL and ownership, and fixes the cases the spec lists:
  imports and copies publish, selector changes leave no stale record, and every failure is reported.

### R13. Order of key change, publication and audit

- **Decision**: (1) the Lua write (R4); (2) the publication (R12), whose failure never undoes the key
  change; (3) the audit record (001 `AuditLogger`, its own transaction). If (3) fails, a compensating
  script restores the previous selector, public key and private key, but only when the key is still
  the one just written (fingerprint compare), the error is logged, and the response is `500`
  (spec edge case: a change whose audit record cannot be written does not succeed). The DNS zone is
  not rolled back; the log entry names the name so that the operator can publish again.
- **Rationale**: Redis and MariaDB cannot share a transaction. The outcome of (2) must be in the
  audit record (FR-030, FR-054), so the record is written last.
- **Alternatives considered**: Writing the audit record first and updating it with the outcome was
  rejected: audit records are immutable (001 R22). Two audit records per change were rejected
  (SC-010: exactly one).

---

## 4. MTA-STS

### R14. Storage, identifier and version tag

- **Decision**:
  - Table `mta_sts` (upstream, never migrated): `id BIGINT` (policy identifier), `domain
    VARCHAR(255)` primary key, `version`, `mode`, `mx`, `max_age` (all `VARCHAR(255)`), `active
    TINYINT(1)`. Model `App\Models\Mailcow\MtaStsPolicy` (`$primaryKey = 'domain'`, string key, no
    timestamps). Writes only in `App\Services\MtaSts\MtaStsPolicyService`, inside one database
    transaction together with the audit record (001 R5: one connection).
  - **Stored form**, identical to legacy: `version` lower-case `stsv1`, `mode` lower-case, `mx` the
    normalised host names joined by `,`, `max_age` the decimal string, `active` `0`/`1`. Reads map
    `stsv1` to `STSv1` as legacy's `mailbox('get', 'mta_sts')` does.
  - **Identifier (FR-035)**: `date('YmdHis')` in mailcow's time zone (`TZ`), as legacy writes it;
    when that is not greater than the previous identifier, the previous identifier's time plus one
    second, so that two changes within one second still differ. A change of version, mode, the MX
    list (order included) or maximum age renews it; a change of only `active`, or no change, keeps
    it.
  - **Version tag**: 001's `App\Support\Http\ETag` over the JSON encoding of `[domain, version,
    mode, mx, max_age, active, id]` as stored, so legacy edits change it (no secret is involved).
    Updates read the row `FOR UPDATE`, compare `If-Match`, then write.
  - **Conflict**: creation inserts; a duplicate primary key (also from a concurrent creation) →
    `409 mta-sts-policy-exists`.
  - **Policy text (FR-036)**: rendered exactly as `mta-sts.php` prints it: `version: STSv1\nmode:
    <mode>\nmax_age: <max_age>\n` and one `mx: <host>\n` per host.
  - **Alias domains (FR-032)**: a policy is addressed only through `domain`; list and lookup join
    `domain`, so legacy rows stored under an alias domain's name (legacy `add/mta-sts` allowed them)
    are never shown or changed.
- **Rationale**: The publication endpoint and the certificate service read the same row, so the
  stored form must stay what legacy writes (SC-008).

### R15. Validation

- **Decision**: `version` matches `stsv1` case-insensitively (`notInList`, `allowed: [STSv1]`);
  `mode` is one of `enforce`, `testing`, `none` case-insensitively (`notInList`); `maxAge` is an
  integer from 1 to 31,557,600 (`outOfRange`); `mxHosts` is a non-empty list; each entry is trimmed,
  lower-cased and converted with UTS #46, may start with `*.`, and the rest must pass 002's
  domain-name rule (`invalidFormat`, `format: mxHost`, key `mxHosts.<i>`); duplicates are removed
  keeping the first; the joined length must be at most 255 (`tooLong`, `max: 255`, key `mxHosts`).
  The request schemas leave `version` and `mode` as strings, because the contract cannot express
  case-insensitive enumerations cleanly; responses use the `MtaStsMode` enumeration.

---

## 5. DNS diagnostics

### R16. Resolver: feature 009's DNS client, extended

- **Decision**: Diagnostics uses feature 009's `App\Clients\Dns\DnsResolver` (009 research R15:
  interface with `txt()`, `mx()`, `a()`, `aaaa()`; implementation `NativeDnsResolver` wrapping
  `dns_get_record()`; `[]` for "no records" (NXDOMAIN or NODATA); `DnsTemporaryFailure` for a
  resolver failure; TXT strings concatenated) and extends it with `cname()`, `srv()` (a null
  record keeps its target `.`), `ptr()` and `tlsa()` (`dns_get_record($name, 52, …, true)` raw
  mode, decoded as legacy does into `<usage> <selector> <matching type> <hex>`). The test double is
  009's `Tests\Support\Dns\FakeDnsResolver`, extended the same way and with a programmable delay
  on the fake clock.
  - **Server (FR-046)**: the container resolves through mailcow's `unbound-mailcow`
    (`dns: ["${IPV4_NETWORK:-172.22.1}.254"]` in the override, 009), the resolver of the mail
    services, so the result shows what they see.
  - **Time limits (FR-048)**: `dns_get_record()` cannot be interrupted, so each lookup is bounded by
    the container's `dns_opt: ["timeout:2", "attempts:2"]` (009), about 4 s, which is within the
    5-second limit. The run has one `App\Support\Clock\Deadline` of `RESTV2_DNS_RUN_TIMEOUT` (30 s):
    before each lookup the service checks that at least `RESTV2_DNS_LOOKUP_TIMEOUT` (5 s) remains;
    otherwise this and every remaining check report `lookupFailed` with `runTimeout` without a
    lookup, so the run returns within 30 s.
  - **Classification**: `[]` → `missing`; `DnsTemporaryFailure` → `lookupFailed` with
    `lookupTimeout` (`timeoutSeconds`) when the call lasted at least the resolver bound, otherwise
    `lookupError` (SERVFAIL, which unbound also answers for a DNSSEC validation failure, REFUSED or
    a network error). Never "missing" for a failure (spec edge case).
- **Rationale**: `docs/conventions.md` and the orchestrator require reusing 009's client instead of
  a second DNS stack. The native resolver already tells "no records" from failures, which is what
  the statuses need; the container options bound each lookup; the run deadline bounds the whole run.
- **Alternatives considered**: `mikepultz/netdns2` (per-call timeouts and RCODEs) was rejected: it
  would duplicate 009's client and add a dependency that 009 rejected for the same reason. Exact
  RCODEs are not needed by the contract (`lookupError` has no parameters).

### R17. Expected values and comparisons (FR-040 to FR-043)

- **Decision**: `App\Services\DnsDiagnostics\ExpectedRecordSet` builds the checks in legacy order:
  - **Server values**: host name `MAILCOW_HOSTNAME` (lower-cased); ports from `$autodiscover_config`
    (via `MailcowConfig`: `imap.port`/`tlsport`, `pop3.port`/`tlsport`, `smtp.port`/`tlsport`, and
    `sieve.port` or `SIEVE_PORT`), whose upstream definition reads the `mailcow.conf` variables
    `IMAPS_PORT`, `IMAP_PORT`, `POPS_PORT`, `POP_PORT`, `SMTPS_PORT`, `SUBMISSION_PORT` and
    `SIEVE_PORT` (last `:`-separated part, so `127.0.0.1:993` gives 993); with neither, the mailcow
    defaults 993, 143, 995, 110, 465, 587 and 4190. The TLSA name uses `smtp.server`. The HTTPS port
    of the autodiscover SRV record is the request's `Host` port, else 443, as legacy derives it.
  - **Records**: as the spec's FR-040 table. MTA-STS checks run when the policy of the name (for an
    alias domain, of its target domain) is active, with the stored identifier. The DKIM check runs
    when the name has a key with a selector and a public key. When the shared store cannot be read,
    the selector is unknown, so the check is emitted with id `dkim`, record name
    `_domainkey.<name>`, status `expectedUnknown` and reason `dkimKeyUnavailable` (spec edge case).
    POP3 and POP3S check `_pop3._tcp`/`_pop3s._tcp` first: a null SRV
    (target `.`) becomes the expected value with note `nullServiceRecord`.
  - **Comparisons**: host names case-insensitive without the trailing dot; IPv6 compared as packed
    addresses and shown expanded (`expand_ipv6`, as legacy shows them); TLSA data lower-case; MX
    any record whose exchange is the host name; A/AAAA of the host name: a CNAME in the answer →
    `mismatch` with `hostIsAlias`; CNAME checks: a CNAME to the host name matches; with no CNAME
    (`noData`), the name's A and AAAA sets are compared with the host name's, and IPv4 equal plus
    IPv6 equal or both empty → `match` with note `matchedByAddressRecords`; SRV target and port;
    TXT strings joined; DKIM: a match when a found record's `p=` tag equals the stored public key,
    tags parsed by `;` with whitespace ignored; MTA-STS record: tags `v` and `id` equal, spaces
    around separators ignored; DMARC and TLS reporting: `informational` when found, else `missing`
    (both optional).
  - **Server-wide checks**: administrators only (FR-041); a domain administrator's result carries
    the note `serverChecksOmitted`. Without a public IPv6 address the AAAA and IPv6 PTR checks are
    left out and the result carries `noPublicIpv6`. PTR names are built from the public addresses
    (`in-addr.arpa`, nibble `ip6.arpa`).
- **Rationale**: Legacy's record set and order (SC-001-style parity), with the statuses and
  comparison fixes the spec states as deviations.

### R18. SPF evaluation

- **Decision**: `mlocati/spf-lib` ^3.3 (MIT; it depends on `mlocati/ip-lib` and `mlocati/idna`, both
  MIT) evaluates RFC 7208 `check_host()` through `App\Services\DnsDiagnostics\Spf\SpfResolverAdapter`,
  which implements `SPFLib\DNS\Resolver` on top of R16's resolver and deadline.
  - Before evaluating, the service counts the name's TXT records starting with `v=spf1` (followed
    by a space or the end): none → `missing`; more than one → `mismatch` with `spfMultipleRecords`
    (`count`).
  - It evaluates the public IPv4 address and, when there is one, the public IPv6 address, with the
    name as `MAIL FROM` domain and the host name as `HELO`. Both `pass` → `match`. Otherwise
    `mismatch` with `spfNotPass` (`address`, `result`). The library's
    `TooManyDNSLookupsException` and `TooManyDNSVoidLookupsException` map to `spfTooManyLookups`
    (`limit: 10`) and `spfTooManyVoidLookups` (`limit: 2`); other `permerror` or `temperror` →
    `spfError`. A resolver timeout inside the evaluation → `lookupFailed`.
  - Without a public IPv4 address → `expectedUnknown` with `publicAddressUnknown`.
  - Guidance always lists `spfAuthorizeAddresses` (`ipv4`, `ipv6`) and `spfSyntaxReference`
    (`url: http://www.open-spf.org/SPF_Record_Syntax/`), plus `spfSuggestedRecord` when
    `RESTV2_SUGGESTED_SPF_RECORD` is set (FR-050; FeldHost `v=spf1 mx a ~all`).
- **Rationale**: Legacy's `get_spf_allowed_hosts()` ignores ranges, qualifiers and lookup limits
  (spec deviation). The library is RFC 7208-complete, lets the resolver be injected, and reports
  the lookup limits as distinct exceptions, which map to stable codes.
- **Alternatives considered**: An in-house evaluator was rejected: macros, `exists`, `ptr`, void
  lookups and limits make it a sizeable, error-prone port for one check.

### R19. TLSA and public addresses

- **TLSA (FR-042)**: feature 009's SMTP client `App\Clients\Smtp\SmtpProbe` (`StreamSmtpProbe`,
  009 research R13) is extended with a TLS-only request: `SmtpProbeRequest` gets
  `stopAfterTls: true`, `capturePeerCertificate: true` and a per-request deadline (5 s here), and
  `SmtpProbeResult` gets the peer certificate (PEM). The probe connects to the SMTP host of
  `$autodiscover_config` on port 25, reads the `220` greeting, sends `EHLO <MAILCOW_HOSTNAME>`
  (falling back to `HELO`, as 009 does), `STARTTLS`, and completes the handshake without
  verification, then `QUIT`. `App\Services\DnsDiagnostics\TlsaDigest` computes `3 1 1 ` + the
  SHA-256 of the DER `SubjectPublicKeyInfo`, as legacy `generate_tlsa_digest($host, 25, 1)`. The
  probe's failure steps map to `certificateUnavailable` causes: `connect` → `connectionFailed`,
  `timeout` → `timeout`, no `STARTTLS` advertised or refused → `starttlsRefused`, `tls` →
  `tlsHandshakeFailed`, no certificate → `noCertificate`. The found TLSA records are always listed.
  Legacy sends `HELO tlsa.generator.local`; `EHLO` is used because servers advertise `STARTTLS`
  only after `EHLO` (plan parity note 1). Feature tests use 009's `FakeSmtpProbe`; the TLS-only
  path is tested against 009's `ScriptedSmtpServer`.
- **Public addresses (FR-042)**: `App\Clients\Dns\PublicAddressDetector` uses Laravel's HTTP client
  with Guzzle's `force_ip_resolve` (`v4`, `v6`), `connectTimeout(3)` and `timeout(5)` against
  `RESTV2_IP_ECHO_URL_IPV4` (`http://ip4.mailcow.email`) and `RESTV2_IP_ECHO_URL_IPV6`
  (`http://ip6.mailcow.email`), as legacy does. The trimmed body must validate as an address of
  that family. `RESTV2_PUBLIC_IPV4` and `RESTV2_PUBLIC_IPV6` override detection; `RESTV2_PUBLIC_IPV6=none`
  declares no IPv6 without asking. No IPv6 answer means no public IPv6 address; no IPv4 answer
  means "unknown".
- **Reuse (FR-049)**: both facts are cached in Redis for `RESTV2_DNS_FACTS_TTL` (600 s):
  `restv2:dns-facts:addresses` and `restv2:dns-facts:tlsa:<host>`. Failures are not cached, so the
  next run retries.

### R20. Rate limit and headers (FR-049, orchestrator item 5)

- **Decision**: Laravel's `RateLimiter` on the `restv2` cache store, key
  `dns-diagnostics:<sha256(caller)>` (Redis `restv2:dns-diagnostics:<hash>` plus `:timer`), where the
  caller is `user:<username>` for sessions and `apiKey:<id>` for keys. Limit
  `RESTV2_DNS_DIAGNOSTICS_PER_MINUTE` (10) per 60-second fixed window. Order: authentication → role
  (`403`) → limit check (`429` when exhausted, before any lookup) → name resolution (`404`, not
  counted) → `hit()` → run. Every `200` and `429` carries `RateLimit-Limit`, `RateLimit-Remaining`
  and `RateLimit-Reset` (seconds, `availableIn()`), the header names of the IETF HTTPAPI draft that
  the orchestrator named. The `429` also carries `Retry-After` and `retryAfter` (`max(1,
  availableIn())`), with type `too-many-requests`.
- **Rationale**: Counting only started runs protects outbound lookups (OWASP API4) without
  penalising a mistyped name; the limit is checked first so that an exhausted caller makes no
  database query either.

### R21. Zone file (FR-047)

- **Decision**: `App\Services\DnsDiagnostics\ZoneFileRenderer` writes `$ORIGIN <name>.` and one line
  per check whose record name equals or ends with `.<name>`, with absolute owner names
  (`<record>.`), class `IN`, and:
  - MX `10 <host>.`; CNAME `<host>.`; SRV `0 1 <port> <target>.` (a null record `0 0 0 .`); A/AAAA
    the address; TXT the value quoted, with `"` and `\` escaped, split into strings of at most 255
    characters;
  - checks with an exact expected value use it; SPF, DMARC and TLS reporting use the values found,
    or `; <owner> IN TXT "…"` with the guidance as a commented placeholder when none was found;
  - server-wide records (outside the name) are left out, as in legacy.

  A unit test feeds every rendering to `named-checkzone` (BIND 9, `bind-tools` in the dev image) to
  prove it parses (SC-007).
- **Rationale**: Legacy's output is relative to `$ORIGIN`, omits MX preference and SRV fields, and
  leaves TXT unquoted, which breaks at the first `;` (spec deviation).

---

## 6. Cross-cutting

### R22. Audit

- **Decision**: 001's `AuditLogger` with these event types (appended to `AuditEventType`) and
  target types (`AuditTarget.type`: `dkimKey`, `mtaStsPolicy`; `id` and `name` are the name or
  domain):

  | `eventType` | `details` keys (whitelist) |
  |---|---|
  | `dkimKeyGeneration` | `selector`, `keyLength`, `overwriteRequested`, `old` and `new` (`selector`, `keyLength`, `publicKeySha256`), `trigger` (`api`, `domainCreation`, `aliasDomainCreation`), `dnsPublication` (`status`, `reasonCode`, `detail`) |
  | `dkimKeyImport` | as generation, without `trigger` |
  | `dkimKeyCopy` | as import, plus `sourceName` |
  | `dkimKeyDeletion` | `old`, `dnsPublication` |
  | `dkimPrivateKeyExport` | `selector`, `publicKeySha256` |
  | `dkimDnsPublication` | `selector`, `dnsPublication` |
  | `mtaStsPolicyCreation` | `version`, `mode`, `mxHosts`, `maxAge`, `active`, `policyId` |
  | `mtaStsPolicyUpdate` | `changes` (`{member: {old, new}}`), `oldPolicyId`, `newPolicyId` |

  - **Refusals** (`docs/conventions.md` "Refusal auditing"): every state-changing route of this
    feature, and the export route, declares `->defaults('restv2.audit', ['event' => …, 'target' =>
    'dkimKey' | 'mtaStsPolicy'])`, and the shared `App\Services\Audit\RefusalAuditor` (008 R-refusals,
    or the equivalent the first implemented feature created) records each `4xx` refusal of an
    authenticated caller, whether this feature or 001's pipeline raised it: read-only key scope,
    restricted session, role, scope, conflict, outdated, validation, disabled operation. `401`s
    and anti-forgery failures are not recorded. This feature registers the target resolvers
    `dkimKey` (the normalised path name, or the body `name` for generation, truncated to 255) and
    `mtaStsPolicy` (the path domain, or the body `domain`). `503` temporary failures are recorded
    by `DkimKeyService` itself, since `RefusalAuditor` handles `4xx` only.
  - **Failure reasons** use the shared `AuditFailureReason` values, with `details.problemType` and,
    for `422`, `details.errors` (codes and parameters only, never input values): `permissionDenied`
    (`forbidden`, `insufficient-key-scope`, `pending-action-required`), `notFound`,
    `versionOutdated`, `validationFailed`, `conflict` (`dkim-key-exists`, `mta-sts-policy-exists`,
    incomplete copy source), `featureDisabled` (`dkim-private-key-export-disabled`,
    `dkim-dns-publication-disabled`) and `temporaryFailure`. No value is added to the enumeration.
  - `publicKeySha256` is the SHA-256 of the DER public key (a fingerprint, FR-054); no private key
    or ISPConfig password ever enters `details`.
  - A policy patch that changes nothing is not audited (001 R22: idempotent no-ops are not
    audited). Diagnostics runs, lists and reads are not audited (FR-039, conventions); the export is
    the exception (conventions: views of sensitive content).
- **Filter (FR-055)**: clients use the generic `targetType` and `targetId` filters of
  `listAuditEvents` (added by 002, FR-058 there); this feature only adds the `AuditTarget` values
  `dkimKey` and `mtaStsPolicy` and adds no filter parameter (conventions: history).

### R23. Problem types and status codes

- **Decision**: New types, documented in `docs/problems.md`:
  `dkim-key-exists` (409), `mta-sts-policy-exists` (409), `dkim-private-key-export-disabled`
  (403), `dkim-dns-publication-disabled` (403). Reused: `temporary-failure` (503, added by 002),
  `too-many-requests` (429, 001), `validation-failed`, `forbidden`, `insufficient-key-scope`, and
  `about:blank` for `404`, `412` and the "incomplete copy source" `409`. The "outdated version" of
  FR-056 is 001's `412` (`about:blank`, `PreconditionFailed`).
- **Copy from an incomplete key**: a source without its selector, public key or private key cannot
  be copied completely; it answers `409` (`about:blank`, detail names the missing parts) rather
  than copying a broken key as legacy does.
- **Rationale**: `403` for both "disabled" types: the operation exists but the server's operator
  does not allow it, which no retry or other input changes. `503` for the shared store follows 002
  and 001 (Complexity Tracking).

### R24. Server settings

| Setting | Default | Source and meaning |
|---|---|---|
| `RESTV2_DEFAULT_DKIM_SELECTOR` | `dkim` | 002 FR-028 and D24 (FeldHost `feldhost`) |
| `RESTV2_SHOW_DKIM_PRIV_KEYS` | from `vars*.inc.php`, else `false` | Private key export (FR-026) |
| `RESTV2_AUTODISCOVER_CONFIG` | from `vars*.inc.php`, else derived from the port variables | Service ports and SMTP host for diagnostics (JSON, R17) |
| `IMAP_PORT`, `IMAPS_PORT`, `POP_PORT`, `POPS_PORT`, `SMTPS_PORT`, `SUBMISSION_PORT`, `SIEVE_PORT` | mailcow defaults | `mailcow.conf` names (conventions) |
| `RESTV2_DKIM_DNS_PUBLICATION` | `n` | FeldHost ISPConfig publication (FR-028; 002 D24) |
| `RESTV2_ISPCONFIG_URL`, `RESTV2_ISPCONFIG_USERNAME`, `RESTV2_ISPCONFIG_PASSWORD`, `RESTV2_ISPCONFIG_DNS_SERVER_ID`, `RESTV2_ISPCONFIG_VERIFY_TLS` | empty, empty, empty, `1`, `y` | Connection (002 D24, R11); the password is a secret |
| `RESTV2_ISPCONFIG_TIMEOUT` | `15` | Publication limit in seconds (FR-030) |
| `RESTV2_DNS_LOOKUP_TIMEOUT` | `5` | Seconds reserved per lookup by the run deadline, and the limit of the echo request and the certificate fetch (FR-048); the resolver's own bound is the container's `dns_opt` (009, about 4 s), which must stay below it |
| `RESTV2_DNS_RUN_TIMEOUT` | `30` | Seconds per run (FR-048) |
| `RESTV2_DNS_DIAGNOSTICS_PER_MINUTE` | `10` | Runs per caller per minute (FR-049) |
| `RESTV2_DNS_FACTS_TTL` | `600` | Reuse of public addresses and certificate digest (FR-049) |
| `RESTV2_PUBLIC_IPV4`, `RESTV2_PUBLIC_IPV6` | empty (detect) | Explicit public addresses; `none` for no IPv6 |
| `RESTV2_IP_ECHO_URL_IPV4`, `RESTV2_IP_ECHO_URL_IPV6` | `http://ip4.mailcow.email`, `http://ip6.mailcow.email` | Echo service (legacy) |
| `RESTV2_SUGGESTED_SPF_RECORD` | empty | FR-050 (FeldHost `v=spf1 mx a ~all`) |

The spec's assumption that these limits are operator-changeable is met by the settings above. The
capabilities read reports the effective values.

### R25. Dependencies (constitution VIII)

| Package | Licence | Scope | Justification |
|---|---|---|---|
| `mlocati/spf-lib` ^3.3 (+ `mlocati/ip-lib` ^1.11, `mlocati/idna` ^1, MIT) | MIT | runtime | RFC 7208 evaluation with lookup limits and an injectable resolver (R18) |
| `bind-tools` (Alpine) | MPL-2.0 | dev image only | `named-checkzone` for the zone-file test (R21) |

All are GPL-3.0-compatible (MIT; MPL-2.0 for a dev-only tool that is not distributed with the
code). Without a package: RSA keys (ext-openssl), IDNA (ext-intl, already present), DNS (009's
`dns_get_record()` client, extended), the SMTP certificate fetch (009's SMTP client, extended), the
ISPConfig JSON remote API and the echo service (Laravel's HTTP client), the rate limiter (Laravel).
No PHP extension is added.

### R26. Tests

- **Decision**:
  - Feature tests on MariaDB with the `2026-09a` fixture and the real Redis (database 15), so every
    DKIM side effect is asserted on the real `DKIM_*` hashes, including the exact field names and
    the stored private key text.
  - ISPConfig, DNS, the SMTP probe and the echo service are faked: `FakeIspconfigDnsClient` (002)
    holds in-memory zones and records, records every call, and can fail per call (unreachable,
    timeout, fault); 009's `FakeDnsResolver`, extended, serves a programmable zone, throws
    `DnsTemporaryFailure` on demand and advances a fake clock; 009's `FakeSmtpProbe`, extended,
    returns a certificate or a failing step; the echo service uses `Http::fake()`.
  - Adapters get their own tests: `JsonIspconfigDnsClient` with `Http::fake()` (exact JSON bodies
    and the time limit); the new methods of 009's `NativeDnsResolver` in 009's
    `NativeDnsResolverTest`; the TLS-only path of 009's `StreamSmtpProbe` against 009's
    `ScriptedSmtpServer` with a generated self-signed certificate.
  - Parity: `tests/fixtures/legacy/dkim/` holds a key pair generated by legacy's code path (an
    extraction of `dkim('add')`'s key handling, like 001's `verify_hash` extraction), so the stored
    format is compared byte for byte.
  - Every request and response is validated against the contract (001 R13), and the contract
    coverage check requires all 14 operations.
- **Rationale**: Constitution VII: MariaDB and Redis real, external services faked with asserting
  fakes.

### R27. Conventions check (`docs/conventions.md`)

- Scope: out-of-scope names answer `404`; `403` only for roles (domain administrators importing,
  copying, deleting, exporting, publishing again or overwriting; mailbox users everywhere).
- Keys act as their owner; read-only keys may list, read, export and run diagnostics, and are
  refused on generation, import, copy, deletion, publishing again and policy writes.
- Lists default to 25 entries (maximum 1,000) with `q`, `sort`, `order` and filters.
- Capabilities: `GET /capabilities/dns` (`getDnsCapabilities`), read-only keys allowed, no secrets.
- History: the generic `targetType`/`targetId` filters; this feature adds only `AuditTarget` values.
- Refusal auditing: the shared `RefusalAuditor` with route defaults, shared `AuditFailureReason`
  values; `401`s and CSRF failures are not recorded.
- Version tags: strong ETags over stored values only, on every representation and list entry that
  has a stored key or policy (`etag: null` for an entry without a key); the private key enters only
  as an HMAC keyed with the app key.
- Secrets: private keys are write-only except the gated export; the ISPConfig password is never
  returned, logged or cached.
- Legacy configuration: `SHOW_DKIM_PRIV_KEYS` and `$autodiscover_config` come from 001's reader;
  FeldHost behaviour (default selector, ISPConfig publication, suggested SPF record) is a server
  setting with the upstream default.
- Creation follow-ups (002, 004) use `generateForNewName`, which reports its outcome and never rolls
  back the domain.
- **Bulk actions**: the conventions call for bulk actions where legacy offers mass actions, and the
  legacy DKIM screen has a mass delete. Spec FR-059 governs this feature's behaviour and excludes
  batch operations (creation in bulk is excluded by the conventions anyway). This plan follows the
  spec and lists bulk deletion as an owner-level question; adding it later is an additive change.
