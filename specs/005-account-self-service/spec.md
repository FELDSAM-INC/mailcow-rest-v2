# Feature Specification: Account Self-Service

**Feature Branch**: `main` (no feature branches, constitution 1.1.0)

**Created**: 2026-10-18

**Status**: Draft

**Input**: Feature description built from `docs/roadmap.md` (feature 005 and allocation row 005):

> Account self-service for mailcow REST API v2. Every signed-in account (administrator, domain
> administrator, mailbox user) changes its own password, resolves a forced password change and a
> forced second-factor setup, and enrols and removes its own second factors (TOTP authenticator
> apps, WebAuthn security keys and Yubico OTP) in a way that stays compatible with the legacy
> storage. Mailbox users keep a recovery e-mail address and reset a forgotten password through it,
> manage app passwords, temporary (spam) aliases, push notifications (Pushover), tagged-mail
> handling and TLS enforcement, review and clear their recent logins, reset their ActiveSync cache
> and their groupware (SOGo) profile, read their active Sieve script, see an overview of their
> mailbox, and open webmail from the new web app without signing in again. Administrators and
> domain administrators act on these objects of the mailboxes in their scope where legacy lets
> them, including the mailbox Sieve filters that only they manage.
>
> Allocated legacy surface: own password (`edit/self`, user password), forced password change,
> password reset via recovery e-mail (*form* `pw_reset_request`/`pw_reset`), recovery e-mail,
> TOTP/WebAuthn/Yubi OTP enrolment and removal (`webauthn-tfa-registration`, *form*
> `set_tfa`/`unset_tfa_key`), forced 2FA setup, app passwords (`app-passwd`), temporary aliases
> (`time_limited_alias`), mailbox sieve filters (`filter`, `filters`, `active-user-sieve`), Pushover
> (`edit/pushover`, `pushover-test`), tagging (`delimiter_action`), user TLS policy
> (`edit/tls_policy`), recent logins (`last-login`, `reset-last-login`), EAS cache and SOGo profile
> reset (`delete/eas_cache`, `delete/sogo_profile`), webmail SSO from the new web app. The legacy
> user page, the domain administrator's account page, the administrator's own second-factor section
> and the "Filters" tab need what they show today.
>
> Constraints: changing a password offers to end the account's other sessions of this API (ASVS
> level 2). Second factors enrolled in either implementation keep working in both. Secrets never
> appear in responses after creation, in logs or in audit records. Every legacy side effect is
> reproduced. The feature builds on feature 001's identity, roles, sessions, restricted sessions,
> passkeys, API keys, error conventions and audit log, on feature 002's domain conventions and on
> feature 003's mailboxes and password policy.

## Clarifications

### Session 2026-10-18

- Q: Can the new web app open webmail without a second sign-in, given that constitution V forbids
  patching other containers and keeps the sessions of the two implementations independent? → A:
  Yes, through a webmail-only hand-off. mailcow's existing webmail single sign-on accepts a browser
  session kept in mailcow's shared store, so this API can create, for the requesting browser, a
  sign-in that grants webmail and nothing else, without changing any container. The hand-off is
  available only to a mailbox user's full session, records the legacy "SSO" recent-login entry,
  ends with the API session that created it, and is reused by feature 007 for webmail on behalf of
  a user (FR-067, FR-068). (agent decision: legacy webmail SSO works this way today, constitution V
  allows a spec to link the two implementations explicitly, and the user need is explicit. Because
  it couples this API to the legacy session format, it is also listed as an owner-level question.)
- Q: When must an account re-enter its current password? → A: For a password change, a
  recovery-address change, enrolling a second factor and removing all second factors, for every
  role, as legacy requires for mailbox users and domain administrators. Administrators changing their
  own password must also enter it (legacy does not ask them). Resolving a forced password change or a
  forced second-factor setup needs no current password, as in legacy. A wrong current password is
  audited and counts towards feature 001's sign-in throttling, but is not reported to fail2ban
  (FR-008, FR-012, FR-029). (agent decision: legacy behaviour for two roles, ASVS 2.1.6 for the
  third, and throttling closes the brute-force path through an open session.)
- Q: Which sessions of this API end when a password changes? → A: A password change by the account
  itself ends its other sessions by default; the client may choose to keep them. The current
  session stays signed in. A completed password reset ends all of the account's sessions. Webmail
  hand-offs created by ended sessions end with them. Legacy UI sessions and app passwords are not
  affected (FR-011, FR-025). (agent decision: ASVS 3.3.3 asks to offer ending other sessions;
  ending them by default is the safer choice for a change made because of a suspected leak.
  Constitution V keeps legacy sessions out of reach.)
- Q: May a self-service password or an app password be given in pre-hashed form, as legacy accepts
  for any value that starts with a hash-scheme prefix? → A: No. In this feature every password is
  hashed by the system, and a value that starts with a recognised hash-scheme prefix is refused with
  a validation error (FR-009, FR-038). (agent decision: legacy lets a user bypass the password
  policy and store a plaintext password this way. Pre-hashed values stay available to
  administrators in feature 003, where migrations need them.)
- Q: How much may a password reset request reveal about the account? → A: Nothing. Every
  well-formed request receives the same acknowledgement, in the same time, whether or not the
  account exists, has a recovery address, is eligible or has reached its token limit, and whether or
  not the message could be sent. The masked recovery address that legacy shows is not returned.
  Only a server-wide "password reset not available" answer is distinct (FR-019, FR-020). (agent
  decision: legacy answers with different messages and the masked address, which lets anyone
  enumerate accounts; OWASP ASVS 2.5 and constitution VI.)
- Q: Do the permission flags gate reading as well as changing? → A: They gate changes only. Reading
  app passwords, temporary aliases, filters, push notification settings and mailbox settings is
  allowed without the flag, as legacy reads are. Every change needs the flag, and this API enforces
  the flag for app passwords too, which legacy checks only in its user interface (FR-006). (agent
  decision: legacy parity for reads; constitution VI for the server-side check.)
- Q: Does the mailbox user's password-reset permission (`pw_reset`) also block the reset itself? →
  A: No. As in legacy, it controls only whether the user may set their own recovery address. A
  mailbox whose recovery address an administrator set (feature 003) can reset its password even
  without the flag (FR-018, FR-020). (agent decision: legacy behaviour; many mailboxes created
  through `/api/v1` lack the flag while their administrators rely on the reset working.)
- Q: Are the Pushover application token and user key returned when settings are read, as the
  legacy form shows them? → A: No. They are write-only after saving: reads show only whether they
  are set. A replacement that omits them keeps the stored values (FR-054, FR-055). (agent decision:
  they are credentials of a third-party service, the constraint that secrets never appear after
  creation covers them, and the cross-feature conventions make stored secrets write-only.)
- Q: Does the API offer the legacy mass actions of this feature as batch requests? → A: Only for
  TLS enforcement, which administrators and domain administrators apply from the mailbox list; it
  follows feature 003's bulk-action pattern (up to 100 mailboxes, one result per mailbox). The mass
  actions on a user's temporary aliases, app passwords and filters are one request per item
  (FR-060, FR-079). (agent decision: feature 003 deferred the TLS mass action to this feature;
  the other lists are short, and per-item requests keep one outcome, version check and audit
  record each, as in feature 002.)
- Q: Can mailboxes whose password lives in an external identity provider (LDAP, Keycloak, generic
  OIDC) change their password or manage second factors here? → A: No. Their password is managed by
  the identity provider, and confirming their current password needs that provider, which arrives
  with feature 012. Such mailboxes keep app passwords, temporary aliases and every other
  self-service function (FR-013, FR-028). (agent decision: consistent with feature 001, which does
  not verify external passwords; legacy offers second factors to LDAP mailboxes, so that is a
  temporary deviation until 012.)
- Q: Should this feature include the mailbox user's read-only overview (aliases, send-as addresses,
  quota) and the password rules, which no allocation row names explicitly? → A: Yes. The legacy user
  page shows the overview, feature 004 assigns the user's own view of aliases and send-as addresses
  to this feature, and the password-change and reset screens need the rules before submitting.
  Every signed-in account can read the rules for its own password, and so can the holder of a valid
  reset token (FR-014, FR-069). (agent decision: the legacy user page is this feature's screen, and
  feature 003 exposes the same rules to administrators.)
- Q: How does a client learn the server settings it needs before submitting, such as whether
  webmail is enabled or whether password reset works? → A: Through a read-only "self-service
  capabilities" operation for every signed-in account; signed-out clients can read whether password
  reset is available (FR-080). (agent decision: the cross-feature conventions ask each feature to
  expose such settings through its own capabilities read, as features 002 and 004 do; legacy renders
  them into its pages.)
- Q: Can a client tell, before it offers a removal, that the account must keep a second factor? →
  A: Yes. For a signed-in session, the self-service capabilities also describe the account: whether
  it is required to keep at least one second factor (legacy `force_tfa`), whether it may change its
  password here and whether it may manage second factors here (FR-080). (orchestrator decision: the
  UI 005 spec found the gap; clients warn before a removal that FR-034 or FR-035 would refuse.)
- Q: Can administrators and domain administrators learn the domains available for a mailbox's
  temporary aliases? → A: Yes. Every caller who may manage a mailbox's temporary aliases can read the
  domains available for them: the mailbox's domain and its alias domains (FR-081). (orchestrator
  decision: the UI 005 spec found that only the user's own overview listed them, while
  administrators create temporary aliases for mailboxes in their scope.)
- Q: Are the Apple connection profile and the client configuration guide part of this feature? →
  A: Yes. A mailbox user can download an Apple connection profile for their own mailbox as legacy
  `mobileconfig.php` builds it (e-mail only, or e-mail with calendars and contacts), optionally with
  a newly generated app password embedded, which appears only in the file, is audited and needs the
  app-password permission. Legacy does not sign the profile, so neither does this API. A mailbox user
  can also read the client configuration data that the legacy client setup help shows: host names,
  ports, encryption and authentication per protocol (FR-082, FR-083). Autodiscover and autoconfig
  stay in php-fpm. (orchestrator decision: the owner wants every legacy feature; the roadmap
  allocation was updated.)
- Q: Which error refuses a change that needs a permission flag, and how are bulk TLS changes
  addressed? → A: The shared "permission flag required" error, which names the flag in `requiredFlags` (FR-006,
  FR-075), and the bulk TLS change is a `setTlsEnforcement` action sent with `targets` to the mail
  settings' bulk-action path (FR-060). (orchestrator decision: cross-feature conventions on
  permission refusals and bulk paths; this feature no longer defines its own "permission flag
  missing" type.)

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Change own password, including a forced change (Priority: P1)

A mailbox user opens their account in the new web app and changes their password. They enter the
current password and a new one, and the app tells them which policy rules a rejected password
misses. After the change, the old password no longer works, the app passwords they gave to their
phone keep working, and their other signed-in browsers are signed out unless they chose to keep
them. Domain administrators and administrators change their own password the same way.

When an administrator has flagged the account for a forced password change, the user signs in,
lands in a restricted session, and sets a new password without the current one, exactly as the
legacy UI's forced-change dialog allows. The restriction is lifted at once.

**Why this priority**: Feature 001 leaves forced password changes to the legacy UI until this
feature exists. Changing one's own password is the most basic self-service task of every role.

**Independent Test**: For one account of each role, change the password through the API, then sign
in with the new password in both UIs and to IMAP, and confirm that the old password fails. Repeat
with a wrong current password, with passwords that fail each policy rule, with a pre-hashed value,
with a mailbox of an external identity provider, and with an account flagged for a forced change.

**Acceptance Scenarios**:

1. **Given** a mailcow-source mailbox user with two other sessions of this API and one active app
   password, **When** they submit their correct current password and a new password that meets the
   policy, **Then** the password is changed, the password-changed time is updated, the other two
   sessions end, the current session stays signed in, the app password keeps working, and the audit
   log records the change without the password.
2. **Given** the same user, **When** they ask to keep their other sessions, **Then** the other
   sessions stay signed in.
3. **Given** a wrong current password, **When** the change is submitted, **Then** it is refused with
   a "current password incorrect" error, nothing changes, the attempt is audited, and repeated
   failures are delayed and finally refused as "too many requests".
4. **Given** a password policy with a minimum length of 10 and digits required, **When** the user
   submits `secret`, **Then** the change is refused with a validation error that names both rules
   and the minimum length 10.
5. **Given** a new password that starts with `{SSHA512}`, **When** it is submitted, **Then** it is
   refused with a validation error.
6. **Given** a signed-in administrator, **When** they change their own password without the current
   password, **Then** the change is refused. **When** they include it, **Then** the change is saved.
7. **Given** a domain administrator flagged for a forced password change, **When** they sign in and
   submit a new password without the current one, **Then** the password is changed, the flag is
   cleared, and the session is no longer restricted.
8. **Given** a mailbox whose password lives in LDAP, signed in with a passkey, **When** it tries to
   change its password, **Then** the request is refused with a "password managed externally" error.
9. **Given** a read-write API key owned by an administrator, **When** a client uses it to change the
   owner's password, **Then** the request is refused as forbidden.

---

### User Story 2 - Enrol and remove second factors, including a forced setup (Priority: P1)

A user protects their account with a second factor: an authenticator app (TOTP), a security key
(WebAuthn) or a YubiKey in Yubico OTP mode. For TOTP, the app shows a QR code and the secret once,
the user scans it, confirms a code and their current password, and names the entry. From then on,
signing in to either UI asks for the second factor. The user sees their entries, without any secret
material, and removes the ones they no longer use. When an administrator requires a second factor,
a user who has none is restricted after sign-in until they enrol one, and they can do that in the
new web app.

**Why this priority**: Feature 001 verifies second factors but leaves enrolment to this feature,
so until then users must visit the legacy UI. A forced second-factor setup blocks the user
completely.

**Independent Test**: For one account of each role, enrol each type through the API and sign in
with it through the legacy UI and through feature 001. Enrol each type in the legacy UI and remove
it through the API. Try every refusal: wrong code, wrong current password, last entry, forced
policy, external identity provider, expired enrolment.

**Acceptance Scenarios**:

1. **Given** a signed-in mailbox user, **When** they start a TOTP enrolment, **Then** they receive a
   new secret, a provisioning address that names the account and the configured issuer, and a QR
   code, all exactly once. **When** they confirm with a name, a valid code and their current
   password, **Then** the entry is saved, it is used at their next sign-in in both UIs, and the code
   they confirmed with is not accepted again for sign-in within its validity window.
2. **Given** a started TOTP enrolment, **When** the user submits a wrong code, **Then** the
   confirmation is refused and the enrolment stays open. **When** they submit five wrong codes, or
   ten minutes pass, **Then** the enrolment is cancelled.
3. **Given** a domain administrator with one WebAuthn security key, **When** they request
   enrolment options, **Then** the options exclude that key. **When** they return a new key's
   attestation with a name and their current password, **Then** the key is saved and completes the
   second-factor step of both UIs.
4. **Given** a user with a YubiKey and Yubico API credentials, **When** they submit a name, the
   credentials, an OTP from the key and their current password, **Then** the OTP is verified with
   the Yubico service and the key is saved. **When** they enrol the same YubiKey again, **Then** the
   new entry replaces the old one.
5. **Given** a user with two active entries, **When** they remove one, **Then** it is gone. **When**
   they try to remove the remaining one, **Then** the request is refused with a "last second factor"
   error. **When** they remove all second factors with their current password, **Then** every entry
   is gone and their passkeys are untouched.
6. **Given** a mailbox whose administrator requires a second factor, **When** the user tries to
   remove one entry or all of them, **Then** the request is refused with a "second factor required
   by policy" error.
7. **Given** an administrator required to set up a second factor who has none and no passkey,
   **When** they sign in, **Then** the session is restricted. **When** they enrol TOTP without
   entering their current password, **Then** the entry is saved and the restriction is lifted at
   once.
8. **Given** a user who signed in with one of three entries, **When** they list their second
   factors, **Then** each entry shows its type, name and state, the entry used for this sign-in is
   marked, and no secret, key handle or Yubico credential appears.
9. **Given** a Keycloak-source mailbox, **When** it tries to enrol a second factor, **Then** the
   request is refused.

---

### User Story 3 - Recover a forgotten password with the recovery e-mail (Priority: P1)

A mailbox user stores a private recovery address in their account, confirming the change with
their password. Months later they forget their password. On the new web app's sign-in page they
choose "Forgot password", enter their mailbox address, and receive a message at the recovery
address with a link that is valid for 15 minutes. The link opens the new web app, which asks for a
new password and, because the account has a second factor, for that factor too. The password is
then reset, every open session of the account in this API ends, and the user signs in with the new
password.

**Why this priority**: A locked-out user cannot reach any other self-service function, and the
legacy reset page is the only alternative today.

**Independent Test**: Configure the reset message in the legacy UI. Request resets for accounts in
every state (eligible, unknown, without recovery address, external source, inactive, at the token
limit) and compare the responses and the messages sent. Complete resets with and without second
factors, with tokens issued by either UI, with expired and reused tokens, and with invalid
passwords.

**Acceptance Scenarios**:

1. **Given** a mailcow-source mailbox user with the password-reset permission, **When** they set a
   recovery address and confirm with their current password, **Then** it is saved. **When** they
   submit an invalid address, **Then** it is refused with a validation error. **When** they submit
   an empty address, **Then** the recovery address is removed.
2. **Given** an eligible mailbox with a recovery address, **When** a signed-out client requests a
   reset for its address, **Then** a message built from the operator's template is sent to the
   recovery address with a link to the reset page, and the client receives the standard
   acknowledgement.
3. **Given** an unknown address, a mailbox without a recovery address, an LDAP-source mailbox, or a
   mailbox with three outstanding tokens, **When** a reset is requested, **Then** the client receives
   the same acknowledgement in the same time, and no message is sent.
4. **Given** a valid token, **When** the client checks it, **Then** it learns the account address,
   the expiry time and the password rules. **Given** a token older than 15 minutes, **When** it is
   checked or used, **Then** the response is an "invalid reset token" error.
5. **Given** a valid token for an account without second factors and with a forced password change
   pending, **When** the client submits a new password that meets the policy, **Then** the password
   is set, the forced change is cleared, every token of the account stops working, every session of
   the account in this API ends, and the new password works in both UIs.
6. **Given** a valid token for an account with TOTP, **When** the client submits only the new
   password, **Then** the response says a second factor is required and lists the methods.
   **When** it resubmits with a valid TOTP code, **Then** the password is reset. **When** it
   resubmits with a wrong code, **Then** the reset is refused and the token stays valid.
7. **Given** a server whose reset message has no sender or subject configured, **When** a reset is
   requested, **Then** the response is a "password reset not available" error.
8. **Given** a token issued through the legacy reset page, **When** it is used through this API,
   **Then** the reset succeeds, and the reverse also holds.

---

### User Story 4 - Manage app passwords (Priority: P1)

A user whose phone mail app does not support second factors creates an app password named "Phone"
that works only for IMAP and SMTP. The API generates a strong password and shows it once, and the
user types it into the phone. Later the user deactivates it while the phone is being repaired,
renames another one, and deletes an old one. An administrator, or a domain administrator with the
app-password permission, does the same for a mailbox in their scope.

**Why this priority**: Users with second factors need app passwords for every mail client, so this
is part of the minimum self-service set.

**Independent Test**: Create, list, change and delete app passwords as a mailbox user with and
without the permission, as a domain administrator with and without it, and as an administrator.
Use each app password against each protocol and against the web sign-in, and compare the stored
data with that of the legacy UI.

**Acceptance Scenarios**:

1. **Given** a mailbox user with the app-password permission, **When** they create "Phone" with IMAP
   and SMTP and a password that meets the policy, **Then** the app password is stored hashed, the
   response contains its identifier but not the password, IMAP and SMTP accept it, POP3 refuses it,
   and the web sign-in of feature 001 refuses it. **When** they create another one without giving a
   password, **Then** the response contains a generated 32-character password, and no later
   response contains it again.
2. **Given** a mailbox user without the permission, **When** they create an app password, **Then**
   the request is refused as forbidden with an error that names the flag. **When** they list their
   app passwords, **Then** the list is returned.
3. **Given** an active app password, **When** it is deactivated, **Then** mail clients using it are
   refused at their next sign-in. **When** it is renamed and given new protocols, **Then** only the
   new protocols accept it.
4. **Given** a domain administrator with the app-password permission, **When** they create an app
   password for a mailbox of their domain, **Then** it is created for that mailbox. **When** they
   request the app passwords of a mailbox outside their domains, **Then** the response is "not
   found".
5. **Given** an administrator who changes the name of a user's app password, **When** the change is
   saved, **Then** the app password still belongs to the user (legacy assigns it to the editor).
6. **Given** a mailbox with 40 app passwords, **When** the user lists them, **Then** the first 25 are
   returned with the total, and no entry contains a password or a password hash.

---

### User Story 5 - Open webmail and see one's mailbox at a glance (Priority: P2)

A mailbox user signed in to the new web app taps "Webmail" and is in their webmail immediately,
without a second sign-in. The account page shows an overview like the legacy user page: storage and
message count against the quota, the protocols they may use, the addresses that deliver to them,
the addresses they may send as, their recovery address, and how many app passwords and temporary
aliases are active. When the operator wants users sent to webmail after sign-in, the overview says
so, and the app does it.

**Why this priority**: Webmail is the main tool of most mailbox users, and the overview answers the
most frequent support questions, but users can still reach webmail through its own sign-in page.

**Independent Test**: As a mailbox user, request the webmail hand-off and open webmail in the same
browser; confirm that no sign-in is asked, that the legacy UI still shows its sign-in page in that
browser, and that a recent-login entry is recorded. Sign out of the new web app and confirm that
webmail asks for a sign-in again. Compare every value of the overview with the legacy user page.

**Acceptance Scenarios**:

1. **Given** a signed-in mailbox user on a server with webmail enabled, **When** they request
   webmail, **Then** the response gives the webmail address, opening it in the same browser shows
   their mailbox without a sign-in prompt, their recent logins show a webmail single sign-on entry
   from their address, and the request is audited.
2. **Given** the same browser, **When** it opens a legacy UI page, **Then** the legacy UI shows its
   sign-in page.
3. **Given** a webmail hand-off, **When** the user signs out of the new web app, **Then** webmail
   requires a sign-in at its next request.
4. **Given** a server with webmail disabled, **When** a user requests webmail, **Then** the request
   is refused with a "groupware disabled" error.
5. **Given** a session restricted by a forced password change, **When** the user requests webmail,
   **Then** the request is refused with feature 001's pending-action error.
6. **Given** a mailbox with two direct aliases, one shared alias with a public comment, an alias
   domain, a catch-all and one send-as address, **When** the user reads the overview, **Then** all of
   them appear as the legacy user page shows them.
7. **Given** an administrator or a domain administrator, **When** they request the overview or the
   webmail hand-off for themselves, **Then** the request is refused as forbidden.

---

### User Story 6 - Use temporary aliases (Priority: P2)

Before registering in an online shop, a user creates a temporary alias with the description "shop":
a random, readable address in their domain or one of its alias domains, valid for one year. When the
address starts receiving spam, the user lets it expire in an hour, or deletes it. Another alias they
keep forever.

**Why this priority**: Temporary aliases are a popular privacy feature, but nothing else depends on
them.

**Independent Test**: Create temporary aliases for the mailbox domain, an alias domain and an
unrelated domain, change their lifetime, let them expire, delete them, and send mail to each. Try the
operations without the permission and from a domain administrator inside and outside their scope.

**Acceptance Scenarios**:

1. **Given** a user with the temporary-alias permission, **When** they create an alias in their own
   domain with the description "shop", **Then** a random readable address is returned, it is valid
   for one year, and mail sent to it is delivered to the user.
2. **Given** a domain with an alias domain, **When** the user creates an alias in the alias domain,
   **Then** it is created. **When** they name an unrelated domain, **Then** the request is refused
   with a validation error.
3. **Given** a temporary alias, **When** the user makes it permanent, **Then** it never expires.
   **When** they set it to expire in one hour, **Then** it stops receiving mail after that hour, it
   disappears from the list, and requests for it are answered "not found".
4. **Given** a user without the permission, **When** they create, change or delete a temporary
   alias, **Then** the request is refused as forbidden. **When** they list their temporary aliases,
   **Then** the list is returned.
5. **Given** any existing mailbox, alias or temporary alias address, **When** new temporary aliases
   are generated, **Then** none of them equals an existing address.

---

### User Story 7 - Adjust mailbox settings and push notifications (Priority: P2)

A user who uses plus-addressing (`anna+shop@example.org`) chooses to have tagged mail filed into a
subfolder named after the tag. A security-conscious user enforces TLS for incoming and outgoing mail.
An administrator enforces outgoing TLS for 30 selected mailboxes in one step. Another user connects
their Pushover account, so that new clean mail triggers a notification on their phone, limited to
mail from their boss's address, and checks that the credentials are accepted.

**Why this priority**: These settings matter to the users who use them, but defaults work for
everyone else.

**Independent Test**: Change each setting as a user with and without the permission, as a domain
administrator and as an administrator, including the bulk TLS change, and verify the effect on mail
flow and on the stored data. Save, verify and remove Pushover settings with valid and invalid
values.

**Acceptance Scenarios**:

1. **Given** a user with the tagging permission, **When** they choose "subfolder", **Then** mail sent
   to `user+shop@domain` is filed into the subfolder `shop` of their inbox, as with the legacy
   setting.
2. **Given** a user with the TLS permission, **When** they enforce inbound TLS, **Then** mail
   delivered to them over a plaintext connection is refused.
3. **Given** a user without the TLS permission, **When** they change TLS enforcement, **Then** the
   request is refused as forbidden and the error names the flag. **When** they send the settings
   back with unchanged TLS values and a changed tagging value they may change, **Then** the change is
   saved.
4. **Given** an administrator and 30 mailboxes, one of which was deleted a moment ago, **When** they
   enforce outbound TLS for all 30 in one request, **Then** 29 mailboxes are changed, the deleted
   one is reported as not found, and each change has its own audit record.
5. **Given** a user with the push-notification permission, **When** they save a 30-character
   application token and user key, a title, a text, one sender address and the sound "bike",
   **Then** the settings are stored, and reading them shows only that a token and a key are set.
   **When** they verify the settings, **Then** the response reports the Pushover service's verdict.
6. **Given** a token of 29 characters or an invalid sender address, **When** the settings are saved,
   **Then** the request is refused with a validation error naming the field.
7. **Given** saved push notification settings, **When** the user removes them, **Then** no further
   notifications are sent.
8. **Given** a domain administrator without the push-notification permission, **When** they change
   a user's push notification settings, **Then** the request is refused as forbidden.

---

### User Story 8 - Review and clear recent logins (Priority: P2)

A user who received a suspicious message checks where their account has been used during the last
week: IMAP from their home address with its country, the web UI, webmail, and an app password used
from an unknown country. They clear the list after changing their password, so that new entries
stand out. An administrator looks at the same list for a mailbox while helping the user.

**Why this priority**: It is the user's main tool to notice a compromised account, but it does not
block other tasks.

**Independent Test**: Load recent-login records for several services, addresses and app passwords,
list them for periods from 1 to 31 days as the user, a domain administrator and an administrator,
clear them, and compare with the legacy user page.

**Acceptance Scenarios**:

1. **Given** IMAP logins from one public address on three days and one web sign-in, **When** the user
   lists their recent logins of the last 7 days, **Then** they see one IMAP entry with the latest
   time and the country of the address, and one web UI entry.
2. **Given** a login with an app password, **When** the list is read, **Then** the entry names the
   app password.
3. **Given** a request for 32 days, **When** it is sent, **Then** it is refused with a validation
   error that states the maximum of 31.
4. **Given** recent logins, **When** the user clears them, **Then** the list is empty in both UIs.
5. **Given** a domain administrator, **When** they list the recent logins of a mailbox outside their
   domains, **Then** the response is "not found".
6. **Given** web sign-ins recorded by upstream mailcow (`MAILCOWUI`) and by the FeldHost fork
   (`WEBUI`), **When** they are listed, **Then** both appear with the same web UI service code.

---

### User Story 9 - Manage mailbox Sieve filters (Priority: P3)

An administrator, or a domain administrator with the filter permission, keeps Sieve filters for the
mailboxes in their scope: scripts that run before (prefilter) or after (postfilter) the user's own
rules, with at most one active script of each kind per mailbox. They validate a script before saving
it. A mailbox user sees the filters that apply to them, and the active Sieve script they manage in
webmail.

**Why this priority**: Few mailboxes need administrator-managed filters, and webmail covers the
user's own rules.

**Independent Test**: Create, validate, activate, change and delete filters as an administrator and
as a domain administrator with and without the permission; deliver mail and confirm the filters
apply. List filters as a mailbox user and read the active script.

**Acceptance Scenarios**:

1. **Given** a mailbox with an active prefilter, **When** an administrator creates another active
   prefilter for it, **Then** the new one is active, the old one is inactive, and the next delivered
   message is processed by the new one.
2. **Given** a script with a syntax error, **When** it is validated or saved, **Then** the response is
   a validation error with the parser's message, and nothing is saved.
3. **Given** a domain administrator without the filter permission, **When** they create a filter,
   **Then** the request is refused as forbidden. **When** they list filters, **Then** the filters of
   their domains are returned.
4. **Given** a mailbox user with two filters, **When** they list their filters, **Then** both are
   returned. **When** they try to create one, **Then** the request is refused as forbidden.
5. **Given** a user with an active Sieve script made in webmail, **When** they read their active
   script, **Then** its text is returned. **Given** none, **Then** an empty result is returned.
6. **Given** 300 filters across 120 mailboxes, **When** an administrator lists filters filtered by one
   mailbox and by type, **Then** only the matching filters are returned, with pagination.

---

### User Story 10 - Reset the ActiveSync cache and the groupware profile (Priority: P3)

A user whose phone stopped syncing resets their ActiveSync device cache, and the phone downloads
everything again. A user whose groupware settings are broken, and whose administrator allows it,
resets their groupware profile, knowing that it deletes their contacts and calendars.

**Why this priority**: These are rare repair actions.

**Independent Test**: Reset both for users with and without the permissions, for a domain
administrator and an administrator, and on a server with webmail disabled; compare the remaining
groupware data with a legacy reset.

**Acceptance Scenarios**:

1. **Given** a user with the ActiveSync reset permission, **When** they reset their ActiveSync
   cache, **Then** the groupware's cached device state for them is removed, as in legacy.
2. **Given** a user with the profile reset permission, **When** they reset their groupware profile,
   **Then** their groupware settings, contacts, calendars and sharing permissions are removed, as in
   legacy.
3. **Given** a user without the profile reset permission (the default), **When** they request it,
   **Then** the request is refused as forbidden.
4. **Given** a server with webmail disabled, **When** either reset is requested, **Then** the request
   is refused with a "groupware disabled" error.

---

### User Story 11 - Trace self-service changes (Priority: P3)

An administrator investigating a complaint lists the audit log for a mailbox and sees every
self-service change and attempt: password changes, failed current-password checks, second-factor
changes, reset requests and completions, app passwords, temporary aliases, filters, settings,
cleared logins, groupware resets and webmail hand-offs, each with its actor, time and address, and
never a secret.

**Why this priority**: The constitution requires the records, but users can work without reading
them.

**Independent Test**: Perform each kind of change and refusal, then list the audit log filtered by
target and scan every record for secrets.

**Acceptance Scenarios**:

1. **Given** a user who changed their password and created an app password, **When** an
   administrator lists audit records for that mailbox, **Then** both appear with actor, time and
   client address, and neither contains a password.
2. **Given** a reset request for an unknown address, **When** an administrator lists reset events,
   **Then** the request appears with the attempted address and the reason "unknown account".
3. **Given** a mailbox user, **When** they request the audit log, **Then** the request is refused as
   forbidden.

---

### User Story 12 - Set up mail clients (Priority: P3)

A user with a new iPhone downloads an Apple connection profile from the new web app, chooses the
variant with calendars and contacts and asks for a new app password to be embedded, so that the
phone is configured without typing anything. Another user sets up a desktop mail client by hand and
reads the server names, ports and encryption for IMAP, SMTP and CalDAV from the client setup help.

**Why this priority**: Autodiscover covers most clients, and the legacy UI still offers both, but
users of the new web app should not need the legacy UI for it.

**Independent Test**: Download both profile variants with and without an embedded app password,
install them on iOS and macOS, and compare them with legacy `mobileconfig.php`. Read the client
configuration with the groupware enabled and disabled and compare it with the legacy help link.

**Acceptance Scenarios**:

1. **Given** a mailbox user, **When** they download the e-mail-only profile, **Then** the file
   contains the IMAP and SMTP payloads with the server's host name and TLS-wrapped ports, the
   identifiers legacy writes, and no password, and no app password is created.
2. **Given** a user with the app-password permission, **When** they download the full profile with
   an embedded app password, **Then** an app password for IMAP, SMTP and CalDAV/CardDAV is created,
   its password appears only in the file, and the creation is audited. **Given** a user without the
   permission, **Then** the request is refused with the "permission flag required" error.
3. **Given** a server with the groupware disabled, **When** the full profile is requested, **Then** it
   is refused with "groupware disabled", and the client configuration lists no CalDAV, CardDAV or
   ActiveSync entries.
4. **Given** a server whose IMAP port is mapped to 10993, **When** the user reads the client
   configuration, **Then** IMAP is reported on port 10993 with TLS, and the STARTTLS port as
   configured.
5. **Given** an administrator, **When** they request a profile or the client configuration for
   themselves, **Then** the request is refused as forbidden.

---

### Edge Cases

- **Username is a mailbox and an administrator at the same time**: self-service acts on the account
  the session belongs to (feature 001 resolves the role at sign-in).
- **Current password checked against a legacy hash format** (SSHA, MD5, PBKDF2 and the others of
  feature 001, FR-004 there): it verifies; the new password is stored in the configured scheme.
- **Mail clients after a password change**: the mail server may accept the old password for up to its
  credential cache period (300 seconds by default), as after a legacy change. The contract says so.
- **Password change while a forced change is also pending**: the change needs no current password
  and lifts that pending action; a pending second-factor setup stays.
- **Both forced actions pending**: they can be resolved in either order; the session stays
  restricted until both are resolved.
- **Account deactivated during a self-service request**: feature 001 ends the session; the request
  fails as unauthenticated.
- **API key whose owner changes their password**: the key keeps working, because keys are separate
  credentials (feature 001).
- **Recovery address equal to the mailbox's own address**: accepted, as in legacy, although it cannot
  help when the user is locked out.
- **Reset requested again within the token lifetime**: a new token is issued while fewer than three
  are outstanding; all outstanding tokens work until one is used, then all stop working.
- **Reset token used after the account's password was changed by other means**: still valid until it
  expires or one token is used, as in legacy.
- **Reset completion with a second factor that fails**: refused; the token stays valid; the failure
  counts towards throttling.
- **Account whose only second factors are deprecated U2F or HOTP entries**: a reset cannot be
  completed through this API (feature 001 accepts neither); the error tells the user to ask an
  administrator, as feature 001 does for sign-in.
- **Account with passkeys but no second factor**: the reset needs only the token, as in legacy.
- **Reset message cannot be sent** (mail server unavailable or the template's sender invalid): the
  requester still receives the standard acknowledgement; the token is discarded, the failure is
  logged and audited.
- **Reset requested through a host name that is not mailcow's**: the link uses mailcow's host name,
  as legacy does.
- **Second-factor enrolment started in one session and confirmed in another**: refused; enrolments
  are bound to the session that started them.
- **Same security key enrolled as a passkey and as a WebAuthn second factor**: allowed, as in legacy;
  they are separate entries.
- **Inactive second-factor entries** (disabled by an administrator in the legacy UI): listed with
  their state; they become active again at the next sign-in without an active second factor
  (feature 001, FR-026 there).
- **Yubico service unreachable during enrolment**: temporary failure; nothing is stored.
- **App password named with HTML special characters** (`A&B`): stored in the encoded form legacy
  uses, shown as `A&B` in both UIs.
- **App password without any protocol**: accepted, as in legacy; it cannot be used anywhere.
- **Mailbox protocol access versus app passwords**: an app password works for its own protocols even
  when the mailbox's direct access to that protocol is off, as in legacy. The contract says so.
- **Temporary alias in an alias domain that is later deleted**: removed with the alias domain
  (features 002 and 004).
- **Expired temporary alias not yet removed by mailcow's daily clean-up**: not listed and answered
  "not found"; mail to it is refused.
- **No free temporary alias address after repeated generation attempts**: temporary failure; nothing
  is stored.
- **Filter activated while another filter of the same kind is active**: the other one is deactivated
  in the same change.
- **Changing an active filter from prefilter to postfilter**: the active postfilter, if any, is
  deactivated.
- **Script larger than 64 KiB**: refused with a validation error stating the limit.
- **Mail server unreachable when the active Sieve script is read**: temporary failure.
- **Push notification settings saved for the first time without token or key**: refused; both are
  required until stored.
- **Pushover service unreachable during verification**: temporary failure.
- **Tagging or TLS value sent with its current value by a user without the permission**: accepted.
- **Bulk TLS change naming the same mailbox twice**: processed and reported once.
- **Shared store unavailable** during a tagging change: temporary failure, nothing changes.
- **Recent-login entry from a private or internal address**: marked as internal, without a country.
- **Country lookup slow or unavailable**: the list is returned without the missing countries within
  the response time of SC-010.
- **Clearing recent logins while a mail client logs in**: the new login appears afterwards.
- **Groupware profile reset while the user is in webmail**: webmail may need a new sign-in.
- **Webmail hand-off in a browser that is signed in to the legacy UI**: the hand-off replaces that
  legacy sign-in, so the legacy UI asks for a sign-in again in that browser. The contract says so.
- **Webmail hand-off for a mailbox with webmail redirect off**: still allowed; the flag only controls
  the redirect after sign-in.
- **Webmail open in another tab when the API session expires**: webmail asks for a sign-in at its
  next request, subject to the groupware's own session handling.
- **Mailbox user's permission flag removed while a request is in flight**: the next request sees the
  change (feature 001, FR-029 there).
- **Audit record cannot be written**: the change it would record does not succeed (feature 001
  convention).
- **Embedded app password named after a long user agent**: the name is cut to the 255 characters
  that the storage allows.
- **Profile requested through a non-standard HTTPS port**: the CalDAV and CardDAV ports follow the
  port of the request, as legacy's auto-detection does, unless the operator configured them.

## Requirements *(mandatory)*

### Functional Requirements

#### Access and scope

- **FR-001**: Every signed-in account MUST be able to use the own-account functions of this feature
  for itself only: password, forced actions and second factors for every role; recovery address,
  mailbox overview, webmail hand-off, Apple connection profile and client configuration for mailbox
  users. For the mailbox objects of this feature (app passwords, temporary aliases and their
  domains, Sieve filters, active Sieve script, push notification settings, mailbox settings, recent
  logins and groupware data), administrators MUST reach every
  mailbox, domain administrators MUST reach exactly the mailboxes of the domains assigned to them
  through an active assignment, and mailbox users MUST reach only their own mailbox. Resources
  (location, thing and group) are not mailboxes for any operation of this feature.
- **FR-002**: Scope, permission flags, account status and authentication source MUST be evaluated
  from the current mailcow data on every request (feature 001, FR-029 there).
- **FR-003**: A mailbox, or a mailbox object (app password, temporary alias, filter), that does not
  exist or lies outside the caller's scope MUST get the same "not found" answer. An operation that
  the caller's role may never use, or that needs a permission flag the caller lacks, MUST be refused
  as forbidden (FR-005, FR-006).
- **FR-004**: A request authenticated by an API key MUST act with the key owner's role, scope and
  flags, and a read-only key MUST be refused on every operation that changes state (feature 001,
  FR-033 and FR-039 there). Validating a Sieve script and reading the active Sieve script count as
  reads. Verifying push notification settings counts as a change, because it acts on an external
  service for the mailbox. Own-account functions (own password, own second factors, recovery
  address, mailbox overview, webmail hand-off, Apple connection profile and client configuration)
  MUST NOT be usable with any API key and MUST be refused as forbidden. A restricted
  session (feature 001, FR-014 there) MUST be refused, except as FR-017 allows.
- **FR-005**: The operations MUST be authorised as follows. "Own domains" means the mailboxes of the
  domain administrator's domains; a flag in brackets is needed for changes only (FR-006).

  | Operation | Administrator | Domain administrator | Mailbox user |
  |---|---|---|---|
  | Change own password, resolve forced actions | Yes | Yes | Yes; password change for the mailcow source only |
  | Read the password rules for the own password | Yes | Yes | Yes |
  | Read the self-service capabilities (FR-080) | Yes | Yes | Yes; signed out: reset availability only |
  | Own second factors | Yes | Yes | Mailcow source only |
  | Own recovery address | Forbidden | Forbidden | Mailcow source (`pw_reset`) |
  | Password reset through the recovery address | Not applicable | Not applicable | Signed out, mailcow-source mailboxes |
  | Own mailbox overview, webmail hand-off | Forbidden | Forbidden | Own mailbox |
  | Apple connection profile, client configuration (FR-082, FR-083) | Forbidden | Forbidden | Own mailbox; an embedded app password needs `app_passwds` |
  | App passwords | Any mailbox | Own domains (`app_passwds` of the domain administrator) | Own (`app_passwds`) |
  | Temporary aliases | Any mailbox | Own domains | Own (`spam_alias`) |
  | Domains available for temporary aliases (FR-081) | Any mailbox | Own domains | Own |
  | List and inspect Sieve filters | Any mailbox | Own domains | Own |
  | Create, change, delete Sieve filters | Any mailbox | Own domains (`filters` of the domain administrator) | Forbidden |
  | Validate a Sieve script | Yes | Yes | Forbidden |
  | Read the active Sieve script | Any mailbox | Own domains | Own |
  | Push notification settings and their verification | Any mailbox | Own domains (`pushover` of the domain administrator) | Own (`pushover`) |
  | Tagged-mail handling | Any mailbox | Own domains | Own (`delimiter_action`) |
  | TLS enforcement | Any mailbox | Own domains | Own (`tls_policy`) |
  | Bulk TLS enforcement | Any mailboxes | Own domains | Forbidden |
  | Read and clear recent logins | Any mailbox | Own domains | Own |
  | ActiveSync cache reset | Any mailbox | Own domains | Own (`eas_reset`) |
  | Groupware profile reset | Any mailbox | Own domains | Own (`sogo_profile_reset`) |
  | Read the audit records of this feature | Yes | Forbidden | Forbidden |

- **FR-006**: Permission flags MUST be applied as legacy applies them:
  - a mailbox user acts with their own user permission flags (feature 003, FR-035 there);
  - a domain administrator acts with every user permission set, except that `app_passwds` and
    `pushover` come from the domain administrator's own flags of the same name, and filter changes
    need the domain administrator's `filters` flag;
  - an administrator acts with every flag set.

  Reads MUST NOT need the flag. A change that needs a flag the caller lacks MUST be refused as a
  whole with the "permission flag required" error, which names the flag, and nothing MUST change. A
  value sent equal to its current value MUST NOT count as a change.
- **FR-007**: A mailbox MUST be addressed by its full address and normalised as in feature 003
  (FR-007 there).

#### Own password

- **FR-008**: Every account MUST be able to change its own password by giving its current password
  and a new password. The current password MUST be verified against the stored hash in every format
  that feature 001 accepts (FR-004 there). The current password MUST NOT be required when the change
  resolves a pending forced password change (FR-015).
- **FR-009**: A new password, whether set by a change, by a reset (FR-024) or for an app password
  (FR-038), MUST:
  - be given once, without a confirmation field;
  - meet the operator's existing password policy, with the rules and values that feature 003 reports
    (FR-063 there). A password that fails MUST be refused with a validation error that names each
    unmet rule and its parameter;
  - not start with one of the hash-scheme prefixes that legacy recognises (for example
    `{BLF-CRYPT}`, `{SSHA512}`, `{PLAIN}`); such a value MUST be refused with a validation error;
  - be stored only in the hash scheme the operator configured for mailcow (`MAILCOW_PASS_SCHEME`),
    so that the mail server verifies it.
- **FR-010**: A successful own-password change MUST, as legacy does:
  - for a mailbox user: store the new hash, record the password-changed time, clear the forced
    password change flag, and update the groupware directory view and flush its cache, unless the
    groupware is disabled;
  - for an administrator or a domain administrator: store the new hash and clear the forced password
    change flag.

  It MUST NOT change app passwords, API keys or legacy UI sessions. The response MUST state how many
  active app passwords keep working, as the legacy dialog does.
- **FR-011**: A password change request MAY say whether to end the account's other sessions of this
  API. The default MUST be to end them. The current session MUST stay signed in, with a renewed
  session identifier. The response MUST state how many sessions were ended. Webmail hand-offs
  created by an ended session MUST end with it (FR-068).
- **FR-012**: A wrong current password, in any request of this feature that asks for it, MUST be
  refused with the "current password incorrect" error, MUST change nothing, MUST be audited, and MUST
  count towards feature 001's sign-in throttling for that account and client address (FR-007 there),
  including its final "too many requests" refusal. It MUST NOT be reported to fail2ban.
- **FR-013**: A mailbox whose authentication source is an external identity provider (LDAP,
  Keycloak, generic OIDC) MUST NOT change its password through this feature. The request MUST be
  refused with the "password managed externally" error.
- **FR-014**: Every signed-in account, including a session restricted by a pending forced password
  change, MUST be able to read the password rules that apply to its own new password, with the
  values of feature 003 (FR-063 there). The holder of a valid reset token MUST receive the same rules
  (FR-023).

#### Forced actions and restricted sessions

- **FR-015**: An account with a pending forced password change MUST be able to resolve it in its
  restricted session by setting a new password without the current one (FR-008 to FR-010). The
  pending action MUST be removed from the session at once, and the session MUST become a full session
  when no other action is pending.
- **FR-016**: An account with a pending forced second-factor setup MUST be able to resolve it in its
  restricted session by enrolling a TOTP, WebAuthn or Yubico OTP second factor without its current
  password (FR-029 to FR-032), in addition to registering a passkey (feature 001, FR-052 there). The
  restriction MUST be lifted at once, and later sign-ins MUST NOT be restricted for it.
- **FR-017**: In a restricted session, this feature MUST allow only: reading the self-service
  capabilities (FR-080); reading the password rules and changing the own password while a forced
  password change is pending; listing the own second
  factors and enrolling a second factor while a forced second-factor setup is pending. Every other
  operation of this feature MUST be refused with feature 001's pending-action error.

#### Recovery address

- **FR-018**: A mailcow-source mailbox user MUST be able to read their recovery address (legacy
  "password recovery email") and, when their `pw_reset` flag is set, to set, change or remove it by
  giving their current password. A change without the flag MUST be refused (FR-006). The address
  MUST be a valid e-mail address of at most 255 characters; an empty value removes it. It MUST be
  stored where legacy stores it, so that both UIs use it, and it also appears in the overview
  (FR-069).

#### Password reset

- **FR-019**: A signed-out client MUST be able to request a password reset by giving a mailbox
  address. A value that is not an e-mail address MUST be refused with a validation error. When the
  operator's reset message lacks a sender or a subject, every request MUST be refused with the
  "password reset not available" error.
- **FR-020**: Every well-formed request MUST receive the same acknowledgement, and the response time
  MUST NOT reveal whether a message was sent. A message MUST be sent only when all of these hold:
  the address belongs to an active mailbox (not a resource) with the mailcow authentication source;
  the mailbox has a valid recovery address; and the account has fewer than the maximum number of
  outstanding tokens. The `pw_reset` flag MUST NOT be checked. Every request, with its outcome and
  reason, MUST be audited.
- **FR-021**: Reset tokens MUST follow legacy:
  - five groups of six random hexadecimal characters joined by hyphens, from a cryptographically
    secure source;
  - valid for 15 minutes after issue, a server setting with the legacy default;
  - at most 3 outstanding per account, a server setting with the legacy default;
  - stored where legacy stores them, so that a token issued by either UI works in both;
  - expired tokens removed whenever a new one is requested.
- **FR-022**: The reset message MUST be built from the operator's existing template and sender (the
  defaults legacy ships when none is saved), replacing the placeholders `{{link}}`, `{{username}}`,
  `{{username2}}` (the recovery address), `{{date}}`, `{{token_lifetime}}` and `{{hostname}}` as
  legacy does. The link MUST point to the configurable reset page, by default the new web app's, on
  the host name of the request when it is mailcow's host name or one of its additional server names,
  and otherwise on mailcow's host name. The message MUST be sent through mailcow's mail server, as
  legacy sends it. A token whose message cannot be sent MUST be discarded, and the failure MUST be
  logged and audited.
- **FR-023**: A signed-out client MUST be able to check a token. A valid token MUST return the
  account address, the expiry time and the password rules (FR-014). A token that is unknown, expired,
  or belongs to a mailbox that is no longer active or no longer uses the mailcow source MUST get the
  "invalid reset token" error.
- **FR-024**: A signed-out client MUST be able to complete a reset with a valid token and a new
  password (FR-009). When the account has at least one active second factor, the completion MUST also
  carry a valid second factor of the account: a TOTP code, a Yubico OTP, or a WebAuthn assertion for a
  challenge bound to the token. Without one, the response MUST be feature 001's "second factor
  required" error with the available methods in feature 001's order (FR-020 there). Deprecated U2F
  and HOTP entries count as second factors but are never accepted (feature 001, FR-025 there), so an
  account whose only active entries are deprecated MUST get an error that tells the user to ask an
  administrator. A failed second factor MUST refuse the completion, MUST leave the token valid and
  MUST count towards throttling. The system MUST NOT keep the new password between requests.
- **FR-025**: A completed reset MUST store the new hash, record the password-changed time, clear the
  forced password change flag, remove every reset token of the account, update the groupware
  directory view for the mailbox and flush its cache (unless the groupware is disabled), and end every
  session of the account in this API together with their webmail hand-offs.
- **FR-026**: Reset requests, token checks and completions MUST be throttled per client address, by
  default to 10 attempts in 15 minutes for each of the three, and answered "too many requests" with a
  retry hint above that. Requests for one account MUST also respect the token limit of FR-021.

#### Second factors

- **FR-027**: An account MUST be able to list its own second-factor entries. Each entry MUST show an
  identifier, its type (TOTP, WebAuthn, Yubico OTP, or a deprecated U2F or HOTP entry), its name,
  whether it is active, whether this API accepts it (deprecated entries are never accepted), the
  public key identifier for Yubico OTP, and whether it was used for the current sign-in. No secret,
  key handle, public key, certificate or Yubico credential MUST ever be returned.
- **FR-028**: Second factors MUST be managed only by administrators, domain administrators and
  mailcow-source mailboxes. A mailbox with an external authentication source MUST be refused.
- **FR-029**: Enrolling a second factor and removing all second factors MUST require the current
  password (FR-012), except during a forced second-factor setup (FR-016).
- **FR-030**: TOTP enrolment MUST work in two steps:
  - starting an enrolment returns, exactly once, a new random 160-bit secret in base32, the
    provisioning address with the account's username and the configured issuer name, and a QR code
    image of that address. The enrolment is bound to the session, single-use, and expires after 10
    minutes;
  - confirming it with a name (FR-033), a current six-digit code and the current password stores the
    entry. Codes are 6 digits for 30-second steps with SHA-1, and a code from the previous or the
    next step is accepted, as in legacy. Five wrong codes cancel the enrolment. The code used to
    confirm counts as used for feature 001's replay protection (FR-023 there).

  An account MAY have several TOTP entries, as in legacy.

  The issuer name MUST be a server setting, by default the upstream `mailcow UI`; the FeldHost
  deployment sets `FeldHost™ Mailhosting UI`.
- **FR-031**: WebAuthn enrolment MUST work in two steps:
  - requesting options returns registration options bound to the server's host name and the
    account, which exclude the account's existing WebAuthn second factors and follow the server
    settings that mirror legacy's choices for user verification (not required by default), accepted
    attestation formats and trusted vendors. The options are bound to the session, single-use, and
    expire after 5 minutes;
  - completing it with a name, the authenticator's response and the current password stores the
    entry.
- **FR-032**: Yubico OTP enrolment MUST take a name, the Yubico API client identifier (digits only)
  and secret key, and an OTP of 44 letters from the key, together with the current password. The OTP
  MUST be verified with the Yubico verification service using the given credentials. A successful
  enrolment MUST replace any entry of the same account with the same public key identifier (the
  first 12 characters of the OTP), as legacy does. When the service cannot be reached, the request
  MUST fail with a temporary-failure error and store nothing.
- **FR-033**: A second-factor name MUST be given, trimmed, and between 1 and 255 characters long.
- **FR-034**: An account MUST be able to remove one of its own entries without its password. The
  request MUST be refused with the "second factor required by policy" error when the account is
  flagged for forced second-factor setup, and with the "last second factor" error when the entry is
  the account's only active entry.
- **FR-035**: An account MUST be able to remove all of its second-factor entries with its current
  password, unless it is flagged for forced second-factor setup ("second factor required by
  policy"). Passkeys (feature 001) MUST NOT be affected.
- **FR-036**: Entries MUST be stored where and as legacy stores them, active from the start, so that
  entries enrolled through this API complete the second-factor step of the legacy UI and of feature
  001, and entries enrolled in the legacy UI can be listed and removed here.

#### App passwords

- **FR-037**: Clients MUST be able to list the app passwords of a mailbox in their scope with
  pagination (default 25, maximum 1,000, as the cross-feature conventions set), sorting by name,
  creation time, modification time or
  active state (default name, ascending), a filter on the active state and a search on the name, and
  to inspect one. An app password MUST be represented with its identifier, name, mailbox, allowed
  protocols, active state, and creation and modification times, and never with its password or hash.
- **FR-038**: Creating an app password MUST take:
  - a name, trimmed, 1 to 255 characters in the form legacy stores (HTML special characters
    encoded), returned as plain text;
  - a password given by the client (FR-009) or, when none is given, a password generated by the
    system: 32 random characters that meet the password policy;
  - the allowed protocols, any subset of IMAP, POP3, SMTP, Sieve, ActiveSync and CalDAV/CardDAV, all
    six by default;
  - the active state, active by default.

  A generated password MUST be returned exactly once, in the creation response. A given password
  MUST NOT be returned.
- **FR-039**: Changing an app password MUST change only the values sent: name, password (given, or
  generated and returned once as in FR-038), protocols and active state. The mailbox it belongs to
  MUST never change.
- **FR-040**: Deleting an app password MUST remove it at once.
- **FR-041**: The contract MUST state that an app password authenticates only to its allowed
  protocols, independently of the mailbox's own protocol access; that it never signs in to the web
  UI or webmail; and that changes apply at the mail server's next authentication.

#### Temporary aliases

- **FR-042**: Clients MUST be able to list the temporary aliases of a mailbox in their scope that are
  permanent or not yet expired, with pagination as in FR-037, sorting by address, expiry or creation
  time (default creation time, newest first), a filter on permanence and a search on address and
  description. A temporary alias MUST be represented with its address, mailbox, description, expiry
  time or permanence, and creation and modification times.
- **FR-043**: Creating a temporary alias MUST take a domain, which MUST be the mailbox's domain or an
  alias domain of it, an optional description of at most 255 characters, and either a lifetime of 1
  to 87,600 hours (8,760, one year, by default) or permanence. The response MUST contain the
  generated address.
- **FR-044**: The address MUST be generated with a cryptographically secure source in the readable
  legacy form: two dot-separated parts of alternating consonants and vowels, each 6 to 8 letters
  long. It MUST NOT equal the address of any mailbox, resource, alias, alias-domain address of a
  mailbox or temporary alias; the system MUST generate another one on a collision.
- **FR-045**: Changing a temporary alias MUST set either a new lifetime of 1 to 87,600 hours, counted
  from now, or permanence. Nothing else can change.
- **FR-046**: Deleting a temporary alias MUST remove it at once. An expired temporary alias MUST be
  treated as not existing by every operation, and mail to it MUST be refused, as the mail server does
  today. mailcow's daily clean-up removes it.

#### Sieve filters

- **FR-047**: Administrators and domain administrators MUST be able to list the filters of all
  mailboxes in their scope, and every permitted caller (FR-005) the filters of one mailbox, with
  pagination as in FR-037, sorting by description, mailbox, kind, active state, creation or
  modification time (default mailbox, then description), filters on mailbox, kind and active state,
  and a search on description and mailbox. A filter MUST be represented with its identifier, mailbox,
  description, kind (prefilter or postfilter), script, active state, and creation and modification
  times.
- **FR-048**: Creating a filter MUST take a mailbox, a description of 1 to 255 characters, a kind, a
  script and an active state (active by default). The script MUST be at most 64 KiB and MUST pass the
  Sieve validation legacy applies; otherwise the request MUST be refused with a validation error that
  carries the parser's message.
- **FR-049**: Changing a filter MUST change only the values sent, with the rules of FR-048. The
  mailbox MUST NOT change.
- **FR-050**: Deleting a filter MUST remove it at once.
- **FR-051**: At most one prefilter and one postfilter MUST be active per mailbox. Activating a filter,
  or changing the kind of an active filter, MUST deactivate the other active filter of that kind for
  the mailbox in the same change, as legacy does. Filters MUST be stored where legacy stores them, so
  that the mail server applies them to the next delivered message.
- **FR-052**: Administrators and domain administrators MUST be able to validate a script without
  saving it. The answer MUST say whether the script is valid and, when it is not, carry the parser's
  message.
- **FR-053**: Every permitted caller MUST be able to read the active Sieve script of a mailbox in
  scope, which the user manages in webmail, as the mail server reports it through mailcow's
  container service. A mailbox without an active script MUST return an empty result. When the mail
  server cannot be reached, or does not answer within the container-service timeout of the
  cross-feature conventions, the request MUST fail with a temporary-failure error.

#### Push notifications

- **FR-054**: Clients MUST be able to read the push notification settings of a mailbox in scope:
  whether they are configured, the active state, whether an application token and a user key are
  stored (never their values), the title, the text, the sender addresses, the
  sender pattern, the sound, whether the message priority is evaluated, and whether only
  high-priority messages notify. A mailbox without settings MUST return "not configured" with the
  defaults of FR-055.
- **FR-055**: Clients MUST be able to replace the settings as a whole:
  - application token and user key: exactly 30 letters or digits each. Both are required while none
    are stored; when omitted later, the stored values MUST be kept;
  - title: at most 250 characters, `Mail` when empty; text: at most 1,024 characters,
    `You've got mail 📧` when empty;
  - sender addresses: at most 100, each a valid e-mail address, stored in the legacy form
    (comma-separated, dots removed from the local part of `gmail.com` addresses);
  - sender pattern: empty, or a valid regular expression in the delimited form legacy uses, at most
    1,024 characters;
  - sound: 1 to 20 letters, digits, `-` or `_`, `pushover` by default; the contract MUST list
    Pushover's built-in sounds;
  - active state (active by default), "evaluate priority" and "only high priority" (both off by
    default).

  The contract MUST document the placeholders `{SUBJECT}`, `{SENDER}`, `{SENDER_ADDRESS}`,
  `{SENDER_NAME}`, `{TO_NAME}`, `{TO_ADDRESS}` and `{MSG_ID}`, and that notifications cover clean
  mail delivered to the mailbox and its aliases. The settings MUST be stored where legacy stores
  them, so that mailcow's existing notification pipeline uses them unchanged.
- **FR-056**: Clients MUST be able to remove the settings, after which no notification is sent.
- **FR-057**: Clients MUST be able to verify the stored credentials with the Pushover validation
  service, without sending a notification. The answer MUST say whether Pushover accepted them, with
  Pushover's reason when it did not. Unconfigured settings MUST be refused with the "push
  notifications not configured" error; an unreachable service MUST give a temporary-failure error.

#### Mailbox settings

- **FR-058**: Clients MUST be able to read the mailbox settings of a mailbox in scope: the tagged-mail
  handling (subfolder, subject or none), inbound TLS enforcement and outbound TLS enforcement. The
  contract MUST explain each value, including that TLS enforcement also covers alias-domain addresses
  and aliases whose only target is the mailbox, and that mail which cannot meet it is bounced.
- **FR-059**: Clients MUST be able to change any of the three values; values not sent keep their
  current value. Changing the tagged-mail handling needs `delimiter_action` and changing TLS
  enforcement needs `tls_policy` (FR-006). The tagged-mail handling MUST be stored in mailcow's
  shared store and TLS enforcement in the mailbox, where legacy stores them, so that the mail filter
  and the mail server apply them to the next message.
- **FR-060**: Administrators and domain administrators MUST be able to set inbound and/or outbound
  TLS enforcement for up to 100 mailboxes in one request, following the bulk-action rules of the
  cross-feature conventions. The whole request MUST be refused only when the caller's role or key
  scope does not allow it. Otherwise each mailbox MUST be authorised, changed and audited on its own,
  MAY carry the version tag of its mailbox settings, and MUST get one result: done, not found,
  refused, outdated, or failed with its error type. A failure MUST NOT stop the others, and a mailbox
  named twice MUST be processed and reported once.

#### Recent logins

- **FR-061**: Clients MUST be able to list the recent logins of a mailbox in scope for a period of 1
  to 31 days (7 by default), grouped by client address, service and app password with the latest time
  of each group, as legacy groups them. Each entry MUST show the service, the client address, whether
  the address is private or internal, its country where known, the app password used (identifier and
  name) where there was one, and the time. Service codes MUST be returned as stored and upper-cased,
  except that web UI sign-ins (`MAILCOWUI` upstream, `WEBUI` in the FeldHost fork) MUST be reported
  with one documented code. The list MUST support pagination as in FR-037, sorting by time (default,
  newest first), service or address, and a filter on the service.
- **FR-062**: Countries MUST come from mailcow's shared cache of address countries. When a public
  address is not cached, the system MAY look it up with the external lookup service legacy uses and
  store the result in the cache. The lookup MUST be a server setting, on by default as in legacy, and
  MUST NOT delay the response beyond SC-010; an address whose lookup does not finish in time is
  reported without a country.
- **FR-063**: Clients MUST be able to clear the recent logins of a mailbox in scope. Clearing MUST
  remove all of the mailbox's recent-login records and, when the caller is an administrator or the
  mailbox user themself, also the legacy UI's records of the mailbox's successful sign-ins, as legacy
  does. Clearing MUST be a state-changing request.

#### Groupware data

- **FR-064**: Clients MUST be able to reset the ActiveSync cache of a mailbox in scope (`eas_reset`),
  which MUST remove the groupware's cached device state for the mailbox, as legacy does.
- **FR-065**: Clients MUST be able to reset the groupware profile of a mailbox in scope
  (`sogo_profile_reset`), which MUST remove, as legacy does, the mailbox's groupware settings, cached
  device state, sharing permissions on and by the mailbox, and its folders with their contacts and
  events. The contract MUST state that this cannot be undone.
- **FR-066**: When the groupware is disabled on the server, both resets MUST be refused with the
  "groupware disabled" error.

#### Webmail and own mailbox

- **FR-067**: A mailbox user with a full session MUST be able to obtain a webmail hand-off for their
  own mailbox. Webmail MUST be enabled on the server; otherwise the request MUST be refused with the
  "groupware disabled" error. (The mailbox and its domain are active, or feature 001 would have
  ended the session.) On success, the
  browser that made the request MUST be signed in to webmail as that mailbox through mailcow's
  existing webmail single sign-on, without any change to a mailcow container, and the response MUST
  give the webmail address to open. The system MUST record the recent-login entry legacy records for
  webmail single sign-on (service `SSO`, client address) and MUST audit the hand-off.
- **FR-068**: A webmail hand-off MUST:
  - grant webmail access only: it MUST NOT sign the browser in to the legacy UI and MUST NOT allow
    changes through `/api/v1`;
  - work only in the browser that requested it;
  - replace an existing legacy UI sign-in of that browser, which the contract MUST state;
  - end when the session of this API that created it ends, whether by sign-out, expiry or FR-011 and
    FR-025 (at once, except after an expiry, which no request reveals: then within one minute), and
    at the latest after the legacy session lifetime of inactivity (3 hours by default);
  - be reusable for webmail on behalf of a user (feature 007, FR-054 to FR-056 there).
- **FR-069**: A mailbox user MUST be able to read an overview of their own mailbox:
  - address, display name, domain and authentication source;
  - quota, storage used, message count and percentage used, as feature 003 computes them;
  - protocol access for the six protocols;
  - the webmail redirect preference (legacy "SOGo access") and whether webmail is enabled;
  - when the password was last changed, and the recovery address for the mailcow source;
  - direct aliases (aliases whose only target is the mailbox), shared aliases (aliases with the
    mailbox among several targets) with their public comments, and the mailbox's addresses in alias
    domains;
  - the addresses and domains the mailbox may send as, and whether it may send as any address;
  - the catch-all addresses that deliver to it;
  - the domains available for temporary aliases;
  - the number of active app passwords and of active temporary aliases, and whether push
    notifications are active.

  Texts MUST be returned as plain text.

#### Self-service capabilities

- **FR-080**: Every signed-in account MUST be able to read the self-service capabilities, the server
  settings and limits a client needs before it submits:
  - whether the groupware and webmail are enabled (`SKIP_SOGO`);
  - whether password reset is available (FR-019) and the reset token lifetime;
  - the temporary alias lifetime range and default, the recent-login period range and default, the
    bulk limit, and the default and maximum page sizes;
  - the size limits of this feature (names, descriptions, scripts, notification texts, sender
    lists) and Pushover's built-in sounds;
  - for a signed-in session, the account itself: whether it is required to keep at least one second
    factor (legacy `force_tfa`), so that clients can warn before a removal that FR-034 or FR-035
    would refuse; whether it may change its password here (FR-013); and whether it may manage second
    factors here (FR-028).

  Signed-out clients MUST be able to read whether password reset is available and the token
  lifetime, so that the sign-in page offers "Forgot password" only when it works. The values MUST
  reflect the server's current configuration, MUST NOT include any secret, and the read MUST change
  nothing. Read-only keys MAY use it.

#### Temporary-alias domains, Apple connection profile and client configuration

- **FR-081**: Every caller who may manage the temporary aliases of a mailbox (FR-005) MUST be able
  to read the domains available for that mailbox's temporary aliases: the mailbox's domain first,
  then its alias domains, as FR-043 accepts them. The list MUST be the same as the overview's
  (FR-069) for the mailbox user.
- **FR-082**: A mailbox user with a full session MUST be able to download an Apple connection
  profile (`.mobileconfig`) for their own mailbox, with the payloads, keys and identifiers that legacy
  `mobileconfig.php` writes: an IMAP account with its SMTP server on the TLS-wrapped ports of the
  server's autodiscover settings and, unless the e-mail-only variant is chosen, a CalDAV and a
  CardDAV account (`/SOGo/dav/<address>`). The file name MUST be the product name followed by
  `.mobileconfig`. The profile MUST NOT be signed, as in legacy. All values MUST be escaped for XML.
  - The client MAY ask for a new app password to be embedded. It MUST be created as in FR-038, with
    a generated password, the protocols IMAP and SMTP (plus CalDAV/CardDAV for the full variant),
    active, and named as legacy names it (`iPad`, `iPhone` or `Mac` after the client's user agent,
    otherwise the user agent) unless the client gives a name. It MUST need the app-password
    permission (FR-006), MUST be audited as an app password creation, and its password MUST appear
    only in that file.
  - When the groupware is disabled, the variant with calendars and contacts MUST be refused with
    the "groupware disabled" error.
- **FR-083**: A mailbox user with a full session MUST be able to read the client configuration of
  their own mailbox that the legacy client setup help shows: the address and display name; for
  IMAP, POP3 and SMTP the host name, the TLS-wrapped port and the STARTTLS port; for CalDAV and
  CardDAV the host name, port and principal path, and the ActiveSync address, only when the
  groupware is enabled; the authentication method (the account's password or an app password); the
  general autodiscover type and whether Outlook is set up with ActiveSync; and the address of the
  client documentation page with the parameters legacy appends to it. The values MUST come from the
  server's autodiscover settings, including the operator's local overrides and the ports configured
  in `mailcow.conf`.

#### Version tags

- **FR-070**: App passwords, temporary aliases, filters, push notification settings, mailbox
  settings and the recovery address MUST carry a version tag that changes whenever the stored values
  change, through this API, the legacy UI or `/api/v1`. The tag of mailbox settings MUST cover only
  the three settings. Every list entry MUST carry its version tag.
- **FR-071**: A client MAY send the version tag it read with a change, a replacement or a deletion.
  When the tag no longer matches, the request MUST be refused as outdated and MUST change nothing, as
  in feature 002 (FR-042 there).

#### Reliability

- **FR-072**: When mailcow's shared store, the mail server or the container service that an
  operation needs is unavailable, the operation MUST fail with a temporary-failure error and leave no
  partial change.

#### Audit

- **FR-073**: Every request of this feature that changes state, or tries to, MUST write exactly one
  audit record per affected object into feature 001's audit log, with the fields of FR-046 there and:
  - the target type (account password, second factor, recovery address, password reset, app
    password, temporary alias, Sieve filter, push notification settings, mailbox settings, recent
    logins, groupware data, webmail hand-off) and the target identifier, including the mailbox;
  - the outcome, including refusals and their reason (for example current password incorrect, unknown
    account for a reset, token limit, message not sent, last second factor);
  - for successful changes, each changed value with its old and new value. Passwords, second-factor
    secrets, Yubico credentials, one-time codes, reset tokens and push credentials MUST be recorded
    only as "set" or "changed"; scripts and notification texts only as changed with their old and new
    sizes;
  - for a password change, the number of sessions ended; for a bulk TLS change, the identifier of the
    bulk request.
- **FR-074**: Administrators MUST be able to filter the audit log by target type and target
  identifier, including the mailbox, in addition to the filters of feature 001 (FR-047 there).

#### Cross-cutting

- **FR-075**: Errors MUST follow feature 001's conventions (FR-049 and FR-055 there). This feature
  MUST document at least these error types: current password incorrect, password managed externally,
  password reset not available, invalid reset token,
  enrolment expired, last second factor, second factor required by policy, push notifications not
  configured, groupware disabled, outdated version and temporary failure (with the unavailable
  service), and reuse feature 001's second factor required, second factor failed, too many requests
  and pending action required, and the shared "permission flag required" error, which names the flag in
  `requiredFlags` (`docs/conventions.md`). Validation errors MUST carry stable rule codes with their parameters,
  including each unmet password rule, the lifetime range, the period range, the size limits and the
  parser message.
- **FR-076**: Passwords, current passwords, second-factor secrets after the enrolment start, Yubico
  credentials, one-time codes, WebAuthn responses, reset tokens, app passwords and push credentials
  MUST NOT appear in any response, log line or audit record. The only exceptions are the TOTP
  secret, its provisioning address and its QR code in the response that starts an enrolment
  (FR-030), and a generated app password in the response that creates or changes it (FR-038,
  FR-039). Request logging MUST redact them.
- **FR-077**: Every operation MUST be described in the published API contract before it is
  implemented, and its behaviour MUST match the contract.
- **FR-078**: Changes made through the legacy UI or `/api/v1` MUST be visible in the next response of
  this API.
- **FR-079**: Apart from the bulk TLS change (FR-060), this feature MUST NOT offer batch operations.

### Legacy Coverage

Every legacy capability allocated to feature 005 in `docs/roadmap.md` maps to requirements as
follows. Actions are `/api/v1` names; *form* marks legacy UI form handlers and helper pages.

| Legacy action or form | Legacy implementation | Requirements |
|---|---|---|
| `edit/self` for a mailbox user: password change, *form* "Change password" dialog | `edit_user_account()` | FR-008 to FR-012, FR-013, FR-014 |
| `edit/self` for a domain administrator: password change | `domain_admin('edit')`, self branch | FR-008 to FR-012 |
| Administrator's own password (`edit/admin` on oneself) | `admin('edit')` | FR-008 to FR-012 |
| Forced password change, *form* "Change password" dialog after sign-in | `pending_pw_update`, `edit/self` or `edit/admin` | FR-015, FR-017 |
| Recovery address, *form* "Password recovery email" dialog (`edit/self`) | `edit_user_account()`, `pw_reset` check | FR-018, FR-069 |
| *form* `pw_reset_request` (reset page, request form) | `reset_password('issue')`, `send_mail` | FR-019 to FR-022, FR-026 |
| *form* reset page token check | `reset_password('check')` | FR-023, FR-014 |
| *form* `pw_reset`, with the second-factor step for accounts with second factors | `reset_password('reset')`, `verify_tfa_login` with `pending_pw_reset_token` | FR-024, FR-025, FR-026 |
| `get/webauthn-tfa-registration`, `add/webauthn-tfa-registration` | `WebAuthn->getCreateArgs`, `processCreate`, `set_tfa('webauthn')` | FR-029, FR-031, FR-033, FR-036 |
| *form* `set_tfa` with `totp`, TOTP QR code (`inc/ajax/qr_gen.php`) | `set_tfa('totp')`, `RobThree\TwoFactorAuth` | FR-029, FR-030, FR-033, FR-036 |
| *form* `set_tfa` with `yubi_otp` | `set_tfa('yubi_otp')`, `Auth_Yubico` | FR-029, FR-032, FR-033, FR-036 |
| *form* `set_tfa` with `none` ("Disable two-factor authentication") | `set_tfa('none')` | FR-035 |
| *form* `unset_tfa_key` | `unset_tfa_key()` | FR-034 |
| Second-factor list on the user, domain administrator and administrator pages | `get_tfa()`, `tfa_keys.twig` | FR-027 |
| Forced 2FA setup, *form* "Set up two-factor authentication" dialog | `pending_tfa_setup`, `set_tfa` without password | FR-016, FR-017 |
| `add/app-passwd`, `get/app-passwd/all[/{mailbox}]`, `get/app-passwd/{id}`, `edit/app-passwd`, `delete/app-passwd`, *form* app password page | `app_passwd()` | FR-037 to FR-041, FR-006 |
| `add/time_limited_alias`, `get/time_limited_aliases[/{mailbox}]`, `edit/time_limited_alias`, `delete/time_limited_alias` | `mailbox('add'/'get'/'edit'/'delete', 'time_limited_alias(es)')` | FR-042 to FR-046 |
| `add/filter`, `edit/filter`, `delete/filter`, `get/filters/all`, `get/filters/{mailbox}`, *form* "Filters" tab and filter page | `mailbox(…, 'filter')`, `mailbox('get', 'filters' / 'filter_details')` | FR-047 to FR-051 |
| *form* Sieve validation (`inc/ajax/sieve_validation.php`) | `Sieve\SieveParser` | FR-052 |
| `get/active-user-sieve/{mailbox}`, *form* "Show active Sieve filters" | `mailbox('get', 'active_user_sieve')`, dockerapi `sieve list/print` | FR-053 |
| `edit/pushover` (including `delete`), *form* Pushover tab | `pushover('edit')`, `pushover('get')` | FR-054 to FR-056 |
| `edit/pushover-test` | `pushover('test')` | FR-057 |
| `edit/delimiter_action`, tagging buttons on the user page and the edit-mailbox page | `mailbox('edit' / 'get', 'delimiter_action')` | FR-058, FR-059 |
| `edit/tls_policy`, TLS buttons and the mailbox list's TLS mass actions | `mailbox('edit' / 'get', 'tls_policy')` | FR-058 to FR-060 |
| `get/last-login/{mailbox}/{days}` | `last_login('get')` | FR-061, FR-062 |
| `get/reset-last-login/{mailbox}` | `last_login('reset')` | FR-063 |
| `delete/eas_cache` | `mailbox('delete', 'eas_cache')` | FR-064, FR-066 |
| `delete/sogo_profile` | `mailbox('delete', 'sogo_profile')` | FR-065, FR-066 |
| Webmail SSO ("Webmail" button, `sogo-auth.php`, session flag `sogo-sso-user-allowed`) | `set_user_loggedin_session()`, `sogo-auth.php` | FR-067, FR-068 |
| User page "Overview" and "Mailbox details" | `mailbox('get', 'mailbox_details')`, `user_get_alias_details()` | FR-069 |
| Domain select of the temporary-alias form (mailbox domain and alias domains) | `user_get_alias_details()['alias_domains']`, `mailbox('add', 'time_limited_alias')` | FR-081 |
| *form* Apple connection profile links on the user page (`mobileconfig.php`, `?only_email`, `?app_password`) | `mobileconfig.php`, `password_generate()`, `app_passwd('add')` | FR-082 |
| *form* client configuration help link on the user page | `user.php` (`clientconfigstr`), `$autodiscover_config` | FR-083 |

Parts of the legacy user page and related screens that belong to other features: quarantine
notification frequency and category (006), the spam filter tab (006), sync jobs (008), passkeys
(001), the global prefilter and postfilter on the "Filters" tab and the Sieve presets (013), the
reset message template (013), the password policy (003 read, 013 edit), and webmail on behalf of a
user (007). The autodiscover and autoconfig endpoints that mail clients call (`autodiscover.php`,
`autoconfig.php`) are internal endpoints that stay in php-fpm (feature 014). The Apple connection
profile and the client configuration help, which earlier drafts left unallocated, belong to this
feature (FR-082, FR-083), as `docs/roadmap.md` now allocates them.

### Legacy Parity and Deliberate Deviations

Parity reference: `source_code/data/web/inc/functions.inc.php` (`edit_user_account`,
`user_get_alias_details`, `set_tfa`, `unset_tfa_key`, `get_tfa`, `verify_tfa_login`,
`reset_password`, `password_check`, `password_complexity`, `hash_password`, `last_login`,
`set_sasl_log`, `update_sogo_static_view`, `getBaseURL`, `set_user_loggedin_session`,
`hasMailboxObjectAccess`, `hasACLAccess`), `functions.app_passwd.inc.php`,
`functions.mailbox.inc.php` (`time_limited_alias`, `time_limited_aliases`, `filter`, `filters`,
`filter_details`, `active_user_sieve`, `delimiter_action`, `tls_policy`, `eas_cache`,
`sogo_profile`), `functions.pushover.inc.php`, `functions.admin.inc.php` and
`functions.domain_admin.inc.php` (`edit`), `functions.acl.inc.php` (`to_session`),
`functions.auth.inc.php` (`apppass_login`), `triggers.user.inc.php`, `triggers.global.inc.php`,
`sessions.inc.php`, `sogo-auth.php`, `reset-password.php`, `json_api.php`, `mobileconfig.php`,
`user.php` (`clientconfigstr`), `vars.inc.php` (`PW_RESET_TOKEN_LIMIT`, `PW_RESET_TOKEN_LIFETIME`,
`OTP_LABEL`, `WEBAUTHN_UV_FLAG_REGISTER`, `FIDO2_FORMATS`, `SESSION_LIFETIME`,
`$autodiscover_config`), `init_db.inc.php` (tables `mailbox`, `admin`, `tfa`,
`app_passwd`, `spamalias`, `sieve_filters` and the views `sieve_before`/`sieve_after`, `pushover`,
`sasl_log`, `reset_password`, `user_acl`, `da_acl`, `sogo_*`, `logs`), the templates `user.twig`,
`user/*.twig`, `domainadmin.twig`, `admin/tab-config-admins.twig`, `modals/user.twig`,
`modals/footer.twig`, `reset-password.twig`, `mailbox/tab-filters.twig`, `edit/app-passwd.twig`,
`edit/filter.twig`, `js/site/user.js`, `base.twig`, the reset templates in `assets/templates/`,
`Dockerfiles/postfix/postfix.sh` (TLS and temporary alias maps),
`Dockerfiles/phpfpm/docker-entrypoint.sh` (clean-up events, session store, password policy defaults),
`Dockerfiles/dockerapi/modules/DockerApi.py` (`sieve list/print`),
`conf/rspamd/meta_exporter/pushover.php` and `conf/dovecot/auth/mailcowauth.php`.

The reference is upstream mailcow-dockerized `2026-09a`. `source_code/` points to the FeldHost fork
(`~/Projects/mailhosting`, branch `feldhost-202609a`), so the files above were diffed against that
upstream tag. All self-service business logic, the reset flow and its templates, the webmail single
sign-on, Pushover and the second-factor code are identical. The fork differs in four ways that touch
this feature:

1. The TOTP issuer label (`OTP_LABEL`) is `FeldHost™ Mailhosting UI` instead of `mailcow UI`. It
   becomes a server setting with the upstream default (FR-030).
2. Web UI sign-ins are recorded in the recent logins with the service `WEBUI` instead of
   `MAILCOWUI` (feature 001, FR-027 there), and the fork's user page also displays stored
   `MAILCOWUI` entries as `WEBUI`. This API reports both with one code (FR-061).
3. Webmail's toolbar button that leads back to the legacy user page is labelled with the fork's
   product name. Its target stays the legacy user page (see Assumptions).
4. Branding texts, such as the label of the `pw_reset` permission, change only display names.

| Behaviour | Legacy | This feature |
|---|---|---|
| Own password, mailbox user | Current password via `verify_hash` (mailcow source only); `password_check` with two fields; `hash_password`; `force_pw_update` set to `0`, `passwd_update` set; full `update_sogo_static_view()` and memcached flush | Same storage and side effects (FR-008 to FR-010). One field. **Deviation**: other sessions of this API end by default (FR-011) |
| Own password, domain administrator | `edit/self`: current password, `force_pw_update` `0` | Same (FR-008 to FR-010) |
| Own password, administrator | `edit/admin` on oneself, no current password | Current password required (FR-008). **Deviation** (ASVS 2.1.6) |
| Forced password change | Restricted session; no current password; flag cleared | Same (FR-015) |
| Pre-hashed values | Any value with a recognised `{SCHEME}` prefix stored as given, for own passwords, resets and app passwords | Refused (FR-009). **Deviation** (security fix: bypassed the policy) |
| Wrong current password | `access_denied`, no delay, no fail2ban | Dedicated error, audited, counted towards throttling, no fail2ban (FR-012). **Deviation** |
| External authentication source | Password change offered only to the mailcow source; second factors offered to mailcow and LDAP (password checked by LDAP bind) | Password change for the mailcow source (FR-013); second factors for the mailcow source only (FR-028). **Deviation** for LDAP until feature 012 |
| Recovery address | Needs `pw_reset` and the current password; mailcow source only; an invalid address is silently stored as empty | Same gate; invalid addresses refused (FR-018). **Deviation** |
| Reset request responses | Different messages for an invalid user, a missing recovery address, the token limit and a failed message; the masked recovery address on success | One acknowledgement in constant time; per-address throttling (FR-020, FR-026). **Deviation** (anti-enumeration) |
| Reset eligibility | Mailcow-source mailbox with a valid recovery address and fewer than `PW_RESET_TOKEN_LIMIT` (3) tokens; mailbox state not checked at request; `pw_reset` not checked | Same, and only active mailboxes get a message (FR-020). **Deviation** for inactive mailboxes, whose token legacy would refuse anyway |
| Reset token | Five groups of six random hexadecimal characters, stored as plain text in `reset_password`, valid `PW_RESET_TOKEN_LIFETIME` (15) minutes, expired tokens removed at each request | Same, shared with the legacy UI (FR-021) |
| Reset message | Template and sender from `PW_RESET_*` in the shared store or `/tpls/pw_reset_*.tpl`; placeholders; link `getBaseURL()/reset-password?token=…`; sent through the mail server without authentication; the token stays when sending fails | Same template, placeholders, host rule and sending; reset page configurable, by default the new web app's (FR-022). **Deviation**: the token is discarded when sending fails |
| Reset completion | Policy check; second factor required when the account has active second-factor entries, with the new password kept in the session meanwhile; hash, `passwd_update`, all tokens deleted, `update_sogo_static_view(username)`; forced change flag kept; sessions untouched | Same checks and side effects without keeping the password (FR-024, FR-025). **Deviation**: the forced change flag is cleared and every session of this API ends |
| Second-factor enrolment confirmation | Current password (administrator table first, then mailbox), skipped during forced setup | Same (FR-029) |
| TOTP enrolment | 80-bit secret generated at page render and sent back by the browser in a hidden field; QR code generated by the server; 6 digits, 30 s, SHA-1, ±1 step; issuer `OTP_LABEL`; several TOTP entries allowed | Secret kept by the server for the pending enrolment, 160 bits, five attempts, 10 minutes (FR-030). **Deviation** (security). Same algorithm, QR code and multiple entries |
| WebAuthn enrolment | Options exclude existing WebAuthn entries; user verification per `WEBAUTHN_UV_FLAG_REGISTER` (false); formats per `FIDO2_FORMATS`; challenge kept in the session until replaced; stored in `tfa` (`keyHandle`, `publicKey`, `certificate`, counter 0) | Same options and storage; options single-use for 5 minutes (FR-031). **Deviation** |
| Yubico OTP enrolment | 44-character OTP verified with the given API credentials; entries with the same key identifier replaced; stored as `id:key:modhex` | Same (FR-032) |
| Second-factor name | Defaults to `unidentified` when absent | Required, 1 to 255 characters (FR-033). **Deviation** |
| Removing one entry | Refused under `force_tfa` and for the last active entry; no password | Same (FR-034) |
| Removing all entries | Current password; refused under `force_tfa`; passkeys kept | Same (FR-035) |
| Second-factor list | Active entries only | All entries with their state and usability, never secrets (FR-027). **Deviation** |
| App password permission | Not checked by the server; the UI hides the tab | Enforced for changes (FR-006). **Deviation** (security fix) |
| App password reads | `SELECT *`, including the password hash | Never the password or its hash (FR-037). **Deviation** (security fix) |
| App password defaults | Server: no protocols and inactive when omitted. UI: all protocols, active | UI defaults (FR-038). **Deviation** from the server |
| App password generation | The UI generates a 32-character password in the browser; the server never generates one | Given by the client, or generated by the system when omitted and shown once (FR-038). **Deviation** (addition, cross-feature conventions) |
| App password changed by an administrator or domain administrator | Ownership rewritten to the editor's username and a new password silently not applied | Ownership never changes and the password is applied (FR-039). **Deviation** (bug fix) |
| App password name | Stored HTML-encoded | Same storage, plain text in the API (FR-038) |
| App password behaviour | Per-protocol flags only, independent of mailbox protocol access; never for the web UI | Same, documented (FR-041) |
| Temporary alias address | Non-secure random generator; parts of 2 to 8 letters; collisions with mailboxes or aliases not checked | Secure random generator, parts of 6 to 8 letters, collisions refused and regenerated (FR-044). **Deviation** (security fix) |
| Temporary alias lifetime | 1 to 87,600 hours at creation (default 8,760); any value on change; permanence | Same range for both (FR-043, FR-045). **Deviation** for changes |
| Temporary alias description | No limit | At most 255 characters (FR-043). **Deviation** |
| Expired temporary aliases | Hidden from the list but changeable through `/api/v1` until the daily clean-up | Not found (FR-046). **Deviation** |
| Temporary alias permission | `spam_alias` for changes; reads unchecked | Same (FR-006) |
| Filters | `filters` domain administrator flag for changes; users cannot change filters; one active filter per kind; Sieve parser validation; stored in `sieve_filters`, read by the mail server through views | Same (FR-047 to FR-051). Script limited to 64 KiB, the size of the storage column |
| Filter list | `get/filters/all` loops over every domain and mailbox | Paginated list with filters (FR-047). **Deviation** (performance) |
| Active Sieve script | dockerapi exec of `doveadm sieve list` and `sieve get` | Same (FR-053) |
| Pushover reads | Token and key returned in full | Write-only; reads say only whether they are set (FR-054). **Deviation** |
| Pushover validation | Token and key 30 letters or digits; invalid sender addresses silently dropped; empty title or text keeps the old value; any sound; no length limits; regex checked | Same token, key and regex rules; invalid senders refused; empty title or text means the default; sound name rule; length limits (FR-055). **Deviation** |
| Pushover verification | `users/validate` call with the stored credentials | Same (FR-057) |
| Tagged-mail handling | `RCPT_WANTS_SUBJECT_TAG` / `RCPT_WANTS_SUBFOLDER_TAG` hashes in the shared store | Same (FR-059) |
| TLS enforcement | `tls_enforce_in` / `tls_enforce_out` in the mailbox attributes; mass action with a list of mailboxes | Same storage; bulk action with one result per mailbox (FR-059, FR-060) |
| Recent logins | `sasl_log` grouped by address, service and app password; days parameter; non-public addresses labelled "Web/EAS/Internal"; country from `IP_SHORTCOUNTRY` or a synchronous external lookup (5 s per address); the previous legacy UI sign-in from `logs` also returned | Same grouping, internal flag, cache and lookup, bounded in time and switchable (FR-061, FR-062). Service codes normalised. **Deviation**: the previous legacy UI sign-in is not returned |
| Clearing recent logins | `get/reset-last-login`, a read request that deletes the mailbox's `sasl_log` rows and, for administrators and the user themself, the `logs` rows of its successful sign-ins | State-changing request with the same deletions (FR-063). **Deviation** (HTTP semantics) |
| Domain administrator's own recent connections | A "clear" link that deletes only the administrator's legacy UI sign-in records; nothing is listed | Not offered. **Deviation** |
| ActiveSync cache reset | Deletes `sogo_cache_folder` rows of the mailbox; offered by the UI only when the groupware is enabled | Same; refused when the groupware is disabled (FR-064, FR-066). **Deviation** for the server check |
| Groupware profile reset | Deletes `sogo_user_profile`, `sogo_cache_folder`, `sogo_acl` (objects of and grants to the mailbox), `sogo_store`, `sogo_quick_contact`, `sogo_quick_appointment` and `sogo_folder_info` of the mailbox | Same (FR-065, FR-066) |
| Webmail single sign-on | The legacy session gets `sogo-sso-user-allowed` at sign-in; nginx asks `sogo-auth.php`, which reads the session from the shared store; blocked while actions are pending; `sasl_log` entry `SSO` | Webmail-only hand-off for the requesting browser, with the same entry and blocks (FR-067, FR-068). **Deviation**: a link between the two implementations' sessions, which constitution V allows when a spec states it |
| Overview | `mailbox_details` and `user_get_alias_details`, comments HTML-encoded | Same content, plain text (FR-069) |
| Server settings for the screens | Rendered into the pages: `SKIP_SOGO` hides webmail and the groupware resets; the "Forgot password" link is always shown | Readable self-service capabilities, also signed out for the reset availability (FR-080), an addition |
| Apple connection profile | IMAP/SMTP and CalDAV/CardDAV payloads, `only_email` variant, unsigned; `app_password` generates a password of at least 16 characters named after the device (`iPad`, `iPhone`, `Mac` or the user agent) for IMAP, SMTP and DAV without checking `app_passwds`; values partly inserted into the XML unescaped; DAV payloads offered even with `SKIP_SOGO=y` | Same payloads, keys and identifiers, unsigned; the embedded app password follows FR-038 (32 characters) and needs `app_passwds`; every value XML-escaped; the DAV variant refused when the groupware is disabled (FR-082). **Deviation** |
| Client configuration help | A link to the mailcow client documentation with `host`, `email`, `name`, `ui`, `port` and `outlookEAS` in the fragment | Structured data per protocol from the same autodiscover settings, plus the same documentation link (FR-083), an addition |
| Temporary-alias domains | Shown only in the user's own form | Also readable by administrators and domain administrators for a mailbox in scope (FR-081), an addition |
| Out-of-scope objects | `access_denied` or an empty result | "Not found" (FR-003) |
| Permission flags | Loaded into the legacy session at sign-in | Evaluated on every request (FR-002) |
| Mass actions on temporary aliases, app passwords and filters | One request with a list of items | One request per item (FR-079). **Deviation** |
| Concurrent edits | Last write wins | Version tags (FR-070, FR-071). **Deviation** |
| Change log | Entries in the `logs` table | This API's audit log (FR-073), as in feature 001. **Deviation** |
| Removing one second factor while exactly one entry is active (added by the plan) | Refused for any entry, also an inactive one | Refused only when the entry itself is the only active one (FR-034). **Deviation** |
| `force_tfa` check on removal (added by the plan) | Compares the JSON-encoded value with `'1'`, which never matches the stored string `"1"`, so removals are never blocked | The flag blocks removals (FR-034, FR-035). **Deviation** (bug fix) |
| Webmail hand-off after this API's session expires (added by the plan) | Not applicable (legacy sessions carry the SSO flag themselves) | Ended by a background sweep within one minute of the expiry; at once for sign-out, invalidation, FR-011 and FR-025 (FR-068) |
| Small presentation differences (added by the plan) | TOTP QR code as PNG; WebAuthn registration timeout hint 30 s; reset link with the request's scheme; Apple profile payload UUIDs from an MD5-based GUID; Thunderbird connector version in the client help link; Pushover verification without a timeout | QR code as SVG; timeout hint 300 s, matching the 5-minute validity; reset link always `https`; random v4 UUIDs; no connector version (the file lives outside the API container); 10-second timeout. **Deviation** (no effect on stored data) |

### Key Entities *(include if feature involves data)*

- **Account** (existing mailcow data, feature 001): an administrator, domain administrator or
  mailbox user, with its password hash, forced password change and forced second-factor flags,
  authentication source and, for mailbox users, the password-changed time and the recovery address.
- **Password reset token** (existing mailcow data, shared with the legacy UI): a random token for one
  mailbox with its issue time.
- **Second factor** (existing mailcow data): a TOTP, WebAuthn or Yubico OTP entry of one account,
  with a name, a type, an active state and secret material that never leaves the server.
- **Pending enrolment** (owned by this API, short-lived): a started TOTP or WebAuthn enrolment bound
  to one session, with its expiry and attempt count.
- **App password** (existing mailcow data): an alternative password of one mailbox for selected
  protocols, with a name and an active state.
- **Temporary alias** (existing mailcow data): a generated address that delivers to one mailbox until
  it expires, or permanently, with a description.
- **Sieve filter** (existing mailcow data): a prefilter or postfilter script of one mailbox with a
  description and an active state.
- **Active Sieve script** (mail server data): the script the user manages in webmail.
- **Push notification settings** (existing mailcow data): the Pushover credentials and notification
  options of one mailbox.
- **Mailbox settings** (existing mailcow data): tagged-mail handling, kept in the shared store, and
  inbound and outbound TLS enforcement, kept in the mailbox.
- **Recent-login entry** (existing mailcow data): service, client address, app password and time of a
  mailbox login, shared with the legacy UI, plus the shared cache of address countries.
- **Groupware data** (existing mailcow data): the mailbox's groupware profile, cached device state,
  sharing permissions, folders, contacts and events.
- **Webmail hand-off** (owned by this API, linked to the legacy session store): a webmail-only
  sign-in of one browser for one mailbox, tied to the session of this API that created it.
- **Mailbox overview** (computed): the read-only view of FR-069.
- **Self-service capabilities** (computed from the server configuration): the settings and limits
  of FR-080, and for a session the account's own second-factor and password rules.
- **Apple connection profile** (computed, FR-082): an unsigned `.mobileconfig` file for one mailbox,
  optionally carrying a newly created app password.
- **Client configuration** (computed, FR-083): host names, ports, encryption and authentication per
  protocol, from the server's autodiscover settings.
- **Audit record** (owned by this API, defined in feature 001): extended with the target types of
  FR-073.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of the second factors in the parity test set work in both directions: entries
  enrolled through the API complete sign-in in the legacy UI and in feature 001, and entries enrolled
  in the legacy UI can be listed and removed through the API.
- **SC-002**: A user changes their password in the new web app in under 1 minute; the old password
  stops working for the web sign-in at once and for mail clients within 5 minutes.
- **SC-003**: For 100% of the accounts in the test set that are flagged for a forced password change
  or a forced second-factor setup, the user resolves it in the new web app without visiting the
  legacy UI.
- **SC-004**: A reset message reaches the recovery address within 1 minute in 95% of eligible
  requests, and a user completes a reset, including a second factor, in under 3 minutes.
- **SC-005**: Over 1,000 reset requests each for eligible, ineligible and unknown addresses, the
  responses are identical in content and their median response times differ by less than 50
  milliseconds.
- **SC-006**: A scan of all responses after creation, service logs and audit records of the full test
  run finds no password, hash, second-factor secret after the enrolment start, Yubico credential,
  one-time code, reset token, app password or push credential.
- **SC-007**: 100% of attempts to reach a mailbox object outside the caller's scope get the same
  response as for a nonexistent object, and 100% of changes without the required permission flag
  are refused.
- **SC-008**: In 95% of attempts, a mailbox user reaches their webmail from the new web app within
  3 seconds and without a credential prompt, and the legacy UI still shows its sign-in page in that
  browser.
- **SC-009**: For the same input, an app password, temporary alias, filter, push notification
  setting, tagging or TLS change made through the API leaves the same stored data and shared-store
  entries as the legacy UI, apart from generated values, timestamps and the stated deviations.
- **SC-010**: In 95% of requests, a page of 25 app passwords, temporary aliases, filters or recent
  logins is returned within 1 second, and a recent-login page that needs country lookups within 2
  seconds.
- **SC-011**: Every state-changing request of this feature produces exactly one audit record per
  affected object.
- **SC-012**: 100% of changes sent with an outdated version tag are refused.

## Assumptions

- Feature 001 provides sign-in, sessions, restricted sessions, passkeys, API keys, the error
  conventions and the audit log. Feature 003 provides the mailboxes, their user permission flags and
  the read-only password policy. Feature 004 manages the aliases and send-as permissions that the
  overview shows.
- The reset message template, its sender and subject are configured in the legacy UI until feature
  013 makes them editable. The password policy is edited in feature 013.
- mailcow's mail server accepts the reset message from the API container without authentication, as
  it accepts it from php-fpm.
- The webmail hand-off is feasible without changing any container: mailcow's webmail single sign-on
  check reads the browser's legacy session from the shared store, and a session that carries only the
  webmail permission grants webmail but no legacy UI page. This depends on the legacy session format
  of each supported mailcow release, which the integration suite covers (constitution III). The plan
  decides the details. If the owner rejects the link between the two implementations, users open
  webmail through its own sign-in page.
- Webmail's own button that leads back to the account settings keeps pointing to the legacy user
  page, because changing it would patch the groupware container.
- The session store of feature 001 can find and end all sessions of an account.
- The external country lookup service is the one legacy uses. Operators who do not want client
  addresses sent to a third party switch the lookup off.
- Legacy values that live in its PHP configuration (`PW_RESET_TOKEN_LIFETIME`,
  `PW_RESET_TOKEN_LIMIT`, `OTP_LABEL`, `SESSION_LIFETIME`, `WEBAUTHN_UV_FLAG_REGISTER`,
  `FIDO2_FORMATS`) become server settings of this API with the upstream defaults, and the operator
  keeps them aligned with legacy, as the cross-feature conventions require. Values from
  `mailcow.conf` (`SKIP_SOGO`, `MAILCOW_PASS_SCHEME`, `MAILCOW_HOSTNAME`, `ADDITIONAL_SERVER_NAMES`,
  `WEBAUTHN_ONLY_TRUSTED_VENDORS`) keep their names.
- These defaults can be changed by the operator: the reset token lifetime (15 minutes) and limit
  (3); the reset page address; the reset throttling (10 per 15 minutes); the TOTP issuer name
  (`mailcow UI`); the enrolment lifetimes (10 minutes for TOTP, 5 minutes for WebAuthn); the country
  lookup (on); the bulk limit (100); the page sizes (25, maximum 1,000).
- Own passwords are chosen by the user, and the web app may suggest one generated in the browser, as
  legacy does. The API generates only app passwords, and only when the client gives none.
- **Out of scope**:
  - passkeys (feature 001);
  - administrators setting mailbox passwords, recovery addresses and forced flags (003), and
    administrator and domain administrator accounts (007);
  - quarantine notification settings, spam score and allow and block lists (006);
  - webmail and impersonation on behalf of a user (007);
  - sync jobs (008);
  - identity-provider sign-in and password changes for external sources (012);
  - the password policy, the reset message template, global Sieve filters and Sieve presets (013);
  - the autodiscover and autoconfig endpoints that mail clients call (internal endpoints, 014);
  - batch operations other than the bulk TLS change.
- **Dependencies**:
  - the upstream mailcow database schema of the supported release;
  - mailcow's shared store (tagging, password policy, reset template, country cache, legacy
    sessions), the mail server, the container service, the groupware directory view and its cache;
  - the Yubico verification service, the Pushover validation service and the country lookup service;
  - features 001 and 003.
