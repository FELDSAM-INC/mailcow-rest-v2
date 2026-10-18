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
  set up a second factor. Until then, the session can read `GET /me`, sign out and, when only
  `secondFactorSetup` is pending, register a passkey. Operations that need no signed-in account
  (the anti-forgery token, the session state, signing in again, the public configuration, the
  documentation and the health checks) stay available. API keys are never restricted.
- **Extension members**: `pendingActions`, a non-empty list of `passwordChange` and
  `secondFactorSetup`.
- **Client action**: Guide the user through the pending actions. Register a passkey for
  `secondFactorSetup`. For `passwordChange`, link to the legacy UI until password change is
  available in this API. Pending actions are re-evaluated on every request.

## too-many-requests

- **Type**: `/api/v2/problems/too-many-requests`
- **Status**: 429
- **Meaning**: A rate limit was reached. Two operations use it:
  - Sign-in throttling (feature 001). After 10 failed sign-ins for the same username and client
    address within 15 minutes, further attempts are refused without checking credentials. Failed
    second factors have their own count per username and client address, which a correct password
    does not reset; reaching it refuses the password and second-factor steps of that pair too. For
    passkeys, the limit counts failures per client address. The operator can configure both values.
  - DNS diagnostics (feature 010). Each caller (a user or an API key) may start 10 runs per minute
    (configurable). Every diagnostics result and every refusal carry `RateLimit-Limit`,
    `RateLimit-Remaining` and `RateLimit-Reset`, so clients can see the limit before reaching it.
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

## sync-job-exists

- **Type**: `/api/v2/problems/sync-job-exists`
- **Status**: 409
- **Meaning**: Feature 008. Another sync job already pulls from the same remote user on the same
  remote host into the same local mailbox. Legacy mailcow enforces the same uniqueness of
  `(mailbox, remote user, remote host)` and reports it as `object_exists`. The API refuses both a
  creation and an edit of the remote user or host that would create such a duplicate. Nothing is
  stored. Values are compared like legacy does, with the database's case-insensitive collation.
- **Extension members**: `syncJobId`, the `id` of the existing job. It syncs into the same mailbox,
  so the caller can always reach it.
- **Client action**: Open or edit the existing job instead, or change the remote user or host.
- **Related sync-job errors** (no type of their own): a job or target mailbox that does not exist
  or lies outside the caller's scope is `404` (`about:blank`). An outdated `If-Match` is `412`
  (`about:blank`); in a bulk action the target is reported `outdated`. A caller without the
  sync-jobs permission gets `permission-flag-required` with `requiredFlags: [syncjobs]`, and a read-only key
  `insufficient-key-scope`. Invalid input
  is `validation-failed` with the rule codes `invalidFormat` (`format: hostName`), `invalidRegex`,
  `optionNotAllowed` (param `option`), `optionContainsSpace` (param `token`),
  `customParamsNotPermitted`, `notEligible` (param `kind`), `immutable`, `outOfRange` (params `min`
  and `max`) and `tooMany` (param `max`, the bulk limit).

## domain-exists

- **Type**: `/api/v2/problems/domain-exists`
- **Status**: 409
- **Meaning**: A domain cannot be created because its name, after normalisation (trimmed,
  lower-cased, converted to ASCII), already exists as a domain or as an alias domain. Nothing was
  created. Of two concurrent creations of the same name, exactly one succeeds and the other gets
  this problem.
- **Client action**: Tell the user that the name is taken. Do not retry.

## domain-not-empty

- **Type**: `/api/v2/problems/domain-not-empty`
- **Status**: 409
- **Meaning**: A domain cannot be deleted because it still has mailboxes or resources. Nothing was
  deleted. Bulk deletions report it per item.
- **Extension members**: `mailboxes` and `resources`, the numbers that remain.
- **Client action**: Show the remaining objects (`GET /api/v2/domains/{domain}/deletion-preview`
  lists up to 100 of each) and let the user delete or move them first.

## domain-template-name-exists

- **Type**: `/api/v2/problems/domain-template-name-exists`
- **Status**: 409
- **Meaning**: Another domain template already has this name, compared without regard to letter
  case. Applies to creation and to renaming. Nothing was changed.
- **Client action**: Ask for another name.

## domain-template-protected

- **Type**: `/api/v2/problems/domain-template-protected`
- **Status**: 409
- **Meaning**: The Default domain template (named "Default", compared without regard to letter
  case) cannot be renamed or deleted. Its values can be changed. Nothing was changed.
- **Client action**: Hide rename and delete for the Default template.

## attribute-not-permitted

- **Type**: `/api/v2/problems/attribute-not-permitted`
- **Status**: 403
- **Meaning**: The request would change attributes that the caller's role or permission flags do
  not allow it to change, for example a domain administrator changing a domain's mailbox limit, or
  its description without the `domainDesc` flag. The whole request is refused and nothing changed.
  Attributes sent with their current value are always accepted, so a client may send a whole form.
- **Extension members**: `attributes`, the names of the attributes that may not be changed, as in
  the request body.
- **Client action**: Make these fields read-only for the user, or send only permitted changes.

## temporary-failure

- **Type**: `/api/v2/problems/temporary-failure`
- **Status**: 503
- **Meaning**: A dependency that the change needs, such as mailcow's shared store (Redis), is
  temporarily unavailable. The request left no partial change. In bulk actions, the item gets the
  outcome `failed` with this problem.
- **Extension members**: `dependency`, the unavailable service: `sharedStore` (mailcow's Redis,
  since feature 003 on every such answer), and for mailboxes (feature 003) also `mailStore` (the
  container API or the Dovecot container), `webmail` (the SOGo container) and `database` (the
  database step of a mailbox rename). A mailbox rename adds `steps`, as in
  `mailbox-rename-incomplete`. Later features may add values. The `Retry-After` header gives the
  seconds after which a retry makes sense.
- **Client action**: Retry later. Tell the user if it persists.

## dkim-key-exists

- **Type**: `/api/v2/problems/dkim-key-exists`
- **Status**: 409
- **Meaning**: A DKIM key generation, import or copy targets a name that already has a key,
  complete or not, and the request does not ask to overwrite. Of several concurrent requests for a
  name without a key, exactly one succeeds and the others get this problem. The existing key is
  unchanged.
- **Client action**: Show that the name already has a key. An administrator may repeat the request
  with `overwrite: true`, which replaces the whole old key in one step. Domain administrators cannot
  overwrite keys.

## dkim-private-key-export-disabled

- **Type**: `/api/v2/problems/dkim-private-key-export-disabled`
- **Status**: 403
- **Meaning**: A DKIM private key export was requested, but the operator has not enabled private key
  export (the server setting mirroring legacy's `SHOW_DKIM_PRIV_KEYS`, off by default). The refused
  attempt is recorded in the audit log.
- **Client action**: Explain that the operator must enable private key export. Do not retry.

## dkim-dns-publication-disabled

- **Type**: `/api/v2/problems/dkim-dns-publication-disabled`
- **Status**: 403
- **Meaning**: A DKIM record was asked to be published again, but the DKIM DNS publication to an
  ISPConfig server (a FeldHost setting, off by default) is disabled on this server, or enabled
  without the ISPConfig remote API address, user and password. The refused attempt is recorded in
  the audit log.
- **Client action**: Hide the action when `getDnsCapabilities` reports
  `dkim.dnsPublicationEnabled: false`. Publish the record at the DNS provider instead.

## mta-sts-policy-exists

- **Type**: `/api/v2/problems/mta-sts-policy-exists`
- **Status**: 409
- **Meaning**: An MTA-STS policy was to be created for a domain that already has one. A domain has
  at most one policy.
- **Client action**: Read the existing policy and change it with `PATCH
  /api/v2/mta-sts-policies/{domain}` instead.

## mailbox-limit-reached

- **Type**: `/api/v2/problems/mailbox-limit-reached`
- **Status**: 409
- **Meaning**: Feature 003. A mailbox cannot be created because its domain already has as many
  mailboxes as its limit allows. Resources (locations, things, groups) are not counted. Of
  concurrent creations in a domain with room for one, exactly one succeeds. Nothing was created.
- **Extension members**: `domain` (ASCII form), `count` (mailboxes now) and `limit`.
- **Client action**: Tell the user the limit; an administrator can raise it (feature 002).
- **Related mailbox errors**: a mailbox outside the caller's scope, a resource and an unknown
  address are all `404` (`about:blank`). An outdated `If-Match` is `412` (`about:blank`); in a bulk
  action the item is reported `outdated`. A mailbox user gets `forbidden` for every mailbox
  operation, and a domain administrator gets it for template writes. A protocol bulk action by a
  domain administrator without the `protocolAccess` flag gets `permission-flag-required` (`requiredFlags:
  [protocolAccess]`). A domain administrator choosing a setting their flags do not
  allow gets `attribute-not-permitted`, which for mailboxes also carries `requiredFlags` (refused
  member path → `PermissionFlags` name). A shared store, mail store or webmail outage is
  `temporary-failure`, which for mailboxes also carries `dependency` (`sharedStore`, `mailStore` or
  `webmail`) and, for a rename, `steps`. Keeping the old address as an alias on rename can give
  `domain-alias-limit-reached` (feature 004). Invalid input is `validation-failed`; the rule codes
  of feature 003 are listed in `ValidationRuleCode`.

## address-in-use

- **Type**: `/api/v2/problems/address-in-use`
- **Status**: 409
- **Meaning**: The address is already used by another object, so a mailbox cannot be created with
  it or renamed to it. Addresses are compared after normalisation (trimmed, lower-cased, domain in
  ASCII form). Nothing was changed.
- **Extension members**: `address`, the normalised address, and `holder`, the kind of object that
  holds it: `mailbox`, `resource`, `alias` or `temporaryAlias`.
- **Client action**: Tell the user which kind of object uses the address. Do not retry.

## mailbox-not-renamable

- **Type**: `/api/v2/problems/mailbox-not-renamable`
- **Status**: 409
- **Meaning**: Feature 003. Only active and login-disabled mailboxes can be renamed, as in legacy.
  The mailbox is inactive. Nothing was changed.
- **Client action**: Offer to activate the mailbox first.

## mailbox-rename-incomplete

- **Type**: `/api/v2/problems/mailbox-rename-incomplete`
- **Status**: 500
- **Meaning**: Feature 003. A step of a mailbox rename failed, and undoing the steps already done
  failed as well, so the mailbox is partly renamed. The audit record of the request holds the same
  step list.
- **Extension members**: `previousAddress`, `newAddress`, and `steps`, every rename step in order
  with its outcome (`done`, `undone`, `notDone`, `failed`, `unknown`, `undoFailed`, `skipped`).
  The steps are `sharedFolderPermissionsRemoval`, `sharedStoreMove`, `database`, `mailStoreMove`,
  `webmailRename` and `sharedFolderPermissionsRecreation`.
- **Client action**: Show the step list and ask an administrator to finish the steps marked
  `undoFailed` or `notDone` by hand: the database rows name the address given by the `database`
  step's outcome, the maildir is under `/var/vmail/<domain>/<local part>`, SOGo data is renamed
  with `sogo-tool rename-user`, and shared-folder permissions are set with `doveadm acl set`. Do not
  retry automatically.

## outcome-unknown

- **Type**: `/api/v2/problems/outcome-unknown`
- **Status**: 504
- **Meaning**: mailcow's container API did not answer within its timeout (`DOCKER_TIMEOUT`, 60
  seconds), so the outcome of one step is unknown. For a mailbox deletion the step is
  `mailStoreCleanup`: no record was removed, and retrying is safe because a maildir that is already
  gone counts as moved. For a mailbox rename, every step known to be done was undone and the
  unknown step is named.
- **Extension members**: `step`, the step whose outcome is unknown, and for a rename `steps`, as in
  `mailbox-rename-incomplete`.
- **Client action**: Retry a deletion later. After a rename, read the mailbox under both addresses
  and ask an administrator to check the named step before retrying.

## mailbox-template-name-exists

- **Type**: `/api/v2/problems/mailbox-template-name-exists`
- **Status**: 409
- **Meaning**: Feature 003. Another mailbox template already has this name, compared without
  regard to letter case. Applies to creation and to renaming. Nothing was changed.
- **Client action**: Ask for another name.

## mailbox-template-protected

- **Type**: `/api/v2/problems/mailbox-template-protected`
- **Status**: 409
- **Meaning**: Feature 003. The Default mailbox template cannot be renamed or deleted. Its values
  can be changed. Nothing was changed.
- **Client action**: Hide rename and delete for the Default template (`isDefault`).

## account-exists

- **Type**: `/api/v2/problems/account-exists`
- **Status**: 409
- **Meaning**: Feature 007. The username of a new or renamed administrator or domain administrator
  is already used by an administrator or a domain administrator. Both roles share one namespace,
  compared without regard to letter case (usernames are lower-cased). Nothing was changed.
- **Client action**: Show the error on the username field and ask for another name.

## last-active-admin

- **Type**: `/api/v2/problems/last-active-admin`
- **Status**: 409
- **Meaning**: Feature 007. The deactivation or deletion would leave the server without an active
  administrator. The check and the change are atomic, so concurrent requests cannot together
  remove the last one. In a bulk action, the item that would leave none active is reported
  `refused` with this problem and the other items are processed. Changes that do not reduce the
  number of active administrators are never refused with it. Nothing was changed.
- **Client action**: Activate or create another administrator first.

## cannot-delete-own-account

- **Type**: `/api/v2/problems/cannot-delete-own-account`
- **Status**: 409
- **Meaning**: Feature 007. An administrator tried to delete their own account, alone or in a bulk
  action (the item is then reported `refused`). Self-deactivation is allowed while another active
  administrator remains.
- **Client action**: Ask another administrator to delete the account.

## already-impersonating

- **Type**: `/api/v2/problems/already-impersonating`
- **Status**: 409
- **Meaning**: Feature 007. The session is already impersonating an account. Impersonations cannot
  be nested.
- **Client action**: End the current impersonation (`DELETE /session/impersonation`) and start the
  new one.

## impersonation-ended

- **Type**: `/api/v2/problems/impersonation-ended`
- **Status**: 403
- **Meaning**: Feature 007. The session's impersonation became invalid and ended automatically: the
  impersonated account was deleted or renamed, it left the impersonator's scope, or the
  impersonating domain administrator lost the `loginAs` flag. Any request of the session can
  detect it. The request was **not** carried out. The session now acts as the impersonator again,
  and its identifier and anti-forgery token were renewed. The automatic end is audited with its
  reason. Ending the impersonation (`DELETE /session/impersonation`) and signing out
  (`DELETE /session`) are never refused with this problem: when they detect the invalid
  impersonation, they end and audit it and complete with their normal response.
- **Client action**: Fetch a new anti-forgery token, re-read `GET /me`, tell the user that the
  impersonation ended, and do not resend the request: it was meant for the impersonated account.

## not-allowed-while-impersonating

- **Type**: `/api/v2/problems/not-allowed-while-impersonating`
- **Status**: 403
- **Meaning**: Feature 007. The session impersonates another account, and the operation is not
  available during an impersonation: managing the account's sign-in credentials (password,
  passkeys, second factors, recovery e-mail) or any webmail sign-in (on behalf of a user, or the
  impersonated mailbox's own webmail hand-off). Nothing was changed.
- **Client action**: Explain that the user must do this themselves, or end the impersonation first.

## webmail-on-behalf-disabled

- **Type**: `/api/v2/problems/webmail-on-behalf-disabled`
- **Status**: 403
- **Meaning**: Feature 007. Opening a mailbox's webmail on the user's behalf is disabled on this
  server: the setting `ALLOW_ADMIN_EMAIL_LOGIN` (mailcow.conf, default `n`) is off. The identity of
  administrators and domain administrators reports the setting as `webmailOnBehalfEnabled`.
- **Client action**: Hide the webmail button. The operator can enable the setting.

## account-inactive

- **Type**: `/api/v2/problems/account-inactive`
- **Status**: 409
- **Meaning**: Feature 007. A single sign-on token was requested for an inactive domain
  administrator account. No token was issued, because an inactive account can never sign in.
- **Client action**: Activate the account first.
- **Related errors of feature 007** (no type of their own): an account, impersonation target or
  mailbox that does not exist, has the other role or lies outside the caller's scope is `404`
  (`about:blank`). An outdated `If-Match` is `412` (`about:blank`); in a bulk action the item is
  reported `outdated`. Administrator account writes with an API key are `session-required`; other
  roles get `forbidden`. A failed single sign-on token sign-in is `sign-in-failed`. Invalid input is
  `validation-failed` with the rule codes `invalidFormat` (`format: accountUsername` or
  `domainName`), `reserved`, `tooLong`, `notFound`, `notEligible` (`kind: aliasDomain`, or `reason`
  for a webmail sign-in), `immutable`, `unknownField`, `required`, `tooMany` (the bulk limit) and
  feature 003's password rule codes.

## routing-entry-exists

- **Type**: `/api/v2/problems/routing-entry-exists`
- **Status**: 409
- **Meaning**: Feature 009. A creation or edit would duplicate a unique key of a routing entry:
  a transport destination (MX-based or not, compared without regard to letter case after
  normalisation), a BCC map's local destination and type (also when a type switch would duplicate
  another map), a recipient map's original destination, or a TLS policy map's destination. The
  uniqueness also holds under concurrent requests. Nothing changed. In a bulk action, the item is
  `refused` with this problem.
- **Extension members**: `field`, the request member that collides (`destination`,
  `localDestination`, `type`, `originalDestination`); `conflictingId`, the identifier of the existing
  entry as used in its path.
- **Client action**: Show the error on the field and offer to open the existing entry.
- **Related routing errors** (no type of their own): an entry, domain or mailbox that does not
  exist or lies outside the caller's scope is `404` (`about:blank`); an outdated `If-Match` is `412`
  (`about:blank`), and an outdated bulk item `outdated`. Mailcow's Redis, a temporary DNS failure
  or a write lock held too long gives `temporary-failure`. Invalid input is `validation-failed`
  with the codes listed for feature 009 in `ValidationRuleCode`, for example `invalidFormat` with
  `format: nextHop`, `invalidRegex`, `invalidCharacter` (a `:` in an SMTP username), `notResolvable`
  (a forwarding host name without addresses), `notFound` (an unknown or out-of-scope BCC local
  destination or relayhost) and `immutable` (a read-only member sent with a different value).

## next-hop-credentials-differ

- **Type**: `/api/v2/problems/next-hop-credentials-differ`
- **Status**: 409
- **Meaning**: Feature 009. A transport creation sends a username for a next hop that other
  transports already use with a different username (an empty username counts). Transports with the
  same next hop share one set of credentials, because the mail server looks credentials up by next
  hop. Nothing changed.
- **Extension members**: `nextHop`, the stored next hop; `transportIds`, the transports that use it.
- **Client action**: Create the transport without credentials (it inherits the next hop's), or
  change the credentials on one of the listed transports first, which applies them to all of them.

## next-hop-interferes

- **Type**: `/api/v2/problems/next-hop-interferes`
- **Status**: 409
- **Meaning**: Feature 009. A transport would use the next hop `host` while another uses `[host]` or
  `[host]:port`, or the reverse. The mail server looks up the credentials of `host` before those of
  `[host]:port`, so both cannot be used at the same time. Letter case is ignored. Nothing changed.
- **Extension members**: `nextHop`, the interfering stored next hop; `transportIds`, its transports.
- **Client action**: Use the same next hop spelling as the existing transports, or change them.

## permission-flag-required

- **Type**: `/api/v2/problems/permission-flag-required`
- **Status**: 403
- **Meaning**: The caller is a domain administrator, the operation is open to domain administrators
  in general, but it needs a permission flag that this account does not hold. Feature 009 uses it
  for changing a domain's relayhost (`domainRelayhost`), changing a mailbox's relayhost
  (`mailboxRelayhost`), reading the relayhost choices (either flag) and writing BCC maps
  (`bccMaps`). The flag is checked before the object is looked up, so the answer never reveals
  whether an object outside the caller's scope exists. Nothing changed. A bulk action that needs the
  flag is refused as a whole. Other features may use this type for the same meaning; an edit whose
  individual attributes are not permitted uses `attribute-not-permitted` instead.
- **Extension members**:
  - `requiredFlags`: a non-empty list of flag names as in `PermissionFlags` (camelCase), for example
    `["domainRelayhost"]`.
  - `requiredFlagsMatch`: either `all` (the default when the member is absent: every listed flag
    must be granted, for example `["quarantine", "quarantineAttachments"]` for a mailbox user's
    whole-message download) or `any` (any one listed flag suffices, for example
    `["domainRelayhost", "mailboxRelayhost"]` for the relayhost choices).

  With `all`, the list names only the flags the caller lacks. Both members are declared once in the
  contract's `PermissionFlagRequiredProblem` schema, which every feature's variant extends (G34);
  the server always sends `requiredFlagsMatch`.
- **Client action**: Hide or disable the action for this account, and tell the user which permission
  an administrator must grant.

## identity-provider-not-configured

- **Type**: `/api/v2/problems/identity-provider-not-configured`
- **Status**: 404
- **Meaning**: Feature 012. No external identity provider configuration is stored, so it cannot be
  read, changed or deleted. A whole save (`PUT /api/v2/identity-provider`) creates one.
- **Client action**: Offer to set up an identity provider.

## identity-provider-in-use

- **Type**: `/api/v2/problems/identity-provider-in-use`
- **Status**: 409
- **Meaning**: Feature 012. Mailboxes still authenticate against an external source that the request
  would remove: a deletion while any mailbox has an external authentication source, or a save of
  another type while mailboxes use a source other than the new type. Nothing changed.
- **Extension members**: `blockingMailboxes`, the number of mailboxes that block the request, and
  `mailboxCounts`, the number of mailboxes per external source (`keycloak`, `genericOidc`, `ldap`).
- **Client action**: Tell the administrator to switch those mailboxes to another authentication
  source first (mailbox list filtered by authentication source, feature 003).

## single-sign-on-not-available

- **Type**: `/api/v2/problems/single-sign-on-not-available`
- **Status**: 409
- **Meaning**: Feature 012. Single sign-on was started or completed, but the server has no complete
  Keycloak or generic OIDC configuration, for example because it was removed or changed to LDAP in
  the meantime.
- **Client action**: Refresh the public configuration and hide the single sign-on action.

## sign-in-state-invalid

- **Type**: `/api/v2/problems/sign-in-state-invalid`
- **Status**: 401
- **Meaning**: Feature 012. The `state` of a single sign-on completion or provider-error report is
  unknown, expired (10 minutes after the start), already used, or was issued to another browser
  session. The provider was not contacted, and the pending single sign-on is gone.
- **Client action**: Start a new single sign-on. Never resubmit the old code.

## identity-provider-unavailable

- **Type**: `/api/v2/problems/identity-provider-unavailable`
- **Status**: 503
- **Meaning**: Feature 012. The identity provider could not be reached, did not answer within
  7 seconds, answered with a server error, or refused the service account, during a single sign-on
  completion or a password sign-in of an LDAP or Keycloak mailbox. The attempt is not reported to
  fail2ban and does not count towards sign-in throttling. The `Retry-After` header says when to try
  again.
- **Client action**: Say that the organisation's sign-in service is not reachable. Keep the
  username, clear the password, and do not describe it as a wrong password.

## identity-provider-session-ended

- **Type**: `/api/v2/problems/identity-provider-session-ended`
- **Status**: 401
- **Meaning**: Feature 012. A single sign-on session ended. The provider refused or could not renew
  it (checked on the first request more than 5 minutes after the last renewal), the renewed user
  details carry another e-mail address, the identity provider configuration was removed, changed to
  another type or is incomplete, or the mailbox no longer authenticates against the provider. The
  session is gone; the user is still signed in at the provider.
- **Client action**: Show the sign-in screen with an explanation and offer single sign-on again.
  Do not send the browser to the provider automatically.
- **Related errors of feature 012** (no type of their own): an OAuth2 client that does not exist is
  `404` (`about:blank`). An outdated `If-Match` is `412` (`about:blank`); in a bulk action the item
  is reported `outdated`. A refused single sign-on completion or provider password sign-in is
  `sign-in-failed`. Invalid input is `validation-failed` with, among others, the rule codes
  `secretRequired`, `duplicate`, `notFound` (`resource: mailboxTemplate`), `conflictsWith`
  (`field: type`), `immutable`, `tooMany` (the bulk limit) and `invalidFormat` with the formats
  listed in `ValidationRuleCode`.

## container-protected

- **Type**: `/api/v2/problems/container-protected`
- **Status**: 409
- **Meaning**: Feature 011. The container is protected and the action is not allowed: stopping a
  protected container (by default the container service, the database, the key-value store, the
  reverse proxy and this API's own container, a server setting), or stopping or restarting this
  API's own container. Nothing was done. The attempt is audited.
- **Extension members**: `container`, the service name.
- **Client action**: Explain that the container cannot be stopped through the API. Offer a restart
  where it is allowed (`Container.availableActions`).

## container-not-running

- **Type**: `/api/v2/problems/container-not-running`
- **Status**: 409
- **Meaning**: Feature 011. The resource usage of a container that is not running was requested.
  The request was refused at once, without waiting for the container service.
- **Extension members**: `container`, the service name.
- **Client action**: Show the container as stopped and stop polling its usage until it runs again.

## container-action-failed

- **Type**: `/api/v2/problems/container-action-failed`
- **Status**: 502
- **Meaning**: Feature 011. mailcow's container service reported a failure for a start, stop or
  restart (after the documented retries for start and stop), or for the restart of the ban service
  during a rule reset. The audit record names the failure.
- **Extension members**: `container`, the service name; `serviceMessage`, the container service's
  message.
- **Client action**: Show `serviceMessage`, read the container again and let the administrator
  retry. Repeated failures need a look at the host (`docker compose ps`).

## mail-service-not-running

- **Type**: `/api/v2/problems/mail-service-not-running`
- **Status**: 409
- **Meaning**: Feature 011. The Postfix container is not running, so the queue cannot be listed,
  viewed or changed. Nothing was changed.
- **Extension members**: `container`, the stopped container (`postfix-mailcow`).
- **Client action**: Tell the administrator to start the named container (`POST
  /containers/{containerName}/start`) and try again.

## queue-action-failed

- **Type**: `/api/v2/problems/queue-action-failed`
- **Status**: 502
- **Meaning**: Feature 011. Postfix, or the container service running its command, reported a
  failure for a queue listing, a message view or a queue action. A selected-messages action is not
  partially reported: the whole request failed, and the audit record names the failure.
- **Extension members**: `serviceMessage`, the reported message, never message content.
- **Client action**: Show `serviceMessage` and list the queue again to see what changed.

## ban-rules-not-restored

- **Type**: `/api/v2/problems/ban-rules-not-restored`
- **Status**: 504
- **Meaning**: Feature 011. The ban rules were reset: the stored rules were removed and the ban
  service was restarted, but it did not store mailcow's default rules within 10 seconds of the
  restart. It stores them with the next log line it receives, as legacy reports. The audit record
  holds the old rules.
- **Extension members**: none.
- **Client action**: Do not repeat the reset. Read the rules again after the next login attempt on
  the server, or later.

## ip-check-disabled

- **Type**: `/api/v2/problems/ip-check-disabled`
- **Status**: 409
- **Meaning**: Feature 011. The public address lookup is refused because the IP check of the legacy
  customisation settings is disabled (`MonitoringCapabilities.ipCheckEnabled` is `false`). No
  outbound request was made. Enabling the check belongs to feature 013.
- **Extension members**: none.
- **Client action**: Hide or disable the lookup, or link to the setting.

Feature 011 also uses `temporary-failure` (503, with `Retry-After`) when the container service,
the key-value store, the database or the Rspamd controller is unavailable for a read or a change,
or when the audit log cannot be written before an irreversible operation (container action, queue
action, rule reset), and `outcome-unknown` (504) when the container service does not answer within
`DOCKER_TIMEOUT`; there `step` names the action (`start`, `stop`, `restart`, `deliver`, `hold`,
`release`, `delete`, `flush`, `purge`, `rulesReset`). An outdated `If-Match` on the ban settings,
rules, rule reset or ban list rotation answers `412` (`about:blank`). A change that was carried out
but whose audit record could not be written (an irreversible operation whose record still failed at
the container-service timeout, or a ban service change whose record failed after it was stored)
answers as it would otherwise and carries the response header `Audit-Recorded: false`. A
`temporary-failure` of feature 011 always means that nothing was changed; a failure after a change
started is `outcome-unknown`.

## quarantine-item-busy

- **Type**: `/api/v2/problems/quarantine-item-busy`
- **Status**: 409
- **Meaning**: Feature 006. Another request of this API is releasing, learning or deleting the same
  quarantine item right now. An item is processed by at most one request, so a message is never
  delivered or learned twice through this API. Nothing changed. In a bulk action, the item gets the
  outcome `refused` with this problem.
- **Extension members**: none. The `Retry-After` header (5 seconds) says when to look again.
- **Client action**: Re-check the item after `Retry-After`. It is then gone (`404`) or available
  again.
- **Related feature 006 errors** (no type of their own, or shared types):
  - An item, mailbox, domain, alias domain, list entry or bucket that does not exist or lies outside
    the caller's scope is `404` (`about:blank`), and so is a resource addressed as a mailbox. An
    outdated `If-Match` is `412` (`about:blank`); in a bulk action the target is `outdated`.
  - `permission-flag-required` (403, `requiredFlags`) is also used for mailbox users: `quarantine`
    (item actions, whole-message download), `quarantineAttachments` (attachment downloads,
    whole-message download), `spamScore` and `spamPolicy`; and for domain administrators:
    `quarantine`, `spamPolicy` and `ratelimit`. The whole-message download needs both `quarantine`
    and `quarantineAttachments` for mailbox users; when both are missing, `requiredFlags` names
    `quarantine` first. A mailbox user who would change a notification setting without
    `quarantineNotification` or `quarantineCategory` gets `attribute-not-permitted` (`attributes`,
    `requiredFlags` per attribute). A role or key type that may never use an operation (a mailbox
    user and rate limits, a domain administrator and the quarantine settings) gets `forbidden`.
  - `temporary-failure` (503) carries, in feature 006, the extension members `dependency`
    (`sharedStore`, `spamFilter` or `mailServer`) and, for item actions, `step` (`delivery` or
    `learning`). A release whose learning fails after the delivery is not an error: the action
    report says `learning: failed`.
  - Invalid input is `validation-failed` with the rule codes listed for feature 006 in
    `ValidationRuleCode`, among them `invalidTemplate` (params `reason`: `syntax`, `security`,
    `render` or `timeout`, and `line`) for a notification template the legacy notifier cannot use.

## quarantine-delivery-refused

- **Type**: `/api/v2/problems/quarantine-delivery-refused`
- **Status**: 409
- **Meaning**: Feature 006. The mail server permanently refused (`5xx`) the message released from the
  quarantine, for example because the recipient mailbox no longer exists or a policy rejects it.
  The item is kept and nothing was learned. When the mail server cannot be reached, times out or
  answers `4xx`, the answer is `temporary-failure` with `dependency: mailServer` instead. In a bulk
  action, the item gets the outcome `refused` with this problem.
- **Extension members**: `smtpCode` (500–599) and `smtpResponse`, the mail server's answer.
- **Client action**: Show the mail server's answer. Delete the item, or fix the cause (for example
  recreate the mailbox) and release it again.

## quarantine-message-too-large

- **Type**: `/api/v2/problems/quarantine-message-too-large`
- **Status**: 409
- **Meaning**: Feature 006. The stored message is larger than the content size limit (10 MiB by
  default, `contentSizeLimit` of `GET /capabilities/quarantine`), so its attachments and the whole
  message are not downloaded, as in legacy. The item's details then report
  `contentStatus: tooLarge`. Releasing, learning and deleting the item still work.
- **Extension members**: `size` and `limit`, in bytes.
- **Client action**: Explain that the message is too large to inspect. Offer release or deletion.

## spam-policy-entry-exists

- **Type**: `/api/v2/problems/spam-policy-entry-exists`
- **Status**: 409
- **Meaning**: Feature 006. The domain, alias domain or mailbox already has this pattern (after
  trimming and lower-casing) on its allow or its block list. A pattern may be on only one list of
  an owner, as in legacy. The same pattern may exist for other owners. Nothing changed.
- **Extension members**: `list` (`allow` or `block`) and `entryId`, the existing entry of the same
  owner.
- **Client action**: Point the user to the existing entry. To move a pattern to the other list,
  remove the existing entry first.

## demo-mode

- **Type**: `/api/v2/problems/demo-mode`
- **Status**: 403
- **Meaning**: Feature 013. The operator enabled mailcow's demo mode (`DEMO_MODE=y`), which refuses
  changes to logos, UI texts, login page options, app links, brand colours and the IP check, as the
  legacy UI refuses its customisation writes. Nothing was changed. `GET /api/v2/capabilities/settings`
  reports the mode beforehand (`demoMode`).
- **Client action**: Explain that branding cannot be changed on this server. Do not retry.

## feature-disabled

- **Type**: `/api/v2/problems/feature-disabled`
- **Status**: 403
- **Meaning**: Feature 013. The operator disabled the operation on this server with a server
  setting. Today this is license verification (`RESTV2_LICENSE_VERIFICATION=false`, for example on
  the FeldHost deployment): reading the license status and verifying the GUID are refused, and the
  license service is never contacted. `GET /api/v2/capabilities/settings` reports it beforehand
  (`licenseVerificationEnabled`).
- **Client action**: State that the feature is not available on this server. Do not retry.

## regex-map-unavailable

- **Type**: `/api/v2/problems/regex-map-unavailable`
- **Status**: 409
- **Meaning**: Feature 013. The global regex map is editable by configuration, but its file does
  not exist in the shared Rspamd map directory of this server, so nothing was written. Legacy
  silently skips such a map and reports success.
- **Client action**: Tell the administrator that the deployment does not provide this map. Do not
  retry.

## global-sieve-filter-unavailable

- **Type**: `/api/v2/problems/global-sieve-filter-unavailable`
- **Status**: 409
- **Meaning**: Feature 013. The shared file of the global Sieve pre-filter or post-filter does not
  exist on this server, so nothing was written and Dovecot was not restarted. Legacy restarts
  Dovecot anyway and reports success.
- **Client action**: Tell the administrator that the deployment does not provide the filter file.
  Do not retry.

## mass-mail-already-running

- **Type**: `/api/v2/problems/mass-mail-already-running`
- **Status**: 409
- **Meaning**: Feature 013. Another mass mail job is running, and only one runs at a time. The new
  job was not created and nothing was sent.
- **Extension members**: `runningJobId`, the identifier of the running job.
- **Client action**: Show the running job (`GET /api/v2/mass-mail-jobs/{runningJobId}`). Send again
  after it has completed or was cancelled.

## mass-mail-recipient-count-changed

- **Type**: `/api/v2/problems/mass-mail-recipient-count-changed`
- **Status**: 409
- **Meaning**: Feature 013. The selection of a new mass mail job yields another number of recipients
  than the confirmed `expectedRecipients`, for example because a mailbox was created or deactivated
  after the preview. The job was not created and nothing was sent.
- **Extension members**: `actualRecipients`, the number the selection yields now.
- **Client action**: Preview the selection again and ask the administrator to confirm the new count.
- **Related system-settings errors** (feature 013, no type of their own or another feature's type):
  an outdated `If-Match` is `412` (`about:blank`). Cancelling a completed mass mail job is `409`
  (`about:blank`). mailcow's shared store or settings map endpoint being unavailable is
  `temporary-failure`. Sending mass mail, cancelling it and setting the Rspamd web interface password
  with an API key is `session-required`. A failure of mailcow's container service while setting the
  Rspamd password is `container-action-failed` with `container: rspamd-mailcow` and without
  `serviceMessage`; its timeout is `outcome-unknown` with `step: rspamdUiPassword`. Invalid input is
  `validation-failed` with the rule codes listed under feature 013 in `ValidationRuleCode`.

## domain-alias-limit-reached

- **Type**: `/api/v2/problems/domain-alias-limit-reached`
- **Status**: 409
- **Meaning**: Feature 004. Creating, copying or moving aliases would make the aliases in use of a
  primary domain exceed its alias limit. Aliases in use are counted as feature 002 counts them:
  the aliases of the domain and of its alias domains, not counting the rows through which
  mailboxes receive mail at their own address. It applies to alias creation, a copy over alias
  domains (where the copy is reported as skipped instead), an alias address change into another
  primary domain, an alias domain whose target changes (all its aliases move), and a mailbox rename
  that keeps the old address as an alias (feature 003). Of concurrent requests for the last free
  alias, exactly one succeeds. Nothing was changed.
- **Extension members**: `domain` (the primary domain, ASCII form), `count` (aliases in use now),
  `limit` (the domain's alias limit) and `requested` (aliases the request would add).
- **Client action**: Tell the user the limit; an administrator can raise it (feature 002).
- **Related alias, alias domain, resource and sender settings errors**: an object outside the
  caller's scope, a mailbox's own-address row and a mailbox address used as a resource are `404`
  (`about:blank`). An outdated `If-Match` is `412` (`about:blank`); in a bulk action the item is
  reported `outdated`. An address that is taken is `address-in-use` (extension members `address`
  and `holder`). A domain administrator creating an alias domain without `aliasDomains` gets
  `permission-flag-required` (`requiredFlags: [aliasDomains]`); adding external alias destinations without
  `aliasExternalGoto`, giving an initial rate limit without `ratelimit`, or changing external
  sender addresses without `extendSenderAcl` gets `attribute-not-permitted` with `attributes` and
  the `requiredFlags` map (field → flag). External destinations that the server disables for every
  role (`ALIAS_DISABLE_EXTERNAL_DOMAINS`) are `validation-failed` with `notEligible`
  (`kind: externalDestination`). Only destinations that a request adds are checked. When mailcow's shared
  store is down, alias domain creation, deletion and reads answer `temporary-failure`
  (`dependency: sharedStore`). Invalid input is `validation-failed`; the rule codes and formats of
  feature 004 are listed in `ValidationRuleCode`.

## alias-domain-already-exists

- **Type**: `/api/v2/problems/alias-domain-already-exists`
- **Status**: 409
- **Meaning**: Feature 004. A new alias domain's name, after normalisation (trimmed, lower-cased,
  converted to ASCII), already exists as a domain or as an alias domain. Nothing was created.
- **Extension members**: `name` (the normalised name) and `existingKind` (`domain` or
  `aliasDomain`).
- **Client action**: Tell the user that the name is taken. Do not retry.

## not-primary-domain-alias

- **Type**: `/api/v2/problems/not-primary-domain-alias`
- **Status**: 409
- **Meaning**: Feature 004. Only an alias of a primary domain can be copied over that domain's
  alias domains (the legacy action "Expand alias over alias domains"). The alias lives in an alias
  domain. Nothing was created. In a bulk copy the item is reported `refused` with this problem.
- **Client action**: Offer the action only for aliases with `inAliasDomain: false`.

## current-password-incorrect

- **Type**: `/api/v2/problems/current-password-incorrect`
- **Status**: 403
- **Meaning**: A self-service request that needs the account's current password (password
  change, recovery address, second-factor enrolment, removing all second factors) carried a wrong
  one. Nothing changed. The attempt is audited and counts towards the sign-in throttling of the
  account and client address: later failures are delayed, and after 10 failures in 15 minutes the
  answer is `too-many-requests`. It is not reported to fail2ban. Feature 005.
- **Client action**: Ask for the current password again. Do not retry automatically.

## password-managed-externally

- **Type**: `/api/v2/problems/password-managed-externally`
- **Status**: 403
- **Meaning**: The mailbox's password lives in an external identity provider (LDAP, Keycloak or
  generic OIDC), so its password, recovery address and second factors cannot be managed through
  this API. Feature 005.
- **Client action**: Hide these functions for the account (the self-service capabilities report
  `passwordChangeAvailable: false`) and point the user to the identity provider.

## password-reset-unavailable

- **Type**: `/api/v2/problems/password-reset-unavailable`
- **Status**: 403
- **Meaning**: Password reset does not work on this server, because the operator's reset message
  has no sender or no subject. Every reset request answers this until it is configured. Feature
  005.
- **Client action**: Hide "Forgot password" (the self-service capabilities report
  `passwordReset.available: false`) and tell the user to contact the administrator.

## invalid-reset-token

- **Type**: `/api/v2/problems/invalid-reset-token`
- **Status**: 400
- **Meaning**: The reset token is unknown, expired (15 minutes by default), already used (any
  completed reset invalidates all tokens of the account), or belongs to a mailbox that is no longer
  active or no longer uses the mailcow source. Feature 005.
- **Client action**: Offer to request a new reset link.

## second-factor-enrolment-expired

- **Type**: `/api/v2/problems/second-factor-enrolment-expired`
- **Status**: 400
- **Meaning**: The session has no TOTP enrolment or WebAuthn second-factor registration options, or
  they expired (10 and 5 minutes), were used up (five wrong TOTP codes, one WebAuthn attempt) or
  were started in another session. Nothing was stored. Feature 005.
- **Client action**: Start the enrolment again.

## second-factor-enrolment-failed

- **Type**: `/api/v2/problems/second-factor-enrolment-failed`
- **Status**: 400
- **Meaning**: The TOTP code did not match the enrolment's secret, the WebAuthn response did not
  verify, or the Yubico service rejected the OTP. Nothing was stored. A TOTP enrolment stays open
  while attempts remain; WebAuthn options are used up. Feature 005.
- **Extension members**: `attemptsRemaining` (TOTP only), the wrong codes left before the
  enrolment is cancelled.
- **Client action**: Ask for a new code, or start again.

## last-second-factor

- **Type**: `/api/v2/problems/last-second-factor`
- **Status**: 409
- **Meaning**: The second-factor entry to remove is the account's only active entry, which legacy
  does not allow to remove on its own. Nothing changed. Feature 005.
- **Client action**: Offer "remove all second factors" (with the current password) or enrolling
  another one first.

## second-factor-required-by-policy

- **Type**: `/api/v2/problems/second-factor-required-by-policy`
- **Status**: 403
- **Meaning**: An administrator requires the account to keep a second factor (legacy `force_tfa`),
  so its entries cannot be removed. Nothing changed. Feature 005.
- **Client action**: Do not offer removal when the self-service capabilities report
  `account.secondFactorRequired: true`; enrol a new entry before replacing an old one.

## push-notifications-not-configured

- **Type**: `/api/v2/problems/push-notifications-not-configured`
- **Status**: 409
- **Meaning**: The mailbox has no stored Pushover application token and user key, so there is
  nothing to verify. Feature 005.
- **Client action**: Save the push notification settings first.

## groupware-disabled

- **Type**: `/api/v2/problems/groupware-disabled`
- **Status**: 403
- **Meaning**: mailcow runs without its groupware (`SKIP_SOGO=y`), so webmail, the webmail
  hand-off, the ActiveSync cache and groupware profile resets, and the Apple profile with calendars
  and contacts are not available. Feature 005.
- **Client action**: Hide these functions (the self-service capabilities report
  `groupware.enabled: false`).

## Feature 005 use of shared types

- `permission-flag-required` refuses a self-service change that needs a flag the caller lacks: a
  mailbox user's own `user_acl` flag, or for app passwords, push notifications and Sieve filters the
  domain administrator's own `da_acl` flag. Its `requiredFlags` holds that one flag (for example
  `appPasswds`, `spamAlias`, `filters`, `pushover`, `delimiterAction`, `tlsPolicy`, `easReset`,
  `sogoProfileReset`, `pwReset`). Values sent with
  their current value never need the flag, and reading never needs it.
- `temporary-failure` carries the extension `dependency` (`sharedStore`, `mailStore`, `pushover` or
  `addressGeneration`) in the self-service operations.
- `second-factor-required` (status 401) is also the answer of `POST /api/v2/password-resets/completion`
  when the account has active second factors and the request carried none. It then has the
  extension `methods`, the usable methods in the recommended order; the token stays valid.
- `second-factor-failed` (status 401) answers a wrong second factor in a reset completion; the token
  stays valid and the failure counts towards the account's sign-in throttling.
- `second-factor-unavailable` (status 403) answers a reset completion for an account whose only
  second factors are deprecated U2F or HOTP entries.
- `temporary-verification-failure` (status 503) also answers a Yubico OTP enrolment or reset when
  the Yubico service cannot be reached.
- `too-many-requests` also answers wrong current passwords (per account and client address) and
  more than 10 reset requests, token checks or completions per client address in 15 minutes.
- An outdated `If-Match` on a self-service resource answers `412` (`about:blank`); in the bulk TLS
  change the item's outcome is `outdated`.
