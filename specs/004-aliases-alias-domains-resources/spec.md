# Feature Specification: Aliases, Alias Domains, Resources and Sender Permissions

**Feature Branch**: `main` (no feature branches, constitution 1.1.0)

**Created**: 2026-10-18

**Status**: Draft

**Input**: Feature description built from `docs/roadmap.md` (feature 004 and allocation row 004):

> Aliases, alias domains, resources and sender permissions for mailcow REST API v2. Administrators
> and domain administrators list, search, inspect, create, change and delete the aliases of an
> existing mailcow-dockerized server: ordinary aliases and catch-all aliases, delivering to a list
> of addresses or performing one of the special actions (discard, learn as spam, learn as ham),
> with their groupware visibility, internal-only flag, "allowed to send as" flag and public and
> private comments. They copy aliases over the domain's alias domains. They manage alias domains
> that make a domain's mailboxes reachable under another domain name, and resources (rooms,
> equipment and groups) that are booked through the groupware. They manage which addresses a
> mailbox may send as: explicit permissions for addresses and domains of the server, the "any
> address" permission, and external sender addresses. Domain administrators reach only the objects
> of the domains they manage, as their permission flags allow (`alias_domains`,
> `alias_external_goto`, `extend_sender_acl`). Mailbox users have no access; their own view is
> feature 005.
>
> Allocated legacy surface: `alias`, `alias-domain`, `resource` add/get/edit/delete, sender ACL /
> send-as (`extend_sender_acl`, external sender addresses). The legacy "Mail setup › Aliases",
> "Alias domains" and "Resources" screens, their edit pages and the "Allow to send as" and
> "External sender addresses" fields of the legacy "Edit mailbox" page need list filters, sorting,
> search, the fields they show today and their mass actions.
>
> Out of scope, allocated elsewhere: temporary aliases (005), BCC maps and recipient maps (009),
> rate limit changes (006), DKIM key management (010), domain administrator accounts and flags
> (007), the mailbox user's own view of their aliases and send-as addresses (005).
>
> Constraints: every legacy side effect is reproduced, so Postfix, Dovecot, Rspamd and SOGo behave
> the same whichever implementation made the change. The legacy UI and `/api/v1` keep working on
> the same data. The feature builds on feature 001's identity, roles, permission flags, API keys,
> error conventions and audit log, on feature 002's domains and on feature 003's mailboxes.

## Clarifications

### Session 2026-10-18

- Q: Legacy drops invalid or refused destinations, alias addresses and sender permission entries
  with a message each and saves the rest, and an alias edit whose destinations all fail reports
  success without saving anything. Should the API do the same? → A: No. A request with any invalid
  or refused entry is refused as a whole, the error names every such entry with its rule, and
  nothing is saved. (agent decision: constitution II requires field-level validation errors and forbids
  reporting success with an error, and a partly saved alias misleads both the user and automation.
  Feature 002 decided the same for footer exclusions.)
- Q: Legacy re-checks every destination of an alias against the external-destination rules on every
  edit, so a domain administrator without `alias_external_goto` who only deactivates an alias that an
  administrator pointed outside the server silently loses that destination or saves nothing. Which
  destinations are checked? → A: Only destinations that the request adds. Destinations already on
  the alias are kept, also after the server setting is turned on. (agent decision: the rule exists to
  stop domain administrators from creating external forwarding, not to undo an administrator's
  decision, and the legacy behaviour is a defect that breaks ordinary edits.)
- Q: The legacy screens offer mass actions (activate, deactivate, show or hide in the groupware,
  delete, expand over alias domains) and the add form accepts several alias addresses at once. What
  does feature 004 offer? → A: Bulk actions for the legacy mass actions, as features 002 and 003
  define them: up to 100 objects, refused as a whole when the action is not permitted, otherwise one
  result and one audit record per object. Creation takes one alias, alias domain or resource per
  request; the web app sends one request per address of the legacy multi-address form. (agent
  decision: the brief asks for the legacy bulk actions, features 002 and 003 set the pattern, and
  single-object creation keeps "created" responses with one address each.)
- Q: How is an alias addressed, given that its address can be changed? → A: By its identifier,
  which never changes, as the legacy edit page and `/api/v1` do. A client finds an alias by address
  with the exact-address list filter. (agent decision: a changeable address cannot be a stable
  resource name, and the identifier is what legacy links use.)
- Q: Where do a mailbox's sender permissions live in the API, and how are they changed? → A: As the
  mailbox's sender settings in this feature, separate from the mailbox of feature 003, with their
  own version tag. Explicit permissions and external sender addresses are each replaced as a whole
  set. A domain administrator's request keeps the entries outside their scope and cannot add such
  entries, "any address" is for administrators and is stored alone, and an entry for an alias that
  already lets the mailbox send is accepted but not stored. (agent decision: feature 003 leaves
  `sender_acl` and `extended_sender_acl` to feature 004; set replacement with a version check matches
  the legacy form and avoids lost updates; the rest is legacy behaviour.)
- Q: The legacy add-alias-domain form also sets an initial rate limit and generates a DKIM key with a
  pre-filled selector. Does the API do that, and are the rate limit and DKIM key editable on the alias
  domain? → A: Creation applies the rate limit when given and generates a DKIM key with the selector
  and key size given, or else the server's default selector (feature 002) and 2,048 bits, unless the
  selector is explicitly empty. The outcome is reported per step. Afterwards the rate limit is shown
  read-only (changes in feature 006) and DKIM keys are managed in feature 010. A domain administrator
  needs `ratelimit` to set an initial rate limit. (agent decision: legacy form behaviour, the same
  model as feature 002's domain creation, and the roadmap allocation of rows 006 and 010.)
- Q: Legacy alias domain deletion leaves the rate limit (it removes the entry of an undefined
  variable), sender permissions and spam lists of the alias domain behind, and alias domain creation
  never removes overlapping external sender addresses (same defect). Should the API do the same? →
  A: No. Deletion also removes the rate limit, the explicit sender permissions for the alias domain
  and its aliases, and its spam allow and block lists; it keeps the DKIM key as feature 002 does.
  Creation removes the external sender addresses that lie in the new alias domain, as the legacy
  hint text promises. (agent decision: leftovers would apply again to an alias domain created later
  with the same name and would grant send-as rights nobody gave; constitution IV allows stated
  deviations.)
- Q: Should a resource's address be chosen by the client or derived from its description? → A:
  Derived as legacy does (the ASCII letters and digits of the description, letter case kept, at the
  chosen domain) and never changed afterwards. A derived address that is in use is refused as a
  conflict. (agent decision: legacy behaviour; the groupware stores bookings under the address, so it
  must stay fixed.)
- Q: Legacy refuses a new alias only when exactly zero aliases are left, and never checks the limit
  when an alias moves to another domain or an alias domain moves to another target. How is the alias
  limit enforced? → A: A creation or move is refused when the aliases in use in the receiving primary
  domain would exceed its limit, including copies over alias domains and alias domain target changes.
  (agent decision: the limit is a commercial package boundary, and the legacy gaps let it be
  exceeded.)
- Q: The legacy `alias_domains` flag of domain administrators is checked only when an alias domain is
  added. Should it also gate changing and deleting alias domains? → A: No. It gates creation only, as
  in legacy, where the flag is labelled "Add alias domains" and the edit and delete actions are shown
  without it. (agent decision: legacy behaviour; a domain administrator can only move an alias domain
  between domains they manage anyway.)
- Q: Legacy alias domain deletion removes BCC maps whose local destination is the bare alias domain
  name, but domain-level BCC maps are stored as `@<alias domain>`, so they survive the deletion and
  apply again to an alias domain created later with the same name. Which BCC maps does the deletion
  remove? → A: The alias domain's domain-level BCC maps in both forms, `@<alias domain>` and the bare
  name. (orchestrator decision: legacy defect found by feature 009; feature 002's domain deletion is
  aligned the same way.)
- Q: May a bulk action check version tags? → A: Yes, per item. Each item may carry the version tag the
  client read; an item whose tag is outdated gets the result "outdated" and stays unchanged while the
  others proceed, and items without a tag are not checked. Every list entry carries its version tag
  (FR-059, FR-060). (orchestrator decision: cross-feature conventions in `docs/conventions.md`.)
- Q: Does a client need server settings before it submits, and where does it read them? → A: Yes. A
  capabilities read gives whether the groupware is enabled, whether external alias destinations are
  disabled, the bulk limit, page sizes and field limits, and the DKIM defaults for the alias domain
  form (FR-068). (orchestrator decision: legacy renders these settings into its screens, for example
  `SKIP_SOGO` hides the visibility option; the conventions route such settings through the owning
  feature's capabilities read, as feature 002 does in its FR-064.)

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Find and inspect aliases (Priority: P1)

An administrator opens the alias list in the new web app. Each row shows what the legacy "Mail
setup › Aliases" screen shows today: the identifier, the address with a catch-all badge where it
applies, the destinations or the special action, the domain (with the target domain when the alias
lives in an alias domain), whether the alias is visible in the groupware, the public and private
comments, the internal flag and the active state. The administrator searches by a fragment of the
address, a destination or a comment, filters by domain, active state and kind, finds every alias
that delivers to one mailbox, sorts by any column and pages through tens of thousands of aliases.
Opening an alias shows all its settings and the version needed to change it.

A domain administrator sees the same screen, limited to the aliases of the domains they manage and
of those domains' alias domains.

**Why this priority**: Every alias task starts by finding the alias, and the mailbox screens of
feature 003 need "aliases that deliver to this mailbox". The legacy list loads every alias one by
one and becomes unusable on large servers.

**Independent Test**: Load a test copy of the mailcow database with aliases of every kind (regular,
catch-all, special actions, aliases in alias domains, inactive, internal, hidden from the
groupware) in several domains, and two domain administrators with different domains. List and
inspect them with each filter, search and sort as an administrator and as each domain
administrator, and compare every value with what the legacy UI shows.

**Acceptance Scenarios**:

1. **Given** 60 aliases and 40 mailboxes, **When** an administrator lists aliases without
   parameters, **Then** the 25 newest aliases are returned, newest first, with a total of 60, and
   none of the mailboxes' own addresses appear.
2. **Given** an alias `info@example.net` where `example.net` is an alias domain of `example.org`,
   **When** it is listed, **Then** it shows the domain `example.net` and the primary domain
   `example.org`. **When** the list is filtered by the primary domain `example.org`, **Then** it is
   included. **When** it is filtered by the domain `example.org`, **Then** it is not.
3. **Given** aliases whose address, destination or comment contains "invoice", **When** an
   administrator searches for "INVOICE", **Then** all of them are returned.
4. **Given** a domain administrator who manages two of ten domains, **When** they list aliases,
   **Then** only aliases of those two domains and their alias domains are returned. **When** they
   request an alias of a third domain by its identifier, **Then** the response is the same "not
   found" as for an alias that does not exist.
5. **Given** an alias stored by the legacy UI with "learn as spam", **When** it is inspected,
   **Then** it shows the special action "learn as spam" and no destination addresses.
6. **Given** three aliases that deliver to `anna@example.org` among other destinations, **When** the
   list is filtered by that destination, **Then** exactly those three are returned.
7. **Given** a mailbox user, **When** they list or inspect aliases, **Then** the request is refused
   as forbidden.

---

### User Story 2 - Create an alias (Priority: P1)

An administrator, a domain administrator or a billing system with a read-write key creates an
alias. They enter the address, or `@domain` for a catch-all, and either the destination addresses
or one special action. The alias is active, allowed to send as and visible in the groupware unless
they change that, as in the legacy form. They can mark it internal and add comments. Mail to the
address is delivered at once, the destination mailboxes may send as the address, and the address
appears as a sender identity in their webmail. The domain's alias limit is enforced, and the
server's rules for destinations outside the server apply.

**Why this priority**: Aliases are the second most frequent provisioning object after mailboxes.
Billing systems create shared addresses such as `info@` and `sales@` for every customer.

**Independent Test**: Create aliases through the API with each kind of destination, as an
administrator and as domain administrators with and without the external-destination flag, with
the server setting for external destinations on and off, and at the domain's alias limit. Compare
the stored data and the groupware directory with the same alias created in the legacy UI, and send
mail to each alias.

**Acceptance Scenarios**:

1. **Given** mailboxes `anna@example.org` and `bob@example.org`, **When** an administrator creates
   `sales@example.org` delivering to both, **Then** the alias exists, is active, allows sending,
   is visible in the groupware and is not internal, mail to it reaches both mailboxes, both can send
   as it, and their webmail offers it as a sender identity.
2. **Given** a domain `example.org`, **When** a client creates `@example.org` delivering to
   `anna@example.org`, **Then** a catch-all alias is created, and mail to any address of the
   domain that does not otherwise exist on the server reaches Anna.
3. **Given** a request for `noreply@example.org` with the special action "discard", **When** it is
   submitted, **Then** the alias is created and mail to it is silently discarded.
4. **Given** an address that already belongs to a mailbox, a resource, an alias or a temporary
   alias, **When** a client creates an alias with it, **Then** the request is refused as a conflict
   that names which kind of object holds the address, and nothing is created.
5. **Given** a domain with an alias limit of 10 and 10 aliases in use, **When** a client creates an
   alias in it or in one of its alias domains, **Then** the request is refused as "domain alias
   limit reached" with the count and the limit.
6. **Given** a request with three destinations of which one is not a valid address, **When** it is
   submitted, **Then** the request is refused with a validation error that names that destination,
   and nothing is created.
7. **Given** a domain administrator without the external-destination flag, **When** they create an
   alias delivering to `someone@gmail.example`, which is not a domain of this server, **Then** the
   request is refused as not permitted, naming the destination and the flag. **When** they create
   an alias delivering to a mailbox of this server, **Then** it is created.
8. **Given** the server setting that disables external destinations, **When** an administrator
   creates an alias with an external destination, **Then** the request is refused in the same way.
9. **Given** a resource `MeetingRoom1@example.org`, **When** a client creates an alias delivering to
   it, **Then** the request is refused with a validation error naming that destination.
10. **Given** an alias domain `example.net` of `example.org` and a mailbox `anna@example.org`,
    **When** a client creates the alias `anna@example.net`, **Then** the request is refused as a
    conflict, because that address already reaches Anna's mailbox.
11. **Given** a private comment of 161 bytes, **When** the alias is created, **Then** the request is
    refused with a validation error that states the limit of 160 bytes.

---

### User Story 3 - Change or delete an alias (Priority: P1)

An administrator or a domain administrator changes an alias: its address, destinations, special
action, active state, sending permission, groupware visibility, internal flag or comments. The
client sends only what changes, together with the version it read, so that an edit made meanwhile
in the legacy UI is not overwritten. They delete aliases that are no longer needed, and every
permission that let other mailboxes send as the deleted address goes with them.

**Why this priority**: Operators change alias destinations whenever staff change, and billing
systems suspend and remove aliases with the customer.

**Independent Test**: Change each attribute through the API and check the stored data, the mail
flow, the sender permissions and the groupware directory. Edit the same alias in the legacy UI
between reading and writing and confirm the stale write is refused. Delete aliases with and without
explicit sender permissions and compare the remaining data with a legacy deletion.

**Acceptance Scenarios**:

1. **Given** `sales@example.org` delivering to Anna, **When** its destinations are replaced by Bob,
   **Then** mail reaches only Bob, the address disappears from Anna's webmail identities and appears
   in Bob's.
2. **Given** a client that read an alias, and an operator who then changed it in the legacy UI,
   **When** the client sends its change with the version it read, **Then** the request is refused
   as outdated and nothing changes.
3. **Given** an alias, created by an administrator, that delivers to an external address, and a
   domain administrator without the external-destination flag, **When** the domain administrator
   deactivates the alias, **Then** the change is saved and the external destination is kept.
   **When** they add a second external destination, **Then** the request is refused as not
   permitted.
4. **Given** `info@example.org`, and `example.com` whose alias limit is reached, **When** a client
   changes the address to `info@example.com`, **Then** the request is refused as "domain alias
   limit reached".
5. **Given** a mailbox that holds an explicit permission to send as `info@example.org`, **When** the
   alias address is changed to `contact@example.org`, **Then** the mailbox may send as
   `contact@example.org` and no longer as `info@example.org`.
6. **Given** an alias that allows sending, **When** sending is turned off, **Then** its destination
   mailboxes can no longer send as its address, unless a mailbox holds an explicit permission for
   it.
7. **Given** an alias for which two mailboxes hold explicit sender permissions, **When** it is
   deleted, **Then** the alias and both permissions are gone, mail to the address is no longer
   accepted, and the response has no body.
8. **Given** a read-only API key, **When** it is used to change or delete an alias, **Then** the
   request is refused with the "insufficient key scope" error.

---

### User Story 4 - Manage alias domains (Priority: P2)

An administrator, or a domain administrator with the "alias domains" permission, adds an alias
domain so that every mailbox of a domain also receives mail under the other name, for example
`example.net` for `example.org`. A DKIM key is generated for it, as in the legacy form, and an
initial rate limit can be set. Later they point the alias domain at another of their domains,
deactivate it, or delete it together with the aliases that live in it.

**Why this priority**: Alias domains are common for customers with several brands or country
domains, but less frequent than aliases. The legacy UI can still manage them meanwhile.

**Independent Test**: Create, change and delete alias domains as an administrator and as domain
administrators with and without the permission, with and without DKIM keys and rate limits. Send
mail to mailbox addresses under the alias domain. Compare the stored data, shared-store entries and
groupware directory with legacy operations on the same data, allowing for the stated deviations.

**Acceptance Scenarios**:

1. **Given** a domain administrator with the "alias domains" permission who manages `example.org`,
   **When** they create the alias domain `example.net` for it, **Then** mail to `anna@example.net`
   reaches `anna@example.org`, the mail filter treats `example.net` as local, a DKIM key is created
   with the server's default selector, and the response reports each follow-up step.
2. **Given** a domain administrator without that permission, **When** they create an alias domain,
   **Then** the request is refused as not permitted, naming the permission.
3. **Given** a name that already exists as a domain or an alias domain, **When** an alias domain is
   created with it, **Then** the request is refused as a conflict.
4. **Given** a relay (backup MX) domain, **When** a client creates an alias domain for it, **Then**
   the request is refused with a validation error.
5. **Given** a mailbox with the external sender address `bob@example.net`, **When** `example.net`
   becomes an alias domain, **Then** that external sender address is removed, because the domain is
   now local.
6. **Given** the alias domain `example.net` of `example.org`, **When** its target is changed to
   `example.com`, **Then** mail to `carl@example.net` reaches `carl@example.com`, and the aliases of
   `example.net` now count towards the alias limit of `example.com`.
7. **Given** an alias domain with aliases, temporary aliases, BCC maps and sender permissions,
   **When** it is deleted, **Then** all of them, its domain map entry and its rate limit are gone,
   its DKIM key is kept, and the response has no body.
8. **Given** an active alias domain, **When** it is deactivated, **Then** mail to its addresses is no
   longer accepted.

---

### User Story 5 - Manage what a mailbox may send as (Priority: P2)

An administrator or a domain administrator opens the sender settings of a mailbox. They see every
address the mailbox may send as and why: its own address, its addresses in the domain's alias
domains, the aliases that deliver to it and allow sending, and the explicit permissions for other
addresses or whole domains. They grant or revoke explicit permissions, and an administrator can
disable the sender check for the mailbox entirely. With the "external sender addresses" permission
they let the mailbox send as addresses that are not hosted on this server.

**Why this priority**: Shared mailboxes and assistants need send-as rights, and customers moving to
the server need external sender addresses. Mailboxes work without them.

**Independent Test**: Grant and revoke each kind of permission as an administrator and as domain
administrators with and without the external-sender permission, then submit mail with each sender
address through the mail server and check acceptance. Compare the stored permissions and the
groupware identities with the same changes made in the legacy UI.

**Acceptance Scenarios**:

1. **Given** Anna, who receives `sales@` (sending allowed) and `info@` (sending not allowed) and has
   an explicit permission for `@example.com`, **When** an administrator reads her sender settings,
   **Then** they list her own address, her alias-domain addresses, `sales@` as allowed through the
   alias, `info@` as not allowed, and the domain permission for `example.com`.
2. **Given** an administrator, **When** they grant Anna `@example.org` and "any address" together,
   **Then** only "any address" is stored, and the response shows that her sender check is disabled.
3. **Given** a mailbox with an administrator-set permission for `@other.example`, a domain the
   domain administrator does not manage, **When** the domain administrator reads it, **Then** that
   permission is shown as not changeable by them. **When** they save the permissions with
   `info@example.org` only, **Then** `info@example.org` is granted and the permission for
   `@other.example` is kept.
4. **Given** a domain administrator, **When** they grant a permission for an address of a domain they
   do not manage, or "any address", **Then** the request is refused, naming the entry, and nothing
   changes.
5. **Given** a domain administrator without the external-sender permission, **When** they change a
   mailbox's external sender addresses, **Then** the request is refused as not permitted. **When**
   they send the external addresses unchanged together with other changes, **Then** the request is
   accepted.
6. **Given** a domain administrator with that permission, **When** they set the external sender
   addresses `news@mailer.example` and `@partner.example`, **Then** the mailbox can submit mail with
   either sender, and its webmail offers `news@mailer.example` as an identity.
7. **Given** an external sender address in a domain or alias domain of this server, **When** it is
   saved, **Then** the request is refused with a validation error naming it.
8. **Given** Anna receives `info@` with sending not allowed, **When** she is granted an explicit
   permission for `info@example.org`, **Then** she can send as `info@example.org`.

---

### User Story 6 - Apply an action to many objects at once (Priority: P2)

An administrator or a domain administrator selects aliases in the list and activates,
deactivates, shows or hides them in the groupware, deletes them, or copies them over the domain's
alias domains, as the legacy mass actions do. They activate, deactivate or delete several alias
domains or resources the same way. They get one result per object.

**Why this priority**: Operators use the legacy mass actions to suspend customers and clean up.
One request per object would be slow and error-prone for the web app.

**Independent Test**: Apply each action to a selection that mixes objects inside and outside a
domain administrator's scope, with one object whose operation fails, and compare the per-object
results, stored data and audit records with the same mass action in the legacy UI.

**Acceptance Scenarios**:

1. **Given** 30 active aliases, **When** an administrator deactivates them in one request, **Then**
   all 30 are inactive, the response has 30 successful results and the audit log has 30 records.
2. **Given** a domain administrator and a selection with three of their aliases and two of another
   domain, **When** they hide the selection in the groupware, **Then** their three aliases change
   and the other two are reported "not found".
3. **Given** a selection of 101 objects, **When** it is submitted, **Then** the request is refused
   with a validation error that states the limit of 100.
4. **Given** `sales@example.org`, the alias domains `example.net` and `example.com`, and an existing
   `sales@example.net`, **When** the alias is copied over the alias domains, **Then**
   `sales@example.com` is created with the same destinations and settings, and `example.net` is
   reported as skipped because the address is in use.
5. **Given** a selection of resources, **When** they are deactivated, **Then** none of them can be
   booked in the groupware any more.
6. **Given** a selection of three aliases sent with the version tags from the list, one of which was
   changed in the legacy UI since, **When** they are deactivated, **Then** that alias is reported
   "outdated" and stays active, and the other two are deactivated.

---

### User Story 7 - Manage resources (Priority: P3)

An administrator or a domain administrator adds a meeting room, a projector or a team calendar as
a resource: a description, a domain, a kind (location, thing or group) and how double bookings are
handled. The resource gets an address derived from its description and appears in the groupware,
where users book it. Later they change its description, kind, booking rule or active state, or
delete it.

**Why this priority**: Only some customers use groupware resources, and the rest of the mail setup
works without them.

**Independent Test**: Create, change and delete resources of each kind and booking rule, book them
in the groupware, and compare the stored data and groupware directory with resources managed in
the legacy UI.

**Acceptance Scenarios**:

1. **Given** the domain `example.org`, **When** a client creates the location "Meeting Room 1",
   **Then** the resource `MeetingRoom1@example.org` exists, can be booked in the groupware, and shows
   as busy when booked while still accepting further bookings, which is the legacy default.
2. **Given** that resource, **When** another resource with the description "Meeting-Room 1" is
   created in the same domain, **Then** the request is refused as a conflict, because the derived
   address is in use.
3. **Given** the description "!!!", **When** a resource is created with it, **Then** the request is
   refused with a validation error, because no address can be derived from it.
4. **Given** a resource limited to 2 concurrent bookings, **When** a third overlapping booking is
   made in the groupware, **Then** the groupware declines it.
5. **Given** an active resource, **When** it is deactivated, **Then** it disappears from the
   groupware and can no longer be booked.
6. **Given** a domain whose mailbox limit is reached, **When** a resource is created in it, **Then**
   it is created, because resources do not count as mailboxes.
7. **Given** an alias domain name, **When** a resource is created in it, **Then** the request is
   refused with a validation error.
8. **Given** a resource, **When** it is deleted, **Then** it and its groupware data are gone, and the
   response has no body.

---

### User Story 8 - Trace changes (Priority: P3)

An administrator investigating a complaint lists the audit log filtered by an alias, an alias
domain, a resource or a mailbox's sender settings and sees every change: who made it, when, from
where, the old and new values, and the outcome of follow-up steps.

**Why this priority**: The constitution requires an audit record for every change, but the objects
can be managed without reading the log.

**Independent Test**: Make each kind of change, including refused and bulk ones, and list the audit
log filtered by target.

**Acceptance Scenarios**:

1. **Given** an alias whose destinations a domain administrator replaced, **When** an administrator
   lists the audit records of that alias, **Then** the entry shows the actor, time, client address
   and the old and new destinations.
2. **Given** a refused attempt to add an external destination, **When** the audit log is listed,
   **Then** the refusal is listed with its reason.
3. **Given** a domain administrator, **When** they request the audit log, **Then** the request is
   refused as forbidden.

---

### Edge Cases

- **Mailbox's own address**: the alias row through which a mailbox receives mail at its own address
  belongs to feature 003. It is never listed, counted or changed here, and addressing it answers
  "not found".
- **Letter case**: addresses are compared without regard to letter case, so `Info@example.org` is
  in use when `info@example.org` exists. The local part keeps the letter case it was given, as in
  legacy, and the domain part is stored in lower-case ASCII form.
- **Internationalised domains**: an address or domain given in Unicode form is converted to ASCII
  (punycode) before it is checked and stored, both at creation and when changed.
- **Single-label domains** such as `localdomain`: only a catch-all alias can be created in them, and
  no destination can be in them, because their addresses are not valid e-mail addresses under the
  legacy rules.
- **Alias whose address equals one of its destinations**: refused with a validation error.
- **Destination in another customer's domain on the same server**: allowed, as in legacy. It is not
  external, so the external-destination rules do not apply.
- **Destination in an inactive domain or inactive alias domain of this server**: treated as
  external, as in legacy.
- **Destination that does not exist** (for example a mistyped mailbox in a local domain): accepted,
  as in legacy. Mail to it bounces.
- **Destinations stored by another tool that the rules now refuse**: shown as stored. A change that
  replaces the destination list must make every destination valid, except external destinations
  already on the alias.
- **Reserved addresses** of the special actions given as ordinary destinations: refused, because
  they are not valid addresses. A special action and destination addresses in one request, or two
  special actions, are refused.
- **Catch-all alias that allows sending**: its destinations may send as any address of the domain
  that has no alias of its own allowing sending, as in legacy. The contract documents this.
- **Catch-all aliases in the groupware**: never shown as sender identities, as in legacy.
- **Internal alias**: the mail filter refuses mail to it from senders outside the alias's domain and
  that domain's alias domains, as in legacy.
- **Groupware disabled on the server** (legacy `SKIP_SOGO`): no groupware directory updates are
  made, and the visibility flag is stored but has no effect.
- **Alias limit already exceeded**, for example after a change by another tool: creations and moves
  into the domain are refused until the count is below the limit. Other changes are accepted.
- **Two creations for the last free alias of a domain**, or for the same address, at the same time:
  exactly one succeeds.
- **Orphaned alias** whose domain no longer exists as a domain or alias domain (possible after
  legacy deletions): listed for administrators only, marked as orphaned, and can be deleted.
- **Copying an alias over alias domains when the domain has none**: succeeds and creates nothing.
- **Copying an alias that lives in an alias domain**: refused with a documented error.
- **Alias that is the destination of other aliases, or an alias domain whose addresses are**: when
  it is deleted, the other aliases keep the address as a destination, as in legacy.
- **BCC maps and recipient maps that name an alias address** (feature 009): not changed when the
  alias address changes, as in legacy.
- **Alias domain whose target becomes a relay domain later**: allowed, as in legacy. The alias
  domain is reported with "target is a relay domain".
- **Alias domain moved to another target** where one of its aliases has the same local part as a
  mailbox of the new target: allowed, as in legacy. The alias takes precedence for that address.
- **Alias domain equal to the server's host name**: refused.
- **Number of alias domains or resources per domain**: unlimited, as in legacy.
- **Resource description with letters outside A to Z**, such as "Raum Süd": those letters are left
  out of the derived address (`RaumSd@…`), as in legacy. The description itself keeps them.
- **Resource description changed**: the address stays the same.
- **Mail sent to a resource address**: discarded by the mail server, as in legacy. Bookings go
  through the groupware.
- **Sender permission for an address whose alias delivers to the mailbox and allows sending**:
  accepted but not stored, because the mailbox already has that right. If sending is later turned
  off for the alias, the mailbox loses the right, as in legacy.
- **Sender permission for another mailbox's address**: the mail server accepts it at once, but the
  groupware offers the address as a sender identity only after the other mailbox delegates access in
  the groupware, as the legacy hint explains.
- **Stale sender permission** for a domain or address that no longer exists: shown and marked as
  stale. An administrator who saves the permissions without it removes it. A domain administrator
  cannot remove it, because it is outside their scope.
- **External sender address that later becomes local** because a domain or alias domain is added:
  removed when that domain or alias domain is created.
- **Bulk action naming the same object twice**: it is processed once and reported once.
- **Bulk item with an outdated version tag**: that item is reported "outdated" and left unchanged;
  the other items proceed. Items sent without a tag are not version-checked.
- **Concurrent edits from the legacy UI**: the legacy UI never sends a version, so a legacy save can
  still overwrite a change made through this API.
- **Shared store unavailable** during an alias domain creation or deletion: the request is refused
  with a temporary failure and leaves no partial change.
- **Audit record cannot be written**: the change it would record does not succeed.

## Requirements *(mandatory)*

### Functional Requirements

#### Access and scope

- **FR-001**: Administrators MUST be able to reach every alias, alias domain, resource and mailbox
  sender setting. Domain administrators MUST be able to reach exactly the objects of the domains
  assigned to them through an active assignment, whether or not the domain itself is active
  (legacy behaviour):
  - aliases whose domain is one of those domains or an alias domain of one of them;
  - alias domains whose target is one of those domains;
  - resources in those domains;
  - the sender settings of mailboxes in those domains.

  Mailbox users MUST NOT reach any operation of this feature.
- **FR-002**: Scope and permission flags MUST be evaluated from the current mailcow data on every
  request, as in feature 001 (FR-029 there). A change made in either UI MUST apply to the next
  request.
- **FR-003**: An object that does not exist and an object outside the caller's scope MUST get the
  same "not found" answer. A domain named in a request that is outside the caller's scope MUST
  get the same validation error as a domain that does not exist. A request for an operation that the
  caller's role may never use MUST be refused as forbidden.
- **FR-004**: A request authenticated by an API key MUST act with the key owner's role, scope and
  flags (feature 001, FR-033). A read-only key MUST be refused on every operation that changes
  state, with the "insufficient key scope" error of feature 001 (FR-039). A restricted session
  (feature 001, FR-014) MUST be refused.
- **FR-005**: The operations MUST be authorised as follows:

  | Operation | Administrator | Domain administrator | Mailbox user |
  |---|---|---|---|
  | List and inspect aliases | All aliases | Aliases of own domains and their alias domains | Forbidden |
  | Create, change, delete and copy aliases | All aliases | Own scope; new external destinations need `alias_external_goto` (FR-027) | Forbidden |
  | List and inspect alias domains | All | Alias domains of own domains | Forbidden |
  | Create alias domains | Yes | Targets in own domains, with `alias_domains`; an initial rate limit needs `ratelimit` | Forbidden |
  | Change and delete alias domains | All | Own scope, no permission flag needed (legacy behaviour) | Forbidden |
  | Resources (all operations) | All | Resources of own domains | Forbidden |
  | Read sender settings and grantable entries | All mailboxes | Mailboxes of own domains | Forbidden |
  | Read the capabilities of this feature (FR-068) | All values | Without the DKIM DNS publication state; DKIM defaults only with `alias_domains` | Forbidden |
  | Replace explicit sender permissions | All mailboxes, including "any address" | Mailboxes of own domains; entries within own scope only; no "any address" (FR-054) | Forbidden |
  | Replace external sender addresses | All mailboxes | Mailboxes of own domains, with `extend_sender_acl` | Forbidden |
  | Bulk actions | As the single operation | As the single operation, per object | Forbidden |
  | Read the audit records of this feature | Yes | Forbidden | Forbidden |

- **FR-006**: A request that needs a permission flag the calling domain administrator does not hold
  MUST be refused as a whole with the "attribute not permitted for the role" error of feature 002,
  naming the field, or the operation, and the flag. Nothing MUST change. A value sent unchanged from its current value
  MUST NOT need the flag. The flags are:
  - `alias_domains` ("Add alias domains") to create an alias domain;
  - `alias_external_goto` to add an external destination to an alias (FR-027);
  - `extend_sender_acl` to change a mailbox's external sender addresses;
  - `ratelimit` to give a new alias domain an initial rate limit.
- **FR-007**: A server setting MUST disable external alias destinations for every role, including
  administrators, as legacy `ALIAS_DISABLE_EXTERNAL_DOMAINS` does. Its default MUST be off, the
  legacy default.

#### Addresses

- **FR-008**: Addresses MUST be normalised as legacy does: surrounding spaces removed and the domain
  part converted to lower-case ASCII with the UTS #46 rules. The local part MUST keep its letter
  case. Addresses MUST be compared without regard to letter case. Domain names (alias domains,
  target domains, resource domains) MUST be normalised as in feature 002 (FR-006 there).
- **FR-009**: An address MUST be valid by the legacy rules after normalisation: a local part of at
  most 64 characters made of the characters allowed in an unquoted e-mail address, with no leading,
  trailing or repeated dots, and a domain of at least two labels whose last label starts with a
  letter. Quoted local parts, non-ASCII local parts and address literals MUST be refused.
- **FR-010**: An address MUST count as in use when it is the address of a mailbox, a resource, an
  alias (including a mailbox's own address) or a temporary alias, or when it lies in an alias domain
  and the target domain has a mailbox with the same local part. A request that would create or move
  something to an address in use MUST be refused with the "address in use" conflict of feature 003,
  naming the kind of holder.

#### Alias representation

- **FR-011**: An alias MUST be represented with:
  - its identifier, which never changes;
  - its address in ASCII form and in readable form (domain part in Unicode);
  - its kind: regular, or catch-all for an address of the form `@domain`;
  - its domain, in ASCII and readable form, and whether that domain is an alias domain;
  - its primary domain: the target domain when the alias lives in an alias domain, otherwise its
    domain;
  - either its destination addresses or its special action: discard, learn as spam, or learn as
    ham;
  - its active state, whether its destinations may send as it ("sending allowed"), whether it is
    visible in the groupware, and whether it is internal;
  - its public comment and its private comment;
  - its creation and last modification times;
  - for administrators, whether it is orphaned (FR-019).
- **FR-012**: An alias whose stored destination is exactly one of the reserved addresses the legacy
  UI uses for the special actions MUST be represented with that special action. Any other stored
  destination MUST be represented as a list of addresses, exactly as stored.
- **FR-013**: The alias through which a mailbox receives mail at its own address MUST NOT be treated
  as an alias by any operation of this feature, and MUST NOT be counted, consistent with feature 002
  (FR-008 there). Addressing it MUST answer "not found".

#### Listing and inspecting aliases

- **FR-014**: Administrators and domain administrators MUST be able to list the aliases in their
  scope with pagination. The default page size MUST be 25 and the maximum 1,000. The response MUST
  include the total number of matching aliases.
- **FR-015**: The list MUST support a free-text search that matches, case-insensitively and as a
  substring, the address in both forms, the destination addresses, and both comments.
- **FR-016**: The list MUST support these filters: exact address; domain (the alias's own domain,
  which may be an alias domain); primary domain (a domain together with its alias domains); kind;
  destination type (addresses, discard, learn as spam, learn as ham); destination address (aliases
  that deliver to it, among others); active state; sending allowed; groupware visibility; internal.
  Several values of the same filter MUST match objects that have any of them. A domain filter
  outside a domain administrator's scope MUST return an empty list.
- **FR-017**: The list MUST be sortable, ascending or descending, by: identifier, address, domain,
  active state, groupware visibility, internal, sending allowed, creation time and modification
  time. The default MUST be by identifier, descending, which shows the newest aliases first as the
  legacy screen does. Ties MUST be broken by identifier.
- **FR-018**: A client MUST be able to inspect one alias in its scope by its identifier. The response
  MUST carry the alias's version tag (FR-060). Each list entry MUST carry the full representation
  (FR-011), so that the list screen needs no request per row.
- **FR-019**: An alias whose domain no longer exists as a domain or alias domain MUST be listed for
  administrators only, marked as orphaned, and MUST be deletable by them. A change to an orphaned
  alias MUST be refused unless it moves the alias to an existing domain.

#### Creating an alias

- **FR-020**: Administrators and domain administrators MUST be able to create one alias per request.
  The request MUST give the address and either destination addresses or one special action. It MAY
  give the active state (default on), sending allowed (default on), groupware visibility (default
  on), internal (default off), a public comment and a private comment (default empty). These
  defaults are those of the legacy form.
- **FR-021**: The address MUST be normalised (FR-008) and MUST then:
  - be a valid address (FR-009), or a catch-all of the form `@domain`;
  - lie in an existing domain or alias domain in the caller's scope (FR-003). Relay domains and
    inactive domains are allowed, as in legacy;
  - not be in use (FR-010).
- **FR-022**: Before an alias is created in a domain or one of its alias domains, the number of
  aliases in use in that primary domain, counted as in feature 002 (FR-008 there), MUST be below the
  domain's alias limit. Otherwise the request MUST be refused with the "domain alias limit reached"
  error, carrying the count and the limit. Concurrent requests MUST NOT together exceed the limit.
- **FR-023**: The public comment and the private comment MUST each be at most 160 bytes in UTF-8
  encoding, the legacy limit.
- **FR-024**: A successful creation MUST store the alias, apply the follow-up steps of FR-032 and
  FR-033, and respond as "created" with the address of the new alias.

#### Destinations

- **FR-025**: A destination list MUST contain at least one address. Each destination MUST be
  normalised (FR-008) and MUST:
  - be a valid address (FR-009);
  - differ from the alias's own address;
  - not be the address of a resource.

  Destinations that differ only in letter case MUST be stored once, in the order first given. The
  whole list, joined by commas, MUST NOT exceed 65,535 characters, the legacy storage limit.
- **FR-026**: A special action MUST exclude destination addresses, and only one special action MAY be
  given. It MUST be stored as the reserved address legacy uses, so that the mail server discards the
  mail or passes it to spam training exactly as for an alias saved in the legacy UI.
- **FR-027**: A destination MUST count as external when its domain is neither an active domain nor an
  active alias domain of this server (legacy behaviour). An external destination that the request
  adds to an alias MUST be refused with the "destination not permitted" error, naming each such
  destination, when the server setting of FR-007 is on, or when the caller is a domain administrator
  without `alias_external_goto` (FR-006). Destinations already on the alias MUST be kept and MUST NOT
  be re-checked, also after the server setting is turned on, so that a change of any other attribute
  never needs the permission.
- **FR-028**: A request that contains any invalid or refused entry, whether an address, a destination,
  a sender permission entry or an external sender address, MUST be refused as a whole. The error MUST
  name every such entry with its rule, and nothing MUST be saved.

#### Changing an alias

- **FR-029**: A client MUST be able to change an alias by sending only the attributes it changes.
  Attributes that are not sent MUST keep their values, and a value that is sent MUST be applied or
  refused by the rules, never ignored. The changeable attributes are the address, the destination
  addresses or special action, the active state, sending allowed, groupware visibility, internal and
  both comments. The kind and the domains follow from the address. A request that sends a read-only
  attribute (identifier, times, orphaned marker, or a kind or domain that contradicts the resulting
  address) with a different value MUST be refused with a validation error naming it; sending it with
  its current value MUST be accepted, so that a client can send back the representation it read. The
  response MUST contain the updated alias and its new version tag.
- **FR-030**: A changed address MUST meet the rules of FR-021, where the alias's own current address
  does not count as in use, so that a change of letter case alone is possible. When the new address
  lies in a different primary domain, the limit of FR-022 MUST be checked for that domain. Explicit
  sender permissions to send as the old address MUST be carried over to the new address.
- **FR-031**: Sending destination addresses or a special action MUST replace the alias's whole
  destination. An alias MAY switch between destination addresses and a special action.

#### Follow-up steps of alias changes

- **FR-032**: After an alias is created or changed and allows sending, explicit sender permissions
  that let one of its destination mailboxes send as its address MUST be removed, because the alias
  already grants that right. When the alias does not allow sending, such permissions MUST be kept,
  because they override the alias's setting.
- **FR-033**: After an alias is created, changed or deleted, the groupware directory entries of the
  mailboxes that were or are among its destinations MUST be updated and the groupware cache flushed,
  unless the groupware is disabled on the server. The alias's address MUST then appear as a sender
  identity in the webmail of each destination mailbox exactly when the alias is active, visible in
  the groupware, not a catch-all and delivers to that mailbox (legacy behaviour).
- **FR-034**: A failure of the groupware directory update MUST NOT undo the change. It MUST be
  recorded in the audit record of the change.

#### Deleting an alias

- **FR-035**: Administrators and domain administrators MUST be able to delete an alias in their
  scope. The deletion MUST also remove every explicit sender permission to send as the alias's
  address, and MUST apply FR-033. Deleting an alias that does not exist, or is outside the caller's
  scope, MUST be answered "not found". A successful deletion MUST respond with no body.

#### Copying an alias over alias domains

- **FR-036**: Administrators and domain administrators MUST be able to copy an alias of a primary
  domain over that domain's alias domains, as the legacy action "Expand alias over alias domains"
  does. For each alias domain, the system MUST create `<local part>@<alias domain>` (for a catch-all,
  `@<alias domain>`) with the same destination or special action, active state, sending allowed,
  groupware visibility and internal flag, and no comments, unless that address is in use (FR-010).
- **FR-037**: Each copy MUST be created with the checks and follow-up steps of a creation by the
  caller (FR-021 to FR-024, FR-025 to FR-027, FR-032, FR-033) and its own audit record. The response
  MUST list, per alias domain, whether the copy was created (with its identifier) or skipped, with
  the reason (address in use, alias limit reached, destination not permitted). Copying an alias that
  lives in an alias domain MUST be refused with the "not a primary-domain alias" error.

#### Alias domains

- **FR-038**: An alias domain MUST be addressed by its name, normalised as in FR-008, and represented
  with:
  - its name in ASCII and readable form;
  - its target domain in ASCII and readable form;
  - its active state;
  - whether the target domain is a relay (backup MX) domain;
  - the number of aliases that live in it;
  - its rate limit (a value and a time frame), or none, read-only until feature 006;
  - its creation and last modification times.
- **FR-039**: Administrators and domain administrators MUST be able to list the alias domains in
  their scope with pagination as in FR-014, a search on the name in both forms and on the target,
  filters on target domain and active state, and sorting by name, target, active state, creation time
  and modification time (default: name, ascending; ties broken by name). They MUST be able to inspect
  one alias domain, with its version tag (FR-060).
- **FR-040**: Administrators, and domain administrators with `alias_domains`, MUST be able to create
  one alias domain per request with: its name and target domain (required), its active state
  (default on), an initial rate limit (optional), a DKIM selector and a DKIM key size. The rate limit,
  selector and key size MUST follow the rules of feature 002 (FR-020 there).
- **FR-041**: The name MUST be normalised and MUST:
  - meet the syntax and length rules for domain names of feature 002 (FR-019 there);
  - not equal the server's host name, compared case-insensitively;
  - not exist as a domain or as an alias domain. This MUST be refused as a conflict;
  - differ from the target.

  The target MUST be an existing domain, not an alias domain, in the caller's scope (FR-003), and
  MUST NOT be a relay (backup MX) domain.
- **FR-042**: A successful creation MUST:
  - store the alias domain;
  - add it to mailcow's domain map in the shared store, which the mail filter uses to recognise
    local domains;
  - remove every mailbox's external sender addresses that lie in the new alias domain, because the
    domain is now local;
  - apply the initial rate limit, when one is given;
  - generate a DKIM key, unless a key already exists for the name or the selector is explicitly
    empty, with the selector from the request or else the server's default selector (feature 002,
    FR-028), and the key size from the request or else 2,048 bits, as the legacy form pre-fills. When
    the fork-only DKIM DNS publication is enabled, the key MUST also be published as in feature 002
    (FR-027 there);
  - rebuild the groupware directory and flush its cache, unless the groupware is disabled.

  Storing the alias domain and its domain map entry MUST succeed or fail together. Failures of the
  other steps MUST NOT undo the creation.
- **FR-043**: The creation response MUST respond as "created" with the address of the new alias
  domain, and MUST contain the alias domain and a creation report with the outcome of each follow-up
  step: rate limit (applied, not requested, failed), DKIM key (created, already existed, not
  requested, failed), DKIM DNS publication (published, no DNS zone found, failed, not configured),
  groupware directory update (done, failed, groupware disabled).
- **FR-044**: Administrators and domain administrators MUST be able to change an alias domain's
  target and active state, with the rules of FR-029 for omitted, sent and read-only attributes. The
  name and the rate limit MUST NOT be changeable here. A new target MUST meet the target rules of
  FR-041. The aliases that live in the alias domain then count towards the new target's alias limit,
  so the change MUST be refused with the "domain alias limit reached" error when the new target's
  aliases in use plus those aliases would exceed its limit. After a successful change, the groupware
  directory MUST be rebuilt and its cache flushed, unless the groupware is disabled.
- **FR-045**: Administrators and domain administrators MUST be able to delete an alias domain in
  their scope. A deletion MUST remove:
  - the alias domain and its entries in the domain map and in the rate limits;
  - the aliases that live in it, and the explicit sender permissions to send as their addresses;
  - the explicit sender permissions for the alias domain as a whole;
  - the temporary aliases of its addresses;
  - its domain-level BCC maps, that is, BCC maps whose local destination is the alias domain, stored
    as `@<alias domain>` or as the bare name;
  - its spam allow and block lists.

  It MUST keep the DKIM key, as feature 002 does for domains (FR-038 there). It MUST then rebuild the
  groupware directory and flush its cache, unless the groupware is disabled. This is the cleanup
  that feature 002 applies to the alias domains of a deleted domain (FR-037 there). A successful
  deletion MUST respond with no body.

#### Resources

- **FR-046**: A resource MUST be addressed by its address and represented with:
  - its address, which never changes, in ASCII and readable form;
  - its description;
  - its kind: location, thing or group;
  - its domain, in ASCII and readable form;
  - its booking rule: "always show as free" (stored as 0), "unlimited, but show as busy when
    booked" (stored as -1) or "limited to N concurrent bookings" (N from 1 to 2,147,483,647);
  - its active state;
  - its creation and last modification times.
- **FR-047**: Administrators and domain administrators MUST be able to list the resources in their
  scope with pagination as in FR-014, a search on the description and the address, filters on
  domain, kind and active state, and sorting by description, address, kind, domain, booking rule,
  active state, creation time and modification time (default: address, ascending; ties broken by
  address). They MUST be able to inspect one resource, with its version tag (FR-060).
- **FR-048**: Administrators and domain administrators MUST be able to create a resource with a
  description, a domain and a kind (required), a booking rule (default "unlimited, but show as busy
  when booked") and an active state (default on). The address MUST be derived as legacy derives it:
  the ASCII letters and digits of the description, in their letter case, followed by `@` and the
  domain.
- **FR-049**: A resource creation MUST be refused with a validation error when the description is
  empty or longer than 255 characters, when the derived local part is empty or longer than 64
  characters, when the domain is not an existing domain in the caller's scope (alias domains are
  refused), or when the booking rule is outside the values of FR-046. It MUST be refused with the
  "address in use" conflict when the derived address is in use (FR-010).
- **FR-050**: A successful creation MUST store the resource in the mailbox store as legacy does,
  without a password and with no quota, so that it does not count towards the domain's mailbox limit
  or domain quota. It MUST then update the groupware directory and flush its cache, unless the
  groupware is disabled, so that the resource can be booked at once. It MUST respond as "created"
  with the address of the new resource.
- **FR-051**: Administrators and domain administrators MUST be able to change a resource's
  description, kind, booking rule and active state, with the rules of FR-029 for omitted, sent and
  read-only attributes. The address and the domain MUST NOT be changeable. After a change, the
  groupware directory MUST be updated and its cache flushed, unless the groupware is disabled. An
  inactive resource MUST disappear from the groupware.
- **FR-052**: Administrators and domain administrators MUST be able to delete a resource in their
  scope. The deletion MUST remove the resource and its groupware data (profile, cached folders,
  sharing permissions, calendars and their indexes), and MUST update the groupware directory and
  flush its cache, unless the groupware is disabled. A successful deletion MUST respond with no body.

#### Sender permissions

- **FR-053**: Administrators and domain administrators MUST be able to read the sender settings of a
  mailbox in their scope. They MUST contain:
  - the addresses the mailbox may send as without an explicit permission: its own address, its
    addresses in its domain's alias domains, and the aliases that deliver to it, each with whether
    the alias allows sending and whether it is a catch-all;
  - its explicit permissions, each being an address, a domain (which also covers that domain's alias
    domains) or "any address", and each marked whether the caller may change it and whether it is
    stale (its domain or address no longer exists);
  - whether its sender check is disabled ("any address");
  - its external sender addresses, each an address or a whole domain;
  - a version tag (FR-060).

  A resource, or the address of anything other than a mailbox, MUST be answered "not found".
- **FR-054**: A client MUST be able to change a mailbox's sender settings by sending its explicit
  permissions, its external sender addresses, or both, in one request. Each set that is sent MUST
  replace the stored set as a whole, and a set that is not sent MUST stay unchanged. The response
  MUST contain the updated sender settings and their new version tag. Each explicit permission MUST
  be:
  - an address of an existing alias or mailbox in the caller's scope; or
  - an existing domain or alias domain in the caller's scope, given as `domain` or `@domain`; or
  - "any address", which only administrators MAY grant.

  Duplicate entries MUST be stored once. When "any address" is among the resulting entries, it MUST
  be stored alone (legacy behaviour). For
  a domain administrator, existing entries outside their scope, including "any address" set by an
  administrator, MUST be kept even when the request leaves them out, and MAY be sent back unchanged;
  the request MUST NOT add such entries. An entry for an alias that delivers to the mailbox and
  allows sending MUST be accepted without being stored, because the mailbox already has that right;
  for an alias that does not allow sending it MUST be stored, overriding the alias's setting.
- **FR-055**: Each external sender address MUST be a valid address (FR-009) or a domain name given as
  `domain` or `@domain`, and its domain MUST NOT be a domain or alias domain of this server.
  Duplicates MUST be stored once. A domain administrator MUST hold `extend_sender_acl` to change
  them (FR-006).
- **FR-056**: A client MUST be able to list, with pagination and search, the entries the caller could
  add as explicit permissions for a mailbox: the domains and alias domains in the caller's scope,
  "any address" for administrators, and the addresses of aliases and mailboxes in the caller's scope,
  leaving out the mailbox itself, the aliases that deliver to it and the entries already granted, as
  the legacy selection list does.
- **FR-057**: Sender permissions MUST be stored where legacy stores them, so that the mail server
  accepts a sender address from the mailbox at once exactly when legacy would. After the external
  sender addresses change, the mailbox's groupware directory entry MUST be updated and the cache
  flushed, unless the groupware is disabled, so that its external full addresses become selectable
  sender identities. The contract MUST repeat the legacy hints: a DKIM key should be imported for an
  external domain, the server must be added to that domain's SPF record, and another mailbox's
  address becomes a groupware identity only after that mailbox delegates access.

#### Bulk actions

- **FR-058**: Administrators and domain administrators MUST be able to apply one of these actions to
  up to 100 objects in one request, as the legacy mass actions do:
  - aliases: activate, deactivate, show in the groupware, hide in the groupware, delete, copy over
    alias domains (FR-036);
  - alias domains: activate, deactivate, delete;
  - resources: activate, deactivate, delete.

  A request with more than 100 objects MUST be refused with a validation error that states the
  limit. Creation MUST NOT be offered as a bulk action; each alias, alias domain and resource is
  created with its own request.
- **FR-059**: A bulk action MUST be refused as a whole when the caller's role, key scope or flags do
  not allow the action. Otherwise each named object MUST be processed on its own, with the same
  checks, follow-up steps and audit record as a single request for it, and the response MUST report
  one result per distinct object: done, not found, refused (with the error type), outdated, or
  failed (with the error type). The result of a copy over alias domains MUST include the
  per-alias-domain outcomes of FR-037. A failure on one object MUST NOT stop or undo the others, as
  in legacy. Each item MAY carry the version tag the client read, for example from the list
  (FR-060). An item whose tag no longer matches MUST get the result "outdated" and stay unchanged,
  and the other items MUST proceed. Items without a tag MUST be processed without a version check.

#### Version tags

- **FR-060**: Every alias, alias domain and resource, and the sender settings of every mailbox, MUST
  carry a version tag. The tag MUST change whenever the stored values change, whether the change was
  made through this API, the legacy UI or `/api/v1`. For an alias domain, the tag MUST NOT change
  with the alias count, the target's relay state or the rate limit. For sender settings, it MUST
  cover the explicit permissions and the external sender addresses, and MUST NOT change with the
  addresses the mailbox may send as without a permission. Every list entry MUST carry its version
  tag, so that a client can send it with a change or a bulk action without inspecting each object.
- **FR-061**: A client MAY send the version tag it read with a change, a replacement or a deletion.
  When the tag no longer matches, the request MUST be refused as outdated and MUST change nothing.
  The contract MUST recommend that interactive clients always send it.

#### Reliability

- **FR-062**: Each change of this feature MUST store all of its changes to stored data together or
  none of them, for example an alias and the sender permissions it carries over or removes, or an
  alias domain and everything its deletion removes. When mailcow's shared store is unavailable, alias
  domain creation and deletion MUST be refused with a temporary-failure error and MUST leave no
  partial change.

#### Audit

- **FR-063**: Every request of this feature that changes state, or tries to, MUST write one audit
  record per affected object into feature 001's audit log, with the fields of FR-046 there and:
  - the target type (alias, alias domain, resource or mailbox sender settings) and identifier;
  - the outcome, including refusals and their reason;
  - for successful changes, each changed value with its old and new value;
  - for bulk actions and copies over alias domains, the identifier of the request;
  - for an alias domain creation, the creation report (FR-043);
  - for deletions, what else was removed (aliases, sender permissions, temporary aliases, BCC maps);
  - failures of groupware directory updates (FR-034).
- **FR-064**: Administrators MUST be able to filter the audit log by target type and target
  identifier, in addition to the filters of feature 001 (FR-047 there).

#### Cross-cutting

- **FR-065**: Errors MUST follow feature 001's conventions (FR-049 and FR-055 there). This feature
  MUST document at least these error types: not found, address in use (with the kind of holder),
  domain alias limit reached (with the count and the limit), destination not permitted (with the
  destinations and the reason: server setting or missing flag), attribute not permitted for the role
  (with the field and the flag), alias domain already exists, not a primary-domain alias, outdated
  version, and temporary failure. Validation errors MUST use stable rule codes with their
  parameters, including the comment limit in bytes, the destination length limit, the booking rule
  range and the bulk limit.
- **FR-066**: Every operation MUST be described in the published API contract before it is
  implemented, and its behaviour MUST match the contract.
- **FR-067**: Changes made through the legacy UI or `/api/v1` MUST be visible in the next response of
  this API.

#### Capabilities

- **FR-068**: Administrators and domain administrators MUST be able to read the capabilities of this
  feature, the server settings and limits a client needs before it submits:
  - whether the groupware is enabled (legacy `SKIP_SOGO`). When it is disabled, clients can hide the
    groupware visibility option and the "show in the groupware" and "hide in the groupware" bulk
    actions, as legacy does;
  - whether external alias destinations are disabled for every role (FR-007);
  - the bulk limit (FR-058) and the default and maximum page sizes (FR-014);
  - the comment limit in bytes (FR-023), the destination length limit (FR-025), the maximum
    resource description length and the booking rule range (FR-046, FR-049);
  - the allowed rate limit time frames and the allowed DKIM key sizes (FR-040);
  - for administrators, and for domain administrators who hold `alias_domains`, the default DKIM
    selector and default key size that pre-fill the alias domain creation form (FR-042);
  - for administrators only, whether the DKIM DNS publication is enabled (FR-042).

  Whether the caller holds `alias_domains`, `alias_external_goto` or `extend_sender_acl` comes from
  the identity of feature 001 (FR-028 there). The values MUST reflect the server's current
  configuration and MUST NOT include any secret or connection detail. The read MUST change nothing,
  and read-only keys MAY use it.

### Legacy Coverage

Every legacy capability allocated to feature 004 in `docs/roadmap.md` maps to requirements as
follows. Actions are `/api/v1` names; *form* marks legacy UI forms and screen actions. The access,
version tag, audit and error requirements (FR-001 to FR-004 and FR-060 to FR-067) apply to every row.

| Legacy action or form | Legacy implementation | Requirements |
|---|---|---|
| `get/alias/all`, `get/alias/all/{domains}`, *form* "Mail setup › Aliases" list with domain filter | `mailbox('get', 'aliases')` per domain and alias domain, then `alias_details` per alias; `draw_alias_table()` | FR-011 to FR-017, FR-019 |
| `get/alias/{id}`, *form* Edit alias page (read) | `mailbox('get', 'alias_details')` | FR-011 to FR-013, FR-018 |
| `add/alias`, *form* add-alias modal | `mailbox('add', 'alias')`, `modals/mailbox.twig` | FR-008 to FR-010, FR-020 to FR-028, FR-032 to FR-034 |
| `edit/alias`, *form* Edit alias page | `mailbox('edit', 'alias')`, `edit/alias.twig` | FR-025 to FR-034 |
| `edit/alias` mass actions: activate, deactivate, "Show alias in SOGo", "Hide alias in SOGo" | `tab-mbox-aliases.twig` quick actions | FR-058, FR-059 |
| `edit/alias` with `expand_alias`, mass action "Expand alias over alias domains" | `mailbox('edit', 'alias')` expand branch | FR-036, FR-037, FR-058 |
| `delete/alias`, single and mass | `mailbox('delete', 'alias')` | FR-033 to FR-035, FR-058, FR-059 |
| `get/alias-domain/all`, `get/alias-domain/{name}`, *form* "Alias domains" list and edit page (read) | `mailbox('get', 'alias_domains' / 'alias_domain_details')`, `draw_aliasdomain_table()` | FR-038, FR-039 |
| `add/alias-domain`, *form* add-alias-domain modal (with rate limit, DKIM selector and key size) | `mailbox('add', 'alias_domain')`, `ratelimit('edit', 'domain')`, `dkim('add')` | FR-040 to FR-043, FR-062 |
| `edit/alias-domain`, *form* Edit alias domain page, mass activate and deactivate | `mailbox('edit', 'alias_domain')`, `edit/aliasdomain.twig`, `tab-domain-aliases.twig` | FR-044, FR-058, FR-059 |
| `delete/alias-domain`, single and mass | `mailbox('delete', 'alias_domain')` | FR-045, FR-058, FR-059, FR-062 |
| `get/resource/all`, `get/resource/{name}`, *form* "Resources" list and edit page (read) | `mailbox('get', 'resources' / 'resource_details')`, `draw_resource_table()` | FR-046, FR-047 |
| `add/resource`, *form* add-resource modal | `mailbox('add', 'resource')` | FR-048 to FR-050 |
| `edit/resource`, *form* Edit resource page, mass activate and deactivate | `mailbox('edit', 'resource')`, `edit/resource.twig`, `tab-resources.twig` | FR-051, FR-058, FR-059 |
| `delete/resource`, single and mass | `mailbox('delete', 'resource')` | FR-052, FR-058, FR-059 |
| Sender ACL / send-as: `edit/mailbox` attribute `sender_acl` (with `default` and `*`), *form* "Allow to send as" on the Edit mailbox page | `mailbox('edit', 'mailbox')` sender ACL branch | FR-054, FR-057, FR-028 |
| Send-as data of the Edit mailbox page (fixed, read-only, changeable and selectable entries) | `mailbox('get', 'sender_acl_handles')` | FR-053, FR-056 |
| External sender addresses: `edit/mailbox` attribute `extended_sender_acl`, *form* "External sender addresses", flag `extend_sender_acl` | `mailbox('edit', 'mailbox')` external branch | FR-006, FR-055, FR-057, FR-028 |
| Domain administrator flag `alias_domains` | check in `mailbox('add', 'alias_domain')` | FR-005, FR-006, FR-040 |
| Domain administrator flag `alias_external_goto` and server setting `ALIAS_DISABLE_EXTERNAL_DOMAINS` | alias add and edit, `is_local_mailcow_domain()` | FR-006, FR-007, FR-027 |
| Domain alias limit | `domain_details` (`aliases_left`) | FR-022, FR-030, FR-037, FR-044 |
| Server settings rendered into the screens: `SKIP_SOGO` hides the visibility option and the "Show/Hide alias in SOGo" actions; the add-alias-domain form pre-fills the DKIM selector and key size; the `maxlength` of the comment fields | `templates/edit/alias.twig`, `mailbox/tab-mbox-aliases.twig`, `modals/mailbox.twig` | FR-068 |

Parts of the legacy screens that belong to other features: the rate limit form of the Edit alias
domain page (`edit/rl-domain`, feature 006), the DKIM record and the DNS button of alias domains
(feature 010), the mailbox user's "Details" tab with their own aliases and send-as addresses
(`user_get_alias_details()`, feature 005), temporary aliases (005), and BCC and recipient maps (009).
The Edit mailbox page's other fields belong to feature 003.

### Legacy Parity and Deliberate Deviations

Parity reference: `source_code/data/web/inc/functions.mailbox.inc.php` (`mailbox()` cases `alias`,
`alias_domain`, `resource`, `aliases`, `alias_details`, `alias_domains`, `alias_domain_details`,
`resources`, `resource_details`, `sender_acl_handles`, the sender ACL branches of the `mailbox` edit
case, and the closing groupware update), `functions.inc.php` (`hasDomainAccess`,
`hasMailboxObjectAccess`, `hasAliasObjectAccess`, `hasACLAccess`, `is_local_mailcow_domain`,
`is_valid_domain_name`, `update_sogo_static_view`, `flush_memcached`), `functions.acl.inc.php`
(`to_session`: administrators hold every flag), `functions.ratelimit.inc.php`,
`functions.dkim.inc.php`, `vars.inc.php` (`ALIAS_DISABLE_EXTERNAL_DOMAINS`), `json_api.php`,
`init_db.inc.php` (tables `alias`, `alias_domain`, `sender_acl`, `spamalias`, `mailbox` with `kind`
and `multiple_bookings`, `da_acl`; views `grouped_mail_aliases`, `grouped_sender_acl_external`,
`grouped_domain_alias_address`), `templates/edit/alias.twig`, `edit/aliasdomain.twig`,
`edit/resource.twig`, `edit/mailbox.twig`, `templates/mailbox/tab-mbox-aliases.twig`,
`tab-domain-aliases.twig`, `tab-resources.twig`, `templates/modals/mailbox.twig`,
`js/site/mailbox.js`, `lang/lang.en-gb.json`, the Postfix SQL maps in
`Dockerfiles/postfix/postfix.sh` (`mysql_virtual_alias_maps`, `mysql_virtual_alias_domain_maps`,
`mysql_virtual_resource_maps`, `mysql_virtual_sender_acl`, `mysql_virtual_domains_maps`), the Rspamd
settings map `conf/rspamd/dynmaps/settings.php` (internal aliases) and
`Dockerfiles/sogo/bootstrap-sogo.sh` (groupware identity fields `aliases`, `ad_aliases`, `ext_acl`).

The reference is upstream mailcow-dockerized `2026-09a`. `source_code/` points to the FeldHost fork
(`~/Projects/mailhosting`, branch `feldhost-202609a`), so the files above were diffed against that
upstream tag. `functions.mailbox.inc.php`, `functions.inc.php`, `functions.ratelimit.inc.php`,
`json_api.php`, the alias, alias domain and resource templates and the Postfix and SOGo
configuration are identical. The `alias_external_goto` flag and `ALIAS_DISABLE_EXTERNAL_DOMAINS` are
upstream (commit `8bd9e12a`, contained in `2026-09a`); the fork only adds their Czech and Slovak
translations. The fork differs in three ways:

1. The add-alias-domain form pre-fills the DKIM selector `feldhost` instead of `dkim`
   (`modals/mailbox.twig`), and `dkim('add')` falls back to `feldhost`. This feature uses feature
   002's default-selector setting (FR-042).
2. DKIM key creation, which alias domain creation triggers, also pushes the public key to an
   ISPConfig DNS server when `inc/ispconfig.inc.php` exists, ignoring failures. This feature uses
   feature 002's DKIM DNS publication setting, off by default, and reports the outcome (FR-042,
   FR-043).
3. Branding-only texts, such as "FeldHost™ Mailhosting domains only" in the alias address hint, with
   no effect on behaviour.

| Behaviour | Legacy | This feature |
|---|---|---|
| Who may manage | Administrators and domain administrators within their domains; mailbox users never | Same (FR-001, FR-005) |
| Object outside scope | Empty result with status 200, or an "access denied" message | "Not found" (FR-003) |
| Mailbox's own alias | Hidden from the alias list (`address != goto`) and from the alias count | Same (FR-013) |
| Orphaned aliases (domain gone) | Invisible to everyone, because access checks need the domain | Listed for administrators, marked, deletable (FR-019). **Deviation** |
| Alias identifier | Numeric `id`; `alias_details` also accepts the address | Identifier; address through the exact-address filter (FR-011, FR-016) |
| Address normalisation | Domain part converted with UTS #46, local part case kept. Destinations are converted on creation but validated before conversion on edit, so Unicode destination domains fail on edit | Same normalisation, for addresses and destinations on creation and change (FR-008). **Deviation** for edits |
| Several addresses per creation | `add/alias` splits the address field and creates one alias per address, stopping at the alias limit | One alias per request (FR-020, FR-058). **Deviation** |
| Defaults at creation | The form pre-sets active, sending allowed and visible on, internal off; `/api/v1` treats omitted flags as 0, so an alias created without `active` is inactive | The form defaults (FR-020). **Deviation** from `/api/v1` |
| Address in use | `alias` rows (including mailboxes' own), alias-domain addresses of existing mailboxes, `spamalias`. A resource address is not checked, so an alias can shadow a resource | Also resources; one conflict that names the holder (FR-010, FR-021). **Deviation** |
| Alias limit | Refused only when `aliases_left` is exactly 0; not checked when an alias moves to another domain or an alias domain to another target | Refused when the count would exceed the limit, also for moves and copies (FR-022, FR-030, FR-037, FR-044). **Deviation** |
| Destination rules | Creation: external check, not a resource, valid address. Edit: valid address, external check, not the alias's own address; resources not checked. Duplicates removed case-sensitively | The same rules on creation and change, resources refused on change, duplicates merged case-insensitively, 65,535-character limit (FR-025). **Deviation** |
| Address equal to a destination | Creation silently skips that address, so no alias is made and no error is shown; edit refuses | Refused (FR-025). **Deviation** |
| Special actions | Checkboxes for discard, spam and ham, resolved silently in that order; other destinations dropped | Exactly one special action, never with destinations (FR-026). **Deviation** |
| Invalid or refused entries | Dropped with one message each, the rest saved; an edit whose destinations all fail reports success and saves nothing | Whole request refused, every entry named (FR-028). **Deviation** |
| External destinations | External means not an active domain or active alias domain. Every destination is re-checked on every edit, so unchanged external destinations are dropped. The global switch also binds administrators | Same definition and switch; only added destinations are checked (FR-007, FR-027). **Deviation** |
| Comments | At most 160 bytes each | Same (FR-023) |
| Sender permissions on alias changes | Every edit deletes the permissions of the destination mailboxes to send as the alias, even when the alias does not allow sending, which removes deliberate overrides; creation does not | Removed on creation and change only when the alias allows sending (FR-032). **Deviation** |
| Address change | Only the alias row changes; permissions for the old address stay and would apply to a new alias with that address | Permissions carried over to the new address (FR-030). **Deviation** |
| Alias deletion | `alias` row and every `sender_acl` row for the address; groupware update of the former destinations | Same (FR-033, FR-035) |
| Expand over alias domains | Copies get the destination, active and visible on, sending allowed and internal off; an alias in an alias domain is skipped with a warning; stops at the alias limit | Copies inherit the destination and all four flags; one result per alias domain; an alias in an alias domain is refused (FR-036, FR-037). **Deviation** |
| Groupware updates | `update_sogo_static_view()` per affected mailbox for aliases and resources, a full rebuild for alias domains, then a memcached flush; skipped with `SKIP_SOGO`; database errors reported as success | Same mechanisms; failures recorded in the audit record (FR-033, FR-034, FR-042, FR-044, FR-045, FR-050 to FR-052, FR-057) |
| Alias domain creation checks | Valid names, name differs from target, target exists and is not a backup MX domain, name not yet a domain or alias domain, `alias_domains` flag | Same, plus the host name check (FR-041). **Deviation** for the host name |
| Alias domain creation effects | `alias_domain` row; `DOMAIN_MAP` entry (a failure leaves the row without its entry); `ratelimit('edit')` → `RL_VALUE`, which needs the `ratelimit` flag and otherwise fails after the row exists; `dkim('add')` → `DKIM_PUB_KEYS`, `DKIM_SELECTORS`, `DKIM_PRIV_KEYS` (fork: ISPConfig push); full groupware rebuild. The removal of overlapping external sender addresses uses an undefined variable and removes nothing | Row and domain map entry atomic; overlapping external sender addresses removed; a domain administrator without `ratelimit` refused before anything is stored; per-step report (FR-006, FR-042, FR-043). **Deviation** |
| Alias domain change | Target and active state; the new target must be a domain, not an alias domain, in scope; relay targets and the alias limit are not checked; no flag needed | Target rules as at creation, including relay domains; alias limit checked; no flag needed (FR-044). **Deviation** for the relay and limit checks |
| Alias domain deletion | `alias_domain` row, its `alias` rows, `spamalias` rows, `DOMAIN_MAP` entry. The `RL_VALUE` removal uses an undefined variable, so the rate limit stays; sender permissions and spam lists stay; the DKIM key stays; the name is not normalised | Also removes the rate limit, the sender permissions and the spam lists; keeps the DKIM key; normalises the name (FR-045). **Deviation** |
| BCC maps on alias domain deletion | `DELETE FROM bcc_maps WHERE local_dest = '<alias domain>'`, but domain-level BCC maps are stored as `@<alias domain>`, so they survive and apply again to an alias domain created later with the same name | BCC maps with the local destination `@<alias domain>` or the bare name removed (FR-045). **Deviation** (bug fix) |
| Resource creation | Local part from the letters and digits of the description, case kept; kind location, group or thing; booking values below -1 become -1; the domain must be a primary domain; `mailbox`, `alias` and `spamalias` checked; `mailbox` row with password `RESOURCE`, quota 0 and the kind; groupware update | Same; booking values below -1 refused (FR-048 to FR-050). **Deviation** for booking values |
| Resource change and deletion | Description, kind, booking rule and active state; deletion removes the `mailbox` row and the resource's `sogo_*` rows | Same (FR-051, FR-052) |
| Resources and domain limits | Not counted as mailboxes, no quota, no limit on their number | Same (FR-050) |
| Explicit sender permissions | One list per save: `default` resets, `*` is stored alone and only by administrators; domains must be domains or alias domains of the server in scope; addresses must be aliases or mailboxes in scope; entries outside a domain administrator's scope are kept; entries for aliases that allow sending are skipped, for aliases that do not are stored; invalid entries are dropped | The same model as set replacement; invalid entries refuse the request (FR-028, FR-054). **Deviation** for the refusal |
| External sender addresses | Needs `extend_sender_acl`; addresses or domains outside the server's domains and alias domains; the set is replaced; domains stored as `@domain`; full addresses become groupware identities (`ext_acl`); invalid entries are dropped | Same; invalid entries refuse the request (FR-028, FR-055, FR-057). **Deviation** for the refusal |
| Reading sender settings | Fixed aliases split into allowed and blocked (catch-alls hidden), alias-domain addresses, read-only and changeable entries, selectable domains and addresses | Same information; catch-all aliases shown and stale entries marked (FR-053, FR-056). **Deviation** |
| Mass actions | One request with a list of items; processing continues past failures; no version check | Bulk action with one result per object and an optional version tag per item (FR-058, FR-059) |
| Concurrent edits | Last write wins | Version tags (FR-060, FR-061). **Deviation** |
| UI log entries | Written to the `logs` table | Written to this API's audit log (FR-063), as in feature 001. **Deviation** |
| Domain administrator flags | `alias_domains` (creation only), `alias_external_goto`, `extend_sender_acl`, `ratelimit`; values the flags do not allow are dropped with a message | Same flags; requests that need a missing flag are refused and named (FR-006). **Deviation** |

### Key Entities *(include if feature involves data)*

- **Alias** (existing mailcow data): an address, or `@domain` for a catch-all, that delivers to a
  list of destination addresses or performs one special action, with its active state, sending
  permission, groupware visibility, internal flag, comments and times. It lives in a domain or an
  alias domain.
- **Special action** (existing mailcow convention): discard, learn as spam or learn as ham, stored as
  a reserved destination address.
- **Alias domain** (existing mailcow data): a domain name whose mailbox addresses map to the same
  local parts in its target domain. It can hold aliases of its own. Its rate limit and DKIM key are
  kept in mailcow's shared store.
- **Resource** (existing mailcow data, in the mailbox store): a bookable location, thing or group
  with a description, a derived address, a booking rule and an active state.
- **Explicit sender permission** (existing mailcow data): lets one mailbox send as an address, a
  domain of the server, or any address.
- **External sender address** (existing mailcow data): lets one mailbox send as an address or a
  whole domain that is not hosted on the server.
- **Implicit sender address** (computed): an address a mailbox may send as without a permission: its
  own, its alias-domain addresses, and the aliases that deliver to it and allow sending.
- **Sender settings of a mailbox** (view over existing data): the mailbox's implicit sender
  addresses, explicit sender permissions and external sender addresses, with one version tag over
  the stored entries.
- **Bulk action** (transient): one action on up to 100 objects, with one result per object.
- **Alias domain creation report**: the outcome of each follow-up step of one alias domain creation.
- **Audit record** (owned by this API, defined in feature 001): extended with the target type, the
  target identifier, the value changes and the follow-up step outcomes.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For 100% of the aliases, alias domains, resources and mailbox sender settings in the
  parity test set, the values returned by the API equal those the legacy UI shows for the same data.
- **SC-002**: An alias, alias domain or resource created, changed or deleted through the API with the
  same input as through the legacy UI leaves identical stored data, shared-store entries and
  groupware directory contents, apart from timestamps, generated key material and the stated
  deviations.
- **SC-003**: Mail to a new alias, or to a mailbox address under a new alias domain, is accepted
  within 1 minute of its creation, and a changed sender permission applies to the next mail
  submission.
- **SC-004**: 100% of attempts by domain administrators to reach an object outside their scope get
  the same response as for an object that does not exist, and 100% of attempts by mailbox users to
  use any operation of this feature are refused.
- **SC-005**: In 95% of requests, a page of 25 aliases is returned within 1 second on a server with
  5,000 domains, 100,000 mailboxes and 200,000 aliases.
- **SC-006**: 100% of changes sent with an outdated version tag are refused, and no change sent with
  a current version tag overwrites a change it did not see.
- **SC-007**: Every request in FR-063 produces exactly one audit record per affected object.
- **SC-008**: After an alias domain is deleted through the API, a scan of the mailcow data finds no
  row or shared-store entry that refers to it, apart from its retained DKIM key.
- **SC-009**: 100% of refused requests name every invalid field or entry with a stable rule code, so
  that the web app can show each error next to its field.
- **SC-010**: A bulk action on 100 aliases completes within 10 seconds.

## Assumptions

- Domains (feature 002) and mailboxes (feature 003) are managed in their own features. This feature
  reads their data and counts aliases with feature 002's rules.
- Temporary aliases (005), BCC maps and recipient maps (009), rate limit changes (006), DKIM key
  management and DNS diagnostics (010) and domain administrator accounts and flags (007) are managed
  in their own features. This feature only checks temporary-alias addresses, applies initial rate
  limits and DKIM keys of new alias domains, and removes the dependent data listed in FR-045.
- The mailbox user's own view of their aliases and send-as addresses belongs to feature 005.
- Feature 002 deletes the alias domains of a deleted domain "as a deletion of that alias domain alone
  would" (FR-037 there). That cleanup is the one in FR-045, which also removes the alias domain's
  sender permissions and spam lists. The plans of both features use one shared cleanup.
- Mailbox deletion and renaming in feature 003 remove or rewrite the aliases, destinations and
  sender permissions of the mailbox. This feature does not repeat that work.
- Postfix, Dovecot and the mail filter read aliases, alias domains, resources and sender permissions
  directly from the mailcow data, so they need no further action. The groupware reads its directory
  view, which this feature keeps up to date.
- The legacy UI never sends version tags, so it can still overwrite changes made through this API.
- The server setting of FR-007 mirrors the legacy setting `ALIAS_DISABLE_EXTERNAL_DOMAINS`, which
  lives in a legacy configuration file. The operator sets both the same way.
- These defaults can be changed by the operator: the external-destination setting (off), the bulk
  limit (100) and the list page size (25, maximum 1,000). The default DKIM selector and the DKIM DNS
  publication are feature 002's settings.
- **Out of scope**:
  - temporary aliases, BCC maps and recipient maps;
  - rate limit changes and DKIM key management for alias domains;
  - the mailbox user's own view of aliases and send-as addresses;
  - domain administrator management.
- **Dependencies**:
  - the upstream mailcow database schema of the supported release;
  - mailcow's shared store (domain map, rate limits, DKIM keys);
  - the groupware directory view and its cache;
  - for the fork-only DKIM DNS publication, an ISPConfig server and feature 010's specification;
  - feature 001 (identity, roles, flags, API keys, errors, audit log), feature 002 (domains, alias
    counting, DKIM settings) and feature 003 (mailboxes, address-in-use conflict).
