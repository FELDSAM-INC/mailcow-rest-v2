# Contracts: Monitoring and Operations (feature 011)

The contract is not duplicated here. The source of truth is the OpenAPI 3.1 document in
[`api/`](../../../api/openapi.yaml) (constitution I). Feature 011 authored these modules:

| Module | Paths | Operations |
|---|---|---|
| [`api/modules/host/`](../../../api/modules/host/_index.yaml) | `/host`, `/host/mail-storage`, `/host/public-addresses`, `/installation`, `/installation/update-status`, `/monitoring/capabilities` | 6 |
| [`api/modules/containers/`](../../../api/modules/containers/_index.yaml) | `/containers`, `/containers/{containerName}`, `…/resource-usage`, `…/start`, `…/stop`, `…/restart` | 6 |
| [`api/modules/logs/`](../../../api/modules/logs/_index.yaml) | `/logs/{postfix,dovecot,sogo,netfilter,autodiscover,watchdog,acme,cron,rate-limits,legacy-api-requests,legacy-ui-activity,logins}` | 12 |
| [`api/modules/rspamd-stats/`](../../../api/modules/rspamd-stats/_index.yaml) | `/rspamd/history`, `/rspamd/statistics` | 2 |
| [`api/modules/mail-queue/`](../../../api/modules/mail-queue/_index.yaml) | `/mail-queue/messages`, `…/delivery`, `…/hold`, `…/release`, `…/deletion`, `…/{queueId}/content`, `/mail-queue/flush`, `/mail-queue/purge` | 8 |
| [`api/modules/fail2ban/`](../../../api/modules/fail2ban/_index.yaml) | `/fail2ban/settings` (GET, PATCH), `/fail2ban/ban-list-rotation`, `/fail2ban/rules` (GET, PUT), `/fail2ban/rules/reset`, `/fail2ban/active-bans`, `…/{network}/lift`, `…/{network}/allowlist`, `…/{network}/denylist`, `/fail2ban/permanent-bans` | 11 |

**Components** (`api/components/`), all named after their resource:

- schemas: `HostOverview`, `MailStorageUsage`, `PublicAddresses`, `InstallationVersion`,
  `InstallationUpdateStatus`, `MonitoringCapabilities`, `MonitoringLogRetention`, `Container`,
  `ContainerState`, `ContainerRole`, `ContainerAction`, `ContainerActionResult`,
  `ContainerResourceUsage`, `ContainerProblem`, `LogSeverity`, `SyslogLogEntry`,
  `NetfilterLogEntry`, `AutodiscoverLogEntry`, `WatchdogLogEntry`, `AcmeLogEntry`, `CronLogEntry`,
  `RateLimitLogEntry`, `LegacyApiRequestLogEntry`, `LegacyUiActivityLogEntry`, `LoginLogEntry`,
  `RspamdHistoryEntry`, `RspamdHistorySymbol`, `RspamdStatistics`, `MailQueueMessage`,
  `MailQueueRecipient`, `MailQueueName`, `MailQueueListMeta`, `MailQueueMessageContent`,
  `MailQueueBulkTarget`, `MailQueueBulkRequest`, `MailQueueActionResult`, `MailQueueProblem`,
  `Fail2BanSettings`, `Fail2BanSettingsPatch`, `Fail2BanRules`, `Fail2BanRule`,
  `Fail2BanRulesReplaceRequest`, `Fail2BanActiveBan`, `Fail2BanPermanentBan`,
  `Fail2BanBanActionResult`;
- parameters: `ContainerName`, `ContainerSort`, `LogSort`, `LogFrom`, `LogTo`, `LogBefore`,
  `LogSeverityFilter`, `RspamdHistorySort`, `MailQueueId`, `MailQueueMessageSort`,
  `Fail2BanNetwork`, `Fail2BanActiveBanSort`, `Fail2BanPermanentBanSort`;
- responses: one per resource (`…Response`, `…ListResponse`), plus `ContainerConflict`,
  `ContainerActionFailed`, `MailQueueConflict`, `MailQueueActionFailed`,
  `Fail2BanRulesNotRestored`, `PublicAddressesIpCheckDisabled`, `MonitoringTemporaryFailure` and
  `MonitoringOutcomeUnknown`.

**Shared components reused**: `ListMeta`, `Problem`, `ValidationProblem`, `Limit`, `Offset`,
`Order`, `Search`, `IfMatch`, `BulkTarget`, `BulkResultResponse`, the security schemes and the
standard responses (`BadRequest`, `Unauthorized`, `Forbidden`, `NotFound`, `PreconditionFailed`,
`ValidationFailed`, `InternalError`).

**Shared enumerations extended**: `KnownProblemType` (7 types), `AuditEventType` (17 events),
`AuditTarget` (7 target types), `ValidationRuleCode` (`missingCaptureGroup`, plus documented uses
of existing codes) and `AuditFailureReason` (`serviceFailure`, `rulesNotRestored`). Problem types
are documented in [`docs/problems.md`](../../../docs/problems.md).

Lint: `npx --yes @redocly/cli@2.64.1 lint api/openapi.yaml`.
