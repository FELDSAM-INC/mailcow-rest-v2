# Contracts of feature 003

The contract of this feature is not duplicated here. The OpenAPI 3.1 document in the repository's
[`api/`](../../../api/) directory is the single source of truth (constitution I):

- [`api/modules/mailboxes/`](../../../api/modules/mailboxes/): `/mailboxes`,
  `/mailboxes/bulk-actions`, `/mailboxes/{mailbox}`, `/mailboxes/{mailbox}/deletion-preview`,
  `/mailboxes/{mailbox}/rename`, `/capabilities/mailboxes` and `/mailbox-tags`.
- [`api/modules/mailbox-templates/`](../../../api/modules/mailbox-templates/): `/mailbox-templates`
  and `/mailbox-templates/{mailboxTemplateId}`.
- [`api/modules/password-policy/`](../../../api/modules/password-policy/): `/password-policy`
  (`GET` here; feature 013 adds the change operation to the same path file).
- Components of this feature (other features also use the `Mailbox` prefix, so this is the exact
  list):
  - schemas: `Mailbox`, `MailboxAddressInUseProblem`, `MailboxAddressSample`, `MailboxAliasLimitReachedProblem`, `MailboxAttributeNotPermittedProblem`, `MailboxAuthSource`, `MailboxBulkActionRequest`, `MailboxCapabilities`, `MailboxCreateRequest`, `MailboxCreated`, `MailboxCreationReport`, `MailboxCustomAttributes`, `MailboxDeletionPreview`, `MailboxLastLogins`, `MailboxLimitReachedProblem`, `MailboxOutcomeUnknownProblem`, `MailboxPatch`, `MailboxPermissionFlagRequiredProblem`, `MailboxProtocolAccess`, `MailboxProtocolAccessInput`, `MailboxQuarantineCategory`, `MailboxQuarantineNotification`, `MailboxRateLimit`, `MailboxRenameIncompleteProblem`, `MailboxRenameRequest`, `MailboxRenameStep`, `MailboxRenamed`, `MailboxStatus`, `MailboxTagSet`, `MailboxTagUsage`, `MailboxTaggedMailHandling`, `MailboxTemplate`, `MailboxTemplateCreateRequest`, `MailboxTemplatePatch`, `MailboxTemporaryFailureProblem`, `MailboxUserPermissions`, `MailboxUserPermissionsInput`, `PasswordPolicy`;
  - parameters: `MailboxAddress`, `MailboxSort`, `MailboxTagSort`, `MailboxTemplateId`, `MailboxTemplateSort`;
  - responses: `MailboxCapabilitiesResponse`, `MailboxConflict`, `MailboxCreatedResponse`, `MailboxDeletionPreviewResponse`, `MailboxForbidden`, `MailboxListResponse`, `MailboxOutcomeUnknown`, `MailboxRenameFailed`, `MailboxRenamedResponse`, `MailboxResponse`, `MailboxTagListResponse`, `MailboxTemplateConflict`, `MailboxTemplateCreatedResponse`, `MailboxTemplateListResponse`, `MailboxTemplateResponse`, `MailboxTemporaryFailure`, `PasswordPolicyResponse`.
- Values appended to the shared enumerations `KnownProblemType`, `AuditEventType`,
  `AuditTarget`, `AuditFailureReason` and `ValidationRuleCode`.
- [`docs/problems.md`](../../../docs/problems.md): the problem types `address-in-use`,
  `mailbox-limit-reached`, `mailbox-not-renamable`, `mailbox-rename-incomplete`,
  `outcome-unknown`, `mailbox-template-name-exists` and `mailbox-template-protected`, and the
  mailbox extensions of `attribute-not-permitted` and `temporary-failure`; `permission-flag-required`
  is reused for protocol bulk actions.

The 16 operations, with their authentication and the requirements they serve, are listed in
[plan.md](../plan.md#contract-operations).

Check the contract with:

```bash
npx --yes @redocly/cli@2.64.1 lint api/openapi.yaml
npx --yes @redocly/cli@2.64.1 bundle api/openapi.yaml -o build/contract/openapi.yaml
```
