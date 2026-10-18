# Implementation Plan: System Settings

**Branch**: `main` (no feature branches, constitution 1.1.0) | **Date**: 2026-10-18 |
**Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/013-system-settings/spec.md`

## Summary

Feature 013 lets administrators manage the server-wide settings of mailcow through the API:

- branding: light and dark logos (content-detected, size-limited, SVGs with active content
  refused), UI texts, login page options, custom app links, API-owned brand colours with WCAG 2.2
  contrast checks (published in 001's public configuration) and the IP check opt-in;
- the password policy (as the change operation of 003's `/password-policy` resource) and the
  password reset notification;
- quota notifications and each domain's copy recipients (domain administrators for their domains);
- Rspamd settings rules, the global regex maps, the effective settings map, the presets and the
  Rspamd web interface password;
- the global Sieve pre- and post-filter with an explicit Dovecot restart;
- the CORS settings of the legacy `/api/v1`;
- background mass mail delivered into the mailboxes over LMTP, with a confirmed preview, a rate
  limit, cancellation and per-recipient outcomes;
- the GUID and its verification against mailcow's license service, switchable per server;
- a settings capabilities read with every limit and server switch a client needs before submitting
  (orchestrator decision).

The technical approach:

- The OpenAPI 3.1 contract was authored first: 44 operations in 9 new modules plus one operation
  in 003's `password-policy` module. It lints clean.
- Legacy's storage is written exactly as legacy writes it: Redis keys in legacy's formats with
  atomic WATCH/MULTI/EXEC transactions, the `settingsmap` table, and the shared files that
  rspamd-mailcow and dovecot-mailcow read, mounted into the API container as php-fpm-mailcow
  mounts them and rewritten in place (the shared directories forbid atomic renames).
- Side effects go through 002's dockerapi client (Dovecot restart, Rspamd password), an HTTP
  client (effective settings map, license service) and a new LMTP client (mass mail).
- Mass mail jobs live in two `restv2_` tables and are processed by an artisan runner started by
  mailcow's ofelia scheduler, with at-most-once delivery per recipient.
- Tests come first, run on MariaDB with the `2026-09a` schema and on a real Redis, fake dockerapi,
  LMTP and the settings map behind interfaces, use real temporary directories for the shared
  files, and validate every request and response against the contract.

## Technical Context

**Language/Version**: PHP 8.4, Laravel 13.x (001)

**Primary Dependencies**: 001's stack, plus `symfony/yaml` ^7 (presets) and an explicit
`symfony/mime` ^7 (already installed with Laravel's mailer; mass mail messages), and the PHP
extension `gd` (logo decode check). Justifications in [research R27](./research.md#r27-dependencies-constitution-viii).

**Storage**: mailcow's Redis database 0 (legacy keys: `MAIN_LOGO*`, UI texts, `CUSTOM_LOGIN`,
`APP_LINKS`, `IP_CHECK`, `PASSWD_POLICY`, `PW_RESET_*`, `QW_*`, `QW_BCC`, `CORS_SETTINGS`,
`LICENSE_STATUS_CACHE`); API-owned Redis keys `restv2:brand-colors`,
`restv2:license:last-verification`; upstream table `settingsmap` (read and written), `domain`,
`alias_domain`, `domain_admins`, `mailbox`, `versions` (read); API-owned tables
`restv2_mass_mail_jobs` and `restv2_mass_mail_recipients`; shared files
`data/conf/rspamd/custom/*.map`, `data/conf/dovecot/global_sieve_{before,after}` (read and
written), `data/assets/templates/*.tpl`, `data/web/inc/presets/`, `data/web/lang/` (read). See
[data-model.md](./data-model.md).

**Testing**: PHPUnit 12 feature and unit tests on MariaDB 10.11 with the schema fixture and Redis
7.4 (001-R3), with contract validation of every request and response (001-R13; the matcher is
extended for binary request bodies). Faked behind interfaces: `DockerApiClient` (002's fake),
`LmtpClient`, `SettingsMapClient`; the license service with `Http::fake()`. Shared files live in a
per-test temporary directory that the configuration points to. An LMTP stub server tests the socket
adapter. Delays use 001's injectable `Sleeper`.

**Target Platform**: 001's API container (`php:8.4-fpm-alpine`, uid 82, read-only root file
system) in mailcow-dockerized, with extra mounts identical to php-fpm-mailcow's and two ofelia
jobs (research R26).

**Project Type**: web service (REST API).

**Performance Goals**: SC-013: 95% of settings reads, rule pages and recipient pages within 1 s
(a few Redis reads or one indexed query). SC-005: regex maps and rules reach Rspamd within 2
minutes without a restart. SC-009: 10,000 recipients in under 20 minutes at 10 messages/s.
SC-008: no message later than 5 s after a confirmed cancellation (5 s LMTP deadline).

**Constraints**:

- No change to upstream tables, containers or their files other than the data legacy itself edits.
- Legacy UI, `/api/v1`, Dovecot and Rspamd keep reading the same keys, rows and files in the same
  formats; the legacy UI can still overwrite API changes (it sends no version tags).
- The Rspamd password and other secrets never appear in logs, audit records or responses.
- Mass mail never passes Postfix or Rspamd; at most one copy per recipient; one running job.
- Request bodies up to 5 MiB, logo bodies up to 16 MiB (nginx), as legacy's limits require.

**Scale/Scope**: One server with up to about 10,000 mailboxes; 13 regex maps of up to 1 MiB; a few
hundred settings rules; mass mail jobs of up to tens of thousands of recipients, a few per year.
44 operations in 9 modules (plus one in 003's module), 2 API-owned tables, 8 user stories.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design (below).*

| Principle | Gate | Status |
|---|---|---|
| I. Contract-first | All 45 operations (44 in 013's modules, `updatePasswordPolicy` in 003's module) are in `api/` before code; `npx @redocly/cli@2.64.1 lint` passes; every test request and response is validated; the coverage check includes them. | PASS |
| II. HTTP contract | Plural nouns and sub-resources (`/mass-mail-jobs/{jobId}/cancellation`, `/license/verification`, `/mass-mail-jobs/recipient-preview`); PATCH is JSON Merge Patch for every settings resource; PUT replaces content (logo, regex map, Sieve script, Rspamd password); 201 + `Location` for rules and jobs, 200 for reads, updates and actions, 204 for deletions and the write-only password; `{data, meta}` lists with `limit`/`offset`/`sort`/`order`/`q`; RFC 9457 problems; `ETag` + `If-Match` on every mutable resource and every list entry. Status codes 502/503/504 reuse earlier features' justification (Complexity Tracking). | PASS (justified) |
| III. Upstream schema | No migration touches an upstream table; the two new tables carry `restv2_` (001's `MigrationPrefixTest` covers them). `settingsmap` is written with its existing columns only. | PASS |
| IV. Parity | Legacy code read for every operation (research, spec parity table). Writes go through services in `app/Services/` and reproduce legacy's side effects: Redis formats byte-identical (`htmlspecialchars`, `json_encode` without flags), map files trimmed + newline + touch, Sieve files + Dovecot restart, Rspamd password through dockerapi, license cache in legacy's format, LMTP delivery with `X-Mailer: MooMassMail`. Deviations are only the spec's, plus the `QW_BCC` list format added to the spec's parity table. | PASS |
| V. Coexistence | Own container; extra mounts are the same host paths php-fpm-mailcow mounts; nginx changes stay in the API's own `site.restv2.custom`; ofelia runs jobs inside the API container; no other container or file is patched. | PASS |
| VI. Security | Object-level authorisation per role and key type (policies; domain administrators reach only their domains' copy recipients, 404 otherwise); mass mail and the Rspamd password need a session; read-only keys refused on writes; SVGs with active content refused; the Rspamd password never stored, logged or audited; audit record for every state change and every refused attempt that reaches the feature. | PASS |
| VII. Test-first | `tasks.md` orders tests before implementation in every phase; MariaDB with the real schema, real Redis, fakes asserting dockerapi, LMTP and settings map calls; side effects asserted on Redis keys, files and fakes. | PASS |
| VIII. Simplicity | Two small packages and one bundled extension (R27); no queue broker, no image library beyond GD, no SVG or UCL library; abstractions only where a fake or a second adapter is needed (`LmtpClient`, `SettingsMapClient`) or reused from 002 (`DockerApiClient`). | PASS |
| Cross-feature conventions | Out-of-scope domains answer 404; lists default to 25 (max 1,000) with `q`; tags over stored settings, on list entries too, shared with 003 for the policy; keys act as their owner, mass mail and the Rspamd password need a session; secrets write-only; invalid input refused with rule codes; no time-based caching; legacy PHP configuration through 001's reader; FeldHost license behaviour is a server setting defaulting to upstream; no bulk actions (FR-092). | PASS |
| Runtime constraints | Stateless php-fpm; the runner is an artisan command run by ofelia (no long-lived worker); configuration from environment variables with `mailcow.conf` names (`DEMO_MODE`, `MAILCOW_HOSTNAME`, `COMPOSE_PROJECT_NAME`). | PASS |

**Post-design re-check (after Phase 1)**: PASS. The decisions that touched gates:

- In-place rewriting of shared files instead of atomic renames (IV/V/VI; spec FR-058 aligned by a
  plan-level clarification, research R13).
- The mass mail runner on ofelia instead of a queue worker (V, VIII; research R17).
- `PATCH /password-policy` inside 003's path item, so the read and the change share one resource
  and tag (I, conventions; research R8).
- The preview as a POST that read-only keys may use, through 005's `restv2.readOnly` marker (II,
  VI; research R19).

## Parity notes added to the spec

- The spec's parity table now records that quota copy recipients are always written as a JSON list
  (legacy writes an object after dropping entries, which Dovecot then iterates by key).
- A plan-level clarification records that regex maps are rewritten in place and re-touched, as in
  legacy, because the shared directory forbids atomic renames (FR-058 aligned).
- The orchestrator decisions (settings capabilities FR-094, preview search in FR-072, no separate
  effective app links read in FR-028) are recorded as clarifications.

## Contract Operations

| Method and path | operationId | Auth | Story / FR |
|---|---|---|---|
| GET `/settings/capabilities` | getSettingsCapabilities | admin session or admin key (ro too) | US1, US6, US7, FR-094 |
| GET `/settings/logos/{variant}` | getLogoSetting | admin session or key | US1, FR-018 |
| PUT `/settings/logos/{variant}` | replaceLogo | admin session + CSRF or rw key | US1, FR-012–FR-016 |
| DELETE `/settings/logos/{variant}` | deleteLogo | admin session + CSRF or rw key | US1, FR-017 |
| GET `/settings/ui-texts` | getUiTexts | admin | US1, FR-019 |
| PATCH `/settings/ui-texts` | updateUiTexts | admin write | US1, FR-019–FR-023 |
| GET `/settings/login-page` | getLoginPageSettings | admin | US1, FR-024 |
| PATCH `/settings/login-page` | updateLoginPageSettings | admin write | US1, FR-024 |
| GET `/settings/app-links` | getAppLinkSettings | admin | US1, FR-025, FR-027 |
| PATCH `/settings/app-links` | updateAppLinkSettings | admin write | US1, FR-025–FR-028 |
| GET `/settings/brand-colors` | getBrandColors | admin | US1, FR-029–FR-031 |
| PATCH `/settings/brand-colors` | updateBrandColors | admin write | US1, FR-029–FR-034 |
| GET `/settings/ip-check` | getIpCheckSetting | admin | US7, FR-035 |
| PATCH `/settings/ip-check` | updateIpCheckSetting | admin write | US7, FR-035 |
| PATCH `/password-policy` (003's module) | updatePasswordPolicy | admin write | US2, FR-036–FR-038 |
| GET `/settings/password-reset-notification` | getPasswordResetNotification | admin | US2, FR-039–FR-042 |
| PATCH `/settings/password-reset-notification` | updatePasswordResetNotification | admin write | US2, FR-039–FR-042 |
| GET `/settings/quota-notification` | getQuotaNotificationSettings | admin | US5, FR-043–FR-045 |
| PATCH `/settings/quota-notification` | updateQuotaNotificationSettings | admin write | US5, FR-043–FR-045 |
| GET `/domains/{domain}/quota-notification-recipients` | getQuotaNotificationRecipients | admin, domain admin (own domains) | US5, FR-046–FR-049 |
| PATCH `/domains/{domain}/quota-notification-recipients` | updateQuotaNotificationRecipients | admin, domain admin (own domains), write | US5, FR-046–FR-049 |
| GET `/settings/rspamd-rules` | listRspamdSettingsRules | admin | US3, FR-050–FR-052 |
| POST `/settings/rspamd-rules` | createRspamdSettingsRule | admin write | US3, FR-050–FR-055, FR-093 |
| GET `/settings/rspamd-rules/{ruleId}` | getRspamdSettingsRule | admin | US3, FR-050 |
| PATCH `/settings/rspamd-rules/{ruleId}` | updateRspamdSettingsRule | admin write | US3, FR-053, FR-093 |
| DELETE `/settings/rspamd-rules/{ruleId}` | deleteRspamdSettingsRule | admin write | US3, FR-054 |
| GET `/settings/rspamd-regex-maps` | listRspamdRegexMaps | admin | US3, FR-056 |
| GET `/settings/rspamd-regex-maps/{mapId}` | getRspamdRegexMap | admin | US3, FR-056, FR-059 |
| PUT `/settings/rspamd-regex-maps/{mapId}` | replaceRspamdRegexMap | admin write | US3, FR-057–FR-059, FR-093 |
| GET `/settings/rspamd-settings-map` | getEffectiveRspamdSettingsMap | admin | US3, FR-060 |
| PUT `/settings/rspamd-ui-password` | setRspamdUiPassword | admin session + CSRF only | US7, FR-006, FR-066, FR-067 |
| GET `/settings/global-sieve-filters` | listGlobalSieveFilters | admin | US4, FR-062 |
| GET `/settings/global-sieve-filters/{filterId}` | getGlobalSieveFilter | admin | US4, FR-062 |
| PUT `/settings/global-sieve-filters/{filterId}` | replaceGlobalSieveFilter | admin write | US4, FR-063–FR-065, FR-093 |
| GET `/presets/{presetKind}` | listPresets | admin (rspamd, sieve), domain admin (sieve) | US3, FR-061 |
| GET `/settings/legacy-api-cors` | getLegacyApiCorsSettings | admin | US7, FR-068–FR-071 |
| PATCH `/settings/legacy-api-cors` | updateLegacyApiCorsSettings | admin write | US7, FR-068–FR-071 |
| GET `/mass-mail-jobs` | listMassMailJobs | admin | US6, FR-080, FR-081 |
| POST `/mass-mail-jobs` | createMassMailJob | admin session + CSRF only | US6, FR-006, FR-073–FR-079 |
| POST `/mass-mail-jobs/recipient-preview` | previewMassMailRecipients | admin session + CSRF or admin key (ro too) | US6, FR-072, FR-073, FR-081 |
| GET `/mass-mail-jobs/{jobId}` | getMassMailJob | admin | US6, FR-077 |
| GET `/mass-mail-jobs/{jobId}/recipients` | listMassMailJobRecipients | admin | US6, FR-077 |
| POST `/mass-mail-jobs/{jobId}/cancellation` | cancelMassMailJob | admin session + CSRF only | US6, FR-006, FR-078 |
| GET `/license` | getLicenseStatus | admin | US7, FR-082, FR-085 |
| POST `/license/verification` | verifyLicense | admin write | US7, FR-083–FR-085 |
| GET `/audit-events` (001, filters by 002) | listAuditEvents | admin | US8, FR-086–FR-088 (new event and target values only) |

"admin" = a signed-in administrator or a key owned by an administrator (read-only keys included
for reads); "admin write" = a signed-in administrator with CSRF or an administrator's read-write
key. Problem types are in [`docs/problems.md`](../../docs/problems.md) (section of feature 013).

## Project Structure

### Documentation (this feature)

```text
specs/013-system-settings/
├── spec.md              # Feature specification (clarifications incl. orchestrator input)
├── plan.md              # This file
├── research.md          # Phase 0: decisions R1–R29
├── data-model.md        # Phase 1: Redis keys, tables, files, calls, audit events
├── quickstart.md        # Phase 1: validation guide
├── contracts/README.md  # Phase 1: pointer to api/ modules
├── checklists/requirements.md
├── tasks.md             # Phase 2 (/speckit-tasks)
└── analysis.md          # /speckit-analyze summary
```

### Source Code (repository root)

New files of this feature, inside 001's layout (001 plan, Project Structure); files of earlier
features that 013 extends are marked *(extends)*.

```text
api/
├── modules/{settings-branding,settings-password,settings-quota-notifications,settings-rspamd,
│            settings-global-filters,settings-server,presets,mass-mail,license}/
├── modules/password-policy/password-policy.yaml        # (extends 003) updatePasswordPolicy
└── components/{schemas,parameters,responses}/          # 013-named components (contracts/README.md)

app/
├── Clients/
│   ├── Lmtp/{LmtpClient,SocketLmtpClient,LmtpResult}.php
│   └── Rspamd/{SettingsMapClient,HttpSettingsMapClient}.php
├── Console/Commands/{MassMailRunCommand,MassMailPurgeCommand}.php
├── Http/
│   ├── Controllers/Api/V2/
│   │   ├── SettingsCapabilitiesController.php
│   │   ├── LogoSettingController.php, UiTextsController.php, LoginPageSettingsController.php,
│   │   │   AppLinkSettingsController.php, BrandColorsController.php, IpCheckSettingController.php
│   │   ├── PasswordPolicyController.php                 # (extends 003) update()
│   │   ├── PasswordResetNotificationController.php
│   │   ├── QuotaNotificationSettingsController.php, QuotaNotificationRecipientsController.php
│   │   ├── RspamdSettingsRuleController.php, RspamdRegexMapController.php,
│   │   │   RspamdSettingsMapController.php, RspamdUiPasswordController.php
│   │   ├── GlobalSieveFilterController.php, PresetController.php, LegacyApiCorsController.php
│   │   ├── MassMailJobController.php, MassMailRecipientPreviewController.php,
│   │   │   MassMailJobRecipientController.php, MassMailJobCancellationController.php
│   │   └── LicenseController.php, LicenseVerificationController.php
│   └── Requests/Api/V2/Settings/                        # one Form Request per write (data-model.md)
├── Models/
│   ├── Mailcow/RspamdSettingsRule.php
│   └── {MassMailJob,MassMailRecipient}.php
├── Policies/{SettingsPolicy,QuotaNotificationRecipientsPolicy,PresetPolicy,MassMailJobPolicy}.php
├── Rules/{SingleLineText,MailcowEmail,AppLinkUrl,HexColor,LegacyOrigin,ByteLength}.php
├── Services/
│   ├── Settings/{RedisSettingsStore,SettingsLimits,BrandingService,BrandColorService,
│   │             PasswordResetNotificationService,QuotaNotificationService,
│   │             QuotaNotificationRecipientsService,LegacyApiCorsService,LicenseService,
│   │             SettingsCapabilitiesService}.php
│   ├── PasswordPolicy/PasswordPolicyService.php        # (extends 003) update()
│   ├── Rspamd/{RspamdSettingsRuleService,RspamdRegexMapService,RspamdUiPasswordService}.php
│   ├── GlobalFilters/GlobalSieveFilterService.php
│   ├── Presets/PresetCatalog.php
│   ├── MassMail/{SelectionResolver,Selection,MassMailJobService,MassMailRunner,
│   │             MassMailMessageFactory}.php
│   ├── Branding/PublicConfigService.php                 # (extends 001) brandColors
│   └── Config/MailcowConfig.php                         # (extends 001) logoLimits(), rspamdRegexMaps()
└── Support/
    ├── Color/WcagContrast.php
    ├── Files/SharedFileWriter.php
    ├── Images/{ImageInspector,ImageInspection,SvgSafetyInspector}.php
    └── Rspamd/{UclStructureChecker,RegexMapValidator}.php

database/migrations/2026_10_18_013001_create_restv2_mass_mail_jobs_table.php
resources/bin/export-mailcow-vars.php                    # (extends 001) LOGO_LIMITS, RSPAMD_MAPS
routes/api.php                                           # (extends) 013 routes
config/restv2.php                                        # (extends) R2 settings
deploy/docker-compose.override.yml, deploy/nginx/site.restv2.custom   # (extend) R26
docker/php/Dockerfile, docker/php/php.ini                # (extend) gd, post_max_size

tests/
├── Feature/Settings/{Capabilities,Logo,UiTexts,LoginPage,AppLinks,BrandColors,IpCheck,
│                     PasswordPolicyUpdate,PasswordResetNotification,QuotaNotification,
│                     QuotaNotificationRecipients,LegacyApiCors,License}*Test.php
├── Feature/Rspamd/{RspamdSettingsRule,RspamdRegexMap,RspamdSettingsMap,RspamdUiPassword,Preset}*Test.php
├── Feature/GlobalFilters/GlobalSieveFilter*Test.php
├── Feature/MassMail/{Preview,Create,Runner,Cancellation,Read,Purge}*Test.php
├── Feature/Settings/SettingsAuditTest.php, SettingsAuthorizationMatrixTest.php
├── Unit/Support/{WcagContrast,ImageInspector,SvgSafetyInspector,UclStructureChecker,
│                 RegexMapValidator,SharedFileWriter}Test.php
├── Unit/Clients/{SocketLmtpClient,HttpSettingsMapClient}Test.php
├── Support/Fakes/{FakeLmtpClient,FakeSettingsMapClient}.php
├── Support/Lmtp/LmtpStubServer.php
├── Support/Settings/SharedFilesSandbox.php
└── fixtures/
    ├── logos/{valid,hostile}/…                          # SC-003 set
    └── mailcow/{rspamd-custom,global-sieve,templates,presets,lang}/   # copies from 2026-09a
```

**Structure Decision**: The single Laravel project of 001. Business logic sits in one service per
aggregate under `app/Services/` (settings group, Rspamd, global filters, mass mail, presets);
outbound systems behind `app/Clients/` (LMTP and the settings map new, dockerapi from 002); pure
legacy-parity helpers without side effects in `app/Support/`. Controllers are thin, one per
resource.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Status `502` (`container-action-failed`), `503` (`temporary-failure`), `504` (`outcome-unknown`), outside constitution II's list | Reused from features 011, 002/001 and 003 with their justification: a dependency failure, a temporary outage and an unknown outcome must be told apart from a bug (`500`) so that clients act correctly (FR-067, FR-091, conventions on `DOCKER_TIMEOUT`). | `500` cannot be told apart from a bug; a new 013-only type would split one meaning across features. |
| Shared map and filter files are rewritten in place, not atomically (FR-058 aligned) | The map directory belongs to Rspamd's user (0755) and the Sieve files are single-file bind mounts, so a new file cannot be created or renamed into place without patching another container (V) or running as root (VI). | Atomic rename: permission denied / `EBUSY`. dockerapi write: no such command. Running as `_rspamd`: php-fpm could no longer write the files. |
| Mass mail runner on mailcow's ofelia scheduler instead of a Laravel queue worker | Background delivery with at-most-once semantics, without a long-running process in the php-fpm container and without a second container. | A queue worker needs a supervisor or a second container and re-delivers after a crash (at-least-once). |
| A POST (recipient preview) that changes nothing and that read-only keys may use | The selection's lists (up to 1,000 addresses each) do not fit a URL; FR-081 lets administrators' keys read previews. Uses 005's `restv2.readOnly` marker. | `GET` with query lists exceeds nginx's 8 KiB request line. Refusing read-only keys contradicts FR-081. |
| `updatePasswordPolicy` lives in feature 003's module file | One resource with one version tag for read and change (003 FR-063, conventions); a path item can be defined only once. | A separate `/settings/password-policy` would split one resource across two URIs. |
