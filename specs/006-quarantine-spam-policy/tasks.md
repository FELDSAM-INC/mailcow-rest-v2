---
description: "Task list for feature 006: quarantine, spam policy and rate limits"
---

# Tasks: Quarantine, Spam Policy and Rate Limits

**Input**: Design documents from `specs/006-quarantine-spam-policy/`: [plan.md](./plan.md),
[spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md),
[quickstart.md](./quickstart.md). The contract is `api/modules/{quarantine,spam-policy,rate-limits}/`
with its components (already authored and lint-clean). Problem types are in `docs/problems.md`.

**Tests**: Mandatory (constitution VII, test-first). In every phase the test tasks come first and
must be observed **failing** before the implementation tasks of that phase start. Every feature
test runs on MariaDB with the upstream `2026-09a` schema fixture and on a real Redis; every request
and response is validated against the contract by 001's `tests/TestCase.php`. The Rspamd controller
and Postfix are faked (`FakeRspamdController`, `FakeQuarantineReleaseMailer`) and the fakes assert
the calls made; the template engine (Python/Jinja2) runs for real.

**Dependencies on earlier features**: Features are implemented in number order. This feature
starts only when 001–005 are merged. It uses (research R1): from **001** `Principal`, `Role`,
`PermissionFlag`, `IdentityService`/`Scope`, `ProblemException`/`ProblemType`, `ETag`,
`ApiFormRequest`, `ListQuery`, `AuditLogger`, `MailcowConfig`, the preferences language, the Redis
connections `mailcow` and `restv2`, `MailcowDateTime`, `MailcowRows`/`MailcowFactory`,
`OpenApiContract`, the `api.v2` pipeline; from **002** (`specs/002-domains/plan.md`)
`App\Support\Domain\DomainName`, the `Domain` and `AliasDomain` models,
`App\Services\Domains\DomainScope`, `App\Clients\SharedStore\SharedStore` (with
`SharedStoreUnavailable`), `App\Services\RateLimits\RateLimitStore`, the `temporary-failure`
problem type, the generic audit filters `targetType`/`targetId` and its refusal auditing; from
**003** `App\Services\Mailboxes\MailboxScope` and the `Mailbox` model; from **004**
`App\Services\AliasDomains\AliasDomainQuery`; from **009** the `permission-flag-required` type.
006 makes no dockerapi call (002's `DockerApiClient` is not needed). T001 verifies them; if a
class has another name in the merged code, that name replaces the one used below.

**Commands**: every PHP, Composer, Node and Python command runs in the dev container:
`docker compose run --rm app <command>`.

## Format: `- [ ] [ID] [P?] [Story?] Description with file path`

- **[P]**: can run in parallel with the other [P] tasks of the same block (different files, no
  dependency on unfinished tasks).
- **[USn]**: the user story of the task. Setup, Foundational and Polish tasks have no story label.
- FR, SC and AS references point to `spec.md`, R references to `research.md`.
- "Roles × keys" in a test means: admin session, domain-admin session (with and without the
  relevant flag), mailbox session (with and without the relevant flags), admin read-write key,
  admin read-only key, domain-admin read-write key and domain-admin read-only key.

---

## Phase 1: Setup (shared infrastructure)

**Purpose**: Verify the code of earlier features, add the runtime pieces, configuration, legacy
extracts and fixtures. No business logic.

- [X] T001 Verify the prerequisites of R1 and record any differing class name at the top of this
  file (a "Resolved names" note): 001's classes listed above; 002's `app/Support/Domain/DomainName.php`,
  `app/Services/Domains/DomainScope.php`, `app/Clients/SharedStore/SharedStore.php`,
  `app/Services/RateLimits/RateLimitStore.php`, `ProblemType::TemporaryFailure`, the generic
  `targetType`/`targetId` filters of `GET /audit-events` and the refusal auditing hook (the
  route-declared `RefusalAuditor` of `docs/conventions.md`); 003's
  `app/Services/Mailboxes/MailboxScope.php`; 004's `app/Services/AliasDomains/AliasDomainQuery.php`;
  009's `ProblemType::PermissionFlagRequired` (add the case if 009 is not merged yet; its section
  is in `docs/problems.md`). If `SharedStore` has no optimistic `WATCH` variant, add
  `SharedStore::transactionWatching(array $keys, Closure $build)` with its unit test. If no earlier
  feature created the refusal hook, T023 creates it.
- [X] T002 Extend `docker/php/Dockerfile` (stage `base`): `pecl install mailparse` (3.2.x) plus
  `docker-php-ext-enable mailparse`; `apk add --no-cache python3 py3-jinja2 py3-markupsafe
  py3-html2text`. Rebuild and check `php -m | grep mailparse` and
  `python3 -I -c 'import jinja2.sandbox, html2text'` in the `dev` and `production` targets (R7, R23).
- [X] T003 Run `composer require php-mime-mail-parser/php-mime-mail-parser soundasleep/html2text`
  (the current majors that support PHP 8.4; MIT). Record the versions and licences in the
  dependency table of `README.md` (or `docs/dependencies.md` if 001 created it) (R23).
- [X] T004 Extend `config/restv2.php` and `.env.example` with the server settings of R20:
  `rspamd.socket` (`RESTV2_RSPAMD_SOCKET`, `/var/lib/rspamd/rspamd.sock`), `rspamd.timeout`
  (`RESTV2_RSPAMD_TIMEOUT`, 30), `release_smtp.host` (`RESTV2_RELEASE_SMTP_HOST`, `postfix`),
  `release_smtp.port` (`RESTV2_RELEASE_SMTP_PORT`, 590), `release_smtp.timeout` (30),
  `quarantine.content_limit` (`RESTV2_QUARANTINE_CONTENT_LIMIT`, 10485760),
  `quarantine.default_template` (`RESTV2_QUARANTINE_DEFAULT_TEMPLATE`,
  `/mailcow/assets/templates/quarantine.tpl`), `quarantine.template_timeout`
  (`RESTV2_TEMPLATE_RENDER_TIMEOUT`, 5), `skip_clamd` (`SKIP_CLAMD`, `n`). The bulk limit is the
  API-wide constant 100 (conventions), not a setting.
- [X] T005 [P] Deployment (R2, R7, R18, R20): in `deploy/docker-compose.override.yml` add the volumes
  `rspamd-vol-1:/var/lib/rspamd:ro` and `./data/assets/templates:/mailcow/assets/templates:ro,z`
  and the variable `SKIP_CLAMD=${SKIP_CLAMD:-n}` to `restv2-mailcow`; in
  `deploy/nginx/site.restv2.custom` set `fastcgi_read_timeout 300s;` in `location ^~ /api/v2/`; in
  `docker/php/php-fpm.conf` set `request_terminate_timeout = 300s`. Add an optional `mailpit`
  service (`axllent/mailpit`, MIT, profile `mail`, ports 1025 and 8025) to `compose.yaml` as the
  dev SMTP sink for releases (quickstart Part B).
- [X] T006 [P] Create `scripts/legacy/extract-quarantine-release-strings.sh <tag>`: for every
  `data/web/lang/lang.*.json` of the tag (`git -C "$MAILCOW_REPO" show <tag>:<path>`), extract
  `quarantine.release_subject` and `quarantine.release_body` into
  `resources/legacy/quarantine-release-strings.json` (`{"en-gb": {"subject": …, "body": …}, …}`,
  sorted, with the tag in a `_source` member). Copy `data/assets/templates/quarantine.tpl` of the
  tag to `resources/legacy/quarantine.tpl`. Run it for `2026-09a` and commit both files.
- [X] T007 [P] Create the message fixtures `tests/fixtures/quarantine/messages/*.eml` (R22):
  `plain.eml`, `html-only.eml`, `multipart-two-attachments.eml` (a PDF and a PNG),
  `html-attachment.eml`, `svg-attachment.eml`, `path-name-attachment.eml` (file name
  `../../etc/passwd`), `unnamed-attachment.eml`, `encoded-subject.eml` (RFC 2047 UTF-8 and
  ISO-8859-2 words), `unparseable.eml` (no MIME structure, HTML in the body), `spam-flag.eml`
  (`X-Spam-Flag: YES` header and a body line starting with `X-Spam-Flag: `), `dot-lines.eml`
  (body lines starting with `.`), `lf-only.eml` (LF line endings), `cc-bcc.eml` (To, Cc, Bcc and an
  invalid address). Add `tests/Support/Quarantine/LargeMessage.php`, which builds a message of
  10 MiB + 1 byte at runtime.
- [X] T008 [P] Create `scripts/legacy/generate-qitem-goldens.sh <tag>`: in the tag's
  `ghcr.io/mailcow/phpfpm:8.2.29-3` image with the tag's `data/web/inc/lib/vendor`, run a harness
  that applies the parsing part of `inc/ajax/qitem_details.php` (recipients, `header_from`,
  `text_plain`, `text_html`, subject, attachments with name, type, size and SHA-256) to each fixture
  of T007 and writes `tests/fixtures/quarantine/legacy/<name>.json`. Run it for `2026-09a` and
  commit the goldens.
- [X] T009 [P] Create the template fixtures `tests/fixtures/quarantine/templates/*.tpl` (SC-011):
  `default.tpl` (copy of `resources/legacy/quarantine.tpl`), `valid-simple.tpl`,
  `unclosed-for.tpl`, `unknown-tag.tpl`, `unsafe-attribute.tpl` (`{{ ''.__class__.__mro__ }}`),
  `sandbox-call.tpl` (`{{ meta.__init__.__globals__ }}`), `undefined-attribute.tpl`
  (`{{ nothing.attribute }}`), `zero-division.tpl` (`{{ 1/0 }}`), `big-range.tpl`
  (`{{ range(10**6)|list }}`), `endless.tpl` (nested `range(100000)` loops), `acl-branch-error.tpl`
  (an error only inside `{% if quarantine_acl == 1 %}`). Create
  `scripts/legacy/generate-template-goldens.sh <tag>`, which renders each fixture with the
  notifier's `SandboxedEnvironment` and the datasets of R7 in the tag's Dovecot image
  (`ghcr.io/mailcow/dovecot:2.3.21.1-2`, `python3`) and writes
  `tests/fixtures/quarantine/templates/goldens.json` (`parsed`, `rendered`, exception class, line,
  output). Run it for `2026-09a` and commit.

**Checkpoint**: the image has the runtime pieces; configuration, extracts and fixtures exist.

---

## Phase 2: Foundational (blocking prerequisites)

**Purpose**: Clients for Rspamd and Postfix, models, problem types, access checks and audit
plumbing that every story needs.

### Test support and tests for the foundation (write first, observe failing) ⚠️

- [X] T010 [P] Extend `database/factories/MailcowRows.php` (and the `MailcowFactory` wrapper) with
  rows exactly as legacy writes them:
  - `quarantineItem(rcpt, msg = fixture, qid, subject, score, action = 'reject', symbols = [],
    fuzzyHashes = [], sender, ip, user = 'unknown', notified = 0, created = now)`, which inserts
    like `pipe.php` and then sets `qhash = SHA2(CONCAT(id, qid), 256)`;
  - `filterconf(object, option, value)`; `spamScore(username, low, high)`;
  - `aliasDomain(alias, target, active = 1)` if 004 did not add it;
  - `mailboxAttributes(username, quarantine_notification, quarantine_category)`.
- [X] T011 [P] Create `tests/Support/Rspamd/FakeRspamdController.php` implementing
  `App\Clients\Rspamd\RspamdController`: records `calls` (`endpoint`, `flag`, `sha256` of the body,
  order); scripted results per endpoint (`done`, `alreadyLearned`, `noFuzzyContent`,
  `failed(<text>)`, `actions([...])`, `stat([...])`, `unreachable`); helpers `assertCalls([...])` and
  `assertNoCalls()`. Bind it in `tests/TestCase.php` through a `useFakeSpamFilter()` helper that
  every 006 feature test calls in `setUp()`.
- [X] T012 [P] Create `tests/Support/Mail/FakeQuarantineReleaseMailer.php` implementing
  `App\Clients\Mail\QuarantineReleaseMailer`: records envelope sender, recipient and the exact bytes;
  scripted outcomes `accepted`, `refused(550, '550 5.1.1 …')`, `temporaryFailure('timeout')`, an
  optional delay for concurrency tests; `assertDelivered(count)`, `assertNothingDelivered()`. Bind
  it through `useFakeReleaseMailer()` in `tests/TestCase.php`.
- [X] T013 [P] Create `tests/Support/Smtp/ScriptedSmtpServer.php` and the process script
  `tests/Support/Smtp/scripted-smtp-server.php`: listens on `127.0.0.1:<random port>`, answers a
  scripted reply per command (multi-line `250-` replies supported), records the full dialogue to a
  file, and stops on `QUIT` or after a timeout.
- [X] T014 [P] Write `tests/Unit/Clients/UnixSocketRspamdControllerTest.php` with `Http::fake()`
  (R2): `learnHam` posts to `http://rspamd/learnham` with `Content-Type: text/plain` and the exact
  body, and the cURL option `CURLOPT_UNIX_SOCKET_PATH` = `config('restv2.rspamd.socket')`;
  `fuzzyAdd($m, 13)` sends header `Flag: 13`; classification: `{"success":true}` → `done`; HTTP 208
  or `{"error":"<id> has been already learned as ham, ignore it"}` → `alreadyLearned`;
  `{"error":"No content to generate fuzzy"}` → `noFuzzyContent`; `{"error":"x"}` with 500, a
  connection exception and a timeout (30 s configured) → `failed` with the text; `actions()` and
  `stat()` decode JSON and fail on invalid JSON.
- [X] T015 [P] Write `tests/Unit/Clients/SmtpQuarantineReleaseMailerTest.php` against
  `ScriptedSmtpServer` (R3): the dialogue is `EHLO quarantine`, `MAIL FROM:<sender>`,
  `RCPT TO:<rcpt>`, `DATA`, message, `.`, `QUIT`; no `STARTTLS` even when the server offers it; a
  message with LF line endings is sent with CRLF; a line starting with `.` is sent as `..`;
  `550` at `RCPT` → `refused` with code 550 and the full reply; `451` → `temporaryFailure`;
  connection refused and a server that never answers (timeout 1 s in the test) → `temporaryFailure`.
- [X] T016 [P] Write `tests/Unit/Quarantine/SpamFilterAccessTest.php` (R15, FR-001 to FR-006,
  FR-069) for `App\Policies\QuarantinePolicy`, `SpamPolicyPolicy` and `RateLimitPolicy` with
  principals built from `MailcowFactory` rows:
  - admin: every ability; domain admin: `quarantine`, `spam_policy`, `ratelimit` from `da_acl`, the
    user-only flags always held (a `da_acl` row with `quarantine = 0` and a `user_acl`-style
    restriction cannot remove `quarantine_attachments`); mailbox user: its `user_acl` row;
  - whole-message download requires `quarantine` and, for mailbox users, `quarantine_attachments`;
    `requiredFlags` names the missing flag as in `PermissionFlags` (`["quarantine"]` first when both
    `quarantine` and `quarantineAttachments` are missing, then `["quarantineAttachments"]`);
  - role refusals (mailbox user and rate limits, domain admin and settings) are `forbidden`, not
    `permission-flag-required`;
  - the flags of the target mailbox never restrict admins or domain admins (FR-005).
- [X] T017 [P] Write `tests/Unit/Support/SpamFilterProblemTest.php`: `ProblemType` has the cases
  `QuarantineItemBusy` (409), `QuarantineDeliveryRefused` (409), `QuarantineMessageTooLarge` (409),
  `SpamPolicyEntryExists` (409); `temporaryFailure(dependency, step)` renders `dependency`, `step`
  and `Retry-After: 30`; `quarantine-item-busy` renders `Retry-After: 5`; every rendered problem
  validates against its contract schema.

### Implementation of the foundation

- [X] T018 Create `app/Clients/Rspamd/RspamdController.php` (interface: `learnHam`, `learnSpam`,
  `fuzzyAdd(string $message, int $flag)`, `actions()`, `stat()`), `LearnOutcome.php` (enum-backed
  value with the error text) and `UnixSocketRspamdController.php` (R2). Bind the adapter in
  `app/Providers/AppServiceProvider.php`. This makes T014 pass.
- [X] T019 Create `app/Clients/Mail/QuarantineReleaseMailer.php` (interface:
  `deliver(RawMessage $message, string $sender, string $recipient): DeliveryOutcome`),
  `DeliveryOutcome.php` and `SmtpQuarantineReleaseMailer.php` on Symfony Mailer's `EsmtpTransport`
  with local domain `quarantine`, auto-TLS off, the configured host, port and timeout, CRLF
  normalisation of `RawMessage` content, and the outcome classification of R3. Bind it in
  `AppServiceProvider`. This makes T015 pass.
- [X] T020 [P] Create `app/Models/Mailcow/QuarantineItem.php` (`$table = 'quarantine'`, `$primaryKey
  = 'id'`, `$timestamps = false`, `$guarded = ['*']`, casts: `created` → `MailcowDateTime`,
  `symbols` and `fuzzy_hashes` → array, `notified` → bool, `score` → float; `msg` and `qhash` in
  `$hidden`; a scope `withoutMessage()` selecting every column except `msg`) and
  `app/Models/Mailcow/FilterPreference.php` (`$table = 'filterconf'`, `$primaryKey = 'prefid'`,
  `$timestamps = false`, `created` cast).
- [X] T021 Add the four problem types to `app/Support/Http/ProblemType.php` with their English
  titles ("Quarantine item is being processed", "Delivery refused", "Quarantine message too large",
  "List entry already exists"), and factory methods in `app/Exceptions/ProblemException.php`:
  `permissionFlagRequired(array $requiredFlags)`, `attributeNotPermitted(array $attributes, array
  $requiredFlags)` (if 002 has no factory for it), `quarantineItemBusy()`, `quarantineDeliveryRefused(int
  $code, string $reply)`, `quarantineMessageTooLarge(int $size, int $limit)`,
  `spamPolicyEntryExists(string $list, int $entryId)`, `temporaryFailure(string $dependency, ?string
  $step = null)` (on top of 002's case). This makes T017 pass.
- [X] T022 Create `app/Policies/QuarantinePolicy.php`, `app/Policies/SpamPolicyPolicy.php` and
  `app/Policies/RateLimitPolicy.php` with the abilities of FR-006 (role check → `forbidden`, flag
  check → `permissionFlagRequired(requiredFlags)`, or `attributeNotPermitted(attributes, requiredFlags)` for a
  mailbox user's notification members that would change), using 001's effective flags, and
  `app/Services/Access/SpamFilterScope.php` with `itemScope(Principal): Closure` (admin: none;
  domain admin: `SUBSTRING_INDEX(rcpt, '@', -1) IN (<managed domains>)`; mailbox user:
  `rcpt = <username>`) and `mailbox()`, `domain()`, `aliasDomain()` resolvers that normalise the
  path value, apply 002–004's reachability and resource exclusion (`kind NOT REGEXP
  'location|thing|group'`) and throw `404` otherwise. Register the policies. This makes T016 pass.
- [X] T023 Register the 006 audit event types and `details` whitelists of data-model.md "Audit
  records" in `app/Services/Audit/AuditLogger.php` (or its event registry). Refusal auditing
  (R19, conventions "Refusal auditing"): reuse the route-declared hook
  `App\Services\Audit\RefusalAuditor` (called from 001's problem rendering in `bootstrap/app.php`
  for a `4xx` problem when the matched route declares `->defaults('restv2.audit', ['event' => …,
  'target' => …])`, the request has an authenticated principal and the type is not
  `csrf-token-mismatch`); create it with its unit test `tests/Unit/Services/RefusalAuditorTest.php`
  if no earlier feature did. Register resolvers for the target types `quarantineItem` (id, queue
  id, recipient; never the subject), `quarantineSettings`, `quarantineNotificationSettings`,
  `spamScore`, `spamPolicyEntry`, `rateLimit` and `rateLimitBucket`, and the `failureReason`
  mapping `permissionDenied` (role, flag, key scope, restricted session), `attributeNotPermitted`,
  `notFound`,
  `versionOutdated`, `validationFailed` (field names only), `conflict` (busy item, existing entry),
  `serviceFailure` (refused delivery), `temporaryFailure`. Every state-changing route registered by
  the tasks below declares `restv2.audit`.

**Checkpoint**: clients, models, problem types and access checks exist; user stories can start.

---

## Phase 3: User Story 1 — Review and act on my own quarantine (Priority: P1) 🎯 MVP

**Goal**: A mailbox user lists, inspects, downloads, releases (with or without learning), learns as
spam and deletes the items of their own mailbox.

**Independent test**: Seed items for several mailboxes (rejected, junk, virus, attachments, over
10 MiB, unparseable) and run the four flag combinations of `quarantine` × `quarantine_attachments`
for a mailbox user; compare deliveries, remaining rows and Rspamd calls with legacy.

### Tests for User Story 1 (write first, observe failing) ⚠️

- [X] T024 [P] [US1] Write `tests/Unit/Quarantine/QuarantineMessageParserTest.php` (R5, FR-013,
  SC-001): for every fixture of T007, the parsed content equals the legacy golden of T008 modulo
  the stated deviations (`""` for legacy `-`, `textPlain` `null` instead of `-` or "Parser failed,
  assuming HTML", `parserFallback: true` for `unparseable.eml`, safe names, no VirusTotal URL);
  `cc-bcc.eml` lists To, Cc, Bcc and the envelope recipient last, without the invalid address;
  invalid UTF-8 is scrubbed; no HTML tag appears in any text; attachments carry position, name,
  declared type, decoded size and SHA-256; nothing is written to disk.
- [X] T025 [P] [US1] Write `tests/Unit/Quarantine/DownloadFileNameTest.php` (R6, FR-016, FR-017):
  attachment `../../etc/passwd` → `....etcpasswd` without separators; control characters removed;
  empty name → `attachment-3` for position 3; message file name from the subject with tags removed,
  cut to 30 characters (multi-byte safe), `.eml` appended; empty subject → `<qid>.eml`; the
  `Content-Disposition` has an ASCII `filename` and a UTF-8 `filename*`.
- [X] T026 [P] [US1] Write `tests/Unit/Quarantine/ReleaseMessageFactoryTest.php` (R3, FR-020):
  `original`: only lines matching `^X-Spam-Flag: ` are renamed `X-Pre-Release-Spam-Flag: ` (header
  and the body line of `spam-flag.eml`, legacy regex), the rest is byte-identical; `attachment`: a
  message from the sender to the recipient with subject `Potentially damaging quarantine item
  <qid>` and body "We have attached your message as eml file to this message." in `en-gb`, the
  German strings for a user whose preference is `de-de`, `DEFAULT_LANG` for an API key, English for
  a language without a translation, and one `message/rfc822` attachment named `<qid>.eml` whose
  content is the stored message; sender fallback: empty, `unknown` → `sender-unknown@rspamd`.
- [X] T027 [P] [US1] Write `tests/Feature/Quarantine/ListOwnQuarantineTest.php` for
  `listQuarantineItems` as a mailbox user (FR-001, FR-007, FR-008, AS-1.1): 30 items for alice and
  5 for bob → alice gets 25, newest first, `meta.total` 30, none of bob's; `offset=25` gives 5;
  each entry has every member of `QuarantineItem` with `subject` `""` for NULL, `score` `null`,
  `action` mapped (`reject` → `rejected`, `add header` → `junk`, `rewrite subject` →
  `subjectRewritten`, `foo` → `other` with `filterAction: "foo"`), `virus` from `VIRUS_FOUND`,
  `arrivedAt` in UTC for `TZ=Europe/Prague`, `etag`; the JSON never contains `qhash` or `msg`;
  listing works without the `quarantine` flag.
- [X] T028 [P] [US1] Write `tests/Feature/Quarantine/InspectQuarantineItemTest.php` for
  `getQuarantineItem` (FR-012 to FR-015, AS-1.2, AS-1.7, AS-1.8, AS-1.9): a virus item returns
  score, `action: rejected`, `virus: true`, symbols with name/score/options, `fuzzyHashes`,
  `sourceAddress`, `size` = `LENGTH(msg)`, `contentStatus: available`, `from`, `subject`,
  recipients and both texts, no HTML; without `quarantine_attachments` `content.attachments` is
  `null`, with it the two attachments are listed; `authenticatedSender` is absent for a mailbox
  user (and present for an admin); a >10 MiB item → `contentStatus: tooLarge`, `content: null`,
  symbols still present; an item without `msg` → `missing`; bob's item and an unknown id → the same
  `404`; the `ETag` header equals `data.etag`; reading writes no audit record.
- [X] T029 [P] [US1] Write `tests/Feature/Quarantine/DownloadQuarantineFilesTest.php` for
  `downloadQuarantineMessage` and `downloadQuarantineAttachment` (FR-006, FR-016 to FR-018,
  AS-1.6, AS-1.7, AS-2.4): the message download returns the stored bytes as `message/rfc822`;
  attachments return the decoded bytes as `application/octet-stream`, also for the HTML and SVG
  attachments; every download carries `Content-Disposition: attachment`,
  `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; sandbox` and
  `Cache-Control: private, no-store`; a mailbox user without `quarantine` → `403
  permission-flag-required` `requiredFlags: ["quarantine"]` for the message; without
  `quarantineAttachments` → `requiredFlags: ["quarantineAttachments"]` for both downloads; position 9 → `404`; >10 MiB →
  `409 quarantine-message-too-large` with `size` and `limit`; a read-only key of an admin may
  download; not audited.
- [X] T030 [P] [US1] Write `tests/Feature/Quarantine/ReleaseQuarantineItemTest.php` for
  `releaseQuarantineItem` (FR-019 to FR-022, FR-025, FR-028, AS-1.3, AS-1.4, edge cases):
  - release format `raw` and with no `Q_RELEASE_FORMAT` → the fake mailer received the renamed
    original from the stored sender to `rcpt`; `attachment` → the wrapper of T026;
  - with learning: Rspamd calls exactly `learnham`, then `fuzzyadd` with `Flag: 13`, once each, on
    the stored message; the row is gone; the report is `delivery: done, learning: done, fuzzy:
    done, itemRemoved: true`; `alreadyLearned` → `learning: alreadyLearned` and the fuzzy call
    happens; `noFuzzyContent` → `fuzzy: skipped`; learning `failed` → `learning: failed`,
    `learningError` set, `fuzzy: notRequested`, no fuzzy call, the row is still gone, `200`;
  - `{"learnAsHam": false}` → delivered and removed, `assertNoCalls()` on Rspamd, `learning:
    notRequested`;
  - mail server refuses (550) → `409 quarantine-delivery-refused` with `smtpCode` and
    `smtpResponse`, the row kept, no Rspamd call; mail server unreachable → `503
    temporary-failure` `dependency: mailServer`, `step: delivery`, the row kept;
  - item whose recipient mailbox was deleted: the fake refuses → `409`, kept;
  - `quarantine.notified` of other rows is unchanged, `Q_LAST_NOTIFIED` untouched;
  - a mailbox user without `quarantine` → `403 permission-flag-required` before the lookup (also for
    an unknown id); bob's item → `404`; `If-Match` with the item's `etag` → `200`, with a wrong
    tag → `412` and nothing delivered; Redis stopped (pointing the `restv2` connection at a closed
    port) → `503` `sharedStore`, nothing delivered;
  - one audit record `quarantineItemRelease` with `queueId`, `recipient`, `sender`, `action`,
    `steps`, no subject and no `qhash`; refusals write a failure record with `failureReason`
    (`permissionDenied` for a missing flag, an admin read-only key — `403 insufficient-key-scope` —
    and a restricted session; `serviceFailure` for a refused delivery); a CSRF failure and an
    unauthenticated request write none.
- [X] T031 [P] [US1] Write `tests/Feature/Quarantine/LearnQuarantineItemAsSpamTest.php` for
  `learnQuarantineItemAsSpam` (FR-023, FR-025, AS-1.5, AS-2.7): Rspamd calls `learnspam`, then
  `fuzzyadd` with `Flag: 11`; the row is removed only afterwards; `alreadyLearned` counts as success;
  learning `failed` or unreachable → `503 temporary-failure` (`spamFilter`, `learning`), the row
  kept, no fuzzy call, a failure audit record; fuzzy `failed` → `200` with `fuzzy: failed`,
  `fuzzyError`, the row removed; nothing is delivered (`delivery: notApplicable`); the audit record
  `quarantineItemSpamLearning` has the steps.
- [X] T032 [P] [US1] Write `tests/Feature/Quarantine/DeleteQuarantineItemTest.php` for
  `deleteQuarantineItem` (FR-024): `204` with no body, the row gone, no Rspamd call, nothing
  delivered; without `quarantine` → `403`; another mailbox's item → `404`; `If-Match` mismatch →
  `412`; audit `quarantineItemDeletion` without subject; a second delete → `404`.

### Implementation for User Story 1

- [X] T033 [US1] Create `app/Services/Quarantine/QuarantineMessageParser.php` and `ParsedMessage.php`
  (R5): parse only when `strlen($msg) <= config('restv2.quarantine.content_limit')`; recipients,
  `from`, `subject` (`mb_convert_encoding(..., 'UTF-8', 'auto')`), `textPlain` (`null` when empty),
  `textFromHtml` (`Html2Text::convert`), raw-body fallback with `parserFallback`, `mb_scrub` on every
  text, attachments from `getAttachments(true)` in memory with position, safe name, declared type,
  decoded size and SHA-256. This makes T024 pass.
- [X] T034 [P] [US1] Create `app/Services/Quarantine/DownloadFileName.php` (`forAttachment(string
  $name, int $position)`, `forMessage(?string $subject, string $qid)`, `disposition(string $name)` via
  `HeaderUtils::makeDisposition`). This makes T025 pass.
- [X] T035 [P] [US1] Create `app/Services/Quarantine/ReleaseMessageFactory.php` (R3): `original()`
  with the legacy regex, `attachment()` building a Symfony `Email` with the strings of
  `resources/legacy/quarantine-release-strings.json` for the acting principal's effective language,
  and `envelopeSender()` with the fallback. This makes T026 pass.
- [X] T036 [US1] Create `app/Services/Quarantine/QuarantineItemLock.php` (R4: Laravel cache lock on
  the `restv2` store, key `quarantine-item:<id>`, TTL 300 s, non-blocking `acquire(int $id):
  ?Lock`, `release()` in `finally`, Redis errors → `temporaryFailure('sharedStore')`) and
  `app/Services/Quarantine/ActionReport.php`.
- [X] T037 [US1] Create `app/Services/Quarantine/QuarantineActionService.php` with `release(Principal,
  int $id, bool $learnAsHam, ?string $ifMatch)`, `learnAsSpam(...)` and `delete(...)` (R3, R4, R19):
  policy check → scoped lookup (`404`) → `If-Match` (`412`) → lock (busy: re-check → `404` or
  `409` with `Retry-After: 5`) → re-read the row with `msg` → steps in the order of FR-021 to
  FR-024 (release: deliver → `DELETE` in its own transaction → learn ham → fuzzy 13 → audit, a
  failed audit write answering `500` with the full record logged at `error`; learn as spam: learn
  → fuzzy 11 → transaction {`DELETE`, audit}; delete: transaction {`DELETE`, audit}), reading
  `Q_RELEASE_FORMAT` on the `mailcow` connection (`503 sharedStore` on failure), refusals through
  `RefusalAuditor`. It never touches `notified` or `Q_LAST_NOTIFIED`.
- [X] T038 [US1] Create `app/Services/Quarantine/QuarantineQuery.php` with the scoped base query
  (`withoutMessage()`, the `virus` expression, `SpamFilterScope::itemScope`), pagination through
  `ListQuery` and the default sort `created DESC, id DESC`, and `findInScope(Principal, int $id,
  bool $withMessage = false)`.
- [X] T039 [US1] Create `app/Http/Resources/Api/V2/QuarantineItemResource.php`,
  `QuarantineItemDetailsResource.php` (contract shapes; `authenticatedSender` only for admins;
  `content.attachments` `null` without the attachment right; `etag` over `id|qid|rcpt|created`) and
  `QuarantineActionReportResource.php`.
- [X] T040 [US1] Create `app/Http/Requests/Api/V2/ListQuarantineItemsRequest.php` (pagination, `q`,
  `sort`, `order`) and `ReleaseQuarantineItemRequest.php` (`learnAsHam` optional boolean, default
  `true`, body optional, unknown members → `unknownField` or `invalidType`).
- [X] T041 [US1] Create the controllers `app/Http/Controllers/Api/V2/QuarantineItemController.php`
  (`index`, `show`, `destroy`), `QuarantineItemReleaseController.php` (`store`),
  `QuarantineItemSpamLearningController.php` (`store`) and `QuarantineItemDownloadController.php`
  (`message`, `attachment`, headers of R6, `409` for too-large messages), and register in
  `routes/api.php`, with `restv2.auth:authenticated` and `whereNumber('itemId')`:
  `GET /quarantine`, `GET|DELETE /quarantine/{itemId}`, `POST /quarantine/{itemId}/release`,
  `POST /quarantine/{itemId}/spam-learning`, `GET /quarantine/{itemId}/message`,
  `GET /quarantine/{itemId}/attachments/{position}` (after the literal `/quarantine/settings`,
  `/quarantine/bulk-actions` routes of later phases). This makes T027 to T032 pass.

**Checkpoint**: US1 works for mailbox users: the MVP.

---

## Phase 4: User Story 2 — Handle the quarantine of all mailboxes in my scope (Priority: P1)

**Goal**: Administrators and domain administrators filter, search, sort and act in bulk across their
scope; concurrent actions are safe; clients read the capabilities.

**Independent test**: Run listing, filters, downloads and bulk actions as every role and key type
across several domains; run concurrent releases; make Rspamd unreachable during learning.

### Tests for User Story 2 (write first, observe failing) ⚠️

- [X] T042 [P] [US2] Write `tests/Feature/Quarantine/QuarantineScopeAndFilterTest.php` for
  `listQuarantineItems` (FR-001, FR-009 to FR-011, AS-2.1): a domain admin of 2 of 5 domains gets
  exactly the items whose recipient domain is managed, including an item of a deleted mailbox and
  an item of a resource; admin gets all; filters `recipient`, `domain` (also in Unicode form),
  repeated `action` (incl. `other`), `virus`, `notified`, `from`/`to` (UTC against `TZ` storage),
  `minScore`/`maxScore` (NULL scores left out) each give the matching `meta.total`; `q` matches
  subject, sender, recipient and queue id case-insensitively, with `%` and `_` literal; every sort
  field ascending and descending, ties by `id DESC`; invalid values (`sort=foo`, `action=bar`,
  `limit=1001`, `from=yesterday`) → `422` with rule codes.
- [X] T043 [P] [US2] Write `tests/Feature/Quarantine/QuarantineBulkActionTest.php` for
  `applyQuarantineBulkAction` (FR-027, AS-2.2, AS-2.3, AS-2.8): 40 targets with one removed → 39
  `done` with `report`, one `notFound`, `summary` counts, 40 audit records sharing `bulkRequestId`
  (39 success, 1 failure); each action (`release`, `releaseWithoutLearning`, `learnAsSpam`,
  `delete`) produces the single-item side effects; a target with an outdated `version` →
  `outdated`, unchanged, the others proceed; a target named twice → processed and reported once;
  a refused delivery → `refused` with `quarantine-delivery-refused`; Rspamd down during
  `learnAsSpam` → `failed` with `temporary-failure`, rows kept; a domain admin without `quarantine`
  → `403 permission-flag-required`, nothing removed; a read-only key → `403
  insufficient-key-scope`, with one failure record (null target, `details.itemIds`); 101 targets
  → `422 tooMany` `max: 100`; out-of-scope ids → `notFound`.
- [X] T044 [P] [US2] Write `tests/Feature/Quarantine/QuarantineConcurrencyTest.php` (FR-026,
  AS-2.5, SC-006): while the lock of an item is held (acquired in the test), release, learn and
  delete answer `409 quarantine-item-busy` with `Retry-After: 5`, and after the item is removed
  `404`; ten PHP processes (`Symfony\Component\Process`) run `php artisan restv2:test-release`
  over the same 100 items in shuffled order with a fake mailer delay of 20 ms — 1,000 release
  attempts (SC-006) — and every item is delivered exactly once and learned exactly once (the fakes
  append their calls to a shared file), every other attempt ending in `409` or `404`; a lock left by
  a killed process expires after its TTL (a short test TTL).
- [X] T045 [P] [US2] Write `tests/Feature/Quarantine/QuarantineRoleAndKeyAccessTest.php` (FR-004,
  FR-006, AS-2.6): roles × keys for list, inspect, downloads, release and delete: a domain admin's
  read-only key with `quarantine` lists and downloads within the scope and gets `403
  insufficient-key-scope` on release; a domain admin without `quarantine` lists, inspects and
  downloads attachments but gets `permission-flag-required` for the whole message and actions; a
  domain admin's key acts with the owner's current flags (flag removed in `da_acl` → next request
  refused, FR-002); a restricted session → `403 pending-action-required`.
- [X] T046 [P] [US2] Write `tests/Feature/Quarantine/QuarantineCapabilitiesTest.php` for
  `getQuarantineCapabilities` at `GET /capabilities/quarantine` (FR-072, AS-2.9): every role and both key types get `bulkLimit` 100,
  `defaultPageSize` 25, `maxPageSize` 1000, `contentSizeLimit` 10485760,
  `pipelineMaxMessageSize` 10485760, the spam score range 0–5000 step 0.1, `senderPattern`
  (`maxLength` 100, `allowedCharacters` `a-z0-9@_.*-`), the rate limit range and frames, the
  settings limits (`maxMessageSizeStep` 1048576, `notificationTemplateMaxBytes` 1048576,
  `templateRenderTimeoutSeconds` 5, `releaseFormats`), `virusScanning: true`; with `SKIP_CLAMD=y`
  `false`; with `RESTV2_QUARANTINE_CONTENT_LIMIT=5242880` `contentSizeLimit` 5242880; no audit
  record.

### Implementation for User Story 2

- [X] T047 [US2] Extend `app/Services/Quarantine/QuarantineQuery.php` and
  `ListQuarantineItemsRequest.php` with the filters, search and sort map of R16 (`recipient`
  normalised, `domain` normalised with 002's normaliser, repeated `action` read from the raw query
  string like 001's audit filter, `virus`, `notified`, `from`/`to` converted to `TZ`, score range,
  escaped `LIKE` for `q`, every sort field with `id DESC` tie-break). This makes T042 pass.
- [X] T048 [US2] Create `app/Http/Requests/Api/V2/QuarantineBulkActionRequest.php` (`action` enum,
  `targets` 1–100 `BulkTarget`s with `id` `^[0-9]+$`, `tooMany` `max: 100`),
  `app/Services/Quarantine/QuarantineBulkActions.php` (R18, built like 002's `DomainBulkActions`:
  whole-request policy check, deduplication, `QuarantineActionService` per distinct target with its
  `version` as `If-Match`, outcome mapping, `report` on `done` items, per-item audit with
  `bulkRequestId`) and `app/Http/Controllers/Api/V2/QuarantineBulkActionController.php` (`store`,
  `QuarantineBulkResult` response); register `POST /quarantine/bulk-actions` before
  `/quarantine/{itemId}`. This makes T043 and T045 pass.
- [X] T049 [US2] Add the test-only artisan command `tests/Support/Commands/TestReleaseCommand.php`
  (`restv2:test-release`, registered only in the `testing` environment) used by T044, and make
  `QuarantineItemLock` TTL configurable for tests (`restv2.quarantine.lock_ttl`, default 300). This
  makes T044 pass.
- [X] T050 [US2] Create `app/Services/Quarantine/QuarantineCapabilities.php` (values from
  configuration, FR-072) and `app/Http/Controllers/Api/V2/QuarantineCapabilitiesController.php`
  (`show`); register `GET /capabilities/quarantine` (`restv2.auth:authenticated`). This makes T046
  pass.

**Checkpoint**: US1 and US2 work; both P1 stories are complete.

---

## Phase 5: User Story 3 — Configure the quarantine system (Priority: P2)

**Goal**: Administrators read and change the quarantine settings with the template check,
validate-only and preview; other roles read the public subset.

**Independent test**: Read on a fresh Redis, change every setting with valid and invalid values,
restore the default template, check and preview templates, provoke an outdated save, and compare
with the legacy settings tab.

### Tests for User Story 3 (write first, observe failing) ⚠️

- [ ] T051 [P] [US3] Write `tests/Unit/Quarantine/QuarantineTemplateRendererTest.php` (R7, FR-070,
  SC-011): for every template fixture of T009 the outcome equals `goldens.json` (accepted with the
  same `html` and `text` for both datasets, or refused with the same reason class and line); the
  reasons map to `syntax`, `security`, `render`; `endless.tpl` → `timeout` after 5 s (1 s in the
  test via config); the subprocess gets an empty environment and `-I -B`; output above 8 MiB is
  refused as `render`; a missing `python3` binary (configurable path) → an exception that becomes
  `500`.
- [ ] T052 [P] [US3] Write `tests/Feature/Quarantine/QuarantineSettingsTest.php` for
  `getQuarantineSettings` and `updateQuarantineSettings` (FR-029 to FR-034, FR-059 to FR-061,
  AS-3.1 to AS-3.7):
  - empty Redis → `active: false`, retention 0, size 0, `notificationScoreLimit: null`,
    `maxAgeDays` 365, `original`, `quarantine@localhost` with `notificationSenderIsDefault`, the
    default subject, no copy/redirect, the default template with `notificationTemplateIsDefault`;
  - legacy-written values (`Q_MAX_SIZE` 15, `Q_RELEASE_FORMAT raw`, `Q_EXCLUDE_DOMAINS
    ["example.net"]`, `Q_HTML` text) are read back converted;
  - `PATCH {"retentionPerMailbox":20,"maxMessageSize":15728640}` → `active: true`, Redis
    `Q_RETENTION_SIZE` = `20`, `Q_MAX_SIZE` = `15`, other keys untouched;
  - each rule of data-model.md "Quarantine settings" with an invalid value gives its rule code and
    leaves Redis unchanged: `maxMessageSize` 1000 → `notMultipleOf` `factor: 1048576`; `maxAgeDays`
    0 → `outOfRange`; `copyRecipient` `not-an-address` → `invalidFormat` `email`; subject of 256
    characters → `tooLong`; template of 1048577 bytes → `tooLong` `unit: bytes`; excluded domain
    `unknown.example` → `notFound` on `excludedDomains.0`; `releaseFormat` `raw` → `notInList`;
    empty body → `required` on `body`;
  - excluded domains are normalised (`  ÉXAMPLE.org ` → its ASCII form), deduplicated and stored as
    a JSON list; a stored entry whose domain was deleted is listed in `missingExcludedDomains` and
    can be sent back unchanged, but not added again;
  - `null`/`""` for sender, subject, copy, redirect and template store `""`; an empty template
    restores the default (`notificationTemplateIsDefault: true`);
  - `If-Match` with an old tag → `412`, Redis unchanged; a legacy write between read and save
    changes the tag;
  - Redis unavailable → `503 temporary-failure` `sharedStore`, nothing written (for GET too);
  - a domain admin and a mailbox user get only `visibility: public`, `active`,
    `retentionPerMailbox`, `maxMessageSize`, `etag`; their `PATCH` → `403 forbidden`; read-only
    keys may read, not write;
  - one audit record with `changes` old/new, the template only as `changed`, `oldSize`, `newSize`.
- [ ] T053 [P] [US3] Write `tests/Feature/Quarantine/QuarantineTemplateTest.php` (FR-070, FR-071,
  AS-3.8 to AS-3.10): saving `unclosed-for.tpl` → `422 invalidTemplate` `reason: syntax`, `line`;
  `unsafe-attribute.tpl` and `sandbox-call.tpl` → `security` or `render` as in the goldens;
  `acl-branch-error.tpl` → refused (the `quarantine_acl` 1 dataset); nothing stored, a failure audit
  record; `?validateOnly=true` with a valid template → `200` `{"data": {"valid": true}}` without
  `ETag`, Redis unchanged, no audit record;
  with an invalid one → the same `422`; with a stale `If-Match` → `412`;
  `previewQuarantineTemplate` without body → the stored or default template rendered with dataset
  B: `subject`, `html` containing the release and delete links with the zero `qhash` and
  `MAILCOW_HOSTNAME`, `text` from html2text, `sample` echoing the data; `quarantineAcl: false` →
  no links; a given invalid template → `422` on `template`; a domain admin → `403 forbidden`; a
  read-only key → `403 insufficient-key-scope`; previews are not audited.

### Implementation for User Story 3

- [ ] T054 [US3] Create `resources/bin/quarantine-template.py` (stdin JSON → `SandboxedEnvironment`
  `from_string` and `render` per dataset → `html2text.html2text`; exceptions mapped to `syntax`
  (`TemplateSyntaxError.lineno`), `security`, `render`, with the line from the traceback when
  available; stdout JSON) and `app/Services/Quarantine/QuarantineTemplateRenderer.php` plus
  `TemplateCheckResult.php` (R7: `Process(['python3', '-I', '-B', …])`, empty environment, timeout
  from config, 8 MiB output cap, datasets A and B with the fixed sample values, `check()` and
  `preview(bool $quarantineAcl)`). This makes T051 pass.
- [ ] T055 [US3] Create `app/Services/Quarantine/QuarantineSettingsService.php` (R8: `MGET` of the
  eleven keys through 002's `SharedStore`, conversions and defaults, default template from
  `config('restv2.quarantine.default_template')` with the bundled fallback, ETag over the raw
  values, `update()` with `WATCH`/`MULTI`/`EXEC` writing only changed keys in legacy formats, one
  retry, audit record inserted in the transaction before `EXEC`, `validateOnly` path that stops
  before writing).
- [ ] T056 [US3] Create `app/Rules/MailboxSize.php` (multiple of 1048576), `app/Rules/NotificationTemplate.php`
  (byte length, then `QuarantineTemplateRenderer::check()` → `invalidTemplate` with `reason` and
  `line`) and `app/Http/Requests/Api/V2/UpdateQuarantineSettingsRequest.php` (every rule of
  data-model.md "Quarantine settings", excluded domains through 002's normaliser and existence in
  `domain`/`alias_domain` or the stored list) and `PreviewQuarantineTemplateRequest.php`
  (`template` string ≥ 1 or `null`, ≤ 1048576 bytes, `quarantineAcl` boolean).
- [ ] T057 [US3] Create `app/Http/Resources/Api/V2/QuarantineSettingsResource.php` (full or public
  visibility), `app/Http/Controllers/Api/V2/QuarantineSettingsController.php` (`show`, `update`
  with `validateOnly` → `200` `{"data": {"valid": true}}`) and `QuarantineTemplatePreviewController.php` (`store`); register
  `GET|PATCH /quarantine/settings` and `POST /quarantine/settings/template-preview` before
  `/quarantine/{itemId}`. This makes T052 and T053 pass.

**Checkpoint**: US3 works on its own.

---

## Phase 6: User Story 4 — Choose quarantine notifications for a mailbox (Priority: P2)

**Goal**: Mailbox users, domain administrators and administrators read and change a mailbox's
notification frequency and category, singly and in bulk.

**Independent test**: Change frequency and category as each role with and without each flag,
singly and in bulk; confirm the stored attributes the legacy pages and the notifier read.

### Tests for User Story 4 (write first, observe failing) ⚠️

- [ ] T058 [P] [US4] Write `tests/Feature/Quarantine/QuarantineNotificationSettingsTest.php` for
  `getMailboxQuarantineNotificationSettings` and `updateMailboxQuarantineNotificationSettings`
  (FR-035, FR-036, FR-038, FR-069, AS-4.1, AS-4.2, AS-4.4): read returns `frequency`, `category`,
  `etag`; a mailbox without the attributes → `never`, `null`; stored `add_header` → `junk`;
  `PATCH {"frequency":"daily"}` with `quarantine_notification` → `JSON_VALUE(attributes,
  '$.quarantine_notification')` = `daily`, the category unchanged, `quarantine.notified` and
  `Q_LAST_NOTIFIED` untouched; `{"category":"junk"}` stores `add_header`; without
  `quarantineCategory` → `403 attribute-not-permitted` with `attributes: [category]` and
  `requiredFlags: {category: quarantineCategory}`, while the same request sending the current
  category is accepted; a domain admin
  and an admin may always change; another mailbox → `404`; a resource → `404`; `{"frequency":
  "monthly"}` → `notInList`; `{}` → `required` on `body`; `If-Match` mismatch → `412`; audit with
  old and new values.
- [ ] T059 [P] [US4] Write `tests/Feature/Quarantine/QuarantineNotificationBulkTest.php` for
  `applyQuarantineNotificationBulkAction` (FR-037, AS-4.3): a domain admin sets `weekly` on 30
  mailboxes of their domains and one of another domain → 30 `done` with the new `version`, one
  `notFound`; an outdated `version` → `outdated`; a duplicate target → once; a resource → `notFound`;
  a mailbox user → `403 forbidden`; a read-only key → `403`; body without `frequency` and
  `category` → `422`; 101 targets → `tooMany`; every audit record carries `bulkRequestId`.

### Implementation for User Story 4

- [ ] T060 [US4] Create `app/Services/Quarantine/QuarantineNotificationService.php` (R9: read with
  `JSON_VALUE`, mapping, ETag; `update()` in a transaction with `SELECT … FOR UPDATE`, `If-Match`,
  legacy `JSON_SET` statements, audit) and
  `app/Http/Resources/Api/V2/QuarantineNotificationSettingsResource.php`.
- [ ] T061 [US4] Create `app/Http/Requests/Api/V2/UpdateQuarantineNotificationSettingsRequest.php`
  and `QuarantineNotificationBulkRequest.php`, and the controllers
  `app/Http/Controllers/Api/V2/QuarantineNotificationSettingsController.php` (`show`, `update`,
  flag checks per member), `app/Services/Quarantine/QuarantineNotificationBulkActions.php` (R18)
  and `QuarantineNotificationBulkActionController.php` (`store`, `BulkResultResponse`); register `GET|PATCH
  /mailboxes/{mailbox}/quarantine-notification-settings` and `POST
  /quarantine-notification-settings/bulk-actions`. This makes T058 and T059 pass.

**Checkpoint**: US4 works on its own.

---

## Phase 7: User Story 5 — Tune spam filtering for a mailbox or a domain (Priority: P2)

**Goal**: Spam scores per mailbox and allow/block lists for domains, alias domains and mailboxes.

**Independent test**: Read and change scores and lists as each role with and without `spam_score`
and `spam_policy`; confirm the `filterconf` rows the settings map reads.

### Tests for User Story 5 (write first, observe failing) ⚠️

- [ ] T062 [P] [US5] Write `tests/Feature/SpamPolicy/SpamScoreTest.php` for `getMailboxSpamScore`,
  `setMailboxSpamScore` and `resetMailboxSpamScore` (FR-039 to FR-042, AS-5.1 to AS-5.4, edge
  cases): Rspamd `actions` with `add header` 8 and `reject` 15 → `8`, `15`, `custom: false`,
  `defaults.fallback: false`; Rspamd unreachable or `add header` missing or `0` → `5`, `15`,
  `fallback: true`; `PUT {6.5, 12}` → rows `lowspamlevel` `6.5` and `highspamlevel` `12` (inserted
  high first), `custom: true`; `{8, 8}` → reject `8.1`; `{0.3, 0.3}` → `0.4` exactly; `{12, 6}` →
  `422 mustNotExceed` `field: rejectScore`, rows unchanged; `5000.1` → `outOfRange`; `6.55` →
  `notMultipleOf` `factor: 0.1`; only one stored row → `custom: true` with the other from the
  defaults; `DELETE` → both rows removed, `204`, defaults again; resetting without rows → `204`,
  no audit; a mailbox user without `spam_score` → `403 permission-flag-required` `requiredFlags: ["spamScore"]`; a
  domain admin always may within scope; another domain's mailbox → `404`; a resource → `404`;
  `If-Match` mismatch → `412`; audit with old and new values.
- [ ] T063 [P] [US5] Write `tests/Feature/SpamPolicy/MailboxSpamPolicyEntryTest.php` for the mailbox
  list operations (FR-044 to FR-049, AS-5.5, AS-5.6, AS-5.9): `POST {"list":"block","pattern":"
  *@SPAM.example "}` → `201`, `Location` `/api/v2/mailboxes/alice@example.org/spam-policy-entries/<id>`,
  `ETag`, row `(alice@example.org, blacklist_from, *@spam.example)`; the same pattern on `allow` →
  `409 spam-policy-entry-exists` `list: block`, `entryId`; `user+tag@example.org` → `422
  invalidFormat` `senderPattern`; `*` → `invalidFormat` `senderPattern`; 101 characters → `tooLong`;
  the list includes the domain entry with `own: false`, owner kind `domain`; filters `list`,
  `ownership`, `q`, sorts `pattern` (default asc), `createdAt`, `id`; `DELETE` of the domain entry
  through the mailbox → `404`; `DELETE` own entry → `204`, row gone; `GET` of the domain entry
  through the mailbox → `404`; a mailbox user without `spam_policy` reads but gets `403
  permission-flag-required` on create and delete; two concurrent identical creations from two
  processes (separate database connections) → one `201`, one `409`, one row (`GET_LOCK`); a resource in the mailbox path → `404` (FR-069); a mailbox of another domain for a
  domain admin → `404`; audit with owner, list and pattern.
- [ ] T064 [P] [US5] Write `tests/Feature/SpamPolicy/DomainSpamPolicyEntryTest.php` for the domain
  list operations (FR-043, FR-045 to FR-048, AS-5.8): the list contains the domain's entries
  (`own: true`, kind `domain`) and the mailbox entries of the domain (`own: false`, kind
  `mailbox`); a domain admin with `spam_policy` adds and removes; without it → `403
  permission-flag-required` `requiredFlags: ["spamPolicy"]` (reads allowed); a mailbox user → `403 forbidden` for
  read and write; a domain outside the scope → `404`; an alias domain name in the domain path →
  `404`; the same pattern may exist for another owner.
- [ ] T065 [P] [US5] Write `tests/Feature/SpamPolicy/AliasDomainSpamPolicyEntryTest.php` (FR-043,
  AS-5.7): a domain admin with `spam_policy` adds an allow entry for an alias domain of their domain
  → row with `object` = the alias domain, kind `aliasDomain`; an alias domain of another domain →
  `404`; a mailbox user → `403 forbidden`; removal through the target domain's path → `404`.

### Implementation for User Story 5

- [ ] T066 [US5] Create `app/Services/SpamPolicy/SpamScoreService.php` (R10: rows, defaults via
  `RspamdController::actions()` with fallback, integer tenths, legacy insert order, mailbox row lock,
  `If-Match`, audit), `app/Rules/OneDecimal.php`, `app/Http/Requests/Api/V2/SetSpamScoreRequest.php`
  (`junkScore`/`rejectScore` numbers 0–5000, one decimal, junk ≤ reject → `mustNotExceed`),
  `app/Http/Resources/Api/V2/SpamScoreResource.php` and
  `app/Http/Controllers/Api/V2/SpamScoreController.php` (`show`, `update`, `destroy`); register
  `GET|PUT|DELETE /mailboxes/{mailbox}/spam-score`. This makes T062 pass.
- [ ] T067 [US5] Create `app/Services/SpamPolicy/SpamPolicyEntryService.php` (R11: views per owner
  kind, filters, search, sort, paging in SQL; create with `GET_LOCK('restv2:filterconf:<object>',
  10)` around the uniqueness check and the insert;
  remove by owner; ETag over `prefid|object|option|value`; audit), `app/Rules/SenderPattern.php`,
  `app/Http/Requests/Api/V2/ListSpamPolicyEntriesRequest.php` and `StoreSpamPolicyEntryRequest.php`,
  `app/Http/Resources/Api/V2/SpamPolicyEntryResource.php` and
  `app/Http/Controllers/Api/V2/SpamPolicyEntryController.php` (`index`, `store`, `show`, `destroy`,
  owner kind from the route defaults); register the three owner families of routes
  (`/domains/{domain}/…`, `/alias-domains/{aliasDomain}/…`, `/mailboxes/{mailbox}/…`). This makes
  T063 to T065 pass.

**Checkpoint**: US5 works on its own.

---

## Phase 8: User Story 6 — Limit how much a domain or mailbox may send (Priority: P2)

**Goal**: Read, list, set and remove rate limits of domains, alias domains and mailboxes; reset
buckets.

**Independent test**: Set, read, list and remove limits as each role with and without `ratelimit`;
reset existing and missing buckets; confirm `RL_VALUE` and `RL_LOG`.

### Tests for User Story 6 (write first, observe failing) ⚠️

- [ ] T068 [P] [US6] Write `tests/Feature/RateLimits/RateLimitStoreTest.php` on the real Redis
  (R12): parse `100 / 1h` → `{100, hour}`; `0 / 1h`, `-5 / 1m`, `10/1h`, `10 / 1w`, `abc` →
  invalid with the raw text; compare-and-set: with the current raw value → written; with a stale one
  → refused without change; absent expected and absent actual → written; `HDEL` of an absent field
  → no change reported; a closed Redis port → `RedisException` mapped to `temporaryFailure`.
- [ ] T069 [P] [US6] Write `tests/Feature/RateLimits/RateLimitTest.php` for the domain, alias domain
  and mailbox `get`/`set`/`remove` operations (FR-050, FR-052 to FR-056, AS-6.1 to AS-6.5): a
  domain admin with `ratelimit` sets `{100, hour}` on their domain → `RL_VALUE[example.org]` =
  `100 / 1h`; the domain name is normalised (`EXAMPLE.ORG` and the Unicode form write the ASCII
  lower-case field); a mailbox of the domain reports `limit: null`, `effectiveLimit {100, hour}`,
  `effectiveSource: domain`; `{10, minute}` on the mailbox → `effectiveSource: mailbox`; removing it
  → the domain limit again; `value` 0 and -1 → `outOfRange`; `frame: week` → `notInList`; a
  legacy-written invalid value is reported with `invalidStoredValue` and can be replaced and
  removed; removing an unset limit → `204`, no audit; a domain admin without `ratelimit` reads
  (`200`) but cannot set (`403 permission-flag-required` `requiredFlags: ["ratelimit"]`); a mailbox user → `403
  forbidden` for every operation; an alias domain limit is stored under the alias domain; a
  resource → `404`; `If-Match` mismatch → `412`, unchanged; Redis down → `503 sharedStore`; audit
  with old and new values.
- [ ] T070 [P] [US6] Write `tests/Feature/RateLimits/RateLimitListTest.php` for `listRateLimits`
  (FR-051, AS-6.7): with limits on 3 domains, 1 alias domain, 4 mailboxes, a resource and an orphan
  field → `kind=mailbox` returns exactly the 4 mailbox limits; no filter returns 8 sorted by
  object; `sort=kind`; `q`; a domain admin gets only their domains, alias domains and mailboxes;
  each entry has its `etag`; a mailbox user → `403 forbidden`.
- [ ] T071 [P] [US6] Write `tests/Feature/RateLimits/RateLimitBucketResetTest.php` for
  `resetRateLimitBucket` (FR-057, AS-6.6): a bucket hash `RLabc=` and three `RL_LOG` lines with
  `rl_hash` `RLabc=` plus two other lines (one containing `RLabc=` in another field) → `204`, the key
  gone, exactly the three lines removed; only log lines (expired bucket) → `204`; neither → `404`;
  `RLx` stored as a string key, not a hash → counters not deleted; `bucketId` `foo` → `422` (path
  pattern) or `404`; a domain admin → `403 forbidden`; an `LPUSH` concurrent with the reset is kept;
  Redis down → `503 temporary-failure` `sharedStore` (FR-061);
  audit with `countersExisted` and `logEntriesRemoved`.

### Implementation for User Story 6

- [ ] T072 [US6] Create `app/Support/Redis/LuaScripts.php` with the scripts `compareAndSetField`
  (`HGET` compare, then `HSET` or `HDEL`) and `resetRateLimitBucket` (R13: `TYPE`/`DEL`, `LRANGE`,
  `cjson.decode`, `LREM` on matching `rl_hash`, return counts). Extend 002's
  `app/Services/RateLimits/RateLimitStore.php` with `get`, `all`, `setIfMatches`,
  `removeIfMatches`, value parsing and formatting, running the scripts through 002's `SharedStore`
  (`SharedStoreUnavailable` → `temporaryFailure('sharedStore')`). This makes T068 pass.
- [ ] T073 [US6] Create `app/Services/RateLimits/RateLimitService.php` (scope and existence per kind,
  normalisation, mailbox effective limit, ETag, audit, list classification with one query per kind,
  filters, search, sort, paging) and `RateLimitBucketService.php`.
- [ ] T074 [US6] Create `app/Http/Requests/Api/V2/SetRateLimitRequest.php` and
  `ListRateLimitsRequest.php`, `app/Http/Resources/Api/V2/RateLimitResource.php`, and the
  controllers `app/Http/Controllers/Api/V2/RateLimitController.php` (`index`, `show`, `update`,
  `destroy`, object kind from route defaults) and `RateLimitBucketController.php` (`destroy`);
  register `GET /rate-limits`, `GET|PUT|DELETE` on `/domains/{domain}/rate-limit`,
  `/alias-domains/{aliasDomain}/rate-limit`, `/mailboxes/{mailbox}/rate-limit`, and `DELETE
  /rate-limit-buckets/{bucketId}`. This makes T069 to T071 pass.

**Checkpoint**: US6 works on its own.

---

## Phase 9: User Story 7 — See how the spam filter handled mail (Priority: P3)

**Goal**: Administrators read the action statistics with their counting period.

**Independent test**: Read the statistics as each role with Rspamd reachable and unreachable.

### Tests for User Story 7 (write first, observe failing) ⚠️

- [ ] T075 [P] [US7] Write `tests/Feature/SpamPolicy/SpamFilterStatisticsTest.php` for
  `getSpamFilterActionStatistics` (FR-058, AS-7.1 to AS-7.4): `stat` with `actions` `{reject: 10,
  "soft reject": 2, greylist: 3, "rewrite subject": 1, "add header": 4, "no action": 80}` and
  `uptime` 3600 → `rejected` 10, `softRejected` 5, `subjectRewritten` 1, `movedToJunk` 4,
  `noAction` 80, `total` 100, `countedSince: null`, `spamFilterRunningSince` = now − 1 h; a missing
  action key counts 0; a `start_time` member → `countedSince` set; Rspamd unreachable → `503
  temporary-failure` `spamFilter`; a domain admin and a mailbox user → `403 forbidden`; an admin
  read-only key → `200`; not audited.

### Implementation for User Story 7

- [ ] T076 [US7] Create `app/Services/SpamPolicy/SpamFilterStatisticsService.php` (R14) and
  `app/Http/Controllers/Api/V2/SpamFilterStatisticsController.php` (`show`); register `GET
  /spam-filter-action-statistics`. This makes T075 pass.

**Checkpoint**: US7 works on its own.

---

## Phase 10: User Story 8 — Trace quarantine and spam policy changes (Priority: P3)

**Goal**: Administrators filter the audit log by this feature's targets and see who changed what.

**Independent test**: Make each kind of change, including refused ones, and list the audit log
filtered by target.

### Tests for User Story 8 (write first, observe failing) ⚠️

- [ ] T077 [P] [US8] Write `tests/Feature/Quarantine/SpamFilterAuditTrailTest.php` (FR-063,
  FR-064, AS-8.1 to AS-8.3): after a release, `GET /audit-events?targetType=quarantineItem&
  targetId=<id>` returns one record with actor, time, client address, `queueId`, `recipient`,
  `sender` and the steps, and no subject, body, attachment or `qhash`; a template change is listed
  as `quarantineSettingsUpdate` with `changed`, `oldSize`, `newSize` and no template text; filters
  work for every target type of this feature (`quarantineSettings`, `quarantineNotificationSettings`,
  `spamScore`, `spamPolicyEntry`, `rateLimit`, `rateLimitBucket`); refusals (403 flag, 404, 409,
  412, 422, 503, a read-only key, a restricted session) are listed with `outcome: failure`,
  `failureReason` and `details.problemType`; CSRF failures and `401`s are not listed; a domain
  admin → `403 forbidden`.
- [ ] T078 [P] [US8] Write `tests/Feature/Quarantine/AuditContentScanTest.php` (SC-010): runs every
  state-changing operation of this feature once (single and bulk, success and refusal) with
  fixtures whose subjects, bodies, attachment names and `qhash` are known marker strings, plus a
  template containing a marker; asserts exactly one record per affected object and that no
  `restv2_audit_events` row and no captured `stderr_json` log line contains any marker.

### Implementation for User Story 8

- [ ] T079 [US8] Make T077 and T078 pass: complete the `details` whitelists of T023 where a test
  shows a gap, and check that every state-changing 006 route declares `restv2.audit`. The generic
  `targetType`/`targetId` filters are 002's (conventions "History": no feature adds its own audit
  filter); if T001 found them missing, implement exactly those generic filters in
  `app/Http/Requests/Api/V2/ListAuditEventsRequest.php` and `AuditEventController` (002 FR-058).

**Checkpoint**: All eight user stories work independently.

---

## Phase 11: Polish and cross-cutting concerns

- [ ] T080 [P] Write `tests/Feature/Security/SpamFilterAuthorizationMatrixTest.php` (constitution
  VI, quality gate 5, SC-005): a data provider over **all 42 operations** × anonymous, restricted
  session, admin session, domain-admin session with all flags, domain-admin session without
  `quarantine`/`spam_policy`/`ratelimit`, mailbox session with all flags, mailbox session without the
  six user flags, admin read-write key, admin read-only key, domain-admin read-write key and
  domain-admin read-only key; expected status and problem `type` from a table derived from FR-006
  and the contract; object-level cases: foreign item, foreign mailbox, foreign domain, foreign
  alias domain and foreign list entry → `404`, identical to unknown objects; every refusal of a
  state-changing operation by an authenticated caller writes exactly one failure audit record, and
  anonymous requests write none (conventions "Refusal auditing").
- [ ] T081 [P] Write `tests/Feature/Quarantine/Performance/QuarantineListLatencyTest.php` (group
  `performance`, SC-007, R16): seeds 100,000 rows (multi-row inserts, `msg` of 2 KiB, 50 mailboxes
  in 5 domains) and measures 20 requests each of the default page, a domain admin page, a `q`
  search and a `sort=score` page; p95 < 1 s.
- [ ] T082 [P] Write `tests/Feature/Quarantine/Performance/QuarantineBulkReleaseDurationTest.php`
  (group `performance`, SC-008): a bulk release of 100 items with the fake mailer and fake Rspamd
  delaying 100 ms per call completes within 60 s and reports 100 `done`.
- [ ] T083 [P] Extend 001's `tests/Unit/Support/ProblemTypeConsistencyTest.php` expectations, if
  needed, so that the four new `ProblemType` cases match `docs/problems.md` and `KnownProblemType`;
  add one sentence to the `temporary-failure` section of `docs/problems.md` pointing to the 006
  extension members `dependency` and `step` (documented in the `quarantine-item-busy` section).
- [ ] T084 Extend `database/seeders/DevelopmentSeeder.php` with the data of quickstart Part B
  (quarantine items from the T007 fixtures for alice and bob, a domain block entry, a domain rate
  limit, quarantine settings retention 20 and size 15 MiB).
- [ ] T085 Run `composer test` (contract coverage lists all 42 operations of 006),
  `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` and the 006 performance group; fix
  findings. Run quickstart Part B against the dev stack and record any deviation in
  `quickstart.md`.
- [ ] T086 Add the feature's contract changes to the release notes (`CHANGELOG.md`, section
  "Unreleased"): the 42 operations, the four problem types and the appended enum values, so that
  mailcowui-ng can bump its pinned contract.

---

## Dependencies and execution order

### Phase dependencies

- **Setup (Phase 1)**: needs 001–005 merged. T001 first; T002–T009 can then run in parallel except
  T003 after T002 (the image needs `mailparse` before `composer require`).
- **Foundational (Phase 2)**: needs Setup. T010–T017 in parallel; T018–T023 after their tests.
  Blocks every story.
- **US1 (Phase 3)**: needs Phase 2. **US2 (Phase 4)** extends US1's query and action service.
- **US3, US4, US5, US6, US7**: each needs only Phase 2 and can run in parallel with the others and
  with US1/US2 (different files; routes are appended to `routes/api.php` in a fixed order: literal
  `/quarantine/*` segments before `/quarantine/{itemId}`).
- **US8 (Phase 10)**: needs the stories whose changes it audits (US1–US7).
- **Polish (Phase 11)**: needs all stories.

### Within each story

- Tests first, observed failing; then services, requests, resources, controllers and routes; the
  story's checkpoint before the next priority when working sequentially.

### Parallel opportunities

- Setup: T005–T009. Foundational tests: T010–T017. US1 tests: T024–T032; US1 implementation T034
  and T035 next to T033. US2 tests: T042–T046. US3 tests: T051–T053. US5 tests: T062–T065. US6
  tests: T068–T071. Polish tests: T080–T083.
- With several developers after Phase 2: US1+US2, US3, US4, US5, US6+US7 in parallel.

## Parallel example: User Story 1

```text
# All US1 tests together (they fail until T033–T041):
T024 tests/Unit/Quarantine/QuarantineMessageParserTest.php
T025 tests/Unit/Quarantine/DownloadFileNameTest.php
T026 tests/Unit/Quarantine/ReleaseMessageFactoryTest.php
T027–T032 tests/Feature/Quarantine/{ListOwnQuarantine,InspectQuarantineItem,DownloadQuarantineFiles,
          ReleaseQuarantineItem,LearnQuarantineItemAsSpam,DeleteQuarantineItem}Test.php

# Then, in parallel:
T033 QuarantineMessageParser   T034 DownloadFileName   T035 ReleaseMessageFactory
# Then sequentially: T036 → T037 → T038 → T039 → T040 → T041
```

## Implementation strategy

### MVP first (User Story 1)

1. Phase 1 and Phase 2.
2. Phase 3 (US1): mailbox users review, inspect, download, release, learn and delete their items.
3. Validate with quickstart Part B (alice) and Part C items 1–2 on a mailcow host.

### Incremental delivery

1. US2: administrators and resellers at scale (bulk, filters, concurrency, capabilities).
2. US3 and US4: settings with template check and preview; notification choices.
3. US5 and US6: spam tuning and rate limits.
4. US7 and US8: statistics and the audit trail.
5. Polish: authorisation matrix, performance, release notes.

## Notes

- Never select `msg` in lists; never return or log `qhash`, `msg`, subjects in audit records or
  template text.
- Every state-changing route declares `->defaults('restv2.audit', …)` so that refusals of
  authenticated callers are audited (T023).
- Commit with explicit file names only, never directories or globs.
- Every upstream write goes through `app/Services/`; no migration in this feature.
- Commit after each task or logical group; keep the contract and the implementation in step.
