# Data Model: Identity Providers and OAuth2 Clients

**Feature**: `specs/012-identity-providers-oauth2` | **Date**: 2026-10-18 | **Plan**: [plan.md](./plan.md)

Feature 012 adds **no API-owned table and no migration**. It reads and writes upstream mailcow
tables through services (constitution III and IV), keeps short-lived data in the feature 001
session and in `restv2:`-prefixed Redis keys, and writes audit records into 001's
`restv2_audit_events`. Column definitions are quoted from upstream `init_db.inc.php` at tag
`2026-09a` (`db_version 18082026_1200`). The JSON shapes are in the contract
(`api/components/schemas/IdentityProvider*.yaml`, `Oauth2Client*.yaml`, `SingleSignOn*.yaml`);
this document maps them to storage.

Upstream `DATETIME` columns hold mailcow's local time (`TZ`) and are read through 001's
`App\Casts\MailcowDateTime` (001 data model).

---

## Upstream tables

### IdentityProviderSetting (`identity_provider`): read by every part, written by `IdentityProviderConfigService` and `KeycloakServiceTokenCache` only

| Column | Type | Use |
|---|---|---|
| `key` | VARCHAR(255) NOT NULL, PK | Setting name (below) |
| `value` | TEXT NOT NULL | Encoded value (below) |
| `created` | DATETIME(0) NOT NULL DEFAULT NOW(0) | `modifiedAt` fallback |
| `modified` | DATETIME ON UPDATE CURRENT_TIMESTAMP (NULL on insert) | `modifiedAt` |

Model `App\Models\Mailcow\IdentityProviderSetting` exists since 001 (read-only there). Feature 012
keeps it read-only and writes with the query builder inside the two services.

**Settings, contract members and encodings** (written byte-identically to legacy, research R9):

| `key` | Types | Contract member | Stored value | Default when missing |
|---|---|---|---|---|
| `authsource` | all | `type` | `keycloak`, `generic-oidc`, `ldap` | — (no row = not configured) |
| `ignore_ssl_error` | all | `ignoreTlsErrors` | `'1'` / `''` | `false` |
| `login_provisioning` | all | `createMailboxOnSignIn` | `'1'` / `''` | `true` |
| `mappers` | all | `attributeMapping[].attributeValue` | JSON list | `[]` |
| `templates` | all | `attributeMapping[].template` | JSON list, same positions | `[]` |
| `default_template` | all | `defaultTemplate` | name or `''` | `null` |
| `access_token` | Keycloak (cache) | — never exposed | service token or `''` | `''` |
| `server_url` | Keycloak | `serverUrl` | URL without trailing `/` | `null` |
| `realm` | Keycloak | `realm` | text | `null` |
| `client_id` | Keycloak, OIDC | `clientId` | text | `null` |
| `client_secret` | Keycloak, OIDC | `clientSecretSet` (`value <> ''`) | secret | not set |
| `redirect_url` | Keycloak, OIDC | `legacyReturnUrl` | URL | `null` |
| `redirect_url_extra` | Keycloak, OIDC | `additionalReturnUrls` | JSON list (or legacy object) | `[]` |
| `version` | Keycloak | `version` | text | `null` |
| `mailpassword_flow` | Keycloak | `mailpasswordFlow` | `'1'` / `'0'` | `false` |
| `periodic_sync` | Keycloak, LDAP | `periodicSync` | `'1'` / `'0'` | `false` |
| `import_users` | Keycloak, LDAP | `importUsers` | `'1'` / `'0'` | `false` |
| `sync_interval` | Keycloak, LDAP | `syncInterval` | decimal | `15` |
| `authorize_url` | OIDC | `authorizationEndpoint` | URL | `null` |
| `token_url` | OIDC | `tokenEndpoint` | URL | `null` |
| `userinfo_url` | OIDC | `userInfoEndpoint` | URL | `null` |
| `client_scopes` | OIDC | `scopes` | space-separated | `openid profile email mailcow_template` |
| `host` | LDAP | `hosts` | comma-joined, no spaces | `[]` |
| `port` | LDAP | `port`, `effectivePort` | decimal | `389` |
| `use_ssl` | LDAP | `useSsl` | `'1'` / `''` | `false` |
| `use_tls` | LDAP | `useStartTls` | `'1'` / `''` (always `''` with SSL) | `false` |
| `basedn` | LDAP | `baseDn` | text | `null` |
| `username_field` | LDAP | `usernameField` | lower-cased | `mail` |
| `filter` | LDAP | `filter` | text or `''` | `null` |
| `attribute_field` | LDAP | `attributeField` | lower-cased or `''` | `null` |
| `binddn` | LDAP | `bindDn` | text | `null` |
| `bindpass` | LDAP | `bindPasswordSet` (`value <> ''`) | secret | not set |

**Derived members**:

- `complete` and `missingSettings`: research R9 (keys per type that must be non-empty).
- `effectivePort`: 636 when `use_ssl` and `port = 389`, else `port`.
- `attributeMapping[].templateExists`, `defaultTemplateExists`: `EXISTS (SELECT 1 FROM templates
  WHERE type = 'mailbox' AND template = ?)`, one query for all names.
- `appReturnUrls`: research R4, from `MAILCOW_HOSTNAME` and `ADDITIONAL_SERVER_NAMES`.
- `mailboxCounts`: see `Mailbox` below.
- `synchronisation`: see "Redis, mailcow-owned keys".
- `modifiedAt`: `MAX(COALESCE(modified, created))` over every row except `access_token`.

**Version tag**: strong ETag over `key\0value\n` of every row except `access_token`, sorted by key,
with the values of `client_secret` and `bindpass` replaced by `hash_hmac('sha256', value,
config('app.key'))`. It covers the secrets only through that HMAC (a changed secret changes the tag,
and the tag never contains a plain hash of a secret) and leftover rows of another type (research
R9). It never covers the counts or the synchronisation status (`docs/conventions.md`).

**Writes**:

| Operation | SQL (one transaction) | Side effects |
|---|---|---|
| Whole save, change | under `GET_LOCK('restv2_identity_provider', 5)`: `DELETE FROM identity_provider WHERE key NOT IN (<keys of the type>)`; `INSERT INTO identity_provider (key, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)` for every key of the type; `access_token` upserted as `''` | Cached service token cleared (FR-016); audit `identityProviderCreation` or `identityProviderUpdate` in the same transaction |
| Delete | under the same lock: `DELETE FROM identity_provider` | Audit `identityProviderDeletion` |
| Service token refresh | `INSERT … ('access_token', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)` | None; not part of the version tag |

**Validation**: FR-010 to FR-014 as rule objects (research R10). Key constraints, quoted for the
tasks:

- Keycloak: `serverUrl` "required, absolute https/http URL, at most 2,000 characters, trailing
  slashes removed"; `realm` "required, 1 to 255 characters, without `/`"; `clientId` "required, 1 to
  255 characters"; `version` "required, one to four whole numbers separated by dots";
  `syncInterval` "whole number from 1 to 10,080, default 15".
- Generic OIDC: the three endpoints "required, each an absolute https/http URL of at most 2,000
  characters"; `clientId` "required, 1 to 255 characters"; `scopes` "1 to 50 scope names separated
  by single spaces, each of printable characters other than space, `\"` and `\\`".
- LDAP: `hosts` "1 to 10 host names or IP addresses"; `port` "1 to 65,535, default 389"; `baseDn`
  and `bindDn` "required, 1 to 1,000 characters"; `usernameField` "a letter followed by letters,
  digits or hyphens, at most 255 characters, stored lower-cased, default `mail`"; `attributeField`
  "empty or an attribute name as above, stored lower-cased, not empty while user import or periodic
  full synchronisation is on"; `filter` "empty or at most 1,000 characters, starting with `(`,
  ending with `)`, balanced parentheses".
- Shared: `legacyReturnUrl` "required for Keycloak and generic OIDC, absolute https/http URL, at
  most 2,000 characters"; `additionalReturnUrls` "at most 100 such URLs, duplicates stored once";
  `attributeMapping` "at most 100 entries, attribute value 1 to 255 characters and unique (exact
  comparison), template name of an existing mailbox template"; `defaultTemplate` "none or an
  existing mailbox template".
- Secrets: "1 to 1,000 characters, write-only; required on the first save of a type and on a type
  change; kept when omitted; required again when the Keycloak server URL, the generic OIDC token
  endpoint or the LDAP hosts change" (`secretRequired`).

**State transitions**:

```
                 PUT (201)
 not configured ───────────► configured(type T) ──PATCH / PUT same type (200)──► configured(T)
       ▲                          │      │
       │ DELETE (204;             │      └─PUT type U (200; 409 while mailboxes use a source ≠ U)─► configured(U)
       │ 409 while any mailbox    │
       │ has an external source)  │
       └──────────────────────────┘
 legacy UI / api/v1 may also write any state; an incomplete legacy-stored configuration is
 readable and unusable for sign-in until a save completes it.
```

### Mailbox (`mailbox`): read here; written only through feature 003's mailbox service

| Column | Type | Use in 012 |
|---|---|---|
| `username` | VARCHAR(255) PK | Matched with the provider's e-mail address (trimmed, lower-cased) |
| `name` | VARCHAR(255) | Compared with the provider's display name for the template update |
| `local_part`, `domain` | VARCHAR(255) | Creation input (via 003) |
| `kind` | VARCHAR(100) | `location`, `thing`, `group` are resources and never match |
| `authsource` | ENUM('mailcow','keycloak','generic-oidc','ldap') DEFAULT 'mailcow' | Must equal the configured type; counted per source |
| `attributes` | JSON | `attribute_hash` (template marker), `force_tfa` (via 001's evaluator) |
| `active` | TINYINT(1) | Must be `1` after verification or creation |

**Counts** (`mailboxCounts`, FR-015, FR-017): `SELECT authsource, COUNT(*) FROM mailbox WHERE
authsource IS NOT NULL AND authsource <> 'mailcow' GROUP BY authsource`, over all rows like
legacy's in-use checks.

**Template marker**: `attributes.attribute_hash = sha1(json_encode(templates.attributes))`, where
`json_encode` takes the column's text with PHP's default flags (research R6). Feature 003 clears it
on every other change (003 FR-034); the sign-in update records the new value.

### Domain (`domain`), read only

`domain.active` must be `1` for a sign-in (joined in the mailbox lookup). Creation requires an
existing primary domain, checked by feature 003.

### MailboxTemplate (`templates` with `type = 'mailbox'`), read only

| Column | Type | Use |
|---|---|---|
| `id` | INT PK | Passed to 003's creation |
| `template` | VARCHAR(255) | Name stored in the mapping and `default_template` |
| `type` | VARCHAR(255) | `mailbox` only |
| `attributes` | JSON | Template values (applied by 003) and marker input |

The model is feature 003's (`App\Models\Mailcow\MailboxTemplate` in 003's plan); 012 only reads it.

### Oauth2Client (`oauth_clients`): written by `Oauth2ClientService` only

| Column | Type | Contract | Written |
|---|---|---|---|
| `id` | INT NOT NULL AUTO_INCREMENT, UNIQUE | `id` | database |
| `client_id` | VARCHAR(80) NOT NULL, PK | `clientId` | `bin2hex(random_bytes(6))`, unique |
| `client_secret` | VARCHAR(80) | `clientSecret` (creation and regeneration only) | `bin2hex(random_bytes(12))` |
| `redirect_uri` | VARCHAR(2000) | `redirectUris` (split on spaces) | URIs joined by single spaces |
| `grant_types` | VARCHAR(80) | — | `NULL` |
| `scope` | VARCHAR(4000) | `scope` | `profile` |
| `user_id` | VARCHAR(80) | — | `NULL` |

Model `App\Models\Mailcow\Oauth2Client` (`$table = 'oauth_clients'`, `$primaryKey = 'id'` for route
binding, `$timestamps = false`, `$guarded = ['*']`; reads only).

**Validation**: `redirectUris` "a list of 1 or more absolute URIs, each with a scheme, without a
fragment and without whitespace; joined by single spaces at most 2,000 characters; duplicates stored
once". `clientId` and `scope` in a change "only with the current value" (`immutable`).

**Version tag**: strong ETag over `id|client_id|hmac|redirect_uri|grant_types|scope|user_id`, where
`hmac` is `hash_hmac('sha256', client_secret, config('app.key'))`. It never covers tokens or counts.

**List**: search `client_id LIKE ? OR redirect_uri LIKE ?`; filter `hasActiveTokens` as `EXISTS` on
unexpired access or refresh tokens; sort `id` (default asc), `client_id`, `redirect_uri`.

### Oauth2 tokens and codes: counted and deleted by `Oauth2ClientService` only; issued by legacy

| Table | Key | Columns used |
|---|---|---|
| `oauth_access_tokens` | `access_token` VARCHAR(40) PK | `client_id` VARCHAR(80) NOT NULL, `expires` TIMESTAMP NOT NULL |
| `oauth_refresh_tokens` | `refresh_token` VARCHAR(40) PK | `client_id`, `expires` |
| `oauth_authorization_codes` | `authorization_code` VARCHAR(40) PK | `client_id`, `expires` |

- **Counts**: `COUNT(*) WHERE client_id = ? AND expires > NOW()` (access and refresh tokens).
- **Revocation and deletion**: `DELETE FROM <each table> WHERE client_id = ?`, counting the rows
  removed, in one transaction with the audit record (deletion then removes the client row).

**Client state transitions**:

```
 (none) ──create (201, secret shown once)──► active ──PATCH redirectUris (200)──► active
                                               │  ├─secret regeneration (200, secret shown once; tokens kept)
                                               │  ├─token revocation (200; tokens and codes removed)
                                               │  └─legacy endpoints issue tokens and codes
                                               └─delete (204; client, tokens and codes removed) ──► (none)
 bulk action: delete or revokeTokens per client, each with its own outcome and audit record
```

### Rows written through feature 001's services

- `sasl_log`: `RecentLoginRecorder::record()` after a single sign-on (FR-024) and after a provider
  password verification (001 behaviour, FR-031), with 001's service label.
- `tfa`: re-activation by `PasswordSignInService` after a provider password sign-in without usable
  second factors (001 FR-026). Never after a single sign-on.

---

## Feature 001's audit table (`restv2_audit_events`)

No schema change. Values added by this feature (all fit 001's column sizes: `event_type` 64,
`auth_method` 32, `target_type` 32, `failure_reason` 64):

| `event_type` | Written when | `target` | `details` keys (whitelist) |
|---|---|---|---|
| `identityProviderCreation` | `PUT` created the configuration, or was refused | `identityProvider`, null id, name = type | `type`, `changes` (setting → `{old, new}`; secrets `{changed: true}`) |
| `identityProviderUpdate` | `PUT` replaced or `PATCH` changed it, or was refused | same | `type`, `previousType`, `changes` |
| `identityProviderDeletion` | `DELETE`, or refused | same | `type`, `mailboxCounts` (when refused) |
| `identityProviderConnectionTest` | every test | same | `type`, `testedAddress`, `outcome`, `failureCategory`, `providerStatus` |
| `identityProviderSessionEnd` | FR-026 ended a session | `session` | `identityProvider`, `reason` |
| `oauth2ClientCreation` | client created or refused | `oauth2Client`, id, name = client ID | `redirectUris` |
| `oauth2ClientUpdate` | redirect URIs changed or refused | same | `changes` |
| `oauth2ClientSecretRegeneration` | secret regenerated | same | — (never the secret) |
| `oauth2ClientTokenRevocation` | tokens revoked (also with nothing to remove) | same | `removed` (`accessTokens`, `refreshTokens`, `authorizationCodes`), `bulkRequestId` |
| `oauth2ClientDeletion` | client deleted | same | `removed`, `bulkRequestId` |
| `signIn` (001) | single sign-on and provider password sign-ins | `session` | `identityProvider`, `mailboxCreated`, `templateApplied`, `providerError` (FR-063), 001's keys |
| `mailboxCreation`, `mailboxUpdate` (003) | mailbox created or updated on sign-in | `mailbox`, username | `trigger: identityProviderSignIn`, `template`, `changes`, `signInRequestId`, 003's keys |

`auth_method` values added: `singleSignOn`, `directoryPassword`, `directoryPasswordAndSecondFactor`,
`providerPassword`, `providerPasswordAndSecondFactor`. Failure reasons added: see
`AuditFailureReason` (`signInStateInvalid`, `singleSignOnNotAvailable`, `providerRefused`,
`providerReturnedError`, `identityProviderUnavailable`, `noEmailAddress`, `mailboxNotLinked`,
`autoCreationOff`, `noMatchingMapping`, `mailboxCreationFailed`, `passwordNotVerifiable`,
`connectionTestFailed`). Refused writes reuse `permissionDenied`, `validationFailed`,
`versionOutdated`, `conflict` and `notFound`.

**Refusals**: every state-changing route of the identity provider and OAuth2 modules declares
`restv2.audit` (event type and target type), and the shared `RefusalAuditor` (002 or 008) writes one
failure record per refusal by an authenticated caller, including read-only keys
(`insufficient-key-scope`) and restricted sessions (`pending-action-required`); never for CSRF
failures or `401`s. A refused bulk request gives one record with a `null` target and
`details.itemIds`.

**Filters**: none added (`docs/conventions.md`). Clients use 002's `targetType`
(`identityProvider`, `oauth2Client`, `mailbox`, `session`) and `targetId`, and 001's `eventType`
and `outcome`; each record carries `authMethod`.

---

## Redis

### mailcow-owned keys (connection `mailcow`, read only)

| Key | Shape | Use |
|---|---|---|
| `CRON_LOG` | list of JSON `{time, priority, task, message}`, newest first, trimmed to `LOG_LINES` | Synchronisation status (research R12): entries with `task` `Keycloak Sync` or `LDAP Sync` |

### API-owned keys (connection `restv2`, prefix `restv2:`)

| Key | Value | TTL | Owner |
|---|---|---|---|
| `restv2:sso-link:<linkId>` | `Crypt::encryptString` of JSON `{accessToken, refreshToken, expiresAt, lastRenewedAt, username, type}` | `SESSION_LIFETIME`, renewed with the session | `IdentityProviderSessionGuard`, `SingleSignOnService` |
| `restv2:lock:sso-link:<linkId>` | lock owner | 10 s | `IdentityProviderSessionGuard` (renewal) |

### MariaDB named locks (`GET_LOCK()`, no storage)

| Name | Timeout | Owner |
|---|---|---|
| `restv2_identity_provider` | 5 s | `IdentityProviderConfigService`: serialises saves, changes and deletions, so two first saves cannot both create |
| `restv2_idp_<sha1(email)>` | 10 s | `MailboxProvisioner`: one creation per address at a time (FR-040) |

A lock that cannot be obtained in time is an unexpected failure (`500`), as in feature 008.

### Session keys added (001's session, `restv2:session:<id>`)

| Key | Present when | Content |
|---|---|---|
| `restv2.sso` | after `startSingleSignOn`, until consumed, cancelled or expired | `stateHash` (hex SHA-256), `codeVerifier`, `returnUrl`, `type`, `issuedAt`, `expiresAt` (`issuedAt + 600 s`) |
| `restv2.principal.identityProvider` | single sign-on and provider password sessions | `keycloak`, `genericOidc` or `ldap` |
| `restv2.principal.passwordSource` | password sessions | `mailcow`, `ldap` or `keycloak` |
| `restv2.principal.ssoLinkId` | single sign-on sessions | 32 hex characters |
| `restv2.pending.passwordSource` | pending second factor after a provider password | as above |

**Pending single sign-on**:

```
 none ──POST /session/single-sign-on──► pending ──POST …/completion, state ok──► consumed ─► session active (or failure)
   ▲                                     │  │
   │                                     │  ├─POST …/provider-error, state ok ─► consumed (audited, 204)
   │                                     │  ├─any completion or report with a wrong state ─► consumed (401)
   └──── DELETE /session/single-sign-on ─┘  └─10 minutes pass ─► expired (next use: 401)
 a new start replaces a pending one
```

**Single sign-on session** (on top of 001's session states):

```
 active(singleSignOn) ──request in mode session/authenticated──► checks:
     configuration changed / incomplete, mailbox authsource changed ─► ended (401 identity-provider-session-ended)
     refresh token present and > 5 min since renewal ─► renew ─► ok: active (lastRenewedAt = now)
                                                              └─ refused, unreachable, other e-mail ─► ended
 DELETE /session ─► ended (link deleted, provider untouched)
```

---

## Capabilities (derived, no storage, FR-064)

| Read | Member | Source |
|---|---|---|
| `IdentityProviderCapabilities` | `configured`, `configuredType` | `identity_provider.authsource` |
| | `appReturnUrls` | `AppReturnUrls::all()` |
| | `types` | fixed: Keycloak (single sign-on, password with the mailpassword flow, synchronisation), generic OIDC (single sign-on), LDAP (password, synchronisation) |
| | `defaults` | FR-007 values |
| | `limits` | FR-010 to FR-013 values (100 mapping entries, 255, 100 additional URLs, 2,000, 10 hosts, 1–10,080 minutes, 1,000, 50 scopes, 1,000, 1,000) |
| | `providerTimeoutSeconds`, `singleSignOn` | `config('restv2.identity_provider.*')` |
| `Oauth2ClientCapabilities` | `endpoints`, `scope`, `grantTypes` | fixed (legacy `prerequisites.inc.php`) |
| | `accessTokenLifetimeSeconds`, `refreshTokenLifetimeSeconds` | 001's `MailcowConfig` (`ACCESS_TOKEN_LIFETIME`, `REFRESH_TOKEN_LIFETIME`; `RESTV2_*` overrides) |
| | `redirectUrisMaxLength`, `bulkLimit`, page sizes | 2,000, 100, 25 and 1,000 |

## Value objects (no storage)

- `App\Services\IdentityProvider\IdentityProviderConfig`: type plus typed settings (readonly), with
  `isComplete()`, `missingSettings()`, `secretFor()`, `secretAddress()` and `withPatch()`.
- `App\Clients\Oidc\OidcEndpoints`: authorize, token and user info URLs plus scopes.
- `App\Clients\Oidc\OidcTokens`: access token, refresh token, expiry.
- `App\Clients\Ldap\LdapConnectionSettings`, `LdapResult`.
- `App\Services\IdentityProvider\ProviderVerification`: `verified(mappingValue, displayName)`,
  `wrongPassword`, `unknownUser`, `notVerifiable`, `unavailable(reason)`.
- `App\Services\IdentityProvider\ConnectionTestResult`, `SynchronisationStatus`.
- `App\Auth\PasswordSource` enum (`mailcow`, `ldap`, `keycloak`) and `App\Auth\IdentityProviderType`
  enum (`keycloak`, `genericOidc`, `ldap`, with the legacy value `generic-oidc` mapping).
