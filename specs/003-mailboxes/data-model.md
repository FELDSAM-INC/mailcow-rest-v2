# Data Model: Mailboxes

**Feature**: `specs/003-mailboxes` | **Date**: 2026-10-18 | **Plan**: [plan.md](./plan.md)

Feature 003 owns no table. It reads and writes upstream mailcow tables, which are never migrated
and are written only through `app/Services/Mailboxes/` and the feature-002 services it reuses
(constitution III and IV). It updates two API-owned tables of feature 001
(`restv2_preferences`, `restv2_audit_events`) and uses mailcow's Redis keys plus one API-owned
`restv2:` key family. Column names are those of upstream `init_db.inc.php` at `2026-09a`
(`db_version 18082026_1200`). The JSON shapes are in `api/components/schemas/`; this document maps
them to storage. Upstream `DATETIME` values are mailcow local time (`TZ`), read through 001's
`MailcowDateTime` cast and emitted as UTC.

---

## Upstream tables

Models live in `app/Models/Mailcow/` with `$connection = 'mysql'`, explicit `$table` and
`$primaryKey`, `$timestamps = false` and `$guarded = ['*']`; they are used for reads. Writes use
the query builder inside the named services.

### `mailbox` — read and written

Writers: `MailboxCreator`, `MailboxUpdater`, `MailboxDeleter`, `MailboxDataMover`. A mailbox is a
row with `kind = ''`; rows with another `kind` are resources and answer `404` here.

| Column | Type | Use | Contract (`Mailbox`) |
|---|---|---|---|
| `username` | VARCHAR(255) PK | lower-case address, ASCII domain | `address` |
| `password` | VARCHAR(255) NOT NULL | `{SCHEME}hash`, `''` for external sources; written by `MailboxPasswordHasher` only, never read into a response | — |
| `name` | VARCHAR(255) | display name | `displayName` |
| `quota` | BIGINT, bytes | 0 = unlimited | `quota` |
| `local_part` | VARCHAR(255) | | `localPart` |
| `domain` | VARCHAR(255) | ASCII | `domain`, `domainUnicode` (`idn_to_utf8`) |
| `attributes` | JSON | see below | several members |
| `custom_attributes` | JSON NOT NULL DEFAULT `{}` | object of strings | `customAttributes` |
| `kind` | VARCHAR(100) DEFAULT `''` | `''` only | — |
| `authsource` | ENUM(`mailcow`,`keycloak`,`generic-oidc`,`ldap`) | `NULL` read as `mailcow` | `authSource` (`generic-oidc` ↔ `genericOidc`) |
| `active` | TINYINT(1) | 1, 2 or 0 | `status`: `active`, `loginDisabled`, `inactive` |
| `created` | DATETIME | database default | `createdAt` |
| `modified` | DATETIME ON UPDATE | database | `modifiedAt` (`null` when never set) |
| `description`, `mailbox_path_prefix`, `multiple_bookings` | — | left to database defaults (resources only) | — |

**`attributes` keys** (all values are strings, as legacy writes them):

| Key | Values | Contract | Missing key reads as |
|---|---|---|---|
| `force_pw_update` | `"0"`/`"1"` | `forcePasswordChange` | `false` |
| `force_tfa` | `"0"`/`"1"` | `forceSecondFactorSetup` | `false` |
| `sogo_access` | `"0"`/`"1"` | `webmailRedirect` | `true` |
| `imap_access`, `pop3_access`, `smtp_access`, `sieve_access`, `eas_access`, `dav_access` | `"0"`/`"1"` | `protocolAccess.{imap,pop3,smtp,sieve,activeSync,dav}` | `true` |
| `tls_enforce_in`, `tls_enforce_out` | `"0"`/`"1"` | `tlsEnforceInbound`, `tlsEnforceOutbound` (read-only) | `false` |
| `quarantine_notification` | `never`, `hourly`, `daily`, `weekly` | `quarantineNotification` (read-only) | built-in default |
| `quarantine_category` | `reject`, `add_header`, `all` | `quarantineCategory`: `rejected`, `junk`, `all` (read-only) | built-in default |
| `relayhost` | relayhost id or `"0"` | `relayhostId` (`null` for `"0"`; read-only) | `null` |
| `passwd_update` | `YYYY-MM-DD HH:MM:SS` (local) or Unix seconds | `passwordChangedAt` | `null` |
| `recovery_email` | address or `""` | `recoveryEmail` (`null` for `""`) | `null` |
| `mailbox_format` | `maildir:` | — (written at creation from the defaults) | — |
| `attribute_hash` | `""` or a template hash (feature 012) | — (cleared on every change) | — |

**Creation** (`MailboxCreator`, one transaction with `SELECT … FROM domain WHERE domain = ? FOR
UPDATE`): `INSERT INTO mailbox (username, password, name, quota, local_part, domain, attributes,
authsource, active)` with every key above except `recovery_email`, then
`UPDATE mailbox SET attributes = JSON_SET(attributes, '$.passwd_update', NOW()) WHERE username = ?`.

**Change** (`MailboxUpdater`): `UPDATE mailbox SET active, name, quota, authsource, password,
attributes = JSON_SET(attributes, '$.<key>', ?, …, '$.attribute_hash', '')` for the changed values;
`custom_attributes = ?` when attributes change.

**Validation** (Form Requests and `MailboxValidator`; every violation is collected):

- `localPart`: "required string; after `strtolower(trim())` 1 to 64 characters matching
  `^[a-z0-9!#$%&'*+/=?^_`{|}~-]+(\.[a-z0-9!#$%&'*+/=?^_`{|}~-]+)*$`" (`required`, `tooLong`
  `{max: 64}`, `invalidFormat` `{format: localPart}`); the full address "valid for
  `FILTER_VALIDATE_EMAIL`, at most 255 characters" (`invalidFormat` `{format: email}`, `tooLong`).
- `domain`: "required string, at most 255; normalises with `DomainName::normalize()`; must be a
  `domain` row in the caller's scope" (`notFound`); "an `alias_domain` name" → `notEligible`
  `{kind: aliasDomain}`.
- `password`: "string, 1 to 255 characters; required for `mailcow` at creation; refused for an
  external source" (`required`, `tooLong`, `conflictsWith` `{field: authSource, value}`); "meets the
  effective policy unless pre-hashed" (`tooShort` `{min}`, `missingCharacterClass`
  `{characterClass}`).
- `displayName`: "string, at most 255 characters".
- `authSource`: "one of `mailcow` and the configured provider type" (`notInList` `{allowed}`).
- `quota`: "integer ≥ 0, multiple of 1048576" (`outOfRange` `{min: 0}`, `notMultipleOf`
  `{factor: 1048576}`); creation: "≤ `domain.maxquota` MiB" (`outOfRange` `{max,
  limit: maxMailboxQuota}`) and "allocated + quota ≤ `domain.quota`" (`outOfRange` `{max,
  limit: domainQuotaLeft}`); change: "≤ `largestAllowedQuota`" (`outOfRange` `{max,
  limit: largestAllowedQuota}`).
- `forcePasswordChange: true` with an external source, `forceSecondFactorSetup: true` with
  `genericOidc`: `conflictsWith`.
- `recoveryEmail`: "`null`, `""` or a valid address of at most 255 characters" (`invalidFormat`
  `{format: email}`); "only for `mailcow`" (`conflictsWith`).
- `tags`: `TagSet` rules (`required` on `tags.<i>`, `tooLong` `{max: 255}`, `tooMany`
  `{max: taggingLimit}`).
- `customAttributes`: "names `^\S{1,255}$`" (`invalidFormat` `{format: attributeName}`), "values
  strings of at most 4,096 characters or `null`" (`tooLong`, `invalidType`), "at most 100 after
  merging" (`tooMany` `{max}`).
- Members of `Mailbox` that are not in `MailboxPatch`: `immutable`.
- `templateId`: "integer ≥ 1, a `templates` row with `type = 'mailbox'`" (`notFound`).
- `rateLimit`: "`null` or `{value: integer 1…2147483647, frame: second|minute|hour|day}`".
- Creation-only members (`taggedMailHandling`, `quarantineNotification`, `quarantineCategory`,
  `tlsEnforceInbound`, `tlsEnforceOutbound`, `rateLimit`): their enumerations (`notInList`).

### `templates` (`type = 'mailbox'`) — read and written

Writer: `MailboxTemplateService`. Model `MailboxTemplate` with a global scope `type = 'mailbox'`.

| Column | Type | Contract (`MailboxTemplate`) |
|---|---|---|
| `id` | INT PK | `id` |
| `template` | VARCHAR(255) | `name` (`isDefault` = `template = 'Default'`) |
| `type` | VARCHAR(255) | always `mailbox` |
| `attributes` | JSON | values below |
| `created`, `modified` | DATETIME | `createdAt`, `modifiedAt` |

**`attributes` keys** (legacy writes integers for flags and bytes for the quota):

| Key | Contract |
|---|---|
| `quota` | `quota` (bytes) |
| `tags` | `tags` |
| `active` | `status` |
| `force_pw_update`, `force_tfa`, `sogo_access` | `forcePasswordChange`, `forceSecondFactorSetup`, `webmailRedirect` |
| `imap_access` … `dav_access` | `protocolAccess.*` |
| `acl_<user_acl column>` (15) | `userPermissions.*` |
| `tagged_mail_handler` | `taggedMailHandling` |
| `quarantine_notification`, `quarantine_category` | as for mailboxes |
| `tls_enforce_in`, `tls_enforce_out` | `tlsEnforceInbound`, `tlsEnforceOutbound` |
| `rl_value`, `rl_frame` | `rateLimit` (`null` when `rl_value` is empty) |

A key a stored template lacks is reported, and applied at creation, with the built-in default
(research R5). The API writes every key on creation and change, as integers like legacy, with
`rl_value: ""` and `rl_frame: "s"` for no rate limit.

**Validation**: `name` "trimmed, 1 to 255 characters, unique among mailbox templates compared with
`LOWER()`" (`required`, `tooLong`, `409 mailbox-template-name-exists`); values as for mailboxes.
Renaming or deleting the row named `Default` → `409 mailbox-template-protected`.

### `quota2` and `quota2replica` — written at creation, rename and deletion

`username` PK, `bytes` BIGINT, `messages` BIGINT. Creation inserts both with `0, 0` (`ON DUPLICATE
KEY UPDATE`). Reads use `quota2` when `MASTER=y`, else `quota2replica` (`quotaUsed`,
`messageCount`, `percentUsed`).

### `alias` — written

The own alias (`address = goto = username`, `domain`, `active` = mailbox status) is inserted at
creation, takes the mailbox status on change, is renamed with the mailbox, and is deleted with it.
Deletion also deletes every alias with `goto = username` and removes the address from other
`goto` lists. Rename replaces the address in every `goto` list and may insert the
"renamed from" alias (`private_comment`, `sogo_visible = 1`, `internal = 0`,
`sender_allowed = 0`, `active = 1`). Read for address availability and the alias limit.

### `user_acl` — written

`username` PK and the fifteen TINYINT(1) columns `spam_alias`, `tls_policy`, `spam_score`,
`spam_policy`, `delimiter_action`, `syncjobs`, `eas_reset`, `sogo_profile_reset`, `pushover`,
`quarantine`, `quarantine_attachments`, `quarantine_notification`, `quarantine_category`,
`app_passwds`, `pw_reset` (contract `userPermissions`, camelCase). Inserted with all columns at
creation; changed per column; renamed; deleted (also by the foreign key's cascade).

### `tags_mailbox` — written

`tag_name` VARCHAR(255), `username` (FK → `mailbox.username`, `ON DELETE CASCADE`, `ON UPDATE NO
ACTION`), unique `(tag_name, username)` under the case-insensitive collation. Replaced as a whole
on change; renamed with `FOREIGN_KEY_CHECKS = 0` as legacy.

### Read-only upstream tables

| Table | Columns | Use |
|---|---|---|
| `domain` | `domain`, `mailboxes`, `maxquota`, `quota`, `defquota` (MiB), `backupmx`, `aliases`, `active` | limits, quota defaults, `domainRelayed`, alias limit; row lock at creation and on quota changes |
| `domain_admins` | `username`, `domain`, `active` | domain-administrator scope (001) |
| `da_acl` | the nineteen flags | effective flags (001); never written here |
| `alias_domain` | `alias_domain`, `target_domain` | alias-domain refusal; alias count |
| `identity_provider` | `key = 'authsource'`, `value` | `identityProviderType`, allowed `authSource` |
| `spamalias` | `address`, `goto`, `validity`, `permanent` | address availability, `activeTemporaryAliases` |
| `pushover` | `username`, `active` | `pushNotificationsActive` |
| `sasl_log` | `username`, `service`, `datetime` | `lastLogins`, `lastMailLogin` sort |

### Tables touched by deletion and rename

| Table | Matched by | Deletion (legacy order, research R15) | Rename (`MailboxDataMover`) | Preview count |
|---|---|---|---|---|
| `alias` | `goto = u` / `goto` list | delete exclusive; rewrite shared | update; replace in lists | `exclusiveAliases`, `sharedAliases` |
| `pushover` | `username` | delete | update | `present.pushNotificationSettings` |
| `quarantine` | `rcpt` | delete | update (carry-over) | `quarantineItems` |
| `quota2`, `quota2replica` | `username` | delete | update | — |
| `mailbox` | `username` | delete | update (`username`, `local_part`) | — |
| `sender_acl` | `logged_in_as`, `send_as` | delete both | update both | `senderPermissionsHeld`, `senderPermissionsGranted` |
| `user_acl` | `username` | delete | update | — |
| `spamalias` | `goto` | delete | update (carry-over) | `temporaryAliases` |
| `imapsync` | `user2` | delete | update | `syncJobs` |
| `filterconf` | `object` | delete | update (carry-over) | `spamFilterSettings` |
| `sogo_user_profile`, `sogo_cache_folder` | `c_uid` | delete | `sogo-tool rename-user` | `present.webmailData` |
| `sogo_acl` | `c_object LIKE '%/u/%'` or `c_uid` | delete | `sogo-tool rename-user` | `present.webmailData` |
| `sogo_store`, `sogo_quick_contact`, `sogo_quick_appointment` | folders of `sogo_folder_info.c_path2 = u` | delete | `sogo-tool rename-user` | `present.webmailData` |
| `sogo_folder_info` | `c_path2` | delete | `sogo-tool rename-user` | `present.webmailData` |
| `bcc_maps` | `local_dest` (rename also `bcc_dest`) | delete | update | `bccMaps` |
| `recipient_maps` | `old_dest`, `new_dest` | — | update | — |
| `oauth_access_tokens`, `oauth_refresh_tokens`, `oauth_authorization_codes` | `user_id` | delete | delete (revoked) | `oauthGrants` |
| `tfa` | `username` | delete | update (carry-over) | `secondFactors` |
| `fido2` | `username` | delete | update (carry-over) | `passkeys` |
| `tags_mailbox`, `sieve_filters`, `app_passwd` | `username` / `mailbox` | FK cascade | update | `tags`, `sieveFilters`, `appPasswords` |
| `sasl_log` | `username` | delete (beyond legacy) | update (carry-over) | `recentLogins` |
| `_sogo_static_view` | `c_uid` | `SogoDirectory::refresh()` | update `c_uid`, `c_name`, `mail`, then refresh | — |
| `da_acl` | `username` | — | not moved (spec deviation) | — |

### `_sogo_static_view` — written through `SogoDirectory` (feature 002)

`refresh(array $usernames)` upserts each active mailbox with legacy's `INSERT … SELECT …
mailbox LEFT OUTER JOIN grouped_mail_aliases … grouped_domain_alias_address …
grouped_sender_acl_external … WHERE mailbox.active = '1' AND mailbox.username = ? ON DUPLICATE
KEY UPDATE …` statement, deletes the rows of the others, and runs legacy's orphan cleanup. Nothing
when `SKIP_SOGO=y`.

---

## API-owned tables (feature 001)

- `restv2_preferences`: rows with `account_kind = 'mailbox'` and `username = <address>` are
  renamed with the mailbox and deleted with it. No schema change.
- `restv2_audit_events`: target types `mailbox` (`target_id` = address, `target_name` = display
  name) and `mailboxTemplate` (`target_id` = template id, `target_name` = name). Event types and
  `details` keys are listed in research R22. 002's index `(target_type, target_id, occurred_at)`
  serves FR-057. No schema change.

---

## Redis

### mailcow-owned keys (connection `mailcow`, database 0, no prefix)

| Key | Type | Field → value | Read | Written |
|---|---|---|---|---|
| `PASSWD_POLICY` | hash | `length`, `chars`, `special_chars`, `lowerupper`, `numbers` → digits | `PasswordPolicyReader` | never (013) |
| `RL_VALUE` | hash | `<address>` or `<domain>` → `"<value> / 1<s\|m\|h\|d>"` | representation | creation (`RateLimitStore`), rename (moved), deletion (`HDEL`) |
| `RCPT_WANTS_SUBJECT_TAG` | hash | `<address>` → `1` | representation | creation (`TaggedMailHandlingStore`), rename, deletion |
| `RCPT_WANTS_SUBFOLDER_TAG` | hash | `<address>` → `1` | representation | creation, rename, deletion |
| `MC_CHANNEL` | pub/sub channel | `{"api_call":"container_post","container_name","post_action":"exec","request"}` | — | published in cluster mode (R16) |

### API-owned keys (connection `restv2`, prefix `restv2:`)

| Key | Value | TTL | Owner |
|---|---|---|---|
| `restv2:lock:mailbox:<address>` | Laravel atomic lock owner token | `4 × DOCKER_TIMEOUT` | `MailboxDeleter`, `MailboxRenamer` (old and new address) |

---

## Container and cache calls

All go through 002's `DockerApiClient` (faked in tests) to `https://dockerapi:443`, container
found by its compose labels, `POST /containers/<id>/exec` with the JSON command; answer
`{"type": "success"|"danger", "msg"}`; timeout `DOCKER_TIMEOUT`.

| Service | Command | Used by | Result handling |
|---|---|---|---|
| `dovecot-mailcow` | `{cmd: maildir, task: cleanup, maildir: "<domain>/<local>"}` | deletion | success (also when no maildir existed); failure → `503`; timeout → `504` |
| `dovecot-mailcow` | `{cmd: maildir, task: move, old_maildir, new_maildir}` | rename step 4 and its undo | failure → undo; timeout → `504` |
| `dovecot-mailcow` | `{cmd: doveadm, task: get_acl, id: <address>}` | rename step 1 | JSON list `{user, id, mailbox, rights[]}` |
| `dovecot-mailcow` | `{cmd: doveadm, task: delete_acl, user, mailbox, id}` | rename step 1, undo of step 6 | per entry |
| `dovecot-mailcow` | `{cmd: doveadm, task: set_acl, user, mailbox, id, rights}` | rename step 6, undo of step 1 | per entry |
| `sogo-mailcow` | `{cmd: sogo, task: rename_user, old_username, new_username}` | rename step 5 and its undo | skipped with `SKIP_SOGO=y` |
| (lookup only) | `GET /containers/json` | preflight, preview blockers | `reachable()` |

In cluster mode (`CLUSTERMODE=replication`) maildir commands are also published to `MC_CHANNEL`.
memcached: `flush_all` over TCP (`MemcachedFlusher`, 002) after every committed change, deletion
and rename; failures are logged.

---

## Version tags

| Resource | Inputs (canonical JSON, keys sorted) | Excludes |
|---|---|---|
| Mailbox | `username`, `name`, `quota`, `active`, `authsource`, decoded `attributes`, decoded `custom_attributes`, the 15 `user_acl` columns, tags sorted by `mb_strtolower` | `modified`, usage, last logins, Redis values, counts |
| Mailbox template | `id`, `template`, decoded `attributes` | `created`, `modified` |
| Password policy | `length=<n>;chars=<0\|1>;special_chars=<0\|1>;lowerupper=<0\|1>;numbers=<0\|1>` (effective values) | — |

All are 001's `ETag::strong()` (quoted base64url SHA-256), returned in `ETag` and as `etag`.

---

## State transitions

**Mailbox status** (any to any through `updateMailbox` or the bulk actions; the own alias follows):

```
            activate                 disableLogin
 inactive ───────────► active ◄──────────────────► loginDisabled
 (0)      ◄─────────── (1)                         (2)
            deactivate   ▲  deactivate / activate      │
                         └──────────────────────────────┘
 Only 1 and 2 receive mail; only 1 signs in and is in _sogo_static_view;
 only 1 and 2 can be renamed.
```

**Rename steps** (research R17):

```
 preflight ─► 1 ACL removal ─► 2 Redis move ─► 3 database ─► 4 maildir move ─► 5 SOGo ─► 6 ACL set ─► audit ─► 200
                 │ fail            │ fail          │ fail          │ fail           │ fail      │ fail
                 └───────────── undo done steps in reverse order ────────────────────────────────┘
                                   │ all undone ─► 503 temporary-failure (steps)
                                   │ an undo fails ─► 500 mailbox-rename-incomplete (steps)
 any step times out ─► undo the done steps ─► 504 outcome-unknown (step)
```

**Deletion**:

```
 preflight (scope, lock, If-Match, Redis ping) ─► maildir cleanup ─┬─ failed/unreachable ─► 503, nothing removed
                                                                   ├─ timeout ─► 504, nothing removed (retry safe)
                                                                   └─ success ─► transaction + Redis MULTI ─► 204
                                                                                   └─ failure ─► 500 (mail in _garbage)
```

---

## Value objects (no storage)

- `App\Services\Mailboxes\MailboxAddress`: `localPart`, `domain` (ASCII), `domainUnicode`,
  `address`; `fromInput(string)`, `fromParts(localPart, domain)`.
- `App\Services\Mailboxes\ResolvedMailboxValues`: every creation value with its source (`request`,
  `template`, `defaultTemplate`, `builtIn`), used by `MailboxPermissionGuard`.
- `App\Services\Passwords\EffectivePasswordPolicy`: `minLength`, the four booleans, `etag()`.
- `App\Services\Mailboxes\RenameStep` enum and `RenameStepOutcome` enum (contract
  `MailboxRenameStep`).
- `App\Services\Mailboxes\DeletionPlan`: counts, address samples, presence flags and blockers.
