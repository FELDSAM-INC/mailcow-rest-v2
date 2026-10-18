# Data Model: Authentication, Sessions, API Keys, Identity and Branding Configuration

**Feature**: `specs/001-auth-sessions-api-keys` | **Date**: 2026-10-18 | **Plan**: [plan.md](./plan.md)

There are three kinds of storage. Upstream mailcow tables are an external contract: they are never
migrated, and writes to them go only through the services named below (constitution III and IV).
API-owned tables use the `restv2_` prefix and Laravel migrations. API-owned Redis keys use the
`restv2:` prefix. The JSON shapes are defined in the contract (`api/components/schemas/`); this
document maps them to storage. Column definitions of upstream tables are quoted from the schema
fixture of upstream `2026-09a` (`db_version 18082026_1200`).

Upstream `DATETIME` columns hold mailcow's local time (`TZ`). The cast
`App\Casts\MailcowDateTime` reads them in `TZ` and emits UTC. Writes leave such columns to their
database defaults (`NOW()`), so they match legacy. API-owned tables store UTC.

---

## Upstream tables (existing mailcow data)

All models live in `app/Models/Mailcow/`, have `$connection = 'mysql'`, an explicit `$table` and
`$primaryKey`, `public $timestamps = false` (mailcow maintains `created` and `modified` itself) and
`$guarded = ['*']`, except where a service writes, as noted.

### Admin (`admin`), read only

Accounts of the roles `admin` (`superadmin = 1`) and `domainAdmin` (`superadmin = 0`).

| Column | Type | Use |
|---|---|---|
| `username` | VARCHAR(255) PK | Login name, compared after trim and lower-case |
| `password` | VARCHAR(255) | `{SCHEME}hash`, verified by `LegacyPasswordVerifier` and never written |
| `superadmin` | TINYINT(1) | 1 → `admin`, 0 → `domainAdmin` |
| `active` | TINYINT(1) | Only `1` may sign in or own a usable key |
| `attributes` | JSON | `force_pw_update` (`"1"` → `passwordChange`), `force_tfa` (`"1"` → candidate for `secondFactorSetup`) |
| `created`, `modified` | DATETIME | Not exposed |

Model `Admin`: string key `username`, `$keyType = 'string'`, `$incrementing = false`, cast
`attributes` → array.

**Possible key owners (FR-057)**: `SELECT username, superadmin FROM admin WHERE active = '1'`, plus
`AND username LIKE ?` for `q` (with `%` and `_` escaped) and `AND superadmin = ?` for `role`
(`admin` → 1, `domainAdmin` → 0), `ORDER BY username`, paged. Contract `ApiKeyOwnerCandidate`:
`username`, `role`.

### DomainAdminAssignment (`domain_admins`), read only

| Column | Type | Use |
|---|---|---|
| `id` | INT PK | — |
| `username` | VARCHAR(255) | Domain administrator |
| `domain` | VARCHAR(255) | Managed domain |
| `active` | TINYINT(1) | Only `1` counts towards the scope |

Scope query (legacy `mailbox('get', 'domains')`): `SELECT domain FROM domain WHERE domain IN
(SELECT domain FROM domain_admins WHERE active = '1' AND username = ?) ORDER BY domain`.

### Domain (`domain`), read only

Columns used: `domain` (PK) and `active` (a mailbox user signs in only when `1`).

### Mailbox (`mailbox`), read only

| Column | Type | Use |
|---|---|---|
| `username` | VARCHAR(255) PK | Mailbox address |
| `password` | VARCHAR(255) | `{SCHEME}hash` |
| `domain` | VARCHAR(255) | Joined to `domain.domain` |
| `kind` | VARCHAR(100) | Resource mailboxes match `location|thing|group` and never sign in |
| `authsource` | ENUM('mailcow','keycloak','generic-oidc','ldap') | Anything other than `mailcow` → password sign-in refused (`externalIdentitySource`); passkeys still work |
| `active` | TINYINT(1) | Only `1` may sign in |
| `attributes` | JSON | `force_pw_update`, `force_tfa` |

Password sign-in query (legacy `user_login`): `SELECT mailbox.*, domain.active AS d_active FROM
mailbox INNER JOIN domain ON mailbox.domain = domain.domain WHERE kind NOT REGEXP
'location|thing|group' AND username = ?`.

### DaAcl (`da_acl`) and UserAcl (`user_acl`), read only

These rows give the permission flags. Every column except `username` is TINYINT(1). Effective
flags follow legacy `acl('to_session')`; see [research R20](./research.md#r20-identity-scope-and-permission-flags).
The flags are the 29 cases of the enum `App\Auth\PermissionFlag`, mapped column → contract name by
snake_case → camelCase:

- `user_acl`: `spam_alias`, `tls_policy`, `spam_score`, `spam_policy`, `delimiter_action`,
  `syncjobs`, `eas_reset`, `sogo_profile_reset`, `pushover`, `quarantine`,
  `quarantine_attachments`, `quarantine_notification`, `quarantine_category`, `app_passwds`,
  `pw_reset`.
- `da_acl`: `syncjobs`, `quarantine`, `login_as`, `sogo_access`, `app_passwds`, `bcc_maps`,
  `pushover`, `filters`, `ratelimit`, `spam_policy`, `extend_sender_acl`, `unlimited_quota`,
  `protocol_access`, `smtp_ip_access`, `alias_domains`, `mailbox_relayhost`, `domain_relayhost`,
  `domain_desc`, `alias_external_goto`.

### Fido2Credential (`fido2`): passkeys, written by `App\Services\Passkeys\PasskeyService` only

The table has **no primary key**. A row is identified by `username` plus
`SHA2(credentialId, 256)`, the legacy `cid` and the contract's `Passkey.id`. The model is used for
reads only. Writes use the query builder inside `PasskeyService`.

| Column | Type | Written on registration | Contract |
|---|---|---|---|
| `username` | VARCHAR(255) NOT NULL | the signed-in username | — |
| `friendlyName` | VARCHAR(255) NULL | `name` from the request, else `NULL` | `name` |
| `rpId` | VARCHAR(255) NOT NULL | request host (lbuchs `rpId`) | `hostName` |
| `credentialPublicKey` | TEXT NOT NULL | PEM (`-----BEGIN PUBLIC KEY-----`) | — |
| `certificateChain` | TEXT NULL | lbuchs `certificateChain` | — |
| `certificate` | TEXT NULL | lbuchs `certificate` | — |
| `certificateIssuer` | VARCHAR(255) NULL | lbuchs `certificateIssuer` | — |
| `certificateSubject` | VARCHAR(255) NULL | lbuchs `certificateSubject` | `authenticatorDescription` |
| `signatureCounter` | INT NULL | lbuchs `signatureCounter` | — |
| `AAGUID` | BLOB NULL | raw 16 bytes | — |
| `credentialId` | BLOB NOT NULL | raw credential ID bytes | `id` = hex SHA-256 |
| `created` | DATETIME | database default | `registeredAt` (UTC) |
| `modified` | DATETIME ON UPDATE | database | ETag input |
| `active` | TINYINT(1) DEFAULT 1 | default | `active` |

The three operations:

- **Rename**: `UPDATE fido2 SET friendlyName = ? WHERE username = ? AND SHA2(credentialId, 256) = ?`.
- **Remove**: `DELETE FROM fido2 WHERE username = ? AND SHA2(credentialId, 256) = ?`.
- **Duplicate check before insert**: `SELECT 1 FROM fido2 WHERE credentialId = ?`.

**Validation**:

- `name`: "1 to 255 characters" after trimming. An empty or whitespace-only name is refused with
  `required`; more than 255 characters is refused with `tooLong` (max 255).
- `credential`: must match `WebAuthnRegistrationResponse`. Missing members → `required`;
  non-base64url values → `invalidFormat` (`format: base64url`).

**Version tag**: strong ETag, SHA-256 of the stored settings `id|friendlyName|active`, base64url,
quoted. It is returned in the `ETag` header and as `etag` on every representation, list entries
included (`docs/conventions.md`).

**List search** (`q`): `friendlyName LIKE ? OR certificateSubject LIKE ?`.

### TfaEntry (`tfa`): second factors

The table is read by `SecondFactorService`. The only write is the re-activation in
`PasswordSignInService` (FR-026).

| Column | Type | Use |
|---|---|---|
| `id` | INT PK | `SecondFactorMethod.id` |
| `key_id` | VARCHAR(255) | `SecondFactorMethod.name` |
| `username` | VARCHAR(255) | Owner |
| `authmech` | ENUM('yubi_otp','u2f','hotp','totp','webauthn') | `webauthn` → `webauthn` (tier primary), `totp` → `totp` (primary), `yubi_otp` → `yubiOtp` (advanced); `u2f` and `hotp` are never offered |
| `secret` | VARCHAR(255) | TOTP: base32 secret. Yubico: `<client id>:<base64 key>:<modhex public id>` |
| `keyHandle` | VARCHAR(1023) | WebAuthn: base64 (standard) credential ID |
| `publicKey` | VARCHAR(4096) | WebAuthn: PEM public key |
| `counter`, `certificate` | — | Not used (legacy does not use them for verification) |
| `active` | TINYINT(1) DEFAULT 0 | Only `1` is offered and verified |

The re-activation write is `UPDATE tfa SET active = '1' WHERE username = ?`. It runs when a password
verifies and the account has no active `tfa` row of any type (legacy `get_tfa()` returns nothing).
Accounts whose only active rows are `u2f` or `hotp` are not re-activated; they get
`second-factor-unavailable`.

### SaslLogEntry (`sasl_log`): recent logins, written by `App\Services\Auth\RecentLoginRecorder` only

| Column | Type | Written |
|---|---|---|
| `service` | VARCHAR(32), PK part | `config('restv2.sasl_log_service')` (`MAILCOWUI` upstream, `WEBUI` FeldHost) |
| `real_rip` | VARCHAR(64), PK part | client address |
| `username` | VARCHAR(255), PK part | mailbox username |
| `app_password` | INT | `0` |
| `datetime` | DATETIME DEFAULT NOW() | `REPLACE` leaves it to the default, so it refreshes like legacy |

The write is `REPLACE INTO sasl_log (username, real_rip, service, app_password) VALUES (?, ?, ?, 0)`.
It runs for mailbox users only: after a verified password (also when a second factor follows) and
after a passkey sign-in.

### IdentityProviderSetting (`identity_provider`) and SchemaVersion (`versions`), read only

- `identity_provider` (`key` PK, `value`): `authsource ∈ {keycloak, generic-oidc, ldap}` →
  `PublicConfig.externalIdentityProvider.configured = true`.
- `versions` (`application` PK, `version`): the row `db_schema` must be in
  `config('restv2.supported_schema_versions')` for readiness.

---

## API-owned tables (Laravel migrations, prefix `restv2_`)

### ApiKey (`restv2_api_keys`)

Migration `database/migrations/2026_10_18_000001_create_restv2_api_keys_table.php`, model
`App\Models\ApiKey` (`HasUlids`).

| Column | Type | Constraint | Contract |
|---|---|---|---|
| `id` | CHAR(26) | PK, ULID | `id` |
| `name` | VARCHAR(255) | NOT NULL | `name` |
| `prefix` | VARCHAR(16) | NOT NULL | `prefix` (first 12 characters of the secret) |
| `secret_hash` | CHAR(64) | NOT NULL, UNIQUE | — (hex SHA-256 of the secret) |
| `owner_username` | VARCHAR(255) | NOT NULL, INDEX | `owner.username` |
| `access_level` | VARCHAR(8) | NOT NULL, `ro` or `rw` | `accessLevel` (`readOnly`/`readWrite`) |
| `allowed_networks` | JSON | NOT NULL, default `[]` | `allowedNetworks` |
| `expires_at` | DATETIME(0) | NULL | `expiresAt` |
| `created_by` | VARCHAR(255) | NOT NULL | `createdBy.username` |
| `last_used_at` | DATETIME(0) | NULL | `lastUsedAt` |
| `last_used_from` | VARCHAR(45) | NULL | `lastUsedFrom` |
| `revoked_at` | DATETIME(0) | NULL, INDEX | `revokedAt` |
| `revoked_by` | VARCHAR(255) | NULL | `revokedBy.username` |
| `created_at`, `updated_at` | DATETIME(0) | NOT NULL | `createdAt` |

**Derived values**:

- `status` is `revoked` if `revoked_at` is set, else `expired` if `expires_at <= now()`, else
  `active`.
- `owner.role` comes from `admin.superadmin` (`null` when the row is missing).
- `owner.active` is `admin.active = 1`.

**Validation** (`App\Http\Requests\Api\V2\StoreApiKeyRequest`):

- `name`: "required, string, 1 to 255 characters after trimming" (empty after trimming →
  `required`, longer → `tooLong`).
- `ownerUsername`: "required, string, max 255". It must exist in `admin` (`notFound`). When it
  exists only in `mailbox`, the code is `notEligible` with params `allowedRoles: [admin, domainAdmin]`.
- `accessLevel`: "required, one of `readOnly`, `readWrite`" (`notInList`).
- `expiresAt`: "optional, nullable, RFC 3339 date-time (`invalidFormat`, `format: dateTime`),
  strictly after now (`mustBeInFuture`)".
- `allowedNetworks`: "optional array, at most 100 items (`tooMany`, max 100). Each item is a
  string of at most 49 characters that is an IPv4 address, an IPv6 address or a prefix (IPv4
  `/0`–`/32`, IPv6 `/0`–`/128`) (`invalidFormat`, `format: ipNetwork`)". Items are normalised to
  `address/prefix` (`/32` or `/128` for single addresses), with duplicates removed and order
  kept.

**Revocation precedence**: an already revoked key answers `200` unchanged before `If-Match` is
evaluated (FR-036). Otherwise a mismatching `If-Match` answers `412`.

**State transitions**:

```
           (time passes expires_at)
 active ───────────────────────────► expired
   │                                    │
   │ revoke (admin session)             │ revoke
   ▼                                    ▼
 revoked ◄──────────────────────────────┘     (final; revoke again = no-op 200)
```

**Version tag**: strong ETag over the stored settings only: `id`, `name`, `owner_username`,
`access_level`, `allowed_networks`, `expires_at`, `revoked_at` and `revoked_by`. It never covers
`last_used_*` or the derived `status`. It is returned as `ETag` and as `etag` on every
representation, including list entries.

**List search** (`q`): `name LIKE ? OR prefix LIKE ? OR owner_username LIKE ?`.

### AuditEvent (`restv2_audit_events`)

Migration `database/migrations/2026_10_18_000002_create_restv2_audit_events_table.php`, model
`App\Models\AuditEvent` (`HasUlids`, `$timestamps = false`, throws on `updating` and `deleting`).

| Column | Type | Constraint | Contract |
|---|---|---|---|
| `id` | CHAR(26) | PK, ULID | `id` |
| `occurred_at` | DATETIME(6) | NOT NULL, INDEX | `occurredAt` |
| `event_type` | VARCHAR(64) | NOT NULL, INDEX (`event_type`, `occurred_at`) | `eventType` |
| `outcome` | VARCHAR(16) | NOT NULL | `outcome` |
| `actor_username` | VARCHAR(255) | NULL, INDEX | `actor.username` |
| `actor_role` | VARCHAR(16) | NULL | `actor.role` |
| `actor_api_key_id` | CHAR(26) | NULL, INDEX | `actor.apiKeyId` |
| `target_type` | VARCHAR(32) | NULL | `target.type` |
| `target_id` | VARCHAR(255) | NULL | `target.id` |
| `target_name` | VARCHAR(255) | NULL | `target.name` |
| `client_address` | VARCHAR(45) | NOT NULL, INDEX | `clientAddress` |
| `user_agent` | VARCHAR(512) | NULL | `userAgent` (truncated to 512) |
| `auth_method` | VARCHAR(32) | NULL | `authMethod` |
| `failure_reason` | VARCHAR(64) | NULL | `failureReason` |
| `request_id` | CHAR(32) | NOT NULL | `requestId` |
| `details` | JSON | NOT NULL, default `{}` | `details` |

**Events and their content** (the `details` keys are whitelisted per event type):

| `eventType` | Written when | `target` | `details` keys |
|---|---|---|---|
| `signIn` (success) | password without second factor, second factor completed, passkey | `session`, null id | `requestedRole`, `secondFactorType`, `restricted` |
| `signIn` (failure) | any refused sign-in step, including throttled ones and Yubico outages | null | `requestedRole`, `secondFactorType`, `step` (`password`, `secondFactor`, `passkey`) |
| `signOut` | `DELETE /session` with a signed-in principal | `session` | — |
| `preferencesUpdate` | `PATCH /me/preferences` changed something | `preferences` | `changedFields` |
| `passkeyRegistration` | success or `registrationFailed` | `passkey`, id, name | `restrictionLifted` |
| `passkeyRename` | name changed | `passkey`, id, name | — |
| `passkeyRemoval` | row deleted | `passkey`, id, name | — |
| `apiKeyCreation` | key created | `apiKey`, id, name | `ownerUsername`, `accessLevel`, `expiresAt`, `allowedNetworks` |
| `apiKeyRevocation` | first revocation | `apiKey`, id, name | `ownerUsername` |

**Filters** (`listAuditEvents`):

- `from` → `occurred_at >= ?`; `to` → `occurred_at < ?`.
- `eventType` (repeatable, `?eventType=signIn&eventType=signOut`) → `event_type IN (...)`. PHP's
  query parser keeps only the last of repeated keys without brackets, so `ListAuditEventsRequest`
  reads every `eventType` value from the raw `QUERY_STRING`.
- `outcome` → `outcome = ?`.
- `actor` → `actor_username = ? OR actor_api_key_id = ?`.
- `clientAddress`: a single address → equality on the canonical form. A prefix → candidate rows
  are pre-filtered in SQL by the textual prefix of the network address, then matched with
  `IpUtils::checkIp` in PHP before pagination. That is acceptable at audit volumes, and an index
  on `client_address` keeps it bounded.

- `q` → `actor_username LIKE ? OR target_name LIKE ? OR user_agent LIKE ?`.

Sort: `occurredAt` (default `desc`). Audit records are immutable and carry no version tag.
Retention: the command `restv2:audit-purge` deletes rows with
`occurred_at < now() - RESTV2_AUDIT_RETENTION_DAYS days`.

### Preference (`restv2_preferences`)

Migration `database/migrations/2026_10_18_000003_create_restv2_preferences_table.php`, model
`App\Models\Preference`.

| Column | Type | Constraint |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK auto-increment |
| `account_kind` | VARCHAR(16) | NOT NULL, `admin` or `mailbox` |
| `username` | VARCHAR(255) | NOT NULL |
| `language` | VARCHAR(16) | NULL (null → default language) |
| `theme` | VARCHAR(8) | NULL (null → `system`) |
| `created_at`, `updated_at` | DATETIME(0) | NOT NULL |
| | | UNIQUE (`account_kind`, `username`) |

**Validation** (`UpdatePreferencesRequest`, JSON Merge Patch):

- The body must have at least one member: an empty object gives `required` on the error key
  `body`.
- `language`: "string, max 16, one of the enabled language codes" (`notInList`, params `allowed`
  = enabled codes).
- `theme`: "one of `light`, `dark`, `system`" (`notInList`).
- `null` for either member is refused (`invalidType`, `expected: string`). The contract does not
  allow `null`, and resetting to defaults is not a feature.

**Effective values**: `language` is the stored value when it is still enabled, else
`DEFAULT_LANG`. `theme` is the stored value, else `system`.

**Version tag**: strong ETag over the stored `language|theme` (null included), returned as `ETag`
and `etag`.

---

## Redis

### mailcow-owned keys (connection `mailcow`, database 0, no prefix, read only)

`TITLE_NAME`, `MAIN_NAME`, `APPS_NAME`, `HELP_TEXT`, `UI_FOOTER`, `UI_IMPRESS`,
`UI_ANNOUNCEMENT_TEXT`, `UI_ANNOUNCEMENT_TYPE`, `UI_ANNOUNCEMENT_ACTIVE`, `MAIN_LOGO`,
`MAIN_LOGO_DARK`, `CUSTOM_LOGIN` and `APP_LINKS`. Their mapping is in
[research R15](./research.md#r15-branding-from-redis).

### App links (no storage of their own)

`PublicConfig.appLinks` concatenates two sources. Legacy writes both, and feature 013 will edit
`APP_LINKS`.

| Source | Shape | Mapping to `AppLink` |
|---|---|---|
| `$MAILCOW_APPS` from `vars.inc.php` / `vars.local.inc.php` (via `MailcowConfig`, or `RESTV2_MAILCOW_APPS`) | list of `{name, link, user_link?, hide?, description?}`; upstream default `[{name: Webmail, link: /SOGo/so, user_link: /SOGo/so, hide: true}]` | `source: builtIn`, `url` = `link`, `signedInUrl` = `user_link` or `link`, `showOnLoginPage` = `!hide`, `description`. Left out when `SKIP_SOGO=y` and `link` or `user_link` matches `/SOGo/i` |
| Redis `APP_LINKS` | JSON list of one-key maps `{name: {link, user_link, hide}}`, or old-style `{name: "address"}` | `source: operator`, `url` = `link`, `signedInUrl` = `user_link` or `link`, `showOnLoginPage` = `!hide`, `description: null` |

Built-in apps come first, then operator links, each in their own order. The channel
`F2B_CHANNEL` is published to, never subscribed.

### API-owned keys (connection `restv2`, database 0, prefix `restv2:`)

| Key | Value | TTL | Owner |
|---|---|---|---|
| `restv2:session:<id>` (Laravel session) | serialized session array (see below) | `SESSION_LIFETIME`, renewed per request | `StartSessionUnlessApiKey` |
| `restv2:signin:<sha256(username\|ip)>` (+ `:timer`) | failure count | `RESTV2_SIGNIN_FAILURE_WINDOW` | `SignInThrottle` |
| `restv2:passkey:<sha256(ip)>` (+ `:timer`) | failure count | same | `SignInThrottle` |
| `restv2:totp:last:<tfa.id>` | last accepted timeslice | 120 s | `SecondFactorService` |
| `restv2:mailcow-vars:<fingerprint>` | JSON of the exported `vars.inc.php` values; the fingerprint covers path, size, modification time and inode of both files | 1 day (refreshed by any file change, which changes the key) | `MailcowConfig` |
| `restv2:migrations-lock` | `migrate --isolated` lock | transient | entrypoint |

### Session (Redis)

The session data is kept under fixed keys:

| Key | Present when | Content |
|---|---|---|
| `_token` | always | CSRF token (Laravel) |
| `restv2.lastActivity` | always | Unix seconds |
| `restv2.principal` | `restricted` or `active` | `username`, `role` (`admin`, `domainAdmin`, `mailboxUser`), `accountKind` (`admin`, `mailbox`), `authMethod`, `signedInAt`, `userAgentHash` |
| `restv2.pending` | `pendingSecondFactor` | `username`, `role`, `accountKind`, `methodIds` (ordered), `expiresAt`, `userAgentHash`, optional `webauthn` (`challenge` b64, `expiresAt`) |
| `restv2.passkeyChallenge` | after `createPasskeyChallenge` | `challenge` b64, `expiresAt` |
| `restv2.passkeyRegistration` | after `createPasskeyRegistrationOptions` | `challenge` b64, `expiresAt`, `username` |

`state` is derived from this data:

- `anonymous`: neither `principal` nor `pending` is present.
- `pendingSecondFactor`: `pending` is present and not expired.
- `restricted`: `principal` is present and `PendingActionEvaluator` returns at least one action.
- `active`: `principal` is present and there are no pending actions.

**Session state transitions**:

```
              POST /session (no 2FA)          ┌──────────────┐
 anonymous ───────────────────────────────────►  active /    │◄── pending actions
   │  ▲       POST /session/passkey-assertion │  restricted  │    re-evaluated each
   │  │                                       └──────▲───────┘    request (both ways)
   │  │ DELETE /session/pending-sign-in, failure,    │
   │  │ expiry (5 min)                               │ POST …/second-factor (ok)
   │  │                                              │
   │  └──────────────── pendingSecondFactor ─────────┘
   └─ POST /session (2FA active) ───────►

 any state ── DELETE /session, idle timeout, UA change, account inactive/deleted ──► ended
              (session invalidated; next request is anonymous with a new CSRF token)
```

Challenges (pending WebAuthn, passkey sign-in, passkey registration) are `issued → consumed`
(by the first verification attempt, whatever its result) or `issued → expired` (300 s). Issuing a
new one replaces the previous one.

---

## Value objects (no storage)

- `App\Auth\Principal`: `username`, `role` (`App\Auth\Role` enum), `accountKind`, `authMethod`
  (`App\Auth\AuthMethod`), optional `apiKey` (`id`, `name`, `prefix`, `accessLevel`). It is built
  per request from the session or the key.
- `App\Auth\PendingAction` enum: `passwordChange`, `secondFactorSetup`.
- `App\Auth\PermissionFlag` enum: the 29 flags, each with its source columns.
- `App\Services\Identity\Scope`: `allDomains`, `domains(list)` or `mailbox(username, domain)`.
- `App\Support\Http\ProblemType` enum: one case per section of `docs/problems.md`, with its URI,
  status and English title.
