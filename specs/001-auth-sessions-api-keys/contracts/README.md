# Contracts of feature 001

The contract of this feature is not duplicated here. The OpenAPI 3.1 document in the repository's
[`api/`](../../../api/) directory is the single source of truth (constitution I):

- [`api/openapi.yaml`](../../../api/openapi.yaml): the root document, with conventions, security
  schemes, the recommended presentation order of sign-in methods and the path list.
- `api/modules/{auth,identity,passkeys,api-keys,public-config,audit,system}/`: one kebab-case file
  per path, and one `_index.yaml` per module.
- `api/components/{schemas,parameters,responses}/`: PascalCase shared components.
- [`docs/problems.md`](../../../docs/problems.md): every problem `type` of the feature.

The 31 operations, with their authentication and the requirements they serve, are listed in
[plan.md](../plan.md#contract-operations).

Check the contract with:

```bash
npx --yes @redocly/cli@2.64.1 lint api/openapi.yaml
npx --yes @redocly/cli@2.64.1 bundle api/openapi.yaml -o build/contract/openapi.yaml
```
