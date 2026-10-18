# Changelog

The contract version (`info.version` of `api/openapi.yaml`) is the API version. mailcowui-ng pins a
released contract; every release lists its contract changes.

## 0.1.0 — unreleased

Contract: the 31 operations of feature 001 (authentication, sessions, API keys, identity and
branding configuration):

- CSRF bootstrap and session state: `getCsrfToken`, `getSession`.
- Password sign-in with second factors (TOTP, WebAuthn, Yubico OTP) and sign-out: `createSession`,
  `deleteSession`, `cancelPendingSignIn`, `createSecondFactorWebAuthnChallenge`,
  `verifySecondFactor`.
- Passkey sign-in and the user's own passkeys: `createPasskeyChallenge`,
  `createSessionWithPasskey`, `listPasskeys`, `registerPasskey`,
  `createPasskeyRegistrationOptions`, `getPasskey`, `updatePasskey`, `deletePasskey`.
- Identity and preferences: `getIdentity`, `getPreferences`, `updatePreferences`.
- API keys: `listApiKeys`, `createApiKey`, `listApiKeyOwnerCandidates`, `getApiKey`,
  `revokeApiKey`.
- Public branding configuration: `getPublicConfig`, `getPublicConfigLogo`.
- Audit log: `listAuditEvents`.
- System: `getLiveness`, `getReadiness`, `getOpenApiDocument`, `getApiDocs`,
  `getProblemTypeDocumentation`.

Contract changes made while implementing feature 001:

- `listAuditEvents` has a repeatable `authMethod` filter and a `requestId` filter (gaps G18 and
  G19); values of shared enumerations that the server does not record match nothing.
- `ValidationRuleCode` documents `format: requestId`.
- `WebAuthnCreationOptions.attestation` is legacy's conveyance: `indirect`, `direct` only with
  `WEBAUTHN_ONLY_TRUSTED_VENDORS=y`, `none` when only the `none` format is accepted.
- `getPublicConfig`'s `Cache-Control` must contain `no-cache` (the server sends
  `no-cache, public`) instead of being exactly `no-cache`.
- The texts of `createSession` and `verifySecondFactor` describe the separate second-factor
  throttling.
- `getApiRoot` (owner request, 2026-10-18): `GET /api/v2/` answers `302` with the relative
  `Location: /api/v2/docs`, so a browser opening the base path lands on the documentation.

Release checks (quickstart Part C: SC-002, SC-003, SC-004, SC-006) ran on 2026-10-18 on the
FeldHost fork (upstream `2026-09a` baseline) with the image of 3a35973:

- SC-003 passes: the legacy UI, `/api/v1`, SOGo, IMAP and SMTP are unchanged; only the four
  `restv2_` tables were added, and `db_schema` is unchanged.
- SC-004 passes for the API's part: each failed sign-in publishes one `F2B_CHANNEL` message in the
  legacy text, ten for ten failures, as in the legacy UI. After the fork's netfilter fix, ten such
  messages made the unmodified netfilter ban the address on the tenth.
- SC-002 passes with a virtual authenticator: registration took 69 ms, and the first passkey
  sign-in completed 262 ms after the password sign-in. The browser stopwatch check is still open.
- SC-006 passes: the key was created and first used for a read within 196 ms.
- Still open: the browser passkey stopwatch. A plain upstream `2026-09a` host was waived by the
  owner (none is available).

Contract: the 16 operations of feature 002 (domains) and the audit target filters:

- Domains: `listDomains`, `createDomain`, `applyDomainBulkAction`, `getDomain`, `updateDomain`,
  `deleteDomain`, `previewDomainDeletion`, `getDomainFooter`, `replaceDomainFooter`,
  `getDomainsCapabilities`, `listDomainTags`.
- Domain templates: `listDomainTemplates`, `createDomainTemplate`, `getDomainTemplate`,
  `updateDomainTemplate`, `deleteDomainTemplate`.
- `listAuditEvents`: `targetType=domain|domainFooter` and `targetId` match domain names in any
  form and letter case; without `targetType`, a domain name matches the domain's and its footer's
  records.

Contract changes made while implementing feature 002:

- `Domain.footer` (`DomainFooterSummary`): whether a footer is set, its sizes and exclusion counts
  (G28); `DomainPatch` accepts it as a read-only member.
- `DomainCapabilities.templateDefaults` (`DomainTemplateDefaults`, administrators only) and
  `default` keywords in `DomainTemplateCreateRequest` (G29).
- `BulkTarget.version` accepts the version tag with or without its quotes (G33).
- `PermissionFlagRequiredProblem` declares `requiredFlags` and `requiredFlagsMatch` once; every
  feature's `*PermissionFlag*Problem` extends it (G34).
- `DomainCreationReport` has no `groupwareDirectoryUpdate`: like legacy, a domain creation does not
  rebuild the groupware directory.
- `defaultMailboxQuota` removed from domains and domain templates (owner decision, 2026-10-18: an
  upstream leftover that mailbox templates replace): from `Domain`, `DomainCreateRequest`,
  `DomainPatch`, `DomainTemplate`, `DomainTemplateCreateRequest`, `DomainTemplatePatch` and
  `DomainTemplateDefaults` (so also `DomainCapabilities.templateDefaults`), and from the `sort`
  values of `listDomains`. `DomainUsage.defaultNewMailboxQuota`, derived from it, is removed too.
  A sent `defaultMailboxQuota` is ignored like any undeclared member. The server keeps mailcow's
  columns valid for the classic interface without exposing or auditing them.

Release checks of feature 002 (quickstart Part C: SC-001, SC-002, SC-003, SC-008): not run yet.

Contract: the 17 operations of feature 003 (mailboxes, mailbox templates and the password policy
read), and 002's `applyDomainTemplateBulkAction`:

- Mailboxes: `listMailboxes`, `createMailbox`, `getMailboxCapabilities`, `listMailboxTags`,
  `applyMailboxBulkAction`, `getMailbox`, `updateMailbox`, `deleteMailbox`,
  `previewMailboxDeletion`, `renameMailbox`.
- Mailbox templates: `listMailboxTemplates`, `createMailboxTemplate`, `getMailboxTemplate`,
  `updateMailboxTemplate`, `deleteMailboxTemplate`, `applyMailboxTemplateBulkAction`.
- Password policy: `getPasswordPolicy` (feature 013 adds the change operation with the same tag).
- `listAuditEvents`: `targetType=mailbox|mailboxTemplate` with `targetId`.

Contract changes made while implementing feature 003:

- `MailboxCapabilities.preHashedPasswordSchemes`, the exact list of hash-scheme prefixes stored as
  given (G31), and `MailboxCapabilities.templateDefaults` (`MailboxTemplateDefaults`), the built-in
  values of new mailboxes and templates (G32).
- `OutcomeUnknownProblem`, shared by every `outcome-unknown` variant, declares `step` (G11);
  `MailboxOutcomeUnknownProblem` extends it.
- Every `503 temporary-failure` now carries `dependency` (`sharedStore` for mailcow's Redis; for
  mailboxes also `mailStore`, `webmail` and `database`), additively.
- `applyMailboxTemplateBulkAction` (`POST /mailbox-templates/bulk-actions`) and
  `applyDomainTemplateBulkAction` (`POST /domain-templates/bulk-actions`) delete up to 100 templates
  at once, as legacy's `delete_selected` does (G39, G40). Legacy's mass deletion of domain templates
  stops after the first template; the API deletes every target.
- Bulk actions resolve and deduplicate every target before applying any, so an object named twice
  in different spellings is deleted and reported once.
- `createMailbox` without a quota takes it from the template chain (named template, Default
  template, unlimited) instead of the domain's default mailbox quota, which the API no longer has
  (owner decision, 2026-10-18); the texts of `MailboxCreateRequest` and `MailboxTemplateDefaults`
  say so.

Release checks of feature 003 (quickstart Part C: SC-001, SC-003, SC-007, SC-008): not run yet.

Contract: the 23 operations of feature 004 (aliases, alias domains, resources and sender settings):

- Aliases: `listAliases`, `createAlias`, `applyAliasBulkAction` (activate, deactivate, show or hide
  in the groupware, delete, copy over alias domains), `getAlias`, `updateAlias`, `deleteAlias`,
  `copyAliasOverAliasDomains`, `getAliasesCapabilities`.
- Alias domains: `listAliasDomains`, `createAliasDomain`, `applyAliasDomainBulkAction`,
  `getAliasDomain`, `updateAliasDomain`, `deleteAliasDomain`.
- Resources: `listResources`, `createResource`, `applyResourceBulkAction`, `getResource`,
  `updateResource`, `deleteResource`.
- Sender settings: `getMailboxSenderSettings`, `updateMailboxSenderSettings`,
  `listSenderPermissionCandidates`.
- Problem types: `domain-alias-limit-reached` (registered with 003), `alias-domain-already-exists`
  and `not-primary-domain-alias`; the shared `address-in-use`, `attribute-not-permitted`,
  `permission-flag-required` and `temporary-failure` are reused.
- Values appended to shared enumerations: `AuditEventType` (`aliasCreation`, `aliasUpdate`,
  `aliasDeletion`, `aliasDomainCreation`, `aliasDomainUpdate`, `aliasDomainDeletion`,
  `resourceCreation`, `resourceUpdate`, `resourceDeletion`, `mailboxSenderSettingsUpdate`),
  `AuditTarget` (`alias`, `aliasDomain`, `resource`, `mailboxSenderSettings`), `KnownProblemType`
  (the two new types) and `ValidationRuleCode` (the formats `aliasAddress`, `emailOrDomain`,
  `senderPermission`, `resourceDescription` and the `notEligible` kinds `resource`,
  `externalDestination`, `relayDomain`, `aliasDomain`, `localDomain`).
- Server setting `RESTV2_ALIAS_DISABLE_EXTERNAL_DOMAINS` (legacy `ALIAS_DISABLE_EXTERNAL_DOMAINS`,
  default `false`).

Contract changes made while implementing feature 004:

- `MailboxSenderPermission.kind` (nullable for stale entries) and
  `MailboxSenderPermissionCandidate.kind` (`MailboxSenderPermissionKind`: `domain`, `aliasDomain`,
  `alias`, `mailbox`, `anyAddress`) (G12).
- Readable forms `Alias.destinationsUnicode`, `AliasCopyOutcome.addressUnicode` and
  `MailboxSenderSettings.externalSenderAddressesUnicode` (G13); `AliasPatch` and
  `MailboxSenderSettingsPatch` accept them as ignored members.
- `MailboxSenderSettingsPatch` names the refusal parameter `kind: localDomain` (G14).

Release checks of feature 004 (quickstart Part C): not run yet.

Contract: the 42 operations of feature 011 (monitoring and operations), administrators only:

- Host and installation: `getHostOverview`, `getMailStorageUsage`, `getPublicAddresses`,
  `getInstallationVersion`, `getUpdateStatus`, `getMonitoringCapabilities`.
- Containers: `listContainers`, `getContainer`, `getContainerResourceUsage`, `startContainer`,
  `stopContainer`, `restartContainer`.
- Logs: `listPostfixLog`, `listDovecotLog`, `listSogoLog`, `listNetfilterLog`,
  `listAutodiscoverLog`, `listWatchdogLog`, `listAcmeLog`, `listCronLog`, `listRateLimitLog`,
  `listLegacyApiRequestLog`, `listLegacyUiActivityLog`, `listLoginLog`.
- Rspamd: `listRspamdHistory`, `getRspamdStatistics`.
- Mail queue: `listMailQueueMessages`, `getMailQueueMessageContent`, `bulkMailQueueMessages`,
  `flushMailQueue`, `purgeMailQueue`.
- Ban service: `getFail2BanSettings`, `updateFail2BanSettings`, `rotateFail2BanBanListId`,
  `getFail2BanRules`, `replaceFail2BanRules`, `resetFail2BanRules`, `listFail2BanActiveBans`,
  `liftFail2BanBan`, `allowlistFail2BanBan`, `denylistFail2BanBan`, `listFail2BanPermanentBans`.
- Shared enumerations: `KnownProblemType` (`container-protected`, `container-not-running`,
  `container-action-failed`, `mail-service-not-running`, `queue-action-failed`,
  `ban-rules-not-restored`, `ip-check-disabled`), 17 `AuditEventType` values, 7 `AuditTarget`
  types, `AuditFailureReason` `serviceFailure` and `rulesNotRestored`, `ValidationRuleCode`
  `missingCaptureGroup`.

Contract changes made while implementing feature 011:

- `LogBefore`: `before` always selects older entries and `order` sorts the selection; with
  `before`, `meta.total` counts the remaining entries (G6, G9).
- `Fail2BanSettingsPatch` declares the parameter ranges as `minimum`/`maximum` (G7).
- The shared `BulkResultResponse` declares the optional `Audit-Recorded: false` header (G8).
- `MonitoringOutcomeUnknownProblem` extends 003's shared `OutcomeUnknownProblem` with the action
  names as `step`; `Fail2BanRulesNotRestored` answers it or `ban-rules-not-restored` (G11).

Release checks of feature 011 (quickstart Part C): not run yet.

Contract: the 9 operations of feature 008 (sync jobs):

- Sync jobs: `listSyncJobs`, `createSyncJob`, `getSyncJob`, `updateSyncJob` (activation and
  deactivation included), `deleteSyncJob`, `getSyncJobLog`, `rescheduleSyncJob`,
  `applySyncJobBulkAction` (activate, deactivate, reschedule, delete) and
  `getSyncJobsCapabilities`.
- Problem type: `sync-job-exists`; the shared `permission-flag-required` (`requiredFlags:
  [syncjobs]`) is reused.
- Values appended to shared enumerations: `AuditEventType` (`syncJobCreation`, `syncJobUpdate`,
  `syncJobReschedule`, `syncJobDeletion`), `AuditTarget` (`syncJob`), `AuditFailureReason`
  (`permissionDenied`, `notFound`), `KnownProblemType` (`sync-job-exists`) and
  `ValidationRuleCode` (`optionNotAllowed`, `optionContainsSpace`, `customParamsNotPermitted`,
  `invalidRegex`, `immutable`, `format: hostName`, the `notEligible` kinds `resource`, `alias` and
  `aliasDomainAddress`).
- `listAuditEvents`: `targetType=syncJob` with `targetId`.
- Server settings `RESTV2_IMAPSYNC_OPTIONS`, `RESTV2_SYNC_JOBS_CUSTOM_PARAMS_ADMIN_ONLY` and
  `RESTV2_SYNC_JOBS_BULK_LIMIT`.

Contract changes made while implementing feature 008:

- `applySyncJobBulkAction`: a `done` item's `version` is the job's new tag quoted, exactly as its
  `ETag` header shows it, like every other bulk action (the first text said unquoted).

Release checks of feature 008 (quickstart Part C): not run yet.

Fixes:

- An empty or blank environment setting now means unset and takes its documented default
  (`App\Support\Config\Env::get`), except `RESTV2_MAILCOW_INC_DIR` (empty: not mounted). The
  deploy example's `NAME=${NAME:-}` lines had turned `RESTV2_BULK_LIMIT` and
  `RESTV2_MAILBOX_CUSTOM_ATTRIBUTE_LIMIT` into 1 (every mailbox bulk action with two targets got
  `422 tooMany`) and `RESTV2_CONTAINER_ROLES` into `""`. `true`/`false` legacy variable settings
  (for example `RESTV2_ALIAS_DISABLE_EXTERNAL_DOMAINS=true`) and `RESTV2_UPDATE_CHECK=false` now
  take effect.
- Template rate limits are stored as legacy's string (`"50"`), and alias comments that were not
  sent as `NULL`, as legacy does; both forms are read.

Contract: the 19 operations of feature 012 (identity providers, single sign-on and OAuth2 clients):

- Identity provider configuration: `getIdentityProviderCapabilities`, `getIdentityProvider`,
  `saveIdentityProvider`, `updateIdentityProvider`, `deleteIdentityProvider`,
  `testIdentityProviderConnection`.
- Single sign-on of mailbox users: `startSingleSignOn`, `cancelSingleSignOn`,
  `completeSingleSignOn`, `reportSingleSignOnProviderError`.
- OAuth2 clients: `getOauth2ClientsCapabilities`, `listOauth2Clients`, `createOauth2Client`,
  `bulkOauth2ClientAction`, `getOauth2Client`, `updateOauth2Client`, `deleteOauth2Client`,
  `regenerateOauth2ClientSecret`, `revokeOauth2ClientTokens`.

Additive changes to feature 001's contract for mailcowui-ng: `createSession` verifies LDAP and
Keycloak (mailpassword flow) passwords and may answer `503 identity-provider-unavailable`;
`AuthMethod` and `Session.authMethod` gain `singleSignOn`, `directoryPassword[AndSecondFactor]` and
`providerPassword[AndSecondFactor]`; `IdentityAuthentication.identityProvider`;
`PublicConfig.externalIdentityProvider.singleSignOnAvailable` is now reported; `Unauthorized`
documents `identity-provider-session-ended`; the shared enumerations gain feature 012's values.

Contract changes made while implementing feature 012:

- `saveIdentityProvider` honours `If-None-Match: *` (create only) and a listed version tag
  (`IdentityProviderIfNoneMatch`, gap G20).
- `bulkOauth2ClientAction` has the action `renewSecret`, which returns each new secret once in its
  item result; the response is `Oauth2ClientBulkResultResponse` (`Oauth2ClientBulkResult`,
  `Oauth2ClientBulkItemResult`), a `BulkResult` whose items may carry `clientSecret` (gap G42).

Release checks of feature 012 (quickstart Part C): not run yet.

Contract: the 46 operations of feature 009 (routing and address rewriting):

- Relayhosts: `listRelayhosts`, `createRelayhost`, `listRelayhostChoices`,
  `applyRelayhostBulkAction`, `getRelayhost`, `updateRelayhost`, `deleteRelayhost`,
  `listRelayhostUsage`, `testRelayhostConnection`.
- Relayhost assignments: `getDomainRelayhostAssignment`, `replaceDomainRelayhostAssignment`,
  `getMailboxRelayhostAssignment`, `replaceMailboxRelayhostAssignment`.
- Transports: `listTransports`, `createTransport`, `applyTransportBulkAction`, `getTransport`,
  `updateTransport`, `deleteTransport`, `testTransportConnection`.
- BCC maps: `listBccMaps`, `createBccMap`, `listBccDestinationChoices`, `applyBccMapBulkAction`,
  `getBccMap`, `updateBccMap`, `deleteBccMap`.
- Recipient maps: `listRecipientMaps`, `createRecipientMap`, `applyRecipientMapBulkAction`,
  `getRecipientMap`, `updateRecipientMap`, `deleteRecipientMap`.
- TLS policy maps: `listTlsPolicyMaps`, `createTlsPolicyMap`, `applyTlsPolicyMapBulkAction`,
  `getTlsPolicyMap`, `updateTlsPolicyMap`, `deleteTlsPolicyMap`.
- Forwarding hosts: `listForwardingHosts`, `createForwardingHost`, `applyForwardingHostBulkAction`,
  `getForwardingHost`, `updateForwardingHost`, `deleteForwardingHost`.
- Capabilities: `getRoutingCapabilities`.
- Problem types: `routing-entry-exists`, `next-hop-credentials-differ` and `next-hop-interferes`;
  the shared `permission-flag-required` and `temporary-failure` are reused (the latter with
  `dependency` `sharedStore`, `dns` or `database`).
- Values appended to shared enumerations: 21 `AuditEventType` values (`relayhost*`,
  `relayhostAssignmentUpdate`, `transport*`, `bccMap*`, `recipientMap*`, `tlsPolicyMap*`,
  `forwardingHost*`), 8 `AuditTarget` types, `KnownProblemType` (the three new types) and
  `ValidationRuleCode` (`invalidCharacter`, `notResolvable`, `required` with `because: password`, and
  the formats `nextHop`, `transportDestination`, `recipientMapDestination`, `tlsPolicyDestination`,
  `tlsPolicyParameters`).
- Server settings `RESTV2_ROUTING_TEST_SENDER`, `RESTV2_ROUTING_TEST_RECIPIENT`,
  `RESTV2_ROUTING_TEST_STEP_TIMEOUT` and `RESTV2_ROUTING_TEST_TOTAL_TIMEOUT`.

Contract changes made while implementing feature 009:

- `RelayhostChoice.username` (`null` without one): the relayhost choices carry the username, as the
  legacy selectors show it (G41).
- `RelayhostPatch` names the rule code `immutable` (G37); the patches of relayhosts, transports, BCC
  maps, recipient maps, TLS policy maps and forwarding hosts accept the representation's read-only
  members (`passwordSet`, `etag`, times, `orphaned`) with their current value and refuse unknown
  members (`immutable`).
- The `nextHop` filter of `listTransports` is normalised like a stored next hop (G35).
- `RoutingCapabilities.administration.maxLengths` gained `forwardingHost`, `testSender` and
  `testRecipient` (G36).
- Refusal records of the routing event types carry `requiredFlags` for `permission-flag-required`
  (`docs/audit-events.md`).

Release checks of feature 009 (quickstart Part C): not run yet.
