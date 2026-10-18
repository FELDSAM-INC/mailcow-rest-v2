---
description: "Task list for feature 009: routing and address rewriting"
---

# Tasks: Routing and Address Rewriting

**Input**: Design documents from `specs/009-routing-address-rewriting/`: [plan.md](./plan.md),
[spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md),
[quickstart.md](./quickstart.md). The contract is `api/` (46 operations, already authored and
lint-clean, see [contracts/README.md](./contracts/README.md)). Problem types are in
`docs/problems.md`.

**Tests**: These are mandatory (constitution VII, test-first). In every phase the test tasks come
first and must be observed **failing** before the implementation tasks of that phase start. Every
feature test runs on MariaDB, with the upstream `2026-09a` schema fixture, and on a real Redis
(database 15). `tests/TestCase.php` validates every request and response against the contract.
SMTP and DNS are faked behind `App\Clients\Smtp\SmtpProbe` and `App\Clients\Dns\DnsResolver`, and
the fakes assert the calls made.

**Organisation**: Phase 1 covers setup and Phase 2 the foundation that blocks every story. Phases 3
to 11 cover one user story each, in spec priority order (US1–US3 are P1, US4–US8 are P2, US9 is
P3). Phase 12 is polish.

**Commands**: every PHP, Composer and Node command runs in the dev container:
`docker compose run --rm app <command>`.

**Dependencies on earlier features** (features are implemented in number order, research R25).
Feature 009 starts only when these exist; the tasks below use them and never re-create them:

- **001**: `ProblemException`, `ProblemType`, `ETag::strong()`, `ListQuery`, `ApiFormRequest`, the
  middleware (`restv2.auth:authenticated`, `restv2.csrf`, `restv2.pending`, `EnforceKeyScope`),
  `Principal`, `PermissionFlag`, `IdentityService`, `AuditLogger`, `MailcowConfig`,
  `OpenApiContract`, `MailcowFactory`/`MailcowRows`, the models `Domain`, `Mailbox`,
  `DomainAdminAssignment` and `DaAcl`, and the `AuthorizationMatrixTest` pattern.
- **002**: `App\Support\Domain\DomainName`, `App\Services\Domains\DomainScope`, `DomainVersion`,
  `App\Clients\SharedStore\SharedStore`, the models `Relayhost`, `AliasDomain` and `Alias`, the
  `temporary-failure` problem type, the `targetType`/`targetId` filters of `listAuditEvents`, and
  the test fakes `FakeDockerApiClient` and `FakeMemcachedFlusher`.
- **003**: `App\Services\Mailboxes\MailboxAddress`, `MailboxScope`, `MailboxVersion`.
- **004** (at the latest): `App\Support\Bulk\BulkActionRunner`.
- **008** (or 002, whichever was first): `App\Services\Audit\RefusalAuditor`, the refusal hook in
  001's problem rendering driven by the route default `restv2.audit` (`docs/conventions.md`,
  "Capabilities, history and refusals").

## Format: `- [ ] [ID] [P?] [Story?] Description with file path`

- **[P]**: The task can run in parallel with the other [P] tasks of the same block. It touches
  different files and does not depend on unfinished tasks.
- **[USn]**: The user story the task belongs to. Setup, Foundational and Polish tasks have no story
  label.
- FR, SC and AS references point to `spec.md`. R references point to `research.md`.

---

## Phase 1: Setup (shared infrastructure)

**Purpose**: Settings, deployment adjustments, the legacy parity fixtures and the contract check.
Nothing here contains business logic.

- [X] T001 Verify that the prerequisite code of features 001 to 004 listed above exists (class
  names as in their plans). Record any rename in this file's dependency list before continuing. Do
  not create stand-ins: a missing prerequisite means the earlier feature is not finished.
- [X] T002 [P] Add the routing settings of R21 to `config/restv2.php` under `routing`:
  `test_sender` (`RESTV2_ROUTING_TEST_SENDER`, default `relay@example.org`), `test_recipient`
  (`RESTV2_ROUTING_TEST_RECIPIENT`, default `null@hosted.mailcow.de`), `test_step_timeout`
  (`RESTV2_ROUTING_TEST_STEP_TIMEOUT`, default 15), `test_total_timeout`
  (`RESTV2_ROUTING_TEST_TOTAL_TIMEOUT`, default 60), `lock_timeout` (10 s, not an env setting) and
  `spf_lookup_limit` (10, fixed). Invalid values (a non-address, a timeout below 1, a total below the
  step) fall back to the default with one logged warning. Document the four variables in
  `.env.example`.
- [X] T003 [P] Add an optional `mailpit` service (`axllent/mailpit`, pinned tag, MIT) to
  `compose.yaml` under the profile `routing`, with `MP_SMTP_AUTH_ACCEPT_ANY=1`,
  `MP_SMTP_AUTH_ALLOW_INSECURE=1` and port `8025:8025`, for quickstart Part B (R24).
- [X] T004 [P] Deployment (R13, R15): add `dns: ["${IPV4_NETWORK:-172.22.1}.254"]` and
  `dns_opt: ["timeout:2", "attempts:2"]` to the `restv2-mailcow` service in
  `deploy/docker-compose.override.yml`; raise `fastcgi_read_timeout`
  from `60s` to `90s` in `deploy/nginx/site.restv2.custom` (and in `docker/nginx/dev.conf` if it
  repeats the value).
- [X] T005 [P] Create `scripts/legacy/extract-routing-maps.sh <tag>`. It reads
  `data/Dockerfiles/postfix/postfix.sh` and `data/conf/rspamd/dynmaps/bcc.php` with
  `git -C "${MAILCOW_REPO:-./source_code}" show <tag>:…` (tags only) and writes the `query = …` of
  `mysql_sender_dependent_default_transport_maps.cf`, `mysql_sasl_passwd_maps_sender_dependent.cf`,
  `mysql_sasl_passwd_maps_transport_maps.cf`, `mysql_transport_maps.cf`, `mysql_mbr_access_maps.cf`,
  `mysql_tls_policy_override_maps.cf`, `mysql_recipient_canonical_maps.cf` and the two `bcc_maps`
  queries of `bcc.php` to `tests/fixtures/legacy/routing-maps/2026-09a/<name>.sql`, with a header
  (tag, source SHA-256). Run it for `2026-09a` and commit the files. They feed the parity tests.
- [X] T006 Run `composer contract:lint` and `composer contract:bundle`. Confirm that
  `build/contract/openapi.json` contains the 46 operations of plan.md ("Contract Operations") and
  that `tests/bin/check-contract-coverage.php` now reports them as uncovered (they become covered
  story by story). Confirm that `tests/Support/Contract/OpenApiContract.php` coerces query values
  by their schema type (`active=true` against `type: boolean`, repeated `domain=` against
  `type: array`); extend it and its unit test if it does not (FR-088).

---

## Phase 2: Foundational (blocking prerequisites)

**Purpose**: Next hop parsing, address literals, credential fingerprints, named locks, validation
rules, the DNS client, problem types, authorisation, audit and bulk plumbing, and the test support.
**No user story can start before this phase is complete.**

### Test support and foundation tests (write first, observe failing) ⚠️

- [X] T007 [P] Extend `database/factories/MailcowRows.php` and `tests/Support/Mailcow/MailcowFactory.php`
  with rows written exactly as legacy writes them (data-model.md):
  - `relayhost(host, username = '', password = '', active = 1)`;
  - `transport(destination, nexthop, username = '', password = '', isMxBased = 0, active = 1)`;
  - `bccMap(localDest, bccDest, domain, type = 'rcpt', active = 1)`;
  - `recipientMap(oldDest, newDest, active = 1)`;
  - `tlsPolicyMap(dest, policy, parameters = '', active = 1)`;
  - `forwardingHost(address, source, keepSpam = false)`, which writes `WHITELISTED_FWD_HOST` and
    `KEEP_SPAM` on the `mailcow` Redis connection;
  - `domainRelayhost(domain, string value)` and `mailboxRelayhost(username, ?string value)` (a
    `null` value removes `$.relayhost`, as in old rows);
  - reuse 002's and 004's `aliasDomain()` and `alias()`.

  Legacy variants for edge tests: credentials with escaped colons (`u\:x`), `domain.relayhost = '03'`,
  forwarding hosts in non-canonical form (`2001:0db8::0001`, `192.0.2.5/24`).
- [X] T008 [P] Create `tests/Support/Dns/FakeDnsResolver.php` (records per name and type, names that
  throw `DnsTemporaryFailure`, a call log, `assertQueried()` and `lookupCount()`). It implements the
  interface of T023. (`FakeSmtpProbe` comes with US4, T057.)
- [X] T009 [P] Write `tests/Unit/Routing/NextHopTest.php` (FR-019, R5) as a data table:
  `[smtp.example.net]:587` kept; `192.0.2.10:25` → `[192.0.2.10]:25`; `192.0.2.10` →
  `[192.0.2.10]`; `2001:db8::1` → `[2001:db8::1]`; `[ipv6:2001:DB8::1]:25` → lower-cased, kept;
  `postfix-relay` kept; `Bücher.Example:587` → `xn--bcher-kva.example:587` (FR-011);
  ` relay.example.net ` trimmed; `[smtp.example.net]:465` accepted (spec edge case: implicit TLS is
  only warned about). Refused with `invalidFormat` `{format: nextHop}`: `smtp:relay.example.net`,
  `relay..example.net`, `[relay.example.net]:0`, `relay.example.net:65536`, `[]`, `[a b]`,
  `host:+25`. `required` for `""`, `tooLong` `{max: 255}` for 256 characters. Also assert
  `bracketed()`, `host()`, `port()` and `cleanHost()` (the legacy `next_hop_clean`).
- [X] T010 [P] Write `tests/Unit/Routing/IpNetworkLiteralTest.php` (FR-077, R16): canonical forms
  (`2001:0DB8::0001` → `2001:db8::1`, `192.0.2.5/24` → `192.0.2.0/24`, `2001:db8::1/32` →
  `2001:db8::/32`, `203.0.113.7` without prefix); invalid literals (`999.1.1.1`,
  `192.0.2.0/33`, `2001:db8::/129`, `1.2.3`); classification (`cafe` and `example.org` are not
  literals; anything with `/` or `:` or only digits and dots is); canonical equality of a stored
  non-canonical entry; the sort key (IPv4 before IPv6, numeric, then prefix length).
- [X] T011 [P] Write `tests/Unit/Routing/CredentialFingerprintTest.php` (R2): the fingerprint is
  `hash_hmac('sha256', password, config('app.key'))`; it differs from `hash('sha256', password)`;
  it changes with the password and with the application key; an empty password has its own
  fingerprint; a version tag built with `ETag::strong()` over a fingerprint never contains the
  password or its plain hash.
- [X] T012 [P] Write `tests/Feature/Routing/Support/NamedLockTest.php` (R9): `NamedLock::run('restv2:routing:transports', fn)`
  takes `GET_LOCK`, runs the callback in a transaction and releases the lock, also when the callback
  throws. With the lock held by a second PDO connection and `routing.lock_timeout = 1`, the call
  throws a `ProblemException` of `temporary-failure` (503, `Retry-After`) after about 1 s and runs
  nothing. After the other connection releases it, the call succeeds.
- [X] T013 [P] Write `tests/Unit/Routing/RoutingRulesTest.php` against a test-only Form Request:
  `App\Rules\SmtpUsername` (trimmed, at most 255 → `tooLong` `{max: 255}`, a `:` →
  `invalidCharacter` `{characters: [":"]}`); `App\Rules\RoutingEmail` (`FILTER_VALIDATE_EMAIL` after
  UTS #46 normalisation of the domain, at most 255; otherwise `invalidFormat` `{format: email}`);
  `ApiFormRequest` maps the new codes `invalidCharacter` and `notResolvable` with their params, and
  `required` with `{because: password}`. Messages never contain the submitted value. Several invalid
  fields in one request are all named (SC-011).
- [X] T014 [P] Write `tests/Unit/Clients/NativeDnsResolverTest.php` (R15) with an injected lookup
  callable in place of `dns_get_record()`: `[]` → no records; `false` plus the warning "A temporary
  server error occurred." → `DnsTemporaryFailure`; `false` with another warning →
  `DnsTemporaryFailure`; TXT records with several character strings are concatenated; MX records
  are returned with their preference; no PHP warning reaches the log.
- [X] T015 [P] Write `tests/Feature/Routing/Support/RoutingPolicyTest.php` (FR-001 to FR-006,
  FR-029): every row of the FR-005 table for an administrator, a domain administrator with and
  without each of `domain_relayhost`, `mailbox_relayhost` and `bcc_maps` (rows in `da_acl`), and a
  mailbox user. The decision order is role (`403 forbidden`), then flag (`403
  permission-flag-required` with `requiredFlags`), then scope (`404`). The domain administrator's
  BCC scope covers their domains (active `domain_admins` row, existing domain) and those domains'
  alias domains, and nothing else. An inactive `domain_admins` row removes the scope on the next
  request (FR-002). A key principal acts as its owner.
- [X] T016 [P] Write `tests/Feature/Routing/Support/RoutingAuditTest.php` (FR-085, data-model.md
  audit table, R22): `RoutingAudit` writes one `restv2_audit_events` row per target with the event
  type, `target.type`, `target.id` and `target.name`; `password` appears only as `set`, `changed` or
  `removed`; `bulkRequestId` is set for bulk items; a failing audit insert rolls the audited change
  back; details keys outside the whitelist are dropped. Through 008's `RefusalAuditor`, on a test
  route declaring `restv2.audit` with each of this feature's target types, a refusal by an
  authenticated caller writes one failure row with `failureReason` `permissionDenied` (for
  `forbidden`, `permission-flag-required` with `details.requiredFlags`, `insufficient-key-scope` and
  `pending-action-required`, told apart by `details.problemType`), `notFound`, `validationFailed`
  (`details.errors` with rule codes, never values), `conflict`, `versionOutdated` or
  `temporaryFailure`; a `401` and a CSRF failure write nothing; the resolvers give the target id
  (numeric id, stored address, or the normalised domain name or mailbox address for assignments).
- [X] T017 [P] Write `tests/Unit/Routing/RoutingBulkActionRequestTest.php` (FR-090, R20):
  `RoutingBulkActionRequest` accepts each collection's actions only (`notInList` with `allowed`),
  1 to 100 targets (`tooMany` `{max: 100}` above, `required` for none), each target shaped like
  `BulkTarget` (`id` required string, optional `version`). Duplicate ids are kept for the runner to
  de-duplicate.

### Implementation of the foundation

- [X] T018 Create `app/Support/Routing/NextHop.php` (R5), using 002's `App\Support\Domain\DomainName`
  for the domain part and `filter_var(FILTER_VALIDATE_IP)` for literals, and the rule
  `app/Rules/NextHopRule.php` that reports `required`, `tooLong` and `invalidFormat`
  `{format: nextHop}`. This makes T009 pass.
- [X] T019 [P] Create `app/Support/Routing/IpNetworkLiteral.php` (`parse()`, `isLiteralLike()`,
  `canonical()`, `equals()`, `sortKey()`). This makes T010 pass.
- [X] T020 [P] Create `app/Support/Routing/CredentialFingerprint.php`. This makes T011 pass.
- [X] T021 [P] Create `app/Support/Database/NamedLock.php` (`run(string $name, Closure $fn)`,
  `GET_LOCK(?, config('restv2.routing.lock_timeout'))`, `RELEASE_LOCK` in `finally`, a timeout →
  `ProblemException(ProblemType::TemporaryFailure)` with `Retry-After: 5`). This makes T012 pass.
- [X] T022 [P] Create `app/Rules/SmtpUsername.php` and `app/Rules/RoutingEmail.php`, and register the
  codes `invalidCharacter` and `notResolvable` in `app/Http/Requests/Api/V2/ApiFormRequest.php`'s
  rule-code mapping. This makes T013 pass.
- [X] T023 [P] Create `app/Clients/Dns/DnsResolver.php` (interface: `txt`, `mx`, `a`, `aaaa`),
  `app/Clients/Dns/NativeDnsResolver.php` and `app/Clients/Dns/DnsTemporaryFailure.php`, and bind
  the interface in `app/Providers/AppServiceProvider.php`. This makes T014 pass.
- [X] T024 (FR-087) Add the cases `RoutingEntryExists` (409), `NextHopCredentialsDiffer` (409),
  `NextHopInterferes` (409) and `PermissionFlagRequired` (403) to `app/Support/Http/ProblemType.php`,
  with their URIs and English titles, plus extension builders (`field`, `conflictingId`; `nextHop`,
  `transportIds`; `requiredFlags`). Run 001's `tests/Unit/Support/ProblemTypeConsistencyTest.php`,
  which must pass against the four new sections of `docs/problems.md` and `KnownProblemType`.
- [X] T025 Create `app/Policies/RoutingPolicy.php` (FR-005 decisions, using 001's `Principal` and
  `PermissionFlag`, 002's `DomainScope` and 003's `MailboxScope`) and
  `app/Services/Routing/BccScope.php` (the SQL condition of data-model.md for a domain
  administrator), and register the policy. This makes T015 pass.
- [X] T026 Create `app/Services/Routing/RoutingAudit.php` (event, target and details builders for
  successes and per-item bulk outcomes), register the target resolvers of R22 with 008's
  `App\Services\Audit\RefusalAuditor` in `app/Providers/AppServiceProvider.php`, and extend the
  per-event `details` whitelist of `app/Services/Audit/AuditLogger.php` with the 21 event types of
  data-model.md (plus `requiredFlags` for failures). Every state-changing route registered by the
  later tasks declares `->defaults('restv2.audit', ['event' => …, 'target' => …])`. This makes T016
  pass.
- [X] T027 Create `app/Http/Requests/Api/V2/RoutingBulkActionRequest.php` (the action list comes
  from the route: relayhosts, transports, recipient maps and TLS policy maps `activate`,
  `deactivate`, `delete`; BCC maps also `switchToSender`, `switchToRecipient`; forwarding hosts
  `enableSpamFilter`, `disableSpamFilter`, `delete`) and the skeleton
  `app/Services/Routing/RoutingBulkActions.php`, which registers per-collection item handlers with
  `App\Support\Bulk\BulkActionRunner` (outcomes `done`, `notFound`, `refused`, `outdated`, `failed`;
  one transaction and audit record per item; `bulkRequestId` = request ID). This makes T017 pass.

**Checkpoint**: The foundation tests are green. User stories can start.

---

## Phase 3: User Story 1 — Manage relayhosts (Priority: P1) 🎯 MVP

**Goal**: Administrators list, search, filter, sort, inspect, create, edit, delete and bulk-change
relayhosts with write-only credentials; domain administrators with a relayhost flag read the
choices.

**Independent test**: Load relayhosts written like legacy (with and without credentials, assigned
to domains and mailboxes), run every relayhost operation, compare the stored rows with legacy's
for the same input, and scan every response for passwords.

### Tests for User Story 1 (write first, observe failing) ⚠️

- [X] T028 [P] [US1] Write `tests/Feature/Routing/Relayhosts/RelayhostListTest.php` for
  `listRelayhosts` and `getRelayhost` (FR-007, FR-012, FR-015 to FR-017):
  - each entry has `id`, `host`, `username` (`null` for `''`), `passwordSet`, `active`, `usage` and
    `etag`, and the `etag` equals the `ETag` header of `getRelayhost`;
  - AS-3: a relayhost assigned to 2 domains and 1 mailbox reports `{domains: 2, mailboxes: 1}`; a
    legacy `domain.relayhost = '03'` counts for relayhost 3; resource mailboxes are not counted;
  - `q` matches host and username case-insensitively; `active` and `inUse` filter; every sort key
    (`id`, `host`, `username`, `active`, `domainCount`, `mailboxCount`) in both orders, ties by `id`;
    `limit`, `offset` and `meta.total`; `limit=1001` → `outOfRange`;
  - AS-2: relayhosts with passwords `s3cret`, `pa:ss` and legacy `x\:y` never appear in any body,
    not even their first three characters;
  - a usage change does not change the `etag`; a password change written directly to the row does
    (FR-008, FR-089);
  - `getRelayhost` of an unknown id → `404`; the query count of a page does not grow with the page
    size (FR-007).
- [X] T029 [P] [US1] Write `tests/Feature/Routing/Relayhosts/RelayhostWriteTest.php` for
  `createRelayhost`, `updateRelayhost` and `deleteRelayhost` (FR-009, FR-013, FR-014, FR-018 to
  FR-021):
  - AS-1: `{"host": "[smtp.example.net]:587", "username": "acct", "password": "s3cret"}` → `201`,
    `Location: /api/v2/relayhosts/<id>`, `ETag`, `active: true`, `passwordSet: true`; the row holds
    `hostname`, `username = 'acct'`, `password = 's3cret'` and `active = 1`; a password `pa:ss` is
    stored verbatim (no `\:`); `active: false` is honoured;
  - AS-5: `192.0.2.10:25` stored as `[192.0.2.10]:25`; `smtp:relay.example.net` and
    `relay..example.net` → `422` naming `host`; AS-6: username `user:name` → `invalidCharacter`;
    username and password are trimmed; 256 characters → `tooLong`; a password without a username →
    `required` on `username` with `{because: password}`;
  - AS-4: deactivating without credentials keeps username and password; `password: ""` →
    `passwordSet: false`; `username: ""` without a password empties both; an omitted password is
    kept; legacy `u\:x` credentials stay byte-identical when neither is sent;
  - `id` or `usage` sent with a different value → `immutable`; with the current value → accepted;
    `{}` → `required` on `body`; a stale `If-Match` → `412` and nothing changes;
  - AS-7: deleting a relayhost assigned to a domain and a mailbox → `204`; the domain's `relayhost`
    is `'0'`, the mailbox's `$.relayhost` is `"0"`, every other column and attribute is unchanged,
    and the audit record lists both (`clearedDomains`, `clearedMailboxes`); a forced failure of the
    mailbox update leaves the relayhost and the domain unchanged (FR-083); an unknown id → `404`;
  - a change sent with an outdated `If-Match` never overwrites the newer row (SC-009);
  - every successful write writes one audit record with the right event type and `password: set |
    changed | removed`; no dockerapi or memcached call is made (002's fakes record nothing, FR-092).
- [X] T030 [P] [US1] Write `tests/Feature/Routing/Relayhosts/RelayhostChoicesAndUsageTest.php` for
  `listRelayhostChoices` and `listRelayhostUsage` (FR-022, FR-023):
  - an administrator and a domain administrator with `domain_relayhost`, or with only
    `mailbox_relayhost`, get `id`, `host` and `active` for every relayhost, sorted by `id` or
    `host`, searchable by host; no `username`, `passwordSet` or `usage` member is present;
  - AS-8: a domain administrator without either flag → `403 permission-flag-required` with
    `requiredFlags: ["domainRelayhost", "mailboxRelayhost"]`; `listRelayhosts` → `403 forbidden`;
  - usage: the 3 names of AS-3, `kind` filter, `q` on the name, default order domains first then by
    name, `sort=name`; an unknown relayhost → `404`.
- [X] T031 [P] [US1] Write `tests/Feature/Routing/Relayhosts/RelayhostBulkActionTest.php` for
  `applyRelayhostBulkAction` (FR-090, FR-091):
  - AS-9: three relayhosts and id `999` deactivated → three `done` with new `version`, one
    `notFound`; four audit records carrying the same `bulkRequestId`; one target with an outdated
    `version` → `outdated` and unchanged, the others done;
  - a target named twice is processed and reported once; `delete` clears the assignments as the
    single deletion does; 101 targets → `422` `tooMany` `{max: 100}` (test name contains
    `Invalid`); an unknown action → `notInList`;
  - a domain administrator → `403 forbidden` as a whole; an administrator's read-only key →
    `403 insufficient-key-scope`.
- [X] T032 [P] [US1] Write `tests/Feature/Routing/Relayhosts/RelayhostAuthorizationTest.php`: the
  nine relayhost operations × anonymous (`401`), mailbox user (`403 forbidden`), domain
  administrator session and read-write key (`403 forbidden`, except choices per T030), restricted
  session (`403 pending-action-required`), session without CSRF token on writes (`403
  csrf-token-mismatch`), administrator read-only key (reads `200`, writes and the test `403
  insufficient-key-scope`) and administrator read-write key (success). Each refused write by an
  authenticated caller leaves exactly one failure audit record; `401` and CSRF refusals leave none.

### Implementation for User Story 1

- [X] T033 [US1] Extend 002's `app/Models/Mailcow/Relayhost.php`: `$hidden = ['password']`, cast
  `active` to bool, no timestamps. Create `app/Services/Routing/RelayhostQuery.php` (R10): the list
  with the two derived count tables joined once and grouped by `r.id`, `q`, filters, sorts and
  paging through 001's `ListQuery`; the usage `UNION ALL`; the choices.
- [X] T034 [US1] Create `app/Services/Routing/RelayhostService.php`: `create`, `update` (R4 credential
  rules, `NextHop` normalisation, only changed columns written), `delete` (R12, one transaction with
  the cleared names), the version tag (`ETag::strong()` over `id|hostname|username|fingerprint|active`)
  and the bulk item handlers `activate`, `deactivate`, `delete`; every path records through
  `RoutingAudit` in the same transaction.
- [X] T035 [P] [US1] Create the Form Requests `app/Http/Requests/Api/V2/ListRelayhostsRequest.php`,
  `ListRelayhostChoicesRequest.php`, `ListRelayhostUsageRequest.php`, `StoreRelayhostRequest.php`
  and `UpdateRelayhostRequest.php`: `host` via `NextHopRule` ("trimmed, at most 255 characters"),
  `username` via `SmtpUsername` ("trimmed, at most 255, no colon"), `password` ("trimmed, at most
  255"; `""` removes), `active` boolean, `id`/`usage` `immutable` checks, `required` on `body` for an
  empty patch, the password-without-username rule.
- [X] T036 [P] [US1] Create `app/Http/Resources/Api/V2/RelayhostResource.php`,
  `RelayhostChoiceResource.php` and `RelayhostUsageEntryResource.php` (contract shapes, `etag`).
- [X] T037 [US1] Create `app/Http/Controllers/Api/V2/RelayhostController.php` (`index`, `show`,
  `store`, `update`, `destroy`), `RelayhostChoiceController.php`, `RelayhostUsageController.php` and
  `RelayhostBulkActionController.php`, and register in `routes/api.php`: the top-level helper
  `GET /relayhost-choices`; `POST /relayhosts/bulk-actions` before `/relayhosts/{relayhostId}`;
  `/relayhosts`, `/relayhosts/{relayhostId}` and `/relayhosts/{relayhostId}/usage` with
  `whereNumber('relayhostId')`, `restv2.auth:authenticated`, `restv2.csrf` on writes,
  `restv2.pending`, and `restv2.audit` defaults on the writes (`relayhostCreation`,
  `relayhostUpdate`, `relayhostDeletion`, bulk). This makes T028 to T032 pass.

**Checkpoint**: Relayhosts work end to end and pass the leak scan of their responses.

---

## Phase 4: User Story 2 — Assign relayhosts to domains and mailboxes (Priority: P1)

**Goal**: Read and replace the relayhost assignment of a domain and of a mailbox, with flags for
domain administrators, the effective relayhost and the credential source of a mailbox.

**Independent test**: Assign, change and clear relayhosts as an administrator and as domain
administrators with and without each flag, then run the extracted Postfix queries and confirm that
they choose the expected relayhost and credentials, as after the same change in the legacy UI.

### Tests for User Story 2 (write first, observe failing) ⚠️

- [X] T038 [P] [US2] Write `tests/Feature/Routing/RelayhostAssignments/DomainRelayhostAssignmentTest.php`
  for `getDomainRelayhostAssignment` and `replaceDomainRelayhostAssignment` (FR-024, FR-026 to
  FR-030):
  - reads: none (`relayhost: null`), active, inactive and dangling (`state: dangling`, `host: null`)
    references; the domain name in Unicode and upper case is normalised; an alias domain name →
    `404`;
  - AS-1: assigning relayhost 3 → `200` with the reference and a new `ETag`; only
    `domain.relayhost` changed (snapshot of every other column) and `modified` moved; 002's
    `getDomain` now shows relayhost 3 with a different `etag`;
  - AS-2: a domain administrator with `domain_relayhost` assigns to an own domain and it is saved;
    without the flag → `403 permission-flag-required` with `requiredFlags: ["domainRelayhost"]`,
    nothing changes, and a failure audit record with target `domainRelayhostAssignment`
    `example.org` and reason `permissionDenied` exists; a domain administrator with only
    `mailbox_relayhost` → the same `403`;
  - AS-5: an unknown relayhost id → `422` `notFound` on `relayhostId`; an inactive relayhost is
    accepted and shown `inactive`;
  - AS-6: a domain administrator with the flag reading or changing a foreign domain → `404`,
    identical to an unknown domain;
  - AS-7: `{"relayhostId": null}` stores `'0'`; a dangling assignment can be replaced and cleared;
  - AS-8: a stale `If-Match` → `412` and nothing changes.
- [X] T039 [P] [US2] Write `tests/Feature/Routing/RelayhostAssignments/MailboxRelayhostAssignmentTest.php`
  for `getMailboxRelayhostAssignment` and `replaceMailboxRelayhostAssignment` (FR-024 to FR-030,
  edge cases):
  - AS-3: a domain administrator with `mailbox_relayhost` assigns relayhost 5 to
    `user@example.org` in a domain with relayhost 3 → `relayhost` 5, `domainRelayhost` 3,
    `effectiveRelayhost` `{id: 5, source: mailbox}`, `credentialsRelayhost` `{id: 3, source:
    domain}`; the stored attribute is the JSON string `"5"`; `attribute_hash` and every other
    attribute are unchanged; 003's `getMailbox` shows a new `etag`;
  - AS-4: an inactive mailbox relayhost → `effectiveRelayhost` is the domain's; a domain relayhost
    without a username and an active mailbox relayhost with one → `credentialsRelayhost: null`; no
    relayhost at all → both `null`;
  - a mailbox without `$.relayhost` reads as none; a resource address and an alias-domain address →
    `404`; a domain administrator without the flag → `403 permission-flag-required` with
    `["mailboxRelayhost"]`; out of scope → `404`; a stale `If-Match` → `412`.
- [X] T040 [P] [US2] Write `tests/Feature/Routing/RelayhostAssignments/RelayhostAssignmentPostfixParityTest.php`
  (SC-002, FR-027): after assignments made through the API, run the extracted
  `mysql_sender_dependent_default_transport_maps.sql` and
  `mysql_sasl_passwd_maps_sender_dependent.sql` (T005) with `%s`, `%d` and `%u` substituted for the
  mailbox's address, a direct alias of the mailbox, the mailbox's address in an alias domain and
  another address of the domain. The chosen host and the `username:password` agree with
  `effectiveRelayhost` and `credentialsRelayhost` of the API, including the domain-first
  credential lookup and the alias-domain rule. A password containing `:` comes back verbatim.
- [X] T041 [P] [US2] Write `tests/Feature/Routing/RelayhostAssignments/RelayhostAssignmentAuthorizationTest.php`:
  the four operations × the callers of T032, plus domain administrators with each flag
  combination and read-write keys owned by them (reads need no flag; writes need the matching
  flag; out of scope `404`).

### Implementation for User Story 2

- [X] T042 [US2] Create `app/Services/Routing/RelayhostReference.php` (value object) and
  `app/Services/Routing/RelayhostAssignmentService.php` (R11): read a domain's or mailbox's
  assignment through 002's `DomainScope` and 003's `MailboxScope`/`MailboxAddress`, resolve
  references in one query, compute `effectiveRelayhost` and `credentialsRelayhost`, the version tags
  (`ETag::strong()` over `domain|relayhost` and `username|relayhost`), and `replace()` writing only
  `domain.relayhost` or `JSON_SET(attributes, '$.relayhost', ?)` with the audit record
  (`relayhostAssignmentUpdate`, target id the domain name or the address).
- [X] T043 [P] [US2] Create `app/Http/Requests/Api/V2/ReplaceRelayhostAssignmentRequest.php`
  (`relayhostId`: required, "integer ≥ 1 or null", unknown → `notFound`) and
  `app/Http/Resources/Api/V2/DomainRelayhostAssignmentResource.php` and
  `MailboxRelayhostAssignmentResource.php`.
- [X] T044 [US2] Create `app/Http/Controllers/Api/V2/DomainRelayhostAssignmentController.php` and
  `MailboxRelayhostAssignmentController.php` (`show`, `update`; flag check before the lookup), and
  register `GET|PUT /domains/{domain}/relayhost-assignment` and
  `GET|PUT /mailboxes/{mailbox}/relayhost-assignment` in `routes/api.php` with the parameter
  patterns 002 and 003 use, and `restv2.audit` defaults (`relayhostAssignmentUpdate`,
  `domainRelayhostAssignment` / `mailboxRelayhostAssignment`) on the `PUT` routes. This makes T038 to T041 pass.

**Checkpoint**: Assignments work, and the Postfix queries confirm the routing they produce.

---

## Phase 5: User Story 3 — Manage transport maps (Priority: P1)

**Goal**: Administrators manage transports with validated destinations, interference checks and
credentials shared per next hop.

**Independent test**: Create transports of each destination kind, with and without credentials
and with shared next hops, and compare the stored rows and the Postfix transport and credential
lookups with those for the same entries created in the legacy UI.

### Tests for User Story 3 (write first, observe failing) ⚠️

- [X] T045 [P] [US3] Write `tests/Feature/Routing/Transports/TransportListTest.php` for
  `listTransports` and `getTransport` (FR-031 to FR-033): every member including
  `nextHopSharedWith` (two transports on `[h]:25` and one on `[H]:25` share: each reports 2); `q` on
  destination, next hop and username; filters `active`, `mxBased`, `nextHop` (exact, ignoring
  case); every sort key in both orders; `etag` equal to `getTransport`'s `ETag`; no password in any
  body.
- [X] T046 [P] [US3] Write `tests/Feature/Routing/Transports/TransportWriteTest.php` for
  `createTransport`, `updateTransport` and `deleteTransport` (FR-034 to FR-036, FR-039, FR-040):
  - AS-1: created inactive by default; activating it works; `201` with `Location`, `ETag` and
    `meta.affectedTransports: []`;
  - AS-2: `.example.com`, `*` and `user@example.com` accepted; `..example.com` and `exa mple.com` →
    `invalidFormat` `{format: transportDestination}`; `Bücher.Example` stored as
    `xn--bcher-kva.example`;
  - AS-3: MX-based `.*\.google\.com` accepted and stored as given; `(` → `invalidRegex`
    (validated with MariaDB `REGEXP`);
  - AS-4: `Example.com` next to `example.com` → `409 routing-entry-exists` with `field:
    destination` and `conflictingId`; an MX-based and a non-MX-based transport cannot share a
    destination; an edit is checked excluding itself;
  - a bare IP next hop is bracketed; switching `mxBased` re-validates the destination; `id` or
    `nextHopSharedWith` with a different value → `immutable`; a stale `If-Match` → `412`;
  - deleting one transport leaves the credentials of the other transports of its next hop intact;
    unknown ids → `404`.
- [X] T047 [P] [US3] Write `tests/Feature/Routing/Transports/TransportCredentialSharingTest.php`
  (FR-038, R7):
  - AS-6: with A on next hop H and username `u1`, creating B on H with `u2` → `409
    next-hop-credentials-differ` with `nextHop` and `transportIds: [A]`; without credentials, B gets
    `u1` and A's password; with `u1` and a new password, A and B both store it and
    `meta.affectedTransports` names A with its new `etag`;
  - a creation on an existing next hop with only a password keeps the next hop's username and
    replaces every password;
  - AS-7: changing A's password changes B's row and B's `etag`, and the audit record of A lists B in
    `affectedTransportIds`;
  - AS-8: moving C (own credentials) to H without credentials makes C adopt H's; moving it to an
    unused next hop keeps its own; moving it with credentials applies them to every transport of H;
  - an empty username clears the password across the next hop; next hops differing only in letter
    case are the same next hop; transports of one next hop with different stored credentials (a
    manual database change) each report what is stored, and the next credential change through the
    API applies one set to all of them (spec edge case); all changes of one request are stored together (a forced failure of
    the audit insert leaves every row unchanged).
- [X] T048 [P] [US3] Write `tests/Feature/Routing/Transports/TransportInterferenceTest.php` (FR-037,
  R8): AS-5, an existing `relay.net` refuses `[relay.net]:587` and the reverse, ignoring letter
  case, with `409 next-hop-interferes` naming the interfering next hop and its transports; an edit
  that keeps its own next hop is not refused by itself.
- [X] T049 [P] [US3] Write `tests/Feature/Routing/Transports/TransportConcurrencyTest.php` (FR-084):
  with `restv2:routing:transports` held by a second connection and `routing.lock_timeout = 1`, a
  creation answers `503 temporary-failure` with `Retry-After` and stores nothing; after the other
  connection inserts the same destination and releases the lock, the creation answers `409`.
- [X] T050 [P] [US3] Write `tests/Feature/Routing/Transports/TransportPostfixParityTest.php` (SC-002,
  FR-040): run the extracted `mysql_transport_maps.sql` (active transport → `smtp_via_transport_maps:<nexthop>`,
  inactive → nothing), `mysql_sasl_passwd_maps_transport_maps.sql` (by next hop → the shared
  `username:password`, verbatim) and `mysql_mbr_access_maps.sql` (`'MX1.GOOGLE.COM' REGEXP
  destination` matches the stored pattern ignoring case).
- [X] T051 [P] [US3] Write `tests/Feature/Routing/Transports/TransportBulkActionAndAuthorizationTest.php`:
  bulk activate, deactivate and delete with `notFound`, `outdated`, duplicates and `bulkRequestId`;
  AS-9 and the caller matrix of T032 for the seven transport operations.

### Implementation for User Story 3

- [X] T052 [US3] Create `app/Models/Mailcow/Transport.php` (`$hidden = ['password']`, casts),
  `app/Support/Routing/TransportDestination.php` (kinds and normalisation of R6) with
  `app/Rules/TransportDestinationRule.php` (the MX-based check runs `SELECT '' REGEXP ?` and maps
  error 1139 to `invalidRegex`), and `app/Services/Routing/TransportQuery.php` (list with the
  `nexthop` count table, filters, sorts).
- [X] T053 [US3] Create `app/Services/Routing/TransportService.php`: uniqueness (R6), interference
  (R8), the credential algorithm (R7), writes under `NamedLock('restv2:routing:transports')` in one
  transaction with the audit record (`affectedTransportIds`), the version tag over the credential
  fingerprint, the list of affected transports for `meta`, and the bulk item handlers.
- [X] T054 [P] [US3] Create `app/Http/Requests/Api/V2/ListTransportsRequest.php`,
  `StoreTransportRequest.php` ("destination required, trimmed, at most 255"; "nextHop required";
  `mxBased` and `active` booleans, defaults `false`; credentials as for relayhosts) and
  `UpdateTransportRequest.php` (merge patch, `immutable` for `id` and `nextHopSharedWith`).
- [X] T055 [P] [US3] Create `app/Http/Resources/Api/V2/TransportResource.php` and
  `TransportReferenceResource.php`.
- [X] T056 [US3] Create `app/Http/Controllers/Api/V2/TransportController.php` and
  `TransportBulkActionController.php` (`store` and `update` answer `{data, meta.affectedTransports}`),
  and register `/transports`, `POST /transports/bulk-actions` and `/transports/{transportId}`
  (`whereNumber`) in `routes/api.php`, with `restv2.audit` defaults on the writes. This makes T045 to T051 pass.

**Checkpoint**: The three P1 stories are complete: the MVP of this feature.

---

## Phase 6: User Story 4 — Test a relayhost or a transport (Priority: P2)

**Goal**: A connection test with the stored credentials that reports a structured, redacted
result, and the routing capabilities read.

**Independent test**: Run tests against the scripted SMTP server (accepting, refusing the login,
refusing the sender, silent, unreachable) and through the API with the fakes, and scan the results
and audit records for credentials.

### Tests for User Story 4 (write first, observe failing) ⚠️

- [X] T057 [P] [US4] Create `tests/Support/Smtp/FakeSmtpProbe.php` (a queue of scripted
  `SmtpProbeResult`s, the recorded `SmtpProbeRequest`s, `assertProbed()` and `assertNotProbed()`,
  implementing the interface of T062), `tests/Support/Smtp/ScriptedSmtpServer.php` and
  `tests/Support/Smtp/scripted-smtp-server.php`. The CLI script listens on `127.0.0.1:<port>`, plays
  a JSON script of expected client lines and replies (including `STARTTLS` with a certificate
  passed by path), records everything it receives, and exits after one session. The helper starts
  it through Symfony `Process`, waits for the port, generates a self-signed certificate with
  `openssl_pkey_new()`/`openssl_csr_sign()` in the test's temporary directory, and returns the
  received lines.
- [X] T058 [P] [US4] Write `tests/Unit/Clients/StreamSmtpProbeTest.php` (FR-042, FR-043, R13, SC-007)
  against the scripted server:
  - acceptance with `AUTH PLAIN` when PLAIN and LOGIN are offered, with `AUTH LOGIN` when only LOGIN
    is offered; `accepted: true`, `step: message`, `login: succeeded`, `lastReply.code 250`;
  - STARTTLS offered → `tls: true`, `EHLO` repeated after the handshake, certificate not verified;
    not offered → `tls: false`;
  - the transcript contains `AUTH PLAIN [redacted]`, `334 [redacted]` and `[redacted]` lines, and
    neither the username, the password nor their base64 forms appear anywhere in the result object;
    the message content is one line `[message: N bytes]`; control characters become `?`; lines are
    cut to 1,000 characters and the transcript to 500 lines;
  - no username → `login: notConfigured` and no `AUTH`; `AUTH` not offered →
    `loginNotOffered`; only `CRAM-MD5` offered → `loginMechanismNotSupported`; `535` → step `login`,
    `login: failed`, `rejected`;
  - `550` to `MAIL FROM` → step `sender`; `550` to `RCPT TO` → step `recipient`; `554` after the data
    → step `message`, `accepted: false`; `EHLO` answered `502` → `HELO` fallback;
  - a server that never greets with the step timeout set to 1 s → step `greeting`, `timeout`, within
    about 1 s; a closed port → `connect`, `connectionRefused`; a server closing mid-dialogue →
    `connectionClosed`; a failing handshake → `tls`, `tlsFailed`; the overall deadline stops a slow
    server that answers each step just within the step timeout;
  - `EHLO` carries `MAILCOW_HOSTNAME`; message lines starting with `.` are dot-stuffed.
- [X] T059 [P] [US4] Write `tests/Feature/Routing/ConnectionTests/RelayhostConnectionTestTest.php`
  for `testRelayhostConnection` with `FakeSmtpProbe` and `FakeDnsResolver` (FR-041 to FR-045):
  - AS-1: a scripted accepted result is returned `200` with every member of the contract;
  - a host without brackets: MX records looked up, the most preferred used (equal preferences: the
    first returned), `hostSource: mailExchanger` and an `info` line; with brackets: no MX lookup; no
    MX or a failed MX lookup → the host itself; no port → 25;
  - the host without addresses → `200` with `step: resolve`, `nameNotResolved`, and the probe is not
    called; a temporary DNS failure on A/AAAA → `dnsTemporaryFailure`;
  - credentials reach the probe only when a username is stored; a legacy host `smtp:relay.example.net`
    is still tested the legacy way (brackets removed, trailing `:digits` as port);
  - AS-3: a failed connection is still `200`; AS-4: `sender: "not-an-address"` → `422`
    `invalidFormat` `{format: email}` on `sender`; omitted addresses take `routing.test_sender` and
    `routing.test_recipient` (changed in the test); AS-5: an administrator's read-only key →
    `403 insufficient-key-scope`; AS-6: an unknown relayhost → `404`; an inactive relayhost can be
    tested;
  - the probe receives the step and total timeouts from configuration; the relayhost row and every
    other table are unchanged afterwards (FR-044); one `relayhostTest` audit record holds `sender`,
    `recipient`, `accepted`, `step` and `failureReason`, and no transcript; domain administrators →
    `403 forbidden`.
- [X] T060 [P] [US4] Write `tests/Feature/Routing/ConnectionTests/TransportConnectionTestTest.php`
  for `testTransportConnection`: AS-2, next hop `partner.net` uses its most preferred MX and says so;
  `[mx.partner.net]:2525` connects without a lookup on port 2525; the shared credentials of the next
  hop are used; `transportTest` audit record; `404`, read-only key and domain administrator refusals.
- [X] T061 [P] [US4] Write `tests/Feature/Routing/Capabilities/RoutingCapabilitiesTest.php` for
  `getRoutingCapabilities` (FR-093, SC-012): AS-7, an administrator gets `bulkLimit 100`, page sizes 25 and
  1,000, the BCC limits, the configured default sender and recipient, the timeouts, every
  `maxLengths` member (255), the eight policies and the lookup limit 10; a changed setting shows on
  the next request; a domain administrator gets `administration: null`; a read-only key may read; a
  mailbox user → `403`; no secret appears.

### Implementation for User Story 4

- [X] T062 [US4] Create `app/Clients/Smtp/SmtpProbe.php` (interface), `SmtpProbeRequest.php`,
  `SmtpProbeResult.php`, `SmtpTranscript.php` (redaction when a line is recorded, caps, sanitising)
  and `StreamSmtpProbe.php` (the dialogue of R13 with per-step and overall deadlines,
  `set_time_limit(90)`), and bind the interface in `app/Providers/AppServiceProvider.php`. This
  makes T058 pass.
- [X] T063 [US4] Create `app/Services/Routing/ConnectionTestService.php`: load the relayhost or
  transport (with its password, read explicitly), parse the next hop (legacy fallback), MX lookup and
  name resolution through `DnsResolver`, call `SmtpProbe`, map the result, and write the audit record
  in its own transaction after the dialogue.
- [X] T064 [P] [US4] Create `app/Http/Requests/Api/V2/RoutingConnectionTestRequest.php` (`sender`
  and `recipient` optional, `RoutingEmail`) and
  `app/Http/Resources/Api/V2/RoutingConnectionTestResultResource.php`.
- [X] T065 [US4] Create `app/Http/Controllers/Api/V2/RelayhostConnectionTestController.php` and
  `TransportConnectionTestController.php`, and register `POST /relayhosts/{relayhostId}/connection-test`
  and `POST /transports/{transportId}/connection-test` in `routes/api.php` with `restv2.audit`
  defaults (`relayhostTest`, `transportTest`). This makes T059 and
  T060 pass.
- [X] T066 [US4] Create `app/Services/Routing/RoutingCapabilitiesService.php`,
  `app/Http/Resources/Api/V2/RoutingCapabilitiesResource.php` and
  `app/Http/Controllers/Api/V2/RoutingCapabilitiesController.php`, and register
  `GET /capabilities/routing` (operation `getRoutingCapabilities`, `restv2.auth:authenticated`;
  administrators and domain administrators) next to the other `/capabilities/{area}` routes. This makes T061 pass.

**Checkpoint**: Tests and capabilities work; no credential appears in any result.

---

## Phase 7: User Story 5 — Manage BCC maps (Priority: P2)

**Goal**: Administrators and domain administrators manage BCC maps within their scope, with the
local destination choices, the orphaned flag and bulk actions.

**Independent test**: Create, edit and delete maps of both types for every kind of local
destination, as an administrator and as domain administrators with and without the flag, then run
Rspamd's BCC queries and confirm that copies would be sent as for legacy maps.

### Tests for User Story 5 (write first, observe failing) ⚠️

- [X] T067 [P] [US5] Write `tests/Feature/Routing/BccMaps/BccMapListTest.php` for `listBccMaps` and
  `getBccMap` (FR-046 to FR-048, FR-056): every member with UTC `createdAt` and `modifiedAt` (`null`
  when never modified, FR-010; stored in mailcow's local time `TZ=Europe/Prague` and returned in
  UTC); `type` mapping `rcpt` → `recipient`; filters `type`, `active`, repeated
  `domain` (Unicode accepted) and `orphaned`; `q` on both destinations; every sort key; AS-11: a map
  left behind for a deleted domain is listed `orphaned: true` for an administrator and can be
  filtered and deleted; a domain administrator never sees it.
- [X] T068 [P] [US5] Write `tests/Feature/Routing/BccMaps/BccMapWriteTest.php` for `createBccMap`,
  `updateBccMap` and `deleteBccMap` (FR-049 to FR-055):
  - AS-1: `example.org` as a recipient map → stored `local_dest = '@example.org'`,
    `domain = 'example.org'`, `type = 'rcpt'`, `active = 1` (default `true`);
  - local destinations accepted: an alias domain (`@example.net`), a mailbox, a shared and a direct
    alias, and a mailbox's local part at an alias domain (`user@example.net`, a deviation); refused
    with `notFound`: a resource, a catch-all, an unknown address and an unknown domain; Unicode
    domains are stored in ASCII (FR-011); a BCC destination equal to the local destination is
    accepted (spec edge case);
  - AS-2: a domain administrator with `bcc_maps` creates a sender map for an own mailbox; without
    the flag, listing works and creating → `403 permission-flag-required` with `["bccMaps"]`;
  - AS-3: a foreign address and a nonexistent address give the identical `422` (same `errors`);
  - AS-4: a second recipient map for `user@example.org` → `409 routing-entry-exists`; a sender map
    for it is saved; AS-5: `a@example.net, b@example.net` → `tooMany` `{max: 1}`; `not-an-address`
    → `invalidFormat` `{format: email}`;
  - AS-6: switching a sender map to recipient when a recipient map exists → `409` with `field:
    type`; AS-9: a different `localDestination` on edit → `immutable`, the current value accepted;
  - deletion `204`; a stale `If-Match` → `412`; an administrator can edit and delete an orphaned
    map; a concurrent creation with the `bcc_maps` lock held answers `503 temporary-failure`.
- [X] T069 [P] [US5] Write `tests/Feature/Routing/BccMaps/BccMapScopeTest.php` (FR-001, FR-003): a
  domain administrator sees the maps of their domains and of those domains' alias domains, not of
  other domains; AS-7: a foreign map → `404` for get, patch and delete, identical to an unknown id;
  an inactive `domain_admins` row removes the access on the next request.
- [X] T070 [P] [US5] Write `tests/Feature/Routing/BccMaps/BccDestinationChoicesTest.php` for
  `listBccDestinationChoices` (FR-057): AS-8, a domain administrator with two domains, one alias
  domain and 300 mailboxes searching `anna` receives one page of matching values from the scope only;
  every kind appears with its stored value and domain; `deliversTo` lists the in-scope mailboxes of
  an alias and the mailbox of an alias-domain address; catch-alls, mailboxes' own aliases and
  resources never appear; filters `kind` and `domain`, sorts `kind` and `value`, paging and
  `meta.total`; every value is accepted by `createBccMap`.
- [X] T071 [P] [US5] Write `tests/Feature/Routing/BccMaps/BccMapBulkActionTest.php`
  (`applyBccMapBulkAction`): AS-10, three sender maps switched to recipient where one collides → two
  `done`, one `refused` with `routing-entry-exists` and unchanged; maps outside a domain
  administrator's scope → `notFound`; a domain administrator without `bcc_maps` → `403
  permission-flag-required` as a whole; activate, deactivate and delete; `outdated`.
- [X] T072 [P] [US5] Write `tests/Feature/Routing/BccMaps/BccMapRspamdParityTest.php` (SC-002,
  FR-058): after API writes, the two extracted `bcc.php` queries return the BCC destination for the
  address and for `@domain`, for the right type, only for active maps.
- [X] T073 [P] [US5] Write `tests/Feature/Routing/BccMaps/BccMapAuthorizationTest.php`: the seven BCC
  operations × the callers of T032, and domain administrators with and without `bcc_maps`, with
  sessions and keys.

### Implementation for User Story 5

- [X] T074 [US5] Create `app/Models/Mailcow/BccMap.php` (casts with 001's `MailcowDateTime`) and
  `app/Services/Routing/BccDestinationResolver.php` (R18: classification, scope, `notFound` for
  unknown or out-of-scope values, the stored value and the domain).
- [X] T075 [US5] Create `app/Services/Routing/BccMapQuery.php` (scope through `BccScope`, the orphaned
  expression, filters, sorts) and `app/Services/Routing/BccDestinationChoiceQuery.php` (the
  `UNION ALL` of R18 with the page's `deliversTo` in one more query).
- [X] T076 [US5] Create `app/Services/Routing/BccMapService.php`: create, edit (type, BCC
  destination, active; read-only local destination and domain), delete, uniqueness under
  `NamedLock('restv2:routing:bcc_maps')`, the version tag, audit records and the bulk item handlers
  (`activate`, `deactivate`, `switchToSender`, `switchToRecipient`, `delete`).
- [X] T077 [P] [US5] Create `app/Http/Requests/Api/V2/ListBccMapsRequest.php`,
  `ListBccDestinationChoicesRequest.php`, `StoreBccMapRequest.php` ("localDestination required, at
  most 255"; "type one of sender, recipient"; "bccDestination exactly one address, at most 255";
  `active` boolean default `true`) and `UpdateBccMapRequest.php`, and the resources
  `app/Http/Resources/Api/V2/BccMapResource.php` and `BccDestinationChoiceResource.php`.
- [X] T078 [US5] Create `app/Http/Controllers/Api/V2/BccMapController.php`,
  `BccMapBulkActionController.php` and `BccDestinationChoiceController.php`, and register the
  top-level helper `GET /bcc-destination-choices`, `POST /bcc-maps/bulk-actions`, `/bcc-maps` and
  `/bcc-maps/{bccMapId}` (`whereNumber`) in `routes/api.php`, with `restv2.audit` defaults on the
  writes. This makes T067 to T073 pass.

**Checkpoint**: BCC maps work for both roles within their scope.

---

## Phase 8: User Story 6 — Manage TLS policy maps (Priority: P2)

**Goal**: Administrators manage outbound TLS policy overrides with checked destinations, policies
and parameters.

**Independent test**: Create entries with each policy and destination form, and run the extracted
Postfix TLS policy query to confirm the stored policy is what the mail server applies.

### Tests for User Story 6 (write first, observe failing) ⚠️

- [X] T079 [P] [US6] Write `tests/Feature/Routing/TlsPolicyMaps/TlsPolicyMapTest.php` for the six
  TLS policy map operations (FR-065 to FR-073):
  - AS-1: `dane-only` for `example.org` stored; AS-2: `.example.org` and `[mail.example.org]:25`
    accepted, `*`, `.`, `@` and `example.org:587` → `invalidFormat` `{format:
    tlsPolicyDestination}`;
  - AS-3: `strict` → `notInList` with the eight `allowed` values; `Encrypt` stored as `encrypt`;
  - AS-4: `protocols=!SSLv2 ciphers=medium` accepted, `protocols` → `invalidFormat` `{format:
    tlsPolicyParameters}`; runs of whitespace stored as single spaces; more than 255 characters after
    joining → `tooLong`;
  - AS-5: `EXAMPLE.org` next to `example.org` on create and on edit → `409 routing-entry-exists`;
    a duplicate-key error from a concurrent insert is also answered `409`;
  - AS-6: changing the destination to `bücher.example` stores `xn--bcher-kva.example`;
  - list filters (repeated `policy`, `active`), `q` on destination and parameters, every sort key,
    `etag`; UTC times with `modifiedAt: null` before the first edit (FR-010); default `active:
    true`; `412`; `immutable` `id`;
  - the extracted `mysql_tls_policy_override_maps.sql` returns `encrypt ` for an active entry and
    nothing for an inactive one (SC-002).
- [X] T080 [P] [US6] Write `tests/Feature/Routing/TlsPolicyMaps/TlsPolicyMapBulkActionAndAuthorizationTest.php`:
  bulk activate, deactivate and delete with per-item outcomes, and the caller matrix of T032 for the
  six operations (domain administrators `403 forbidden`).

### Implementation for User Story 6

- [X] T081 [US6] Create `app/Models/Mailcow/TlsPolicyOverride.php`,
  `app/Rules/TlsPolicyDestinationRule.php` (via `NextHop` for bracketed forms),
  `app/Rules/TlsPolicyParametersRule.php` and `app/Services/Routing/TlsPolicyMapService.php`
  (normalisation of R19, duplicate check plus error 1062 mapping, audit, bulk handlers).
- [X] T082 [US6] Create `app/Http/Requests/Api/V2/ListTlsPolicyMapsRequest.php`,
  `StoreTlsPolicyMapRequest.php` ("destination required, at most 255"; "policy required, one of the
  eight, any letter case"; "parameters at most 255 after joining") and `UpdateTlsPolicyMapRequest.php`,
  `app/Http/Resources/Api/V2/TlsPolicyMapResource.php`,
  `app/Http/Controllers/Api/V2/TlsPolicyMapController.php` and `TlsPolicyMapBulkActionController.php`,
  and register `POST /tls-policy-maps/bulk-actions`, `/tls-policy-maps` and
  `/tls-policy-maps/{tlsPolicyMapId}` in `routes/api.php`, with `restv2.audit` defaults on the
  writes. This makes T079 and T080 pass.

---

## Phase 9: User Story 7 — Manage recipient maps (Priority: P2)

**Goal**: Administrators manage recipient rewriting with the same normalisation on creation and
edit.

**Independent test**: Create maps between addresses and between domains and run the extracted
Postfix recipient canonical query to confirm the rewriting.

### Tests for User Story 7 (write first, observe failing) ⚠️

- [X] T083 [P] [US7] Write `tests/Feature/Routing/RecipientMaps/RecipientMapTest.php` for the six
  recipient map operations (FR-059 to FR-064):
  - AS-1: `old.example` → `new.example` stored as `@old.example` → `@new.example`; AS-2:
    `info@example.org` → `sales@example.org`; AS-3: a value that is neither a domain nor an address →
    `invalidFormat` `{format: recipientMapDestination}` naming `originalDestination`; AS-4:
    `old.example` next to `@old.example` → `409 routing-entry-exists`; AS-5: editing the original
    destination to `Info@Example.org` stores `info@example.org`;
  - a new destination outside mailcow is accepted; an original destination that is also a mailbox is
    accepted; UTC times (FR-010); default `active: true`; values longer than 255
    once stored → `tooLong`; list filter, `q`, sorts, `etag`, `412`; a concurrent creation with the
    `recipient_maps` lock held → `503`;
  - the extracted `mysql_recipient_canonical_maps.sql` returns the new destination for active maps
    only (SC-002).
- [X] T084 [P] [US7] Write `tests/Feature/Routing/RecipientMaps/RecipientMapBulkActionAndAuthorizationTest.php`:
  bulk actions with per-item outcomes and the caller matrix (AS-6: domain administrators `403`).

### Implementation for User Story 7

- [X] T085 [US7] Create `app/Models/Mailcow/RecipientMap.php`,
  `app/Rules/RecipientMapDestinationRule.php` and `app/Services/Routing/RecipientMapService.php`
  (R19 normalisation, uniqueness under `NamedLock('restv2:routing:recipient_maps')`, audit, bulk
  handlers).
- [X] T086 [US7] Create `app/Http/Requests/Api/V2/ListRecipientMapsRequest.php`,
  `StoreRecipientMapRequest.php` ("both destinations required, at most 255 as stored") and
  `UpdateRecipientMapRequest.php`, `app/Http/Resources/Api/V2/RecipientMapResource.php`,
  `app/Http/Controllers/Api/V2/RecipientMapController.php` and `RecipientMapBulkActionController.php`,
  and register the routes in `routes/api.php`, with `restv2.audit` defaults on the writes. This
  makes T083 and T084 pass.

---

## Phase 10: User Story 8 — Manage forwarding hosts (Priority: P2)

**Goal**: Administrators add, list, change and remove forwarding hosts in mailcow's Redis, with
host names resolved through SPF, MX and A/AAAA.

**Independent test**: Add forwarding hosts by address, prefix and host name with known DNS records,
toggle the spam filter, remove them, and check `WHITELISTED_FWD_HOST` and `KEEP_SPAM` as Rspamd and
`forwardinghosts.php` read them.

### Tests for User Story 8 (write first, observe failing) ⚠️

- [X] T087 [P] [US8] Write `tests/Unit/Routing/SpfHostCollectorTest.php` (FR-076, R16) with
  `FakeDnsResolver`:
  - `ip4`, `ip6` with prefixes; `a`, `a:domain`, `a/24`, `a:domain/24//64`; `mx` and `mx:domain` with
    their exchangers' A and AAAA (at most 10 exchangers);
  - AS-2: a record permitting `198.51.100.0/24` and including one that permits `203.0.113.7` gives
    both;
  - `-` and `~` mechanisms end the evaluation of their record; `+` and `?` are stripped; `redirect`
    replaces the result; a self-`include` is skipped; `all`, `ptr` and `exists` are ignored; TXT
    strings are concatenated before parsing; non-SPF TXT records are ignored;
  - the 11th DNS-querying term is not evaluated and the hosts found so far are kept; a looping
    `include` stops at the limit;
  - no SPF address → MX hosts' addresses; none → the name's own A and AAAA; none → empty;
  - a temporary failure anywhere → `DnsTemporaryFailure`.
- [X] T088 [P] [US8] Write `tests/Feature/Routing/ForwardingHosts/ForwardingHostCreateTest.php` for
  `createForwardingHost` on the real Redis (FR-076 to FR-078, FR-081):
  - AS-1: `192.0.2.0/24` without `spamFilter` → `201`, `Location:
    /api/v2/forwarding-hosts?source=192.0.2.0%2F24`, `WHITELISTED_FWD_HOST[192.0.2.0/24] =
    192.0.2.0/24` and no `KEEP_SPAM` field; `spamFilter: false` → `KEEP_SPAM[...] = 1`;
  - AS-2: `forwarder.example` (fake DNS) → two entries `added` with source `forwarder.example`;
  - AS-3: `203.0.113.7` listed with source `other.example` → that entry `updated` with the new source
    and spam filter; a request whose addresses all exist → `200` with `updated` entries only;
  - AS-4: a name without records → `422` `notResolvable` on `host`; AS-5: `cafe` is resolved as a
    name, never stored as an address; AS-6: `192.0.2.5/24` stored as `192.0.2.0/24`; `999.1.1.1` →
    `invalidFormat` `{format: ipNetwork}`;
  - a stored non-canonical `2001:0db8::0001` is updated in its stored form when `2001:db8::1` is
    added; the source is the trimmed input; one audit record lists every address with its outcome.
- [X] T089 [P] [US8] Write `tests/Feature/Routing/ForwardingHosts/ForwardingHostManageTest.php` for
  `listForwardingHosts`, `getForwardingHost`, `updateForwardingHost` and `deleteForwardingHost`
  (FR-074, FR-075, FR-079, FR-080): the list from two `HGETALL` calls whatever the page size;
  natural address order (IPv4 before IPv6, numeric, then prefix length); filters `spamFilter` and
  `source` (exact); `q` on address and source; `etag`; reads by percent-encoded address for a
  prefix (`192.0.2.0%2F24`), an IPv6 address and a legacy non-canonical entry; an empty source
  listed and editable; AS-7: disabling the spam filter sets `KEEP_SPAM`; `address` or `source` with
  a different value → `immutable`; deleting removes both hash fields; `404` for an unknown address;
  `412`.
- [X] T090 [P] [US8] Write `tests/Feature/Routing/ForwardingHosts/ForwardingHostFailureTest.php`
  (FR-082, edge cases): AS-8, with the `mailcow` Redis connection pointed at a closed port, create,
  update and delete answer `503 temporary-failure` with `Retry-After`, nothing changes, and a
  failure audit record is written; a temporary DNS failure while resolving a name → `503`; a failure
  of the audit commit after `EXEC` restores the previous hash fields (compensation, R17); a `MULTI`
  with several addresses is applied completely or not at all.
- [X] T091 [P] [US8] Write `tests/Feature/Routing/ForwardingHosts/ForwardingHostBulkActionAndAuthorizationTest.php`:
  AS-9, five entries with the spam filter disabled in one request → five `done`; delete; `outdated`;
  ids are stored addresses (not percent-encoded); an unavailable Redis gives `failed` with
  `temporary-failure` per item; the caller matrix of T032 for the six operations.

### Implementation for User Story 8

- [X] T092 [US8] Create `app/Services/Routing/SpfHostCollector.php` and
  `app/Services/Routing/ForwardingHostResolver.php` (classification, canonical forms, SPF, MX, A/AAAA
  fallbacks, the lookup limit from `restv2.routing.spf_lookup_limit`). This makes T087 pass.
- [X] T093 [US8] Create `app/Services/Routing/ForwardingHostStore.php` on 002's
  `App\Clients\SharedStore\SharedStore` (`HGETALL` of both hashes, `MULTI`/`EXEC` writes, captured
  previous values and the compensation) and `app/Services/Routing/ForwardingHostService.php`
  (create with added/updated outcomes, edit, delete, the version tag, audit records written in the
  transaction whose callback runs `EXEC` last, the bulk item handlers).
- [X] T094 [P] [US8] Create `app/Http/Requests/Api/V2/ListForwardingHostsRequest.php`,
  `StoreForwardingHostRequest.php` ("host required, trimmed, at most 255"; `spamFilter` boolean
  default `true`) and `UpdateForwardingHostRequest.php`, and the resources
  `app/Http/Resources/Api/V2/ForwardingHostResource.php` and `ForwardingHostCreationResultResource.php`.
- [X] T095 [US8] Create `app/Http/Controllers/Api/V2/ForwardingHostController.php` (`store` answers
  `201` with `Location` when an address was added, else `200`) and
  `ForwardingHostBulkActionController.php`, and register `POST /forwarding-hosts/bulk-actions` before
  `/forwarding-hosts/{forwardingHostAddress}` with `->where('forwardingHostAddress', '.+')` in
  `routes/api.php`, with `restv2.audit` defaults on the writes. This makes T088 to T091 pass.

---

## Phase 11: User Story 9 — Trace routing changes (Priority: P3)

**Goal**: Administrators find every routing change, test and refused attempt in the audit log,
filtered by target.

**Independent test**: Make each kind of change, test and refusal of this feature, then list the
audit log filtered by target type and identifier.

### Tests for User Story 9 (write first, observe failing) ⚠️

- [X] T096 [P] [US9] Write `tests/Feature/Routing/Audit/RoutingAuditTrailTest.php` against 001's
  `listAuditEvents` with 002's `targetType` and `targetId` filters (FR-085, FR-086, SC-008):
  - AS-1: a relayhost password change is listed for `targetType=relayhost&targetId=<id>` with the
    actor, time, client address and `password: changed`, and no password;
  - AS-2: a domain administrator's assignment refused for the missing flag is listed for
    `targetId=example.org` with `failureReason: permissionDenied` and `requiredFlags`; a mailbox
    assignment is found by its address;
  - AS-3: a transport password change affecting two transports names both;
  - a test, a forwarding host creation (every address with its outcome), a bulk item (with
    `bulkRequestId`) and a BCC map change are each listed once per target;
  - AS-4: a domain administrator → `403 forbidden`;
  - every request of this feature's suite produced exactly one record per targeted entry (a counting
    helper run over the other tests' scenarios).

### Implementation for User Story 9

- [X] T097 [US9] Confirm that 002's `targetType` filter accepts the eight new `AuditTarget` values
  (it references `AuditTarget.yaml#/properties/type`) and that assignment records store the ASCII
  domain name or the normalised address as `target_id`. Fix only this feature's code
  (`RoutingAudit`) if T096 fails. This makes T096 pass.

---

## Phase 12: Polish and cross-cutting concerns

- [X] T098 [P] Write `tests/Feature/Routing/Security/RoutingAuthorizationMatrixTest.php`
  (constitution VI, quality gate 5, SC-004): a data provider over **all 46 operations** × anonymous,
  restricted session, administrator session, domain administrator session (no flags, each flag,
  all flags), mailbox session, administrator read-write and read-only keys, and domain
  administrator read-write and read-only keys, with the expected status and problem `type` from a
  table derived from plan.md ("Contract Operations") and FR-005. Object-level cases: a foreign BCC
  map, domain and mailbox → `404`.
- [X] T099 [P] Write `tests/Feature/Routing/Security/RoutingSecretLeakScanTest.php` (SC-003,
  FR-012): run every relayhost, transport and connection test flow with known passwords (`S3cret-Rh!`,
  `pa:ss`, `x\:y`), capture the `stderr_json` log, and assert that no password, no prefix of 3 or more
  characters of one, and no base64 form of one appears in any response body, audit row or log line.
- [X] T100 [P] Write `tests/Feature/Routing/LegacyCoexistenceTest.php` (FR-089, FR-092, SC-001,
  SC-005): a parity set of rows and hash fields written exactly as the legacy UI writes them (one per
  collection and per legacy variant of T007) is returned with the attributes the legacy screens show
  (mapping of data-model.md), passwords only as `passwordSet`; rows and hash fields changed directly
  appear in the next response with a new `etag`; no operation of this feature calls dockerapi, memcached or a service reload
  (002's fakes record nothing over the whole feature suite).
- [X] T101 [P] Write `tests/Feature/Routing/Performance/RoutingListLatencyTest.php` (SC-006, group
  `performance`): seed 10,000 rows per collection, 10,000 forwarding hosts in Redis and 10,000
  mailboxes assigned across relayhosts; over 20 requests per list, the 95th percentile of a page of
  25 with the default sort, a search and a count-based sort stays under 1 s.
- [X] T102 [P] Update `README.md` (the four `RESTV2_ROUTING_*` settings, the `dns` entry and the nginx
  timeout) and `CHANGELOG.md` ("contract: the 46 operations of feature 009", with the new problem
  types and rule codes) for mailcowui-ng to pin.
- [X] T103 Run `composer test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` and
  `composer contract:lint` in the dev container, and fix every finding until all are green and
  `build/contract/coverage.json` lists all 46 operations of this feature.
- [X] T104 Run quickstart.md Part A and Part B against the dev stack, with the `routing` profile,
  and confirm every expected output. Correct quickstart.md where commands drifted from the
  implementation.

---

## Dependencies and execution order

### Phase dependencies

- **Setup (Phase 1)** depends on features 001 to 004 being complete (T001). T002 to T005 run in
  parallel; T006 last.
- **Foundational (Phase 2)** depends on Setup. T007 and T008 come first (the tests use them), then
  the tests T009 to T017 in parallel, then T018 to T027. (T007 and T008 are test support, not
  production code.) T018 needs 002's `DomainName`; T025 needs
  T024; T027 needs T026. **It blocks all user stories.**
- **User stories**:
  - US1 (Phase 3) depends on Foundational only.
  - US2 (Phase 4) depends on US1's relayhost model and service (references and the deletion clean-up
    it tests), so it follows US1.
  - US3 (Phase 5) depends on Foundational only and can run in parallel with US1.
  - US4 (Phase 6) depends on US1 and US3 (it tests their entries).
  - US5, US6, US7 and US8 (Phases 7 to 10) depend on Foundational only and can run in parallel with
    each other and with US1 to US4.
  - US9 (Phase 11) exercises every other story, so it comes after them.
- **Polish (Phase 12)** depends on every story. T098 needs all 46 operations.

### Within each story

Tests come first, written and failing. Then models, support classes and rules, then services, then
Form Requests and resources, then controllers and routes. Stop at each checkpoint and run the
story's tests.

### Parallel opportunities

- Setup: T002, T003, T004 and T005.
- Foundational tests: T009 to T017 after T007 and T008. Foundational implementation: T019 to T023.
- After Phase 2: US1, US3, US5, US6, US7 and US8 in parallel with enough developers; then US2 and
  US4; then US9.
- Within a story, every test task marked [P], and the Form Request and resource tasks marked [P].

## Parallel example: User Story 3

```bash
# All US3 test tasks together (different files):
Task: "T045 TransportListTest in tests/Feature/Routing/Transports/TransportListTest.php"
Task: "T046 TransportWriteTest in tests/Feature/Routing/Transports/TransportWriteTest.php"
Task: "T047 TransportCredentialSharingTest"
Task: "T048 TransportInterferenceTest", "T049 TransportConcurrencyTest"
Task: "T050 TransportPostfixParityTest", "T051 TransportBulkActionAndAuthorizationTest"

# Then the independent building blocks:
Task: "T054 Transport Form Requests", "T055 Transport resources"
```

## Implementation strategy

### MVP first (User Stories 1 to 3)

1. Phase 1 Setup, then Phase 2 Foundational.
2. Phase 3 (US1): relayhosts with write-only credentials.
3. Phase 4 (US2) and Phase 5 (US3): assignments and transports.
4. **Stop and validate**: run the Postfix parity tests and quickstart Part B's US1 to US3 commands.

### Incremental delivery

1. US4 (tests and capabilities), so the web app can test before assigning.
2. US5 (BCC maps, used by domain administrators), then US6, US7 and US8.
3. US9 (audit trail), then Polish: authorisation matrix, leak scan, performance, README and
   CHANGELOG.

Each story ends with its checkpoint green and the contract coverage growing towards 46 of 46.

## Notes

- Never use `RefreshDatabase`, `migrate:fresh`, `migrate:refresh` or `db:wipe`: they drop mailcow's
  tables (001).
- Every upstream write goes through `app/Services/Routing/`: `RelayhostService` (`relayhosts`, and the
  clean-up of `domain.relayhost` and `mailbox.attributes`), `RelayhostAssignmentService`
  (`domain.relayhost`, `mailbox.attributes.relayhost`), `TransportService` (`transports`),
  `BccMapService` (`bcc_maps`), `RecipientMapService` (`recipient_maps`), `TlsPolicyMapService`
  (`tls_policy_override`) and `ForwardingHostService` (`WHITELISTED_FWD_HOST`, `KEEP_SPAM`).
  Controllers never write.
- Commit after each task or logical group, with short imperative subjects and no AI attribution.

## Implementation notes (2026-10-18)

Deviations and decisions made while implementing, with the reason. Names in tasks that differ from
what exists map as listed.

- **T001** (prerequisites, all on `main`, 003 complete since `1351d9e`): `ETag::strong()` is 001's
  `App\Support\Http\ETag::of()` (already an HMAC keyed with the app key, 003's note);
  `App\Support\Database\NamedLock` exists (003) as `run(list $names, Closure, int $timeout)`
  throwing `LockNotAcquired`; `ProblemType::PermissionFlagRequired` and
  `App\Exceptions\PermissionFlagRequiredException` exist (002, G34); the per-event detail
  whitelist is `App\Audit\AuditEventType::detailKeys()` (004's note), not `AuditLogger`;
  `RefusalAuditor`, `BulkActionRunner`, `DomainScope`, `DomainName`, `DomainVersion`,
  `SharedStore`, the models `Relayhost`, `AliasDomain`, `Alias`, and 003's `MailboxAddress`,
  `MailboxScope`, `MailboxVersion` exist under the names of the dependency list. The contract
  coverage scope is `tests/feature-registry.json` (feature `009-routing-address-rewriting`, all 46
  operations pending until their story lands). No 003 piece is missing: the mailbox assignment is
  009's own operation, and 003's mailbox change refuses `relayhostId` as a read-only member.
- **T002**: `config/restv2.php` keeps the raw values; `App\Services\Routing\RoutingSettings`
  validates them on every read and falls back to the default with one logged warning per process
  (configuration files cannot log).
- **T003**: `axllent/mailpit:v1.27.10`; its UI port is bound to `127.0.0.1` only.
- **T004**: the override already had the `dns` entry (001); `dns_opt` and the four
  `RESTV2_ROUTING_*` settings were added. `fastcgi_read_timeout` is already `900s` (raised by 003
  for renames), above the 90 s the plan needs, so only its comment changed; `docker/nginx/dev.conf`
  does not set it.
- **T005**: the fixtures are `tests/fixtures/legacy/routing-maps/2026-09a/*.sql` plus
  `bcc_maps_recipient.sql` and `bcc_maps_sender.sql` (bcc.php's two statements, `:local_dest`
  kept).
- **T006**: 001's contract validator already coerces boolean and repeated query parameters
  (002 T003 and its tests); nothing was changed.
- **T007**: the existing `relayhost()`, `bccMap()` and `recipientMap()` writers gained optional
  parameters after their existing ones (`relayhost()` takes a nullable ID and returns it);
  `forwardingHost()` lives on the test `MailcowFactory`, next to `redisHash()`, because it writes
  Redis.
- **T012, T021**: no second named-lock helper: `App\Services\Routing\RoutingLock` takes 003's
  `NamedLock`, runs the code in one transaction and maps a timeout to `503 temporary-failure` with
  `dependency: database` and `Retry-After: 5`. T012's test exercises it.
- **T024**: three new cases (`routing-entry-exists`, `next-hop-credentials-differ`,
  `next-hop-interferes`); the extension builders are `App\Services\Routing\RoutingConflict`.
- **T025**: `App\Policies\RoutingPolicy` is not registered with the Gate, because a Gate answer
  cannot carry the `permission-flag-required` problem; callers use `authorize($principal,
  $ability)`.
- **T026**: the resolvers are `App\Services\Routing\RoutingAuditTargets` (like 004's
  `AliasAuditTargets`), using 003's `RefusalAuditor::bulkRefusal()` for whole-request bulk
  refusals. Creation records carry the stored attributes in `values`, as 002–004 do, instead of
  top-level keys; a password appears only as `"set"`, `"changed"` or `"removed"`.
  `PermissionFlagRequiredException` now passes its flags as audit details, so that refusal records
  of this feature carry `requiredFlags` (other features' event types do not list the key, so their
  records are unchanged). The 21 event types are documented in `docs/audit-events.md` (G3).
- **T027**: the per-collection item handlers implement `App\Services\Routing\RoutingBulkHandler`;
  `RoutingBulkActions` runs them on the shared runner.
- **T029**: the atomicity of a deletion is shown with a failing audit insert (the last statement of
  its transaction), because a failure of the mailbox update cannot be provoked without changing an
  upstream table.
- **T030, G41**: relayhost choices carry `username` (`null` without one) for every caller who may
  read them, because the legacy selectors show `ID n: host (username)` to the domain
  administrators holding the matching flag; FR-023, the US1 scenario 8 and the parity table say so
  now. Password states and usage stay hidden.
- **T032**: the relayhost connection test joins the caller matrix with US4 (T059) and in T098.
- **T033**: usage counts compare `domain.relayhost` and `attributes.relayhost` numerically
  (`CAST(… AS UNSIGNED)`) in derived tables grouped by that number, so that `03` counts for
  relayhost 3 once. Writes that clear assignments first select the matching names and then update
  by primary key, because a numeric comparison in an `UPDATE … WHERE` would fail in strict mode on
  non-numeric legacy values.
- **T035**: the Form Requests report the credential rule and the read-only members through 003's
  `ApiFormRequest::memberViolations()`, so that every invalid field is named together (SC-011);
  the service checks again under its row lock. Besides `id` and `usage`, the representation's
  `passwordSet` and `etag` are accepted unchanged (so that a client can send back what it read),
  and any other unknown member is `immutable` (FR-009: a sent value is never ignored);
  `RelayhostPatch` documents both (G37).
- **T036, T037**: one response helper, `App\Http\Resources\Api\V2\RoutingResource`, wraps the
  representations the routing queries build (004's presenter pattern); one
  `RoutingBulkActionController` serves the bulk path of every collection, picking the item handler
  from the route's `restv2.audit` target. Entry IDs match `[1-9][0-9]{0,9}`, as 004's alias IDs.
- **T040, T050**: `tests/Support/Routing/LegacyRoutingMaps` runs the extracted Postfix queries as
  Postfix does (`%s`, `%u`, `%d` substituted with SQL quoting, `%%` turned into `%`) and bcc.php's
  statements with `:local_dest`.
- **T042–T044**: one `RelayhostAssignmentController` (`showDomain`, `updateDomain`, `showMailbox`,
  `updateMailbox`) instead of two; representations are built by `RelayhostAssignmentService`, so
  no separate resource classes. A replacement with the stored value writes nothing and is audited
  with empty `changes` (as 004's alias changes are). The relayhost of a replacement is read with a
  shared lock, so that a concurrent deletion cannot leave a dangling assignment behind.
- **T045, G35**: the `nextHop` filter is normalised like a stored next hop (bare IP bracketed,
  Unicode host to ASCII); the contract says so.
- **T049**: uniqueness, interference and next hop reads of transport writes are locking reads
  (`FOR UPDATE`), so that under the named lock they see rows committed by other sessions even
  inside an open transaction. The test cleans the other session's committed row up after its own
  transaction has been rolled back.
- **T052–T056**: `TransportResource` and `TransportReferenceResource` are not needed: the
  representations come from `TransportQuery` and `RoutingResource` wraps them with
  `meta.affectedTransports`. Credentials are compared exactly (in PHP, not with the
  case-insensitive column collation) to decide which other transports were affected. An edit
  always empties the password of an empty resulting username, as legacy's transport('edit')
  does. The interference check runs only when the next hop changes, so that legacy rows that
  already interfere stay editable otherwise.
- **T057**: `Tests\TestCase` binds `FakeDnsResolver` and `FakeSmtpProbe` for every test (like the
  dockerapi and memcached fakes), so that no test can resolve a real name or open an SMTP
  connection by accident; `RoutingTestHelpers::dns()` and `smtp()` return them. The scripted
  server prints its port on its first line and writes what it received to a JSON file.
- **T062**: the probe's result keeps the last reply of the dialogue; the best-effort `QUIT` and its
  reply appear in the transcript only. The probe connects to the addresses the service resolved
  (through `DnsResolver`), in order, with the host name as TLS peer name.
- **T063**: a next hop the grammar refuses (legacy) is tested the legacy way; unlike legacy, a port
  of an unbracketed `host:port` is used (FR-042; legacy dropped it and used 25). `host` in the
  result is `null` when the dialogue never connected.
- **T064–T066**: one `RoutingConnectionTestController` (`relayhost`, `transport`); the result and
  the capabilities are built by their services, so no resource classes.
- **G36**: `RoutingCapabilities.administration.maxLengths` gained `forwardingHost`, `testSender`
  and `testRecipient` (255 each).
- **T067, T079**: list sorts by `type` (BCC maps) and `policy` (TLS policy maps) compare the
  stored text (`CAST(… AS CHAR)`), so that they are alphabetical like the other sorts, not by the
  `ENUM` index.
- **T074–T078**: one `BccMapController` serves the maps and `listBccDestinationChoices`; the
  representations come from `BccMapQuery` and `BccDestinationChoiceQuery` (no resource classes).
  `deliversTo` is filled for the page with two more queries (the aliases' destinations, then the
  mailboxes in scope among them), independent of the page size. Every read-only member of the
  representation (`orphaned`, `createdAt`, `modifiedAt`, `etag` besides those of FR-054) may be sent
  back unchanged; `BccMapPatch` says so.
- **T081, T085**: the destination and parameter rules of recipient maps and TLS policy maps are one
  support class, `App\Support\Routing\MapDestination` (no separate rule classes); reads live in
  the two services (`TlsPolicyMapService`, `RecipientMapService`), so there are no query classes.
  TLS policy writes need no named lock: `dest` has an upstream unique index, and a duplicate-key
  error of a concurrent insert is answered `409`. A recipient map address longer than 255
  characters as stored is `tooLong` (PHP's address check alone would call it invalid).
- **T082, T086**: one controller per collection; the patches accept `createdAt`, `modifiedAt` and
  `etag` unchanged and refuse unknown members (`immutable`), as their schemas now say.
- **T080, T084**: the full caller matrix of these collections is
  `RoutingAuthorizationMatrixTest` (T098); the story files test bulk outcomes and the refusals of
  domain administrators and read-only keys.
- **T090, T093**: an unavailable shared store answers 002's `temporary-failure` with
  `dependency: sharedStore` and `Retry-After: 10` (the shared rendering of
  `SharedStoreUnavailable`), not 5. The compensation snapshots the touched fields before the write
  and restores them whenever the change fails after the Redis transaction was attempted (also when
  the audit insert fails before it: restoring unchanged fields is harmless).
  `ForwardingHostStore` builds its own `MULTI`/`EXEC` over both hashes on `SharedStore::run()`,
  because 002's `setFields()` and `deleteFields()` each cover one operation; the test simulates a
  failed commit with a store whose `apply()` throws after writing.
- **T091**: a bulk target is resolved while the store may be down; it then counts as existing and
  its item fails with `temporary-failure`, as the contract's per-item `failed` requires.
- **T092**: a host name that is not a valid domain name is `invalidFormat` with
  `format: domainName` (the forwarding host input is either a literal or a name).
- **T094, T095**: no resource classes (the service builds the representations). The patch accepts
  `etag` unchanged besides `address` and `source`, as its schema now says.
- **T096, T097**: the trail test passed on its first run: the target IDs of the success records
  (services) and of the refusal records (`RoutingAuditTargets`) already match 002's `targetType`
  and `targetId` filters, so no code changed. The audit-count check covers one scenario per
  collection (success and refusal) and a bulk request instead of a helper over every other test.
- **T098**: 46 operations × 13 callers (600 cases with the coverage check), plus the foreign
  objects of a domain administrator holding every flag.
- **T099**: the connection test of the scan runs the real `StreamSmtpProbe` against the scripted
  server (`AUTH LOGIN`), so that the base64 forms are checked end to end; three-character prefixes
  of the longer passwords are scanned too.
- **T101**: runs in the `performance` group, which `composer test` now excludes (`composer
  test:performance` runs it alone). The forwarding host list computes each entry's sort key once
  and presents only the page; it took about 2 s for 10,000 entries before that. On 2026-10-18 every
  list stayed under 1 s at p95 with 10,000 rows (and 10,000 mailboxes and 1,000 domains assigned
  across the relayhosts).
- **T103**: gates green on 2026-10-18 (see the commit that marks this task): `composer test`,
  `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, the contract lint. The Dockerfile is
  unchanged, so no production image build was needed.
- **T104**: Part A and Part B ran on 2026-10-18 (quickstart.md records how: API keys, `php artisan
  serve` and mailpit by container name, because a parallel worktree publishes no host ports);
  quickstart.md was corrected (the timing tests' command, the coverage check, `tls: false` with
  mailpit, the choices with usernames). Part C (a mailcow host) is open.
- **docs**: `temporary-failure` documents the `dependency` values `dns` and `database`
  (docs/problems.md, `RoutingTemporaryFailure`); data-model.md's audit table names the `values`
  member of creation records.

- **Settings after main's empty-environment fix**: the four `RESTV2_ROUTING_TEST_*` settings are
  read through `App\Support\Config\Env::get` (an empty value means unset, docs/conventions.md
  "Configuration") and are listed in `tests/Unit/Config/EmptyEnvironmentSettingsTest.php`.
