# Contracts of feature 007

The contract of this feature is not duplicated here. The OpenAPI 3.1 document in the repository's
[`api/`](../../../api/) directory is the single source of truth (constitution I):

- [`api/modules/admins/`](../../../api/modules/admins/): administrator accounts, their bulk
  actions (`/admin-bulk-actions`) and the account capabilities (`/account-capabilities`);
- [`api/modules/domain-admins/`](../../../api/modules/domain-admins/): domain administrator
  accounts with their domains and permission flags, and their bulk actions
  (`/domain-admin-bulk-actions`);
- [`api/modules/sso-tokens/`](../../../api/modules/sso-tokens/): token issue
  (`/domain-admins/{username}/sso-token`) and token sign-in (`/session/sso-token`);
- [`api/modules/impersonation/`](../../../api/modules/impersonation/): login-as
  (`/session/impersonation`) and webmail on behalf (`/mailboxes/{mailbox}/webmail-sign-in`);
- the components named after these resources in `api/components/{schemas,parameters,responses}/`
  (listed in [plan.md](../plan.md#project-structure));
- additive changes to feature 001's components: `Identity` (`impersonation`,
  `webmailOnBehalfEnabled`), `Session` (`impersonation`, `authMethod: ssoToken`), `AuthMethod`
  (`ssoToken`), `AuditEvent` (`impersonatedAccount`), `ApiKey` (`revocationReason`), the shared
  `Forbidden` response, and the `impersonatedAccount` filter of `listAuditEvents`;
- values appended to `KnownProblemType`, `AuditEventType`, `AuditTarget` and `ValidationRuleCode`;
- [`docs/problems.md`](../../../docs/problems.md): the sections `account-exists`,
  `last-active-admin`, `cannot-delete-own-account`, `already-impersonating`,
  `impersonation-ended`, `not-allowed-while-impersonating`, `webmail-on-behalf-disabled` and
  `account-inactive`.

The 21 operations, with their authentication and the requirements they serve, are listed in
[plan.md](../plan.md#contract-operations).

Check the contract with:

```bash
npx --yes @redocly/cli@2.64.1 lint api/openapi.yaml
npx --yes @redocly/cli@2.64.1 bundle api/openapi.yaml -o build/contract/openapi.yaml
```
