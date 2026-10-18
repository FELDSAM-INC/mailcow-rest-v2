# Contracts of feature 003

The contract of this feature is not duplicated here. The OpenAPI 3.1 document in the repository's
[`api/`](../../../api/) directory is the single source of truth (constitution I):

- [`api/modules/mailboxes/`](../../../api/modules/mailboxes/): `/mailboxes`,
  `/mailboxes/capabilities`, `/mailboxes/tags`, `/mailboxes/bulk-actions`,
  `/mailboxes/{mailbox}`, `/mailboxes/{mailbox}/deletion-preview` and
  `/mailboxes/{mailbox}/rename`.
- [`api/modules/mailbox-templates/`](../../../api/modules/mailbox-templates/): `/mailbox-templates`
  and `/mailbox-templates/{mailboxTemplateId}`.
- [`api/modules/password-policy/`](../../../api/modules/password-policy/): `/password-policy`
  (`GET` here; feature 013 adds the change operation to the same path file).
- `api/components/schemas/Mailbox*.yaml` and `PasswordPolicy.yaml`,
  `api/components/parameters/Mailbox*.yaml` and `api/components/responses/Mailbox*.yaml`,
  `PasswordPolicyResponse.yaml`.
- Values appended to the shared enumerations `KnownProblemType`, `AuditEventType`,
  `AuditTarget`, `AuditFailureReason` and `ValidationRuleCode`.
- [`docs/problems.md`](../../../docs/problems.md): the problem types `address-in-use`,
  `mailbox-limit-reached`, `mailbox-not-renamable`, `mailbox-rename-incomplete`,
  `outcome-unknown`, `mailbox-template-name-exists` and `mailbox-template-protected`, and the
  mailbox extensions of `attribute-not-permitted` and `temporary-failure`.

The 16 operations, with their authentication and the requirements they serve, are listed in
[plan.md](../plan.md#contract-operations).

Check the contract with:

```bash
npx --yes @redocly/cli@2.64.1 lint api/openapi.yaml
npx --yes @redocly/cli@2.64.1 bundle api/openapi.yaml -o build/contract/openapi.yaml
```
