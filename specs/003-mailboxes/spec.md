# Feature Specification: Mailboxes

**Feature Branch**: `main` (no feature branches, constitution 1.1.0)

**Created**: 2026-10-18

**Status**: Draft

**Input**: Feature description built from `docs/roadmap.md` (feature 003 and allocation row 003):

> Mailboxes for mailcow REST API v2: administrators and domain administrators list, search,
> inspect, create, change, rename and delete the mailboxes of an existing mailcow-dockerized
> server. This covers the quota, checked against the domain's limits, the protocol access, an
> initial or replacement password set by an administrator, the forced password change and forced
> two-factor setup flags, the mailbox user's permission flags, tags and custom attributes.
> Administrators manage mailbox templates that pre-fill new mailboxes, and domain administrators
> use them. Domain administrators reach only the mailboxes of the domains they manage, as their
> permission flags allow. Mailbox users have no access: their own account is feature 005.
>
> Allocated legacy surface: `mailbox` add/get (all, reduced, id)/edit/delete, `mailbox/template`,
> `edit/mailbox/custom-attribute`, `edit/rename-mbox`, `edit/user-acl`, `delete/mailbox/tag`,
> quota, protocol access, admin-set password, forced password change and forced 2FA flags. The
> legacy "Mail setup › Mailboxes" and "Edit mailbox" screens need list filters, sorting, search,
> the fields they show today and their mass actions. Legacy's `get/mailbox/all` makes one lookup
> per mailbox, so the new list must be paginated and efficient.
>
> Out of scope, allocated elsewhere: sender ACL and external sender addresses (004), self-service
> (005), quarantine notification settings and rate limits (006), relayhost assignment (009), sync
> jobs (008), login-as (007), identity-provider provisioning (012), editing the password policy
> (013).
>
> Constraints: every legacy side effect is reproduced, so Postfix, Dovecot, Rspamd and SOGo behave
> the same whichever implementation made the change. The legacy UI and `/api/v1` keep working on
> the same data. The feature builds on feature 001's identity, roles, permission flags, API keys,
> error conventions and audit log, and on feature 002's domains.

## Clarifications

### Session 2026-10-18

- Q: Which settings that other roadmap features manage may a creation request set, and can this
  feature change them afterwards? → A: A creation accepts the initial values that legacy's form
  and templates carry: tagged-mail handling, quarantine notification frequency and category,
  inbound and outbound TLS enforcement, and a rate limit. Afterwards they are read-only here and
  are changed through features 005 and 006. Sender permissions (004) and relayhost assignment (009)
  are neither set nor changed here (FR-008, FR-018). (agent decision: legacy creates a mailbox with
  these values in one step and templates carry them, while the roadmap allocates their later
  management to other features.)
- Q: When a creation request names no quota, which default applies? → A: The named template's
  quota when a template is named, otherwise the domain's default quota for a new mailbox (FR-022).
  (agent decision: the legacy UI pre-fills the domain default. The legacy server falls back to the
  Default template's unlimited quota, which silently creates unlimited mailboxes.) **Superseded on
  2026-10-18** (owner decision: the domain's default mailbox quota is an upstream leftover and is
  no longer part of the API, feature 002): the quota follows the template chain like every other
  value, named template, then Default template, then the built-in unlimited quota, as the legacy
  server does. Clients pre-fill a quota themselves.
- Q: What happens when a domain administrator sets a field their permission flags do not allow? →
  A: The whole request is refused with an error that names the field and the flag, at creation,
  on change and in bulk actions. Values equal to the current value or to the template's value are
  accepted, and template values apply, except an unlimited quota, which always needs the flag
  (FR-006, FR-035). (agent decision: legacy silently ignores such fields on edit and does not check
  them at creation, which lets a domain administrator grant permissions they lack. Constitution VI.)
- Q: Should the API accept pre-hashed passwords, as legacy does? → A: Yes. A value that begins with
  a hash-scheme prefix legacy recognises is stored as given, without the policy check, and the
  audit log records that a pre-hashed password was set (FR-024, FR-056). (agent decision: legacy
  parity under constitution IV. Migrations from other mail servers rely on it.)
- Q: Should a mailbox outside a domain administrator's scope be answered "not found" or
  "forbidden"? → A: "Not found", the same as a mailbox that does not exist (FR-003). (agent
  decision: constitution VI says a domain administrator never reaches a foreign object, even with a
  guessed identifier. Not revealing existence is the OWASP API Security recommendation.)
- Q: Should the legacy mass actions be one bulk request, and what happens when some mailboxes fail?
  → A: One request applies one action (activate, disable login, deactivate, delete, a protocol on
  or off) to up to 100 mailboxes. Each mailbox is checked, processed and audited on its own,
  failures do not stop the others, and the response reports one result per mailbox (FR-054,
  FR-055). (agent decision: legacy `edit/mailbox` and `delete/mailbox` take arrays and continue
  past failures, and the web app needs the mass actions. 100 covers a large page.)
- Q: What must a deletion guarantee, so that a later mailbox with the same address inherits
  nothing? → A: The deletion is refused, with nothing removed, when the mail store does not
  confirm moving the mail to the garbage area. It also removes the tagged-mail entries and
  recent-login records that legacy leaves behind (FR-040, FR-041). (agent decision: legacy deletes
  the records even when the mail move fails, so a re-created address would receive the old mail.)
- Q: Should a rename carry over the data that legacy leaves under the old address? → A: Yes. Second
  factors, passkeys, temporary aliases, spam settings, quarantine items, the rate limit, tagged-mail
  handling and recent logins follow the mailbox. OAuth tokens bound to the old address are revoked,
  and the groupware cache is flushed (FR-046). (agent decision: legacy forgets these, so users lose
  their second factors and spam settings after a rename. The rule is that what legacy deletes with
  a mailbox moves with it.)
- Q: What happens when a step of a rename fails part-way? → A: Every check runs before anything
  changes. A failing step undoes the steps already done, and the client gets a temporary-failure
  error. If undoing fails too, a "rename incomplete" error lists the steps done and not done, and
  the audit log records them. The client must state whether to keep the old address as an alias
  (FR-043, FR-044, FR-048). (agent decision: legacy deletes the shared-folder permissions before
  its transaction and never undoes anything. The legacy server defaults the alias to off and the UI
  to on, so an explicit choice avoids surprises.)
- Q: Should administrators set a mailbox's recovery e-mail in this feature, although the roadmap
  lists recovery e-mail under self-service (005)? → A: Yes. The administrator's field on the legacy
  "Edit mailbox" form is part of `edit/mailbox`, which row 003 allocates here. The user's own
  recovery e-mail stays in feature 005 (FR-029, FR-033). (agent decision: allocation follows the
  legacy action, and row 005 covers self-service only.)
- Q: Should this feature expose the current password policy, although the roadmap allocates the
  password policy to feature 013? → A: Yes, read-only. Administrators and domain administrators can
  read the rules and their parameters, taken from where legacy keeps them and with the legacy
  defaults, so that the web app shows the rules before a password is submitted. Editing the policy
  stays in feature 013 (FR-063). (orchestrator decision: the create and edit screens of this
  feature need the rules before submit, and feature 013 comes much later.)
- Q: Must the policy read carry a version tag? → A: Yes, the same tag over the same values as
  feature 013's policy edit (FR-063). (orchestrator decision: a client can read here and edit in 013
  without a conflict.)
- Q: How does a client learn the server settings it needs before it submits? → A: Through a
  mailbox capabilities read for administrators and domain administrators. It gives the configured
  identity provider type, whether the groupware is enabled, whether last logins are reported, the
  bulk, tag, custom-attribute and page limits, and the garbage-area retention (FR-064). (orchestrator
  decision: the web app found these gaps, and the read mirrors feature 002's FR-064. Feature 012
  relies on domain administrators learning the provider type here.)
- Q: Can a client see what a deletion would remove before confirming it? → A: Yes, through a
  read-only deletion preview that writes no audit record. It gives the counts of dependent data,
  the mail that would move to the garbage area, the retention period, any blocker and the current
  version tag (FR-065). (orchestrator decision: the web app's confirmation needs numbers. The
  preview mirrors feature 002's FR-065.)
- Q: Can clients suggest existing tags? → A: Yes. A list of the distinct mailbox tags in the
  caller's scope gives counts, with search and paging (FR-066). (orchestrator decision: the web
  app's tag filter and tag input need it. The list mirrors feature 002's FR-066.)
- Q: Do bulk actions check version tags? → A: Each item may carry its own version tag. An outdated
  tag gives that item the result `outdated` while the others proceed, and untagged items are not
  checked. Every list entry carries its version tag (FR-016, FR-053, FR-067). (orchestrator
  decision: the bulk rule in `docs/conventions.md`.)
- Q: How does this spec follow `docs/conventions.md` for follow-up steps, timeouts and legacy PHP
  settings? → A:
  - The initial rate limit, the tagged-mail handling and the groupware update run after the
    mailbox is saved. Each step's outcome is reported, and a failing step never removes the mailbox
    (FR-027).
  - A container API call that times out answers "outcome unknown" (FR-040, FR-048).
  - The mailbox defaults, the tagging limit and the last-login display are API server settings with
    upstream defaults (FR-010, FR-022, FR-036).
  - There is no server-side breached-password check (Assumptions).

  (orchestrator decision: the cross-feature conventions apply to the next change of every spec.)

## User Scenarios & Testing *(mandatory)*

### User Story 1 - List, search and inspect mailboxes (Priority: P1)

An administrator or a domain administrator opens the mailbox screen. They see a page of the
mailboxes in their scope with what the legacy list shows: the address, display name, domain,
quota, storage used and percentage, message count, last logins per protocol, last password
change, protocol access, TLS enforcement, quarantine notification settings, authentication source,
tags, status, and creation and modification times. They narrow the list by domain, status, tag and
authentication source, search by a fragment of the address, name or a tag, sort by any column and
page through thousands of mailboxes without waiting. They open one mailbox and see its full
details, including how much quota it may still be given, the mailbox user's permission flags and
its custom attributes.

**Why this priority**: Every other mailbox task starts by finding the mailbox. The legacy list
fetches every mailbox one by one and becomes unusable on large servers.

**Independent Test**: Load a test copy of the mailcow database with 10,000 mailboxes and 50
resources in 500 domains, and two domain administrators with different domains. As an
administrator and as each domain administrator, list the mailboxes with each filter, search and
sort, inspect single mailboxes, and compare every value with the database. Measure the response
time of each page.

**Acceptance Scenarios**:

1. **Given** an administrator and three domains with 30 mailboxes and 2 resources, **When** the
   client lists mailboxes without parameters, **Then** it receives the first 25 mailboxes sorted by
   address, a total of 30, and no resources.
2. **Given** a domain administrator who manages only `a.example`, **When** the client lists
   mailboxes, **Then** only mailboxes of `a.example` are returned. **When** it filters by
   `b.example`, **Then** the list is empty with a total of 0.
3. **Given** the same domain administrator, **When** the client inspects a mailbox of `b.example`
   by its address, **Then** the answer is "not found", exactly as for an address that does not
   exist.
4. **Given** mailboxes `anna@a.example`, `bob@a.example` with display name "Joanna Bob", and
   `carl@a.example` tagged "Annapolis", **When** the client searches for "ANNA", **Then** all three
   are returned.
5. **Given** mailboxes with different usage, **When** the client sorts by percentage used,
   descending, **Then** the fullest mailbox comes first and mailboxes with unlimited quota come
   last.
6. **Given** a mailbox whose quota an operator changed in the legacy UI a second ago, **When** the
   client inspects it, **Then** it sees the new quota and a different version tag.
7. **Given** a signed-in mailbox user, **When** the client lists or inspects mailboxes, even its
   own, **Then** the request is refused as forbidden.
8. **Given** a server whose operator has turned off the display of last logins, **When** the client
   lists mailboxes, **Then** no last-login times are returned.

---

### User Story 2 - Create a mailbox (Priority: P1)

An administrator or a domain administrator creates a mailbox. They enter the local part, choose a
domain, type the display name and an initial password, and either accept the quota the domain
suggests or enter their own. They can pick a mailbox template that pre-fills the quota, status,
protocol access, permissions, forced flags, tags and the initial tagging, quarantine notification,
TLS and rate-limit settings, and they can override any of these. The mailbox works at once in
Postfix, Dovecot and SOGo, exactly as if it had been created in the legacy UI. The domain's limits
are enforced, and a domain administrator cannot grant more than their own permission flags allow.

**Why this priority**: Creating mailboxes is the most frequent provisioning task, for operators
and for billing systems that use API keys.

**Independent Test**: Create mailboxes through the API with and without templates, as an
administrator, as domain administrators with and without each relevant permission flag, and with
an API key. Compare the resulting database rows, shared-store entries and groupware directory with
those of the same mailbox created in the legacy UI. Sign in to IMAP, SMTP and webmail with the new
mailbox. Repeat at each domain limit.

**Acceptance Scenarios**:

1. **Given** a domain `example.org` with room for more mailboxes, **When** an administrator creates
   ` Anna.Smith ` in `Example.org` with a password that meets the policy, **Then** the mailbox
   `anna.smith@example.org` exists, the response contains no password, the user can sign in to
   IMAP and webmail, the mailbox receives mail at its address, its usage is zero and it appears in
   the webmail address book.
2. **Given** a domain whose mailbox limit is reached, **When** a client creates a mailbox in it,
   **Then** the request is refused as "domain mailbox limit reached" with the current count and the
   limit, and nothing is created.
3. **Given** a domain with a maximum mailbox quota of 10 GiB and 5 GiB of domain quota left,
   **When** a client creates a mailbox with 8 GiB, **Then** the request is refused with a
   validation error on the quota that states the 5 GiB available.
4. **Given** an address that already exists as a mailbox, a resource, an alias or a temporary
   alias, **When** a client creates a mailbox with it, **Then** the request is refused as a conflict
   that names which kind of object holds the address.
5. **Given** a domain administrator without the "unlimited quota" flag, **When** they create a
   mailbox with unlimited quota, **Then** the request is refused as not permitted, naming the flag.
6. **Given** a domain administrator without the "protocol access" flag and a template that allows
   POP3, **When** they create a mailbox from that template with POP3 turned off, **Then** the
   request is refused as not permitted. **When** they create it without choosing protocol access,
   **Then** the mailbox gets the template's protocol access.
7. **Given** a template "Small" with a 1 GiB quota, POP3 off and the sync-jobs permission on,
   **When** a client creates a mailbox naming "Small" and giving only the address and a password,
   **Then** the mailbox gets those values. **Given** no named template and no quota in the request,
   **When** the mailbox is created, **Then** it gets the Default template's quota, or an unlimited
   quota without one (owner decision 2026-10-18).
8. **Given** a password policy with a minimum length of 10 and digits required, **When** a client
   creates a mailbox with the password "short", **Then** the request is refused with a validation
   error on the password that names both unmet rules and their parameters.
9. **Given** a migration script, **When** it creates a mailbox with a password value in a
   recognised pre-hashed format such as `{SSHA512}…`, **Then** the value is stored as given and the
   user signs in with their original password.
10. **Given** a server with LDAP configured as its identity provider, **When** a client creates a
    mailbox with the LDAP authentication source and no password, **Then** it is created. **When**
    it sends a password for such a mailbox, or chooses Keycloak, which is not configured, **Then**
    the request is refused with a validation error.
11. **Given** an operator who set the policy to a minimum length of 10 with digits required in the
    legacy UI, **When** a domain administrator's client reads the password policy, **Then** it
    receives exactly those rules, and a password that meets them is accepted at creation.
12. **Given** a server with LDAP configured and the groupware disabled, **When** a domain
    administrator's client reads the mailbox capabilities, **Then** it learns that the provider type
    is LDAP, that the groupware is disabled, and the bulk, tag and custom-attribute limits in force.
    **Given** no identity provider, **Then** the provider type is "none".
13. **Given** a creation with an initial rate limit while the shared store is unreachable, and a
    pre-hashed password or an external authentication source (which need no policy check),
    **When** the mailbox is created, **Then** it exists, and the response reports the rate-limit
    step as failed.

---

### User Story 3 - Change a mailbox's settings and password (Priority: P1)

An administrator or a domain administrator changes a mailbox: its display name, quota, status
(active, login disabled, inactive), password, forced password change and forced two-factor setup,
protocol access, webmail redirect, authentication source and recovery e-mail. The client sends only
what changes, together with the version it read, so that an edit made meanwhile in the legacy UI is
not overwritten.

**Why this priority**: Operators change quotas, reset passwords and suspend mailboxes every day.
Billing systems suspend and resume mailboxes automatically.

**Independent Test**: For each changeable field, change it through the API and check the stored
data, the mail services' behaviour and the groupware directory. Edit the same mailbox in the
legacy UI between reading and writing, and confirm the API refuses the stale write. Repeat as
domain administrators with and without each relevant flag.

**Acceptance Scenarios**:

1. **Given** a mailbox, **When** an administrator sets a new password, **Then** mail clients accept
   the new password, the password-changed time is updated, and neither the response nor the audit
   log contains the password.
2. **Given** an active mailbox, **When** its status is set to "login disabled", **Then** it still
   receives mail, sign-ins are refused, and it disappears from the webmail address book. **When**
   it is set to "inactive", **Then** mail to it is no longer accepted.
3. **Given** a client that read a mailbox, and an operator who then changed it in the legacy UI,
   **When** the client sends its change with the version it read, **Then** the request is refused
   as outdated and nothing changes.
4. **Given** a mailbox using 4 GiB, **When** its quota is set to 2 GiB, **Then** the change is
   accepted. **When** its quota is set above what the domain has left for it, **Then** the request
   is refused with a validation error that states the largest quota allowed.
5. **Given** a domain administrator without the "protocol access" flag, **When** they turn off
   IMAP, **Then** the request is refused as not permitted. **Given** an administrator, **When** they
   do the same, **Then** IMAP sign-ins of the mailbox fail and webmail keeps working.
6. **Given** a mailbox, **When** forced password change is turned on, **Then** the user's next
   sign-in to the new web app is restricted to changing the password (feature 001, FR-014).
7. **Given** a mailbox that authenticates against LDAP, **When** a client switches it to mailcow
   authentication and sets a password in the same request, **Then** the password is stored and
   works. **When** it switches without a password and the mailbox has none stored, **Then** the
   request is refused with a validation error.
8. **Given** a mailbox user, **When** they try to change any mailbox through this feature, **Then**
   the request is refused as forbidden.

---

### User Story 4 - Delete a mailbox (Priority: P1)

An administrator or a domain administrator deletes a mailbox. Its mail is moved to mailcow's
garbage area and purged after the operator's retention period, and everything that belongs to it
is removed, as in legacy: aliases that only point to it, its sender permissions, sync jobs,
temporary aliases, app passwords, filters, spam settings, second factors, passkeys, quarantine
items and webmail data. Aliases that also point elsewhere keep their other recipients.

**Why this priority**: Deprovisioning is required for every billing integration, and incomplete
deletion leaks data to the next owner of the address.

**Independent Test**: Create a mailbox with every kind of dependent data in the legacy UI, delete
it through the API, and compare the remaining data and the mail-service calls with a deletion made
in the legacy UI. Then create a new mailbox with the same address and confirm it inherits nothing.

**Acceptance Scenarios**:

1. **Given** a mailbox with aliases, sender permissions, sync jobs, temporary aliases, app
   passwords, sieve filters, two-factor entries, passkeys, quarantine items and webmail data,
   **When** it is deleted, **Then** all of these are removed as in legacy, an alias pointing to it
   and to another mailbox keeps the other recipient, its mail is moved to the garbage area, and the
   response has no body.
2. **Given** the mail-store service is unreachable, **When** a client deletes a mailbox, **Then**
   the request is refused with a temporary-failure error and nothing is deleted.
3. **Given** a deleted mailbox, **When** a new mailbox is created with the same address, **Then** it
   has no old mail, settings, recent logins, second factors or tagging preferences.
4. **Given** a domain administrator, **When** they delete a mailbox outside their domains, **Then**
   the answer is "not found". **Given** a read-only API key, **When** it is used to delete a
   mailbox, **Then** the request is refused with "insufficient key scope".
5. **Given** a client that sends a deletion with an outdated version, **Then** the request is
   refused as outdated and the mailbox remains.
6. **Given** a mailbox with 3 aliases that point only to it, 1 alias shared with another mailbox,
   2 app passwords and a TOTP factor, **When** the client previews its deletion, **Then** it gets
   exactly those counts and addresses, the storage that would move to the garbage area, the
   retention period and the current version tag. Nothing changes and no audit record is written.

---

### User Story 5 - Manage a mailbox's permissions, tags and custom attributes (Priority: P2)

An administrator or a domain administrator decides what the mailbox user may do in the web app:
temporary aliases, TLS policy, spam score, allow and block lists, tagging, sync jobs, ActiveSync
and SOGo resets, push notifications, quarantine actions, attachments, notifications and category,
app passwords and password reset. They also label the mailbox with tags and store custom
attributes, which mailcow's domain footers can insert into outgoing mail.

**Why this priority**: These settings shape the user's experience but are rarely changed after
creation, and templates set sensible defaults.

**Independent Test**: Change each permission flag, the tags and the custom attributes through the
API, and compare with the stored data and with the same change made in the legacy UI. As a domain
administrator lacking each overlapping flag, confirm the hidden flag is neither shown nor
changeable. Send a mail through a domain with a footer that uses a custom attribute.

**Acceptance Scenarios**:

1. **Given** a mailbox without the sync-jobs permission, **When** an administrator grants it,
   **Then** the user can manage sync jobs from their next request to this API and their next
   sign-in to the legacy UI.
2. **Given** a domain administrator without the "sync jobs" flag, **When** they inspect a mailbox,
   **Then** the sync-jobs permission is not shown. **When** they try to change it, **Then** the
   request is refused as not permitted. **When** they change another permission, **Then** it is
   saved and the sync-jobs permission stays as it was.
3. **Given** a mailbox, **When** a client sets its tags to 26 values, **Then** the request is refused
   with a validation error that states the limit of 25. **When** it sets "Sales" and "sales",
   **Then** they count as one tag.
4. **Given** a mailbox with the custom attribute `old=1`, **When** a client adds `department=Sales`
   and removes `old`, **Then** only `department=Sales` is stored, and a domain footer containing
   `{= department =}` renders "Sales" for mail sent by that mailbox.
5. **Given** a mailbox user, **When** they try to change their own permissions, **Then** the
   request is refused as forbidden.
6. **Given** a domain administrator whose mailboxes carry the tags "Sales" (3 mailboxes) and
   "sales-eu" (1), while another domain's mailboxes carry "Secret", **When** the client lists the
   tags in use searching for "sal", **Then** it gets "Sales" with 3 and "sales-eu" with 1, and never
   "Secret".

---

### User Story 6 - Manage mailbox templates (Priority: P2)

An administrator maintains mailbox templates such as "Students" or "Staff", each holding the values
that new mailboxes start with. Domain administrators can see the templates and pick one when they
create a mailbox. The Default template always exists and supplies the values when no template is
picked.

**Why this priority**: Templates make provisioning consistent, but mailboxes can be created
without them.

**Independent Test**: Create, inspect, change and delete templates as an administrator, read them
as a domain administrator, and compare with templates made in the legacy UI. Create mailboxes from
them and confirm the values applied.

**Acceptance Scenarios**:

1. **Given** an administrator, **When** they create the template "Students", **Then** it is listed
   for administrators and domain administrators. **Given** a domain administrator, **When** they
   try to create, change or delete a template, **Then** the request is refused as forbidden.
2. **Given** a template "Staff", **When** an administrator creates "staff", **Then** the request is
   refused as a conflict.
3. **Given** the Default template, **When** an administrator tries to rename or delete it, **Then**
   the request is refused as "protected template" and nothing changes.
4. **Given** a template, **When** an administrator changes only its quota, **Then** every other value
   stays as it was.
5. **Given** mailboxes created from a template, **When** the template is changed or deleted,
   **Then** those mailboxes do not change.

---

### User Story 7 - Apply an action to many mailboxes at once (Priority: P2)

An administrator or a domain administrator selects mailboxes in the list and activates them,
disables their login, deactivates them, deletes them, or turns one protocol on or off for all of
them, as the legacy mass actions do. They get one result per mailbox.

**Why this priority**: Operators use the legacy mass actions to suspend customers and to roll out
protocol changes. One request per mailbox would be slow and error-prone for the web app.

**Independent Test**: Apply each action to a selection that mixes mailboxes inside and outside a
domain administrator's scope, with one mailbox whose operation fails, and compare the per-mailbox
results, stored data and audit records with the same mass action in the legacy UI.

**Acceptance Scenarios**:

1. **Given** 50 active mailboxes, **When** an administrator deactivates them in one request,
   **Then** all 50 are inactive, the response has 50 successful results, and the audit log has 50
   records.
2. **Given** a domain administrator and a selection with three of their mailboxes and two of
   another domain, **When** they disable login for the selection, **Then** their three mailboxes
   change, the other two are reported "not found", and nothing else changes.
3. **Given** a domain administrator without the "protocol access" flag, **When** they turn off POP3
   for a selection, **Then** the whole request is refused as not permitted.
4. **Given** a selection of 101 mailboxes, **When** it is submitted, **Then** the request is refused
   with a validation error that states the limit of 100.
5. **Given** a bulk deletion in which the mail store of one mailbox cannot be cleaned, **Then** that
   mailbox is reported as failed and remains, and the others are deleted.
6. **Given** a selection of three mailboxes sent with the version tags read from the list, one of
   which was changed in the legacy UI meanwhile, **When** they are deactivated, **Then** that one
   gets `outdated` and stays unchanged, and the other two are deactivated. Items sent without a
   tag are not version-checked.

---

### User Story 8 - Rename a mailbox (Priority: P3)

An administrator or a domain administrator changes the local part of a mailbox, for example after a
name change. The mail, folders, settings, permissions, aliases, sync jobs, filters, app passwords,
webmail data, shared-folder permissions and second factors follow the mailbox to its new address.
They can keep the old address as an alias, so that mail sent to it is still delivered.

**Why this priority**: Renaming is rare and risky. Legacy asks the operator to make a backup first.

**Independent Test**: Rename mailboxes that hold every kind of dependent data and shared folders in
both directions. Compare the result with a legacy rename, sign in with the new address, and check
that the data legacy leaves behind under the old address now follows the mailbox. Inject a failure
into each external step and check that the mailbox is left as it was.

**Acceptance Scenarios**:

1. **Given** `anna@example.org` with mail, shared folders and an app password, **When** she is
   renamed to `anna.smith` with "keep the old address as an alias", **Then** she signs in as
   `anna.smith@example.org` with the same password, sees all her mail, still reaches the folders
   others share with her, an alias from `anna@example.org` to the new address exists with a comment
   about the rename, and her app password still works.
2. **Given** a new address already used by a mailbox, resource, alias or temporary alias, **When**
   the rename is requested, **Then** it is refused as a conflict and nothing changes.
3. **Given** an inactive mailbox, **When** the rename is requested, **Then** it is refused as "mailbox
   cannot be renamed" and nothing changes.
4. **Given** the webmail rename step fails, **When** the rename is requested, **Then** the steps
   already done are undone, the mailbox keeps its old address, and the client gets a
   temporary-failure error. **When** undoing also fails, **Then** the client gets a "rename
   incomplete" error that lists the steps left undone.
5. **Given** a mailbox with a TOTP second factor and a passkey, **When** it is renamed, **Then** both
   keep working under the new address.
6. **Given** a domain whose alias limit is reached, **When** a rename with "keep the old address as
   an alias" is requested, **Then** it is refused before anything changes.

---

### Edge Cases

- **Resources** (mailcow's location, thing and group kinds) share the mailbox store. They are never
  listed, counted, changed or deleted by this feature, and addressing one answers "not found".
  Their addresses still count as taken when a mailbox is created or renamed.
- **Mailbox outside the caller's scope** is indistinguishable from one that does not exist.
- **Domain administrator assignment removed, or flag revoked, between two requests**: the next
  request sees the change.
- **Domain filter, or creation domain, outside a domain administrator's scope**: the list is empty;
  a creation is refused with the same "domain not found" validation error as for a domain that
  does not exist.
- **Creation in an alias domain**: refused with a validation error, because mailboxes live in
  primary domains (legacy behaviour).
- **Creation in an inactive domain or in a backup MX (relayed) domain**: allowed, as in legacy.
- **Two mailboxes created concurrently in a domain with room for one**: exactly one succeeds. The
  quota limits hold the same way.
- **Quota set below the storage already used**: allowed, as in legacy. Mail delivery then follows
  Dovecot's quota rules.
- **Unlimited quota (0)**: does not count towards the domain quota, as in legacy. That is why
  domain administrators need the "unlimited quota" flag to set it.
- **Mailbox created before a protocol setting existed** (setting missing from its stored data): the
  protocol is reported as allowed, which is how mail sign-in treats it. A missing forced flag is
  reported as off, and a missing password-changed time as absent.
- **Password value that begins with a recognised hash-scheme prefix**: stored as given, without the
  policy check. A user whose intended password really starts with such a prefix cannot have it set
  through the API or the legacy UI.
- **Password changed while mail clients are connected**: the old password can keep working for mail
  clients for up to Dovecot's credential cache lifetime (5 minutes by default), as in legacy.
- **Password set for a mailbox that authenticates against an external identity provider**: refused
  with a validation error. Switching the source to mailcow and setting the password in the same
  request works.
- **Forced password change requested for an externally authenticated mailbox**: refused with a
  validation error. A template or default that would turn it on is ignored for such a mailbox, and
  switching a mailbox to an external source turns it off, as legacy does. The same applies to
  forced two-factor setup for the generic OIDC source.
- **Template named at creation no longer exists**: the creation is refused with a validation error
  on the template.
- **Default template missing from the database**: built-in defaults are used (FR-022).
- **Domain administrator lacking a flag, creating from a template that turns the gated setting on**:
  the template's value is applied, because it is the administrator's policy, not the domain
  administrator's choice. Only an explicit different value is refused. Unlimited quota is the
  exception: a template with unlimited quota is refused for such a domain administrator, who can
  send an explicit quota instead.
- **Request that sets a gated field to its current value**: accepted, because nothing is chosen.
- **Request that sends a read-only field** (a setting owned by another feature, usage, times):
  refused with a validation error naming the field.
- **Tags differing only in letter case, or with surrounding spaces**: treated as the same tag.
- **Mailbox that already has more tags than the limit** (possible through legacy): shown in full;
  only a change that leaves more than the limit is refused.
- **Custom attribute key containing whitespace**: refused with a validation error (legacy silently
  removes spaces).
- **Bulk item with an outdated version tag**: that item gets `outdated` and changes nothing; the
  other items proceed, and items sent without a tag are not version-checked (FR-067).
- **Deletion preview followed by a change**: the deletion re-checks everything. A client that sends
  the previewed version tag with the deletion is refused as outdated when the mailbox changed in
  between.
- **Usage figures and last logins change constantly**: they do not change the version tag, so a
  client editing a busy mailbox is not refused for no reason.
- **Deletion while webmail is disabled on the server** (legacy `SKIP_SOGO`): webmail data is still
  removed from the database, and no groupware directory update is made.
- **Replicated cluster setups** (legacy `CLUSTERMODE=replication`): mail-store moves and cleanups go
  to every mail node, and usage figures come from the record legacy reads on the current node.
- **Mail-store cleanup reports that no mail directory existed** (mailbox never received mail):
  this counts as success.
- **Rename to the same local part**, or to one that only differs in letter case: refused with a
  validation error.
- **Rename of a mailbox that authenticates against an external identity provider**: allowed, as in
  legacy. The provider's next synchronisation (feature 012) may provision the old address again.
- **Rename when the mailbox shares folders with others, or others share with it**: the folder
  permissions are re-created for the new address in both directions, as in legacy.
- **Domain administrator account whose username equals the renamed mailbox's address** (the two
  are separate accounts): it keeps its permission flags. Legacy moves them to the new address,
  which leaves that domain administrator without flags.
- **Bulk action naming the same mailbox twice**: it is processed once and reported once.
- **Audit record cannot be written**: the operation it would record does not succeed (feature 001
  convention).
- **Shared store unavailable** during a deletion, a rename or a password check: the request fails
  with a temporary-failure error and nothing is changed. Reading the password policy fails the same
  way. During a creation's follow-up steps (initial rate limit, tagged-mail handling), the step is
  reported as failed and the mailbox stays (FR-027).
- **Container API timeout** (`DOCKER_TIMEOUT`, 60 s) during a deletion or a rename: the answer is
  "outcome unknown". A deletion then removes no records, and retrying it is safe (FR-040, FR-048).
- **Password policy changed between reading it and submitting a password**: the password is
  checked against the current policy, and the validation error names the rules it fails.
- **Password policy never saved by the operator**: the legacy defaults apply, both when the policy
  is read and when a password is checked.

## Requirements *(mandatory)*

### Functional Requirements

#### Access and scope

- **FR-001**: Administrators MUST be able to reach every mailbox. Domain administrators MUST be
  able to reach exactly the mailboxes of the domains assigned to them through an active assignment
  (legacy behaviour). Mailbox users MUST NOT reach any operation of this feature, not even for
  their own mailbox. Their own account is feature 005.
- **FR-002**: Scope and permission flags MUST be evaluated from the current mailcow data on every
  request, as in feature 001 (FR-029 there). A change made in either UI MUST apply to the next
  request.
- **FR-003**: A mailbox that does not exist, and a mailbox outside the caller's scope, MUST get the
  same "not found" answer. Resources (mailcow's location, thing and group kinds) MUST NOT be treated
  as mailboxes by any operation of this feature.
- **FR-004**: A request authenticated by an API key MUST act with the key owner's role, scope and
  flags (feature 001, FR-033). A read-only key MUST be refused on every operation that changes
  state, with the "insufficient key scope" error of feature 001 (FR-039). A restricted session
  (feature 001, FR-014) MUST be refused.
- **FR-005**: The operations MUST be authorised as follows:

  | Operation | Administrator | Domain administrator | Mailbox user |
  |---|---|---|---|
  | List and inspect mailboxes | All mailboxes | Mailboxes of own domains | Refused |
  | Create, change, delete, rename, bulk actions | All mailboxes | Mailboxes of own domains, subject to FR-006 | Refused |
  | User permissions, tags, custom attributes | All mailboxes | Mailboxes of own domains; permissions subject to FR-035 | Refused |
  | List and inspect mailbox templates | Yes | Yes | Refused |
  | Create, change and delete mailbox templates | Yes | Refused | Refused |
  | Read the password policy | Yes | Yes | Refused |
  | Read the mailbox capabilities | Yes | Yes | Refused |
  | Preview a mailbox deletion | All mailboxes | Mailboxes of own domains | Refused |
  | List the mailbox tags in use | All mailboxes | Tags of own domains' mailboxes | Refused |
  | Read the audit records of this feature | Yes | Refused | Refused |

- **FR-006**: A domain administrator MUST hold these permission flags to choose these settings:
  - "unlimited quota" (`unlimited_quota`) to give a mailbox unlimited quota, whether the value
    comes from the request or from a template, unless the mailbox already has it (legacy refuses it
    whatever its source, because an unlimited quota bypasses the domain quota);
  - "protocol access" (`protocol_access`) to choose any of the six protocol settings;
  - "SOGo access" (`sogo_access`) to choose the webmail redirect;
  - "rate limit" (`ratelimit`) to choose an initial rate limit;
  - the overlapping flags of FR-035 to choose the matching user permissions.

  Apart from unlimited quota, a setting is chosen when a request sets it to a value other than the
  current value (change) or other than the value the mailbox would get from the template and
  defaults (creation). A request that chooses a setting the caller's flags do not allow MUST be
  refused as a whole with the "attribute not permitted for the role" error, which names the field
  and the flag, and nothing MUST change. This applies to creations, changes and bulk actions
  alike.

#### Mailbox representation

- **FR-007**: A mailbox MUST be addressed by its full address. The system MUST accept the address
  in any letter case and with the domain in ASCII or Unicode form, and MUST normalise it as legacy
  does: trimmed, lower-cased, and the domain converted to ASCII as in feature 002 (FR-006 there).
- **FR-008**: A mailbox MUST be represented with:
  - its address, local part, domain (ASCII and readable form) and display name;
  - its status: active, login disabled (receives mail, refuses sign-ins) or inactive;
  - its authentication source: mailcow, or the external identity provider type (Keycloak, generic
    OIDC, LDAP);
  - its quota, storage used, message count and percentage used (FR-010);
  - the largest quota it may be given: the domain quota minus the quotas of the domain's other
    mailboxes, capped at the domain's maximum mailbox quota and never below zero;
  - its forced password change and forced two-factor setup flags;
  - its webmail redirect flag (legacy "SOGo access": after signing in to the UI, the user is sent
    to webmail);
  - its protocol access: IMAP, POP3, SMTP, Sieve, ActiveSync and CalDAV/CardDAV, each allowed or
    not;
  - its user permission flags (FR-035);
  - its tags (FR-036) and custom attributes (FR-037);
  - its recovery e-mail address, for the mailcow authentication source;
  - when its password was last changed;
  - its last login per service: IMAP, POP3, SMTP and webmail single sign-on (FR-010);
  - read-only, because other features manage them: its tagged-mail handling and inbound and
    outbound TLS enforcement (005), its quarantine notification frequency and category (006), its
    effective rate limit and whether that comes from the mailbox or from the domain (006), its
    relayhost assignment (009), its number of active temporary aliases (005), and whether push
    notifications are active (005);
  - whether its domain is relayed as a backup MX, read-only;
  - its creation and last modification times.
- **FR-009**: Quotas MUST be expressed in bytes. Quota values in requests MUST be whole multiples of
  1 MiB, as in feature 002 (FR-009 there), and MUST NOT be negative. A quota of 0 means unlimited.
  Times MUST be RFC 3339 UTC timestamps. A value that legacy never recorded (a mailbox never
  modified, never logged in, password never changed) MUST be reported as absent.
- **FR-010**: Usage figures and last logins MUST be computed as legacy computes them:
  - storage used and message count come from the mail server's usage record of the mailbox. In
    replicated cluster setups they come from the record legacy reads on the current node;
  - the percentage used is the storage used divided by the quota, rounded to a whole percent. A
    mailbox with unlimited quota has no percentage;
  - each last login is the most recent recent-login record of that service;
  - when the operator has turned off the display of last logins, no last-login times MUST be
    returned. This is an API server setting with the upstream default "on", which the operator
    keeps aligned with legacy's `SHOW_LAST_LOGIN`. Clients learn it from FR-064.
- **FR-011**: When the stored data of an older mailbox lacks a setting, the representation MUST
  report a missing protocol setting as allowed (as mail sign-in treats it), a missing forced flag
  as off and a missing password-changed time as absent.

#### Listing and inspecting

- **FR-012**: Administrators and domain administrators MUST be able to list the mailboxes in their
  scope with pagination. The default page size MUST be 25, as in legacy, and the maximum 1,000, as
  in feature 002. The response MUST include the total number of matching mailboxes.
- **FR-013**: The list MUST support these filters: domain, status, tag and authentication source.
  Each filter MUST accept several values and match mailboxes that have any of them. The tag filter
  MUST match whole tags case-insensitively. Filters MUST combine with each other and with the
  search. A domain outside the caller's scope MUST match nothing.
- **FR-014**: The list MUST support a free-text search that matches, case-insensitively and as a
  substring, the address, the display name and the tags.
- **FR-015**: The list MUST be sortable, ascending or descending, by: address, display name,
  domain, status, quota, storage used, percentage used, message count, last mail login (the most
  recent IMAP, POP3 or SMTP login, as legacy sorts it), password last changed, creation time and
  modification time. The default MUST be by address, ascending. Ties MUST be broken by address.
  Mailboxes without a value for the sort field MUST come after all others in both directions.
- **FR-016**: Each list entry MUST carry the full mailbox representation (FR-008) and its version
  tag (FR-038), so that the list screen needs no request per row and can send the tags with a bulk
  action (FR-067). Producing a page MUST NOT require one lookup per mailbox on the page.
- **FR-017**: A client MUST be able to inspect one mailbox in its scope. The response MUST carry the
  mailbox's version tag (FR-038).

#### Creating a mailbox

- **FR-018**: Administrators and domain administrators MUST be able to create a mailbox in a domain
  of their scope. A creation request MUST contain the local part and the domain, and a password
  unless the authentication source is external. It MAY contain:
  - the display name, the authentication source and a mailbox template, identified by its
    identifier;
  - the quota, status, forced password change, forced two-factor setup, webmail redirect, protocol
    access, user permissions and tags;
  - initial values for settings that other features manage afterwards: tagged-mail handling,
    quarantine notification frequency, quarantine category, inbound and outbound TLS enforcement,
    and a rate limit.

  A creation request MUST NOT set the relayhost assignment, sender permissions, recovery e-mail or
  custom attributes, as in legacy. A new mailbox has no relayhost assignment.
- **FR-019**: The local part MUST be trimmed and lower-cased, and MUST then be 1 to 64 characters
  long, use only ASCII letters, digits and the characters ``! # $ % & ' * + / = ? ^ _ ` { | } ~ -
  .``, and neither start nor end with a dot nor contain two dots in a row. The full address MUST be
  a valid e-mail address of at most 255 characters.
- **FR-020**: The domain MUST be normalised (FR-007) and MUST be an existing primary domain in the
  caller's scope. An alias domain MUST be refused with a validation error. A domain that does not
  exist and a domain outside the caller's scope MUST get the same "domain not found" validation
  error. Inactive domains and relayed (backup MX) domains MUST be accepted, as in legacy.
- **FR-021**: The address MUST NOT already be used by a mailbox, a resource, an alias or a temporary
  alias. A request for a used address MUST be refused with the "address in use" conflict, which
  names the kind of object that holds it.
- **FR-022**: Each value the request does not contain MUST be taken, in this order, from the named
  template, the Default template, and the built-in legacy defaults: active, no forced password
  change, no forced two-factor setup, webmail redirect on, all six protocols allowed, no TLS
  enforcement, no tagged-mail handling, hourly quarantine notifications, "rejected mail" quarantine
  category, no rate limit, and every user permission except sync jobs and SOGo profile reset. These
  built-in defaults MUST be API server settings with the upstream values above. The operator keeps
  them aligned with legacy's overrides (`MAILBOX_DEFAULT_ATTRIBUTES`), which the API cannot read.
  Exceptions:
  - the quota follows the same order, with an unlimited quota as the built-in default; the domain's
    default mailbox quota never applies (owner decision 2026-10-18, feature 002 FR-020);
  - the display name, when absent or empty, is the local part. Leading `<` and trailing `>` are
    removed from it, as legacy does;
  - a template that does not exist MUST be refused with a validation error on the template.
- **FR-023**: A creation MUST respect the domain's limits:
  - the number of the domain's mailboxes, not counting resources, MUST stay within the domain's
    maximum number of mailboxes. Otherwise the request MUST be refused with the "domain mailbox
    limit reached" conflict, which states the current count and the limit;
  - the quota MUST NOT exceed the domain's maximum mailbox quota. Otherwise the request MUST be
    refused with a validation error on the quota that states the maximum;
  - the quotas of the domain's mailboxes, not counting resources, plus the new quota MUST NOT exceed
    the domain quota. Otherwise the request MUST be refused with a validation error on the quota
    that states the quota left;
  - an unlimited quota MUST NOT count towards the domain quota, as in legacy, and needs the flag of
    FR-006 for domain administrators.

  These limits MUST hold when several requests run at the same time.
- **FR-024**: A password set by an administrator or a domain administrator MUST:
  - be given once. The API MUST NOT require a confirmation field;
  - meet the operator's existing password policy (FR-063), which legacy keeps in the shared store: a
    minimum length (6 by default) and, optionally, letters, digits, special characters, and both
    lower and upper case. A password that fails MUST be refused with a validation error that names
    each unmet rule and its parameter;
  - be stored in the hash scheme the operator configured for mailcow (`MAILCOW_PASS_SCHEME`:
    BLF-CRYPT by default, or SSHA, SSHA256 or SSHA512), so that Dovecot verifies it;
  - when it begins with one of the hash-scheme prefixes legacy recognises (for example
    `{BLF-CRYPT}`, `{SSHA512}`, `{ARGON2ID}`), be stored as given, without the policy check, as
    legacy does for migrations.

  A password MUST be refused with a validation error when the authentication source is external.
- **FR-025**: The authentication source MUST be mailcow (the default) or the type of the external
  identity provider currently configured on the server. Any other value MUST be refused with a
  validation error. For an external source, forced password change MUST be off. For the generic
  OIDC source, forced two-factor setup MUST also be off. A request that explicitly turns such a flag
  on MUST be refused with a validation error. A template or default value that would turn it on
  MUST be ignored.
- **FR-026**: The other values MUST meet these rules, at creation and when changed:
  - tagged-mail handling: subfolder, subject or none;
  - quarantine notification frequency: never, hourly, daily or weekly;
  - quarantine category: rejected mail, mail moved to junk, or all;
  - rate limit: none, or a positive whole number of messages per second, minute, hour or day;
  - user permissions: only the flags of FR-035;
  - tags: FR-036.
- **FR-027**: A successful creation MUST, as legacy does:
  - store the mailbox with its settings, its password hash, its password-changed time set to now
    and the operator's default mailbox storage format;
  - create its usage records with zero storage and zero messages, for the primary and the replica
    record;
  - create the mailbox's own alias (its address delivering to itself) with the mailbox's status;
  - store its user permissions and its tags;
  - as follow-up steps: store its rate limit and its tagged-mail handling in the shared store when
    they are set, and add it to the groupware directory view when it is active and flush the
    groupware cache. When webmail is disabled on the server (`SKIP_SOGO`), the groupware step MUST
    be skipped.

  The database part of a creation MUST succeed or fail as a whole. The follow-up steps MUST run
  after it, as `docs/conventions.md` requires: the response MUST report each step's outcome, and a
  failing step MUST NOT remove the mailbox.
- **FR-028**: A successful creation MUST respond with the created mailbox, its address and its
  version tag. The response MUST NOT contain the password or its hash.

#### Changing a mailbox

- **FR-029**: A client MUST be able to change a mailbox by sending only the values it changes. The
  changeable values are: the display name, status, quota, password, forced password change, forced
  two-factor setup, webmail redirect, each protocol setting, authentication source, recovery
  e-mail, tags, user permissions and custom attributes. The address changes only by renaming
  (FR-043). A request that sends a read-only value MUST be refused with a validation error naming
  the field.
- **FR-030**: A changed quota MUST NOT exceed the largest quota the mailbox may be given (FR-008)
  or the domain's maximum mailbox quota. Otherwise the request MUST be refused with a validation
  error on the quota that states the largest quota allowed. A quota below the storage already used
  MUST be accepted, as in legacy.
- **FR-031**: When the status changes, the mailbox's own alias MUST take the same status, and the
  groupware directory view MUST list the mailbox only while it is active. Mail delivery and sign-in
  MUST then behave as in legacy: active and login-disabled mailboxes receive mail, only active
  mailboxes can sign in, and inactive mailboxes receive no mail.
- **FR-032**: A changed password MUST follow FR-024 and MUST update the password-changed time. It
  MUST NOT change the forced password change flag, app passwords or existing sessions, as in
  legacy. When the same request switches the authentication source to mailcow, the password MUST
  be applied to the switched mailbox.
- **FR-033**: Switching the authentication source MUST follow FR-025:
  - switching to an external source MUST turn off forced password change, and for generic OIDC
    also forced two-factor setup. The stored password hash MUST be kept, as in legacy;
  - switching to mailcow MUST be refused with a validation error when the request contains no
    password and the mailbox has no stored password.

  The recovery e-mail MUST be a valid e-mail address or empty to clear it, and MUST only be set
  for the mailcow authentication source.
- **FR-034**: After a successful change, the system MUST update the mailbox's groupware directory
  entry and flush the groupware cache (unless webmail is disabled), and MUST clear the marker that
  the identity-provider synchronisation uses to detect template changes, as legacy does on every
  edit.

#### Permissions, tags and custom attributes

- **FR-035**: A mailbox MUST have these user permission flags, as stored by legacy: temporary
  aliases (`spam_alias`), TLS policy (`tls_policy`), spam score (`spam_score`), allow and block
  lists (`spam_policy`), delimiter action (`delimiter_action`), sync jobs (`syncjobs`), ActiveSync
  reset (`eas_reset`), SOGo profile reset (`sogo_profile_reset`), push notifications (`pushover`),
  quarantine actions (`quarantine`), quarantine attachments (`quarantine_attachments`), quarantine
  notifications (`quarantine_notification`), quarantine category (`quarantine_category`), app
  passwords (`app_passwds`) and password reset (`pw_reset`).
  - Five flags overlap with domain-administrator flags of the same name: `syncjobs`, `quarantine`,
    `app_passwds`, `pushover` and `spam_policy`. For a domain administrator who lacks such a flag,
    the matching user permission MUST be omitted from every representation and MUST NOT be
    changeable, as in legacy.
  - A change MUST change only the flags it sends.
  - Users MUST NOT change their own permissions.
- **FR-036**: A mailbox's tags MUST be a set of texts, as in feature 002 (FR-043 and FR-044 there):
  each tag trimmed, not empty and at most 255 characters long; tags differing only in letter case
  are the same tag; at most the server's tagging limit, an API server setting that defaults to 25
  as legacy's `TAGGING_LIMIT` does.
  Setting the tags MUST replace the whole set. Removing tags, which legacy does with
  `delete/mailbox/tag`, is done by setting the set without them.
- **FR-037**: A mailbox's custom attributes MUST be a set of named text values. Each name MUST be 1
  to 255 characters long without whitespace, and each value a text of at most 4,096 characters; a
  mailbox MUST NOT have more than 100 attributes. A change MUST add or replace the attributes it
  sends with a value and remove those it sends without a value (null). The attributes MUST be
  stored where legacy stores them, so that domain footers substitute `{= name =}` with their values
  unchanged.

#### Version tags

- **FR-038**: Every mailbox and mailbox template MUST carry a version tag that changes whenever any
  of its changeable values changes, whether the change was made through this API, the legacy UI or
  `/api/v1`. Usage figures and last logins MUST NOT change it. A client MAY send the version it read
  with a change, a deletion or a rename. When the version no longer matches, the request MUST be
  refused as outdated and change nothing. The password policy read carries feature 013's version
  tag (FR-063).

#### Deleting a mailbox

- **FR-039**: Administrators and domain administrators MUST be able to delete a mailbox in their
  scope. A successful deletion MUST respond with no body.
- **FR-040**: Before removing anything else, the system MUST ask the mail store to move the
  mailbox's mail to mailcow's garbage area, from which mailcow purges it after the operator's
  retention period (legacy `MAILDIR_GC_TIME`, 5 days by default). In replicated cluster setups the
  request MUST go to every mail node. When the mail store reports a failure or cannot be reached,
  the deletion MUST be refused with the "temporary failure" error and nothing MUST be removed. When
  it does not answer within the container API timeout (`DOCKER_TIMEOUT`, 60 s), the deletion MUST
  answer "outcome unknown" and MUST remove no records. A retry is safe, because a mail directory
  that is already gone counts as moved.
- **FR-041**: A successful deletion MUST remove, as legacy does:
  - the mailbox, its usage records, user permissions, tags, sieve filters and app passwords;
  - aliases whose only recipient is the mailbox, including its own alias, and the mailbox from the
    recipients of every other alias;
  - sender permissions held by the mailbox and sender permissions that let others send as it;
  - its temporary aliases, the sync jobs that deliver into it, its spam filter settings and allow
    and block lists, its quarantine items, its push notification settings, and BCC maps whose local
    destination is the mailbox;
  - its webmail profile, cached folders, folders, contacts, appointments and webmail sharing
    permissions;
  - its OAuth access tokens, refresh tokens and authorisation codes;
  - its second factors and passkeys;
  - its rate limit in the shared store.

  It MUST also remove what legacy leaves behind: its tagged-mail handling in the shared store and
  its recent-login records and password-reset tokens, so that a later mailbox with the same
  address inherits nothing. **Security deviation**: reset tokens are removed in the same
  transaction as the mailbox; a token for the deleted owner cannot reset a replacement mailbox.
- **FR-042**: The database part of a deletion MUST succeed or fail as a whole. After it, the system
  MUST remove the mailbox from the groupware directory view and flush the groupware cache (unless
  webmail is disabled).

#### Renaming a mailbox

- **FR-043**: Administrators and domain administrators MUST be able to rename a mailbox in their
  scope to a new local part in the same domain. The request MUST contain the new local part,
  normalised and validated as in FR-019, and MUST state whether to keep the old address as an
  alias. The response MUST contain the renamed mailbox and its new address. Afterwards the old
  address MUST answer "not found" as a mailbox.
- **FR-044**: Before changing anything, the system MUST check that:
  - the mailbox is active or login disabled. Otherwise the request MUST be refused with the
    "mailbox cannot be renamed" error, as legacy refuses it;
  - the new local part differs from the old one;
  - the new address is not used (FR-021);
  - when the old address is to be kept as an alias, the domain's alias limit allows one more alias;
  - the mail store and webmail services can be reached.
- **FR-045**: A rename MUST perform every change legacy performs:
  - change the mailbox's address and local part and its own alias;
  - replace the old address with the new one in its tags, sieve filters, app passwords, user
    permissions, usage records, push notification settings, alias recipients, sync job
    destinations, BCC maps (local and BCC destination), recipient maps (old and new destination)
    and sender permissions (holder and send-as target). Unlike legacy, it MUST NOT move the
    permission flags of a domain administrator account that has the same name;
  - update its groupware directory entry and rename its webmail data with the webmail rename tool;
  - move its mail store to the new address;
  - re-create, for the new address, the shared-folder permissions it granted to others and those
    others granted to it.
- **FR-046**: A rename MUST also carry over what legacy leaves under the old address: second
  factors, passkeys, temporary aliases, spam filter settings and allow and block lists, quarantine
  items, the rate limit and tagged-mail handling in the shared store, and recent-login records. It
  MUST revoke the mailbox's OAuth tokens and authorisation codes, which are bound to the old
  address, and MUST flush the groupware cache.
- **FR-047**: When the old address is kept, the system MUST create an active alias from the old
  address to the new one, visible in the webmail address book, with the private comment "Mailbox
  was renamed from <old> to <new>", as legacy does.
- **FR-048**: A rename MUST either complete or leave the mailbox as it was. When a step fails, the
  system MUST undo the steps already done and refuse the request with the "temporary failure"
  error. When undoing also fails, the system MUST answer with the "rename incomplete" error, which
  lists the steps that are done and those that are not, and MUST record the same in the audit log.
  A step that does not answer within the container API timeout (`DOCKER_TIMEOUT`, 60 s) has an
  unknown outcome. The system MUST undo the steps known to be done and answer "outcome unknown",
  naming that step.

#### Mailbox templates

- **FR-049**: Administrators MUST be able to list, inspect, create, change and delete mailbox
  templates. Domain administrators MUST be able to list and inspect them. A template MUST be
  represented with its identifier, name, whether it is the Default template, its values (FR-050),
  its creation and modification times and its version tag.
- **FR-050**: A template MUST hold: quota, status, forced password change, forced two-factor setup,
  webmail redirect, protocol access, user permissions, tags, tagged-mail handling, quarantine
  notification frequency, quarantine category, inbound and outbound TLS enforcement, and a rate
  limit. The values MUST meet the rules of FR-009, FR-026 and FR-036. A template created without
  some values MUST get the built-in defaults of FR-022 for them, with an unlimited quota as in the
  legacy Default template.
- **FR-051**: A template name MUST NOT be empty, MUST be at most 255 characters long, and MUST be
  unique among mailbox templates, compared case-insensitively. A duplicate MUST be refused with the
  "template name already exists" conflict.
- **FR-052**: A change MUST change only the values it sends. The Default template MUST NOT be
  renamed or deleted. Such a request MUST be refused with the "protected template" error, and
  nothing MUST change.
- **FR-053**: The template list MUST support pagination, sorting by name, identifier, creation time
  and modification time (default: name, ascending), and a search on the name. Each entry MUST carry
  its version tag. Templates MUST only pre-fill new mailboxes. Changing or deleting a template MUST
  NOT change any mailbox.

#### Bulk actions

- **FR-054**: Administrators and domain administrators MUST be able to apply one of these actions
  to up to 100 mailboxes in one request, as the legacy mass actions do: activate, disable login,
  deactivate, delete, or allow or disallow one of the six protocols. A request with more than 100
  mailboxes MUST be refused with a validation error that states the limit.
- **FR-055**: A bulk action MUST be refused as a whole when the caller's role, key scope or flags
  (FR-006) do not allow the action. Otherwise each named mailbox MUST be processed on its own, with
  the same checks, side effects and audit record as a single request for it, and the response MUST
  report one result per distinct mailbox: `done`, `notFound`, `refused` (with the error type),
  `outdated` (FR-067) or `failed` (with the error type). A failure on one mailbox MUST NOT stop or
  undo the others, as in legacy.

#### Audit

- **FR-056**: Every request of this feature that changes state, or tries to, MUST write one audit
  record per affected mailbox or template into feature 001's audit log, with the fields of FR-046
  there and:
  - the target type (mailbox or mailbox template) and identifier, and for a rename both addresses;
  - the outcome, including refusals;
  - for successful changes, each changed value with its old and new value. A password MUST be
    recorded only as "password set", with whether it was pre-hashed;
  - for bulk actions, the identifier of the bulk request;
  - the outcome of each follow-up step of a creation, deletion or rename (mail store, groupware
    directory, webmail rename, shared-folder permissions).
- **FR-057**: Administrators MUST be able to filter the audit log by target type and target
  identifier, in addition to the filters of feature 001.

#### Cross-cutting

- **FR-058**: Errors MUST follow feature 001's conventions (FR-049 and FR-055 there). This feature
  MUST document at least these error types: not found, address in use (with the kind of holder),
  domain mailbox limit reached (with the count and the limit), attribute not permitted for the
  role (with the field and the flag), outdated version, mailbox cannot be renamed, rename
  incomplete (with the steps), outcome unknown (with the step that timed out), template name
  already exists, protected template, and temporary failure (mail store, webmail service or shared
  store unavailable).
- **FR-059**: Validation errors MUST carry stable rule codes and their parameters, as feature 001
  requires (FR-055 there). They MUST include the quota rules with the bytes allowed, each unmet
  password policy rule with its parameter, the tag limit, and the read-only field rule.
- **FR-060**: Passwords and password hashes MUST NOT appear in any response, log line or audit
  record. Request logging MUST redact them.
- **FR-061**: Every operation MUST be described in the published API contract before it is
  implemented, and its behaviour MUST match the contract.
- **FR-062**: Changes made through the legacy UI or `/api/v1` MUST be visible in the next response
  of this API.

#### Password policy

- **FR-063**: Administrators and domain administrators, who may set mailbox passwords, MUST be able
  to read the current password policy. Mailbox users MUST be refused. Read-only API keys MAY read
  it.
  - The policy MUST list each rule with its parameter: the minimum length, and whether letters,
    digits, special characters, and both lower and upper case are required.
  - The values MUST be read from where legacy keeps them (the shared store). Rules the operator
    never saved MUST be reported with the legacy defaults: a minimum length of 6 and no other rule
    required.
  - A change made in the legacy UI MUST be visible in the next response, without caching. The
    password checks of FR-024 MUST use the same values, so that a password meeting the reported
    rules is accepted.
  - The policy MUST be returned as structured rules, not as legacy's ready-made HTML text, so that
    the web app renders and translates it.
  - The response MUST carry the policy's version tag. It MUST be the same version tag that feature
    013 uses to edit the policy (FR-008, FR-036 and FR-037 there), computed over the same effective
    policy values, including the legacy defaults for rules never saved. A client can then read the
    policy here and send the tag with a change in feature 013 without a conflict. The tag MUST change
    whenever a policy value changes, whether through feature 013, the legacy UI or `/api/v1`.
  - The policy MUST NOT be changed through this feature. Editing it belongs to feature 013.

#### Capabilities

- **FR-064**: Administrators and domain administrators MUST be able to read the mailbox
  capabilities, the server settings and limits a client needs before it submits:
  - the type of the configured external identity provider: none, Keycloak, generic OIDC or LDAP,
    read from mailcow's identity-provider settings. It decides which authentication sources a
    mailbox may be given at creation or switched to (FR-025). Feature 012 relies on domain
    administrators learning it here;
  - whether the groupware is enabled (`SKIP_SOGO`). When it is disabled, clients can hide the
    webmail redirect, which then has no effect, as legacy does;
  - whether last logins are reported (FR-010), so that clients can tell "turned off" from "never
    logged in";
  - the bulk limit (FR-054), the tagging limit and the maximum tag length (FR-036), the
    custom-attribute limits (FR-037), and the default and maximum page sizes (FR-012);
  - the garbage-area retention period (`MAILDIR_GC_TIME`, FR-040).

  The values MUST reflect the server's current configuration and MUST NOT include any secret or
  connection detail of the identity provider. The read MUST change nothing, and read-only keys MAY
  use it.

#### Deletion preview

- **FR-065**: Administrators and domain administrators MUST be able to preview the deletion of a
  mailbox in their scope. The preview MUST change nothing and MUST NOT write an audit record.
  Read-only keys MAY use it. It MUST report, computed by the same rules as the deletion itself
  (FR-040 to FR-042):
  - what would be removed: the aliases whose only recipient is the mailbox (not counting its own
    alias), with up to 100 addresses; the aliases that would only lose it as a recipient, with up
    to 100 addresses; and the number of sender permissions it holds, sender permissions that let
    others send as it, temporary aliases, sync jobs delivering into it, app passwords, sieve
    filters, spam filter settings and allow and block list entries, quarantine items, BCC maps,
    OAuth tokens and codes, second factors, passkeys, tags and recent-login records;
  - whether push notification settings, webmail data, a rate limit and a tagged-mail handling
    exist;
  - the storage used and the message count that would move to the garbage area, and the retention
    period before they are purged;
  - whether the deletion could currently proceed. The only blockers are services the deletion needs
    (mail store, shared store) being unreachable, and each one is named;
  - the mailbox's current version tag, which the client can send with the deletion.

  A preview of a mailbox that does not exist or lies outside the caller's scope MUST be answered
  "not found".

#### Tags in use

- **FR-066**: Administrators and domain administrators MUST be able to list the distinct tags of the
  mailboxes in their scope. Each entry MUST give the tag and the number of mailboxes in scope that
  carry it. Tags that differ only in letter case MUST form one entry, as the tag filter matches them
  (FR-013). The list MUST support a case-insensitive substring search and pagination as in FR-012,
  sorted alphabetically without regard to letter case. It MUST NOT reveal tags that appear only on
  mailboxes outside the caller's scope.

#### Bulk version tags

- **FR-067**: Each mailbox named in a bulk action MAY carry its version tag (FR-038). When an item's
  tag no longer matches, that item MUST get the result `outdated` and MUST change nothing, and the
  other items MUST proceed. Items without a tag MUST NOT be version-checked.

### Legacy Coverage

Each allocated legacy action and screen, and the requirements that cover it.

| Legacy action or screen | Requirements |
|---|---|
| `add/mailbox` and the "Add mailbox" form | FR-018 to FR-028, FR-006 |
| `get/mailbox/all` (optionally per domain and tags) | FR-012 to FR-016 |
| `get/mailbox/reduced` | FR-012, FR-016 (the list carries the full representation) |
| `get/mailbox/{id}` (also with `?tags=`) | FR-017, FR-008 to FR-011; tag selection via FR-013 |
| `search/mailbox` (server-side table of the "Mailboxes" tab) | FR-012 to FR-016 |
| `edit/mailbox` and the "Edit mailbox" tab | FR-029 to FR-034, FR-006 |
| `edit/mailbox` fields `sender_acl`, `extended_sender_acl` | Not covered: feature 004 |
| `edit/mailbox` field `relayhost` | Not covered: feature 009 (read-only in FR-008) |
| `delete/mailbox` | FR-039 to FR-042 |
| `add/mailbox/template`, `get/mailbox/template/{all,id}`, `edit/mailbox/template`, `delete/mailbox/template`, "Templates" tab and template edit page | FR-049 to FR-053 |
| `edit/mailbox/custom-attribute` and the "Custom attributes" tab | FR-037, FR-029 |
| `edit/rename-mbox` and the "Rename" tab | FR-043 to FR-048 |
| `edit/user-acl` and the "ACL" tab | FR-035, FR-006 |
| `delete/mailbox/tag` | FR-036 |
| Quota against domain limits | FR-009, FR-023, FR-030 |
| Protocol access | FR-008, FR-029, FR-006, FR-054 |
| Admin-set password | FR-024, FR-032, FR-060 |
| `get/passwordpolicy` and `get/passwordpolicy/html` (read only) | FR-063; editing (`edit/passwordpolicy`) belongs to feature 013 |
| Forced password change and forced 2FA flags | FR-008, FR-025, FR-029, FR-033 |
| Mailbox list mass actions: activate, disable login, deactivate, delete, protocol on/off | FR-054, FR-055, FR-067 |
| Server settings the legacy forms depend on (`SKIP_SOGO` hides the webmail redirect, the identity-provider type fills the source select, `SHOW_LAST_LOGIN` hides last logins) | FR-064 |
| "Remove" confirmation in the list and on the edit page | FR-039, FR-065 (preview with counts) |
| Tag input and tag filter (legacy suggests nothing) | FR-066 |
| Mass actions for TLS policy and quarantine notifications | Not covered: features 005 and 006 |
| "Edit mailbox" buttons for tagging, TLS policy, quarantine notifications; "Pushover" and "Rate limit" tabs | Not covered: features 005 and 006 (initial values at creation in FR-018) |
| Login-as and SOGo login buttons in the list | Not covered: feature 007 |
| Internal `mailbox_from_template` (identity-provider provisioning) | Not covered: feature 012 |

### Legacy Parity and Deliberate Deviations

Parity reference: `source_code/data/web/inc/functions.mailbox.inc.php` (`add`/`edit`/`get`/`delete`
for `mailbox`, `mailbox_templates`, `mailbox_custom_attribute`, `mailbox_rename`, `tags_mailbox`,
`delimiter_action`), `functions.acl.inc.php` (`acl` for `user`, `to_session`), `functions.inc.php`
(`hash_password`, `password_check`, `password_complexity`, `hasDomainAccess`,
`hasMailboxObjectAccess`, `hasACLAccess`, `update_sogo_static_view`, `flush_memcached`),
`functions.ratelimit.inc.php`, `json_api.php` (including `get/passwordpolicy`),
`Dockerfiles/phpfpm/docker-entrypoint.sh` (`PASSWD_POLICY` defaults), `vars.inc.php`
(`MAILBOX_DEFAULT_ATTRIBUTES`, `TAGGING_LIMIT`, `SHOW_LAST_LOGIN`), `init_db.inc.php` (`mailbox`,
`quota2`, `quota2replica`, `user_acl`, `da_acl`, `tags_mailbox`, `templates` and the Default mailbox template), the templates
`modals/mailbox.twig`, `edit/mailbox.twig`, `mailbox/tab-mailboxes.twig`, `js/site/mailbox.js`,
`Dockerfiles/dockerapi/modules/DockerApi.py` (`maildir cleanup|move`, `sogo rename_user`,
`doveadm get_acl|delete_acl|set_acl`) and `Dockerfiles/postfix/postfix.sh` (status values).

The reference is upstream mailcow-dockerized `2026-09a`. `source_code/` points to the FeldHost fork
(`~/Projects/mailhosting`, branch `feldhost-202609a`), so the files above were diffed against that
upstream tag. All mailbox business logic is identical. The fork differs only in display texts: the
mailbox list and edit page label the `mailcow` authentication source "FeldHost™ Mailhosting", and
the `pw_reset` permission's label names the product. The API returns the source code `mailcow`
either way, and clients take display names from the branding configuration (feature 001). The fork's
other default change, the DKIM selector of the Default domain template, belongs to feature 002.

| Behaviour | Legacy | This feature |
|---|---|---|
| Address normalisation | Local part trimmed and lower-cased, domain converted to ASCII, PHP e-mail validation | Same, with the validation rules stated (FR-019, FR-020) |
| Address uniqueness | `mailbox` (any kind), `alias` and `spamalias` checked, each with its own message | Same, as one conflict that names the holder (FR-021) |
| Domain of a new mailbox | Must be in `domain`; alias domains rejected; inactive and backup-MX domains accepted | Same (FR-020) |
| Mailbox count and quota limits | Count of `kind = ''` mailboxes, `maxquota` per mailbox, sum of quotas in MiB against `quota`; `0` not counted; domain admins need `unlimited_quota` | Same, in bytes (FR-009, FR-023, FR-030). Limits also hold under concurrency. **Deviation**: negative quotas are rejected |
| Quota default (owner decision, 2026-10-18) | Server: named template, else first template (Default: 0 = unlimited). UI: pre-fills the domain's default quota (`domain.defquota`, an upstream leftover since mailbox templates carry the quota) | Named template, else Default template, else unlimited, like every other value (FR-022). Same as the server. The domain's default mailbox quota is not exposed (feature 002 FR-020), and the API keeps the column valid for legacy. Until 2026-10-18 the API took the domain's default quota for a new mailbox |
| Display name default | Meant to default to the local part, but an ordering bug stores an empty name | Local part (FR-022). **Deviation** (bug fix) |
| Template application | Server merges the named or first template into the defaults, but applies the template's tagged-mail handling only when the form sends it. The seeded Default template lacks the password-reset permission, so `/api/v1` creations without ACLs get it off while the UI pre-selects it | Every template value applies, and a value the template lacks comes from the built-in defaults (FR-022). **Deviation** (bug fix) |
| Password input | `password` and `password2` must match | One field (FR-024). **Deviation**: confirmation is the client's job |
| Password policy and hashing | `PASSWD_POLICY` in Redis; `hash_password` with `MAILCOW_PASS_SCHEME`; pre-hashed values with a known `{SCHEME}` prefix stored as given; `passwd_update` set | Same (FR-024). **Deviation**: the policy is not evaluated against a pre-hashed value (legacy evaluates it against the hash text) |
| External authentication source | Password silently ignored; `force_pw_update` forced to 0 for Keycloak, generic OIDC and LDAP, `force_tfa` for generic OIDC | Same storage rules, but explicit requests are refused with validation errors (FR-024, FR-025). **Deviation** |
| Password plus source switch in one edit | The password update runs before the source switch and is skipped, because it only updates `authsource = 'mailcow'` rows | Password applied after the switch (FR-032). **Deviation** (bug fix) |
| Creation side effects | `mailbox` row with JSON `attributes`, `quota2` and `quota2replica` rows, own `alias` row, `user_acl` row, `tags_mailbox`, `RL_VALUE` via `ratelimit()`, `RCPT_WANTS_SUBJECT_TAG`/`RCPT_WANTS_SUBFOLDER_TAG` via `delimiter_action`, `_sogo_static_view` upsert and memcached flush unless `SKIP_SOGO` | Same (FR-027), with the database part atomic |
| Domain-admin flags at creation | Not checked for protocol access, SOGo access and user ACL flags; rate limit silently skipped without `ratelimit` | Checked (FR-006, FR-035). **Deviation** (security fix: legacy lets a domain admin grant permissions they lack). Template values still apply. **Deviation**: legacy drops the template's rate limit for such domain admins |
| Domain-admin flags on edit | Values silently ignored without `protocol_access`, `sogo_access`, `mailbox_relayhost` | Refused with an error naming the flag (FR-006). **Deviation** |
| Status values | `active` 1, 2 (disallow login, mail accepted) or 0; own alias takes the same value; only `active = 1` in `_sogo_static_view`; Postfix accepts mail for 1 and 2 | Same (FR-008, FR-031) |
| Edit side effects | `_sogo_static_view` incremental update, memcached flush, `attributes.attribute_hash` reset to empty on every edit | Same (FR-034) |
| Recovery e-mail | Set by admins on the edit page, only for `mailcow` mailboxes, not validated | Same scope; validated as an e-mail address (FR-033). **Deviation** |
| Tags | Added by `edit/mailbox`, removed by `delete/mailbox/tag`; limit checked per request with an off-by-one (26 accepted), excess silently dropped; duplicates ignored | Whole-set replacement with a total limit of 25 and a validation error (FR-036). **Deviation**, aligned with feature 002 |
| Tag filter | Substring match, any of the given tags | Whole-tag match, any of the given tags; substrings through the search (FR-013, FR-014). **Deviation**, aligned with feature 002 |
| Custom attributes | Replaced as a whole; spaces silently removed from names; no limits | Added, replaced or removed per name; names with whitespace refused; size limits (FR-037). **Deviation** |
| User permissions | Domain admins see and change only the user ACL flags they hold themselves (overlap with `da_acl`); users cannot change their own | Same (FR-035), and also enforced at creation (FR-006) |
| Deletion order and cascade | `maildir cleanup` via dockerapi (broadcast in cluster mode) first; failure only a warning. Then `alias`, `pushover`, `quarantine`, `quota2`, `quota2replica`, `mailbox`, `sender_acl`, `user_acl`, `spamalias`, `imapsync`, `filterconf`, `sogo_*`, `bcc_maps`, `oauth_*`, `tfa`, `fido2`, goto rewrite of other aliases, `RL_VALUE`; foreign keys remove `tags_mailbox`, `sieve_filters`, `app_passwd` | Same set (FR-040 to FR-042). **Deviation**: refused when the mail-store move is not confirmed, so a later mailbox with the same address cannot inherit old mail. **Deviation**: also removes `RCPT_WANTS_*` entries and `sasl_log` rows |
| Rename | `doveadm` ACLs read and deleted, SQL updates in one transaction (`mailbox`, `alias`, `tags_mailbox`, `sieve_filters`, `app_passwd`, `user_acl`, `da_acl`, `quota2`, `quota2replica`, `pushover`, `imapsync`, `bcc_maps`, `recipient_maps`, `sender_acl`, `_sogo_static_view`), `maildir move`, `sogo rename_user`, ACLs re-set, optional alias with a comment. No check that the new address is free, no lower-casing, no undo after the ACLs are deleted, and `tfa`, `fido2`, `spamalias`, `filterconf`, `quarantine`, `RL_VALUE`, `RCPT_WANTS_*`, `sasl_log` and `oauth_*` stay under the old address. No memcached flush | Same mechanisms (FR-045, FR-047), plus preflight checks and normalisation (FR-043, FR-044), undo (FR-048), carry-over of the forgotten data, revocation of OAuth tokens and a cache flush (FR-046). `da_acl` rows are not moved, because they belong to a separate domain-administrator account. **Deviations** (bug fixes) |
| Rename of inactive mailboxes | Refused (`active` must be 1 or 2) | Same (FR-044) |
| Template edits | Omitted quota reset to 0; edits without protocol or ACL fields copy unrelated row fields into the attributes; Default name kept silently; Default deletion silently ignored; the UI form sends `activesync` instead of `eas`, so new templates never allow ActiveSync | Only sent values change; Default protected with an explicit error; explicit boolean per protocol (FR-050 to FR-052). **Deviations** (bug fixes) |
| Mass actions | `edit/mailbox` and `delete/mailbox` with arrays; processing continues past failures; no version check | Bulk action with one result per mailbox (FR-054, FR-055), and optional per-item version tags that give `outdated` (FR-067). **Deviation** |
| Server settings for forms | Twig renders the forms from PHP configuration and the identity-provider settings; `/api/v1` offers no read of them | Mailbox capabilities read (FR-064). **Deviation** (new read) |
| Deletion confirmation | Generic "Are you sure?" dialog without counts | Read-only deletion preview with counts, retention and blockers (FR-065). **Deviation** (new read) |
| Tags in use | Not available | Distinct tags in scope with counts (FR-066). **Deviation** (new read) |
| Legacy PHP configuration (`MAILBOX_DEFAULT_ATTRIBUTES`, `TAGGING_LIMIT`, `SHOW_LAST_LOGIN`) | Read directly from `vars.(local.)inc.php` | API server settings with upstream defaults, kept aligned by the operator (FR-010, FR-022, FR-036), as `docs/conventions.md` requires. **Deviation** |
| Creation follow-up failures | A failing rate-limit or tagging step adds an error message; the mailbox stays | Same principle: each follow-up step's outcome is reported (FR-027) |
| Container API timeouts | `DOCKER_TIMEOUT` (60 s); deletion continues after a failed maildir move | "Outcome unknown", with no records removed (FR-040, FR-048). **Deviation** |
| Breached-password check | The browser asks the haveibeenpwned service while a password is typed | Not offered. The new web app makes no third-party calls, and the API adds no server-side check. **Deviation** |
| Listing | `get/mailbox/all` makes one detail lookup per mailbox; `search/mailbox` pages server-side but still looks up each row | Paginated list without per-row lookups (FR-012 to FR-016). **Deviation** (performance) |
| Concurrent edits | No protection | Version tags (FR-038). **Deviation** |
| Out-of-scope objects | `access_denied` | "Not found" (FR-003). **Deviation**: existence is not revealed |
| Change log | Entries in the `logs` table | This API's audit log (FR-056), as in feature 001. **Deviation**: changes made through the new API do not appear in the legacy log viewer |
| Dovecot credential cache | Old password may work for up to `auth_cache_ttl` (300 s) | Same; documented (Edge Cases) |
| Password policy read | `get/passwordpolicy` returns the `PASSWD_POLICY` hash from Redis as raw strings, and `get/passwordpolicy/html` returns a translated HTML list; any signed-in role can call them. php-fpm seeds the defaults (length 6, other rules 0) at start-up | Same source and defaults, as structured rules for administrators and domain administrators only, with the version tag that feature 013 edits against (FR-063). **Deviation**: no HTML, because the client translates; mailbox users are refused here, as their own password change is feature 005 |
| Rename of multi-recipient alias destinations (plan) | Only aliases whose destination is exactly the old address are updated; aliases that also point elsewhere keep the old address | The old address is replaced inside every alias destination list (FR-045). **Deviation** (bug fix) |
| Groupware directory password (plan) | The "random" hash is built from undefined variables, so every row gets the same hash of an empty string | A random unusable hash per write, as feature 002 decided (FR-027, FR-034). **Deviation** (bug fix) |
| Rename with the groupware disabled (plan) | `sogo-tool rename-user` is called even when `SKIP_SOGO=y`, and its result is ignored | The webmail rename step is skipped and recorded as skipped (FR-045, FR-048). **Deviation** |
| Maildir moves in replicated clusters (plan) | Broadcast to every node through `MC_CHANNEL`, without any confirmation | Confirmed on the current node, then broadcast to every node (FR-040, FR-045). **Deviation** |
| API-owned data of the mailbox user (plan) | Not applicable | Feature 001's stored preferences follow a rename and are removed by a deletion, so that a later mailbox with the same address inherits nothing (FR-041, FR-046) |
| Quota default when the domain quota is exhausted (plan) | The UI pre-fills the domain's default quota, which can be 0 (unlimited) when no quota is left | Withdrawn on 2026-10-18 with the domain default (owner decision): a template's quota counts against the quota left like a sent one, and an unlimited quota from the template chain is not counted, as in legacy's server (FR-022, FR-023) |

### Key Entities *(include if feature involves data)*

- **Mailbox** (existing mailcow data): a mailbox with its address, local part, domain, display name,
  password hash (never exposed), quota, status, authentication source, settings (forced flags,
  webmail redirect, protocol access, TLS enforcement, quarantine notification settings, relayhost
  assignment, password-changed time, recovery e-mail, storage format, identity-provider template
  marker), custom attributes, creation and modification times. Resources share its store but are
  not mailboxes.
- **Mailbox usage** (existing mailcow data): storage used and message count, in a primary and a
  replica record.
- **User permissions** (existing mailcow data): the fifteen permission flags of one mailbox user.
- **Mailbox tag** (existing mailcow data): a label attached to one mailbox.
- **Mailbox template** (existing mailcow data): a named set of starting values for new mailboxes,
  with an identifier and creation and modification times. The Default template always exists.
- **Own alias** (existing mailcow data): the alias row through which a mailbox receives mail at its
  own address. Its status follows the mailbox.
- **Domain** (feature 002): supplies the limits checked here (maximum mailboxes, maximum mailbox
  quota, domain quota) and the backup MX flag.
- **Domain-administrator permissions** (existing mailcow data, feature 007 manages them): the flags
  that gate what a domain administrator may choose here.
- **Groupware directory entry** (existing mailcow data): the denormalised record through which SOGo
  sees an active mailbox.
- **Shared-store entries** (existing mailcow data): a mailbox's rate limit and tagged-mail handling.
- **Password policy** (existing mailcow data, edited in feature 013): the minimum length and the
  required character classes for passwords, read-only here.
- **Recent-login record** (existing mailcow data): the last login of a mailbox per service and
  address.
- **Bulk action** (transient): an action, up to 100 mailboxes each with an optional version tag, and
  one result per mailbox.
- **Mailbox capabilities** (read-only view of server settings): the identity provider type, the
  groupware and last-login switches, the limits and the garbage-area retention.
- **Deletion preview** (computed): what deleting one mailbox would remove or move, the blockers and
  the version tag.
- **Tag in use** (computed): a distinct mailbox tag in the caller's scope and how many mailboxes
  carry it.
- **Audit record** (feature 001): one immutable record per change or attempted change.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For 100% of the operations in the parity test set (creations with and without
  templates, every changeable field, deletions with every kind of dependent data, renames), the
  resulting stored data and the requests to the mail services equal those of the same operation in
  the legacy UI, apart from the documented deviations.
- **SC-002**: On a server with 10,000 mailboxes in 500 domains, 95% of list requests for a page of
  100 mailboxes, with any filter, search and sort, complete within 1 second, and a page of 1,000
  within 5 seconds.
- **SC-003**: A mailbox created through the API can sign in to IMAP, SMTP and webmail, and receive
  mail, within 1 minute of the response, in 100% of attempts.
- **SC-004**: 100% of attempts by domain administrators to reach mailboxes outside their domains
  get the same answer as for a nonexistent mailbox, and 100% of attempts to choose a setting their
  flags do not allow are refused, at creation, change and in bulk actions.
- **SC-005**: 100% of stale writes, including those raced against a legacy UI edit, are refused as
  outdated, and none of them changes data.
- **SC-006**: 1,000 concurrent creation attempts against a domain with room for 10 mailboxes create
  exactly 10 mailboxes and never exceed the domain quota.
- **SC-007**: After a mailbox is deleted and a mailbox with the same address is created, the new
  mailbox shows no mail, settings, recent logins, second factors or tagging preferences of the old
  one, in 100% of test runs.
- **SC-008**: After a rename, the user signs in with the new address and the same password, sees
  100% of their messages and folders, and keeps every second factor and shared-folder permission.
  95% of renames complete within 30 seconds.
- **SC-009**: A bulk action on 100 mailboxes completes within 10 seconds (deletions within 60
  seconds) and reports exactly one result per mailbox.
- **SC-010**: Every state change and every refused attempt produces exactly one audit record per
  affected mailbox or template. A scan of all responses, audit records and service logs after the
  full test run finds no password or password hash.
- **SC-011**: An administrator can find a mailbox among 10,000 by a fragment of its address or name
  and open its details within 10 seconds in the web app.

## Assumptions

- Feature 001 provides sign-in, roles, permission flags, API keys, the error conventions and the
  audit log. Feature 002 provides the domains and their limits and usage figures, which the web app
  uses to suggest a quota before creation.
- This feature exposes the password policy read-only (FR-063). Editing it comes with feature 013,
  and until then operators edit it in the legacy UI. The web app shows the rules before submit and
  generates passwords in the browser, as legacy does. The API does not generate passwords.
- The external identity provider is configured in the legacy UI until feature 012. This feature only
  reads which provider type is configured.
- The mail store, the webmail service and the shared-folder permissions are reached through
  mailcow's existing container API, with the commands legacy uses. No mailcow container is changed.
- Mailbox users' permission flags take effect in the legacy UI at the user's next sign-in, because
  legacy loads them into the session, and in this API on the next request.
- Settings managed by other features (tagged-mail handling and TLS enforcement in 005, quarantine
  notifications and rate limits in 006, relayhost assignment in 009, sender permissions in 004) are
  shown read-only here and changed there. Their mass actions arrive with those features.
- When upstream adds a user permission flag, the contract gains it in a later release. Until then it
  keeps its database default.
- The tagging limit, the bulk limit of 100 and the custom-attribute limits are API server settings
  with these defaults; a setting can lower the bulk and custom-attribute limits but not raise them.
  The page sizes (25 by default, at most 1,000) are fixed for every list by `docs/conventions.md`.
  Clients read the values in force from FR-064.
- There is no breached-password check. Legacy's check runs in the browser against a third-party
  service. The new web app makes no third-party calls, and the API does not replace the check
  (orchestrator decision).
- **Out of scope**:
  - sender permissions and external sender addresses (004);
  - users managing their own mailbox, recovery e-mail, tagging, TLS policy, push notifications, app
    passwords and temporary aliases (005);
  - quarantine notification settings and rate limits after creation (006);
  - login-as and webmail login on behalf of a user (007);
  - sync jobs (008);
  - relayhost assignment (009);
  - identity-provider provisioning and synchronisation (012);
  - editing the password policy, and quota notifications (013);
  - resources (004).
- **Dependencies**:
  - the upstream mailcow database schema of the supported release;
  - mailcow's container API (mail store moves and cleanups, webmail rename, shared-folder
    permissions), the shared store and the groupware cache;
  - features 001 and 002.
