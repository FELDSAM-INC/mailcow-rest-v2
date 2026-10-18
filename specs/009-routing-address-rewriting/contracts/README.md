# Contracts of feature 009

The contract of this feature is not duplicated here. The OpenAPI 3.1 document in the repository's
[`api/`](../../../api/) directory is the single source of truth (constitution I):

- [`api/openapi.yaml`](../../../api/openapi.yaml): the root document. Feature 009 adds the tags
  "Relayhosts", "Relayhost assignments", "Transports", "BCC maps", "Recipient maps",
  "TLS policy maps", "Forwarding hosts" and "Routing capabilities", and the path references below.
- The modules, one kebab-case file per path and one `_index.yaml` each:
  - [`api/modules/relayhosts/`](../../../api/modules/relayhosts/): `/relayhosts`,
    `/relayhost-choices`, `/relayhosts/bulk-actions`, `/relayhosts/{relayhostId}`,
    `/relayhosts/{relayhostId}/usage`, `/relayhosts/{relayhostId}/connection-test`;
  - [`api/modules/relayhost-assignments/`](../../../api/modules/relayhost-assignments/):
    `/domains/{domain}/relayhost-assignment`, `/mailboxes/{mailbox}/relayhost-assignment`;
  - [`api/modules/transports/`](../../../api/modules/transports/): `/transports`,
    `/transports/bulk-actions`, `/transports/{transportId}`,
    `/transports/{transportId}/connection-test`;
  - [`api/modules/bcc-maps/`](../../../api/modules/bcc-maps/): `/bcc-maps`,
    `/bcc-destination-choices`, `/bcc-maps/bulk-actions`, `/bcc-maps/{bccMapId}`;
  - [`api/modules/recipient-maps/`](../../../api/modules/recipient-maps/): `/recipient-maps`,
    `/recipient-maps/bulk-actions`, `/recipient-maps/{recipientMapId}`;
  - [`api/modules/tls-policy-maps/`](../../../api/modules/tls-policy-maps/): `/tls-policy-maps`,
    `/tls-policy-maps/bulk-actions`, `/tls-policy-maps/{tlsPolicyMapId}`;
  - [`api/modules/forwarding-hosts/`](../../../api/modules/forwarding-hosts/): `/forwarding-hosts`,
    `/forwarding-hosts/bulk-actions`, `/forwarding-hosts/{forwardingHostAddress}`;
  - [`api/modules/capabilities/routing/`](../../../api/modules/capabilities/routing/):
    `/capabilities/routing`.
- `api/components/{schemas,parameters,responses}/`: the PascalCase components named after this
  feature's resources (`Relayhost*`, `Transport*`, `BccMap*`, `BccDestinationChoice*`,
  `RecipientMap*`, `TlsPolicy*`, `ForwardingHost*`, `Routing*`, `DomainRelayhostAssignment*`,
  `MailboxRelayhostAssignment*`). Shared components are reused: `BulkTarget`, `BulkResultResponse`,
  `ListMeta`, `Problem`, `ValidationProblem`, `Limit`, `Offset`, `Order`, `Search`, `IfMatch`, 002's
  `DomainName` and 003's `MailboxAddress`.
- Values appended to the shared enumerations: `KnownProblemType`, `ValidationRuleCode`,
  `AuditEventType` and `AuditTarget`.
- [`docs/problems.md`](../../../docs/problems.md): the sections `routing-entry-exists`,
  `next-hop-credentials-differ`, `next-hop-interferes` and `permission-flag-required`. The feature
  also uses 002's `temporary-failure`.

The 46 operations, with their authorisation and the requirements they serve, are listed in
[plan.md](../plan.md#contract-operations).

Check the contract with:

```bash
npx --yes @redocly/cli@2.64.1 lint api/openapi.yaml
npx --yes @redocly/cli@2.64.1 bundle api/openapi.yaml -o build/contract/openapi.yaml
```
