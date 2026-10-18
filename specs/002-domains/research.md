# Research: Domains

**Feature**: `specs/002-domains` | **Date**: 2026-10-18 | **Plan**: [plan.md](./plan.md)

This document records only the decisions of feature 002. Everything shared is inherited from
feature 001 and is not repeated: the stack (Laravel 13, PHP 8.4), the Docker dev stack, the schema
fixture, the single `mysql` connection, the `restv2_` and `restv2:` prefixes, problem URIs,
contract validation in tests, `ListQuery`, `ETag`, `AuditLogger`, `MailcowConfig`, the middleware
pipeline and the `MailcowDateTime` cast (see
[001 research](../001-auth-sessions-api-keys/research.md)). Each entry has a **Decision**, its
**Rationale** and the **Alternatives considered**. Precedence: legacy behaviour (constitution IV),
then the constitution and `docs/conventions.md`, then best practice.

Legacy references are paths in mailcow-dockerized `2026-09a`
(`git -C ~/Projects/mailhosting show 2026-09a:<path>`). For this feature, the FeldHost fork
(`feldhost-202609a`) differs only in the default DKIM selector and in the ISPConfig DKIM push
(`functions.dkim.inc.php`, `inc/lib/ispconfig.php`); `functions.mailbox.inc.php`, `functions.inc.php`,
`functions.ratelimit.inc.php`, `json_api.php` and the domain templates were diffed and are identical.

---

## 1. Contract

### D1. Modules, paths and operations

- **Decision**: Two modules, 16 operations:
  - `api/modules/domains/`: `GET|POST /domains`, `POST /domains/bulk-actions`,
    `GET|PATCH|DELETE /domains/{domain}`, `GET /domains/{domain}/deletion-preview`,
    `GET|PUT /domains/{domain}/footer`, `GET /capabilities/domains`, `GET /domain-tags`;
  - `api/modules/domain-templates/`: `GET|POST /domain-templates`,
    `GET|PATCH|DELETE /domain-templates/{templateId}`.

  Plus two query parameters on 001's `GET /audit-events` (`targetType`, `targetId`, FR-058).
- **Rationale**:
  - Single-label names such as `tags` or `capabilities` are valid domain names (legacy accepts
    them, spec edge case). `GET /domains/tags` would shadow the domain `tags`, so the read-only
    collections that are not domains live at the top level: the capabilities read at
    `/capabilities/domains` (operation `getDomainsCapabilities`, the API-wide
    `/capabilities/{area}` pattern of `docs/conventions.md`) and the tag list at `/domain-tags`. `POST /domains/bulk-actions` cannot collide, because `/domains/{domain}` has no
    `POST`; it matches the bulk path of feature 008 (`/sync-jobs/bulk-actions`).
  - The deletion preview and the footer are sub-resources of the domain they belong to
    (constitution II: no verbs, ownership nesting). The preview is a `GET` because it is safe.
  - The footer is replaced as a whole (FR-052), so it is `PUT`, not `PATCH`.
- **Alternatives considered**: `GET /domains/-/capabilities` was rejected as unusual. One bulk path
  per action (`/domains/activation`) was rejected because 008 uses one `bulk-actions` path with an
  `action` member. `DELETE /domains` with a body was rejected: bodies on `DELETE` are poorly
  supported by clients and proxies.

### D2. Representation

- **Decision**: `Domain` is flat for the writable attributes, so that validation errors use simple
  keys (`maxMailboxes`) and JSON Merge Patch works without nesting. Usage figures are nested in
  `usage` (read-only). Names: `name` (ASCII, the path identifier), `unicodeName`, `description`,
  `active`, `maxMailboxes`, `maxAliases`, `defaultMailboxQuota`, `maxMailboxQuota`, `domainQuota`,
  `backupMx`, `relayAllRecipients`, `relayUnknownOnly`, `globalAddressList`, `tags`, `relayhost`
  (`{id, hostname}` or `null`), `rateLimit` (`{value, frame}` or `null`), `createdAt`, `modifiedAt`,
  `usage`, `domainAdmins` (administrators only, omitted otherwise) and `etag`.
  - Quotas are bytes (constitution II); the casts multiply mailcow's MiB by 1,048,576. Requests
    must send whole MiB (`notMultipleOf`, `factor: 1048576`).
  - `rateLimit.frame` is `second|minute|hour|day`, mapped from legacy `s|m|h|d`.
  - `relayhost` reads `domain.relayhost` (a string ID, `'0'` = none) joined to `relayhosts` for the
    host name; it is `null` for `'0'`, empty or non-numeric values.
- **Rationale**: Legacy terms are kept where they are mailcow vocabulary (`backupMx`). The
  creation response is `Domain` plus `creationReport` (`allOf`), so clients get one shape.
- **Alternatives considered**: Nesting limits and quotas (`limits.maxMailboxes`) was rejected: it
  complicates merge patches and error keys for no gain.

### D3. Problem types and rule codes

- **Decision**: Six new problem types, documented in `docs/problems.md`:
  `domain-exists` (409), `domain-not-empty` (409, extensions `mailboxes`, `resources`),
  `domain-template-name-exists` (409), `domain-template-protected` (409), `attribute-not-permitted`
  (403, extension `attributes`) and `temporary-failure` (503, `Retry-After: 10`). The last two are
  generic, so that features 003 to 013 reuse them.
  - An outdated version tag is `412 about:blank`, the convention of feature 001 and
    `PreconditionFailed.yaml`. Bulk items report it as outcome `outdated` with that problem.
  - New validation rule codes: `notMultipleOf` (`factor`), `mustNotExceed` (`field`, `value`),
    `belowInUse` (`inUse`) and `reserved`. Read-only members sent with a different value reuse
    `immutable`, which feature 008 introduced for the same rule. New `invalidFormat` formats:
    `domainName`, `dkimSelector`, `email`. `tooLong` on footer texts carries `unit: bytes`.
- **Rationale**: FR-059 asks for these error types and for stable rule codes with parameters
  (the value in use for FR-033, the limit for tags and bulk). The protected template is a state
  conflict, so `409`, a code the constitution lists. A distinct `412` type was rejected to stay
  consistent with feature 001.
- **Alternatives considered**: `422` for the protected template was rejected: the request is valid,
  the resource's state forbids it. A domain-specific `domain-temporary-failure` was rejected:
  every feature that writes the shared store needs the same problem.

---

## 2. Reading domains

### D4. Name normalisation and addressing

- **Decision**: `App\Support\Domain\DomainName` ports legacy normalisation and validation:
  `normalize()` trims, lower-cases (`mb_strtolower`) and converts with
  `idn_to_ascii($n, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46)`; `isValid()` ports
  `is_valid_domain_name()` exactly (the three regular expressions: labels of letters, digits and
  inner hyphens; 1–253 characters; labels of 1–63); `toUnicode()` uses `idn_to_utf8()` and falls
  back to the ASCII form. In paths, a name that does not normalise, is invalid, does not exist, is
  outside the scope or is an alias domain answers `404`. In bulk targets it gives `notFound`. In a
  creation body it gives `invalidFormat` with `format: domainName`.
- **Rationale**: FR-006 and FR-019 demand legacy normalisation. The class is shared with features
  003 and 004, which address the same names.

### D5. Usage figures

- **Decision**: `App\Services\Domains\DomainUsageCalculator` computes the figures of FR-008 with the
  legacy queries of `mailbox('get', 'domain_details')` for a set of domains at once:
  - mailboxes and quota allocated: `SELECT domain, COUNT(*), COALESCE(SUM(quota),0), MAX(quota)
    FROM mailbox WHERE kind = '' AND domain IN (…) GROUP BY domain` (legacy's `kind = NULL` branch
    never matches, so `kind = ''` is exact);
  - resources: `kind <> ''` (resources are `location`, `thing`, `group`);
  - storage and messages: `SUM(quota2.bytes)`, `SUM(quota2.messages)` over all mailboxes of the
    domain, resources included;
  - aliases: `SELECT COALESCE(ad.target_domain, a.domain) AS d, COUNT(*) FROM alias a LEFT JOIN
    alias_domain ad ON ad.alias_domain = a.domain WHERE (a.domain IN (…) OR ad.target_domain IN
    (…)) AND a.address NOT IN (SELECT username FROM mailbox) GROUP BY d`, the displayed count of
    legacy;
  - alias domains: `COUNT(*) FROM alias_domain GROUP BY target_domain`;
  - `maxNewMailboxQuota = max(0, min(domainQuota − quotaAllocated, maxMailboxQuota))` and
    `defaultNewMailboxQuota = min(maxNewMailboxQuota, defaultMailboxQuota)`. Legacy can return a
    negative value; FR-008 says "never below zero".

  A list request first selects one page of names (D6), then computes the figures for those names
  only, with five grouped queries per page instead of legacy's six queries per row.
- **Rationale**: SC-001 needs legacy's figures; SC-005 needs a page of 25 within 1 s on 5,000
  domains and 100,000 mailboxes. Every query uses an index (`mailbox.domain`, `alias.domain`,
  `alias_domain.target_domain`, `quota2` primary key).
- **Alternatives considered**: One query per domain (legacy `search/domain`) was rejected for
  SC-005. A cache of figures was rejected (`docs/conventions.md`: no time-based caching).

### D6. List, search, filters and sorting

- **Decision**: `App\Services\Domains\DomainQuery` builds the page query on `domain d`, scoped for
  domain administrators by `d.domain IN (SELECT domain FROM domain_admins WHERE active = '1' AND
  username = ?)` (001's scope query):
  - `q` (shared `Search.yaml`, escaped by `ListQuery`): `d.domain LIKE ? OR d.description LIKE ? OR
    EXISTS (SELECT 1 FROM tags_domain t WHERE t.domain = d.domain AND t.tag_name LIKE ?) OR
    d.domain IN (:unicodeMatches)`. `unicodeMatches` are the names in scope that contain `xn--`
    and whose `toUnicode()` form contains `q` (`mb_stripos`), found with one extra query and a PHP
    filter. A punycode name cannot be matched by `LIKE` on its readable form otherwise.
  - `active`, `backupMx`: equality. `tag[]`: `EXISTS (… t.tag_name IN (?, …))`; the column's
    `utf8mb4_general_ci` collation makes the comparison case-insensitive, and the values are
    trimmed.
  - `sort`: stored columns sort directly; `mailboxes`, `aliases`, `quotaAllocated` and
    `storageUsed` join grouped derived tables (the queries of D5 without the `IN` restriction).
    Ties: `d.domain ASC`. NULL `modified` sorts first in ascending order (MariaDB default).
  - The total uses the same filters without `LIMIT`.
- **Rationale**: FR-011 to FR-015 and the legacy `search/domain` columns. The derived tables are
  only joined when such a sort is asked for.

### D7. Version tags

- **Decision**: `App\Services\Domains\DomainVersion` computes strong ETags with 001's
  `App\Support\Http\ETag` over canonical JSON:
  - **Domain**: `domain`, `description`, `aliases`, `mailboxes`, `defquota`, `maxquota`, `quota`,
    `relayhost`, `backupmx`, `gal`, `relay_all_recipients`, `relay_unknown_only`, `active`,
    `created`, and the tags sorted by `mb_strtolower`. Not `modified` (it only changes when one of
    these does), not the usage figures, not `rateLimit` (stored in Redis and owned by feature 006),
    not `domainAdmins` (owned by feature 007). `created` distinguishes a domain from an earlier one
    with the same name.
  - **Footer**: `domain`, `html ?? ''`, `plain ?? ''`, `skip_replies`, both exclusion lists. A missing
    row and a stored empty footer give the same tag.
  - **Template**: `id`, `template`, and the attributes decoded and re-encoded with sorted keys.

  The same function serves single reads, list entries, bulk item checks and the deletion preview,
  so the tags are equal everywhere (FR-015, AS-10). It reads the rows inside the writing
  transaction after `SELECT … FOR UPDATE`, so `If-Match` is compared against the locked state.
- **Rationale**: FR-041 and `docs/conventions.md` (stored settings only). Legacy writes never touch
  a version column, so the tag must be computed from the data itself.

---

## 3. Writing domains

### D8. Creation flow and atomicity

- **Decision**: `App\Services\Domains\DomainCreator`:
  1. Resolve the template (`templateId`, else the row named `Default` of `type = 'domain'`, else
     the built-in values of FR-022) and merge the request over it. Validate (D11).
  2. Check the shared store (`SharedStore::ping()`); on failure answer `503 temporary-failure`.
  3. In one database transaction:
     - `SELECT alias_domain FROM alias_domain WHERE alias_domain = ? FOR UPDATE` (a gap lock that
       blocks a concurrent alias domain of the same name through this API) and
       `SELECT domain FROM domain WHERE domain = ? FOR UPDATE`; either row → `409 domain-exists`;
     - `DELETE FROM sender_acl WHERE external = 1 AND send_as LIKE '%@<domain>'` (legacy order);
     - `INSERT INTO domain (domain, description, aliases, mailboxes, defquota, maxquota, quota,
       backupmx, gal, active, relay_unknown_only, relay_all_recipients)`; a duplicate-key error
       from a concurrent insert → `409 domain-exists`;
     - `INSERT INTO tags_domain` for each normalised tag;
     - `HSET DOMAIN_MAP <domain> 1`; on failure roll back → `503`;
     - the audit record (`domainCreation`) with `creationReport: "pending"` (D16);
     - commit. When the commit fails after `HSET`, the entry is removed again (`HDEL`).
  4. The follow-up steps, each caught on its own (FR-024, FR-025): rate limit (D9), DKIM key and
     publication (D10), groupware directory update (D13), groupware restart (D13).
  5. Complete the audit record with the report and return `201` with `Location` and `ETag`.
- **Rationale**: FR-024 makes the row, the tags and the map entry atomic, unlike legacy, which can
  leave a domain without its map entry. Redis has no transaction shared with MariaDB, so the write
  happens inside the database transaction and is compensated if the commit fails. The primary
  key and the gap lock give "exactly one succeeds" for concurrent creations.
- **Alternatives considered**: A named lock (`GET_LOCK`) was rejected: the primary key already
  serialises domains, and the gap lock covers alias domains. Writing `DOMAIN_MAP` after the commit
  was rejected: a failure would leave the legacy defect.

### D9. Rate limit at creation

- **Decision**: `App\Services\RateLimits\RateLimitStore` writes `HSET RL_VALUE <domain> "<value> /
  1<s|m|h|d>"` (legacy `ratelimit('edit', 'domain')`) and reads it back by splitting on `" / 1"`.
  The request's `rateLimit` wins; `null` means none; when omitted the template's `rl_value` and
  `rl_frame` apply if `rl_value` is non-empty. Outcome `applied`, `notRequested` or `failed`.
- **Rationale**: Feature 006 owns later changes and will extend this class. Legacy skips the
  `ratelimit` permission check only because the caller is an administrator; the API's creation is
  administrator-only too.

### D10. DKIM key and its DNS publication

- **Decision**:
  - `App\Services\Dkim\DkimKeyGenerator::generate(domain, selector, bits)` ports `dkim('add')` for
    one domain: `openssl_pkey_new(['digest_alg' => 'sha256', 'private_key_bits' => bits,
    'private_key_type' => OPENSSL_KEYTYPE_RSA])`; the public key is the PEM body without the header
    and footer lines, joined (legacy's `implode(array_slice(array_filter(explode(PHP_EOL, …)), 1,
    -1))`); `HSET DKIM_PUB_KEYS <domain> <pub>`, `HSET DKIM_SELECTORS <domain> <selector>` and
    `HSET DKIM_PRIV_KEYS <selector>.<domain> <trimmed PEM>`. Rspamd reads these keys directly.
  - A key "already exists" when `DKIM_SELECTORS` or `DKIM_PUB_KEYS` has the domain (legacy checks
    the first in `mailbox('add', 'domain')` and the second in `dkim('add')`). An explicitly empty
    selector gives `notRequested`.
  - The selector is the request's, else the template's `dkim_selector`, else
    `config('restv2.dkim.default_selector')` (`RESTV2_DEFAULT_DKIM_SELECTOR`, default `dkim`,
    FeldHost `feldhost`, FR-028). The key size is the request's, else the template's `key_size`
    when it is one of 1024, 2048, 3072, 4096, else 2048.
  - `App\Services\Dkim\DkimDnsPublisher::publish(domain, selector, publicKey)` implements the fork's
    `dkim_update_in_dns()` behind `App\Clients\Ispconfig\IspconfigDnsClient`, as feature 010
    specifies it (FR-029 there): find the zone whose origin is `<domain>.`; none → `noZone`; find a
    TXT record named `<selector>._domainkey` (relative, or fully qualified
    `<selector>._domainkey.<domain>.`); update it, or create it, with
    `v=DKIM1;k=rsa;t=s;s=email;p=<key>`, TTL 7200, active `Y`, on the configured DNS server, for
    client `sys_groupid − 1`. Disabled → `notConfigured`; any error or the 15-second budget
    exceeded → `failed`. No new key → `skipped`.
  - The adapter `JsonIspconfigDnsClient` uses ISPConfig's JSON remote API (`/remote/json.php`, the
    same remote user and functions `login`, `dns_zone_get`, `dns_rr_get_all_by_zone`,
    `dns_txt_add`, `dns_txt_update`, `logout`) with Laravel's HTTP client, a 15-second total budget
    and TLS verification on by default.
- **Rationale**: FR-027 and the clarification on the fork: off by default, outcome reported, never
  failing the creation. The JSON remote API calls the same ISPConfig remoting functions as the
  fork's SOAP client, so the zone ends up identical; it needs no `ext-soap` in the image and can be
  faked with `Http::fake()`. Feature 010 reuses both classes and adds removal and republication.
- **Alternatives considered**: PHP's `SoapClient` (as the fork) was rejected: it needs the `soap`
  extension and `libxml2` in the image, and cannot be faked at the HTTP level. Publishing inside
  the creation transaction was rejected: an external call of up to 15 s would hold row locks.

### D11. Validation and the order of checks

- **Decision**: Checks run in this order; the first failure answers:
  1. 001's pipeline: `401`, key scope (`403 insufficient-key-scope` for read-only keys on writes),
     CSRF, pending actions.
  2. Role (`DomainPolicy`, `DomainTemplatePolicy`): `403 forbidden` for a role that may never use
     the operation, before any lookup, so that it reveals nothing.
  3. Scope: the normalised name must be a domain in the caller's scope, else `404`.
  4. Shape: the Form Request checks types, lengths, enumerations and patterns (`422`).
  5. Version: inside the transaction, after `SELECT … FOR UPDATE`, `If-Match` (`412`).
  6. Permission per attribute (`DomainAttributeGuard`, FR-032): a domain administrator's changed
     attributes outside `globalAddressList`, `tags` and (with `domain_desc`) `description` →
     `403 attribute-not-permitted` with all names.
  7. Read-only members with a different value → `422 immutable` (FR-031).
  8. Business rules (`DomainRules`), all violations collected and reported together:
     `notMultipleOf`, `mustNotExceed` (default ≤ maximum mailbox quota ≤ domain quota, on the
     values after merging), `belowInUse` for changed limits only, tags (D12), `reserved` (host
     name, compared case-insensitively with `MAILCOW_HOSTNAME`), `notFound` (template).
- **Rationale**: Each step maps to one documented status. Role before scope keeps `403` free of
  existence information; version before permission lets a client that read stale data learn that
  first. Collecting every violation follows `docs/conventions.md` ("every bad entry is named").

### D12. Tags

- **Decision**: `App\Services\Domains\DomainTagSet` normalises a list: trim, refuse empty
  (`required` on `tags.<i>`) or longer than 255 characters (`tooLong`), drop later duplicates
  compared with `mb_strtolower`, keep the first spelling. Limit: `MailcowConfig::taggingLimit()`
  (legacy `$TAGGING_LIMIT`, default 25, server setting `RESTV2_TAGGING_LIMIT`). A request is refused
  with `tooMany` (`max`) when the new set is larger than the limit, unless it equals the current set
  or is smaller than it (a legacy domain over the limit can only shrink). Saving replaces the set:
  `DELETE FROM tags_domain WHERE domain = ?` then one `INSERT` per tag, in the domain's
  transaction. The representation sorts with `mb_strtolower`, ties by the original string.
- **Rationale**: FR-043, FR-044 and their edge cases. The unique key `(tag_name, domain)` under
  `utf8mb4_general_ci` is case-insensitive, so the deduplication also avoids legacy's duplicate-key
  error.

### D13. Groupware side effects

- **Decision**:
  - `App\Services\Groupware\SogoDirectory::rebuild()` ports the full rebuild of
    `update_sogo_static_view()`: the `INSERT INTO _sogo_static_view … SELECT … FROM mailbox LEFT
    OUTER JOIN grouped_mail_aliases … grouped_domain_alias_address … grouped_sender_acl_external
    WHERE mailbox.active = '1' GROUP BY mailbox.username ON DUPLICATE KEY UPDATE …` statement and
    `DELETE FROM _sogo_static_view WHERE c_uid NOT IN (SELECT username FROM mailbox WHERE active =
    '1')`, then a memcached flush. `c_password` is a fresh random `{SSHA256}` hash per rebuild
    (spec parity note: legacy's hash is built from undefined variables). It does nothing when
    `SKIP_SOGO=y` and returns `done`, `failed` or `groupwareDisabled`. It runs after the commit
    (legacy runs it at the end of `mailbox()`); a failure is logged and, for a creation, reported.
  - `App\Clients\Memcached\MemcachedFlusher` (`SocketMemcachedFlusher`): opens
    `tcp://<RESTV2_MEMCACHED_HOST>:<RESTV2_MEMCACHED_PORT>` (default `memcached:11211`), sends
    `flush_all\r\n` and expects `OK`, with a 2 s timeout. Failures are ignored like legacy's
    `flush_memcached()`, but logged.
  - Groupware restart: `DockerApiClient::restart('sogo-mailcow')` (D14), only when asked and the
    groupware is enabled. A changed Global Address List flag needs this restart to take effect
    (`bootstrap-sogo.sh` reads `domain.gal` at start); an edit never restarts (FR-034).
- **Rationale**: Parity with every legacy domain write. The memcached text protocol needs no PHP
  extension. One rebuild per bulk request (FR-063 allows it).
- **Alternatives considered**: The `memcached` PECL extension was rejected: one command does not
  justify a new extension. An incremental view update was rejected: legacy rebuilds fully for
  domain operations, because a domain change affects every mailbox.

### D14. Container service (dockerapi)

- **Decision**: `App\Clients\DockerApi\DockerApiClient` with `restart(string $service)` and
  `exec(string $service, array $command)`, both returning `DockerCallResult` (`success`, `failed`
  with a message, or `unknown` on timeout). The adapter `HttpDockerApiClient` ports legacy
  `docker()`: `GET <RESTV2_DOCKERAPI_URL>/containers/json`, the container whose labels
  `com.docker.compose.service` equals the service and `com.docker.compose.project` equals
  `COMPOSE_PROJECT_NAME` (case-insensitive), then `POST /containers/<id>/restart` or
  `POST /containers/<id>/exec` with the JSON command; `type: success` means success. TLS
  verification is off, as in legacy (dockerapi uses mailcow's certificate under another name). The
  timeout is `MailcowConfig::dockerTimeout()` (legacy `$DOCKER_TIMEOUT`, 60 s). Tests use
  `tests/Support/Fakes/FakeDockerApiClient`, which records the calls.
- **Rationale**: Constitution VII (container service faked behind an interface) and
  `docs/conventions.md` (synchronous, bounded by `DOCKER_TIMEOUT`, unknown outcome on timeout).
  Features 008 and 011 reuse the client.

### D15. Editing

- **Decision**: `App\Services\Domains\DomainUpdater` runs in one transaction: lock the row, check
  `If-Match`, merge the patch over the current values, check D11 steps 6–8, apply relay
  normalisation (FR-021, after merging, so clearing `backupMx` while `relayAllRecipients` stays set
  has no effect), `UPDATE domain` with every editable column (legacy sets them all), replace the
  tags when sent, and write the audit record with each changed attribute's old and new value. An
  empty `description` or `null` stores the domain name. A patch that changes nothing returns `200`
  without a write or a groupware rebuild, and is audited with empty `changes`. After the commit,
  `SogoDirectory::rebuild()`.
- **Rationale**: FR-030 to FR-034 and the legacy parity table. FR-057 requires a record for every
  request that changes state "or tries to", so an unchanged edit is audited too, unlike 001's
  idempotent no-ops (R22 there), which no spec of 001 asks to audit.

### D16. Audit records

- **Decision**:
  - Event types `domainCreation`, `domainUpdate`, `domainDeletion`, `domainFooterUpdate`,
    `domainTemplateCreation`, `domainTemplateUpdate`, `domainTemplateDeletion`; target types
    `domain` (id = ASCII name, name = Unicode name), `domainFooter` (id = ASCII name) and
    `domainTemplate` (id = template ID, name = template name).
  - `details` keys (added to 001's per-event whitelist): `changes` (`{attribute: {old, new}}`;
    footer texts as `{changed: true, oldSize, newSize}`), `values` and `templateId` (creation),
    `creationReport`, `maildirCleanup`, `removedAliasDomains`, `removedDomainAdmins`,
    `footerRemoved`, `bulkRequestId`, `problemType` and `errors` (refusals).
  - Refusals after authentication and scope resolution are audited with outcome `failure` and the
    new failure reasons `attributeNotPermitted`, `versionOutdated`, `validationFailed`, `conflict`
    and `temporaryFailure`, each in its own transaction (001 R22). Role-level `403`, `401` and
    out-of-scope `404` are not audited: they concern no object of the caller's scope.
  - **Follow-up completion**: creation and deletion have steps that must run after the commit
    (the groupware restart must see the committed domain; the stored-mail move must not run for a
    deletion that rolls back). The audit record is written inside the transaction with
    `creationReport` or `maildirCleanup` set to `"pending"`, and
    `AuditLogger::completeFollowUps(eventId, key, outcome)` replaces that value once afterwards
    (`UPDATE … SET details = JSON_SET(details, '$.<key>', JSON_EXTRACT(?, '$')) WHERE id = ? AND
    JSON_VALUE(details, '$.<key>') = 'pending'`). Only these two keys can be completed, only from
    `"pending"`, and no route exposes it. The `AuditEvent` model still throws on `updating`.
  - The domain's footer removed by a deletion is recorded in the domain's record
    (`footerRemoved: true`), not as a second record.
  - `GET /audit-events` gets `targetType` (`target_type = ?`) and `targetId` (`target_id = ?`; for
    `domain` and `domainFooter`, or without `targetType`, the value is normalised with
    `DomainName::normalize()` when it is a valid name). An index `(target_type, target_id,
    occurred_at)` is added to `restv2_audit_events` by a new `restv2_` migration.
- **Rationale**: FR-057 requires one record per affected object, written atomically with the
  change, holding the creation report and the stored-mail outcome. Completing a value that was
  explicitly marked pending keeps both properties: the change is never unaudited, and the record
  ends up with the full report. See Complexity Tracking in the plan.
- **Alternatives considered**: Holding the transaction across the follow-up steps was rejected:
  the groupware restart cannot see uncommitted rows, and the DNS publication (15 s) and the
  container call (60 s) would hold row locks. A second audit record for the follow-ups was
  rejected (SC-007: exactly one per object). Writing the record only after the follow-ups was
  rejected: a failed write would leave an unaudited domain (spec edge case).

### D17. Deletion

- **Decision**: `App\Services\Domains\DomainDeleter`, with `DomainDeletionPlanner` computing the
  plan for both the deletion and the preview (SC-011):
  1. Ping the shared store (`503` when down).
  2. Transaction: `SELECT … FROM domain WHERE domain = ? FOR UPDATE`; `If-Match`; `SELECT username,
     kind FROM mailbox WHERE domain = ? FOR UPDATE` (next-key locks block a concurrent mailbox
     insert through this API); any row → `409 domain-not-empty` with the counts.
  3. The plan: alias domains (`alias_domain.target_domain = ?`), orphaned domain administrators
     (D18), whether a footer exists, whether a DKIM key exists.
  4. Deletes, as `mailbox('delete', 'domain')` does: `domain`; `domain_admins` (domain); `alias`
     (domain); `sender_acl` (`logged_in_as LIKE '%@<d>'`); `quota2`, `pushover`, `quota2replica`
     (`username LIKE '%@<d>'`); `spamalias` (`address LIKE '%@<d>'`); `filterconf` (`object =
     <d>`); `mta_sts` (domain); then, unlike legacy, `bcc_maps` with `local_dest IN ('<d>',
     '@<d>')`, `domain_wide_footer` (domain), and for each alias domain
     `AliasDomainCleanup::deleteRows()` (D19); then `alias_domain` (`target_domain = <d>`). The
     `tags_domain` rows go by the foreign key's `ON DELETE CASCADE`. `DELETE FROM mailbox` is not
     issued: the lock guarantees there are none (legacy's statement is a no-op then).
  5. Redis, in one `MULTI`: `HDEL DOMAIN_MAP` and `HDEL RL_VALUE` for the domain and each alias
     domain, remembering the old values; a failure rolls back the transaction (`503`), and a
     failed commit restores them.
  6. The audit record (`domainDeletion`, with `removedAliasDomains`, `removedDomainAdmins`,
     `footerRemoved`, `maildirCleanup: "pending"`); commit.
  7. After the commit: `DockerApiClient::exec('dovecot-mailcow', {cmd: maildir, task: cleanup,
     maildir: <d>})`, the outcome completed into the audit record (FR-039); then
     `SogoDirectory::rebuild()` (once per bulk request).
- **Rationale**: FR-035 to FR-040 and FR-056. The DKIM keys stay in Redis (FR-038). Requesting the
  mail move after the commit means a refused or failed deletion never moves mail (spec parity
  note).

### D18. Domain administrators left without a domain

- **Decision**: `App\Services\DomainAdmins\OrphanedDomainAdminRemover` runs legacy's two statements
  inside the deletion transaction, after the assignments are gone: `DELETE FROM admin WHERE
  superadmin = 0 AND username NOT IN (SELECT username FROM domain_admins)` and `DELETE FROM da_acl
  WHERE username NOT IN (SELECT username FROM domain_admins)`. It first selects the usernames it
  will delete, for the audit record and the preview. Their API keys then fail 001's owner check
  (`api-key-invalid`). Feature 007 extends this class with the full cascade of its FR-032 (second
  factors, passkeys, key revocation, sessions, preferences).
- **Rationale**: FR-038 "as legacy does". Legacy deletes every domain administrator without an
  assignment, including ones orphaned earlier; the preview lists them all, so nothing is hidden.

### D19. Alias domain cleanup shared with feature 004

- **Decision**: `App\Services\AliasDomains\AliasDomainCleanup` implements the deletion of one alias
  domain as feature 004's FR-045 defines it, for both features (`docs/conventions.md`):
  `deleteRows(string $aliasDomain)` removes `alias` rows with `domain = <a>` and their
  `sender_acl` rows (`send_as` = their addresses), `sender_acl` rows with `send_as = '@<a>'`,
  `spamalias` (`address LIKE '%@<a>'`), `bcc_maps` (`local_dest IN ('<a>', '@<a>')`) and `filterconf`
  (`object = <a>`), and the `alias_domain` row; `redisFields(string $aliasDomain)` returns the
  `DOMAIN_MAP` and `RL_VALUE` fields for the caller's `MULTI`. The DKIM key is kept. Feature 004
  calls the same methods for a single alias domain deletion.
- **Rationale**: FR-037 says "as a deletion of that alias domain alone would", and 004's FR-045 is
  that deletion. One implementation prevents the two from drifting (spec FR-037 was aligned).

### D20. Bulk actions

- **Decision**: `App\Services\Domains\DomainBulkActions`: the request is refused as a whole only
  for the role or key scope (`403`) and for more than 100 targets (`422 tooMany` on `targets` with
  `max: 100`; the contract's `maxItems`). The limit is fixed, as `docs/conventions.md` and FR-062
  state; the spec's assumptions were aligned. Targets are
  normalised and deduplicated in request order (first spelling kept as `id`); an unnormalisable
  name gives `notFound`. Each target runs `DomainUpdater` (`{active: true|false}`) or
  `DomainDeleter` with its own transaction, version check (`version` compared like `If-Match`),
  audit record carrying `bulkRequestId` (the request ID) and outcome mapping: success → `done`
  (with the new `version` unless deleted), `404` → `notFound`, `412` → `outdated`,
  `409`/`403`/`422` → `refused`, `503` or an exception → `failed`. The groupware rebuild is
  deferred until all targets are processed and runs once if any target changed. Activating an
  active domain is `done`, audited with empty `changes` (FR-057, "or tries to").
- **Rationale**: FR-062, FR-063 and `docs/conventions.md`. Reusing the single-operation services
  guarantees identical checks and side effects.

### D21. Footer

- **Decision**: `App\Services\Domains\DomainFooterService`. Reads return the row or the empty
  footer. A replacement locks the domain row (`FOR UPDATE`), checks `If-Match` against the footer
  tag, validates, then `DELETE FROM domain_wide_footer WHERE domain = ?` and `INSERT` (legacy) with
  `mbox_exclude` and `alias_domain_exclude` as JSON arrays and `skip_replies` 0/1, and audits.
  - Each `excludedAddresses` entry is trimmed and lower-cased, with the domain part converted to
    ASCII; it must be a valid e-mail address (`invalidFormat`, `format: email`) whose domain is the
    domain or one of its alias domains and that exists as `mailbox.username` or `alias.address`
    (`notFound`). Each `excludedAliasDomains` entry is normalised and must be an alias domain of
    the domain (`notFound`). Duplicates are dropped.
  - Each text is at most 1,048,576 bytes (`strlen`), `tooLong` with `max` and `unit: bytes`.
  - nginx: `deploy/nginx/site.restv2.custom` raises `client_max_body_size` from `1m` to `5m`, so
    that two 1 MiB texts with JSON escaping fit.
- **Rationale**: FR-051 to FR-055. The mail filter (`data/conf/rspamd/dynmaps/footer.php`) compares
  the sender and the alias domain exactly, so stored values must be in the form mailcow stores
  addresses.

### D22. Domain templates

- **Decision**: `App\Services\DomainTemplates\DomainTemplateService` on `templates` rows with
  `type = 'domain'`; mailbox templates are invisible (`404`).
  - **Storage format** (read and written, legacy keys): `tags` (array), `max_num_aliases_for_domain`,
    `max_num_mboxes_for_domain` (int), `def_quota_for_mbox`, `max_quota_for_mbox`,
    `max_quota_for_domain` (int **bytes**), `rl_value` (int, or `""` for none), `rl_frame`
    (`s|m|h|d`, `s` when none), `active`, `gal`, `backupmx`, `relay_all_recipients`,
    `relay_unknown_only` (int 0/1), `dkim_selector` (string), `key_size` (int). The JSON is written
    with these keys only, so legacy's add form keeps working.
  - **Reading legacy rows** tolerantly: numeric strings are cast; `tags` as a JSON string is
    decoded; a missing key takes the FR-046 default; a missing or non-numeric `rl_value` means no
    rate limit.
  - **Defaults** (FR-046): 400, 10, 3,072 MiB, 10,240 MiB, 10,240 MiB, no rate limit, active, GAL on,
    no relay options, no tags, `config('restv2.dkim.default_selector')`, 2048.
  - **Uniqueness**: inside the transaction, `SELECT id, template FROM templates WHERE type =
    'domain' FOR UPDATE` (the scan locks the table's rows and gaps, so concurrent creations
    serialise), then compare `mb_strtolower`. Default protection compares `mb_strtolower(name) ===
    'default'`.
  - Values are validated and relay-normalised like domains (FR-047), and audited.
- **Rationale**: FR-045 to FR-050 and legacy storage.

### D23. Capabilities and tag summary

- **Decision**:
  - `DomainCapabilities`: `groupwareEnabled = !config('restv2.skip_sogo')`, `taggingLimit =
    MailcowConfig::taggingLimit()`, `maxTagLength = 255`, `maxDescriptionLength = 255`,
    `footerTextMaxBytes = 1048576`, `bulkLimit = 100`, `defaultPageSize` and
    `maxPageSize` from 001's `ListQuery` constants (25 and 1,000, fixed by `docs/conventions.md` and
    `Limit.yaml`), `quotaUnit = 1048576`; for administrators `dkim = {defaultSelector,
    defaultKeySize: 2048, allowedKeySizes: [1024, 2048, 3072, 4096], dnsPublicationEnabled}`.
  - Distinct tags: `SELECT MIN(tag_name) AS name, COUNT(DISTINCT domain) AS domain_count FROM
    tags_domain WHERE domain IN (<scope>) [AND tag_name LIKE ?] GROUP BY LOWER(tag_name) ORDER BY
    LOWER(name)` (or by count, ties by name), paged with `ListQuery`.
- **Rationale**: FR-064 and FR-066. The page sizes and the bulk limit are API-wide constants of the
  contract and `docs/conventions.md`; the tagging limit, the DKIM selector and the publication are
  the operator-changeable values.

---

## 4. Configuration, dependencies and tests

### D24. Server settings

| Variable | Default | Meaning |
|---|---|---|
| `COMPOSE_PROJECT_NAME` | — (from `mailcow.conf`) | dockerapi container lookup (D14) |
| `RESTV2_DOCKERAPI_URL` | `https://dockerapi:443` | dockerapi base URL |
| `RESTV2_DOCKER_TIMEOUT` | `vars.inc.php` `$DOCKER_TIMEOUT` (60) | Exported by `MailcowConfig` (001 R14 precedence) |
| `RESTV2_TAGGING_LIMIT` | `vars.inc.php` `$TAGGING_LIMIT` (25) | Exported by `MailcowConfig` |
| `RESTV2_MEMCACHED_HOST`, `RESTV2_MEMCACHED_PORT` | `memcached`, `11211` | Groupware cache flush |
| `RESTV2_DEFAULT_DKIM_SELECTOR` | `dkim` | FR-028 (FeldHost: `feldhost`) |
| `RESTV2_DKIM_DNS_PUBLICATION` | `n` | FR-027 (FeldHost: `y`) |
| `RESTV2_ISPCONFIG_URL` | empty | JSON remote endpoint, e.g. `https://ispconfig.example:8080/remote/json.php` |
| `RESTV2_ISPCONFIG_USERNAME`, `RESTV2_ISPCONFIG_PASSWORD` | empty | ISPConfig remote user; the password is a secret, never logged |
| `RESTV2_ISPCONFIG_DNS_SERVER_ID` | `1` | ISPConfig DNS server ID |
| `RESTV2_ISPCONFIG_VERIFY_TLS` | `y` | TLS verification of the ISPConfig endpoint |
| `RESTV2_FAKE_OUTBOUND` | `n` | Development only (`APP_ENV=local`): binds the dockerapi, memcached and ISPConfig fakes, because the dev stack has none of these services |

`SKIP_SOGO` and `MAILCOW_HOSTNAME` are 001's. The publication is "enabled" only when
`RESTV2_DKIM_DNS_PUBLICATION=y` and the URL, user and password are set; otherwise the outcome is
`notConfigured` and a warning is logged once.

### D25. Dependencies (constitution VIII)

- **Decision**: No new Composer package and no new PHP extension. `intl` (IDN) and `openssl` (RSA
  keys) are in 001's image; Redis is phpredis; HTTP uses Laravel's client; memcached uses a socket.
- **Rationale**: Everything needed is in the framework or the existing image.

### D26. Tests

- **Decision**:
  - Fakes bound in `tests/TestCase.php` for every test: `FakeDockerApiClient`,
    `FakeMemcachedFlusher` and `FakeIspconfigDnsClient` (all in `tests/Support/Fakes/`), each with
    assertion helpers and programmable failures. The HTTP adapters are tested with `Http::fake()`,
    the socket flusher with a local TCP server in the test.
  - Redis is real (database 15). "Shared store unavailable" is produced by pointing the `mailcow`
    connection at a closed port for the test (001's fail2ban approach).
  - `tests/Support/Mailcow/MailcowFactory` gains writers for every upstream row this feature
    touches, inserting them as legacy does (D27 of the data model).
  - DKIM generation uses 1024-bit keys in tests except where the size is asserted.
  - 001's `OpenApiContract` must coerce `boolean` query parameters (`true`/`false`) like integers,
    for `active` and `backupMx`.
  - A performance test seeds 5,000 domains and 100,000 mailboxes with multi-row inserts and
    asserts SC-005 (group `performance`).
- **Rationale**: Constitution VII: MariaDB with the real schema, real Redis, outbound services
  faked behind interfaces with call assertions.

### D27. Hand-offs to later features

| Shared piece created here | Reused and extended by |
|---|---|
| `DomainName` | 003, 004 (addresses and alias domains) |
| `DomainUsageCalculator` (limits, quota figures) | 003 (mailbox limits and quota), 004 (alias limit) |
| `AliasDomainCleanup` | 004 (FR-045 there) |
| `SogoDirectory`, `MemcachedFlusher` | 003, 004 (incremental rebuild added by 003) |
| `DockerApiClient` | 003 (maildir cleanup, SOGo), 008, 011 |
| `RateLimitStore` | 004 (alias domain creation), 006 (rate limit changes) |
| `DkimKeyGenerator`, `DkimDnsPublisher`, `IspconfigDnsClient`, the `RESTV2_ISPCONFIG_*` and `RESTV2_DEFAULT_DKIM_SELECTOR` settings | 004 (alias domain creation), 010 (key management, removal, republication) |
| `OrphanedDomainAdminRemover` | 007 (full cascade of FR-032 there) |
| `SharedStore` (ping, `MULTI` with compensation) | every feature writing mailcow's Redis |
| `attribute-not-permitted`, `temporary-failure`, rule codes `notMultipleOf`, `mustNotExceed`, `belowInUse`, `reserved` | 003 onwards |

Locking conventions for later features: a mailbox or alias creation that checks domain limits
locks the domain row `FOR UPDATE` (003, 004), which serialises it with domain edits and
deletions; an alias domain creation locks the `domain` and `alias_domain` keys of its name like
D8 (004).
