# Contracts of feature 012

The contract of this feature is not duplicated here. The OpenAPI 3.1 document in the repository's
[`api/`](../../../api/) directory is the single source of truth (constitution I):

- [`api/modules/identity-provider/`](../../../api/modules/identity-provider/): the configuration
  (`GET`, `PUT`, `PATCH`, `DELETE /identity-provider`) and connection tests
  (`POST /identity-provider/connection-tests`).
- [`api/modules/single-sign-on/`](../../../api/modules/single-sign-on/): single sign-on of mailbox
  users (`POST`, `DELETE /session/single-sign-on`, `POST /session/single-sign-on/completion`,
  `POST /session/single-sign-on/provider-error`), under the Authentication tag.
- [`api/modules/oauth2-clients/`](../../../api/modules/oauth2-clients/): OAuth2 clients, their
  secret regeneration, token revocation and bulk actions.
- New components, named after their resource: `api/components/schemas/IdentityProvider*.yaml`,
  `SingleSignOn*.yaml`, `Oauth2Client*.yaml`; `api/components/parameters/Oauth2Client{Id,Sort}.yaml`;
  `api/components/responses/IdentityProvider*.yaml`, `SingleSignOn*.yaml`, `Oauth2Client*.yaml`.
- Additive changes to feature 001's contract: `POST /session` (provider password sign-in, `503`),
  `PublicConfig.externalIdentityProvider.singleSignOnAvailable`, `LoginPageOptions`, `AuthMethod`,
  `Session.authMethod`, `IdentityAuthentication.identityProvider`, `Unauthorized`, and the
  `authMethod` filter of `GET /audit-events`.
- Values appended to the shared enumerations `KnownProblemType`, `AuditEventType`, `AuditTarget`,
  `AuditFailureReason` and `ValidationRuleCode`.
- [`docs/problems.md`](../../../docs/problems.md): `identity-provider-not-configured`,
  `identity-provider-in-use`, `single-sign-on-not-available`, `sign-in-state-invalid`,
  `identity-provider-unavailable`, `identity-provider-session-ended`.

The 17 operations, with their authentication and the requirements they serve, are listed in
[plan.md](../plan.md#contract-operations).

Check the contract with:

```bash
npx --yes @redocly/cli@2.64.1 lint api/openapi.yaml
npx --yes @redocly/cli@2.64.1 bundle api/openapi.yaml -o build/contract/openapi.yaml
```
