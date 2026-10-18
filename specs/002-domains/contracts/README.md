# Contracts of feature 002

The contract of this feature is not duplicated here. The OpenAPI 3.1 document in the repository's
[`api/`](../../../api/) directory is the single source of truth (constitution I):

- [`api/modules/domains/`](../../../api/modules/domains/): domains, the deletion preview, the
  footer, bulk actions, the domain capabilities and the distinct tag list (`_index.yaml` maps each
  path to its file).
- [`api/modules/domain-templates/`](../../../api/modules/domain-templates/): domain templates.
- `api/components/schemas/Domain*.yaml`, `api/components/parameters/Domain*.yaml` and
  `api/components/responses/Domain*.yaml`: the components of this feature.
- [`api/modules/audit/audit-events.yaml`](../../../api/modules/audit/audit-events.yaml): the
  `targetType` and `targetId` filters added by this feature (FR-058).
- Shared enumerations extended: `KnownProblemType`, `AuditEventType`, `AuditTarget`,
  `AuditFailureReason` and `ValidationRuleCode`.
- [`docs/problems.md`](../../../docs/problems.md): `domain-exists`, `domain-not-empty`,
  `domain-template-name-exists`, `domain-template-protected`, `attribute-not-permitted` and
  `temporary-failure`.

The 16 operations, with their authentication and the requirements they serve, are listed in
[plan.md](../plan.md#contract-operations).

Check the contract with:

```bash
npx --yes @redocly/cli@2.64.1 lint api/openapi.yaml
npx --yes @redocly/cli@2.64.1 bundle api/openapi.yaml -o build/contract/openapi.yaml
```
