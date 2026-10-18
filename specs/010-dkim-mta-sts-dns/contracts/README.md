# Contracts of feature 010

The contract of this feature is not duplicated here. The OpenAPI 3.1 document in the repository's
[`api/`](../../../api/) directory is the single source of truth (constitution I):

- [`api/modules/dkim/`](../../../api/modules/dkim/): DKIM entries, generation, import, copy,
  deletion, bulk deletion (`/dkim-keys/bulk-actions`), private key export, publishing a record to ISPConfig again, and the capabilities read
  (`/capabilities/dns`).
- [`api/modules/mta-sts/`](../../../api/modules/mta-sts/): MTA-STS policies.
- [`api/modules/dns-diagnostics/`](../../../api/modules/dns-diagnostics/): DNS diagnostics.
- Components named after their resource in `api/components/{schemas,parameters,responses}/`:
  `Dkim*`, `MtaSts*`, `Dns*` and `DnsCapabilities*`.
- Values appended to the shared enumerations `KnownProblemType`, `AuditEventType`, `AuditTarget`
  and `ValidationRuleCode`.
- Problem types `dkim-key-exists`, `dkim-private-key-export-disabled`,
  `dkim-dns-publication-disabled` and `mta-sts-policy-exists` in
  [`docs/problems.md`](../../../docs/problems.md).

The 15 operations, with their authentication and the requirements they serve, are listed in
[plan.md](../plan.md#contract-operations).

Check the contract with:

```bash
npx --yes @redocly/cli@2.64.1 lint api/openapi.yaml
npx --yes @redocly/cli@2.64.1 bundle api/openapi.yaml -o build/contract/openapi.yaml
```
