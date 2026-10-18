# Research: Mailboxes

**Feature**: `specs/003-mailboxes` | **Date**: 2026-10-18 | **Plan**: [plan.md](./plan.md)

Only decisions specific to this feature are recorded here. The stack, the dev and test
environment, the schema fixture, the table and Redis prefixes, problem URIs, contract validation,
configuration reading and the application layout are feature 001's (its research R1–R29). The
domain helpers, the groupware, container, rate-limit and shared-store clients and the audit
follow-up completion are feature 002's (its research D4–D27).

Each entry has a **Decision**, its **Rationale** and the **Alternatives considered**. Precedence is
legacy behaviour (constitution IV), then the constitution and `docs/conventions.md`, then best
practice. Legacy references are paths in mailcow-dockerized `2026-09a`
(`git -C ~/Projects/mailhosting show 2026-09a:<path>`). The FeldHost fork differs for this feature
only in display texts (spec, parity section).

---

## 1. Contract shape

### R1. Paths, modules and naming

- **Decision**:
  - The mailbox is addressed by its full address: `/mailboxes/{mailbox}`, with the list and
    creation at `/mailboxes`. The address is unique across domains, so nesting under
    `/domains/{domain}` would only duplicate the domain. The list filters by domain instead.
    Feature 004 already uses `/mailboxes/{mailbox}/sender-settings`, so the path and parameter
    name agree.
  - Non-CRUD actions are sub-resources: `POST /mailboxes/{mailbox}/rename`,
    `GET /mailboxes/{mailbox}/deletion-preview`, `POST /mailboxes/bulk-actions` (the address
    pattern `^[^@/\s]+@[^@/\s]+$` can never equal `bulk-actions`, and routes register literal
    paths first, 001 R8).
  - The capabilities read is `GET /capabilities/mailboxes` (operation `getMailboxCapabilities`)
    and the tags in use are `GET /mailbox-tags`, both top-level as `docs/conventions.md`
    ("Capabilities, history and refusals") requires for every feature.
  - Templates are `/mailbox-templates/{mailboxTemplateId}` (the integer `templates.id`).
  - The password policy is its own module, `api/modules/password-policy/`, holding only `GET`.
    Feature 013 adds its change operation to the same path file, so the read and the change share
    one resource and one version tag (spec FR-063, 013 FR-037).
  - Schema files are prefixed `Mailbox` (and `PasswordPolicy`) so that they never collide with
    other features. Problem types reuse the generic ones that already exist
    (`attribute-not-permitted`, `temporary-failure` from 002; `domain-alias-limit-reached` from
    004) with additional, optional extension members, and add generic `address-in-use` and
    `outcome-unknown` (which 004, 011 and 013 can reuse) and mailbox-specific ones.
  - JSON names: `status` (`active`, `loginDisabled`, `inactive`) for `mailbox.active` 1/2/0;
    `authSource` (`mailcow`, `keycloak`, `genericOidc`, `ldap`); `webmailRedirect` for
    `sogo_access`; `protocolAccess.{imap,pop3,smtp,sieve,activeSync,dav}`; `userPermissions` in
    001's camelCase flag names; `quarantineCategory` `rejected`/`junk`/`all` for
    `reject`/`add_header`/`all`; `rateLimit.frame` `second`/`minute`/`hour`/`day` for `s/m/h/d`.
- **Rationale**: Constitution II (plural nouns, ownership nesting only where real, no verbs) and
  the conventions' naming rule. One resource for the policy avoids two tags for the same values.
- **Alternatives considered**: `/domains/{domain}/mailboxes/{localPart}` was rejected: every
  client already holds full addresses, cross-domain lists would need a second path, and 004 uses
  `/mailboxes/{mailbox}`. Separate sub-resources for user permissions, tags and custom attributes
  (legacy's separate actions) were rejected: FR-029 lists them as changeable values of one change,
  and JSON Merge Patch expresses partial permissions and attribute removal (`null`) natively.

### R2. The stored mailbox and its representation

- **Decision**: `App\Models\Mailcow\Mailbox` (001) gains the columns and casts below. Writes use
  the query builder inside the services.
  - `attributes` (JSON) holds **strings**, exactly as legacy writes them (`json_encode` of
    `strval`s at creation, `JSON_SET` with PDO string parameters on edit): `force_pw_update`,
    `force_tfa`, `tls_enforce_in`, `tls_enforce_out`, `sogo_access`, `imap_access`, `pop3_access`,
    `smtp_access`, `sieve_access`, `eas_access`, `dav_access` (`"0"`/`"1"`), `relayhost` (id or
    `"0"`), `passwd_update`, `mailbox_format` (`maildir:`), `quarantine_notification`,
    `quarantine_category`, `attribute_hash`, `recovery_email`. The API writes the same strings.
  - `passwd_update` is written as legacy does: `JSON_SET(attributes, '$.passwd_update', NOW())`,
    which stores mailcow's local time as `YYYY-MM-DD HH:MM:SS`. Older rows may hold a Unix
    timestamp (legacy writes `time()` first); the cast accepts both and emits UTC.
  - Missing keys (older mailboxes): protocols read as `true`, forced flags as `false`,
    `passwd_update` as `null` (FR-011). `quarantine_notification` and `quarantine_category`
    default to the built-in defaults when absent; `tls_enforce_*` to `false`.
  - `percentUsed` is `round(bytes / quota * 100)` (PHP `round`, as legacy), `null` for quota 0.
  - `largestAllowedQuota` is `min(domain.maxquota, domain.quota − Σ quota of the domain's other
    mailboxes)` in bytes, never below 0 (legacy `max_new_quota`). It comes from 002's
    `DomainUsageCalculator`.
  - Usage comes from `quota2` when `MASTER` is `y` (default) and from `quota2replica` otherwise,
    as legacy `mailbox_details` does.
  - Last logins are `MAX(sasl_log.datetime)` grouped by service for `imap`, `pop3`, `smtp` and
    `SSO`; all `null` when `MailcowConfig::showLastLogin()` is false.
  - Read-only values managed elsewhere: tagged-mail handling from Redis (`RCPT_WANTS_SUBJECT_TAG`
    wins over `RCPT_WANTS_SUBFOLDER_TAG`, as legacy's `get delimiter_action`), the effective rate
    limit from `RL_VALUE[<address>]`, else `RL_VALUE[<domain>]` (`rateLimitSource`), the relayhost
    id, active temporary aliases (`spamalias.goto = address AND (validity >= now OR permanent !=
    0)`) and whether `pushover.active = 1`.
  - User permissions: the `user_acl` row (DB defaults when the row is missing). For a domain
    administrator, every permission whose `da_acl` flag is `0` is omitted (legacy
    `acl('get', 'user')` unsets every `da_acl` column that is 0; only the five overlapping names
    exist in both tables).
- **Rationale**: Byte-compatible storage keeps Dovecot's userdb, Postfix's maps, SOGo and the
  legacy UI working on rows the API writes (constitution IV).
- **Alternatives considered**: Writing JSON booleans or integers was rejected: Dovecot's userdb
  query and legacy compare against `"1"`/`"0"` strings in places.

---

## 2. Passwords

### R3. Password hashing in `MAILCOW_PASS_SCHEME`

- **Decision**: `App\Services\Passwords\MailboxPasswordHasher` ports legacy `hash_password()`:
  - Pre-hashed values: a value matching legacy's exact pattern
    `/^{(ARGON2I|ARGON2ID|BLF-CRYPT|CLEAR|CLEARTEXT|CRYPT|DES-CRYPT|LDAP-MD5|MD5|MD5-CRYPT|PBKDF2|PLAIN|PLAIN-MD4|PLAIN-MD5|PLAIN-TRUNC|SHA|SHA1|SHA256|SHA256-CRYPT|SHA512|SHA512-CRYPT|SMD5|SSHA|SSHA256|SSHA512)}/i`
    is stored unchanged, flagged `preHashed` for the audit record, and skips the policy check
    (spec FR-024 deviation: legacy runs the policy against the hash text).
  - Otherwise the scheme is the environment variable `MAILCOW_PASS_SCHEME` (mailcow.conf name,
    default `BLF-CRYPT`), upper-cased:
    - `SSHA`, `SSHA256`, `SSHA512`: the salt is `bin2hex(random_bytes(8))` (16 hex characters,
      used as the salt bytes, exactly as legacy), and the hash is
      `{SCHEME}` + base64(digest(password . salt, raw) . salt);
    - `BLF-CRYPT` or any other value: `{BLF-CRYPT}` + `password_hash($password, PASSWORD_BCRYPT)`
      (cost 10, `$2y$`), as legacy's default branch.
  - The hasher never logs, and the value it returns is written only to `mailbox.password`.
  - Tests verify every generated hash with 001's `LegacyPasswordVerifier` and with the extracted
    legacy `verify_hash()` fixture, and quickstart Part C checks IMAP sign-in against Dovecot.
- **Rationale**: Dovecot verifies these exact formats (constitution IV). Reading the same
  environment variable as php-fpm and Dovecot keeps one setting for the operator.
- **Alternatives considered**: Exporting `$default_pass_scheme` through `MailcowConfig` was
  rejected: `vars.inc.php` only reads `getenv('MAILCOW_PASS_SCHEME')`, so the environment variable
  is the source anyway. Argon2 by default was rejected: it is not legacy's default and older
  Dovecot images in clusters may lack it.

### R4. Password policy read and version tag

- **Decision**: `App\Services\Passwords\PasswordPolicyReader` reads `HGETALL PASSWD_POLICY` from
  the `mailcow` Redis connection on every call (no cache). Effective values: `length` = integer
  value when numeric, else 6; `chars`, `special_chars`, `lowerupper`, `numbers` = `1` when the
  stored value equals `1` (legacy compares `== 1`), else `0`. A Redis failure raises
  `temporary-failure` (`dependency: sharedStore`, `503`).
  - The version tag is computed by 001's `ETag::strong()` over the canonical string
    `length=<n>;chars=<0|1>;special_chars=<0|1>;lowerupper=<0|1>;numbers=<0|1>`. The same reader
    and string serve feature 013's change operation, so both features return the same tag for the
    same effective values (`docs/conventions.md`, 013 FR-037). The canonical string is documented
    in the contract (`PasswordPolicy.etag`).
  - `App\Services\Passwords\PasswordPolicyChecker` ports `password_check()` against the effective
    values: length is `strlen()` (bytes, as legacy); letters `/[a-zA-Z]/`, digits `/\d/`, special
    `/[^a-zA-Z\d]/`, mixed case = at least one `[a-z]` and one `[A-Z]`. It returns every unmet
    rule: `tooShort` (`min`) and `missingCharacterClass` (`characterClass`: `letter`, `digit`,
    `special`, `lowerAndUpper`).
- **Rationale**: FR-063 requires the same source, the legacy defaults (php-fpm seeds 6/0/0/0/0)
  and a tag shared with 013. Counting bytes keeps the API's verdict identical to legacy's for the
  same password.
- **Alternatives considered**: Counting characters (`mb_strlen`) was rejected: a password accepted
  here could be refused by the legacy UI and vice versa. A tag over the raw hash (missing fields
  included) was rejected: two installations with equivalent effective policies would get
  different tags, and 013 writes all five fields anyway.

---

## 3. Creation

### R5. Value resolution, templates and defaults

- **Decision**: `App\Services\Mailboxes\MailboxDefaults` resolves every value in this order: the
  request, the named template (`templateId`, must be `type = 'mailbox'`, else `notFound`), the
  template named `Default` (`type = 'mailbox'`), then the built-in defaults.
  - Built-in defaults come from `MailcowConfig::mailboxDefaultAttributes()`, which 001's reader
    exports from `$MAILBOX_DEFAULT_ATTRIBUTES` (server setting `RESTV2_MAILBOX_DEFAULT_ATTRIBUTES`
    as JSON wins), merged over the upstream values: `active` 1, `force_pw_update` 0, `force_tfa` 0,
    `sogo_access` 1, all six protocols 1, `tls_enforce_in`/`out` 0, `tagged_mail_handler` `none`,
    `quarantine_notification` `hourly`, `quarantine_category` `reject`, `mailbox_format`
    `maildir:`, no rate limit, and the `user_acl` column defaults (every permission 1 except
    `syncjobs` and `sogo_profile_reset`).
  - Template JSON keys (legacy `templates.attributes`): `quota` (bytes), `tags`,
    `tagged_mail_handler`, `quarantine_notification`, `quarantine_category`, `rl_frame`,
    `rl_value` (empty = none), `force_pw_update`, `force_tfa`, `sogo_access`, `active`,
    `tls_enforce_in`, `tls_enforce_out`, the six `*_access` keys and the fifteen `acl_*` keys. A
    key the template lacks falls through to the next source (spec deviation: legacy drops the
    template's tagged-mail handling unless the form sends it and the seeded Default lacks
    `acl_pw_reset`).
  - Quota: the request; else the named template's `quota`; else the domain's default quota for a
    new mailbox (002 FR-008: `min(defquota, largest quota for a new mailbox)`). When `defquota` is
    above 0 but no quota is left, that default is 0, which would mean "unlimited": the creation is
    refused with `outOfRange` (`max: 0`, `limit: domainQuotaLeft`) instead (plan parity note 6).
  - Display name: trimmed; leading `<` and trailing `>` removed; empty → the local part
    (spec deviation: legacy's ordering bug stores an empty name).
  - External `authSource` (`keycloak`, `genericOidc`, `ldap`): only the provider type stored in
    `identity_provider` (`key = 'authsource'`) is accepted (`notInList` with the allowed values);
    a password is refused (`conflictsWith`, `field: authSource`); an explicit
    `forcePasswordChange: true` is refused, and for `genericOidc` also
    `forceSecondFactorSetup: true`; template and default values that would turn them on are
    ignored; `password` is stored as the empty string, as legacy.
- **Rationale**: FR-018, FR-022 and FR-025, with the spec's clarified quota default.
- **Alternatives considered**: Legacy's "named template or else the first template" was rejected
  by the spec (the Default template's unlimited quota would create unlimited mailboxes silently).

### R6. Domain limits under concurrency

- **Decision**: `MailboxCreator` runs one transaction that starts with
  `SELECT domain, mailboxes, maxquota, quota, defquota FROM domain WHERE domain = ? FOR UPDATE`
  (002's locking convention for mailbox creation). Under that lock it counts the domain's
  mailboxes and sums their quotas (`kind = ''`, through `DomainUsageCalculator`), checks the
  address (R7) and inserts. Two creations in one domain therefore serialise; the primary key of
  `mailbox` and the unique `alias.address` turn a concurrent duplicate into `address-in-use`.
  - Mailbox count: `count >= domain.mailboxes` → `409 mailbox-limit-reached` (`count`, `limit`).
  - Quota (MiB in the database, bytes in the API): `quota > maxquota` → `outOfRange`
    (`limit: maxMailboxQuota`, `max` in bytes); `allocated + quota > domain.quota` →
    `outOfRange` (`limit: domainQuotaLeft`, `max` = quota left in bytes). Quota 0 is not counted.
  - `MailboxUpdater` takes the same lock when the quota changes and checks
    `quota <= largestAllowedQuota` (`limit: largestAllowedQuota`); a quota below the storage used
    is accepted.
  - SC-006 is tested with 20 parallel `php artisan tinker` processes (or `pcntl_fork` workers)
    calling `MailboxCreator` against a domain with room for 10.
- **Rationale**: FR-023 and SC-006 demand that the limits hold for concurrent requests; legacy
  checks without a lock. The domain row lock also serialises with 002's domain edits.
  - Address uniqueness across `mailbox`, `alias` and `spamalias` is enforced by upstream only in
    code, so the availability check and the insert run under the MariaDB named lock of R7, as
    `docs/conventions.md` requires. No index is added to an upstream table.
- **Alternatives considered**: a `GET_LOCK()` for the domain limits was rejected: the domain row
  lock already serialises them and is released with the transaction. Optimistic retry was
  rejected: it can still exceed the limit between check and insert.

### R7. Address normalisation and availability

- **Decision**: `App\Services\Mailboxes\MailboxAddress` normalises the local part
  (`strtolower(trim())`) and the domain (002's `DomainName::normalize()`), validates the local part
  against FR-019 (`^[a-z0-9!#$%&'*+/=?^_`{|}~-]+(\.[a-z0-9!#$%&'*+/=?^_`{|}~-]+)*$`, 1–64
  characters; `invalidFormat` with `format: localPart`) and the address with
  `filter_var(FILTER_VALIDATE_EMAIL)` and a length of at most 255 (`format: email`). In paths, an
  address that does not normalise answers `404`.
  - `AddressAvailability` checks, in this order, `mailbox.username` (holder `mailbox`, or
    `resource` when `kind <> ''`), `alias.address` (`alias`) and `spamalias.address`
    (`temporaryAlias`), and answers `409 address-in-use` with `address` and `holder`.
  - **Address lock**: `App\Services\Mailboxes\AddressLock`, on a generic
    `App\Support\Database\NamedLock` helper (`GET_LOCK`/`RELEASE_LOCK`; created here as the first
    implemented user, reused by 005 and 009), takes the MariaDB named lock
    `'restv2_address_' . substr(sha1(<normalised address>), 0, 40)` with `SELECT GET_LOCK(name, 5)`
    (0 → unexpected failure, `500`) before the availability check and releases it with
    `RELEASE_LOCK` in a `finally` after the commit (008's pattern). Creation takes it for the new
    address; deletion for the address; rename for the old and the new address for the whole step
    sequence. The same lock name serves features 004 and 005 when they create aliases and
    temporary aliases, so two features can never hand out one address twice. A named lock is
    bound to the request's database connection and is released if the process dies.
  - The creation domain must exist in `domain` and be in the caller's scope (else `notFound` on
    `domain`); a name found in `alias_domain` gives `notEligible` with `kind: aliasDomain`.
    Inactive and backup-MX domains are accepted.
- **Rationale**: FR-007, FR-019 to FR-021. Legacy checks the same three tables, each with its own
  message.

### R8. Domain-administrator permission checks

- **Decision**: `App\Services\Mailboxes\MailboxPermissionGuard` compares, for a domain
  administrator, each member of the request with the baseline: the current value on a change, the
  value resolved from template and defaults on a creation. A member that differs and whose flag
  is `false` in 001's effective flags is refused. Members and flags: `quota` = 0 →
  `unlimitedQuota` (always a choice unless the current quota is already 0, also when it comes from
  a template at creation); `protocolAccess.*` → `protocolAccess`; `webmailRedirect` →
  `sogoAccess`; `rateLimit` (creation) → `ratelimit`; `userPermissions.{syncjobs, quarantine,
  appPasswds, pushover, spamPolicy}` → the flag of the same name. All refused members are
  collected into one `403 attribute-not-permitted` with `attributes` (paths) and `requiredFlags`
  (path → flag). Bulk protocol actions need `protocolAccess` as a whole (`403 permission-flag-required` with
  `flag: protocolAccess`, `docs/conventions.md` "Permission refusals"); a role that may never use
  an operation gets `403 forbidden`.
  - Order of checks, as 002 D11: role (`403 forbidden` before any lookup), scope (`404`), shape
    (`422`), version under the row lock (`412`), permissions (`403 attribute-not-permitted`),
    read-only members (`422 immutable`), business rules (`422`, all violations together), state
    conflicts (`409`).
- **Rationale**: FR-006 and FR-035 close legacy's escalation hole (spec deviation) while
  accepting values a client merely echoes.

### R9. Creation side effects and follow-up steps

- **Decision**: `MailboxCreator`, after validation. A plain password is checked against the policy,
  which reads Redis, so an unreachable shared store answers `503 temporary-failure` before
  anything is stored. A pre-hashed password or an external source needs no Redis before the
  commit, so such a creation succeeds during an outage and reports the Redis steps as `failed`
  (spec AS-13):
  1. Transaction (domain row lock, R6): `INSERT INTO mailbox (username, password, name, quota,
     local_part, domain, attributes, authsource, active)` with legacy's attribute set (R2);
     `UPDATE mailbox SET attributes = JSON_SET(attributes, '$.passwd_update', NOW())`;
     `INSERT INTO quota2 … ON DUPLICATE KEY UPDATE bytes = 0, messages = 0` and the same for
     `quota2replica`; `INSERT INTO alias (address, goto, domain, active)` with the address as both
     address and goto and the mailbox's `active`; `INSERT INTO user_acl` with all fifteen columns;
     one `INSERT INTO tags_mailbox` per tag; the audit record `mailboxCreation` with
     `creationReport: "pending"` (002's follow-up completion). Commit.
  2. Follow-up steps, each caught on its own: the rate limit (`RateLimitStore::set(<address>,
     …)`, `applied`/`notRequested`/`failed`); the tagged-mail handling
     (`TaggedMailHandlingStore::set()`: `HSET` one hash to 1 and `HDEL` the other, as legacy
     `edit delimiter_action`; `none` = `notRequested`); the groupware entry
     (`SogoDirectory::refresh([address])` and the memcached flush; `notRequired` when inactive,
     `groupwareDisabled` when `SKIP_SOGO=y`).
  3. `AuditLogger::completeFollowUps(eventId, 'creationReport', report)`; `201` with `Location`,
     `ETag` and `creationReport`.
- **Rationale**: FR-027 and the conventions: the database part is atomic, follow-ups never undo
  it, and their outcomes are reported and audited in the one record.
- **Alternatives considered**: Writing Redis inside the transaction (002's `DOMAIN_MAP` pattern)
  was rejected here: the spec makes rate limit and tagging follow-up steps whose failure keeps the
  mailbox.

---

## 4. Reading

### R10. Efficient listing

- **Decision**: `App\Services\Mailboxes\MailboxReader` builds a page in two phases.
  1. Page query on `mailbox m` with `m.kind = ''`, the scope (`m.domain IN (SELECT domain FROM
     domain_admins WHERE active = '1' AND username = ?)` for a domain administrator), the filters
     (`m.domain IN`, `m.active IN`, `m.authsource IN`, and for tags `EXISTS (SELECT 1 FROM
     tags_mailbox t WHERE t.username = m.username AND LOWER(t.tag_name) IN (…))`) and the search
     (`m.username LIKE ? OR m.name LIKE ? OR EXISTS (… t.tag_name LIKE ?)`, escaped by 001's
     `ListQuery`). Sort expressions join only what they need: `quota2`/`quota2replica` for
     `quotaUsed`, `messageCount` and `percentUsed` (`q.bytes / NULLIF(m.quota, 0)`); a derived
     table `SELECT username, MAX(datetime) FROM sasl_log WHERE service IN ('imap','pop3','smtp')
     GROUP BY username` for `lastMailLogin`; `JSON_VALUE(m.attributes, '$.passwd_update')` for
     `passwordChangedAt`. Every sort is `ORDER BY (<expr> IS NULL), <expr> <dir>, m.username ASC`,
     so missing values come last in both directions. `COUNT(*)` with the same filters gives
     `meta.total`.
  2. Batch loading for the page's addresses (at most 1,000): domains (`backupmx`, limits, quota
     allocated per domain), usage, `user_acl`, tags, last logins, active temporary aliases,
     active push settings, and three `HMGET`s (`RL_VALUE` for addresses and their domains,
     `RCPT_WANTS_SUBJECT_TAG`, `RCPT_WANTS_SUBFOLDER_TAG`).
  - The domain administrator's `da_acl` row is read once per request (001's identity).
  - A Redis failure answers `503 temporary-failure` (`sharedStore`): the representation cannot
    be complete without it, and sessions live in the same Redis anyway.
- **Rationale**: FR-012 to FR-016 and SC-002. The existing indexes `mailbox(domain)`,
  `mailbox(kind)`, `tags_mailbox(tag_name, username)` and `sasl_log(username)` serve these
  queries; 10,000 rows sort in memory well under the budget.
- **Alternatives considered**: Legacy's per-row `mailbox_details` (11 queries per mailbox) was
  rejected (the spec's performance deviation). A materialised summary table was rejected: it
  would be API-owned state that the legacy UI does not update.

### R11. Version tags

- **Decision**: `App\Services\Mailboxes\MailboxVersion` computes 001's strong ETag over the
  canonical JSON (keys sorted recursively, values as stored) of: `username`, `name`, `quota`,
  `active`, `authsource`, `attributes` (decoded), `custom_attributes` (decoded), the fifteen
  `user_acl` columns, and the tags sorted by `mb_strtolower`. It excludes `modified`, usage,
  last logins and Redis values (read-only here, owned by 005 and 006). Templates: `id`,
  `template`, decoded `attributes`. The password policy: R4.
- **Secrets**: the inputs never include `mailbox.password` or any other stored secret, so a
  plain SHA-256 tag is allowed (`docs/conventions.md` requires an HMAC only when a secret is an
  input).
- **Rationale**: Conventions: tags cover stored settings, never usage. A change made by the
  legacy UI to any of these values changes the tag (FR-038, SC-005). `attributes` changes on every
  password set (`passwd_update`), so a password reset elsewhere also invalidates stale clients.
- **Alternatives considered**: Including `modified` was rejected: it is not a setting and legacy
  touches it on writes that change nothing visible. Including the Redis values was rejected: they
  are not changeable here, and including them would make unrelated 006 changes refuse 003 edits.

### R12. Tags in use, capabilities and deletion preview

- **Decision**:
  - Tags in use: `SELECT MIN(CAST(t.tag_name AS BINARY)), COUNT(DISTINCT t.username) FROM
    tags_mailbox t JOIN mailbox m ON m.username = t.username WHERE m.kind = '' AND <scope> AND
    t.tag_name LIKE ? GROUP BY LOWER(t.tag_name) ORDER BY LOWER(t.tag_name)`, paged.
  - Capabilities: `identityProviderType` from `identity_provider.authsource` (001's model;
    `none` when missing or not one of the three), `authSources`, `groupwareEnabled` = `SKIP_SOGO`
    is not `y`, `lastLoginsReported` = `MailcowConfig::showLastLogin()`, `bulkLimit` =
    `min(config('restv2.bulk_limit'), 100)`, `taggingLimit` = `MailcowConfig::taggingLimit()`,
    the custom-attribute limits from `config('restv2.mailbox_custom_attribute_limit')` (default and
    maximum 100), page sizes 25 and 1,000, `garbageRetentionMinutes` = `MAILDIR_GC_TIME`
    (mailcow.conf, default 7200 minutes, used by Dovecot's `maildir_gc.sh` with `find -cmin`).
  - Deletion preview: `MailboxDeletionPlanner` computes the deletion's own plan (R15) with
    `COUNT`s and up to 100 addresses for exclusive and shared aliases; blockers come from
    `DockerApiClient::reachable('dovecot-mailcow')` (container lookup only, no exec) and
    `SharedStore::ping()`. Not audited (FR-065).
- **Rationale**: FR-064 to FR-066 with one implementation of the deletion rules.

---

## 5. Changes

### R13. Change side effects

- **Decision**: `MailboxUpdater` runs in one transaction: `SELECT … FROM mailbox WHERE username = ?
  AND kind = '' FOR UPDATE` (plus the domain row when the quota changes), `If-Match`, the checks
  of R8, then:
  - `UPDATE alias SET active = ? WHERE address = ?` when the status changes (legacy updates it on
    every edit; the API only when it changes, same result);
  - authentication source before password: a switch to an external source sets
    `force_pw_update` to `"0"` (and `force_tfa` for `genericOidc`) and keeps the hash; a password
    is applied after the switch (spec deviation: legacy's order skips it), with
    `JSON_SET(attributes, '$.passwd_update', NOW())`; switching to `mailcow` without a password is
    refused when the stored password is empty (`required` on `password`);
  - `UPDATE mailbox SET active, name, quota, authsource, attributes = JSON_SET(…)` for the changed
    keys, and always `$.attribute_hash = ''` (legacy clears the identity-provider marker on every
    edit);
  - recovery e-mail: `JSON_SET(attributes, '$.recovery_email', ?)`, empty string for `null`/`""`;
  - tags: `DELETE FROM tags_mailbox WHERE username = ?` and one insert per normalised tag;
  - user permissions: one `UPDATE user_acl SET <col> = ? …` for the changed columns only (legacy
    resets unsent columns to 0, which the spec changes to "only the flags it sends");
  - custom attributes: decode, apply the merge (string sets, `null` removes), re-encode as a JSON
    object (`{}` when empty), `UPDATE mailbox SET custom_attributes = ?`;
  - `SogoDirectory::refresh([address])` inside the transaction (pure SQL);
  - the audit record `mailboxUpdate` with `changes` (`{path: {old, new}}`; the password as
    `{passwordSet: true, preHashed}`).

  After the commit, the memcached flush (failures logged, as legacy's `flush_memcached()`). A
  request whose merged values equal the stored ones changes nothing and writes no audit record.
- **Rationale**: FR-029 to FR-037 and the legacy edit path.

### R14. Groupware directory and memcached

- **Decision**: 002's `App\Services\Groupware\SogoDirectory` gains `refresh(array $usernames)`,
  the incremental form of legacy `update_sogo_static_view($mailbox)`: for each username, when the
  mailbox exists with `active = '1'`, the legacy `INSERT … SELECT … AND mailbox.username = ? ON
  DUPLICATE KEY UPDATE …` statement; otherwise `DELETE FROM _sogo_static_view WHERE c_uid = ?`;
  then legacy's orphan cleanup `DELETE FROM _sogo_static_view WHERE c_uid NOT IN (SELECT username
  FROM mailbox WHERE active = '1')`. `c_password` is a fresh random `{SSHA256}` hash, as 002
  decided. When `SKIP_SOGO=y` it does nothing. The memcached flush stays 002's
  `MemcachedFlusher` (`flush_all` over TCP to `memcached:11211`).
  - Creation refreshes the new mailbox after the commit (a reported step). Change, deletion and
    rename refresh inside their transaction (the deletion refreshes the deleted address and every
    mailbox whose alias destinations were rewritten).
- **Rationale**: Legacy refreshes incrementally for mailbox operations (`$update_sogo_mailboxes`);
  a deleted mailbox makes legacy fall back to a full rebuild, whose effect on the affected rows is
  the same as the targeted refresh and the orphan cleanup.
- **Alternatives considered**: A full rebuild per mailbox operation was rejected: it rewrites
  10,000 rows for one change and makes bulk actions slow.

---

## 6. Deletion and container calls

### R15. Deletion

- **Decision**: `MailboxDeleter`, with `MailboxDeletionPlanner` shared with the preview:
  1. Resolve in scope (`404`), take the address lock of R7, check `If-Match` against a fresh read
     (`412`), and `SharedStore::ping()` (`503`, `sharedStore`).
  2. Mail store first (FR-040): `MailStoreCommands::cleanupMaildir(domain, localPart)` →
     `DockerApiClient::exec('dovecot-mailcow', {cmd: maildir, task: cleanup, maildir:
     "<domain>/<local_part>"})`. `failed` or unreachable → `503 temporary-failure`
     (`mailStore`), nothing removed; `unknown` (timeout) → `504 outcome-unknown`
     (`step: mailStoreCleanup`), nothing removed. dockerapi's command is guarded by
     `if [[ -d … ]]`, so a maildir that never existed or is already gone is a success, and a retry
     is safe. In cluster mode see R16.
  3. Transaction: `SELECT … FOR UPDATE` of the mailbox; `If-Match` again; the deletes in legacy's
     order — `alias` (`goto = address`), `pushover`, `quarantine` (`rcpt`), `quota2`,
     `quota2replica`, `mailbox`, `sender_acl` (`logged_in_as` or `send_as`), `user_acl`,
     `spamalias` (`goto`), `imapsync` (`user2`), `filterconf` (`object`), `sogo_user_profile`,
     `sogo_cache_folder` (`c_uid`), `sogo_acl` (`c_object LIKE '%/<escaped address>/%' OR c_uid`),
     `sogo_store`, `sogo_quick_contact`, `sogo_quick_appointment` (folders of
     `sogo_folder_info.c_path2 = address`), `sogo_folder_info`, `bcc_maps` (`local_dest`),
     `oauth_access_tokens`, `oauth_refresh_tokens`, `oauth_authorization_codes` (`user_id`),
     `tfa`, `fido2` (`username`); the rewrite of every other alias whose `goto` list contains the
     address (`REGEXP '(^|,)<quoted>($|,)'`, element removed); then, beyond legacy, `sasl_log`
     (`username`) and `restv2_preferences` (`account_kind = 'mailbox'`). `tags_mailbox`,
     `sieve_filters` and `app_passwd` go by their foreign keys' `ON DELETE CASCADE`; they are
     counted first for the audit record.
  4. Redis in one `MULTI` through `SharedStore`: `HDEL RL_VALUE`, `HDEL RCPT_WANTS_SUBJECT_TAG`,
     `HDEL RCPT_WANTS_SUBFOLDER_TAG` for the address, remembering the old values; a failure rolls
     back the transaction (`503`); a failed commit restores them.
  5. `SogoDirectory::refresh([address, …rewritten alias owners])`; the audit record
     `mailboxDeletion` (`mailStoreCleanup: done`, removed counts); commit; memcached flush.
  - If the transaction fails after the mail move, the answer is `500`; the audit failure record
    says `mailStoreCleanup: done`, and the mail stays in `_garbage` for `MAILDIR_GC_TIME` minutes,
    from where an operator can restore it. A retry completes the deletion.
- **Rationale**: FR-039 to FR-042, SC-007. The spec deviates from legacy, which deletes the records
  even when the move fails.

### R16. dockerapi commands and cluster mode

- **Decision**: 002's `App\Clients\DockerApi\DockerApiClient` (`exec(service, command)` returning
  `DockerCallResult` `success`/`failed(message)`/`unknown`) gains:
  - `reachable(string $service): bool` — the container lookup (`GET /containers/json`, labels
    `com.docker.compose.service` and `com.docker.compose.project` = `COMPOSE_PROJECT_NAME`) without
    an exec, for preflight checks and the preview;
  - `broadcast(string $service, array $command): void` — legacy's `docker('broadcast')`:
    `PUBLISH MC_CHANNEL {"api_call":"container_post","container_name":<service>,
    "post_action":"exec","request":<command>}` on the `mailcow` Redis connection;
  - an unreachable result (`failed` with `unreachable: true`) for connection errors, so callers can
    map it to `temporary-failure`;
  - a per-request circuit breaker: after a connection failure or a timeout, further calls in the
    same request return the same result at once, so a bulk action against a dead container API
    fails fast instead of waiting 60 s per mailbox.

  `App\Services\Mailboxes\MailStoreCommands` builds the commands legacy sends:
  `{cmd: maildir, task: cleanup, maildir}`, `{cmd: maildir, task: move, old_maildir,
  new_maildir}`, `{cmd: doveadm, task: get_acl, id}` (answer: a JSON list of
  `{user, id, mailbox, rights[]}`), `{cmd: doveadm, task: delete_acl, user, mailbox, id}`,
  `{cmd: doveadm, task: set_acl, user, mailbox, id, rights}` to `dovecot-mailcow`, and
  `{cmd: sogo, task: rename_user, old_username, new_username}` to `sogo-mailcow`.
  - With `CLUSTERMODE=replication`, maildir cleanup and move go to the local node with `exec`
    (confirmed) and then to every node with `broadcast`; the local node receives the broadcast too
    and finds nothing left to move (the `-d` guard). Legacy only broadcasts (plan parity note 4).
- **Rationale**: Constitution VII (faked behind an interface, calls asserted) and FR-040's
  "every mail node" with a confirmed outcome.

---

## 7. Rename

### R17. Rename as a compensated sequence of steps

- **Decision**: `App\Services\Mailboxes\MailboxRenamer`.
  - **Preflight**, nothing changed yet: role, scope (`404`), shape; the address locks of R7 on the
    old and the new address, held until the audit record is written; `If-Match`; status 1 or 2 (else
    `409 mailbox-not-renamable`); normalised new local part valid and different, also ignoring
    case (`unchanged`); new address free (`address-in-use`); with `keepOldAddressAsAlias`, room
    for one alias (002's alias count: aliases of the domain and its alias domains whose address is
    not a mailbox) else `409 domain-alias-limit-reached`; `SharedStore::ping()`;
    `DockerApiClient::reachable('dovecot-mailcow')` and, unless `SKIP_SOGO=y`,
    `reachable('sogo-mailcow')` (else `503`, dependency named). `ignore_user_abort(true)` and
    `set_time_limit(0)` for the rest of the request.
  - **Steps**, each recorded with its outcome; on a failure the done steps are undone in reverse
    order:

    | # | Step | Action | Undo |
    |---|---|---|---|
    | 1 | `sharedFolderPermissionsRemoval` | `get_acl` for the old address, then `delete_acl` for each entry (the ACLs it grants and the ACLs others grant it) | `set_acl` each removed entry with its original user, id and rights |
    | 2 | `sharedStoreMove` | one `MULTI`: for `RL_VALUE`, `RCPT_WANTS_SUBJECT_TAG`, `RCPT_WANTS_SUBFOLDER_TAG`, `HSET <new>` with the old value and `HDEL <old>` | the reverse `MULTI` |
    | 3 | `database` | one transaction through `MailboxDataMover::move(old, new)` (R18), `SET FOREIGN_KEY_CHECKS = 0` inside it as legacy, plus the optional alias and the groupware entry | `MailboxDataMover::move(new, old)` in one transaction, deleting the alias created in step 3 |
    | 4 | `mailStoreMove` | `{cmd: maildir, task: move}` (cluster: R16) | the move back |
    | 5 | `webmailRename` | `sogo rename_user old new`; `skipped` when `SKIP_SOGO=y` (plan parity note 3) | `sogo rename_user new old` |
    | 6 | `sharedFolderPermissionsRecreation` | `set_acl` for each entry of step 1 with the old address replaced by the new one in `user` and `id` | `delete_acl` of the entries set |

    The order is legacy's except for step 2, which legacy does not have. Dovecot resolves users
    through the `mailbox` table, so ACLs can only be removed before step 3 and set after it.
  - **Outcomes**: all done → `200`, then the memcached flush. A failed step whose undo of all done
    steps succeeds → `503 temporary-failure` with `dependency` and `steps`. An undo that fails →
    `500 mailbox-rename-incomplete` with `steps` (`undoFailed` entries). A step answering
    `unknown` (timeout) → the done steps are undone and the answer is `504 outcome-unknown` naming
    the step, which is left alone because its command may still be running.
  - **Audit**: one `mailboxRename` record written after the steps in its own transaction, with
    `previousAddress`, `newAddress`, `keepOldAddressAsAlias`, `steps` and the outcome. If that
    write fails after a successful rename, the steps are undone and the answer is `500`
    (Complexity Tracking in the plan). Every step is also logged at `info` with the request ID, so
    an operator can reconstruct an interrupted rename from the logs.
- **Rationale**: FR-043 to FR-048 and SC-008. Each step's undo is the same operation in reverse,
  which keeps the design small and testable by injecting a failure into each step.
- **Alternatives considered**: A persistent saga journal table was rejected: it adds API-owned
  state and a recovery job for a rare crash, while the response, the audit record and the logs
  already list the steps. Compensating the step that timed out was rejected: racing a command
  that may still run could move a maildir twice.

### R18. The rename's database part

- **Decision**: `App\Services\Mailboxes\MailboxDataMover::move(string $from, string $to)`, symmetric
  so that the undo is the same call reversed:
  - legacy's statements: `mailbox` (`username`, `local_part`), the own alias (`address` and
    `goto` where `address = from`), and `UPDATE <table> SET <col> = to WHERE <col> = from` for
    `tags_mailbox.username`, `sieve_filters.username`, `app_passwd.mailbox`, `user_acl.username`,
    `quota2.username`, `quota2replica.username`, `pushover.username`, `alias.goto`,
    `imapsync.user2`, `bcc_maps.local_dest`, `bcc_maps.bcc_dest`, `recipient_maps.old_dest`,
    `recipient_maps.new_dest`, `sender_acl.logged_in_as`, `sender_acl.send_as`, and
    `_sogo_static_view` (`c_uid`, `c_name`, `mail`);
  - not `da_acl` (spec FR-045 deviation);
  - multi-recipient alias destinations: the address is replaced element-wise in every `goto` list
    that contains it (plan parity note 1);
  - carried over (FR-046): `tfa.username`, `fido2.username`, `spamalias.goto`,
    `filterconf.object`, `quarantine.rcpt`, `sasl_log.username`, and `restv2_preferences`
    (`account_kind = 'mailbox'`, plan parity note 5);
  - revoked: `DELETE FROM oauth_access_tokens / oauth_refresh_tokens / oauth_authorization_codes
    WHERE user_id = from` (not restored by an undo);
  - forward only: with `keepOldAddressAsAlias`, `INSERT INTO alias (address, goto, domain,
    private_comment, sogo_visible, internal, sender_allowed, active) VALUES (from, to, domain,
    'Mailbox was renamed from <from> to <to>', 1, 0, 0, 1)` — legacy's `mailbox('add', 'alias')`
    call passes no `sender_allowed` or `internal`, which `intval()` turns into 0;
  - `SogoDirectory::refresh([to])` so that the alias list of the renamed mailbox is current.
  - Later features with API-owned per-mailbox data register it here (005).
- **Rationale**: Parity plus the spec's carry-over list in one atomic database step.

---

## 8. Bulk actions, tags, attributes, audit

### R19. Bulk actions

- **Decision**: `App\Services\Mailboxes\MailboxBulkActions`, as 002 D20: the request is refused as
  a whole only for the role, the key scope, a domain administrator without `protocolAccess` for a
  protocol action (`403 permission-flag-required`, `flag: protocolAccess`) and for more targets than the bulk limit (`422 tooMany`).
  Targets are normalised (R7) and deduplicated in request order (the first spelling is the result
  `id`); an address that does not normalise gives `notFound`. Each target runs `MailboxUpdater`
  (`status` or one `protocolAccess` member) or `MailboxDeleter` with its own transaction, version
  check (`version` compared like `If-Match`) and audit record carrying `bulkRequestId`. Outcome
  mapping: success → `done` (with the new `version` unless deleted), `404` → `notFound`, `412` →
  `outdated`, `403`/`409`/`422` → `refused`, `503`, `504` and exceptions → `failed`, each with its
  problem. The memcached flush runs once at the end if anything changed.
  - The per-target loop (deduplication, version check, outcome mapping, summary) is extracted from
    002's `DomainBulkActions` into `App\Support\Bulk\BulkActionRunner` and `BulkOutcome` on its
    second use (constitution VIII); 002's class then uses it, and 004 to 013 reuse it.
- **Rationale**: FR-054, FR-055, FR-067 and the conventions.

### R20. Tags

- **Decision**: 002's `DomainTagSet` is generalised into `App\Support\Tags\TagSet` (second use,
  constitution VIII): trim, refuse empty (`required` on `tags.<i>`) or longer than 255
  (`tooLong`), drop later duplicates compared with `mb_strtolower`, keep the first spelling, limit
  `MailcowConfig::taggingLimit()` with `tooMany` unless the new set equals or is smaller than the
  current one. `DomainTagSet` becomes a thin use of it.
- **Rationale**: FR-036 is word for word 002's tag rule.

### R21. Custom attributes

- **Decision**: Names must match `^\S{1,255}$` (`invalidFormat` with `format: attributeName`),
  values are strings of at most 4,096 characters (`tooLong`), and the merged set may hold at most
  `config('restv2.mailbox_custom_attribute_limit')` (100) attributes (`tooMany` on
  `customAttributes`). Stored as a JSON object in `mailbox.custom_attributes`; domain footers
  (php-fpm's `footer.php`) read it unchanged.
- **Rationale**: FR-037 (spec deviation: legacy strips spaces and has no limits).

### R22. Audit

- **Decision**: Event types `mailboxCreation`, `mailboxUpdate`, `mailboxDeletion`, `mailboxRename`,
  `mailboxTemplateCreation`, `mailboxTemplateUpdate`, `mailboxTemplateDeletion`; target types
  `mailbox` (id = address, name = display name) and `mailboxTemplate` (id = template ID, name =
  template name). `details` keys added to the whitelist: `changes`, `values` and `templateId`
  (creation, without the password), `passwordSet`, `preHashed`, `creationReport`,
  `mailStoreCleanup`, `removed` (counts), `previousAddress`, `newAddress`,
  `keepOldAddressAsAlias`, `steps`, `bulkRequestId`, `bulkAction`, `problemType`, `errors`.
  - **Refusals**: every state-changing route (`createMailbox`, `updateMailbox`, `deleteMailbox`,
    `renameMailbox`, `applyMailboxBulkAction` and the three template writes) declares the route
    default `restv2.audit` (`['event' => …, 'target' => 'mailbox' | 'mailboxTemplate']`; the bulk
    route uses `'event' => 'fromBulkAction'`), so that 008's `App\Services\Audit\RefusalAuditor`,
    or the equivalent hook of the first implemented feature, writes one failure record for every
    refusal by an authenticated caller: read-only keys, restricted sessions, missing roles and
    flags, out-of-scope and unknown objects (`404`), outdated versions, validation failures,
    conflicts and temporary failures. CSRF failures and `401`s are not recorded
    (`docs/conventions.md`). `MailboxAuditTargetResolver` supplies the target: for
    `{mailbox}` routes the normalised address as sent (looked up without scope; the audit log is
    for administrators only), for a creation `localPart@domain` as sent (normalised when possible,
    truncated to 255), for template routes the id and name, for a whole-request bulk refusal a
    `null` target with `details.itemIds`. Failure reasons are 002's and 008's shared values
    (`attributeNotPermitted`, `versionOutdated`, `validationFailed`, `conflict`,
    `temporaryFailure`, `permissionDenied`, `notFound`) and the two this feature appends to the
    shared `AuditFailureReason` (`outcomeUnknown`, `partiallyCompleted`). Refusals raised inside a
    rename or a deletion after the preflight are recorded by those services, not twice.
    `RefusalAuditor` handles `4xx` problems only; the services record their own `5xx` outcomes
    (`temporaryFailure`, `outcomeUnknown`, `partiallyCompleted`, and a database failure after the
    mail move) in their own transaction.
  `AuditLogger::completeFollowUps()` accepts the key `creationReport` for `mailboxCreation`.
  Reads, including the preview, are not audited.
- **Rationale**: FR-056, SC-010 and 002's audit conventions.

---

## 9. Configuration, dependencies, tests and operations

### R23. Settings

| Variable | Default | Meaning |
|---|---|---|
| `MAILCOW_PASS_SCHEME` | `BLF-CRYPT` (mailcow.conf) | Hash scheme (R3) |
| `MAILDIR_GC_TIME` | `7200` (mailcow.conf, minutes) | Garbage retention reported by capabilities and the preview |
| `CLUSTERMODE` | empty (mailcow.conf) | `replication` → confirmed local move plus broadcast (R16) |
| `MASTER` | `y` (mailcow.conf) | `quota2` (y) or `quota2replica` (n) for usage figures |
| `SKIP_SOGO`, `COMPOSE_PROJECT_NAME` | 001 / 002 | Groupware steps; container lookup |
| `RESTV2_SHOW_LAST_LOGIN` | `vars.inc.php` `$SHOW_LAST_LOGIN` (`true`) | Exported by `MailcowConfig` (001 R14) |
| `RESTV2_MAILBOX_DEFAULT_ATTRIBUTES` | `vars.inc.php` `$MAILBOX_DEFAULT_ATTRIBUTES` | JSON object, exported by `MailcowConfig`, merged over the upstream values (R5) |
| `RESTV2_TAGGING_LIMIT`, `RESTV2_DOCKER_TIMEOUT`, `RESTV2_BULK_LIMIT` | 002 | Tagging limit, container timeout, bulk limit (capped at 100 here) |
| `RESTV2_MAILBOX_CUSTOM_ATTRIBUTE_LIMIT` | `100` | Custom attributes per mailbox (1–100) |

`resources/bin/export-mailcow-vars.php` adds `SHOW_LAST_LOGIN` and `MAILBOX_DEFAULT_ATTRIBUTES` to
its JSON output. The page sizes are contract constants (25 and 1,000).

### R24. Dependencies

- **Decision**: No new Composer package and no new PHP extension. Hashing uses PHP's
  `password_hash`, `hash` and `random_bytes`; Redis is phpredis; dockerapi uses Laravel's HTTP
  client (002); memcached uses a socket (002). All dependencies stay GPL-3.0-compatible.

### R25. Tests

- **Decision**:
  - Every feature test validates requests and responses against the contract (001 R13), on
    MariaDB with the fixture and real Redis (database 15).
  - `FakeDockerApiClient` (002) gains scripted answers per `{service, cmd, task}`
    (`success`, `failed`, `unknown`, `unreachable`), a canned `get_acl` list, a broadcast log
    and assertions (`assertExecuted`, `assertNotExecuted`, `assertOrder`). `FakeMemcachedFlusher`
    asserts flushes. The rename suite injects a failure into each of the six steps and into each
    undo.
  - `MailcowFactory` gains writers that insert rows exactly as legacy does: `mailbox()` with the
    string attribute set, `quota2`/`quota2replica`, own `alias`, `user_acl`, `tags_mailbox`,
    `mailboxTemplate()` (including the seeded Default template of `init_db.inc.php`), `alias()`,
    `spamalias()`, `senderAcl()`, `imapsync()`, `filterconf()`, `quarantine()`, `pushover()`,
    `bccMap()`, `recipientMap()`, `sieveFilter()`, `appPasswd()`, `sogoData()`, `oauthTokens()`,
    `saslLog()`. The fixture contains no rows, so tests seed the Default template explicitly.
  - Shared store outages point the `mailcow` connection at a closed port (002's approach).
  - Password hashes are checked with 001's `LegacyPasswordVerifier` and the legacy fixture.
  - Concurrency (SC-006) and performance (SC-002) tests run in the `concurrency` and
    `performance` groups; the performance test seeds 10,000 mailboxes in 500 domains with
    multi-row inserts.

### R26. Long requests

- **Decision**: A rename or a bulk deletion can take several container calls of up to 60 s each.
  `deploy/nginx/site.restv2.custom` (001) sets `fastcgi_read_timeout 900s` for `/api/v2/`, and the
  rename, deletion and bulk controllers call `ignore_user_abort(true)` and `set_time_limit(0)`,
  so a disconnecting client never interrupts a step sequence half-way. The per-request circuit
  breaker (R16) bounds a bulk action against a dead container API.
- **Rationale**: SC-008 and FR-048 ("complete or leave as it was") must hold even when the client
  goes away.
