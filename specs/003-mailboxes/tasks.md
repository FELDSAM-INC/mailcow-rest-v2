---
description: "Task list for feature 003: mailboxes"
---

# Tasks: Mailboxes

**Input**: Design documents from `specs/003-mailboxes/`: [plan.md](./plan.md), [spec.md](./spec.md),
[research.md](./research.md), [data-model.md](./data-model.md), [quickstart.md](./quickstart.md).
The contract is `api/` (modules `mailboxes`, `mailbox-templates`, `password-policy`; already
authored and lint-clean). Problem types are in `docs/problems.md`.

**Prerequisites**: features 001 and 002 are implemented and merged. This feature uses their code
as listed in [plan.md](./plan.md#dependencies-on-earlier-features): 001's pipeline, identity,
`PermissionFlag`, `ProblemType`, `ApiFormRequest`, `ListQuery`, `ETag`, `AuditLogger`,
`MailcowConfig`, `LegacyPasswordVerifier`, `MailcowDateTime`, the upstream models and the test
toolkit; 002's `DomainName`, `DomainScope`, `DomainUsageCalculator`, `DomainTagSet`,
`SogoDirectory`, `MemcachedFlusher`, `DockerApiClient`, `SharedStore`, `RateLimitStore`,
`FieldViolations`, `WholeMebibytes`, `AuditLogger::completeFollowUps()`, the audit target filters,
the models `Alias` and `AliasDomain`, and the fakes `FakeDockerApiClient` and
`FakeMemcachedFlusher`. A task that "extends" a file changes code that 001 or 002 created.

**Tests**: mandatory (constitution VII). In every phase the test tasks come first and must be
observed **failing** before the implementation tasks of that phase start. Every feature test runs
on MariaDB with the `2026-09a` schema fixture and on a real Redis (database 15), and every request
and response is validated against the contract by `tests/TestCase.php`. dockerapi and memcached
are faked (`FakeDockerApiClient`, `FakeMemcachedFlusher`) and the fakes assert the calls made.
Authorisation tests cover, for every operation: administrator session, domain-administrator
session (own domain, foreign domain), mailbox-user session, restricted session, read-only and
read-write keys owned by an administrator and by a domain administrator, and no credentials.

**Commands**: every PHP, Composer and Node command runs in the dev container
(`docker compose run --rm app <command>`).

## Format: `- [ ] [ID] [P?] [Story?] Description with file path`

- **[P]**: can run in parallel with the other [P] tasks of the same block (different files, no
  dependency on unfinished tasks).
- **[USn]**: the user story of spec.md. Setup, Foundational and Polish tasks have no story label.
- FR, SC and AS references point to spec.md, R references to research.md.

---

## Phase 1: Setup (settings, deployment and test tooling)

**Purpose**: configuration, deployment files and test support. No business logic.

- [X] T001 Extend `config/restv2.php` with the settings of research R23: `mailcow_pass_scheme`
  (`env('MAILCOW_PASS_SCHEME', 'BLF-CRYPT')`), `maildir_gc_time` (`(int) env('MAILDIR_GC_TIME',
  7200)`), `cluster_mode` (`env('CLUSTERMODE', '')`), `master` (`env('MASTER', 'y')` matched by
  `/y|yes/i` as legacy), and `mailbox_custom_attribute_limit` (`RESTV2_MAILBOX_CUSTOM_ATTRIBUTE_LIMIT`,
  default 100, clamped to 1–100). Add `MAILCOW_PASS_SCHEME=BLF-CRYPT`, `MAILDIR_GC_TIME=7200`,
  `MASTER=y` to `.env.example` and `.env.testing`. Add the tags `Mailboxes` and `Mailbox templates`
  and the single operation `getPasswordPolicy` to `tests/contract-scope.php` (002's T004); extend
  the scope file and `tests/bin/check-contract-coverage.php` to accept operation IDs, because the
  `Password policy` tag also holds feature 013's `updatePasswordPolicy`.
- [X] T002 [P] Update `deploy/docker-compose.override.yml`: pass `MAILCOW_PASS_SCHEME`,
  `MAILDIR_GC_TIME`, `CLUSTERMODE`, `MASTER`, `RESTV2_SHOW_LAST_LOGIN`,
  `RESTV2_MAILBOX_DEFAULT_ATTRIBUTES` and `RESTV2_MAILBOX_CUSTOM_ATTRIBUTE_LIMIT` to the
  `restv2-mailcow` environment (empty defaults). Update `deploy/nginx/site.restv2.custom` with
  `fastcgi_read_timeout 900s;` in the `/api/v2/` location (R26).
- [X] T003 [P] Write the failing cases first in `tests/Unit/Services/MailcowConfigTest.php`:
  `showLastLogin()` (default `true`; `false` from a fixture `vars.local.inc.php`; `RESTV2_SHOW_LAST_LOGIN=n`
  wins) and `mailboxDefaultAttributes()` (upstream values of R5 when unset; a fixture override of
  `sogo_access`; `RESTV2_MAILBOX_DEFAULT_ATTRIBUTES` JSON wins). Add the fixture files under
  `tests/fixtures/mailcow-inc/`. Then extend `resources/bin/export-mailcow-vars.php` to export
  `SHOW_LAST_LOGIN` and `MAILBOX_DEFAULT_ATTRIBUTES`, and `app/Services/Config/MailcowConfig.php`
  with `showLastLogin(): bool` and `mailboxDefaultAttributes(): array` (001 R14 precedence, merged
  over the upstream defaults).
- [X] T004 [P] Extend `database/factories/MailcowRows.php` and
  `tests/Support/Mailcow/MailcowFactory.php` with writers that insert rows exactly as legacy does
  (data-model.md, research R25): `mailbox(address, password = 'Passw0rd!dev', scheme, active = 1,
  quota = 0, attributes = legacy string set, customAttributes = {}, authsource = 'mailcow', kind = '')`
  writing `mailbox`, `quota2`, `quota2replica`, the own `alias` and `user_acl`; `mailboxTag()`;
  `usage(address, bytes, messages, replica = false)`; `mailboxTemplate(name, attributes)` and
  `defaultMailboxTemplate()` (the exact array `init_db.inc.php` seeds); `alias(address, goto[],
  …)`; `spamalias()`; `senderAcl(loggedInAs, sendAs, external)`; `syncJob(user2)`;
  `filterconf(object, option, value)`; `quarantineItem(rcpt)`; `pushover(username, active)`;
  `bccMap(localDest, bccDest, type)`; `recipientMap(old, new)`; `sieveFilter(username)`;
  `appPassword(mailbox)`; `sogoData(username)` (profile, cache folder, folder info with store,
  quick contact, quick appointment and ACL rows); `oauthTokens(userId)`; `saslLog(username,
  service, datetime)`; `identityProvider('ldap'|'keycloak'|'generic-oidc')`.
- [X] T005 [P] Extend `tests/Support/Fakes/FakeDockerApiClient.php` (002): scripted answers per
  `{service, cmd, task}` (`success`, `failed(message)`, `unknown`, `unreachable`), optionally per
  call number; a programmable `get_acl` answer; `reachable()` per service; a broadcast log;
  assertions `assertExecuted(service, command)`, `assertNotExecuted(...)`, `assertExecOrder([...])`,
  `assertBroadcast(service, command)`. Write `tests/Unit/Support/FakeDockerApiClientTest.php`.

---

## Phase 2: Foundational (blocking prerequisites)

**Purpose**: addresses, scope, passwords, version tags, groupware and container helpers,
permission guard, problem types and audit vocabulary. **No user story can start before this phase
is complete.**

### Tests for the foundation (write first, observe failing) ⚠️

- [X] T006 [P] Write `tests/Unit/Passwords/MailboxPasswordHasherTest.php` (FR-024, R3): for
  `MAILCOW_PASS_SCHEME` `BLF-CRYPT` (`{BLF-CRYPT}$2y$10$…`), `SSHA`, `SSHA256`, `SSHA512` (salt of
  16 hex characters inside the base64) and an unknown value (falls back to BLF-CRYPT), the hash
  verifies with `App\Support\Hashing\LegacyPasswordVerifier` and with the legacy
  `legacy_verify_hash()` fixture; every prefix of legacy's pre-hash pattern, in any case, is
  returned unchanged with `preHashed = true`; `{PBKDF2-SHA512}…` is **not** recognised (legacy
  pattern), so it is hashed.
- [X] T007 [P] Write `tests/Feature/PasswordPolicy/PasswordPolicyReaderTest.php` (FR-063, R4) on
  real Redis: no `PASSWD_POLICY` → 6/false×4; stored `length=10, numbers=1` → those values; a
  non-numeric length → 6; `chars=2` → false; the tag equals `ETag::strong('length=10;chars=0;
  special_chars=0;lowerupper=0;numbers=1')` and changes when a field changes; a closed Redis port
  raises `SharedStoreUnavailable`. Write `tests/Unit/Passwords/PasswordPolicyCheckerTest.php`:
  "short" against length 10 + digits → `tooShort {min: 10}` and `missingCharacterClass
  {characterClass: digit}`; byte length (`"ääääa"` is 9 bytes); each class rule; all rules met →
  no violation.
- [X] T008 [P] Write `tests/Unit/Mailboxes/MailboxAddressTest.php` (FR-007, FR-019, R7):
  `" Anna.Smith@Example.org "` → `anna.smith@example.org`; `bücher.example` domains in Unicode and
  ASCII give the same address and `domainUnicode`; local parts with every allowed special
  character pass; leading, trailing and double dots, spaces, 65 characters, non-ASCII local parts
  fail with `invalidFormat {format: localPart}` or `tooLong {max: 64}`; a 256-character address
  fails with `tooLong {max: 255}`. Extend `tests/Unit/Http/ValidationRuleCodeTest.php` (001) with
  the codes and params this feature adds: `missingCharacterClass {characterClass}`,
  `conflictsWith {field, value}`, `unchanged`, `outOfRange` with `limit`, `notEligible` with
  `kind`.
- [X] T009 [P] Write `tests/Feature/Mailboxes/MailboxScopeTest.php` (FR-001–FR-003): an
  administrator resolves every mailbox; a domain administrator resolves mailboxes of actively
  assigned domains only (inactive assignment → `null`, inactive domain still in scope); a resource
  (`kind = 'location'`) and an unknown address resolve to `null` for everyone; an address with an
  alias domain resolves to `null`; changing the assignment between two calls is seen at once.
- [X] T010 [P] Write `tests/Feature/Mailboxes/MailboxVersionTest.php` (FR-038, R11): the tag is
  stable for unchanged rows; it changes when the legacy UI's statements change `name`, `quota`,
  `active`, an `attributes` key, `custom_attributes`, a `user_acl` column or a tag; it does not
  change when `quota2.bytes`, `sasl_log`, `RL_VALUE` or `mailbox.modified` change. Template tags
  change with `template` or `attributes`.
- [X] T011 [P] Write `tests/Feature/Groupware/SogoDirectoryRefreshTest.php` (R14): `refresh([a])`
  inserts an active mailbox's row with legacy's columns (aliases from `grouped_mail_aliases`, a
  `{SSHA256}` `c_password` that differs between calls), updates it after a name change, deletes
  the row of a mailbox that became inactive or was deleted, removes orphans, and touches nothing
  when `SKIP_SOGO=y`.
- [X] T012 [P] Extend `tests/Unit/Clients/HttpDockerApiClientTest.php` (002) with `Http::fake()`
  cases (R16): `reachable()` true when the container list has the service of
  `COMPOSE_PROJECT_NAME`, false when absent or the API is down; a connection error yields
  `failed` with `unreachable: true`; after a connection error or a timeout, later calls in the same
  request return the same result without HTTP traffic (circuit breaker). Write
  `tests/Feature/Clients/DockerApiBroadcastTest.php` with 001's `ChannelSpy` on `MC_CHANNEL`:
  `broadcast('dovecot-mailcow', {cmd: maildir, task: cleanup, maildir: 'd/l'})` publishes exactly
  `{"api_call":"container_post","container_name":"dovecot-mailcow","post_action":"exec","request":{…}}`.
- [X] T013 [P] Write `tests/Unit/Support/TagSetTest.php` (FR-036, R20): trim, empty → `required`
  on `tags.<i>`, 256 characters → `tooLong`, "Sales"/"sales" → one tag keeping "Sales", 26 tags →
  `tooMany {max: 25}`, a current set of 30 may shrink to 28 but not grow, and 002's existing
  `DomainTagSet` tests still pass unchanged.
- [X] T014 [P] Write `tests/Unit/Mailboxes/MailboxPermissionGuardTest.php` (FR-006, FR-035, R8),
  with a domain administrator lacking each flag in turn: a value equal to the baseline is
  accepted; a different `protocolAccess.pop3` → `protocolAccess`; `webmailRedirect` → `sogoAccess`;
  `rateLimit` at creation → `ratelimit`; `userPermissions.syncjobs` → `syncjobs` (and the other four
  overlapping flags); `quota: 0` → `unlimitedQuota` unless the current quota is 0, and also when 0
  comes from the template; several refusals are reported together with `attributes` and
  `requiredFlags`; an administrator is never refused.

- [X] T015 [P] Write `tests/Feature/Database/AddressLockTest.php` (R7): `AddressLock::for($address)`
  uses the name `restv2_address_<first 40 hex of sha1(normalised address)>`; a second database
  connection holding the lock makes `GET_LOCK(name, 5)` time out and the caller answer `500`; the
  lock is released in `finally` when the guarded code throws; two different addresses do not block
  each other; `NamedLock` holds several locks on one connection (rename).
- [X] T016 [P] Write `tests/Feature/Audit/MailboxRefusalAuditTest.php` (`docs/conventions.md`
  "Refusal auditing", FR-056, SC-010): for each of the eight state-changing operations
  (`createMailbox`, `updateMailbox`, `deleteMailbox`, `renameMailbox`, `applyMailboxBulkAction`,
  `createMailboxTemplate`, `updateMailboxTemplate`, `deleteMailboxTemplate`), a refusal by an
  authenticated caller writes exactly one `failure` record with the event type, the target
  (`mailbox` with the address as sent, `mailboxTemplate` with the id, `null` with
  `details.itemIds` for a whole-request bulk refusal), the reason and `details.problemType`: a
  read-only key (`insufficient-key-scope`), a restricted session (`pending-action-required`), a
  mailbox user or a domain administrator writing templates (`permissionDenied`), a protocol bulk
  action without `protocolAccess` (`permission-flag-required`, `permissionDenied`, `null` target),
  a field the caller's flags do not allow (`attributeNotPermitted`), an out-of-scope or unknown mailbox (`notFound`), an outdated version
  (`versionOutdated`), a validation failure, a conflict; a CSRF failure and a `401` write none; a
  refusal inside a rename or deletion after the preflight is recorded once, not twice; a `503`
  creation (shared store down during the policy check) and a `503` deletion (mail store down) each
  write one `temporaryFailure` record written by the service.

### Implementation of the foundation

- [X] T017 [P] Extend `app/Models/Mailcow/Mailbox.php` (001) with every column of data-model.md,
  `$keyType = 'string'`, casts (`attributes` and `custom_attributes` → array, `created`/`modified`
  → `MailcowDateTime`), and the scopes `mailboxesOnly()` (`kind = ''`) and `inScope(Principal)`.
  Create read-only models in `app/Models/Mailcow/`: `MailboxTemplate.php` (table `templates`,
  global scope `type = 'mailbox'`), `MailboxTag.php` (`tags_mailbox`), `QuotaUsage.php`
  (`quota2`), `QuotaReplicaUsage.php` (`quota2replica`), `SpamAlias.php` (`spamalias`),
  `SenderAcl.php` (`sender_acl`) and `Pushover.php` (`pushover`), each with explicit `$table`,
  `$primaryKey`, `$timestamps = false`, `$guarded = ['*']`.
- [X] T018 [P] Create `app/Services/Mailboxes/MailboxAddress.php` (value object of data-model.md,
  using 002's `App\Support\Domain\DomainName`), `app/Rules/MailboxLocalPart.php` and
  `app/Rules/CustomAttributeName.php` (`^\S{1,255}$`), each reporting its rule code. This makes
  T008 pass.
- [X] T019 Create `app/Services/Mailboxes/MailboxScope.php` (`resolve(Principal, string $raw):
  ?Mailbox` and `domainInScope(Principal, string $domain): bool`, reusing 002's `DomainScope`),
  `app/Policies/MailboxPolicy.php` (`viewAny`, `view`, `create`, `update`, `delete`, `rename`,
  `bulk`, `previewDeletion`, `viewCapabilities`, `viewTags`, `viewPasswordPolicy`: admin or domain
  admin) and `app/Policies/MailboxTemplatePolicy.php` (`viewAny`, `view`: admin or domain admin;
  `create`, `update`, `delete`: admin), registered in `app/Providers/AppServiceProvider.php`; a
  refusal renders `403 forbidden`, a `null` scope result `404 about:blank`. This makes T009 pass.
- [X] T020 Create `app/Services/Passwords/MailboxPasswordHasher.php` (`hash(string): HashedPassword`
  with `value` and `preHashed`), `PasswordPolicyReader.php` (`effective(): EffectivePasswordPolicy`,
  on 002's `SharedStore` connection), `EffectivePasswordPolicy.php` (with `etag()` over the
  canonical string of R4) and `PasswordPolicyChecker.php` (`violations(string, EffectivePasswordPolicy):
  array`). This makes T006 and T007 pass.
- [X] T021 [P] Create `app/Services/Mailboxes/MailboxVersion.php` (`forMailbox(row, userAcl, tags)`,
  `forTemplate(row)`, through `App\Support\Http\ETag`; canonical JSON with recursively sorted
  keys). This makes T010 pass.
- [X] T022 Extend `app/Services/Groupware/SogoDirectory.php` (002) with `refresh(array $usernames):
  string` (`done`, `failed`, `groupwareDisabled`) as in R14, reusing the statement text of
  `rebuild()` with the `AND mailbox.username = ?` condition. This makes T011 pass.
- [X] T023 Extend `app/Clients/DockerApi/DockerApiClient.php`, `HttpDockerApiClient.php` and
  `DockerCallResult.php` (002) with `reachable()`, `broadcast()` (on the `mailcow` Redis
  connection), the `unreachable` flag and the per-request circuit breaker (R16). Create
  `app/Services/Mailboxes/MailStoreCommands.php` with `cleanupMaildir(domain, localPart)`,
  `moveMaildir(domain, old, new)`, `getAcls(address): array`, `deleteAcl(user, mailbox, id)`,
  `setAcl(user, mailbox, id, rights)` and `renameWebmailUser(old, new)`, building exactly the
  commands of data-model.md and, when `config('restv2.cluster_mode') === 'replication'`, adding the
  broadcast after a successful local maildir command. This makes T012 pass.
- [X] T024 [P] Generalise `app/Services/Domains/DomainTagSet.php` (002) into
  `app/Support/Tags/TagSet.php` (`normalise(array $tags, array $current, int $limit,
  string $field = 'tags')`), and make `DomainTagSet` delegate to it. This makes T013 pass.
- [X] T025 Create `app/Services/Mailboxes/MailboxDefaults.php` and
  `app/Services/Mailboxes/ResolvedMailboxValues.php` (R5): the built-in defaults from
  `MailcowConfig::mailboxDefaultAttributes()` and the `user_acl` column defaults, template decoding
  (legacy keys of data-model.md, tolerant of missing keys and integer or string flags), the chain
  request → named template → Default template → built-in with the source of each value, the quota
  rule (named template, else 002's default quota for a new mailbox, with the exhausted-quota
  refusal), the display-name rule and the external-source overrides.
- [X] T026 Create `app/Services/Mailboxes/MailboxPermissionGuard.php` (`check(Principal, array
  $requestMembers, array $baseline, ?int $currentQuota): void`, throwing the
  `attribute-not-permitted` problem with `attributes` and `requiredFlags`). This makes T014 pass.
- [X] T027 [P] Create `app/Support/Database/NamedLock.php` (`run(string $name, int $timeout,
  callable $fn)` and `acquire()`/`release()` with `GET_LOCK`/`RELEASE_LOCK` on the `mysql`
  connection, timeout → `500`; reused by 005 and 009) and `app/Services/Mailboxes/AddressLock.php`
  (`for(string $normalisedAddress)`, name of research R7). This makes T015 pass.
- [X] T028 Extend the shared vocabulary:
  - `app/Support/Http/ProblemType.php` (001): cases `AddressInUse`, `MailboxLimitReached`,
    `MailboxNotRenamable`, `MailboxRenameIncomplete`, `OutcomeUnknown` (status 504),
    `MailboxTemplateNameExists`, `MailboxTemplateProtected`, and `DomainAliasLimitReached`
    (feature 004's contract type, implemented here first because 004 comes later; 004 reuses the
    case), and `PermissionFlagRequired` (`docs/conventions.md` "Permission refusals"; feature
    009's contract type, implemented here first) with the extension `requiredFlags`;
  - `app/Clients/SharedStore/SharedStoreUnavailable.php` rendering (002, `bootstrap/app.php`): add
    the extension `dependency: sharedStore`; create
    `app/Exceptions/MailboxDependencyUnavailable.php` (`dependency`, optional `steps`, rendered as
    `503 temporary-failure` with `Retry-After: 30`) and `app/Exceptions/OutcomeUnknown.php` (`step`,
    optional `steps`, `504`);
  - `app/Http/Requests/Api/V2/ApiFormRequest.php` and `app/Support/Http/FieldViolations.php`: the
    rule codes `missingCharacterClass`, `conflictsWith`, `unchanged`, and the params `limit` on
    `outOfRange` and `kind` on `notEligible`;
  - `app/Services/Audit/AuditLogger.php`: the event types `mailboxCreation`, `mailboxUpdate`,
    `mailboxDeletion`, `mailboxRename`, `mailboxTemplateCreation`, `mailboxTemplateUpdate`,
    `mailboxTemplateDeletion`, the target types `mailbox` and `mailboxTemplate`, the failure
    reasons `outcomeUnknown` and `partiallyCompleted`, the `details` whitelist of R22 (never a
    password or hash), and `creationReport` as a completable key for `mailboxCreation`;
  - refusal auditing: reuse `app/Services/Audit/RefusalAuditor.php` and its route default
    `restv2.audit` if feature 002 created them; otherwise create them as feature 008's research R16
    describes (hooked into 001's problem rendering in `bootstrap/app.php`; authenticated principal,
    4xx, not `csrf-token-mismatch`). Create `app/Services/Mailboxes/MailboxAuditTargetResolver.php`
    for the targets `mailbox` and `mailboxTemplate` (research R22) and register it. Every
    state-changing route of this feature declares `restv2.audit` (the bulk route with
    `'event' => 'fromBulkAction'`). `RefusalAuditor` covers 4xx refusals; the services write the
    failure records of their own `5xx` outcomes (`temporaryFailure`, `outcomeUnknown`,
    `partiallyCompleted`, unexpected failures after a mail move). This makes T016 pass once the
    routes exist (each story's route task).

  Extend `tests/Unit/Support/ProblemTypeConsistencyTest.php` (001) expectations if needed so that
  the `ProblemType` cases still equal the sections of `docs/problems.md`.

**Checkpoint**: the foundation tests are green. User stories can start.

---

## Phase 3: User Story 1 — List, search and inspect mailboxes (P1) 🎯 MVP

**Goal**: administrators and domain administrators page, filter, sort and search the mailboxes of
their scope and inspect one with its full representation and version tag (FR-001–FR-017).

**Independent test**: seed mailboxes, resources and two domain administrators; list with each
filter, search and sort; inspect single mailboxes; compare with the database (spec US1).

### Tests for User Story 1 (write first, observe failing) ⚠️

- [X] T029 [P] [US1] Write `tests/Feature/Mailboxes/ListMailboxesTest.php` for `listMailboxes`:
  - AS-1: 30 mailboxes and 2 resources in three domains → first 25 by address, `meta.total` 30,
    no resource; `limit`/`offset` paging; `limit=1001` → `422 outOfRange` on `limit`.
  - AS-2: a domain administrator of `a.example` sees only its mailboxes; `domain=b.example` →
    empty, total 0; `domain=BÜCHER.example` matches the ASCII domain.
  - AS-4: `q=ANNA` returns the address match, the display-name match and the tag match.
  - Filters `status` (each value and combinations), `tag` (whole tag, case-insensitive, any of
    several; "Sales" does not match "Salesforce"), `authSource`, combined with `q`.
  - AS-5 and FR-015: every sort field ascending and descending, ties by address, missing values
    last in both directions (unlimited quota for `percentUsed`, no login for `lastMailLogin`, no
    `passwd_update`, `modified` NULL); `lastMailLogin` ignores `SSO` and `MAILCOWUI` records.
  - FR-016: each entry carries the full `Mailbox` and an `etag` equal to `getMailbox`'s `ETag`.
  - AS-8: with `RESTV2_SHOW_LAST_LOGIN=n`, every `lastLogins` member is `null`.
  - Shared store down (closed port on the `mailcow` connection) → `503 temporary-failure` with
    `dependency: sharedStore`.
  - Authorisation: AS-7 mailbox user → `403 forbidden`; read-only and read-write keys of an
    administrator list everything; a domain administrator's key lists its scope; restricted
    session → `403 pending-action-required`; no credentials → `401`.
- [X] T030 [P] [US1] Write `tests/Feature/Mailboxes/GetMailboxTest.php` for `getMailbox`:
  - every member of `Mailbox` against the seeded rows: `domainUnicode`, `quotaUsed`,
    `messageCount`, `percentUsed` (rounded; `null` for quota 0), `largestAllowedQuota`
    (`min(maxquota, quota − other mailboxes)`, never negative), `passwordChangedAt` from a
    `NOW()`-style string and from a Unix timestamp, `lastLogins` per service, `recoveryEmail`,
    `taggedMailHandling` from each Redis hash (subject wins), `rateLimit` from the mailbox and,
    when absent, from the domain with `rateLimitSource`, `relayhostId` (`"0"` → `null`),
    `activeTemporaryAliases` (expired excluded, permanent included), `pushNotificationsActive`,
    `domainRelayed`;
  - FR-011: a mailbox without protocol keys reports them `true`, without forced flags `false`;
  - `MASTER=n` reads `quota2replica`;
  - FR-035: a domain administrator without `syncjobs` does not see `userPermissions.syncjobs`
    (and likewise for the four other overlapping flags); an administrator sees all fifteen;
  - AS-6: after the legacy UI's `UPDATE mailbox SET quota = …`, the next read shows the new quota
    and a different `ETag`;
  - AS-3 and FR-003: a domain administrator reading a mailbox of another domain, any caller reading
    a resource address or an unknown address → identical `404` bodies apart from `requestId`;
  - the address in mixed case and with a Unicode domain (percent-encoded) resolves;
  - authorisation as in T029.
- [X] T031 [P] [US1] Write `tests/Feature/Mailboxes/MailboxListQueryCountTest.php` (FR-016,
  SC-002): with `DB::listen`, the number of SQL statements for a page of 10 and a page of 100
  mailboxes is the same constant, and Redis is called at most three times per page.

### Implementation for User Story 1

- [X] T032 [US1] Create `app/Services/Mailboxes/MailboxListQuery.php` (R10: scope, filters, search
  with 001's `ListQuery` escaping, the sort expressions with `IS NULL` ordering and the address
  tie-break, the count query) and `app/Http/Requests/Api/V2/ListMailboxesRequest.php` (`limit`
  1–1000 default 25, `offset` ≥ 0, `sort` enum of `MailboxSort`, `order` `asc|desc`, `q` ≤ 255,
  `domain[]` ≤ 100 items ≤ 255 characters, `status[]` ⊂ `active|loginDisabled|inactive`, `tag[]` ≤
  100 items ≤ 255 characters, `authSource[]` ⊂ `mailcow|keycloak|genericOidc|ldap`).
- [X] T033 [US1] Create `app/Services/Mailboxes/MailboxReader.php`: `page(Principal, ListMailboxesRequest)`
  and `one(Principal, Mailbox)` with the batch loading of R10 and the representation rules of R2
  (usage table by `MASTER`, domain figures from 002's `DomainUsageCalculator`, Redis `HMGET`s
  through `SharedStore`, last logins honouring `MailcowConfig::showLastLogin()`, permission
  omission for domain administrators, `MailboxVersion` for `etag`).
- [X] T034 [US1] Create `app/Http/Resources/Api/V2/MailboxResource.php` (contract `Mailbox`,
  status, auth-source, quarantine-category and frame mappings of research R1) and
  `app/Http/Controllers/Api/V2/MailboxController.php` with `index` and `show` (`ETag` header).
  Add to `routes/api.php`, under `restv2.auth:authenticated` and `restv2.pending:enforce`:
  `GET /mailboxes` and `GET /mailboxes/{mailbox}` (registered after the literal
  `/mailboxes/bulk-actions` route of US7; use `->where('mailbox', '[^/]+@[^/]+')`). This makes T029–T031 pass.

**Checkpoint**: US1 is usable on its own; the list and the single read pass under contract
validation.

---

## Phase 4: User Story 2 — Create a mailbox (P1)

**Goal**: create mailboxes with templates, domain limits, the password policy and mailcow's hash
scheme, domain-administrator checks and follow-up steps; read the capabilities and the password
policy (FR-006, FR-018–FR-028, FR-063, FR-064). Depends on US1's `MailboxReader` and resource.

**Independent test**: create mailboxes with and without templates, as each role and key, at each
domain limit; compare rows, Redis and container calls with legacy (spec US2).

### Tests for User Story 2 (write first, observe failing) ⚠️

- [X] T035 [P] [US2] Write `tests/Feature/Mailboxes/CreateMailboxTest.php` for `createMailbox`:
  - AS-1: `" Anna.Smith "` in `"Example.org"` → `201`, `Location: /api/v2/mailboxes/anna.smith@example.org`,
    `ETag`, no `password` member anywhere in the body; rows exactly as legacy (data-model.md):
    `mailbox` with the string `attributes` set (`mailbox_format` `maildir:`, `relayhost` `"0"`,
    `attribute_hash` `""`, `passwd_update` a `YYYY-MM-DD HH:MM:SS` value), `quota2` and
    `quota2replica` with 0/0, the own `alias` with the mailbox's `active`, `user_acl` with all
    fifteen columns, `tags_mailbox`; `displayName` defaults to the local part and `"<Anna>"`
    becomes `Anna`;
  - the creation's audit record `mailboxCreation` with `values` (no password, `passwordSet: true`)
    and the completed `creationReport`;
  - `status: loginDisabled` gives `active = 2` on the mailbox and its alias;
  - inactive domains and backup-MX domains accept creations; an alias domain → `422 notEligible
    {kind: aliasDomain}` on `domain`; an unknown domain and, for a domain administrator, a foreign
    domain → the same `422 notFound` on `domain`;
  - FR-018: `recoveryEmail`, `customAttributes`, `relayhostId` or a sender-permission member in a
    creation body → `422 immutable`, nothing written;
  - `422` for every rule of data-model.md "Validation" (local part, address length, quota
    `notMultipleOf {factor: 1048576}` and negative, display name length, tags, enumerations,
    `templateId` unknown → `notFound`), all violations reported together.
- [X] T036 [P] [US2] Write `tests/Feature/Mailboxes/CreateMailboxLimitsTest.php` (FR-023, R6):
  AS-2 a full domain → `409 mailbox-limit-reached` with `domain`, `count`, `limit`, nothing written;
  resources are not counted; AS-3 maximum mailbox quota 10 GiB and 5 GiB left, 8 GiB requested →
  `422 outOfRange {limit: domainQuotaLeft, max: 5368709120}`; above `maxquota` →
  `{limit: maxMailboxQuota}`; quota 0 (administrator) is accepted and not counted; no quota and no
  template → the domain's default quota for a new mailbox; `defquota > 0` with no quota left and no
  quota sent → `422 outOfRange {limit: domainQuotaLeft, max: 0}`.
- [X] T037 [P] [US2] Write `tests/Feature/Mailboxes/CreateMailboxConcurrencyTest.php` (SC-006,
  group `concurrency`): 1,000 creation attempts of distinct mailboxes from 20 parallel processes
  (50 each) against a domain with room for 10 and 10 GiB quota at 1 GiB each → exactly 10 rows, `Σ quota ≤ domain quota`, the others got
  `mailbox-limit-reached`; two parallel creations of the same address → one `201`, one
  `409 address-in-use`.
- [X] T038 [P] [US2] Write `tests/Feature/Mailboxes/CreateMailboxConflictTest.php` (FR-021, AS-4):
  an address held by a mailbox, a resource, an alias and a temporary alias → `409 address-in-use`
  with `holder` `mailbox`, `resource`, `alias`, `temporaryAlias` and the normalised `address`.
- [X] T039 [P] [US2] Write `tests/Feature/Mailboxes/CreateMailboxTemplatesTest.php` (FR-022, R5):
  AS-7 template "Small" (1 GiB, POP3 off, `acl_syncjobs` 1) applies all its values; a template
  lacking `tagged_mail_handler` and `acl_pw_reset` falls back to the Default template, then to the
  built-in defaults; Default template missing → built-in defaults; request values override
  template values; a template rate limit is written to `RL_VALUE` as `"<v> / 1<f>"`;
  `rateLimit: null` overrides it; `RESTV2_MAILBOX_DEFAULT_ATTRIBUTES` changes a built-in default.
- [X] T040 [P] [US2] Write `tests/Feature/Mailboxes/CreateMailboxPermissionsTest.php` (FR-006,
  AS-5, AS-6), as a domain administrator: without `unlimitedQuota`, `quota: 0` and a template with
  quota 0 → `403 attribute-not-permitted` with `requiredFlags.quota = unlimitedQuota` (an explicit
  quota then works); without `protocolAccess`, `protocolAccess.pop3: false` against a template
  allowing POP3 → refused, omitting it → the template's value, sending the template's value →
  accepted; without `sogoAccess` and `webmailRedirect`; without `ratelimit` and `rateLimit`; without
  each overlapping flag and the matching `userPermissions` member; with every flag → `201`. Nothing
  is written on refusal, and a failure audit record (`attributeNotPermitted`) is written.
- [X] T041 [P] [US2] Write `tests/Feature/Mailboxes/CreateMailboxPasswordTest.php` (FR-024,
  FR-025): AS-8 policy length 10 + digits and password "short" → `422` with both violations;
  AS-11 a password meeting the policy set in Redis is accepted; AS-9 `{SSHA512}…` (generated with
  a known password) is stored as given, the audit record says `preHashed: true`, and
  `LegacyPasswordVerifier` accepts the original password; `MAILCOW_PASS_SCHEME=SSHA256` stores
  `{SSHA256}`; AS-10 with `identityProvider('ldap')`: `authSource: ldap` without password → `201`
  with `password = ''` and `force_pw_update "0"` even when the template sets it; a password with
  `ldap` → `422 conflictsWith`; `authSource: keycloak` → `422 notInList {allowed: [mailcow, ldap]}`;
  `genericOidc` forces `force_tfa "0"`; explicit `forcePasswordChange: true` with `ldap` →
  `conflictsWith`; missing password for `mailcow` → `required`; shared store down with a plain
  password → `503`, nothing written.
- [X] T042 [P] [US2] Write `tests/Feature/Mailboxes/CreateMailboxFollowUpTest.php` (FR-027,
  AS-13): `taggedMailHandling: subject` → `HSET RCPT_WANTS_SUBJECT_TAG <a> 1` and no subfolder field;
  `subfolder` the reverse; `none` → `notRequested`; the groupware step upserts `_sogo_static_view`
  for an active mailbox and flushes memcached once, reports `notRequired` for an inactive one and
  `groupwareDisabled` with `SKIP_SOGO=y`; AS-13 shared store down with a pre-hashed password and a
  rate limit → `201`, the mailbox exists, `creationReport.rateLimit = failed` with a message, and the
  audit record holds the same report.
- [X] T043 [P] [US2] Write `tests/Feature/Mailboxes/CreateMailboxAuthorizationTest.php`: mailbox
  user → `403 forbidden`; read-only key → `403 insufficient-key-scope`; read-write keys of an
  administrator and of a domain administrator (own domain) → `201`; restricted session → `403
  pending-action-required`; session without CSRF token → `403 csrf-token-mismatch`.
- [X] T044 [P] [US2] Write `tests/Feature/Mailboxes/MailboxCapabilitiesTest.php` for
  `getMailboxCapabilities` (FR-064, AS-12): LDAP configured and `SKIP_SOGO=y` →
  `identityProviderType: ldap`, `authSources: [mailcow, ldap]`, `groupwareEnabled: false`; no
  provider → `none`; `lastLoginsReported` follows the setting; `bulkLimit` is
  `min(RESTV2_BULK_LIMIT, 100)`; `taggingLimit`, custom-attribute limits, page sizes and
  `garbageRetentionMinutes` (`MAILDIR_GC_TIME`); no secret of `identity_provider` appears; mailbox
  user → `403`; read-only key → `200`.
- [X] T045 [P] [US2] Write `tests/Feature/PasswordPolicy/GetPasswordPolicyTest.php` for
  `getPasswordPolicy` (FR-063): AS-11 values written by the legacy statements (`HSET PASSWD_POLICY
  length 10 numbers 1`) are returned as structured rules; never saved → 6 and `false`s; `ETag`
  equals `data.etag` equals the canonical tag of R4; a changed field changes it on the next read;
  administrator, domain administrator and read-only keys → `200`; mailbox user → `403`; shared
  store down → `503 temporary-failure`.

### Implementation for User Story 2

- [X] T046 [P] [US2] Create `app/Services/Mailboxes/AddressAvailability.php` (`holderOf(string
  $address): ?string` with the order of R7, plus the duplicate-key mapping helper).
- [X] T047 [P] [US2] Create `app/Services/Mailboxes/TaggedMailHandlingStore.php` (`get(array
  $addresses)`, `set(address, none|subfolder|subject)` exactly as legacy `edit delimiter_action`,
  `move(from, to)` and `forget(address)` for later phases), on 002's `SharedStore`.
- [X] T048 [US2] Create `app/Services/Mailboxes/MailboxValidator.php` (business rules of
  data-model.md "Validation", collected through `FieldViolations`: address, domain eligibility,
  authentication source against `identity_provider`, password policy, quota rules against
  `DomainUsageCalculator`, `TagSet`, custom attributes, `conflictsWith` rules) and
  `app/Http/Requests/Api/V2/StoreMailboxRequest.php` (shape only: types, lengths, enumerations,
  `quota` "integer 0–9007199254740992, `WholeMebibytes`", `rateLimit.value` "integer
  1–2147483647", no unknown members).
- [X] T049 [US2] Create `app/Services/Mailboxes/MailboxCreator.php` implementing research R9:
  resolve values (`MailboxDefaults`), guard (`MailboxPermissionGuard`), validate, `AddressLock`
  for the new address around the availability check and the transaction with the domain row lock
  and every insert of data-model.md, the audit record with
  `creationReport: "pending"`, the follow-up steps (`RateLimitStore::set`,
  `TaggedMailHandlingStore::set`, `SogoDirectory::refresh` and `MemcachedFlusher::flush`), and
  `AuditLogger::completeFollowUps`. Map duplicate-key errors to `address-in-use`. Create
  `app/Services/Mailboxes/MailboxCreationReport.php` (value object of the contract).
- [X] T050 [US2] Create `app/Http/Resources/Api/V2/MailboxCreatedResource.php` and add
  `MailboxController@store` (`201`, `Location`, `ETag`). Route `POST /mailboxes` under
  `restv2.auth:authenticated`, `restv2.csrf`, `restv2.pending:enforce`, with the route default
  `restv2.audit` (`mailboxCreation`, target `mailbox`). This makes T035–T043 pass.
- [X] T051 [P] [US2] Create `app/Services/Mailboxes/MailboxCapabilities.php`,
  `app/Http/Resources/Api/V2/MailboxCapabilitiesResource.php` and
  `app/Http/Controllers/Api/V2/MailboxCapabilitiesController.php` (`show`); route
  `GET /capabilities/mailboxes`. This makes T044 pass.
- [X] T052 [P] [US2] Create `app/Http/Resources/Api/V2/PasswordPolicyResource.php` and
  `app/Http/Controllers/Api/V2/PasswordPolicyController.php` (`show`, `ETag` header); route
  `GET /password-policy` under `restv2.auth:authenticated`, authorised by
  `MailboxPolicy::viewPasswordPolicy`. This makes T045 pass.

**Checkpoint**: US1 and US2 work; mailboxes can be created and listed.

---

## Phase 5: User Story 3 — Change a mailbox's settings and password (P1)

**Goal**: JSON Merge Patch of display name, status, quota, password, forced flags, webmail
redirect, protocols, authentication source and recovery e-mail, guarded by version tags
(FR-029–FR-034, FR-038). Depends on US1.

**Independent test**: change each field, check rows and side effects, race a legacy edit, repeat
as domain administrators with and without flags (spec US3).

### Tests for User Story 3 (write first, observe failing) ⚠️

- [X] T053 [P] [US3] Write `tests/Feature/Mailboxes/UpdateMailboxTest.php` for `updateMailbox`:
  - AS-1: a new password → `mailbox.password` verifies with `LegacyPasswordVerifier`,
    `passwd_update` changes, `force_pw_update` and `app_passwd` rows unchanged, neither the
    response nor the audit record nor the log contains it (`passwordSet: true`);
  - AS-2: `status: loginDisabled` → `active = 2` on the mailbox and its alias, the
    `_sogo_static_view` row removed; `inactive` → 0; back to `active` → row restored;
  - AS-3: an `If-Match` read before a legacy `UPDATE` → `412`, nothing changed; `If-Match: *` and a
    current tag succeed; without `If-Match` the change applies;
  - AS-4: usage 4 GiB, quota 2 GiB → `200`; above `largestAllowedQuota` → `422 outOfRange
    {limit: largestAllowedQuota, max}`;
  - AS-6: `forcePasswordChange: true` → `force_pw_update "1"`, and 001's `GET /me` for that
    mailbox user shows `pendingActions: [passwordChange]`;
  - AS-7: an LDAP mailbox switched to `mailcow` with a password in the same request → the password
    is stored and verifies; switched without a password while `password = ''` → `422 required` on
    `password`; switched to `ldap` → `force_pw_update "0"`, hash kept; `genericOidc` also clears
    `force_tfa`;
  - recovery e-mail set, cleared with `null` and `""`, invalid → `invalidFormat {format: email}`,
    on an LDAP mailbox → `conflictsWith`;
  - every change sets `attributes.attribute_hash` to `""` and flushes memcached once;
  - members of `Mailbox` not in `MailboxPatch` (`address`, `quotaUsed`, `rateLimit`, …) → `422
    immutable`; an empty body → `422 required` on `body`;
  - a patch equal to the stored values → `200`, no audit record;
  - the audit record `mailboxUpdate` lists `changes` with old and new values;
  - FR-057: `listAuditEvents?targetType=mailbox&targetId=<address>` returns the records of this
    mailbox only.
- [X] T054 [P] [US3] Write `tests/Feature/Mailboxes/UpdateMailboxAuthorizationTest.php`: AS-8
  mailbox user → `403 forbidden` (also for its own mailbox); AS-5 domain administrator without
  `protocolAccess` turning off IMAP → `403 attribute-not-permitted` while an administrator succeeds;
  without `sogoAccess` changing `webmailRedirect` → refused, sending the current value → accepted;
  without `unlimitedQuota`, `quota: 0` on a limited mailbox → refused, on an unlimited one →
  accepted; foreign domain → `404`; read-only key → `403 insufficient-key-scope`; domain
  administrator's read-write key → `200` in its domain.

### Implementation for User Story 3

- [X] T055 [US3] Create `app/Http/Requests/Api/V2/UpdateMailboxRequest.php` (merge-patch shape:
  types, lengths, enumerations, `quota` with `WholeMebibytes`, `recoveryEmail` string ≤ 255 or
  `null`, unknown or read-only members → `immutable`, empty body → `required` on `body`).
- [X] T056 [US3] Create `app/Services/Mailboxes/MailboxUpdater.php` implementing research R13 for
  the members of this story (display name, status with alias and groupware refresh, quota with the
  domain row lock, password after the authentication-source switch, forced flags, webmail
  redirect, protocol access, recovery e-mail), the permission guard against current values, the
  `If-Match` check under `SELECT … FOR UPDATE`, the `attribute_hash` reset, the audit record and
  the post-commit memcached flush; `update(Principal, Mailbox, array $patch, ?string $ifMatch,
  ?string $bulkRequestId = null)` so that the bulk actions reuse it.
- [X] T057 [US3] Add `update` to `app/Http/Controllers/Api/V2/MailboxController.php` (`200` with
  `ETag`); in `routes/api.php` add `PATCH /mailboxes/{mailbox}` with `restv2.csrf` and `restv2.audit` (`mailboxUpdate`). This makes T053 and T054 pass.

---

## Phase 6: User Story 4 — Delete a mailbox (P1)

**Goal**: delete after the mail store confirms the garbage move, with legacy's cascade plus the
data legacy leaves behind, and preview it (FR-039–FR-042, FR-065). Depends on US1.

**Independent test**: delete a mailbox with every kind of dependent data, compare with a legacy
deletion, re-create the address (spec US4).

### Tests for User Story 4 (write first, observe failing) ⚠️

- [X] T058 [P] [US4] Write `tests/Feature/Mailboxes/DeleteMailboxTest.php` for `deleteMailbox`:
  AS-1 seed every dependent row of data-model.md "Tables touched by deletion and rename" plus
  `RL_VALUE`, both `RCPT_WANTS_*` fields, `sasl_log` and a `restv2_preferences` row → `204`; every
  row naming the address is gone (FK cascades included), an alias pointing to it and to another
  mailbox keeps only the other recipient, other mailboxes' rows are untouched, the Redis fields are
  gone, `FakeDockerApiClient` received exactly one
  `{cmd: maildir, task: cleanup, maildir: "<domain>/<local>"}` **before** any row disappeared
  (`assertExecOrder` with a DB snapshot in the fake's callback), the `_sogo_static_view` row is
  gone, memcached was flushed, and the audit record `mailboxDeletion` holds
  `mailStoreCleanup: done` and the removed counts. With `SKIP_SOGO=y` the `sogo_*` rows are still
  removed and `_sogo_static_view` is not touched (spec edge case).
- [X] T059 [P] [US4] Write `tests/Feature/Mailboxes/DeleteMailboxFailureTest.php`: AS-2 cleanup
  `failed` and `unreachable` → `503 temporary-failure {dependency: mailStore}` and every row and
  Redis field still present; `unknown` → `504 outcome-unknown {step: mailStoreCleanup}`, nothing
  removed, and a retry with `success` deletes; shared store down → `503`, no container call;
  `CLUSTERMODE=replication` → one local exec and one `MC_CHANNEL` broadcast; a failing database
  statement after the move (injected) → `500`, rows kept, Redis fields restored, the failure audit
  record says `mailStoreCleanup: done`.
- [X] T060 [P] [US4] Write `tests/Feature/Mailboxes/DeleteMailboxReuseTest.php` (AS-3, SC-007):
  delete a mailbox with mail usage, logins, TOTP, a passkey, tagged-mail handling and preferences,
  re-create the same address through `createMailbox`, and assert that it has zero usage, no
  `sasl_log`, `tfa`, `fido2`, `RCPT_WANTS_*`, rate limit or preferences.
- [X] T061 [P] [US4] Write `tests/Feature/Mailboxes/DeleteMailboxAuthorizationTest.php`: AS-4
  foreign mailbox for a domain administrator → `404`, read-only key → `403
  insufficient-key-scope`; AS-5 outdated `If-Match` → `412`, nothing removed and no container call;
  mailbox user → `403`; a domain administrator deletes in its own domain → `204`.
- [X] T062 [P] [US4] Write `tests/Feature/Mailboxes/PreviewMailboxDeletionTest.php` for
  `previewMailboxDeletion` (FR-065, AS-6): 3 exclusive aliases (own alias not counted), 1 shared
  alias, 2 app passwords and a TOTP factor → exactly those counts and addresses; every other count
  and presence flag against seeded rows; `mail` from `quota2`; `garbageRetentionMinutes`; `etag`
  equals `getMailbox`'s; `canProceed: false` with a `mailStore` blocker when the fake reports the
  container unreachable and with a `sharedStore` blocker (presence flags `null`) when Redis is
  down; no row changes, no container exec, no audit record; read-only key → `200`; foreign → `404`;
  mailbox user → `403`.

### Implementation for User Story 4

- [X] T063 [US4] Create `app/Services/Mailboxes/DeletionPlan.php` and
  `app/Services/Mailboxes/MailboxDeletionPlanner.php` (the selects and counts of research R15 and
  R12, alias samples of up to 100, blockers via `DockerApiClient::reachable` and
  `SharedStore::ping`).
- [X] T064 [US4] Create `app/Services/Mailboxes/MailboxDeleter.php` implementing research R15:
  scope, `AddressLock` for the address, `If-Match`, `SharedStore::ping`,
  `MailStoreCommands::cleanupMaildir` with the `503`/`504` mapping, the transaction with every
  delete in legacy's order plus `sasl_log` and `restv2_preferences`, the alias `goto` rewrite, the
  Redis `MULTI` with compensation, `SogoDirectory::refresh` for the address and the rewritten
  aliases' mailboxes, the audit record, commit, memcached flush;
  `delete(Principal, Mailbox, ?string $ifMatch, ?string $bulkRequestId = null)`.
- [X] T065 [US4] Add `destroy` to `app/Http/Controllers/Api/V2/MailboxController.php` (`204`; `ignore_user_abort(true)`,
  `set_time_limit(0)`), `app/Http/Resources/Api/V2/MailboxDeletionPreviewResource.php` and
  `app/Http/Controllers/Api/V2/MailboxDeletionPreviewController.php` (`show`); in `routes/api.php`
  `DELETE /mailboxes/{mailbox}` (with `restv2.csrf` and `restv2.audit`, `mailboxDeletion`) and
  `GET /mailboxes/{mailbox}/deletion-preview`. This makes T058–T062 pass.

**Checkpoint**: the P1 stories (US1–US4) are complete; this is the deliverable MVP for billing
integrations.

---

## Phase 7: User Story 5 — Permissions, tags and custom attributes (P2)

**Goal**: change user permissions, tags and custom attributes, and list the tags in use (FR-035
to FR-037, FR-066). Depends on US3's `MailboxUpdater`.

**Independent test**: change each permission, the tags and the attributes; compare with legacy;
check hidden flags for domain administrators (spec US5).

### Tests for User Story 5 (write first, observe failing) ⚠️

- [X] T066 [P] [US5] Write `tests/Feature/Mailboxes/UpdateMailboxPermissionsTest.php`: AS-1
  granting `syncjobs` sets `user_acl.syncjobs = 1`, and 001's `GET /me` of that mailbox user shows
  `syncjobs: true` on the next request; only the sent flags change (others keep their values, also
  0s that legacy would reset); AS-2 a domain administrator without `syncjobs`: the read omits it,
  changing it → `403 attribute-not-permitted`, changing `spamScore` succeeds and `syncjobs` keeps
  its value; AS-5 a mailbox user → `403`.
- [X] T067 [P] [US5] Write `tests/Feature/Mailboxes/UpdateMailboxTagsTest.php`: AS-3 26 tags →
  `422 tooMany {max: 25}`; "Sales" and "sales" → one row "Sales"; `RESTV2_TAGGING_LIMIT=3` applies;
  a legacy mailbox with 30 tags is shown in full and may be set to 28 but not 31; the set replaces
  the old rows; tags appear in the representation sorted without regard to case.
- [X] T068 [P] [US5] Write `tests/Feature/Mailboxes/UpdateMailboxCustomAttributesTest.php`: AS-4
  `{old: 1}` plus `{department: "Sales", old: null}` → stored JSON exactly `{"department":"Sales"}`;
  a name with a space → `422 invalidFormat {format: attributeName}`; 4,097-character value →
  `tooLong`; 101 attributes after merging → `tooMany {max: 100}`; a non-string value →
  `invalidType`; the stored JSON is what php-fpm's `footer.php` reads (`json_decode` of the column
  gives `{"department":"Sales"}`).
- [X] T069 [P] [US5] Write `tests/Feature/Mailboxes/ListMailboxTagsTest.php` for `listMailboxTags`
  (FR-066, AS-6): a domain administrator with "Sales" on 3 mailboxes and "sales-eu" on 1, another
  domain with "Secret" → `q=sal` gives `Sales` 3 and `sales-eu` 1 and never `Secret`; "Sales" and
  "sales" on different mailboxes form one entry named "Sales" with both counted; tags of resources
  are excluded; paging, `order=desc`; mailbox user → `403`; read-only key → `200`.

### Implementation for User Story 5

- [X] T070 [US5] Extend `app/Services/Mailboxes/MailboxUpdater.php` and
  `app/Http/Requests/Api/V2/UpdateMailboxRequest.php` with `userPermissions` (per-column
  `UPDATE user_acl`), `tags` (`TagSet`, delete and insert) and `customAttributes` (merge, limits,
  JSON object) as in research R13 and R21, including their audit `changes`.
- [X] T071 [P] [US5] Create `app/Services/Mailboxes/MailboxTagUsage.php` (query of research R12),
  `app/Http/Requests/Api/V2/ListMailboxTagsRequest.php`,
  `app/Http/Resources/Api/V2/MailboxTagUsageResource.php` and
  `app/Http/Controllers/Api/V2/MailboxTagController.php` (`index`); route `GET /mailbox-tags`. This makes T066–T069 pass.

---

## Phase 8: User Story 6 — Mailbox templates (P2)

**Goal**: administrators manage templates; domain administrators read them (FR-049–FR-053).
Independent of US1–US5 apart from the foundation.

**Independent test**: create, read, change and delete templates; compare with legacy rows; create
mailboxes from them (spec US6).

### Tests for User Story 6 (write first, observe failing) ⚠️

- [X] T072 [P] [US6] Write `tests/Feature/MailboxTemplates/MailboxTemplateCrudTest.php`:
  - AS-1 an administrator creates "Students" → `201` with `Location` and `ETag`; the `templates`
    row has `type = 'mailbox'` and every legacy key with legacy's value types (integer flags, quota
    in bytes, `rl_value ""`, `rl_frame "s"`, the fifteen `acl_*` keys); omitted values get the
    built-in defaults and an unlimited quota; it is listed for administrators and domain
    administrators;
  - list sorting by `name` (case-insensitive), `id`, `createdAt`, `modifiedAt`, `q` on the name,
    paging, `etag` per entry equal to the single read;
  - AS-4 changing only `quota` keeps every other key; nested `protocolAccess` merges;
    `rateLimit: null` writes `rl_value ""`; `If-Match` outdated → `412`;
  - AS-2 "staff" while "Staff" exists → `409 mailbox-template-name-exists` (creation and rename);
  - AS-3 renaming or deleting the Default template → `409 mailbox-template-protected`, nothing
    changed; changing its values works;
  - AS-5 mailboxes created from a template keep their values after it changes and after it is
    deleted;
  - a stored legacy template missing keys (the seeded Default) is read with the defaults;
  - audit records `mailboxTemplateCreation`, `mailboxTemplateUpdate`, `mailboxTemplateDeletion`
    with `changes`.
- [X] T073 [P] [US6] Write `tests/Feature/MailboxTemplates/MailboxTemplateAuthorizationTest.php`:
  AS-1 a domain administrator (session and read-write key) creating, changing or deleting →
  `403 forbidden`, reading → `200` with overlapping permissions omitted per its flags; mailbox user →
  `403` for all five operations; administrator read-only key reads, `403 insufficient-key-scope`
  on writes; unknown id → `404`.

### Implementation for User Story 6

- [X] T074 [US6] Create `app/Services/Mailboxes/MailboxTemplateService.php` (create, update with
  merge and protection, delete with protection, case-insensitive name uniqueness under the named
  lock `restv2_mailbox_template_<sha1(lower(name))>` (`NamedLock`, data-model.md), legacy encoding through `MailboxDefaults`' template codec, audit) and the
  Form Requests `app/Http/Requests/Api/V2/ListMailboxTemplatesRequest.php`,
  `StoreMailboxTemplateRequest.php` (`name` "trimmed, 1–255 characters", values as in
  `StoreMailboxRequest`) and `UpdateMailboxTemplateRequest.php` (merge patch, non-empty).
- [X] T075 [US6] Create `app/Http/Resources/Api/V2/MailboxTemplateResource.php` and
  `app/Http/Controllers/Api/V2/MailboxTemplateController.php` (`index`, `show`, `store`,
  `update`, `destroy`); routes `/mailbox-templates` and `/mailbox-templates/{mailboxTemplateId}`
  (`whereNumber`), the writes with `restv2.csrf` and `restv2.audit` (target `mailboxTemplate`). This makes T072 and T073 pass.

---

## Phase 9: User Story 7 — Bulk actions (P2)

**Goal**: one action on up to 100 mailboxes with one result per mailbox (FR-054, FR-055, FR-067).
Depends on US3 (`MailboxUpdater`) and US4 (`MailboxDeleter`).

**Independent test**: apply each action to a mixed selection with one failing mailbox; compare
results, rows and audit records (spec US7).

### Tests for User Story 7 (write first, observe failing) ⚠️

- [X] T076 [P] [US7] Write `tests/Feature/Mailboxes/MailboxBulkActionTest.php` for
  `applyMailboxBulkAction`:
  - AS-1 50 active mailboxes deactivated → 50 `done` results with new `version`s, `summary.done`
    50, all inactive (and their aliases), 50 audit records carrying the same `bulkRequestId`;
  - `activate`, `disableLogin`, `delete`, `allowProtocol`/`disallowProtocol` for each of the six
    protocols, each with the single operation's side effects;
  - AS-2 a domain administrator's selection of three own and two foreign mailboxes → 3 `done`,
    2 `notFound`, the foreign ones unchanged;
  - AS-3 a domain administrator without `protocolAccess` and `disallowProtocol` → `403
    permission-flag-required` with `requiredFlags: [protocolAccess]`,
    nothing changed, no audit record per item;
  - AS-4 101 targets → `422 tooMany {max: 100}` on `targets`; `protocol` missing for a protocol
    action or present for another action → `422`;
  - AS-5 bulk deletion where the fake fails the cleanup of one mailbox → that one `failed` with
    `temporary-failure`, it remains, the others are deleted;
  - AS-6 three targets with versions from the list, one changed by a legacy `UPDATE` meanwhile →
    `outdated` with a problem for it, the other two `done`; targets without `version` are not
    checked;
  - an address given twice (also in different case or Unicode form) is processed and reported
    once, in request order; an unnormalisable address → `notFound`;
  - after the container API times out once, the remaining deletions fail at once with
    `outcome-unknown` (circuit breaker), and the whole request finishes within the test's 5 s;
  - memcached is flushed once per request;
  - mailbox user → `403`; read-only key → `403 insufficient-key-scope`.

### Implementation for User Story 7

- [X] T077 [US7] Extract the per-target loop of 002's `app/Services/Domains/DomainBulkActions.php`
  into `app/Support/Bulk/BulkActionRunner.php` and `BulkOutcome.php` (research R19, second use;
  002's bulk tests must stay green), then create `app/Services/Mailboxes/MailboxBulkActions.php` on
  it (research R19: whole-request
  refusals, normalisation and deduplication, per-target `MailboxUpdater` or `MailboxDeleter` with
  `version` as `If-Match` and the request ID as `bulkRequestId`, the outcome mapping, one memcached
  flush) and `app/Http/Requests/Api/V2/MailboxBulkActionRequest.php` (`action` enum, `protocol`
  conditional, `targets` 1–`min(config('restv2.bulk_limit'), 100)` of `{id, version?}`).
- [X] T078 [US7] Create `app/Http/Controllers/Api/V2/MailboxBulkActionController.php` (`store`,
  `200` with 001's bulk result shape; `ignore_user_abort(true)`, `set_time_limit(0)`); route
  `POST /mailboxes/bulk-actions` before `/mailboxes/{mailbox}`, with `restv2.csrf` and
  `restv2.audit` (`'event' => 'fromBulkAction'`). This makes T076 pass.

---

## Phase 10: User Story 8 — Rename a mailbox (P3)

**Goal**: rename with preflight checks, legacy's changes, the carried-over data, and undo on
failure (FR-043–FR-048). Depends on US1 and the foundation (`MailStoreCommands`,
`SogoDirectory::refresh`).

**Independent test**: rename mailboxes with every kind of dependent data and shared folders;
inject a failure into each step (spec US8).

### Tests for User Story 8 (write first, observe failing) ⚠️

- [X] T079 [P] [US8] Write `tests/Feature/Mailboxes/RenameMailboxTest.php` for `renameMailbox`:
  AS-1 `anna@example.org` with every row of data-model.md's rename column, an app password, two
  ACL entries from `get_acl` (one granted, one received), `RL_VALUE` and a `RCPT_WANTS_*` field,
  renamed to `anna.smith` with `keepOldAddressAsAlias: true` → `200` with `previousAddress`,
  `Content-Location: /api/v2/mailboxes/anna.smith@example.org` and `ETag`; every legacy column now
  names the new address; multi-recipient alias destinations are rewritten element-wise;
  `tfa`, `fido2`, `spamalias.goto`, `filterconf`, `quarantine.rcpt`, `sasl_log` and
  `restv2_preferences` moved; `oauth_*` rows deleted; `da_acl` of an admin account named like the
  old address unchanged; the Redis fields moved; the new alias row has the comment
  `Mailbox was renamed from anna@example.org to anna.smith@example.org`, `sogo_visible 1`,
  `sender_allowed 0`, `active 1`; the container calls in order: `get_acl`, two `delete_acl`,
  `maildir move` with `example.org/anna` → `example.org/anna.smith`, `sogo rename_user`, two
  `set_acl` with the old address replaced in `user` and `id`; memcached flushed;
  `GET /mailboxes/anna@example.org` → `404`; the audit record `mailboxRename` lists all six steps
  `done`. Without `keepOldAddressAsAlias`, no alias row. With `SKIP_SOGO=y`, no SOGo call and the
  step `skipped`. A mailbox with `authsource = ldap` is renamed as well (spec edge case).
- [X] T080 [P] [US8] Write `tests/Feature/Mailboxes/RenameMailboxPreflightTest.php`, each case
  asserting that no row, Redis field or container write changed: AS-2 new address held by a
  mailbox, resource, alias or temporary alias → `409 address-in-use` with the holder; AS-3 an
  inactive mailbox → `409 mailbox-not-renamable`; the same local part and one differing only in
  case → `422 unchanged`; an invalid local part → `422 invalidFormat`; AS-6 the domain's alias
  limit reached with `keepOldAddressAsAlias: true` → `409 domain-alias-limit-reached`, and the same
  rename without the alias succeeds; dovecot or (unless `SKIP_SOGO=y`) sogo unreachable → `503
  temporary-failure` with the dependency; shared store down → `503`; outdated `If-Match` → `412`;
  foreign mailbox for a domain administrator → `404`; read-only key → `403`; mailbox user → `403`.
- [X] T081 [P] [US8] Write `tests/Feature/Mailboxes/RenameMailboxRollbackTest.php` (FR-048,
  AS-4): for each step 1–6, inject a failure (the fake's scripted `failed` for container steps, a
  closed Redis port for step 2, a failing SQL statement for step 3) → `503 temporary-failure` with
  `steps` (`done` steps reported `undone`, the failing one `failed`, later ones `notDone`), and the
  database, Redis and the recorded container state equal the state before the request (the undo
  calls are asserted: `set_acl` of removed entries, maildir move back, `sogo rename_user` back,
  `delete_acl` of set entries); a failing undo (e.g. the maildir move back) → `500
  mailbox-rename-incomplete` with `undoFailed`; a step answering `unknown` → `504 outcome-unknown`
  naming it, the done steps undone and the unknown step not repeated; a failing final audit write
  → steps undone and `500`; each outcome has exactly one audit record with the steps and the
  failure reason (`temporaryFailure`, `partiallyCompleted`, `outcomeUnknown`).
- [X] T082 [P] [US8] Write `tests/Feature/Mailboxes/RenameMailboxSecondFactorTest.php` (AS-5,
  SC-008): a mailbox with a TOTP secret and a passkey is renamed; 001's password sign-in as the new
  address offers and accepts the TOTP code, the passkey's `fido2` row belongs to the new address
  and 001's passkey sign-in with the virtual authenticator resolves the new address.

### Implementation for User Story 8

- [X] T083 [US8] Create `app/Services/Mailboxes/MailboxDataMover.php` (`move(string $from, string
  $to, bool $forward, ?string $aliasComment)`: research R18 statements in one transaction with
  `SET FOREIGN_KEY_CHECKS = 0`/`1`, element-wise `goto` rewrite, carry-overs, OAuth revocation on
  the forward move, the optional alias insert and its removal on the reverse move,
  `SogoDirectory::refresh`).
- [X] T084 [US8] Create `app/Services/Mailboxes/RenameStep.php` and `RenameStepOutcome.php`
  (enums of the contract) and `app/Services/Mailboxes/MailboxRenamer.php` implementing research
  R17: preflight, `AddressLock` on the old and the new address (held until the audit record is
  written), the six steps with their undo in reverse order, the `503`/`500`/`504`
  outcomes, step logging with the request ID, the final audit record in its own transaction (undo
  and `500` when it fails), memcached flush on success.
- [X] T085 [US8] Create `app/Http/Requests/Api/V2/RenameMailboxRequest.php` (`newLocalPart` string
  1–255, `keepOldAddressAsAlias` boolean, both required, no unknown members),
  `app/Http/Resources/Api/V2/MailboxRenamedResource.php` and
  `app/Http/Controllers/Api/V2/MailboxRenameController.php` (`store`, `200`, `Content-Location`,
  `ETag`; `ignore_user_abort(true)`, `set_time_limit(0)`); route
  `POST /mailboxes/{mailbox}/rename` with `restv2.csrf` and `restv2.audit` (`mailboxRename`). This makes T079–T082 pass.

**Checkpoint**: all eight user stories pass.

---

## Phase 11: Polish and cross-cutting concerns

- [X] T086 [P] Write `tests/Feature/Security/MailboxAuthorizationMatrixTest.php` (as 002's
  `DomainAuthorizationMatrixTest`) covering the 16 operations of this feature × {administrator session, domain-administrator session in scope and
  out of scope, mailbox-user session, restricted session, administrator ro and rw key,
  domain-administrator ro and rw key, no credentials}, asserting the status of each cell
  (constitution VI, SC-004).
- [X] T087 [P] Extend `tests/Feature/Security/SecretLeakScanTest.php` (001, SC-010, FR-060): after
  the feature suite, no response body, audit record or log line contains any test password, any
  generated or pre-hashed hash, or the `password` column of any seeded mailbox.
- [X] T088 [P] Write `tests/Feature/Performance/MailboxListPerformanceTest.php` (SC-002, group
  `performance`): seed 10,000 mailboxes with usage, tags and logins in 500 domains by multi-row
  inserts; for pages of 100 with each filter, `q` and every sort, p95 under 1 s over 20 runs; pages
  of 1,000 under 5 s. Also SC-009: a bulk `deactivate` of 100 mailboxes completes within 10 s and a
  bulk `delete` of 100 (fake container API answering at once) within 60 s, each with exactly 100
  results.
- [X] T089 [P] Write `tests/Feature/Mailboxes/MailboxAuditCompletenessTest.php` (SC-010): run one
  successful and one refused request of every state-changing operation (and a bulk action with
  three targets) and assert exactly one audit record per affected mailbox or template, with the
  right event type, target and outcome.
- [X] T090 [P] Extend `database/seeders/DevelopmentSeeder.php` with the Default mailbox template
  (as `init_db.inc.php` seeds it), the template "Small" and the mailbox limits of quickstart Part B.
- [X] T091 Run `composer test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` and
  `composer contract:lint`; confirm `build/contract/coverage.json` lists all 16 operations of this
  feature (constitution quality gates 1, 2 and 6).
- [X] T092 Run quickstart.md Part A and Part B against the dev stack and confirm every expected
  output; record Part C as the release checklist for a mailcow host.

---

## Phase 12: Additions from the 1:1 review of the legacy forms (G39, G40)

**Purpose**: legacy deletes several mailbox templates and several domain templates at once
(`delete_selected` on `tab-templates-mbox.twig` and `tab-templates-domains.twig`). Added to 003's
scope by the orchestrator on 2026-10-18; G40 is 002's area and reuses 002's domain template
deletion.

- [X] T093 [P] Contract: `POST /mailbox-templates/bulk-actions` (`applyMailboxTemplateBulkAction`,
  `MailboxTemplateBulkActionRequest`) and `POST /domain-templates/bulk-actions`
  (`applyDomainTemplateBulkAction`, `DomainTemplateBulkActionRequest`), action `delete`, up to 100
  `BulkTarget`s, `BulkResultResponse`; register both in `tests/feature-registry.json` (003 and 002).
- [X] T094 [P] Write `tests/Feature/MailboxTemplates/MailboxTemplateBulkActionTest.php` and
  `tests/Feature/DomainTemplates/DomainTemplateBulkActionTest.php` (deleted with one record each and
  the bulk request ID, Default refused, unknown and other-type IDs `notFound`, repeated IDs once,
  outdated versions per item, whole-request refusals recorded once with `targetIds`), add both
  operations to the two authorization matrices and the read-only key row of
  `MailboxRefusalAuditTest`, and a deletion under two spellings to `MailboxBulkActionTest`.
- [X] T095 Implement `MailboxTemplateBulkActions` and `DomainTemplateBulkActions` on the shared
  `BulkActionRunner` through the services' single deletions (which take the bulk request ID), the
  requests, controllers and routes (`fromBulkAction` refusal auditing), and the bulk detail keys of
  `mailboxTemplateDeletion` and `domainTemplateDeletion`.
- [X] T096 [P] Mark G39 and G40 done in `docs/api-gaps.md`; document the detail keys in
  `docs/audit-events.md` and the operations in `CHANGELOG.md`.

---

## Dependencies and execution order

### Phase dependencies

- **Setup (Phase 1)**: needs features 001 and 002 merged.
- **Foundational (Phase 2)**: needs Setup; blocks every story.
- **US1 (Phase 3)**: needs Foundational. Its `MailboxReader` and `MailboxResource` are used by every
  later story, so implement US1 first.
- **US2 (Phase 4)**, **US3 (Phase 5)**, **US4 (Phase 6)**: need US1; independent of each other.
- **US5 (Phase 7)**: needs US3 (`MailboxUpdater`).
- **US6 (Phase 8)**: needs only Foundational (it can run in parallel with US1–US5); US2's template
  tests use the factory, not US6's endpoints.
- **US7 (Phase 9)**: needs US3 and US4.
- **US8 (Phase 10)**: needs US1 and Foundational.
- **Polish (Phase 11)**: needs every story.

### Within each story

Tests first (they must fail), then Form Requests and services, then resources, controllers and
routes. `/mailboxes/bulk-actions` is registered before `/mailboxes/{mailbox}`. Every
state-changing route declares the route default `restv2.audit` (T028).

### Parallel opportunities

- Setup: T002–T005 in parallel after T001.
- Foundational tests T006–T016 in parallel; implementation T017, T018, T021, T024, T027 in
  parallel, then T019, T020, T022, T023, T025, T026, T028.
- After US1: US2, US3, US4, US6 and US8 can be staffed in parallel; their test tasks are all [P].

## Parallel example: User Story 2

```bash
# Tests first, in parallel (all must fail):
T035 CreateMailboxTest      T036 CreateMailboxLimitsTest     T037 CreateMailboxConcurrencyTest
T038 CreateMailboxConflictTest  T039 CreateMailboxTemplatesTest  T040 CreateMailboxPermissionsTest
T041 CreateMailboxPasswordTest  T042 CreateMailboxFollowUpTest   T043 CreateMailboxAuthorizationTest
T044 MailboxCapabilitiesTest    T045 GetPasswordPolicyTest
# Then: T046 and T047 in parallel → T048 → T049 → T050; T051 and T052 in parallel.
```

## Implementation strategy

### MVP first

1. Phases 1 and 2.
2. Phase 3 (US1): list and inspect. Validate independently.
3. Phases 4–6 (US2–US4): create, change, delete. These four P1 stories are the MVP for billing
   integrations and the web app's mailbox screen.

### Incremental delivery

4. US5 and US6 (P2): permissions, tags, attributes and templates.
5. US7 (P2): bulk actions.
6. US8 (P3): rename.
7. Polish: authorisation matrix, leak scan, performance, audit completeness, quality gates.

## Notes

- Never use `RefreshDatabase`, `migrate:fresh` or `db:wipe` (001). This feature adds no migration.
- Every upstream write goes through `app/Services/Mailboxes/` or the 002 services it calls;
  controllers never write.
- Passwords and hashes never reach responses, logs or audit records.
- Commit after each task or logical group with a short imperative subject.

## Implementation notes (2026-10-18)

Deviations and decisions made while implementing, with the reason. Names in tasks that differ from
what exists map as listed.

- **Reused from 002** instead of creating parallel copies: `App\Support\Bulk\{BulkActionRunner,
  BulkOutcome}` (T077's extraction was already done by 002), `App\Services\Audit\RefusalAuditor`
  with the route default `restv2.audit` (T028), `DomainScope`, `DomainName`, `SogoDirectory`,
  `MemcachedFlusher`, `SharedStore`, `RateLimitStore`, `FieldViolations`, `WholeMebibytes`,
  `ETag::preconditionFails()` and the fakes. 001's `ETag::of()` (HMAC) is what tasks call
  `ETag::strong()`.
- **T001**: the contract scope is 001's `tests/feature-registry.json` (feature `003-mailboxes`,
  operation IDs), so no `tests/contract-scope.php` and no change to the coverage script were
  needed. `RESTV2_BULK_LIMIT` did not exist (002 uses a constant of 100); it is added as
  `restv2.bulk_limit` (1–100) for mailboxes. `MASTER` is a boolean `restv2.master`.
- **T003**: `resources/bin/export-mailcow-vars.php` exports every variable listed in
  `config/restv2.php`, so adding `SHOW_LAST_LOGIN` and `MAILBOX_DEFAULT_ATTRIBUTES` to
  `vars_overrides`/`vars_defaults` was enough. The tests append overrides to a copy of the fixture
  files, as 002's tests do, instead of adding new fixture files.
- **T004**: 001's `MailcowRows::mailbox()` is used by many existing tests and writes no usage rows
  or own alias, so the exact legacy creation is a new writer, `legacyMailbox()`.
  `syncJob()`, `quarantineItem()`, `temporaryAlias()` (with validity and permanence),
  `recipientMap()`, `sieveFilter()`, `appPassword()`, `sogoData()`, `oauthTokens()`, `saslLog()`,
  `usage()`, `mailboxTag()`, `mailboxTemplate()` and `defaultMailboxTemplate()` were added;
  `alias()`, `spamalias()`, `senderAcl()`, `filterconf()`, `bccMap()`, `pushover()` (now with
  `active`) and `identityProvider()` already existed.
- **T005, T012, T023**: `DockerApiClient` gained `query()` (for `doveadm get_acl`, which answers a
  JSON list instead of dockerapi's `{type, msg}`), `reachable()` and `broadcast()` (legacy
  docker('broadcast') through `SharedStore::run()`); `DockerCallResult` gained `unreachable()` and
  `data`. The per-request circuit breaker is not in `HttpDockerApiClient`: it would also cut off
  the undo calls of a rename after a timeout. `MailboxBulkActions` stops calling the mail store for
  the remaining deletions of a request after a timeout or an unreachable mail store instead
  (T076), which is the case the breaker was meant for.
- **T016** (refusal audit per operation) needs the routes of every story; it is written with the
  polish phase. The plumbing is in Phase 2: `MailboxAuditTargets` is registered with the
  `RefusalAuditor`, and exceptions that implement the new `App\Exceptions\CarriesAuditDetails`
  (every `ProblemException` with `auditDetails`, `MailboxDependencyUnavailable`, `OutcomeUnknown`,
  `AuditedFailure`) add their details to the failure record and are recorded also for `5xx`
  answers, by `RefusalAuditor` and by `BulkActionRunner`. Services therefore never write failure
  records themselves (002's rule), also not for `503`, `504` and `500` outcomes.
- **T018**: the local-part rule lives in `MailboxAddress::fromParts()` (all violations are business
  rules reported together); no separate `MailboxLocalPart` rule class was needed.
- **T020**: the policy tag is the plain SHA-256 the contract documents (`PasswordPolicy.etag`),
  computed by `EffectivePasswordPolicy::etag()`; the policy holds no secret.
- **T022**: `SogoDirectory::refresh()` returns 002's `GroupwareOutcome` and throws database errors,
  so that it can run inside a transaction; it never flushes memcached (callers flush after the
  commit).
- **T028**: `SharedStoreUnavailable` now renders `dependency: sharedStore` on every `503`
  (additive; `docs/problems.md` updated). `AddressAvailability` (T046) was moved into Phase 2,
  because features 004 and 005 need it with `AddressLock`.
- **T037**: under the domain row lock, an attempt that would exceed the domain quota is refused
  with `422 outOfRange` (`domainQuotaLeft`) before the mailbox count is checked (research R8's
  order: business rules before state conflicts), so of 1,000 concurrent attempts against a domain
  with room for 10, 10 succeed and the others get `mailbox-limit-reached` or that `422`.
- **T042, AS-13**: the creation response is built also while mailcow's shared store is down (the
  follow-up steps already reported the outage); its rate limit and tagged-mail handling are then
  shown as not set (`MailboxReader::present(..., tolerateSharedStore: true)`). Every other read
  answers `503` without the shared store.
- **T044 (G31, G32)**: `MailboxCapabilities` gained `preHashedPasswordSchemes` and
  `templateDefaults` (new schema `MailboxTemplateDefaults`), both for administrators and domain
  administrators.
- **T048**: unknown members of a body (including those inside `protocolAccess`, `userPermissions`
  and `rateLimit`) are `immutable`, through the new `ApiFormRequest::memberViolations()` hook;
  booleans are checked with the new `StrictBoolean` rule (Laravel's `boolean` accepts `0` and `1`).
  Whole MiB, the address, tags, templates and quota limits are business rules
  (`MailboxValidator`), reported together. An invalid creation domain name is `invalidFormat`
  (`format: domainName`).
- **T049**: without a rate limit or with `taggedMailHandling: none`, the creation removes any
  stale shared-store entry of the address, as legacy's ratelimit('edit') and delimiter_action do;
  a failure of that removal is only logged (the step is `notRequested`).
- **T056, T070**: US3 and US5 are one `MailboxUpdater` (the members of both stories in one merge
  patch). Changes are audited as dot paths (`protocolAccess.pop3`, `userPermissions.syncjobs`,
  `customAttributes.<name>`), sorted; a password only as `password: {passwordSet, preHashed}`. The
  quota limit is checked only when the quota actually changes, so a legacy mailbox over the domain
  quota can still be edited otherwise. A legacy set of custom attributes over the limit may shrink
  but not grow (as tags).
- **T064**: failure records of a deletion are written by the shared refusal auditing from the
  exceptions' audit details (`mailStoreCleanup`: `failed`, `unknown`, or `done` for a failure after
  the move, which answers `500` through `AuditedFailure`). An unreachable mail store raises
  `MailStoreUnreachable` (a `MailboxDependencyUnavailable`), so that bulk deletions can stop calling
  it. The groupware entry of the deleted address is refreshed (removed); the other recipients of
  rewritten aliases keep identical rows, so they are not refreshed. The rewrite removes every exact
  occurrence of the address from a destination list.
- **T076–T078**: every distinct target gets one audit record, also a `notFound` one (002's
  `BulkActionRunner` records each refused item); a protocol action of a domain administrator without
  `protocolAccess` is refused as a whole before any item and recorded once with a `null` target.
  A `protocol` sent with another action is `conflictsWith` (`field: action`).
- **T083–T085**: the own alias is renamed by address and then rewritten with every other
  destination list (element-wise), so a forwarding own alias (`anna,ext@…`) keeps its other
  recipients; legacy overwrote its destination with the new address. Before the forward move, stale
  rows of the (free) new address in tables keyed by the address (`quota2`, `quota2replica`,
  `pushover`, `sasl_log`, `restv2_preferences`) are removed, so they cannot block the rename. A
  failing database step answers `503` with `dependency: database`. A step that cannot revert its own
  partial changes (an ACL delete or set in the middle of its list) is `undoFailed`. The new local
  part is validated (`422`) before the address locks are taken, because the locks name the new
  address; `ignore_user_abort()`/`set_time_limit(0)` are set by the renamer after the preflight.
- **T016** was written with the polish phase, once every route existed; it passes against the
  shared refusal auditing as built in Phase 2.
- **T087**: the mailbox flows are scanned by their own `tests/Feature/Security/MailboxSecretsTest.php`
  (as 002's `DomainSecretsTest`), instead of growing 001's single-flow `SecretLeakScanTest`.
- **T088**: each of the 18 query shapes is timed three times (54 pages of 100; p95 under 1 s), the
  pages of 1,000 three times; the bulk timings run with the groupware disabled, as in 002's latency
  test. Sorting by `lastMailLogin` uses an indexed correlated subquery: the grouped derived-table
  join of research R10 took 13 s on 10,000 mailboxes.
- **T090**: the dev seed's `reseller` lacks `protocol_access`, and example.org has room for 10
  mailboxes and 5 GiB, as quickstart Part B expects.
- **G11** (shared with 011): the shared `OutcomeUnknownProblem` declares `step`; 011's response
  still has to reference it.
- **T091**: the concurrency and performance groups are not excluded by `phpunit.xml`, so
  `composer test` runs them with the rest of the suite. The production image was not rebuilt:
  the plan does not change it (the nginx `fastcgi_read_timeout` and the compose override are
  deployment files).
- **T092**: Part B ran against the `restv2-003` dev stack with no published ports, so curl ran
  inside the compose network against the HTTPS listener (`--connect-to mail.example.org:443:web:443`;
  curl drops cookies for the single-label host `web`). The run corrected the guide: the base URL is
  002's HTTPS one with `-k`, the sign-in renews the CSRF token, `bob@example.org` is seeded already
  (the template example creates `carol`), template ids are looked up by name, and the audit records
  of the renamed mailbox are found under its address at the time (`anna.smith@example.org`). Part C
  stays the release checklist for a mailcow host and was not run.
- **T093–T096 (G39, G40)**: the template IDs of `BulkTarget` stay strings without a pattern, as the
  other bulk actions' targets: a value that is not an ID, an unknown ID and the ID of the other
  template type are `notFound`. The limit is the fixed 100 of the conventions, not
  `restv2.bulk_limit` (which bounds the mail-store calls of mailbox deletions). Legacy's domain
  template loop returns after the first ID (a bug); the API deletes every target. Unknown members are
  `immutable`, as in the mailbox bulk action. The resolver code for a bulk request refused as a whole
  moved to `RefusalAuditor::bulkRefusal()`, used by the domain, mailbox and both template resolvers.
  Writing the repeated-ID tests showed that `BulkActionRunner` resolved each target only when it
  reached it, so a second spelling of an object deleted by an earlier item (`anna@…` and `ANNA@…`,
  `87` and `087`) was reported `notFound` with a second record; the runner now resolves and
  deduplicates all targets before applying any (this also fixes 002's domain and 003's mailbox bulk
  deletions).
