# Research: Account Self-Service

**Feature**: `specs/005-account-self-service` | **Date**: 2026-10-18 | **Plan**: [plan.md](./plan.md)

This document records only the decisions that feature 005 adds. Every shared technical decision
(stack, Docker dev stack, schema fixture, `restv2_` and `restv2:` prefixes, sessions, CSRF, the
request pipeline, problem URIs, contract validation, WebAuthn, TOTP and Yubico libraries,
`MailcowConfig`, audit log) is taken from feature 001 and referenced as **001-R<n>**
(`specs/001-auth-sessions-api-keys/research.md`). Decisions follow the owner's precedence: legacy
behaviour first (constitution IV), then the constitution and `docs/conventions.md`, then best
practice. Each entry has a **Decision**, its **Rationale** and the **Alternatives considered**.

Legacy references are paths in upstream mailcow-dockerized `2026-09a`
(`git -C ~/Projects/mailhosting show 2026-09a:<path>`). The FeldHost fork differs, for this
feature, only in `OTP_LABEL`, the `WEBUI` recent-login label and display names (spec, "Legacy
Parity").

---

## 1. Dependencies and shared building blocks

### R1. Code this feature reuses from features 001 to 004

Features are implemented in order (001 → 004, then 005). This feature reuses, and where noted
extends, the following. The class names are those fixed by the earlier plans; when an earlier
feature did not deliver a piece, the 005 Foundational phase creates it at the named path.

| Capability | Owner | Class (planned) | 005 use |
|---|---|---|---|
| Principal, roles, effective permission flags, scope | 001 (R20) | `App\Auth\Principal`, `App\Auth\PermissionFlag`, `App\Services\Identity\IdentityService`, `Scope` | Flag checks of FR-006, own-account functions |
| Sessions, renewal, sign-out | 001 (R6) | `App\Services\Auth\SessionManager` | Extended with the session registry (R2) and a stable session handle |
| Route modes `restv2.auth:*`, `restv2.csrf`, `restv2.pending:*`, key-scope check | 001 (R8) | `RequireAuth`, `VerifyCsrfToken`, `EnforcePendingActions`, `EnforceKeyScope` | Extended with the pending mode `includes` and a read-only POST marker (R5) |
| Pending actions | 001 (R16) | `App\Services\Auth\PendingActionEvaluator` | Re-evaluated per request, so a resolved forced action lifts at once |
| Throttling | 001 (R17) | `App\Services\Auth\SignInThrottle` | Wrong current password and failed reset second factor (FR-012, FR-024); new reset limiters |
| Legacy hash verification | 001 (R12) | `App\Support\Hashing\LegacyPasswordVerifier` | Current password (FR-008) |
| TOTP, WebAuthn, Yubico verification | 001 (R9–R11) | `App\Services\Auth\SecondFactorService`, `WebAuthnServerFactory`, `OptionsJson`, `App\Clients\Yubico\YubicoOtpVerifier` | Reset completion second factor; extended with account-level verification (R6) and explicit Yubico credentials (R9) |
| Recent-login writer | 001 | `App\Services\Auth\RecentLoginRecorder` | Webmail hand-off `SSO` entry (R3) |
| Product name | 001 (R15) | `App\Services\Branding\ProductName` | Apple profile file name and organisation (R18) |
| Legacy configuration reader | 001 (R14) | `App\Services\Config\MailcowConfig`, `resources/bin/export-mailcow-vars.php` | Extended with this feature's legacy variables (R23) |
| Problems, ETags, lists, form requests, audit | 001 | `ProblemType`, `ProblemException`, `ETag`, `ListQuery`, `ApiFormRequest`, `AuditLogger` | Everywhere |
| Test toolkit | 001 | `tests/TestCase.php` (`signInAs`, `csrfToken`), `MailcowFactory`, `VirtualAuthenticator`, `OpenApiContract` | Extended with rows of this feature's tables |
| Domain-name normalisation (IDN) | 002 (D27 there) | `App\Support\Domain\DomainName` | Mailbox addresses and temporary-alias domains |
| Audit filters `targetType`, `targetId` | 002 (FR-058 there) | `listAuditEvents` (`api/modules/audit/audit-events.yaml`) | FR-074 (R22) |
| dockerapi client (`DOCKER_TIMEOUT`), fake | 002 (D27 there) | `App\Clients\DockerApi\DockerApiClient` (`exec`), `tests/Support/Fakes/FakeDockerApiClient` | Active Sieve script (R13) |
| Groupware directory view, memcached flush | 002, `refresh()` by 003 | `App\Services\Groupware\SogoDirectory` (`rebuild()`, `refresh(array)`), `App\Clients\Memcached\MemcachedFlusher` | FR-010 (full `rebuild()`), FR-025 (`refresh([mailbox])`) |
| Writes to mailcow's Redis with ping and compensation | 002 (D27 there) | `App\Clients\SharedStore\SharedStore` | Tagged mail, hand-off session, country cache (R3, R15, R16) |
| Mailbox scope and 404 semantics, address value object | 003 | `App\Services\Mailboxes\MailboxScope`, `MailboxAddress` | Every `/mailboxes/{mailbox}/…` operation (R11) |
| Mailbox read models | 003 | `App\Models\Mailcow\{Mailbox,QuotaUsage,QuotaReplicaUsage,SpamAlias,SenderAcl,Pushover}` | Overview, temporary aliases (written here through `SpamAlias`), push notifications (written here through `Pushover`) |
| Address availability (mailbox, resource, alias, temporary alias) | 003 | `App\Services\Mailboxes\AddressAvailability` | Temporary-alias collisions (R12) |
| Tagged-mail hashes | 003 | `App\Services\Mailboxes\TaggedMailHandlingStore` | Mail settings (R15) |
| Password policy read and check, password hashing | 003 | `App\Services\Passwords\{PasswordPolicyReader,EffectivePasswordPolicy,PasswordPolicyChecker,MailboxPasswordHasher}` | FR-009, FR-014 (R4); the hasher serves admin accounts too, as 007 uses it |
| Mailbox usage figures | 003 | `App\Services\Mailboxes\MailboxReader` (quota, `QuotaUsage` / `QuotaReplicaUsage` per `MASTER`) | Overview (FR-069) |
| Aliases and alias domains | 002, 004 | `App\Models\Mailcow\{Alias,AliasDomain}` | Overview, temporary-alias domains |
| Bulk runner | 004 (or an earlier feature) | `App\Support\Bulk\{BulkActionRunner,BulkOutcome}` | Bulk TLS (R15) |
| Refusal auditing | the first implemented feature (008 research describes it) | `App\Services\Audit\RefusalAuditor`, route default `restv2.audit` | Every state-changing route (R22) |
| Named locks | 005 creates it if absent; 009 reuses it | `App\Support\Database\NamedLock` (`GET_LOCK`/`RELEASE_LOCK`) | Code-only uniqueness: reset token limit (R6), last active second factor and Yubico replace (R10), one active Sieve filter per kind (R13) |

- **Rationale**: Constitution VIII (abstractions appear after the second use, no duplicated
  logic) and `docs/conventions.md` ("cleanup that more than one feature performs is implemented
  once and reused"; "features reuse this reader; none writes its own").
- **Alternatives considered**: Re-implementing the password policy, hashing and the static view
  in 005 was rejected: two implementations would drift, and the password policy tag must be the
  same everywhere (003 FR-063).

---

## 2. Sessions of this API

### R2. Session registry: finding and ending all sessions of an account

- **Decision**: 005 adds a small Redis index next to 001's Redis sessions.
  - At sign-in, `SessionManager` gives every session a stable **handle** (a ULID stored as
    `restv2.handle` in the session data). The handle survives `migrate(true)`, while the Laravel
    session ID changes.
  - `restv2:account-sessions:<accountKind>:<username>` is a Redis hash `handle → current session
    ID`. `SessionManager` writes it when a principal is established, rewrites the field after every
    `migrate(true)` (password step, second factor, passkey, and the password change of R4), and
    deletes it on sign-out and invalidation. `EnsureSessionIntegrity` refreshes the hash's TTL
    (`SESSION_LIFETIME`) on every session request, so an idle account's index expires with its
    sessions.
  - `App\Services\Auth\SessionRegistry` provides `register`, `rename`, `forget`, `isAlive(handle)`
    (asks the configured session handler whether the ID still has data) and
    `endAll(accountKind, username, ?exceptHandle)`. `endAll` destroys each listed session through
    the session handler (`destroy($id)`), ends its webmail hand-off (R3), removes the field and
    returns the number of sessions ended. Fields whose session no longer exists are pruned when the
    hash is read and are not counted.
  - A password change by the account ends its other sessions unless `endOtherSessions: false`
    (FR-011). A completed reset ends all of them (FR-025). The current session is renewed with
    `migrate(true)`.
- **Rationale**: The spec assumes "the session store of feature 001 can find and end all sessions
  of an account". Laravel's Redis session driver keys sessions only by ID, so an index is needed. A
  hash per account keeps the cost at one command per sign-in and one `EXPIRE` per request, and the
  handle keeps the index right across identifier renewals.
- **Alternatives considered**: Scanning `restv2:session:*` and unserialising every session was
  rejected (O(all sessions) per password change). Storing sessions in SQL was rejected by 001-R6.
  A per-account "generation counter" checked on every request was rejected: it needs a read per
  request and does not free the sessions' memory.

---

## 3. Webmail single sign-on

### R3. Webmail hand-off through the legacy PHP session format (FR-067, FR-068)

This is the one explicit coupling to the legacy session format that the spec sanctions
(constitution V: "unless a spec says otherwise"). It is kept minimal, isolated in one class and
tested against the legacy `sogo-auth.php` of each supported release.

- **How legacy webmail SSO works** (`data/web/sogo-auth.php`, `inc/sessions.inc.php`,
  `Dockerfiles/phpfpm/docker-entrypoint.sh`):
  - nginx-mailcow sends an `auth_request` for every `/SOGo/so/` request to `sogo-auth.php` through
    an internal loopback that sets `SOGO_AUTH_INTERNAL=1`. The browser's cookies travel with it.
  - `sogo-auth.php` includes `vars.inc.php` and `sessions.inc.php`, which starts the PHP session
    named `$SESSION_NAME` (`MCSESSID`) from phpredis (`session.save_handler = redis`, default key
    prefix `PHPREDIS_SESSION:`, database 0, default `php` serialiser,
    `gc_maxlifetime = $SESSION_LIFETIME`). An idle session (`LAST_ACTIVITY` older than
    `SESSION_LIFETIME`) is destroyed.
  - It grants webmail (`X-User: <mailbox>` plus SOGo's SSO secret) when
    `$_SESSION['mailcow_cc_username']` is a valid e-mail address listed in
    `$_SESSION['sogo-sso-user-allowed']` and neither `pending_pw_update` nor `pending_tfa_setup` is
    set. It does **not** look at `mailcow_cc_role`.
- **Decision**: `App\Services\Webmail\WebmailHandOffService::handOff(Principal $creator, string
  $mailbox, Request $request): WebmailHandOff`, used by `POST /me/webmail-handoff` and, later, by
  feature 007 for webmail on behalf of a user (007 FR-054 to FR-056):
  1. Checks: the session is a full session of a mailbox user for its own mailbox (007 passes its
     own checks), the groupware is enabled (`SKIP_SOGO` ≠ `y`, else `groupware-disabled`), and
     Redis is reachable (else `temporary-failure`, `dependency: sharedStore`).
  2. Ends a previous hand-off of the same API session (one hand-off per session).
  3. Creates a new legacy session ID: 32 lower-case hexadecimal characters from `random_bytes(16)`,
     the format PHP 8.2's default `session.sid_length = 32` / `sid_bits_per_character = 4`
     produces, and writes `PHPREDIS_SESSION:<id>` on the `mailcow` Redis connection with
     `SET … NX EX <SESSION_LIFETIME>` (a collision retries with a new ID).
  4. The value is encoded by `App\Support\LegacySession\PhpSessionEncoder` in PHP's `php`
     serialiser format (`<key>|<serialize(value)>` per top-level key) and contains exactly:
     - `mailcow_cc_username` = the mailbox;
     - `sogo-sso-user-allowed` = `[<mailbox>]`;
     - `mailcow_cc_api_access` = `'ro'` (see "webmail only" below);
     - `SESS_REMOTE_UA` = the request's `User-Agent`;
     - `LAST_ACTIVITY` = the current Unix time;
     - `CSRF` = `['TOKEN' => <64 random hex>]` (what `sessions.inc.php` would create anyway).

     It never contains `mailcow_cc_role`, `acl`, `pending_*`, `dual-login` or the SSO secret.
  5. Sets the cookie in the response: `MCSESSID=<id>; Path=/; HttpOnly; Secure;
     SameSite=<SESSION_SAMESITE_POLICY>` (legacy defaults: name `MCSESSID`, `Lax`), with no
     `Domain` and no expiry, exactly as PHP would issue it. The name and policy come from
     `MailcowConfig` (R23).
  6. Records the legacy recent-login entry `REPLACE INTO sasl_log (service, app_password,
     username, real_rip) VALUES ('SSO', 0, :mailbox, :ip)` through `RecentLoginRecorder`, and
     audits `webmailHandOff`.
  7. Stores `restv2:webmail-handoff:<id>` (hash: `handle`, `owner` = `<accountKind>:<username>`,
     `mailbox`, `createdAt`) and adds `<id>` to `restv2:session-handoffs:<handle>`.
  8. Answers `{mailbox, url: "/SOGo/so/"}`. The browser then opens `/SOGo/so/` and nginx's
     `auth_request` grants it.
- **Webmail only (FR-068)**:
  - *Legacy UI*: every legacy page and form handler requires `mailcow_cc_role` (`protect_route()`,
    `index.php`, `triggers.*.inc.php`, `mobileconfig.php`, `oauth/authorize.php`,
    `inc/ajax/*.php`). Without it the browser sees the legacy sign-in page.
  - *`/api/v1`*: `json_api.php` refuses `add`, `edit` and `delete` whenever
    `$_SESSION['mailcow_cc_api_access'] == 'ro'`, which the hand-off sets, before any category
    runs. This matters because some legacy functions (`app_passwd('add')`, for example) fall back to
    `mailcow_cc_username` without a role check. `get` requires `mailcow_cc_role`, so reads return
    nothing. The marker is otherwise only set by legacy for read-only API keys and has no other
    effect.
  - *`sogo-auth.php?login=`* (the legacy SSO entry) requires `mailcow_cc_role`, so the hand-off
    cannot be turned into a dual login.
- **Ending (FR-068)**:
  - Sign-out, invalidation (account deactivated, user agent changed) and FR-011/FR-025 end the
    hand-off at once: `SessionManager::end()` and `SessionRegistry::endAll()` call
    `WebmailHandOffService::endForHandle()`, which deletes `PHPREDIS_SESSION:<id>` and its records.
    The sign-out response also expires the `MCSESSID` cookie when the session had a hand-off.
  - Idle expiry of the API session raises no event (Redis TTL). The artisan command
    `restv2:webmail-handoffs:sweep`, run every minute by mailcow's ofelia (label on the API
    container, as 001-R22 does for the audit purge), scans `restv2:webmail-handoff:*` (a few keys)
    and deletes the legacy session of every hand-off whose API session is no longer alive
    (`SessionRegistry::isAlive`), and the record of every hand-off whose legacy session already
    expired. An expired API session therefore ends its hand-off within 60 seconds.
  - Legacy's own idle rule (`LAST_ACTIVITY` + `SESSION_LIFETIME`) ends an unused hand-off after
    3 hours at the latest.
- **Coupling kept testable**:
  - `scripts/legacy/extract-sogo-auth.sh <tag>` copies `data/web/sogo-auth.php`,
    `inc/vars.inc.php` and `inc/sessions.inc.php` of an upstream tag into
    `tests/fixtures/legacy/sogo-auth/<tag>/` with the tag and source hashes in a header file.
  - `tests/Feature/Webmail/SogoAuthCompatibilityTest.php` runs that **unmodified** legacy
    `sogo-auth.php` under a throw-away php-fpm pool (`php-fpm -F -y` on `127.0.0.1:9901`, phpredis
    session handler pointed at the test Redis database) through `cgi-fcgi` (Alpine package `fcgi`,
    dev image only), with `SOGO_AUTH_INTERNAL=1`, `HTTP_X_ORIGINAL_URI=/SOGo/so/` and the
    hand-off cookie. It asserts `X-User: <mailbox>` after a hand-off and an empty `X-User` after
    sign-out, after `endAll`, after the sweeper and with a tampered session.
  - `scripts/legacy/check-handoff-assumptions.sh <tag>` greps the tag for the three guards above
    (the `mailcow_cc_api_access == 'ro'` checks of `json_api.php`, the `mailcow_cc_role` check of
    `protect_route()` and the `auth_request` branch of `sogo-auth.php`) and fails when one is
    missing. CI runs it for every supported release next to the schema fixture (constitution III).
- **Rationale**: This reproduces the legacy SSO without changing any container (constitution V),
  binds the hand-off to the requesting browser (HttpOnly cookie), grants nothing but webmail, and
  makes its lifetime follow this API's session. Isolation in one service plus a compatibility test
  against the real legacy script makes a format change in a future release fail loudly.
- **Alternatives considered**:
  - A signed URL that SOGo accepts was rejected: SOGo trusts only nginx's `auth_request`.
  - Setting `mailcow_cc_role = user` (what legacy does) was rejected: it signs the browser in to
    the legacy UI and `/api/v1`, which FR-068 forbids.
  - Setting `pending_mailcow_cc_username` to block `/api/v1` writes was rejected: the legacy UI
    would show its second-factor dialog, whose completion turns the browser into a full legacy
    session.
  - Redis keyspace notifications for expired API sessions were rejected: they need a change to
    mailcow's Redis configuration.
  - Patching `sogo-auth.php` to ask this API was rejected (constitution V).

---

## 4. Own password, forced actions, restricted sessions

### R4. Changing the own password (FR-008 to FR-015)

- **Decision**: `App\Services\Account\OwnPasswordService::change(Principal, ?string $current,
  string $new, bool $endOtherSessions, Request)`:
  1. **Eligibility**: a mailbox whose `authsource` is not `mailcow` → `password-managed-externally`
     (FR-013). Role and account kind come from the session principal, so a username that is both a
     mailbox and an administrator acts on the account it signed in as.
  2. **Current password**: required unless `passwordChange` is a pending action of the session
     (FR-015). Missing → `422 required`. Throttle check first (`SignInThrottle::assertNotThrottled`
     on 001's key `signin:<sha256(username|ip)>` → `429`). Verification with
     `LegacyPasswordVerifier` against `admin.password` or `mailbox.password` (mailbox:
     `kind NOT REGEXP 'location|thing|group' AND authsource = 'mailcow'`, as `edit_user_account`).
     A wrong value → throttle `hit` with 001's progressive delay, audit
     (`ownPasswordChange`, failure `currentPasswordIncorrect`) and `403 current-password-incorrect`.
     Never published to fail2ban (FR-012). A correct value clears the throttle key.
  3. **New password** (FR-009), checked in this order and all violations reported together:
     - a value starting with a hash-scheme prefix that legacy `hash_password()` recognises
       (`/^{(ARGON2I|ARGON2ID|BLF-CRYPT|CLEAR|CLEARTEXT|CRYPT|DES-CRYPT|LDAP-MD5|MD5|MD5-CRYPT|PBKDF2|PLAIN|PLAIN-MD4|PLAIN-MD5|PLAIN-TRUNC|SHA|SHA1|SHA256|SHA256-CRYPT|SHA512|SHA512-CRYPT|SMD5|SSHA|SSHA256|SSHA512)}/i`)
       → rule `preHashedPassword` with param `scheme`;
     - 003's `PasswordPolicyChecker` (legacy `password_check`: byte length,
       `[A-Za-z]`, `\d`, `[^a-zA-Z\d]`, lower and upper case) → 003's codes: `tooShort` with the
       policy's `min`, and one `missingCharacterClass` per unmet class (`characterClass`: `letter`,
       `digit`, `special`, `lowerAndUpper`), so every client translates password rules the same way
       in features 003, 005 and 007.
  4. **Write** in one transaction with its audit record: 003's `MailboxPasswordHasher` in
     `MAILCOW_PASS_SCHEME` (`BLF-CRYPT` default, `SSHA`, `SSHA256`, `SSHA512`), then
     - mailbox: `UPDATE mailbox SET password = ?, attributes = JSON_SET(attributes,
       '$.force_pw_update', '0'), attributes = JSON_SET(attributes, '$.passwd_update', NOW())
       WHERE username = ? AND authsource = 'mailcow'`, followed by the full
       `update_sogo_static_view()` port (002's `SogoDirectory::rebuild()`) unless `SKIP_SOGO=y`, and
       the memcached flush (`MemcachedFlusher`) after commit (FR-010, `edit_user_account`);
     - administrator and domain administrator: `UPDATE admin SET password = ?, attributes =
       JSON_SET(COALESCE(attributes, '{}'), '$.force_pw_update', '0') WHERE username = ?`
       (`admin('edit')` forced branch, `domain_admin('edit')` self branch).
  5. **Sessions** (R2): other sessions ended unless `endOtherSessions` is `false`; the current
     session is renewed (`migrate(true)`, new cookie, registry renamed).
  6. **Response**: `sessionsEnded`, the number of active app passwords that keep working
     (`SELECT COUNT(*) FROM app_passwd WHERE mailbox = ? AND active = '1'`, 0 for admin accounts),
     and `mailServerCacheSeconds: 300`, the Dovecot authentication cache period during which mail
     clients may still use the old password.
  - App passwords, API keys and legacy sessions are not touched.
  - Order of checks follows legacy (current password before the policy), so the policy answer never
    helps someone who does not know the password.
- **Rationale**: A line-by-line port of the three legacy branches with the spec's deviations
  (one field, current password for admins, prefix refusal, throttling, other sessions ended).
- **Alternatives considered**: Checking the policy before the current password was rejected
  (information to an attacker with an open session). Reporting a wrong current password as `422`
  was rejected: it is a re-authentication failure that is audited and throttled, so it gets its own
  problem type.

### R5. Restricted sessions and route modes (FR-016, FR-017, FR-004)

- **Decision**:
  - 001's `EnforcePendingActions` gets one more mode, `includes,<action>`: the route is allowed in
    a restricted session when the session's pending actions include `<action>`, whatever else is
    pending (both forced actions can be resolved in either order). Routes:
    - `GET /capabilities/self-service`: `allow`;
    - `GET /me/password-policy`, `PUT /me/password`: `includes,passwordChange`;
    - `GET /me/second-factors`, `POST /me/second-factors`, `POST /me/second-factors/totp-enrolment`,
      `POST /me/second-factors/webauthn-registration-options`: `includes,secondFactorSetup`;
    - every other route of this feature: `enforce`.
  - Because 001 re-evaluates pending actions on every request, a saved password or second factor
    lifts the restriction for the next request. `secondFactorSetup` stays lifted because the `tfa`
    row now exists (001-R16).
  - Own-account routes use `restv2.auth:session` (keys get `403 session-required`, which is
    FR-004's "refused as forbidden"). Mailbox-object routes use `restv2.auth:authenticated`.
  - 001's `EnforceKeyScope` refuses every non-`GET` request of a read-only key. FR-004 counts
    Sieve validation as a read, so routes may carry the marker `->defaults('restv2.readOnly',
    true)`, which `EnforceKeyScope` honours. Only `POST /sieve-filters/validation` uses it.
    Push-notification verification is a change (FR-004) and does not.
  - Feature 007 adds the "not allowed while impersonating" refusal to the own-credential routes of
    001 and 005 (007 FR-049). 005 gathers those routes under the route-group name `restv2.own`, so
    007 attaches one middleware.
- **Rationale**: The smallest extension of 001's pipeline that expresses FR-017 exactly.
- **Alternatives considered**: Reusing 001's `secondFactorSetup` mode (allowed only when it is the
  sole pending action) was rejected: with both actions pending, the user could not enrol first.
  A `GET` with the script in the query for validation was rejected: scripts reach 64 KiB.

---

## 5. Password reset

### R6. Requests, tokens, messages and completion (FR-019 to FR-026)

- **Decision** (`App\Services\PasswordReset\PasswordResetService`):
  - **Routes** use `restv2.auth:browser`, `restv2.csrf` and `restv2.pending:allow`, like sign-in:
    the web app fetches `GET /csrf-token` first. API keys get `session-required`.
  - **Availability** (FR-019): the reset is available when `PW_RESET_FROM` and `PW_RESET_SUBJ`
    (mailcow Redis) are both non-empty, as `reset_password('issue')` requires. Otherwise every
    request answers `403 password-reset-unavailable`, and the capabilities say so.
  - **Request** (`POST /password-resets`): validate (`address` an e-mail address, ≤ 255), check the
    per-address limiter, then answer `200` with the constant acknowledgement **before any lookup**.
    The work runs in a deferred callback (`Illuminate\Support\defer()`, executed after the response
    is flushed; Symfony's `Response::send()` calls `fastcgi_finish_request()` under php-fpm):
    1. `DELETE FROM reset_password WHERE created < DATE_SUB(NOW(), INTERVAL <lifetime> MINUTE)`;
    2. eligibility (FR-020): an active (`active = '1'`), non-resource mailbox with
       `authsource = 'mailcow'`, a recovery address that passes `FILTER_VALIDATE_EMAIL`, and fewer
       than `PW_RESET_TOKEN_LIMIT` rows in `reset_password` for the username; `pw_reset` is not
       checked;
    3. token: five groups of `strtoupper(bin2hex(random_bytes(3)))` joined by `-`, inserted with
       `INSERT INTO reset_password (username, token) VALUES (?, ?)` (`created` by default). Steps 2
       (the count) and 3 run under the named lock `restv2_pwreset_<sha1(username), 40 hex>`
       (`NamedLock`, `GET_LOCK(…, 5)`), so concurrent requests cannot exceed the token limit, which
       upstream enforces only in code (`docs/conventions.md`);
    4. message (below); when sending fails the token row is deleted again (spec deviation);
    5. one audit record `passwordResetRequest` with the attempted address and the outcome
       (`sent`, or the failure reason `unknownAccount`, `accountNotEligible`, `noRecoveryEmail`,
       `tokenLimitReached`, `messageNotSent`).
  - **Timing (FR-020, SC-005)**: the response path is identical for every well-formed address
    (validation, one Redis limiter call, a fixed body). The lookup, token and SMTP work happen after
    the response is sent, so their duration never reaches the client. A timing test compares
    medians.
  - **Message** (FR-022, `reset_password('issue' / 'send_mail')`): sender `PW_RESET_FROM`, subject
    `PW_RESET_SUBJ`, bodies `PW_RESET_HTML` / `PW_RESET_TEXT`, falling back to
    `pw_reset_html.tpl` / `pw_reset_text.tpl` of the mounted `data/assets/templates` directory when
    both stored bodies are empty. Placeholders `{{hostname}}` (`MAILCOW_HOSTNAME`), `{{link}}`,
    `{{username}}`, `{{username2}}` (recovery address), `{{date}}` (`IntlDateFormatter` FULL/FULL in
    the default locale and `TZ`) and `{{token_lifetime}}` are replaced with `str_replace`, as legacy
    does. The link is `https://<host><RESTV2_PW_RESET_PAGE>` with `{token}` replaced; `<host>` is the
    request's host when it equals `MAILCOW_HOSTNAME` or one of `ADDITIONAL_SERVER_NAMES` (lower-cased,
    whitespace removed), otherwise `MAILCOW_HOSTNAME` (`getBaseURL()`), captured before the response.
    The default page is the web app's `/ng/reset-password?token={token}`; an operator who keeps the
    legacy page sets `/reset-password?token={token}`.
  - **Transport**: Laravel's mailer (Symfony Mailer, part of the framework) with an SMTP transport to
    `postfix-mailcow:25`, no authentication, opportunistic STARTTLS without peer verification,
    10-second timeout, `X-Mailer: MooMail`, UTF-8, HTML body with text alternative (or text only),
    exactly PHPMailer's settings in `send_mail`. The legacy checks (valid sender and recipient,
    non-empty subject and text) apply; a failed check counts as "message not sent".
  - **Token check** (`POST /password-resets/token-check`, FR-023): the token is sent in the body,
    never in a URL, and stripped of characters outside `[A-Za-z0-9-]` as legacy does. Lookup:
    `SELECT t1.username, t1.created FROM reset_password t1 JOIN mailbox t2 ON t1.username =
    t2.username WHERE t1.token = ? AND t1.created > DATE_SUB(NOW(), INTERVAL <lifetime> MINUTE) AND
    t2.active = 1 AND t2.authsource = 'mailcow'`. Not found → `400 invalid-reset-token`. Found →
    address, expiry (`created` + lifetime, in UTC), 003's password policy, and the account's
    second-factor situation (usable methods in 001's order; `secondFactorRequired`).
  - **WebAuthn for the reset** (`POST /password-resets/webauthn-challenge`): a valid token gets a
    001-style challenge (`getGetArgs` with the account's active WebAuthn key handles and `appid`),
    stored in the anonymous session as `restv2.passwordResetWebauthn` = `{tokenHash, challenge,
    expiresAt}` (single-use, 300 s), which binds it to the token and the browser.
  - **Completion** (`POST /password-resets/completion`, FR-024, FR-025):
    1. per-address limiter, token lookup as above (`invalid-reset-token`), new-password rules (R4);
    2. second factor when the account has at least one active `tfa` row (legacy `get_tfa`, any
       `authmech`): only deprecated entries → `403 second-factor-unavailable`; none sent →
       `401 second-factor-required` with `methods`; a sent proof is verified by
       `SecondFactorService::verifyForAccount(username, proof, ?challenge)` (TOTP with 001's replay
       protection, WebAuthn against `restv2.passwordResetWebauthn`, Yubico OTP). Failure →
       `401 second-factor-failed`, the token stays valid, 001's sign-in throttle counts a failure for
       `(username, ip)`, and the attempt is audited; a Yubico outage → `503
       temporary-verification-failure`;
    3. one transaction: `UPDATE mailbox SET password = ?, attributes = JSON_SET(attributes,
       '$.passwd_update', NOW(), '$.force_pw_update', '0') WHERE username = ? AND authsource =
       'mailcow'`, `DELETE FROM reset_password WHERE username = ?`, the single-mailbox static-view
       refresh (`SogoDirectory::refresh([$username])`) unless `SKIP_SOGO=y`, the audit record; then
       the memcached flush and `SessionRegistry::endAll('mailbox', username)` with hand-offs;
    4. the new password is never kept between requests (the client resends it with the second
       factor).
  - **Throttling** (FR-026): three `RateLimiter` keys per client address,
    `restv2:throttle:pwreset:{request|check|complete}:<sha256(ip)>`, each 10 attempts per 900 s
    (`RESTV2_PW_RESET_MAX_ATTEMPTS`, `RESTV2_PW_RESET_WINDOW`). Every attempt counts. Above the limit
    → `429` with `Retry-After`.
- **Rationale**: Legacy storage, template, placeholders, link host, transport and eligibility are
  kept, so tokens work across both UIs (SC-001 of the spec's US3 AS-8). Deferring the work after the
  response is the only way to make the response time independent of the outcome without sleeping.
- **Alternatives considered**:
  - A fixed minimum response time (sleep to 1 s) was rejected: it still leaks when the SMTP step
    exceeds the floor, and it holds a worker anyway.
  - Laravel queues were rejected: the API has no worker container, and constitution VIII asks for
    the simplest mechanism.
  - Keeping the new password in the session during the second-factor step (legacy) was rejected by
    FR-024.
  - Putting the token in the URL path was rejected: tokens would land in access logs.

---

## 6. Second factors

### R7. TOTP enrolment (FR-029, FR-030) and the QR code

- **Decision**:
  - `POST /me/second-factors/totp-enrolment`: `RobThree\Auth\TwoFactorAuth` configured as in
    001-R10 (`OTP_LABEL` issuer, 6 digits, 30 s, SHA-1), `createSecret(160)`. The provisioning
    address is `getQRText(<username>, <secret>)`
    (`otpauth://totp/<label>?secret=…&issuer=…&period=30&algorithm=SHA1&digits=6`), and the QR code
    is `getQRCodeImageAsDataUri()` from robthree's `BaconQrCodeProvider` with format `svg`
    (`data:image/svg+xml;base64,…`). The session stores `restv2.totpEnrolment = {secret,
    expiresAt (now + 600 s), attemptsLeft: 5}`; a new start replaces it. This ceremony request is not
    audited (001's Complexity Tracking justification for ceremony requests applies).
  - Confirmation (`POST /me/second-factors`, `type: totp`): checks in this order: eligibility,
    current password (unless `secondFactorSetup` is pending, FR-016), enrolment present and not
    expired (`400 second-factor-enrolment-expired`), then `verifyCode($secret, $code, 1, null,
    $timeslice)`. A wrong code decrements `attemptsLeft` and answers `400
    second-factor-enrolment-failed` with `attemptsRemaining`; at zero the enrolment is removed.
    Success inserts `INSERT INTO tfa (username, key_id, authmech, secret, active) VALUES (?, ?,
    'totp', ?, '1')`, writes `restv2:totp:last:<new id>` = `$timeslice` (TTL 120 s, 001-R10) so the
    confirming code cannot sign in, removes the enrolment and audits `ownSecondFactorEnrolment`.
- **New dependency**: `bacon/bacon-qr-code` (BSD-2-Clause, GPL-3.0-compatible), the library legacy
  uses for the same purpose (2.0.8 in `inc/lib/composer.lock`), in the version range robthree's
  `BaconQrCodeProvider` accepts. SVG output needs no `imagick` extension. 001's
  `NoQrCodeProvider` stays for verification-only uses.
- **Rationale**: Same algorithm and issuer as legacy, so legacy and API verify each other's
  entries. The secret never travels back from the browser (deviation recorded in the spec).
- **Alternatives considered**: Returning only the provisioning address and letting the client draw
  the QR code was rejected (FR-030 asks for the image). `endroid/qr-code` was rejected: larger, and
  not legacy's library. PNG output was rejected: it needs `imagick` in the image.

### R8. WebAuthn second-factor enrolment (FR-031)

- **Decision**: As legacy `get/webauthn-tfa-registration` and `add/webauthn-tfa-registration`:
  - options: `getCreateArgs(username, username, username, 300, false (resident key not required),
    WEBAUTHN_UV_FLAG_REGISTER, null, <base64-decoded keyHandle of the account's tfa webauthn rows>)`
    on 001's `WebAuthnServerFactory` (host name as `rpId`, `FIDO2_FORMATS`, trusted-vendor roots when
    `WEBAUTHN_ONLY_TRUSTED_VENDORS=y`); the challenge is stored as
    `restv2.secondFactorWebauthn = {challenge, expiresAt (300 s)}`, single-use;
  - completion: `processCreate(clientDataJSON, attestationObject, challenge,
    WEBAUTHN_UV_FLAG_REGISTER, WEBAUTHN_USER_PRESENT_FLAG)`; a failure answers `400
    second-factor-enrolment-failed` and consumes the options; success inserts `INSERT INTO tfa
    (username, key_id, authmech, keyHandle, publicKey, certificate, counter, active) VALUES (?, ?,
    'webauthn', base64(credentialId), credentialPublicKey (PEM), certificate, 0, '1')`.
  - The `timeout` hint is 300 000 ms (legacy sends 30 s), matching the 5-minute validity.
  - The same security key may also be a passkey (separate `fido2` row), as in legacy.
- **Rationale**: Byte-compatible storage with legacy, so both UIs verify the entry (FR-036).
- **Alternatives considered**: Requiring resident keys (passkey options of 001) was rejected:
  legacy second factors are non-discoverable credentials.

### R9. Yubico OTP enrolment (FR-032)

- **Decision**: Request fields `name`, `clientId` (digits, ≤ 255), `secretKey` (base64, ≤ 255),
  `otp` (44 letters or digits). 001's verifier gains `verifyWithCredentials(clientId, secretKey,
  otp)` (the per-row variant calls it). `valid` → `DELETE FROM tfa WHERE username = ? AND
  authmech = 'yubi_otp' AND secret LIKE ?` (`'%' . substr(otp, 0, 12)`, as legacy), then `INSERT
  INTO tfa (key_id, username, authmech, active, secret) VALUES (?, ?, 'yubi_otp', '1',
  '<clientId>:<secretKey>:<modhex>')`; `invalid` → `400 second-factor-enrolment-failed`;
  `temporaryFailure` → `503 temporary-verification-failure`, nothing stored.
- **Rationale**: Legacy storage format and replacement rule; the HTTP client and its fake are
  001's.

### R10. Listing and removing second factors (FR-027, FR-034, FR-035)

- **Decision**:
  - List: every `tfa` row of the username, all `authmech` values and both states, with `usable`
    false for `u2f` and `hotp`, `yubicoPublicId` = `RIGHT(secret, 12)` for `yubi_otp`, and
    `usedForCurrentSignIn` from the session principal's `secondFactorId`, which 001's
    `SecondFactorService` records on promotion (005 adds the field). `secret`, `keyHandle`,
    `publicKey`, `certificate` and `counter` are never selected.
  - Remove one (`DELETE /me/second-factors/{id}`): refused with `403
    second-factor-required-by-policy` when the account's `attributes.force_tfa` is `"1"` or `1`
    (read with `JSON_UNQUOTE(JSON_EXTRACT(…))`), and with `409 last-second-factor` when the entry is
    active and the account's only active entry. Legacy refuses any removal while exactly one entry is
    active, also of an inactive entry; the spec's rule ("the entry is the only active entry") is
    kept, and the difference is a plan-level parity note. `DELETE FROM tfa WHERE username = ? AND
    id = ?`. The check and the delete run under the named lock `restv2_tfa_<sha1(username), 40 hex>`,
    so two concurrent removals cannot delete the last two active entries; enrolments (the Yubico
    replace of R9 included) take the same lock.
  - Remove all (`POST /me/second-factors/removal`): current password, `force_tfa` refusal, `DELETE
    FROM tfa WHERE username = ?`; `fido2` is untouched. One audit record with the removed IDs.
  - Legacy's `force_tfa` check compares the JSON-encoded value with `'1'`, which never matches a
    string `"1"`, so legacy effectively never blocks. The API applies the rule the spec states.
  - Eligibility (FR-028): administrators, domain administrators and mailcow-source mailboxes;
    other mailboxes → `403 password-managed-externally` (the type covers "managed by the identity
    provider").
- **Rationale**: Ports of `get_tfa`, `unset_tfa_key` and `set_tfa('none')` with the spec's fixes.

---

## 7. Mailbox objects

### R11. App passwords (FR-037 to FR-041)

- **Decision** (`App\Services\AppPasswords\AppPasswordService`):
  - Every `/mailboxes/{mailbox}/…` route resolves the mailbox through 003's `MailboxScope`
    (normalised address, non-resource, in the principal's scope) and answers `404` otherwise
    (FR-003). The flag check (FR-006) uses the principal's effective flags: `appPasswds` for app
    password changes.
  - Name: trimmed, 1 character at least, stored as `htmlspecialchars(trim($name))` (PHP defaults
    `ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401`), and the stored form must fit 255 characters
    (`tooLong`, `max: 255`, `stored: true`). Returned with `htmlspecialchars_decode(…, ENT_QUOTES)`.
    `q` is encoded the same way before the `LIKE`.
  - Password: given (R4 rules) or generated: `max(32, policy minLength)` characters from
    `random_int`, at least one lower-case letter, upper-case letter, digit and special character of
    `!@#$%^&*()?=`, shuffled with Fisher–Yates (the `password_generate()` alphabet with the spec's
    length). Hashed with `MailboxPasswordHasher`. A generated password is returned once as
    `generatedPassword`.
  - Protocols map to `imap_access`, `pop3_access`, `smtp_access`, `sieve_access`, `eas_access`,
    `dav_access`; default all six, active by default (the legacy UI's defaults).
  - Insert: `INSERT INTO app_passwd (name, mailbox, domain, password, imap_access, smtp_access,
    eas_access, dav_access, pop3_access, sieve_access, active) VALUES (…)` with `domain` =
    `mailbox.domain`.
  - Update: only sent members; `UPDATE app_passwd SET … WHERE id = ? AND mailbox = ?`; `mailbox`
    is never rewritten (legacy bug fixed) and a new password is applied for administrators too.
  - Delete: `DELETE FROM app_passwd WHERE id = ? AND mailbox = ?`.
  - Reads never select `password`.
- **Rationale**: `app_passwd()` storage with the spec's security fixes.

### R12. Temporary aliases (FR-042 to FR-046)

- **Decision** (`App\Services\TemporaryAliases\TemporaryAliasService`):
  - "Not expired" means `permanent != 0 OR validity >= UNIX_TIMESTAMP()` (legacy
    `time_limited_aliases` and Postfix's map). Expired rows are invisible to every operation (`404`).
  - Domains (FR-043, FR-081): the mailbox's domain, then `SELECT alias_domain FROM alias_domain
    WHERE target_domain = ? ORDER BY alias_domain` (legacy `user_get_alias_details`, active or not).
    The requested domain is normalised (002) and must be in that list (`notInList` with `allowed`).
  - Address: `<part>.<part>@<domain>` where each part has `random_int(6, 8)` letters alternating
    consonant (`bcdfghjklmnprstvwxyz`) and vowel (`aeiou`), starting with a consonant, from
    `random_int`. It must not equal: a `mailbox.username` (mailboxes and resources), an
    `alias.address`, a `spamalias.address`, or, for an alias domain, the target-domain address of a
    mailbox or alias with the same local part. 003's `AddressAvailability` answers the first three;
    the alias-domain case is checked here. Up to 10 attempts, then `503 temporary-failure`
    (`dependency: addressGeneration`), nothing stored.
  - Locking: the check and the insert run in one transaction that first locks the mailbox's domain
    row (`SELECT domain FROM domain WHERE domain = ? FOR UPDATE`), the lock 002, 003 and 004 take
    before creating any address in a domain (002 research D27). Temporary aliases therefore
    serialise with concurrent mailbox, alias and resource creations, which a separate named lock
    would not achieve; the primary key of `spamalias` guards duplicates among temporary aliases.
    This is the convention's purpose ("uniqueness that upstream enforces only in code", no new
    index) with the lock the earlier features already share.
  - Insert: `INSERT INTO spamalias (address, description, goto, validity, permanent) VALUES (?, ?, ?,
    UNIX_TIMESTAMP() + hours * 3600, ?)` (the description defaults to `''`; `permanent = 1` keeps a
    validity, as legacy).
  - Change (FR-045): `{lifetimeHours}` → `validity = now + hours * 3600, permanent = 0`;
    `{permanent: true}` → `validity = 0, permanent = 1` (legacy `edit`).
  - Delete: `DELETE FROM spamalias WHERE address = ? AND goto = ?`.
  - Flag: `spamAlias` for changes.

### R13. Sieve filters and the active script (FR-047 to FR-053)

- **Decision**:
  - **Parser**: the legacy parser (`data/web/inc/lib/sieve/`, namespace `Sieve`, distributed in
    mailcow-dockerized under GPL-3.0) is copied unmodified from the upstream tag into
    `resources/legacy/sieve/` by `scripts/legacy/extract-sieve-parser.sh <tag>` (tag and hashes in a
    header file) and loaded through Composer's `classmap`. `App\Support\Sieve\SieveValidator` calls
    `(new \Sieve\SieveParser())->parse($script)` and returns the exception message on failure, so
    validation equals legacy's byte for byte.
  - **Storage** (`sieve_filters`): `script_name` `'active'`/`'inactive'`, `filter_type`
    `'prefilter'`/`'postfilter'`, `script_desc`, `script_data`. Activation and kind changes run, in
    the same transaction, `UPDATE sieve_filters SET script_name = 'inactive' WHERE username = ? AND
    filter_type = ? AND id <> ?` (legacy deactivates all of the kind, then sets the row), under the
    named lock `restv2_sieve_<sha1(username), 40 hex>`, so concurrent activations leave at most one
    active filter per kind (a rule upstream enforces only in code). Dovecot reads the
    `sieve_before` / `sieve_after` views, so the next delivery uses the change.
  - **Limits**: description 1–255 characters; script non-empty and at most 65,535 bytes (the `TEXT`
    column; `tooLong` with `max: 65535, unit: bytes`); a parser failure → `422` with
    `sieveSyntax` and `message`.
  - **Flags**: changes need `filters` (administrators always; domain administrators their own
    flag; mailbox users never); reads need none.
  - **Active script** (FR-053): `App\Clients\Dovecot\SieveScriptReader` (interface) with the adapter
    `DockerApiSieveScriptReader` over the dockerapi client: `POST
    /containers/<dovecot id>/exec` with `{cmd: sieve, task: list, username}`, then, for the first line
    matching `/.+ ACTIVE/i`, `{cmd: sieve, task: print, script_name: <line without " ACTIVE">,
    username}`, removing the first output line, as `mailbox('get', 'active_user_sieve')` does. No
    active line → empty result. Unreachable, timeout (`DOCKER_TIMEOUT`) or error → `503
    temporary-failure` (`dependency: mailStore`). The fake asserts the calls.
- **Rationale**: Legacy parser and storage keep validation and delivery identical (SC-009).
- **Alternatives considered**: `protonlabs/libsieve-php` from Packagist was rejected: it is a
  separately maintained fork whose acceptance can drift from mailcow's copy. Normalising CRLF was
  rejected: legacy does it only for global filters (013).

### R14. Push notifications (FR-054 to FR-057)

- **Decision** (`App\Services\PushNotifications\PushNotificationService`):
  - Storage: one `pushover` row per mailbox, written with `REPLACE INTO pushover (username, key,
    attributes, senders_regex, senders, token, title, text, active)` and `attributes` =
    `{"evaluate_x_prio": "0|1", "only_x_prio": "0|1", "sound": "<sound>"}`, as `pushover('edit')`.
    "Configured" means a row exists with a non-empty key and token.
  - Validation: token and user key `^[A-Za-z0-9]{30}$` (both required while none are stored, kept
    when omitted); title ≤ 250, text ≤ 1,024 characters (empty stored as `''`, which mailcow's
    notifier replaces with `Mail` and `You've got mail 📧`); senders ≤ 100 valid addresses, stored
    comma-separated with dots removed from the local part of `@gmail.com` addresses
    (`preg_replace('/\.(?=.*?@gmail\.com$)/', '', …)`); sender pattern empty or a delimited PCRE
    that compiles (`@preg_match($p, '') !== false`), ≤ 1,024 characters; sound
    `^[A-Za-z0-9_-]{1,20}$`, default `pushover`.
  - Reads return `tokenSet` and `userKeySet`, never the values.
  - Delete: `DELETE FROM pushover WHERE username = ?`.
  - Verification: `App\Clients\Pushover\PushoverClient` (interface) with the HTTP adapter `POST
    https://api.pushover.net/1/users/validate.json` (`token`, `user`; 10 s timeout;
    `RESTV2_PUSHOVER_VALIDATE_URL`). HTTP 200 with `status: 1` → accepted; HTTP 4xx with `status:
    0` → not accepted, with Pushover's `errors` joined as the reason; connection error, timeout or
    5xx → `503 temporary-failure` (`dependency: pushover`). No row or empty credentials → `409
    push-notifications-not-configured`. Audited (`pushNotificationVerification`), and refused for
    read-only keys (FR-004).
  - Flag: `pushover` (domain administrators: their own `da_acl.pushover`).

### R15. Mailbox mail settings and bulk TLS (FR-058 to FR-060)

- **Decision** (`App\Services\MailSettings\MailSettingsService`):
  - Tagged-mail handling: `RCPT_WANTS_SUBJECT_TAG` / `RCPT_WANTS_SUBFOLDER_TAG` hashes on the
    `mailcow` Redis connection (subject wins when both are set, as legacy `get`), read and written
    through 003's `TaggedMailHandlingStore` on 002's `SharedStore`. Writes follow
    `mailbox('edit', 'delimiter_action')`: `HSET` the chosen hash and `HDEL` the other, or `HDEL`
    both for `none`, in one `MULTI`.
  - TLS: `UPDATE mailbox SET attributes = JSON_SET(attributes, '$.tls_enforce_out', ?), attributes =
    JSON_SET(attributes, '$.tls_enforce_in', ?) WHERE username = ?` with integers 0/1, as legacy.
    Reads treat `1`, `"1"` and `true` as on.
  - Atomicity (FR-072): the database update and the audit record run in one transaction; the Redis
    write happens inside it, last. A Redis failure throws and rolls the transaction back (`503
    temporary-failure`, `dependency: sharedStore`); a commit failure after the Redis write restores
    the previous Redis value.
  - A value equal to the current one is not a change and needs no flag (FR-006).
  - Bulk TLS (`POST /mailboxes/mail-settings/bulk-actions` with `action: setTlsEnforcement`, `docs/conventions.md`'s bulk path and body, run by the shared `BulkActionRunner`): 003's bulk pattern (`targets` of `BulkTarget`, at
    most 100, duplicates once, results in request order). Refused as a whole (`403 forbidden`) for
    mailbox users and (`insufficient-key-scope`) read-only keys. Each target: locate (`notFound`),
    version check against its mail-settings tag (`outdated`), update, one audit record carrying
    `bulkRequestId`. Domain administrators hold `tls_policy` implicitly (FR-006).

### R16. Recent logins and the country lookup (FR-061 to FR-063)

- **Decision** (`App\Services\RecentLogins\RecentLoginService`):
  - Query (legacy `last_login('get')`), paginated after grouping:
    `SELECT real_rip, MAX(datetime) AS datetime, service, app_password, MAX(app_passwd.name) AS
    app_password_name FROM sasl_log LEFT OUTER JOIN app_passwd ON sasl_log.app_password =
    app_passwd.id WHERE username = ? AND HOUR(TIMEDIFF(NOW(), datetime)) < ? GROUP BY real_rip,
    service, app_password`, with `days * 24`, optional `service` filter (compared upper-case, `WEBUI`
    matching `MAILCOWUI` and `WEBUI`), `q` on `real_rip`, and the requested order.
  - Service codes: stored value upper-cased; `MAILCOWUI` and `WEBUI` are both reported as `WEBUI`.
  - `internal` = the address fails `FILTER_VALIDATE_IP` with `FILTER_FLAG_NO_PRIV_RANGE |
    FILTER_FLAG_NO_RES_RANGE`; such entries never get a country.
  - Countries: `HGET IP_SHORTCOUNTRY <ip>` (mailcow Redis). For uncached public addresses of the
    current page, and only when `RESTV2_COUNTRY_LOOKUP` is on (default), `App\Clients\Geo\
    CountryLookup` (interface; HTTP adapter `GET https://dfdata.bella.network/country/<ip>`,
    `User-Agent: Moocow`, legacy's service) runs the lookups concurrently (`Http::pool`) within an
    overall budget of 1,500 ms (`RESTV2_COUNTRY_LOOKUP_BUDGET_MS`); every answer with a
    `shortcountry` is written back with `HSET IP_SHORTCOUNTRY`, as legacy does. Unfinished lookups
    are reported without a country (SC-010: 2 s).
  - Clear (FR-063): `DELETE FROM sasl_log WHERE username = ?` and, for administrators and the user
    themself, legacy's statement verbatim: `DELETE FROM logs WHERE JSON_EXTRACT(call, "$[0]") =
    "check_login" AND JSON_EXTRACT(call, "$[1]") = :username AND type = "success"`, so both UIs
    remove exactly the same rows.

### R17. Groupware resets (FR-064 to FR-066)

- **Decision**: Refused with `403 groupware-disabled` when `SKIP_SOGO=y`. Otherwise, in one
  transaction with the audit record:
  - ActiveSync cache (`easReset`): `DELETE FROM sogo_cache_folder WHERE c_uid = ?`;
  - groupware profile (`sogoProfileReset`): `DELETE FROM sogo_user_profile WHERE c_uid = ?`; `DELETE
    FROM sogo_cache_folder WHERE c_uid = ?`; `DELETE FROM sogo_acl WHERE c_object LIKE ? OR c_uid =
    ?` (`'%/' . addcslashes($mailbox, '\\%_') . '/%'`); `DELETE FROM sogo_store`,
    `sogo_quick_contact` and `sogo_quick_appointment` `WHERE c_folder_id IN (SELECT c_folder_id FROM
    sogo_folder_info WHERE c_path2 = ?)`; `DELETE FROM sogo_folder_info WHERE c_path2 = ?`
    (legacy order).
- **Rationale**: Exact port of `mailbox('delete', 'eas_cache' | 'sogo_profile')`; the tables are in
  the schema fixture.

---

## 8. Own mailbox views

### R18. Overview, client configuration and Apple profile (FR-069, FR-082, FR-083)

- **Decision**:
  - **Overview** (`App\Services\Account\MailboxOverviewService`): 003's mailbox read and usage;
    aliases per `user_get_alias_details` (direct: `goto = mailbox`; shared: `goto REGEXP
    '(^|,)<quoted>($|,)'` and `goto <> mailbox`; both excluding `@…` and the mailbox itself; alias
    domains: `local_part@alias_domain` for every alias domain of the domain); send-as from
    `sender_acl` (`logged_in_as = mailbox`: `*` → `anyAddress`, `@domain` entries plus their alias
    domains → `domains`, the rest → `addresses`); catch-alls: `alias.address LIKE '@%'` whose `goto`
    contains the mailbox; counts of active app passwords and unexpired temporary aliases;
    `pushover.active`. Texts decoded to plain text.
  - **Autodiscover settings**: `MailcowConfig` exports `$autodiscover_config` after legacy's merge
    with `vars.local.inc.php` (R23). Legacy computes `$https_port` from the request's `Host`; the
    export runs with `HTTP_HOST = <MAILCOW_HOSTNAME>:__RESTV2_HTTPS_PORT__`, and the API replaces the
    placeholder with the request's port (443 when none). IMAP, POP3 and SMTP ports come from
    `IMAPS_PORT`, `IMAP_PORT`, `POPS_PORT`, `POP_PORT`, `SMTPS_PORT` and `SUBMISSION_PORT` exactly as
    `vars.inc.php` parses them (an `ip:port` value keeps the port). A port of 0 is reported as `null`.
  - **Client configuration** (FR-083): the settings above per protocol, CalDAV/CardDAV with
    principal path `/SOGo/dav/<address>/` and ActiveSync (`activesync.url`) only when the groupware is
    enabled, `autodiscoverType`, `useEASforOutlook == 'yes'`, and the legacy documentation link
    `https://mailcow.github.io/mailcow-dockerized-docs/client/client/#host=…&email=…&name=…&ui=…&port=…`
    plus `&outlookEAS=1` when applicable, each value `urlencode`d as `user.php` does. The
    Thunderbird connector version (read by legacy from a file in `data/web`) is not available to
    the API container and is left out.
  - **Apple profile** (FR-082, `App\Services\Account\AppleProfileBuilder`): the plist of legacy
    `mobileconfig.php` key for key, in the same order, built with `XMLWriter` (every value escaped),
    `Content-Type: application/x-apple-aspen-config`, `Content-Disposition: attachment;
    filename="<product name>.mobileconfig"`, `Cache-Control: no-store`. Identifier =
    reversed address parts + `.appleprofile.` + the product name without non-alphanumerics; payload
    UUIDs are random v4 UUIDs in upper case (legacy uses an MD5-based GUID). The CalDAV port stays a
    `<real>` and the CardDAV port an `<integer>`, as legacy writes them. Not signed.
  - **Embedded app password**: `POST /me/mailbox/apple-profile` creates the app password through
    `AppPasswordService` (flag `appPasswds`, audit `appPasswordCreation` with `source:
    appleProfile`), name = request name or legacy's device name (`iPad`, `iPhone`, `Mac` by
    substring of the user agent, otherwise the user agent cut to 255), protocols IMAP and SMTP plus
    DAV for the full variant, and embeds the generated password. `201`, `Location` of the new app
    password.
  - The full variant is refused with `groupware-disabled` when `SKIP_SOGO=y` (spec deviation).

### R19. Self-service capabilities (FR-080)

- **Decision**: `GET /capabilities/self-service` (`docs/conventions.md`: capabilities reads live under `/capabilities/{area}`) (`restv2.auth:public`, `restv2.pending:allow`).
  `passwordReset` is always present. The remaining members are present for authenticated callers
  (session or key). `account` is present for sessions only: `role`, `secondFactorRequired`
  (`force_tfa`), `passwordChangeAvailable` (false for external-source mailboxes) and
  `secondFactorManagementAvailable`. Values are computed per request from `MailcowConfig`, Redis
  (`PW_RESET_FROM`, `PW_RESET_SUBJ`) and the account rows; nothing is cached by time.

---

## 9. Cross-cutting

### R20. Version tags (FR-070, FR-071)

- **Decision**: 001's `ETag` (strong, quoted base64url SHA-256) over the stored settings only.
  Stored secrets enter the input only as `hmac(<value>)`, an HMAC-SHA256 keyed with the app key
  (`docs/conventions.md`: a version tag never contains a plain hash of a stored secret):
  - app password: `id|name|mailbox|imap|pop3|smtp|sieve|eas|dav|active|hmac(password)`;
  - temporary alias: `address|goto|description|validity|permanent`;
  - Sieve filter: `id|username|script_desc|script_name|filter_type|sha256(script_data)`;
  - push notifications: `username|hmac(key)|hmac(token)|title|text|senders|senders_regex|
    evaluate_x_prio|only_x_prio|sound|active`, or the fixed input `unconfigured`;
  - mail settings: `tagging|tls_in|tls_out` (normalised values);
  - recovery address: the stored `attributes.recovery_email` (empty string when unset).

  Every list entry carries its tag. `If-Match` is optional; a mismatch answers `412` (`about:blank`,
  001's `PreconditionFailed`), which is FR-071's "outdated" refusal.
- **Rationale**: `docs/conventions.md` (tags over stored settings, every list entry carries one, an
  HMAC for secrets); the keyed HMAC makes the tag change when a secret changes, and a published tag
  cannot be used to test guesses of the secret offline.

### R21. Problem types and status codes (FR-075)

- **Decision**: New types, documented in `docs/problems.md`:

  | Type | Status | Extension |
  |---|---|---|
  | `current-password-incorrect` | 403 | — |
  | `password-managed-externally` | 403 | — |
  | `password-reset-unavailable` | 403 | — |
  | `invalid-reset-token` | 400 | — |
  | `second-factor-enrolment-expired` | 400 | — |
  | `second-factor-enrolment-failed` | 400 | `attemptsRemaining` (TOTP) |
  | `last-second-factor` | 409 | — |
  | `second-factor-required-by-policy` | 403 | — |
  | `push-notifications-not-configured` | 409 | — |
  | `groupware-disabled` | 403 | — |

  Reused: `permission-flag-required` (403; `docs/conventions.md`; with `flag` and, as 006 and 009 report it, `requiredFlags`), `temporary-failure` (503, feature 002's type; this feature adds the extension
  `dependency` as 003 does: `sharedStore`, `mailStore`, `pushover`, `addressGeneration`),
  `temporary-verification-failure` (Yubico), `second-factor-required` (with `methods` for the reset),
  `second-factor-failed`, `second-factor-unavailable`, `too-many-requests`, `pending-action-required`,
  `session-required`, `insufficient-key-scope`, `forbidden`, `validation-failed`; "outdated version"
  is `412` `about:blank`.
- **Validation rule codes**: two are added, `preHashedPassword` (param `scheme`) and `sieveSyntax`
  (params `message` and, when the parser reports it, `line`; feature 013 already relies on it for
  global filters). Everything else reuses codes other features defined: password rules use 003's
  `tooShort` and `missingCharacterClass`; `invalidRegex` (008) for the sender pattern;
  `secretRequired` (012, `reason: noStoredSecret`) for missing Pushover credentials;
  `mutuallyExclusive` (013) for `password` with `generatePassword`; `notInList`, `outOfRange`,
  `tooLong` (`unit: bytes` for scripts, `stored: true` for HTML-encoded names), `tooMany` and
  `invalidFormat` with the formats `email`, `pushoverCredential`, `soundName` and `digits`.
- **Status 503** is already justified by 001's Complexity Tracking.

### R22. Audit (FR-073, FR-074)

- **Decision**: One record per affected object through `AuditLogger`, in the transaction of the
  change (failures in their own transaction, 001-R22).
  - **Target identifier**: for every mailbox object the `target.id` is the **mailbox address** and
    `target.name` is the object's label (app password name, temporary alias address, filter
    description); the object's own ID is in `details` (`appPasswordId`, `sieveFilterId`,
    `temporaryAlias`). For own-account targets of administrators and domain administrators it is the
    username; for reset requests the attempted address. With 002's `targetType` and `targetId`
    filters, `targetId=<mailbox>` lists every record of this feature for a mailbox (US11 AS-1), and
    it also matches 003's `mailbox` records.
  - **Event types** (appended to `AuditEventType`): `ownPasswordChange`, `ownSecondFactorEnrolment`,
    `ownSecondFactorRemoval`, `ownRecoveryEmailChange`, `passwordResetRequest`,
    `passwordResetCompletion`, `appPasswordCreation`, `appPasswordUpdate`, `appPasswordDeletion`,
    `temporaryAliasCreation`, `temporaryAliasUpdate`, `temporaryAliasDeletion`,
    `sieveFilterCreation`, `sieveFilterUpdate`, `sieveFilterDeletion`,
    `pushNotificationSettingsUpdate`, `pushNotificationSettingsDeletion`,
    `pushNotificationVerification`, `mailSettingsUpdate`, `recentLoginsClear`,
    `activeSyncCacheReset`, `groupwareProfileReset`, `webmailHandOff`.
  - **Target types** (appended to `AuditTarget.type`): `accountPassword`, `secondFactor`,
    `recoveryEmail`, `passwordReset`, `appPassword`, `temporaryAlias`, `sieveFilter`,
    `pushNotificationSettings`, `mailSettings`, `recentLogins`, `groupwareData`, `webmailHandOff`.
  - **Failure reasons**: existing values are reused where they fit: `externalIdentitySource`
    (password managed externally), `permissionDenied` (missing flag, with `details.flag`, as 008
    uses it), `versionOutdated`, `validationFailed`, `temporaryFailure`, `notFound`, `throttled`,
    `challengeExpired` (enrolment expired), `registrationFailed` (enrolment rejected),
    `secondFactorFailed`, `secondFactorUnavailable`, `verificationUnavailable`, `featureDisabled`
    (groupware disabled, reset unavailable). Appended to `AuditFailureReason`:
    `currentPasswordIncorrect`, `unknownAccount`, `accountNotEligible`, `noRecoveryEmail`,
    `tokenLimitReached`, `messageNotSent`, `invalidResetToken`, `lastSecondFactor`,
    `secondFactorRequiredByPolicy`, `notConfigured`.
  - **Details whitelist**: changed fields with old and new values; secrets only as `set` or
    `changed`; scripts and notification texts as `{changed: true, oldSize, newSize}`; `sessionsEnded`
    for password changes; `bulkRequestId`; `source` (`appleProfile`). Ceremony requests (TOTP start,
    WebAuthn options, reset WebAuthn challenge) and reads are not audited; a failed token check is a
    read and is not audited (its throttling still applies). A change sent with the current values
    is audited too (it "tries to" change state, FR-073), with no changed fields, as 002 does.
  - **Refusals** (`docs/conventions.md`): every state-changing route of this feature declares
    `->defaults('restv2.audit', ['event' => <event type>, 'target' => <target type>])`, and the
    shared `RefusalAuditor` records each `4xx` refusal of an authenticated caller, also those raised
    by 001's pipeline (read-only key, restricted session), with the reasons of 008's mapping
    (`permissionDenied` with `details.problemType`, `notFound`, `versionOutdated`,
    `validationFailed`, `conflict`) or this feature's specific ones (`currentPasswordIncorrect`,
    `lastSecondFactor`, `secondFactorRequiredByPolicy`, `externalIdentitySource`, `featureDisabled`,
    `challengeExpired`, `registrationFailed`, `notConfigured`, `invalidResetToken`). This feature
    registers resolvers for its target types: the mailbox address from the route, whatever the
    scope, as 008 does. CSRF failures and `401`s are never recorded. Services record the refusals
    they decide themselves exactly once (the auditor skips a request that already wrote a record).
    The anonymous password reset routes have no authenticated principal; their requests and
    completions are audited by `PasswordResetService` itself (FR-020, FR-073).
- **Rationale**: FR-073 and 001's audit model; the mailbox-address convention makes FR-074 work
  with the generic filters, without a new column.

### R23. Configuration

- **Decision**:
  - **Legacy variables** added to 001's export (`resources/bin/export-mailcow-vars.php`) and to
    `config('restv2.vars_overrides')` (`RESTV2_<NAME>` wins): `PW_RESET_TOKEN_LIFETIME` (15),
    `PW_RESET_TOKEN_LIMIT` (3), `WEBAUTHN_UV_FLAG_REGISTER` (`false`), `SESSION_NAME` (`MCSESSID`),
    `SESSION_SAMESITE_POLICY` (`Lax`), `autodiscover_config` (R18; `RESTV2_AUTODISCOVER_CONFIG` as
    JSON). `OTP_LABEL`, `SESSION_LIFETIME`, `FIDO2_FORMATS`, `WEBAUTHN_USER_PRESENT_FLAG` are already
    exported by 001.
  - **`mailcow.conf` names** passed to the container: `SKIP_SOGO`, `MAILCOW_PASS_SCHEME`,
    `MAILCOW_HOSTNAME`, `ADDITIONAL_SERVER_NAMES`, `IMAP_PORT`, `IMAPS_PORT`, `POP_PORT`, `POPS_PORT`,
    `SMTPS_PORT`, `SUBMISSION_PORT`.
  - **New server settings**:

    | Variable | Default | Meaning |
    |---|---|---|
    | `RESTV2_PW_RESET_PAGE` | `/ng/reset-password?token={token}` | Path of the reset page in the message link |
    | `RESTV2_PW_RESET_MAX_ATTEMPTS` | `10` | Reset request, check and completion limit per client address |
    | `RESTV2_PW_RESET_WINDOW` | `900` | Window of that limit in seconds |
    | `RESTV2_MAILCOW_TPLS_DIR` | `/mailcow/tpls` | Mount of mailcow's `data/assets/templates` |
    | `RESTV2_SMTP_HOST` / `RESTV2_SMTP_PORT` | `postfix-mailcow` / `25` | Mail server for reset messages |
    | `RESTV2_TOTP_ENROLMENT_TTL` | `600` | TOTP enrolment lifetime in seconds |
    | `RESTV2_TOTP_ENROLMENT_MAX_ATTEMPTS` | `5` | Wrong codes before an enrolment is cancelled |
    | `RESTV2_COUNTRY_LOOKUP` | `true` | External country lookup on or off |
    | `RESTV2_COUNTRY_LOOKUP_URL` | `https://dfdata.bella.network/country/` | Lookup service |
    | `RESTV2_COUNTRY_LOOKUP_BUDGET_MS` | `1500` | Time budget of the lookups of one page |
    | `RESTV2_PUSHOVER_VALIDATE_URL` | `https://api.pushover.net/1/users/validate.json` | Pushover validation endpoint |

    The WebAuthn options lifetime reuses `RESTV2_CHALLENGE_TTL` (300). The bulk limit and page
    sizes reuse the shared settings of 001 and 003.
  - **Deployment** (`deploy/docker-compose.override.yml`): the volume
    `./data/assets/templates:/mailcow/tpls:ro,z`, the variables above, and the ofelia label
    `ofelia.job-exec.restv2_webmail_handoff_sweep.schedule: "@every 1m"` with command `php
    /app/artisan restv2:webmail-handoffs:sweep`.

### R24. Composer dependencies (constitution VIII)

| Package | Licence | Scope | Justification |
|---|---|---|---|
| `bacon/bacon-qr-code` | BSD-2-Clause | runtime | QR code of the TOTP enrolment (FR-030); legacy's library; SVG output without extensions (R7) |

No package for: the Sieve parser (legacy copy, R13), SMTP (Laravel Mail), HTTP clients (Laravel
`Http`), the plist (`XMLWriter`), the PHP session encoding (`serialize()`), the FastCGI test harness
(`cgi-fcgi` from Alpine's `fcgi` package, dev image only).

### R25. Test strategy additions

- **Decision**:
  - Real MariaDB (schema fixture) and Redis, as 001. Fakes behind interfaces only for outbound
    services: `SieveScriptReader` (dockerapi), `PushoverClient`, `CountryLookup`, 001's
    `YubicoOtpVerifier`; the HTTP adapters are tested with `Http::fake()`. Reset mail uses Laravel's
    `array` mailer in tests (`Mail::fake()` is not used, so the real message building runs); a
    transport test runs against a throw-away SMTP sink only in the transport unit test.
  - `tests/Feature/Webmail/SogoAuthCompatibilityTest.php` runs the legacy `sogo-auth.php` (R3).
  - Parity tests compare stored rows with what the legacy functions write: the tests insert rows
    "as legacy" through `MailcowFactory` and compare after API calls (SC-009).
  - A timing test (`@group timing`) sends 200 reset requests for each class of address and compares
    medians (< 50 ms difference, SC-005).
  - Object-level authorisation matrices (admin, domain admin inside and outside scope, mailbox user
    own and foreign, read-only key, read-write key of an admin and of a domain admin, restricted
    session) are table-driven per operation.
