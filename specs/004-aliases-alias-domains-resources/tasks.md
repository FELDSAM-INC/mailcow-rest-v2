---
description: "Task list for feature 004: aliases, alias domains, resources and sender permissions"
---

# Tasks: Aliases, Alias Domains, Resources and Sender Permissions

**Input**: Design documents from `specs/004-aliases-alias-domains-resources/`: [plan.md](./plan.md),
[spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md),
[quickstart.md](./quickstart.md). The contract is `api/` (modules `aliases`, `alias-domains`,
`resources`, `sender-settings`; already authored and lint-clean). Problem types are in
`docs/problems.md`; cross-feature rules in `docs/conventions.md`.

**Prerequisites**: Features 001, 002 and 003 are implemented and their suites are green. This
feature calls their classes unchanged ([research R24](./research.md#r24-dependencies-on-features-001-to-003)):
002's `DomainName`, `DomainScope`, `DomainUsageCalculator`, `AliasDomain` and `Alias` models,
`SharedStore`, `RateLimitStore`, `DkimKeyGenerator`, `DkimDnsPublisher`, `SogoDirectory`,
`MemcachedFlusher`, `AliasDomainCleanup`, `AuditLogger::completeFollowUps()` and fakes; 003's
`AddressAvailability`, `AddressLock`, `MailboxScope`, `SogoDirectory::refresh()`, `SenderAcl` and
`SpamAlias`; and the shared `RefusalAuditor` (008's mechanism, or the equivalent the first
implemented feature created; if none exists yet, T014 creates it to 008's design). Phase 2 pins
them with a test.

**Tests**: Mandatory (constitution VII). In every phase the test tasks come first and must be
observed **failing** before the implementation tasks of that phase start. Every feature test runs
on MariaDB with the upstream `2026-09a` schema fixture and on a real Redis (001 R3), uses
`DatabaseTransactions`, seeds rows through `MailcowFactory`, and is validated against the contract
by `tests/TestCase.php`. Only outbound services are faked (002's `FakeMemcachedFlusher`,
`FakeIspconfigDnsClient`, `FakeDockerApiClient`), with call assertions. Every write test asserts the
resulting rows, Redis hashes, `_sogo_static_view` rows, memcached flushes and audit records (side
effects), and every operation is tested for each role and key type that can reach it
(object-level authorisation).

**Commands**: `docker compose run --rm app <command>` (the host PHP is too old).

## Format: `- [ ] [ID] [P?] [Story?] Description with file path`

- **[P]**: can run in parallel with the other [P] tasks of the same block (different files, no
  dependency on an unfinished task).
- **[USn]**: the user story of spec.md. Setup, Foundational and Polish tasks have no story label.
- FR, SC and AS refer to spec.md; R to research.md; "DM" to data-model.md.

---

## Phase 1: Setup (shared infrastructure)

**Purpose**: Confirm the baseline. No business logic.

- [X] T001 Run `composer contract:lint` and `composer test` on the current `main` and confirm that
  the suites of features 001–003 are green and that `api/openapi.yaml` lints without error for
  `api/modules/{aliases,alias-domains,resources,sender-settings}` (`composer.json` scripts of 001).
  Add the tags `Aliases`, `Alias domains`, `Resources` and `Sender settings` to
  `tests/contract-scope.php` (002's coverage scope), so the coverage gate includes the 23
  operations of this feature.
- [X] T002 Add `RESTV2_ALIAS_DISABLE_EXTERNAL_DOMAINS` to `config/restv2.php` (nullable boolean;
  `null` means "read the legacy files", R21), to `.env.example`, to
  `deploy/docker-compose.override.yml` (commented, empty) and to the README settings table.
- [X] T003 Verify that `api/modules/audit/audit-events.yaml` has the generic `targetType` and
  `targetId` filters and that `api/components/schemas/AuditTarget.yaml` lists `alias`,
  `aliasDomain`, `resource` and `mailboxSenderSettings` (FR-064). No feature-specific audit filter is
  added (`docs/conventions.md`).

---

## Phase 2: Foundational (blocking prerequisites)

**Purpose**: Test rows, dependency pinning, the setting, the value objects, version tags, the bulk
runner, refusal-audit resolvers and enum cases. **No user story can start before this phase is
complete.**

### Tests for the foundation (write first, observe failing) ⚠️

- [X] T004 [P] Extend `database/factories/MailcowRows.php` and `tests/Support/Mailcow/MailcowFactory.php`
  with the rows this feature needs that features 001–003 do not provide yet (keep their signatures
  where they exist): `alias(address, goto, domain = derived, active = 1, senderAllowed = 1,
  sogoVisible = 1, internal = 0, publicComment = null, privateComment = null)` returning the `id`;
  `aliasDomain(name, target, active = 1)`; `resource(address, description, kind = 'location',
  multipleBookings = -1, active = 1)` (password `RESOURCE`, quota 0, legacy's column list);
  `senderAcl(loggedInAs, sendAs, external = 0)`; `spamAlias(address, goto)`; `bccMap(...)`;
  `filterconf(object, option, value)`; `domainFooter(domain, aliasDomainExclude = [])`;
  `sogoFolder(cUid, path2)` with matching `sogo_*` rows for resource deletion tests; and
  `redisHash(key, field, value)` on the `mailcow` connection. A mailbox created with the existing
  helper has its own-alias row (`address = goto = username`), as legacy writes it.
- [X] T005 [P] Write `tests/Unit/Services/MailcowConfigAliasSettingTest.php` (R21):
  `aliasDisableExternalDomains()` is `false` by default; `true` when
  `tests/fixtures/mailcow-inc/vars.local.inc.php` sets `$ALIAS_DISABLE_EXTERNAL_DOMAINS = true`;
  `RESTV2_ALIAS_DISABLE_EXTERNAL_DOMAINS=false` beats the file; a change of the file is picked up by
  the next call (001 R14's fingerprint cache).
- [X] T006 [P] Write `tests/Feature/Dependencies/Feature004DependenciesTest.php`, pinning the reused
  classes with the behaviour this feature relies on (R24):
  - `App\Support\Domain\DomainName::normalize(' Bücher.Example ')` = `xn--bcher-kva.example`;
    `isValid()` like legacy `is_valid_domain_name` (single labels allowed, labels ≤ 63, ≤ 253);
  - `DomainUsageCalculator`'s alias figure of `P` counts aliases of `P` and of its alias domains and
    not own-alias rows;
  - 003's `AddressAvailability` answers `mailbox` (also for a mailbox's own-alias row), `resource`,
    `alias` and `temporaryAlias`, case-insensitively; 003's `AddressLock` takes
    `restv2_address_<sha1>` with `GET_LOCK(…, 5)` and releases it;
  - 002's `SharedStore` writes and deletes `DOMAIN_MAP` and `RL_VALUE` fields, runs a `MULTI` with
    compensation and throws `SharedStoreUnavailable` when Redis is unreachable; `RateLimitStore`
    writes `"500 / 1h"`;
  - `DkimKeyGenerator` writes `DKIM_PUB_KEYS`, `DKIM_SELECTORS` and `DKIM_PRIV_KEYS[<selector>.<name>]`;
    `DkimDnsPublisher` reports `notConfigured` when disabled and `skipped` without a new key;
  - `SogoDirectory::refresh([…])` and `rebuild()` exist, write nothing with `SKIP_SOGO=y`, and the
    memcached flush goes through `MemcachedFlusher`;
  - `AliasDomainCleanup::deleteRows()` and `redisFields()` exist with 002 D19's effect;
  - `AuditLogger::completeFollowUps()` completes a `"pending"` key once;
  - a route declaring `->defaults('restv2.audit', …)` gets its `4xx` refusals of authenticated
    callers recorded by the shared `RefusalAuditor`, except `401` and `csrf-token-mismatch`.
- [X] T007 [P] Write `tests/Unit/Services/AliasAddressTest.php` and `AliasDestinationTest.php`
  (R3, R4, FR-008, FR-009, FR-012, FR-025, FR-026): `AliasAddress` trims, keeps the local part's
  case, converts the domain with `DomainName`, accepts `@domain` as catch-all (also single-label),
  refuses quoted, non-ASCII and literal local parts and `a..b`; `AliasDestination::fromGoto()` maps
  `null@localhost` → `discard`, `spam@localhost` → `learnSpam`, `ham@localhost` → `learnHam`, else
  `addresses` with entries exactly as stored; `toGoto()` round-trips; letter-case duplicates are
  merged keeping the first spelling and order; `addedComparedTo()` returns only destinations not in
  the stored list, case-insensitively; the joined limit is 65,535 characters.
- [X] T008 [P] Write `tests/Unit/Services/AliasVersionTagTest.php` (R18, FR-060): the tags of alias,
  alias domain, resource and sender settings change when any listed stored column changes and
  stay equal otherwise; an alias domain's tag does not change with its alias count, the target's
  `backupmx` or `RL_VALUE`; the sender settings tag does not depend on row order or `id` and does
  not change when an alias delivering to the mailbox changes; no input is a secret.
- [X] T009 [P] Write `tests/Unit/Services/ResourceAddressDeriverTest.php` (R13, FR-048): "Meeting
  Room 1" → `MeetingRoom1`; "Meeting-Room 1" → `MeetingRoom1`; "Raum Süd" → `RaumSd`; "!!!" →
  refused (`invalidFormat`, `format: resourceDescription`); 65 letters → refused; 64 → accepted; the
  result equals legacy's `preg_replace('/[^\da-z]/i', '', preg_quote($d, '/'))` for 50 generated
  descriptions.
- [X] T010 [P] Write `tests/Unit/Services/SenderEntryTest.php` (R14): `example.org`,
  `@Example.ORG` and `@bücher.example` normalise to `@example.org` / `@xn--bcher-kva.example`;
  addresses keep the local part's case; `*` is `anyAddress`; external entries accept an address or a
  domain and refuse anything else (`format: emailOrDomain`); explicit entries refuse anything that
  is not an address, a domain or `*` (`format: senderPermission`).
- [X] T011 [P] Write `tests/Unit/Support/UnicodeSearchTest.php` (R19): for a non-ASCII `q`, it
  returns the ids of scoped rows whose `idn_to_utf8` form contains `q` case-insensitively (a partial
  label such as `ücher` included); for an ASCII `q` it adds nothing.
- [X] T012 [P] Write `tests/Unit/Support/BulkActionRunnerTest.php` (R16), unless an earlier feature
  already tests `App\Support\Bulk\BulkActionRunner`: `targets` are deduplicated after normalisation
  keeping the first; each target runs in its own transaction; a `404` maps to `notFound`, other
  `4xx` to `refused` with the problem, `412` or a version mismatch to `outdated`, `5xx` and
  unexpected throwables to `failed`; a failure does not stop later targets; results keep request
  order; the summary counts match; `bulkRequestId` equals the request ID; a deferred action (the
  groupware rebuild) runs once at the end when any target changed.

### Implementation of the foundation

- [X] T013 Add `ALIAS_DISABLE_EXTERNAL_DOMAINS` to `resources/bin/export-mailcow-vars.php` and the
  getter `aliasDisableExternalDomains(): bool` to `app/Services/Config/MailcowConfig.php`, with
  001 R14's precedence and the setting of T002; add `$ALIAS_DISABLE_EXTERNAL_DOMAINS = false` to
  `tests/fixtures/mailcow-inc/vars.inc.php`. Makes T005 pass.
- [X] T014 Make T006 pass without changing the reused classes. Where an earlier feature delivered a
  capability under another name, note it in research R24 and use it. If no implemented feature has
  created the shared refusal mechanism yet, create `app/Services/Audit/RefusalAuditor.php` and its
  hook in `bootstrap/app.php` exactly as feature 008's research specifies (route default
  `restv2.audit`, authenticated principal, not `csrf-token-mismatch`, resolver registry), so that 008
  reuses it. Register this feature's refusal
  target resolvers `app/Services/Audit/RefusalTargets/{AliasTargetResolver,AliasDomainTargetResolver,ResourceTargetResolver,MailboxSenderSettingsTargetResolver}.php`
  (look the object up regardless of scope; record the identifier as sent when it does not exist;
  R20).
- [X] T015 [P] Create `app/Services/Aliases/AliasAddress.php` and `AliasDestination.php`. Makes T007
  pass.
- [X] T016 [P] Create `app/Services/Aliases/AliasVersion.php`, `app/Services/AliasDomains/AliasDomainVersion.php`,
  `app/Services/Resources/ResourceVersion.php` and `SenderSettingsReader::etagFor()` (in
  `app/Services/SenderSettings/SenderSettingsReader.php`), each with 001's `ETag` over the canonical
  JSON arrays of R18. Makes T008 pass.
- [X] T017 [P] Create `app/Services/Resources/ResourceAddressDeriver.php`. Makes T009 pass.
- [X] T018 [P] Create `app/Services/SenderSettings/SenderEntry.php`. Makes T010 pass.
- [X] T019 [P] Create `app/Support/Mailcow/UnicodeSearch.php`. Makes T011 pass.
- [X] T020 [P] Create or reuse `app/Support/Bulk/BulkActionRunner.php` (with deferred end-of-request
  actions). Makes T012 pass.
- [X] T021 [P] Add to 002's `app/Models/Mailcow/Alias.php` the local scope `notOwnAlias`
  (`NOT EXISTS (SELECT 1 FROM mailbox m WHERE m.username = alias.address)`) and the accessors
  `primaryDomain`, `inAliasDomain` and `orphaned` (DM), without changing its existing behaviour;
  create `app/Models/Mailcow/Resource.php` (table `mailbox`, string key `username`, global scope
  `kind REGEXP 'location|thing|group'`, booking rule accessors, `MailcowDateTime` casts).
- [X] T022 Add the enum cases of this feature: `domainAliasLimitReached`, `aliasDomainAlreadyExists`,
  `notPrimaryDomainAlias` (409, with the titles of `docs/problems.md`) to
  `app/Support/Http/ProblemType.php` (plus `permissionFlagRequired` if no earlier feature added it);
  the ten event types of R20 to `app/Audit/AuditEventType.php`; and the per-event `details`
  whitelists of R20 (`changes`, `bulkRequestId`, `copiedFromAliasId`, `creationReport`, `removed`,
  `groupwareUpdate`, `problemType`, `errors`, `targetIds`) to `app/Services/Audit/AuditLogger.php`.
  001's `tests/Unit/Support/ProblemTypeConsistencyTest.php` must stay green.

**Checkpoint**: Foundation tests green. User stories can start (US1–US3 first, as P1).

---

## Phase 3: User Story 1 — Find and inspect aliases (P1) 🎯 MVP

**Goal**: Administrators and domain administrators list, filter, search, sort and inspect aliases
in their scope (FR-001–FR-005, FR-011–FR-019).

**Independent Test**: Seed aliases of every kind in several domains and alias domains plus two
domain administrators; list and inspect with every filter, search and sort as each role and compare
with the expected values.

### Tests for User Story 1 (write first, observe failing) ⚠️

- [X] T023 [P] [US1] Write `tests/Feature/Aliases/ListAliasesTest.php` (`listAliases`):
  - AS1: 60 aliases and 40 mailboxes → 25 newest by `id desc`, `meta.total = 60`, no own-alias row;
  - AS2: `info@example.net` in alias domain `example.net` of `example.org` shows `domain`
    `example.net`, `inAliasDomain: true`, `primaryDomain` `example.org`; included by
    `primaryDomain=example.org`, excluded by `domain=example.org`;
  - AS3: `q=INVOICE` matches the address, a destination, the public and the private comment;
    `q=bücher`, the partial label `q=ücher` and `q=xn--bcher` match an alias in
    `xn--bcher-kva.example`;
  - AS5: a legacy-stored `spam@localhost` alias has `destinationType: learnSpam`, `destinations: []`;
  - AS6: `destination=anna@example.org` returns exactly the three aliases delivering to her, also
    with `ANNA@Example.org`;
  - every other filter (`address` and `kind` repeated, `destinationType` repeated, `active`,
    `senderAllowed`, `groupwareVisible`, `internal`) and every `sort` value both ways, ties broken by
    `id`; `limit` 1 and 1000, `offset` beyond the end;
  - an orphaned alias is listed for an administrator with `orphaned: true`;
  - every entry's `etag` equals the `ETag` of `getAlias` for it;
  - invalid `sort`, `limit=1001`, a 256-character `q` → `422` with 001's rule codes.
- [X] T024 [P] [US1] Write `tests/Feature/Aliases/GetAliasTest.php` (`getAlias`): `200` with the full
  representation (UTC times, `modifiedAt: null` when never modified, comments `""` for `NULL`,
  `active: true` for stored `2`) and `ETag`; a mailbox's own-alias row id → `404 about:blank`; an
  unknown id → `404`.
- [X] T025 [P] [US1] Write `tests/Feature/Aliases/AliasReadAuthorizationTest.php` (FR-001–FR-005,
  SC-004) for `listAliases` and `getAlias` as admin session, admin read-write key, admin read-only
  key, domain-admin session, domain-admin read-only key and mailbox user: AS4 a domain administrator
  of two of ten domains lists only aliases of those domains and their alias domains (an active
  assignment of an inactive domain included; an inactive assignment excluded); an alias of a third
  domain, or an orphaned alias, answers the same `404` as an unknown id; `domain=<foreign>` gives an
  empty list; AS7 a mailbox user gets `403 forbidden`; read-only keys may read.

### Implementation for User Story 1

- [X] T026 [P] [US1] Create `app/Services/Aliases/AliasQuery.php`: base query with `notOwnAlias`, the
  scope of R19 through 002's `DomainScope` (alias domains of the caller's domains included), the
  derived columns by left joins on `alias_domain` and `domain`, the filters, the search with
  `UnicodeSearch`, and the sort map of `api/components/parameters/AliasSort.yaml`, through 001's
  `ListQuery`.
- [X] T027 [P] [US1] Create `app/Http/Requests/Api/V2/ListAliasesRequest.php` (filters normalised like
  stored data; `address`, `domain`, `primaryDomain` up to 100 values, `destination` up to 20, `kind`
  and `destinationType` from their enums, booleans) and `app/Http/Resources/Api/V2/AliasResource.php`
  (contract `Alias`, readable forms with `DomainName::toUnicode()`, `etag`).
- [X] T028 [US1] Create `app/Policies/AliasPolicy.php` (administrators and domain administrators;
  mailbox users → `forbidden`; objects outside the scope resolve to `404` in the lookup) and
  `app/Http/Controllers/Api/V2/AliasController.php` with `index` and `show` (`ETag` header).
- [X] T029 [US1] Register in `routes/api.php` `GET /aliases` and `GET /aliases/{aliasId}`
  (`restv2.auth:authenticated`, `restv2.pending:enforce`), `{aliasId}` constrained to
  `[1-9][0-9]{0,9}`, after the literal `/aliases/bulk-actions` route (registered in US6). Makes
  T023–T025 pass.

**Checkpoint**: US1 is usable on its own (read-only alias screens).

---

## Phase 4: User Story 2 — Create an alias (P1)

**Goal**: Create one alias with every legacy side effect, the alias limit, the external-destination
rules and the capabilities read (FR-006–FR-010, FR-020–FR-028, FR-032–FR-034, FR-068).

**Independent Test**: Create aliases with each kind of destination as administrator and as domain
administrators with and without `alias_external_goto`, with the server setting on and off, and at
the alias limit; compare rows, sender permissions and groupware rows with legacy.

### Tests for User Story 2 (write first, observe failing) ⚠️

- [X] T030 [P] [US2] Write `tests/Feature/Aliases/CreateAliasTest.php` (`createAlias`):
  - AS1: `sales@example.org` → `anna`, `bob`: `201`, `Location: /api/v2/aliases/<id>`, `ETag`; the
    `alias` row has legacy's columns (`goto = 'anna@example.org,bob@example.org'`, `sogo_visible = 1`,
    `sender_allowed = 1`, `internal = 0`, `active = 1`, comments `''`); `_sogo_static_view.aliases`
    of both mailboxes contains `sales@example.org`; one memcached flush after commit;
  - AS2: `@example.org` → `kind: catchAll`, in no `aliases` column;
  - AS3: `destinationType: discard` stores `goto = 'null@localhost'`; `learnSpam`/`learnHam` likewise;
  - omitted flags take the legacy form defaults (not `/api/v1`'s 0);
  - `Sales@Bücher.Example` stores `Sales@xn--bcher-kva.example`; destinations normalised the same
    way; letter-case duplicates stored once in first order; a non-existent local destination
    (`typo@example.org`) is accepted (edge case);
  - FR-032: an existing `sender_acl` row `anna` → `sales@example.org` is deleted by a creation with
    `senderAllowed: true` and kept with `senderAllowed: false`;
  - relay and inactive domains are accepted (FR-021); a single-label domain accepts only `@localdomain`;
  - audit: one `aliasCreation` record with target `alias`, the values in `details.changes` and
    `details.groupwareUpdate = done`; with `SKIP_SOGO=y` nothing is written to the view and the
    record says `groupwareDisabled`; a forced view failure keeps the alias and records `failed`
    (FR-034); a forced audit insert failure rolls the alias back and answers `500` (edge case).
- [X] T031 [P] [US2] Write `tests/Feature/Aliases/CreateAliasRefusalTest.php` (FR-009, FR-010,
  FR-021–FR-028):
  - AS4: an address held by a mailbox, a resource, an alias (also `INFO@example.org` against
    `info@example.org`) or a temporary alias → `409 address-in-use` with `holder`; nothing created;
  - AS10: `anna@example.net` with alias domain `example.net` of `example.org` and mailbox
    `anna@example.org` → `409 address-in-use`, `holder: mailbox`;
  - AS5: limit 10 with 10 in use → `409 domain-alias-limit-reached` `{domain, count: 10, limit: 10, requested: 1}`,
    also for an address in an alias domain of that domain; an own-alias row does not count;
  - AS6: three destinations with one invalid → `422`, `errors["destinations.1"]` `invalidFormat`
    `{format: email}`, nothing created; every invalid entry named in one response;
  - AS9: a destination that is a resource → `notEligible` `{kind: resource}`;
  - AS11: a 161-byte private comment (`str_repeat('é', 80) . 'x'`) → `tooLong` `{max: 160, unit: bytes}`;
  - FR-009: `"a b"@example.org`, `müller@example.org`, `a@[192.0.2.1]`, a 65-character local part and
    `a..b@example.org` → `invalidFormat` `{format: aliasAddress}` on `address`;
  - a destination equal to the address → `conflictsWith` `{field: address}`; destinations with a
    special action → `conflictsWith` `{field: destinationType}`; the reserved addresses as
    destinations → `invalidFormat`; a joined list over 65,535 characters → `tooLong`
    `{max: 65535, unit: characters}`;
  - a domain outside a domain administrator's scope and an unknown domain give the same `422`
    `notFound` on `address` (FR-003);
  - every refusal above is audited by the `RefusalAuditor` with its failure reason (`conflict`,
    `validationFailed`).
- [X] T032 [P] [US2] Write `tests/Feature/Aliases/AliasExternalDestinationTest.php` (FR-006, FR-007,
  FR-027): AS7 a domain administrator without `alias_external_goto` adding `someone@gmail.example`
  → `403 attribute-not-permitted` `{attributes: [destinations.0], requiredFlags: {destinations.0: aliasExternalGoto}}`,
  a local destination succeeds, and with the flag it succeeds; AS8 with the server setting on, an
  administrator gets `422` `notEligible {kind: externalDestination}` on `destinations.0`; a
  destination in another customer's domain is not external; one in an inactive domain or inactive
  alias domain is external; the refusal is audited with `failureReason: attributeNotPermitted`;
  FR-002: setting `da_acl.alias_external_goto = 0` between two requests of the same session makes the
  second refused, and setting it back makes the next one succeed.
- [X] T033 [P] [US2] Write `tests/Unit/Services/AliasLimitLockTest.php` (FR-022, R6): with two
  database connections, A takes `AliasLimit::lockPrimaryDomain('example.org')` in a transaction;
  B's lock attempt with `innodb_lock_wait_timeout = 1` times out until A commits; after A inserts the
  last allowed alias and commits, B's check refuses. With 003's `AddressLock` held by A for
  `sales@example.org`, B cannot take it. A duplicate-key error on `alias.address` maps to
  `409 address-in-use`.
- [X] T034 [P] [US2] Write `tests/Feature/Aliases/AliasWriteAuthorizationTest.php` for `createAlias`
  as admin session (with and without CSRF token), admin read-write key, admin read-only key
  (`403 insufficient-key-scope`, audited as `permissionDenied`), domain-admin session and key in and
  out of scope, mailbox user (`403 forbidden`, audited) and a restricted session
  (`403 pending-action-required`, audited); a CSRF failure and a `401` write no audit record.
- [X] T035 [P] [US2] Write `tests/Feature/Aliases/AliasesCapabilitiesTest.php`
  (`getAliasesCapabilities`, FR-068): an administrator sees `groupwareEnabled` (false with
  `SKIP_SOGO=y`), `externalDestinationsDisabled`, `bulkLimit: 100`, `defaultPageSize: 25`,
  `maxPageSize: 1000`, `commentMaxBytes: 160`, `destinationsMaxLength: 65535`,
  `resourceDescriptionMaxLength: 255`, `resourceBookingLimit`, `rateLimitFrames`, `dkimKeySizes`,
  `dkimDefaults {selector: dkim, keySize: 2048}` (`RESTV2_DEFAULT_DKIM_SELECTOR=feldhost` →
  `feldhost`) and `dkimDnsPublicationEnabled`; a domain administrator gets
  `dkimDnsPublicationEnabled: null` and `dkimDefaults: null` without `alias_domains`; read-only keys
  may read; a mailbox user → `403`.

### Implementation for User Story 2

- [X] T036 [P] [US2] Create `app/Services/Aliases/AliasLimit.php` (`lockPrimaryDomain()` with
  `SELECT … FOR UPDATE`; `assertRoom(string $primary, int $adding)` with 002's
  `DomainUsageCalculator`, throwing `domain-alias-limit-reached`) and
  `app/Services/Aliases/AliasAddressAvailability.php` (003's `AddressAvailability` plus the
  alias-domain clause of R7). Makes T033 pass with T037.
- [X] T037 [US2] Create `app/Services/Aliases/AliasSenderAclEffects.php` (the statements of R8) and
  `app/Services/Aliases/AliasCreator.php`: normalise (R3); collect every validation error of DM's
  alias table into one `422` (the server-setting external rule included); check `aliasExternalGoto`
  for added external destinations (`attribute-not-permitted`); take 003's `AddressLock`; in one
  transaction lock the primary domain, check the address and the limit, insert with legacy's column
  list, apply the FR-032 cleanup, `SogoDirectory::refresh()` of the destinations in a savepoint,
  write the audit record, commit; release the lock; flush memcached after the commit.
- [X] T038 [P] [US2] Create `app/Http/Requests/Api/V2/StoreAliasRequest.php` (types and lengths of
  `AliasCreateRequest`, comment byte length, rule codes of DM) and extend `AliasController` with
  `store` (`201`, `Location`, `ETag`).
- [X] T039 [P] [US2] Create `app/Services/Aliases/AliasesCapabilitiesService.php`,
  `app/Http/Resources/Api/V2/AliasesCapabilitiesResource.php` and
  `app/Http/Controllers/Api/V2/AliasesCapabilitiesController.php` (`show`), reading `SKIP_SOGO`,
  `MailcowConfig::aliasDisableExternalDomains()`, `ListQuery`'s sizes, the fixed bulk limit and 002's
  DKIM settings, with the role rules of FR-005.
- [X] T040 [US2] Register `POST /aliases` (`restv2.auth:authenticated`, `restv2.csrf`,
  `restv2.pending:enforce`, `->defaults('restv2.audit', ['event' => 'aliasCreation', 'target' => 'alias'])`)
  and `GET /capabilities/aliases` in `routes/api.php`. Makes T030–T035 pass.

**Checkpoint**: US1 + US2 deliver alias provisioning for billing systems.

---

## Phase 5: User Story 3 — Change or delete an alias (P1)

**Goal**: Merge-patch changes with version checks, legacy side effects and the spec's deviations;
deletion with sender permissions (FR-025–FR-035, FR-060, FR-061).

**Independent Test**: Change each attribute, change the alias in the legacy UI between read and
write, delete aliases with and without explicit sender permissions; compare with legacy.

### Tests for User Story 3 (write first, observe failing) ⚠️

- [X] T041 [P] [US3] Write `tests/Feature/Aliases/UpdateAliasTest.php` (`updateAlias`):
  - AS1: destinations Anna → Bob: `goto` updated, `sales@` removed from Anna's view row and added to
    Bob's (both refreshed), response with the new `ETag`;
  - AS2: read the tag, update the row directly (as the legacy UI does), send the old tag →
    `412 about:blank`, nothing changed, audited as `versionOutdated`; the current tag → `200`; no
    `If-Match` → applied;
  - AS3: an administrator-made alias with an external destination; a domain administrator without
    `alias_external_goto` sends `{"active": false}` → saved, external destination kept; adding a
    second external destination → `403 attribute-not-permitted` naming only the new one; also after
    the server setting is turned on (the kept destination is not re-checked);
  - AS4: an address change to `info@example.com` whose limit is reached → `409 domain-alias-limit-reached`;
    a change within the same primary domain is never limit-checked, also when the limit is exceeded;
  - AS5: a `sender_acl` row `bob` → `info@example.org`; an address change to `contact@example.org`
    moves it, with no duplicate if Bob already held the new address;
  - AS6: `senderAllowed: false` keeps existing explicit permissions; `true` removes those of the
    destination mailboxes (FR-032);
  - a letter-case-only address change succeeds; switching between destinations and a special action
    both ways; `destinationType: addresses` without destinations on an alias with a special action
    → `required`;
  - the whole representation from `getAlias` sent back with one change → `200`; a different `id`,
    `createdAt`, or a `kind` contradicting the resulting address → `422 immutable`; a stale `etag` in
    the body is ignored;
  - a stored comment longer than 160 bytes sent back unchanged is accepted; a changed one is checked;
  - a destination list stored by another tool with an invalid entry: a replacement that keeps it →
    `422` naming it; one without it succeeds (edge case);
  - BCC and recipient maps naming the old address are unchanged after an address change (edge case);
  - an orphaned alias: a change without a new address → `422` `notFound` on `address`; moving it to
    an existing domain succeeds (administrator);
  - audit: `aliasUpdate` with `details.changes` holding old and new values per changed field.
- [X] T042 [P] [US3] Write `tests/Feature/Aliases/DeleteAliasTest.php` (`deleteAlias`): AS7 an alias
  with two explicit permissions → `204` without body; the row and both `sender_acl` rows are gone;
  the destinations' view rows refreshed; memcached flushed; deleting the catch-all `@example.org`
  also deletes the `sender_acl` rows with `send_as = '@example.org'` (legacy parity, R8); other
  aliases naming the deleted address keep it; an orphaned alias is deletable by an administrator;
  `If-Match` mismatch → `412`; unknown, out-of-scope or own-alias id → `404` (out of scope audited
  as `notFound`); audit `aliasDeletion` with `details.removed.senderPermissions = 2`.
- [X] T043 [P] [US3] Extend `tests/Feature/Aliases/AliasWriteAuthorizationTest.php` with
  `updateAlias` and `deleteAlias`: AS8 a read-only key → `403 insufficient-key-scope`; a mailbox user
  → `403`; a domain administrator on a foreign alias → `404`; moving an alias into a foreign domain
  → `422 notFound`; each refusal audited.

### Implementation for User Story 3

- [X] T044 [US3] Create `app/Services/Aliases/AliasUpdater.php` and `AliasDeleter.php`:
  `SELECT … FOR UPDATE`, version check, merge with stored values, read-only member check
  (`immutable`), validation, `aliasExternalGoto` check on added destinations only, `AddressLock` and
  the limit lock when the address or primary domain changes, legacy's `UPDATE`, the `sender_acl`
  carry-over and cleanup of R8, `SogoDirectory::refresh()` of old ∪ new destinations, audit with
  `changes`; deletion with legacy's statements, the refresh and `details.removed`.
- [X] T045 [P] [US3] Create `app/Http/Requests/Api/V2/UpdateAliasRequest.php` (merge-patch members of
  `AliasPatch`; an empty body → `required` on `body`) and extend `AliasController` with `update`
  and `destroy`.
- [X] T046 [US3] Register `PATCH` and `DELETE /aliases/{aliasId}` (`restv2.csrf`, `restv2.audit`
  defaults with `aliasUpdate` / `aliasDeletion`) in `routes/api.php`. Makes T041–T043 pass.

**Checkpoint**: All P1 stories done; aliases are fully managed.

---

## Phase 6: User Story 4 — Manage alias domains (P2)

**Goal**: List, inspect, create (with domain map, rate limit, DKIM key and report), change and
delete alias domains (FR-038–FR-045, FR-062).

**Independent Test**: Create, change and delete alias domains as administrator and as domain
administrators with and without the flags; compare rows, Redis hashes and groupware rows with
legacy plus the stated deviations.

### Tests for User Story 4 (write first, observe failing) ⚠️

- [X] T047 [P] [US4] Write `tests/Feature/AliasDomains/ListAliasDomainsTest.php` and
  `GetAliasDomainTest.php` (`listAliasDomains`, `getAliasDomain`): representation with
  `nameUnicode`, `targetIsRelayDomain` (target made a relay domain after creation), `aliasCount`,
  `rateLimit` from `RL_VALUE` (`500 / 1h` → `{value: 500, frame: hour}`, absent → `null`); default
  sort by name, every sort value, `targetDomain` and `active` filters, `q` on name and target
  (including a partial Unicode label); entry `etag` equals the `ETag`; a domain administrator sees
  only alias domains of their domains and gets `404` for others; the path accepts `EXAMPLE.NET` and
  the Unicode form; with Redis broken → `503 temporary-failure` (`dependency: sharedStore`).
- [X] T048 [P] [US4] Write `tests/Feature/AliasDomains/CreateAliasDomainTest.php` (`createAliasDomain`):
  - AS1: a domain administrator with `alias_domains` creates `example.net` for `example.org` →
    `201`, `Location: /api/v2/alias-domains/example.net`; `alias_domain` row; `DOMAIN_MAP` field;
    DKIM key with selector `dkim` and 2,048 bits in the three `DKIM_*` hashes;
    `creationReport {rateLimit: notRequested, dkimKey: created, dkimDnsPublication: notConfigured, groupwareDirectoryUpdate: done}`;
    after the rebuild the view rows of `example.org`'s mailboxes have `ad_aliases` with
    `@example.net` addresses; the audit record's `creationReport` was completed from `"pending"`;
  - AS2: without `alias_domains` → `403 permission-flag-required` `{requiredFlags: [aliasDomains]}`,
    before validation (an invalid body gets the same answer); nothing stored; audited as
    `permissionDenied`;
  - with `alias_domains` but without `ratelimit`, sending `rateLimit` → `403 attribute-not-permitted`
    `{attributes: [rateLimit], requiredFlags: {rateLimit: ratelimit}}` before anything is stored; with
    `ratelimit` → `RL_VALUE[example.net] = '500 / 1h'`, `applied`;
  - AS3: a name existing as domain or alias domain → `409 alias-domain-already-exists` with
    `existingKind`;
  - AS4: a relay target → `422 notEligible {kind: relayDomain}`; an alias domain as target →
    `notEligible {kind: aliasDomain}`; a foreign or unknown target → `notFound`; the host name →
    `reserved`; the name equal to the target → `conflictsWith`;
  - AS5: the external sender addresses `bob@example.net` and `@example.net` of any mailbox are removed
    in the creation transaction;
  - DKIM: an existing `DKIM_PUB_KEYS[example.net]` → `alreadyExisted`, unchanged; `dkimSelector: ""`
    → `notRequested`; `dkimSelector: "feldhost"`, `dkimKeySize: 4096` honoured; with the publication
    enabled (002's fake client) → `published`; enabled but the key already existed → `skipped`; a
    `failed` publication does not undo the creation;
  - FR-062: with Redis broken → `503 temporary-failure`, no `alias_domain` row, no sender ACL removed;
    a forced commit failure after `HSET` removes the `DOMAIN_MAP` field again and answers `500`.
- [X] T049 [P] [US4] Write `tests/Feature/AliasDomains/UpdateAliasDomainTest.php` (`updateAliasDomain`):
  AS6 target `example.org` → `example.com` updates the row, the groupware is rebuilt after the
  commit, and the alias domain's aliases count towards `example.com`; when `example.com`'s in-use
  count plus the alias count exceeds its limit → `409 domain-alias-limit-reached`
  `{requested: <alias count>}`; zero aliases never refused; a move where an alias's local part
  equals a mailbox of the new target is allowed (edge case); a relay or alias-domain target → `422`;
  AS8 `active: false` updates the row and leaves `DOMAIN_MAP`; no flag needed for a domain
  administrator; the whole representation from `getAliasDomain` sent back with one change → `200`;
  a read-only member (`name`, `aliasCount`, `rateLimit`, `createdAt`) with a different value →
  `immutable`; `etag` in the body ignored; `If-Match` mismatch → `412`; audit `aliasDomainUpdate`.
- [X] T050 [P] [US4] Write `tests/Feature/AliasDomains/DeleteAliasDomainTest.php` (`deleteAliasDomain`):
  AS7 an alias domain with 2 aliases, explicit permissions for `@example.net` and for one alias
  address, an external sender address `x@example.net`, 2 temporary aliases, BCC maps for
  `@example.net`, `example.net` and `anna@example.net`, `filterconf` rows, a footer of `example.org`
  excluding `example.net`, and `DOMAIN_MAP`, `RL_VALUE` and `DKIM_*` entries → `204`; everything of
  FR-045 removed (through 002's `AliasDomainCleanup`) including the footer exclusion; kept: the DKIM
  entries, `x@example.net` (`external = 1`) and the `anna@example.net` BCC map (SC-008); the
  groupware rebuilt after the commit; with Redis broken → `503`, nothing removed; a forced commit
  failure restores `DOMAIN_MAP` and `RL_VALUE`; `If-Match` mismatch → `412`; foreign → `404`
  (audited `notFound`); audit `aliasDomainDeletion` with `details.removed`.
- [X] T051 [P] [US4] Write `tests/Feature/AliasDomains/AliasDomainAuthorizationTest.php` for the five
  operations as every role and key type (read-only key refused on writes, mailbox user `403`,
  domain administrator `404` outside scope), each refusal audited.

### Implementation for User Story 4

- [X] T052 [P] [US4] Create `app/Services/AliasDomains/AliasDomainQuery.php` (scope through
  `DomainScope`, filters, search with `UnicodeSearch`, sort of `AliasDomainSort`, alias counts by a
  grouped subquery, rate limits through `RateLimitStore` with one `HMGET` per page) and
  `app/Http/Resources/Api/V2/AliasDomainResource.php`.
- [X] T053 [US4] Create `app/Services/AliasDomains/AliasDomainCreator.php` and
  `AliasDomainCreationReport.php` (R10: 002's flow with `SharedStore`, `RateLimitStore`,
  `DkimKeyGenerator`, `DkimDnsPublisher`, `SogoDirectory::rebuild()` and
  `AuditLogger::completeFollowUps()`), `AliasDomainUpdater.php` (R12, rebuild after commit, deferred
  in bulk) and `AliasDomainDeleter.php` (R11: `AliasDomainCleanup::deleteRows()`, the footer
  exclusion, `SharedStore` `MULTI` of `redisFields()` with compensation, audit, rebuild after commit).
- [X] T054 [P] [US4] Create `app/Http/Requests/Api/V2/ListAliasDomainsRequest.php`,
  `StoreAliasDomainRequest.php`, `UpdateAliasDomainRequest.php`,
  `app/Http/Resources/Api/V2/AliasDomainCreatedResource.php`, `app/Policies/AliasDomainPolicy.php`
  (the `aliasDomains` check for creation raises `permission-flag-required` before validation) and
  `app/Http/Controllers/Api/V2/AliasDomainController.php` (`index`, `show`, `store`, `update`,
  `destroy`); `SharedStoreUnavailable` renders `503 temporary-failure` with `Retry-After: 10` and
  `dependency: sharedStore`.
- [X] T055 [US4] Register the alias domain routes in `routes/api.php` (`/alias-domains/bulk-actions`
  before `/alias-domains/{aliasDomain}`; writes with `restv2.audit` defaults and target
  `aliasDomain`). Makes T047–T051 pass.

**Checkpoint**: Alias domains are managed with the cleanup shared with 002.

---

## Phase 7: User Story 5 — Manage what a mailbox may send as (P2)

**Goal**: Read and replace explicit sender permissions and external sender addresses; list the
grantable entries (FR-053–FR-057).

**Independent Test**: Grant and revoke each kind of permission as administrator and as domain
administrators with and without `extend_sender_acl`; compare `sender_acl` and groupware rows with
legacy.

### Tests for User Story 5 (write first, observe failing) ⚠️

- [X] T056 [P] [US5] Write `tests/Feature/SenderSettings/GetMailboxSenderSettingsTest.php`
  (`getMailboxSenderSettings`): AS1 Anna receives `sales@` (sending allowed), `info@` (not allowed)
  and a catch-all, holds `@example.com`; the response lists her own address, her alias-domain
  addresses, the three aliases with `senderAllowed` and `catchAll`, the domain permission; a stale
  entry (domain deleted) is `stale`; AS3 for a domain administrator, `@other.example` and `*` are
  `changeable: false`; `senderCheckDisabled` follows `*`; `ETag` equals `etag`; a resource address,
  an alias address, an unknown or a foreign mailbox → `404`.
- [X] T057 [P] [US5] Write `tests/Feature/SenderSettings/UpdateMailboxSenderSettingsTest.php`
  (`updateMailboxSenderSettings`):
  - AS2: administrator sends `["@example.org", "*"]` → only `*` stored, `senderCheckDisabled: true`;
  - AS3: a domain administrator sends `["info@example.org"]` on a mailbox holding `@other.example`
    → both stored; `@other.example` sent back unchanged is accepted;
  - AS4: a domain administrator sending `@foreign.example` → `422 notFound` on
    `explicitPermissions.0`; sending `*` → `notEligible {allowedRoles: [admin]}`; nothing changed;
  - AS5: a domain administrator without `extend_sender_acl` changing `externalSenderAddresses` →
    `403 attribute-not-permitted` `{attributes: [externalSenderAddresses], requiredFlags: {externalSenderAddresses: extendSenderAcl}}`;
    the same list sent back unchanged (other order and case) with an explicit change → `200`;
  - AS6: with the flag, `["news@mailer.example", "partner.example"]` stores `news@mailer.example` and
    `@partner.example` with `external = 1`; the view row's `ext_acl` becomes `news@mailer.example`;
  - AS7: an external entry in a domain or alias domain of the server, also one the domain
    administrator does not manage → `notEligible {kind: localDomain}`;
  - AS8: Anna receives `info@` with sending not allowed; granting `info@example.org` stores it; an
    entry for `sales@` (sending allowed, delivering to her) is accepted and not stored;
  - an administrator saving without a stale entry removes it; duplicates stored once; only the sent
    list is replaced; `If-Match` mismatch → `412`; every invalid entry named in one `422`;
  - audit `mailboxSenderSettingsUpdate` with `changes` of both lists.
- [X] T058 [P] [US5] Write `tests/Feature/SenderSettings/ListSenderPermissionCandidatesTest.php`
  (`listSenderPermissionCandidates`, FR-056): `?mailbox=anna@example.org` gives administrators `*`
  first, then `@domain` for every domain and alias domain not held, then addresses of aliases and
  mailboxes; left out: the mailbox itself, aliases delivering to it, its alias-domain addresses,
  catch-all aliases and held entries; a domain administrator gets only their scope and no `*`;
  `type` filter, `q`, `sort`, paging, `meta.total`; a missing, foreign or resource `mailbox` →
  `422 notFound` on `mailbox`.
- [X] T059 [P] [US5] Write `tests/Feature/SenderSettings/SenderSettingsAuthorizationTest.php` for the
  three operations as every role and key type (read-only key refused on `PATCH`, mailbox user `403`,
  foreign mailbox `404`), each refusal of the `PATCH` audited.

### Implementation for User Story 5

- [X] T060 [P] [US5] Complete `app/Services/SenderSettings/SenderSettingsReader.php` (implicit
  addresses, explicit entries with `changeable` and `stale`, external entries; mailbox lookup through
  003's `MailboxScope`) and create `app/Http/Resources/Api/V2/MailboxSenderSettingsResource.php`.
- [X] T061 [US5] Create `app/Services/SenderSettings/SenderSettingsUpdater.php` with the replacement
  rules, statements and `SogoDirectory::refresh([mailbox])` of R14 / DM in one transaction with the
  audit record.
- [X] T062 [P] [US5] Create `app/Services/SenderSettings/PermissionCandidateQuery.php` (R15) and
  `app/Http/Resources/Api/V2/SenderPermissionCandidateResource.php`.
- [X] T063 [P] [US5] Create `app/Http/Requests/Api/V2/UpdateMailboxSenderSettingsRequest.php`,
  `ListSenderPermissionCandidatesRequest.php` (required `mailbox`), `app/Policies/MailboxSenderSettingsPolicy.php`,
  `app/Http/Controllers/Api/V2/MailboxSenderSettingsController.php` (`show`, `update`) and
  `SenderPermissionCandidateController.php` (`index`).
- [X] T064 [US5] Register `GET`/`PATCH /mailboxes/{mailbox}/sender-settings` (the `PATCH` with
  `restv2.audit` defaults and target `mailboxSenderSettings`) and `GET /sender-permission-candidates`
  in `routes/api.php`. Makes T056–T059 pass.

**Checkpoint**: Send-as rights are managed through the API.

---

## Phase 8: User Story 6 — Apply an action to many objects at once (P2)

**Goal**: The legacy mass actions as bulk actions, and copies over alias domains (FR-036, FR-037,
FR-058, FR-059).

**Independent Test**: Apply each action to a selection mixing objects inside and outside a domain
administrator's scope, with one outdated and one failing target; compare results, rows and audit
records.

### Tests for User Story 6 (write first, observe failing) ⚠️

- [X] T065 [P] [US6] Write `tests/Feature/Aliases/AliasCopyTest.php` (`copyAliasOverAliasDomains`):
  AS4 `sales@example.org` with alias domains `example.net` (holding `sales@example.net`) and
  `example.com` → `200`, `copies` in name order: `example.com` `created` with an `aliasId` whose row
  has the same `goto`, `active`, `sender_allowed`, `sogo_visible`, `internal` and empty comments;
  `example.net` `skipped` `addressInUse`; a catch-all copied as `@example.com`; a domain without
  alias domains → `copies: []`; an alias in an alias domain → `409 not-primary-domain-alias`; the
  limit reached mid-way → later copies `skipped` `aliasLimitReached`; a domain administrator without
  `alias_external_goto` copying an alias with external destinations → `skipped`
  `destinationNotPermitted`, and likewise with the server setting on; `If-Match` mismatch → `412`;
  one `aliasCreation` audit record per copy with `copiedFromAliasId`.
- [X] T066 [P] [US6] Write `tests/Feature/Aliases/AliasBulkActionTest.php` (`applyAliasBulkAction`):
  AS1 30 active aliases deactivated → 30 `done`, 30 audit records with the same `bulkRequestId`; AS2
  a domain administrator hiding 3 own and 2 foreign aliases → 3 `done`, 2 `notFound`; AS3 101
  targets → `422 tooMany {max: 100}`; AS6 three targets with versions, one changed meanwhile → that
  one `outdated` and unchanged, the others `done` with the new `version`; the same id twice →
  processed and reported once; `delete` removes the rows and sender permissions; `showInGroupware`
  with `SKIP_SOGO=y` stores the flag; `copyOverAliasDomains` → `done` per alias with
  `meta.copies[<id>]`, an alias in an alias domain `refused` with `not-primary-domain-alias`; a
  mailbox user and a read-only key → whole request `403`, audited once with `details.targetIds`.
- [X] T067 [P] [US6] Write `tests/Feature/AliasDomains/AliasDomainBulkActionTest.php`
  (`applyAliasDomainBulkAction`): activate, deactivate and delete (full cleanup per target); one
  groupware rebuild per request; a shared-store failure on one target → `failed` with
  `temporary-failure`, that target unchanged, the others done; versions per target; foreign →
  `notFound`.
- [X] T068 [P] [US6] Write `tests/Feature/Resources/ResourceBulkActionTest.php`
  (`applyResourceBulkAction`): AS5 deactivating resources removes their view rows; activate and
  delete; versions; foreign → `notFound`; 101 targets → `422`.

### Implementation for User Story 6

- [X] T069 [US6] Create `app/Services/Aliases/AliasCopier.php` and `AliasCopyOutcome.php` (R17),
  reusing `AliasCreator` per alias domain and mapping its refusals to `skipped` reasons;
  `app/Http/Resources/Api/V2/AliasCopyReportResource.php`; `app/Http/Controllers/Api/V2/AliasCopyController.php`.
- [X] T070 [P] [US6] Create `app/Http/Requests/Api/V2/AliasBulkActionRequest.php`,
  `AliasDomainBulkActionRequest.php` and `ResourceBulkActionRequest.php` (`targets` 1–100, `tooMany`
  `{max: 100}`), and the controllers `AliasBulkActionController.php` (with `meta.copies` for
  `copyOverAliasDomains`), `AliasDomainBulkActionController.php` and
  `ResourceBulkActionController.php`, each driving `BulkActionRunner` with the single operation's
  service method.
- [X] T071 [US6] Register `POST /aliases/{aliasId}/alias-domain-copies`, `POST /aliases/bulk-actions`,
  `POST /alias-domains/bulk-actions` and `POST /resources/bulk-actions` in `routes/api.php` (the
  bulk paths before the parameterised ones; `restv2.audit` defaults). Makes T065–T067 pass (T068
  after Phase 9).

**Checkpoint**: Mass actions available for aliases and alias domains.

---

## Phase 9: User Story 7 — Manage resources (P3)

**Goal**: Create, list, inspect, change and delete groupware resources (FR-046–FR-052).

**Independent Test**: Create, change and delete resources of each kind and booking rule; compare
`mailbox`, `sogo_*` and view rows with legacy.

### Tests for User Story 7 (write first, observe failing) ⚠️

- [X] T072 [P] [US7] Write `tests/Feature/Resources/CreateResourceTest.php` (`createResource`): AS1
  "Meeting Room 1" location → `201`, `Location: /api/v2/resources/MeetingRoom1@example.org`, row with
  `password = 'RESOURCE'`, `quota = 0`, `local_part`, `kind`, `multiple_bookings = -1`; view row
  refreshed with `kind` and `multiple_bookings`; AS2 "Meeting-Room 1" → `409 address-in-use`
  `holder: resource` (under 003's `AddressLock`); AS3 "!!!" → `422 invalidFormat
  {format: resourceDescription}`; AS6 a domain at its mailbox limit → created, mailbox count
  unchanged; AS7 an alias domain → `notEligible {kind: aliasDomain}`; an empty and a 256-character
  description → `required` / `tooLong {max: 255}`; an unknown or foreign domain → `notFound`;
  `limited` without `bookingLimit` → `required`; `bookingLimit` with `unlimited` → `conflictsWith`;
  `bookingLimit: 0` → `outOfRange`; audit `resourceCreation`.
- [X] T073 [P] [US7] Write `tests/Feature/Resources/ListResourcesTest.php` and `GetResourceTest.php`:
  filters `domain`, `kind`, `active`, `q` on description and address (including a partial Unicode
  label), every sort value, default by address; booking rule mapping (`0`, `-1`, `-5`, `2`); a
  mailbox address → `404`; domain administrator scope.
- [X] T074 [P] [US7] Write `tests/Feature/Resources/UpdateResourceTest.php` and
  `DeleteResourceTest.php`: a description change keeps the address; AS4 `limited`/2 stored as `2`;
  AS5 deactivation removes the view row; a whitespace-only description → `required`; a different
  `address` → `immutable`; `If-Match`; AS8 deletion removes the `mailbox` row and the resource's
  `sogo_user_profile`, `sogo_cache_folder`, `sogo_acl`, `sogo_store`, `sogo_quick_contact`,
  `sogo_quick_appointment` and `sogo_folder_info` rows (seeded), `204`; a mailbox address can never
  be deleted (`404`); audit records.
- [X] T075 [P] [US7] Write `tests/Feature/Resources/ResourceAuthorizationTest.php` for the five
  operations as every role and key type, each write refusal audited.

### Implementation for User Story 7

- [X] T076 [P] [US7] Create `app/Services/Resources/ResourceQuery.php` (search with `UnicodeSearch`)
  and `app/Http/Resources/Api/V2/GroupwareResourceResource.php` (contract `Resource`).
- [X] T077 [US7] Create `app/Services/Resources/ResourceCreator.php` (`AddressLock`, derivation,
  `AliasAddressAvailability`, legacy's `INSERT`, refresh), `ResourceUpdater.php` and
  `ResourceDeleter.php` (the `sogo_*` statements of R13), each with its audit record.
- [X] T078 [P] [US7] Create `app/Http/Requests/Api/V2/ListResourcesRequest.php`,
  `StoreResourceRequest.php`, `UpdateResourceRequest.php`, `app/Policies/ResourcePolicy.php` and
  `app/Http/Controllers/Api/V2/ResourceController.php`.
- [X] T079 [US7] Register the resource routes in `routes/api.php` (writes with `restv2.audit`
  defaults and target `resource`). Makes T068 and T072–T075 pass.

**Checkpoint**: All objects of the feature are managed.

---

## Phase 10: User Story 8 — Trace changes (P3)

**Goal**: Every change and refusal is in the audit log and can be filtered by target (FR-063,
FR-064).

### Tests for User Story 8 (write first, observe failing) ⚠️

- [X] T080 [P] [US8] Write `tests/Feature/Audit/AliasFeatureAuditTest.php`: AS1 a domain
  administrator replaces destinations; `GET /audit-events?targetType=alias&targetId=<id>` as an
  administrator shows actor, time, client address and `details.changes.destinations {old, new}`;
  AS2 a refused external destination is listed with `outcome: failure`,
  `failureReason: attributeNotPermitted` and `details.problemType`; AS3 a domain administrator →
  `403`; `targetType=aliasDomain` finds the alias domain's records by ASCII name; `resource` and
  `mailboxSenderSettings` targets; bulk records carry `bulkRequestId`; an out-of-scope id gives a
  `notFound` record with the id as sent; a read-only key's attempt gives a `permissionDenied`
  record; `401`s and CSRF failures give none; SC-007: a scripted sequence of the writes of Phases
  4–9 produces exactly one record per affected object.

### Implementation for User Story 8

- [X] T081 [US8] Make T080 pass: complete the target `id` and `name` of R20 in the services and the
  four resolvers. No audit filter parameter is added.

---

## Phase 11: Polish and cross-cutting concerns

- [ ] T082 [P] Write `tests/Feature/Security/Feature004AuthorizationMatrixTest.php`: every one of the
  23 operations × {anonymous, admin session without CSRF, admin session, admin read-write key, admin
  read-only key, domain-admin session, domain-admin read-only key, mailbox session, restricted
  session} with the expected status and problem `type` from a table derived from the contract and
  FR-005.
- [ ] T083 [P] Write `tests/Feature/Groupware/AliasGroupwareRefreshTest.php` (R9, SC-002): seed a
  varied fixture; after each of create, change and delete of an alias, a catch-all, an alias with
  external destinations, a resource and external sender addresses, and after a bulk action,
  `_sogo_static_view` (all columns but `c_password`) equals the result of 002's
  `SogoDirectory::rebuild()` run on the same data.
- [ ] T084 [P] Write `tests/Feature/Performance/AliasListPerformanceTest.php` (group `performance`;
  SC-005, SC-010): seed 5,000 domains, 100,000 mailboxes (with own-alias rows) and 200,000 aliases
  with multi-row inserts; the p95 of 20 `listAliases` calls (default, with `q`, with `destination`,
  as a domain administrator) is below 1 s; a bulk deactivation of 100 aliases completes below 10 s.
- [ ] T085 [P] Write `tests/Feature/Aliases/LegacyCoexistenceTest.php` (FR-067, SC-001): rows written
  with legacy's own statements (copied from `functions.mailbox.inc.php` into the test) for an alias,
  an alias domain, a resource and sender permissions appear unchanged in the next API response, and
  rows written by the API are read back by legacy's `alias_details`, `alias_domain_details`,
  `resource_details` and `sender_acl_handles` queries with the same values.
- [ ] T086 Run `docker compose run --rm app composer test`; confirm `build/contract/coverage.json`
  covers all 23 operations of this feature and that 001's `ProblemTypeConsistencyTest` passes.
- [ ] T087 [P] Run `vendor/bin/pint --test` and `vendor/bin/phpstan analyse` and fix every finding in
  the files of this feature.
- [ ] T088 [P] Add the feature's contract changes to the release notes (`CHANGELOG.md`): the 23
  operations, the new problem types and the values appended to `AuditEventType`, `AuditTarget`,
  `KnownProblemType` and `ValidationRuleCode`, so that mailcowui-ng can bump its pinned contract.
- [ ] T089 Walk through [quickstart.md](./quickstart.md) Part B against the dev stack and correct
  `specs/004-aliases-alias-domains-resources/quickstart.md` wherever the behaviour differs from the
  guide (the behaviour follows the contract, not the guide).

---

## Dependencies and execution order

### Phase dependencies

- **Before Phase 1**: features 001, 002 and 003 implemented (their suites green). The shared
  `RefusalAuditor` is reused if present, otherwise created in T014.
- **Phase 1 (Setup)** → **Phase 2 (Foundational)** → user story phases.
- **US1 (Phase 3)** needs Phase 2. **US2 (Phase 4)** needs US1's controller and routes.
  **US3 (Phase 5)** needs US2's services (`AliasLimit`, `AliasSenderAclEffects`).
- **US4 (Phase 6)** needs Phase 2 and `AliasLimit` (T036, for the target change); it seeds aliases
  through `MailcowFactory`, so it can run in parallel with US2–US3 once T036 is done.
- **US5 (Phase 7)** needs Phase 2 only.
- **US6 (Phase 8)** needs US2 and US3 (alias operations), US4 (alias domain operations) and, for
  T068, US7.
- **US7 (Phase 9)** needs Phase 2 and `AliasAddressAvailability` (T036).
- **US8 (Phase 10)** needs the write stories it audits (Phases 4–9).
- **Phase 11** needs all stories.

### Within each story

Tests first (observed failing) → query objects and value objects → services → Form Requests,
resources and policies → controllers → routes. A story is done when its tests and the contract
validation pass.

### Parallel opportunities

- Phase 2: T004–T012 are all [P]; T015–T021 are [P] (T014 after T006; T022 last).
- Within each story, all test tasks are [P], and query/resource/request tasks marked [P] touch
  different files.
- With two developers after Phase 2: one takes US1 → US2 → US3, the other US5, then US4 (after
  T036) and US7; US6 and US8 follow.

## Parallel example: User Story 2

```text
T030 CreateAliasTest            T031 CreateAliasRefusalTest     T032 AliasExternalDestinationTest
T033 AliasLimitLockTest         T034 AliasWriteAuthorizationTest T035 AliasesCapabilitiesTest
→ then T036 AliasLimit + AliasAddressAvailability  ∥  T038 StoreAliasRequest  ∥  T039 capabilities
→ then T037 AliasCreator → T040 routes
```

## Implementation strategy

### MVP first (User Stories 1–3)

1. Phases 1 and 2.
2. US1 (read), US2 (create), US3 (change, delete): billing systems can provision aliases and the web
   app can show and edit them. Validate with quickstart Part B steps 1–6.

### Incremental delivery

1. MVP (US1–US3).
2. US4 (alias domains) and US5 (sender settings), independently.
3. US6 (bulk actions and copies), then US7 (resources), then US8 (audit verification).
4. Polish: authorisation matrix, groupware equality, performance, coexistence, release notes.

## Notes

- Never use `RefreshDatabase`, `migrate:fresh` or `db:wipe`: they drop mailcow's tables (001 R3).
- No migration is created by this feature; 001's `MigrationPrefixTest` must stay green.
- Every upstream write goes through the services of this feature or the reused services of 002 and
  003 (constitution IV); controllers never write.
- Commit after each green checkpoint, with explicit file names and a short imperative message.

## Implementation notes (2026-10-18)

Deviations and decisions made while implementing, with the reason. Names in tasks that differ from
what exists map as listed.

- **T001**: the coverage scope is 001's `tests/feature-registry.json` (feature
  `004-aliases-alias-domains-resources` with its 23 operation IDs and the problem types
  `alias-domain-already-exists` and `not-primary-domain-alias`; `domain-alias-limit-reached` is
  registered by 003), so no `tests/contract-scope.php` was needed. Main's suite was green (1,493
  tests) and every 003 piece of plan.md "Dependencies on earlier features" exists on main.
- **T002, T013**: `resources/bin/export-mailcow-vars.php` exports every variable listed in
  `config/restv2.php`, so adding `ALIAS_DISABLE_EXTERNAL_DOMAINS` to `vars_overrides` and
  `vars_defaults` was enough; the fixture `vars.inc.php` (upstream, unmodified) already sets it to
  `false`.
- **T004**: existing helpers are reused: `spamalias()`/`temporaryAlias()` for `spamAlias()`,
  `sogoData()` for `sogoFolder()`; `alias()` gained the optional flags and comments after its
  existing parameters; `resource()` is new (legacy's column list); `redisHash()` lives on the test
  `MailcowFactory`.
- **T006, T053**: `DkimKeyGenerator` only creates key pairs since feature 010's core; DKIM keys of new
  names are written through `DkimKeyService::generateForNewName()` (as 002's domain creation does),
  which also publishes and writes the `dkimKeyGeneration` record (`trigger: aliasDomainCreation`).
- **T012, T020**: 002's `App\Support\Bulk\BulkActionRunner` is reused unchanged (its first unit test
  is T012). Deferred end-of-request work is done by the callers, as 002 does: alias domain actions
  rebuild the groupware once (`deferRebuild`), and the memcached flushes of alias and resource items
  are collected by the new request-scoped `App\Services\Groupware\GroupwareCacheFlush::deferred()`.
- **T014**: the four refusal target resolvers are one class, `App\Services\Aliases\AliasAuditTargets`,
  registered like 002's `DomainAuditTargets` and 003's `MailboxAuditTargets`.
- **T022**: the per-event detail whitelists live in `AuditEventType::detailKeys()` (001's design), not
  in `AuditLogger`. Creation records carry the stored attributes in `values`, as the creation records
  of 002 and 003 do, instead of `changes`. `permission-flag-required` existed (002).
- **T026, T028**: the scope of aliases is `App\Services\Aliases\AliasScope` (002's `DomainScope`
  covers `domain` rows only; aliases also reach the alias domains of the caller's domains), and the
  representation is built by `AliasPresenter`, which `AliasResource` wraps (002's
  `DomainPresenter`/`DomainResource` split). `AliasPolicy` (registered for the `Alias` model) gates
  every operation of feature 004, alias domains, resources and sender settings included.
- **T026**: ties are broken by the identifier in the requested direction (001's `ListQuery`
  convention); `active`, `senderAllowed`, `groupwareVisible` and `internal` filters treat any
  non-zero flag as `true`, as the representation does.
- **T036, T037**: the field rules shared by creations, changes and copies are in
  `App\Services\Aliases\AliasRules`; the groupware refresh in a savepoint is the new shared
  `App\Services\Groupware\GroupwareRefresh` (also used for resources and sender settings). Invalid
  destination entries do not hide the other entries' violations (FR-028).
- **T034, T043**: one test file, `AliasWriteAuthorizationTest`, covers creation, change and deletion.
- **T044**: a change whose values equal the stored ones writes nothing and is audited with empty
  `changes`, as 002's domain edits are (003's mailbox edits write no record); the FR-032 cleanup of
  redundant explicit permissions runs on every successful change of an alias that allows sending.
  An address change that would make the address one of the kept destinations is refused with
  `conflictsWith` on `address`.
- **T052–T054**: alias domains have their own `AliasDomainPresenter` (rate limits with one `HMGET`
  per page), `AliasDomainRules` (the target rules shared by creation and change) and
  `AliasDomainCreation`; 002's `DomainRules::name()` checks a new name (syntax and the host name).
  `updateAliasDomain` pings the shared store before it writes, so that a change is never committed
  when its representation (which carries the rate limit) cannot be returned. Alias domain creation
  records `removedExternalSenderAddresses` in `values`. Ties of the alias domain and resource lists
  are broken by the name or address ascending, as 002 does for domains.
- **T060–T063**: `MailboxSenderSettingsPolicy` is not a separate class: `AliasPolicy` gates the
  sender settings like every operation of the feature, and 003's `MailboxScope` resolves the mailbox
  (`404` for resources and other addresses). Implicit addresses leave out mailboxes' own rows, also
  another mailbox's row that forwards to this one (FR-013), and list catch-all aliases (spec
  deviation). An entry already stored is accepted as sent back, also a stale one or one outside a
  domain administrator's scope. A stored `*` that a domain administrator cannot change keeps the set
  at `*` alone, as legacy merges it. The version tag covers the stored values with their letter case.
- **G12–G14** (`docs/api-gaps.md`) are fixed with FR-069–FR-071: `kind` on explicit permissions
  (`null` when stale) and candidates, the Unicode forms `Alias.destinationsUnicode`,
  `AliasCopyOutcome.addressUnicode` and `MailboxSenderSettings.externalSenderAddressesUnicode`, and
  `kind: localDomain` in `MailboxSenderSettingsPatch`. `destinationsUnicode` and
  `externalSenderAddressesUnicode` may be sent back in a patch and are ignored.
- **T069, T070**: the bulk loops live in services (`AliasBulkActions`, `AliasDomainBulkActions`,
  `ResourceBulkActions`), as 002's `DomainBulkActions`; the controllers stay thin. A copy skips an
  address in use before it tries a creation, as legacy computes the missing addresses first; a
  copy refused for another reason is audited as a refused `aliasCreation` with `copiedFromAliasId`.
  `AliasCopyReportResource` is not needed: the report is returned as built.
- **T076–T078**: `GroupwareResourceResource` is not needed: `ResourcePresenter` builds the
  contract `Resource` and the shared `AliasResource` wraps every single and list response of the
  feature. `ResourceRules` holds the description and booking rules of creation and change. A
  resource change that sends `limited` alone keeps a stored limit; a resource of an inactive domain
  stays manageable (legacy). Resource deletion records the removed groupware rows in
  `removed` (`groupwareProfiles`, `groupwareFolders`, `groupwareItems`,
  `groupwareSharingPermissions`, `groupwareCacheEntries`).
- **T080, T081**: the target IDs and names of R20 were completed with the write stories (services
  and `AliasAuditTargets`), whose tests saw the audit assertions fail first; T080 therefore passed on
  its first run and T081 needed no further code.
