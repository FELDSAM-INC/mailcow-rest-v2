# Feature Specification: Routing and Address Rewriting

**Feature Branch**: `main` (no feature branches, constitution 1.1.0)

**Created**: 2026-10-18

**Status**: Draft

**Input**: Feature description built from `docs/roadmap.md` (feature 009 and allocation row 009):

> Routing and address rewriting for mailcow REST API v2: administrators manage how an existing
> mailcow-dockerized server sends and rewrites mail. They list, search, create, inspect, edit and
> delete sender-dependent relayhosts and transport maps, including the SMTP credentials these use,
> and test a relayhost or a transport by sending a test message through it. They assign a relayhost
> to domains and mailboxes; domain administrators may do this for their own domains and mailboxes
> when their permission flags allow it. Administrators manage TLS policy maps, recipient maps and
> forwarding hosts. Administrators, and domain administrators with the BCC permission flag, manage
> BCC maps for the domains, alias domains, mailboxes and aliases in their scope. Mailbox users have
> no access.
>
> Allocated legacy surface: relayhosts (including the test), transports (including the transport
> check), relayhost assignment to domains and mailboxes, forwarding hosts (`fwdhost`), BCC maps
> (`bcc`, `bcc-destination-options`), recipient maps and TLS policy maps. The legacy screens
> "System › Routing" (sender-dependent transports, transport maps, test dialog), "System ›
> Configuration › Forwarding hosts", "Mail setup › BCC" (BCC maps, recipient maps), "Mail setup ›
> TLS policy maps" and the relayhost selectors of the "Edit domain" and "Edit mailbox" pages need
> list filters, sorting, search and the fields they show today.
>
> Constraints: every legacy side effect is reproduced, so Postfix and Rspamd route, rewrite, copy and
> accept mail the same whichever implementation made the change. Relayhost and transport passwords
> are stored secrets. The legacy UI and `/api/v1` keep working on the same data. The feature builds
> on feature 001's identity, roles, permission flags, API keys, error conventions and audit log, and
> on the conventions of feature 002 (not found outside the caller's scope, version tags, list
> parameters, bulk actions for the legacy mass actions).

## Clarifications

### Session 2026-10-18

- Q: How are relayhost and transport passwords handled, given that legacy returns them in plain text
  (`/api/v1` and the edit pages) and escapes colons when storing them? → A: Passwords are
  write-only. No response, list, error, audit record or log contains a password, not even its first
  characters. Representations show the username (to administrators) and whether a password is set.
  An edit that omits the password keeps it, and an explicitly empty password removes it. Passwords are
  stored verbatim, because the mail server needs them in clear text, without the legacy colon
  escaping. A username that contains a colon is refused. Test transcripts redact authentication data
  (FR-012 to FR-014, FR-043). (agent decision: constitution VI and feature 001 FR-050 forbid secrets
  in responses after creation. The mail server splits the stored credentials at the first colon and
  knows no escaping, so the legacy escaping corrupts every credential that contains a colon, and
  legacy relayhost creation even escapes the password twice.)
- Q: Should a domain administrator with the `domain_relayhost` flag be able to change a domain's
  relayhost, given that legacy computes the value but never saves it? → A: Yes. The change is saved.
  The `mailbox_relayhost` flag works as in legacy (FR-029). (agent decision: the flag is labelled
  "Change relayhost for a domain" and the legacy UI shows the selector to domain administrators who
  hold it. The same administrators can already assign any relayhost to their mailboxes with
  `mailbox_relayhost`, so this grants no new kind of access. Not saving the value is a legacy defect.)
- Q: Are relayhost assignments changed through the domain and mailbox edit operations of features 002
  and 003, or through operations of their own? → A: Through their own operations: a client reads and
  replaces the relayhost assignment of a domain or of a mailbox, each with its own version tag and
  audit record. The domain and mailbox representations keep showing the assignment read-only, and
  their edit operations keep refusing to change it. Domain administrators who hold either relayhost
  flag can read a reduced list of relayhosts (identifier, host, active state) to choose from, without
  usernames or usage (FR-023 to FR-028). (agent decision: one operation per permission flag keeps
  authorisation and auditing simple and leaves the 002 and 003 contracts unchanged. Legacy shows
  every domain administrator the relayhost usernames, which least privilege does not require.)
- Q: What happens when a relayhost that is still assigned to domains or mailboxes is deleted? → A:
  The deletion is allowed. It clears the assignments of the domains, as legacy does, and of the
  mailboxes, which legacy leaves behind, in the same step. The audit record lists both (FR-021).
  (agent decision: legacy deletes assigned relayhosts and resets the domains. The mailbox references it
  leaves behind have no effect today, but would silently become active again if the identifier were
  ever reused, for example after a database restore.)
- Q: How are the legacy mass actions and the comma-separated destinations of the legacy transport
  form handled? → A: The mass actions (activate, deactivate, switch the BCC type, switch the
  forwarding host spam filter, delete) are bulk actions as features 002 to 004 define them: up to 100
  entries per request, refused as a whole when the action is not permitted, otherwise one result and
  one audit record per entry; each item may carry a version tag (see the orchestrator clarification
  below). Creation takes exactly one entry per request,
  so a transport has one destination; the web app may split the form input and send one request per
  destination (FR-034, FR-090, FR-091). (agent decision: legacy `edit` and `delete` take arrays and
  continue past failures, and features 002 to 004 set the bulk pattern. Single-entry creation keeps
  one "created" response per entry, while legacy silently skips invalid destinations and stores the
  rest.)
- Q: How do the shared credentials of transports with the same next hop behave? → A: Credentials
  belong to the next hop. A creation for an existing next hop inherits its credentials when it sends
  none, is refused as a conflict when it sends a different username, and replaces the password of
  every transport of that next hop when it sends a password with the same username or with none. An edit that
  sends credentials applies them to every transport of the resulting next hop. An edit that moves a
  transport to a next hop already used by other transports, without sending credentials, makes it
  adopt their credentials. Responses and audit records name the other transports whose credentials
  changed (FR-038). (agent decision: the mail server looks up transport credentials per next hop, and
  legacy propagates credentials to every transport with the same next hop and refuses a different
  username at creation. Legacy also wipes the shared password when a creation omits it, and
  overwrites the target's credentials when an edit moves a transport: both are defects.)
- Q: Which state applies when a creation request does not say whether the entry is active? → A: The
  value the legacy form pre-selects: relayhosts active, transports inactive, BCC maps, recipient maps
  and TLS policy maps active, and forwarding hosts with the spam filter enabled (FR-018, FR-034,
  FR-049, FR-061, FR-067, FR-076). (agent decision: the legacy server code stores "inactive" or "spam
  filter disabled" for an omitted value, which differs from what operators get through the forms.
  Transports stay inactive by default, because an active `*` transport reroutes all outbound mail at
  once.)
- Q: What does a relayhost or transport test return, and how is it treated? → A: A structured result:
  whether the test message was accepted, the step reached, the host and port used, whether TLS was
  used, the last server reply and the dialogue transcript with authentication data redacted. A test
  that ran is a successful request whatever its outcome. A test counts as a state-changing request: a
  read-only key cannot run it, a browser session needs the anti-forgery token, and it is audited.
  Invalid sender or recipient addresses are refused instead of silently replaced (FR-041 to FR-045).
  (agent decision: the legacy test prints a coloured HTML transcript at a debug level that includes
  the base64-encoded login exchange, which leaks the stored credentials. A test sends a real message
  to a third party, so it is not a safe read.)
- Q: Which local destinations does a BCC map accept, and how does a client find them? → A: Domains,
  alias domains, mailboxes, aliases other than catch-alls and mailboxes' own aliases, and mailbox
  addresses in the domain's alias domains, each only within the caller's scope. An unknown and an
  out-of-scope destination get the same validation error. A paginated, searchable list offers these
  choices (FR-050, FR-057). (agent decision: the legacy server refuses the alias-domain addresses
  that its own choice list offers, although the spam filter checks the envelope address and such a map
  works. The legacy choice list returns every object of the server in one response.)
- Q: How is a forwarding host created when its name resolves to several addresses, some of which are
  already listed? → A: The name is resolved as in legacy (SPF permitted hosts, else the mail exchanger
  addresses, else the host addresses), with the SPF limit of 10 DNS lookups. All addresses are stored
  together or not at all. An address that is already listed gets the new source and spam filter
  setting, as in legacy, and the response says for each address whether it was added or updated. New
  addresses are stored in canonical form, and invalid address literals are refused (FR-076 to
  FR-078). (agent decision: the legacy overwrite is kept. Legacy can stop half-way when the shared
  store fails. The keep-spam check of the mail filter matches only prefixes written with their network
  address, and legacy accepts strings such as `cafe` as IPv6 addresses.)
- Q: How does a client learn the server settings and limits it needs before it submits, such as the
  default test addresses the web app shows as placeholders? → A: A read-only "routing capabilities"
  operation, following feature 002 (FR-064 there). Administrators read the default test sender and
  recipient, the bulk limit, the page sizes, the field limits, the allowed TLS policies and the
  forwarding host lookup limit. Domain administrators read only what they use: the bulk limit, the
  page sizes and the BCC field limits (FR-093). (orchestrator decision: the web app's feature 009
  lists the unreadable default test addresses as an API gap; `docs/conventions.md` sends clients to
  the owning feature's capabilities read.)
- Q: How can a client tell an orphaned BCC map from a normal one? → A: Every BCC map carries a flag
  that says whether the domain or alias domain of its local destination still exists. Only
  administrators can see orphaned maps, so domain administrators always see the flag as false
  (FR-046, FR-056). (orchestrator decision: the web app wants to label orphans, which administrators
  can already list and delete.)
- Q: Do bulk actions check version tags? → A: Each bulk item may carry its own version tag. An item
  whose tag is outdated gets the result `outdated` and changes nothing, and the other items proceed.
  Items without a tag are not checked. Every list entry carries its version tag, and lists keep the
  page size of 25 by default and 1,000 at most (FR-007, FR-091). (orchestrator decision:
  `docs/conventions.md`, bulk actions and version tags.)

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Manage relayhosts (Priority: P1)

An administrator keeps a list of relayhosts, which legacy calls "sender-dependent transports": SMTP
servers through which mailcow sends the outbound mail of selected domains and mailboxes, for example
a provider's smarthost. Each relayhost has a host (with an optional port), optional SMTP credentials
and an active state. The list shows, as the legacy "System › Routing" screen does, each relayhost's
identifier, host, username, active state and which domains and mailboxes use it. The administrator
searches, filters, sorts and pages through the list, adds relayhosts, changes their host, credentials
or state, and deletes them. Passwords can be set and replaced, but are never shown again.

**Why this priority**: Relayhosts are the most used routing objects, assignments and tests depend on
them, and the legacy UI exposes their passwords today.

**Independent Test**: Load a test copy of the mailcow database with relayhosts created in the legacy
UI, with and without credentials, assigned to domains and mailboxes. List, inspect, create, edit and
delete relayhosts through the API, compare the stored data with what the legacy UI stores for the same
input (allowing for the stated deviations), and scan every response for passwords.

**Acceptance Scenarios**:

1. **Given** no relayhosts, **When** an administrator creates one with host `[smtp.example.net]:587`,
   username `acct` and password `s3cret`, **Then** it is created as active, the response points to it,
   and it shows the username and "password set" but not the password.
2. **Given** relayhosts created in the legacy UI with passwords, **When** a client lists or inspects
   them, **Then** no response contains a password or a part of one.
3. **Given** a relayhost assigned to 2 domains and 1 mailbox, **When** it is listed, **Then** it
   reports 2 domains and 1 mailbox, and its usage list returns those 3 names.
4. **Given** a relayhost with credentials, **When** an administrator deactivates it without sending
   credentials, **Then** its username and password are unchanged. **When** the administrator sends an
   empty password, **Then** it reports "no password set".
5. **Given** a creation with host `192.0.2.10:25`, **When** it is submitted, **Then** the host is
   stored as `[192.0.2.10]:25`. **Given** a host `smtp:relay.example.net` or `relay..example.net`,
   **Then** the request is refused with a validation error naming the host.
6. **Given** a creation with the username `user:name`, **When** it is submitted, **Then** it is refused
   with a validation error naming the username.
7. **Given** a relayhost assigned to a domain and to a mailbox, **When** an administrator deletes it,
   **Then** the response has no body, neither the domain nor the mailbox has a relayhost assignment
   any more, and the audit record names both.
8. **Given** a domain administrator, **When** they request the full relayhost list, **Then** the
   request is refused as forbidden. **When** they hold the `domain_relayhost` flag and request the
   relayhost choices, **Then** they receive identifiers, hosts and active states, without usernames.
9. **Given** three relayhosts and an identifier that does not exist, **When** an administrator
   deactivates all four in one request, **Then** the three are inactive, the fourth is reported "not
   found", and the audit log has one record per relayhost. **When** one of the named relayhosts
   carries a version tag that is outdated, **Then** that one is reported `outdated` and unchanged, and
   the others are deactivated.

---

### User Story 2 - Assign relayhosts to domains and mailboxes (Priority: P1)

An administrator routes the outbound mail of a domain through a relayhost, or overrides the domain's
choice for one mailbox. A domain administrator does the same for the domains and mailboxes they
manage, when their permission flags allow it. The client sees which relayhost is assigned, whether it
is active, and which relayhost the mail server will actually use for a mailbox. If someone changed the
assignment in the meantime, the stale change is refused.

**Why this priority**: Assignments are what makes relayhosts take effect, and domain administrators
need them for customers whose mail must leave through their own provider.

**Independent Test**: Assign, change and clear relayhosts for domains and mailboxes as an
administrator and as domain administrators with and without each flag, and confirm through test mail
that the mail server sends mail from each address through the expected relayhost, exactly as after
the same change in the legacy UI.

**Acceptance Scenarios**:

1. **Given** a domain `example.org` with an alias domain `example.net`, **When** an administrator
   assigns relayhost 3 to `example.org`, **Then** mail sent from addresses in `example.org` and
   `example.net` leaves through relayhost 3, and the domain's representation in feature 002 shows
   relayhost 3 with a new version tag.
2. **Given** a domain administrator with the `domain_relayhost` flag, **When** they assign a relayhost
   to a domain they manage, **Then** the assignment is saved (legacy silently discards it). **Given**
   a domain administrator without the flag, **Then** the request is refused as forbidden, the error
   names the flag, and nothing changes.
3. **Given** a domain administrator with the `mailbox_relayhost` flag and a domain assigned to
   relayhost 3, **When** they assign relayhost 5 to the mailbox `user@example.org`, **Then** mail from
   that mailbox leaves through relayhost 5, the mailbox reports relayhost 5 as effective, and other
   addresses of the domain still use relayhost 3.
4. **Given** a mailbox assigned to an inactive relayhost 5 in a domain assigned to active relayhost 3,
   **When** a client reads the mailbox's assignment, **Then** it shows relayhost 5 as inactive and
   relayhost 3 as effective.
5. **Given** a relayhost identifier that does not exist, **When** a client assigns it, **Then** the
   request is refused with a validation error.
6. **Given** a domain administrator, **When** they read or change the assignment of a domain or
   mailbox they do not manage, **Then** the response is the same "not found" as for one that does
   not exist.
7. **Given** an assigned domain, **When** an administrator clears its assignment, **Then** its mail is
   delivered directly again, and the legacy UI shows "None / Inherit".
8. **Given** two clients that read the same assignment, **When** the first changes it and the second
   then saves with the version it read, **Then** the second save is refused as outdated.

---

### User Story 3 - Manage transport maps (Priority: P1)

An administrator routes mail for selected recipients through a specific next hop, regardless of the
sender: all mail for a domain, for its subdomains, for one address, for everything (`*`), or for
recipients whose mail exchanger matches a pattern (an MX-based transport). Transports with the same
next hop share one set of credentials. The administrator lists, searches, filters, creates, inspects,
edits, activates, deactivates and deletes transports, and sees which transports share credentials.

**Why this priority**: Transports override relayhosts and decide where mail for partner systems goes.
A wrong entry misroutes mail, so validation and clear credential sharing matter.

**Independent Test**: Create transports of each destination kind, with and without credentials and
with shared next hops, and compare the stored data and the mail server's routing decisions with those
for the same entries created in the legacy UI.

**Acceptance Scenarios**:

1. **Given** no transport for `example.com`, **When** an administrator creates one with next hop
   `[mx.partner.net]:25` without stating its state, **Then** it is created inactive. **When** they
   activate it, **Then** mail for `example.com` is sent to that next hop.
2. **Given** creations with destinations `.example.com`, `*` and `user@example.com`, **When** they are
   submitted, **Then** they are accepted. **Given** `..example.com` or `exa mple.com`, **Then** the
   request is refused with a validation error naming the destination.
3. **Given** an MX-based creation with destination `.*\.google\.com`, **When** it is submitted,
   **Then** it is accepted. **Given** the destination `(`, **Then** it is refused because it is not a
   valid regular expression.
4. **Given** a transport for `example.com`, **When** a client creates another one for `Example.com`,
   **Then** the request is refused as a conflict.
5. **Given** a transport with next hop `relay.net`, **When** a client creates one with next hop
   `[relay.net]:587`, **Then** the request is refused as a conflict naming `relay.net`, and the same
   holds in the reverse order.
6. **Given** transport A with next hop H and username `u1`, **When** a client creates transport B with
   next hop H and username `u2`, **Then** the request is refused as a conflict. **When** B is created
   without credentials, **Then** B uses `u1` and A's password. **When** B is created with `u1` and a
   new password, **Then** A and B both use the new password, and the response names A as affected.
7. **Given** transports A and B sharing a next hop, **When** an administrator changes A's password,
   **Then** B's password changes too, B's version tag changes, and the audit record names B.
8. **Given** transport C with next hop K and its own credentials, **When** an administrator changes
   C's next hop to H without sending credentials, **Then** C uses H's credentials.
9. **Given** a domain administrator, **When** they list transports, **Then** the request is refused as
   forbidden.

---

### User Story 4 - Test a relayhost or a transport (Priority: P2)

Before assigning a relayhost or activating a transport, an administrator checks that it works. They
start a test, optionally with a sender and a recipient address, and the system connects to the next
hop, uses STARTTLS when offered, logs in with the stored credentials and sends a short test message,
as the legacy "Test" dialog does. The administrator sees whether the message was accepted, where the
dialogue stopped and the server's replies, without the credentials appearing anywhere.

**Why this priority**: The test prevents misrouted mail, but routing can be configured without it.

**Independent Test**: Run tests against SMTP test servers that accept, reject the login, reject the
sender, are unreachable or answer slowly, and against next hops with and without brackets, and
compare the outcome with the legacy test. Scan results and audit records for credentials.

**Acceptance Scenarios**:

1. **Given** a relayhost with valid credentials on a server that offers STARTTLS, **When** an
   administrator tests it, **Then** the result says the message was accepted, TLS was used and login
   succeeded, and the transcript shows the dialogue with the login data replaced by a redaction mark.
2. **Given** a transport with next hop `partner.net` (without brackets), **When** it is tested,
   **Then** the most preferred mail exchanger of `partner.net` is used, and the result says so.
3. **Given** a next hop that does not answer, **When** it is tested, **Then** the result reports a
   failed connection within 60 seconds, and the request itself succeeds.
4. **Given** a sender address `not-an-address`, **When** a test is requested, **Then** the request is
   refused with a validation error naming the sender.
5. **Given** a read-only API key owned by an administrator, **When** it is used to start a test,
   **Then** the request is refused with the "insufficient key scope" error.
6. **Given** a relayhost that does not exist, **When** a test is requested for it, **Then** the
   response is "not found".
7. **Given** an operator who changed the default test recipient, **When** an administrator reads the
   routing capabilities, **Then** they receive the configured default sender and recipient, which
   the web app shows as placeholders. **Given** a domain administrator, **Then** the capabilities
   contain only the bulk limit, the page sizes and the BCC field limits.

---

### User Story 5 - Manage BCC maps (Priority: P2)

An administrator, or a domain administrator with the BCC permission, sets up silent copies of mail:
every message sent by, or sent to, a domain, an alias domain, a mailbox or an alias is copied to one
other address, for example an archive. The client picks the local destination from a list of choices
within its scope, as the legacy "Add BCC map" dialog does. The list of maps shows the type, local
destination, BCC destination, domain and state, as the legacy screen does, and supports activating,
deactivating, switching the type and deleting.

**Why this priority**: Archiving and compliance copies are a common customer request, and domain
administrators manage them themselves today.

**Independent Test**: Create, edit and delete BCC maps of both types for each kind of local
destination, as an administrator and as domain administrators with and without the BCC flag, and
confirm through test mail that copies arrive as they do for maps created in the legacy UI.

**Acceptance Scenarios**:

1. **Given** the domain `example.org`, **When** an administrator creates a recipient-type BCC map for
   it with the BCC destination `archive@backup.net`, **Then** it is stored for `@example.org`, and
   copies of mail to any address in `example.org` are sent to `archive@backup.net`.
2. **Given** a domain administrator with the `bcc_maps` flag, **When** they create a sender-type map
   for a mailbox of a domain they manage, **Then** it is saved. **Given** a domain administrator
   without the flag, **When** they list the maps of their domains, **Then** they see them. **When**
   they try to create one, **Then** the request is refused as forbidden.
3. **Given** a domain administrator, **When** they create a map for an address in a domain they do
   not manage, or for an address that does not exist, **Then** both requests are refused with the same
   validation error.
4. **Given** a recipient-type map for `user@example.org`, **When** a client creates another
   recipient-type map for it, **Then** the request is refused as a conflict. **When** the client
   creates a sender-type map for it, **Then** it is saved.
5. **Given** the BCC destination `a@example.net, b@example.net`, **When** a map is created, **Then**
   the request is refused with a validation error, because a map has exactly one BCC destination.
6. **Given** a sender-type and a recipient-type map for the same address, **When** a client switches
   the sender-type map to recipient, **Then** the request is refused as a conflict.
7. **Given** a domain administrator, **When** they request a map of a domain they do not manage,
   **Then** the response is "not found".
8. **Given** a domain administrator with two domains, one alias domain and 300 mailboxes, **When**
   they request the BCC destination choices with a search term, **Then** they receive a page of
   matching domains, alias domains, mailboxes and aliases from their scope only.
9. **Given** an existing map, **When** a client sends a different local destination with an edit,
   **Then** the request is refused with a validation error naming the local destination.
10. **Given** three sender-type maps, one of whose local destinations already has a recipient-type
    map, **When** a client switches all three to recipient in one request, **Then** two are switched,
    the third is reported as refused with the "entry already exists" error, and it is unchanged.
11. **Given** a domain-wide BCC map left behind by a legacy deletion of its domain, **When** an
    administrator lists BCC maps, **Then** that map is flagged as orphaned and can be filtered and
    deleted. **When** a domain administrator lists BCC maps, **Then** it is not listed.

---

### User Story 6 - Manage TLS policy maps (Priority: P2)

An administrator overrides the outbound TLS policy for selected destinations, for example requiring
DANE for a bank's domain or skipping TLS for a broken partner server. Each entry has a destination, a
policy and optional policy parameters. The administrator lists, searches, filters, creates, edits,
activates, deactivates and deletes entries.

**Why this priority**: Rarely needed, but security relevant when it is.

**Independent Test**: Create entries with each policy and destination form, and confirm that outbound
connections to those destinations follow the stored policy as they do for entries created in the
legacy UI.

**Acceptance Scenarios**:

1. **Given** no entry for `example.org`, **When** an administrator creates one with policy
   `dane-only`, **Then** it is stored, and outbound mail to `example.org` is only delivered over
   DANE-verified TLS.
2. **Given** destinations `.example.org` and `[mail.example.org]:25`, **When** entries are created,
   **Then** they are accepted. **Given** the destinations `*`, `.` or `@`, **Then** they are refused.
3. **Given** the policy `strict`, **When** an entry is created, **Then** it is refused with a
   validation error listing the allowed policies. **Given** `Encrypt`, **Then** it is stored as
   `encrypt`.
4. **Given** the parameters `protocols=!SSLv2 ciphers=medium`, **When** an entry is created, **Then**
   they are accepted. **Given** the parameters `protocols`, **Then** they are refused.
5. **Given** an entry for `example.org`, **When** a client creates or edits another entry to
   `EXAMPLE.org`, **Then** the request is refused as a conflict.
6. **Given** an entry, **When** a client changes its destination to `bücher.example`, **Then** it is
   stored as `xn--bcher-kva.example`.

---

### User Story 7 - Manage recipient maps (Priority: P2)

An administrator rewrites recipient addresses before delivery: mail for an old address or domain is
delivered to a new address or domain, for example after a company renamed its domain. The
administrator lists, searches, creates, edits, activates, deactivates and deletes recipient maps.

**Why this priority**: Needed for migrations and renames, but used by few installations.

**Independent Test**: Create recipient maps between addresses and between domains, and confirm through
test mail that recipients are rewritten as for maps created in the legacy UI.

**Acceptance Scenarios**:

1. **Given** no recipient maps, **When** an administrator maps `old.example` to `new.example`,
   **Then** the map is stored as `@old.example` to `@new.example`, and mail to `user@old.example` is
   delivered to `user@new.example`.
2. **Given** a map from `info@example.org` to `sales@example.org`, **When** mail arrives for
   `info@example.org`, **Then** it is delivered to `sales@example.org`.
3. **Given** an original destination that is neither a domain nor an address, **When** a map is
   created, **Then** it is refused with a validation error naming the original destination.
4. **Given** a map for `@old.example`, **When** a client creates another map for `old.example`,
   **Then** the request is refused as a conflict.
5. **Given** a map, **When** a client edits its original destination to `Info@Example.org`, **Then**
   it is stored as `info@example.org` (legacy stores edits without normalising them).
6. **Given** a domain administrator, **When** they list recipient maps, **Then** the request is
   refused as forbidden.

---

### User Story 8 - Manage forwarding hosts (Priority: P2)

An administrator lists the servers that forward mail to mailcow, for example a customer's old mail
server that still receives mail and passes it on. Mail from these hosts is accepted without blocklist
checks or greylisting and is never rejected as spam. Optionally, spam from them is still filed as
spam. The administrator adds an address, a network or a host name (which mailcow resolves), switches
the spam filter per entry and removes entries.

**Why this priority**: Without forwarding hosts, forwarded mail is greylisted or rejected, but only
some installations need them.

**Independent Test**: Add forwarding hosts by address, network and host name with known DNS records,
toggle the spam filter, remove them, and confirm that the mail filter, greylisting and connection
screening treat the senders as they do for entries created in the legacy UI.

**Acceptance Scenarios**:

1. **Given** no forwarding hosts, **When** an administrator adds `192.0.2.0/24` without stating the
   spam filter, **Then** one entry is created with the spam filter enabled, and mail from those
   addresses is accepted without blocklist checks or greylisting.
2. **Given** a name `forwarder.example` whose SPF record permits `198.51.100.0/24` and includes
   another record that permits `203.0.113.7`, **When** an administrator adds it, **Then** an entry is
   created for each address with the source `forwarder.example`, and the response marks each as
   added.
3. **Given** `203.0.113.7` listed with the source `other.example`, **When** an administrator adds
   `forwarder.example` as above, **Then** that entry gets the source `forwarder.example` and is
   reported as updated.
4. **Given** a name with no SPF, mail exchanger or address records, **When** it is added, **Then** the
   request is refused with a validation error saying the host could not be resolved.
5. **Given** the input `cafe`, **When** it is added, **Then** it is resolved as a host name and not
   stored as an IPv6 address (legacy stores it as one).
6. **Given** the input `192.0.2.5/24`, **When** it is added, **Then** it is stored as `192.0.2.0/24`.
7. **Given** an entry with the spam filter enabled, **When** the administrator disables it, **Then**
   spam from that host is accepted and delivered like other mail.
8. **Given** mailcow's shared store is unavailable, **When** an administrator adds or removes a
   forwarding host, **Then** the request is refused with a temporary failure and nothing changes.
9. **Given** five forwarding hosts, **When** an administrator disables the spam filter of all five in
   one request, **Then** each is reported done and has the spam filter disabled.

---

### User Story 9 - Trace routing changes (Priority: P3)

An administrator investigating misrouted or missing mail lists the audit log filtered by a relayhost,
transport, map, forwarding host, domain or mailbox, and sees every change, test and refused attempt:
who, when, from where and what changed, without any password.

**Why this priority**: The constitution requires the records, but routing can be managed without
reading them.

**Independent Test**: Make each kind of change, test and refused request of this feature and list the
audit log filtered by target.

**Acceptance Scenarios**:

1. **Given** an administrator who changed a relayhost's password, **When** another administrator
   lists the audit records for that relayhost, **Then** the entry shows the actor, time, client
   address and "password changed", and no password.
2. **Given** a domain administrator whose domain assignment was refused for lack of the flag, **When**
   an administrator lists the audit records for that domain, **Then** the refusal is listed with its
   reason.
3. **Given** a transport password change that affected two other transports, **When** its audit record
   is listed, **Then** it names both.
4. **Given** a domain administrator, **When** they request the audit log, **Then** the request is
   refused as forbidden.

---

### Edge Cases

- **Relayhost or transport credentials created in legacy with escaped colons** (`\:`): kept as stored
  until a new password or username is sent, because they are never returned or rewritten.
- **Password with leading or trailing spaces**: trimmed, as in legacy. The contract says so.
- **Password sent while the resulting username is empty**: refused with a validation error. Clearing
  the username without sending a password removes the password as well.
- **Several relayhosts with the same host**, for example two accounts on one smarthost: allowed, as in
  legacy.
- **Single-label hosts** such as a container name (`postfix-relay`): accepted as next hops.
- **Next hop on port 465 (implicit TLS)**: accepted, as in legacy. The contract warns that the mail
  server only supports STARTTLS, as the legacy hint does.
- **Assignment that points to a relayhost that no longer exists**, left behind by legacy or set through
  `/api/v1` without validation: reported as a dangling assignment that has no effect. It can be
  replaced or cleared.
- **Inactive relayhost assigned**: allowed, as in legacy. It has no effect until activated. For a
  mailbox, the domain's relayhost applies meanwhile.
- **Both a domain and a mailbox relayhost apply** to a sender: the mail server connects to the
  mailbox's relayhost, but looks up credentials for the domain's relayhost first, because of how
  mailcow's mail server configuration orders its lookups. This upstream behaviour is out of this API's
  reach. The contract documents it, and the mailbox's assignment shows both relayhosts.
- **Mailbox assignment and alias-domain addresses**: a mailbox's relayhost applies to its own address
  and to aliases whose only destination is the mailbox, not to its addresses in alias domains. The
  domain's relayhost applies to the domain and its alias domains. The contract documents both, as the
  legacy hint does.
- **Assignment addressed with an alias domain name or a resource address**: "not found", as in
  features 002 and 003.
- **Domain administrator with `mailbox_relayhost` only**: can read the relayhost choices and change
  mailbox assignments, but not domain assignments.
- **Transports of one next hop with different stored credentials**, for example after a manual
  database change or next hops that differ only in letter case: each transport reports what is
  stored, and the mail server uses the first active one with a username. The next credential change
  through this API applies one set of credentials to the whole next hop again.
- **Transport destination `*`**: applies to every recipient except `localhost` addresses, including
  the hosted domains, as in legacy. The contract warns about it, as the legacy hint does.
- **MX-based transport regular expression**: matched against the mail exchanger host name without
  regard to letter case, as the mail server's lookup does.
- **Test of an inactive relayhost or transport**: allowed, as in legacy.
- **Test whose next hop has several mail exchangers with the same preference**: the first one returned
  is used, as in legacy, and the result names it.
- **BCC map whose domain no longer exists**, because legacy deletion of a domain or alias domain left
  its domain-wide maps behind: listed for administrators only, flagged as orphaned, and can be edited
  and deleted. Legacy hides such maps from everyone.
- **BCC map for an address that stops existing** after creation: kept and still listed, as in legacy.
- **BCC destination equal to the local destination**: allowed, as in legacy.
- **BCC copies and spam**: copies are only sent for mail the spam filter does not reject, soft-reject
  or greylist, and sub-address tags (`user+tag@`) are ignored when maps are matched. The contract says
  so, as the legacy hint does.
- **Recipient map to an external address**: allowed, as in legacy.
- **Recipient map whose original destination is also a mailbox**: allowed; mail is rewritten before
  delivery, as in legacy.
- **TLS policy map destination with a port but without brackets** (`example.org:587`): refused,
  because the mail server never looks up such a key.
- **Forwarding host entries created in legacy in non-canonical form** (for example an expanded IPv6
  address, or a prefix with host bits): listed and addressed in their stored form, never rewritten.
  Adding an equivalent address updates that entry.
- **Forwarding host name whose SPF record includes itself or loops**: resolution stops at the limit of
  10 DNS lookups and uses what was found, or refuses the request if nothing was found.
- **Forwarding host with an empty source**, created outside the API: listed and editable.
- **DNS temporarily unavailable** while a forwarding host name is resolved: refused with a temporary
  failure, distinguishable from a name that does not resolve.
- **Version tags in a bulk action**: an item with an outdated tag is reported `outdated` and is not
  changed; items without a tag are not checked; the other items proceed.
- **Bulk action naming the same entry twice**: it is processed once and reported once.
- **Bulk action by a domain administrator naming BCC maps outside their scope**: those entries are
  reported "not found", and the others are processed.
- **Concurrent creation** of the same unique key (transport destination, BCC local destination and
  type, recipient map original destination, TLS policy destination): exactly one succeeds, the other
  is refused as a conflict.
- **Concurrent edits from the legacy UI**: the legacy UI never sends a version, so a legacy save can
  still overwrite a change made through this API, as before.
- **Audit record cannot be written**: the change it would record does not succeed.

## Requirements *(mandatory)*

### Functional Requirements

#### Access and scope

- **FR-001**: Administrators MUST be able to reach every object of this feature. Domain
  administrators MUST be able to reach only:
  - the BCC maps whose domain is in their scope: a domain assigned to them through an active
    assignment, or an alias domain of such a domain;
  - the relayhost assignments of the domains in their scope and of the mailboxes of those domains;
  - the relayhost choices (FR-023);

  each as the permission matrix (FR-005) allows. Mailbox users MUST NOT reach any operation of this
  feature.
- **FR-002**: Scope and permission flags MUST be evaluated from the current mailcow data on every
  request, as in feature 001 (FR-029 there). An assignment or flag changed in either UI MUST apply to
  the next request.
- **FR-003**: A request for a BCC map, or for the relayhost assignment of a domain or mailbox, that
  lies outside the caller's scope MUST get exactly the same "not found" response as one that does not
  exist, as in feature 002 (FR-003 there). A request for an operation that the caller's role or flags
  never allow MUST be refused as forbidden (FR-005).
- **FR-004**: A request authenticated by an API key MUST act with the key owner's role, scope and
  flags (feature 001, FR-033). A read-only key MUST be refused on every operation that changes state,
  including connection tests, with the "insufficient key scope" error of feature 001 (FR-039). A
  restricted session (feature 001, FR-014) MUST be refused.
- **FR-005**: The operations MUST be authorised as follows:

  | Operation | Administrator | Domain administrator | Mailbox user |
  |---|---|---|---|
  | Relayhosts: list, inspect, usage, create, edit, delete | Yes | Forbidden | Forbidden |
  | Relayhost choices (identifier, host, active state) | Yes | Only with the `domain_relayhost` or `mailbox_relayhost` flag | Forbidden |
  | Read a domain's relayhost assignment | All domains | Own domains, no flag needed | Forbidden |
  | Change a domain's relayhost assignment | All domains | Own domains, with the `domain_relayhost` flag | Forbidden |
  | Read a mailbox's relayhost assignment | All mailboxes | Mailboxes of own domains, no flag needed | Forbidden |
  | Change a mailbox's relayhost assignment | All mailboxes | Mailboxes of own domains, with the `mailbox_relayhost` flag | Forbidden |
  | Transports (all operations) | Yes | Forbidden | Forbidden |
  | Test a relayhost or transport | Yes | Forbidden | Forbidden |
  | List and inspect BCC maps, list BCC destination choices | All | Own domains and their alias domains, no flag needed | Forbidden |
  | Create, edit and delete BCC maps | All | Own domains and their alias domains, with the `bcc_maps` flag | Forbidden |
  | Recipient maps (all operations) | Yes | Forbidden | Forbidden |
  | TLS policy maps (all operations) | Yes | Forbidden | Forbidden |
  | Forwarding hosts (all operations) | Yes | Forbidden | Forbidden |
  | Bulk actions (FR-090) | As the single operation | BCC maps only, per entry, with the `bcc_maps` flag | Forbidden |
  | Read routing capabilities (FR-093) | All values | Bulk limit, page sizes and BCC field limits | Forbidden |
  | Read the audit log | Yes | Forbidden | Forbidden |

- **FR-006**: A refusal for a missing permission flag MUST name the flag.

#### Common rules

- **FR-007**: Every list of this feature MUST support pagination as in feature 002 (FR-011 there):
  default page size 25, maximum 1,000, and the total number of matching entries. Sorting MUST be
  available ascending and descending, ties MUST be broken by the entry's identifier, and searches
  MUST match case-insensitively as substrings. Each list entry MUST carry the full representation of
  the entry, including its version tag (FR-008), and producing a page MUST NOT require one lookup per
  entry.
- **FR-008**: Every relayhost, relayhost assignment, transport, BCC map, recipient map, TLS policy map
  and forwarding host MUST carry a version tag. The tag MUST change whenever the entry's stored values
  change, whether through this API, the legacy UI or `/api/v1`, including credential changes, and for
  a transport also when another transport changes the shared credentials of its next hop (FR-038).
  Usage counts MUST NOT change it. A client MAY send the tag it read with an edit, a replacement or a
  deletion. When it no longer matches, the request MUST be refused as outdated and MUST change
  nothing, as in feature 002 (FR-042 there).
- **FR-009**: A client MUST be able to change an entry by sending only the attributes it changes, as
  in feature 002 (FR-030 there). Attributes that are not sent MUST keep their values. A sent value MUST
  be applied or refused by the rules, never ignored. An attribute this feature defines as read-only,
  sent with a different value, MUST be refused with a validation error naming it. Sent with its
  current value, it MUST be accepted. The response MUST contain the updated entry and its new version
  tag.
- **FR-010**: Times MUST be RFC 3339 UTC timestamps. Entries for which mailcow stores no times
  (relayhosts, transports and forwarding hosts) MUST report none, and an entry that was never modified
  MUST report no modification time.
- **FR-011**: Domain names in any field of this feature MUST be normalised as in feature 002 (FR-006
  there): trimmed, lower-cased and converted to ASCII with the UTS #46 rules. The domain part of an
  e-mail address MUST be normalised the same way.

#### Credentials

- **FR-012**: Relayhost and transport passwords MUST be write-only. No response, list entry, error,
  audit record or log MUST contain a password or any part of one, including passwords stored by
  legacy. Representations MUST show the username, to administrators only, and whether a password is
  set.
- **FR-013**: Usernames and passwords MUST be trimmed of leading and trailing whitespace, as in legacy,
  and MUST each be at most 255 characters long. A username MUST NOT contain a colon. Credentials MUST
  be stored verbatim where legacy stores them, without the legacy colon escaping, so that the mail
  server uses them unchanged. A request that would leave a password without a username MUST be
  refused with a validation error.
- **FR-014**: An edit that omits the password MUST keep it. An edit that sends an empty password MUST
  remove it. An edit that sets an empty username without sending a password MUST also remove the
  password.

#### Relayhosts

- **FR-015**: Administrators MUST be able to list relayhosts. A relayhost MUST be represented with its
  identifier, its host, its username, whether a password is set, its active state, and the number of
  domains and of mailboxes assigned to it.
- **FR-016**: The relayhost list MUST support a search on the host and the username, filters on the
  active state and on whether the relayhost is in use, and sorting by identifier, host, username,
  active state, number of domains and number of mailboxes. The default MUST be by identifier,
  ascending.
- **FR-017**: Administrators MUST be able to inspect one relayhost, with its version tag.
- **FR-018**: Administrators MUST be able to create a relayhost. The host is required. The username,
  the password and the active state MAY be given; a relayhost is active unless the request says
  otherwise. A successful creation MUST respond as "created", with the address of the new relayhost.
- **FR-019**: A relayhost host, and a transport next hop, MUST be trimmed, at most 255 characters
  long, and MUST have one of the forms `host`, `host:port`, `[host]` or `[host]:port`, where:
  - `host` outside brackets is a domain name (FR-011), single labels allowed;
  - `host` inside brackets is a domain name, an IPv4 address, or an IPv6 address optionally prefixed
    with `ipv6:`;
  - `port` is a whole number from 1 to 65535.

  A bare IP address MUST be stored in brackets, as legacy does for transport next hops; a bare IPv4
  address MAY carry a port, and a bare IPv6 address is always taken as an address without a port. The
  contract MUST explain that brackets turn off the mail exchanger lookup. Several relayhosts MAY have
  the same host.
- **FR-020**: Administrators MUST be able to change a relayhost's host, username, password and active
  state (FR-009, FR-013, FR-014). The identifier and the usage counts MUST NOT be changeable.
- **FR-021**: Administrators MUST be able to delete a relayhost, whether or not it is in use. The
  deletion MUST remove the relayhost and clear every domain and mailbox assignment that refers to it,
  together and atomically. A successful deletion MUST respond with no body.
- **FR-022**: Administrators MUST be able to list the domains and mailboxes assigned to a relayhost,
  with pagination, a filter on the kind (domain or mailbox), a search on the name, and sorting by kind
  and name (default) or by name.
- **FR-023**: Administrators, and domain administrators who hold the `domain_relayhost` or the
  `mailbox_relayhost` flag, MUST be able to list the relayhost choices: every relayhost with only its
  identifier, host and active state, with pagination, a search on the host and sorting by identifier
  (default) or host. Domain administrators MUST NOT see usernames, password states or usage.

#### Relayhost assignment

- **FR-024**: Every domain and every mailbox MUST have a relayhost assignment: no relayhost, or exactly
  one. Reading an assignment MUST return the assigned relayhost's identifier, host and active state,
  or "none", and the assignment's version tag (FR-008).
- **FR-025**: A mailbox's assignment MUST also show the assignment of its domain and the effective
  relayhost for mail sent from the mailbox's own address: the mailbox's relayhost when it is set and
  active, otherwise the domain's relayhost when it is set and active, otherwise none, which means
  direct delivery.
- **FR-026**: A client MUST be able to replace an assignment with an existing relayhost's identifier
  or with "none". An identifier that does not exist MUST be refused with a validation error. An
  inactive relayhost MUST be accepted, as in legacy, and the response MUST show that it is inactive.
- **FR-027**: An assignment MUST be stored where legacy stores it (the domain record, and the
  mailbox's settings), so that the mail server applies it unchanged:
  - a domain's relayhost applies to mail sent from the domain and from its alias domains;
  - a mailbox's relayhost applies to mail sent from the mailbox's own address and from aliases whose
    only destination is the mailbox, and takes precedence over the domain's relayhost for the host it
    connects to.

  The contract MUST document both rules, and the credential lookup order described under Edge Cases.
- **FR-028**: Changing an assignment MUST change nothing else of the domain or the mailbox, apart from
  the modification time that mailcow records for every change. The domain
  and mailbox representations of features 002 and 003 MUST keep showing the assignment read-only, and
  their edit operations MUST keep refusing to change it (feature 002, FR-031). A changed assignment
  MUST count as a change of the domain or mailbox for their version tags (feature 002, FR-041; feature
  003, FR-038).
- **FR-029**: Domain administrators MUST be able to change the assignment of a domain in their scope
  only with the `domain_relayhost` flag, and of a mailbox in their scope only with the
  `mailbox_relayhost` flag. Reading needs no flag. Unlike legacy, a domain administrator's change of a
  domain's assignment MUST be saved.
- **FR-030**: An assignment that refers to a relayhost that does not exist MUST be reported as
  dangling, with the stored identifier and no effect, and MUST be replaceable and clearable.

#### Transports

- **FR-031**: Administrators MUST be able to list transports. A transport MUST be represented with its
  identifier, its destination, its next hop, whether it is MX-based, its username, whether a password
  is set, its active state, and the number of other transports that share its next hop and therefore
  its credentials.
- **FR-032**: The transport list MUST support a search on the destination, the next hop and the
  username, filters on the active state, on MX-based and on the next hop (exact, without regard to
  letter case), and sorting by identifier, destination, next hop, username, active state and MX-based.
  The default MUST be by identifier, ascending.
- **FR-033**: Administrators MUST be able to inspect one transport, with its version tag.
- **FR-034**: Administrators MUST be able to create a transport with exactly one destination. The
  destination and the next hop are required. MX-based (default: no), the active state (default:
  inactive), the username and the password MAY be given. A successful creation MUST respond as
  "created", with the address of the new transport.
- **FR-035**: A destination MUST be trimmed and at most 255 characters long, and MUST be:
  - for a transport that is not MX-based: `*`, a domain name, a domain name with a leading dot (its
    subdomains), or an e-mail address, normalised by FR-011 and lower-cased;
  - for an MX-based transport: a regular expression that the mail server's MX-based routing lookup can
    evaluate, matched against the mail exchanger host name without regard to letter case, and stored
    as given.
- **FR-036**: A destination MUST be unique among all transports, MX-based or not, compared without
  regard to letter case after normalisation. A duplicate MUST be refused as a conflict.
- **FR-037**: A next hop MUST meet FR-019. A next hop in brackets (`[h]` or `[h]:p`) MUST NOT coexist
  with the unbracketed next hop `h` on another transport, in either order, because the mail server
  looks up the credentials of `h` before those of `[h]:p`. Such a request MUST be refused as a
  conflict that names the interfering next hop.
- **FR-038**: All transports with the same next hop, compared without regard to letter case, MUST
  share one username and password:
  - a creation for an existing next hop that sends no credentials MUST inherit the next hop's
    credentials;
  - a creation for an existing next hop that sends a username different from the next hop's username
    MUST be refused as a conflict, as in legacy;
  - a creation for an existing next hop that sends a password, together with the next hop's username
    or without a username, MUST replace the password of every transport with that next hop;
  - an edit that sends a username or a password MUST apply the resulting credentials to every transport
    with the resulting next hop;
  - an edit that moves a transport to a next hop used by other transports, without sending credentials,
    MUST make it adopt their credentials. Moved to a next hop no other transport uses, it MUST keep its
    credentials.

  All changes of one request MUST be stored together. The response MUST name the other transports
  whose credentials changed.
- **FR-039**: Administrators MUST be able to change a transport's destination, next hop, MX-based
  flag, username, password and active state, validated as at creation, the transport itself excluded
  from the uniqueness and interference checks.
- **FR-040**: Administrators MUST be able to delete a transport. Only that transport MUST be removed.
  A successful deletion MUST respond with no body. Transports MUST be stored where legacy stores them,
  so that the mail server routes mail unchanged. The contract MUST state, as the legacy hint does,
  that a transport overrules relayhosts, that MX-based transports take precedence, that mailbox
  outbound TLS settings do not apply to mail routed by a transport, and that only STARTTLS is
  supported.

#### Connection tests

- **FR-041**: Administrators MUST be able to test a relayhost or a transport: the system connects to
  its next hop and sends a test message with its stored credentials, as legacy does. The request MAY
  give a sender and a recipient address. They default to server settings whose defaults are the legacy
  values `relay@example.org` and `null@hosted.mailcow.de`. A given address that is not a valid e-mail
  address MUST be refused with a validation error.
- **FR-042**: The test MUST connect as legacy does:
  - a next hop without brackets is looked up as a mail domain, and its most preferred mail exchanger is
    used; without mail exchangers, the host itself is used;
  - the port is the next hop's port, or 25;
  - STARTTLS is used when the server offers it, without verifying the server certificate, as in
    legacy; implicit TLS is not used;
  - the test logs in only when a username is stored;
  - each network step times out after 15 seconds, and the whole test ends within 60 seconds.
- **FR-043**: The test result MUST state:
  - whether the test message was accepted;
  - the step that was reached or failed: name resolution, connection, greeting, TLS, login, sender,
    recipient or message;
  - the host and port used, and whether they came from a mail exchanger lookup;
  - whether TLS was used and whether the login succeeded;
  - the last server reply;
  - the dialogue transcript, with every login command argument and server login challenge replaced by
    a redaction mark.

  A test that ran MUST be reported as a successful request, whatever its outcome. A test of an entry
  that does not exist MUST be answered "not found".
- **FR-044**: A test MUST NOT change any stored data. It MUST be treated as a state-changing request
  (FR-004) and MUST be audited (FR-085).
- **FR-045**: Inactive relayhosts and transports MUST be testable, as in legacy. The contract MUST
  state that the test connects directly, without the mail server, so that TLS policy maps and
  mailbox TLS settings do not apply to it.

#### BCC maps

- **FR-046**: Administrators and domain administrators MUST be able to list the BCC maps in their
  scope. A BCC map MUST be represented with its identifier, its type (sender or recipient), its local
  destination, its BCC destination, its domain, whether that domain still exists as a domain or alias
  domain, its active state and its creation and modification times.
- **FR-047**: The BCC map list MUST support a search on the local and the BCC destination, filters on
  the type, the active state and the domain (several values match any), and sorting by identifier,
  type, local destination, BCC destination, domain, active state, creation time and modification time.
  The default MUST be by identifier, ascending.
- **FR-048**: A client MUST be able to inspect one BCC map in its scope, with its version tag.
- **FR-049**: Administrators, and domain administrators with the `bcc_maps` flag, MUST be able to
  create a BCC map. The local destination, the type and the BCC destination are required. The active
  state MAY be given; a map is active unless the request says otherwise. A successful creation MUST
  respond as "created", with the address of the new map.
- **FR-050**: The local destination MUST be trimmed and lower-cased, and MUST then be, within the
  caller's scope:
  - a domain or an alias domain, given with or without a leading `@`, stored as `@` followed by its
    ASCII name; or
  - the address of a mailbox (not a resource); the address of an alias that is neither a catch-all
    nor a mailbox's own alias; or a mailbox's local part at one of its domain's alias domains. Each is
    stored with its domain part in ASCII.

  A local destination that does not exist and one outside the caller's scope MUST be refused with the
  same validation error.
- **FR-051**: The domain of a BCC map MUST be the domain or alias domain of its local destination, in
  ASCII. It MUST decide the map's scope, and MUST NOT be changeable.
- **FR-052**: The BCC destination MUST be exactly one valid e-mail address, at most 255 characters
  long, normalised by FR-011. It MAY be outside mailcow. A list of addresses MUST be refused with a
  validation error; the contract MUST suggest an alias for several recipients, as the legacy hint does.
- **FR-053**: There MUST be at most one BCC map per local destination and type. A duplicate, at
  creation or by an edit of the type, MUST be refused as a conflict.
- **FR-054**: Administrators, and domain administrators with the `bcc_maps` flag, MUST be able to
  change a BCC map's type, BCC destination and active state. The local destination and the domain
  MUST be read-only (FR-009).
- **FR-055**: Administrators, and domain administrators with the `bcc_maps` flag, MUST be able to
  delete a BCC map in their scope. A successful deletion MUST respond with no body.
- **FR-056**: Administrators MUST also reach BCC maps whose domain no longer exists, which FR-046
  reports as orphaned, and the BCC map list MUST offer a filter on that state. Domain administrators
  MUST NOT reach them.
- **FR-057**: Administrators and domain administrators MUST be able to list the BCC destination
  choices of their scope: the domains, alias domains, mailboxes, aliases and mailbox addresses in
  alias domains that FR-050 accepts. Each choice MUST show its kind, its value as it would be stored,
  its domain, and for aliases and alias-domain addresses the mailboxes in scope that it delivers to.
  The list MUST support pagination, filters on the kind and the domain, a search on the value, and
  sorting by kind and value (default).
- **FR-058**: BCC maps MUST be stored where legacy stores them, so that mailcow's spam filter sends
  the copies unchanged. The contract MUST state that copies are sent only for mail the spam filter
  does not reject, soft-reject or greylist, that sub-address tags are ignored when matching, that a
  domain-wide map matches every address in the domain, and that the local destination is not told
  about failed copies.

#### Recipient maps

- **FR-059**: Administrators MUST be able to list recipient maps. A recipient map MUST be represented
  with its identifier, its original destination, its new destination, its active state and its
  creation and modification times. The list MUST support a search on both destinations, a filter on
  the active state, and sorting by identifier (default, ascending), original destination, new
  destination, active state, creation time and modification time.
- **FR-060**: Administrators MUST be able to inspect one recipient map, with its version tag.
- **FR-061**: Administrators MUST be able to create a recipient map. The original and the new
  destination are required. The active state MAY be given; a map is active unless the request says
  otherwise. Each destination MUST be trimmed, lower-cased and stripped of a leading `@`, and MUST then
  be a domain name, stored as `@` followed by its ASCII name, or a valid e-mail address with its domain
  part in ASCII. Each MUST be at most 255 characters long once stored. A successful creation MUST
  respond as "created", with the address of the new map.
- **FR-062**: The original destination MUST be unique among recipient maps. A duplicate, at creation
  or by an edit, MUST be refused as a conflict.
- **FR-063**: Administrators MUST be able to change a recipient map's original destination, new
  destination and active state, with the rules of FR-061.
- **FR-064**: Administrators MUST be able to delete a recipient map. A successful deletion MUST respond
  with no body. Recipient maps MUST be stored where legacy stores them, so that the mail server
  rewrites envelope recipients unchanged.

#### TLS policy maps

- **FR-065**: Administrators MUST be able to list TLS policy maps. An entry MUST be represented with
  its identifier, its destination, its policy, its parameters, its active state and its creation and
  modification times. The list MUST support a search on the destination and the parameters, filters
  on the policy (several values match any) and the active state, and sorting by identifier (default,
  ascending), destination, policy, active state, creation time and modification time.
- **FR-066**: Administrators MUST be able to inspect one TLS policy map, with its version tag.
- **FR-067**: Administrators MUST be able to create a TLS policy map. The destination and the policy
  are required. The parameters MAY be given (default: none). The active state MAY be given; an entry is
  active unless the request says otherwise. A successful creation MUST respond as "created", with the
  address of the new entry.
- **FR-068**: A destination MUST be trimmed, lower-cased and converted to ASCII (FR-011), at most 255
  characters long, and MUST be a domain name, a domain name with a leading dot, or a next hop in
  brackets with an optional port (`[host]` or `[host]:port`, host as in FR-019). `.`, `*` and `@` MUST
  be refused. A destination MUST be unique among TLS policy maps; a duplicate, at creation or by an
  edit, MUST be refused as a conflict.
- **FR-069**: The policy MUST be one of `none`, `may`, `encrypt`, `dane`, `dane-only`, `fingerprint`,
  `verify` and `secure`, accepted in any letter case and stored in lower case.
- **FR-070**: The parameters MUST be empty, or a list of `name=value` items separated by whitespace,
  each with a non-empty name and value. Runs of whitespace MUST be stored as single spaces. The stored
  parameters MUST be at most 255 characters long.
- **FR-071**: Administrators MUST be able to change a TLS policy map's destination, policy, parameters
  and active state, with the rules of FR-068 to FR-070.
- **FR-072**: Administrators MUST be able to delete a TLS policy map. A successful deletion MUST respond
  with no body.
- **FR-073**: TLS policy maps MUST be stored where legacy stores them, so that the mail server applies
  them unchanged. The contract MUST state that an entry overrides both the mailbox TLS settings and
  mailcow's automatic TLS policy service for its destination, and MUST link the policy and parameter
  documentation of the mail server, as the legacy hint does.

#### Forwarding hosts

- **FR-074**: Administrators MUST be able to list forwarding hosts. An entry MUST be represented with
  its address (an IP address or prefix, in its stored form), its source (what the administrator
  entered when it was added) and whether its spam filter is enabled. The list MUST support a search on
  the address and the source, filters on the spam filter and on the source (exact), and sorting by
  address (default, ascending), source and spam filter.
- **FR-075**: Administrators MUST be able to inspect one forwarding host by its stored address, with
  its version tag.
- **FR-076**: Administrators MUST be able to add a forwarding host. The host is required. The spam
  filter MAY be given; it is enabled unless the request says otherwise. The host MUST be trimmed and
  MUST be:
  - an IPv4 or IPv6 address or prefix, which gives one entry; or
  - a domain name (FR-011), which MUST be resolved as legacy does: the hosts its SPF record permits
    (following `include` and `redirect`, and using `a`, `mx`, `ip4` and `ip6` with their prefix
    lengths, where a mechanism with a fail or softfail qualifier ends the evaluation of its record),
    with at most 10 DNS lookups in total; when that gives no address, the addresses of its mail
    exchangers; when that gives none, its own IPv4 and IPv6 addresses.

  A name that resolves to no address MUST be refused with a validation error. A temporary DNS failure
  MUST be refused with a temporary-failure error.
- **FR-077**: New addresses MUST be stored in canonical form: IPv6 in compressed lower-case notation,
  and prefixes with their host bits cleared. The source MUST be the trimmed host as entered.
- **FR-078**: An address that is not yet listed MUST be added. An address equal to a listed entry, in
  canonical comparison, MUST update that entry's source and spam filter setting, as in legacy, and keep
  its stored form. All addresses of one request MUST be stored together or not at all. The response
  MUST list every entry with "added" or "updated". When at least one entry was added, it MUST respond
  as "created" and point to the forwarding host list filtered by that source; otherwise it MUST
  respond with the updated entries.
- **FR-079**: Administrators MUST be able to enable or disable the spam filter of a forwarding host.
  Its address and source MUST be read-only (FR-009).
- **FR-080**: Administrators MUST be able to delete a forwarding host. The deletion MUST remove the
  address and its keep-spam setting together. A successful deletion MUST respond with no body.
- **FR-081**: Forwarding hosts MUST be stored where legacy stores them, in mailcow's shared store, so
  that the mail filter, greylisting and connection screening treat them as legacy entries. The contract
  MUST describe their effect as the legacy hint does: mail from them is accepted unconditionally,
  skips blocklist checks and greylisting, and is never rejected as spam; with the spam filter disabled,
  spam from them is delivered like other mail.

#### Reliability

- **FR-082**: When mailcow's shared store is unavailable, forwarding host operations MUST be refused
  with a temporary-failure error and MUST leave no partial change.
- **FR-083**: The stored changes of one request MUST succeed or fail together, in particular a
  relayhost deletion with its cleared assignments (FR-021) and a transport change with its credential
  propagation (FR-038).
- **FR-084**: The uniqueness rules of FR-036, FR-053, FR-062 and FR-068 MUST also hold under concurrent
  requests.

#### Audit

- **FR-085**: Every request of this feature that changes state, runs a test, or tries to, MUST write
  exactly one audit record per targeted entry (one for a single request, one per named entry for a
  bulk action) into feature 001's audit log, with the fields of FR-046 there and:
  - the target type (relayhost, domain relayhost assignment, mailbox relayhost assignment, transport,
    BCC map, recipient map, TLS policy map or forwarding host) and the target identifier;
  - the outcome, including refusals and their reason, and for a bulk action the bulk request
    identifier;
  - for successful changes, each changed attribute with its old and new value. Passwords MUST be
    recorded only as "set", "changed" or "removed";
  - for a relayhost deletion, the domains and mailboxes whose assignment was cleared;
  - for transport changes, the other transports whose credentials changed;
  - for a test, the sender, the recipient, the outcome and the step reached, without the transcript;
  - for a forwarding host creation, each address with "added" or "updated".
- **FR-086**: Administrators MUST be able to filter the audit log by the target types of this feature
  and by target identifier, as feature 002 adds for its targets (FR-058 there). A relayhost assignment
  MUST also be found by its domain or mailbox.

#### Cross-cutting

- **FR-087**: Errors MUST follow feature 001's conventions (FR-049 and FR-055 there). This feature MUST
  document at least these error types: entry already exists, next hop has different credentials, next
  hop interferes, outdated version, attribute not permitted, missing permission flag, temporary
  failure, and host not resolvable. Validation errors MUST use stable rule codes with their
  parameters, for example the allowed policies or the maximum length.
- **FR-088**: Every operation MUST be described in the published API contract before it is
  implemented, and its behaviour MUST match the contract.
- **FR-089**: Changes made through the legacy UI or `/api/v1` MUST be visible in the next response of
  this API.
- **FR-090**: Administrators, and for BCC maps the domain administrators that FR-005 allows, MUST be
  able to apply one of these actions to up to 100 entries in one request, as the legacy mass actions
  do:
  - relayhosts, transports, recipient maps and TLS policy maps: activate, deactivate, delete;
  - BCC maps: activate, deactivate, switch to sender, switch to recipient, delete;
  - forwarding hosts: enable the spam filter, disable the spam filter, delete.

  A request with more than 100 entries MUST be refused with a validation error that states the
  limit. Creation MUST NOT be offered as a bulk action.
- **FR-091**: A bulk action MUST be refused as a whole when the caller's role, key scope or flags do
  not allow the action. Otherwise each named entry MUST be processed on its own, with the same
  checks, side effects and audit record as a single request for it, and the response MUST report one
  result per distinct entry: done, not found, refused (with the error type), outdated, or failed
  (with the error type). A failure on one entry MUST NOT stop or undo the others, as in legacy. Each
  named entry MAY carry the version tag the client read. An entry whose tag no longer matches MUST be
  reported as outdated and MUST NOT be changed; an entry without a tag MUST NOT be version-checked.
- **FR-092**: No operation of this feature MUST restart or reload a mailcow service, as in legacy: the
  mail server and the mail filter read the stored routing data on each message.

#### Routing capabilities

- **FR-093**: Administrators and domain administrators MUST be able to read the routing capabilities,
  the server settings and limits a client needs before it submits:
  - for both roles: the bulk limit (FR-090), the default and maximum page sizes (FR-007), and the BCC
    map field limits (maximum length of the local and the BCC destination, FR-050, FR-052);
  - for administrators only: the default test sender and recipient (FR-041), the test timeouts
    (FR-042), the maximum lengths of hosts, next hops, usernames, passwords, destinations and TLS
    policy parameters (FR-013, FR-019, FR-035, FR-061, FR-068, FR-070), the allowed TLS policies
    (FR-069), and the forwarding host DNS lookup limit (FR-076).

  The values MUST reflect the server's current configuration and MUST NOT include any secret. The
  read MUST change nothing, and read-only keys MAY use it.

### Legacy Coverage

Every legacy capability allocated to feature 009 in `docs/roadmap.md` maps to requirements as follows.
Actions are `/api/v1` names; *form* marks legacy UI forms and *ajax* the legacy UI's internal scripts.

| Legacy action or form | Legacy implementation | Requirements |
|---|---|---|
| `add/relayhost`, *form* "Add sender-dependent transport" | `relayhost('add')`, `admin/tab-routing.twig` | FR-012 to FR-014, FR-018, FR-019, FR-085 |
| `get/relayhost/all`, `get/relayhost/{id}`, relayhost table with "in use by" | `relayhost('get' / 'details')`, `draw_relayhosts()` in `admin.js` | FR-012, FR-015 to FR-017, FR-022 |
| `edit/relayhost`, *form* edit relayhost page, mass activate and deactivate | `relayhost('edit')`, `edit/relayhost.twig` | FR-009, FR-012 to FR-014, FR-020, FR-090, FR-091 |
| `delete/relayhost`, single and mass | `relayhost('delete')` | FR-021, FR-083, FR-090, FR-091 |
| *ajax* relayhost test ("Test" button, test transport dialog with `transport_type=sender-dependent`) | `inc/ajax/transport_check.php`, `modals/admin.twig` | FR-041 to FR-045 |
| `add/transport`, *form* "Add transport" | `transport('add')`, `admin/tab-routing.twig` | FR-034 to FR-038 |
| `get/transport/all`, `get/transport/{id}`, transport table | `transport('get' / 'details')`, `draw_transport_maps()` | FR-012, FR-031 to FR-033 |
| `edit/transport`, *form* edit transport page, mass activate and deactivate | `transport('edit')`, `edit/transport.twig` | FR-009, FR-038, FR-039, FR-090, FR-091 |
| `delete/transport`, single and mass | `transport('delete')` | FR-040, FR-090, FR-091 |
| *ajax* transport check (test transport dialog with `transport_type=transport-map`) | `inc/ajax/transport_check.php` | FR-041 to FR-045 |
| Relayhost assignment to domains: `edit/domain` field `relayhost`, relayhost selector on the Edit domain page | `mailbox('edit', 'domain')`, `edit/domain.twig` | FR-023 to FR-030 |
| Relayhost assignment to mailboxes: `edit/mailbox` field `relayhost`, relayhost selector on the Edit mailbox page | `mailbox('edit', 'mailbox')`, `edit/mailbox.twig` | FR-023 to FR-030 |
| Domain administrator flags `domain_relayhost` and `mailbox_relayhost` | `da_acl`, `hasACLAccess()` | FR-005, FR-006, FR-023, FR-029 |
| Relayhost list for the selectors | `relayhost('get')` in `edit.php` | FR-023 |
| `add/fwdhost`, *form* "Add forwarding host" | `fwdhost('add')`, `inc/spf.inc.php`, `admin/tab-config-fwdhosts.twig` | FR-076 to FR-078, FR-081, FR-082 |
| `get/fwdhost/all`, `get/fwdhost/{host}`, forwarding hosts table | `fwdhost('get' / 'details')`, `draw_fwd_hosts()` | FR-074, FR-075 |
| `edit/fwdhost` (`keep_spam`), mass "Enable / Disable spam filter" | `fwdhost('edit')` | FR-079, FR-090, FR-091 |
| `delete/fwdhost`, single and mass | `fwdhost('delete')` | FR-080, FR-082, FR-090, FR-091 |
| `add/bcc`, *form* "Add BCC map" dialog | `bcc('add')`, `modals/mailbox.twig` | FR-049 to FR-053, FR-058 |
| `get/bcc/all`, `get/bcc/{id}`, BCC table | `bcc('get' / 'details')`, `draw_bcc_table()` in `mailbox.js` | FR-046 to FR-048, FR-056 |
| `edit/bcc`, *form* edit BCC page, mass activate, deactivate, "BCC to sender", "BCC to recipient" | `bcc('edit')`, `edit/bcc.twig` | FR-053, FR-054, FR-090, FR-091 |
| `delete/bcc`, single and mass | `bcc('delete')` | FR-055, FR-090, FR-091 |
| `get/bcc-destination-options` | `json_api.php` case, local destination selector | FR-057 |
| Domain administrator flag `bcc_maps` | `da_acl`, `$_SESSION['acl']['bcc_maps']` | FR-005, FR-049, FR-054, FR-055 |
| `add/recipient_map`, *form* "Add recipient map" dialog | `recipient_map('add')` | FR-061, FR-062, FR-064 |
| `get/recipient_map/all`, `get/recipient_map/{id}`, recipient map table | `recipient_map('get' / 'details')`, `draw_recipient_map_table()` | FR-059, FR-060 |
| `edit/recipient_map`, *form* edit recipient map page, mass activate and deactivate | `recipient_map('edit')`, `edit/recipient_map.twig` | FR-062, FR-063, FR-090, FR-091 |
| `delete/recipient_map`, single and mass | `recipient_map('delete')` | FR-064, FR-090, FR-091 |
| `add/tls-policy-map`, *form* "Add TLS policy map" dialog | `tls_policy_maps('add')` | FR-067 to FR-070, FR-073 |
| `get/tls-policy-map/all`, `get/tls-policy-map/{id}`, TLS policy map table | `tls_policy_maps('get' / 'details')`, `draw_tls_policy_table()` | FR-065, FR-066 |
| `edit/tls-policy-map`, *form* edit TLS policy map page, mass activate and deactivate | `tls_policy_maps('edit')`, `edit/tls_policy_map.twig` | FR-068 to FR-071, FR-090, FR-091 |
| `delete/tls-policy-map`, single and mass | `tls_policy_maps('delete')` | FR-072, FR-090, FR-091 |
| Server settings for the forms (test placeholders `relay@example.org` and `null@hosted.mailcow.de`, policy list of the TLS form) | `modals/admin.twig`, `edit.php` | FR-093 |

Not covered, because it is dead code: the `#testRelayhostModal` handler in `admin.js` calls
`inc/ajax/relay_check.php`, which no longer exists and has no button; the relayhost test uses
`transport_check.php` (row above). Parts of the same legacy screens that belong to other features:
the outbound and inbound TLS settings of a mailbox (`edit/tls_policy`, 005), the quota warning BCC
(013), the mail queue (011), and the editing of domain administrator flags (007).

### Legacy Parity and Deliberate Deviations

Parity reference: `source_code/data/web/inc/functions.transports.inc.php` (`relayhost()`,
`transport()`), `functions.address_rewriting.inc.php` (`bcc()`, `recipient_map()`),
`functions.tls_policy_maps.inc.php`, `functions.fwdhost.inc.php`, `inc/spf.inc.php`,
`inc/ajax/transport_check.php`, `functions.mailbox.inc.php` (`relayhost` in `add` / `edit` for
`domain` and `mailbox`, `shared_aliases`, `direct_aliases`), `functions.inc.php` (`hasDomainAccess`,
`hasMailboxObjectAccess`, `hasAliasObjectAccess`, `hasACLAccess`, `is_valid_domain_name`),
`functions.acl.inc.php` (`to_session`), `json_api.php`, `edit.php`, `init_db.inc.php` (tables
`relayhosts`, `transports`, `bcc_maps`, `recipient_maps`, `tls_policy_override`, `forwarding_hosts`,
`domain.relayhost`, `mailbox.attributes.relayhost`, `da_acl`), the templates
`admin/tab-routing.twig`, `admin/tab-config-fwdhosts.twig`, `mailbox/tab-bcc.twig`,
`mailbox/tab-tls-policy.twig`, `edit/{relayhost,transport,bcc,recipient_map,tls_policy_map,domain,mailbox}.twig`,
`modals/{admin,mailbox}.twig`, `js/site/{admin,mailbox}.js`, `lang/lang.en-gb.json`; the consumers
`Dockerfiles/postfix/postfix.sh` (SQL maps), `conf/postfix/{main,master}.cf`,
`Dockerfiles/postfix/whitelist_forwardinghosts.sh`, `conf/rspamd/dynmaps/{bcc,forwardinghosts}.php`,
`conf/rspamd/lua/rspamd.local.lua` (BCC and `KEEP_SPAM`) and `conf/rspamd/local.d/{multimap,greylist,force_actions}.conf`.

The reference is upstream mailcow-dockerized `2026-09a`. `source_code/` points to the FeldHost fork
(`~/Projects/mailhosting`, branch `feldhost-202609a`), so the files above were diffed against that
upstream tag. All routing and rewriting logic, the templates, the JavaScript, the schema of these
tables and the Postfix and Rspamd consumers are identical. The fork differs only in display texts with
no effect on behaviour: the forwarding host hint in the language files says "your server" instead of
"your mailcow server", and the examples of `web/api/openapi.yaml` use `feldhost.tld` and the product
name. There is no fork-only routing capability to make configurable.

| Behaviour | Legacy | This feature |
|---|---|---|
| Who may manage relayhosts, transports, recipient maps, TLS policy maps, forwarding hosts and tests | Administrators only | Same (FR-005) |
| Relayhost list for domain administrators | Every domain administrator reads all relayhosts with usernames (`relayhost('get')`) | Only with a relayhost flag, without usernames (FR-023). **Deviation** |
| Relayhost and transport passwords in responses | Returned in clear text by `get/relayhost`, `get/transport` and shown in the edit forms; `password_short` shows the first 3 characters | Never returned; "password set" instead (FR-012). **Deviation** (constitution VI) |
| Credential storage | Clear text in `relayhosts` and `transports`. `:` escaped as `\:` on creation (twice for relayhost passwords, never on edit), which the mail server does not unescape | Clear text, verbatim; usernames with `:` refused (FR-013). **Deviation** (bug fix) |
| Credential use | Postfix `smtp_sasl_password_maps` (`mysql_sasl_passwd_maps_sender_dependent.cf`, `CONCAT_WS(':', username, password)`, only when `username != ''`) and the `smtp_via_transport_maps` service (`mysql_sasl_passwd_maps_transport_maps.cf`, by next hop) | Same storage, same effect (FR-013, FR-027, FR-038) |
| Relayhost host syntax | Only non-empty; stored as typed | Next hop syntax, bare IPs bracketed (FR-019). **Deviation** |
| Relayhost creation side effects | `relayhosts` row, always active | Same; active by default, may be created inactive (FR-018) |
| Relayhost deletion | Row deleted, `domain.relayhost` reset to `'0'`; `mailbox.attributes.relayhost` left pointing to the deleted id | Domains and mailboxes cleared atomically (FR-021). **Deviation** |
| Relayhost usage | `used_by_domains`, `used_by_mailboxes` as comma-joined lists | Counts plus a paginated usage list (FR-015, FR-022) |
| Domain assignment by administrators | `edit/domain` stores any integer in `domain.relayhost`, no existence check | Existing relayhost or none (FR-026). **Deviation** |
| Domain assignment by domain administrators | Value computed with `domain_relayhost` but never written | Saved with the flag, refused without it (FR-029). **Deviation** (bug fix) |
| Mailbox assignment | `edit/mailbox` writes `attributes.relayhost` (string id, `"0"` for none) with `mailbox_relayhost`; other values silently ignored; no existence check | Same storage; refused without the flag; existing relayhost or none (FR-026, FR-029). **Deviation** |
| Assignment side effects | Domain edit runs `update_sogo_static_view()` and flushes memcached; mailbox edit does the same and resets `attributes.attribute_hash` | Only the assignment changes (FR-028). **Deviation**: the groupware directory does not contain relayhosts, and resetting the identity-provider marker would make the next provider sync re-apply unrelated template values |
| Effect of an assignment | `sender_dependent_default_transport_maps` (`mysql_sender_dependent_default_transport_maps.cf`): mailbox relayhost for the mailbox and its direct aliases, else domain relayhost for the domain and its alias domains, active relayhosts only; credentials looked up domain first | Same, documented; effective relayhost shown (FR-025, FR-027) |
| Transport destinations | Split on space, comma, semicolon and newline; invalid or existing ones skipped with a message, the rest inserted; validated only when other transports exist | One destination per request, always validated, normalised (FR-034 to FR-036). **Deviation** |
| Transport next hop | Only non-empty; bare IPs bracketed; `[h]` and `h` may not coexist (`next_hop_interferes`) | Same rules plus syntax (FR-019, FR-037). **Deviation** for syntax |
| Transport credentials | Shared per next hop: creation with another username refused (`invalid_nexthop_authenticated`), creation without a password wipes the shared password, edits propagate to all, a moved transport overwrites the target's credentials, an empty username clears the password | Shared per next hop, inheritance and adoption instead of wiping and overwriting (FR-038). **Deviation** (bug fix) |
| Transport default state | Server stores inactive when omitted; the form pre-selects inactive | Inactive (FR-034) |
| Transport effect | Postfix `transport_maps` (`mysql_transport_maps.cf`, after `custom_transport.pcre`, `local_transport` and `mysql_relay_ne.cf`); MX-based via `check_recipient_mx_access` (`mysql_mbr_access_maps.cf`, `REGEXP`) | Same storage, same effect (FR-035, FR-040, FR-092) |
| Connection test | PHP connects directly from the web container (no dockerapi), MX lookup without brackets, port 25 default, STARTTLS without certificate checks, 15 s timeout, sends a real message; HTML transcript at debug level 3 including the base64 login exchange; invalid addresses silently replaced by defaults; admin only | Same connection behaviour from the API's container; structured result with redacted transcript; invalid addresses refused; audited (FR-041 to FR-045). **Deviation** |
| BCC map access | Read for every domain administrator in scope; writes need `bcc_maps`; maps whose domain no longer exists are invisible even to administrators (`hasDomainAccess`) | Same for domain administrators; administrators also reach orphaned maps, which are flagged (FR-005, FR-046, FR-056). **Deviation** |
| BCC local destination | Domain or alias domain in scope (stored `@domain`), or a mailbox, a direct or shared alias in scope; alias-domain addresses offered by the choices but refused; an unknown address gives `access_denied` | Same, plus mailbox alias-domain addresses; one error for unknown and out-of-scope (FR-050). **Deviation** |
| BCC destination | `FILTER_VALIDATE_EMAIL`, not normalised | Same, domain part normalised (FR-052) |
| BCC uniqueness | One map per `local_dest` and `type`, also checked when the type changes | Same, also under concurrency (FR-053, FR-084) |
| BCC default state | Server stores inactive when omitted; the form pre-selects active | Active (FR-049). **Deviation** from the server, matches the form |
| BCC effect | Rspamd prefilter queries the internal `bcc.php` per message (address and `@domain`, tags stripped), only for actions no action, add header, rewrite subject or a pre-result accept, and sends the copy through Postfix port 591; Postfix `*_bcc_maps` are disabled | Same storage, same effect (FR-058) |
| BCC destination choices | `get/bcc-destination-options`: every domain, alias domain, mailbox and its aliases in one response | Paginated and searchable, consistent with what creation accepts (FR-057). **Deviation** |
| Recipient maps | Admin only; creation trims, lower-cases, strips `@`, stores domains as `@` + ASCII; edits skip trimming and lower-casing; duplicates of `old_dest` refused; default inactive when omitted, the form pre-selects active | Same rules on creation and edit; default active (FR-061 to FR-063). **Deviation** for edits and the default |
| Recipient map effect | Postfix `recipient_canonical_maps` (`mysql_recipient_canonical_maps.cf`, envelope recipients) | Same (FR-064) |
| TLS policy maps | Admin only; creation converts the destination with UTS #46 and lower-cases the policy; edits do neither; the policy is not checked against the list; parameters must be `name=value` tokens; duplicates refused (on edit with the recipient map message); default inactive when omitted, the form pre-selects active | Same rules on creation and edit, policy checked, destination forms checked, default active (FR-067 to FR-071). **Deviation** |
| TLS policy effect | Postfix `smtp_tls_policy_maps`: `mysql_tls_policy_override_maps.cf` (`CONCAT(policy, ' ', parameters)`) before the `postfix-tlspol` socketmap | Same (FR-073) |
| Forwarding hosts storage | Redis `WHITELISTED_FWD_HOST` (address → source) and `KEEP_SPAM` (address → 1); the SQL table `forwarding_hosts` is unused | Same keys; the table stays unused (FR-081) |
| Forwarding host input | Strings matching `[0-9a-fA-F:/]+` or `[0-9./]+` stored as typed (so `cafe` is an IPv6 address); other input resolved through SPF, MX, then A/AAAA, without a lookup limit; source stored untrimmed | Valid literals only, canonical form, SPF lookup limit of 10, trimmed source (FR-076, FR-077). **Deviation** |
| Forwarding host creation | Existing addresses overwritten; a Redis error stops half-way; spam filter disabled when omitted, the form pre-selects enabled | Overwrite kept and reported; all or nothing; enabled by default (FR-078, FR-082). **Deviation** |
| Forwarding host effect | Rspamd multimap `WHITELISTED_FWD_HOST` and `force_actions`, greylisting `whitelisted_ip` through `forwardinghosts.php`, Postfix `postscreen_access_list` through `whitelist_forwardinghosts.sh` (`tcp:127.0.0.1:10027`), Rspamd `KEEP_SPAM` prefilter matching exact addresses and network-base prefixes | Same (FR-081) |
| Server settings for the forms | Hard-coded in the templates (test placeholders, TLS policy list); never readable | Readable routing capabilities (FR-093), an addition |
| Out-of-scope objects | Empty result or `access_denied` with status 200 | "Not found" (FR-003) |
| Mass actions | One request with a list of items; processing continues past failures, with one message per item | Bulk actions of up to 100 entries with one result and one audit record per entry, and optional per-entry version tags (FR-090, FR-091). **Deviation** for the limit, the per-entry results and the version checks |
| Concurrent edits | Last write wins | Version tags (FR-008). **Deviation** |
| Change log | Entries in the `logs` table, with the request data including passwords | This API's audit log without passwords (FR-085). **Deviation** |
| Service reloads and other side effects | None: Postfix reads the tables through `proxy:mysql` on each lookup, Rspamd reads Redis and the internal endpoints per message or map refresh; no dockerapi or memcached calls | Same (FR-092) |

### Key Entities *(include if feature involves data)*

- **Relayhost** (existing mailcow data, legacy "sender-dependent transport"): an SMTP next hop with an
  identifier, a host, an optional username and password, and an active state. Domains and mailboxes
  refer to it by identifier.
- **Relayhost assignment** (existing mailcow data, part of a domain or a mailbox): the relayhost a
  domain or mailbox sends through, or none. For a mailbox, it is shown with the domain's assignment
  and the effective relayhost.
- **Relayhost choice** (computed): a relayhost reduced to identifier, host and active state, for
  selectors.
- **Transport** (existing mailcow data): a destination (address, domain, subdomains, `*`, or a mail
  exchanger pattern), a next hop, an MX-based flag, an active state and the credentials of its next
  hop.
- **Next hop credentials** (existing mailcow data, repeated on each transport): one username and
  password shared by all transports with the same next hop.
- **Connection test result** (not stored): outcome, step reached, host and port, TLS and login state,
  last reply and redacted transcript of one test.
- **BCC map** (existing mailcow data): a local destination, a type (sender or recipient), one BCC
  destination, a domain that decides its scope, an active state and creation and modification times.
- **BCC destination choice** (computed): a domain, alias domain, mailbox, alias or alias-domain address
  that the caller may use as a local destination.
- **Recipient map** (existing mailcow data): an original destination, a new destination, an active
  state and creation and modification times.
- **TLS policy map** (existing mailcow data): a destination, a policy, parameters, an active state and
  creation and modification times.
- **Forwarding host** (existing mailcow data in the shared store): an IP address or prefix, its source
  and its spam filter setting.
- **Routing capabilities** (computed from the server configuration): the default test addresses,
  timeouts, limits and allowed values a client needs before it submits.
- **Audit record** (owned by this API, defined in feature 001): extended with the target types of this
  feature, attribute changes without passwords, affected entries and test outcomes.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For 100% of the entries in the parity test set, the attributes returned by the API equal
  those the legacy UI shows for the same data, apart from passwords, which are reported only as set
  or not set.
- **SC-002**: An entry created or changed through the API with the same input as through the legacy UI
  leaves identical stored data and shared-store entries, apart from the stated deviations, and a test
  message is routed, rewritten, copied or accepted identically.
- **SC-003**: A scan of every response, audit record and service log of the full test run finds none
  of the relayhost or transport passwords used in the tests.
- **SC-004**: 100% of attempts by domain administrators to reach a BCC map or relayhost assignment
  outside their scope get the same response as for an entry that does not exist; 100% of their
  changes without the required flag, and 100% of attempts by mailbox users, are refused.
- **SC-005**: A routing change made through the API applies to the next message handled by the mail
  server, within 1 minute and without restarting any service.
- **SC-006**: In 95% of requests, a page of 25 entries is returned within 1 second for collections of
  10,000 relayhosts, transports, BCC maps, recipient maps, TLS policy maps or forwarding hosts.
- **SC-007**: 100% of connection tests report a result within 60 seconds, including tests of
  unreachable or silent next hops.
- **SC-008**: Every request in FR-085 produces exactly one audit record per targeted entry.
- **SC-009**: 100% of changes sent with an outdated version tag are refused, and no change sent with a
  current version tag overwrites a change it did not see.
- **SC-010**: An administrator can add a relayhost, assign it to a domain and run a successful test in
  the new web app in under 3 minutes.
- **SC-011**: 100% of refused creations and edits name every invalid field with a stable rule code, so
  that the web app can show each error next to its field.
- **SC-012**: Each routing capability equals the server's configuration in 100% of test runs, and a
  changed setting is reflected on the next request.

## Assumptions

- Domain administrator accounts, their domain assignments and their permission flags are managed in
  feature 007. This feature only reads them.
- Domains (002), mailboxes (003), aliases and alias domains (004) are managed in their features. This
  feature reads them for scope checks, BCC destination choices and relayhost usage, and writes only
  the relayhost assignment of domains and mailboxes.
- Feature 002 shows a domain's relayhost read-only "until feature 009". This feature satisfies that by
  the assignment operations; the domain and mailbox representations keep the field read-only.
- The mail server and the mail filter read the routing tables and shared-store keys directly on each
  message or map refresh, so no reload is needed, as in legacy.
- Connection tests start from this API's own container. Its outbound network and DNS resolution are
  assumed to be the same as those of the legacy web container, which runs the legacy test and resolves
  forwarding host names.
- The credential lookup order for senders with both a domain and a mailbox relayhost is a property of
  mailcow's mail server configuration. Changing it needs an upstream change and is out of scope.
- The legacy UI never sends version tags, so it can still overwrite changes made through this API.
- These defaults can be changed by the operator: the test sender (`relay@example.org`) and recipient
  (`null@hosted.mailcow.de`), and the list page size (25, maximum 1,000). Clients read them through
  the routing capabilities (FR-093).
- **Out of scope**:
  - the global relayhost and custom transport files of the mail server configuration, which the
    legacy UI does not manage;
  - mailbox TLS settings (005), quota warning BCC (013), the mail queue (011), sender permissions
    (004);
  - editing domain administrator permission flags (007);
  - testing a next hop or credentials that are not saved, which legacy does not offer either;
  - creating entries in bulk.
- **Dependencies**:
  - the upstream mailcow database schema of the supported release;
  - mailcow's shared store, for forwarding hosts;
  - DNS resolution, for forwarding host names and connection tests;
  - outbound SMTP connectivity, for connection tests;
  - feature 001, for identity, roles, permission flags, API keys, errors and the audit log;
  - feature 002, for the conventions this feature reuses and the domain version tag.
