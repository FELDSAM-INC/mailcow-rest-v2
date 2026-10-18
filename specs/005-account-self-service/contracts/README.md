# Contracts of feature 005

The contract of this feature is not duplicated here. The OpenAPI 3.1 document in the repository's
[`api/`](../../../api/) directory is the single source of truth (constitution I):

- [`api/modules/self-service/`](../../../api/modules/self-service/): one kebab-case file per path
  and the module's `_index.yaml`, referenced from [`api/openapi.yaml`](../../../api/openapi.yaml)
  under the tags *Self-service*, *Own account*, *Password reset*, *Mailbox self-service* and
  *Sieve filters*.
- `api/components/{schemas,parameters,responses}/`: this feature's PascalCase components
  (`Own*`, `SelfService*`, `PasswordReset*`, `AppPassword*`, `TemporaryAlias*`,
  `PushNotification*`, `MailboxMailSettings*`, `MailboxMailSettingsBulkActionRequest`, `RecentLogin*`,
  `SieveFilter*`, `SieveScript*`, `ActiveSieveScript*`, `MailboxOverview*`, `MailboxClient*`,
  `AppleProfile*`, `WebmailHandOff*`, `SelfServicePermissionFlagProblem`,
  `SecondFactorEnrolmentFailedProblem`), plus the shared ones it reuses from features 001 and 003.
- Values appended to the shared enumerations `KnownProblemType`, `AuditEventType`, `AuditTarget`,
  `AuditFailureReason` and `ValidationRuleCode`.
- [`docs/problems.md`](../../../docs/problems.md): the problem types of this feature (sections
  `current-password-incorrect` to `groupware-disabled`, and "Feature 005 use of shared types").

The 51 operations, with their authentication, pending-action mode and the requirements they serve,
are listed in [plan.md](../plan.md#contract-operations).

Check the contract with:

```bash
npx --yes @redocly/cli@2.64.1 lint api/openapi.yaml
npx --yes @redocly/cli@2.64.1 bundle api/openapi.yaml -o build/contract/openapi.yaml
```
