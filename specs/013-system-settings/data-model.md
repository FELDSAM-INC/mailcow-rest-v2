# Data Model: System Settings

**Feature**: `specs/013-system-settings` | **Date**: 2026-10-18 | **Plan**: [plan.md](./plan.md)

Storage follows 001's rules (001 data-model): upstream tables are never migrated and are written
only through the services named here; API-owned tables use the `restv2_` prefix and Laravel
migrations; API-owned Redis keys use the `restv2:` prefix. The JSON shapes are defined in the
contract (`api/components/schemas/`); this document maps them to storage. "Tag" means the strong
ETag computed by 001's `ETag::of()` (base64url SHA-256, quoted), returned in `ETag` and as `etag`.

---

## 1. mailcow's Redis (connection `mailcow`, database 0, no prefix)

All writes go through `App\Services\Settings\RedisSettingsStore` (WATCH/MULTI/EXEC, research R1)
from the service named in each table. Reads happen on every request.

### Branding (service `App\Services\Settings\BrandingService`)

| Key | Format | Written | Contract | Tag input |
|---|---|---|---|---|
| `MAIN_LOGO`, `MAIN_LOGO_DARK` | `data:<type>;base64,<data>` | `replaceLogo` (SET), `deleteLogo` (DEL) | `LogoSetting` (type, size, dimensions decoded on read) | raw value |
| `TITLE_NAME`, `MAIN_NAME`, `APPS_NAME` | `htmlspecialchars()` of plain text | `updateUiTexts` | `UiTexts.pageTitle`, `productName`, `appsHeading` (decoded) | raw values of the nine UI text keys |
| `HELP_TEXT`, `UI_FOOTER` | raw HTML | `updateUiTexts` | `loginHelpHtml`, `footerHtml` | ″ |
| `UI_IMPRESS` | raw HTML (older releases) | deleted by `updateUiTexts` after moving it to `UI_FOOTER` | `footerHtml` while `UI_FOOTER` is empty | ″ |
| `UI_ANNOUNCEMENT_TEXT` | plain text as given | `updateUiTexts` | `announcement.text` | ″ |
| `UI_ANNOUNCEMENT_TYPE` | `info` / `warning` / `danger` / `""` | `updateUiTexts` | `announcement.type` (`""` → `null`) | ″ |
| `UI_ANNOUNCEMENT_ACTIVE` | `1` / `0` | `updateUiTexts` | `announcement.active` | ″ |
| `CUSTOM_LOGIN` | JSON `{"hide_user_quicklink":0,"hide_domainadmin_quicklink":0,"hide_admin_quicklink":0,"force_sso":0}` (integers, this key order) | `updateLoginPageSettings` | `LoginPageSettings` | raw value |
| `APP_LINKS` | JSON list of `{"<name>": {"link": "...", "user_link": "...", "hide": bool}}`; old-style `{"<name>": "<address>"}` read only | `updateAppLinkSettings` | `AppLinkSettings.links` (`user_link` `""` ↔ `signedInUrl: null`, `hide` ↔ `!showOnLoginPage`) | raw value |
| `IP_CHECK` | `1` / `0` | `updateIpCheckSetting` | `IpCheckSetting.enabled` (missing → `false`) | raw value |

Defaults when a key is missing: empty texts, announcement inactive without type, no logos, all
login links shown, force single sign-on off, no custom app links, IP check off (spec edge case
"Legacy defaults").

### Password settings

| Key | Format | Service | Contract | Tag input |
|---|---|---|---|---|
| `PASSWD_POLICY` (hash) | fields `length`, `chars`, `special_chars`, `lowerupper`, `numbers`, decimal strings; seeded `6,0,0,0,0` by php-fpm | 003's `App\Services\PasswordPolicy\PasswordPolicyService::update()` (added by 013) | 003's `PasswordPolicy` | 003's formula over the **effective** values: `length=<n>;chars=<0|1>;special_chars=<0|1>;lowerupper=<0|1>;numbers=<0|1>` |
| `PW_RESET_FROM`, `PW_RESET_SUBJ`, `PW_RESET_TEXT`, `PW_RESET_HTML` | strings, `""` when unset | `App\Services\Settings\PasswordResetNotificationService` | `PasswordResetNotification` (defaults from `pw_reset_text.tpl` / `pw_reset_html.tpl` when both templates are empty) | raw values of the four keys |

Validation (`UpdatePasswordPolicyRequest`, `UpdatePasswordResetNotificationRequest`):
`minLength` "integer 3 to 64" (`outOfRange`, `min: 3`, `max: 64`); the four booleans; `sender`
"empty, or an e-mail address (`FILTER_VALIDATE_EMAIL`) without `\x00-\x1F\x7F`, at most 255"
(`invalidFormat`, `format: email`); `subject` "at most 255 characters, no control characters"
(`tooLong`, `invalidFormat` `singleLine`); each template "at most 1,048,576 bytes" (`tooLong`,
`max: 1048576`, `unit: bytes`).

### Quota notifications (service `App\Services\Settings\QuotaNotificationService`)

| Key | Format | Contract | Tag input |
|---|---|---|---|
| `QW_SENDER`, `QW_SUBJ`, `QW_HTML` | strings; `""` or missing → Dovecot default | `QuotaNotificationSettings` (effective value + `…IsDefault`); defaults `quota-warning@localhost`, `Quota warning`, `quota.tpl` | raw values of the three keys |
| `QW_BCC` (hash, field = domain) | JSON `{"bcc_rcpts": ["a@x", ...], "active": 0|1}`, always a list, `active` integer; an object read as its values | `QuotaNotificationRecipients` (missing field → `[]`, inactive) | raw field value |

Validation: sender and subject as for the reset notification; template ≤ 1,048,576 bytes;
`recipients` "at most 100 (`tooMany`), each an e-mail address without control characters
(`invalidFormat` `email`, key `recipients.<i>`)", trimmed, deduplicated case-insensitively keeping
the first spelling; `active` boolean; an empty resulting list stores `active = 0`.

### Server settings (service `App\Services\Settings\LegacyApiCorsService`, `LicenseService`)

| Key | Format | Contract | Tag input |
|---|---|---|---|
| `CORS_SETTINGS` (hash) | `allowed_origins` = `"https://a.example, *"` (`", "`-joined), `allowed_methods` = `"GET, POST"` | `LegacyApiCorsSettings` (`allowedOrigins` split on `,` and trimmed; empty → `[]`; `serverOrigin` = `https://<MAILCOW_HOSTNAME>`; methods missing → the four) | the two raw fields |
| `LICENSE_STATUS_CACHE` | JSON `{"valid": "true"|"false", "c": "...", "s": "...", "m": "..."}` (legacy) | `LicenseStatus.lastVerification` (research R20) | — (not mutable through a version-checked write) |

Validation (`UpdateLegacyApiCorsRequest`): `allowedOrigins` "at most 100 (`tooMany`), each `*` or a
valid origin (`invalidFormat`, `format: origin`)", lower-cased, deduplicated; `allowedMethods`
"non-empty (`tooFew`, `min: 1`), each one of GET, POST, PUT, DELETE (`notInList`)", deduplicated.
The merged result is validated as a whole.

### Channels and other keys

None. This feature publishes nothing to Redis channels.

---

## 2. API-owned Redis keys (connection `restv2`, prefix `restv2:`)

| Key | Value | TTL | Owner |
|---|---|---|---|
| `restv2:brand-colors` (hash) | `light`, `dark`: `#rrggbb` (lower case); a cleared colour is a missing field | none | `App\Services\Settings\BrandColorService`; read by 001's `PublicConfigService` |
| `restv2:license:last-verification` | JSON `{result, verifiedAt, customerId, serviceId, supportLevel, cacheHash}` | none | `LicenseService` |
| `restv2:mass-mail-runner` (cache lock) | Laravel atomic lock | 330 s | `restv2:mass-mail-run` |

`BrandColors` tag input: the two raw fields (`null` when missing). The effective colours are
derived (research R5) and also feed 001's public configuration tag.

---

## 3. Upstream tables

### RspamdSettingsRule (`settingsmap`), written only by `App\Services\Rspamd\RspamdSettingsRuleService`

Model `App\Models\Mailcow\RspamdSettingsRule`: `$table = 'settingsmap'`, `$primaryKey = 'id'`,
`$timestamps = false`, casts `active` → bool, `created`/`modified` → `MailcowDateTime`.

| Column | Type (upstream) | Contract | Written |
|---|---|---|---|
| `id` | INT AUTO_INCREMENT PK | `id` | by the database |
| `desc` | VARCHAR(255) NOT NULL | `description` | create, update |
| `content` | LONGTEXT NOT NULL | `content` (trimmed) | create, update |
| `created` | DATETIME(0) DEFAULT NOW(0) | `createdAt` (UTC) | by the database |
| `modified` | DATETIME ON UPDATE CURRENT_TIMESTAMP | `modifiedAt` (`null` until modified) | by the database |
| `active` | TINYINT(1) DEFAULT 0 | `active` | create (default `0`), update |

Validation (`StoreRspamdSettingsRuleRequest`, `UpdateRspamdSettingsRuleRequest`): `description`
"required on create, 1 to 255 characters after trimming" (`required`, `tooLong`); `content`
"required on create, non-empty after trimming, at most 1,048,576 bytes, structurally balanced"
(`required`, `tooLong` `unit: bytes`, `unbalancedStructure` with `line` and `construct`);
`active` boolean. In a PATCH, an empty `description` or `content` is `required`.

Tag: `id|desc|sha256(content)|active`. List search `q`: `` `desc` LIKE ? OR `content` LIKE ? ``.
Sort map: `id` → `id`, `description` → `desc`, `active`, `createdAt` → `created`, `modifiedAt` →
`modified` (NULLs first ascending), always with `id` as tie-breaker.

Rspamd reads active rows through `settings.php` (php-fpm), unchanged.

### Read-only upstream data

| Table | Columns | Use |
|---|---|---|
| `domain` | `domain` | Quota copy recipients (must exist, not an alias domain), mass mail domain filter |
| `alias_domain` | `alias_domain` | Tells an alias domain apart (404 for copy recipients, `notFound` in a selection) |
| `domain_admins` | `username`, `domain`, `active` | Domain administrator scope (001's `Scope`) |
| `mailbox` | `username`, `domain`, `kind`, `active` | Mass mail starting set: `kind = '' AND active IN (1, 2)` |
| `versions` | `application = 'GUID'`, `version` | GUID (`INVALID` → invalid) |

---

## 4. API-owned tables (Laravel migrations, prefix `restv2_`)

### MassMailJob (`restv2_mass_mail_jobs`)

Migration `database/migrations/2026_10_18_013001_create_restv2_mass_mail_jobs_table.php`, model
`App\Models\MassMailJob` (`HasUlids`, `$timestamps = false`).

| Column | Type | Constraint | Contract |
|---|---|---|---|
| `id` | CHAR(26) | PK, ULID | `id` |
| `status` | VARCHAR(16) | NOT NULL, INDEX (`status`, `created_at`) | `status` (`running`, `completed`, `cancelled`) |
| `running_slot` | TINYINT UNSIGNED | NULL, UNIQUE | — (`1` while running, `NULL` otherwise: at most one running job) |
| `sender` | VARCHAR(255) | NOT NULL | `sender` |
| `subject` | VARCHAR(255) | NOT NULL, INDEX prefix for `q` not needed (LIKE scan, small table) | `subject` |
| `text_body` | MEDIUMTEXT | NOT NULL | `textBody` |
| `html_body` | MEDIUMTEXT | NULL | `htmlBody` |
| `selection` | JSON | NOT NULL | `selection` (`{domains, include, exclude}` as normalised) |
| `recipient_count` | INT UNSIGNED | NOT NULL | `recipientCount` |
| `created_by` | VARCHAR(255) | NOT NULL | `createdBy.username` |
| `created_request_id` | CHAR(32) | NOT NULL | — (audit correlation of the completion record) |
| `created_client_address` | VARCHAR(45) | NOT NULL | — (″) |
| `created_at` | DATETIME(6) | NOT NULL | `createdAt` |
| `started_at` | DATETIME(6) | NULL | `startedAt` |
| `finished_at` | DATETIME(6) | NULL | `finishedAt` |
| `cancelled_by` | VARCHAR(255) | NULL | `cancelledBy.username` |

`counts` are computed: `SELECT job_id, outcome, COUNT(*) FROM restv2_mass_mail_recipients WHERE
job_id IN (…) GROUP BY job_id, outcome`, with `delivering` counted as `pending`.

Validation (`StoreMassMailJobRequest`): `sender` "required, e-mail address without control
characters, at most 255" (`required`, `invalidFormat` `email`); `subject` "required, 1 to 255
characters, single line"; `textBody` "required, non-empty, at most 1,048,576 bytes"; `htmlBody`
"optional, nullable, at most 1,048,576 bytes"; `selection` per `MassMailSelection` (research R19:
`notFound`, `notEligible` with `reason`, `mutuallyExclusive` with `with`, `tooMany` `max: 1000`);
`expectedRecipients` "required integer ≥ 1". After validation: count 0 → `tooFew` on `selection`;
count ≠ expected → `409 mass-mail-recipient-count-changed`.

List: sort `createdAt` (default desc), `status`; filter `status`; `q` → `subject LIKE ?`.
Retention: `restv2:mass-mail-purge` deletes non-running jobs older than
`RESTV2_AUDIT_RETENTION_DAYS`.

### MassMailRecipient (`restv2_mass_mail_recipients`)

Same migration file (second `Schema::create`), model `App\Models\MassMailRecipient`
(`$timestamps = false`).

| Column | Type | Constraint | Contract |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK AUTO_INCREMENT | — (`Message-ID` part) |
| `job_id` | CHAR(26) | NOT NULL, FK → `restv2_mass_mail_jobs.id` ON DELETE CASCADE | — |
| `address` | VARCHAR(255) | NOT NULL, UNIQUE (`job_id`, `address`) | `address` |
| `outcome` | VARCHAR(16) | NOT NULL DEFAULT `pending`, INDEX (`job_id`, `outcome`, `next_attempt_at`) | `outcome` (`delivering` exposed as `pending`) |
| `failure_reason` | VARCHAR(1024) | NULL | `failureReason` |
| `attempts` | TINYINT UNSIGNED | NOT NULL DEFAULT 0 | `attempts` |
| `next_attempt_at` | DATETIME(6) | NULL | — (retry schedule) |
| `last_attempt_at` | DATETIME(6) | NULL | `lastAttemptAt` |

List: sort `address` (default asc), `outcome`; filter `outcome` (`pending` matches `pending` and
`delivering`); `q` → `address LIKE ?`.

**Recipient state machine** (research R17):

```
            claim (job running, row locked)             LMTP 2xx
 pending ───────────────────────────────► delivering ───────────► delivered
   ▲  │                                      │  │  │
   │  │ cancel                               │  │  └─ LMTP 5xx, or 4th temporary failure ─► failed
   │  ▼                                      │  └──── runner restarted while delivering ──► failed ("interrupted")
   │ skipped ◄── temporary failure after ────┘
   │             the job was cancelled
   └──── temporary failure (attempts 1–3): next_attempt_at = now + 30/60/120 s
```

**Job state machine**:

```
 running ──(no pending or delivering recipient left)──► completed   (completion audit record)
    │
    └──(cancel, session)──► cancelled   (pending → skipped; cancellation audit record)
 completed ──cancel──► 409        cancelled ──cancel──► 200, unchanged
```

---

## 5. Files

| Path in the API container | Host path (mailcow) | Mode | Owner, mode on host | Read/write by |
|---|---|---|---|---|
| `/mailcow/rspamd_custom_maps/<map>.map` | `data/conf/rspamd/custom/` | rw | files 82:82 0644, directory `_rspamd` 0755 (rspamd entrypoint) | `RspamdRegexMapService` via `SharedFileWriter::replaceInPlace()` + touch (research R13) |
| `/mailcow/global_sieve/before`, `/after` | `data/conf/dovecot/global_sieve_before`, `_after` | rw (file mounts) | 82:82 (php-fpm entrypoint) | `GlobalSieveFilterService` via `replaceInPlace()` (R14) |
| `/mailcow/templates/{pw_reset_text,pw_reset_html,quota}.tpl` | `data/assets/templates/` | ro | — | default templates (R9) |
| `/mailcow/web/inc/presets/{rspamd,sieve}/*.yml` | `data/web/inc/` (001's mount) | ro | — | `PresetCatalog` (R22) |
| `/mailcow/web/lang/lang.*.json` | `data/web/lang/` | ro | — | English preset headlines, legacy license strings (R20, R22) |

Tags of files: `sha256` of the file content. A missing map or filter file reads as empty content
(`available: false` for maps) and refuses writes with 409.

**Regex map** (`RspamdRegexMap`): `id` = file name from `MailcowConfig::rspamdRegexMaps()`, `name`
= its key, `content` = file content. Validation (`ReplaceRspamdRegexMapRequest` +
`RegexMapValidator`): `content` "string, at most 1,048,576 bytes" (`tooLong` `unit: bytes`), every
line valid (`invalidRegexLine`, `line`, `reason`). Stored as `trim(content) . "\n"`. List sort
`name`, `id` (default: configured order); `q` → name contains.

**Global Sieve filter** (`GlobalSieveFilter`): `id` `prefilter` → `before`, `postfilter` →
`after`. Validation (`ReplaceGlobalSieveFilterRequest` + 005's `SieveValidator`): `script` "string,
at most 1,048,576 bytes after normalising line endings", valid Sieve unless empty or comments only
(`sieveSyntax`, `message`, `line`); `deferRestart` boolean.

---

## 6. Container service and network calls

| Call | Client | Request | Success | Failure mapping |
|---|---|---|---|---|
| Dovecot restart | 002's `DockerApiClient::restart('dovecot-mailcow')` | `POST https://dockerapi/containers/<id>/restart` | `type: success` → `dovecotRestart.outcome = done` | `failed` → `failed` (+ message); timeout → `failed` ("may still happen") |
| Rspamd password | `DockerApiClient::exec('rspamd-mailcow', {cmd: rspamd, task: worker_password, raw: <password>})` | `POST https://dockerapi/containers/<id>/exec` | `type: success` → 204 | `failed` → 502 `container-action-failed`; timeout → 504 `outcome-unknown` |
| Effective settings map | `App\Clients\Rspamd\SettingsMapClient` | `GET http://nginx:8081/settings.php` | 200 body | error, timeout, non-200 → 503 `temporary-failure` |
| Mass mail delivery | `App\Clients\Lmtp\LmtpClient` | LMTP to `dovecot-mailcow:24`: `LHLO`, `MAIL FROM`, `RCPT TO`, `DATA` | 2xx → delivered | 4xx/connection → retry; 5xx → failed |
| License verification | Laravel HTTP client in `LicenseService` | `POST https://verify.mailcow.email` form `guid=<GUID>` | JSON `response: ok|invalid` | anything else → `unreachable` (stored, not an error) |

Tests fake the first four behind their interfaces (constitution VII) and the license service with
`Http::fake()`.

---

## 7. Audit events (feature 001's `restv2_audit_events`)

`details` keys are whitelisted per event type in `AuditLogger` (001-R22). Every refusal record
carries `details.problemType` and, for validation, `details.errors` (field → codes, no values).

| `eventType` | `target.type` | `target.id` / `name` | `details` (success) |
|---|---|---|---|
| `logoReplacement` | `logo` | `light` / `dark` | `old`, `new`: `{mediaType, sizeBytes, width, height}` |
| `logoDeletion` | `logo` | variant | `old` |
| `uiTextsUpdate` | `uiTexts` | `null` | `changes`: plain fields `{old, new}`; `loginHelpHtml`, `footerHtml`, `announcement.text` as `{changed, oldBytes, newBytes}`; `footerMigrated` |
| `loginPageUpdate` | `loginPage` | `null` | `changes` `{field: {old, new}}` |
| `appLinksUpdate` | `appLinks` | `null` | `oldCount`, `newCount`, `names` (new list of names) |
| `brandColorsUpdate` | `brandColors` | `null` | `changes` `{light, dark: {old, new}}` |
| `ipCheckUpdate` | `ipCheck` | `null` | `changes` |
| `passwordPolicyUpdate` | `passwordPolicy` | `null` | `changes` |
| `passwordResetNotificationUpdate` | `passwordResetNotification` | `null` | `changes` (sender, subject `{old, new}`; templates `{changed, oldBytes, newBytes}`) |
| `quotaNotificationUpdate` | `quotaNotification` | `null` | `changes` (template as sizes) |
| `quotaNotificationRecipientsUpdate` | `quotaNotificationRecipients` | domain | `changes` (`recipients` old/new lists, `active`) |
| `rspamdSettingsRuleCreation` / `Update` / `Deletion` | `rspamdSettingsRule` | id / description | `changes` (`content` as sizes) |
| `rspamdRegexMapReplacement` | `rspamdRegexMap` | file / name | `oldBytes`, `newBytes` |
| `globalSieveFilterReplacement` | `globalSieveFilter` | `prefilter` / `postfilter` | `oldBytes`, `newBytes`, `dovecotRestart` (`done`, `failed`, `deferred`), `restartDetail` |
| `rspamdUiPasswordChange` | `rspamdUiPassword` | `null` | `passwordChanged: true`, `rspamdConfirmed` |
| `legacyApiCorsUpdate` | `legacyApiCors` | `null` | `changes` |
| `massMailJobCreation` | `massMailJob` | job id / subject | `sender`, `subject`, `selection` summary (`domains` count and names up to 20, `includeCount`, `excludeCount`), `recipientCount` |
| `massMailJobCompletion` | `massMailJob` | job id / subject | `counts`; actor fields `null`, creation `requestId` and client address |
| `massMailJobCancellation` | `massMailJob` | job id / subject | `counts` at confirmation |
| `licenseVerification` | `license` | `null` | `result` (`valid`, `invalid`, `unreachable`) |

---

## 8. Value objects (no storage)

- `App\Support\Color\WcagContrast`: luminance, ratio, effective colour and foreground (R5).
- `App\Support\Images\ImageInspection`: `mediaType`, `sizeBytes`, `width`, `height`.
- `App\Support\Rspamd\StructureViolation`, `RegexLineViolation`: `line`, `construct` / `reason`.
- `App\Services\MassMail\Selection`: normalised `domains`, `include`, `exclude`, with `summary()`.
- `App\Clients\Lmtp\LmtpResult`: `status`, `code`, `text`.
- `App\Services\Settings\SettingsLimits`: the constants returned by the capabilities and used by the
  Form Requests.
