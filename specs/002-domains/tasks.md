---
description: "Task list for feature 002: domains"
---

# Tasks: Domains

**Input**: Design documents from `specs/002-domains/`: [plan.md](./plan.md), [spec.md](./spec.md),
[research.md](./research.md), [data-model.md](./data-model.md), [quickstart.md](./quickstart.md).
The contract is `api/modules/domains/`, `api/modules/domain-templates/` and the `Domain*`
components (authored and lint-clean). Problem types are in `docs/problems.md`.

**Depends on feature 001 being implemented.** This feature reuses, without re-creating: the dev
stack and schema fixture, `tests/TestCase.php` (contract validation, Redis flush, `signInAs()`),
`tests/Support/Mailcow/MailcowFactory.php`, `App\Support\Http\{ProblemType,Problem,ETag,ListQuery}`,
`App\Exceptions\ProblemException`, `App\Http\Requests\Api\V2\ApiFormRequest`,
`App\Services\Audit\AuditLogger`, `App\Services\Config\MailcowConfig`,
`App\Services\Identity\{IdentityService,Scope}`, `App\Auth\{Principal,Role,PermissionFlag}`, the
middleware `restv2.auth`, `restv2.csrf`, `restv2.pending`, `AuthenticateApiKey` and
`EnforceKeyScope`, and 001's models `Domain`, `DomainAdminAssignment`, `Mailbox`, `Admin`, `DaAcl`.

**Tests**: Mandatory (constitution VII). In every phase the test tasks come first and must be
observed **failing** before the implementation tasks of that phase start. Every feature test runs
on MariaDB with the upstream `2026-09a` schema fixture and on a real Redis (database 15). dockerapi,
memcached and ISPConfig are faked (`tests/Support/Fakes/`) and the fakes assert the calls. Every
request and response is validated against the contract by `tests/TestCase.php`.

**Organisation**: Phase 1 is setup, Phase 2 the foundation every story needs. Phases 3 to 10 are
the user stories in spec order (US1–US3 P1, US4–US6 P2, US7–US8 P3). Phase 11 is polish.

**Commands**: every PHP, Composer and Node command runs in the dev container:
`docker compose run --rm app <command>`.

## Format: `- [ ] [ID] [P?] [Story?] Description with file path`

- **[P]**: can run in parallel with the other [P] tasks of the same block (different files, no
  dependency on unfinished tasks).
- **[USn]**: the user story. Setup, Foundational and Polish tasks have no story label.
- FR, SC and AS references point to `spec.md`; D references to `research.md`.

---

## Phase 1: Setup (shared infrastructure)

**Purpose**: settings, deployment files and test tooling that the feature needs. No business logic.

- [X] T001 Extend `config/restv2.php` with the settings of research D24: `dkim.default_selector`
  (`env('RESTV2_DEFAULT_DKIM_SELECTOR', 'dkim')`), `dkim.dns_publication`
  (`env('RESTV2_DKIM_DNS_PUBLICATION', 'n') === 'y'`), `ispconfig.url`, `ispconfig.username`,
  `ispconfig.password`, `ispconfig.dns_server_id` (default `1`), `ispconfig.verify_tls` (default
  `true`), `dockerapi.url` (default `https://dockerapi:443`), `compose_project_name`
  (`env('COMPOSE_PROJECT_NAME')`), `memcached.host` (`memcached`), `memcached.port` (`11211`) and
  `fake_outbound` (`RESTV2_FAKE_OUTBOUND === 'y'`, honoured only when `APP_ENV=local`). Add to `.env.example`:
  `COMPOSE_PROJECT_NAME=mailcowdockerized`, `RESTV2_FAKE_OUTBOUND=y`; to `.env.testing`:
  `COMPOSE_PROJECT_NAME=mailcowdockerized`, `SKIP_SOGO=n`, `RESTV2_DEFAULT_DKIM_SELECTOR=dkim`.
- [ ] T002 [P] Update `deploy/docker-compose.override.yml`: add `COMPOSE_PROJECT_NAME` and every
  `RESTV2_*` variable of research D24 to the `restv2-mailcow` environment (empty defaults,
  `RESTV2_ISPCONFIG_PASSWORD` passed through, never hard-coded). Update
  `deploy/nginx/site.restv2.custom`: `client_max_body_size 5m;` instead of `1m` (research D21).
- [X] T003 [P] Extend `tests/Support/Contract/OpenApiContract.php` so that query parameters with
  `type: boolean` accept exactly `true` and `false` (coerced before schema validation, like
  integers); any other value is a request violation. Add the cases first to
  `tests/Unit/Support/OpenApiContractTest.php` (`?active=true` valid, `?active=1` invalid) and
  observe them failing (research D26).
- [X] T004 Scope the contract gates to implemented features, because `api/` already holds the
  contracts of later features. Create `tests/contract-scope.php`, returning the OpenAPI tags whose
  operations are implemented (001's seven tags plus `Domains` and `Domain templates`), and change
  `tests/bin/check-contract-coverage.php` to require coverage only for operations carrying one of
  those tags. Change `tests/Unit/Support/ProblemTypeConsistencyTest.php` so that every
  `ProblemType` case must be a section of `docs/problems.md` and a value of `KnownProblemType` with
  the same status (subset instead of equality), and the six types of this feature must be cases.
  Skip this task if 001's implementation already scoped both gates.
- [X] T005 Verify the contract (FR-060): `composer contract:lint` reports zero errors (001's four
  accepted warnings only), `composer contract:bundle` succeeds, and
  `php tests/bin/check-contract-coverage.php` lists exactly the 16 operations of plan.md as not yet
  covered.

**Checkpoint**: settings load, the deployment files are updated and the contract gates see the 16
new operations.

---

## Phase 2: Foundational (blocking prerequisites)

**Purpose**: name handling, models, the shared store, outbound clients, groupware directory, usage
figures, version tags, tags, template attributes, audit extensions and validation codes. **No user
story can start before this phase is complete.**

### Test toolkit (no business logic)

- [X] T006 Extend `database/factories/MailcowRows.php` and `tests/Support/Mailcow/MailcowFactory.php`
  with writers that insert upstream rows exactly as legacy does (data-model.md):
  - `domain(name, attributes = [])` with every `domain` column (quotas in MiB, `relayhost` `'0'`),
    `domainTags(domain, tags)`, `domainTemplate(name, attributes, type = 'domain')` storing the
    legacy JSON keys (quotas in **bytes**), `domainFooter(domain, html, plain, mboxExclude,
    aliasDomainExclude, skipReplies)`;
  - `aliasDomain(alias, target, active = 1)`, `alias(address, goto, domain, active = 1)`,
    `mailbox(…, quota = bytes, kind = '')` (extend 001's writer with `quota` and `kind`),
    `quota2(username, bytes, messages)`, `quota2replica(username)`, `relayhost(id, hostname)`,
    `senderAcl(loggedInAs, sendAs, external = 0)`, `spamalias(address, goto)`,
    `filterconf(object, option, value)`, `bccMap(localDest, bccDest, domain, type)`,
    `mtaSts(domain)`, `pushover(username)`;
  - `domainAdmin(username, domains[], daAcl = [], active = 1, assignmentActive = 1)` (extend 001's
    with inactive assignments) and `orphanedDomainAdmin(username)` (an `admin` row with
    `superadmin = 0` and no assignment);
  - Redis helpers on the `mailcow` connection: `domainMap(name)`, `rateLimit(object, "10 / 1h")`,
    `dkimKey(domain, selector)`.

### Tests for the foundation (write first, observe failing) ⚠️

- [X] T007 [P] Write `tests/Unit/Support/DomainNameTest.php` (FR-006, FR-019, D4):
  - `normalize(' MÜNCHEN.de ')` and `normalize('münchen.de')` → `xn--mnchen-3ya.de`;
    `normalize('Example.ORG')` → `example.org`; a string that UTS #46 rejects → `null`;
  - `isValid()` equals legacy `is_valid_domain_name()`: `localdomain`, `a-b.example`,
    `a--b.example` valid; `-a.example`, `a-.example`, `a_b.example`, `a..b`, a 64-character label
    and a 254-character name invalid;
  - `toUnicode('xn--mnchen-3ya.de')` → `münchen.de`; an undecodable label returns the ASCII form.
- [X] T008 [P] Extend `tests/Unit/Services/MailcowConfigTest.php`: `taggingLimit()` and
  `dockerTimeout()` come from `$TAGGING_LIMIT` and `$DOCKER_TIMEOUT` of the vars fixtures (upstream
  25 and 60; a `vars.local.inc.php` override `$TAGGING_LIMIT = 30` wins), `RESTV2_TAGGING_LIMIT` and
  `RESTV2_DOCKER_TIMEOUT` win over the files, and the defaults apply when the directory is missing
  (D24, 001 R14).
- [X] T009 [P] Write `tests/Unit/Clients/HttpDockerApiClientTest.php` with `Http::fake()` (D14):
  - `restart('sogo-mailcow')` requests `GET {dockerapi.url}/containers/json`, picks the container
    whose labels are `com.docker.compose.service = sogo-mailcow` and
    `com.docker.compose.project = MailcowDockerized` (project compared case-insensitively with
    `COMPOSE_PROJECT_NAME`), then `POST /containers/<id>/restart`;
  - `exec('dovecot-mailcow', ['cmd' => 'maildir', 'task' => 'cleanup', 'maildir' => 'example.org'])`
    posts exactly that JSON to `/containers/<id>/exec`;
  - `{"type":"success"}` → success; `{"type":"danger","msg":"x"}` → failed with `x`; no matching
    container → failed; a connection timeout → `unknown`;
  - TLS verification is off and the timeout equals `MailcowConfig::dockerTimeout()`.
- [X] T010 [P] Write `tests/Unit/Clients/SocketMemcachedFlusherTest.php` (D13): a local TCP server
  started in the test receives exactly `flush_all\r\n` and answers `OK\r\n` → `true`; an unreachable
  port → `false` within 2 s and one warning log entry; no exception escapes.
- [X] T011 [P] Write `tests/Unit/Clients/JsonIspconfigDnsClientTest.php` with `Http::fake()` (D10):
  - `login` posts `{"username","password"}` to `{ispconfig.url}?login` and keeps the session ID;
  - `findZone('example.org.')` posts `{"session_id","primary_id":{"origin":"example.org."}}` to
    `?dns_zone_get`; `records(zoneId)` uses `?dns_rr_get_all_by_zone` with `zone_id`;
    `addTxt(clientId, params)` and `updateTxt(clientId, recordId, params)` use `?dns_txt_add` and
    `?dns_txt_update` with `client_id`, `primary_id` and `params`; `logout` is called last;
  - a response with `"code":"remote_fault"` raises `IspconfigException`; the whole exchange stops
    after 15 s; `ispconfig.verify_tls` is honoured;
  - the password never appears in an exception message or a log entry.
- [X] T012 [P] Write `tests/Feature/Clients/SharedStoreTest.php` on the real Redis (D8, D17):
  `ping()`; `setFields(key, [field => value])` and `deleteFields([key => [fields]])` run in one
  `MULTI`/`EXEC` on the `mailcow` connection (no `restv2:` prefix) and return an undo action that
  restores the previous values (absent fields removed again); pointing the connection at a closed
  port makes every method throw `SharedStoreUnavailable`.
- [X] T013 [P] Write `tests/Feature/Groupware/SogoDirectoryTest.php` (D13, FR-023, FR-034, FR-036):
  - after `rebuild()`, `_sogo_static_view` holds one row per active mailbox with `c_uid`, `domain`,
    `c_name`, `c_cn`, `mail`, `aliases` (space-separated, from `grouped_mail_aliases`), `ad_aliases`,
    `ext_acl`, `kind` and `multiple_bookings` as legacy's statement produces them, and rows of
    inactive or deleted mailboxes are removed;
  - `c_password` starts with `{SSHA256}` and differs between two rebuilds;
  - the fake memcached flusher was called once; with `SKIP_SOGO=y` nothing is written, nothing is
    flushed and the result is `groupwareDisabled`; a database error gives `failed` and is logged.
- [X] T014 [P] Write `tests/Feature/RateLimits/RateLimitStoreTest.php` (D9): `set('example.org', 10,
  'hour')` writes `RL_VALUE` field `example.org` = `10 / 1h`; `get()` parses `10 / 1h`, `5 / 1s`,
  `7 / 1d` into value and frame; a missing or malformed value → `null`; `delete()` removes the field.
- [X] T015 [P] Write `tests/Feature/Domains/DomainUsageCalculatorTest.php` (FR-008, D5):
  - AS-2: 3 mailboxes, 1 resource, 5 aliases (2 in an alias domain) and 2 aliases equal to mailbox
    addresses → `mailboxes 3`, `aliases 5`, `aliasDomains 1`;
  - AS-8: 2 resources and mailboxes of 1, 2 and 5 GiB → `resources 2`, `largestMailboxQuota`
    5 GiB, `quotaAllocated` 8 GiB; a mailbox with quota 0 allocates nothing;
  - `storageUsed` and `messages` sum `quota2` over mailboxes and resources;
  - `mailboxesLeft`/`aliasesLeft` may be negative; `maxNewMailboxQuota` =
    `max(0, min(domainQuota − quotaAllocated, maxMailboxQuota))`, `defaultNewMailboxQuota` capped at
    `defaultMailboxQuota`;
  - figures for 25 domains are computed with at most six queries (count queries with
    `DB::listen`).
- [X] T016 [P] Write `tests/Feature/Domains/DomainVersionTest.php` (FR-041, D7):
  - the tag is stable across reads and equal for the list entry, the single read and the preview;
  - it changes when any of `description`, `aliases`, `mailboxes`, `defquota`, `maxquota`, `quota`,
    `relayhost`, `backupmx`, `gal`, `relay_all_recipients`, `relay_unknown_only`, `active` or the
    tag set changes through direct SQL (the legacy UI);
  - it does not change with `quota2`, `RL_VALUE`, `domain_admins` or new aliases and mailboxes;
  - a deleted and re-created domain with the same values gets a different tag (`created`);
  - footer tags: no row and a stored empty footer give the same tag; template tags change with the
    name and any attribute.
- [X] T017 [P] Write `tests/Unit/Services/DomainTagSetTest.php` (FR-043, D12): trimming; empty →
  `required` at `tags.<i>`; 256 characters → `tooLong`; `["Gold", "gold", " GOLD "]` → `["Gold"]`;
  more than the limit → `tooMany` with `max`; a current set of 30 may become any set of fewer than
  30 or stay equal, but not a different set of 30; sorting ignores letter case.
- [X] T018 [P] Write `tests/Unit/Services/DomainTemplateAttributesTest.php` (D22): decoding legacy
  attributes with string numbers, `tags` as a JSON string, missing keys (FR-046 defaults),
  `rl_value` `""`/`"10"`/`10`, `rl_frame` `h`, `key_size` `512`; encoding writes exactly the legacy
  keys with integer bytes, `rl_value` `""` for no rate limit and `rl_frame` `s`; a decode of the
  seeded Default template of `init_db.inc.php` gives the FR-022 values.
- [X] T019 [P] Write `tests/Feature/Audit/AuditFollowUpCompletionTest.php` (D16): a record written
  with `creationReport: "pending"` is completed once by `AuditLogger::completeFollowUps()`; a second
  completion, a completion of a non-pending key and a key other than `creationReport` and
  `maildirCleanup` throw; `AuditEvent::update()` still throws; the `details` whitelist accepts the
  keys of data-model.md for the seven new event types and drops others; the failure reasons
  `attributeNotPermitted`, `versionOutdated`, `validationFailed`, `conflict` and `temporaryFailure`
  are accepted.
- [X] T020 [P] Extend `tests/Unit/Http/ValidationRuleCodeTest.php` (FR-059, D3): `App\Rules\WholeMebibytes`
  → `notMultipleOf` `{factor: 1048576}`; `App\Rules\DkimSelector` → `invalidFormat`
  `{format: dkimSelector}`; violations added by services through
  `App\Support\Http\FieldViolations` (`mustNotExceed {field, value}`, `belowInUse {inUse}`,
  `reserved {}`, `immutable {}`, `notFound {}`, `tooLong {max, unit: bytes}`) render as one
  `422 validation-failed` with every field; `App\Support\Http\ProblemType` has the cases
  `domain-exists` (409), `domain-not-empty` (409), `domain-template-name-exists` (409),
  `domain-template-protected` (409), `attribute-not-permitted` (403) and `temporary-failure` (503,
  `Retry-After: 10`).

### Implementation of the foundation

- [X] T021 Create `app/Support/Domain/DomainName.php` (D4). This makes the DomainName test pass.
- [X] T022 Extend `resources/bin/export-mailcow-vars.php` to export `TAGGING_LIMIT` and
  `DOCKER_TIMEOUT`, and `app/Services/Config/MailcowConfig.php` with `taggingLimit(): int` and
  `dockerTimeout(): int` and the overrides `RESTV2_TAGGING_LIMIT` and `RESTV2_DOCKER_TIMEOUT`, with
  the upstream defaults 25 and 60.
- [X] T023 [P] Create `app/Clients/DockerApi/DockerApiClient.php` (interface: `restart(string
  $service): DockerCallResult`, `exec(string $service, array $command): DockerCallResult`),
  `HttpDockerApiClient.php` and `DockerCallResult.php` (D14), and
  `tests/Support/Fakes/FakeDockerApiClient.php` (records calls, programmable results,
  `assertRestarted()`, `assertExecuted()`, `assertNothingCalled()`). Bind the adapter in
  `app/Providers/AppServiceProvider.php` and the fake in `tests/TestCase.php::setUp()`.
- [X] T024 [P] Create `app/Clients/Memcached/MemcachedFlusher.php` (interface `flush(): bool`) and
  `SocketMemcachedFlusher.php` (D13), and `tests/Support/Fakes/FakeMemcachedFlusher.php`
  (`flushCount()`). Bind both like the dockerapi client.
- [X] T025 [P] Create `app/Clients/Ispconfig/IspconfigDnsClient.php` (interface: `findZone(string
  $origin): ?array`, `records(int $zoneId): array`, `addTxt(int $clientId, array $params): void`,
  `updateTxt(int $clientId, int $recordId, array $params): void`), `JsonIspconfigDnsClient.php` and
  `IspconfigException.php` (D10), and `tests/Support/Fakes/FakeIspconfigDnsClient.php` (zones and
  records in memory, programmable failures and delays, recorded calls). Bind both.
- [X] T026 Create `app/Clients/SharedStore/SharedStore.php` and `SharedStoreUnavailable.php` on the
  `mailcow` Redis connection (D8, D17). Map `SharedStoreUnavailable` in `bootstrap/app.php` to
  `503 temporary-failure` with `Retry-After: 10`.
- [X] T027 [P] Create `app/Casts/Mebibytes.php` (MiB column ↔ bytes) and the models of
  data-model.md: extend `app/Models/Mailcow/Domain.php` with every column and cast (`boolean` for
  the TINYINT flags, `MailcowDateTime` for `created` and `modified`, `Mebibytes` for `defquota`,
  `maxquota`, `quota`), and create `DomainTag.php`, `DomainTemplate.php` (global scope
  `type = 'domain'`), `DomainFooter.php`, `AliasDomain.php`, `Alias.php` and `Relayhost.php` in
  `app/Models/Mailcow/`, each read-only (`$guarded = ['*']`).
- [X] T028 Create `app/Services/Groupware/SogoDirectory.php` (D13). This makes the SogoDirectory test
  pass.
- [X] T029 [P] Create `app/Services/RateLimits/RateLimitStore.php` (D9), on `SharedStore`.
- [X] T030 Create `app/Services/Domains/DomainUsage.php` and `DomainUsageCalculator.php` (D5).
- [X] T031 [P] Create `app/Services/Domains/DomainVersion.php` (D7: `forDomain(row, tags)`,
  `forFooter(row|null)`, `forTemplate(row)`, all through `App\Support\Http\ETag`).
- [X] T032 [P] Create `app/Services/Domains/DomainTagSet.php` (D12).
- [X] T033 [P] Create `app/Services/DomainTemplates/DomainTemplateAttributes.php` (D22: tolerant
  decode, exact legacy encode, FR-046 defaults with `config('restv2.dkim.default_selector')`).
- [X] T034 Extend `app/Services/Audit/AuditLogger.php` with `completeFollowUps(string $eventId,
  string $key, array|string $outcome)` (D16; the only code that updates `restv2_audit_events`),
  the PHP enum cases of `AuditEventType` (`domainCreation`, `domainUpdate`, `domainDeletion`,
  `domainFooterUpdate`, `domainTemplateCreation`, `domainTemplateUpdate`,
  `domainTemplateDeletion`), the target types `domain`, `domainFooter`, `domainTemplate`, the five
  failure reasons and the `details` whitelist of data-model.md. `record()` returns the event ID.
- [X] T035 Create `app/Rules/WholeMebibytes.php`, `app/Rules/DkimSelector.php` (`^[A-Za-z0-9._-]*$`,
  max 255) and `app/Support/Http/FieldViolations.php` (collects `field → [code, params, message]`
  and throws the `validation-failed` problem), register their codes in
  `app/Http/Requests/Api/V2/ApiFormRequest.php`, and add the six `ProblemType` cases.
- [X] T036 Create `app/Services/Domains/DomainScope.php`: `resolve(Principal, string $rawName):
  ?string` normalises the name and returns it only when it is a `domain` row in the principal's
  scope (admin: any; domain admin: active assignment; alias domains and invalid names: `null`).
  Create `app/Policies/DomainPolicy.php` (`viewAny`, `view`, `update`, `updateFooter`: admin or
  domain admin; `create`, `delete`, `bulk`, `previewDeletion`: admin only) and
  `app/Policies/DomainTemplatePolicy.php` (admin only), registered in `AppServiceProvider`; a
  refusal renders `403 forbidden`, a `null` scope result `404 about:blank`.

**Checkpoint**: the foundation tests are green. User stories can start.

---

## Phase 3: User Story 1 — List, search and inspect domains (P1) 🎯 MVP

**Goal**: administrators and domain administrators list, search, filter, sort and inspect the
domains in their scope with the legacy usage figures, read the domain capabilities and the
distinct tags.

**Independent test**: seed domains in every relay state with mailboxes, resources, aliases, alias
domains, tags and stored usage; list and inspect them as an administrator, a domain administrator
and with their keys, with each search, filter and sort option; compare every figure with legacy's
queries.

### Tests for User Story 1 (write first, observe failing) ⚠️

- [X] T037 [P] [US1] Write `tests/Feature/Domains/ListDomainsTest.php` for `listDomains`
  (FR-001, FR-002, FR-011–FR-015):
  - AS-1: 60 domains, no parameters → the first 25 by name, `meta {total: 60, limit: 25, offset: 0}`;
    `limit=1000` returns all; `limit=1001` and `offset=-1` → `422`;
  - each `sort` value of `DomainSort` in both orders, ties broken by name; `mailboxes`, `aliases`,
    `quotaAllocated` and `storageUsed` sort by the usage figures; an unknown `sort` → `422
    notInList`;
  - AS-3: `q=customer` matches tags `customer-a` and `customer-b`; `q` also matches the ASCII name,
    the description and the readable name (`q=münch` finds `xn--mnchen-3ya.de`); `%` and `_` in
    `q` are literal;
  - `tag=CUSTOMER-A` matches whole tags without regard to letter case; two `tag` values match
    either; `active=false` and `backupMx=true` filter;
  - AS-4: a domain administrator with two of ten domains sees exactly those two (`total 2`),
    including an inactive domain; a domain with an inactive assignment is not listed; an assignment
    added or removed through SQL applies to the next request; entries never have `domainAdmins`;
    administrators get `domainAdmins` sorted;
  - AS-7: a read-only key owned by that domain administrator gets the same two;
  - AS-6: a mailbox user → `403 forbidden`; a restricted session → `403 pending-action-required`;
    no authentication → `401`;
  - AS-10: each entry's `etag` equals the `ETag` of `getDomain` for that domain.
- [X] T038 [P] [US1] Write `tests/Feature/Domains/GetDomainTest.php` for `getDomain` (FR-006–FR-010,
  FR-016):
  - every member of `Domain` from seeded columns: quotas in bytes (`defquota 3072` → `3221225472`),
    booleans, `description`, `createdAt` converted from `TZ=Europe/Prague` to UTC, `modifiedAt`
    `null` when never modified, `tags` sorted without regard to letter case;
  - `relayhost`: `'0'` → `null`; `'3'` with `relayhosts.id = 3` → `{id: 3, hostname}`; `'9'` without
    a row → `{id: 9, hostname: null}`; `rateLimit`: `RL_VALUE` `10 / 1h` → `{value: 10, frame:
    hour}`, absent → `null`;
  - AS-5: `/domains/münchen.de` and `/domains/MÜNCHEN.de` return `xn--mnchen-3ya.de` with
    `unicodeName münchen.de`;
  - an alias domain name → `404`; AS-4: a domain outside a domain administrator's scope → `404`
    with the same `type`, `title` and `status` as a name that does not exist (FR-003, SC-004);
    an invalid name → `404`;
  - the `ETag` header equals `data.etag`; a change made through SQL (the legacy UI) is visible in the
    next read (FR-061); mailbox user → `403 forbidden`.
- [X] T039 [P] [US1] Write `tests/Feature/Domains/DomainUsageParityTest.php` (SC-001): create
  `tests/fixtures/legacy/domain_details_queries.php`, holding the SQL statements of legacy
  `mailbox('get', 'domain_details')` copied verbatim from `2026-09a` with the tag and source SHA-256
  in a header. For a fixture of 12 domains in every relay state with mailboxes, resources, aliases,
  alias domains, aliases that are mailbox addresses and `quota2` rows, every figure of `getDomain`
  equals the legacy query result (`mboxes_in_domain`, `aliases_in_domain`, `quota_used_in_domain`,
  `bytes_total`, `msgs_total`, `max_new_mailbox_quota` where non-negative, `def_new_mailbox_quota`).
- [X] T040 [P] [US1] Write `tests/Feature/Domains/DomainCapabilitiesTest.php` for
  `getDomainsCapabilities` (FR-028, FR-064, SC-012, AS-11 of US2):
  - administrator: `groupwareEnabled true`, `taggingLimit 25`, `maxTagLength 255`,
    `maxDescriptionLength 255`, `footerTextMaxBytes 1048576`, `bulkLimit 100`, `defaultPageSize 25`,
    `maxPageSize 1000`, `quotaUnit 1048576`, `dkim {defaultSelector: dkim, defaultKeySize: 2048,
    allowedKeySizes: [1024, 2048, 3072, 4096], dnsPublicationEnabled: false}`;
  - `SKIP_SOGO=y` → `groupwareEnabled false`; `RESTV2_DEFAULT_DKIM_SELECTOR=feldhost` → `feldhost`;
    `RESTV2_TAGGING_LIMIT=30` → `30`; publication `y` with URL,
    user and password → `dnsPublicationEnabled true`, with the URL missing → `false`;
  - a domain administrator gets no `dkim`; a read-only key works; a mailbox user → `403`;
  - the body never contains the ISPConfig URL, user or password.
- [X] T041 [P] [US1] Write `tests/Feature/Domains/ListDomainTagsTest.php` for `listDomainTags`
  (FR-066): AS-9 (three domains tagged `gold`, one outside the domain administrator's scope →
  `{name: gold, domainCount: 2}`); `Gold` and `gold` on different domains count together; a tag only
  on out-of-scope domains is absent; `q`, `sort=domainCount`, `order`, paging and `meta.total`;
  mailbox user → `403`.

### Implementation for User Story 1

- [X] T042 [US1] Create `app/Services/Domains/DomainPresenter.php` (rows + tags + usage + rate limit
  + relayhost + version → the `Domain` shape, `domainAdmins` only for administrators) and
  `app/Http/Resources/Api/V2/DomainResource.php`.
- [X] T043 [US1] Create `app/Services/Domains/DomainQuery.php` (D6: scope, `q` including the Unicode
  match, filters, sorts with derived usage tables, ties by name, total) and
  `app/Http/Requests/Api/V2/ListDomainsRequest.php` (`ListQuery` with the `DomainSort` values;
  `active`, `backupMx` booleans; `tag` array of at most 100 strings of 1–255 characters).
- [X] T044 [US1] Create `app/Http/Controllers/Api/V2/DomainController.php` with `index` and `show`
  (`ETag` header), and register `GET /domains` and `GET /domains/{domain}` in `routes/api.php` with
  `restv2.auth:authenticated` and `restv2.pending`. This makes the list, get and parity tests pass.
- [X] T045 [P] [US1] Create `app/Services/Domains/DomainCapabilitiesService.php`,
  `app/Http/Resources/Api/V2/DomainCapabilitiesResource.php` and
  `app/Http/Controllers/Api/V2/DomainCapabilitiesController.php` (`show`); register
  `GET /capabilities/domains`.
- [X] T046 [P] [US1] Create `app/Services/Domains/DomainTagSummaryQuery.php` (D23),
  `app/Http/Requests/Api/V2/ListDomainTagsRequest.php`,
  `app/Http/Resources/Api/V2/DomainTagSummaryResource.php` and
  `app/Http/Controllers/Api/V2/DomainTagController.php` (`index`); register `GET /domain-tags`.

**Checkpoint**: US1 works on its own; the domain screens can be built against the API.

---

## Phase 4: User Story 2 — Create a domain (P1)

**Goal**: administrators create a domain from a template with every legacy side effect and a
per-step creation report.

**Independent test**: create domains with the Default template, another template, overrides, with
and without a groupware restart, with an existing DKIM key and with the DNS publication on and off;
compare rows, Redis entries and fake calls with legacy's behaviour.

### Tests for User Story 2 (write first, observe failing) ⚠️

- [X] T047 [P] [US2] Write `tests/Feature/Domains/CreateDomainTest.php` for `createDomain`
  (FR-017–FR-022, FR-029):
  - AS-1: `{name: example.org}` with the seeded Default template → `201`,
    `Location: /api/v2/domains/example.org`, `ETag`, the template's values, `description`
    `example.org`, `creationReport.dkimKey created`;
  - AS-2: template "Reseller" (50 mailboxes, 50 GiB domain quota) with `maxMailboxes: 20` → 20
    mailboxes, 50 GiB and every other value from "Reseller";
  - AS-3: an existing domain or alias domain `example.org`, sent as `Example.org` or `EXAMPLE.ORG` →
    `409 domain-exists`, no row, no Redis change;
  - AS-4: `defaultMailboxQuota` above `maxMailboxQuota` → `422 mustNotExceed` on
    `defaultMailboxQuota` with `field: maxMailboxQuota`; `maxMailboxQuota` above `domainQuota`
    likewise; nothing created;
  - AS-5: the host name (`MAIL.EXAMPLE.ORG` for `MAILCOW_HOSTNAME=mail.example.org`) → `422
    reserved` on `name`;
  - AS-6: only `relayUnknownOnly: true` → all three relay options `true`;
  - `München.de` stored as `xn--mnchen-3ya.de`; `localdomain` accepted; `exa_mple.org` →
    `invalidFormat` `{format: domainName}`; a quota of 1.5 MiB → `notMultipleOf`; an unknown
    `templateId` → `notFound`; 26 tags → `tooMany {max: 25}`; `description` of 256 characters →
    `tooLong`; `dkimSelector: "a b"` → `invalidFormat {format: dkimSelector}`; `dkimKeySize: 512`
    and `rateLimit.frame: week` → `notInList` (tests whose names contain `Invalid` where the body
    violates the contract); several violations are reported together, each with its rule code
    (SC-009);
  - the Default template missing → the FR-022 built-ins with the configured default selector;
  - external `sender_acl` rows `x@example.org` are removed; internal ones and other domains' stay;
  - AS-10: a domain administrator → `403 forbidden`; a read-only administrator key → `403
    insufficient-key-scope`; a mailbox user → `403 forbidden`; missing CSRF → `403
    csrf-token-mismatch`.
- [X] T048 [P] [US2] Write `tests/Feature/Domains/CreateDomainSideEffectsTest.php` (FR-023,
  FR-025–FR-027):
  - `DOMAIN_MAP` field `example.org` = `1`;
  - rate limit from the request (`10 / 1h` in `RL_VALUE`), from the template, and `rateLimit: null`
    overriding the template (no field, `notRequested`);
  - DKIM: `DKIM_SELECTORS` = selector, `DKIM_PUB_KEYS` = the PEM body without header lines whose
    RSA key has the requested bits, `DKIM_PRIV_KEYS["<selector>.example.org"]` = the matching
    private key; AS-7: an existing key is kept byte for byte, `alreadyExisted`, publication
    `skipped`; an explicitly empty selector → `notRequested`, no DKIM field;
  - the groupware directory is rebuilt and memcached flushed → `groupwareDirectoryUpdate done`;
    `restartGroupware: true` → `FakeDockerApiClient` restarted `sogo-mailcow` → `done`; AS-8: the
    restart fails → `201` with `groupwareRestart failed` and a message; a timeout (`unknown`) →
    `failed`; with `SKIP_SOGO=y` both groupware steps are `groupwareDisabled` and nothing is called
    (FR-026);
  - a failing rate limit write after the commit (Redis connection broken after the commit by a
    `SharedStore` double) → `rateLimit failed`, the domain stays.
- [X] T049 [P] [US2] Write `tests/Feature/Domains/CreateDomainDnsPublicationTest.php` (AS-9, FR-027,
  D10) with `FakeIspconfigDnsClient`:
  - publication off → `notConfigured`, no call;
  - on, zone `example.org.` with a TXT record `dkim._domainkey` → `updateTxt` with
    `v=DKIM1;k=rsa;t=s;s=email;p=<public key>`, `ttl 7200`, `active Y`, `server_id` from settings and
    client `sys_groupid − 1` → `published`; a fully qualified record name is updated too; no record
    → `addTxt`; no zone → `noZone`; a client exception or a delay over 15 s → `failed` while the
    domain is created; on but URL missing → `notConfigured` and one warning;
  - the FeldHost selector setting (`RESTV2_DEFAULT_DKIM_SELECTOR=feldhost`) is the published
    selector (FR-028).
- [X] T050 [P] [US2] Write `tests/Feature/Domains/CreateDomainAtomicityTest.php` (FR-024, FR-056):
  - shared store down before the creation → `503 temporary-failure` with `Retry-After`, no `domain`
    or `tags_domain` row, a failure audit record with `temporaryFailure`;
  - `HSET DOMAIN_MAP` failing inside the transaction (`SharedStore` double) → `503`, no rows;
  - a failing commit after the `HSET` → the `DOMAIN_MAP` field is removed again (undo invoked);
  - concurrent creation (edge case): a second PHP process
    (`Symfony\Component\Process\Process` running `php artisan tinker` with the same
    `DomainCreator` call) and the HTTP request create the same name in parallel → exactly one
    succeeds, the other gets `domain-exists`, and exactly one row exists.
- [X] T051 [P] [US2] Write `tests/Feature/Domains/CreateDomainParityTest.php` (SC-002): create
  `tests/fixtures/legacy/domain_add_expected.php` with the rows and Redis entries that legacy
  `mailbox('add', 'domain')` of `2026-09a` writes for three inputs (default template, template with
  overrides and rate limit, relay options), each value annotated with the legacy line it comes
  from. The API creation with the same input leaves identical `domain` and `tags_domain` rows
  (apart from `created`), `DOMAIN_MAP`, `RL_VALUE` and `DKIM_SELECTORS` values, and the same fake
  calls.
- [X] T052 [P] [US2] Write `tests/Feature/Domains/CreateDomainAuditTest.php` (FR-057, SC-007): one
  `domainCreation` record with actor, target `{type: domain, id: example.org, name}`, client
  address, `values`, `templateId` and `creationReport` equal to the response's (completed, not
  `"pending"`); refused creations (`409`, `422`) give one failure record with `conflict` or
  `validationFailed` and `details.problemType`; when the audit insert fails, the response is `500`
  and neither the row nor the `DOMAIN_MAP` field exists.

### Implementation for User Story 2

- [X] T053 [P] [US2] Create `app/Services/Dkim/DkimKeyGenerator.php` (D10: legacy key generation and
  the three Redis hashes through `SharedStore`; private keys never logged).
- [X] T054 [P] [US2] Create `app/Services/Dkim/DkimDnsOutcome.php` (enum) and
  `app/Services/Dkim/DkimDnsPublisher.php` (D10: enabled check, zone and record lookup, add or
  update, 15-second budget, outcome).
- [X] T055 [US2] Create `app/Services/Domains/DomainRules.php` (D11 step 8: quota order, whole MiB,
  usage floors for changed limits, host name, template existence, tags through `DomainTagSet`,
  relay normalisation), reporting through `FieldViolations`.
- [X] T056 [US2] Create `app/Http/Requests/Api/V2/StoreDomainRequest.php`: `name` "required, string,
  1–255"; `templateId` "integer ≥ 1"; `description` "string, max 255"; `maxMailboxes`, `maxAliases`
  "integer 0–2147483647"; the three quotas "integer 1048576–9007199254740992, `WholeMebibytes`";
  relay options, `active`, `globalAddressList`, `restartGroupware` "boolean"; `tags` "array of
  strings"; `rateLimit` "null or {value: integer 1–2147483647, frame: second|minute|hour|day}";
  `dkimSelector` "string, max 255, `DkimSelector`"; `dkimKeySize` "in 1024, 2048, 3072, 4096".
- [X] T057 [US2] Create `app/Services/Domains/DomainCreationReport.php` and
  `app/Services/Domains/DomainCreator.php` (D8: template resolution, validation, ping, transaction
  with locks, `sender_acl` cleanup, insert, tags, `DOMAIN_MAP` with undo, pending audit record,
  follow-ups, audit completion).
- [X] T058 [US2] Add `store` to `DomainController`, create
  `app/Http/Resources/Api/V2/DomainCreatedResource.php` (`Domain` plus `creationReport`, `Location`
  and `ETag` headers), and register `POST /domains` with `restv2.auth:authenticated`,
  `restv2.csrf` and `restv2.pending`. This makes the US2 tests pass.

**Checkpoint**: US1 and US2 work; billing automation can create domains.

---

## Phase 5: User Story 3 — Edit a domain (P1)

**Goal**: administrators edit limits, quotas, relay options, the GAL flag, description, tags and
the active state; domain administrators edit what their flags allow; stale changes are refused.

**Independent test**: edit each attribute as an administrator and as a domain administrator with
and without `domain_desc`, try limits below usage, stale versions and forbidden attributes, and
compare rows and side effects with legacy.

### Tests for User Story 3 (write first, observe failing) ⚠️

- [X] T059 [P] [US3] Write `tests/Feature/Domains/UpdateDomainAdminTest.php` for `updateDomain`
  (FR-030, FR-031, FR-033, FR-034):
  - each editable attribute changes its column (quotas stored in MiB); omitted attributes keep
    their values; the response has the new `etag` and `ETag`;
  - AS-1: 8 mailboxes, `maxMailboxes: 5` → `422 belowInUse {inUse: 8}`; AS-2: 30 GiB allocated,
    `domainQuota` 20 GiB → `belowInUse {inUse: 32212254720}`; `maxMailboxQuota` below the largest
    mailbox quota and `maxAliases` below the aliases in use (alias-domain aliases included) →
    `belowInUse`; a domain already below a limit accepts an edit that does not change that limit;
  - `maxAliases: 0` is applied when no alias exists; a zero `maxMailboxQuota` is refused (Invalid
    test); `mustNotExceed` is checked on the merged values;
  - relay normalisation on edit; clearing `backupMx` while `relayAllRecipients` stays set has no
    effect and the response shows the effective values;
  - `description: ""` and `description: null` store the domain name;
  - read-only members equal to the current values (`name` also in Unicode form, `usage`, `etag`) are
    accepted; a different `name`, `createdAt`, `relayhost`, `rateLimit` or `usage` → `422
    immutable` naming each;
  - `{}` → `422 required` on `body`; a patch that changes nothing → `200`, unchanged `ETag`, one
    audit record with empty `changes` (FR-057), no rebuild;
  - after a change the groupware directory is rebuilt and memcached flushed, and dockerapi is never
    called, also for a `globalAddressList` change (FR-034); with `SKIP_SOGO=y` nothing is rebuilt.
- [X] T060 [P] [US3] Write `tests/Feature/Domains/UpdateDomainDomainAdminTest.php` (FR-032):
  - AS-6: without `domain_desc`, `globalAddressList` and `tags` are saved; `description` → `403
    attribute-not-permitted` with `attributes: [description]` and nothing changed; with
    `domain_desc` the description is saved;
  - AS-7: `maxMailboxes` or `active` → `403` naming them all; the whole representation with only
    `globalAddressList` changed → saved;
  - an inactive domain in scope is editable; a domain outside the scope, or whose assignment was
    removed since the last read, → `404`; setting `da_acl.domain_desc` through SQL lets the next
    request change the description (FR-002);
  - `attribute-not-permitted` wins over `immutable`; a read-only key of a domain administrator →
    `403 insufficient-key-scope`; a mailbox user → `403 forbidden`.
- [X] T061 [P] [US3] Write `tests/Feature/Domains/UpdateDomainVersionTest.php` (FR-041, FR-042,
  SC-006):
  - AS-3: two clients read; the first saves; the second with the old `If-Match` → `412`, nothing
    changed, a failure record with `versionOutdated`;
  - AS-4: `UPDATE domain SET active = 0` through SQL, then the old tag → `412`; a tag change through
    SQL → `412`;
  - AS-5: new `quota2` bytes, a new `RL_VALUE` or a new `domain_admins` row, then the old tag →
    saved;
  - no `If-Match` → saved; `If-Match: *` → saved.
- [X] T062 [P] [US3] Write `tests/Feature/Domains/UpdateDomainTagsTest.php` (FR-043, FR-044): AS-8
  (`a`, `b` → `b`, `c` gives exactly `b`, `c`); AS-9 (26 tags → `422 tooMany {max: 25}`; with
  `RESTV2_TAGGING_LIMIT=30` accepted); duplicates in other letter case stored once with the first
  spelling; a legacy domain with 30 tags can shrink to 28, keeps 30 when the set is unchanged, and
  is refused for a different set of 30; removing tags by sending the set without them (legacy
  `delete/domain/tag`); the `tags_domain` rows equal the new set.

### Implementation for User Story 3

- [X] T063 [P] [US3] Create `app/Services/Domains/DomainAttributeGuard.php` (FR-032, D11 step 6:
  changed attributes per role and `domain_desc`, collected into `attribute-not-permitted`).
- [X] T064 [P] [US3] Create `app/Http/Requests/Api/V2/UpdateDomainRequest.php` (merge patch: at least
  one member, else `required` on `body`; the types and bounds of `DomainPatch`; read-only members
  accepted with any type for the later `immutable` comparison).
- [X] T065 [US3] Create `app/Services/Domains/DomainUpdater.php` (D15: lock, `If-Match`, merge, guard,
  `immutable`, rules, normalisation, `UPDATE`, tag replacement, audit with old and new values,
  failure records, no-op detection, post-commit `SogoDirectory::rebuild()` unless deferred by the
  bulk caller).
- [X] T066 [US3] Add `update` to `DomainController` and register `PATCH /domains/{domain}`. This
  makes the US3 tests pass.

**Checkpoint**: US1–US3 work; packages can be upgraded and domains suspended.

---

## Phase 6: User Story 4 — Delete a domain (P2)

**Goal**: administrators delete empty domains with full cleanup, after an optional preview.

**Independent test**: delete domains with and without aliases, alias domains, footers, policies
and domain administrators; compare the remaining data with a legacy deletion plus the stated
deviations; try a domain with a mailbox or a resource.

### Tests for User Story 4 (write first, observe failing) ⚠️

- [X] T067 [P] [US4] Write `tests/Feature/Domains/DeleteDomainTest.php` for `deleteDomain` (FR-035,
  FR-036, FR-039, FR-040, FR-056):
  - AS-1: 2 aliases, an alias domain with 1 alias, no mailbox → `204` without body; the domain, the
    3 aliases and the alias domain are gone; neither name is in `DOMAIN_MAP`; `FakeDockerApiClient`
    executed `dovecot-mailcow` with `{cmd: maildir, task: cleanup, maildir: example.org}` after the
    commit; the groupware directory was rebuilt;
  - AS-2: 1 mailbox → `409 domain-not-empty {mailboxes: 1, resources: 0}`, nothing changed; 1
    resource → `{mailboxes: 0, resources: 1}`;
  - AS-4: unknown name → `404`; an alias domain name → `404`; AS-5: a domain administrator → `403
    forbidden`; a read-only key → `403 insufficient-key-scope`;
  - a stale `If-Match` → `412`; shared store down → `503`, nothing removed;
  - the maildir move fails or times out → still `204`, recorded in the audit record (FR-039).
- [X] T068 [P] [US4] Write `tests/Feature/Domains/DeleteDomainCleanupTest.php` (FR-036–FR-038,
  SC-003): seed every dependent row of data-model.md for domain `d` and alias domain `a`, plus the
  same rows for a control domain `e`:
  - afterwards no row of any table refers to `d`, `@d`, `a` or `@a` (scan `domain_admins`, `alias`,
    `sender_acl` including `send_as` of `a`'s aliases and `@a`, `quota2`, `quota2replica`,
    `pushover`, `spamalias`, `filterconf`, `mta_sts`, `bcc_maps` in both forms, `domain_wide_footer`,
    `tags_domain`, `alias_domain`), `RL_VALUE` and `DOMAIN_MAP` have neither name, the DKIM hashes of
    `d` are unchanged, and every row of `e` survives;
  - AS-3: a domain administrator managing only `d` is deleted with its `da_acl` row and its API key
    then answers `401 api-key-invalid`; one also managing `e` stays; an earlier orphaned domain
    administrator is deleted as legacy does (D18);
  - a domain created again with the name of `d` starts without a footer.
- [X] T069 [P] [US4] Write `tests/Feature/Domains/DomainDeletionPreviewTest.php` for
  `previewDomainDeletion` (FR-065, SC-011):
  - AS-6: 1 resource, 1 alias domain, 3 aliases, a footer and a domain administrator managing only
    this domain → `allowed false`, `blockers.resources {count: 1, addresses: [...]}`,
    `removals.aliasDomains [a]`, `removals.aliases.count 3`, `removals.footer true`,
    `domainAdminsToDelete [that admin]`, `dkimKeyKept` per Redis; nothing changes (row counts and
    Redis compared) and no audit record is written;
  - 150 aliases → `count 150` and the first 100 addresses sorted; `etag` equals `getDomain`'s;
  - unknown name → `404`; domain administrator → `403`; read-only administrator key → `200`;
  - SC-011: preview then delete without changes → the deletion's audit record lists exactly the
    previewed alias domains and domain administrators and the alias count drops by the previewed
    count; preview, then a mailbox is added, then delete → `409`.
- [X] T070 [P] [US4] Write `tests/Feature/Domains/DeleteDomainAuditTest.php` (FR-057, SC-007): one
  `domainDeletion` record with `removedAliasDomains`, `removedDomainAdmins`, `footerRemoved` and
  `maildirCleanup` completed to `done`, `failed` (with message) or `unknown`; a `409` refusal →
  failure record `conflict`; a `412` → `versionOutdated`.

### Implementation for User Story 4

- [X] T071 [P] [US4] Create `app/Services/AliasDomains/AliasDomainCleanup.php` (D19, shared with
  feature 004: `deleteRows(string $aliasDomain)`, `redisFields(string $aliasDomain)`).
- [X] T072 [P] [US4] Create `app/Services/DomainAdmins/OrphanedDomainAdminRemover.php` (D18:
  `candidates(): array`, `remove(): array`; feature 007 extends it).
- [X] T073 [US4] Create `app/Services/Domains/DomainDeletionPlan.php` and
  `app/Services/Domains/DomainDeletionPlanner.php` (D17 step 3, also used by the preview with the
  orphan candidates computed as if the domain's assignments were removed).
- [X] T074 [US4] Create `app/Services/Domains/DomainDeleter.php` (D17: ping, lock, `If-Match`,
  blockers, deletes, alias domain cleanup, orphan removal, Redis `MULTI` with undo, pending audit
  record, commit, maildir cleanup, audit completion, rebuild unless deferred).
- [X] T075 [US4] Add `destroy` to `DomainController`; create
  `app/Http/Resources/Api/V2/DomainDeletionPreviewResource.php` and
  `app/Http/Controllers/Api/V2/DomainDeletionPreviewController.php` (`show`); register
  `DELETE /domains/{domain}` and `GET /domains/{domain}/deletion-preview`. This makes the US4
  tests pass.

**Checkpoint**: the domain life cycle is complete.

---

## Phase 7: User Story 5 — Manage domain templates (P2)

**Goal**: administrators list, create, inspect, edit and delete domain templates; Default is
protected.

**Independent test**: create, list, edit and delete templates including Default, and confirm that
domains created afterwards use the right values and earlier domains are unaffected.

### Tests for User Story 5 (write first, observe failing) ⚠️

- [X] T076 [P] [US5] Write `tests/Feature/DomainTemplates/DomainTemplateCrudTest.php` (FR-045–FR-050):
  - list: only `type = 'domain'` rows, `sort` by `name`, `id`, `createdAt`, `modifiedAt`, `q` on
    the name, paging, `etag` per entry equal to `getDomainTemplate`'s `ETag`; a mailbox template ID
    → `404`;
  - AS-1: create "Small" with `maxMailboxes: 5` → the FR-046 defaults for the rest (selector from
    `RESTV2_DEFAULT_DKIM_SELECTOR`), `201`, `Location: /api/v2/domain-templates/<id>`; the stored
    `attributes` JSON has exactly the legacy keys with quotas in bytes; `maxAliases: 0` is kept;
  - AS-2: "small" at creation and a rename to "SMALL" → `409 domain-template-name-exists`;
  - AS-3: renaming or deleting Default → `409 domain-template-protected`, unchanged; Default with
    its current name and a new mailbox limit → saved;
  - AS-5: a patch with only `maxMailboxes` keeps every other value;
  - the domain rules (quota order, whole MiB, tags, key size, selector) → `422`; relay
    normalisation; `rateLimit: null` clears it; stale `If-Match` → `412`; `immutable` for `id`,
    `isDefault`, `createdAt`;
  - AS-4: a domain created from "Small" keeps its values after the template is edited and deleted;
  - AS-6: a domain administrator → `403 forbidden` on all five operations; a read-only
    administrator key reads but cannot write.
- [X] T077 [P] [US5] Write `tests/Feature/DomainTemplates/DomainTemplateLegacyRowsTest.php` (D22):
  templates stored by legacy (string numbers, `tags` as a JSON string, missing keys, `rl_value`
  `""` and `"10"`, `key_size` 512) are listed and read with the defaults; editing one writes the
  normalised legacy format; a template written by the API decodes with legacy's
  `json_decode($row['attributes'], true)` into the values legacy's add form reads
  (`def_quota_for_mbox / 1024 ** 2` integer MiB).
- [X] T078 [P] [US5] Write `tests/Feature/DomainTemplates/DomainTemplateAuditTest.php` (FR-057):
  creation, update and deletion records with target `{type: domainTemplate, id, name}`, `values` or
  `changes`; `409` and `412` refusals audited with `conflict` and `versionOutdated`.

### Implementation for User Story 5

- [X] T079 [US5] Create `app/Services/DomainTemplates/DomainTemplateService.php` (D22: list, find,
  create, update, delete, uniqueness under `FOR UPDATE`, Default protection, rules through
  `DomainRules`, audit).
- [X] T080 [US5] Create `app/Http/Requests/Api/V2/ListDomainTemplatesRequest.php`,
  `StoreDomainTemplateRequest.php` (`name` "required, string, 1–255"; the value rules of
  `StoreDomainRequest` without `templateId`, `description` and `restartGroupware`) and
  `UpdateDomainTemplateRequest.php` (merge patch), `app/Http/Resources/Api/V2/DomainTemplateResource.php`
  and `app/Http/Controllers/Api/V2/DomainTemplateController.php` (`index`, `show`, `store`,
  `update`, `destroy`); register the five routes. This makes the US5 tests pass.

**Checkpoint**: templates work and feed US2.

---

## Phase 8: User Story 6 — Apply an action to many domains at once (P2)

**Goal**: administrators activate, deactivate or delete up to 100 domains in one request with one
result per domain.

**Independent test**: apply each action to a selection with an unknown domain and a failing
domain; compare per-domain results, rows and audit records.

### Tests for User Story 6 (write first, observe failing) ⚠️

- [X] T081 [P] [US6] Write `tests/Feature/Domains/DomainBulkActionTest.php` for
  `applyDomainBulkAction` (FR-062, FR-063, SC-010):
  - AS-1: deactivate 50 active domains → 50 `done` results with `version`, all inactive, 50 audit
    records with the same `bulkRequestId`, one groupware rebuild (`FakeMemcachedFlusher` count 1);
  - AS-2: delete three empty domains and one with a mailbox → three `done`, one `refused` with a
    `domain-not-empty` problem, the fourth unchanged; one maildir cleanup per deleted domain;
  - AS-3: an unknown name → `notFound`, the others activated; an invalid name → `notFound`;
  - AS-4: a domain administrator, and a read-only administrator key → `403` for the whole request;
  - AS-5: 101 targets → `422 tooMany {max: 100}` on `targets` (the body exceeds the contract's
    `maxItems`, so the test name contains `Invalid`);
  - AS-6: one item with an outdated `version` → `outdated` with a `412` problem, unchanged; the
    others `done`;
  - the same domain named twice, also as ASCII and Unicode, → processed and reported once with the
    first spelling as `id`; results follow request order; `summary` counts each outcome;
  - activating an active domain → `done`, audited with empty `changes` (FR-057, SC-007); one
    deletion with the shared store down → `failed` with `temporary-failure`, the others proceed.

### Implementation for User Story 6

- [X] T082 [US6] Create `app/Services/Domains/DomainBulkActions.php` (D20), reusing `DomainUpdater`
  and `DomainDeleter` with the rebuild deferred to one call at the end.
- [X] T083 [US6] Create `app/Http/Requests/Api/V2/DomainBulkActionRequest.php` (`action` in
  activate, deactivate, delete; `targets` array, 1 to 100 items (`tooMany {max: 100}`), each
  `{id: string 1–255, version?: string}`) and
  `app/Http/Controllers/Api/V2/DomainBulkActionController.php` (`store`); register
  `POST /domains/bulk-actions` **before** the `/domains/{domain}` routes. This makes the US6 test
  pass.

**Checkpoint**: the legacy mass actions are available.

---

## Phase 9: User Story 7 — Manage the domain-wide footer (P3)

**Goal**: administrators and domain administrators read and replace a domain's footer.

**Independent test**: set, read and clear footers as both roles with valid and invalid exclusions;
confirm the stored row is the one legacy writes.

### Tests for User Story 7 (write first, observe failing) ⚠️

- [X] T084 [P] [US7] Write `tests/Feature/Domains/DomainFooterTest.php` for `getDomainFooter` and
  `replaceDomainFooter` (FR-051–FR-055):
  - AS-1: no footer → empty texts, `skipReplies false`, no exclusions, an `ETag`;
  - AS-2: a domain administrator without any flag saves HTML and plain text excluding one of the
    domain's mailboxes → the row has `mbox_exclude` `["alice@example.org"]`,
    `alias_domain_exclude` `[]`, `skip_replies` 1, exactly as legacy stores it;
  - an alias address in an alias domain and an alias domain name are accepted; `ALICE@Example.org`
    is stored lower-case; duplicates are stored once;
  - AS-3: an address of another domain, an unknown mailbox, another domain's alias domain or a
    non-address in `excludedAddresses` → `422` naming `excludedAddresses.<i>` or
    `excludedAliasDomains.<i>` (`notFound` or `invalidFormat {format: email}`), and the stored footer
    is unchanged;
  - AS-4: a stale `If-Match` → `412`; the tag of an absent footer equals the tag of a stored empty
    one;
  - AS-5: an alias domain name → `404` for both operations; out of scope → `404`; mailbox user →
    `403`; read-only key on `PUT` → `403 insufficient-key-scope`;
  - a text of 1,048,577 bytes (multi-byte characters under the character limit) → `tooLong {max:
    1048576, unit: bytes}`; empty texts without exclusions are saved; a stored exclusion that no
    longer belongs to the domain is shown on read and refused on the next save until removed.
- [X] T085 [P] [US7] Write `tests/Feature/Domains/DomainFooterAuditTest.php` (FR-057, SC-007): one
  `domainFooterUpdate` record per save, with `html` and `plain` as `{changed, oldSize, newSize}` and
  never their text; `skipReplies` and exclusions with old and new values; refusals audited.

### Implementation for User Story 7

- [X] T086 [US7] Create `app/Services/Domains/DomainFooterService.php` (D21),
  `app/Http/Requests/Api/V2/ReplaceDomainFooterRequest.php` (`html`, `plain` "required, string";
  `skipReplies` "required, boolean"; `excludedAddresses`, `excludedAliasDomains` "array of strings,
  max 255 each"), `app/Http/Resources/Api/V2/DomainFooterResource.php` and
  `app/Http/Controllers/Api/V2/DomainFooterController.php` (`show`, `update`); register
  `GET|PUT /domains/{domain}/footer`. This makes the US7 tests pass.

**Checkpoint**: footers work in both UIs.

---

## Phase 10: User Story 8 — Trace domain changes (P3)

**Goal**: administrators filter the audit log by target type and identifier.

**Independent test**: make each kind of domain, template and footer change, including refused ones,
and list the audit log filtered by target.

### Tests for User Story 8 (write first, observe failing) ⚠️

- [X] T087 [P] [US8] Write `tests/Feature/Audit/DomainAuditTrailTest.php` (FR-057, FR-058):
  - AS-1: a domain quota raise → the record shows the actor, the time, the client address and
    `changes.domainQuota {old, new}` in bytes;
  - `targetType=domain&targetId=EXAMPLE.org` and `targetId=münchen.de` (normalised) find the
    domain's records; `targetId` alone finds the domain and footer records;
    `targetType=domainTemplate&targetId=<id>` finds a template's records;
  - AS-2: a domain administrator's refused edit is listed with `failureReason attributeNotPermitted`
    and `details.problemType`;
  - AS-3: a domain administrator requesting the audit log → `403`; an unknown `targetType` → `422
    notInList`;
  - list, get, preview, capabilities and tag reads write no records.

### Implementation for User Story 8

- [X] T088 [US8] Create `database/migrations/2026_10_19_000001_add_target_index_to_restv2_audit_events.php`
  (index `(target_type, target_id, occurred_at)`), and extend 001's
  `app/Http/Requests/Api/V2/ListAuditEventsRequest.php` (`targetType` in the `AuditTarget` types,
  `targetId` max 255) and `app/Http/Controllers/Api/V2/AuditEventController.php` (filters of
  research D16, normalising domain names). This makes the US8 test pass.

**Checkpoint**: every user story works independently.

---

## Phase 11: Polish and cross-cutting concerns

- [ ] T089 [P] Write `tests/Feature/Security/DomainAuthorizationMatrixTest.php` (constitution VI,
  quality gate 5, FR-001, FR-003–FR-005, SC-004): a data provider over the 16 operations ×
  anonymous, pending sign-in, restricted session, administrator session, domain-administrator session with and without
  `domain_desc`, mailbox session, administrator read-write and read-only keys, domain-administrator
  read-write and read-only keys, asserting status and problem `type` from a table derived from
  plan.md and the contract. Object level: a foreign domain → `404` on every domain-scoped
  operation for domain administrators and their keys; an alias domain name → `404`; an unknown
  template ID → `404` for administrators and `403` for everyone else.
- [ ] T090 [P] Write `tests/Feature/Performance/DomainListLatencyTest.php` (group `performance`,
  SC-005, SC-010): seed 5,000 domains, 100,000 mailboxes, 50,000 aliases and `quota2` rows with
  multi-row inserts; the 95th percentile of 20 `listDomains` pages of 25 (default sort and
  `sort=storageUsed`) is under 1 s; a bulk deactivation of 100 domains finishes within 10 s and a
  bulk deletion of 100 empty domains within 60 s with the fakes.
- [ ] T091 [P] Write `tests/Feature/Security/DomainSecretsTest.php` (constitution VI): with the DNS
  publication on, a creation's responses, captured log lines and audit records contain neither the
  DKIM private key nor the ISPConfig password.
- [ ] T092 [P] Write `tests/Unit/Architecture/UpstreamWritesTest.php` (constitution IV): no file under
  `app/Http/` writes an upstream table (`DB::table(...)->insert|update|delete`, model `save`,
  `create`, `delete`), and only `AuditLogger::completeFollowUps` updates `restv2_audit_events`.
- [ ] T093 [P] Extend `database/seeders/DevelopmentSeeder.php` with the domains, template and mailbox of
  quickstart Part B, and bind the three fakes in `AppServiceProvider` when `fake_outbound` is on
  (local only, refused in other environments).
- [ ] T094 [P] Update `README.md` (the settings of research D24 with the FeldHost values, and the
  dockerapi, memcached and ISPConfig reachability needs) and `CHANGELOG.md` ("contract: the 16
  operations of feature 002 and the audit target filters").
- [ ] T095 Run `composer test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` and
  `composer contract:lint`; fix every finding until all are green and the coverage check lists the
  16 operations as covered (FR-060).
- [ ] T096 Run quickstart.md Parts A and B against the dev stack and confirm every expected output;
  correct quickstart.md where commands drifted.
- [ ] T097 Before the release that ships this feature, run quickstart.md Part C on a mailcow host
  (upstream `2026-09a` and the FeldHost fork): figure parity (SC-001), creation parity and mail
  delivery to a new domain's mailbox within one minute (SC-002, SC-008), coexistence with the legacy
  UI, deletion scan (SC-003), the ISPConfig publication and `SKIP_SOGO=y`. Record the results in the
  release notes.

---

## Dependencies and execution order

### Phase dependencies

- **Feature 001** must be implemented: the pipeline, `ListQuery`, `ETag`, `AuditLogger`,
  `MailcowConfig`, `IdentityService`, the factory and the contract tooling.
- **Setup (Phase 1)** depends on 001 only.
- **Foundational (Phase 2)** depends on Setup. The toolkit task comes first, then the tests, then
  the implementation. `DomainName`, the models and `SharedStore` come before the services that use
  them. **It blocks all user stories.**
- **User stories (Phases 3–10)** depend on Foundational:
  - US1 has no story dependency. Its `DomainPresenter` is used by US2, US3 and US6, so implement US1
    first.
  - US2 needs `DomainRules` (created in US2) and the template codec (Foundational); US5 reuses
    `DomainRules`.
  - US3 is independent of US2 (tests seed domains with the factory).
  - US4 is independent of US2 and US3.
  - US6 needs US3 (`DomainUpdater`) and US4 (`DomainDeleter`).
  - US7 and US8 need only Foundational and US1's routes and scope.
- **Polish (Phase 11)** depends on every story.

### Dependencies on and for other features

- Feature 001 provides everything listed at the top.
- Feature 004 reuses `AliasDomainCleanup`, `DomainName`, `DomainUsageCalculator`, `RateLimitStore`,
  `DkimKeyGenerator`, `DkimDnsPublisher`, `SogoDirectory` and the locking convention of research
  D27; its alias domain deletion must call `AliasDomainCleanup` rather than re-implement it.
- Feature 003 reuses `DomainUsageCalculator`, `SogoDirectory`, `DockerApiClient` and
  `attribute-not-permitted`; feature 006 extends `RateLimitStore`; feature 007 extends
  `OrphanedDomainAdminRemover` with its full cascade; feature 010 extends `DkimKeyGenerator`,
  `DkimDnsPublisher` and `IspconfigDnsClient` and reuses their settings.

### Within each story

- Tests first, observed failing; then services, then requests, resources and controllers, then
  routes. A story is done at its checkpoint.

### Parallel opportunities

- Setup: the deployment task and the contract tooling task in parallel.
- Foundational: all test tasks in parallel; the three client tasks, the models, `RateLimitStore`,
  `DomainVersion`, `DomainTagSet` and `DomainTemplateAttributes` in parallel.
- Each story's test tasks in parallel; after Foundational, US3, US4, US5, US7 and US8 can proceed in
  parallel with US2 if staffed.

## Parallel example: User Story 1

```bash
# Tests together (all [P]):
tests/Feature/Domains/ListDomainsTest.php
tests/Feature/Domains/GetDomainTest.php
tests/Feature/Domains/DomainUsageParityTest.php
tests/Feature/Domains/DomainCapabilitiesTest.php
tests/Feature/Domains/ListDomainTagsTest.php
# Then: DomainPresenter → DomainQuery → DomainController; capabilities and tags in parallel.
```

## Implementation strategy

### MVP first (User Story 1)

1. Phase 1 and Phase 2.
2. Phase 3 (US1): the domain list and detail screens and the usage figures that later features
   need.
3. Validate with quickstart Part B (list, inspect, tags, capabilities).

### Incremental delivery

1. US2 (create) and US3 (edit): billing automation and package changes.
2. US4 (delete and preview), US5 (templates), US6 (bulk).
3. US7 (footer), US8 (audit trail).
4. Polish: authorisation matrix, performance, secrets, architecture, docs.

Each story ends with its checkpoint green and the contract coverage growing towards all 16
operations.
