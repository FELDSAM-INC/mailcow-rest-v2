# Research: Aliases, Alias Domains, Resources and Sender Permissions

**Feature**: `specs/004-aliases-alias-domains-resources` | **Date**: 2026-10-18 | **Plan**: [plan.md](./plan.md)

Feature 001 settled every shared technical decision: Laravel 13 on PHP 8.4, the Docker dev stack,
the schema fixture generated from upstream `init_db_schema()`, one MariaDB connection with the
`restv2_` prefix for API-owned tables, the `restv2:` Redis prefix, problem URIs, contract
validation of every test request and response, the request pipeline, `MailcowConfig`, `AuditLogger`
and the test toolkit ([001 research](../001-auth-sessions-api-keys/research.md)). This document
records only the decisions this feature adds. They follow the owner's precedence: legacy behaviour
first, then the constitution and `docs/conventions.md`, then best practice.

Legacy references are paths in upstream mailcow-dockerized `2026-09a`
(`git -C ~/Projects/mailhosting show 2026-09a:<path>`). For this feature the FeldHost fork differs
only in the pre-filled DKIM selector, the ISPConfig DKIM push and branding texts (spec, "Legacy
Parity"). The main sources are `data/web/inc/functions.mailbox.inc.php` (`mailbox()` cases
`alias`, `alias_domain`, `resource`, the sender ACL branches of `edit/mailbox`,
`sender_acl_handles`, the getters and the closing groupware update), `functions.inc.php`
(`hasDomainAccess`, `hasAliasObjectAccess`, `is_local_mailcow_domain`, `is_valid_domain_name`,
`update_sogo_static_view`, `flush_memcached`), `functions.ratelimit.inc.php`,
`functions.dkim.inc.php`, `init_db.inc.php` and the Postfix SQL maps in
`data/Dockerfiles/postfix/postfix.sh`.

---

## R1. Contract layout and paths

- **Decision**: Four modules under `api/modules/`, 23 operations (list in [plan.md](./plan.md#contract-operations)):
  - `aliases`: `/aliases`, `/aliases/{aliasId}`, `/aliases/{aliasId}/alias-domain-copies`, the
    bulk actions `/aliases/bulk-actions` (including the copy over alias domains), and the
    feature's capabilities at `/capabilities/aliases` (`getAliasesCapabilities`);
  - `alias-domains`: `/alias-domains`, `/alias-domains/{aliasDomain}`, `/alias-domains/bulk-actions`;
  - `resources`: `/resources`, `/resources/{resourceAddress}`, `/resources/bulk-actions`;
  - `sender-settings`: `/mailboxes/{mailbox}/sender-settings` and the top-level helper list
    `/sender-permission-candidates?mailbox=…`.

  Aliases are addressed by their numeric identifier (spec clarification), alias domains by their
  normalised name, resources by their address, and sender settings by the mailbox address, with the
  path parameter name `mailbox` that feature 003 uses for `/mailboxes/{mailbox}`. Capabilities and
  helper lists use top-level paths (`docs/conventions.md`, "Capabilities, history and refusals"),
  like 002's `/capabilities/domains` and `/domain-tags`. Bulk actions use
  `POST /{collection}/bulk-actions`, which object paths never accept. `{aliasId}` is an integer, so
  the literal sub-path `bulk-actions` can never be mistaken for an identifier; `routes/api.php`
  registers it first and constrains `{aliasId}` to digits.
- **Rationale**: Aliases and resources are listed across domains (an administrator sees all), so
  top-level collections with domain filters serve the screens better than nesting under
  `/domains/{domain}`. The sender settings belong to a mailbox and have their own version tag
  (clarification), so they are a sub-resource of the mailbox that feature 003 owns.
- **Alternatives considered**: `/domains/{domain}/aliases` was rejected: an alias moves between
  domains when its address changes, and an alias in an alias domain would sit under a name that
  `/domains/{domain}` answers with `404` (002 FR-006). Folding sender permissions into the mailbox
  `PATCH` of 003 was rejected by the clarification (separate version tag). The candidates list
  under `/mailboxes/{mailbox}/sender-settings/` was replaced by the top-level helper list the
  conventions require.

## R2. Which `alias` rows are aliases

- **Decision**:
  - The row through which a mailbox receives mail at its own address is the row whose `address`
    equals a `mailbox.username`. It is excluded from every query of this feature with
    `NOT EXISTS (SELECT 1 FROM mailbox m WHERE m.username = alias.address)`, and addressing it
    answers `404` (FR-013).
  - An alias is **orphaned** when `alias.domain` is neither a `domain.domain` nor an
    `alias_domain.alias_domain` (FR-019). Orphaned aliases are visible to administrators only,
    carry `orphaned: true`, can be deleted, and accept a change only when the change moves them into
    an existing domain.
  - The alias's **primary domain** is `alias_domain.target_domain` when `alias.domain` is an alias
    domain, otherwise `alias.domain`.
- **Rationale**: The exclusion rule is exactly the alias count of legacy `domain_details`
  (`address NOT IN (SELECT username FROM mailbox)`), which feature 002 reuses for its usage figures
  (002 FR-008). FR-013 requires the two to be consistent. Legacy's list uses `address != goto`
  instead; the two differ only for rows that legacy itself never writes (an own-alias row whose
  destinations another tool changed), and the count rule is the one that decides the alias limit.
  The `mailbox.username` primary key makes the anti-join an index lookup.
- **Alternatives considered**: `address != goto` was rejected because it would count and list rows
  that the alias limit does not count.

## R3. Address and domain normalisation and validation

- **Decision**:
  - **Addresses** (alias addresses, destinations, sender entries):
    `App\Services\Aliases\AliasAddress` trims, splits at the last `@`, keeps the local part as
    given (legacy keeps the case of alias local parts, unlike mailboxes, whose 003
    `MailboxAddress` lower-cases them), converts the domain part with 002's
    `App\Support\Domain\DomainName::normalize()` (UTS #46, lower case) and rejoins. Validity is PHP's own `filter_var($address, FILTER_VALIDATE_EMAIL)`, which is what
    legacy calls, so FR-009's rules (64-character local part, unquoted, ASCII, no address
    literals, a domain of at least two labels whose last label starts with a letter) hold by
    construction. A catch-all is `@` followed by a valid domain name (`is_valid_domain_name`), so
    single-label domains accept only catch-all aliases (edge case).
  - **Domain names** (alias domain names, targets, resource domains, domain filters): trimmed,
    lower-cased and converted with UTS #46, then checked with the port of `is_valid_domain_name`:
    feature 002's `App\Support\Domain\DomainName` (`normalize()`, `isValid()`, `toUnicode()`,
    002 D4), reused unchanged.
  - **Readable forms** (`addressUnicode`, `domainUnicode`, `nameUnicode`, `valueUnicode`) are
    computed with `idn_to_utf8` on output. A domain that does not convert is shown as stored.
  - **Comparison**: addresses are compared without regard to letter case. In SQL that is the
    `utf8mb4_general_ci` collation of the upstream columns; in PHP it is `mb_strtolower` on both
    sides.
- **Rationale**: Calling the same PHP functions as legacy is the most exact parity possible, and
  the dev image already has `intl`. FR-008 requires the same normalisation on creation and change;
  legacy validated destinations before converting them on edit, which the spec marks as a fixed
  deviation.
- **Alternatives considered**: An RFC 5322 parser (for example `egulias/email-validator`) was
  rejected: it accepts addresses that legacy refuses and adds a dependency.

## R4. Destinations and special actions

- **Decision**:
  - **Representation**: `destinationType` is `discard`, `learnSpam` or `learnHam` when `alias.goto`
    is exactly `null@localhost`, `spam@localhost` or `ham@localhost`; otherwise it is `addresses`
    and `destinations` is `explode(',', goto)` with every entry kept as stored (FR-012).
  - **Request**: `destinations` with `destinationType` omitted or `addresses`, or a special action
    in `destinationType` alone. Both together give `conflictsWith` on `destinations`
    (`field: destinationType`); `destinationType: addresses` without destinations (on creation, or
    on a change from a special action) gives `required` on `destinations`.
  - **Per destination** (after normalisation): `invalidFormat` (`format: email`) unless
    `FILTER_VALIDATE_EMAIL` accepts it, which also refuses the three reserved addresses because
    `localhost` has one label; `conflictsWith` (`field: address`, `value`) when it equals the
    alias's resulting address case-insensitively; `notEligible` (`kind: resource`) when it is the address of a
    resource (`mailbox.kind REGEXP 'location|thing|group'`).
  - **List**: duplicates that differ only in letter case are merged, keeping the first spelling
    and order (`array_unique` over lower-cased keys). The joined list (`implode(',', …)`) may have
    at most 65,535 characters (`tooLong`, `max: 65535`, `unit: characters`), the `TEXT` limit.
  - **External destinations** (FR-027): a destination is external when its domain is neither a
    `domain` with `active = 1` nor an `alias_domain` with `active = 1` (legacy
    `is_local_mailcow_domain`). The check applies only to **added** destinations: those whose
    lower-cased form is not in the alias's stored destination list (on creation every destination
    is added). When `MailcowConfig::aliasDisableExternalDomains()` is true it refuses them for
    every role with a validation error (`notEligible`, `kind: externalDestination`, on
    `destinations.N`); otherwise, for a domain administrator without `aliasExternalGoto`, with
    `403 attribute-not-permitted` naming each such destination (`attributes: [destinations.N, …]`,
    `requiredFlags: {destinations.N: aliasExternalGoto}`), the shared permission refusal of
    `docs/conventions.md`.
- **Rationale**: One field per concern keeps the merge-patch semantics simple: sending
  `destinations` replaces the list, sending a special action switches to it, and the representation
  can be sent back unchanged. The added-destinations rule is the clarification of 2026-10-18.
- **Alternatives considered**: Boolean flags per special action (the legacy form) were rejected:
  legacy silently resolves several checked boxes, and FR-026 requires exactly one action.

## R5. Order of checks and how refusals are reported

- **Decision**: Every write runs these steps; the first failing step answers, and nothing is
  stored (FR-028):
  1. the pipeline of 001 R8: authentication, key scope (`insufficient-key-scope`), session,
     role (`forbidden` for mailbox users), CSRF, pending actions; then, for alias domain creation
     only, the operation's flag: `403 permission-flag-required` (`requiredFlags: [aliasDomains]`), checked
     before anything else of the request (`docs/conventions.md`, "Permission refusals");
  2. object lookup in the caller's scope: `404` (`about:blank`) for a missing or out-of-scope
     object, including the own-alias row and an orphaned alias for a domain administrator;
  3. Form Request validation, then the domain-aware validation in the service, collecting **every**
     invalid field and entry into one `422 validation-failed`: syntax, lengths, referenced domains
     and addresses that do not exist or lie outside the caller's scope (`notFound`, the same answer
     for both, FR-003), resources as destinations, relay or alias domains as targets;
  4. inside the transaction, after `SELECT … FOR UPDATE` of the object, `If-Match` against the
     current version tag: `412` (`about:blank`, 001 convention; 002 D11 orders the version check
     after the shape check in the same way);
  5. field permission flags: `403 attribute-not-permitted` for `rateLimit` (`ratelimit`) and
     `externalSenderAddresses` (`extendSenderAcl`), with `attributes` and the `requiredFlags` map
     (member → flag) that feature 003 added, and for each added external destination of a domain
     administrator without `aliasExternalGoto` (FR-006, FR-027);
  6. read-only members sent with a different value: `422 immutable`;
  7. state conflicts, evaluated under the locks of R6: `409 address-in-use`,
     `409 alias-domain-already-exists`, `409 domain-alias-limit-reached`,
     `409 not-primary-domain-alias`;
  8. the shared store for alias domain creation and deletion: `503 temporary-failure` (R22).

  A value sent unchanged never needs a flag (FR-006), so steps 3 and 5 compare with the stored
  value first. All field errors arrive in one response, which is what SC-009 measures, and
  permission and conflict refusals stay distinguishable by type. Every refusal of an
  authenticated caller is audited (R20).
- **Rationale**: This is 001 R8 and 002 D11 extended to the body-dependent checks of this feature,
  and matches the contract text of `createAlias` and `updateAlias`.
- **Alternatives considered**: A feature-specific "destination not permitted" problem type was
  dropped: `docs/conventions.md` allows only `forbidden`, `permission-flag-required` and
  `attribute-not-permitted` for permission refusals. A missing flag is therefore
  `attribute-not-permitted` on the destinations, and the server-wide switch, which is not a
  permission of the caller, is a validation rule (FR-027 and FR-065 were aligned).

## R6. Alias limit, address uniqueness and concurrency

- **Decision**:
  - **Count** (FR-022): feature 002's `App\Services\Domains\DomainUsageCalculator` alias figure for
    primary domain `P` (aliases of `P` and of its alias domains whose address is not a
    `mailbox.username`, 002 D5). The limit is `domain.aliases`.
  - **Rule**: a creation, copy or move into `P` that adds `n ≥ 1` aliases is refused when
    `count + n > limit`, with `domain`, `count`, `limit` and `requested = n`. An alias domain whose
    target changes moves `n` = its alias count; a move of zero aliases is never refused. A change
    that stays in the same primary domain is never checked, so an already exceeded limit refuses
    only creations and moves into the domain (edge case).
  - **Limit lock**: inside the transaction, `SELECT domain, aliases FROM domain WHERE domain = :P FOR UPDATE`
    before counting (002's locking convention for creations that check domain limits, D27), so
    two requests for the last free alias of a domain serialise and exactly one succeeds. An alias
    domain target change locks the new target.
  - **Address uniqueness** is enforced upstream only in code across `mailbox`, `alias` and
    `spamalias`, so it is guarded with MariaDB `GET_LOCK()` around check-and-insert
    (`docs/conventions.md`). This feature uses feature 003's `App\Services\Mailboxes\AddressLock`
    with its lock name `'restv2_address_' . substr(sha1(<lower-cased normalised address>), 0, 40)`
    and timeout 5 s, taken before the transaction and released in a `finally` after the commit,
    for: an alias creation, each copy, an address change (the new address) and a resource creation.
    Mailbox creation and rename (003) and temporary aliases (005) take the same lock, so no two
    features hand out one address twice. The unique key on `alias.address` stays the last guard:
    a duplicate-key error maps to `409 address-in-use`. An address in an alias domain
    (`anna@example.net`) and the mailbox it would shadow (`anna@example.org`) have different lock
    names; the limit lock on the shared primary domain row serialises them.
  - **Alias domain names** must not exist as a domain or alias domain. This feature follows
    002's convention for that pair (D8, D27): `SELECT … FROM alias_domain WHERE alias_domain = ? FOR UPDATE`
    and `SELECT … FROM domain WHERE domain = ? FOR UPDATE` in the creation transaction, the same
    key and gap locks that 002's domain creation takes, so a domain and an alias domain of one name
    can never both be created; the `alias_domain` primary key catches the rest.
- **Rationale**: Row locks on the `domain` primary key serialise exactly the competing limit checks
  and are shared with 002 and 003. Named locks are the conventions' rule for code-only
  uniqueness, and sharing 003's lock name makes them effective across features. Legacy does not
  lock, so a concurrent legacy write can still race, which the spec accepts.
- **Alternatives considered**: A new unique index across tables is impossible (constitution III).
  Counting without a lock was rejected (FR-022). A separate 004 lock name for addresses was
  rejected: it would not exclude a concurrent mailbox creation.

## R7. Address in use

- **Decision**: An address is in use (FR-010) when, compared case-insensitively:
  - it is a `mailbox.username` (holder `mailbox`, or `resource` when `kind` is not empty);
  - it is an `alias.address` (holder `alias`; a mailbox's own-alias row is found as the mailbox
    first, so it reports `mailbox`);
  - it is a `spamalias.address` (holder `temporaryAlias`);
  - its domain is an `alias_domain.alias_domain` and `CONCAT(<local part>, '@', target_domain)` is
    a `mailbox.username` (holder `mailbox`, legacy's alias-domain clause).

  The first three are feature 003's `App\Services\Mailboxes\AddressAvailability` (003 R7), reused
  unchanged. The alias-domain clause matters only for aliases (a mailbox can never be created in an
  alias domain), so `App\Services\Aliases\AliasAddressAvailability` calls 003's check and then adds
  it. The answer is `409 address-in-use` with `address` and `holder`, under the address lock of R6.
  For an address change, the alias's own current address does not count (FR-030), so a change of
  letter case alone is possible.
- **Rationale**: Legacy checks `alias`, the alias-domain clause and `spamalias` for aliases, but
  not resources; the spec adds resources (deviation in its parity table). Reusing 003's check
  keeps one definition across mailbox creation, rename and alias creation.

## R8. Sender permissions that follow alias changes

- **Decision** (all in the transaction of the alias change):
  - **After a creation or change with `senderAllowed = true`** (FR-032):
    `DELETE FROM sender_acl WHERE send_as = :address AND logged_in_as IN (:destinations)`, where
    the destinations are the alias's resulting destination addresses. With `senderAllowed = false`
    nothing is deleted, so deliberate overrides survive (legacy deleted them on every edit; spec
    deviation).
  - **Address change** (FR-030): `UPDATE sender_acl SET send_as = :new WHERE send_as = :old AND external = 0`,
    after deleting the rows of mailboxes that already hold `:new`, so no duplicate arises. The
    FR-032 cleanup then runs against `:new`.
  - **Deletion** (FR-035): `DELETE FROM sender_acl WHERE send_as = :address`, legacy's statement.
  - **Catch-all aliases**: their address is `@domain`, which is also the stored form of a domain
    permission. The statements above therefore act on the domain permissions for that domain, as
    legacy's do: deleting a catch-all alias removes the explicit domain permissions for its domain,
    and creating one that allows sending removes its destinations' domain permission for it. The
    contract states this on `deleteAlias`. It is listed as an owner-level question in the plan.
- **Rationale**: These are the legacy statements, narrowed by the spec's two stated deviations.
- **Alternatives considered**: Leaving permissions for the old address in place (legacy) was
  rejected by FR-030: they would grant send-as rights for an address nobody holds.

## R9. Groupware directory updates

- **Decision**: The groupware directory is mailcow's `_sogo_static_view` table plus the memcached
  cache in front of it. This feature reuses, unchanged, feature 002's
  `App\Services\Groupware\SogoDirectory` (`rebuild()`, 002 D13), the `refresh(array $usernames)`
  that feature 003 adds to it (003 R14: legacy's per-mailbox statement for active mailboxes, row
  deletion otherwise, then the orphan cleanup), and 002's `App\Clients\Memcached\MemcachedFlusher`.
  Both do nothing with `SKIP_SOGO=y` and report `groupwareDisabled`.
  - **Aliases** (create, change, delete, bulk flags, copies): `refresh()` of the former and new
    destination addresses, special actions excluded (legacy's `$update_sogo_mailboxes`), inside the
    change's transaction in a savepoint (a nested `DB::transaction`), as 003 does for changes. A
    failure rolls back to the savepoint, is recorded in the audit record
    (`details.groupwareUpdate = failed`, FR-034) and does not undo the change.
  - **Resources** (create, change, delete): `refresh([address])` the same way.
  - **External sender addresses changed**: `refresh([mailbox])` the same way.
  - **Alias domains**: `rebuild()` after the commit, as legacy (a full rebuild for alias domain
    operations) and as 002 does for domains; once per bulk request (002 D20). For a creation the
    outcome is a step of the creation report (R10); for a change or deletion a failure is logged,
    as 002 does for domain edits.
  - memcached is flushed after the commit (002's flusher), so SOGo cannot re-cache uncommitted
    rows; a failed flush is logged, as legacy ignores it.
  - **One deviation**: legacy falls back to a full rebuild when one destination of an alias is not
    an active local mailbox (an external address); `refresh()` instead touches only the listed
    rows. Only those rows' inputs changed, so the resulting rows are identical; a test compares
    them with `rebuild()` (tasks.md, Polish). The `c_password` placeholder follows 002 (a fresh
    random hash, 002's parity note).
- **Rationale**: Legacy refreshes incrementally for aliases and resources and rebuilds fully for
  alias domains; reusing 002's and 003's service keeps one implementation and lets bulk actions
  rebuild once.
- **Alternatives considered**: A targeted refresh for alias domains was considered and dropped in
  favour of 002's `rebuild()`: it would be a second definition of "affected mailboxes" for a rare
  operation whose bulk form already rebuilds once.

## R10. Alias domain creation sequence

- **Decision**: `App\Services\AliasDomains\AliasDomainCreator` follows feature 002's domain
  creation flow (002 D8) step by step:
  1. validate (R5), with the `aliasDomains` and `ratelimit` flags checked before anything is stored;
  2. `SharedStore::ping()` (002's `App\Clients\SharedStore\SharedStore`); on failure answer
     `503 temporary-failure`;
  3. one database transaction:
     - lock the target domain row (limit convention) and the `alias_domain` and `domain` keys of the
       name (R6); either row existing → `409 alias-domain-already-exists` with `existingKind`;
     - remove the external sender addresses in the new alias domain:
       `DELETE FROM sender_acl WHERE external = 1 AND send_as LIKE :pattern` with `%@<name>`, which
       matches `user@<name>` and `@<name>` (legacy meant this but used an undefined variable);
     - `INSERT INTO alias_domain (alias_domain, target_domain, active)`; a duplicate-key error →
       `409 alias-domain-already-exists`;
     - `HSET DOMAIN_MAP <name> 1` through `SharedStore`; a failure rolls back → `503`;
     - the audit record (`aliasDomainCreation`) with `creationReport: "pending"`;
     - commit; if the commit fails after `HSET`, the entry is removed again (`HDEL`);
  4. the follow-up steps, each caught on its own and never undoing the creation (FR-042):
     - rate limit: 002's `App\Services\RateLimits\RateLimitStore` (`HSET RL_VALUE <name> "<value> / 1<s|m|h|d>"`);
     - DKIM key: 002's `App\Services\Dkim\DkimKeyGenerator` — `alreadyExisted` when `DKIM_SELECTORS`
       or `DKIM_PUB_KEYS` has the name, `notRequested` for an empty selector, otherwise generated
       with the request's selector or `config('restv2.dkim.default_selector')` and the request's
       size or 2,048 bits;
     - DKIM DNS publication: 002's `App\Services\Dkim\DkimDnsPublisher` (`published`, `noZone`,
       `failed`, `notConfigured`, `skipped`);
     - groupware: `SogoDirectory::rebuild()` (`done`, `failed`, `groupwareDisabled`);
  5. complete the audit record with 002's `AuditLogger::completeFollowUps(eventId, 'creationReport', report)`
     and answer `201` with `Location`, `ETag` and the report.
- **Rationale**: FR-042 makes only the row and the map entry atomic; the rest follows 002's
  justified flow (002 plan, Complexity Tracking: the audit record is completed once from
  `"pending"`), so a domain and an alias domain are created the same way and with the same report
  values.
- **Alternatives considered**: Running the follow-ups before the commit was rejected: the DNS
  publication can take 15 s and would hold row locks, and 002 already settled the pattern.

## R11. Alias domain deletion and the shared cleanup

- **Decision**: Feature 002 built `App\Services\AliasDomains\AliasDomainCleanup` for this
  feature's FR-045 (002 D19): `deleteRows(string $aliasDomain)` removes the alias domain's aliases
  and their `sender_acl` rows, the `sender_acl` rows for `@<alias domain>`, the temporary aliases
  (`spamalias.address LIKE '%@<a>'`), the domain-level BCC maps (`local_dest IN ('<a>', '@<a>')`),
  the spam lists (`filterconf.object = <a>`) and the `alias_domain` row; `redisFields()` returns
  the `DOMAIN_MAP` and `RL_VALUE` fields for the caller's `MULTI`; the DKIM key is kept. This
  feature reuses it unchanged. `App\Services\AliasDomains\AliasDomainDeleter`:
  1. `SharedStore::ping()` (`503` when down);
  2. transaction: `SELECT … FROM alias_domain WHERE alias_domain = ? FOR UPDATE`; `If-Match`;
     read the aliases that will go (`SELECT id, address, goto FROM alias WHERE domain = ?`) and the
     counts for the audit record; `AliasDomainCleanup::deleteRows($name)`; then, only here (a
     domain deletion removes the whole footer), remove the name from the target domain's footer
     exclusions (`domain_wide_footer.alias_domain_exclude`, `JSON_REMOVE` of the matching element),
     FR-045;
  3. `SharedStore` `MULTI` with `HDEL` of `redisFields($name)`, remembering the old values; a
     failure rolls back (`503 temporary-failure`, no partial change, FR-062); a failed commit
     restores them (002 D17);
  4. the audit record (`aliasDomainDeletion`, `details.removed`), commit;
  5. after the commit, `SogoDirectory::rebuild()` (once per bulk request) and the memcached flush.

  External sender addresses (`external = 1`) that name the alias domain are kept: they can exist
  only from a legacy creation and become valid external entries again once the name is not local
  (SC-008 was aligned).
- **Rationale**: One implementation for both features (`docs/conventions.md`, 002 FR-037, this
  feature's FR-045). The footer step belongs to the standalone deletion only, so it lives in this
  feature's deleter and does not change 002's cleanup.

## R12. Alias domain change

- **Decision**: `targetDomain` and `active` only (FR-044), without a permission flag. A new target
  must exist in `domain`, not in `alias_domain`, be in the caller's scope (`notFound` otherwise)
  and not be a relay domain (`notEligible`, `kind: relayDomain`; `kind: aliasDomain` for an alias
  domain). The alias limit of the new target is checked with `n` = the alias domain's alias count
  (R6). `DOMAIN_MAP` is not touched: legacy keeps the entry while the alias domain exists, active
  or not, and Postfix's `mysql_virtual_domains_maps` checks `active` itself. The groupware is
  rebuilt after the commit (R9).
- **Rationale**: Legacy edit checks only that the target is a non-alias domain in scope; the spec
  adds the relay and limit checks (deviation).

## R13. Resources

- **Decision**:
  - **Address**: `preg_replace('/[^\da-z]/i', '', $description)` (legacy wraps the description in
    `preg_quote` first, whose backslashes the same expression removes), then `@` and the
    normalised domain. An empty derived local part, or one longer than 64 characters, gives
    `invalidFormat` (`format: resourceDescription`) on `description`.
  - **Description**: trimmed; empty after trimming gives `required`; at most 255 characters
    (`tooLong`), the `mailbox.name` column.
  - **Booking rule**: `alwaysFree` ↔ `0`, `unlimited` ↔ `-1`, `limited` ↔ `bookingLimit`
    (1 to 2,147,483,647, `outOfRange`). Stored values below `-1` (only possible through another
    tool) read as `unlimited`. `bookingLimit` with another rule gives `conflictsWith`
    (`field: bookingRule`).
  - **Storage**: `INSERT INTO mailbox (username, password, name, quota, local_part, domain, active, multiple_bookings, kind) VALUES (:address, 'RESOURCE', :description, 0, :local, :domain, :active, :bookings, :kind)`,
    legacy's statement, so the resource never counts as a mailbox (002 FR-008 filters by `kind`).
  - **Deletion**: legacy's statements on `mailbox`, `sogo_user_profile`, `sogo_cache_folder`,
    `sogo_acl` (escaped `LIKE '%/<address>/%'` or `c_uid`), `sogo_store`, `sogo_quick_contact`,
    `sogo_quick_appointment` (through `sogo_folder_info.c_path2`) and `sogo_folder_info`, in one
    transaction. Unlike legacy, the lookup accepts only rows whose `kind` is a resource kind, so a
    mailbox can never be deleted through this operation.
  - **Concurrency**: creation takes the address lock of R6 (`AddressLock`) around the
    availability check and the insert. Resources do not count towards any limit, so no domain row
    lock is needed.
- **Rationale**: Legacy behaviour, with the stated deviation for booking values below -1.

## R14. Sender settings

- **Decision**:
  - **Implicit addresses**: the own address; `CONCAT(local_part, '@', alias_domain)` for every
    alias domain of the mailbox's domain; and every alias with
    `goto REGEXP '(^|,)<quoted mailbox>($|,)'` (legacy's expression), including catch-all aliases
    (legacy hides them; spec deviation), each with `aliasId`, `senderAllowed` and `catchAll`.
  - **Explicit permissions**: `sender_acl` rows with `external = 0`. `type` is `anyAddress` for
    `*`, `domain` for `@…`, `address` otherwise. `stale` is true when a domain entry's domain is
    neither a domain nor an alias domain, or an address entry is not an `alias.address`.
    `changeable` is true for administrators and, for a domain administrator, when the entry is in
    their scope: a domain entry whose domain they reach (legacy `hasDomainAccess`, which covers
    alias domains of their domains), an address entry whose alias row's domain they reach (legacy
    `hasAliasObjectAccess`). `*` is never changeable for them.
  - **Replacing explicit permissions** (FR-054): normalise every entry (`domain` and `@domain` both
    become `@<ascii domain>`; addresses as in R3); validate each, collecting every failure: `*` from
    a domain administrator gives `notEligible` (`allowedRoles: [admin]`); a domain that is not a
    domain or alias domain in scope, or an address that is not an `alias.address` in scope, gives
    `notFound`; anything else that is neither gives `invalidFormat` (`format: senderPermission`).
    Entries the caller may not change are accepted only when they are already stored. The result is
    (valid request entries) ∪ (stored entries the caller may not change), deduplicated
    case-insensitively; if it contains `*`, it becomes `{*}`. Entries equal to an alias that
    delivers to the mailbox, is not a catch-all and allows sending are dropped (legacy's
    `fixed_sender_aliases_allowed`). Then `DELETE … WHERE external = 0 AND logged_in_as = :mailbox`
    and one `INSERT` per entry.
  - **Replacing external sender addresses** (FR-055): each entry must be a valid address or domain
    name (`invalidFormat`, `format: emailOrDomain`); its domain must not be a domain or alias domain
    of the **server** (`notEligible`, `kind: localDomain`). Domains are stored as `@<ascii>`.
    Deduplicated case-insensitively. A domain administrator without `extendSenderAcl` gets
    `403 attribute-not-permitted` unless the normalised set equals the stored set. Then
    `DELETE … WHERE external = 1 AND logged_in_as = :mailbox`, one `INSERT … external = 1` per entry,
    and a groupware refresh of the mailbox (`ext_acl`).
  - **Version tag**: over the sorted `(external, send_as)` pairs of the mailbox (R18).
- **Rationale**: Legacy's model and statements, with the spec's deviations: refusal instead of
  silent dropping, stale entries visible and removable by administrators, catch-all aliases shown,
  and the local-domain check against all of the server's domains (legacy checked only the domain
  administrator's own domains, which let them add another customer's domain as "external").

## R15. Permission candidates

- **Decision**: The top-level helper list `GET /sender-permission-candidates?mailbox=…`
  (`docs/conventions.md`). The `mailbox` parameter must name a non-resource mailbox in the caller's
  scope; otherwise `422` with `notFound` on `mailbox` (the same for a missing and an out-of-scope
  mailbox, FR-003). One `UNION ALL` query, filtered by `type` and `q`, ordered by a type rank
  (`anyAddress` 0, `domain` 1, `address` 2) and `value`, then paged:
  - `'*'` for administrators, when the mailbox does not hold it;
  - `CONCAT('@', domain)` for every domain in scope, and `CONCAT('@', alias_domain)` for every alias
    domain in scope, that the mailbox does not hold;
  - `alias.address` for every row in scope (own-alias rows included, so mailboxes are offered)
    that is not a catch-all, not the mailbox itself, does not deliver to the mailbox
    (legacy's `goto REGEXP`), is not an alias-domain address of the mailbox, and is not held.
- **Rationale**: Legacy's `sender_acl_handles` selectable lists, plus alias domains (FR-056),
  paged in SQL so that servers with 200,000 aliases stay fast.

## R16. Bulk actions

- **Decision**:
  - Endpoints: `POST /aliases/bulk-actions`, `/alias-domains/bulk-actions` and
    `/resources/bulk-actions` with `{action, targets}` (shared `BulkTarget`, `docs/conventions.md`).
    Alias domains and resources answer with the shared `BulkResultResponse`. Aliases answer with
    `AliasBulkResultResponse`: the shared `BulkResult` in `data`, plus `meta.copies` (alias id →
    per-alias-domain outcomes) for `copyOverAliasDomains`, because the closed `BulkItemResult`
    cannot carry them and the conventions allow side information in `meta`.
  - The whole request is refused only by the role and key-scope steps of R5 (no bulk action of
    this feature needs a flag). Target identifiers are deduplicated after normalisation (alias ids
    as integers, names and addresses lower-cased and UTS #46 converted), keeping the first
    occurrence, so an object named twice is processed once.
  - The shared `App\Support\Bulk\BulkActionRunner` (created here at the latest, as feature 009
    expects) runs each target through the single operation's service method in its own
    transaction, with its own version check (`outdated` when `version` differs from the current tag
    without quotes), checks, follow-up steps and audit record carrying `details.bulkRequestId` (the
    request ID). Outcomes: `done` (with the new `version` unless deleted), `notFound`, `refused`
    (a 4xx problem of the single operation, embedded), `outdated`, `failed` (5xx, including
    `temporary-failure`).
  - Alias domain actions defer `SogoDirectory::rebuild()` until all targets are processed and run
    it once if any target changed (002 D20).
  - `showInGroupware` and `hideInGroupware` set `sogo_visible`; they are allowed when the
    groupware is disabled (the flag is stored, legacy behaviour).
- **Rationale**: `docs/conventions.md` and FR-058/FR-059. SC-010 (100 aliases within 10 s) holds
  because each target is a few indexed statements plus a refresh of its destinations' rows.

## R17. Copy over alias domains

- **Decision**: `App\Services\Aliases\AliasCopier`. For an alias of a primary domain (otherwise
  `409 not-primary-domain-alias`), list the alias domains of its domain in name order and, per
  alias domain, run the alias creation of R4–R9 as the caller with address `<local>@<alias domain>`
  (or `@<alias domain>`), the same `goto`, `active`, `sender_allowed`, `sogo_visible` and
  `internal`, and empty comments. The creation's outcome maps to `created`, or `skipped` with
  `addressInUse` (`address-in-use`), `aliasLimitReached` (`domain-alias-limit-reached`) or
  `destinationNotPermitted` (an external destination refused by the server setting or by the
  caller's missing `aliasExternalGoto`), or `failed`. Each copy is its own transaction and audit
  record (`aliasCreation` with `details.copiedFromAliasId` and, in a bulk action,
  `details.bulkRequestId`). Destinations count as "added" for a copy, so the external rules apply to
  the caller as for any creation. The single operation is
  `POST /aliases/{aliasId}/alias-domain-copies`; the bulk form is the `copyOverAliasDomains` action
  of `POST /aliases/bulk-actions` (R16).
- **Rationale**: FR-036/FR-037. Legacy copied only active/visible and dropped `sender_allowed` and
  `internal`; the spec decided that copies inherit all four flags.

## R18. Version tags

- **Decision**: 001's `App\Support\Http\ETag` over a canonical JSON array of the stored settings,
  in fixed order and with raw database values:
  - alias: `id, address, goto, domain, active, sender_allowed, sogo_visible, internal,
    public_comment, private_comment`;
  - alias domain: `alias_domain, target_domain, active` (not the alias count, relay state or rate
    limit, FR-060);
  - resource: `username, name, kind, multiple_bookings, active, domain`;
  - sender settings: the list of `[external, send_as]` pairs of the mailbox, sorted by `external`
    then lower-cased `send_as` (not the implicit addresses).

  Timestamps are excluded: they are not settings, and `modified` does not change when legacy saves
  identical values anyway. Every list entry and bulk result carries the same value.
- **Rationale**: `docs/conventions.md` ("strong ETag over the stored settings") and 001 R19/R21.

## R19. Lists, search and performance

- **Decision**:
  - All lists use 001's `ListQuery` (`limit` 25/1,000, `offset`, `sort`, `order`, `q`).
  - `q` is an escaped case-insensitive `LIKE '%q%'` over the documented fields. Readable (Unicode)
    forms are not stored and MariaDB has no IDN function, so when `q` contains a non-ASCII
    character the query also matches the rows whose readable form contains `q`: the service reads
    the identifiers and ASCII values of the scoped rows whose domain contains an `xn--` label
    (`address LIKE '%xn--%'`, usually a small set), converts them with `idn_to_utf8`, keeps those
    whose readable form contains `q` (`mb_stripos`), and adds `OR id IN (…)` to the query. An ASCII
    `q` needs no conversion, because the readable form of an ASCII label is the label itself.
  - Alias filters: `address` → `address IN (…)`; `domain` → `domain IN (…)`; `primaryDomain` →
    `domain IN (:p) OR domain IN (SELECT alias_domain FROM alias_domain WHERE target_domain IN (:p))`;
    `kind` → `address LIKE '@%'` or not; `destinationType` → `goto` equal to a reserved address or
    none of them; `destination` → `FIND_IN_SET(:d, goto) > 0` per value; booleans → column `= 1`
    or `= 0`. Values are normalised like the stored data.
  - Scope: administrators see every row; domain administrators see
    `domain IN (:scope) OR domain IN (SELECT alias_domain FROM alias_domain WHERE target_domain IN (:scope))`
    with 001's scope (active assignments), which also hides orphaned aliases from them.
  - Domain filters outside a domain administrator's scope return an empty list (FR-016).
  - No index is added: upstream tables are never altered (constitution III). The default alias
    page (`ORDER BY id DESC LIMIT 25`) walks the primary key; the count scans `alias` with
    primary-key anti-joins, which stays well under one second for 200,000 rows on MariaDB 10.11
    (SC-005). Rate limits of alias domains are fetched with one `HMGET RL_VALUE` per page.
- **Rationale**: Constitution II and the conventions. A performance test seeds 200,000 aliases
  and asserts the p95 of SC-005 (tasks.md, Polish).

## R20. Audit

- **Decision**:
  - Event types appended to `AuditEventType`: `aliasCreation`, `aliasUpdate`, `aliasDeletion`,
    `aliasDomainCreation`, `aliasDomainUpdate`, `aliasDomainDeletion`, `resourceCreation`,
    `resourceUpdate`, `resourceDeletion`, `mailboxSenderSettingsUpdate`. Bulk activations and
    groupware visibility changes are updates; copies are creations.
  - Target types appended to `AuditTarget`: `alias` (id = alias id, name = address), `aliasDomain`
    (id = ASCII name, name = Unicode name), `resource` (id = address, name = description) and
    `mailboxSenderSettings` (id and name = mailbox address). History is read with the generic
    `targetType`/`targetId` filters of `listAuditEvents` (FR-064); this feature adds no filter
    parameter (`docs/conventions.md`).
  - `details` (whitelisted per event): `changes` (`{field: {old, new}}` in contract names, for
    successful changes), `bulkRequestId`, `copiedFromAliasId`, `creationReport` (alias domains,
    completed after the commit with 002's `AuditLogger::completeFollowUps()`, R10), `removed`
    (deletions: aliases with ids and addresses, sender permission, temporary alias, BCC map, spam
    list and footer exclusion counts), `groupwareUpdate` (`done`, `failed`, `groupwareDisabled`),
    and for refusals `problemType` and `errors`, following features 002 and 008.
  - **Refusals** of authenticated callers are audited through the shared refusal mechanism
    (`docs/conventions.md`, "Refusal auditing"): 008's `App\Services\Audit\RefusalAuditor`, or the
    equivalent the first implemented feature created. Every state-changing route of this feature
    declares `->defaults('restv2.audit', ['event' => …, 'target' => …])`, and this feature registers
    target resolvers for `alias`, `aliasDomain`, `resource` and `mailboxSenderSettings`. A resolver
    looks the object up regardless of the caller's scope (the audit log is for administrators
    only), or records the identifier as sent when it does not exist. This covers read-only keys,
    restricted sessions, mailbox users, missing flags and out-of-scope objects, with the shared
    failure reasons: `permissionDenied` (`forbidden`, `insufficient-key-scope`,
    `pending-action-required`, `permission-flag-required`), `attributeNotPermitted`, `notFound`,
    `versionOutdated`, `validationFailed`, `conflict` and `temporaryFailure`. Not audited: `401`s,
    CSRF failures and reads. Refusals raised inside a service (validation, conflicts) go through
    the same mechanism, each in its own transaction (001 R22). A whole-request bulk refusal gives
    one record with a `null` target and `details.targetIds` (008's rule).
  - Each change's record is written inside the change's transaction (001 R22).
- **Rationale**: FR-063 and US8 ("every request that changes state, or tries to"). No value is
  appended to `AuditFailureReason`: the shared values cover every refusal of this feature.

## R21. Server settings and capabilities

- **Decision**:
  - `ALIAS_DISABLE_EXTERNAL_DOMAINS` is added to 001's `MailcowConfig` export
    (`resources/bin/export-mailcow-vars.php`) and getter `aliasDisableExternalDomains()`, with 001
    R14's precedence: `RESTV2_ALIAS_DISABLE_EXTERNAL_DOMAINS`, then the mounted `vars.inc.php` /
    `vars.local.inc.php`, then the upstream default `false`.
  - `SKIP_SOGO` (mailcow.conf, already read by 001) gives `groupwareEnabled`.
  - The bulk limit (100) and the page sizes (25, at most 1,000) are API-wide constants of
    `docs/conventions.md` and the contract, as feature 002 decided (D20, D23); the spec's
    assumption was aligned.
  - The DKIM defaults and the publication switch are feature 002's settings
    (`RESTV2_DEFAULT_DKIM_SELECTOR`, default `dkim`; 2,048 bits; `RESTV2_DKIM_DNS_PUBLICATION`
    plus the ISPConfig settings, 002 D24).
  - `GET /capabilities/aliases` (`getAliasesCapabilities`) hides `dkimDefaults` from domain
    administrators without `aliasDomains` and `dkimDnsPublicationEnabled` from all domain
    administrators (FR-005, FR-068). Read-only keys may use it.
- **Rationale**: `docs/conventions.md` ("Legacy configuration the API cannot read", "Capabilities")
  and 001 R14.

## R22. Shared store failures

- **Decision**: Alias domain creation and deletion need Redis for `DOMAIN_MAP` and `RL_VALUE`. They
  call 002's `SharedStore::ping()` first and write inside their transaction with compensation
  (R10, R11). A `SharedStoreUnavailable` rolls back and answers `503 temporary-failure` with
  `Retry-After: 10` (002 D3) and feature 003's extension `dependency: sharedStore`, leaving no
  partial change (FR-062). Reads of alias domains need `RL_VALUE`; when Redis is unavailable they
  answer the same `503` rather than show a wrong rate limit. Alias, resource and sender settings
  operations do not touch Redis.
- **Rationale**: FR-062 and the edge case "Shared store unavailable". Feature 002 defined the
  `temporary-failure` problem type and the `SharedStore` client for the same situation.

## R23. Problem types and validation rule codes

- **Decision**:
  - New problem types of this feature: `domain-alias-limit-reached` (409; `domain`, `count`,
    `limit`, `requested`; feature 003 reuses it), `alias-domain-already-exists` (409; `name`,
    `existingKind`) and `not-primary-domain-alias` (409).
  - Permission refusals use only the shared types of `docs/conventions.md`: `forbidden` (mailbox
    users), `permission-flag-required` with `requiredFlags` (alias domain creation without `aliasDomains`)
    and `attribute-not-permitted` with `attributes` and feature 003's `requiredFlags` map (rate
    limit without `ratelimit`, external sender addresses without `extendSenderAcl`, added external
    destinations without `aliasExternalGoto`). Out of scope stays `404`.
  - Other shared types reused: `temporary-failure` (feature 002, with 003's `dependency`) and
    `address-in-use` (feature 003; `address`, `holder`).
  - `412` stays `about:blank` (001 convention); in bulk results the target is `outdated`.
  - Validation rule codes: the existing `required`, `invalidType`, `tooShort`, `tooLong` (with
    `unit: bytes` for comments, `unit: characters` for the destination list), `tooMany`,
    `outOfRange`, `notInList`, `invalidFormat` (formats `email`, `domainName`, `dkimSelector` of
    features 002 and 003, plus `aliasAddress`, `emailOrDomain`, `senderPermission` and
    `resourceDescription`), `notFound`, `notEligible` (`kind`: `resource`, `externalDestination`,
    `relayDomain`, `aliasDomain`, `localDomain`; or `allowedRoles`), `conflictsWith` (`field`,
    `value`; feature 003), `immutable` and `reserved` (the server's host name). No new rule code;
    the new format names and kinds are described in `ValidationRuleCode`.
- **Rationale**: FR-065 names the error types; reusing the shared types keeps one meaning per type
  URI, as the conventions require.

## R24. Dependencies on features 001 to 003

Features are implemented in order (001, 002, 003, then 004). This feature reuses their code
unchanged, as their designs name it, and creates only its own pieces. Phase 2 of `tasks.md` pins
each dependency with a test.

| Capability | From | Used for |
|---|---|---|
| Pipeline, roles, scope, flags (`Principal`, `IdentityService`, `Scope`, `PermissionFlag`, `RequireAuth`, `EnforceKeyScope`, CSRF) | 001 | Every operation |
| `ListQuery`, `ETag`, `ProblemType`, `ProblemException`, `ApiFormRequest` with rule codes | 001 | Lists, tags, errors |
| `AuditLogger` (+ `completeFollowUps()` and the `targetType`/`targetId` filters, 002), `App\Audit\AuditEventType` | 001, 002 | Audit (FR-063, FR-064) |
| `RefusalAuditor` (route default `restv2.audit`, target resolvers) | 008's design; created by the first implemented feature, here if 002 and 003 did not | Refusal auditing |
| `MailcowConfig` | 001 | `ALIAS_DISABLE_EXTERNAL_DOMAINS` (added here) |
| `MailcowFactory` / `MailcowRows`, fakes `FakeMemcachedFlusher`, `FakeIspconfigDnsClient`, `FakeDockerApiClient` | 001, 002, 003 | Test rows and fakes (this feature adds rows it alone needs) |
| `App\Support\Domain\DomainName`, `App\Services\Domains\DomainScope` | 002 | Normalisation, scope |
| `App\Models\Mailcow\{Domain, AliasDomain, Alias}` | 001, 002 | Models (this feature adds scopes and accessors) |
| `App\Services\Domains\DomainUsageCalculator` (aliases in use) | 002 | Alias limit (R6) |
| `App\Clients\SharedStore\{SharedStore, SharedStoreUnavailable}` | 002 | `DOMAIN_MAP`, `RL_VALUE` (R10, R11, R22) |
| `App\Services\RateLimits\RateLimitStore` | 002 | Initial rate limit, representation |
| `App\Services\Dkim\{DkimKeyGenerator, DkimDnsPublisher, DkimDnsOutcome}`, `config('restv2.dkim.*')` | 002 | DKIM at alias domain creation |
| `App\Services\Groupware\SogoDirectory` (`rebuild()` 002, `refresh(array)` 003), `App\Clients\Memcached\MemcachedFlusher` | 002, 003 | Groupware (R9) |
| `App\Services\AliasDomains\AliasDomainCleanup` (`deleteRows()`, `redisFields()`) | 002 | Alias domain deletion (R11) |
| `App\Services\Mailboxes\{AddressAvailability, AddressLock}`, `App\Models\Mailcow\{SpamAlias, SenderAcl}`, `App\Services\Mailboxes\MailboxScope` | 003 | Address in use and its lock (R6, R7), sender settings |
| `attribute-not-permitted`, `temporary-failure` (002), `address-in-use` (003), `permission-flag-required` (shared) | 002, 003, conventions | Problems (R23) |

Created here: `App\Services\Aliases\*`, `App\Services\AliasDomains\{AliasDomainCreator, AliasDomainUpdater, AliasDomainDeleter, AliasDomainQuery, AliasDomainCreationReport}`,
`App\Services\Resources\*`, `App\Services\SenderSettings\*`, `App\Support\Bulk\BulkActionRunner`
(unless an earlier feature created it; feature 009 expects it from 004 at the latest),
`App\Support\Mailcow\UnicodeSearch`, `App\Models\Mailcow\Resource`, the controllers, Form
Requests, resources and policies, and the `restv2.audit` target resolvers.

## R25. Dependencies (constitution VIII)

- **Decision**: No new Composer package and no new PHP extension. Address validation and IDN
  conversion use PHP's `filter` and `intl` extensions; RSA keys, Redis, memcached and ISPConfig go
  through feature 002's classes; named locks use MariaDB `GET_LOCK()` through feature 003's
  `AddressLock`.
- **Rationale**: Every need is met by the runtime image of 001 and the classes of 002 and 003.

## R26. Plan-level parity notes

These behaviours are not spelled out in the spec's parity table and were added to it (constitution
IV requires every deviation to be stated in the spec):

1. Alias domain deletion also removes the alias domain's name from its target domain's footer
   exclusions (FR-045, SC-008).
2. An alias change whose destinations include an address that is not an active local mailbox
   refreshes the affected mailboxes' groupware rows instead of legacy's full rebuild; the resulting
   rows are identical (R9).
3. The resource deletion accepts only resource rows; legacy's `delete/resource` deletes any
   `mailbox` row by name. A resource description is trimmed, and an empty description after
   trimming is refused on change too (legacy accepted whitespace on change).
4. Refusals are audited with their reason (R20); legacy logged successes and failures alike in
   `logs`.
