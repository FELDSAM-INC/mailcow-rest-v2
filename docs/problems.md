# Problem types

Errors of mailcow REST API v2 are [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) problem details
(`application/problem+json`). Refusals that clients act on carry a stable `type` URI that points to a
section of this document. Compare `type` as an exact string. `title` and `detail` are for humans and
may be reworded. Problems not listed here use `type: about:blank`.

Each problem type gets its own `## <name>` section with its status code, its meaning, and any
extension members.

## Conventions

- `type` is a relative URI reference of the form `/api/v2/problems/<name>`, where `<name>` is the
  section name below. Every installation serves the section as HTML at that path
  (`GET /api/v2/problems/{problemType}`), so the URI dereferences without any external site.
- Every problem has `type`, `title`, `status`, `requestId` and usually `detail`. `requestId` is the
  W3C trace ID of the request. It also appears in the service log and in any audit record of the
  request.
- Errors are never localised. The API ignores `Accept-Language`, and `title`, `detail` and
  `errors[].message` are English. Clients translate from `type`, from the validation rule `code`
  and from its `params`.
- New problem types and new extension members may be added in later releases. Clients must treat
  an unknown `type` by its status code.
- `about:blank` is used for `400` (malformed request, for example invalid JSON), `404` (unknown
  resource or path, including objects outside the caller's scope), `405` (method not allowed),
  `409` (conflict described in `detail`), `412` (`If-Match` mismatch) and `500` (unexpected
  failure).

## validation-failed

- **Type**: `/api/v2/problems/validation-failed`
- **Status**: 422
- **Meaning**: The request body or query parameters are invalid. Nothing was changed.
- **Extension members**: `errors`, an object that maps each invalid field to a non-empty list of
  violations. Body fields use their dot-separated path in the request body (`allowedNetworks.2`).
  Query parameters use their name (`limit`). The key `body` stands for the request body as a whole,
  for example an empty merge patch. Each violation has:
  - `code`: a stable rule code, one of `required`, `invalidType`, `tooShort`, `tooLong`,
    `tooMany`, `outOfRange`, `notInList`, `invalidFormat`, `mustBeInFuture`, `notFound`,
    `notEligible`. Later features may add codes. Treat unknown codes as a generic invalid value.
  - `params`: the rule's parameters, for example `{"max": 255}`, `{"allowed": ["cs-cz", "en-gb"]}`,
    `{"format": "ipNetwork"}` or `{"allowedRoles": ["admin", "domainAdmin"]}`.
  - `message`: an English message for logs and scripts.
- **Client action**: Show each violation on the matching form field, translated from `code` and
  `params`.

```json
{
  "type": "/api/v2/problems/validation-failed",
  "title": "Validation failed",
  "status": 422,
  "detail": "The request contains invalid fields.",
  "requestId": "4bf92f3577b34da6a3ce929d0e0e4736",
  "errors": {
    "expiresAt": [
      {"code": "mustBeInFuture", "params": {}, "message": "The expiry must be in the future."}
    ],
    "allowedNetworks.1": [
      {"code": "invalidFormat", "params": {"format": "ipNetwork"}, "message": "Not an IP address or prefix."}
    ]
  }
}
```

## unauthenticated

- **Type**: `/api/v2/problems/unauthenticated`
- **Status**: 401
- **Meaning**: The operation needs authentication, and the request has neither a live signed-in
  session nor an API key. This covers a session that ended through idle timeout, sign-out, a
  changed user agent, or the deactivation or deletion of its account.
- **Client action**: Show the sign-in page.

## forbidden

- **Type**: `/api/v2/problems/forbidden`
- **Status**: 403
- **Meaning**: The caller is authenticated, but its role or permission flags do not allow the
  operation. Examples are a domain administrator or mailbox user listing API keys or audit records,
  and a key owned by a domain administrator calling an administrator-only operation.
- **Client action**: Explain that the account lacks the permission. Do not retry.

## csrf-token-mismatch

- **Type**: `/api/v2/problems/csrf-token-mismatch`
- **Status**: 403
- **Meaning**: A state-changing request made with a session cookie lacks a valid `X-CSRF-Token`
  header. The token is missing, wrong, or belongs to a session that has ended.
- **Client action**: Call `GET /api/v2/csrf-token` and retry the request once with the new token.
  If the session ended, the retry yields `unauthenticated` or a sign-in error.

## session-required

- **Type**: `/api/v2/problems/session-required`
- **Status**: 403
- **Meaning**: The operation is only available to a signed-in browser session and the request was
  authenticated by an API key. This covers sign-in, sessions, preferences, passkey management, and
  creating and revoking API keys. Keys are created and revoked only by a signed-in administrator, so
  that a leaked key cannot mint replacements.
- **Client action**: Use a signed-in session for this operation.

## pending-action-required

- **Type**: `/api/v2/problems/pending-action-required`
- **Status**: 403
- **Meaning**: The session is restricted. mailcow forces the account to change its password or to
  set up a second factor. Until then, the session can only read `GET /me` and `GET /session`, sign
  out and, when only `secondFactorSetup` is pending, register a passkey.
- **Extension members**: `pendingActions`, a non-empty list of `passwordChange` and
  `secondFactorSetup`.
- **Client action**: Guide the user through the pending actions. Register a passkey for
  `secondFactorSetup`. For `passwordChange`, link to the legacy UI until password change is
  available in this API. Pending actions are re-evaluated on every request.

## too-many-requests

- **Type**: `/api/v2/problems/too-many-requests`
- **Status**: 429
- **Meaning**: Sign-in throttling. After 10 failed sign-ins for the same username and client address
  within 15 minutes, further attempts are refused without checking credentials. For passkeys, the
  limit counts failures per client address. The operator can configure both values.
- **Extension members**: `retryAfter`, the number of seconds until the next attempt is considered.
  The `Retry-After` header carries the same value.
- **Client action**: Tell the user when to try again. Do not retry automatically.

## sign-in-failed

- **Type**: `/api/v2/problems/sign-in-failed`
- **Status**: 401
- **Meaning**: A password or passkey sign-in failed. It is deliberately the same for an unknown
  account, a wrong password, an inactive account or domain, a resource mailbox, a malformed
  username, a mailbox that authenticates against an external identity provider, an unknown or
  inactive passkey, and a passkey whose account is not of the requested role. It never reveals
  which of these applies.
- **Client action**: Show a generic "sign-in failed" message. For passkeys, offer username and
  password.

## sign-in-expired

- **Type**: `/api/v2/problems/sign-in-expired`
- **Status**: 401
- **Meaning**: The session has no pending sign-in or passkey challenge, or it expired or was already
  used. Pending sign-ins and challenges are single-use and expire 5 minutes after they are issued.
- **Client action**: Start the sign-in again.

## second-factor-required

- **Type**: `/api/v2/problems/second-factor-required`
- **Status**: 401
- **Meaning**: The session's sign-in awaits a second factor, and the operation needs a completed
  sign-in.
- **Client action**: Call `GET /api/v2/session` and show the second-factor step with
  `pendingSignIn.methods`.

## second-factor-failed

- **Type**: `/api/v2/problems/second-factor-failed`
- **Status**: 401
- **Meaning**: The second factor was wrong, was a TOTP code that had already been used, or belongs to
  a method that was deactivated or deleted after the password step. The pending sign-in is
  cancelled, as in legacy mailcow. The failure counts towards sign-in throttling, except for a
  method that disappeared.
- **Client action**: Start again with the password.

## second-factor-unavailable

- **Type**: `/api/v2/problems/second-factor-unavailable`
- **Status**: 403
- **Meaning**: The password was correct, but the account's only active second factors are
  deprecated U2F or HOTP entries. This API never accepts those, so the sign-in cannot be completed.
- **Client action**: Tell the user to ask an administrator to reset their second factors.

## temporary-verification-failure

- **Type**: `/api/v2/problems/temporary-verification-failure`
- **Status**: 503
- **Meaning**: The Yubico verification service could not be reached or answered with a server
  error. The pending sign-in stays open, and the attempt does not count towards throttling.
- **Client action**: Offer another method or retry after the `Retry-After` header.

## passkey-registration-failed

- **Type**: `/api/v2/problems/passkey-registration-failed`
- **Status**: 400
- **Meaning**: A passkey registration was rejected. Either the registration options are missing,
  used or expired, or the authenticator's response does not verify. A response fails to verify when
  its challenge, origin, host name, user verification or attestation format is wrong, or when the
  credential is already registered.
- **Client action**: Request new registration options and try again, or try another authenticator.

## api-key-invalid

- **Type**: `/api/v2/problems/api-key-invalid`
- **Status**: 401
- **Meaning**: The `X-API-Key` value matches no key of this API, or the key's owner is deactivated
  or deleted. Legacy `/api/v1` keys are not accepted. The attempt is reported to mailcow's fail2ban
  as `mailcow UI: Invalid password for API_USER by <client address>`.
- **Client action**: Fix the configured key. Repeated failures get the client address banned.

## api-key-expired

- **Type**: `/api/v2/problems/api-key-expired`
- **Status**: 401
- **Meaning**: The key's expiry has passed. The attempt is reported to fail2ban like an invalid key.
- **Client action**: Ask an administrator for a new key.

## api-key-revoked

- **Type**: `/api/v2/problems/api-key-revoked`
- **Status**: 401
- **Meaning**: An administrator revoked the key. Revocation takes effect on the very next request.
  The attempt is reported to fail2ban like an invalid key.
- **Client action**: Ask an administrator for a new key.

## api-key-source-not-allowed

- **Type**: `/api/v2/problems/api-key-source-not-allowed`
- **Status**: 403
- **Meaning**: The key is valid but restricted to networks that do not include the client address.
  The attempt is reported to fail2ban like an invalid key.
- **Client action**: Use the key from an allowed network, or ask an administrator for a key with
  other networks.

## insufficient-key-scope

- **Type**: `/api/v2/problems/insufficient-key-scope`
- **Status**: 403
- **Meaning**: A read-only key was used for a state-changing request (any method other than `GET`
  and `HEAD`). This is not reported to fail2ban.
- **Client action**: Use a read-write key for this operation.

## service-unavailable

- **Type**: `/api/v2/problems/service-unavailable`
- **Status**: 503
- **Meaning**: The readiness check failed. A dependency is unreachable, the mailcow database schema
  version is not supported by this release, or this API's own migrations are not applied.
- **Extension members**: `checks`, an object with the result (`ok` or `failed`) of `database`,
  `schema`, `redis` and `migrations`.
- **Client action**: For operators and monitoring. Fix the failed dependency.
