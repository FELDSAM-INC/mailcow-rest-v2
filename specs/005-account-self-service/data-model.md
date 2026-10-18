# Data Model: Account Self-Service

**Feature**: `specs/005-account-self-service` | **Date**: 2026-10-18 | **Plan**: [plan.md](./plan.md)

Storage follows feature 001's rules (`specs/001-auth-sessions-api-keys/data-model.md`): upstream
tables are never migrated and are written only through the services named here; API-owned Redis
keys use `restv2:`. **This feature adds no API-owned table and no migration.** Column definitions
are quoted from upstream `2026-09a` (`data/web/inc/init_db.inc.php`, `db_version
18082026_1200`). Upstream `DATETIME` columns hold mailcow's local time (`TZ`); 001's
`MailcowDateTime` cast converts them to UTC, and writes leave them to their defaults (`NOW()`).
The JSON shapes are in the contract (`api/components/schemas/`); this document maps them to storage.

---

## Upstream tables

Models live in `app/Models/Mailcow/` (`$connection = 'mysql'`, explicit `$table` and
`$primaryKey`, `$timestamps = false`). Models that earlier features created are reused (001's
`Admin`, `TfaEntry`, `SaslLogEntry`; 003's `Mailbox`, `SpamAlias`, `Pushover`, `SenderAcl`,
`QuotaUsage`, `QuotaReplicaUsage`; 002/004's `Alias`, `AliasDomain`); this feature adds
`AppPassword`, `SieveFilter` and `PasswordResetToken`. Writes go through the services named here.

**Concurrency** (`docs/conventions.md`, uniqueness that upstream enforces only in code): named
locks through `App\Support\Database\NamedLock` (`GET_LOCK(name, 5)` before the transaction,
`RELEASE_LOCK` in `finally`, a timeout answers `503 temporary-failure`):
`restv2_tfa_<sha1(username)>` around second-factor removals and enrolments,
`restv2_pwreset_<sha1(username)>` around the reset token count and insert,
`restv2_sieve_<sha1(mailbox)>` around Sieve filter activation. Temporary aliases lock the mailbox's
domain row (`SELECT … FROM domain WHERE domain = ? FOR UPDATE`), the lock 002–004 take before
creating any address in a domain.

### `mailbox` (003's `Mailbox`), read; written by `OwnPasswordService`, `PasswordResetService`, `RecoveryEmailService`, `MailSettingsService`

| Column | Use in 005 |
|---|---|
| `username` VARCHAR(255) PK | Mailbox address |
| `password` VARCHAR(255) | Current password verification; new hash (`MAILCOW_PASS_SCHEME`) on change and reset |
| `domain`, `local_part`, `name` | Overview, client configuration, Apple profile |
| `kind` | `location`, `thing`, `group` are resources: never a self-service mailbox |
| `authsource` ENUM('mailcow','keycloak','generic-oidc','ldap') | Only `mailcow` changes password, recovery address and second factors |
| `active` | Reset eligibility and token check (`active = 1`) |
| `quota` | Overview |
| `attributes` JSON | See below |

`attributes` keys used:

| Key | Read | Written |
|---|---|---|
| `force_pw_update` | pending action (001) | `'0'` on own change and on completed reset |
| `force_tfa` | removal refusal, `account.secondFactorRequired` | — |
| `passwd_update` | overview `passwordChangedAt` | `NOW()` on own change and reset |
| `recovery_email` | reset recipient, overview, `OwnRecoveryEmail` | `PUT /me/recovery-email` (`''` removes) |
| `tls_enforce_in`, `tls_enforce_out` | mail settings (`1`, `"1"`, `true` → on) | integers `0`/`1` |
| `imap_access`, `pop3_access`, `smtp_access`, `sieve_access`, `eas_access`, `dav_access` | overview protocol access (missing → on, 003 FR-011) | — |
| `sogo_access` | overview `webmail.redirectAfterSignIn` | — |

Writes:

- own change: `UPDATE mailbox SET password = ?, attributes = JSON_SET(attributes,
  '$.force_pw_update', '0'), attributes = JSON_SET(attributes, '$.passwd_update', NOW()) WHERE
  username = ? AND authsource = 'mailcow'`;
- reset: `UPDATE mailbox SET password = ?, attributes = JSON_SET(attributes, '$.passwd_update',
  NOW(), '$.force_pw_update', '0') WHERE username = ? AND authsource = 'mailcow'`;
- recovery address: `UPDATE mailbox SET attributes = JSON_SET(attributes, '$.recovery_email', ?)
  WHERE username = ? AND authsource = 'mailcow'`;
- TLS: `UPDATE mailbox SET attributes = JSON_SET(attributes, '$.tls_enforce_out', ?), attributes =
  JSON_SET(attributes, '$.tls_enforce_in', ?) WHERE username = ?`.

**Validation (recovery address)**: `address` "null, empty string (removes), or a string of at most
255 characters that passes `FILTER_VALIDATE_EMAIL`" (`invalidFormat`, `format: email`; `tooLong`,
`max: 255`). **Version tag**: the stored `recovery_email` (empty string when unset).

### `admin` (001's `Admin`), read; written by `OwnPasswordService`

`password` (verification and new hash), `attributes.force_pw_update` (`'0'` on change),
`attributes.force_tfa` (removal refusal). Write: `UPDATE admin SET password = ?, attributes =
JSON_SET(COALESCE(attributes, '{}'), '$.force_pw_update', '0') WHERE username = ?`.

### `tfa` (001's `TfaEntry`), written by `SecondFactorEnrolmentService` and `SecondFactorRemovalService`

| Column | Type | 005 use |
|---|---|---|
| `id` | INT PK AI | `OwnSecondFactor.id` |
| `key_id` | VARCHAR(255) NOT NULL | name (trimmed, 1–255) |
| `username` | VARCHAR(255) NOT NULL | the signed-in account |
| `authmech` | ENUM('yubi_otp','u2f','hotp','totp','webauthn') | `type` (`yubi_otp` → `yubiOtp`) |
| `secret` | VARCHAR(255) NULL | TOTP base32 secret; Yubico `<clientId>:<secretKey>:<modhex>`; never returned |
| `keyHandle` | VARCHAR(1023) NULL | WebAuthn base64 credential ID; never returned |
| `publicKey` | VARCHAR(4096) NULL | WebAuthn PEM; never returned |
| `counter` | INT NOT NULL DEFAULT 0 | `0` on insert |
| `certificate` | TEXT | WebAuthn attestation certificate; never returned |
| `active` | TINYINT(1) DEFAULT 0 | `'1'` on insert; `active` |

Inserts (all active):

- TOTP: `INSERT INTO tfa (username, key_id, authmech, secret, active) VALUES (?, ?, 'totp', ?, '1')`;
- WebAuthn: `INSERT INTO tfa (username, key_id, authmech, keyHandle, publicKey, certificate,
  counter, active) VALUES (?, ?, 'webauthn', ?, ?, ?, 0, '1')`;
- Yubico OTP: `DELETE FROM tfa WHERE username = ? AND authmech = 'yubi_otp' AND secret LIKE ?`
  (`'%' . <modhex>`), then `INSERT INTO tfa (key_id, username, authmech, active, secret) VALUES (?,
  ?, 'yubi_otp', '1', ?)`.

Deletes: one `DELETE FROM tfa WHERE username = ? AND id = ?`; all `DELETE FROM tfa WHERE username = ?`.
List query: `SELECT id, key_id, authmech, active, CASE WHEN authmech = 'yubi_otp' THEN
RIGHT(secret, 12) END AS yubico_public_id FROM tfa WHERE username = ?` (no secret column leaves the
query). `q` → `key_id LIKE ?`.

**Validation**: `name` "required, string, trimmed, 1–255 characters" (`required`, `tooShort`
`min: 1`, `tooLong` `max: 255`); `code` "required, `^[0-9]{6}$`" (`invalidFormat`, `format:
totpCode`); `otp` "`^[A-Za-z0-9]{44}$`" (`yubicoOtp`); `clientId` "`^[0-9]{1,32}$`" (`digits`);
`secretKey` "1–128 characters"; `currentPassword` "1–4096 characters, required unless
`secondFactorSetup` is pending".

### `app_passwd` (new model `AppPassword`), written by `AppPasswordService`

| Column | Type | Contract |
|---|---|---|
| `id` | INT PK AI | `id` |
| `name` | VARCHAR(255) NOT NULL | `name` (stored `htmlspecialchars`-encoded, returned decoded) |
| `mailbox` | VARCHAR(255) NOT NULL, FK `mailbox.username` | `mailbox` (never rewritten) |
| `domain` | VARCHAR(255) NOT NULL | `mailbox.domain` on insert |
| `password` | VARCHAR(255) NOT NULL | hash only; never selected for responses |
| `created` | DATETIME NOT NULL DEFAULT NOW() | `createdAt` |
| `modified` | DATETIME ON UPDATE | `modifiedAt` |
| `imap_access`, `smtp_access`, `dav_access`, `eas_access`, `pop3_access`, `sieve_access` | TINYINT(1) DEFAULT 1 | `protocols` (`imap`, `smtp`, `dav`, `activeSync`, `pop3`, `sieve`) |
| `active` | TINYINT(1) DEFAULT 1 | `active` |

**Validation**: `name` "required on create, trimmed, at least 1 character; its
`htmlspecialchars` form at most 255 characters" (`tooLong`, `max: 255`, `stored: true`);
`password` "optional, 1–4096 characters, meets the password policy (003's `tooShort`,
`missingCharacterClass`), no hash-scheme prefix (`preHashedPassword`)"; `generatePassword`
"`true` only, not together with `password`" (`mutuallyExclusive`, `with: password`); `protocols`
"array of unique values from `imap`, `pop3`, `smtp`, `sieve`, `activeSync`, `dav`" (`notInList`);
`active` boolean. Patch: at least one member (`required` on `body`).
**List**: sort `name` (default, asc), `createdAt`, `modifiedAt`, `active`; filter `active`;
`q` → `name LIKE ?` with `q` HTML-encoded first. **Version tag**:
`id|name|mailbox|imap|pop3|smtp|sieve|eas|dav|active|hmac(password)` (HMAC-SHA256 keyed with the app key; no plain hash of a secret).

### `spamalias` (003's `SpamAlias`), written by `TemporaryAliasService`

| Column | Type | Contract |
|---|---|---|
| `address` | VARCHAR(255) PK | `address` |
| `goto` | TEXT NOT NULL | `mailbox` |
| `description` | TEXT NOT NULL | `description` (`''` default) |
| `created` | DATETIME NOT NULL DEFAULT NOW() | `createdAt` |
| `modified` | DATETIME ON UPDATE | `modifiedAt` |
| `validity` | INT(11) | Unix time; `expiresAt` = `validity` unless permanent |
| `permanent` | TINYINT(1) NOT NULL DEFAULT 0 | `permanent` |

Visible rows: `goto = ? AND (validity >= UNIX_TIMESTAMP() OR permanent != 0)`.
Insert: `INSERT INTO spamalias (address, description, goto, validity, permanent) VALUES (?, ?, ?,
?, ?)` with `validity = now + lifetimeHours * 3600`. Change: lifetime → `validity = now + h * 3600,
permanent = 0`; permanence → `validity = 0, permanent = 1`. Delete: `DELETE FROM spamalias WHERE
address = ? AND goto = ?`.

**Validation**: `domain` "required, normalised (trim, lower case, IDN to ASCII), in the mailbox's
temporary-alias domains" (`notInList`, `allowed`); `description` "string, at most 255 characters"
(`tooLong`); `lifetimeHours` "integer 1–87,600, default 8,760" (`outOfRange`, `min: 1`, `max:
87600`); `permanent` boolean. Patch: exactly one of `lifetimeHours` or `permanent: true`
(`mutuallyExclusive`, or `required` on `body`).
**Address uniqueness**: not in `mailbox.username`, `alias.address`, `spamalias.address`, and for an
alias domain not the target-domain address with the same local part in `mailbox.username` or
`alias.address`; 10 attempts.
**List**: sort `createdAt` (default, desc), `address`, `expiresAt` (permanent last); filter
`permanent`; `q` → `address LIKE ? OR description LIKE ?`.
**Version tag**: `address|goto|description|validity|permanent`.

### `sieve_filters` (new model `SieveFilter`), written by `SieveFilterService`

| Column | Type | Contract |
|---|---|---|
| `id` | INT PK AI | `id` |
| `username` | VARCHAR(255) NOT NULL, FK `mailbox.username` ON DELETE CASCADE | `mailbox` |
| `script_desc` | VARCHAR(255) NOT NULL | `description` |
| `script_name` | ENUM('active','inactive') | `active` (`'active'` → true) |
| `script_data` | TEXT NOT NULL | `script` |
| `filter_type` | ENUM('postfilter','prefilter') | `kind` |
| `created` | DATETIME NOT NULL DEFAULT NOW() | `createdAt` |
| `modified` | DATETIME ON UPDATE | `modifiedAt` |

Dovecot reads the views `sieve_before` and `sieve_after` over this table (read only, never touched).
Activation in the same transaction: `UPDATE sieve_filters SET script_name = 'inactive' WHERE
username = ? AND filter_type = ? AND id <> ?`.

**Validation**: `description` "required on create, 1–255 characters" (`tooShort`, `tooLong`);
`kind` "`prefilter` or `postfilter`" (`notInList`); `script` "required on create, non-empty, at
most 65,535 bytes" (`tooLong`, `max: 65535`, `unit: bytes`) "and accepted by the legacy Sieve
parser" (`sieveSyntax`, `message`, `line`); `active` boolean, default `true`.
**Lists**: sort `mailbox` then `description` (default), `description`, `kind`, `active`,
`createdAt`, `modifiedAt`; filters `mailbox` (normalised), `kind`, `active`; `q` → `script_desc LIKE
? OR username LIKE ?`. The cross-mailbox list joins the caller's scope (`username` in the
mailboxes of the domain administrator's domains).
**Version tag**: `id|username|script_desc|script_name|filter_type|sha256(script_data)`.

### `pushover` (003's `Pushover`), written by `PushNotificationService`

| Column | Type | Contract |
|---|---|---|
| `username` | VARCHAR(255) PK | mailbox |
| `key` | VARCHAR(255) NOT NULL | user key; write-only (`userKeySet`) |
| `token` | VARCHAR(255) NOT NULL | application token; write-only (`tokenSet`) |
| `attributes` | JSON | `{"evaluate_x_prio": "0|1", "only_x_prio": "0|1", "sound": "<name>"}` |
| `title` | TEXT | `title` |
| `text` | TEXT | `text` |
| `senders` | TEXT | `senders` (comma-separated) |
| `senders_regex` | TEXT | `senderPattern` |
| `active` | TINYINT(1) NOT NULL DEFAULT 1 | `active` |

Write: `REPLACE INTO pushover (username, key, attributes, senders_regex, senders, token, title,
text, active) VALUES (…)`. Delete: `DELETE FROM pushover WHERE username = ?`.
`configured` = a row with non-empty `key` and `token`. The table has no timestamps.

**Validation**: `token`, `userKey` "`^[A-Za-z0-9]{30}$`" (`invalidFormat`, `format:
pushoverCredential`), "required while none is stored" (`secretRequired`, `reason:
noStoredSecret`); `title` "at most 250 characters", `text` "at most 1,024 characters" (`tooLong`);
`senders` "at most 100 items" (`tooMany`), "each a valid e-mail address" (`invalidFormat`,
`format: email`, on `senders.<i>`), stored with dots removed from `@gmail.com` local parts;
`senderPattern` "empty, or at most 1,024 characters and a compiling delimited PCRE" (`tooLong`,
`invalidRegex`); `sound` "`^[A-Za-z0-9_-]{1,20}$`, default `pushover`" (`invalidFormat`, `format:
soundName`); `active` default `true`; `evaluatePriority`, `onlyHighPriority` default `false`.
**Version tag**: `username|hmac(key)|hmac(token)|title|text|senders|senders_regex|evaluate_x_prio|only_x_prio|sound|active`
(HMAC-SHA256 keyed with the app key), or the fixed input `unconfigured`.

### `reset_password` (new model `PasswordResetToken`), written by `PasswordResetService`

| Column | Type | Use |
|---|---|---|
| `username` | VARCHAR(255) NOT NULL | mailbox |
| `token` | VARCHAR(255) NOT NULL, PK part | `XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX` (upper-case hex); never returned or logged |
| `created` | DATETIME NOT NULL DEFAULT NOW(), PK part | issue time; expiry = `created` + `PW_RESET_TOKEN_LIFETIME` minutes |

Statements: clean-up `DELETE FROM reset_password WHERE created < DATE_SUB(NOW(), INTERVAL ?
MINUTE)`; count `SELECT COUNT(*) FROM reset_password WHERE username = ?`; insert `INSERT INTO
reset_password (username, token) VALUES (?, ?)`; check `SELECT t1.username, t1.created FROM
reset_password t1 JOIN mailbox t2 ON t1.username = t2.username WHERE t1.token = ? AND t1.created >
DATE_SUB(NOW(), INTERVAL ? MINUTE) AND t2.active = 1 AND t2.authsource = 'mailcow'`; completion
`DELETE FROM reset_password WHERE username = ?`; failed send `DELETE FROM reset_password WHERE
username = ? AND token = ?`.

**Validation**: `address` "required, at most 255 characters, `FILTER_VALIDATE_EMAIL` after
normalisation" (`invalidFormat`, `format: email`); `token` "required, 1–64 characters", stripped
of characters outside `[A-Za-z0-9-]` before the lookup; `newPassword` as the own password.

**State** of a token: `issued → used` (any completion of the account removes all its tokens) or
`issued → expired` (lifetime; removed at the next request of any account) or `issued → discarded`
(message not sent).

### `sasl_log` (001's `SaslLogEntry`), read by `RecentLoginService`; written by `RecentLoginRecorder` and `RecentLoginService`

| Column | Use |
|---|---|
| `service` VARCHAR(32) PK part | `service`, upper-cased; `MAILCOWUI` and `WEBUI` → `WEBUI` |
| `app_password` INT | joined to `app_passwd.id` for `appPassword` |
| `username` VARCHAR(255) PK part | mailbox |
| `real_rip` VARCHAR(64) PK part | `clientAddress`; `internal` when not a public address |
| `datetime` DATETIME DEFAULT NOW() | `lastSeenAt` (MAX per group) |

Writes: `REPLACE INTO sasl_log (service, app_password, username, real_rip) VALUES ('SSO', 0, ?, ?)`
for a webmail hand-off; `DELETE FROM sasl_log WHERE username = ?` when clearing.
**Validation**: `days` "integer 1–31, default 7" (`outOfRange`); `service` "at most 32 characters";
sort `lastSeenAt` (default, desc), `service`, `clientAddress`; `q` → `real_rip LIKE ?`.

### `logs`, written by `RecentLoginService` (clear only)

Legacy's statement, verbatim, for administrators and the mailbox user themself: `DELETE FROM logs
WHERE JSON_EXTRACT(call, "$[0]") = "check_login" AND JSON_EXTRACT(call, "$[1]") = :username AND
type = "success"`.

### `sogo_*` (groupware tables in the schema fixture), written by `GroupwareResetService`

| Table | ActiveSync reset | Profile reset |
|---|---|---|
| `sogo_cache_folder` | `DELETE … WHERE c_uid = ?` | same |
| `sogo_user_profile` | — | `DELETE … WHERE c_uid = ?` |
| `sogo_acl` | — | `DELETE … WHERE c_object LIKE ? OR c_uid = ?` (`'%/' . addcslashes(mailbox, '\\%_') . '/%'`) |
| `sogo_store`, `sogo_quick_contact`, `sogo_quick_appointment` | — | `DELETE … WHERE c_folder_id IN (SELECT c_folder_id FROM sogo_folder_info WHERE c_path2 = ?)` |
| `sogo_folder_info` | — | `DELETE … WHERE c_path2 = ?` (last) |

### `_sogo_static_view` (through 002's `SogoDirectory`)

Refreshed after an own mailbox password change (all mailboxes, as legacy's
`update_sogo_static_view()`) and after a reset (that mailbox), unless `SKIP_SOGO=y`, followed by
the memcached flush. 005 calls the shared service and never writes the view itself.

### Read only

| Table | Columns | Use |
|---|---|---|
| `alias` | `address`, `goto`, `public_comment` | overview aliases and catch-alls; temporary-alias collisions |
| `alias_domain` | `alias_domain`, `target_domain` | temporary-alias domains; overview alias-domain addresses and send-as domains |
| `sender_acl` | `logged_in_as`, `send_as` | overview send-as |
| `quota2` / `quota2replica` | `bytes`, `messages` | overview usage (003's `MailboxReader`, `MASTER`) |
| `domain_admins`, `domain` | scope | mailbox-in-scope lookup (001, 003) |
| `user_acl`, `da_acl` | flags | effective flags (001) |
| `fido2` | — | never touched by 005 (passkeys stay with 001) |

---

## Redis

### mailcow-owned keys (connection `mailcow`, database 0, no prefix)

| Key | Type | Access | Use |
|---|---|---|---|
| `RCPT_WANTS_SUBJECT_TAG` | hash `mailbox → 1` | read, write | tagged mail `subject` |
| `RCPT_WANTS_SUBFOLDER_TAG` | hash `mailbox → 1` | read, write | tagged mail `subfolder` |
| `IP_SHORTCOUNTRY` | hash `ip → CC` | read, write (lookup results) | recent-login countries |
| `PASSWD_POLICY` | hash | read (003's service) | password rules |
| `PW_RESET_FROM`, `PW_RESET_SUBJ`, `PW_RESET_HTML`, `PW_RESET_TEXT` | strings | read | reset message and availability |
| `PHPREDIS_SESSION:<id>` | string (PHP `php` serialiser) | create, delete | webmail hand-off legacy session (below) |

**Legacy hand-off session** `PHPREDIS_SESSION:<id>`: `<id>` = 32 lower-case hex characters;
written with `SET NX EX <SESSION_LIFETIME>`; value = PHP session encoding of exactly
`mailcow_cc_username` (string, the mailbox), `sogo-sso-user-allowed` (array with the mailbox),
`mailcow_cc_api_access` (`'ro'`), `SESS_REMOTE_UA` (string), `LAST_ACTIVITY` (int),
`CSRF` (`['TOKEN' => <64 hex>]`). Legacy's `sogo-auth.php` refreshes `LAST_ACTIVITY` and the TTL on
use.

### API-owned keys (connection `restv2`, prefix `restv2:`)

| Key | Value | TTL | Owner |
|---|---|---|---|
| `restv2:account-sessions:<accountKind>:<username>` | hash `handle → session ID` | `SESSION_LIFETIME`, refreshed on every session request | `SessionRegistry` |
| `restv2:webmail-handoff:<legacy id>` | hash `handle`, `owner`, `mailbox`, `createdAt` | none (removed by sign-out, `endAll` and the sweeper) | `WebmailHandOffService` |
| `restv2:session-handoffs:<handle>` | set of legacy IDs (at most one) | `SESSION_LIFETIME`, refreshed with the registry | `WebmailHandOffService` |
| `restv2:throttle:pwreset:request:<sha256(ip)>` (+ `:timer`) | attempts | `RESTV2_PW_RESET_WINDOW` | `PasswordResetService` |
| `restv2:throttle:pwreset:check:<sha256(ip)>` (+ `:timer`) | attempts | same | `PasswordResetService` |
| `restv2:throttle:pwreset:complete:<sha256(ip)>` (+ `:timer`) | attempts | same | `PasswordResetService` |
| `restv2:throttle:signin:<sha256(username\|ip)>` | 001's key, also hit by wrong current passwords and failed reset second factors | 001 | `SignInThrottle` |
| `restv2:totp:last:<tfa.id>` | 001's key, written for a newly enrolled TOTP entry | 120 s | `SecondFactorEnrolmentService` |

### Session data added to 001's session (Redis)

| Key | Present when | Content |
|---|---|---|
| `restv2.handle` | a principal exists | ULID, stable across `migrate()` |
| `restv2.principal.secondFactorId` | sign-in completed with a second factor | `tfa.id` (for `usedForCurrentSignIn`) |
| `restv2.totpEnrolment` | after `startOwnTotpEnrolment` | `secret`, `expiresAt` (600 s), `attemptsLeft` (5) |
| `restv2.secondFactorWebauthn` | after `createOwnSecondFactorWebAuthnOptions` | `challenge` (b64), `expiresAt` (300 s) |
| `restv2.passwordResetWebauthn` | after `createPasswordResetWebAuthnChallenge` (anonymous session) | `tokenHash` (sha256), `challenge`, `expiresAt` (300 s) |

**Enrolment state machine** (TOTP; WebAuthn is the same without attempts):

```
            start                    confirm ok
 (none) ───────────► open ─────────────────────────► (none) + tfa row
   ▲                  │ wrong code (attemptsLeft-1)
   │                  ├──────────────► open
   │ 5th wrong code   │
   ├──────────────────┤
   │ 10 min elapsed   │
   └──────────────────┘ (expired: confirm → second-factor-enrolment-expired)
   start again replaces an open enrolment
```

**Webmail hand-off lifecycle**:

```
 POST /me/webmail-handoff ──► active ──► ended
                               │  sign-out / session invalidated / endAll (FR-011, FR-025)  → immediate
                               │  API session idle-expired  → sweeper, within 60 s
                               │  legacy idle (LAST_ACTIVITY + SESSION_LIFETIME) → legacy destroys it
                               └─ new hand-off of the same session → previous one ended
```

---

## Outbound calls

| Target | Call | Client (fake in tests) |
|---|---|---|
| dockerapi (`https://dockerapi:443`) | `POST /containers/<dovecot-mailcow id>/exec` `{cmd: sieve, task: list, username}` and `{cmd: sieve, task: print, script_name, username}`; timeout `DOCKER_TIMEOUT` | `App\Clients\Dovecot\SieveScriptReader` → `DockerApiSieveScriptReader` over 002/003's `DockerApiClient` |
| `postfix-mailcow:25` | SMTP without authentication, opportunistic STARTTLS without verification, 10 s, `X-Mailer: MooMail` | Laravel mailer `restv2_postfix` (`array` mailer in tests) |
| Yubico (`RESTV2_YUBICO_VERIFY_URL`) | verify OTP with given credentials | 001's `YubicoOtpVerifier` |
| Pushover | `POST https://api.pushover.net/1/users/validate.json` (`token`, `user`), 10 s | `App\Clients\Pushover\PushoverClient` |
| Country lookup | `GET https://dfdata.bella.network/country/<ip>`, `User-Agent: Moocow`, pooled, 1,500 ms budget | `App\Clients\Geo\CountryLookup` |
| memcached | flush after static-view refresh | 002's `MemcachedFlusher` |

---

## Computed views (no storage)

- **MailboxOverview** (FR-069): `mailbox`, `quota2`/`quota2replica`, `alias`, `alias_domain`,
  `sender_acl`, `app_passwd` (active count), `spamalias` (visible count), `pushover.active`.
- **MailboxClientConfiguration** (FR-083) and the **Apple profile** (FR-082): `$autodiscover_config`
  from `MailcowConfig` (port placeholder replaced by the request's port), `mailbox.name`, product
  name (001).
- **SelfServiceCapabilities** (FR-080): `MailcowConfig`, `SKIP_SOGO`, `PW_RESET_FROM`/`PW_RESET_SUBJ`,
  `admin`/`mailbox` attributes of the session account.
- **Audit records** (001's `restv2_audit_events`): event types, target types and failure reasons of
  research R22; for mailbox objects `target_id` is the mailbox address, `target_name` the object's
  label, and `details` carries the object's ID.
