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

Release checks (quickstart Part C: SC-002, SC-003, SC-004, SC-006) ran on 2026-10-18 on the
FeldHost fork (upstream `2026-09a` baseline) with the image of 3a35973:

- SC-003 passes: the legacy UI, `/api/v1`, SOGo, IMAP and SMTP are unchanged; only the four
  `restv2_` tables were added, and `db_schema` is unchanged.
- SC-004 passes for the API's part: each failed sign-in publishes one `F2B_CHANNEL` message in the
  legacy text, ten for ten failures, as in the legacy UI. The ban itself is not verified, because
  the host's netfilter was broken.
- SC-002 passes with a virtual authenticator: registration took 69 ms, and the first passkey
  sign-in completed 262 ms after the password sign-in. The browser stopwatch check is still open.
- SC-006 passes: the key was created and first used for a read within 196 ms.
- Still open: a plain upstream `2026-09a` host, the ban, and the browser passkey stopwatch.
