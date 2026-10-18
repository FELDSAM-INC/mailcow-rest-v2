# Contracts of feature 013

The contract of this feature is not duplicated here. The OpenAPI 3.1 document in the repository's
[`api/`](../../../api/) directory is the single source of truth (constitution I).

Modules of this feature (one kebab-case file per path, one `_index.yaml` per module):

- `api/modules/settings-server/`: `GET /capabilities/settings`, `GET|PATCH /settings/legacy-api-cors`.
- `api/modules/settings-branding/`: `/settings/logos/{variant}`, `/settings/ui-texts`,
  `/settings/login-page`, `/settings/app-links`, `/settings/brand-colors`, `/settings/ip-check`.
- `api/modules/settings-password/`: `/settings/password-reset-notification`.
- `api/modules/password-policy/password-policy.yaml` (feature 003's module): the `patch` operation
  `updatePasswordPolicy` of `/password-policy`, next to 003's read.
- `api/modules/settings-quota-notifications/`: `/settings/quota-notification`,
  `/domains/{domain}/quota-notification-recipients`.
- `api/modules/settings-rspamd/`: `/settings/rspamd-rules[/{ruleId}]`,
  `/settings/rspamd-regex-maps[/{mapId}]`, `/settings/rspamd-settings-map`,
  `/settings/rspamd-ui-password`.
- `api/modules/settings-global-filters/`: `/settings/global-sieve-filters[/{filterId}]`.
- `api/modules/presets/`: `/presets/{presetKind}`.
- `api/modules/mass-mail/`: `/mass-mail-jobs`, `/mass-mail-jobs/recipient-preview`,
  `/mass-mail-jobs/{jobId}`, `/mass-mail-jobs/{jobId}/recipients`,
  `/mass-mail-jobs/{jobId}/cancellation`.
- `api/modules/license/`: `/license`, `/license/verification`.

Components created by this feature (`api/components/`):

- schemas: `SettingsCapabilities`, `SettingsLimits`, `SettingsValidationResult`, `LogoSetting`,
  `UiTexts`, `UiTextsAnnouncement`, `UiTextsPatch`, `LoginPageSettings`, `LoginPageSettingsPatch`,
  `AppLinkSettings`, `AppLinkSettingsEntry`, `AppLinkSettingsPatch`, `BrandColors`,
  `BrandColorsPatch`, `EffectiveBrandColor`, `EffectiveBrandColors`, `IpCheckSetting`,
  `IpCheckSettingPatch`, `PasswordPolicyPatch`, `PasswordResetNotification`,
  `PasswordResetNotificationPatch`, `QuotaNotificationSettings`, `QuotaNotificationSettingsPatch`,
  `QuotaNotificationRecipients`, `QuotaNotificationRecipientsPatch`, `RspamdSettingsRule`,
  `RspamdSettingsRuleCreateRequest`, `RspamdSettingsRulePatch`, `RspamdRegexMap`,
  `RspamdRegexMapReplaceRequest`, `RspamdEffectiveSettingsMap`, `RspamdUiPasswordRequest`,
  `GlobalSieveFilter`, `GlobalSieveFilterReplaceRequest`,
  `DovecotRestartOutcome`, `LegacyApiCorsSettings`, `LegacyApiCorsSettingsPatch`, `Preset`,
  `MassMailSelection`, `MassMailPreviewRequest`, `MassMailPreviewRecipient`, `MassMailJob`,
  `MassMailJobStatus`, `MassMailJobCreateRequest`, `MassMailRecipient`, `MassMailRecipientOutcome`,
  `MassMailRecipientCounts`, `MassMailRecipientCountProblem`, `MassMailRunningProblem`,
  `LicenseStatus`;
- parameters: `RspamdSettingsRuleId`, `RspamdSettingsRuleSort`, `RspamdRegexMapId`,
  `RspamdRegexMapSort`, `GlobalSieveFilterId`, `PresetKind`, `PresetSort`, `MassMailJobId`,
  `MassMailJobSort`, `MassMailRecipientSort`, `SettingsValidateOnly`;
- responses: one `…Response` per representation above, plus `SettingsTemporaryFailure`,
  `BrandingForbidden`, `LicenseForbidden`, `RspamdRegexMapUnavailable`,
  `GlobalSieveFilterUnavailable`, `MassMailConflict`, `RspamdUiPasswordServiceFailure`,
  `RspamdUiPasswordOutcomeUnknown`, `MassMailPreviewResponse`.

Shared components reused: `ListMeta`, `Problem`, `ValidationProblem`, `Limit`, `Offset`, `Order`,
`Search`, `IfMatch`, `LogoVariant`, `LoginPageOptions`, `AppLink`, `AccountRef`, `DomainName`
(002), `PasswordPolicy` and `PasswordPolicyResponse` (003), `ContainerProblem` (011), and the
standard responses. Shared files extended additively: `PublicConfig` (`brandColors`),
`KnownProblemType`, `AuditEventType`, `AuditTarget`, `AuditFailureReason`, `ValidationRuleCode`,
`api/openapi.yaml` (tags and paths) and `docs/problems.md` (feature 013 section).

The 45 operations, with their authentication and requirements, are listed in
[plan.md](../plan.md#contract-operations). Check the contract with:

```bash
npx --yes @redocly/cli@2.64.1 lint api/openapi.yaml
npx --yes @redocly/cli@2.64.1 bundle api/openapi.yaml -o build/contract/openapi.yaml
```
