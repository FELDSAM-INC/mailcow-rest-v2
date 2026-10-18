# Contracts of feature 004

The contract of this feature is not duplicated here. The OpenAPI 3.1 document in the repository's
[`api/`](../../../api/) directory is the single source of truth (constitution I):

- [`api/openapi.yaml`](../../../api/openapi.yaml): the root document. This feature adds the tags
  "Aliases", "Alias domains", "Resources" and "Sender settings" and 13 path entries.
- [`api/modules/aliases/`](../../../api/modules/aliases/): aliases, the copy over alias domains,
  the alias bulk actions (including the bulk copy) and the feature's capabilities
  (`/capabilities/aliases`).
- [`api/modules/alias-domains/`](../../../api/modules/alias-domains/): alias domains and their bulk
  actions.
- [`api/modules/resources/`](../../../api/modules/resources/): groupware resources and their bulk
  actions.
- [`api/modules/sender-settings/`](../../../api/modules/sender-settings/): a mailbox's sender
  settings (`/mailboxes/{mailbox}/sender-settings`) and the top-level helper list
  `/sender-permission-candidates`.
- `api/components/{schemas,parameters,responses}/`: the components named `Alias*`, `Resource*`,
  `MailboxSenderSettings*`, `MailboxSenderPermission*`, `MailboxImplicitSenderAddress`,
  `SenderSettingsMailbox` and `MailboxSenderPermissionCandidateSort`, plus the values appended to
  `KnownProblemType`, `AuditEventType`, `AuditTarget` and `ValidationRuleCode`. The shared
  `ListMeta`, `Problem`, `ValidationProblem`, `Limit`, `Offset`, `Order`, `Search`, `IfMatch`,
  `BulkTarget`, `BulkResultResponse` and standard responses are reused.
- [`docs/problems.md`](../../../docs/problems.md): `domain-alias-limit-reached`,
  `alias-domain-already-exists` and `not-primary-domain-alias` are this feature's; the shared
  `forbidden`, `permission-flag-required`, `attribute-not-permitted`, `address-in-use` and
  `temporary-failure` are reused (their use here is listed under `domain-alias-limit-reached`).

The 23 operations, with their authentication and the requirements they serve, are listed in
[plan.md](../plan.md#contract-operations).

Check the contract with:

```bash
npx --yes @redocly/cli@2.64.1 lint api/openapi.yaml
npx --yes @redocly/cli@2.64.1 bundle api/openapi.yaml -o build/contract/openapi.yaml
```
