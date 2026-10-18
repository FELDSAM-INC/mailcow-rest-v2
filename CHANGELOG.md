# Changelog

The contract version (`info.version` of `api/openapi.yaml`) is the API version. mailcowui-ng pins a
released contract; every release lists its contract changes.

## 0.1.0 — unreleased

Contract: the 31 operations of feature 001 (authentication, sessions, API keys, identity and
branding configuration):

- CSRF bootstrap and session state: `getCsrfToken`, `getSession`.
- Password sign-in with second factors (TOTP, WebAuthn, Yubico OTP) and sign-out: `createSession`,
  `deleteSession`, `cancelPendingSignIn`, `createSecondFactorWebAuthnChallenge`,
  `verifySecondFactor`.
- Passkey sign-in and the user's own passkeys: `createPasskeyChallenge`,
  `createSessionWithPasskey`, `listPasskeys`, `registerPasskey`,
  `createPasskeyRegistrationOptions`, `getPasskey`, `updatePasskey`, `deletePasskey`.
- Identity and preferences: `getIdentity`, `getPreferences`, `updatePreferences`.
- API keys: `listApiKeys`, `createApiKey`, `listApiKeyOwnerCandidates`, `getApiKey`,
  `revokeApiKey`.
- Public branding configuration: `getPublicConfig`, `getPublicConfigLogo`.
- Audit log: `listAuditEvents`.
- System: `getLiveness`, `getReadiness`, `getOpenApiDocument`, `getApiDocs`,
  `getProblemTypeDocumentation`.

Contract changes made while implementing feature 001:

- `listAuditEvents` has a repeatable `authMethod` filter and a `requestId` filter (gaps G18 and
  G19); values of shared enumerations that the server does not record match nothing.
- `ValidationRuleCode` documents `format: requestId`.
- `WebAuthnCreationOptions.attestation` is legacy's conveyance: `indirect`, `direct` only with
  `WEBAUTHN_ONLY_TRUSTED_VENDORS=y`, `none` when only the `none` format is accepted.
- `getPublicConfig`'s `Cache-Control` must contain `no-cache` (the server sends
  `no-cache, public`) instead of being exactly `no-cache`.
- The texts of `createSession` and `verifySecondFactor` describe the separate second-factor
  throttling.

Release checks (quickstart Part C on a mailcow `2026-09a` host: SC-002, SC-003, SC-004, SC-006):
not run yet.
