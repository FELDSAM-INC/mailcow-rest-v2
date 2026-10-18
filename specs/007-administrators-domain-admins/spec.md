# Feature Specification: Administrators and Domain Administrators

**Feature Branch**: `main` (no feature branches, constitution 1.1.0)

**Created**: 2026-10-18

**Status**: Draft

**Input**: Feature description built from `docs/roadmap.md` (feature 007 and allocation row 007):

> Administrators and domain administrators for mailcow REST API v2: administrators list, search,
> inspect, create, change, rename, deactivate and delete the administrator accounts and the domain
> administrator accounts of an existing mailcow-dockerized server. For domain administrators this
> includes the domains each one manages and their permission flags (`da_acl`). Administrators can
> force a password change or second-factor setup at the next sign-in, and suspend an account's
> second factors until its next sign-in. The last active administrator is protected. Administrators
> issue short-lived single sign-on tokens with which a domain administrator signs in without a
> password, as billing and reseller panels do today. Administrators, and domain administrators with
> the `login_as` permission, sign in as another account (login-as, "dual login") and return to their
> own account, and they open a mailbox's webmail on the user's behalf when the operator allows it.
>
> Allocated legacy surface: administrator accounts (`admin`), domain administrators
> (`domain-admin`), `edit/da-acl`, login-as / dual login, domain-admin SSO tokens
> (`sso/domain-admin`). The legacy "System › Administrators" and "Domain administrators" screens,
> their edit pages, their mass actions and the "Login" and "SOGo" buttons need list filters, search
> and the fields they show today.
>
> Constraints: every legacy side effect is reproduced. The legacy UI and `/api/v1` keep working on
> the same data. The feature builds on feature 001's identity, roles, sessions, API keys, error
> conventions and audit log, on feature 002's domains and on feature 003's password rules.
> Impersonation must create a distinguishable identity whose impersonator is recorded in identity
> responses and audit records, ending it must return to the original account, and the effect of
> deactivating or deleting an account on its API keys and sessions must be defined.

## Clarifications

### Session 2026-10-18

- Q: May API keys create, change or delete administrator accounts, as `/api/v1` keys can? → A: No.
  Administrator accounts are written only in a signed-in administrator session, and keys owned by
  administrators may list and inspect them. Domain administrators, their permission flags and single
  sign-on tokens may be managed with an administrator's read-write key (FR-004, FR-005). (agent
  decision: feature 001 already decided that keys cannot mint keys, so that a leaked key cannot
  create lasting administrator credentials, and an administrator account with a password is such a
  credential. Billing systems need domain administrators and tokens, which stay available, and
  `/api/v1` keeps working for scripts that rely on it.)
- Q: Who may impersonate whom, and which targets are eligible? → A: Administrators impersonate any
  mailbox user and any domain administrator. Domain administrators with `login_as` impersonate the
  mailbox users of domains assigned to them through an active assignment. Nobody impersonates an
  administrator. Inactive targets and mailboxes in inactive domains are allowed, resources never.
  Impersonations cannot be nested and cannot be started with an API key or from a restricted session
  (FR-042 to FR-044). (agent decision: eligibility is legacy parity, from `triggers.global.inc.php`,
  `mailbox_details` and `domain_admin('details')`. API keys have no session to switch, and legacy
  gives automation single sign-on tokens instead.)
- Q: Which permission flags apply while impersonating, the target's own or the impersonator's? → A:
  The legacy rule: an administrator gets every flag of the target's role, and a domain administrator
  gets every mailbox user flag except the five that overlap with its own permissions (`syncjobs`,
  `quarantine`, `app_passwds`, `pushover`, `spam_policy`), which take its own values. The identity
  reports these effective flags (FR-046, FR-048). (agent decision: legacy parity under constitution
  IV. `acl('to_session')` computes the flags this way on purpose, so that resellers act for users
  within their own permissions.)
- Q: How does an impersonation end, and what does signing out do during one? → A: An explicit end
  operation returns to the impersonator and renews the session identifier and the anti-forgery
  token. Signing out ends the whole session. An impersonation also ends automatically when the target
  is deleted or renamed, leaves the impersonator's scope, or the domain administrator loses
  `login_as`; the request that detects it is refused with "impersonation ended" and not carried out.
  Deactivating the target does not end it (FR-050 to FR-052). (agent decision: legacy turns sign-out
  into "return", which surprises users on shared computers, and never re-checks an impersonation, so
  a request can run for a deleted target or after the permission was removed. Refusing the
  detecting request keeps a write meant for the target from running as the impersonator.)
- Q: May an impersonator manage the target's sign-in credentials? → A: No. Changing the password,
  passkeys, second factors and recovery e-mail is refused during an impersonation. App passwords
  follow the effective flags (FR-049). (agent decision: legacy allows it, which lets an impersonator
  plant credentials that keep working after `login_as` is revoked. OWASP ASVS level 2 and
  constitution VI. App passwords stay, because legacy grants them to impersonators deliberately
  through `app_passwds`.)
- Q: Where are single sign-on tokens accepted, and what does a token sign-in skip? → A: Tokens are
  kept in the legacy token store, so each token works once, in either the legacy domain
  administrator login or this API, and tokens issued through `/api/v1` work here. A token sign-in
  needs no second factor, as in legacy, but forced actions restrict the session, and a token for an
  inactive account is refused at issue (FR-057 to FR-063). (agent decision: billing panels that
  migrate to the new API keep their redirect target. Skipping the second factor is legacy parity.
  Feature 001 applies forced actions to every sign-in method (FR-014 there). Refusing an inactive
  target gives the panel a clear error instead of a token that can never work.)
- Q: What happens to an account's sessions, API keys, preferences and other data when it is
  deactivated or deleted? → A: Deactivation ends its sessions at their next request and suspends its
  keys until reactivation, as feature 001 specifies. Deletion revokes its keys permanently, ends its
  sessions and the impersonations of it, removes its preferences, second factors, passkeys and unused
  tokens, and keeps the audit records. No session, key, impersonation or token may ever act for a
  later account with the same username (FR-034 to FR-036). (agent decision: feature 001 only says a
  deleted owner's keys stay unusable. Usernames can be reused, so a key bound to a username would
  come back to life for a new account, which explicit revocation prevents.)
- Q: What follows a renamed account? → A: Its second factors, passkeys, assignments with their
  creation times, permission record, API keys and preferences, all together or not at all. Unused
  tokens for the old name are removed and the account's other sessions end; a self-renaming
  administrator's session continues (FR-025). (agent decision: legacy loses the passkeys, and with
  "disable TFA" also the second factors, and a cross-role name collision leaves partial changes.
  Feature 003's rule applies: what legacy deletes with an account moves with it.)
- Q: How is the last active administrator protected? → A: No change and no deletion may leave the
  server without an active administrator, checked atomically with the change. Nobody can delete their
  own account. Edits that do not reduce the number of active administrators are never refused
  (FR-028, FR-029). (agent decision: legacy refuses unrelated edits of inactive administrators, lets
  an `/api/v1` key delete every administrator, and has no protection against concurrent requests, so
  two administrators can lock the server out.)
- Q: Does the API offer batch operations for the legacy mass actions (activate, deactivate, disable
  TFA, remove)? → A: No. The web app sends one request per account (FR-073). (agent decision: as in
  feature 002. Account lists are short, each account gets its own outcome, version check and audit
  record, and the last-administrator rule stays simple per request. A batch operation can be added
  later without breaking clients.)
- Q: Is webmail login on behalf of a user part of this feature, and when is it allowed? → A: Yes.
  With the operator setting `ALLOW_ADMIN_EMAIL_LOGIN` on (default off), administrators, and domain
  administrators with `login_as`, obtain a single-use webmail sign-in for an eligible mailbox in
  their scope, also from inside an impersonation. It uses feature 005's webmail single sign-on
  hand-off and writes the legacy `SSO` recent-login entry (FR-054 to FR-056). (agent decision:
  feature 003 assigns "webmail login on behalf of a user" to this feature, the roadmap assigns the
  webmail single sign-on mechanism to feature 005, and the gating is legacy `sogo-auth.php`.)

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Manage domain administrators (Priority: P1)

An administrator, or a billing system with an administrator's read-write key, gives a reseller a
domain administrator account. They choose a username and a password, pick the domains the reseller
manages, and can set the permission flags and the forced password change in the same request. The
list shows what the legacy "Domain administrators" screen shows today: the username, the managed
domains, whether second factors are active, and the status. The administrator searches by username
or domain, filters by status, domain and second-factor status, sorts and pages through the list.
They change the managed domains, the password, the status, the forced password change and forced
second-factor setup, and rename or delete the account. When the reseller has lost their
authenticator, the administrator suspends the account's second factors until its next sign-in.

**Why this priority**: Domain administrators are how mailcow is resold and delegated. Billing
systems create and suspend them automatically, and every other domain-administrator capability
depends on the account existing.

**Independent Test**: Create domain administrators through the API with one and several domains,
with and without initial flags and forced flags. List, search, filter, edit, rename, suspend second
factors and delete them. Compare the stored rows with the rows the legacy UI produces for the same
input, and sign in with each account in the legacy UI and through feature 001.

**Acceptance Scenarios**:

1. **Given** domains `a.example` and `b.example`, **When** an administrator creates the domain
   administrator `reseller1` with both domains and a valid password, **Then** the account exists
   with the domain administrator role, it has an active assignment to each domain, its permission
   flags have the legacy defaults, the response points to the new account and contains no password,
   and `reseller1` can sign in.
2. **Given** an existing administrator or domain administrator named `ops`, **When** an
   administrator creates a domain administrator `OPS`, **Then** the request is refused as a conflict
   and nothing changes.
3. **Given** a creation request that names `a.example` and the unknown domain `nope.example`,
   **When** it is submitted, **Then** it is refused with a validation error naming `nope.example`,
   and no account, assignment or permission record is created.
4. **Given** `reseller1` managing `a.example` and `b.example`, **When** an administrator sets its
   domains to `b.example` and `c.example`, **Then** it manages exactly those two domains, the
   assignment to `b.example` keeps its creation time, and the next request `reseller1` makes sees the
   new scope.
5. **Given** `reseller1`, **When** an administrator deactivates it, **Then** the account and all of
   its assignments are inactive, its open sessions end at their next request, and its API keys are
   refused until it is activated again.
6. **Given** `reseller1` with an active TOTP and an active WebAuthn second factor, **When** an
   administrator suspends its second factors, **Then** both are inactive, `reseller1` can sign in
   with the password alone, and both are active again after that sign-in.
7. **Given** `reseller1` with two passkeys, one TOTP entry, two API keys and saved preferences,
   **When** an administrator renames it to `reseller-one`, **Then** the passkeys, the TOTP entry,
   the assignments, the permission flags, the API keys and the preferences belong to `reseller-one`.
8. **Given** `reseller1` with an API key, **When** an administrator deletes the account and later
   creates a new domain administrator also named `reseller1`, **Then** the old key stays revoked, and
   the new account has no passkeys, second factors, preferences or keys of the old one.
9. **Given** 300 domain administrators, **When** an administrator lists them filtered by domain
   `a.example`, **Then** exactly the domain administrators assigned to `a.example` are returned, with
   their domains, in pages of at most the requested size, with the total.
10. **Given** a domain administrator or a mailbox user, **When** they list, create, change or delete
    domain administrators, **Then** the request is refused as forbidden.

---

### User Story 2 - Set a domain administrator's permissions (Priority: P1)

An administrator decides what a reseller may do in the domains they manage: log in as mailbox
users, manage sync jobs, quarantine, spam policies, rate limits, BCC maps, filters, app passwords,
push notifications, protocol access, allowed SMTP hosts, relayhosts, alias domains, external alias
destinations, external sender addresses, unlimited quotas, webmail access and the domain
description. The administrator sees each of the 19 legacy flags with its current value and changes
only the ones they want. The reseller cannot change any of their own flags.

**Why this priority**: The flags are the only way to limit what a domain administrator can do, and
every later feature enforces them. Creating accounts without being able to restrict them is not
acceptable for resellers.

**Independent Test**: Change each flag through the API for one domain administrator, then read the
flags back, compare them with the database and with the legacy edit page, and confirm in the legacy
UI and through the API (for example with login-as) that the change applies at the next request.

**Acceptance Scenarios**:

1. **Given** a new domain administrator, **When** an administrator reads its permissions, **Then**
   it receives all 19 flags with the legacy defaults (for example "login as mailbox user" on,
   "unlimited quota" off).
2. **Given** a domain administrator with "login as mailbox user" on, **When** an administrator turns
   only that flag off, **Then** that flag is off, every other flag is unchanged, and the domain
   administrator's next login-as attempt is refused as forbidden.
3. **Given** a request that names a flag that does not exist, **When** it is submitted, **Then** it
   is refused with a validation error naming the flag, and nothing changes.
4. **Given** a domain administrator, **When** they try to change their own flags, **Then** the
   request is refused as forbidden.
5. **Given** two administrators who read the same domain administrator, **When** the first saves a
   flag change and the second saves with the version they read, **Then** the second save is refused
   as outdated.

---

### User Story 3 - Log in as another account and return (Priority: P1)

A support administrator helping a customer signs in as the customer's mailbox user, or as a
reseller's domain administrator, sees exactly what that account sees and acts on its behalf. The
web app shows a clear banner with both names. When done, the administrator returns to their own
account with one action, without signing in again. A reseller whose "login as mailbox user"
permission is on does the same for the mailbox users of their own domains. Every change made while
impersonating is recorded with both the impersonator and the impersonated account.

**Why this priority**: Login-as is the daily support tool of administrators and resellers, and the
legacy "Login" buttons in the mailbox and domain administrator lists must keep working in the new
web app.

**Independent Test**: As an administrator, impersonate a mailbox user and a domain administrator;
as a domain administrator with and without the flag, impersonate mailbox users inside and outside
their domains. Check the identity, scope and permissions in each state, perform audited changes,
return, and inspect the audit log. Repeat with an API key and with a restricted session.

**Acceptance Scenarios**:

1. **Given** a signed-in administrator `alice`, **When** she starts impersonating the mailbox user
   `bob@a.example`, **Then** the session acts as `bob@a.example` with the mailbox user role, the
   identity names `alice` as the impersonator, and the session identifier and anti-forgery token are
   renewed.
2. **Given** `alice` impersonating `bob@a.example`, **When** she changes one of Bob's settings,
   **Then** the audit record names `alice` (administrator) as the actor and `bob@a.example` (mailbox
   user) as the impersonated account.
3. **Given** `alice` impersonating `bob@a.example`, **When** she ends the impersonation, **Then** the
   session acts as `alice` again with her original sign-in method, and the start and the end are
   both in the audit log.
4. **Given** the domain administrator `reseller1` with "login as mailbox user" on and managing
   `a.example`, **When** it impersonates `carol@a.example`, **Then** the impersonation starts. **When**
   it tries `dave@b.example` in a domain it does not manage, **Then** the response is "not found",
   exactly as for an address that does not exist.
5. **Given** `reseller1` with "login as mailbox user" off, **When** it tries to impersonate any
   mailbox user, **Then** the request is refused as forbidden.
6. **Given** `alice` impersonating `reseller1`, **When** she tries to start a second impersonation,
   **Then** the request is refused, and she must end the current one first.
7. **Given** a client authenticated with any API key, **When** it tries to start an impersonation,
   **Then** the request is refused as forbidden.
8. **Given** `alice` impersonating `bob@a.example`, **When** another administrator deletes Bob's
   mailbox and Alice's tab sends its next request, **Then** that request is refused with an
   "impersonation ended" error and not carried out, the session acts as `alice` again, and the
   automatic end is audited.
9. **Given** `alice` impersonating `bob@a.example`, **When** she tries to change Bob's password,
   register a passkey or set up a second factor for him, **Then** the request is refused with a
   "not allowed while impersonating" error.
10. **Given** `alice` impersonating `bob@a.example`, **When** she signs out, **Then** the whole
    session ends, and she is no longer signed in as anyone.

---

### User Story 4 - Manage administrator accounts (Priority: P2)

An administrator lists the server's administrators with their status and second-factor status,
adds a colleague, changes a colleague's password, renames or deactivates an account, forces a
password change or second-factor setup at the next sign-in, suspends a locked-out colleague's
second factors, and removes administrators who left. The server can never be left without an active
administrator, and nobody can delete their own account.

**Why this priority**: Administrator accounts change rarely, and the legacy UI can manage them until
then, but the new web app needs the screen to replace legacy completely.

**Independent Test**: Create, list, edit, rename, deactivate, suspend second factors of and delete
administrators in a signed-in administrator session. Try to deactivate or delete the last active
administrator, alone and with concurrent requests, try to delete the own account, and try every
operation with API keys and with other roles.

**Acceptance Scenarios**:

1. **Given** a signed-in administrator, **When** they create the administrator `bob` with a valid
   password, **Then** `bob` exists as an active administrator without forced actions, and can sign in.
2. **Given** a password that the server's password policy refuses, **When** it is used to create an
   administrator, **Then** the request is refused with a validation error naming each unmet rule.
3. **Given** two administrators of which only `alice` is active, **When** `alice` deactivates
   herself, **Then** the request is refused with a "last active administrator" error and nothing
   changes.
4. **Given** two active administrators, **When** each concurrently deactivates or deletes the other,
   **Then** at most one request succeeds and at least one administrator remains active.
5. **Given** a signed-in administrator, **When** they try to delete their own account, **Then** the
   request is refused with a "cannot delete own account" error.
6. **Given** an administrator `bob` who is inactive while `alice` is the only active one, **When**
   `alice` changes `bob`'s password without activating him, **Then** the change is saved.
7. **Given** an administrator with forced password change set, **When** they next sign in, **Then**
   the session is restricted as feature 001 specifies.
8. **Given** a read-write API key owned by an administrator, **When** a client uses it to create,
   change or delete an administrator account, **Then** the request is refused as forbidden. **When**
   it lists administrators, **Then** the list is returned.

---

### User Story 5 - Sign a domain administrator in with a single sign-on token (Priority: P2)

A reseller panel signs its customer into mailcow without asking for a password. The panel, holding
an administrator's read-write key, asks the API for a token for the customer's domain administrator
account. It redirects the customer's browser either to the legacy domain administrator login with
the token, as today, or to the new web app, which exchanges the token for a session. The token
works once and only within 30 seconds.

**Why this priority**: Billing and hosting panels use this today through `/api/v1`, and they need it
to migrate to the new API, but password sign-in works without it.

**Independent Test**: Issue tokens through the API and through `/api/v1`. Consume each in the new
API and in the legacy UI, after the lifetime, twice, for an inactive account, and with a forced
password change set, and check each outcome and audit record.

**Acceptance Scenarios**:

1. **Given** an active domain administrator `reseller1`, **When** a billing system with an
   administrator's read-write key issues a token for it, **Then** the response contains the token
   and its expiry 30 seconds later.
2. **Given** a fresh token, **When** the new web app submits it, **Then** a session is established
   for `reseller1` with the domain administrator role and the sign-in method "single sign-on token",
   without a password and without a second factor.
3. **Given** a token issued through this API, **When** the browser opens the legacy domain
   administrator login with it, **Then** the legacy UI signs `reseller1` in, and the token no longer
   works in either UI.
4. **Given** a token older than 30 seconds, or one that was already used, **When** it is submitted,
   **Then** sign-in fails with feature 001's generic sign-in error, and the failure is audited.
5. **Given** an inactive domain administrator, **When** a token is requested for it, **Then** the
   request is refused, and no token is issued.
6. **Given** `reseller1` flagged for a forced password change, **When** it signs in with a token,
   **Then** the session is restricted as after a password sign-in.
7. **Given** a domain administrator, a read-only key, or a key owned by a domain administrator,
   **When** it requests a token, **Then** the request is refused.

---

### User Story 6 - Open a mailbox's webmail on the user's behalf (Priority: P3)

On a server whose operator allows administrators to open users' webmail, an administrator, or a
reseller with "login as mailbox user", opens the webmail of a mailbox in their scope directly from
the mailbox list, or from inside an impersonation of that mailbox user, to check what the user sees.

**Why this priority**: The operator setting is off by default, and only some installations use it.

**Independent Test**: With the operator setting on and off, open the webmail of mailboxes inside and
outside the caller's scope as an administrator, as domain administrators with and without the flag,
and while impersonating, and check each outcome, the recent-login entry and the audit record.

**Acceptance Scenarios**:

1. **Given** the operator setting on and a signed-in administrator, **When** they request webmail
   access for `bob@a.example`, **Then** they receive a single-use webmail sign-in for that mailbox,
   Bob's recent logins show a webmail single sign-on entry, and the request is audited.
2. **Given** the operator setting off, **When** any caller requests webmail access on behalf of a
   mailbox user, **Then** the request is refused with a "webmail login on behalf disabled" error.
3. **Given** the setting on and `reseller1` with "login as mailbox user" on, **When** it requests
   webmail access for a mailbox outside its domains, **Then** the response is "not found".
4. **Given** the setting on, **When** a client with an API key requests webmail access on behalf of
   a user, **Then** the request is refused as forbidden.

---

### User Story 7 - Trace account changes and impersonations (Priority: P3)

An administrator investigating an incident lists the audit log filtered by an administrator or
domain administrator account and sees every change to it: who made it, when, from where and what
changed, with passwords recorded only as "changed". They filter by impersonated account to see
everything done on a customer's behalf and by whom.

**Why this priority**: The constitution requires the records, but accounts can be managed without
reading them.

**Independent Test**: Perform every kind of change and impersonation of this feature, including
refused ones, and list the audit log with each filter. Scan all records and logs for passwords and
tokens.

**Acceptance Scenarios**:

1. **Given** an administrator who changed `reseller1`'s domains and password, **When** another
   administrator lists audit records for `reseller1`, **Then** the entry shows the domains added and
   removed and that the password changed, without the password.
2. **Given** impersonations of `bob@a.example` by `alice` and by `reseller1`, **When** an
   administrator filters by impersonated account `bob@a.example`, **Then** both impersonations'
   starts, ends and changes appear with their impersonators.
3. **Given** an issued single sign-on token, **When** the audit log is listed, **Then** the issue
   appears with actor and target, and the token itself appears nowhere.

---

### Edge Cases

- **Username collisions across roles**: administrator and domain administrator usernames share one
  namespace, compared case-insensitively. Mailbox addresses can never collide, because usernames
  cannot contain `@`.
- **Username made only of `.`, `_` or `-`, or the reserved name `api`** in any letter case: refused.
- **Rename to the current name in different letter case**: names are lower-cased, so it is no change.
- **Account addressed with the wrong role**, for example a domain administrator's username in an
  administrator operation: "not found".
- **Domain administrator without any assignment**, left by legacy or by a database change: listed,
  inspectable and deletable, shown with no domains. Any change of its domains must give it at least
  one. Legacy deletes such accounts when its PHP container starts.
- **Assignment to a name that is not an existing primary domain** (an alias domain name, a removed
  domain, or legacy's `ALL`): shown flagged as "not a domain", grants no access in this API, and is
  dropped the next time the assignment set is saved.
- **Domain administrator without a permission record** (legacy can leave one out): every flag reads
  as off. A change creates the record, with unsent flags off. A creation replaces an orphaned
  permission record left by legacy for the same username.
- **Inactive account**: can still be inspected, changed, impersonated (legacy behaviour) and
  deleted. It cannot sign in, and its keys are refused (feature 001).
- **Deactivating an already inactive administrator, or saving it unchanged**: never refused by the
  last-administrator rule, because the number of active administrators does not drop.
- **Self-deactivation** by an administrator while another active administrator exists: allowed, as
  in legacy. The response is returned, and the session ends at its next request.
- **Concurrent changes** that would each leave one active administrator: serialised, so that at
  least one stays active.
- **Account renamed or deleted while it has sessions open**: those sessions end at their next
  request. Only the session that renamed its own account continues, under the new name.
- **New account created with the username of a deleted or renamed one**: it inherits no session,
  key, impersonation, token, passkey, second factor, preference or permission of the old account.
- **Impersonation target that is inactive, or a mailbox in an inactive domain**: allowed, as in
  legacy, because the impersonator's authority counts. The target's own forced actions do not
  restrict the impersonation.
- **Impersonation target that is a resource** (location, thing or group): "not found", because
  resources are not mailboxes (feature 003).
- **Impersonating an administrator**: never possible. An administrator's username given as a domain
  administrator target is "not found".
- **Domain administrator impersonating another domain administrator**: refused as forbidden.
- **Impersonation that becomes invalid**: the target is deleted or renamed, leaves the
  impersonator's scope, or the impersonating domain administrator loses "login as mailbox user". The
  next request is refused with "impersonation ended" and the session acts as the impersonator again.
  Deactivating the target does not end it.
- **Impersonator deactivated or deleted during an impersonation**: the whole session ends (feature
  001).
- **Several browser tabs sharing the session**: starting or ending an impersonation renews the
  anti-forgery token, so a request prepared in another tab under the previous identity is refused
  instead of running as the wrong account.
- **Ending an impersonation when none is active**: succeeds and changes nothing.
- **Restricted or pending session** (feature 001): cannot start an impersonation, issue a token or
  open webmail on behalf.
- **Single sign-on token for an account deleted, renamed or deactivated after issue**: sign-in fails
  with the generic error.
- **Same token submitted twice at the same moment**, to the new API and to the legacy UI: exactly
  one sign-in succeeds.
- **Token sign-in on a client that already holds a session**, including an impersonating one: the
  existing session is replaced, as in legacy.
- **Webmail on behalf for a mailbox that cannot use webmail** (inactive, login disabled, in an
  inactive domain, or webmail disabled on the server): refused with a validation error. (Legacy
  redirects to a webmail login that then fails.)
- **Legacy UI edits of the same account**: the legacy UI never sends a version, so it can still
  overwrite a change made through this API. Legacy edit forms send both forced flags, so a legacy
  save also rewrites them.
- **Legacy sessions of a deactivated or deleted account**: legacy never re-checks them, so they last
  until they expire. This API cannot end them (constitution V).
- **Audit record cannot be written**: the change it would record does not succeed.

## Requirements *(mandatory)*

### Functional Requirements

#### Access and scope

- **FR-001**: Only administrators MAY manage administrator accounts, domain administrator accounts,
  domain administrator assignments and permission flags, and single sign-on tokens. Domain
  administrators and mailbox users MUST be refused as forbidden on every such operation, including
  for their own account. Their own password and second factors are feature 005, and their own
  identity and passkeys are feature 001.
- **FR-002**: Roles, scope, account status and permission flags MUST be evaluated from the current
  mailcow data on every request, as in feature 001 (FR-029 there), for the caller and, during an
  impersonation, for both the impersonator and the target. A change made in either UI MUST apply
  to the next request.
- **FR-003**: An account that does not exist, an account addressed with the wrong role, and an
  impersonation or webmail target outside the caller's scope MUST all get the same "not found"
  response. An operation that the caller's role or flags never permit MUST be refused as forbidden.
- **FR-004**: A request authenticated by an API key MUST act with its owner's role, scope and flags
  (feature 001, FR-033). A read-only key MUST be refused on every state-changing operation with the
  "insufficient key scope" error of feature 001. A restricted session (feature 001, FR-014) MUST be
  refused. In addition:
  - administrator accounts MUST be created, changed, renamed, deleted and have their second factors
    suspended only in a signed-in administrator session, never with an API key. Keys owned by
    administrators MAY list and inspect administrators;
  - domain administrator management, permission flags and single sign-on token issuance MAY use a
    read-write key owned by an administrator;
  - impersonation and webmail login on behalf of a user MUST NOT be possible with any API key.
- **FR-005**: The operations MUST be authorised as follows:

  | Operation | Administrator | Domain administrator | Mailbox user |
  |---|---|---|---|
  | List and inspect administrators | Yes (session or key) | Forbidden | Forbidden |
  | Create, change, rename, delete administrators; suspend their second factors | Yes, signed-in session only (FR-004) | Forbidden | Forbidden |
  | List and inspect domain administrators | Yes | Forbidden | Forbidden |
  | Create, change, rename, delete domain administrators; suspend their second factors | Yes | Forbidden | Forbidden |
  | Read and change domain administrator permission flags | Yes | Forbidden (own flags readable through feature 001's identity) | Forbidden |
  | Issue a single sign-on token for a domain administrator | Yes | Forbidden | Forbidden |
  | Sign in with a single sign-on token (the token decides the account) | Any client, signed in or not (FR-060, FR-063) | Same | Same |
  | Impersonate a mailbox user | Any mailbox | With `login_as`: mailboxes of domains assigned through an active assignment | Forbidden |
  | Impersonate a domain administrator | Any domain administrator | Forbidden | Forbidden |
  | Impersonate an administrator | Never | Never | Never |
  | End an impersonation | The impersonating session | The impersonating session | The impersonating session |
  | Open webmail on behalf of a mailbox user (operator setting on) | Any mailbox | With `login_as`: mailboxes of own domains | Forbidden (own webmail is feature 005) |
  | Read the audit records of this feature | Yes | Forbidden | Forbidden |

#### Account representation

- **FR-006**: An administrator account MUST be represented with: its username; its status (active
  or inactive); its forced password change and forced second-factor setup flags; its second-factor
  status (FR-008); its number of active passkeys; its creation and last modification times; and its
  version tag (FR-064).
- **FR-007**: A domain administrator account MUST be represented with everything in FR-006 and:
  - its assignments, each with the domain's ASCII and readable name, the assignment's status, its
    creation time, and a marker when the assigned name is not an existing primary domain;
  - its 19 permission flags (FR-037).

  The account's status MUST be the account's own status. The assignments' statuses MUST be shown as
  stored, even if a legacy tool made them differ.
- **FR-008**: The second-factor status MUST be "none" when the account has no TOTP, WebAuthn or
  Yubico OTP entry, "active" when at least one of them is active, and "suspended" when entries exist
  but none is active. Deprecated U2F and HOTP entries MUST NOT count, because feature 001 never
  accepts them (FR-025 there).
- **FR-009**: Times MUST be RFC 3339 UTC timestamps. An account that was never modified MUST report
  no modification time. Representations, lists, errors and audit records MUST NOT contain a password
  or a password hash.

#### Listing and inspecting

- **FR-010**: Administrators MUST be able to list administrators and, separately, domain
  administrators, with pagination. The default page size MUST be 25 and the maximum 1,000. The
  response MUST include the total number of matching accounts, and each entry MUST carry the full
  representation, so that the list screen needs no request per row.
- **FR-011**: The administrator list MUST support a case-insensitive substring search on the
  username, filters by status, second-factor status, forced password change and forced
  second-factor setup, and sorting ascending or descending by username, status, creation time and
  modification time. The default MUST be by username, ascending. Ties MUST be broken by username.
- **FR-012**: The domain administrator list MUST support a case-insensitive substring search on the
  username and on the assigned domain names (ASCII and readable), the filters of FR-011, a filter by
  assigned domain (accounts with an assignment to any of the given domains), a filter by the value
  of one permission flag, and the sorts of FR-011 plus the number of assigned domains. It MUST list
  every domain administrator account, including accounts without any assignment.
- **FR-013**: Administrators MUST be able to inspect one account by its username, given in any
  letter case. The response MUST carry the account's version tag.

#### Usernames and passwords

- **FR-014**: A username MUST be trimmed and lower-cased, and MUST then:
  - consist only of the letters `a`–`z`, the digits `0`–`9`, `.`, `_` and `-`, with at least one
    letter or digit;
  - be 1 to 255 characters long;
  - not be the reserved name `api`.
- **FR-015**: A username MUST be unique among all administrator and domain administrator accounts.
  A duplicate, at creation or on a rename, MUST be refused as a conflict.
- **FR-016**: A password set by an administrator for an administrator or a domain administrator MUST
  follow feature 003's rules (FR-024 there): given once without a confirmation field, checked
  against the operator's password policy with each unmet rule named, stored in the configured hash
  scheme, and stored as given when it begins with a hash-scheme prefix legacy recognises. The
  current policy is readable through feature 003 (FR-063 there).

#### Creating accounts

- **FR-017**: Administrators MUST be able to create an administrator account with a username and a
  password, both required. The status MAY be given and defaults to active. The forced password
  change and forced second-factor setup flags MAY be given and default to off.
- **FR-018**: Administrators MUST be able to create a domain administrator account with a username,
  a password and at least one domain, all required, and the optional values of FR-017. Each domain
  MUST be normalised as feature 002 does (FR-006 there) and MUST be an existing primary domain. Alias
  domain names and unknown names MUST be refused with a validation error naming each entry.
  Duplicates MUST be merged. Initial permission flags MAY be given. Flags not given MUST get the
  legacy defaults of FR-037.
- **FR-019**: A successful domain administrator creation MUST store, together or not at all:
  - the account with the domain administrator role;
  - one assignment per domain, with the account's status;
  - the permission record, replacing any orphaned record with the same username.
- **FR-020**: A successful creation MUST respond as "created", with the address of the new account
  and its representation.

#### Changing accounts

- **FR-021**: A client MUST be able to change an account by sending only the values it changes.
  Values not sent MUST keep their current values. A value that is sent MUST be applied or refused by
  the rules, never ignored. Read-only values (creation and modification times, second-factor status,
  passkey count, and assignment details other than the domain) MUST be refused with a validation
  error naming them when sent with a different value, and accepted when sent unchanged. The response
  MUST contain the updated account and its new version tag.
- **FR-022**: Administrators MUST be able to change an administrator account's username (FR-025),
  password (FR-026), status (subject to FR-028) and the two forced flags.
- **FR-023**: Administrators MUST be able to change a domain administrator account's username,
  password, status, the two forced flags, its domains and its permission flags (FR-038):
  - a sent domain set MUST replace the whole set, MUST contain at least one domain, and MUST meet the
    rules of FR-018. Assignments to domains that stay MUST keep their creation time and status. New
    assignments MUST get the account's status;
  - a status change MUST apply to the account and to every one of its assignments, as in legacy.
- **FR-024**: The forced flags MUST be stored where legacy stores them, in the format legacy reads,
  and other stored account attributes MUST be preserved. They take effect at the account's next
  sign-in in either UI, as feature 001 specifies (FR-014 there). Completing a forced password change
  belongs to feature 005.
- **FR-025**: A rename MUST meet FR-014 and FR-015. It MUST carry over to the new username, together
  or not at all:
  - the account and its forced flags;
  - its TOTP, WebAuthn, Yubico OTP and passkey entries, whether active or not;
  - for a domain administrator, its assignments (with their creation times) and its permission
    record;
  - its API keys (feature 001) and its preferences.

  It MUST remove unused single sign-on tokens issued for the old username. It MUST end the renamed
  account's sessions and impersonations of it (FR-052), except the session of an administrator who
  renamed their own account, which continues under the new username. Audit records MUST keep the
  username that was current when they were written.
- **FR-026**: A changed password MUST be stored as in FR-016. It MUST NOT change the forced flags,
  the account's sessions or its API keys, as in legacy.
- **FR-027**: Administrators MUST be able to suspend the second factors of an administrator or a
  domain administrator account: every TOTP, WebAuthn and Yubico OTP entry of the account becomes
  inactive until the account's next successful password sign-in, when feature 001 re-activates them
  (FR-026 there). Passkeys MUST NOT be affected. The response MUST contain the updated account.
  Suspending an account without active entries MUST succeed and change nothing.

#### Protecting the administrators

- **FR-028**: No change and no deletion MAY leave the server without an active administrator
  account. Such a request MUST be refused with a documented "last active administrator" error and
  MUST change nothing. A change that does not reduce the number of active administrators MUST NOT be
  refused by this rule. The check and the change MUST be atomic, so that concurrent requests cannot
  together leave no active administrator.
- **FR-029**: An administrator MUST NOT delete their own account. Such a request MUST be refused with
  a documented "cannot delete own account" error. An administrator MAY deactivate their own account
  when FR-028 allows it.

#### Deleting accounts

- **FR-030**: Administrators MUST be able to delete an administrator account. A deletion MUST remove
  the account, any assignment and permission record with its username, and its TOTP, WebAuthn,
  Yubico OTP and passkey entries, as legacy does, and MUST apply FR-034.
- **FR-031**: Administrators MUST be able to delete a domain administrator account, with or without
  assignments. A deletion MUST remove the account, its assignments, its permission record and its
  TOTP, WebAuthn, Yubico OTP and passkey entries, as legacy does, MUST remove unused single sign-on
  tokens issued for it, and MUST apply FR-034.
- **FR-032**: Every deletion of a domain administrator account MUST apply FR-031, including the
  deletion of accounts left without a domain when a domain is deleted (feature 002, FR-038 there).
- **FR-033**: A successful deletion MUST respond with no body. Deleting an account that does not
  exist, or addressing it with the wrong role, MUST be answered "not found".

#### Effect on sessions, API keys and other data

- **FR-034**: When an account is deleted, the system MUST, in the same operation:
  - revoke every API key the account owns, permanently, recording the deleting administrator as the
    revoker and "owner deleted" as the reason (feature 001, FR-036 there);
  - end every session of the account, and every impersonation of it (FR-052);
  - remove the account's preferences (feature 001, FR-030 there).

  Audit records MUST be kept.
- **FR-035**: When an account is deactivated, its sessions MUST end at their next request, and its
  API keys MUST be refused as invalid until it is active again, as feature 001 specifies. The keys
  MUST NOT be revoked, so that reactivation restores them.
- **FR-036**: A session, an API key, an impersonation or a single sign-on token MUST never act for an
  account that was created after it under the same username, for example after a deletion or a
  rename.

#### Domain administrator permission flags

- **FR-037**: A domain administrator MUST have these 19 permission flags, with these defaults for a
  new account (the database defaults of the supported schema). Each flag is enforced by the feature
  named:

  | Flag (legacy name) | Meaning (legacy label) | Default | Enforced by |
  |---|---|---|---|
  | `syncjobs` | Sync jobs | on | 008 |
  | `quarantine` | Quarantine actions | on | 006 |
  | `login_as` | Login as mailbox user | on | 007 |
  | `sogo_access` | Manage webmail (SOGo) access of mailboxes | on | 003 |
  | `app_passwds` | Manage app passwords | on | 005 |
  | `bcc_maps` | BCC maps | on | 009 |
  | `pushover` | Push notifications (Pushover) | off | 005 |
  | `filters` | Mailbox filters | on | 005 |
  | `ratelimit` | Rate limit | on | 006 |
  | `spam_policy` | Deny and allow lists | on | 006 |
  | `extend_sender_acl` | Extend sender permissions by external addresses | off | 004 |
  | `unlimited_quota` | Unlimited quota for mailboxes | off | 003 |
  | `protocol_access` | Change protocol access | on | 003 |
  | `smtp_ip_access` | Change allowed hosts for SMTP | on | 003 |
  | `alias_domains` | Add alias domains | off | 004 |
  | `mailbox_relayhost` | Change a mailbox's relayhost | on | 009 |
  | `domain_relayhost` | Change a domain's relayhost | on | 009 |
  | `domain_desc` | Change the domain description | off | 002 |
  | `alias_external_goto` | Allow aliases with external destinations | on | 004 |

- **FR-038**: Administrators MUST be able to read a domain administrator's flags and to change any of
  them by sending only the flags to change. A flag that does not exist MUST be refused with a
  validation error naming it. The flags MUST be stored where legacy stores them, so that the legacy
  UI and feature 001's identity show the same values.
- **FR-039**: No domain administrator MAY change any permission flag, their own or another's.
- **FR-040**: A flag change MUST apply to the next request of the domain administrator, of its API
  keys and of an impersonation it performs (FR-046, FR-052).
- **FR-041**: When a domain administrator has no permission record, every flag MUST read as off, and
  a change MUST create the record with the sent flags and every other flag off.

#### Impersonation (login-as)

- **FR-042**: An administrator MUST be able to impersonate any mailbox user and any domain
  administrator. A domain administrator whose `login_as` flag is on MUST be able to impersonate the
  mailbox users of the domains assigned to them through an active assignment. Nobody MAY impersonate
  an administrator, and a domain administrator MUST NOT impersonate another domain administrator.
- **FR-043**: An impersonation MUST be started only from a full browser session (feature 001): never
  with an API key, never from a restricted session or a pending sign-in, and never from a session
  that is already impersonating. A nested attempt MUST be refused with a documented "already
  impersonating" error.
- **FR-044**: The request MUST name the target's role (mailbox user or domain administrator) and its
  identifier: the mailbox address, normalised as feature 003 does (FR-007 there), or the domain
  administrator's username. The target MAY be inactive, and a mailbox target MAY be in an inactive
  domain, as in legacy. A resource MUST be answered "not found".
- **FR-045**: Starting an impersonation MUST renew the session identifier and the anti-forgery token,
  and MUST respond with the new identity (FR-048).
- **FR-046**: While impersonating, the session MUST act with the target's role and scope, and with
  these effective permission flags, as legacy computes them:
  - an administrator impersonating a mailbox user: every mailbox user flag on;
  - an administrator impersonating a domain administrator: every domain administrator flag on;
  - a domain administrator impersonating a mailbox user: every mailbox user flag on, except
    `syncjobs`, `quarantine`, `app_passwds`, `pushover` and `spam_policy`, which take the
    impersonating domain administrator's current values.
- **FR-047**: The target's forced password change and forced second-factor setup MUST NOT restrict
  an impersonation. The impersonator's preferences MUST stay in effect, and a preference change
  during an impersonation MUST change the impersonator's preferences, never the target's.
- **FR-048**: During an impersonation, feature 001's identity (FR-028 there) MUST describe the target
  (username, role, scope and the effective flags of FR-046) and MUST additionally name the
  impersonator (username and role), the impersonator's sign-in method and the time the impersonation
  started.
- **FR-049**: During an impersonation, operations that manage the signed-in account's own sign-in
  credentials MUST be refused with a documented "not allowed while impersonating" error: changing
  the password, registering, renaming or removing passkeys, enrolling or removing second factors,
  and changing the recovery e-mail. This applies to the operations of features 001 and 005. Every
  other operation follows the effective role, scope and flags of FR-046, including app passwords,
  which legacy grants to impersonators through `app_passwds`.
- **FR-050**: The impersonating session MUST be able to end the impersonation. The session MUST then
  act as the impersonator again, with the impersonator's original sign-in method, and the session
  identifier and the anti-forgery token MUST be renewed. Ending when no impersonation is active MUST
  succeed and change nothing.
- **FR-051**: Signing out (feature 001, FR-010 there) during an impersonation MUST end the whole
  session, not only the impersonation.
- **FR-052**: An impersonation MUST end automatically when the target is deleted or renamed, when the
  target leaves the impersonator's scope, or when an impersonating domain administrator's `login_as`
  flag is turned off. The first request that detects it MUST be refused with a documented
  "impersonation ended" error, MUST NOT be carried out, and the session MUST then act as the
  impersonator again. When the impersonator itself can no longer sign in (deactivated or deleted),
  the whole session MUST end, as feature 001 specifies.
- **FR-053**: Impersonations in this API MUST NOT affect legacy UI sessions, and legacy impersonations
  MUST NOT affect sessions of this API (constitution V).

#### Webmail login on behalf of a user

- **FR-054**: When the operator setting that allows administrators' webmail login
  (`ALLOW_ADMIN_EMAIL_LOGIN`, default off) is on, an administrator, or a domain administrator whose
  `login_as` flag is on, MUST be able to obtain a single-use webmail sign-in for a mailbox in their
  scope. The same MUST be possible during an impersonation of that mailbox user, with the effective
  flags of FR-046. The mailbox MUST be active and in an active domain, and webmail MUST be enabled on
  the server. Otherwise the request MUST be refused with a validation error.
- **FR-055**: The webmail sign-in MUST use the webmail single sign-on hand-off that feature 005
  specifies for a user's own webmail, applied to the target mailbox. It MUST record, for the target
  mailbox, the recent-login entry that legacy records for webmail single sign-on, and MUST NOT
  change the caller's session in this API.
- **FR-056**: When the operator setting is off, the request MUST be refused with a documented
  "webmail login on behalf disabled" error. The identity of administrators and domain administrators
  (feature 001, FR-028 there) MUST state whether the setting is on, so that the web app shows the
  webmail button only when it works.

#### Domain administrator single sign-on tokens

- **FR-057**: Administrators MUST be able to issue a single sign-on token for an existing domain
  administrator account. An inactive account MUST be refused with a documented "account inactive"
  error, and no token is issued.
- **FR-058**: A token MUST be five groups of six upper-case hexadecimal characters joined by `-`, as
  in legacy, generated from a cryptographically secure source. It MUST be stored where legacy stores
  these tokens, so that the legacy domain administrator login also accepts it, and tokens issued
  through `/api/v1` MUST be accepted here. Issuing a token MUST remove expired tokens, as legacy does.
- **FR-059**: The issue response MUST contain the token, the username and the expiry time, which is
  30 seconds after issue. The token MUST NOT appear in any later response, log or audit record.
- **FR-060**: Any client MUST be able to sign in with a token. The token is valid only if it exists,
  is less than 30 seconds old, and belongs to an active domain administrator account. A valid token
  MUST be consumed in the same step, so that it signs in exactly once across both UIs.
- **FR-061**: A token sign-in MUST establish a session for the domain administrator with the sign-in
  method "single sign-on token". It MUST NOT ask for a second factor, as in legacy, because the
  issuing administrator vouches for it. Feature 001's session rules MUST apply as to any sign-in:
  the session identifier is renewed, the anti-forgery rules apply, and forced actions restrict the
  session (FR-008, FR-011 and FR-014 there).
- **FR-062**: A failed token sign-in MUST return feature 001's generic sign-in error, MUST be
  audited, and MUST count towards feature 001's throttling per client address. It MUST NOT be
  reported to fail2ban, as in legacy.
- **FR-063**: A token sign-in on a client that already holds a session, including an impersonating
  one, MUST replace that session, as in legacy.

#### Version tags

- **FR-064**: Every administrator and domain administrator account MUST carry a version tag. It MUST
  cover the username, the status, the forced flags and, for a domain administrator, the assignments
  and the permission flags. It MUST change whenever any of them changes, whether through this API,
  the legacy UI or `/api/v1`. It MUST NOT change with the second-factor status or the passkey count.
- **FR-065**: A client MAY send the version tag it read with a change, a permission change, a
  second-factor suspension or a deletion. When it no longer matches, the request MUST be refused as
  outdated and MUST change nothing. The contract MUST recommend that interactive clients always send
  it.

#### Audit

- **FR-066**: Every request of this feature that changes state, or tries to, MUST write exactly one
  audit record into feature 001's audit log, with the fields of FR-046 there and:
  - the target type (administrator, domain administrator, single sign-on token or impersonation) and
    the target identifier;
  - the outcome, including refusals and their reason;
  - for successful changes, each changed value with its old and new value, the domains added and
    removed, and the permission flags changed. A password MUST be recorded only as "changed",
    together with whether it was given pre-hashed;
  - for a deletion, the API keys revoked and the numbers of second factors and passkeys removed;
  - for a single sign-on token, the target account and the expiry, never the token.
- **FR-067**: Starting an impersonation, ending it explicitly, ending it by signing out and ending it
  automatically (with the reason) MUST each be audited. Every audit record written during an
  impersonation, by any feature, MUST record the impersonator as the actor and the target as the
  impersonated account, as legacy logs "impersonator => target".
- **FR-068**: A token sign-in, successful or failed, MUST be audited as a sign-in (feature 001,
  FR-045 there) with the method "single sign-on token". Obtaining a webmail sign-in on behalf of a
  user MUST be audited with the caller, the impersonator if any, and the target mailbox.
- **FR-069**: Administrators MUST be able to filter the audit log by target type and target
  identifier (as in feature 002, FR-058 there) and by impersonated account, and MUST see in each
  record whether it was written during an impersonation.

#### Cross-cutting

- **FR-070**: Errors MUST follow feature 001's conventions (FR-049 and FR-055 there). This feature
  MUST document at least these error types: account already exists, last active administrator,
  cannot delete own account, already impersonating, impersonation ended, not allowed while
  impersonating, webmail login on behalf disabled, account inactive, and outdated version.
  Validation errors MUST use stable rule codes with their parameters, for example the allowed
  characters and the length limits of FR-014 and each unmet password rule.
- **FR-071**: Every operation MUST be described in the published API contract before it is
  implemented, and its behaviour MUST match the contract.
- **FR-072**: Changes made through the legacy UI or `/api/v1` MUST be visible in the next response
  of this API.
- **FR-073**: This feature MUST NOT offer batch operations. Each account is created, changed,
  suspended and deleted with its own request, and the legacy mass actions become one request per
  account.
- **FR-074**: Passwords, password hashes and single sign-on tokens MUST NOT appear in any log, audit
  record or response, apart from the token in its issue response (FR-059).

### Legacy Coverage

Every legacy capability allocated to feature 007 in `docs/roadmap.md` maps to requirements as
follows. Actions are `/api/v1` names; *form* marks legacy UI form handlers and screens.

| Legacy action or form | Legacy implementation | Requirements |
|---|---|---|
| `add/admin`, *form* "Add administrator" modal | `admin('add')`, `modals/admin.twig` | FR-004, FR-014 to FR-017, FR-020, FR-066 |
| `get/admin/all`, `get/admin/{username}`, *form* administrators table | `admin('get')`, `admin('details')`, `js/site/admin.js` (`draw_admins`) | FR-006, FR-008 to FR-011, FR-013 |
| `edit/admin`, *form* "Edit administrator" page (username, password, active, forced flags) | `admin('edit')`, `edit/admin.twig` | FR-021, FR-022, FR-024 to FR-026, FR-028, FR-064, FR-065 |
| `edit/admin` `disable_tfa`, *form* "Disable TFA until next successful login" | `UPDATE tfa SET active = 0` | FR-027 |
| *form* administrators mass actions: activate, deactivate, disable TFA, remove | `tab-config-admins.twig` | FR-022, FR-027, FR-028, FR-030, FR-073 |
| `edit/admin` forced self password update branch (`pending_pw_update`) | `admin('edit')` | Not covered: feature 005 |
| `delete/admin`, single and mass | `admin('delete')` | FR-028 to FR-030, FR-033, FR-034 |
| `add/domain-admin`, *form* "Add domain administrator" modal | `domain_admin('add')`, `modals/admin.twig` | FR-014 to FR-020, FR-037 |
| `get/domain-admin/all`, `get/domain-admin/{username}`, *form* domain administrators table | `domain_admin('get' / 'details')`, `js/site/admin.js` (`draw_domain_admins`) | FR-007 to FR-013 |
| `edit/domain-admin` as administrator, *form* "Edit domain administrator" page | `domain_admin('edit')` administrator branch, `edit/domainadmin.twig` | FR-021, FR-023 to FR-027, FR-064, FR-065 |
| *form* domain administrators mass actions: activate, deactivate, disable TFA, remove | `tab-config-admins.twig` | FR-023, FR-027, FR-031, FR-073 |
| `edit/domain-admin` as domain administrator and `edit/self` (own password) | `domain_admin('edit')` domain administrator branch | Not covered: feature 005 |
| `delete/domain-admin`, single and mass | `domain_admin('delete')` | FR-031 to FR-034 |
| Domain administrator deletion by domain deletion | `mailbox('delete', 'domain')` | FR-032 (triggered by feature 002) |
| `edit/da-acl`, *form* ACL selector on the "Edit domain administrator" page | `acl('edit' / 'get', 'domainadmin')` | FR-037 to FR-041, FR-064, FR-065 |
| Effective permission flags per request, including during dual login | `acl('to_session')` | FR-002, FR-040, FR-046 |
| `add/sso/domain-admin` | `domain_admin_sso('issue')` | FR-057 to FR-059 |
| Token sign-in `/domainadmin?sso_token=` | `domain_admin_sso('check')`, `triggers.domainadmin.inc.php` | FR-060 to FR-063 (sign-in to this API); the legacy page keeps accepting tokens (FR-058) |
| Login-as `?duallogin=`, "Login" buttons in the mailbox list and in the domain administrator list | `triggers.global.inc.php`, `js/site/mailbox.js`, `js/site/admin.js` | FR-042 to FR-049 |
| Return from login-as (sign-out while dual), navbar entry "user (impersonator)" | `sessions.inc.php`, `base.twig` | FR-048, FR-050, FR-051 |
| Logging of actions during login-as (`impersonator => target`) | `logger()` in `functions.inc.php` | FR-067 |
| Webmail login on behalf: `sogo-auth.php?login=`, "SOGo" button in the mailbox list, webmail button during dual login | `sogo-auth.php`, `js/site/mailbox.js`, `user/tab-user-auth.twig` | FR-054 to FR-056, FR-068 |
| Forced password change and forced 2FA attributes (`force_pw_update`, `force_tfa`) | `admin.attributes` | FR-006, FR-017, FR-022 to FR-024 |
| Password hashing and policy check | `hash_password()`, `password_check()` | FR-016, FR-026 |
| Dangling domain administrator cleanup at PHP container start | `init_db.inc.php` | FR-012, FR-023, Edge Cases |
| Quarantine list: delete button shown only with `login_as` | `js/site/quarantine.js` | Not covered: feature 006 |

### Legacy Parity and Deliberate Deviations

Parity reference: `source_code/data/web/inc/functions.admin.inc.php` (`admin()`),
`functions.domain_admin.inc.php` (`domain_admin()`, `domain_admin_sso()`),
`functions.acl.inc.php` (`acl()` for `domainadmin`, `to_session`), `functions.auth.inc.php`
(`admin_login`, `domainadmin_login`), `functions.inc.php` (`hash_password`, `password_check`,
`hasDomainAccess`, `hasMailboxObjectAccess`, `logger`), `triggers.global.inc.php`,
`triggers.domainadmin.inc.php`, `sessions.inc.php`, `prerequisites.inc.php`, `sogo-auth.php`,
`index.php`, `json_api.php`, `init_db.inc.php` (tables `admin`, `domain_admins`, `da_acl`, `da_sso`,
`tfa`, `fido2`, and the start-up cleanup), `templates/edit/admin.twig`,
`templates/edit/domainadmin.twig`, `templates/admin/tab-config-admins.twig`,
`templates/modals/admin.twig`, `templates/user/tab-user-auth.twig`, `templates/base.twig`,
`js/site/admin.js`, `js/site/mailbox.js`, `lang/lang.en-gb.json` and `docker-compose.yml`
(`ALLOW_ADMIN_EMAIL_LOGIN`, default `n`).

The reference is upstream mailcow-dockerized `2026-09a`. `source_code/` points to the FeldHost fork
(`~/Projects/mailhosting`, branch `feldhost-202609a`), so the files above were diffed against that
upstream tag. They are identical, apart from:

1. `triggers.admin.inc.php` and `triggers.domainadmin.inc.php` pass the recent-login service label
   `WEBUI` instead of `MAILCOWUI` at sign-in. Administrator and domain administrator sign-ins never
   write recent-login entries, so this has no effect on this feature (feature 001, FR-027 there).
2. Branding-only language strings and logos.

This feature therefore has no fork-only behaviour.

| Behaviour | Legacy | This feature |
|---|---|---|
| Who manages accounts | The administrator role: administrator sessions and `/api/v1` keys, which act as an unscoped administrator | Administrators only. Administrator accounts are written only in a signed-in session; domain administrators and tokens also with an administrator's read-write key (FR-001, FR-004). **Deviation** for keys |
| Username rules | Trimmed and lower-cased at creation; letters, digits, `.`, `_`, `-`; the `API` check never matches after lower-casing; no length check; renames neither trimmed nor lower-cased | Same characters, 1–255 characters, `api` reserved in any letter case, renames normalised (FR-014). **Deviation** |
| Uniqueness | Creation checks administrator and domain administrator rows (and mailboxes for domain administrators). Renames check only the same role, so a cross-role collision fails with a database error, after partial changes for domain administrators | One namespace, refused as a conflict at creation and on rename, nothing changed (FR-015). **Deviation** |
| Password | `password` and `password2` must match; `PASSWD_POLICY`; `hash_password` with `MAILCOW_PASS_SCHEME`; pre-hashed values stored as given; masked as `*` in the call log | One field; same policy, hashing and pre-hashed handling (FR-016). **Deviation**: confirmation is the client's job |
| Status at creation | An omitted `active` becomes inactive through `/api/v1`; the forms default to active | Defaults to active (FR-017). **Deviation** |
| Forced flags and permission flags at creation | Not accepted; absent attributes read as off; flags get the database defaults | Accepted, with the same defaults (FR-017, FR-018). **Deviation** (additive) |
| Omitted values on edit | Username and status kept; both forced flags reset to off when omitted; empty domain list keeps the domains | Omitted values kept, sent values applied (FR-021). **Deviation** |
| Domain administrator domains | Invalid entries skipped with an error while valid ones are stored; no valid entry leaves no account but an orphaned permission record and a success message; alias domain names accepted; on edit, one invalid entry skips the account; the set cannot be emptied | Every entry must be an existing primary domain or the whole request is refused; at least one domain (FR-018, FR-023). **Deviation** |
| Assignment rows on edit | All deleted and re-inserted: creation times reset, status set to the account status | Unchanged assignments keep creation time and status; a status change applies to all (FR-023). **Deviation** for creation times |
| Domain administrator representation | Creation time and status from the first assignment row; accounts without assignments invisible and not deletable | Account's own creation time and status, assignments with their own status; accounts without assignments listed and deletable (FR-007, FR-012, FR-031). **Deviation** |
| Second-factor status | `tfa_active` of the first joined second-factor row | None, active or suspended over accepted entry types, plus passkey count (FR-008). **Deviation** |
| Lists | Complete lists without pagination, one detail lookup per account | Paginated, searchable, filterable, sortable (FR-010 to FR-012) |
| Last administrator | Deactivation refused when exactly one administrator is active, which also refuses any edit of an inactive administrator that does not activate it. Deletion only refuses the session's own account, so an `/api/v1` key can delete every administrator. No protection against concurrent requests | Never zero active administrators, atomic; unrelated edits allowed; own deletion refused (FR-028, FR-029). **Deviation** |
| Disable TFA | `disable_tfa` sets every second-factor entry inactive; re-activated at the next login without a second factor. Combined with a rename, the entries are deactivated but not renamed | Same suspension as its own operation; entries always follow a rename (FR-027, FR-025). **Deviation** for the rename case |
| Rename | Administrator: second factors renamed unless `disable_tfa` is set, passkeys not. Domain administrator: assignments re-created, permission record and second factors renamed, passkeys not. Tokens and sessions left | Second factors, passkeys, assignments, flags, API keys and preferences follow; tokens removed; other sessions end (FR-025). **Deviation** |
| Delete side effects | Administrator: `admin`, `domain_admins`, `tfa`, `fido2` rows. Domain administrator: `domain_admins`, `admin`, `da_acl`, `tfa`, `fido2` rows; `da_sso` rows left | Same rows; also tokens removed, API keys revoked, sessions ended, preferences removed (FR-030, FR-031, FR-034). **Deviation** for the additions |
| Domain administrator removed by a domain deletion | `admin` and orphaned `da_acl` rows only; `tfa` and `fido2` rows left | Full cascade (FR-032). **Deviation** |
| Permission flags | Administrators only; the whole set is replaced (unsent flags off); unknown names ignored; several accounts per request; a missing record fails with "Cannot determine current ACL" | Administrators only; partial change; unknown names refused; one account per request; missing record handled (FR-038 to FR-041, FR-073). **Deviation** |
| Flag defaults | Database defaults of `da_acl` | Same (FR-037) |
| Login-as start | `GET ?duallogin=<target>` without an anti-forgery check; needs the session's `login_as` (always on for administrators); not nested; a mailbox target is checked with `mailbox_details` (scope, resources excluded, status not checked); other targets with `domain_admin('details')`, which only administrators pass; session identifier kept; not logged | A state-changing request with the anti-forgery check and the same eligibility (FR-042 to FR-044); identifiers renewed (FR-045); audited (FR-067). **Deviation** for the request form, renewal and audit |
| Effective flags during login-as | Recomputed per request from the impersonator: administrator → every flag on; domain administrator → mailbox user flags on, except the five overlapping ones, taken from the impersonator's record | Same (FR-046) |
| Target's forced actions during login-as | Not applied | Same (FR-047) |
| Credential management during login-as | Allowed as the target: password, second factors, FIDO2 credentials, recovery e-mail | Refused (FR-049). **Deviation** (security) |
| Return from login-as | Sign-out while dual returns to the impersonator and clears the webmail single sign-on grants; session identifier kept; not logged | Own end operation with renewal and audit (FR-050, FR-067); sign-out ends the whole session (FR-051). **Deviation** |
| Invalidated login-as | Never re-checked: the session keeps acting for a deleted target or after `login_as` is removed | Ends with an error on the detecting request, which is not carried out (FR-052). **Deviation** |
| Logging | `logs` rows with user `impersonator => target` and role `role => role` | This API's audit log with actor and impersonated account, not the `logs` table (FR-067), as in feature 001. **Deviation** |
| Webmail on behalf | `sogo-auth.php?login=`: needs `login_as` and `ALLOW_ADMIN_EMAIL_LOGIN`; scope check; refused with pending actions; adds the address to the session's webmail grant and starts dual login if none is active; `sasl_log` entry with service `SSO`; redirects to SOGo even for mailboxes that cannot use it | Same gating, scope and recent-login entry, through feature 005's hand-off; the caller's session is unchanged; ineligible mailboxes refused (FR-054 to FR-056). **Deviation** for the session and eligibility |
| Token issue | Administrator role (session or `/api/v1` key); the target needs an assignment row; status not checked; 5 × 6 hexadecimal characters; stored in `da_sso`; tokens older than 30 s deleted; success not logged | Administrator session or administrator read-write key; any domain administrator account, inactive refused; same format and storage (FR-057 to FR-059); audited (FR-066). **Deviation** for inactive targets and the audit |
| Token sign-in | `/domainadmin?sso_token=`: active domain administrator, younger than 30 s, single use; session identifier regenerated; no second factor; forced actions not applied; not logged, not throttled, not reported to fail2ban | Same validity, single use across both UIs, no second factor, not reported to fail2ban (FR-060 to FR-063); forced actions applied, audited, throttled. **Deviation** |
| Shared store, container service and groupware | Account, flag, token and login-as operations make no shared-store writes, container-service or groupware calls; the shared store is only read for the password policy and holds the PHP sessions | None added. The webmail hand-off is feature 005's |
| Concurrent edits | Last write wins | Version tags (FR-064, FR-065). **Deviation** |
| Mass actions | One request with a list of usernames | One request per account (FR-073). **Deviation** |

### Key Entities *(include if feature involves data)*

- **Administrator account** (existing mailcow data): username, password hash, status, forced
  password change and forced second-factor setup flags, creation and modification times. Has full
  access to the server.
- **Domain administrator account** (existing mailcow data, same table as administrators): the same
  attributes, plus assignments and a permission record.
- **Domain administrator assignment** (existing mailcow data): links a domain administrator to one
  domain, with its own status and creation time. Only active assignments grant scope (feature 002).
- **Permission record** (existing mailcow data): the 19 permission flags of one domain administrator.
- **Second-factor entry and passkey** (existing mailcow data, feature 001): TOTP, WebAuthn, Yubico OTP
  entries and passkeys of an account. Suspended, carried over and removed by this feature.
- **Single sign-on token** (existing mailcow data): a single-use, 30-second credential for one domain
  administrator, shared with the legacy UI.
- **Impersonation** (state of a session of this API; legacy calls it "login-as" or "dual login"):
  impersonator (username, role, sign-in method), target (username, role) and start time. Ends
  explicitly, by sign-out or automatically.
- **Webmail sign-in on behalf** (feature 005's hand-off): a single-use grant to open one mailbox's
  webmail, with a recent-login entry for that mailbox.
- **API key and preferences** (owned by this API, feature 001): revoked or removed when their owner is
  deleted, carried over on rename.
- **Audit record** (owned by this API, feature 001): extended with the target types of this feature,
  the attribute, domain and flag changes, and the impersonated account.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For 100% of the create, change, rename, flag change and delete cases in the parity test
  set, the stored rows equal those the legacy UI produces for the same input, apart from timestamps,
  password hashes and the stated deviations.
- **SC-002**: 100% of attempts by domain administrators and mailbox users to reach an account,
  permission or token operation are refused, 100% of impersonation and webmail attempts on mailboxes
  outside a domain administrator's scope get the same answer as for a missing mailbox, and 0
  impersonations can be started with an API key.
- **SC-003**: In a test of 1,000 concurrent pairs of deactivations and deletions of administrators,
  the server never ends up without an active administrator.
- **SC-004**: After an account is deleted or renamed, a new account with the old username inherits
  no session, API key, passkey, second factor, preference, permission or token in 100% of tests.
- **SC-005**: 100% of audit records written during an impersonation name both the impersonator and
  the impersonated account, and 100% of impersonation starts and ends are audited.
- **SC-006**: A billing system can create a domain administrator and sign its user into either web
  interface with a single sign-on token in under 1 minute, without the user typing a password.
- **SC-007**: A support administrator can start an impersonation from the mailbox list and return to
  their own account in under 10 seconds each, without signing in again.
- **SC-008**: In 95% of requests, a page of 25 domain administrators with their domains and flags is
  returned within 1 second on a server with 5,000 domain administrators and 5,000 domains.
- **SC-009**: A scan of all audit records and service logs after the full test run finds no
  password, password hash or single sign-on token.
- **SC-010**: 100% of changes sent with an outdated version tag are refused.

## Assumptions

- Account self-service is feature 005: own password changes of administrators and domain
  administrators, completing a forced password change, and enrolling and removing second factors.
  Own passkeys and the identity are feature 001.
- The webmail single sign-on hand-off is specified in feature 005. Webmail login on behalf of a user
  (FR-054 to FR-056) becomes available when that hand-off exists.
- The supported schema (`2026-09a`) has 19 domain administrator flags. The legacy inventory's "20"
  counts the username column. When upstream adds a flag, the contract gains it in a later release,
  and until then it keeps its database default.
- `ALLOW_ADMIN_EMAIL_LOGIN` is read from the operator's existing mailcow configuration, default off.
- The 30-second token lifetime is fixed, because the legacy domain administrator login enforces the
  same lifetime on the same tokens.
- The legacy UI never sends version tags, so it can still overwrite changes made through this API.
- Legacy deletes domain administrators without assignments when its PHP container starts, so such
  accounts appear only transiently.
- Legacy UI sessions are independent of this API (constitution V): deactivating or deleting an
  account through this API ends its sessions here, while its legacy sessions follow legacy rules.
- These defaults can be changed by the operator: the list page size (25, maximum 1,000).
- **Out of scope**:
  - own password, forced password change completion and second-factor enrolment (005);
  - mailbox, domain and alias management, which only use the scope and flags defined here (002 to
    004);
  - promoting a domain administrator to administrator, or the reverse, which legacy does not offer;
  - the legacy `/api/v1` keys and the `logs` table;
  - batch operations.
- **Dependencies**:
  - the upstream mailcow database schema of the supported release;
  - feature 001 for identity, sessions, sign-in rules, API keys, preferences, errors and the audit
    log;
  - feature 002 for domains and their name normalisation;
  - feature 003 for password rules, the password policy read and mailbox address normalisation;
  - feature 005 for the webmail single sign-on hand-off and for the credential operations refused
    during impersonation.
