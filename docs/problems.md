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
  (`about:blank`); in a bulk action the item is reported `outdated`. A caller without the
  sync-jobs permission gets `forbidden`, and a read-only key `insufficient-key-scope`. Invalid input
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
- **Extension members**: none. The `Retry-After` header gives the seconds after which a retry makes
  sense.
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
  ISPConfig server (a FeldHost setting, off by default) is disabled on this server. The refused
  attempt is recorded in the audit log.
- **Client action**: Hide the action when `getMailDnsCapabilities` reports
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
  operation, and a domain administrator gets it for template writes and for protocol bulk actions
  without the `protocolAccess` flag. A domain administrator choosing a setting their flags do not
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
  reason. Ending the impersonation and signing out are never refused with this problem.
- **Client action**: Call `GET /csrf-token` and `GET /me`, tell the user that the impersonation
  ended, and do not resend the request: it was meant for the impersonated account.

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
