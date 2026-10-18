# Feature Specification: Identity Providers and OAuth2 Clients

**Feature Branch**: `main` (no feature branches, constitution 1.1.0)

**Created**: 2026-10-18

**Status**: Draft

**Input**: Feature description built from `docs/roadmap.md` (feature 012 and allocation row 012):

> Identity providers and OAuth2 clients for mailcow REST API v2. Administrators read, save, test
> and remove the server's external identity provider: Keycloak, a generic OpenID Connect (OIDC)
> provider, or an LDAP directory. The configuration holds the connection endpoints, the client
> credentials or directory bind credentials, the mapping from a provider attribute to a mailbox
> template, the default template, automatic mailbox creation on first sign-in, the Keycloak
> "mailpassword" flow, the periodic full synchronisation and user import of Keycloak and LDAP, and
> the option to ignore TLS certificate errors. Mailbox users sign in to the new web app with single
> sign-on through Keycloak or the generic OIDC provider, and mailboxes whose password lives in LDAP
> or Keycloak sign in with their username and password. A mailbox is created on first sign-in from
> the mapped template, as in legacy. Administrators also manage the OAuth2 clients of mailcow's own
> OAuth2 provider: add a client with its redirect URIs, change them, regenerate the secret, revoke
> the client's tokens and delete the client.
>
> Allocated legacy surface: `identity-provider` get/edit/delete and `identity-provider-test`,
> Keycloak, generic OIDC and LDAP settings, attribute mapping, provisioning, IdP sign-in for the new
> web app, and `oauth2-client` add/get/edit/delete. The legacy "System › Configuration › Identity
> Provider" and "OAuth2 Apps" screens, the OAuth2 client edit page and the "Single Sign-On" button
> of the mailbox-user login page need what they show today.
>
> Constraints: client secrets and LDAP bind passwords are stored secrets and are never shown after
> they are entered. The synchronisation jobs, which mailcow's scheduler runs every minute inside the
> legacy PHP container, stay there and pick up configuration changes made through this API. The
> OAuth2 authorize, token and profile endpoints stay in the legacy PHP container. Every legacy side
> effect is reproduced, and the legacy UI, `/api/v1` and mail clients keep working on the same data.
> The feature builds on feature 001's sign-in, sessions, roles, API keys, throttling, fail2ban
> reporting, error conventions and audit log, on feature 002's conventions, and on feature 003's
> mailboxes and mailbox templates.

## Clarifications

### Session 2026-10-18

- Q: Which secrets does the API ever show, and when? → A: The identity provider's client secret and
  the LDAP bind password are write-only: no response ever contains them, not even the one that saves
  them, and the configuration only says whether each is set. The secret of an OAuth2 client, which
  the server generates, is shown in the creation response and in the response that regenerates it,
  and never again. The cached Keycloak service token is never shown. (agent decision: constitution
  VI forbids secrets in responses after creation. Legacy returns all of them on every read and
  renders them into the admin page. An administrator who types a secret has no need to see it
  echoed, while a generated OAuth2 secret must be shown once to be usable.)
- Q: When an administrator saves the configuration without re-sending a secret, or tests unsaved
  settings, may the stored secret be used? → A: Yes, as long as the provider type is unchanged and
  the address that receives the secret is unchanged: the Keycloak server URL, the generic OIDC token
  endpoint or the LDAP hosts. When that address changes, the secret must be sent again, for saving
  and for testing (FR-014, FR-019). (agent decision: write-only secrets require "keep when omitted",
  or every edit would need the secret. Without the address rule, anyone holding an administrator
  session or key could point the endpoint at their own server and receive the real secret, which
  for Keycloak grants the service account's view-users permission.)
- Q: Where does the provider send the browser back after single sign-on in the new web app? → A: To
  the new web app's sign-in return address on the host name the user is on, when that is one of the
  server's host names, and otherwise on the primary host name. The configuration lists these
  addresses read-only, and the operator registers them at the provider. The legacy return URL and
  the additional return URLs stay as they are and stay required, because the legacy UI uses them
  (FR-006, FR-021). (agent decision: the legacy return URL points at the legacy UI, which ignores a
  sign-in it did not start. Deriving the address from the server's host names, as legacy selects
  among its return URLs by host name, needs no new stored setting and no change to upstream
  tables.)
- Q: Does a single sign-on ask for a mailcow second factor, or apply forced second-factor setup? →
  A: No. The provider is responsible for the strength of the sign-in, as in legacy. Password sign-in
  of LDAP and Keycloak mailboxes keeps the full feature 001 second-factor and forced-setup handling
  (FR-024, FR-031). (agent decision: legacy `verify-sso` signs the user in directly and never checks
  second factors or the forced-setup flag. Adding them would block users who never enrolled a
  mailcow second factor because their provider handles it.)
- Q: How long does a single sign-on session stay valid when the user is disabled or signed out at
  the provider? → A: When the provider issued a renewal token, the API renews it with the provider
  on the first request more than 5 minutes after the last renewal. A refused or failed renewal, or a
  renewed identity with another e-mail address, ends the session with a documented "identity
  provider session ended" error (FR-026). (agent decision: legacy renews on every page load of the
  user area and ends the session when renewal fails. A single-page app has no page loads, so a
  short interval keeps the same effect without a provider call per request. Legacy switches the
  session to whatever e-mail the renewal returns, which is a defect.)
- Q: What happens to a password sign-in when the provider is unreachable or misconfigured, or when
  the password is right but no mailbox can be created? → A: The client gets a temporary-failure
  error ("identity provider unavailable") in the first case and the generic sign-in failure in the
  second. Neither is reported to fail2ban or counted towards throttling, and both are audited with
  their reason (FR-033). (agent decision: legacy treats both as a wrong password, so a provider
  outage or a missing template mapping gets legitimate users' addresses banned by fail2ban.)
- Q: When the provider type changes, what happens to the settings of the previous type? → A: They
  are removed, so the stored configuration holds only the settings of the type just saved, and the
  new type's secret must be sent (FR-015). (agent decision: legacy leaves them stored, and its read
  operation returns them, including the old type's secrets. Keeping secrets of a provider that is no
  longer used serves no purpose and widens exposure.)
- Q: When an OAuth2 client is deleted, or its tokens revoked, do its issued tokens keep working? →
  A: No. Deleting a client removes it together with its access tokens, refresh tokens and
  authorization codes. Revoking removes all three as well (FR-047, FR-048). (agent decision: legacy
  deletion leaves the tokens, and the profile endpoint keeps accepting a deleted client's access
  tokens for up to 24 hours. Legacy revocation keeps authorization codes, which can still be
  exchanged for a short time.)
- Q: What does "test connection" check, who may run it, and how is the result reported? → A: It
  checks unsaved candidate settings as legacy does for Keycloak (service token request) and LDAP
  (connect and bind). For a generic OIDC provider it checks that the token endpoint answers and does
  not reject the client credentials. The result is a success or a failure category (unreachable,
  timed out, certificate rejected, credentials rejected, unexpected response). It needs an
  administrator session or a read-write administrator key, and it is audited (FR-019, FR-052).
  (agent decision: legacy's generic OIDC test expects a success answer to an `OPTIONS` request,
  which many providers never give, and legacy reports only "failed". The test sends stored
  credentials to a host, so it is treated as an action rather than a read.)
- Q: Should the "force single sign-on" login option block password and passkey sign-in of mailbox
  users in the API? → A: No. As in legacy, it is a presentation option: the public configuration
  reports it, and clients then offer only single sign-on on the mailbox-user sign-in screen. The
  API does not refuse other sign-in methods because of it (FR-020). (agent decision: legacy only
  hides the login form and the passkey button and accepts the other methods. Feature 001 already
  publishes the flag as a login page option. Enforcing it is a product decision that is left to the
  owner, see Assumptions.)

### Session 2026-10-18 (orchestrator input from UI feature 012)

- Q: Which mailbox counts does the configuration report? → A: The number of mailboxes for each
  external authentication source (Keycloak, generic OIDC and LDAP), not only for the configured
  type, counted exactly as the "identity provider in use" checks count them. The conflict reports
  the same counts (FR-006, FR-015, FR-017). (orchestrator decision: the UI 012 spec found that a
  client cannot foresee a refusal caused by mailboxes of another external type.)
- Q: Does the configuration report the state of the synchronisation jobs? → A: Yes, for Keycloak and
  LDAP, as far as it can be derived without changing legacy: the end of the last completed run, a
  run-level failure since then, whether a run is in progress or overdue, and when the next run is
  expected. It is derived from the scheduled-job log that the legacy jobs write, is read-only, and
  is not part of the version tag (FR-062). (orchestrator decision: the UI 012 spec found that
  administrators cannot see whether the synchronisation works. The jobs stay in legacy, FR-018.)
- Q: What happens when the provider sends the browser back with an error instead of a code, for
  example because the user cancelled or refused? → A: The client passes the provider's error code
  and the state to the API. The API validates the state as for a completion, cancels the pending
  single sign-on, and writes an audit record with the provider's error code. The provider's
  free-text description is never accepted or stored (FR-063). (orchestrator decision: the UI 012
  spec found that such cancellations could not be audited.)
- Q: Does "force single sign-on" hide passkey sign-in? → A: It stays a display-only option, as in
  clarification 10: clients offer single sign-on instead of the password form on the mailbox-user
  sign-in screen, passkey sign-in stays available, and the API refuses no sign-in method because of
  it (FR-020). (orchestrator decision: UI features 001 and 012 keep passkey sign-in available.)
- Q: Does the API offer the legacy mass actions of the OAuth2 apps table? → A: Yes, for deletion and
  token revocation: up to 100 clients per request, one result per client, optional version tags per
  client and one audit record per client, as `docs/conventions.md` requires wherever legacy offers
  mass actions. Secret regeneration has no bulk form, because each new secret must be shown once and
  copied individually (FR-060, FR-061). This replaces the earlier exclusion of batch operations.
  (orchestrator decision: legacy's "OAuth2 Apps" table offers "Remove", "Revoke all client tokens"
  and "Generate new client secret" for the selected clients.)

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Configure and test the identity provider (Priority: P1)

An administrator opens the identity provider settings in the new web app. They choose Keycloak, a
generic OIDC provider or an LDAP directory, and fill in what the legacy "Identity Provider" screen
asks for:

- the connection: the Keycloak server URL, realm and version; or the generic OIDC authorization,
  token and user info endpoints and scopes; or the LDAP hosts, port, SSL or StartTLS, base DN,
  username field, filter and attribute field;
- the credentials: the client ID and client secret, or the LDAP bind DN and bind password;
- the legacy return URL and additional return URLs used by the legacy UI;
- the attribute mapping, which maps a value of a provider attribute to a mailbox template, and the
  default template for users whose value matches nothing;
- whether mailboxes are created automatically on first sign-in;
- for Keycloak, the mailpassword flow, which lets mailboxes sign in with a password kept at
  Keycloak;
- for Keycloak and LDAP, the user import and the periodic full synchronisation with its interval;
- whether TLS certificate errors are ignored.

Before saving, they test the connection with the values in the form and see whether it worked and,
if not, why. After saving, the legacy UI, mail clients that sign in with an LDAP or Keycloak
password, and the next synchronisation run all use the new settings. The secret and the bind
password are never shown again. The screen says that they are set, and the administrator replaces
them by typing a new value. The screen also lists the return addresses of the new web app that the
administrator must register at the provider, and how many mailboxes authenticate against it. Once
no mailbox uses the provider, the administrator can remove the configuration.

**Why this priority**: Every other part of this feature depends on a configuration, and replacing
the legacy screen removes the last reason for administrators to configure sign-in in the legacy UI.

**Independent Test**: Against test instances of Keycloak, a generic OIDC provider and an LDAP
directory, save, change, test and delete configurations of each type through the API. Compare the
stored settings with what the legacy UI saves for the same input, and confirm that the legacy UI
shows them, that an IMAP sign-in of an LDAP mailbox works with the saved settings, and that the next
synchronisation run uses them. Confirm that no response, log line or audit record contains a secret.

**Acceptance Scenarios**:

1. **Given** no configuration, **When** an administrator saves an LDAP configuration with a bind
   password, **Then** it is stored as legacy stores it, the response says it was created and that a
   bind password is set, and no response ever contains the bind password.
2. **Given** a stored Keycloak configuration, **When** an administrator changes only the sync
   interval, **Then** the change is saved, the client secret is kept, and the cached Keycloak
   service token is cleared.
3. **Given** a stored Keycloak configuration, **When** an administrator changes the server URL
   without sending a client secret, **Then** the request is refused with a validation error on the
   client secret, and nothing changes.
4. **Given** 12 mailboxes that authenticate against LDAP, **When** an administrator saves a Keycloak
   configuration or deletes the configuration, **Then** the request is refused as a conflict that
   reports the 12 mailboxes, and nothing changes.
5. **Given** a mapping entry that names the template "Staff", which does not exist, **When** the
   configuration is saved, **Then** the request is refused with a validation error that names the
   entry.
6. **Given** unsaved LDAP settings with a wrong bind password, **When** an administrator tests them,
   **Then** the result is "credentials rejected", nothing is stored, and the test is audited.
7. **Given** a stored generic OIDC configuration and no mailbox with an external authentication
   source, **When** an administrator deletes it, **Then** the configuration is gone, the public
   configuration no longer offers single sign-on, and the next synchronisation run does nothing.
8. **Given** a domain administrator or a mailbox user, **When** they read, save, test or delete the
   configuration, **Then** the request is refused as forbidden. **Given** a read-only key owned by
   an administrator, **When** a client reads the configuration with it, **Then** it succeeds without
   any secret. **When** it saves or tests, **Then** it is refused with "insufficient key scope".
9. **Given** two administrators who read the configuration, **When** the first saves a change and
   the second then saves with the version they read, **Then** the second save is refused as
   outdated.
10. **Given** an LDAP configuration, 12 LDAP mailboxes and 3 mailboxes left with the Keycloak source,
    **When** an administrator reads the configuration, **Then** it reports 12 LDAP, 3 Keycloak and
    0 generic OIDC mailboxes. **Given** periodic synchronisation on and a completed run 4 minutes
    ago with a 15-minute interval, **Then** it reports that run's end and the next run expected
    about 11 minutes from now.

---

### User Story 2 - Sign in with single sign-on (Priority: P1)

A mailbox user opens the new web app, which shows a "Single Sign-On" button because the server has a
Keycloak or generic OIDC provider configured. They are sent to the provider, sign in there, possibly
with the provider's own second factor, and come back signed in to the new web app. If they have no
mailbox yet and automatic creation is on, their mailbox is created from the template that their
provider attribute maps to, or from the default template. If their attribute maps to a template
that changed since their last sign-in, their mailbox is updated from it. Their session lasts as long
as they stay active and the provider keeps their provider session valid.

**Why this priority**: Organisations that use Keycloak or another OIDC provider cannot use the new
web app at all without it, and feature 001 deliberately left it to this feature.

**Independent Test**: Against test Keycloak and generic OIDC providers, sign in through the API with
existing mailboxes of each authentication source, with users who have no mailbox under each
provisioning setting, and with attributes that map to a template, to nothing, and to a changed
template. Compare the resulting sessions and stored mailboxes with legacy SSO sign-ins of the same
users. Disable a user at the provider and confirm their session ends.

**Acceptance Scenarios**:

1. **Given** Keycloak configured and a mailbox `anna@example.org` that authenticates against
   Keycloak and has a TOTP second factor, **When** the client starts single sign-on, the user signs
   in at Keycloak, and the client completes it with the returned code and state, **Then** a
   mailbox-user session is established for `anna@example.org` without a mailcow second factor, a
   recent-login entry is recorded, and the sign-in is audited.
2. **Given** automatic creation on, no mailbox for `ben@example.org`, and a provider attribute value
   "staff" mapped to the mailbox template "Staff", **When** Ben completes single sign-on, **Then**
   his mailbox is created with the values of "Staff", authenticating against the provider, and he is
   signed in.
3. **Given** a user whose attribute value matches no mapping, **When** they complete single sign-on
   without a mailbox, **Then** their mailbox is created from the default template when one is set.
   **When** no default template is set, **Then** the sign-in fails with the generic error and the
   audit record says no mapping matched.
4. **Given** automatic creation off and no mailbox for the user, **When** they complete single
   sign-on, **Then** the sign-in fails with the generic error.
5. **Given** a mailbox that authenticates with a mailcow password, **When** its owner completes
   single sign-on, **Then** the sign-in fails with the generic error, as in legacy.
6. **Given** a state that was already used, has expired, or was issued to another browser, **When**
   the client submits it with a code, **Then** the request is refused as "sign-in state invalid" and
   the provider is not contacted.
7. **Given** an existing mailbox whose attribute maps to the template "Staff", which an
   administrator changed since the user's last sign-in, **When** the user completes single sign-on,
   **Then** the mailbox takes the template's new values before the session is established.
8. **Given** a single sign-on session, **When** the user is disabled at the provider and makes a
   request more than 5 minutes after the last renewal, **Then** the session ends and the request is
   answered with "identity provider session ended".
9. **Given** a server with an LDAP configuration or no configuration, **When** a client reads the
   public configuration, **Then** single sign-on is reported as unavailable. **When** it starts
   single sign-on, **Then** the request is refused as "single sign-on not available".
10. **Given** a started single sign-on, **When** the user cancels at the provider and the client
    passes the provider's error `access_denied` with the state, **Then** the pending single sign-on
    is cancelled, a failed sign-in is audited with the error code `access_denied`, and a later
    completion with the same state is refused as "sign-in state invalid".

---

### User Story 3 - Sign in with an LDAP or Keycloak password (Priority: P1)

A mailbox user whose mailbox authenticates against an LDAP directory, or against Keycloak with the
mailpassword flow enabled, signs in to the new web app with their username and password, as they do
in the legacy UI. The password is checked by the directory or against the password hash Keycloak
keeps for them. Their mailcow second factors, forced second-factor setup, throttling and fail2ban
reporting work exactly as for a mailcow password. A directory or Keycloak user who has no mailbox
yet gets one on their first sign-in, as in legacy.

**Why this priority**: Feature 001 refuses these mailboxes until this feature. Without it, their
users must keep using the legacy UI.

**Independent Test**: Against test LDAP and Keycloak providers, sign in through the API with LDAP
and Keycloak mailboxes, with and without second factors, with wrong passwords, with unknown users,
with directory users who have no mailbox, and with the provider stopped. Compare outcomes, stored
data, fail2ban reports and recent-login entries with the legacy UI.

**Acceptance Scenarios**:

1. **Given** LDAP configured and a mailbox that authenticates against LDAP, **When** its owner signs
   in with their directory password, **Then** a session is established, or a pending second-factor
   step when the mailbox has second factors, and a recent-login entry is recorded.
2. **Given** the same mailbox, **When** a wrong password is submitted, **Then** the sign-in fails
   with the generic error, the attempt is reported to fail2ban with the legacy message, and it
   counts towards throttling.
3. **Given** a directory user `carl@example.org` without a mailbox, automatic creation on and a
   matching mapping, **When** Carl signs in with his directory password, **Then** his mailbox is
   created from the mapped template and he is signed in.
4. **Given** an LDAP directory that cannot be reached, **When** an LDAP mailbox signs in, **Then**
   the client gets "identity provider unavailable", nothing is reported to fail2ban, the attempt
   does not count towards throttling, and the audit record names the reason.
5. **Given** Keycloak configured with the mailpassword flow and a Keycloak mailbox whose
   `mailcow_password` attribute holds a hash of its password, **When** its owner signs in with that
   password, **Then** a session is established. **Given** the mailpassword flow is off, **Then** the
   sign-in fails with the generic error and is not reported to fail2ban, as in feature 001.
6. **Given** a mailbox that authenticates against a generic OIDC provider, **When** its owner signs
   in with a password, **Then** the sign-in fails with the generic error and is not reported to
   fail2ban.
7. **Given** an LDAP mailbox flagged for forced second-factor setup without any second factor,
   **When** its owner signs in, **Then** the session is restricted as in feature 001.
8. **Given** a username that exists as an administrator and as an LDAP mailbox, **When** a client
   signs in without naming a role, **Then** the administrator account is tried first, as in feature
   001.

---

### User Story 4 - Manage OAuth2 clients (Priority: P2)

An administrator registers an application that signs mailbox users in through mailcow's own OAuth2
provider, for example a wiki or a chat server. They enter the application's redirect URIs and get a
client ID and a client secret, which is shown once. They can list and search the clients, change a
client's redirect URIs, generate a new secret, revoke all of a client's tokens, and delete a client.
The applications keep using mailcow's existing authorize, token and profile endpoints, which this
feature does not change.

**Why this priority**: Only some servers use mailcow as an OAuth2 provider, and the endpoints the
applications use keep working without this feature.

**Independent Test**: Create, change, regenerate, revoke and delete clients through the API. After
each step, run an authorization-code flow and a profile request against the unchanged legacy
endpoints with the client, and confirm the outcome the step implies.

**Acceptance Scenarios**:

1. **Given** an administrator, **When** they create a client with the redirect URI
   `https://wiki.example.org/oauth/callback`, **Then** the response contains a 12-character client
   ID and a 24-character secret, later reads never contain the secret, the legacy UI lists the
   client, and an authorization-code flow with these credentials succeeds.
2. **Given** a redirect URI that is relative, contains a fragment or contains a space, **When** a
   client is created or changed with it, **Then** the request is refused with a validation error
   that names the URI.
3. **Given** a client with issued tokens, **When** an administrator regenerates its secret, **Then**
   the new secret is returned once, the old secret is refused by the token endpoint, and existing
   access tokens keep working until they expire.
4. **Given** a client with issued tokens, **When** an administrator revokes its tokens, **Then** the
   profile endpoint refuses every token issued before.
5. **Given** a client with issued tokens, **When** an administrator deletes it, **Then** the client
   and its tokens are gone, and the profile endpoint refuses its access tokens at once.
6. **Given** a domain administrator, **When** they list clients, **Then** the request is refused as
   forbidden. **Given** a read-only key owned by an administrator, **When** a client lists clients
   with it, **Then** it succeeds without secrets. **When** it creates a client, **Then** it is
   refused with "insufficient key scope".
7. **Given** 40 clients, **When** an administrator searches for "wiki", **Then** exactly the clients
   whose client ID or redirect URIs contain "wiki" are returned.
8. **Given** three selected clients, one of which was deleted elsewhere and one sent with an outdated
   version tag, **When** an administrator revokes their tokens in one bulk request, **Then** the
   response reports `done`, `notFound` and `outdated`, only the first client's tokens are removed,
   and one audit record per client carries the bulk request's identifier.

---

### User Story 5 - Trace identity provider and OAuth2 changes (Priority: P3)

An administrator investigating a sign-in problem or an unexpected change lists the audit log
filtered by the identity provider configuration, an OAuth2 client, or the single sign-on method.
Each entry shows who did what and when, which settings changed (secrets only as "changed"), the
outcome of connection tests, which mailboxes were created or updated on sign-in, and why failed
sign-ins failed.

**Why this priority**: The constitution requires an audit record for every change, but the feature
works without anyone reading the log.

**Independent Test**: Make every kind of configuration and OAuth2 change, including refused ones,
run tests, and perform successful and failed sign-ins of every kind, then list the audit log with
each filter.

**Acceptance Scenarios**:

1. **Given** an administrator who changed the LDAP bind password and the sync interval, **When**
   another administrator lists audit records for the identity provider configuration, **Then** the
   entry shows the old and new sync interval, says the bind password changed, and contains no
   password.
2. **Given** a mailbox created on first single sign-on, **When** an administrator lists audit
   records for that mailbox, **Then** the creation is listed with the template it came from and the
   sign-in that caused it.
3. **Given** a failed single sign-on because no mapping matched, **When** an administrator lists
   failed sign-ins, **Then** the entry shows the e-mail address from the provider and the reason.

---

### Edge Cases

- **Provider user info without an e-mail address**: the single sign-on fails with the generic error,
  and the audit record says so.
- **E-mail address in a different letter case** than the mailbox: matched after trimming and
  lower-casing.
- **E-mail address of an alias, a resource or an administrator**: only mailboxes that are not
  resources are matched. Automatic creation then fails because the address is in use, and the
  sign-in fails with the generic error.
- **E-mail address in a domain that mailcow does not host**, or in a domain whose mailbox limit or
  quota is reached: automatic creation fails, and the audit record names the reason.
- **Provider attribute with several values**: the first value is used for the mapping, as in legacy.
- **Mapped template deleted or renamed** after it was saved in the mapping (feature 003): the
  configuration flags the entry as pointing to a missing template. Automatic creation with it fails
  with the reason "template missing", and the update on sign-in is skipped while the sign-in itself
  succeeds, as in legacy.
- **Two first sign-ins of the same new user at the same time**: exactly one mailbox is created, and
  both sign-ins succeed.
- **User cancels at the provider**, or never comes back: the pending single sign-on expires after
  10 minutes. The client can also cancel it. When the provider sends the browser back with an error,
  the client reports it (FR-063).
- **Configuration changed or deleted between the start and the completion** of a single sign-on:
  the completion uses the current configuration. If single sign-on is no longer available, it fails
  as "single sign-on not available".
- **Provider that issues no renewal token**: the single sign-on session is not re-validated with the
  provider and lasts like any other session, as in legacy.
- **Provider unreachable during a renewal**: the session ends, as in legacy. The user signs in again
  once the provider is back.
- **Mailbox switched to another authentication source** (feature 003) while a single sign-on session
  is open: the session ends at its next request.
- **Legacy-stored configuration with empty required values**: it can be read and is reported as
  incomplete. Single sign-on and password sign-in through the provider are unavailable until an
  administrator completes it.
- **Legacy-stored settings of a previous provider type**: not shown, and removed by the next save
  through this API.
- **Legacy-stored mapping whose two lists differ in length**, or that cannot be read: the entries
  that pair up are shown and used, and the rest are ignored.
- **LDAP with SSL on and port 389**: the connection uses port 636, as legacy does.
- **SSL and StartTLS both requested**: StartTLS is stored off, and the response shows the effective
  values, as in legacy.
- **Keycloak user without a `mailcow_password` attribute**, or with an empty one: the password
  cannot be verified. The sign-in fails with the generic error and is not reported to fail2ban.
- **Keycloak service account without the "view-users" permission**: the mailpassword flow cannot
  look up users. Sign-ins get "identity provider unavailable", and the connection test of the
  configuration still succeeds, because it only requests the service token.
- **Directory entry found, but the bind with the user's password fails**: a wrong password, reported
  to fail2ban.
- **Username with characters that have a meaning in a directory query**: they are escaped and match
  literally. Usernames outside the legacy syntax fail with the generic error before the directory is
  contacted.
- **Throttled username and address**: refused before the provider is contacted.
- **Test against an internal address**: allowed for administrators, because the provider is often in
  the internal network.
- **OAuth2 redirect URIs stored by legacy that break the rules** (for example empty or with a
  fragment): readable, but they must be corrected before the redirect URIs can be changed.
- **OAuth2 client ID collision** when generating a new one: a new ID is generated. (Legacy refuses
  the creation.)
- **Revoking the tokens of a client that has none**: succeeds and changes nothing.
- **Configuration edited in the legacy UI**: the legacy UI never sends a version and always re-sends
  the stored secrets, so it can still overwrite changes made through this API, as before.
- **Audit record cannot be written**: the change it would record does not succeed.

## Requirements *(mandatory)*

### Functional Requirements

#### Access and scope

- **FR-001**: Only administrators MUST reach the identity provider configuration and the OAuth2
  client operations, as in legacy. Domain administrators and mailbox users MUST be refused as
  forbidden. Domain administrators learn which provider type is configured through feature 003,
  which needs it for the mailbox authentication source, not through this configuration.
- **FR-002**: Scope and permissions MUST be evaluated from the current mailcow data on every
  request, as in feature 001 (FR-029 there). A request authenticated by an API key MUST act with the
  key owner's role (feature 001, FR-033). A read-only key owned by an administrator MAY read the
  configuration and list and inspect OAuth2 clients. Every other operation of this feature,
  including the connection test, MUST be refused for it with the "insufficient key scope" error of
  feature 001 (FR-039). A restricted session (feature 001, FR-014) MUST be refused. Single sign-on
  MUST NOT be started or completed with an API key, because it establishes a session.
- **FR-003**: The operations MUST be authorised as follows:

  | Operation | Administrator | Domain administrator | Mailbox user | Not signed in |
  |---|---|---|---|---|
  | Read the identity provider configuration | Yes, without secrets | Forbidden | Forbidden | Unauthenticated |
  | Save, change or delete the configuration | Yes | Forbidden | Forbidden | Unauthenticated |
  | Test a connection | Yes | Forbidden | Forbidden | Unauthenticated |
  | List and inspect OAuth2 clients | Yes, without secrets | Forbidden | Forbidden | Unauthenticated |
  | Create, change, regenerate the secret of, revoke the tokens of, delete an OAuth2 client, singly or in bulk (FR-060) | Yes | Forbidden | Forbidden | Unauthenticated |
  | Start and complete single sign-on | Not applicable | Not applicable | Not applicable | Yes, signs in a mailbox user |
  | Password sign-in of an LDAP or Keycloak mailbox | Not applicable | Not applicable | Not applicable | Yes, through feature 001's sign-in |
  | Read the audit log | Yes | Forbidden | Forbidden | Unauthenticated |

- **FR-004**: An OAuth2 client that does not exist MUST be answered "not found". Reading, changing
  individual settings of, or deleting the configuration when none is stored MUST be answered with a
  documented "identity provider not configured" error of the "not found" kind.

#### Identity provider configuration

- **FR-005**: A server MUST have at most one identity provider configuration, of the type Keycloak,
  generic OIDC or LDAP, as in legacy. The configuration MUST be stored where and how legacy stores
  it, with the same setting names and value encodings, so that the legacy UI, `/api/v1`, the legacy
  single sign-on, the mail clients' sign-in check and the two synchronisation jobs read a
  configuration saved through this API unchanged, and so that this API reads a configuration saved
  by legacy.
- **FR-006**: The configuration MUST be represented with:
  - its type;
  - for Keycloak: the server URL, realm, client ID, Keycloak version, mailpassword flow, user
    import, periodic full synchronisation and sync interval;
  - for generic OIDC: the authorization endpoint, token endpoint, user info endpoint, client ID and
    scopes;
  - for Keycloak and generic OIDC: the legacy return URL, the additional return URLs, and the new
    web app's sign-in return addresses, one for each of the server's host names, read-only, which
    the operator registers at the provider (FR-021);
  - for LDAP: the hosts, port, SSL, StartTLS, base DN, username field, filter, attribute field, bind
    DN, user import, periodic full synchronisation and sync interval;
  - for every type: the attribute mapping, the default template, automatic creation on first
    sign-in, and whether TLS certificate errors are ignored;
  - for each secret (the client secret of Keycloak and generic OIDC, the LDAP bind password), only
    whether it is set;
  - for each mapping entry and for the default template, whether the template currently exists;
  - whether the configuration is complete for sign-in (FR-008);
  - the number of mailboxes for each external authentication source (Keycloak, generic OIDC and
    LDAP), counted as the checks of FR-015 and FR-017 count them;
  - for Keycloak and LDAP, the synchronisation status (FR-062);
  - its last modification time and its version tag (FR-050).
- **FR-007**: A value missing from the stored data MUST be shown with the legacy default: automatic
  creation on first sign-in on; scopes `openid profile email mailcow_template`; SSL, StartTLS,
  ignoring TLS errors, the mailpassword flow, user import and periodic full synchronisation off;
  port 389; username field `mail`; sync interval 15 minutes; no mapping, no default template and no
  additional return URLs. Stored settings that do not belong to the stored type MUST NOT be shown.
- **FR-008**: A configuration is complete for sign-in when it has, as legacy requires before it uses
  a provider: for Keycloak, the server URL, realm, client ID, client secret, legacy return URL and
  version; for generic OIDC, the client ID, client secret, legacy return URL and the three
  endpoints; for LDAP, the hosts, port, base DN, bind DN and bind password. An incomplete stored
  configuration MUST be readable and MUST NOT be used for any sign-in.

#### Saving the configuration

- **FR-009**: Administrators MUST be able to save a whole configuration of a chosen type, which
  creates it or replaces the stored one, and to change individual settings of the stored
  configuration while keeping the others. In a whole-configuration save, an omitted value MUST take
  its default from FR-007. A save when no configuration exists MUST respond as "created", and every
  other successful save as "updated". Both responses MUST contain the representation and the new
  version tag.
- **FR-010**: Keycloak settings MUST meet these rules:
  - server URL: required, an absolute `https` or `http` URL of at most 2,000 characters. Trailing
    slashes MUST be removed, as legacy does;
  - realm: required, 1 to 255 characters, without `/`;
  - client ID: required, 1 to 255 characters;
  - client secret: FR-014;
  - Keycloak version: required, one to four whole numbers separated by dots, for example `26.0.7`;
  - mailpassword flow, user import and periodic full synchronisation: on or off;
  - sync interval: a whole number of minutes from 1 to 10,080.
- **FR-011**: Generic OIDC settings MUST meet these rules:
  - authorization endpoint, token endpoint and user info endpoint: required, each an absolute
    `https` or `http` URL of at most 2,000 characters;
  - client ID: required, 1 to 255 characters; client secret: FR-014;
  - scopes: 1 to 50 scope names separated by single spaces, each made of printable characters other
    than space, `"` and `\`. The default is `openid profile email mailcow_template`.
- **FR-012**: LDAP settings MUST meet these rules:
  - hosts: 1 to 10 host names or IP addresses. Legacy stores them as one comma-separated text
    without spaces, and the API MUST store them that way;
  - port: a whole number from 1 to 65,535, 389 by default. When SSL is on and the port is 389,
    connections MUST use port 636, as legacy does;
  - SSL and StartTLS: on or off. When both are requested, StartTLS MUST be stored off, as legacy
    does, and the response MUST show the effective values;
  - base DN and bind DN: required, 1 to 1,000 characters each; bind password: FR-014;
  - username field: an attribute name (a letter followed by letters, digits or hyphens, at most 255
    characters), stored lower-cased, `mail` by default;
  - attribute field: empty, or an attribute name as above, stored lower-cased. It MUST NOT be empty
    while user import or periodic full synchronisation is on;
  - filter: empty, or a directory search filter of at most 1,000 characters that starts with `(`,
    ends with `)` and has balanced parentheses;
  - user import, periodic full synchronisation and sync interval: as for Keycloak (FR-010).
- **FR-013**: The settings shared by several types MUST meet these rules:
  - legacy return URL (Keycloak and generic OIDC): required, an absolute `https` or `http` URL of at
    most 2,000 characters;
  - additional return URLs (Keycloak and generic OIDC): at most 100 absolute `https` or `http` URLs
    of at most 2,000 characters each, duplicates stored once;
  - attribute mapping: at most 100 entries, each an attribute value of 1 to 255 characters and the
    name of a mailbox template (feature 003) that exists when the configuration is saved. Attribute
    values MUST be unique and are compared exactly, letter case included;
  - default template: none, or the name of an existing mailbox template;
  - automatic creation on first sign-in and ignoring TLS certificate errors: on or off.
- **FR-014**: The client secret (Keycloak, generic OIDC) and the bind password (LDAP) MUST each be 1
  to 1,000 characters long and MUST be write-only: no response, log line or audit record may contain
  them. A secret:
  - MUST be sent when a configuration of that type is first saved and when the type changes;
  - MUST be kept when a later save or change omits it;
  - MUST be sent again when the address that receives it changes: the Keycloak server URL, the
    generic OIDC token endpoint, or the LDAP hosts. A save that changes such an address without the
    secret MUST be refused with a validation error on the secret, and nothing MUST change.
- **FR-015**: A save of a type other than the stored one MUST be refused as an "identity provider in
  use" conflict when any mailbox has an external authentication source other than the new type. The
  error MUST report how many mailboxes there are, per authentication source, as legacy refuses this.
  After any successful save,
  the stored configuration MUST hold only the settings of the saved type: settings of a previous
  type MUST be removed. **Deviation**: legacy keeps them.
- **FR-016**: A successful save MUST clear the cached Keycloak service token, as legacy does, so
  that every component fetches a new one. The new settings MUST apply to the next sign-in through
  this API, through the legacy UI and through mail clients, without a restart. A save MUST NOT
  change any mailbox.

#### Deleting the configuration

- **FR-017**: Administrators MUST be able to delete the configuration. A deletion MUST be refused as
  an "identity provider in use" conflict when any mailbox has an external authentication source,
  and the error MUST report how many mailboxes there are, per authentication source, as in legacy.
  A successful deletion MUST
  remove every stored setting, including the cached service token, and MUST respond with no body.
  Single sign-on then becomes unavailable, and the synchronisation jobs stop at their next run.

#### Synchronisation jobs

- **FR-018**: The user import and the periodic full synchronisation MUST stay with the legacy
  scheduled jobs, which run every minute in the legacy PHP container. This feature MUST NOT run,
  trigger or replace them. A configuration change MUST take effect at a job's next run, because each
  run reads the configuration when it starts. The contract MUST document how the jobs behave:
  - a job runs only when the type is Keycloak or LDAP and user import or periodic full
    synchronisation is on, and at most once per sync interval, counted from the end of its previous
    run;
  - a run in progress finishes with the settings it started with;
  - user import creates a mailbox, authenticating against the provider, for each provider user that
    has no mailbox, from the mapped template or else the default template;
  - periodic full synchronisation re-applies the mapped template to each existing mailbox of the
    configured type. The default template is never used for updates;
  - a generic OIDC provider has no synchronisation;
  - the jobs report their progress in the scheduled-job log, which feature 011 lists.

#### Testing a connection

- **FR-019**: Administrators MUST be able to test candidate settings without saving them:
  - the candidate settings MUST meet the rules of FR-010 to FR-013, apart from the existence of
    mapped templates;
  - an omitted secret MUST be replaced by the stored one only when the stored type is the same and
    the address that receives the secret is unchanged (FR-014). Otherwise the test MUST be refused
    with a validation error on the secret;
  - Keycloak: the test requests a service token with the client credentials, as legacy does, and
    succeeds when the provider issues one;
  - generic OIDC: the test succeeds when the token endpoint answers and does not reject the client
    credentials. **Deviation**: legacy expects a success answer to an `OPTIONS` request;
  - LDAP: the test connects with the SSL, StartTLS and certificate settings and binds with the bind
    DN and password, as legacy does;
  - the result MUST be a success, or a failure with one of these categories: unreachable, timed out,
    TLS certificate rejected, credentials rejected, unexpected response (with the provider's status
    code, never a secret). A failed connection is a successful request that reports the failure;
  - nothing MUST be stored, and the test MUST be audited (FR-052).

#### Single sign-on

- **FR-020**: Single sign-on MUST be available when the configuration is of type Keycloak or generic
  OIDC and complete (FR-008). The public configuration of feature 001 (FR-042 there) MUST also state
  whether single sign-on is available. The contract MUST document that clients offer single sign-on
  to mailbox users when it is available, and that when the "force single sign-on" login option is
  set and single sign-on is available, clients offer single sign-on instead of the password form on
  the mailbox-user sign-in screen, as the legacy login page does, while passkey sign-in stays
  available. The option is display-only and MUST NOT make the API refuse any sign-in method.
- **FR-021**: An unauthenticated client MUST be able to start a single sign-on. The response MUST
  contain the provider's authorization address, to which the browser is sent. Starting MUST:
  - create a random state that is single-use, bound to the client's browser session, and expires
    10 minutes after it is issued;
  - add proof-key protection (PKCE with the S256 method) to the request. **Deviation**: legacy uses
    the state only;
  - set the return address to the new web app's sign-in return address on the host name the client
    used, when that host name is one of the server's host names (`MAILCOW_HOSTNAME` and
    `ADDITIONAL_SERVER_NAMES`), and otherwise on `MAILCOW_HOSTNAME`, as legacy selects its return
    URL by host name;
  - request the scopes legacy requests: for Keycloak `profile` and `email`, plus `openid` when the
    configured version is 20.0.0 or later; for generic OIDC the configured scopes;
  - for Keycloak, use the endpoints legacy uses, which lie below
    `<server URL>/realms/<realm>/protocol/openid-connect/`.

  When single sign-on is not available, the request MUST be refused as "single sign-on not
  available". Starting and completing a single sign-on are sign-in requests and MUST carry the
  anti-forgery token of feature 001 (FR-011 there).
- **FR-022**: The client MUST be able to complete the single sign-on by submitting the code and the
  state that the provider returned. Completing MUST:
  - refuse a state that is unknown, expired, already used or bound to another browser session as
    "sign-in state invalid", without contacting the provider, and consume a valid state;
  - exchange the code, with the client credentials and the proof key, for the provider's tokens, and
    read the user's details from the provider's user info endpoint, as legacy does;
  - take the user's e-mail address from those details, trimmed and lower-cased. A missing or invalid
    address MUST fail the sign-in;
  - take the mapping attribute from the `mailcow_template` value of the details, using the first
    value when there are several.

  A refused exchange or user info request MUST fail with the generic sign-in error of feature 001
  (FR-005 there). A provider that cannot be reached or does not answer in time MUST fail with
  "identity provider unavailable".
- **FR-023**: The system MUST then look up the mailbox, not counting resources, whose address is the
  e-mail address. When it exists, its authentication source MUST be the configured provider type,
  and the mailbox and its domain MUST be active, as legacy requires. Otherwise the sign-in MUST fail
  with the generic error. When no mailbox exists, automatic creation applies (FR-034 to FR-040).
- **FR-024**: A successful single sign-on MUST establish a mailbox-user session, with the session
  identifier renewed as in feature 001 (FR-008 there). As in legacy:
  - no mailcow second factor is asked for, and forced second-factor setup and forced password change
    do not restrict the session. This is an exception to FR-014 and FR-020 of feature 001;
  - the mailbox's second-factor entries are not re-activated.

  The system MUST record a recent-login entry, as feature 001 does for password and passkey sign-ins
  (FR-027 there). **Deviation**: legacy records none for single sign-on. The identity of the session
  (feature 001, FR-028) MUST report the method "single sign-on" and the provider type. The
  provider's tokens MUST be kept on the server side and never given to the client.
- **FR-025**: When a sign-in through the provider succeeds for an existing mailbox of the configured
  type, by single sign-on or by password, and the mapping attribute matches a mapping entry, the
  system MUST update the mailbox from the mapped template, as legacy does, but only when the
  template's values or the display name from the provider differ from those last applied, which
  legacy tracks with a marker on the mailbox. The update MUST apply the template as a change of the
  mailbox under feature 003's rules (FR-029 to FR-035 there), including its TLS enforcement,
  quarantine notification settings, rate limit and user permissions, MUST set the display name
  from the provider (FR-037), and MUST record the new marker instead of clearing it (feature 003,
  FR-034). It acts for the system and is not limited by any domain administrator's flags. The
  default template MUST NOT be used for updates. A failed update MUST NOT stop the sign-in and MUST
  be audited.
- **FR-026**: When the provider issued a renewal token at sign-in, the system MUST renew the
  provider tokens on the first request that arrives more than 5 minutes after the last renewal.
  The session MUST end, and that request MUST be answered with "identity provider session ended",
  when:
  - the provider refuses the renewal or cannot be reached, as in legacy;
  - the renewed details carry another e-mail address. **Deviation**: legacy switches the session to
    that address;
  - the configuration has been deleted, its type changed, or it is no longer complete;
  - the mailbox's authentication source is no longer the configured type.

  When the provider issued no renewal token, the session MUST NOT be re-validated with the
  provider, as in legacy. A session ended this way MUST be audited.
- **FR-027**: Signing out (feature 001, FR-010) MUST end the session and discard the provider's
  tokens. It MUST NOT sign the user out at the provider, as in legacy, and the contract MUST say so.
- **FR-028**: Single sign-on failures MUST be audited. They MUST NOT be reported to fail2ban and
  MUST NOT count towards throttling, because no password is involved, as in legacy.

#### Password sign-in of LDAP and Keycloak mailboxes

- **FR-029**: Feature 001's password sign-in (FR-001 to FR-007 there) MUST verify a mailbox whose
  authentication source is external as follows, replacing the refusal in feature 001 (FR-003 there):
  - LDAP source, with LDAP configured: the system looks up the directory entry whose username field
    equals the username, below the base DN and within the filter, and binds as that entry with the
    password. The password is verified when the bind succeeds;
  - Keycloak source, with Keycloak configured and the mailpassword flow on: the system looks up the
    Keycloak user by exact e-mail address with the service account, and verifies the password
    against the hash in the user's `mailcow_password` attribute, accepting every hash format of
    feature 001 (FR-004 there);
  - every other case (a generic OIDC source, Keycloak without the mailpassword flow, a source other
    than the configured type, or an incomplete configuration): the password cannot be verified, and
    feature 001's handling applies: the generic error, without a fail2ban report.
- **FR-030**: A username MUST meet the legacy syntax (an e-mail address, or letters and digits with
  `_`, `.` and `-`) before the provider is contacted, and characters with a meaning in a directory
  query MUST be escaped, so that a username can never change the query.
- **FR-031**: After the provider verified the password, the mailbox and its domain MUST be active.
  Otherwise the sign-in MUST fail with the generic error. The sign-in MUST then continue exactly as
  for a mailcow password in feature 001: the second-factor step (FR-020 to FR-025 there), the
  re-activation of second-factor entries (FR-026 there), the forced second-factor setup restriction
  (FR-014 there) and the recent-login entry (FR-027 there). Forced password change never applies to
  these mailboxes (feature 003, FR-025). The update from the mapped template (FR-025) MUST happen
  before the session is established.
- **FR-032**: When no account matches the username in feature 001's order (FR-002 there), or the
  mailbox-user role is named and no mailbox matches, and LDAP or Keycloak with the mailpassword flow
  is configured, the system MUST verify the username and password at the provider as in FR-029. When
  the provider verifies them, the system MUST create the mailbox (FR-034 to FR-040) and continue as
  in FR-031, as legacy does.
- **FR-033**: Failures MUST be handled as follows:
  - a wrong password, or a username the provider does not know: the generic error, a fail2ban report
    (feature 001, FR-006), a throttling count (feature 001, FR-007) and an audit record;
  - a provider that cannot be reached, does not answer in time, or refuses the service account: a
    documented "identity provider unavailable" temporary failure, without a fail2ban report or a
    throttling count, and an audit record. **Deviation**: legacy reports it as a wrong password;
  - a verified password, but automatic creation is off, no mapping matches and no default template
    is set, or the creation fails: the generic error, without a fail2ban report or a throttling
    count, and an audit record with the reason. **Deviation**: legacy reports it as a wrong
    password;
  - a Keycloak user without a `mailcow_password` value: the password cannot be verified, so the
    generic error, without a fail2ban report or a throttling count, and an audit record.
    **Deviation**: legacy reports it as a wrong password;
  - an attempt refused by throttling (feature 001, FR-007) MUST NOT contact the provider.

#### Automatic creation on first sign-in

- **FR-034**: Automatic creation MUST happen only when the provider has authenticated the user, by
  single sign-on or by password, no mailbox exists for the address, and automatic creation on first
  sign-in is on. When it is off, the sign-in MUST fail with the generic error, as in legacy.
- **FR-035**: The template MUST be chosen as legacy chooses it: the mailbox template of the mapping
  entry whose attribute value equals the user's mapping attribute, otherwise the default template.
  When neither exists, the sign-in MUST fail with the generic error. The mapping attribute is:
  - for single sign-on, the `mailcow_template` value of the user's details (FR-022);
  - for the Keycloak mailpassword flow, the user's `mailcow_template` attribute;
  - for LDAP, the configured attribute field of the directory entry.

  The first value MUST be used when there are several.
- **FR-036**: The mailbox MUST be created as feature 003 creates a mailbox from a template (FR-019
  to FR-027 there), with these specifics:
  - the local part and the domain come from the address, and the domain MUST be an existing primary
    domain;
  - the domain's limits (feature 003, FR-023) and the address conflict rules (feature 003, FR-021)
    MUST apply;
  - the authentication source is the configured provider type, and the mailbox gets no password;
  - forced password change is off, and for generic OIDC forced second-factor setup is also off
    (feature 003, FR-025);
  - the creation acts for the system and is not limited by any domain administrator's flags,
    including for an unlimited quota, as legacy does;
  - the template marker of FR-025 is recorded, so that later updates detect template changes.
- **FR-037**: The display name MUST come from the provider: the `name` value of the user's details
  for single sign-on, the `displayName` attribute for LDAP, and the first and last name for the
  Keycloak mailpassword flow. When it is empty, the local part MUST be used (feature 003, FR-022).
  **Deviation**: legacy reads a name field that Keycloak never provides in the mailpassword flow, so
  those mailboxes get an empty name and are updated again at every sign-in.
- **FR-038**: A failed creation MUST fail the sign-in with the generic error, MUST leave no partial
  mailbox (feature 003, FR-027), and MUST be audited with the reason.
- **FR-039**: After the creation, the mailbox and its domain MUST be active, as legacy re-checks.
  The sign-in MUST then continue as for an existing mailbox: FR-024 for single sign-on, and FR-031
  for a password, including a forced second-factor setup restriction when the template sets it.
  **Deviation**: legacy skips the forced-setup check on the first password sign-in.
- **FR-040**: When several first sign-ins for the same address happen at the same time, exactly one
  mailbox MUST be created, and the other sign-ins MUST continue as sign-ins of an existing mailbox.

#### OAuth2 clients

- **FR-041**: Administrators MUST be able to list OAuth2 clients with pagination: 25 per page by
  default, at most 1,000, with the total number of matching clients. The list MUST be sortable by
  identifier (the default, ascending), client ID and first redirect URI, MUST support a
  case-insensitive substring search over the client ID and the redirect URIs, and MUST support a
  filter on whether the client has unexpired tokens. Administrators MUST be able to inspect one
  client.
- **FR-042**: An OAuth2 client MUST be addressed by its numeric identifier, as in legacy, and
  represented with that identifier, its client ID, its redirect URIs in stored order, its scope
  (`profile`), the number of its unexpired access tokens and refresh tokens, and its version tag
  (FR-050). The client secret MUST NOT be part of any representation, apart from FR-043 and FR-046.
- **FR-043**: Administrators MUST be able to create a client by giving its redirect URIs. The system
  MUST generate, from a cryptographically secure source, a client ID of 12 lowercase hexadecimal
  characters that no other client has, and a secret of 24 lowercase hexadecimal characters, which
  are the legacy formats, and MUST set the scope `profile`. The creation MUST respond as "created",
  with the address of the new client, its representation and its secret. This and FR-046 are the
  only responses that ever contain the secret.
- **FR-044**: Redirect URIs MUST be a list of 1 or more absolute URIs, each with a scheme, without a
  fragment and without whitespace. Together, joined by single spaces as legacy and the token
  endpoint store them, they MUST NOT exceed 2,000 characters. Duplicates MUST be stored once. The
  contract MUST document that applications must name their redirect URI in the authorization request
  when several are registered, because the token endpoint then requires it.
- **FR-045**: Administrators MUST be able to replace a client's redirect URIs, under the rules of
  FR-044. The client ID and the scope MUST NOT be changeable. A request that sends either with a
  different value MUST be refused with a validation error that names it.
- **FR-046**: Administrators MUST be able to regenerate a client's secret. The new secret MUST be
  generated as in FR-043 and returned once. Existing access tokens and refresh tokens MUST be kept,
  and the contract MUST say, as legacy does, that renewing a token with the old secret fails.
- **FR-047**: Administrators MUST be able to revoke all tokens of a client. This MUST remove the
  client's access tokens, refresh tokens and authorization codes, so that every application session
  ends at once. **Deviation**: legacy keeps the authorization codes. Revoking a client without
  tokens MUST succeed and change nothing.
- **FR-048**: Administrators MUST be able to delete a client. A deletion MUST remove the client
  together with its access tokens, refresh tokens and authorization codes, and MUST respond with no
  body. **Deviation**: legacy removes only the client.
- **FR-049**: Clients MUST be stored where legacy stores them, so that the legacy UI and the
  authorize, token and profile endpoints of the legacy PHP container use them unchanged. Those
  endpoints, the consent page, the token lifetimes, the accepted grant types and the `profile` scope
  MUST stay unchanged and are not part of this feature. The contract MUST document the endpoint
  paths and the behaviour that the legacy information text documents for application developers.

#### Version tags

- **FR-050**: The identity provider configuration and every OAuth2 client MUST carry a version tag
  that changes whenever their stored settings change, whether through this API, the legacy UI or
  `/api/v1`. The configuration's tag MUST cover every stored setting, including the secrets, so that
  a changed secret changes it, and MUST NOT cover the cached Keycloak service token, which legacy
  rewrites during sign-ins. A client's tag MUST cover its redirect URIs and secret, not its tokens.
- **FR-051**: A client MAY send the version tag it read with a save, change, deletion, secret
  regeneration or token revocation. When the tag no longer matches, the request MUST be refused as
  outdated and MUST change nothing, as in feature 002 (FR-042 there). The contract MUST recommend
  that interactive clients always send it.

#### Audit

- **FR-052**: Every request of this feature that changes state or tries to (saving, changing and
  deleting the configuration, and creating, changing, regenerating the secret of, revoking the
  tokens of and deleting an OAuth2 client), and every connection test, MUST write exactly one audit
  record into feature 001's audit log, with the fields of FR-046 there and:
  - the target type (identity provider configuration or OAuth2 client) and the target identifier;
  - the outcome, including refusals and their reason;
  - for successful changes, each changed setting with its old and new value. Secrets MUST be
    recorded only as changed;
  - for a test, the provider type, the address tested and the outcome category;
  - for a revocation or deletion of a client, the number of tokens and codes removed;
  - for an item of a bulk action (FR-060), one record per client, carrying the bulk request's
    identifier, as `docs/conventions.md` requires.
- **FR-053**: Sign-in audit records (feature 001, FR-045 and FR-046 there) MUST cover single sign-on
  and password sign-in through a provider, with the methods "single sign-on", "directory password"
  (LDAP) and "provider password" (Keycloak mailpassword flow), the provider type, and these failure
  reasons in addition to feature 001's: sign-in state invalid, provider refused, identity provider
  unavailable, no e-mail address, mailbox not linked to the provider, automatic creation off, no
  matching mapping, creation failed, password not verifiable. A session ended by FR-026 MUST be
  audited. A mailbox created or updated on sign-in MUST get its own audit record with the mailbox as
  target, the template used, the changes made and a reference to the sign-in.
- **FR-054**: Administrators MUST be able to filter the audit log by the target types of this
  feature and by sign-in method, in addition to the filters of features 001 (FR-047) and 002
  (FR-058).

#### Cross-cutting

- **FR-055**: These values MUST NOT appear in any response, log line or audit record: the identity
  provider's client secret, the bind password, the cached Keycloak service token, the provider's
  tokens, authorization codes, the single sign-on state and proof key, users' passwords, and OAuth2
  client secrets apart from FR-043 and FR-046. **Deviation**: legacy writes the bind password into
  its UI log on every save.
- **FR-056**: Each call to a provider MUST time out after 7 seconds, as in legacy. TLS certificates
  MUST be verified unless the configuration ignores TLS certificate errors, and that setting MUST
  apply to every connection to the provider, including the Keycloak service account and user
  lookups. **Deviation**: legacy ignores the setting for those lookups.
- **FR-057**: Errors MUST follow feature 001's conventions (FR-049 and FR-055 there). This feature
  MUST document at least these error types: identity provider not configured, identity provider in
  use, single sign-on not available, sign-in state invalid, identity provider unavailable, identity
  provider session ended, and outdated version. Validation errors MUST use stable rule codes with
  their parameters, including "secret required" for FR-014 and "template not found" for FR-013.
- **FR-058**: Every operation MUST be described in the published API contract before it is
  implemented, and its behaviour MUST match the contract.
- **FR-059**: Changes made through the legacy UI or `/api/v1` MUST be visible in the next response
  of this API.
- **FR-060**: Administrators MUST be able to delete, or revoke the tokens of, up to 100 OAuth2
  clients in one request, as the legacy mass actions of the "OAuth2 Apps" table do. A request with
  more than 100 clients MUST be refused with a validation error that states the limit. Secret
  regeneration, creation and redirect URI changes MUST NOT be offered as bulk actions, and this
  feature MUST NOT offer any other batch operation.
- **FR-061**: A bulk action MUST be refused as a whole only when the caller's role or key scope does
  not allow it (FR-002, FR-003). Otherwise each named client MUST be processed on its own, with the
  same checks, effects and audit record as a single request for it (FR-047, FR-048, FR-052), and the
  response MUST report one result per distinct client: `done`, `notFound`, `refused`, `outdated` or
  `failed`. A failure on one client MUST NOT stop or undo the others. Each item MAY carry the version
  tag the client read; an item whose tag no longer matches MUST be reported as `outdated` and left
  unchanged, and items without a tag MUST be processed without a version check. A client named twice
  MUST be processed once.
- **FR-062**: For a Keycloak or LDAP configuration, the representation MUST report the
  synchronisation status, derived read-only from the scheduled-job log that the legacy jobs write,
  without changing legacy:
  - whether a job is enabled (user import or periodic full synchronisation on);
  - the end of the last completed run, or none when the log holds no completed run;
  - the time of a run-level failure logged since the last completed run, if any;
  - whether a run is in progress, and whether the next run is overdue;
  - when the next run is expected, counted from the end of the last completed run and the sync
    interval as the jobs count it (FR-018).

  The contract MUST document that the status is derived from the log, which keeps a limited number
  of entries, and that a failure that the jobs do not log shows only as an overdue run. The status
  MUST NOT be part of the version tag (FR-050).
- **FR-063**: When the provider sends the browser back with an error instead of a code, the client
  MUST be able to report the provider's error code together with the state. The system MUST validate
  the state as for a completion (FR-022) and consume it, MUST cancel the pending single sign-on, and
  MUST audit a failed sign-in with the provider's error code. The error code MUST be 1 to 64
  characters of the OAuth 2.0 error code character set. The provider's free-text description MUST NOT
  be accepted or stored. An invalid state MUST be refused as "sign-in state invalid".

### Legacy Coverage

Every legacy capability allocated to feature 012 in `docs/roadmap.md` maps to requirements as
follows. Actions are `/api/v1` names; *form* marks legacy UI handlers and screens.

| Legacy action or form | Legacy implementation | Requirements |
|---|---|---|
| `get/identity-provider` | `identity_provider('get')` | FR-001 to FR-008, FR-014 |
| `edit/identity-provider`, *form* Keycloak, Generic-OIDC and LDAP forms (save) | `identity_provider('edit')`, `admin/tab-config-identity-provider.twig`, `js/site/admin.js` | FR-009 to FR-016, FR-050 to FR-052 |
| Keycloak settings (server URL, realm, client ID and secret, return URLs, version, mailpassword flow) | `edit` case `keycloak` | FR-010, FR-013, FR-014, FR-029 |
| Generic OIDC settings (endpoints, client ID and secret, return URLs, scopes) | `edit` case `generic-oidc` | FR-011, FR-013, FR-014 |
| LDAP settings (hosts, port, SSL, StartTLS, base DN, username field, filter, attribute field, bind DN and password) | `edit` case `ldap` | FR-012, FR-014, FR-029 |
| Attribute mapping and default template | `mappers`, `templates`, `default_template` | FR-006, FR-013, FR-025, FR-035 |
| Automatic creation on first sign-in | `login_provisioning` | FR-007, FR-034 to FR-040 |
| User import, periodic full sync, sync interval | `import_users`, `periodic_sync`, `sync_interval`, `crons/keycloak-sync.php`, `crons/ldap-sync.php` | FR-010, FR-012, FR-018 |
| Ignore TLS certificate errors | `ignore_ssl_error` | FR-013, FR-019, FR-056 |
| `edit/identity-provider-test`, *form* "Test Connection" | `identity_provider('test')` | FR-019, FR-052 |
| `delete/identity-provider`, *form* "Remove" | `identity_provider('delete')` | FR-017, FR-052 |
| Provider initialisation and completeness | `identity_provider('init')` | FR-008, FR-020, FR-029 |
| *form* "Single Sign-On" button, `?iam_sso=1` | `index.php`, `user_index.twig`, `identity_provider('get-redirect')` | FR-020, FR-021 |
| *form* return from the provider with code and state | `triggers.user.inc.php`, `identity_provider('verify-sso')` | FR-022 to FR-025, FR-028 |
| Provider token renewal | `identity_provider('refresh-token')`, `triggers.user.inc.php` | FR-026 |
| *form* logout | `sessions.inc.php` | FR-027 |
| Keycloak service token | `identity_provider('get-keycloak-admin-token')` | FR-016, FR-029, FR-050 |
| Password sign-in of external mailboxes | `user_login()` cases `keycloak` and `ldap`, `keycloak_mbox_login_rest()`, `ldap_mbox_login()` | FR-029 to FR-033 |
| Mailbox creation and update on sign-in | `mailbox('add' / 'edit', 'mailbox_from_template')` | FR-025, FR-034 to FR-040 |
| `add/oauth2-client`, *form* "Add OAuth2 client" | `oauth2('add', 'client')`, `modals/admin.twig` | FR-043, FR-044 |
| `get/oauth2-client/all`, `get/oauth2-client/{id}`, *form* "OAuth2 Apps" table and edit page | `oauth2('get', 'clients')`, `oauth2('details', 'client')`, `admin/tab-config-oauth2.twig`, `edit/oauth2client.twig` | FR-041, FR-042 |
| `edit/oauth2-client` (redirect URI) | `oauth2('edit', 'client')` | FR-044, FR-045 |
| `edit/oauth2-client` with `renew_secret`, *form* "Generate new client secret" | `oauth2('edit', 'client')` | FR-046 |
| `edit/oauth2-client` with `revoke_tokens`, *form* "Revoke all client tokens", single and mass | `oauth2('edit', 'client')` | FR-047, FR-060, FR-061 |
| `delete/oauth2-client`, single and mass | `oauth2('delete', 'client')` | FR-048, FR-060, FR-061 |
| Mass "Generate new client secret" | `oauth2('edit', 'client')` with `renew_secret` | FR-046 per client; no bulk form (FR-060) |
| Scheduled-job log entries of the synchronisation jobs (`CRON_LOG`) | `crons/keycloak-sync.php`, `crons/ldap-sync.php` | FR-062 (read-only status) |

Not covered here, by design:

- `oauth2('delete', 'access_token' / 'refresh_token')`: no legacy caller. Token removal is covered
  per client by FR-047 and FR-048.
- The OAuth2 endpoints `/oauth/authorize`, `/oauth/token` and `/oauth/profile` and the consent page:
  internal endpoints that stay in the legacy PHP container (constitution V, FR-049).
- The scheduled jobs `keycloak-sync.php` and `ldap-sync.php`: they stay in the legacy PHP container
  (FR-018).
- The mail clients' sign-in check (`mailcowauth.php`), which verifies LDAP and Keycloak mailboxes
  with the same configuration: an internal endpoint (FR-005, FR-016).
- The mailbox authentication source field of the mailbox forms: feature 003.
- LDAP password confirmation in the user's own account settings (`edit_user_account`): feature 005.
- Editing the "force single sign-on" login option (`edit/custom_login`): feature 013. Feature 001
  publishes it, and FR-020 states its meaning.
- Reading the synchronisation jobs' log (`logs/cron`): feature 011.

### Legacy Parity and Deliberate Deviations

Parity reference: `source_code/data/web/inc/functions.inc.php` (`identity_provider()`,
`set_user_loggedin_session`, `hasDomainAccess` and `hasACLAccess` with `access_all_exception`,
`logger`), `functions.auth.inc.php` (`check_login`, `user_login`, `keycloak_mbox_login_rest`,
`ldap_mbox_login`), `functions.mailbox.inc.php` (`mailbox('add' / 'edit', 'mailbox_from_template')`,
the `authsource` handling of `mailbox('add' / 'edit', 'mailbox')`), `functions.oauth2.inc.php`,
`json_api.php` (`identity-provider`, `identity-provider-test`, `oauth2-client`),
`triggers.user.inc.php`, `sessions.inc.php`, `prerequisites.inc.php` (provider initialisation and
the OAuth2 server with its grant types), `vars.inc.php` (`REFRESH_TOKEN_LIFETIME`,
`ACCESS_TOKEN_LIFETIME`), `index.php`, `oauth/{authorize,token,profile}.php`,
`templates/admin/tab-config-identity-provider.twig`, `templates/admin/tab-config-oauth2.twig`,
`templates/edit/oauth2client.twig`, `templates/modals/admin.twig`, `templates/user_index.twig`,
`js/site/admin.js`, `init_db.inc.php` (tables `identity_provider`, `oauth_clients`,
`oauth_access_tokens`, `oauth_authorization_codes`, `oauth_refresh_tokens`, and the `mailbox`
column `authsource`), `data/conf/phpfpm/crons/{keycloak,ldap}-sync.php`,
`data/conf/dovecot/auth/mailcowauth.php`, `docker-compose.yml` (the ofelia jobs), the Keycloak
provider library (endpoints and default scopes by version), the LDAP library (SSL port selection)
and the OAuth2 server library (redirect URI matching).

The reference is upstream mailcow-dockerized `2026-09a`. `source_code/` points to the FeldHost fork
(`~/Projects/mailhosting`, branch `feldhost-202609a`), so the files above were diffed against that
upstream tag. `identity_provider()`, the sign-in functions, `functions.oauth2.inc.php`,
`json_api.php`, the synchronisation jobs, the OAuth2 endpoints, the identity provider and OAuth2
screens, `admin.js` and the tables are identical. The fork differs in two ways that touch this
feature:

1. The recent-login entry of a UI password sign-in carries the service `WEBUI` instead of
   `MAILCOWUI` (`triggers.user.inc.php`). Feature 001 makes the label a setting (FR-027 there), and
   the entries of FR-024 and FR-031 use it.
2. Branding-only texts: the language strings for the mailpassword flow, the Keycloak service account
   hint and the "force single sign-on" option name "FeldHost™ Mailhosting" instead of "mailcow", and
   the mailbox edit page labels the `mailcow` authentication source the same way. They have no
   effect on behaviour.

| Behaviour | Legacy | This feature |
|---|---|---|
| Who may manage the provider and OAuth2 clients | Administrators only | Same (FR-001, FR-003) |
| API keys | The single `/api/v1` read-write key may read and change everything | Administrator keys act as their owner; read-only keys only read (FR-002) |
| Reading the configuration | `get/identity-provider` returns every stored value, including the client secret, the bind password and the cached Keycloak service token; the admin page renders the secrets into its form | Secrets never returned; only whether each is set (FR-006, FR-014). **Deviation** (security) |
| UI log entries of a save | Client secret and service token masked, bind password written in clear; an access-denied save logs every value unmasked | No secret in any log or audit record (FR-052, FR-055). **Deviation** (security) |
| Save validation | Only presence of the required settings; empty values pass; URLs, hosts, scopes, version, filter and templates unchecked; a mapping is silently dropped when its two lists differ in length | Every setting validated, mapped templates must exist, duplicates refused (FR-010 to FR-013). **Deviation** |
| Defaults and normalisation | Trailing slash of the Keycloak URL removed, spaces removed from LDAP hosts, username and attribute fields lower-cased, StartTLS off when SSL is on, port 389, username field `mail`, sync interval 15 with values below 1 raised to 1, default OIDC scopes, automatic creation shown as on when unset | Same, except that a sync interval outside 1 to 10,080 is refused (FR-007, FR-010 to FR-012) |
| Secrets on save | The form always re-sends the stored secret | Write-only, kept when omitted, required again when the receiving address changes (FR-014). **Deviation** |
| Switching the provider type | Refused while mailboxes use another external source; the old type's settings stay stored and are returned by reads | Same refusal; the old type's settings are removed (FR-015). **Deviation** |
| Side effects of a save | Upserts the settings and clears the cached service token | Same (FR-016) |
| Deletion | Refused while any mailbox has an external source; removes every setting | Same; a missing configuration is "not found" (FR-004, FR-017) |
| Connection test | Unsaved form values; Keycloak service token request; generic OIDC `OPTIONS` request to the token endpoint expecting success; LDAP connect and bind; 7 s; result success or failure only | Same for Keycloak and LDAP; generic OIDC checks that the endpoint answers and accepts the credentials; stored secrets reused only under FR-014; failure categories; audited (FR-019). **Deviation** |
| Single sign-on start | `?iam_sso=1` builds the authorization URL; state kept in the session without expiry; return URL picked from the extra URLs by host name | Authorization address returned to the client; single-use state expiring after 10 minutes; PKCE; return address of the new web app picked by host name (FR-021). **Deviation** |
| Single sign-on completion | State compared with the session; code exchanged; user details from the user info endpoint; e-mail required; mailbox must have an OIDC source (Keycloak or generic) and be active with an active domain | Same; the source must be the configured type, which is the only OIDC type that can exist (FR-022, FR-023) |
| Second factor and forced actions after single sign-on | Neither | Same (FR-024) |
| Recent-login entry after single sign-on | None | Recorded (FR-024). **Deviation**, as for passkeys in feature 001 |
| Update from the template on sign-in | When a mapping matches and the template or the name changed; failures ignored | Same; failures audited (FR-025) |
| Provider token renewal | On every page load of the user area; failure ends the session; the renewed e-mail address replaces the session's user | On the first request more than 5 minutes after the last renewal; failure ends the session; another e-mail address ends the session (FR-026). **Deviation** |
| Sign-out | Ends the mailcow session only | Same (FR-027) |
| LDAP password sign-in | Look-up by username field and filter, bind as the entry, SSL on port 389 uses 636; then second factors, re-activation, forced setup, recent-login entry | Same (FR-029, FR-031) |
| Keycloak mailpassword flow | User looked up by e-mail with the service token; password checked against `mailcow_password`; the TLS setting is not applied to these calls | Same; the TLS setting applies to every call (FR-029, FR-056). **Deviation** |
| Provider unreachable or misconfigured during a password sign-in | Reported to fail2ban as an invalid password, with the progressive delay | Temporary failure, no fail2ban report, no throttling count (FR-033). **Deviation** |
| Correct password but no mailbox can be created | Reported to fail2ban as an invalid password | Generic failure, no fail2ban report, audited reason (FR-033). **Deviation** |
| Automatic creation | Mapped template or default template; mailbox created from the template with the provider's source and no password; domain and ACL checks bypassed, domain limits applied; name from OIDC `name`, LDAP `displayname`, Keycloak mailpassword flow `name` (never provided) | Same; the Keycloak name comes from first and last name (FR-034 to FR-038). **Deviation** (bug fix) |
| Forced second-factor setup on the first password sign-in | Not checked | Applied as on any later sign-in (FR-039). **Deviation** |
| Synchronisation jobs | Run by ofelia every minute in php-fpm; read the configuration at start; interval from the end of the last run; shared lock | Unchanged; they read configurations saved through this API (FR-018) |
| OAuth2 client creation | 12-hex client ID and 24-hex secret; scope `profile`; redirect URI unchecked, even empty; an ID collision refuses the creation | Same formats; redirect URIs validated; secret returned once; a collision generates a new ID (FR-043, FR-044). **Deviation** |
| OAuth2 client reads | Secret returned by every read and shown in the table and on the edit page | Secret only at creation and regeneration (FR-042). **Deviation** (security) |
| Redirect URI change | Must not be empty; any text | List of URIs, validated (FR-044, FR-045). **Deviation** |
| Secret regeneration | New secret; tokens kept | Same; the secret is returned once (FR-046) |
| Token revocation | Access and refresh tokens removed | Authorization codes removed as well (FR-047). **Deviation** |
| Client deletion | Client row only; its access tokens stay valid for up to 24 hours | Client, tokens and codes removed (FR-048). **Deviation** (security) |
| Mass actions | Remove, revoke tokens and renew secrets of the selected clients in one request, without a result per client | Bulk deletion and bulk token revocation with one result and one audit record per client; secret regeneration only per client (FR-060, FR-061). **Deviation** |
| Synchronisation status | Not shown; the jobs log to the scheduled-job log | Derived read-only from that log (FR-062). **New** |
| Provider error on the return to the UI | Ignored; the state stays in the session | Reported by the client, state consumed, audited with the provider's error code (FR-063). **Deviation** |
| Concurrent edits | Last write wins | Version tags (FR-050, FR-051). **Deviation** |
| UI log entries | Written to the `logs` table | Written to this API's audit log, as in feature 001. **Deviation** |
| "Force single sign-on" | Hides the login form and the passkey button of the mailbox-user login page; not enforced | Presentation option, documented, not enforced; clients keep passkey sign-in (FR-020) |
| OAuth2 endpoints and consent page | php-fpm | Unchanged (FR-049) |
| Renewal answer without a new refresh token | The session stops renewing | The old refresh token is kept and renewal continues (FR-026). **Deviation** (added by the plan) |
| HTTP redirects from the provider | Followed (library default, up to 5) | Not followed; a redirect is an unexpected response (FR-019, FR-022). **Deviation** (added by the plan) |
| LDAP time-out | Library default of 5 s | 7 s, as every provider call (FR-056). **Deviation** (added by the plan) |
| IPv6 LDAP hosts | Connection URIs without brackets, which fail | Bracketed in connection URIs; stored unchanged (FR-012). **Deviation** (added by the plan) |

### Key Entities *(include if feature involves data)*

- **Identity provider configuration** (existing mailcow data, stored as named settings): the type
  (Keycloak, generic OIDC or LDAP), the connection settings of that type, the attribute mapping, the
  default template, automatic creation, synchronisation settings, the TLS setting, the secrets and
  the cached Keycloak service token. At most one exists.
- **Mapping entry**: an attribute value and the name of a mailbox template (feature 003). Part of
  the configuration.
- **Secret**: the client secret of a Keycloak or generic OIDC provider, or the LDAP bind password.
  Write-only.
- **Cached Keycloak service token** (existing mailcow data): a token the legacy components and the
  synchronisation jobs reuse. Never exposed, cleared on every save.
- **Pending single sign-on** (owned by this API, short-lived): the state, the proof key, the return
  address and the expiry of one single sign-on, bound to a browser session.
- **Provider link of a session** (owned by this API, part of a feature 001 session): the provider's
  tokens and the time of the last renewal of a single sign-on session.
- **External authentication source** (existing mailbox data, managed in feature 003): marks a
  mailbox as authenticating against the provider type. Mailboxes with it block type changes and
  deletion.
- **Template marker** (existing mailbox data): records which template values and display name were
  last applied from the provider.
- **OAuth2 client** (existing mailcow data): numeric identifier, client ID, secret, redirect URIs
  and scope.
- **OAuth2 tokens and authorization codes** (existing mailcow data, issued by the legacy OAuth2
  endpoints): counted and removed per client by this feature.
- **Connection test result**: success, or a failure category with the provider's status code.
- **Synchronisation status** (derived, read-only): the last completed run, a later run-level
  failure, whether a run is in progress or overdue, and the next expected run, read from the legacy
  jobs' scheduled-job log.
- **Bulk action result** (feature-independent, `docs/conventions.md`): one outcome per OAuth2 client
  of a bulk deletion or token revocation.
- **Audit record** (owned by this API, defined in feature 001): extended with the target types,
  sign-in methods and failure reasons of this feature.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For 100% of the users in the parity test set (LDAP and Keycloak mailpassword
  mailboxes, Keycloak and generic OIDC single sign-on users, each with an existing mailbox and
  without one), a sign-in through the API has the same outcome as a sign-in through the legacy UI,
  and a mailbox created on first sign-in has the same stored data as one created by legacy, apart
  from timestamps and the stated deviations.
- **SC-002**: A configuration saved through the API is shown with the same values by the legacy UI,
  apart from the secrets. A mail client sign-in of an external mailbox succeeds with it at the next
  attempt, and the next synchronisation run uses it no later than 1 minute after its interval has
  elapsed.
- **SC-003**: In 95% of single sign-ons, the API's own steps add less than 2 seconds to the time the
  user spends on the provider's pages.
- **SC-004**: A scan of all responses, service logs and audit records after the full test run finds
  no provider secret, bind password, service token, provider token, authorization code or user
  password, and no OAuth2 client secret outside the creation and regeneration responses.
- **SC-005**: 100% of attempts by domain administrators and mailbox users to use any operation of
  this feature are refused, and 100% of write attempts with read-only keys are refused.
- **SC-006**: An administrator can configure an LDAP provider, test it and save it in the new web
  app in under 5 minutes.
- **SC-007**: An OAuth2 client created through the API completes an authorization-code flow with the
  unchanged legacy endpoints on the first attempt. After a revocation or deletion, 100% of the
  client's previously issued tokens are refused at their next use.
- **SC-008**: An unmodified mailcow fail2ban configuration bans an address after the same number of
  failed LDAP password sign-ins through the API as through the legacy UI, and never bans an address
  because the provider was unreachable.
- **SC-009**: A user disabled at the provider loses their single sign-on session at their first
  request more than 5 minutes after their last renewal, in 100% of tests.
- **SC-010**: 100% of changes sent with an outdated version tag are refused, every request in
  FR-052 and FR-053 produces exactly one audit record, and a bulk action produces exactly one audit
  record per distinct client.

## Assumptions

- The operator registers the new web app's sign-in return addresses (FR-006) at the provider, in
  addition to the legacy return URL. Until then, single sign-on works in the legacy UI only.
- The provider is trusted to assert users' e-mail addresses. As in legacy, a "verified" flag in the
  user's details is not required, because Keycloak does not set it for users an administrator
  creates.
- Secrets stay stored as legacy stores them, readable by the legacy components that need them (the
  mail clients' sign-in check, the OAuth2 token endpoint and the synchronisation jobs). This feature
  controls their exposure through the API, not how they are stored.
- The synchronisation jobs keep running in the legacy PHP container under mailcow's scheduler. Their
  log is read through feature 011. The API has no scheduler of its own for them.
- The OAuth2 authorize, token and profile endpoints and the consent page stay in the legacy PHP
  container (constitution V). A user authorising an application signs in on the legacy login page.
  Taking these endpoints over belongs to feature 014.
- Mailbox templates and the mailbox authentication source are managed in feature 003. Feature 003
  does not check the identity provider mapping when a template is renamed or deleted, so the mapping
  can point to a missing template (see Edge Cases).
- "Force single sign-on" stays a presentation option, as in legacy (FR-020). Making the API refuse
  password and passkey sign-in of mailbox users when it is set would be a product decision for the
  owner, and can be added later without breaking clients.
- Password change, recovery e-mail and second-factor enrolment of external mailboxes belong to
  feature 005. The "force single sign-on" option is edited in feature 013.
- These defaults can be changed by the operator: the renewal interval of single sign-on sessions
  (5 minutes), the lifetime of a pending single sign-on (10 minutes), the time-out of provider calls
  (7 seconds), and the OAuth2 client list page size (25, maximum 1,000).
- **Out of scope**:
  - single sign-on and provider password sign-in for administrators and domain administrators, which
    legacy does not offer;
  - signing out at the provider (single logout);
  - running, triggering or scheduling the synchronisation jobs;
  - more than one provider at a time, and group or role mapping beyond the template attribute;
  - the OAuth2 provider endpoints and their settings (token lifetimes, grant types, scopes);
  - batch operations other than the OAuth2 bulk actions of FR-060.
- **Dependencies**:
  - feature 001, for sign-in, sessions, roles, API keys, throttling, fail2ban reporting, the public
    configuration, errors and the audit log;
  - feature 003, for mailbox creation and change, mailbox templates and the authentication source,
    and feature 002, for domains and their limits;
  - the upstream mailcow database schema of the supported release;
  - the configured Keycloak, OIDC or LDAP provider;
  - the legacy synchronisation jobs, the mail clients' sign-in check and the OAuth2 endpoints in the
    legacy PHP container.
