# Research: Quarantine, Spam Policy and Rate Limits

**Feature**: `specs/006-quarantine-spam-policy` | **Date**: 2026-10-18 | **Plan**: [plan.md](./plan.md)

Every open point of the plan is settled here, in the owner's precedence order: legacy behaviour
(constitution IV) first, then the constitution and `docs/conventions.md`, then best practice.
Shared technical decisions (stack, dev stack, schema fixture, prefixes, problem URIs, contract
validation, audit log, configuration reader) are feature 001's and are not repeated
([001 research](../001-auth-sessions-api-keys/research.md)). Legacy references are paths in
mailcow-dockerized `2026-09a` (`git -C ~/Projects/mailhosting show 2026-09a:<path>`). The FeldHost
fork is identical for every file this feature reads (spec, "Legacy Parity").

Each entry has a **Decision**, its **Rationale** and the **Alternatives considered**.

---

## 1. Integration with earlier features

### R1. Code this feature relies on (features are implemented in number order)

- **Decision**: 006 adds no shared infrastructure that an earlier feature already provides. It
  relies on:
  - **001**: `Principal`, `Role`, `PermissionFlag`, `IdentityService`/`Scope` (effective flags per
    legacy `acl('to_session')`: a domain administrator always holds the user-only flags
    `quarantineAttachments`, `quarantineNotification`, `quarantineCategory`, `spamScore`, which
    is exactly FR-005), `ProblemException`/`ProblemType`/`Problem`, `ETag`, `ApiFormRequest` with
    rule codes, `ListQuery`, `AuditLogger`, `MailcowConfig` (`DEFAULT_LANG`), the preferences
    language resolution, the Redis connections `mailcow` (database 0, no prefix) and `restv2`
    (prefix `restv2:`), `MailcowDateTime`, `MailcowRows`/`MailcowFactory`, `OpenApiContract`,
    the `api.v2` middleware pipeline (key scope, CSRF, pending actions) and
    `restv2.auth:authenticated`.
  - **002** (`specs/002-domains/plan.md`): `App\Support\Domain\DomainName` (trim, lower case,
    UTS #46 to ASCII; 002 FR-006), the `Domain` and `AliasDomain` models,
    `App\Services\Domains\DomainScope` (domain reachability), `App\Clients\SharedStore\SharedStore`
    and `SharedStoreUnavailable` (ping, `MULTI`/`EXEC` with compensation, for every Redis write),
    `App\Services\RateLimits\RateLimitStore` (the `RL_VALUE` writer, "extended by 006"), the
    `temporary-failure` problem type and the rule codes `notMultipleOf` and `mustNotExceed`, the
    generic audit filters `targetType`/`targetId` on `GET /audit-events` (002 FR-058, needed by
    FR-064), and the refusal auditing of 002 (or the route-declared `RefusalAuditor` hook described
    by `docs/conventions.md`, whichever the first implemented feature created). 006 makes no
    dockerapi call, so it does not use 002's `DockerApiClient`.
  - **003**: `App\Services\Mailboxes\MailboxScope` (mailbox reachability and `404` semantics,
    resources excluded) and the `Mailbox` model.
  - **004**: `App\Services\AliasDomains\AliasDomainQuery` (alias domains reachable through their
    target domain).
  - **009**: the `permission-flag-required` problem type (already in the contract).
  - Bulk actions: 002 and 003 implement them per feature (`DomainBulkActions`,
    `MailboxBulkActions`) with the constant limit of 100 (conventions); 006 does the same with
    `QuarantineBulkActions` and `QuarantineNotificationBulkActions`.

  The tasks name the expected classes. Setup task T001 checks that each exists; when a name
  differs, 006 uses the existing class, and it creates a component only if no earlier feature did
  (the conventions: "cleanup that more than one feature performs is implemented once and reused").
  In particular, rate limit storage has exactly one writer class: 006 extends the class that 002
  introduced for the initial rate limit instead of writing a second one.
- **Rationale**: The roadmap orders implementation by feature number, and the conventions forbid
  duplicate implementations of shared behaviour.
- **Alternatives considered**: Re-implementing scope checks inside 006 was rejected: two
  implementations of `hasDomainAccess` would drift.

---

## 2. Spam filter, mail server and template engine

### R2. Rspamd controller client

- **Decision**: `App\Clients\Rspamd\RspamdController` (interface) with the adapter
  `UnixSocketRspamdController`, built on Laravel's HTTP client (Guzzle) with
  `withOptions(['curl' => [CURLOPT_UNIX_SOCKET_PATH => config('restv2.rspamd.socket')]])` and the
  base URL `http://rspamd`, exactly as legacy calls it (`/var/lib/rspamd/rspamd.sock`).
  - **Calls** (all with a 30 s timeout, FR-062, `RESTV2_RSPAMD_TIMEOUT`):
    - `learnHam(string $message)` → `POST /learnham`, `Content-Type: text/plain`, body = stored
      message;
    - `learnSpam(string $message)` → `POST /learnspam`;
    - `fuzzyAdd(string $message, int $flag)` → `POST /fuzzyadd` with header `Flag: 13` (wanted) or
      `Flag: 11` (unwanted). Sent **once** (legacy sends it twice by mistake). The prepared but
      never executed legacy `fuzzydel` call is not made;
    - `actions()` → `GET /actions`, a list of `{action, value}`;
    - `stat()` → `GET /stat`.
  - **Result classification** (`App\Clients\Rspamd\LearnOutcome`): `done` (2xx without `error`);
    `alreadyLearned` (HTTP 208, or an `error` containing "already learned", case-insensitive, as
    legacy tests); `noFuzzyContent` (an `error` containing "No content to generate fuzzy");
    `failed` (any other `error`, any other status, a connection error, a timeout), with the
    filter's answer or the cURL error as text.
  - **No password**: the controller's Unix socket is trusted by Rspamd (mailcow's
    `override.d/worker-controller.inc` binds it with `mode=0666`); legacy sends no password either.
  - **Deployment**: the override mounts the named volume `rspamd-vol-1:/var/lib/rspamd:ro` into the
    API container. Connecting to a Unix socket needs write permission on the socket inode only
    (mode 0666), which a read-only mount does not remove (Linux applies `EROFS` to regular files,
    directories and links only), so the API cannot change Rspamd's data.
    `RESTV2_RSPAMD_SOCKET` (default `/var/lib/rspamd/rspamd.sock`) overrides the path.
  - **Tests**: `tests/Support/Rspamd/FakeRspamdController.php` records every call (endpoint, flag,
    SHA-256 of the body) and returns scripted outcomes; tests assert the exact call sequence. The
    adapter is tested with `Http::fake()`.
- **Rationale**: Same transport, endpoints and headers as legacy, so the filter sees identical
  requests (SC-002). Laravel's HTTP client needs no new package.
- **Alternatives considered**: The TCP controller port 11334 was rejected: it needs the controller
  password, which mailcow does not expose to other containers. Proxying through dockerapi was
  rejected: dockerapi has no Rspamd endpoint.

### R3. Release delivery through Postfix

- **Decision**: `App\Clients\Mail\QuarantineReleaseMailer` (interface) with the adapter
  `SmtpQuarantineReleaseMailer`, built on Symfony Mailer's `EsmtpTransport` (a dependency of
  `laravel/framework`).
  - **Target**: `RESTV2_RELEASE_SMTP_HOST` (default `postfix`, the network alias legacy resolves),
    port `RESTV2_RELEASE_SMTP_PORT` (default 590: mailcow's quarantine submission, which bypasses the
    milter and accepts `permit_mynetworks` only), no TLS (`auto_tls` off: legacy's raw dialogue
    sends in clear on the internal network), local domain `quarantine` (legacy `HELO quarantine`),
    timeout `RESTV2_RELEASE_SMTP_TIMEOUT` (30 s).
  - **`original` format** (`Q_RELEASE_FORMAT` = `raw`, empty or missing, FR-020): the stored
    message with legacy's exact substitution `preg_replace('/^X-Spam-Flag: (.*)/m',
    'X-Pre-Release-Spam-Flag: $1', $msg)`, then bare `LF` normalised to `CRLF` and sent as a
    `Symfony\Component\Mime\RawMessage`. The transport dot-stuffs lines that start with `.` (legacy
    does it by hand). Postfix stores the same message either way.
  - **`attachment` format**: a `Symfony\Component\Mime\Email` from the sender to the recipient,
    subject `sprintf(release_subject, qid)` and text body `release_body` from the legacy
    translations, with the stored message attached as `<qid>.eml` (`message/rfc822`). The strings
    of all 31 languages are extracted from the tag's `data/web/lang/lang.*.json` into
    `resources/legacy/quarantine-release-strings.json` by
    `scripts/legacy/extract-quarantine-release-strings.sh <tag>`. The language is the acting
    user's effective preference (001 FR-030), `DEFAULT_LANG` for API keys, else `en-gb`.
  - **Envelope**: sender = the stored sender when it is a valid address; otherwise (empty, or a
    non-address such as the column default `unknown`) `sender-unknown@rspamd`; recipient =
    `quarantine.rcpt`.
  - **Outcome classification** (`DeliveryOutcome`): accepted (`250` after `DATA`); `refused` (any
    `5xx` reply: `409 quarantine-delivery-refused` with `smtpCode` and the full reply);
    `temporaryFailure` (connection refused, DNS failure, timeout, any `4xx` reply:
    `503 temporary-failure`, `dependency: mailServer`, `step: delivery`).
  - **Tests**: `tests/Support/Mail/FakeQuarantineReleaseMailer.php` captures envelope and bytes.
    The adapter is tested against `tests/Support/Smtp/ScriptedSmtpServer.php`, a PHP process
    (started with `Symfony\Component\Process\Process`) that listens on a random local port,
    records the dialogue and answers scripted codes.
- **Rationale**: The same entry point (port 590, bypassing the filter) and the same message bytes as
  legacy. Symfony's transport handles multi-line replies, dot-stuffing and timeouts, which legacy's
  fixed 256-byte reads do not, and it reports the server's reply for FR-021.
- **Alternatives considered**: A hand-written SMTP dialogue like legacy was rejected: more code and
  the same weaknesses. PHPMailer (legacy, LGPL-2.1) was rejected: Symfony Mailer is already
  installed. Sending through dockerapi or `sendmail` in another container was rejected: no such
  endpoint, and Postfix's port 590 is the documented path.

### R4. Concurrency control for item actions (FR-026)

- **Decision**: An atomic Redis lock per item through Laravel's cache locks on the `restv2` store:
  `Cache::store('restv2')->lock("quarantine-item:{$id}", 300)` (key
  `restv2:quarantine-item:<id>`, owner token, `SET NX PX`, released with compare-and-delete).
  - `get()` is non-blocking. When it fails, the item is looked up: gone or out of scope → `404`;
    present → `409 quarantine-item-busy` with `Retry-After: 5`.
  - With the lock held, the row is re-read; a row deleted meanwhile (legacy UI, cleanup) → `404`.
  - The lock is released in `finally`. Its 300 s TTL exceeds the worst case (delivery 30 s,
    learning 30 s, fuzzy 30 s), so it never expires while its holder runs, and it expires if the
    PHP worker dies.
  - Redis unavailable → `503 temporary-failure` (`sharedStore`); nothing is done without the lock.
    A release needs Redis anyway for `Q_RELEASE_FORMAT`.
  - Bulk actions take the lock per item, sequentially.
- **Rationale**: Exactly one request delivers and learns (SC-006), without holding a database
  transaction or row lock across network calls: such a lock would block the ingestion's retention
  `DELETE` for the same mailbox (`pipe.php`) for up to a minute.
- **Alternatives considered**: `SELECT … FOR UPDATE` across the SMTP and Rspamd calls was
  rejected (above). MariaDB `GET_LOCK()` (which the conventions prescribe for uniqueness checks,
  R11) was rejected here: the item lock is not a uniqueness check, and it must survive up to 90 s of
  network waits during which a dropped and re-established database connection would silently
  release a session-bound lock; the Redis lock has an explicit owner and TTL. Deleting the row first and re-inserting it on failure was rejected: the item
  would vanish from both UIs while processing, and the re-insert would need explicit IDs.

### R5. Quarantine message parsing for inspection and downloads

- **Decision**: `App\Services\Quarantine\QuarantineMessageParser` wraps
  `php-mime-mail-parser/php-mime-mail-parser` (MIT, legacy's parser, on PECL `mailparse`) and
  `soundasleep/html2text` (MIT, legacy's converter). It ports `qitem_details.php`:
  - `setText($msg)`; recipients from `getAddresses('to'|'cc'|'bcc')`, kept only when
    `FILTER_VALIDATE_EMAIL` accepts them, then `{address: rcpt, source: envelope}`;
  - `from` = `getHeader('from')`, `subject` = `mb_convert_encoding(getHeader('subject'), 'UTF-8',
    'auto')` (legacy), empty strings instead of legacy's `-`;
  - `textPlain` = `getMessageBody('text')`, `null` when empty; `textFromHtml` =
    `Html2Text::convert(getMessageBody('html'))`; when both are empty, `textFromHtml` = the raw body
    after the first blank line converted to text, `textPlain` = `null`, `parserFallback` = `true`;
  - every text passes `mb_scrub()` so that invalid UTF-8 never breaks JSON (legacy blanks the text);
  - attachments from `getAttachments(true)` (inline parts included, as legacy), in order:
    `position` = index, `contentType` = `getContentType()`, `size` = decoded length, `sha256` of the
    decoded content, `fileName` = `getFilename()` with `/`, `\`, NUL and control characters removed
    and trimmed, or `attachment-<position>` when empty. Content is read in memory with
    `getContent()`; nothing is written to disk (read-only root filesystem; legacy writes to `/tmp`).
  - Parsing runs only when `LENGTH(msg) <= RESTV2_QUARANTINE_CONTENT_LIMIT` (10485760, FR-014);
    larger items report `contentStatus: tooLarge`, and downloads answer
    `409 quarantine-message-too-large`.
  - **Versions**: the current major releases that support PHP 8.4 are pinned in `composer.lock`
    (legacy runs mime-mail-parser 7.0.0 and html2text 0.5.0 on PHP 8.2). The parity of the output
    is proven against goldens produced by the legacy code (R22), and any difference caused by the
    newer versions is listed in the spec's parity table before release.
- **Rationale**: Same library and same algorithm as the legacy detail view, so content and
  attachments equal legacy (SC-001).
- **Alternatives considered**: `zbateson/mail-mime-parser` (BSD-2, pure PHP) was rejected: a
  different MIME implementation would change part selection and attachment lists against legacy.
  Writing attachments to `/tmp` like legacy was rejected (read-only filesystem, leftover files).

### R6. Downloads

- **Decision**:
  - Whole message: `Content-Type: message/rfc822`, body = stored message unchanged. File name =
    legacy's subject derivation: tags stripped (`strip_tags`, as `FILTER_SANITIZE_STRING` did),
    quotes, path separators and control characters removed, cut to 30 characters (`mb_substr`),
    trimmed; the queue identifier when that leaves nothing; plus `.eml`.
  - Attachment: always `Content-Type: application/octet-stream`; file name per R5, not shortened
    (legacy cuts to 20 characters, spec FR-016).
  - Both: `Content-Disposition: attachment; filename="<ASCII fallback>"; filename*=UTF-8''<RFC
    8187>` (Symfony `HeaderUtils::makeDisposition`), `X-Content-Type-Options: nosniff`,
    `Content-Security-Policy: default-src 'none'; sandbox`, `Cache-Control: private, no-store`,
    `Content-Length`. Read-only keys may download (GET). Not audited (conventions: reads are not
    audited except 010/011's listed views).
- **Rationale**: FR-016 to FR-018; nothing can render or run in the API's origin.

### R7. Notification template validation and preview (FR-070, FR-071)

- **Decision**: The template is checked and rendered by the legacy notifier's own engine: Python 3
  with Jinja2's `SandboxedEnvironment` and `html2text`, installed in the API image (`apk add python3
  py3-jinja2 py3-html2text`, the same Alpine packages as mailcow's Dovecot image, which runs
  `quarantine_notify.py`).
  - `resources/bin/quarantine-template.py` reads one JSON document from stdin (`template`,
    `datasets`) and writes one JSON document to stdout. For each dataset it calls
    `SandboxedEnvironment().from_string(template)` and `render(meta=…, username=…, counter=…,
    hostname=…, quarantine_acl=…)`, exactly the notifier's call, then `html2text.html2text(html)`.
    It reports `{ok: true, renders: [{html, text}]}` or `{ok: false, reason, line, message}`:
    `syntax` (`TemplateSyntaxError`, with `lineno`), `security` (`SecurityError`), `render` (any
    other exception, including `UndefinedError`, `OverflowError` from the sandbox's range limit and
    `ZeroDivisionError`: the notifier catches only `SecurityError` and `TemplateError` and would
    crash on the others).
  - `App\Services\Quarantine\QuarantineTemplateRenderer` runs it with
    `Symfony\Component\Process\Process(['python3', '-I', '-B', <script>])`, a timeout of
    `RESTV2_TEMPLATE_RENDER_TIMEOUT` (5 s, then `reason: timeout`), an output cap of 8 MiB, and an
    empty environment. `-I` isolates from environment variables and the user site, `-B` writes no
    bytecode (read-only filesystem).
  - **Validation datasets** (FR-070): A = one rejected item, `counter` 1, `quarantine_acl` 0;
    B = three items (actions `reject`, `add header`, `rewrite subject`), `counter` 3,
    `quarantine_acl` 1. Fixed values: `username` `user@example.org`, `hostname` =
    `MAILCOW_HOSTNAME`, `qhash` = 64 zeros, ids 1–3, subjects "Sample message 1–3", scores 7.5,
    16.2, 9.1, senders `sender1@example.net`…, `created` as the notifier receives it from MySQLdb
    (a `datetime`, rendered as `2026-01-01 08:00:00`). The preview uses dataset B, with
    `quarantine_acl` from the request.
  - **Errors**: a failed check answers `422` with `invalidTemplate` on `notificationTemplate`
    (settings) or `template` (preview); the renderer failing to start (no Python) is `500`.
  - **Subject of the preview**: `Q_SUBJ` or "Spam Quarantine Notification".
- **Rationale**: Only the notifier's own engine and sandbox can say whether the notifier can use a
  template (SC-011). Jinja2 (BSD-3-Clause), MarkupSafe (BSD-3-Clause) and html2text (GPL-3.0) are
  GPL-3.0-compatible. The renderer runs as the unprivileged API user, without network access needs,
  inside the sandbox, bounded in time and output.
- **Alternatives considered**: Twig was rejected: its syntax and sandbox differ from Jinja2, so it
  would accept templates the notifier rejects and vice versa. No maintained PHP port of Jinja2
  exists. Running the check in the Dovecot container through dockerapi was rejected: dockerapi
  offers no such command, and constitution V forbids patching other containers. Checking syntax
  only was rejected: the orchestrator decision asks for the render check, and the notifier crashes
  on render errors.

---

## 3. Storage and behaviour

### R8. Quarantine settings in Redis (FR-029 to FR-034, FR-061)

- **Decision**: `App\Services\Quarantine\QuarantineSettingsService` on the `mailcow` connection.
  - **Read**: one `MGET` of the eleven keys. Conversions: `Q_RETENTION_SIZE` and `Q_MAX_SIZE` →
    `intval` (`maxMessageSize` = MiB × 1048576); `Q_MAX_SCORE` empty or non-numeric → `null`;
    `Q_MAX_AGE` empty or ≤ 0 → 365; `Q_EXCLUDE_DOMAINS` JSON list (invalid → `[]`), sorted;
    `Q_RELEASE_FORMAT` `attachment` → `attachment`, anything else → `original`; `Q_SENDER` empty →
    `quarantine@localhost` (`…IsDefault`); `Q_SUBJ` empty → the default subject; `Q_BCC`,
    `Q_REDIRECT` empty → `null`; `Q_HTML` empty → the default template file
    (`RESTV2_QUARANTINE_DEFAULT_TEMPLATE`, default `/mailcow/assets/templates/quarantine.tpl`,
    mounted read-only from `./data/assets/templates`; when unreadable, the bundled copy of the
    `2026-09a` file in `resources/legacy/quarantine.tpl`, with a warning logged). Legacy returns the
    template HTML-escaped for its textarea; the API returns it raw.
  - **Write**: only the keys whose stored value changes, in legacy's formats (`Q_MAX_SIZE` in MiB,
    `Q_EXCLUDE_DOMAINS` as `json_encode` of a list, empty strings for defaults, `raw` for
    `original`), through 002's `SharedStore`: `WATCH` on the eleven keys, re-read, compare
    `If-Match`, then `MULTI`/`SET…`/`EXEC` (T001 adds a `watch()` variant to `SharedStore` if it has
    none). A failed `EXEC` (concurrent change) is retried once, then answered `412` when `If-Match`
    was sent, otherwise applied on the fresh values. `SharedStoreUnavailable` → `503
    temporary-failure` (`sharedStore`), nothing written (FR-061).
  - **Validation** (FR-030) is in `UpdateQuarantineSettingsRequest`; the excluded domains are
    normalised with 002's normaliser and looked up in `domain` and `alias_domain`; a stored entry
    that no longer exists may be sent back unchanged.
- **Rationale**: Same keys and formats, so `pipe.php`, `quarantine_notify.py` and `clean_q_aged.sh`
  keep working (FR-033, FR-068). Writing only changed keys avoids materialising defaults.
- **Alternatives considered**: Writing all eleven keys like legacy was rejected: it would turn
  defaults into stored values and change the version tag for no change.

### R9. Mailbox notification settings (FR-035 to FR-038)

- **Decision**: `App\Services\Quarantine\QuarantineNotificationService`. Read
  `JSON_VALUE(attributes, '$.quarantine_notification')` and `'$.quarantine_category'`; values
  outside the enums read as `never` and `null`. Write in a transaction: `SELECT … FOR UPDATE` on the
  mailbox row (`kind` not a resource), compare `If-Match`, then legacy's exact statement
  `UPDATE mailbox SET attributes = JSON_SET(attributes, '$.quarantine_notification', ?)` (and/or
  `'$.quarantine_category'`), mapping `junk` → `add_header`, `rejected` → `reject`, then the audit
  record, then commit. Nothing else (no `Q_LAST_NOTIFIED`, no `notified` reset, FR-038).
- **Rationale**: Legacy storage and values, so the notifier reads the same JSON.

### R10. Spam scores (FR-039 to FR-042)

- **Decision**: `App\Services\SpamPolicy\SpamScoreService`.
  - **Read**: `SELECT option, value FROM filterconf WHERE object = ? AND option IN
    ('lowspamlevel','highspamlevel')`; defaults from `RspamdController::actions()`: `add header` →
    junk, `reject` → reject; a missing, `null` or `0` value (legacy `empty()`), or any controller
    failure, gives 5 and 15 with `fallback: true`. Custom = at least one row; a missing row takes
    the default.
  - **Write**: in a transaction, lock the mailbox row, compare `If-Match`, `DELETE` both rows, then
    `INSERT highspamlevel`, `INSERT lowspamlevel` (legacy order), audit, commit. Values are handled
    as integer tenths: equal → reject + 1 (0.1), stored as the shortest decimal (`6.5`, `12`).
  - **Reset**: lock, `DELETE` both rows, audit when rows existed, commit.
- **Rationale**: The settings map (`settings.php`) reads exactly these rows. Integer tenths avoid
  float artefacts such as `8.100000000000001`.

### R11. Allow and block lists (FR-043 to FR-049)

- **Decision**: `App\Services\SpamPolicy\SpamPolicyEntryService` on `filterconf` (`option`
  `whitelist_from`/`blacklist_from`).
  - **Views**: domain: `object = :domain` (own) `OR object LIKE '%@' || :domain` (mailbox entries,
    read-only, legacy query); alias domain: `object = :alias` (own) plus the same `LIKE` (normally
    empty); mailbox: `object = :username` (own) `OR object = :mailboxDomain` (inherited). Filters,
    search (`value LIKE`), sorting and paging are applied in SQL over the union.
  - **Create**: normalise (`trim`, `mb_strtolower`), validate (`/^[a-z0-9@_.*-]+$/`, at least one
    `[a-z0-9]`, length ≤ 100), then take the MariaDB lock `GET_LOCK('restv2:filterconf:<object>',
    10)` (the conventions' rule for uniqueness that upstream enforces only in code; a timeout →
    `503 temporary-failure`), and in a transaction check `SELECT prefid, option FROM filterconf
    WHERE object = ? AND value = ? AND option IN (…)` → `409 spam-policy-entry-exists`, `INSERT
    (object, option, value)` (`created` by default), audit, commit, then `RELEASE_LOCK`. The lock
    serialises concurrent identical additions, which legacy does not protect; no index is added to
    `filterconf`.
  - **Remove**: `DELETE FROM filterconf WHERE prefid = ? AND object = ? AND option IN (…)`; zero
    rows → `404`.
- **Rationale**: Legacy's rows, so the settings map applies them within its 30 s refresh (SC-004).

### R12. Rate limits (FR-050 to FR-056)

- **Decision**: 002's `App\Services\RateLimits\RateLimitStore` (R1) gains the operations 006
  needs; no second writer of `RL_VALUE` exists:
  - **Format**: value `"<n> / 1<s|m|h|d>"`; parsing `^([1-9][0-9]{0,9}) / 1([smhd])$` with
    `n ≤ 2147483647`; anything else is `invalidStoredValue`.
  - **Field**: the normalised object (lower case, ASCII domain), which `DYN_RL_CHECK` looks up
    (`uname:lower()`, `envfrom.domain:lower()`).
  - **Set/remove with `If-Match`**: one Lua script per write that compares the current field value
    (or its absence) with the value the tag was computed from and then runs `HSET` or `HDEL`
    atomically; a mismatch → `412`. Without `If-Match`, plain `HSET`/`HDEL`. Removing an absent
    field changes nothing and writes no audit record.
  - **List**: `HGETALL RL_VALUE`, each field classified as an existing mailbox (`kind` not a
    resource), domain or alias domain in the caller's scope, in one query per kind; fields that are
    none of these are left out (legacy lists only existing objects). Filtering, search, sorting and
    paging in PHP (the hash is small).
  - **Mailbox effective limit**: own value, else the mailbox domain's value (legacy
    `mailbox_details`: `rl_scope`).
  - Every Redis failure (`SharedStoreUnavailable`) → `503 temporary-failure` (`sharedStore`)
    (FR-061).
- **Rationale**: One writer for `RL_VALUE` across 002–006 (conventions); atomic compare-and-set
  without `WATCH` on the whole hash, which every unrelated write would abort.

### R13. Rate-limit bucket reset (FR-057)

- **Decision**: One Lua script on the `mailcow` connection, atomic against `pipe_rl.php`'s `LPUSH`:
  it checks `TYPE <bucketId> == hash` and deletes the key, then walks `LRANGE RL_LOG 0 -1`,
  decodes each line with `cjson.decode` (skipping undecodable lines) and `LREM`s every line whose
  `rl_hash` equals the identifier. It returns whether counters existed and how many lines were
  removed. Both zero → `404`. The identifier must match `^RL[0-9A-Za-z=]+$` (legacy pattern).
- **Rationale**: Matching the decoded `rl_hash` instead of legacy's case-insensitive regex over the
  whole line removes only the bucket's own entries (spec deviation already stated). A script avoids
  a race with entries pushed during the reset.

### R14. Spam filter action statistics (FR-058)

- **Decision**: `RspamdController::stat()`. Counts: `reject` → `rejected`; `soft reject` +
  `greylist` → `softRejected`; `rewrite subject` → `subjectRewritten`; `add header` →
  `movedToJunk`; `no action` → `noAction`; missing keys count 0; `total` = the sum.
  `countedSince` = a numeric `start_time` member of `/stat` if the filter ever reports one
  (Rspamd 4.x does not: its `/stat` has `uptime` only, and its counters are restored from its stats
  file after restarts), else `null`. `spamFilterRunningSince` = now − `uptime` (seconds, UTC), or
  `null` when `uptime` is missing. A controller failure → `503 temporary-failure` (`spamFilter`).
- **Rationale**: The orchestrator decision: report the period when known, "unknown" otherwise. The
  uptime is reported with the note that counters may predate it (FR-058).

---

## 4. Access, lists and versions

### R15. Authorisation order and scope

- **Decision**: For every operation: (1) role allowed at all, else `403 forbidden`; (2) flags
  (FR-005/FR-006, effective flags from 001), else `403 permission-flag-required` with `flag`, the
  legacy column name of the first missing flag (`quarantine` before `quarantine_attachments`), or,
  for a mailbox user's notification change, `403 attribute-not-permitted` with `attributes` and
  `requiredFlags` for the members that would change (conventions "Permission refusals"); (3) object
  in scope, else `404`; (4) `If-Match`; (5)
  validation; (6) the action. The flag check precedes the lookup, so a refusal never reveals
  whether an object exists (same order as 009). Policies:
  `app/Policies/QuarantinePolicy.php`, `SpamPolicyPolicy.php`, `RateLimitPolicy.php`.
  - **Items**: administrator: all; domain administrator:
    `SUBSTRING_INDEX(rcpt, '@', -1) IN (<managed domains>)` (the recipient domain, so items of
    deleted mailboxes stay visible, spec edge case); mailbox user: `rcpt = <username>`. The same
    predicate is applied to lists and to every single-item request (FR-001).
  - **Mailboxes** (notifications, spam score, lists, rate limit): `mailbox.kind NOT REGEXP
    'location|thing|group'` (FR-069) and the 003 reachability rule.
  - **Domains and alias domains**: 002 and 004 reachability rules (alias domains through their
    target domain).
- **Rationale**: FR-001 to FR-006, constitution VI, conventions "Scope and errors".

### R16. Quarantine list query and performance (FR-008 to FR-011, SC-007)

- **Decision**: One `SELECT` and one `COUNT(*)` with the same predicates over `quarantine`, never
  selecting `msg` (except `LENGTH(msg)` in the single-item read). Columns: `id, qid, subject,
  score, sender, rcpt, action, created, notified, IFNULL(LOCATE('VIRUS_FOUND', symbols), 0) > 0 AS
  virus`. Filters: `rcpt = ?`; `SUBSTRING_INDEX(rcpt,'@',-1) = ?`; `action IN (…)` (`other` =
  `NOT IN ('reject','add header','rewrite subject')`); virus; `notified = ?`; `created >= ?` and
  `< ?` (UTC converted to `TZ`); `score >= ?`/`<= ?`. `q`: escaped `LIKE` over `subject`, `sender`,
  `rcpt`, `qid` (case-insensitive collation). Sort map: `arrivedAt` → `created`, `queueId` → `qid`,
  `recipient` → `rcpt`, `virus` → the computed flag; always `, id DESC` as tie-breaker. No index
  can be added to the upstream table (constitution III): a full scan of 100,000 rows without the
  `LONGTEXT` column (stored off-page) plus a filesort of the narrow rows takes well under a second.
  `tests/Feature/Quarantine/Performance/QuarantineListLatencyTest.php` (group `performance`) seeds
  100,000 rows and asserts p95 < 1 s for a page of 25.
- **Rationale**: Legacy loads every row into PHP; the API pages in SQL.

### R17. Version tags (FR-059, FR-060)

- **Decision**: 001's `ETag` (strong, base64url SHA-256 of a canonical string, quoted).
  - Quarantine settings: the eleven raw Redis values in fixed order, `null` distinct from `""`.
  - Notification settings: username + the two raw JSON values.
  - Spam score: username + raw `lowspamlevel` and `highspamlevel` values (absent distinct).
  - Rate limit: object + raw `RL_VALUE` field (absent distinct).
  - Quarantine item: `id|qid|rcpt|created` (never `notified`, which is runtime status).
  - List entry: `prefid|object|option|value`.
  The same value is the `ETag` header of a single read and `etag` of every representation and list
  entry. Bulk targets compare `version` (without quotes). No tag covers a stored secret: the item's
  `qhash`, the only secret this feature touches, is deliberately left out, so the conventions' HMAC
  rule for secret-bearing tags does not apply.
- **Rationale**: Conventions "Version tags": stored settings only; immutable objects keep a stable
  tag.

### R18. Bulk actions (FR-027, FR-037)

- **Decision**: `App\Services\Quarantine\QuarantineBulkActions` and
  `QuarantineNotificationBulkActions`, built like 002's `DomainBulkActions`: role/flag/key-scope
  check for the whole request; at most 100 targets, the API-wide constant (`422 tooMany`,
  `max: 100`); deduplication by normalised identifier (first occurrence kept); per target:
  authorise, version check (`outdated`), process, audit with `bulkRequestId`; outcome mapping
  `NotFound` → `notFound`; `quarantine-item-busy` and `quarantine-delivery-refused` → `refused`;
  `412` → `outdated`; `temporary-failure` and anything unexpected → `failed`. The quarantine bulk
  response adds the per-item `report` (`QuarantineBulkResult`, Complexity Tracking).
  - **Duration** (SC-008): 100 releases × (SMTP + 2 Rspamd calls) take about 30–60 s when the
    services respond normally. The deployment raises `fastcgi_read_timeout` for `/api/v2/` to 300 s
    and php-fpm's `request_terminate_timeout` to 300 s (001's snippet and pool, extended by task
    T005).
- **Rationale**: Same semantics as every other feature's bulk actions (conventions).

### R19. Audit (FR-063, FR-064)

- **Decision**: 001's `AuditLogger` with the event types and target types appended to the contract
  (`quarantineItemRelease`, …; `quarantineItem`, …). `details` whitelists per event: item actions
  `queueId`, `recipient`, `sender`, `action`, `learnAsHam`, `steps` (delivery, learning, fuzzy),
  `bulkRequestId`; settings `changes` `{field: {old, new}}`, the template as
  `{changed: true, oldSize, newSize}`; notification settings and spam score `changes`; list entries
  `owner`, `ownerKind`, `list`, `pattern`; rate limits `object`, `kind`, `old`, `new`; bucket
  reset `bucketId`, `countersExisted`, `logEntriesRemoved`. Never a subject, body, attachment or
  `qhash` (SC-010 scan test).
  - **Refusals** (conventions "Refusal auditing"): every state-changing route of this feature
    declares `->defaults('restv2.audit', ['event' => …, 'target' => …])`. The `RefusalAuditor` hook
    in 001's problem rendering (created by the first implemented feature that needed it; 006
    creates it if 002 did not) writes one failure record for every `4xx` problem of such a route
    when the request has an authenticated principal, except `csrf-token-mismatch`. This covers
    read-only keys, restricted sessions, missing flags, objects outside the scope, `409`, `412` and
    `422`; `401`s and CSRF failures are not recorded. 006 registers target resolvers for its seven
    target types (for `quarantineItem` the queue identifier and recipient are looked up regardless of
    scope, since the audit log is for administrators only; never the subject). Per-item refusals of
    bulk actions are recorded by the bulk services. `failureReason` uses the shared values
    (`permissionDenied` for role, flag, key scope and restricted session; `attributeNotPermitted`;
    `notFound`;
    `versionOutdated`; `validationFailed` with field names only; `conflict` for a busy item or an
    existing entry; `serviceFailure` for a delivery the mail server refused; `temporaryFailure`),
    with `details.problemType`. `503` refusals are recorded by the services. Validate-only requests
    and previews write nothing.
  - **Atomicity**: database writes and their record share one transaction. Redis writes: begin
    transaction → insert record → `EXEC` → commit; a failed `EXEC` rolls the record back.
  - **Releases**: delivery → item `DELETE` (own transaction, FR-021) → learning → record. If the
    record cannot be written then, the delivery cannot be undone: the answer is `500` and the full
    record is logged at `error` level (spec edge case). Learning as spam: learning → fuzzy →
    transaction { `DELETE`, record }, so a failed record keeps the item (a retry is then "already
    learned"). Deletion: transaction { `DELETE`, record }.
- **Rationale**: Constitution VI and FR-063 with the strongest guarantee each operation allows.

### R20. Configuration (server settings)

| Variable | Default | Meaning |
|---|---|---|
| `RESTV2_RSPAMD_SOCKET` | `/var/lib/rspamd/rspamd.sock` | Rspamd controller socket (R2) |
| `RESTV2_RSPAMD_TIMEOUT` | `30` | Seconds per controller request (FR-062) |
| `RESTV2_RELEASE_SMTP_HOST` | `postfix` | Postfix host for releases (R3) |
| `RESTV2_RELEASE_SMTP_PORT` | `590` | Quarantine submission port |
| `RESTV2_RELEASE_SMTP_TIMEOUT` | `30` | Seconds per SMTP step |
| `RESTV2_QUARANTINE_CONTENT_LIMIT` | `10485760` | Content size limit in bytes (FR-014) |
| `RESTV2_QUARANTINE_DEFAULT_TEMPLATE` | `/mailcow/assets/templates/quarantine.tpl` | Default notification template |
| `RESTV2_TEMPLATE_RENDER_TIMEOUT` | `5` | Seconds for a template check or preview (FR-070) |
| `SKIP_CLAMD` (`mailcow.conf`) | `n` | `virusScanning` in the capabilities (FR-072) |

The override adds the volumes `rspamd-vol-1:/var/lib/rspamd:ro` and
`./data/assets/templates:/mailcow/assets/templates:ro,z`, and passes `SKIP_CLAMD`.

### R21. Status codes and problem types (FR-065)

- **Decision**: New types (appended to `KnownProblemType` and `docs/problems.md`):
  `quarantine-item-busy` (409, `Retry-After: 5`), `quarantine-delivery-refused` (409, `smtpCode`,
  `smtpResponse`), `quarantine-message-too-large` (409, `size`, `limit`),
  `spam-policy-entry-exists` (409, `list`, `entryId`). Reused: `permission-flag-required`
  (`flag`), `attribute-not-permitted` (002; `attributes`, plus `requiredFlags` for the notification
  settings), `temporary-failure` (002, with the 006 extensions `dependency` and `step`),
  `forbidden`, `validation-failed`, `412`/`404` `about:blank`. `409` is chosen for the item states
  (busy, too large, delivery refused) because the request is valid but the item's current state
  does not permit it.
- **Alternatives considered**: `413` for a too-large message was rejected (it describes request
  bodies); `502` for a refused delivery was rejected (the mail server answered validly).

### R22. Test strategy specifics

- **Decision**:
  - Fakes bound in `tests/TestCase.php` for 006 tests: `FakeRspamdController`,
    `FakeQuarantineReleaseMailer`. Redis and MariaDB are real.
  - **Parser goldens**: `scripts/legacy/generate-qitem-goldens.sh 2026-09a` runs legacy
    `qitem_details.php`'s parsing code in the tag's `ghcr.io/mailcow/phpfpm:8.2.29-3` image over
    `tests/fixtures/quarantine/messages/*.eml` and writes `tests/fixtures/quarantine/legacy/*.json`.
    `QuarantineMessageParserTest` compares the API's parsed content with them, modulo the stated
    deviations (empty strings instead of `-`, `null` text, safe names, no VirusTotal URL).
  - **Template goldens** (SC-011): `scripts/legacy/generate-template-goldens.sh 2026-09a` renders
    `tests/fixtures/quarantine/templates/*.tpl` with the notifier's code in the tag's Dovecot image
    and records parse/render outcomes and output; `QuarantineTemplateRendererTest` compares.
  - The Python renderer runs for real in the dev image (it is local computation, not an external
    service).
  - Fixture messages: plain text, HTML only, multipart with two attachments, an HTML attachment, an
    SVG attachment, an attachment named `../../etc/passwd`, an unnamed attachment, encoded-word
    subjects, an unparseable body, `X-Spam-Flag` header and body lines, lines starting with `.`,
    LF-only line endings; a >10 MiB message is generated at runtime.
- **Rationale**: Constitution VII: real database and Redis, faked outbound services, parity
  asserted against legacy output.

### R23. Dependencies (constitution VIII)

| Package | Licence | Scope | Justification |
|---|---|---|---|
| `php-mime-mail-parser/php-mime-mail-parser` | MIT | runtime | Legacy's MIME parser; identical content and attachment lists (R5) |
| PECL `mailparse` (PHP extension) | PHP-3.01 | runtime image | Required by the parser; a platform extension like phpredis (001), part of upstream's php-fpm image |
| `soundasleep/html2text` | MIT | runtime | Legacy's HTML-to-text converter (R5) |
| Alpine `python3`, `py3-jinja2`, `py3-markupsafe`, `py3-html2text` | PSF-2.0, BSD-3-Clause, BSD-3-Clause, GPL-3.0 | runtime image | The notifier's engine and sandbox, the only exact template check (R7) |

Without a new package: the Rspamd client (Laravel HTTP client), SMTP delivery and MIME building
(Symfony Mailer and Mime, already Laravel dependencies), locks (Laravel cache locks), Lua scripts
(phpredis `eval`), the scripted SMTP server in tests (PHP sockets).
