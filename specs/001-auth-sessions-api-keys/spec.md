# Feature Specification: Authentication, Sessions, API Keys, Identity and Branding Configuration

**Feature Branch**: `main` (no feature branches, constitution 1.1.0)

**Created**: 2026-10-18

**Status**: Draft

**Input**: User description, taken verbatim from `docs/roadmap.md` ("Input for the first
`/speckit-specify`"):

> Authentication, sessions, API keys, identity and branding configuration for mailcow REST API v2:
> a standards-compliant REST API (base path /api/v2) for an existing mailcow-dockerized server. It
> runs next to the legacy mailcow UI and /api/v1, which keep working unchanged, and it is the
> backend of the separate mailcowui-ng web app.
>
> Actors: server administrators, domain administrators, mailbox users, and automation clients
> (billing systems, scripts). Users already exist in the mailcow database with password hashes,
> and some have TOTP, WebAuthn or Yubi OTP second factors enrolled.
>
> User stories: (1, P1) password sign-in for all roles with second-factor completion, session and
> sign-out, preserving legacy account resolution order, throttling and fail2ban reporting;
> (2, P1) "who am I" with role, scope, effective ACL flags and editable preferences (language,
> theme); (3, P1) administrator-managed API keys with name, owner, read-only/read-write scope,
> optional expiry and allowed source networks, secret shown once, `X-API-Key` authentication with
> RFC 9457 errors, invalid keys reported to fail2ban; (4, P2) public branding configuration read
> from the existing mailcow customisation settings; (5, P2) audit log of sign-ins, failed
> sign-ins, sign-outs and API key changes, listable by administrators with filters.
>
> Out of scope: managing mail objects, enrolling new second factors, identity-provider
> (OIDC/LDAP/Keycloak) login, replacing legacy internal endpoints, legacy /api/v1 keys.
>
> Constraints: existing accounts, password hashes and second factors MUST keep working unchanged
> in both implementations.

## Clarifications

### Session 2026-10-18

- Q: Which accounts can own an API key, and whose permissions does the key act with? → A: An
  administrator or a domain administrator can own a key. The key acts with exactly its owner's
  role, domains and permission flags, further limited by its access level. Only administrators
  manage keys.
- Q: Is passwordless FIDO2 sign-in part of this feature? → A: Yes, as **passkeys**. Legacy calls
  these credentials "FIDO2" or "Touch ID on Apple M1". They are ordinary passkeys and the most
  usable method, because everyone with a Mac or an iPhone has one. Sign-in methods are presented in
  this order: passkey first when available, TOTP as the second option, and every other method as
  an advanced option.
- Q: Should signed-in users be able to register and remove their own passkeys in feature 001,
  instead of waiting for feature 005? → A: Yes. Users of every role can register, rename and
  remove their own passkeys in 001. Enrolling TOTP, WebAuthn security keys and Yubico OTP stays in
  005.
- Q: Should existing mailboxes whose password lives in LDAP or Keycloak (instead of mailcow) be
  able to sign in with username and password in feature 001? → A: No. They are refused until the
  identity-provider feature (012) and keep using the legacy UI.
- Q: Should an automation client using an administrator's API key be allowed to create and revoke
  other API keys? → A: No. Keys are created and revoked only by an administrator signed in through
  the web app. An opt-in "may manage keys" permission can be added later without breaking clients,
  for example with domain-administrator management (007).
- Q: Who should translate error messages into the user's language, the web app or the API? → A:
  The web app. The API returns English messages plus stable machine-readable codes, and the
  mailcowui-ng web app translates them with its own language files.
- Q: When mailcow forces an account to change its password, should the user be able to do that
  change in the new web app in feature 001, or keep using the legacy UI for it until feature 005?
  → A: Keep it in 005. Until then the restricted session names the pending password change, and
  the user changes the password in the legacy UI.
- Q: Should the public configuration also carry the app links that legacy shows on its login page
  and in its header menu? → A: Yes, read-only (FR-056). They include the server's built-in apps
  (by default Webmail), the operator's links and the operator's label for the apps menu. Webmail
  is left out when SOGo is disabled. Editing app links stays in feature 013. (orchestrator
  decision: the UI 001 spec found the gap; legacy shows the links before and after sign-in, so the
  new web app needs them from the same place.)
- Q: How does an administrator choose the owner of a new API key before administrator management
  (feature 007) exists? → A: An administrator-only read lists the active administrators and domain
  administrators who can own a key, with search and paging (FR-057). A key owned by an
  administrator, including a read-only one, may use it. (orchestrator decision: the UI 001 spec
  found that the key form otherwise needs a typed username with no way to look it up.)
- Q: Do the lists of this feature follow the cross-feature list conventions? → A: Yes (FR-058):
  default page size 25 and at most 1,000, a substring search, and the version tag on every entry
  of a resource that can change. (orchestrator decision: `docs/conventions.md`.)
- Q: Which permission flags does a domain administrator have when mailcow has no `da_acl` row
  for them? → A: The flags of the row legacy would insert: legacy creates the row with only the
  username, so every flag takes the upstream schema's column default (FR-028). Not "every flag
  off". (orchestrator decision during implementation: the row is missing only after manual
  database changes, and the defaults are what legacy grants a new domain administrator.)
- Q: Which attestation does passkey registration request from the authenticator? → A: The same
  as legacy (FR-052): `indirect` by default, `direct` only when the operator accepts trusted
  vendors only (`WEBAUTHN_ONLY_TRUSTED_VENDORS=y`, which verifies attestation certificates), and
  `none` when only the `none` format is accepted. (orchestrator decision during implementation:
  `direct` makes some browsers show an attestation privacy prompt that legacy never shows.)

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Sign in with password and second factor, then sign out (Priority: P1)

An administrator, a domain administrator or a mailbox user opens the new web app and signs in
with the username and password they already use in the legacy mailcow UI. If their account has an
active second factor, the app asks for it. The methods are offered in the recommended order:
security key or passkey (WebAuthn) first, authenticator app (TOTP) second, and Yubico OTP as an
advanced option. The user completes one of them and then holds a session that lets them use the
app until they sign out or stay idle for longer than the server's session lifetime. Nothing about
their account has to be re-created or re-enrolled, and their legacy UI login keeps working exactly
as before.

**Why this priority**: Every account has a password, so this is the sign-in path that always works.
Every other feature of the new API and web app depends on a signed-in identity.

**Independent Test**: Load accounts of all three roles (with and without each second-factor type,
and with each supported password-hash format) into a test copy of the mailcow database, sign each
in through the API, confirm a session is established and usable, then sign out and confirm the
session no longer works. Confirm the same accounts still sign in to the legacy UI.

**Acceptance Scenarios**:

1. **Given** an active administrator without second factors, **When** the client submits the
   correct username and password, **Then** a session is established for that administrator with
   the administrator role.
2. **Given** an active mailbox user with WebAuthn, TOTP and Yubico OTP second factors, **When** the
   client submits the correct password, **Then** no full session is established yet, and the
   response lists the methods in the order WebAuthn, TOTP, Yubico OTP, with Yubico OTP marked as
   advanced. **When** the client then submits a valid TOTP code, **Then** the session is
   established for that mailbox user.
3. **Given** an active domain administrator with a WebAuthn second factor registered in the legacy
   UI, **When** the client completes the password step, requests a WebAuthn challenge for the
   pending sign-in and returns a valid assertion, **Then** the session is established.
4. **Given** any account, **When** the client submits a wrong password, **Then** the sign-in fails
   with a generic error that does not reveal whether the account exists, the attempt is reported
   to mailcow's fail2ban channel, and the failure is recorded in the audit log.
5. **Given** a signed-in client, **When** it signs out, **Then** the session ends immediately and
   any further request with it is treated as unauthenticated.
6. **Given** a username that exists both as an administrator and as a mailbox user, **When** the
   client signs in without naming a role, **Then** the administrator account is tried first, as in
   legacy. **When** the client names the mailbox-user role, **Then** only the mailbox account is
   tried.
7. **Given** repeated failed sign-ins for the same username from the same address, **When**
   further attempts arrive, **Then** each is answered after a progressively longer delay. Once the
   throttling threshold is reached, attempts are refused as "too many requests" with a hint on when
   to retry.

---

### User Story 2 - Register and sign in with a passkey (Priority: P1)

A user has a passkey: Touch ID or Face ID on a Mac or iPhone, a passkey synced through their
platform account, or a hardware security key. They may have registered it in the legacy UI, which
calls it "FIDO2" or "Touch ID". Or, after signing in with their password, they registered it in the
new web app, where they can also rename and remove their passkeys. The new web app offers passkey
sign-in first. The user confirms with their fingerprint, face or device PIN, without typing a
username or a password, and is signed in. If no passkey is available on the device, the app offers
username and password, as in User Story 1.

**Why this priority**: Passkeys are the most usable sign-in method and resist phishing, and most
users already have one on their Apple devices. The project owner wants passkey sign-in to be the
primary way into the new web app.

**Independent Test**: For an account of each role, register one passkey in the legacy UI and one
through the API. Sign each in through the API with only a passkey assertion, and confirm that a
session is established with the right role and that both passkeys also work in the legacy UI.
Repeat with a deactivated account, a deactivated credential, a removed credential and an unknown
credential, and confirm each is refused.

**Acceptance Scenarios**:

1. **Given** a mailbox user with a passkey registered in the legacy UI, **When** the client
   requests a passkey challenge without a username and returns a valid assertion, **Then** a
   session is established for that mailbox user, with no password and no further second factor.
2. **Given** an administrator with a passkey whose account has been deactivated, **When** the
   passkey is used, **Then** sign-in fails with the generic error. (Legacy would let this sign-in
   through.)
3. **Given** an assertion from a credential that mailcow does not know, **When** it is submitted,
   **Then** sign-in fails with the generic error and the failure is audited.
4. **Given** an account flagged in mailcow for a forced password change, **When** it signs in with a
   passkey, **Then** the session is restricted exactly as after a password sign-in.
5. **Given** a client that names the domain-administrator role, **When** it submits a valid
   assertion from a mailbox user's passkey, **Then** sign-in fails with the generic error.
6. **Given** a mailbox user signed in with their password, **When** the client requests passkey
   registration options and returns the authenticator's response, **Then** the passkey is saved
   to the account, appears in the user's passkey list, and can be used for the next sign-in in
   either UI.
7. **Given** a user with two passkeys, **When** they rename one and remove the other, **Then** the
   list shows only the renamed passkey, and the removed one no longer signs in.
8. **Given** an account that mailcow forces to set up a second factor and that has none, **When**
   it signs in with a password, **Then** the session is restricted. **When** the user registers a
   passkey, **Then** the restriction is lifted at once, and later sign-ins are no longer
   restricted.

---

### User Story 3 - Ask "who am I" and manage own preferences (Priority: P1)

A signed-in client asks the API who it is. It learns:

- the username and the role;
- the scope: all domains for an administrator, the managed domains for a domain administrator, the
  own mailbox for a mailbox user;
- the effective permission flags;
- any actions the account must complete before it can do anything else;
- the user's preferences (language and theme).

The web app uses this to build its navigation and to hide what the user may not do. A signed-in
user can change their language and theme, and the choice follows them to any device on which they
sign in.

**Why this priority**: The web app cannot render anything role-appropriate without it, and every
later feature relies on the same scope and permission model.

**Independent Test**: Sign in as one account of each role, request the identity, and compare role,
scope and permission flags with the mailcow database. Change the permission flags of a domain
administrator in the legacy UI and confirm that the next identity request reflects the change.
Update preferences, sign out, sign in again and confirm the preferences persisted.

**Acceptance Scenarios**:

1. **Given** a signed-in domain administrator who manages two active domains, **When** the client
   requests its identity, **Then** the response contains the domain-administrator role, exactly
   those two domains and the domain administrator's permission flags as stored in mailcow.
2. **Given** a signed-in mailbox user, **When** the client requests its identity, **Then** the
   response contains the mailbox-user role, the mailbox and its domain, and the mailbox user's
   permission flags.
3. **Given** a signed-in user, **When** they set their language to one of the enabled languages and
   their theme to dark, **Then** the change is saved and returned by every later identity request,
   including in new sessions.
4. **Given** a signed-in user, **When** they submit a language that is not enabled on the server,
   **Then** the update is rejected with a validation error that names the invalid field.
5. **Given** an account flagged in mailcow for a forced password change, **When** it signs in,
   **Then** the session is restricted, the identity response lists the pending action, and any other
   operation is refused with an error that names the pending action.

---

### User Story 4 - Manage API keys and authenticate automation clients (Priority: P1)

An administrator creates an API key for a billing system. They give it a name and choose its owner,
which is either an administrator or a domain administrator such as a reseller. They choose
read-only or read-write access, and they can optionally set an expiry date and a list of networks
from which the key may be used. The secret is shown once, at creation.

The billing system sends the secret with each request in the `X-API-Key` header. It acts with the
owner's role, domains and permission flags, limited by the key's access level, so a key owned by a
domain administrator can never reach beyond that domain administrator's domains.

Administrators can list and inspect keys, see when and from where each key was last used, and
revoke a key, which stops it working immediately. The client receives a precise, machine-readable
error when a key is missing, wrong, expired or revoked, when a read-only key is used for a write,
and when a key is used from a network it is not allowed from. Unknown and otherwise invalid keys
are reported to fail2ban.

**Why this priority**: Automation clients are a primary audience of a public REST API, and
mailcow's existing keys grant unscoped administrator rights. Scoped, expiring, revocable keys are a
core reason to adopt the new API.

**Independent Test**: As an administrator, create keys covering both owner roles and each
combination of access level, expiry and allowed network. Exercise them from allowed and disallowed
addresses, with read and write requests, before and after expiry and revocation, and check each
outcome and each fail2ban report.

**Acceptance Scenarios**:

1. **Given** a signed-in administrator, **When** they create a key with a name, an owner and
   read-write access, **Then** the response contains the secret exactly once, and later listings
   and inspections never contain it.
2. **Given** a key owned by a domain administrator, **When** a client requests its identity with
   that key, **Then** it receives the domain administrator's role, domains and permission flags
   together with the key's access level.
3. **Given** a valid read-only key, **When** a client sends a read request with it, **Then** the
   request succeeds with the owner's permissions. **When** the client sends a state-changing request
   with it, **Then** the request is refused with an "insufficient key scope" error.
4. **Given** a key restricted to one network, **When** it is used from an address outside that
   network, **Then** the request is refused with a "source address not allowed" error and the
   attempt is reported to fail2ban.
5. **Given** a key that an administrator has revoked, **When** a client uses it, **Then** the
   request is refused with a "key revoked" error. The key is still listed with its revocation time
   and the administrator who revoked it.
6. **Given** a key whose expiry has passed, **When** a client uses it, **Then** the request is
   refused with a "key expired" error.
7. **Given** a value in the `X-API-Key` header that matches no key, **When** a client uses it,
   **Then** the request is refused with an "invalid key" error and the attempt is reported to
   fail2ban.
8. **Given** a read-write key owned by an administrator, **When** a client uses it to create or
   revoke a key, **Then** the request is refused as forbidden, with the error that the operation
   needs a signed-in session. Keys are created and revoked only in a signed-in administrator
   session.
9. **Given** a domain administrator or a mailbox user, **When** they try to list, create or revoke
   keys, **Then** the request is refused as forbidden.
10. **Given** an administrator filling in the key form, **When** they search the possible owners for
    "res", **Then** they see the active administrators and domain administrators whose username
    contains "res", each with their role. Deactivated accounts and mailbox users are not listed.

---

### User Story 5 - Read the public branding configuration before sign-in (Priority: P2)

Before anyone signs in, the web app loads the server's branding: the product name, the light and
dark logos, the login help text, the footer, the current announcement, the login page options and
the enabled languages. The operator already configures all of this in the legacy mailcow
customisation screen, so the new web app shows the same branding without any second configuration.

**Why this priority**: The login page needs it, but sign-in itself works without it, using
built-in defaults.

**Independent Test**: Without signing in, read the public configuration and compare it with the
customisation saved in the legacy admin UI. Change a value in the legacy UI and confirm that the
new value is returned within one minute.

**Acceptance Scenarios**:

1. **Given** an operator who has uploaded a logo and set a product name in the legacy customisation
   screen, **When** a signed-out client reads the public configuration, **Then** it receives that
   logo and that name.
2. **Given** a server with no customisation saved, **When** a client reads the public
   configuration, **Then** it receives the same defaults the legacy UI shows.
3. **Given** an operator who changes the announcement text in the legacy UI, **When** a client
   reads the public configuration up to one minute later, **Then** it receives the new text.
4. **Given** a server with SOGo disabled and an operator link "Helpdesk" that is hidden on the login
   page, **When** a client reads the public configuration, **Then** the app links do not contain
   Webmail, and they contain "Helpdesk", marked as not shown on the login page and with its
   address for signed-in users.

---

### User Story 6 - Review the authentication audit log (Priority: P2)

An administrator investigating a suspicious login lists the audit log, filtered by time range,
event type, outcome, user and source address. Each entry shows who did what, when, from where, how
they authenticated and, for failures, why it failed. No entry ever exposes a password, a one-time
code or a key secret.

**Why this priority**: It is required for security operations and by the constitution, but users
can sign in and work without it.

**Independent Test**: Perform successful and failed password and passkey sign-ins, second-factor
failures, sign-outs, preference changes, passkey registrations, renames and removals, and key
creations and revocations. Then list the audit log
with each filter and confirm exactly the expected entries appear and contain no secrets.

**Acceptance Scenarios**:

1. **Given** a failed sign-in for "alice@example.org" from 192.0.2.10, **When** an administrator
   lists failed sign-ins for that address, **Then** the entry appears with the attempted username,
   the time, the address and a failure reason.
2. **Given** an administrator who created and then revoked a key, **When** another administrator
   lists key events, **Then** both the creation and the revocation appear with actor, key name and
   time, and neither contains the secret.
3. **Given** a mailbox user, **When** they request the audit log, **Then** the request is refused as
   forbidden.

---

### Edge Cases

- **Username in more than one account type**: without a named role, the first account type in the
  legacy order (administrator, domain administrator, mailbox user) wins. For a password sign-in,
  that is the first whose password verifies. For a passkey, it is the first account type that
  holds the credential's username. With a named role, only that type is tried.
- **Inactive account or domain**: an inactive administrator, an inactive domain administrator, an
  inactive mailbox or a mailbox in an inactive domain cannot sign in with a password or a passkey.
  The failure is the same generic error as a wrong password.
- **Resource mailboxes** (location, thing, group) can never sign in.
- **App passwords** never establish a session, whichever role is named.
- **Mailbox that authenticates against an external identity provider** (LDAP, Keycloak or generic
  OIDC): its
  password cannot be checked in this feature. Sign-in fails with the generic error. The attempt is
  not reported to fail2ban, because no password was found to be wrong, and the audit entry records
  the reason. Support arrives with the identity-provider feature (012).
- **Deactivated passkey credential, or an assertion for an unknown credential**: sign-in fails with
  the generic error.
- **Device or browser without passkey support, or with no passkey for this server**: the client
  falls back to username and password.
- **Passkey challenge reused or left unanswered**: each challenge is single-use and expires 5
  minutes after it is issued.
- **Account deactivated, or domain-admin domains or permission flags changed, while a session is
  open or a key is in use**: the next request sees the change. A deactivated account's session
  ends, and changed scope or flags apply immediately.
- **Second factor deactivated or deleted between the password step and the second-factor step**:
  the pending sign-in fails, and the client must start again. The attempt does not count towards
  throttling, because no wrong factor was submitted.
- **Wrong second factor**: one failed second-factor attempt cancels the pending sign-in (legacy
  behaviour), and the client must start again with the password. The failure counts towards
  throttling.
- **Pending sign-in left unfinished**: it expires after 5 minutes. The client can also cancel it.
- **Same TOTP code submitted twice**: the second use is rejected, even within the code's validity
  window.
- **Yubico verification service unreachable**: the client gets a temporary-failure error. The
  pending sign-in stays open, so another method can be tried, and the failure does not count
  towards throttling.
- **Passkey or WebAuthn credential registered under a different host name** than the one serving
  the new web app: the assertion fails. The user can use another method or the legacy UI.
- **Account whose only active second factors are deprecated U2F entries**: sign-in with a password
  cannot be completed. The error tells the user to ask an administrator to reset their second
  factors.
- **Account forced to set up a second factor** (flagged in mailcow, with no second factor and no
  passkey): the session is restricted. The user lifts the restriction by registering a passkey in
  the new web app, or by setting up a second factor in the legacy UI.
- **Passkey registration options reused or left unanswered**: they are single-use and expire 5
  minutes after they are issued.
- **Authenticator that already holds a passkey for this account**: registration offers it as
  excluded, so the authenticator refuses to create a duplicate.
- **Passkey removed in the legacy UI**: it no longer signs in through the new API either, and it
  disappears from the user's list.
- **Request carrying both a session cookie and an API key**: the key decides, and the cookie is
  ignored.
- **API key whose owner is deactivated or deleted**: the key is rejected as invalid until the owner
  is active again. A deleted owner's keys stay unusable.
- **API key owned by a domain administrator who no longer manages any domain**: the key still
  authenticates, but its scope is empty.
- **Key created with an expiry in the past, with a malformed network entry, or with a mailbox user
  as owner**: creation is rejected with a validation error naming the field.
- **Session used from a browser with a different user agent** than the one that signed in: the
  session is treated as ended (legacy behaviour).
- **Request through the mailcow reverse proxy**: throttling, fail2ban reports, network checks and
  audit entries use the real client address, not the proxy's.
- **Shared store for fail2ban reporting unavailable**: the sign-in or key failure is still
  returned to the client, and the reporting failure is logged.
- **Audit record cannot be written**: the operation it would record does not succeed.

## Requirements *(mandatory)*

### Functional Requirements

#### Sign-in and sessions

- **FR-001**: The system MUST let administrators, domain administrators and mailbox users sign in
  with their existing mailcow username and password. Usernames MUST be trimmed and lower-cased
  before lookup, as in legacy.
- **FR-002**: A sign-in MAY name the intended role (administrator, domain administrator or mailbox
  user), mirroring legacy's separate login pages. Only that account type is then tried. When no
  role is named, the system MUST try account types in the legacy order: administrator, domain
  administrator, mailbox user.
- **FR-003**: Only active accounts MAY sign in. For mailbox users, the mailbox and its domain MUST
  both be active and the mailbox MUST NOT be a resource. App passwords MUST NOT establish a session.
  A mailbox whose authentication source is an external identity provider (LDAP, Keycloak or generic
  OIDC) MUST NOT sign in with a password in this feature. It gets the generic failure (FR-005), which is not
  reported to fail2ban. Passkey sign-in works for such mailboxes as for any other account, because
  mailcow holds the passkey.
- **FR-004**: Password verification MUST accept every stored hash format that legacy accepts
  (BLF-CRYPT, SSHA, SSHA256, SSHA512, ARGON2 variants, other CRYPT variants, MD5, PLAIN, LDAP-MD5,
  PBKDF2 and PBKDF2-SHA512). Signing in MUST NOT modify a stored hash.
- **FR-005**: A failed sign-in MUST return one generic error. The error MUST NOT reveal whether the
  username exists, which account type was tried, whether the account is inactive or whether the
  password was wrong. This applies to unknown accounts, wrong passwords, inactive accounts and
  malformed usernames.
- **FR-006**: Every failed password sign-in, apart from the external-identity-provider case in
  FR-003, MUST be published to mailcow's fail2ban channel with the message legacy uses (`mailcow UI: Invalid password for <username> by <client address>`), so that
  mailcow's existing ban rules match it without any configuration change.
- **FR-007**: Failed sign-ins MUST be delayed progressively as in legacy: the first failure without
  delay, then 0.5 seconds more for each further failure. The count MUST be kept per username and
  client address, not per session, so that discarding the cookie does not reset it. A successful
  password verification resets it. After 10 failures for the same username and address within 15
  minutes, the system MUST refuse further attempts for that pair with a "too many requests" error
  and a retry hint, without checking the password.
- **FR-008**: The session identifier MUST be renewed whenever authentication advances: when the
  password step succeeds, when the second-factor step succeeds, and when a passkey sign-in
  succeeds.
- **FR-009**: A session MUST end after the server's configured session lifetime of inactivity. The
  default is mailcow's 3 hours.
- **FR-010**: Signing out MUST end the session immediately. Signing out without a valid session MUST
  succeed and change nothing.
- **FR-011**: Browser sessions MUST be carried by a cookie that scripts cannot read, that is sent
  only over HTTPS and only to the same site. Every state-changing request made with a session MUST
  carry a valid anti-forgery token, whatever its content type. This includes sign-in and sign-out.
- **FR-012**: A session MUST end if a request arrives with a different user agent from the one that
  signed in (legacy behaviour).
- **FR-013**: Sessions of the new API MUST be independent of legacy UI sessions. Signing in or out
  of one MUST NOT sign in to or out of the other.
- **FR-014**: When an account is flagged in mailcow for a forced password change, or for forced
  second-factor setup while it has neither a second factor nor an active passkey (FR-054), the
  session MUST be restricted. This applies to every sign-in method. In a restricted session, the
  client can read its identity (which lists the pending actions) and sign out. When second-factor
  setup is pending, it can also register a passkey (FR-052). Operations that need no signed-in
  account stay available: the public configuration, the anti-forgery token, the session state,
  signing in again and its steps, the documentation and the health checks. Every other operation
  MUST be refused with a documented error that names the pending actions.

#### Passkey sign-in

- **FR-015**: The system MUST let any account with a passkey registered in mailcow sign in with
  that passkey alone, with no username and no password: the client requests a passkey challenge
  and returns an assertion. A valid assertion MUST establish a full session for the account that
  owns the credential, with no further second factor (legacy behaviour). The role is resolved as in
  FR-002. The sign-in MAY name an intended role, and an assertion whose account is not of that role
  MUST fail.
- **FR-016**: A passkey sign-in MUST succeed only if the credential is active and the account meets
  FR-003. Forced actions (FR-014) MUST apply after a passkey sign-in.
- **FR-017**: Passkey challenges MUST be single-use and MUST expire 5 minutes after they are issued.
  User verification (fingerprint, face or PIN) MUST be requested. Whether it is required MUST follow
  the operator's existing mailcow setting, whose legacy default is "preferred".
- **FR-018**: A failed passkey sign-in MUST return the generic error from FR-005, MUST be audited,
  and MUST count towards throttling per client address. It MUST NOT be reported to fail2ban, because
  no password is involved (legacy behaviour).
- **FR-019**: The contract MUST document the recommended presentation order of sign-in methods:
  passkey sign-in first when the client device supports it, then username and password. Within the
  second-factor step, the order is the one in FR-020. Clients MUST be able to offer legacy-registered
  credentials as passkeys without knowing legacy's names for them ("FIDO2", "Touch ID").

#### Passkey management

- **FR-052**: A signed-in user of any role MUST be able to register a new passkey for their own
  account. The client requests registration options and returns the authenticator's response. The
  options MUST be bound to the session, single-use, and expire after 5 minutes.
  - The credential MUST be registered as discoverable, so that it works for usernameless sign-in
    (FR-015).
  - It MUST be bound to the server's host name.
  - The options MUST exclude credentials already registered to the account.
  - User verification and the accepted authenticator attestation formats MUST follow the
    operator's existing mailcow settings. Legacy requests user verification as "preferred".
  - The requested attestation conveyance MUST be legacy's: `indirect`, or `direct` when only
    trusted vendors are accepted, or `none` when only the `none` format is accepted.
  - The passkey MUST be stored where legacy stores FIDO2 credentials, so that it works in both UIs.

  Registration needs a full session, or a session restricted only by pending second-factor setup.
  It MUST NOT be possible with an API key or during a pending sign-in.
- **FR-053**: A signed-in user MUST be able to list their own passkeys and to rename or remove each
  of them. The list shows the display name, the authenticator description (legacy shows "Unknown"
  plus the registration date when there is none), the registration date and a non-secret
  identifier. Users MUST NOT see or change other accounts' passkeys. Removing the last passkey is
  allowed, as in legacy, because the password remains.
- **FR-054**: Registering a passkey MUST lift a pending second-factor-setup restriction on the
  current session, as legacy does. When the sign-in decides whether forced second-factor setup is
  pending (FR-014), an active passkey MUST count as a second factor.

#### Second factors

- **FR-020**: When the account has at least one active second factor, a correct password MUST NOT
  establish a full session. It MUST open a pending sign-in and return the available active methods,
  each with its type and display name and never any secret material. Methods MUST be returned in
  this order: WebAuthn security keys and passkeys first, TOTP second, then Yubico OTP. Each method
  MUST be marked as primary (WebAuthn, TOTP) or advanced (Yubico OTP).
- **FR-021**: The client MUST be able to complete a pending sign-in with a WebAuthn assertion, a
  TOTP code or a Yubico OTP. For WebAuthn, the client MUST first be able to request a challenge
  bound to the pending sign-in.
- **FR-022**: Second factors enrolled through the legacy UI MUST verify without re-enrolment. Yubico
  OTPs MUST be verified against the Yubico verification service with the per-key credentials
  stored in mailcow, as legacy does.
- **FR-023**: A TOTP code that has been accepted once MUST NOT be accepted again for the same account
  within its validity window.
- **FR-024**: A failed second-factor attempt MUST cancel the pending sign-in and MUST count towards
  the throttling in FR-007. A pending sign-in MUST expire 5 minutes after the password step. The
  client MUST be able to cancel a pending sign-in.
- **FR-025**: Deprecated U2F entries and HOTP entries MUST NOT be accepted as a second factor.
- **FR-026**: When a password sign-in succeeds and the account has no active second factor, the
  system MUST re-activate all of the account's second-factor entries. This reproduces legacy's
  "disable second factor for the next login" behaviour.
- **FR-027**: The system MUST record a recent-login entry for a mailbox user (web UI service and
  client address) when the user's password is verified, as legacy does, and also when a passkey
  sign-in succeeds. The recent-logins views in both UIs then show it. The service label MUST match
  the deployed mailcow release: `MAILCOWUI` upstream (the default), `WEBUI` in the FeldHost fork.

#### Identity and preferences

- **FR-028**: An authenticated client, whether it uses a session or an API key, MUST be able to
  read its identity. The identity contains:
  - the username and role (for a key, those of the key's owner);
  - how the client authenticated: with a password, a password plus a second factor, a passkey, or
    an API key. For an API key, it also contains the key's identifier, name and access level;
  - the scope: all domains for an administrator, the managed domains with an active assignment for
    a domain administrator (as in legacy, a still-assigned inactive domain stays in scope), the
    mailbox and its domain for a mailbox user;
  - the effective permission flags: all for an administrator, the domain-administrator flags for a
    domain administrator (the schema's defaults when mailcow has no flag row for them, as for a
    newly created domain administrator), the mailbox-user flags for a mailbox user;
  - any pending actions;
  - for sessions, the user's preferences.
- **FR-029**: Scope, permission flags and account status MUST be evaluated from the current
  mailcow data on every request. Changes made in either UI MUST apply to the next request.
- **FR-030**: A signed-in user MUST be able to read and update their preferences: a language from
  the server's enabled languages, and a theme of light, dark or follow-system. Invalid values MUST
  be rejected with field-level validation errors. Preferences MUST persist across sessions and
  devices. The defaults are the server's default language and follow-system.
- **FR-031**: API key clients MUST NOT update preferences.

#### API keys

- **FR-032**: Administrators MUST be able to create an API key with:
  - a name (required);
  - an owner, which MUST be an existing administrator or domain administrator. Mailbox users
    cannot own keys;
  - an access level of read-only or read-write;
  - an optional expiry, which MUST be in the future;
  - an optional list of allowed source networks, each an IPv4 or IPv6 address or prefix. A key
    without such a list may be used from any address.
- **FR-033**: A request authenticated by a key MUST act with exactly the key owner's role, scope
  and permission flags, evaluated on every request (FR-029). A read-only key MUST additionally be
  limited to requests that do not change state. A key owned by a domain administrator MUST NOT
  reach any object outside that domain administrator's scope, and MUST NOT use any
  administrator-only operation.
- **FR-034**: The key secret MUST be returned only in the creation response. The system MUST keep
  only an irreversible form of the secret. Listings and inspections MUST show a short non-secret
  identifier, so that administrators can recognise a key.
- **FR-035**: Administrators MUST be able to list keys with pagination, sorting and filters (owner,
  status active/expired/revoked, access level). They MUST be able to inspect a single key: name,
  owner, access level, allowed networks, expiry, created at and by, last used at and from (accurate
  to the minute), status, and revoked at and by.
- **FR-036**: Administrators MUST be able to revoke a key. Revocation MUST take effect on the very
  next request and cannot be undone. Revoked keys MUST stay listed with their revocation details.
  Revoking an already revoked key MUST succeed and change nothing.
- **FR-037**: Keys MUST be created and revoked only in a signed-in administrator session, never in
  a request authenticated by a key, so that a leaked key cannot mint replacements for itself.
  Requests authenticated by a key owned by an administrator MAY list and inspect keys.
- **FR-038**: An automation client MUST be able to authenticate by sending the secret in the
  `X-API-Key` header. A request authenticated by key MUST NOT create a session and MUST NOT need an
  anti-forgery token.
- **FR-039**: Key authentication MUST distinguish these outcomes, each with its own documented
  error type:
  - no credentials, on an operation that requires authentication;
  - invalid key, which also covers a key whose owner is inactive or deleted;
  - expired key;
  - revoked key;
  - source address not allowed;
  - insufficient key scope, when a read-only key is used for a state-changing request.
- **FR-040**: An invalid, expired or revoked key, and use of a key from a disallowed address, MUST
  be published to the fail2ban channel with the message legacy uses for API keys
  (`mailcow UI: Invalid password for API_USER by <client address>`). Insufficient key scope MUST NOT
  be reported.
- **FR-041**: Legacy `/api/v1` keys MUST NOT be accepted by this API, and this feature MUST NOT
  change them.
- **FR-057**: Administrators MUST be able to list the accounts that can own an API key: the active
  administrators and the active domain administrators, each with its username and role. The list
  is sorted by username, paged, and offers a substring search on the username and a role filter.
  Requests authenticated by a key owned by an administrator, including a read-only key, MAY use it.
  Domain administrators and mailbox users MUST be refused.

#### Public branding configuration

- **FR-042**: Unauthenticated clients MUST be able to read a public configuration containing:
  - the page title and product name;
  - the light and dark logos, when set;
  - the login help text and the footer text;
  - the announcement: its text, its type and whether it is active;
  - the login page options: hiding the administrator, domain-administrator and mailbox-user login
    links, and "force single sign-on";
  - the enabled languages and the default language;
  - whether an external identity provider is configured;
  - the app links and the label of the apps menu (FR-056).
- **FR-043**: These values MUST come from mailcow's existing customisation and language settings,
  so that the operator configures them in one place. A change saved in the legacy UI MUST be
  visible within one minute without a restart. Unset values MUST fall back to the defaults the
  deployed legacy UI shows. For the product name and page title, that is
  `<MAILCOW_HOSTNAME> - mail UI` upstream (the default) and `FeldHost™ Mailhosting UI` in the
  FeldHost fork.
- **FR-044**: The public configuration MUST NOT contain secrets or anything account-specific. The
  contract MUST state which text fields may contain operator-authored HTML (the help text and the
  footer, as in legacy), so that clients can render them safely.
- **FR-056**: The public configuration MUST include the app links that legacy shows, in legacy
  order: first the server's built-in apps (`$MAILCOW_APPS`, by default the Webmail entry), then the
  operator's links (the legacy customisation "App links"). Each link has:
  - its name;
  - the address used on the login page;
  - the address used for signed-in users, which may contain legacy's `%u` placeholder for the
    username;
  - an optional description;
  - whether it is shown on the login page. As in legacy, "hidden" applies only to the login page.

  Built-in apps that point to SOGo MUST be left out when SOGo is disabled (`SKIP_SOGO=y`). The
  label of the apps menu MUST be included when the operator has set one, and is null otherwise, so
  that the client shows its own translated default. Editing app links stays in feature 013.

#### Audit log

- **FR-045**: The system MUST write an audit record for these events:
  - every successful sign-in, whether by password, password plus second factor, or passkey;
  - every failed sign-in, including second-factor failures, passkey failures and throttled
    attempts;
  - every sign-out;
  - every preference change;
  - every passkey registration, rename and removal;
  - every API key creation and revocation.
- **FR-046**: Each audit record MUST contain:
  - the time, event type and outcome;
  - the actor: username and role, or key identifier, or for a failed sign-in the attempted
    username where one is known;
  - the target, where there is one (for example the key);
  - the client address and user agent;
  - the authentication method;
  - a failure reason (for example unknown account or wrong password, inactive account, external
    identity source, unknown or inactive passkey, second factor failed, throttled);
  - the request identifier.

  It MUST NOT contain passwords, one-time codes, passkey or WebAuthn assertions, key secrets or
  session identifiers.
- **FR-047**: Administrators MUST be able to list audit records with pagination, newest first by
  default, filtered by time range, event type, outcome, actor and client address. Domain
  administrators and mailbox users MUST be refused.
- **FR-048**: No operation MAY modify or delete audit records. Records MUST be kept for a
  configurable retention period, 365 days by default, and then removed.

#### Cross-cutting

- **FR-049**: Every error MUST be a problem-details response. Each error type that clients act on
  MUST be documented. At minimum these are: sign-in failed, second factor required, second factor
  failed, pending sign-in or passkey challenge expired, too many requests, anti-forgery check
  failed, unauthenticated, forbidden, pending action required, each key outcome in FR-039,
  temporary verification failure, passkey registration failed, and validation failed with
  field-level details.
- **FR-055**: Error responses MUST NOT be localised.
  - Each error MUST carry its documented problem type as a stable machine-readable code, together
    with an English title and detail meant for logs and scripts.
  - A validation error MUST carry, for each invalid field, a stable rule code (for example
    `required`, `tooLong`, `notInList`) and the rule's parameters (for example a maximum length),
    so that clients can build a translated message.
  - The API MUST ignore the client's language headers when building errors. Translation is the
    client's job. This convention applies to every later feature.
- **FR-050**: Passwords, one-time codes, passkey and WebAuthn assertions, and key secrets MUST NOT
  appear in any log, audit record or response, apart from the one-time secret in FR-034.
- **FR-051**: Every operation in this feature MUST be described in the published API contract
  before it is implemented, and its behaviour MUST match the contract.
- **FR-058**: Every list of this feature (passkeys, API keys, possible key owners, audit records)
  MUST follow the cross-feature list conventions:
  - a page size of 25 by default and at most 1,000;
  - an offset;
  - a sort order;
  - a substring search in addition to the documented filters.

  Every entry of a resource that can change (passkeys, API keys) MUST carry the same version tag
  that reading the single resource returns. The tag covers only the stored settings, never usage
  data such as the last use of a key.

### Legacy Coverage

Every legacy capability allocated to feature 001 in `docs/roadmap.md` maps to requirements as
follows. Actions are `/api/v1` names; *form* marks legacy UI form handlers.

| Legacy action or form | Legacy implementation | Requirements |
|---|---|---|
| *form* login on `/admin`, `/domainadmin` and `/` (`login_user`, `pass_user`) | `triggers.{admin,domainadmin,user}.inc.php`, `check_login()`, `admin_login()`, `domainadmin_login()`, `user_login()`, `verify_hash()` | FR-001 to FR-008 |
| *form* second-factor step (`verify_tfa_login`) and cancel (`cancel_tfa_login`) | `verify_tfa_login()`, `get_tfa()`, `json_api.php` `webauthn-tfa-get-args` | FR-020 to FR-026 |
| FIDO2 login (`fido2-get-args`, *form* `verify_fido2_login`) | `fido2('verify')`, `fido2('get_by_b64cid')` | FR-015 to FR-019 |
| `fido2-registration` (options and response) | `getCreateArgs()`, `processCreate()`, `fido2('register')`, `fido2('get_user_cids')` | FR-052, FR-054 |
| `edit/fido2-fn`, *form* `unset_fido2_key`, the FIDO2 list | `fido2('edit_fn')`, `fido2('unset_fido2_key')`, `fido2('get_friendly_names')` | FR-053, FR-058 |
| *form* `logout` | `sessions.inc.php` | FR-010 |
| Session lifetime, user-agent pinning, CSRF token | `sessions.inc.php`, `$SESSION_LIFETIME` | FR-009, FR-011 to FR-013 |
| Forced actions (`pending_pw_update`, `pending_tfa_setup`, `cancel_tfa_setup`) | `*_login()`, `protect_route()`, `fido2-registration` hold release | FR-014, FR-054 |
| Recent-login entry of UI sign-ins | `set_sasl_log()` | FR-027 |
| *form* API key management (System › Configuration › API: read-write and read-only key, allowed networks, skip IP check) | `admin_api()` | FR-032 to FR-037, FR-041, FR-057 |
| `X-API-Key` authentication and its fail2ban report | `sessions.inc.php` API block | FR-038 to FR-040 |
| Identity of the session: username, role, `acl('to_session')`, managed domains | `acl()`, `mailbox('get', 'domains')` | FR-028, FR-029 |
| Language and theme choice | `mailcow_locale` cookie, browser storage | FR-030, FR-031 |
| Branding read: `customize('get', 'ui_texts' \| 'main_logo' \| 'main_logo_dark' \| 'custom_login' \| 'app_links')`, `$MAILCOW_APPS`, `$AVAILABLE_LANGUAGES`, `$DEFAULT_LANG` | `functions.customize.inc.php`, `inc/header.inc.php`, `vars.inc.php` | FR-042 to FR-044, FR-056 |
| UI log entries of logins | `logger()` → `logs` | FR-045 to FR-048 (own audit log) |

Parts of the legacy sign-in screens that belong to other features: identity-provider sign-in
(012), dual login and login-as (007), password reset (005) and the forced password change itself
(005).

### Legacy Parity and Deliberate Deviations

Parity reference: `source_code/data/web/inc/functions.auth.inc.php`, `sessions.inc.php`,
`triggers.{admin,domainadmin,user}.inc.php`, `functions.inc.php` (`get_tfa`, `verify_tfa_login`,
`set_sasl_log`, `fido2`), `json_api.php` (`fido2-get-args`), `functions.customize.inc.php`,
`vars.inc.php`, and the netfilter regex in `Dockerfiles/netfilter/main.py`.

The reference is upstream mailcow-dockerized `2026-09a`. `source_code/` points to the FeldHost fork
(`~/Projects/mailhosting`, branch `feldhost-202609a`), so its auth files were diffed against that
upstream tag. Sign-in, second-factor, passkey, session, API key and fail2ban logic are identical.
The only differences are the `sasl_log` service label for UI logins (FR-027) and the default
product name (FR-043).

| Behaviour | Legacy | This feature |
|---|---|---|
| Account resolution order | Admin, then domain admin, then mailbox user when no role is given. Each login page fixes the role | Same. Role is optional (FR-002, FR-015) |
| Hash formats accepted | `verify_hash` list | Same (FR-004) |
| fail2ban message | `mailcow UI: Invalid password for <user> by <ip>`, and `API_USER` for keys | Same text (FR-006, FR-040) |
| Progressive delay | Per session: 0 s, then +0.5 s per failure, but PHP's `sleep()` truncates the value to whole seconds (0, 0, 1, 1, 2, …) | Exact 0.5 s steps, counted per username and address, plus a hard limit (FR-007). **Deviation**: a session-scoped counter is reset by dropping the cookie |
| Yubico service unreachable | The verification fails and the pending login is cancelled | Temporary-failure error; the pending sign-in stays open and nothing is counted (edge case). **Deviation** |
| Passkey sign-in failures | Not throttled | Counted per client address towards the same limit (FR-018). **Deviation** |
| Ceremony timeout hint | WebAuthn options carry a 30 s `timeout`; the challenge lives until the session ends | Options carry 300 s, and challenges expire after 5 minutes (FR-017, FR-052). **Deviation** |
| Failed second factor | Cancels the pending login | Same, and also counts towards throttling (FR-024). **Deviation** |
| TOTP replay | A code may be reused within its window | Rejected (FR-023). **Deviation** (ASVS L2) |
| U2F | A "u2f" method deletes the U2F keys and grants login without verification | Never accepted (FR-025). **Deviation** (security fix) |
| Pending login lifetime | Until the session expires | 5 minutes (FR-024). **Deviation** |
| Second-factor method order | Listed in table order | WebAuthn, then TOTP, then Yubico OTP as advanced (FR-020). **Deviation** (owner decision) |
| Re-activating second factors after a login without one | All of the account's entries are set active again | Same (FR-026) |
| Recent-login record for mailbox users | `sasl_log` entry once the password is verified, with service `MAILCOWUI` upstream or `WEBUI` in the fork. None for FIDO2 login | Same for password sign-in, and also written for passkey sign-in (FR-027). **Deviation**: users see every web sign-in |
| FIDO2 / passkey sign-in | Usernameless, resident-key credentials, user verification "preferred", no further factor. Account active state, credential active state and forced actions are not checked | Same ceremony, presented as "passkey" (FR-015, FR-017, FR-019). Active states and forced actions are checked (FR-016). **Deviation** (security fix) |
| FIDO2 / passkey registration and management | Any role registers its own discoverable credentials, with existing ones excluded and user verification "preferred". Users rename and delete their own credentials, including the last one. Registering one releases the forced-setup hold for the current session only, because the login check counts only the second-factor table, so the user is forced again at the next login | Same ceremony, storage and own-only rename and removal (FR-052, FR-053). An active passkey also counts at sign-in (FR-054). **Deviation**: a user who resolved forced setup with a passkey is not forced again |
| User-agent pinning, idle lifetime | Both, with a 3 h default | Same (FR-009, FR-012) |
| CSRF | Checked only on form posts, so JSON writes skip it | Every state-changing session request (FR-011). **Deviation** (security fix) |
| UI log entries | Written to the `logs` table | Written to this API's own audit log, not to `logs` (constitution V). **Deviation**: sign-ins through the new API do not show up in the legacy UI's log viewer |
| Language and theme | Browser cookie and browser storage, never stored per account | Stored per account by this API (FR-030). Not shared with the legacy UI |
| API keys | One rw and one ro key, both acting as unscoped admin, IP list or skip flag | Many named keys, each acting as its admin or domain-admin owner, with scope, expiry and networks (FR-032 to FR-040). `/api/v1` keys unchanged |
| External identity sources | `ldap` and `keycloak` mailboxes are checked against the provider. A `generic-oidc` mailbox fails like a wrong password and is reported to fail2ban | All three are refused with the generic error and not reported (FR-003). **Deviation** (added by the plan) |
| Forced actions | Evaluated once at login and kept in the session | Re-evaluated on every request, so a restriction applies or lifts with the next request (FR-014, FR-029). **Deviation** (added by the plan) |
| fail2ban message content | The username is inserted as typed. A newline in it could inject a second line that bans another address | Control characters are replaced by `?`. The text is unchanged for every valid username (FR-006). **Deviation** (added by the plan, security fix) |
| Failures after the throttling limit | No hard limit, and every failure is published to fail2ban | Throttled password attempts are refused without checking the password, and are still published, so fail2ban counts every attempt (FR-006, FR-007, SC-004) |
| Passkey sign-in with a named role | A mailbox-user FIDO2 login fails when the username also exists as an administrator or domain administrator | Only the named account type is tried (FR-002, FR-015). **Deviation** |
| App links | The login page shows the non-hidden `$MAILCOW_APPS` entries, leaving out SOGo entries when `SKIP_SOGO=y`, then the non-hidden operator links. The header shows every entry with its signed-in address and `%u` replaced by the username. Old-style operator entries (name and address only) are converted. For `$MAILCOW_APPS`, legacy's header uses the login address instead of the signed-in address because of a bug | Same entries, order, hiding and `SKIP_SOGO` rule, delivered as data (FR-056). The client replaces `%u`. **Deviation**: the signed-in address of built-in apps is used (bug fix) |
| Owner of a key | Legacy keys have no owner, and the key form lists nothing | List of possible owners (FR-057). New |
| Domain administrator without a `da_acl` row | `acl('to_session')` keeps every `user_acl` flag on and leaves the `da_acl`-only flags unset (with PHP warnings) | The flags of the row legacy inserts for a new domain administrator: every column default of the schema (FR-028). **Deviation** (orchestrator decision) |
| Passkey registration details | Relying-party name `WebAuthn Library`. No name at registration. A credential ID already stored would be stored again if an authenticator ignored the exclusion list | The relying-party name is the product name. A name may be given at registration. An already stored credential ID is refused (FR-052). **Deviation** (added by the plan) |

### Key Entities *(include if feature involves data)*

- **Account** (existing mailcow data): an administrator, domain administrator or mailbox user.
  Attributes: username, role, active state, password hash, forced-password-change and
  forced-second-factor flags. A domain administrator has managed domains and
  domain-administrator permission flags. A mailbox user has a domain, an authentication source and
  mailbox-user permission flags.
- **Passkey** (existing mailcow data, which legacy calls "FIDO2"): a discoverable credential
  belonging to an account. It has a display name, an authenticator description, a registration
  date, an active state and the host name it was registered for. It can be registered in either
  UI and works in both.
- **Second factor** (existing mailcow data): a WebAuthn, TOTP or Yubico OTP entry belonging to an
  account, with a display name and an active state. Deprecated U2F and HOTP entries may exist but
  are never accepted.
- **Session**: a signed-in browser context with its account, role, sign-in method, start and
  last-activity times, user agent, and state: pending second factor, restricted (pending actions)
  or full.
- **Pending sign-in**: an account whose password has been verified and which still needs a second
  factor. It holds the available methods in presentation order, an optional WebAuthn challenge and
  an expiry.
- **API key** (owned by this API): name, short non-secret identifier, irreversible form of the
  secret, owner (an administrator or domain administrator), access level, allowed networks,
  expiry, creator and creation time, last use time and address, and revocation time and actor.
- **Preferences** (owned by this API): language and theme for one account.
- **Public configuration** (read-only view of existing mailcow settings): branding texts, logos,
  announcement, login page options, languages, app links and the apps menu label.
- **Audit record** (owned by this API): one immutable authentication or key-management event, with
  the fields in FR-046.
- **Recent-login entry** (existing mailcow data): service, client address and time of a mailbox
  user's sign-in, shared with the legacy UI.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of the accounts in the parity test set sign in through the new API with their
  existing credentials, passkeys and second factors, without re-enrolment and without stored data
  changing beyond the specified side effects. The set covers all three roles, every supported hash
  format, passkeys and every accepted second-factor type.
- **SC-002**: A user with a passkey on their device completes sign-in in a single step, without
  typing a username or a password, in under 10 seconds. A user without a passkey can register one
  in the new web app and use it for their next sign-in within 1 minute, without visiting the legacy
  UI.
- **SC-003**: After the new API has been running alongside them, 100% of the legacy UI and
  `/api/v1` sign-in, second-factor, FIDO2 and key checks still pass.
- **SC-004**: An unmodified mailcow fail2ban configuration bans an address after the same number of
  failed password sign-ins against the new API as against the legacy UI.
- **SC-005**: Revoked, expired and disallowed-network keys are rejected on 100% of uses, starting
  with the first request after revocation or expiry. Keys owned by domain administrators reach
  nothing outside their owner's scope in 100% of authorisation tests.
- **SC-006**: An administrator can create a key, and an automation client can complete its first
  authenticated read with it, within 2 minutes.
- **SC-007**: In 95% of attempts, users see the result of each sign-in step within 1 second, apart
  from deliberate throttling delays.
- **SC-008**: A branding change saved in the legacy customisation screen reaches signed-out clients
  within 1 minute, with no restart.
- **SC-009**: Every audited event in FR-045 produces exactly one audit record. A scan of all audit
  records and service logs after the full test run finds no passwords, one-time codes, assertions
  or key secrets.
- **SC-010**: 100% of attempts by domain administrators and mailbox users to read the audit log or
  manage keys, and of attempts to create or revoke keys with a key, are refused.

## Assumptions

- The new web app is served from the same host name as the legacy UI. Passkeys and WebAuthn
  credentials registered in the legacy UI stay valid because they are bound to that host name.
- Legacy registers FIDO2 credentials as discoverable (resident) credentials, so usernameless
  passkey sign-in works with existing registrations.
- Passkeys can be registered and removed in the new web app from this feature on. Enrolling TOTP,
  WebAuthn security keys and Yubico OTP comes with feature 005. Until then, users enrol those in
  the legacy UI.
- The presentation order of sign-in methods is a recommendation documented in the contract. The
  mailcowui-ng web app implements the screens.
- The API receives the real client address from mailcow's reverse proxy. It trusts only that proxy
  for the address.
- The session lifetime, the passkey user-verification setting, the enabled languages and the
  default language come from the operator's existing mailcow configuration, including local
  overrides. The plan decides how the API reads them. Each of them is also an API server setting
  with the upstream default, as `docs/conventions.md` prescribes for legacy PHP configuration.
- Legacy customisation has no colour setting, so the public configuration includes no colours for
  now. Colours can be added later without breaking clients, for example in feature 013.
- Forced second-factor setup can be resolved in the new web app by registering a passkey. A forced
  password change can be resolved in the legacy UI until feature 005 brings password change to
  this API.
- Keys cannot be edited. To change a key's access level, networks or expiry, an administrator
  creates a new key and revokes the old one.
- Automated provisioning of keys, such as a billing system handing a new reseller their own key, is
  not possible in this feature (FR-037). If it is needed, it would come as an opt-in key permission
  in a later feature.
- The 10-failures-in-15-minutes limit, the 5-minute pending sign-in and passkey challenge lifetime,
  and the 365-day audit retention are defaults that the operator can configure.
- **Out of scope**:
  - managing mail objects;
  - enrolling or removing second factors (TOTP, WebAuthn security keys, Yubico OTP);
  - managing other accounts' passkeys;
  - password change and password reset;
  - identity-provider sign-in (OIDC, LDAP, Keycloak) and mailbox provisioning on first login;
  - login-as and dual login (feature 007);
  - webmail single sign-on from the new web app;
  - listing or terminating other sessions;
  - domain administrators managing their own keys;
  - taking over legacy internal endpoints;
  - legacy `/api/v1` keys.
- **Dependencies**:
  - the upstream mailcow database schema of the supported release;
  - mailcow's fail2ban channel and its customisation store;
  - the Yubico verification service, for Yubico OTP;
  - the mailcow reverse proxy, for routing and the client address.
