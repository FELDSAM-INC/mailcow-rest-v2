---
description: "Task list for feature 010: DKIM, MTA-STS and DNS diagnostics"
---

# Tasks: DKIM, MTA-STS and DNS Diagnostics

**Input**: Design documents from `specs/010-dkim-mta-sts-dns/`: [plan.md](./plan.md),
[spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md),
[quickstart.md](./quickstart.md). The contract is `api/modules/{dkim,mta-sts,dns-diagnostics}/`
(already authored and lint-clean). Problem types are in `docs/problems.md`.

**Tests**: Mandatory (constitution VII). In every phase the test tasks come first and must be
observed **failing** before the implementation tasks of that phase start. Feature tests run on
MariaDB with the `2026-09a` schema fixture and on a real Redis (database 15); every request and
response is validated against the contract by `tests/TestCase.php` (001). ISPConfig, the DNS
resolver, the SMTP certificate probe and the echo service are faked; the fakes assert the calls.
DKIM keys in tests are 1,024 bits unless the size is asserted (002 D26), or come from
`tests/Support/Dkim/DkimFixtures.php`.

**Order of delivery**: features are implemented in number order, **except Phase 2**, which features
002 (domain creation, FR-027 there) and 004 (alias domain creation, FR-042 there) need. **Phase 2 is
implemented together with feature 002, before 002's domain creation tasks.** Where 002's tasks
already created a class named here (`DkimKeyGenerator`, `DkimDnsPublisher`, `DkimDnsOutcome`,
`IspconfigDnsClient`, `JsonIspconfigDnsClient`, `FakeIspconfigDnsClient`, `App\Rules\DkimSelector`,
the `RESTV2_DEFAULT_DKIM_SELECTOR`, `RESTV2_DKIM_DNS_PUBLICATION` and `RESTV2_ISPCONFIG_*`
settings), the task extends it instead of creating it. Phases 3 onwards run when feature 010 is
implemented.

**Commands**: every PHP, Composer and Node command runs in the dev container:
`docker compose run --rm app <command>`.

## Format: `- [ ] [ID] [P?] [Story?] Description with file path`

- **[P]**: can run in parallel with the other [P] tasks of the same block (different files, no
  dependency on unfinished tasks).
- **[USn]**: the user story of spec.md. Setup, Foundational and Polish tasks have no story label.
- FR, SC and AS references point to `spec.md`; R references point to `research.md`.

## Role and key matrix used by every authorisation test

Each authorisation test runs its operation, through a data provider, as: administrator session,
administrator read-write key, administrator read-only key, domain administrator session (own
domain, own alias domain, foreign domain, unused key), domain administrator read-write key,
domain administrator read-only key, mailbox user session, no credentials, and a restricted session
(001 FR-014). Expected: the status of spec FR-005 and FR-004; `404` (not `403`) for every object
outside the scope; `403 insufficient-key-scope` for read-only keys on unsafe methods;
`403 pending-action-required` for the restricted session; `401 unauthenticated` without
credentials; and, for unsafe methods, `403 csrf-token-mismatch` for a session request without
`X-CSRF-Token`. On state-changing operations and the export, every refusal of an authenticated
caller (read-only key and restricted session included) writes exactly one failure audit record with
the shared `AuditFailureReason` value of research R22; `401` and CSRF refusals write none.

---

## Phase 1: Setup

**Purpose**: dependencies, settings and fixtures on top of 001's stack. No business logic.

- [X] T001 Run `composer require mlocati/spf-lib:^3.3` and check in `composer.lock` that
  `mlocati/spf-lib`, `mlocati/ip-lib` and `mlocati/idna` are MIT-licensed (R25). DNS and SMTP use
  feature 009's clients; no DNS library is added. Record the justification lines of R25 in the `composer.json` `extra`
  block that 001 uses for R28 (or in `docs/` if 001 keeps it there).
- [X] T002 [P] Add `bind-tools` (for `named-checkzone`) to the `dev` stage of `docker/php/Dockerfile`
  (R21, R25). The `production` stage is unchanged.
- [X] T003 [P] Extend `config/restv2.php` with the settings of research R24, each with its default:
  `dkim.default_selector` (`RESTV2_DEFAULT_DKIM_SELECTOR`, `dkim`, if 002 has not added it),
  `dkim.allowed_key_sizes` `[1024, 2048, 3072, 4096]`, `dkim.default_key_size` `2048`,
  `dkim.private_key_max_bytes` `16384`, `dkim.import_bits` `[1024, 8192]`,
  `dkim.dns_publication` (`RESTV2_DKIM_DNS_PUBLICATION`, `n`), `ispconfig.url`, `ispconfig.username`,
  `ispconfig.password`, `ispconfig.dns_server_id` (`1`), `ispconfig.verify_tls` (`y`),
  `ispconfig.timeout` (`RESTV2_ISPCONFIG_TIMEOUT`, `15`), `dns.lookup_timeout` (`5`), `dns.run_timeout`
  (`30`), `dns.runs_per_minute` (`10`), `dns.facts_ttl` (`600`), `dns.public_ipv4`,
  `dns.public_ipv6` (`none` allowed), `dns.echo_url_ipv4` (`http://ip4.mailcow.email`),
  `dns.echo_url_ipv6` (`http://ip6.mailcow.email`), `dns.suggested_spf_record` (empty),
  `mta_sts.max_age_max` `31557600`, `mta_sts.mx_max_length` `255`, and the `mailcow.conf` port
  variables `IMAP_PORT`, `IMAPS_PORT`, `POP_PORT`, `POPS_PORT`, `SMTPS_PORT`, `SUBMISSION_PORT`,
  `SIEVE_PORT` (defaults 143, 993, 110, 995, 465, 587, 4190, last `:`-separated part). Add the dev
  values to `.env.example` and the test values to `.env.testing` (`RESTV2_PUBLIC_IPV4=`,
  `TZ=Europe/Prague` from 001).
- [X] T004 [P] Extend `deploy/docker-compose.override.yml` for the API service (the `dns:` server
  `${IPV4_NETWORK:-172.22.1}.254` and `dns_opt: ["timeout:2", "attempts:2"]` come from 009 and
  satisfy FR-046 and FR-048; keep them): the environment variables `IPV4_NETWORK`,
  `IMAP_PORT`, `IMAPS_PORT`, `POP_PORT`, `POPS_PORT`, `SMTPS_PORT`, `SUBMISSION_PORT`, `SIEVE_PORT`,
  and commented examples of every `RESTV2_*` setting of R24 with its default and FeldHost value.
- [X] T005 Write `tests/Unit/Support/MailcowConfigDkimTest.php` (fails first): with
  `tests/fixtures/mailcow-inc/vars.local.inc.php` setting `$SHOW_DKIM_PRIV_KEYS = true;` and an
  `$autodiscover_config['imap']['port'] = 10993`, `MailcowConfig` returns them; `RESTV2_SHOW_DKIM_PRIV_KEYS=false`
  wins; without the mount the defaults are `false` and ports derived from the port variables; the
  `sieve` entry falls back to `SIEVE_PORT` (legacy `dns_diagnostics.php`).
- [X] T006 Extend 001's `resources/bin/export-mailcow-vars.php` and
  `app/Services/Config/MailcowConfig.php` to export `SHOW_DKIM_PRIV_KEYS` and the `imap`, `pop3`,
  `smtp` and `sieve` entries (`server`, `port`, `tlsport`) of `$autodiscover_config`, with the R14
  precedence (`RESTV2_SHOW_DKIM_PRIV_KEYS`, `RESTV2_AUTODISCOVER_CONFIG` as JSON). Create
  `app/Services/Mailcow/AutodiscoverConfig.php` (SMTP host, IMAP/IMAPS/POP3/POP3S/submission/SMTPS/
  Sieve ports, upstream defaults). T005 passes.
- [X] T007 [P] Create `scripts/legacy/extract-dkim-keygen.sh <tag>`: it runs, in the tag's
  `php-fpm-mailcow` image, the key-handling lines of `dkim('add')` and `dkim('import')` from
  `git show <tag>:data/web/inc/functions.dkim.inc.php` (key pair generation, public key
  extraction, private key export and trim) and writes `tests/fixtures/legacy/dkim/generated-2048.pem`,
  `generated-2048.pub` (legacy's public key text) and `README.md` (tag, source SHA-256, GPL-3.0
  notice). Run it for `2026-09a` and commit the fixtures.
- [X] T008 [P] Create `tests/Support/Dkim/DkimFixtures.php`: pre-generated PEM texts for RSA 1024,
  2048, 3072, 4096 and 8192 bits (PKCS #1 and PKCS #8), a 512-bit and a 9216-bit RSA key, an
  encrypted PKCS #8 key, a PKCS #1 key with `Proc-Type: 4,ENCRYPTED`, an EC P-256 key, a public key,
  a certificate, two concatenated keys, and a 17 KiB text; each with its expected public key text
  and bit length.
  *Partly delivered with feature 002 (Phase 2 needs it): RSA 1024 to 8192 bits (PKCS #8) with their
  public key texts, and an EC P-256 public key. The import cases are still to be added.*

**Checkpoint**: dependencies installed, settings readable, fixtures committed.

---

## Phase 2: Foundational A — shared DKIM core (implemented together with feature 002)

**Purpose**: the store, generator, publisher, ISPConfig client and `DkimKeyService::generateForNewName()`
that features 002 and 004 call (research R4, R5, R10–R13). **Blocks feature 002's domain creation,
feature 004's alias domain creation and every DKIM story of this feature.**

### Tests for Phase 2 (write first, observe failing)

- [X] T009 [P] Write `tests/Unit/Dkim/DkimKeyStoreTest.php` against the real Redis (database 15),
  connection `mailcow`:
  - `create` writes exactly `HSET DKIM_PUB_KEYS <name> <pub>`, `HSET DKIM_SELECTORS <name> <sel>`
    and `HSET DKIM_PRIV_KEYS <sel>.<name> <pem>`, and returns `exists` (writing nothing) when
    either `DKIM_PUB_KEYS[name]` or `DKIM_SELECTORS[name]` is already set;
  - `replace` removes `DKIM_PRIV_KEYS[<old selector>.<name>]` before writing, returns `outdated`
    for a wrong expected fingerprint and changes nothing, and returns the old selector and public
    key;
  - `delete` removes the three fields, returns `absent` without a key and `outdated` for a wrong
    fingerprint;
  - `restore` puts the previous fields back only while the current fingerprint is the one given;
  - the internal `fingerprint()` in PHP equals the script's `redis.sha1hex` value for keys with
    every field combination (absent private key, absent selector, non-ASCII stored name);
  - `versionTag()` is 001's `ETag` over `[name, selector, pub, hash_hmac('sha256', priv, app key)]`:
    it changes when only the private key changes, differs under another app key, and contains
    neither the SHA-1 nor the SHA-256 of the private key;
  - a script loaded with `EVALSHA` falls back to `EVAL` after `SCRIPT FLUSH` (`NOSCRIPT`);
  - a connection to a closed port raises 002's shared-store unavailable exception and writes
    nothing (002 D26 technique).
- [X] T010 [P] Write `tests/Unit/Dkim/DkimKeyGeneratorTest.php`: each allowed size gives an RSA key
  of exactly that many bits; the private key starts with `-----BEGIN PRIVATE KEY-----` and has no
  surrounding whitespace; the public key text has no armour or line breaks and equals legacy's
  derivation for the private key of `tests/fixtures/legacy/dkim/generated-2048.pem`
  (`generated-2048.pub`).
- [X] T011 [P] Write `tests/Unit/Dkim/DkimPublicKeyTest.php`: `bits()` is exact for every key of
  `DkimFixtures` (1,024 to 8,192) and `null` for text that is not a public key or not RSA;
  `recordValue()` is `v=DKIM1;k=rsa;t=s;s=email;p=<pub>`; `recordParts()` are consecutive pieces of
  at most 255 characters that join to the value; `sha256Fingerprint()` is the hex SHA-256 of the
  DER bytes.
- [X] T012 [P] Write `tests/Unit/Dkim/JsonIspconfigDnsClientTest.php` with `Http::fake()`:
  - exact requests: `POST <url>?login` with `{"username","password"}`, then
    `dns_zone_get {session_id, primary_id: {origin: "example.org."}}`,
    `dns_rr_get_all_by_zone {session_id, zone_id}`, `dns_txt_add {session_id, client_id, params}`,
    `dns_txt_update {session_id, client_id, primary_id, params}`, `dns_txt_delete {session_id,
    primary_id}`, and `logout {session_id}` exactly once at the end, also after a failure;
  - mapping: connection error → `unreachable`; timeout → `timeout`; `login` with `code` not `ok`
    → `authenticationFailed`; another call not `ok` → `remoteFault`; HTTP 500, non-JSON body or a
    wrong `response` shape → `invalidResponse`;
  - the 15-second deadline (fake clock) caps every call's timeout and stops before a call when less
    than 0.5 s remains;
  - TLS verification is off only with `RESTV2_ISPCONFIG_VERIFY_TLS=n`;
  - no exception message, `detail` or log line contains the password (it is replaced by `***`).
- [X] T013 [P] Write `tests/Unit/Dkim/DkimDnsPublisherTest.php` with `FakeIspconfigDnsClient`
  (R12): disabled or URL/user/password missing → `notConfigured` and no call; no zone with origin
  `<name>.` → `noZone` (also when only a parent zone exists); a TXT record named
  `<sel>._domainkey` or `<sel>._domainkey.<name>.` is updated with `server_id`, `zone`, `name =
  "<sel>._domainkey"`, `type TXT`, `data "v=DKIM1;k=rsa;t=s;s=email;p=<pub>"`, `ttl 7200`, `active Y`,
  `stamp` and client ID `sys_groupid - 1`; no record → added; duplicates of the selector → first
  updated, others deleted; selector change → old selector's records deleted first, a failure there
  → `failed` with `staleRecordRemovalFailed`; removal → `removed` or `noRecordToRemove`; every
  client error → `failed` with its reason code; the whole publication respects
  `RESTV2_ISPCONFIG_TIMEOUT`.
- [X] T014 Write `tests/Feature/Dkim/DkimCreationStepTest.php` (calls the service directly, as 002
  and 004 do; seeds the hashes with raw `HSET` on the `mailcow` connection): `generateForNewName('example.org', 'dkim', 1024, ctx)` → `created`, the three hashes
  set, one `dkimKeyGeneration` audit record with `target {type: dkimKey, id: example.org}`,
  `details.trigger = domainCreation`, the creator's request ID and the publication outcome; a name
  with only `DKIM_SELECTORS` or only `DKIM_PUB_KEYS` set → `alreadyExisted`, nothing written, no
  audit record; Redis unavailable → `failed`, no exception; publication enabled and ISPConfig
  unreachable → `created` with `failed`/`unreachable`; never a private key in the audit record.

### Implementation for Phase 2

- [X] T015 [P] Create `app/Support/Clock/Clock.php` (interface, `now(): CarbonImmutable`,
  `sleep`-free), `app/Support/Clock/SystemClock.php`, `app/Support/Clock/Deadline.php` (`start`,
  `limitSeconds`, `remaining(): float`, `expired(): bool`) and `tests/Support/Dns/FakeClock.php`
  (manual advance); bind `Clock` in `app/Providers/AppServiceProvider.php`.
- [X] T016 [P] Create `resources/redis/dkim-key-store.lua` (modes `create`, `replace`, `delete`,
  `restore`, as in data-model "Writes") and `app/Clients/SharedStore/DkimKeyStore.php` (connection
  `mailcow`, `SCRIPT LOAD` + `EVALSHA` with `NOSCRIPT` fallback, `read(name): ?StoredDkimKey`,
  internal `fingerprint()` = `sha1(name \0 selector \0 pub \0 priv)` (never returned or logged),
  `versionTag()` through 001's `App\Support\Http\ETag` with the private key as
  `hash_hmac('sha256', priv, config('app.key'))`, exceptions mapped to 002's shared-store
  unavailable exception). Reuse 002's `SharedStore` ping helper if it exists. T009 passes.
- [X] T017 [P] Create `app/Services/Dkim/DkimKeyGenerator.php` (`generate(int $bits):
  DkimKeyMaterial`, R5; if 002 created it with Redis writes, move the writes to `DkimKeyStore`),
  `app/Services/Dkim/DkimKeyMaterial.php` (`__debugInfo` hides the private key),
  `app/Services/Dkim/DkimPublicKey.php` and `app/Services/Dkim/StoredDkimKey.php`. T010 and T011
  pass.
- [X] T018 [P] Create or extend `app/Clients/Ispconfig/IspconfigDnsClient.php` (interface:
  `open(Deadline)`, `findZone(string $origin): ?IspconfigZone`, `records(int $zoneId): list<IspconfigRecord>`,
  `addTxt`, `updateTxt`, `deleteTxt`, `close()`), `JsonIspconfigDnsClient.php`, `IspconfigZone.php`,
  `IspconfigRecord.php`, `IspconfigException.php` (reason code + scrubbed detail) and
  `app/Services/Dkim/IspconfigSettings.php` (enabled only when the flag is `y` and URL, user and
  password are set; password hidden from `__debugInfo`). T012 passes.
- [X] T019 [P] Create or extend `tests/Support/Fakes/FakeIspconfigDnsClient.php`: in-memory zones
  (`origin`, `id`, `sys_groupid`) and records, a call log with assertion helpers
  (`assertNoCalls`, `assertRecord`, `assertCallCount`), programmable failure per function
  (`unreachable`, `timeout` advancing `FakeClock`, `remoteFault`, `authenticationFailed`); bind it
  in `tests/TestCase.php` for every test.
- [X] T020 Create or extend `app/Services/Dkim/DkimDnsOutcome.php` (enum: `published`, `removed`,
  `noRecordToRemove`, `noZone`, `failed`, `notConfigured`, plus 002's `skipped`),
  `app/Services/Dkim/DkimDnsPublicationResult.php` (outcome, record name, reason code, detail) and
  `app/Services/Dkim/DkimDnsPublisher.php` (`publish(name, selector, pub, ?oldSelector)`,
  `remove(name, selector)`, R12). T013 passes.
- [X] T021 Add the cases of this feature to 001's enums: `App\Support\Http\ProblemType`
  (`DkimKeyExists` 409, `DkimPrivateKeyExportDisabled` 403, `DkimDnsPublicationDisabled` 403,
  `MtaStsPolicyExists` 409; `TemporaryFailure` 503 from 002), the audit event type enum used by
  `App\Services\Audit\AuditLogger` (`dkimKeyGeneration`, `dkimKeyImport`, `dkimKeyCopy`,
  `dkimKeyDeletion`, `dkimPrivateKeyExport`, `dkimDnsPublication`, `mtaStsPolicyCreation`,
  `mtaStsPolicyUpdate`) and the audit target type enum (`dkimKey`, `mtaStsPolicy`), matching
  `api/components/schemas/{KnownProblemType,AuditEventType,AuditTarget}.yaml`.
- [X] T022 Create `app/Services/Dkim/DkimAudit.php`: builds the whitelisted `details` of research
  R22 for each event type (`selector`, `keyLength`, `overwriteRequested`, `old`/`new` with
  `selector`, `keyLength`, `publicKeySha256`, `trigger`, `sourceName`, `dnsPublication` with
  `status`, `reasonCode`, `detail`) and writes through `AuditLogger`; any other key is dropped.
  The `503` refusals of DKIM operations are written here with `failureReason: temporaryFailure`;
  every `4xx` refusal is written by the shared `RefusalAuditor` (T032).
- [X] T023 Create `app/Services/Dkim/DkimKeyService.php` with `generateForNewName()` (R10) and the
  shared private step `commitChange()` (store write → publication → audit; on audit failure
  `restore` with the written fingerprint, log at `error`, rethrow → `500`, R13), plus
  `app/Services/Dkim/DkimCreationStep.php`. Register the service, store, publisher and client
  bindings in `app/Providers/AppServiceProvider.php`. T014 passes.

**Checkpoint**: features 002 and 004 can call `DkimKeyService::generateForNewName()`; every DKIM
write path exists below the HTTP layer.

*Delivered with feature 002 (2026-10-18). Notes: `generateForNewName()` never throws, also when its
audit record fails (the key is removed again and the step reports `failed`), because the domain is
already committed. The shared `RefusalAuditor` also records `503 temporary-failure` refusals of
routes that declare `restv2.audit`, so `DkimAudit` needs to write a 503 refusal only when a DKIM
operation refuses outside such a route (or calls `RefusalAuditor::markRecorded()` after writing its
own). T021 added every problem, event and target value of this feature; the event types other than
`dkimKeyGeneration` stay in `AuditEventDetailsDocumentedTest::$notYetDocumented` until their
operations exist.*

---

## Phase 3: Foundational B — feature 010 groundwork

**Purpose**: name resolution, models, policies, routes and test helpers shared by the stories.
Runs when feature 010 is implemented (after 002–009).

### Tests for Phase 3

- [X] T024 [P] Write `tests/Unit/Dkim/DkimNameResolverTest.php` (R7): an administrator's raw name
  that is a field of `DKIM_PUB_KEYS` or `DKIM_SELECTORS` but neither a domain nor an alias domain
  resolves to that unused key by its exact spelling (`Example.ORG`, `bücher.example`); otherwise
  the name is trimmed, lower-cased and converted with UTS #46 (`BÜCHER.example` →
  `xn--bcher-kva.example`); the kind is `domain`, `aliasDomain` (with `targetDomain`) or `none`; a
  domain administrator resolves own domains (also inactive ones) and alias domains whose target is
  own, and gets "out of scope" for foreign names and every `none` name; an unconvertible name is
  "invalid".
- [X] T025 [P] Write `tests/Unit/Support/DomainNameMxHostTest.php`: the MX-host rule accepts
  `mail.example.org`, `*.example.org`, `MAIL.Example.ORG` (normalised) and IDN names, and refuses
  `mail..example`, `-mail.example.org`, `*.`, `a*.example.org`, labels over 63 and names over 253
  characters (002's `domainName` rule plus the `*.` prefix, legacy `is_valid_domain_name` with
  `allow_wildcard`).

### Implementation for Phase 3

- [X] T026 [P] Create `app/Models/Mailcow/MtaStsPolicy.php` (`$table = 'mta_sts'`, `$primaryKey =
  'domain'`, `$keyType = 'string'`, `$incrementing = false`, `$timestamps = false`, `active` cast to
  boolean, accessor `policy_id` for column `id`) and, unless feature 004 created it,
  `app/Models/Mailcow/AliasDomain.php` (`$table = 'alias_domain'`, `$primaryKey = 'alias_domain'`).
- [X] T027 [P] Create `app/Services/Dkim/DkimName.php` and `app/Services/Dkim/DkimNameResolver.php`
  (R7, using 001's `IdentityService` scope and 002's `App\Support\Domain\DomainName`). T024 passes.
- [X] T028 [P] Create `app/Rules/MtaStsMxHost.php` (rule-code aware: `invalidFormat` with `format:
  mxHost`) on top of 002's `App\Support\Domain\DomainName`. T025 passes.
- [X] T029 [P] Extend `tests/Support/Mailcow/MailcowFactory.php` (and
  `database/factories/MailcowRows.php`) with `alias_domain` rows (if 004 has not), `mta_sts` rows
  exactly as legacy writes them (`version 'stsv1'`, lower-case `mode`, `mx` joined by `,`, `max_age`
  as string, `id` as `YmdHis`), and `dkimKey(name, selector, bits|pem, withPrivateKey: true)` that
  writes the three hashes in legacy format, plus `legacyDkimKey(rawName, …)` for non-normalised
  names and `partialDkimKey(name, parts)` for incomplete keys.
- [X] T030 [P] Create `tests/Support/Dkim/DkimAssertions.php` (trait): `assertDkimStored(name,
  selector, pub, pem)`, `assertNoDkim(name)`, `assertNoPrivateKeyField(selector.name)`,
  `assertNoPemInResponse()`; use it in `tests/TestCase.php`.
- [X] T031 [P] Create `app/Policies/DkimKeyPolicy.php` (`viewAny`/`view`/`generate`: admin or domain
  admin; `overwrite`, `import`, `copy`, `delete`, `export`, `publish`: admin), 
  `app/Policies/MtaStsPolicyPolicy.php` (admin or domain admin) and
  `app/Policies/DnsDiagnosticsPolicy.php` (admin or domain admin; `serverChecks`: admin); register
  them as gates in `app/Providers/AppServiceProvider.php`. Mailbox users get `403 forbidden`.
- [X] T032 Register this feature with the shared refusal auditing (`docs/conventions.md`, research
  R22): in `app/Providers/AppServiceProvider.php`, add `RefusalAuditor` target resolvers for
  `dkimKey` (normalised path `dkimName`, else the body `name` or, for a copy, the body `target`,
  truncated to 255) and `mtaStsPolicy` (path `policyDomain`, else the body `domain`), and map this
  feature's problem types to the shared `AuditFailureReason` values (`conflict` for
  `dkim-key-exists`, `mta-sts-policy-exists` and the incomplete copy source; `featureDisabled` for
  `dkim-private-key-export-disabled` and `dkim-dns-publication-disabled`). Write
  `tests/Unit/Audit/MailDnsRefusalResolverTest.php` first: the resolvers return the right target
  for each route, never read the private key text, and `details.errors` of a refused import holds
  codes and parameters only. Use 008's `App\Services\Audit\RefusalAuditor` (or the equivalent the
  first implemented feature created); do not create a second mechanism.
- [X] T033 Prepare `routes/api.php`: a block for this feature inside the `api.v2` group with
  `restv2.auth:authenticated` and `restv2.pending:enforce`, the constraints
  `where('dkimName', '[^/]+')`, `where('policyDomain', '[^/]+')` and
  `where('diagnosticsName', '[^/]+')`, and the rule that the sub-resource routes
  (`/dkim-keys/{dkimName}/import`, `/copy`, `/private-key`, `/dns-publication`) and the POST-only
  `/dkim-keys/bulk-actions` are declared before `/dkim-keys/{dkimName}`, which never accepts POST. Each story's controller task registers its own routes there, so no
  route exists before its implementation. Every state-changing route and the export route declare
  `->defaults('restv2.audit', ['event' => <event type>, 'target' => 'dkimKey' | 'mtaStsPolicy'])`
  (T032).

**Checkpoint**: foundation ready; user stories can start in priority order.

---

## Phase 4: User Story 1 — See DKIM keys and the DNS records they need (Priority: P1) 🎯 MVP

**Goal**: list and inspect DKIM entries with state, key details and record; read the capabilities.

**Independent Test**: seed domains and alias domains with and without keys, every key size, unused
keys (also non-normalised), and an incomplete key; list and inspect as each role with every filter;
compare with legacy's screen values.

### Tests for User Story 1

- [ ] T034 [P] [US1] Write `tests/Feature/Dkim/ListDkimKeysTest.php`:
  - AS1: 3 domains with keys, 1 without, 1 alias domain with a key, 1 key of a deleted domain → 6
    entries, 4 `valid`, 1 `missing`, 1 `unused`, sorted by name;
  - filters: `state` repeated (`missing`, `unused`), `kind`, `selector` (exact), `complete`
    (`true`/`false`; entries without a key never match), `domain` (domain plus its alias domains),
    `q` on the ASCII and the Unicode name, case-insensitive;
  - sort `name`, `state`, `selector`, `keyLength` in both orders, ties by name, entries without a key
    last in ascending `selector` and `keyLength`;
  - pagination: default 25, `meta.total`, `limit=1000` accepted, `limit=1001` → `422 outOfRange`;
  - an incomplete key (no private key) has `complete: false` and `missingParts: [privateKey]`; a
    selector-only key has `publicKey`, `keyLength` and `record` `null`;
  - a legacy key stored under `Example.ORG` is listed as `unused` under that exact name; a
    `DKIM_PRIV_KEYS` field `<selector>.<name>` that no `DKIM_SELECTORS` entry points to (left behind
    by a legacy copy) creates no entry;
  - `etag` is set for every entry with a key and `null` for `missing`; no response contains
    `PRIVATE KEY`;
  - Redis unavailable → `503 temporary-failure` with `Retry-After`.
- [ ] T035 [P] [US1] Write `tests/Feature/Dkim/GetDkimKeyTest.php`: AS2 (2,048-bit key, selector
  `dkim` → `keyLength 2048`, record name `dkim._domainkey.<domain>`, value
  `v=DKIM1;k=rsa;t=s;s=email;p=<pub>`, `valueParts` of ≤ 255 characters joining to the value); the
  `ETag` header equals `data.etag`; a key changed directly in Redis (as legacy would) changes the
  `ETag` on the next read (FR-051, FR-058), also when only the private key changed; `GET /dkim-keys/B%C3%BCCHER.example` finds
  `xn--bcher-kva.example`; an administrator reaches an unused key by its exact stored name; an
  unknown name → `404`; a domain without a key → `200` with `state: missing`, `key: null`, no
  `ETag`.
- [ ] T036 [P] [US1] Write `tests/Feature/Dkim/DnsCapabilitiesTest.php`: an administrator gets
  `serverHostname` (lower-case `MAILCOW_HOSTNAME`), `dkim.defaultSelector` from
  `RESTV2_DEFAULT_DKIM_SELECTOR` (`feldhost` when set), `defaultKeySize 2048`, `allowedKeySizes`,
  `selectorMaxLength 255`, `privateKeyMaxBytes 16384`, `importKeyLengthMin 1024`,
  `importKeyLengthMax 8192`, `dnsPublicationEnabled` (`false` by default, `false` with the flag but
  no URL, `true` when complete), `privateKeyExportEnabled` (vars file and env precedence),
  `mtaSts.version STSv1`, `modes`, `defaultActive false`, `maxAgeMin 1`, `maxAgeMax 31557600`,
  `mxMaxLength 255`, `dnsDiagnostics.runsPerMinute 10`, `lookupTimeoutSeconds 5`,
  `runTimeoutSeconds 30`, `serverChecks true`, `defaultPageSize 25`, `maxPageSize 1000`; a domain
  administrator gets `privateKeyExportEnabled: null` and `serverChecks: false`; no ISPConfig URL,
  user or password appears.
- [ ] T037 [P] [US1] Write `tests/Feature/Dkim/DkimReadAuthorizationTest.php`: the role and key
  matrix for `listDkimKeys`, `getDkimKey` and `getDnsCapabilities` (AS3, AS4, AS6): a domain
  administrator with one domain and one alias domain lists exactly those 2 entries and never an
  unused key; a foreign domain or an unused key's name → the same `404` body shape as a
  non-existent name; mailbox user → `403 forbidden`; read-only keys allowed; removing the domain
  administrator's `domain_admins` row makes the domain answer `404` on the very next request
  (FR-002).

### Implementation for User Story 1

- [ ] T038 [US1] Create `app/Services/Dkim/DkimEntryQuery.php` (research R8: names and kinds from
  `domain` and `alias_domain` restricted to the scope, `HKEYS` of both hashes, values loaded only
  for the filters and sorts that need them, page values in one pipeline, key lengths memoised per
  public key hash) and `app/Services/Dkim/DkimEntry.php`.
- [ ] T039 [US1] Create `app/Http/Requests/Api/V2/ListDkimKeysRequest.php` (`state` array of at most
  3 values of `valid|missing|unused`, `kind` of `domain|aliasDomain|none`, `selector` max 255,
  `complete` boolean, `domain` max 255, `q` max 255, `sort` of `name|state|selector|keyLength`,
  `order`, `limit` 1–1000, `offset` ≥ 0; rule codes `notInList`, `tooMany`, `tooLong`,
  `outOfRange`, `invalidType`).
- [ ] T040 [US1] Create `app/Http/Resources/Api/V2/DkimKeyResource.php` (contract `DkimKey`:
  `name`, `displayName` via `idn_to_utf8`, `kind`, `targetDomain`, `state`, `key` with `selector`,
  `keyLength`, `publicKey`, `record`, `complete`, `missingParts`, and `etag`; never a private key).
- [ ] T041 [US1] Implement `index` and `show` in `app/Http/Controllers/Api/V2/DkimKeyController.php`
  (policy `viewAny`/`view`, `DkimNameResolver`, `ETag` header when a key exists, `503` mapping of
  the shared-store unavailable exception) and register `GET /dkim-keys` and `GET /dkim-keys/{dkimName}` in
  `routes/api.php`. T034, T035 and the read part of T037 pass.
- [ ] T042 [US1] Create `app/Http/Controllers/Api/V2/DnsCapabilitiesController.php` and
  `app/Http/Resources/Api/V2/DnsCapabilitiesResource.php` (contract `DnsCapabilities`,
  values from `config('restv2.*')`, `IspconfigSettings`, `MailcowConfig` and the principal's role)
  and register `GET /capabilities/dns` in `routes/api.php`. T036 and T037 pass.

**Checkpoint**: US1 complete and testable on its own (MVP).

---

## Phase 5: User Story 2 — Generate a DKIM key (Priority: P1)

**Goal**: generate a key for a domain or alias domain, with atomic rotation by administrators.

**Independent Test**: generate keys with each size and with default and custom selectors, rotate
with overwrite, and compare the Redis hashes with legacy's format.

### Tests for User Story 2

- [ ] T043 [P] [US2] Write `tests/Feature/Dkim/GenerateDkimKeyTest.php`:
  - AS1: no selector or size → `201`, `Location: /api/v2/dkim-keys/example.org`, `ETag`,
    `data.state valid`, `data.key.keyLength 2048`, `data.key.selector` = the default selector,
    `data.dnsPublication.status notConfigured`; the three hashes hold the key in legacy format;
  - AS2: an existing key (also an incomplete one) without `overwrite` → `409 dkim-key-exists`, the
    hashes unchanged; with `overwrite: true` by an administrator → `200`, new selector and key
    stored, `DKIM_PRIV_KEYS[<old selector>.<name>]` gone; `If-Match` with the old `ETag` → `200`,
    with another value → `412` and nothing changed; `overwrite: true` for a name without a key →
    `201`;
  - AS3: selector `a b`, `a/b`, `""` and 256 characters → `422` with `invalidFormat`
    (`format: dkimSelector`), `required` or `tooLong`; `keySize: 1536` → `notInList` with
    `allowed: [1024, 2048, 3072, 4096]`;
  - AS4: a name that is neither a domain nor an alias domain → `404`;
  - AS5: a domain administrator generates for an alias domain of their domain → `201`; for another
    administrator's domain → `404`;
  - AS6: an administrator's read-only key → `403 insufficient-key-scope`;
  - AS7: a domain administrator with `overwrite: true` on an own domain with a key → `403
    forbidden`, key unchanged;
  - a Unicode or upper-case name is stored as lower-case ASCII; an inactive domain still gets a key;
  - Redis unavailable → `503 temporary-failure`, nothing written;
  - every attempt above by an authenticated caller, success or refusal (AS6's read-only key
    included, with `failureReason: permissionDenied`), writes exactly one `dkimKeyGeneration` audit
    record with the details and failure reason of research R22 and no private key;
  - audit insert failing (database trigger in the test) → `500`, the previous key restored (or no
    key), the log names the name.
- [ ] T044 [P] [US2] Write `tests/Feature/Dkim/DkimConcurrencyTest.php` (FR-053): five worker
  processes (`Symfony\Component\Process\Process` running `php artisan tinker --execute` against the
  test database and Redis) generate a key for the same name at once → exactly one `created`, four
  `exists`; the stored key is complete and consistent (fingerprint of one generator's material).

### Implementation for User Story 2

- [ ] T045 [US2] Create `app/Http/Requests/Api/V2/GenerateDkimKeyRequest.php`: `name` "required, string, 1 to 255 characters"; `selector` "optional, 1 to 255
  characters, letters, digits, `-`, `_` and `.` only" (`App\Rules\DkimSelector` from 002:
  `invalidFormat`/`dkimSelector`, `tooLong`); `keySize` "optional, one of 1024, 2048, 3072, 4096"
  (`notInList`); `overwrite` "optional boolean" (`invalidType`).
- [ ] T046 [US2] Implement `DkimKeyService::generate(Principal, name, ?selector, ?bits, overwrite,
  ?ifMatch): DkimKeyChange` in `app/Services/Dkim/DkimKeyService.php`: resolve the name (domain or
  alias domain in scope, else not found), refuse `overwrite` for domain administrators, generate,
  `create` or `replace` through the store with the `If-Match` fingerprint, publish, audit
  (`commitChange`). Create `app/Services/Dkim/DkimKeyChange.php`.
- [ ] T047 [US2] Create `app/Http/Resources/Api/V2/DkimKeyChangeResource.php` (contract
  `DkimKeyChangeResult`: `DkimKeyResource` plus `dnsPublication {status, recordName, reason {code,
  detail} | null}`) and implement `store` in `app/Http/Controllers/Api/V2/DkimKeyController.php`
  (`201` with `Location` and `ETag`, or `200` with `ETag`; `4xx` refusals audited by
  `RefusalAuditor` through the route's `restv2.audit` defaults, `503` by the service) and register
  `POST /dkim-keys` in `routes/api.php`. T043 and T044 pass.

**Checkpoint**: US1 and US2 work on their own.

---

## Phase 6: User Story 3 — Check a domain's DNS setup (Priority: P1)

**Goal**: structured diagnostics with identifiers, coded messages, zone file, time limits and a
visible rate limit.

**Independent Test**: a fake zone with correct, wrong, missing and unresolvable records and an
unresponsive server; run as each role for a domain and an alias domain; check statuses, values,
codes and the zone file.

### Tests for User Story 3

- [ ] T048 [P] [US3] Extend 009's `tests/Support/Dns/FakeDnsResolver.php` with CNAME, SRV (target
  `.` included), PTR and TLSA answers, a per-lookup delay on `FakeClock`, `DnsTemporaryFailure` on
  demand and a lookup log; extend 009's `tests/Support/Smtp/FakeSmtpProbe.php` with the TLS-only
  request (returns a certificate PEM or a failing step: `connect`, `timeout`, no `STARTTLS`, `tls`,
  no certificate). Both stay bound in `tests/TestCase.php`.
- [ ] T049 [P] [US3] Write `tests/Unit/DnsDiagnostics/RecordComparatorTest.php` (FR-043): host names
  case-insensitive without trailing dot; IPv6 compared as addresses and shown expanded; TLSA data
  case-insensitive; MX matches when any exchange is the host; A/AAAA of the host with a CNAME →
  `mismatch`/`hostIsAlias`; CNAME match; address-record fallback with IPv4 equal and IPv6 equal or
  both absent → `match` with `matchedByAddressRecords`; SRV target and port only; TXT strings
  joined; DKIM `p=` equality whatever the tag order and spaces, otherwise `dkimPublicKeyDiffers`;
  MTA-STS `v` and `id` tags with spaces around separators; DMARC and TLS reporting →
  `informational` or `missing`.
- [ ] T050 [P] [US3] Write `tests/Unit/DnsDiagnostics/SpfEvaluatorTest.php` with `FakeDnsResolver`:
  `ip4` and `ip6` ranges, `a`, `mx`, `include`, `redirect` → `match` when both public addresses pass;
  `-all`/`~all`/`?all` → `spfNotPass` with `result`; two SPF records → `spfMultipleRecords`
  (`count: 2`); eleven lookups → `spfTooManyLookups` (`limit: 10`); three void lookups →
  `spfTooManyVoidLookups`; a syntax error → `spfError` (`permerror`); a resolver timeout during
  evaluation → `lookupFailed`; no public IPv4 → `expectedUnknown`/`publicAddressUnknown`; no IPv6
  → only IPv4 evaluated; guidance always has `spfAuthorizeAddresses` and `spfSyntaxReference`, and
  `spfSuggestedRecord` only when `RESTV2_SUGGESTED_SPF_RECORD` is set.
- [ ] T051 [P] [US3] Write `tests/Unit/DnsDiagnostics/ExpectedRecordSetTest.php` (FR-040–FR-042):
  the check order and identifiers of legacy (server checks first for administrators: `a`,
  `ptr:ipv4`, `aaaa`, `ptr:ipv6`, `tlsa:25`; then `mx`, `autodiscover`, `srv:autodiscover`,
  `autoconfig`, `mta-sts`, `mta-sts-record`, `tls-rpt`, `spf`, `dmarc`, `dkim:<selector>`, then the
  SRV checks); SRV checks only for non-standard ports from `AutodiscoverConfig`; the autodiscover
  SRV port from the request `Host` (else 443); MTA-STS checks only with an active policy, for an
  alias domain its target's policy and identifier; no DKIM check without a key; `dkim` with
  `expectedUnknown`/`dkimKeyUnavailable` when Redis is down; a null SRV for `_pop3._tcp` or
  `_pop3s._tcp` becomes the expected value with `nullServiceRecord`; no AAAA and IPv6 PTR checks and
  the note `noPublicIpv6` without IPv6; PTR names `in-addr.arpa` and nibble `ip6.arpa`; domain
  administrators get no server checks and the note `serverChecksOmitted`.
- [ ] T052 [P] [US3] Write `tests/Unit/DnsDiagnostics/ZoneFileRendererTest.php` (FR-047, SC-007):
  `$ORIGIN <name>.`, absolute owners, MX `10 <host>.`, SRV `0 1 <port> <target>.`, null SRV, TXT
  quoted with escapes and split at 255 characters, a commented placeholder for SPF, DMARC and TLS
  reporting without values, no server-wide records; every rendering of the test matrix passes
  `named-checkzone <name> <file>` (run through `Symfony\Component\Process\Process`).
- [ ] T053 [P] [US3] Extend 009's `tests/Unit/Clients/NativeDnsResolverTest.php` for the new
  methods `cname()`, `srv()`, `ptr()` and `tlsa()`: record shapes (SRV `target`/`port`, a null
  record as `.`), TLSA raw data decoded to `<usage> <selector> <matching type> <hex>` as legacy
  does, `[]` for no records, `DnsTemporaryFailure` for a resolver warning (captured, never logged
  as a PHP warning), using 009's technique for driving `dns_get_record()` in tests.
- [ ] T054 [P] [US3] Extend 009's `tests/Unit/Clients/StreamSmtpProbeTest.php` with the TLS-only
  request against 009's `tests/Support/Smtp/ScriptedSmtpServer.php` (self-signed certificate):
  `220` → `EHLO` → `STARTTLS` → handshake → peer certificate returned and `QUIT`, no `MAIL FROM`;
  closed port, no `STARTTLS`, failed handshake and a silent server (deadline 5 s) give the matching
  failing step. Write `tests/Unit/DnsDiagnostics/TlsaDigestTest.php`: `3 1 1 <sha256 of the DER
  SPKI>` equals the digest computed with OpenSSL from the script's certificate.
- [ ] T055 [P] [US3] Write `tests/Unit/DnsDiagnostics/PublicAddressDetectorTest.php` with
  `Http::fake()`: IPv4 and IPv6 echo answers (forced address family), invalid bodies → unknown,
  no IPv6 answer → no IPv6; `RESTV2_PUBLIC_IPV4`/`RESTV2_PUBLIC_IPV6` override without a request,
  `RESTV2_PUBLIC_IPV6=none`; results cached in `restv2:dns-facts:addresses` for
  `RESTV2_DNS_FACTS_TTL`, failures not cached.
- [ ] T056 [P] [US3] Write `tests/Feature/DnsDiagnostics/RunDnsDiagnosticsTest.php`: the full result
  shape as an administrator (`name`, `displayName`, `aliasDomain`, `targetDomain`,
  `serverHostname`, `publicIpv4`, `publicIpv6`, `suggestedSpfRecord`, `checkedAt`,
  `serverChecksIncluded`, `summary` counts equal to the checks, `checks` with `id`, `scope`,
  `recordName`, `recordType`, `status`, `optional`, `expected`, `found`, `guidance`, `reason`,
  `notes`, the `propagationDelay` note, and `zoneFile`); AS1 MX `match`; AS2 no MX → `missing` with
  `optional: false`; AS3 DKIM with another key → `mismatch`, `found` lists it; AS6 alias domain →
  `mta-sts-record` expects the target's identifier; AS9 identifiers and codes present; an inactive
  domain is diagnosed like an active one; the result's `zoneFile` passes `named-checkzone` (AS7);
  `Cache-Control: no-store`; no audit record and no change to MariaDB or the `DKIM_*` hashes.
- [ ] T057 [P] [US3] Write `tests/Feature/DnsDiagnostics/DnsDiagnosticsStatusesTest.php` (SC-006): a
  fake zone that yields every status and every reason and note code of `DnsCheckMessageCode`
  (`lookupError` for a resolver failure such as a DNSSEC validation failure, `hostIsAlias`, `matchedByAddressRecords`
  including the IPv4-only case, `nullServiceRecord`, `publicAddressUnknown`,
  `certificateUnavailable` with each cause and the found TLSA records still listed,
  `dkimKeyUnavailable`, each SPF code, DMARC `informational`), each with its parameters.
- [ ] T058 [P] [US3] Write `tests/Feature/DnsDiagnostics/DnsDiagnosticsTimeoutTest.php` (AS4,
  FR-048): with `FakeClock`, every lookup of the domain takes 4 s and then throws
  `DnsTemporaryFailure` (the container's resolver bound) → each affected check
  `lookupFailed`/`lookupTimeout`; a lookup is started only while at least 5 s of the 30-second
  deadline remain, and the remaining checks report `lookupFailed`/`runTimeout` without a lookup;
  the run returns `200` with all checks and its total fake time stays at or below 30 s; a fast
  `DnsTemporaryFailure` gives `lookupError`; the echo service and the certificate fetch each give
  up after 5 s.
- [ ] T059 [P] [US3] Write `tests/Feature/DnsDiagnostics/DnsDiagnosticsRateLimitTest.php` (AS8,
  FR-049): runs 1–10 answer `200` with `RateLimit-Limit: 10`, `RateLimit-Remaining` from 9 to 0 and
  `RateLimit-Reset` ≤ 60; run 11 → `429 too-many-requests` with `Retry-After`, `retryAfter` and
  `RateLimit-Remaining: 0`, before any lookup (resolver log empty); a `404` name does not count; a
  session and an API key of the same owner count separately; after the window (fake time) runs are
  allowed again; the counter key is `restv2:dns-diagnostics:<sha256>`.
- [ ] T060 [P] [US3] Write `tests/Feature/DnsDiagnostics/DnsDiagnosticsAuthorizationTest.php`: the
  role and key matrix (read-only keys allowed); AS5 a domain administrator's own domain and alias
  domain → checks without server-wide checks; a foreign name → `404`; mailbox user → `403`.

### Implementation for User Story 3

- [ ] T061 [P] [US3] Extend 009's `app/Clients/Dns/DnsResolver.php` and `NativeDnsResolver.php`
  with `cname()`, `srv()`, `ptr()` and `tlsa()` (R16; `dns_get_record()` with `DNS_CNAME`,
  `DNS_SRV`, `DNS_PTR`, and type 52 in raw mode), keeping 009's `DnsTemporaryFailure` handling.
  T053 passes.
- [ ] T062 [P] [US3] Extend 009's `app/Clients/Smtp/SmtpProbeRequest.php` (`stopAfterTls`,
  `capturePeerCertificate`, per-request deadline), `SmtpProbeResult.php` (peer certificate PEM) and
  `StreamSmtpProbe.php` (`capture_peer_cert` on the `STARTTLS` upgrade, `QUIT` after the handshake
  in TLS-only mode), and create `app/Services/DnsDiagnostics/TlsaDigest.php` (R19). T054 passes.
- [ ] T063 [P] [US3] Create `app/Clients/Dns/PublicAddressDetector.php` and
  `app/Services/DnsDiagnostics/ServerFactsCache.php` (`restv2:dns-facts:addresses`,
  `restv2:dns-facts:tlsa:<host>`, TTL `RESTV2_DNS_FACTS_TTL`, failures not cached). T055 passes.
- [ ] T064 [P] [US3] Create `app/Services/DnsDiagnostics/RecordComparator.php`,
  `DnsCheckResult.php`, `DnsCheckMessage.php` (code, params, English message). T049 passes.
- [ ] T065 [P] [US3] Create `app/Services/DnsDiagnostics/Spf/SpfResolverAdapter.php` (implements
  `SPFLib\DNS\Resolver` on `DnsResolver` and the run deadline) and
  `app/Services/DnsDiagnostics/Spf/SpfEvaluator.php` (R18). T050 passes.
- [ ] T066 [US3] Create `app/Services/DnsDiagnostics/ExpectedRecordSet.php` (R17, using
  `AutodiscoverConfig`, `DkimKeyStore`, `MtaStsPolicy` and `ServerFactsCache`). T051 passes.
- [ ] T067 [P] [US3] Create `app/Services/DnsDiagnostics/ZoneFileRenderer.php` (R21). T052 passes.
- [ ] T068 [US3] Create `app/Services/DnsDiagnostics/DnsDiagnosticsService.php` (one `Deadline` per
  run, checks in order, a lookup started only while `RESTV2_DNS_LOOKUP_TIMEOUT` remains,
  `runTimeout` for the rest, `lookupTimeout` or `lookupError` from the duration of a failed lookup,
  summary, notes) and
  `app/Services/DnsDiagnostics/DnsDiagnosticsReport.php`.
- [ ] T069 [US3] Create `app/Services/DnsDiagnostics/DiagnosticsRateLimiter.php` (R20: key
  `dns-diagnostics:<sha256(user:<username>|apiKey:<id>)>` on the `restv2` store, limit and window
  from config, `check()` before resolution, `hit()` after, header values).
- [ ] T070 [US3] Create `app/Http/Resources/Api/V2/DnsDiagnosticsResource.php` (contract
  `DnsDiagnosticsResult`) and `app/Http/Controllers/Api/V2/DnsDiagnosticsController.php` (policy,
  limiter check → `429` with `Retry-After` and `RateLimit-*`, name resolution through
  `DkimNameResolver` restricted to domains and alias domains → `404`, hit, run, `200` with
  `RateLimit-*` and `Cache-Control: no-store`), and register `GET /dns-diagnostics/{diagnosticsName}`
  in `routes/api.php`. T056–T060 pass.

**Checkpoint**: all P1 stories work on their own.

---

## Phase 7: User Story 4 — Manage a domain's MTA-STS policy (Priority: P2)

**Goal**: create, read, list and change policies stored exactly as legacy stores them.

**Independent Test**: create, change, activate and deactivate policies as administrator and domain
administrator; compare the `mta_sts` row and the text `mta-sts.php` would serve with legacy.

### Tests for User Story 4

- [ ] T071 [P] [US4] Write `tests/Unit/MtaSts/MtaStsPolicyIdTest.php`: `next()` is `YmdHis` in `TZ`
  (`Europe/Prague` in tests, fake clock); when not greater than the previous identifier it is the
  previous identifier's time plus one second (also across a minute and a day boundary); the result
  always has 14 digits.
- [ ] T072 [P] [US4] Write `tests/Unit/MtaSts/MtaStsPolicyTextTest.php`: the text equals the output
  of legacy `data/web/mta-sts.php` for the same stored row (`version: STSv1`, `mode`, `max_age`, one
  `mx:` line per host, trailing line feeds), including wildcard hosts.
- [ ] T073 [P] [US4] Write `tests/Feature/MtaSts/CreateMtaStsPolicyTest.php`:
  - AS1: `{domain, mode: enforce, mxHosts: [mail.example.org], maxAge: 86400, active: true}` →
    `201`, `Location: /api/v2/mta-sts-policies/example.org`, `ETag`; the `mta_sts` row is
    `version 'stsv1'`, `mode 'enforce'`, `mx 'mail.example.org'`, `max_age '86400'`, `active 1`,
    `id` 14 digits; the response has `version STSv1`, `policyUrl`, `policyText`, `dnsRecords`
    (`_mta-sts.example.org` TXT `v=STSv1;id=<id>;`, `mta-sts.example.org` CNAME host);
  - defaults: no `version` → `stsv1`; no `active` → `active 0`;
  - AS3: `maxAge 0` and `31557601` → `outOfRange`; `mxHosts: []` → `required`; `mail..example` →
    `invalidFormat`/`mxHost` on `mxHosts.0`; `mode: strict` → `notInList`; `version: STSv2` →
    `notInList`; hosts joining to 256 characters → `tooLong` (`max: 255`) on `mxHosts`; 129 hosts
    → `tooMany`;
  - normalisation: `MAIL.Example.ORG`, a duplicate and `*.example.org` → stored
    `mail.example.org,*.example.org`; `mode: ENFORCE`, `version: stsv1` accepted;
  - AS4: a second creation → `409 mta-sts-policy-exists`;
  - AS6: an alias domain's name → `404`; a legacy row stored under an alias domain's name stays
    invisible and untouched;
  - AS7 (create part): a domain administrator creates for an own domain, no flag needed; a foreign
    domain → `404`;
  - each success and refusal after authentication writes one `mtaStsPolicyCreation` audit record;
    when the audit insert fails, the response is `500` and no `mta_sts` row exists (one
    transaction).
- [ ] T074 [P] [US4] Write `tests/Feature/MtaSts/UpdateMtaStsPolicyTest.php`:
  - AS2: changing only `mode` → new `policyId`; changing only `active` → same `policyId`; changing
    the MX order or `maxAge` or `version` value → new identifier; two changes within one second →
    different identifiers (fake clock);
  - a patch that changes nothing → `200`, same identifier and `ETag`, no audit record;
  - AS5: `If-Match` with an outdated tag → `412`, row unchanged; a tag changed by a direct row
    update (legacy edit) is outdated;
  - members not sent keep their values; `{}` → `422 required` on `body`; `{"mode": null}` →
    `invalidType`; validation as in T073;
  - a domain without a policy → `404`; AS7 a domain administrator changes an own domain's policy;
  - the audit record has `changes` with old and new values and `oldPolicyId`/`newPolicyId`.
- [ ] T075 [P] [US4] Write `tests/Feature/MtaSts/ListMtaStsPoliciesTest.php` and
  `tests/Feature/MtaSts/GetMtaStsPolicyTest.php`: scope (administrator all, domain administrator
  own), `q` on ASCII and Unicode names, `active` and `mode` filters, sorts `domain`, `mode`,
  `maxAge` (numeric), `policyId`, ties by domain, pagination with `meta`, `etag` on every entry
  equal to the single read's `ETag`; a row changed directly in MariaDB is visible on the next read
  (FR-058).
- [ ] T076 [P] [US4] Write `tests/Feature/MtaSts/MtaStsAuthorizationMatrixTest.php`: the role and key
  matrix for the four MTA-STS operations.

### Implementation for User Story 4

- [ ] T077 [P] [US4] Create `app/Services/MtaSts/MtaStsPolicyId.php` and
  `app/Services/MtaSts/MtaStsPolicyText.php`. T071 and T072 pass.
- [ ] T078 [P] [US4] Create `app/Http/Requests/Api/V2/StoreMtaStsPolicyRequest.php`,
  `UpdateMtaStsPolicyRequest.php` and `ListMtaStsPoliciesRequest.php` with
  the data-model rules quoted exactly: `version` "optional, string, max 16, equal to `stsv1`
  case-insensitively"; `mode` "required at creation, string, max 16, one of `enforce`, `testing`,
  `none` case-insensitively"; `maxAge` "required at creation, integer, 1 to 31,557,600"; `mxHosts`
  "required at creation, array of 1 to 128 strings, each at most 255 characters … joined by `,` at
  most 255 characters"; `active` "optional boolean"; patch: at least one member, no `null`.
- [ ] T079 [US4] Create `app/Services/MtaSts/MtaStsPolicyService.php` (`create` with `INSERT` and
  duplicate key → conflict; `update` with `SELECT … FOR UPDATE`, `If-Match`, identifier rules,
  no-op detection; both in one transaction with the audit record) and
  `app/Services/MtaSts/MtaStsAudit.php` (whitelisted details of R22).
- [ ] T080 [US4] Create `app/Http/Resources/Api/V2/MtaStsPolicyResource.php` (contract
  `MtaStsPolicy`, ETag of data-model) and `app/Http/Controllers/Api/V2/MtaStsPolicyController.php`
  (`index`, `store`, `show`, `update`; domain resolution through `domain` only, so alias domain names
  answer `404`) and register the four `/mta-sts-policies` routes in `routes/api.php`. T073–T076
  pass.

**Checkpoint**: US4 works on its own.

---

## Phase 8: User Story 5 — Import, copy and delete DKIM keys (Priority: P2)

**Goal**: administrators import private keys, copy keys to one target, and delete keys one at a
time or up to 100 in one bulk request, atomically per key.

**Independent Test**: import valid and invalid keys with and without overwrite, copy to targets with
and without keys, delete domain and unused keys, and compare the hashes with legacy.

### Tests for User Story 5

- [ ] T081 [P] [US5] Write `tests/Unit/Dkim/DkimPrivateKeyRuleTest.php` with `DkimFixtures`: 1,024 to
  8,192-bit RSA in PKCS #1 and PKCS #8 accepted; CRLF and CR line endings normalised and surrounding
  whitespace trimmed; 17 KiB → `tooLong` (`max: 16384`, `unit: bytes`); garbage, a public key, a
  certificate and two keys → `invalidPrivateKey`; encrypted PKCS #8 and `Proc-Type: 4,ENCRYPTED` →
  `encryptedPrivateKey`; EC → `unsupportedKeyType` (`allowed: [rsa]`); 512 and 9,216 bits →
  `keyLengthOutOfRange` (`min: 1024`, `max: 8192`); no `message` or parameter contains any line of
  the input.
- [ ] T082 [P] [US5] Write `tests/Feature/Dkim/ImportDkimKeyTest.php`:
  - AS1: a 2,048-bit key with selector `mx2024` for a domain without a key → `201`, `Location`,
    stored public key equals legacy's derivation (`tests/fixtures/legacy/dkim`), stored private key
    is the normalised text;
  - AS2: existing key without `overwrite` → `409 dkim-key-exists`; with `overwrite` → `200`, old
    private key field gone; `If-Match` outdated → `412`;
  - AS3: each invalid key of T081 → `422` with its code; the key text appears in no response, in
    no audit record and in no log line (log captured with a test handler);
  - an import name that is a valid domain name but no domain → `201` and listed as `unused`; a
    later domain with that name sees `valid` (FR-018); an invalid name (`a..b`) → `422`
    `invalidFormat`/`domainName` on `dkimName`; an omitted selector → the default selector;
  - AS6: domain administrator → `403`; read-only key → `403 insufficient-key-scope`; Redis
    unavailable → `503`, nothing written;
  - one `dkimKeyImport` audit record per attempt with `overwriteRequested`, old and new selector,
    length and fingerprint, and the publication outcome.
- [ ] T083 [P] [US5] Write `tests/Feature/Dkim/CopyDkimKeyTest.php`:
  - AS4: A with a key, B without → `201`, B has A's selector, public key and private key; A
    unchanged;
  - B with a key → `409 dkim-key-exists`; with `overwrite` → `200`, B's old private key field gone;
    `If-Match` applies to B's key;
  - target equal to the source (also in another spelling) → `422 sameAsSource`; a target that is
    neither a domain nor an alias domain → `422 notFound` on `target`; a source without a key →
    `404`; an unused source by its exact stored name → copied; an incomplete source → `409`
    `about:blank` naming the missing parts;
  - AS6: domain administrator → `403`;
  - one `dkimKeyCopy` audit record on the target with `sourceName`.
- [ ] T084 [P] [US5] Write `tests/Feature/Dkim/DeleteDkimKeyTest.php`:
  - AS5: an unused key → `204` without body, the entry disappears from the list; a domain's key →
    `204`, the entry shows `missing`, the three hash fields are gone;
  - a name without a key → `404`; `If-Match` outdated → `412`; a legacy key under `Example.ORG`
    deleted by its exact name; Redis unavailable → `503`, nothing deleted;
  - AS6: domain administrator → `403`; read-only key → `403 insufficient-key-scope`;
  - one `dkimKeyDeletion` audit record with the old selector, length and fingerprint and the
    publication outcome (`notConfigured` by default).
- [ ] T085 [P] [US5] Write `tests/Feature/Dkim/DkimWriteAuthorizationTest.php`: the role and key
  matrix for `generateDkimKey` (with and without `overwrite`), `importDkimKey`, `copyDkimKey`,
  `deleteDkimKey` and `applyDkimKeyBulkAction` (SC-004).

- [ ] T086 [P] [US5] Write `tests/Feature/Dkim/DkimKeyBulkDeleteTest.php` (FR-059, AS7):
  - AS7: three unused keys, one changed in Redis after the client read its `etag`, and a name
    without a key, sent with the tags read → results in request order: `done`, `done`, `outdated`
    (`412` problem, key kept), `notFound`; `summary` counts match; the done keys' three hash fields
    are gone; each processed key has exactly one `dkimKeyDeletion` audit record with
    `details.bulkRequestId` equal to `data.bulkRequestId`;
  - a name sent twice (also once in upper case or Unicode) is processed and reported once; a legacy
    key stored as `Example.ORG` is deleted by its exact name; a target without `version` is not
    version-checked;
  - 101 targets → `422 tooMany` (`max: 100`) on `targets`; `action: activate` → `422 notInList`;
    a domain administrator → `403 forbidden` as a whole; a read-only key → `403
    insufficient-key-scope`; each whole-request refusal writes one refusal audit record with
    `details.itemIds`;
  - Redis unavailable → every item `failed` with `temporary-failure`, nothing removed;
  - publication disabled → no `meta`; enabled → `meta.dnsPublication` maps each `done` target to
    `removed`, `noRecordToRemove`, `noZone` or `failed` (with its reason code), and the audit
    records carry the same outcome; with every removal timing out (`FakeClock`), the 60-second
    budget is spent after four keys and the remaining deleted keys report `failed`/`timeout`
    without any ISPConfig call (`FakeIspconfigDnsClient` call log).
### Implementation for User Story 5

- [ ] T087 [P] [US5] Create `app/Rules/DkimPrivateKey.php` (R6, stops at the first failure) and
  extend `app/Logging/RedactSecrets.php` to drop context keys `privateKey` and `private_key`; add
  `privateKey` to `dontFlash` in `bootstrap/app.php`. T081 passes.
- [ ] T088 [US5] Create `app/Http/Requests/Api/V2/ImportDkimKeyRequest.php` (`privateKey` "required,
  string, at most 16,384 bytes, one unencrypted RSA private key in PEM form (PKCS #1 or PKCS #8) of
  1,024 to 8,192 bits"; `selector` as T045; `overwrite` boolean; `failedValidation()` without input
  values) and `app/Http/Requests/Api/V2/CopyDkimKeyRequest.php` (`target`
  "required, string, 1 to 255 characters", `overwrite` boolean).
- [ ] T089 [US5] Implement `import`, `copy` and `delete` in `app/Services/Dkim/DkimKeyService.php`
  (store modes of the data model, `If-Match`, publication of the target or removal, audit through
  `commitChange`).
- [ ] T090 [US5] Create `app/Http/Controllers/Api/V2/DkimKeyImportController.php` and
  `DkimKeyCopyController.php` (`201` with `Location` or `200`, both with `ETag`), implement
  `destroy` in `DkimKeyController.php` (`204`), and register `POST /dkim-keys/{dkimName}/import`,
  `POST /dkim-keys/{dkimName}/copy` and `DELETE /dkim-keys/{dkimName}` in `routes/api.php`.
  T082–T085 pass.
- [ ] T091 [US5] Create `app/Http/Requests/Api/V2/DkimKeyBulkActionRequest.php` (`action`
  "required, one of `delete`" (`notInList`); `targets` "required array of 1 to 100 `BulkTarget`
  objects" (`required`, `tooMany` with `max: 100`), each `id` "required, string, 1 to 255
  characters" and `version` "optional string"), implement `DkimKeyService::bulkDelete()` on the
  shared `App\Support\Bulk\BulkActionRunner` (research R28: deduplication after name resolution,
  version check, outcome mapping, one audit record per key with `bulkRequestId`, the publication
  budget `RESTV2_DKIM_BULK_PUBLICATION_BUDGET` added to `config/restv2.php` as
  `dkim.bulk_publication_budget`, default `60`), create
  `app/Http/Controllers/Api/V2/DkimKeyBulkActionController.php` (`200`, `data` = `BulkResult`,
  `meta.dnsPublication` only while the publication is enabled), and register the POST-only route
  `POST /dkim-keys/bulk-actions` (with `restv2.audit` defaults) before `/dkim-keys/{dkimName}` in
  `routes/api.php`. T086 passes.

**Checkpoint**: US5 works on its own.

---

## Phase 9: User Story 6 — Export a private key when the operator allows it (Priority: P3)

**Goal**: administrators export a private key while `SHOW_DKIM_PRIV_KEYS` is on; every attempt is
audited.

**Independent Test**: export with the setting off and on, as each role and key type.

### Tests for User Story 6

- [ ] T092 [P] [US6] Write `tests/Feature/Dkim/ExportDkimPrivateKeyTest.php`: AS1 setting on →
  `200`, `data.privateKey` exactly as stored, `Cache-Control: no-store`, one `dkimPrivateKeyExport`
  audit record without the key; AS2 setting off (default) → `403
  dkim-private-key-export-disabled` and a failure audit record; AS3 domain administrator → `403
  forbidden`; an administrator's read-only key → `200`; a domain administrator's key → `403`; a
  name without a key → `404`; every refusal after authentication writes one failure audit record
  (FR-026); Redis unavailable → `503`; the setting read from `vars.local.inc.php` and overridden by
  `RESTV2_SHOW_DKIM_PRIV_KEYS`; audit insert failing → `500` without the key.

### Implementation for User Story 6

- [ ] T093 [US6] Implement `DkimKeyService::export()` (order of checks of R9) in
  `app/Services/Dkim/DkimKeyService.php`, and create
  `app/Http/Resources/Api/V2/DkimPrivateKeyResource.php` and
  `app/Http/Controllers/Api/V2/DkimPrivateKeyController.php` (`Cache-Control: no-store`), and
  register `GET /dkim-keys/{dkimName}/private-key` in `routes/api.php`. T092 passes.

**Checkpoint**: US6 works on its own.

---

## Phase 10: User Story 7 — Keep the ISPConfig DNS zone in sync with DKIM keys (Priority: P3)

**Goal**: every key change updates the ISPConfig zone when enabled, with coded outcomes and a
republish operation.

**Independent Test**: with `FakeIspconfigDnsClient`, enable and disable the publication; generate,
import with another selector, copy and delete keys for names with and without a zone; make the
server fail or time out.

### Tests for User Story 7

- [ ] T094 [P] [US7] Write `tests/Feature/Dkim/DkimDnsPublicationTest.php` (publication enabled,
  `FakeIspconfigDnsClient`):
  - AS1: generation creates TXT `<selector>._domainkey` with the record value, `ttl 7200`,
    `active Y`, `server_id` from `RESTV2_ISPCONFIG_DNS_SERVER_ID`, client `sys_groupid - 1`;
    response `published`;
  - AS2: import with the same selector updates the record (one record only); with another selector
    the old record is deleted and the new one created; a copy publishes for the target; a fully
    qualified existing record is updated, not duplicated (SC-009);
  - AS3: no zone → key stored, `noZone`; a parent zone only → `noZone`;
  - AS4: unreachable server → key stored, `failed` with `reason.code unreachable` within 15 s (fake
    clock); deletion with an unreachable server → `204`, audit record with `failed`/`unreachable`;
    deletion with a record → audit `removed`, without → `noRecordToRemove`;
  - AS5: `POST /dkim-keys/{name}/dns-publication` after a failure → `200`, `published`, `ETag`;
    `If-Match` outdated → `412`; a name without a key → `404`; one `dkimDnsPublication` audit record;
  - AS6: publication disabled (default) or enabled without URL → ISPConfig never contacted
    (`assertNoCalls`), outcome `notConfigured`; republish → `403 dkim-dns-publication-disabled` and
    a failure audit record;
  - domain administrator → `403` on republish; read-only key → `403 insufficient-key-scope`;
  - the ISPConfig password appears in no response, log line or audit record.

### Implementation for User Story 7

- [ ] T095 [US7] Implement `DkimKeyService::publishAgain()` in `app/Services/Dkim/DkimKeyService.php`
  (enabled check → disabled problem, `If-Match` against the stored fingerprint, publish with the
  current selector, audit) and create `app/Http/Controllers/Api/V2/DkimDnsPublicationController.php`
  (`200` with `DkimKeyChangeResource` and `ETag`), and register
  `POST /dkim-keys/{dkimName}/dns-publication` in `routes/api.php`. T094 passes.

**Checkpoint**: US7 works on its own.

---

## Phase 11: User Story 8 — Trace DKIM and MTA-STS changes (Priority: P3)

**Goal**: every key and policy change, export and publication outcome is in the audit log and
filterable by target.

**Independent Test**: make each kind of change, including refused ones, and list the audit log by
target.

### Tests for User Story 8

- [ ] T096 [P] [US8] Write `tests/Feature/Dkim/DkimAuditTrailTest.php`: AS1 import with overwrite →
  the record has actor, time, client address, old and new selector and key length, fingerprints,
  `overwriteRequested: true` and the publication outcome, and no private key;
  `GET /audit-events?targetType=dkimKey&targetId=<name>` (002's filters) lists, for one name, the
  generation triggered by domain creation, a generation, an import, a copy onto it, an export
  attempt, a republish and a deletion, newest first; `targetId` given in Unicode or upper case
  finds the same records; refused attempts carry `details.problemType`.
- [ ] T097 [P] [US8] Write `tests/Feature/MtaSts/MtaStsAuditTrailTest.php`: AS2 a domain
  administrator changes the mode → the record shows old and new `mode` and old and new policy
  identifier; `targetType=mtaStsPolicy&targetId=<domain>` lists creation and changes.
- [ ] T098 [P] [US8] Write `tests/Feature/Audit/DkimMtaStsAuditCountTest.php` (SC-010): every
  state-changing request and every export of the feature by an authenticated caller, successful or
  refused (read-only key and restricted session refusals included), writes exactly one audit record
  (count before and after each request); `401` and CSRF refusals, lists, reads, capabilities and
  diagnostics write none.

### Implementation for User Story 8

- [ ] T099 [US8] Extend 002's `app/Http/Requests/Api/V2/ListAuditEventsRequest.php` so that
  `targetId` is normalised (trim, lower-case, UTS #46) for the target types `dkimKey` and
  `mtaStsPolicy` as it is for `domain`, while an exact legacy spelling is also matched for
  `dkimKey`. T096–T098 pass.

**Checkpoint**: all user stories are complete.

---

## Phase 12: Polish & cross-cutting concerns

- [ ] T100 [P] Write `tests/Feature/Dkim/DkimSecretLeakTest.php` (SC-005, FR-027): run a scenario
  through every DKIM operation with logging captured, then scan the captured log, every response
  body of the scenario and every `restv2_audit_events.details` for `PRIVATE KEY` armour and for the
  base64 body lines of the fixture keys; matches are allowed only in export responses.
- [ ] T101 [P] Write `tests/Feature/Dkim/DkimListPerformanceTest.php` (group `performance`, SC-012):
  seed 5,000 domains, 5,000 alias domains and their 2,048-bit public keys (fixture texts, multi-row
  inserts and pipelined `HSET`), then assert that 20 requests for a page of 25 entries (default
  sort, then `sort=keyLength`) answer within 1 s at p95.
- [ ] T102 [P] Write `tests/Feature/DnsDiagnostics/DnsDiagnosticsPerformanceTest.php` (group
  `performance`, SC-006): with `FakeDnsResolver` answering every lookup after 20 ms, 20 runs finish
  within 5 s at p95; with every lookup timing out, each run returns within the 30-second deadline.
- [ ] T103 [P] Extend `database/seeders/DevelopmentSeeder.php`: alias domain `example.com` →
  `example.org`, a 2,048-bit DKIM key for `example.org`, an unused key for `old.example`, as
  quickstart Part B expects.
- [ ] T104 Run `composer test` and confirm that `build/contract/coverage.json` lists the 15 operations
  of this feature (`listDkimKeys`, `generateDkimKey`, `getDkimKey`, `deleteDkimKey`, `applyDkimKeyBulkAction`,
  `importDkimKey`, `copyDkimKey`, `exportDkimPrivateKey`, `publishDkimDnsRecord`,
  `getDnsCapabilities`, `listMtaStsPolicies`, `createMtaStsPolicy`, `getMtaStsPolicy`,
  `updateMtaStsPolicy`, `runDnsDiagnostics`) as covered; fix gaps.
- [ ] T105 [P] Run `npx --yes @redocly/cli@2.64.1 lint api/openapi.yaml`, `vendor/bin/pint --test` and
  `vendor/bin/phpstan analyse`; fix every finding in this feature's files.
- [ ] T106 [P] Update `docs/legacy-inventory.md`: mark the DKIM, MTA-STS and DNS diagnostics rows as
  covered by `/api/v2` with links to the operations, and note the deviations of the spec's parity
  table and the plan's parity notes.
- [ ] T107 Walk through `specs/010-dkim-mta-sts-dns/quickstart.md` Part A and Part B against the dev
  stack and correct the guide where the output differs.

---

## Dependencies & Execution Order

### Phase dependencies

- **Phase 1 (Setup)**: after 001. T003, T004, T007, T008 in parallel; T006 after T005.
- **Phase 2 (Foundational A, shared DKIM core)**: after Phase 1. **Implemented together with feature
  002**, before 002's domain creation tasks; feature 004's alias domain creation also depends on
  it. Tests T009–T014 first; then T015–T019 in parallel, T020 after T018/T019, T021–T023 in order.
- **Phase 3 (Foundational B)**: when feature 010 starts (after 002–009). Blocks every story.
- **US1 (Phase 4)** → MVP. **US2 (Phase 5)** needs Phase 2 and Phase 3 only. **US3 (Phase 6)** needs
  Phase 3 (and reads DKIM keys and MTA-STS rows through the store and the model, not through US1,
  US2 or US4 code). **US4 (Phase 7)** needs Phase 3. **US5 (Phase 8)** needs US2's
  `DkimKeyChangeResource` (T047). **US6 (Phase 9)** needs Phase 3. **US7 (Phase 10)** needs US2 and
  US5 for its end-to-end cases. **US8 (Phase 11)** needs US2, US4, US5, US6 and US7 for its full
  trail.
- **Polish (Phase 12)**: after the stories.

### Cross-feature dependencies

- 001: the whole platform (pipeline, identity, keys, `MailcowConfig`, `AuditLogger`, contract
  validation).
- 002: `App\Support\Domain\DomainName`, `App\Rules\DkimSelector`, `RESTV2_DEFAULT_DKIM_SELECTOR`, `SharedStore`, the
  `temporary-failure` problem, the audit target filters (`targetType`, `targetId`) and the
  `domain` rows in tests. 002 in turn needs Phase 2 of this file.
- 004: `AliasDomain` model and rows (created here in T026 if 004 has not), and `generateForNewName()`
  for alias domain creation.
- 008: `App\Services\Audit\RefusalAuditor` and the `restv2.audit` route defaults (T032, T033).
- 009: `App\Clients\Dns\DnsResolver`/`NativeDnsResolver`/`DnsTemporaryFailure`,
  `App\Clients\Smtp\SmtpProbe`/`StreamSmtpProbe`, `FakeDnsResolver`, `FakeSmtpProbe`,
  `ScriptedSmtpServer` and the override's `dns:`/`dns_opt`, extended in T048, T053, T054, T061,
  T062.

### Within each phase

- Tests first, observed failing; then models and value objects, services, requests, resources,
  controllers; a phase is done when its tests pass and contract validation is green.

## Parallel Example: User Story 3

```bash
# Test support and unit tests together (different files):
T048 FakeDnsResolver/FakeSmtpProbe (009, extended)   T049 RecordComparatorTest   T050 SpfEvaluatorTest
T051 ExpectedRecordSetTest   T052 ZoneFileRendererTest   T053 NativeDnsResolverTest (extended)
T054 StreamSmtpProbeTest (extended), TlsaDigestTest   T055 PublicAddressDetectorTest
# Feature tests together:
T056 RunDnsDiagnosticsTest   T057 StatusesTest   T058 TimeoutTest   T059 RateLimitTest   T060 AuthorizationTest
# Independent implementation pieces together:
T061 DnsResolver extension   T062 SmtpProbe extension   T063 PublicAddressDetector   T064 RecordComparator
T065 SpfEvaluator   T067 ZoneFileRenderer
# Then in order: T066 ExpectedRecordSet → T068 DnsDiagnosticsService → T069 limiter → T070 controller
```

## Implementation Strategy

### MVP first

1. Phase 2 already exists (delivered with 002). Complete Phase 1 leftovers and Phase 3.
2. Complete US1 (Phase 4): administrators and domain administrators see every key and record.
3. Validate US1 independently, then continue.

### Incremental delivery

1. US1 → US2 → US3 (all P1): read, generate, diagnose. Release the contract to mailcowui-ng.
2. US4 and US5 (P2): MTA-STS and key management.
3. US6, US7, US8 (P3): export, ISPConfig sync, audit trail.
4. Polish.

## Task count

| Phase | Tasks |
|---|---|
| Phase 1: Setup | 8 (T001–T008) |
| Phase 2: Foundational A (with 002) | 15 (T009–T023) |
| Phase 3: Foundational B | 10 (T024–T033) |
| Phase 4: US1 (P1) | 9 (T034–T042) |
| Phase 5: US2 (P1) | 5 (T043–T047) |
| Phase 6: US3 (P1) | 23 (T048–T070) |
| Phase 7: US4 (P2) | 10 (T071–T080) |
| Phase 8: US5 (P2) | 11 (T081–T091) |
| Phase 9: US6 (P3) | 2 (T092–T093) |
| Phase 10: US7 (P3) | 2 (T094–T095) |
| Phase 11: US8 (P3) | 4 (T096–T099) |
| Phase 12: Polish | 8 (T100–T107) |
| **Total** | **107** |
