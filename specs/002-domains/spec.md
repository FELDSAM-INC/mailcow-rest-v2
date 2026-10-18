# Feature Specification: Domains

**Feature Branch**: `main` (no feature branches, constitution 1.1.0)

**Created**: 2026-10-18

**Status**: Draft

**Input**: Feature description built from `docs/roadmap.md` (feature 002 and allocation row 002):

> Domains for mailcow REST API v2: administrators create, list, search, inspect, edit and delete the
> mail domains of an existing mailcow-dockerized server, including their limits (maximum mailboxes
> and aliases), quotas (default and maximum mailbox quota, total domain quota), relay and backup MX
> options, Global Address List flag, activation state, description and tags. Administrators manage
> domain templates that pre-fill new domains. Administrators and domain administrators manage the
> domain-wide footer. Domain administrators see, and partly edit, only the domains they manage, as
> their permission flags allow. Mailbox users have no access.
>
> Allocated legacy surface: `domain` add/get/edit/delete, `domain/template` add/get/edit/delete,
> `edit/domain/footer`, `delete/domain/tag`, domain quotas and limits, relay/backup MX flags, GAL,
> domain tags. The legacy "Mail setup › Domains" and "Edit domain" screens need list filters,
> sorting, search and the usage figures they show today.
>
> Constraints: every legacy side effect is reproduced, so Postfix, Dovecot, Rspamd and SOGo behave
> the same whichever implementation made the change. The legacy UI and `/api/v1` keep working on
> the same data. The feature builds on feature 001's identity, roles, permission flags, API keys,
> error conventions and audit log.

## Clarifications

### Session 2026-10-18

- Q: When a domain administrator asks for a domain, or its footer, that exists but is not one of
  theirs, should the API answer "not found" or "forbidden"? → A: "Not found", exactly as for a domain
  that does not exist. "Forbidden" is used only when the caller's role may never use the operation,
  for example a mailbox user listing domains or a domain administrator creating one. (agent
  decision: constitution VI requires that a guessed name reveals nothing, and OWASP API1 recommends
  not confirming the existence of objects outside the caller's scope. Legacy answers with an empty
  result, which reveals nothing either.)
- Q: When the domain is stored but a follow-up step of the creation fails (rate limit, DKIM key,
  DKIM DNS publication, groupware update or restart), how is that reported? → A: The creation
  succeeds. The response contains the domain plus a creation report with one outcome per follow-up
  step, and the audit record holds the same report. The domain row, its tags and its domain map
  entry are stored together or not at all. (agent decision: legacy keeps the domain and shows a
  separate message per failed step, so partial success is legacy behaviour. A per-step report keeps
  that information without returning an error for a domain that exists. Legacy can also leave a
  domain without its domain map entry when the shared store fails, so that part is made atomic.)
- Q: The legacy edit form sets a domain's relayhost and the edit page shows its rate limit. Are
  these editable in feature 002? → A: No. Both are shown read-only on the domain. The relayhost
  becomes editable with feature 009 and the rate limit with feature 006, as the roadmap allocates
  them. The rate limit given at creation, from the request or the template, is still applied,
  because legacy applies it as part of adding a domain. (agent decision: roadmap allocation table,
  rows 006 and 009. Making the fields writable later is an additive contract change.)
- Q: Should creating a domain publish its DKIM key to an ISPConfig DNS server, as the FeldHost fork
  does? → A: Yes, when enabled. The push is a server setting, off by default, and the FeldHost
  deployment turns it on. A failed push never fails the creation and is reported in the creation
  report. The default DKIM selector is also a setting: `dkim` upstream, `feldhost` in the fork.
  (agent decision: fork-only features are kept but configurable, per the roadmap. The push itself
  is specified in feature 010; this feature only triggers it through DKIM key generation.)
- Q: What happens when a domain administrator sends attributes they may not change, such as the
  mailbox limit, or the description without the `domain_desc` flag? → A: The request is refused as
  forbidden, the error names the attributes, and nothing changes. An attribute sent with its current
  value is accepted. (agent decision: legacy silently ignores such values, so a client believes a
  change was made when it was not. Accepting unchanged values lets the web app send a whole form.)
- Q: How are domain tags changed, and what does the tag limit mean? → A: Saving the tags replaces the
  whole set. Tags that differ only in letter case are one tag. A domain has at most 25 tags (the
  legacy `TAGGING_LIMIT`, which the operator can change), and a request that would exceed the limit
  is refused. (agent decision: legacy appends tags on edit, removes them with `delete/domain/tag`,
  silently drops tags after the 26th of a request, never limits the total, and fails with a
  database error on a duplicate. Set replacement under a version check covers adding and removing
  without lost updates.)
- Q: What does a domain's version tag cover, and must clients send it? → A: It covers the stored
  settings and the tags, not the usage figures. Sending it with a change is optional; when it is sent
  and outdated, the change is refused. (agent decision: storage figures change with every delivered
  message, which would make every edit outdated. Constitution II requires honouring the version,
  not requiring it, and its error codes do not include "precondition required".)
- Q: Legacy deletion leaves the aliases, temporary aliases, BCC maps, domain map entries and rate
  limits of the domain's alias domains behind, and keeps the footer. Should the API do the same? →
  A: No. Deleting a domain also cleans up its alias domains as a separate alias domain deletion
  would, and removes the footer. Everything else follows legacy, including keeping the DKIM key and
  deleting domain administrator accounts that are left without any domain. (agent decision: the
  leftover domain map entries make the mail filter keep treating removed alias domains as local,
  and a leftover footer reappears when the name is added again. Both are legacy defects;
  constitution IV allows a stated deviation.)
- Q: Legacy deletion removes BCC maps whose local destination is the bare domain name, but legacy
  stores domain BCC maps as `@<domain>`, so they survive. Should the API remove them? → A: Yes.
  Deleting a domain removes its BCC maps in the `@<domain>` form and in the bare form, and does the
  same for each of its alias domains, as a deletion of that alias domain alone would (FR-037).
  (orchestrator decision: feature 009 found the defect. Surviving BCC maps keep copying mail for a
  domain that no longer exists, and would apply again if the name were added back. Feature 004
  applies the same fix to alias domain deletion.)
- Q: Legacy lets domain administrators read domain templates through `/api/v1`. Should they? → A:
  No. All template operations are for administrators only. (agent decision: domain administrators
  cannot create domains, the legacy UI shows the templates tab only to administrators, and least
  privilege applies.)
- Q: The legacy domain list activates, deactivates and deletes several selected domains at once.
  Should these mass actions be one bulk request, and what happens when some domains fail? → A: One
  request applies one action (activate, deactivate or delete) to up to 100 domains. Each domain is
  checked, processed and audited on its own, failures do not stop the others, and the response
  reports one result per domain (FR-062, FR-063). Templates and footers have no bulk actions.
  (orchestrator decision: features 003 and 004 define bulk actions for the legacy mass actions in
  the same way. Legacy `edit/domain` and `delete/domain` take arrays and continue past failures,
  and the web app needs the mass actions.)
- Q: How does a client learn the server settings that shape the domain forms before it submits:
  whether the groupware is enabled, the default DKIM selector and key size, the tagging limit and
  the other limits? → A: A read-only "domain capabilities" operation for administrators and domain
  administrators returns them. The DKIM values and the DKIM DNS publication setting are returned to
  administrators only (FR-064). (orchestrator decision: the UI 002 spec found these gaps. Legacy
  renders the same settings into its pages: `SKIP_SOGO` hides the Global Address List checkbox of
  the add form and the restart button, and the forms pre-fill the DKIM values.)
- Q: Should the usage figures include the number of resources and the largest quota of an existing
  mailbox? → A: Yes (FR-008). (orchestrator decision: the UI 002 spec found that a client otherwise
  cannot foresee a deletion refused because of resources, or the "maximum mailbox quota" rule of
  FR-033.)
- Q: Can a client see what deleting a domain would remove or what blocks it, before deleting? → A:
  Yes. A read-only deletion preview for administrators lists the blocking mailboxes and resources,
  the alias domains, aliases and footer that would be removed, the domain administrator accounts
  that would be deleted, and whether a DKIM key would be kept. It changes nothing (FR-065).
  (orchestrator decision: the UI constitution requires destructive actions to show what they
  affect, and the domain administrator cleanup of FR-038 is otherwise invisible.)
- Q: Should list entries carry version tags, and can bulk actions use them? → A: Yes. Every list
  entry carries the domain's version tag. Each item of a bulk action may carry the tag the client
  read. An item whose tag is outdated is refused as outdated, and the others proceed (FR-015,
  FR-063). (orchestrator decision: the UI 002 spec needs stale-list detection for bulk actions.
  Sending tags stays optional, so clients that follow features 003 and 004 are unaffected.)
- Q: Can a client get the distinct domain tags in its scope, for filter and editor suggestions? →
  A: Yes. A read-only tag list returns each distinct tag in the caller's scope with the number of
  domains that carry it, with search and paging (FR-066). (orchestrator decision: the UI 002 spec
  found the gap. Footer exclusion candidates are not a gap; the web app uses the mailbox and alias
  lists of features 003 and 004.)
- Q: Can the domain page show whether a footer is set without reading the whole footer, which can
  hold two texts of 1 MiB each? → A: Yes. Every domain representation carries a footer summary:
  whether a footer adds text, the sizes of its texts, whether replies are skipped and the numbers of
  excluded addresses and alias domains (FR-067). (orchestrator decision: `docs/api-gaps.md` G28,
  found by UI 002. The summary is not covered by the domain's version tag; the footer keeps its
  own.)
- Q: How does a client learn the values that a new domain template gets for what it omits, which are
  only described in prose? → A: The domain capabilities return them to administrators as
  `templateDefaults`, the server's default DKIM selector included, and the template creation schema
  carries them as `default` keywords where they are fixed (FR-068). (orchestrator decision:
  `docs/api-gaps.md` G29, found by UI 002. The default selector is a server setting, so it can only
  come from the capabilities.)
- Q: Should domains and domain templates expose mailcow's default mailbox quota (`domain.defquota`,
  `def_quota_for_mbox`)? → A: No. The API neither shows nor accepts it, and no longer derives a
  default quota for a new mailbox from it; the server keeps the stored values valid for the classic
  interface (FR-020, FR-046). (owner decision, 2026-10-18: "it is completely useless", an upstream
  leftover since mailbox templates carry the quota of a new mailbox. Supersedes the earlier
  contract, which exposed and validated the value.)

## User Scenarios & Testing *(mandatory)*

### User Story 1 - List, search and inspect domains (Priority: P1)

An administrator opens the domain list in the new web app. Each row shows what the legacy
"Mail setup › Domains" screen shows today:

- the domain name, in readable (Unicode) form and in its ASCII form when they differ;
- a relay badge for relay domains;
- mailboxes and aliases in use against their limits;
- quota allocated to mailboxes against the domain quota, and the storage and message count in use;
- the default and maximum mailbox quota and the rate limit;
- the backup MX flag, the domain administrators, the creation and modification dates, the tags and
  the active state.

The administrator searches by name, description or tag, filters by active state, relay state and
tag, sorts by any of the columns the legacy screen sorts by, and pages through the results. Opening
a domain shows the same figures plus the values a mailbox form needs, such as the largest quota a
new mailbox may still get, and the number of aliases and alias domains a deletion would remove.

A domain administrator sees the same screen, limited to the domains they manage and without the
list of domain administrators.

**Why this priority**: Every other domain task starts from this list, and the mailbox, alias and
resource screens of later features need the domain figures. It is also the safest first slice,
because it changes nothing.

**Independent Test**: Load a test copy of the mailcow database with domains in every relay state,
with mailboxes, resources, aliases, alias domains, tags and stored usage. List and inspect them as
an administrator and as a domain administrator, with each search, filter and sort option, and
compare every figure with what the legacy UI shows for the same data.

**Acceptance Scenarios**:

1. **Given** 60 domains, **When** an administrator lists domains without parameters, **Then** the
   first 25 domains sorted by name are returned, together with the total of 60.
2. **Given** a domain with 3 mailboxes, 1 resource, 5 aliases (2 of them in an alias domain) and
   2 aliases that are the mailboxes' own addresses, **When** it is inspected, **Then** it reports
   3 mailboxes and 5 aliases in use, as the legacy UI does.
3. **Given** domains tagged "customer-a" and "customer-b", **When** an administrator searches for
   "customer", **Then** both are returned. **When** they filter by the tag "customer-a", **Then**
   only the first is returned.
4. **Given** a domain administrator who manages two of ten domains, **When** they list domains,
   **Then** exactly those two are returned and the total is 2. **When** they request a third
   domain by name, **Then** the response is the same "not found" as for a domain that does not
   exist.
5. **Given** an internationalised domain stored as `xn--mnchen-3ya.de`, **When** a client requests
   `münchen.de` or `MÜNCHEN.de`, **Then** the domain is returned with both its ASCII and its
   readable name.
6. **Given** a mailbox user, **When** they list or request domains, **Then** the request is
   refused as forbidden.
7. **Given** a read-only API key owned by a domain administrator, **When** a client lists domains
   with it, **Then** it receives exactly that domain administrator's domains.
8. **Given** a domain with 2 resources and mailboxes of 1, 2 and 5 GiB quota, **When** it is
   inspected, **Then** it reports 2 resources and 5 GiB as the largest existing mailbox quota.
9. **Given** three domains tagged "gold", one of them outside a domain administrator's scope,
   **When** that domain administrator lists the distinct tags, **Then** "gold" is returned with a
   count of 2.
10. **Given** a domain listed by a client, **When** the client compares the version tag of the list
    entry with the one an inspection returns, **Then** they are equal.

---

### User Story 2 - Create a domain (Priority: P1)

An administrator, or a billing system with an administrator's read-write key, adds a domain. They
pick a domain template, or accept the Default template, which pre-fills the limits, quotas, relay
options, Global Address List flag, active state, tags, rate limit and DKIM key settings. They can
override any of these values and add a description. The new domain works at once for mail routing.
Its DKIM key is generated as in legacy, and the administrator can ask for the groupware (SOGo) to be
restarted so that it knows the new domain. The response tells them which follow-up steps succeeded.

**Why this priority**: Adding customer domains is the most common write, and billing automation
depends on it.

**Independent Test**: Create domains through the API with the Default template, with another
template, with overrides, with and without a groupware restart, with an existing DKIM key, and with
the DKIM DNS publication enabled and disabled. Compare the stored data, the shared-store entries
and the outbound service calls with those the legacy UI produces for the same input, and confirm
that mail for the new domain is accepted once a mailbox exists.

**Acceptance Scenarios**:

1. **Given** the Default template, **When** an administrator creates `example.org` with only its
   name, **Then** the domain is created with the template's values, its description is
   `example.org`, a DKIM key is generated with the template's selector and key size, and the
   response points to the new domain and reports the DKIM key as created.
2. **Given** a template named "Reseller" with 50 mailboxes and a 50 GiB domain quota, **When** an
   administrator creates a domain from it with a maximum of 20 mailboxes, **Then** the domain gets
   20 mailboxes, a 50 GiB domain quota and every other value from "Reseller".
3. **Given** an existing domain or alias domain `example.org`, **When** an administrator creates
   `Example.org`, **Then** creation is refused as a conflict and nothing changes.
4. **Given** a request whose maximum mailbox quota exceeds the domain quota, **When** it is
   submitted, **Then** it is refused with a validation error naming the field and its rule, and
   nothing is created.
5. **Given** a request for the server's own host name, **When** it is submitted, **Then** it is
   refused with a validation error.
6. **Given** a request that asks for "relay non-existing mailboxes only" without the other relay
   options, **When** the domain is created, **Then** it is stored as a relay domain that relays all
   recipients and only unknown mailboxes, as in legacy.
7. **Given** a DKIM key that still exists for `example.org` from an earlier deletion, **When** the
   domain is created again, **Then** the existing key is kept, and the creation report says a key
   already existed.
8. **Given** a container service that cannot restart the groupware, **When** an administrator
   creates a domain and asks for a restart, **Then** the domain is created and the creation report
   says the restart failed.
9. **Given** the FeldHost DKIM DNS publication enabled, **When** a domain whose DNS zone exists in
   ISPConfig is created, **Then** the DKIM record is published there and the creation report says
   so. **Given** the publication disabled, **Then** the report says it is not configured.
10. **Given** a domain administrator, or a read-only key owned by an administrator, **When** they try
    to create a domain, **Then** the request is refused.
11. **Given** a server whose groupware is disabled and whose default DKIM selector is `feldhost`,
    **When** an administrator reads the domain capabilities, **Then** they learn that the groupware
    is disabled, the selector `feldhost`, the default key size, the allowed key sizes and the
    tagging limit, so the add form can hide the Global Address List flag and the restart option as
    legacy does.

---

### User Story 3 - Edit a domain (Priority: P1)

An administrator changes a domain's limits, quotas, relay options, Global Address List flag,
description, tags or active state. The API refuses limits below what is already in use and tells
the administrator the value in use. If someone changed the same domain in the meantime, in the new app, in the legacy UI or
through `/api/v1`, the API refuses the stale change instead of overwriting it.

A domain administrator edits the Global Address List flag and the tags of a domain they manage, and
its description when their permission flags allow it.

**Why this priority**: Upgrading or downgrading a customer's package and suspending a domain are
routine operations for administrators and billing systems.

**Independent Test**: Edit each attribute as an administrator and as a domain administrator with
and without the description permission. Try limits below current usage, stale updates and
attributes a domain administrator may not change. Compare the stored results and side effects with
the legacy UI.

**Acceptance Scenarios**:

1. **Given** a domain with 8 mailboxes, **When** an administrator lowers its mailbox limit to 5,
   **Then** the update is refused with a validation error that reports 8 mailboxes in use.
2. **Given** a domain whose mailboxes have 30 GiB of quota allocated, **When** an administrator
   lowers the domain quota to 20 GiB, **Then** the update is refused and reports 30 GiB in use.
3. **Given** two clients that read the same domain, **When** the first saves a change and the second
   then saves with the version it read, **Then** the second save is refused as outdated.
4. **Given** an administrator who deactivates a domain in the legacy UI, **When** a client then saves
   a change with the version it read before, **Then** the save is refused as outdated.
5. **Given** a client that read a domain, **When** new mail arrives for the domain and the client
   then saves a change with the version it read, **Then** the change is saved.
6. **Given** a domain administrator without the description permission, **When** they change the
   Global Address List flag and the tags, **Then** the change is saved. **When** they change the
   description, **Then** the request is refused as forbidden and the error names the description.
7. **Given** a domain administrator, **When** they change the mailbox limit or the active state of a
   domain they manage, **Then** the request is refused as forbidden and nothing changes. **When**
   they send the whole domain with only the Global Address List flag changed, **Then** the change is
   saved.
8. **Given** a domain with the tags "a" and "b", **When** a client saves the tags "b" and "c",
   **Then** the domain has exactly the tags "b" and "c".
9. **Given** a domain with 25 tags, **When** a client saves 26 tags, **Then** the request is refused
   with a validation error that reports the limit of 25.
10. **Given** a successful change, **When** the change is saved, **Then** the groupware directory
    view is updated as legacy does, and the change appears in the audit log with its old and new
    values.

---

### User Story 4 - Delete a domain (Priority: P2)

An administrator, or a billing system cancelling a customer, deletes a domain that no longer has
mailboxes or resources. The domain's aliases, alias domains, spam policies, BCC maps, MTA-STS
policy, footer, tags, rate limits and stored mail are cleaned up, so nothing keeps routing mail for
it or for its alias domains.

**Why this priority**: Deletion completes the customer life cycle, but it is less frequent than
creation and editing, and the legacy UI can still do it.

**Independent Test**: Delete domains with and without aliases, alias domains, footers, policies and
domain administrators, and compare the remaining data, shared-store entries and service calls with
a legacy deletion of the same data, allowing for the stated deviations. Try to delete a domain that
still has a mailbox or a resource.

**Acceptance Scenarios**:

1. **Given** a domain with 2 aliases, 1 alias domain with 1 alias of its own, and no mailboxes,
   **When** an administrator deletes it, **Then** the domain, all 3 aliases and the alias domain are
   gone, neither name is left in the domain map, its stored mail is moved to mailcow's garbage
   area, and the response has no body.
2. **Given** a domain with 1 mailbox, **When** an administrator deletes it, **Then** the deletion is
   refused as a conflict that reports 1 mailbox, and nothing changes.
3. **Given** a domain administrator who manages only this domain, **When** the domain is deleted,
   **Then** that domain administrator account is deleted as well, as in legacy, and API keys owned
   by it stop working.
4. **Given** a domain that does not exist, **When** an administrator deletes it, **Then** the
   response is "not found".
5. **Given** a domain administrator, **When** they try to delete a domain they manage, **Then** the
   request is refused as forbidden.
6. **Given** a domain with 1 resource, 1 alias domain, 3 aliases, a footer and a domain
   administrator who manages no other domain, **When** an administrator previews its deletion,
   **Then** the preview says the deletion is blocked by the resource, and lists the alias domain,
   the 3 aliases, the footer and that domain administrator account. Nothing changes, and no audit
   record is written.

---

### User Story 5 - Manage domain templates (Priority: P2)

An administrator keeps a set of domain templates, for example one per hosting package. Each
template holds the values that pre-fill a new domain. The administrator lists, creates, inspects,
edits and deletes templates. The template called "Default" always exists: it can be edited but not
renamed or deleted.

**Why this priority**: Templates make domain creation fast and consistent, but domains can be
created without custom templates.

**Independent Test**: Create, list, edit and delete templates, including the Default template, and
confirm that domains created afterwards use the right values and that domains created earlier are
unaffected.

**Acceptance Scenarios**:

1. **Given** no template named "Small", **When** an administrator creates "Small" with 5 mailboxes
   and leaves the other values out, **Then** the template stores 5 mailboxes and the legacy default
   for every other value.
2. **Given** a template named "Small", **When** an administrator creates another template named
   "small", or renames another template to "small", **Then** the request is refused as a conflict.
3. **Given** the Default template, **When** an administrator tries to rename or delete it, **Then**
   the request is refused and the template is unchanged. **When** they change its mailbox limit,
   **Then** the change is saved.
4. **Given** a domain created from "Small", **When** the template is edited or deleted, **Then** the
   domain keeps its values.
5. **Given** a template edit that sends only the mailbox limit, **When** it is saved, **Then** every
   other value of the template is unchanged.
6. **Given** a domain administrator, **When** they list templates, **Then** the request is refused as
   forbidden.

---

### User Story 6 - Apply an action to many domains at once (Priority: P2)

An administrator selects domains in the list and activates, deactivates or deletes them, as the
legacy mass actions do. They get one result per domain.

**Why this priority**: Operators use the legacy mass actions to suspend or remove a customer's
domains. One request per domain would be slow and error-prone for the web app.

**Independent Test**: Apply each action to a selection that includes a domain that does not exist
and a domain whose operation fails, and compare the per-domain results, stored data and audit
records with the same mass action in the legacy UI.

**Acceptance Scenarios**:

1. **Given** 50 active domains, **When** an administrator deactivates them in one request, **Then**
   all 50 are inactive, the response has 50 successful results, and the audit log has 50 records.
2. **Given** a selection of three empty domains and one domain with a mailbox, **When** an
   administrator deletes the selection, **Then** the three empty domains are deleted, the fourth is
   reported as refused with the "domain not empty" error, and it remains unchanged.
3. **Given** a selection that names a domain that does not exist, **When** it is activated, **Then**
   that domain is reported "not found" and the others are activated.
4. **Given** a domain administrator, or a read-only key owned by an administrator, **When** they
   submit any bulk action, **Then** the whole request is refused.
5. **Given** a selection of 101 domains, **When** it is submitted, **Then** the request is refused
   with a validation error that states the limit of 100.
6. **Given** a bulk deactivation whose items carry the version tags from the list, and one domain
   that was changed in the legacy UI after the list was read, **When** it is submitted, **Then**
   that domain is reported as outdated, with the outdated-version error, and the others are
   deactivated.

---

### User Story 7 - Manage the domain-wide footer (Priority: P3)

An administrator or a domain administrator sets the footer that mailcow adds to outgoing mail from
a domain: an HTML version, a plain-text version, whether replies are skipped, and the addresses and
alias domains that are excluded. The footer can use the legacy placeholders, such as the sender's
name or a mailbox's custom attribute.

**Why this priority**: Only some customers use footers, and the rest of the domain management works
without them.

**Independent Test**: Set, read and clear footers as an administrator and as a domain administrator,
with valid and invalid exclusions, and confirm that the mail pipeline applies the stored footer as
it does for a footer saved in the legacy UI.

**Acceptance Scenarios**:

1. **Given** a domain without a footer, **When** a client reads its footer, **Then** it receives
   empty texts, replies not skipped and no exclusions.
2. **Given** a domain administrator, **When** they save an HTML and a plain-text footer that
   excludes one of the domain's mailboxes, **Then** the footer is stored, and mail sent from that
   mailbox gets no footer.
3. **Given** an exclusion entry that is not an address or alias domain of this domain, **When** the
   footer is saved, **Then** the request is refused with a validation error naming the entry, and
   the stored footer is unchanged.
4. **Given** two clients editing the same footer, **When** the second saves with an outdated
   version, **Then** the save is refused as outdated.
5. **Given** an alias domain name, **When** a client reads or saves a footer for it, **Then** the
   response is "not found".

---

### User Story 8 - Trace domain changes (Priority: P3)

An administrator investigating a customer complaint lists the audit log filtered by a domain and
sees every change to it, its footer and its tags: who made it, when, from where, what changed, and
the outcome of follow-up steps such as DKIM key generation. Template changes can be traced the same
way.

**Why this priority**: The constitution requires an audit record for every change, but domains can
be managed without reading the log.

**Independent Test**: Make each kind of domain, template and footer change, including refused ones,
and list the audit log filtered by target.

**Acceptance Scenarios**:

1. **Given** a domain whose quota an administrator raised, **When** another administrator lists
   audit records for that domain, **Then** the entry shows the actor, the time, the client address
   and the old and new quota.
2. **Given** a domain administrator whose edit was refused because it changed the mailbox limit,
   **When** an administrator lists audit records for that domain, **Then** the refusal is listed
   with its reason.
3. **Given** a domain administrator, **When** they request the audit log, **Then** the request is
   refused as forbidden.

---

### Edge Cases

- **Internationalised names**: names are compared and stored in lower-case ASCII (punycode) form,
  and the readable form is derived from it.
- **Single-label names** such as `localdomain`: accepted, as in legacy.
- **Name used by an alias domain**: creation is refused as a conflict. Domain and footer operations
  addressed with an alias domain's name are answered "not found", because alias domains belong to
  feature 004. (Legacy returns the target domain's details.)
- **Server host name**: refused, compared case-insensitively.
- **Contradicting relay options**, for example "relay all recipients" without "relay this domain":
  the stored values are normalised as in legacy, and the response shows the effective values.
  Clearing "relay this domain" while "relay all recipients" stays set therefore has no effect.
- **Relay domain that is the target of alias domains**: allowed, as in legacy, although legacy
  refuses new alias domains for a relay target.
- **Template that does not exist**, named at creation: refused with a validation error. (Legacy
  silently falls back to the first template.)
- **Default template missing** from the database: creation uses the built-in values in FR-022.
- **Follow-up step fails** after the domain was stored (rate limit, DKIM key, DNS publication,
  groupware restart): the domain stays created, and the creation report and the audit
  record name each failed step.
- **Groupware restart requested on a server whose groupware is disabled**: not an error. The report
  says the groupware is disabled.
- **Explicitly empty DKIM selector** at creation: no DKIM key is generated, as in legacy.
- **DKIM DNS publication enabled, but no DNS zone for the domain** on the ISPConfig server: nothing
  is published, and the report says so. (Legacy silently skips.)
- **Limits already below usage**, for example after a change by another tool: an edit that does not
  change the affected limit is accepted. (Legacy refuses every edit of such a domain.)
- **Explicit zero** for the alias limit, the maximum mailbox quota or the domain quota on an edit:
  applied when valid, or refused by the rules. (Legacy treats zero as "keep the current value".)
- **Mailbox with unlimited quota**: it allocates nothing towards the domain quota, as in legacy.
- **Deleting a domain that still has a resource**: refused like a domain with a mailbox.
- **Stored mail cannot be moved to the garbage area** during deletion: the domain is still deleted,
  and the failure is recorded in the audit record.
- **Domain administrator with an inactive assignment**: it grants no access to that domain.
- **Inactive domain**: still visible and editable for its domain administrators, as in legacy.
- **Domain administrator who loses a domain while editing it**: the next request is answered "not
  found".
- **Concurrent creation** of the same name: exactly one succeeds, the other is refused as a
  conflict.
- **Concurrent edits from the legacy UI**: the legacy UI never sends a version, so a legacy save
  can still overwrite a change made through this API, as before.
- **Bulk action items without version tags**: processed without a version check. Items with tags
  are checked one by one.
- **Deletion preview followed by changes**: the preview is a snapshot. The deletion checks and
  computes everything again, so a mailbox added in between still blocks it.
- **Groupware setting changed by the operator**: the capabilities reflect it on the next request.
- **Bulk action naming the same domain twice**, or naming it in ASCII and Unicode form: it is
  processed once and reported once.
- **Duplicate tags in one request**, or tags that differ only in letter case: stored once.
- **Domain that already has more tags than the limit**, from legacy: it can still be read and
  edited, but its tag set can only shrink until it is within the limit.
- **Footer exclusion that stops belonging to the domain**, for example a mailbox deleted later: it
  stays stored and is shown, but it must be removed or made valid before the footer can be saved
  again.
- **Footer removed with its domain**: a domain created later with the same name starts without a
  footer.
- **Shared store unavailable** during creation or deletion: the request is refused with a temporary
  failure and leaves no partial change.
- **Audit record cannot be written**: the change it would record does not succeed.

## Requirements *(mandatory)*

### Functional Requirements

#### Access and scope

- **FR-001**: Administrators MUST be able to reach every domain. Domain administrators MUST be able
  to reach exactly the domains assigned to them through an active assignment, whether or not the
  domain itself is active (legacy behaviour). Mailbox users MUST NOT reach any operation of this
  feature.
- **FR-002**: Scope and permission flags MUST be evaluated from the current mailcow data on every
  request, as in feature 001 (FR-029 there). An assignment or flag changed in either UI MUST apply
  to the next request.
- **FR-003**: A request for a domain, or for a domain's footer, that lies outside the caller's scope
  MUST get exactly the same "not found" response as a domain that does not exist. A request for an
  operation that the caller's role may never use MUST be refused as forbidden (FR-005).
- **FR-004**: A request authenticated by an API key MUST act with the key owner's role, scope and
  flags (feature 001, FR-033). A read-only key MUST be refused on every operation that changes
  state, with the "insufficient key scope" error of feature 001 (FR-039). A restricted session
  (feature 001, FR-014) MUST be refused.
- **FR-005**: The operations MUST be authorised as follows:

  | Operation | Administrator | Domain administrator | Mailbox user |
  |---|---|---|---|
  | List and inspect domains | All domains | Own domains, without the domain administrator list | Forbidden |
  | Create a domain | Yes | Forbidden | Forbidden |
  | Edit a domain | All editable attributes (FR-031) | Own domains: Global Address List flag and tags; description only with the `domain_desc` permission flag (FR-032) | Forbidden |
  | Delete a domain | Yes | Forbidden | Forbidden |
  | Bulk actions: activate, deactivate, delete (FR-062) | Yes, per domain as the single operation | Forbidden | Forbidden |
  | Preview a domain deletion (FR-065) | Yes | Forbidden | Forbidden |
  | Read domain capabilities (FR-064) | All values | Without the DKIM values | Forbidden |
  | List distinct domain tags (FR-066) | Tags of all domains | Tags of own domains | Forbidden |
  | Domain templates (all operations) | Yes | Forbidden | Forbidden |
  | Read and replace a domain's footer | All domains | Own domains, no permission flag needed | Forbidden |
  | Read the audit log | Yes | Forbidden | Forbidden |

#### Domain representation

- **FR-006**: A domain MUST be addressed by its name. The system MUST accept the name in ASCII or
  Unicode form and in any letter case, and MUST normalise it as legacy does: trimmed, lower-cased
  and converted to ASCII with the UTS #46 rules. A name that belongs to an alias domain MUST be
  answered "not found".
- **FR-007**: A domain MUST be represented with:
  - its ASCII name and its readable (Unicode) name;
  - its description;
  - its active state;
  - its limits: maximum number of mailboxes and maximum number of aliases;
  - its quotas: maximum mailbox quota and total domain quota (not the default mailbox quota,
    FR-020);
  - its relay options: "relay this domain" (backup MX), "relay all recipients" and "relay
    non-existing mailboxes only";
  - its Global Address List flag;
  - its tags, sorted alphabetically without regard to letter case;
  - its assigned relayhost, or none, read-only until feature 009;
  - its rate limit (a value and a time frame), or none, read-only until feature 006;
  - its creation and last modification times;
  - its usage figures (FR-008);
  - for administrators only, the usernames of its domain administrators.
- **FR-008**: Usage figures MUST be computed as legacy computes them:
  - mailboxes in use: the domain's mailboxes, not counting resources;
  - resources: the domain's resources (locations, things and groups), which block a deletion like
    mailboxes (FR-035);
  - largest existing mailbox quota: the largest quota of a mailbox in the domain, not counting
    resources, which is the floor for the maximum mailbox quota (FR-033);
  - aliases in use: aliases whose address is in the domain or in one of its alias domains, not
    counting aliases whose address is itself a mailbox;
  - alias domains: the number of alias domains that point to the domain;
  - mailboxes left and aliases left: the limit minus the number in use;
  - quota allocated: the sum of the quotas of the domain's mailboxes, not counting resources;
  - storage used and message count: the totals stored for all of the domain's mailboxes, including
    resources;
  - largest quota for a new mailbox: the domain quota minus the quota allocated, capped at the
    maximum mailbox quota and never below zero. Legacy's default quota for a new mailbox is not
    reported (FR-020).
- **FR-009**: Quotas MUST be expressed in bytes. Because mailcow stores them in whole mebibytes
  (MiB), quota values in requests MUST be whole multiples of 1 MiB.
- **FR-010**: Times MUST be RFC 3339 UTC timestamps. A domain that was never modified MUST report no
  modification time.

#### Listing and inspecting

- **FR-011**: Administrators and domain administrators MUST be able to list the domains in their
  scope with pagination. The default page size MUST be 25, as in legacy, and the maximum 1,000. The
  response MUST include the total number of matching domains.
- **FR-012**: The list MUST support a free-text search that matches, case-insensitively and as a
  substring, the ASCII name, the readable name, the description and the tags.
- **FR-013**: The list MUST support these filters: active state, "relay this domain", and tag. The
  tag filter MUST match whole tags case-insensitively. Several tags MUST match domains that have
  any of them.
- **FR-014**: The list MUST be sortable, ascending or descending, by: name, description, active
  state, "relay this domain", mailboxes in use, aliases in use, quota allocated, storage used,
  maximum mailboxes, maximum aliases, maximum mailbox quota, domain quota, creation time and
  modification time. The default MUST be by name, ascending. Ties MUST be broken
  by name.
- **FR-015**: Each list entry MUST carry the full domain representation (FR-007) and the domain's
  version tag (FR-041), equal to the one an inspection returns, so that the list screen needs no
  request per row and can detect stale entries.
- **FR-016**: A client MUST be able to inspect one domain in its scope. The response MUST carry the
  domain's version tag (FR-041).

#### Domain capabilities

- **FR-064**: Administrators and domain administrators MUST be able to read the domain capabilities,
  the server settings and limits a client needs before it submits:
  - whether the groupware is enabled (legacy `SKIP_SOGO`). When it is disabled, clients can hide the
    Global Address List flag in the creation form and the restart option, as legacy does;
  - the tagging limit (FR-043) and the maximum tag length;
  - the maximum description length, the footer text limit (FR-054), the bulk limit (FR-062), and the
    default and maximum page sizes (FR-011);
  - for administrators only: the default DKIM selector (FR-028), the built-in default DKIM key size,
    the allowed DKIM key sizes, and whether the DKIM DNS publication is enabled (FR-027).

  The values MUST reflect the server's current configuration and MUST NOT include any secret or
  connection detail. The read is the domains area of the API-wide capabilities reads
  (`docs/conventions.md`). The read MUST change nothing, and read-only keys MAY use it.

- **FR-067**: Every domain representation MUST carry a summary of the domain's footer: whether a
  footer with a non-empty HTML or plain text is stored, the size of each text in bytes, whether
  replies are skipped, and the numbers of excluded addresses and excluded alias domains. The summary
  is read-only and not covered by the domain's version tag (G28).
- **FR-068**: The domain capabilities MUST return, to administrators only, the values that a new
  domain template gets for every value it omits (FR-046), which are also the built-in values of a
  creation without a Default template (FR-022), including the server's default DKIM selector (G29).

#### Creating a domain

- **FR-017**: Administrators MUST be able to create a domain. Only the name is required. Every other
  attribute MAY be given, and an omitted attribute MUST take its value from the chosen template
  (FR-018). The attributes are: description, active state, maximum mailboxes, maximum aliases,
  maximum mailbox quota, domain quota, the three relay options, the Global Address List flag, tags,
  an initial rate limit, a DKIM selector, a DKIM key size, and whether to restart the groupware.
- **FR-018**: A creation request MAY name a domain template by its identifier. When it does not, the
  Default template MUST be used. A named template that does not exist MUST be refused with a
  validation error.
- **FR-019**: The name MUST be normalised (FR-006) and MUST then:
  - consist of labels of letters, digits and inner hyphens, each starting and ending with a letter
    or digit, separated by dots;
  - be at most 253 characters long, with labels of at most 63 characters;
  - not exist as a domain or as an alias domain. This MUST be refused as a conflict;
  - not equal the server's host name, compared case-insensitively.
- **FR-020**: The other attributes MUST meet these rules, both at creation and when edited:
  - description: at most 255 characters. An empty or omitted description at creation, and an empty
    description sent with an edit, MUST be stored as the domain name;
  - maximum mailboxes and maximum aliases: whole numbers from 0 to 2,147,483,647;
  - default mailbox quota: not part of the API (owner decision 2026-10-18). The stored value MUST
    be kept valid for the classic interface, which requires it to be above zero and not more than
    the maximum mailbox quota: a creation MUST store the template's default capped at the maximum
    mailbox quota, or the maximum mailbox quota when the template has none (or no template
    applies); an edit that changes the maximum mailbox quota MUST lower a larger default to it,
    and any other edit MUST leave it unchanged. These values MUST NOT be audited;
  - maximum mailbox quota: at least 1 MiB and not more than the domain quota;
  - tags: as in FR-043;
  - initial rate limit: a whole number from 1 to 2,147,483,647 together with a time frame of
    second, minute, hour or day; or none;
  - DKIM selector: letters, digits, `-`, `_` and `.` only, at most 255 characters. An explicitly
    empty selector means "no DKIM key";
  - DKIM key size: 1024, 2048, 3072 or 4096 bits.
- **FR-021**: The relay options MUST be normalised as legacy does: "relay non-existing mailboxes
  only" implies "relay all recipients", which implies "relay this domain". This applies at
  creation, on every edit and to templates.
- **FR-022**: When the Default template is missing from the database, creation MUST use these
  built-in values, which are the values legacy seeds: 10 mailboxes, 400 aliases, 3,072 MiB default
  mailbox quota, 10,240 MiB maximum mailbox quota, 10,240 MiB domain quota, active, Global Address
  List enabled, no relay options, no tags, no rate limit, the configured default DKIM selector
  (FR-028) and 2,048-bit DKIM keys.
- **FR-023**: A successful creation MUST:
  - store the domain and its tags;
  - add the domain to mailcow's domain map in the shared store, which the mail filter uses to
    recognise local domains;
  - remove the external sender addresses in the new domain that were granted to mailboxes, because
    the domain is now local (legacy behaviour);
  - apply the initial rate limit, when the request or the template sets one;
  - generate a DKIM key as in FR-027;
  - restart the groupware when the request asks for it (FR-026).

  As in legacy, a creation MUST NOT update the groupware directory view or flush its cache: the
  new domain has no mailboxes yet (corrected on 2026-10-18).
- **FR-024**: Storing the domain, its tags and its domain map entry MUST succeed or fail together.
  Failures of the other steps MUST NOT undo the creation.
- **FR-025**: The creation response MUST contain the domain and a creation report with the outcome
  of each follow-up step:
  - rate limit: applied, not requested, or failed;
  - DKIM key: created, already existed, not requested, or failed;
  - DKIM DNS publication: published, no DNS zone found, failed, not configured, or skipped because
    no new key was generated;
  - groupware restart: done, failed, not requested, or groupware disabled.
- **FR-026**: A creation request MAY ask for the groupware to be restarted, which legacy offers as
  "Add domain and restart SOGo". On a server whose groupware is disabled, the request MUST still
  succeed, and the report MUST say that the groupware is disabled.
- **FR-027**: Unless a DKIM key already exists for the domain or the selector is explicitly empty,
  creation MUST generate a DKIM key with the selector and key size from the request, the template
  or the built-in values, in that order. The key MUST be stored where legacy stores DKIM keys, so
  that outgoing mail is signed at once. When the server setting for DKIM DNS publication is enabled
  (fork-only, off by default), the new public key MUST also be published as specified in feature
  010. A failed publication MUST NOT fail the creation.
- **FR-028**: A server setting MUST define the default DKIM selector, used when neither the request
  nor the template gives one. Its default MUST be `dkim`, the upstream value. The FeldHost
  deployment sets `feldhost`.
- **FR-029**: A successful creation MUST respond as "created", with the address of the new domain.

#### Editing a domain

- **FR-030**: A client MUST be able to change a domain by sending only the attributes it changes.
  Attributes that are not sent MUST keep their values. A value that is sent, including zero, MUST be
  applied or refused by the rules, never ignored. The only exception is the relay normalisation of
  FR-021, which can override a sent relay option. The response MUST contain the updated domain and
  its new version tag.
- **FR-031**: Administrators MUST be able to change the description, active state, limits, quotas,
  relay options, Global Address List flag and tags. The name, the usage figures and the times MUST
  NOT be changeable. The relayhost assignment and the rate limit MUST NOT be changeable in this
  feature. A request that sends one of these read-only attributes with a different value MUST be
  refused with a validation error naming it. Sending it with its current value MUST be accepted, so
  that a client can send back the representation it read.
- **FR-032**: Domain administrators MUST be able to change the Global Address List flag and the tags
  of a domain in their scope, and its description only when their `domain_desc` permission flag is
  set. A request from a domain administrator that would change any other attribute, or the
  description without the flag, MUST be refused as forbidden. The error MUST name the attributes,
  and nothing MUST change. An attribute sent with its current value MUST be accepted.
- **FR-033**: An edit that changes a limit MUST be refused when the new limit is below current
  usage. The validation error MUST carry the value in use as a rule parameter:
  - maximum mailboxes below the mailboxes in use;
  - maximum aliases below the aliases in use (FR-008);
  - maximum mailbox quota below the largest quota of an existing mailbox, not counting resources;
  - domain quota below the quota allocated.
- **FR-034**: After a successful edit, the system MUST update the groupware directory view and flush
  its cache, unless the groupware is disabled. A change of the Global Address List flag MUST take
  effect in the groupware at its next restart, as in legacy, and the contract MUST say so. An edit
  MUST NOT restart the groupware.

#### Deleting a domain

- **FR-035**: Administrators MUST be able to delete a domain that has no mailboxes and no resources.
  A domain that still has any MUST be refused as a conflict, and the error MUST report how many
  mailboxes and resources remain.
- **FR-036**: A successful deletion MUST remove, as legacy does:
  - the domain, its tags, its domain administrator assignments and its aliases;
  - the alias domains that point to it;
  - the sender permissions, quota records, replicated quota records, notification settings and
    temporary aliases of addresses in the domain;
  - the domain's spam allow and block lists and its MTA-STS policy;
  - the domain's entries in the domain map and in the rate limits;
  - the domain's stored mail, by asking mailcow's container service to move it to the garbage area
    (FR-039).

  It MUST then update the groupware directory view and flush its cache, unless the groupware is
  disabled.
- **FR-037**: A deletion MUST also remove, unlike legacy:
  - the domain's BCC maps, both those stored for `@<domain>`, the form legacy creates, and any
    stored for the bare domain name. Legacy removes only the bare form, so the domain's BCC maps
    survive a legacy deletion;
  - for each alias domain that pointed to the domain: its aliases, the temporary aliases of its
    addresses, its BCC maps in both forms, the explicit sender permissions for it and for its
    aliases' addresses, its spam allow and block lists, and its entries in the domain map and in
    the rate limits, as a deletion of that alias domain alone would (feature 004, FR-045 there,
    with one shared implementation);
  - the domain's footer.
- **FR-038**: A deletion MUST keep the domain's DKIM key, as in legacy, so that a domain created
  again with the same name reuses it. DKIM keys are managed in feature 010. A deletion MUST delete
  domain administrator accounts that are left without any domain, together with their permission
  flags, as legacy does. Their API keys then stop working (feature 001).
- **FR-039**: A failure to move the stored mail to the garbage area MUST NOT stop the deletion. It
  MUST be recorded in the audit record of the deletion.
- **FR-040**: Deleting a domain that does not exist, or is outside the caller's scope, MUST be
  answered "not found". A successful deletion MUST respond with no body.
- **FR-065**: Administrators MUST be able to preview the deletion of a domain. The preview MUST
  change nothing and MUST NOT write an audit record. It MUST report, computed by the same rules as
  the deletion itself (FR-035 to FR-038):
  - whether the deletion would currently be allowed;
  - the blocking objects: the number of mailboxes and resources, with up to 100 addresses of each;
  - what would be removed: the alias domains, the number of aliases (including those of the alias
    domains) with up to 100 addresses, and whether a footer exists;
  - the domain administrator accounts that would be deleted because they manage no other domain;
  - whether a DKIM key exists, which would be kept;
  - the domain's current version tag, which the client can send with the deletion.

  A preview of a domain that does not exist MUST be answered "not found".

#### Version tags

- **FR-041**: Every domain, domain template and domain footer MUST carry a version tag. The tag MUST
  change whenever the stored settings change, whether the change was made through this API, the
  legacy UI or `/api/v1`. For a domain, the tag MUST cover the stored attributes and the tags, and
  MUST NOT change with the usage figures.
- **FR-042**: A client MAY send the version tag it read with an edit, a replacement or a deletion.
  When the tag no longer matches, the request MUST be refused as outdated and MUST change nothing.
  The contract MUST recommend that interactive clients always send it.

#### Tags

- **FR-043**: A domain's tags MUST be a set of texts. Each tag MUST be trimmed, MUST NOT be empty and
  MUST be at most 255 characters long. Tags that differ only in letter case MUST be stored once,
  keeping the first spelling given. A request MUST NOT leave a domain with more tags than the
  server's tagging limit, 25 by default (legacy `TAGGING_LIMIT`).
- **FR-044**: Setting a domain's tags MUST replace the whole set. Removing tags, which legacy does
  with `delete/domain/tag`, is done by setting the set without them. Both administrators and the
  domain's domain administrators MAY change tags.
- **FR-066**: Administrators and domain administrators MUST be able to list the distinct tags of the
  domains in their scope. Each entry MUST give the tag and the number of domains in scope that
  carry it. The list MUST support a case-insensitive substring search and pagination as in FR-011,
  sorted alphabetically without regard to letter case. It MUST NOT reveal tags that appear only on
  domains outside the caller's scope.

#### Domain templates

- **FR-045**: Administrators MUST be able to list, create, inspect, edit and delete domain templates.
  A template MUST hold: a name, tags, maximum mailboxes, maximum aliases, maximum mailbox quota,
  domain quota, a rate limit, the active state, the Global Address List flag,
  the three relay options, a DKIM selector and a DKIM key size. It MUST also report an identifier,
  whether it is the Default template, its creation and modification times, and a version tag
  (FR-041).
- **FR-046**: A template created without some values MUST get the legacy defaults for them: 400
  aliases, 10 mailboxes, 10,240 MiB maximum mailbox quota, 10,240 MiB domain quota, no rate limit,
  active, Global Address List enabled, no relay options, no tags, the configured default DKIM
  selector (FR-028) and 2,048-bit keys. An explicitly sent value, including zero, MUST be kept when
  it is valid. The template's default mailbox quota, which the API does not expose (FR-020), MUST
  be stored as the maximum mailbox quota at creation; an edit MUST lower a larger stored value to
  the maximum mailbox quota and replace a missing or zero one with it, without auditing it.
- **FR-047**: A template name MUST NOT be empty, MUST be at most 255 characters long, and MUST be
  unique among domain templates, compared case-insensitively. A duplicate, at creation or on a
  rename, MUST be refused as a conflict. The values of a template MUST meet the rules in FR-020 and
  MUST be normalised as in FR-021, so that a template always produces a valid domain.
- **FR-048**: An edit MUST change only the values it sends. The template whose name is "Default",
  compared case-insensitively, MUST NOT be renamed or deleted. Such a request MUST be refused with
  a documented error, and nothing MUST change.
- **FR-049**: The template list MUST support pagination as in FR-011, sorting by name, identifier,
  creation time and modification time (default: name, ascending), and a search on the name.
- **FR-050**: Templates MUST only pre-fill new domains. Editing or deleting a template MUST NOT
  change any domain, and a domain MUST NOT record which template it came from.

#### Domain-wide footer

- **FR-051**: A client MUST be able to read the footer of a domain in its scope: the HTML text, the
  plain text, whether replies are skipped, the excluded addresses and the excluded alias domains,
  with a version tag (FR-041). A domain without a footer MUST return empty texts, "replies not
  skipped" and no exclusions.
- **FR-052**: Administrators and the domain's domain administrators MUST be able to replace a
  domain's footer as a whole. No permission flag is needed, as in legacy. Saving empty texts
  without exclusions MUST be possible and means that no footer is added.
- **FR-053**: Each excluded entry MUST be either the address of a mailbox or alias in the domain or
  in one of its alias domains, or the name of one of the domain's alias domains. Any other entry
  MUST be refused with a validation error that names it, and the stored footer MUST stay unchanged.
  Duplicate entries MUST be stored once.
- **FR-054**: The HTML text and the plain text MUST each be at most 1 MiB. The contract MUST document
  the legacy placeholders (`{= auth_user =}`, `{= from_user =}`, `{= from_name =}`,
  `{= from_addr =}`, `{= from_domain =}`, and `{= <attribute> =}` for a custom attribute of the
  sending mailbox) and that the HTML text is operator-authored HTML that clients must render
  safely.
- **FR-055**: The footer MUST be stored where legacy stores it, so that mailcow's mail pipeline
  applies it unchanged, including to mail from the domain's alias domains. Footer operations
  addressed with an alias domain's name MUST be answered "not found".

#### Reliability

- **FR-056**: When mailcow's shared store is unavailable, creation and deletion MUST be refused with
  a temporary-failure error and MUST leave no partial change.

#### Audit

- **FR-057**: Every request of this feature that changes state, or tries to, MUST write exactly one
  audit record per affected domain, template or footer into feature 001's audit log, with the fields
  of FR-046 there and:
  - the target type (domain, domain template or domain footer) and the target identifier;
  - for bulk actions, the identifier of the bulk request;
  - the outcome, including refusals and their reason;
  - for successful changes, each changed attribute with its old and new value. Footer texts MUST be
    recorded only as changed, with their old and new sizes;
  - for a creation, the creation report (FR-025);
  - for a deletion, the outcome of moving the stored mail, and the domain administrator accounts
    and alias domains that were removed.
- **FR-058**: Administrators MUST be able to filter the audit log by target type and target
  identifier, in addition to the filters of feature 001 (FR-047 there).

#### Cross-cutting

- **FR-059**: Errors MUST follow feature 001's conventions (FR-049 and FR-055 there). This feature
  MUST document at least these error types: domain already exists, domain not empty, template name
  already exists, protected template, outdated version, attribute not permitted for the role, and
  temporary failure. Validation errors MUST use stable rule codes with their parameters, including
  the value in use for the rules of FR-033, the limit for the tag rule of FR-043 and the bulk limit
  of FR-062.
- **FR-060**: Every operation MUST be described in the published API contract before it is
  implemented, and its behaviour MUST match the contract.
- **FR-061**: Changes made through the legacy UI or `/api/v1` MUST be visible in the next response of
  this API.

#### Bulk actions

- **FR-062**: Administrators MUST be able to apply one of these actions to up to 100 domains in one
  request, as the legacy mass actions do: activate, deactivate, delete. A request with more than 100
  domains MUST be refused with a validation error that states the limit. Creation MUST NOT be
  offered as a bulk action. Templates and footers have no bulk actions: each is created, changed and
  deleted with its own request.
- **FR-063**: A bulk action MUST be refused as a whole when the caller's role or key scope does not
  allow the action (FR-004, FR-005). Otherwise each named domain MUST be processed on its own, with
  the same checks, side effects and audit record as a single request for it, and the response MUST
  report one result per distinct domain: done, not found, refused (with the error type), outdated,
  or failed (with the error type), the outcomes of `docs/conventions.md`. A failure on one domain
  MUST NOT stop or undo the others, as in legacy. Each item MAY carry the version tag the client
  read, for example from the list (FR-015). An item whose tag no longer matches MUST be reported as
  outdated, with the outdated-version error, and left unchanged. Items without a tag MUST be
  processed without a version check. The groupware directory update and cache flush MAY be made
  once for the whole request, as legacy does.

### Legacy Coverage

Every legacy capability allocated to feature 002 in `docs/roadmap.md` maps to requirements as
follows. Actions are `/api/v1` names; *form* marks legacy UI form handlers.

| Legacy action or form | Legacy implementation | Requirements |
|---|---|---|
| `add/domain`, *form* "Add domain only" and "Add domain and restart SOGo" | `mailbox('add', 'domain')`, add-domain modal | FR-017 to FR-029, FR-043, FR-056, FR-057 |
| `get/domain/all`, including `?tags=` | `mailbox('get', 'domains')` with `domain_details` per domain | FR-007, FR-008, FR-011 to FR-015 |
| `get/domain/{domain}` | `mailbox('get', 'domain_details')` | FR-006 to FR-010, FR-016 |
| `search/domain` (server-side list of the Domains screen) | `json_api.php` search case, `draw_domain_table()` | FR-011 to FR-015 |
| `edit/domain`, *form* Edit domain (administrator and domain administrator) | `mailbox('edit', 'domain')`, `edit/domain.twig` | FR-030 to FR-034, FR-041 to FR-044 |
| `delete/domain`, single | `mailbox('delete', 'domain')` | FR-035 to FR-040, FR-056 |
| Domain list mass actions: activate, deactivate, delete (`edit/domain` and `delete/domain` with arrays) | `tab-domains.twig` quick actions | FR-062, FR-063 |
| Server settings rendered into the domain screens: `skip_sogo` (Global Address List checkbox of the add form, restart button), pre-filled DKIM selector and key size, `TAGGING_LIMIT` | `inc/header.inc.php`, `modals/mailbox.twig`, `vars.inc.php` | FR-064 |
| Delete confirmation of the domain list | `ConfirmDeleteModal` in `js/build/011-api.js` | FR-065 |
| `add/domain/template`, *form* add-template modal | `mailbox('add', 'domain_templates')` | FR-045 to FR-047 |
| `get/domain/template/all`, `get/domain/template/{id}` | `mailbox('get', 'domain_templates')` | FR-045, FR-049 |
| `edit/domain/template`, *form* Edit template | `mailbox('edit', 'domain_templates')`, `edit/domain-templates.twig` | FR-047, FR-048 |
| `delete/domain/template`, single and the template list's mass delete | `mailbox('delete', 'domain_templates')`, `tab-templates-domains.twig` | FR-048, FR-050, FR-062 (one request per template) |
| `edit/domain/footer`, *form* footer tab, footer read on the Edit domain page | `mailbox('edit' / 'get', 'domain_wide_footer')` | FR-051 to FR-055, FR-037, FR-062 (one request per footer) |
| `delete/domain/tag` | `mailbox('delete', 'tags_domain')` | FR-043, FR-044 |
| Domain quotas and limits | `domain` columns, figures of `domain_details` | FR-007 to FR-009, FR-020, FR-033 |
| Relay/backup MX flags | `backupmx`, `relay_all_recipients`, `relay_unknown_only` | FR-007, FR-020, FR-021 |
| GAL | `gal` | FR-007, FR-031, FR-032, FR-034 |
| Domain tags | `tags_domain` | FR-012, FR-013, FR-043, FR-044 |
| Domain administrator flag `domain_desc` | description edit by domain administrators | FR-032 |

Parts of the legacy "Edit domain" screen that belong to other features: the relayhost selector and
the `domain_relayhost` flag (009), the rate limit and spam filter tabs (006), the quota warning BCC
tab (013), the MTA-STS tab, the DKIM record display and the DNS button (010).

### Legacy Parity and Deliberate Deviations

Parity reference: `source_code/data/web/inc/functions.mailbox.inc.php` (`mailbox()` cases
`domain`, `domain_templates`, `domain_wide_footer`, `domains`, `domain_details`, `tags_domain`, and
the closing `update_sogo_static_view()` call), `functions.inc.php` (`hasDomainAccess`,
`is_valid_domain_name`, `update_sogo_static_view`, `flush_memcached`), `functions.ratelimit.inc.php`,
`functions.dkim.inc.php`, `json_api.php`, `init_db.inc.php` (tables `domain`, `tags_domain`,
`templates`, `domain_wide_footer`, `domain_admins`, `da_acl`, and the seeded Default template),
`templates/edit/domain.twig`, `templates/edit/domain-templates.twig`,
`templates/mailbox/tab-domains.twig`, `templates/mailbox/tab-templates-domains.twig`,
`templates/modals/mailbox.twig`, `js/site/mailbox.js`, `lang/lang.en-gb.json`, and
`Dockerfiles/sogo/bootstrap-sogo.sh` (SOGo reads the domains and their GAL flag only at start).

The reference is upstream mailcow-dockerized `2026-09a`. `source_code/` points to the FeldHost fork
(`~/Projects/mailhosting`, branch `feldhost-202609a`), so the files above were diffed against that
upstream tag. `functions.mailbox.inc.php`, `json_api.php`, `functions.inc.php`,
`functions.ratelimit.inc.php` and the domain templates and screens are identical. The fork differs
in three ways:

1. The default DKIM selector is `feldhost` instead of `dkim` in the seeded Default template
   (`init_db.inc.php`), in the selector the add-domain and add-template forms pre-fill
   (`modals/mailbox.twig`) and in the fallback of `dkim('add')`. The fallback of
   `mailbox('add' / 'edit', 'domain_templates')` stays `dkim` in both (FR-028).
2. DKIM key creation, which domain creation triggers, also pushes the public key as a TXT record to
   an ISPConfig DNS server when `inc/ispconfig.inc.php` exists, and ignores every failure
   (`functions.dkim.inc.php` `dkim_update_in_dns()`, `inc/lib/ispconfig.php`). Domain deletion
   never deletes DKIM keys, so it never removes the record (FR-027, FR-038).
3. Branding-only texts (language strings, the `authsource` label in `mailbox.js`), with no effect on
   domains.

| Behaviour | Legacy | This feature |
|---|---|---|
| Who may create and delete | Administrators only | Same (FR-005) |
| Domain administrator edits | Global Address List flag and tags; description with `domain_desc`. A relayhost value is computed with `domain_relayhost` but never saved. Other values are silently ignored | Global Address List flag, tags, description with `domain_desc`. Other changes are refused and named (FR-032). **Deviation** |
| Domain administrators reading templates | Allowed through `/api/v1`. The UI hides the tab | Administrators only (FR-005, FR-045). **Deviation** |
| Object outside scope | Empty result (`{}` or `false`) with status 200 | "Not found" (FR-003) |
| Mailbox users | No domain access | Same, refused as forbidden (FR-005) |
| Name normalisation and syntax | Trim, lower-case, IDNA UTS #46, `is_valid_domain_name` (single labels allowed, 253/63 lengths) | Same (FR-006, FR-019) |
| Alias domain name in a domain lookup | `get/domain/{alias}` returns the target domain's details | "Not found" (FR-006). **Deviation** |
| Duplicate and host name checks | Exists as domain or alias domain; equal to `MAILCOW_HOSTNAME` | Same; the host name compared case-insensitively (FR-019) |
| Values at creation | From the chosen template. An unknown or empty template silently falls back to the first domain template | From the chosen template or Default; an unknown template is refused; built-in seed values when Default is missing (FR-018, FR-022). **Deviation** |
| Quota and limit rules | Default ≤ maximum mailbox quota ≤ domain quota, neither mailbox quota 0. Negative limits accepted | Maximum mailbox quota ≤ domain quota, plus limits ≥ 0 and whole MiB (FR-009, FR-020). **Deviation**: negative limits refused |
| Default mailbox quota (owner decision, 2026-10-18) | `domain.defquota` and templates' `def_quota_for_mbox` are shown, edited and validated (default ≤ maximum mailbox quota, not 0); `domain_details` derives `def_new_mailbox_quota` from it, and the UI pre-fills new mailboxes with it | Not exposed, accepted or reported: an upstream leftover that mailbox templates replace. The API maintains the columns so that legacy's rules hold: creation stores the template's default capped at the maximum mailbox quota (else the maximum), a changed maximum lowers a larger default, templates store the maximum (FR-020, FR-046). **Deviation** |
| Quota units | MiB in requests and storage, bytes in details | Bytes, whole MiB (FR-009) |
| Relay options | "Unknown only" implies "all", which implies backup MX | Same, also for templates (FR-021) |
| Zero and omitted values on edit | Alias limit, maximum mailbox quota and domain quota treat 0 as "keep". Template edits reset omitted values to 0 or defaults | Sent values applied, omitted values kept, for domains and templates (FR-030, FR-048). **Deviation** |
| Usage checks on edit | Every edit checks all four limits. The alias count leaves out aliases in alias domains | Only changed limits are checked, against the displayed counts (FR-033). **Deviation** |
| Description | Empty at creation → domain name. Empty on edit keeps the old value | Empty → domain name in both cases (FR-020). **Deviation** |
| Tags | Appended on create and edit. Tags after the 26th of a request dropped with a warning, no total limit, a duplicate fails with a database error. `delete/domain/tag` removes tags | Set replacement, case-insensitive duplicates merged, total ≤ limit (default 25), refusal above it (FR-043, FR-044). **Deviation** |
| Tag filter (`get/domain/all?tags=`) | Substring match, any tag | Whole-tag match, any tag. Substring matching through the search (FR-012, FR-013). **Deviation** |
| Domain list | `search/domain`: search on name and tags, 9 sortable columns, one detail lookup per row | Search also on readable name and description, filters, more sort keys, one request (FR-011 to FR-015) |
| Creation side effects | `domain` and `tags_domain` rows, `DOMAIN_MAP` entry, removal of external `sender_acl` entries for the domain, `ratelimit('edit')` → `RL_VALUE`, `dkim('add')` → `DKIM_PUB_KEYS`, `DKIM_SELECTORS`, `DKIM_PRIV_KEYS` (fork: ISPConfig TXT push), optional dockerapi restart of `sogo-mailcow`. The add case returns before the closing `update_sogo_static_view()`, so there is no directory rebuild and no memcached flush | Same, without a directory rebuild or memcached flush (FR-023, FR-026, FR-027). Corrected on 2026-10-18: the API had rebuilt the directory on creation |
| Partial failure at creation | A shared-store failure after the row insert leaves a domain without its map entry. Rate limit, DKIM and SOGo restart failures leave the domain created, with a message each | Domain, tags and map entry atomic; follow-up outcomes reported (FR-024, FR-025). **Deviation** for atomicity |
| Edit side effects | `update_sogo_static_view()` and memcached flush. GAL changes need a SOGo restart | Same (FR-034) |
| Delete preconditions | Any `mailbox` row, including resources, blocks. A missing domain is reported as removed | Same; a missing domain is "not found" (FR-035, FR-040). **Deviation** |
| Delete side effects | Rows in `domain`, `domain_admins`, `alias` (domain), `alias_domain` (target), `sender_acl`, `quota2`, `quota2replica`, `pushover`, `spamalias`, `filterconf`, `mta_sts`; `admin` rows with `superadmin = 0` left without `domain_admins`, orphaned `da_acl`; `tags_domain` by cascade; `DOMAIN_MAP` and `RL_VALUE` entries; dockerapi exec in dovecot `maildir cleanup`; SOGo view and memcached | Same (FR-036, FR-038, FR-039) |
| Delete leftovers | Aliases, temporary aliases, BCC maps, sender permissions, spam lists, `DOMAIN_MAP` and `RL_VALUE` entries of the alias domains, and the `domain_wide_footer` row stay | Removed, with the alias domain cleanup shared with feature 004 (FR-037). **Deviation** |
| Domain BCC maps on delete | `DELETE FROM bcc_maps WHERE local_dest = '<domain>'`, but `bcc` add stores domain BCC maps as `@<domain>` (`functions.address_rewriting.inc.php`), so they survive. Alias domain deletion has the same defect | Removed in both forms, for the domain and its alias domains (FR-037). **Deviation** (bug fix) |
| DKIM key on delete | Kept, shown as an unassigned key on the DKIM screen | Kept (FR-038) |
| Templates | Name required; uniqueness checked only at creation; renaming Default silently ignored; deleting Default silently ignored but reported as done; bulk delete stops after the first; values not validated; 0 replaced by defaults | Uniqueness also on rename; Default rename and delete refused; values validated like domains; explicit values kept (FR-046 to FR-048). **Deviation** |
| Footer | Stored per name in `domain_wide_footer` (replaced as a whole). Exclusions must exist anywhere on the server; invalid entries are skipped and the rest saved; no size limit; alias domain names accepted but never applied by the mail pipeline | Same storage; exclusions must belong to the domain; invalid entries refuse the whole request; 1 MiB per text; alias domain names "not found" (FR-051 to FR-055). **Deviation** |
| Concurrent edits | Last write wins | Version tags on domains, list entries and bulk items (FR-015, FR-041, FR-042, FR-063). **Deviation** |
| Server settings for the forms | Rendered into the pages: `SKIP_SOGO` hides the Global Address List checkbox of the add form and the restart button; DKIM values pre-filled from the template; the tagging limit is never shown | Readable domain capabilities (FR-064), an addition |
| Usage figures | No resource count, no largest existing mailbox quota (both computed only inside the delete and edit checks) | Both reported (FR-008), an addition |
| Delete confirmation | A dialog that names the selected domains only | Read-only deletion preview (FR-065), an addition |
| Tag suggestions | None | Distinct tag list in scope (FR-066), an addition |
| Domain mass actions | `edit/domain` and `delete/domain` take a list of domains; processing continues past failures | Bulk action with one result per domain (FR-062, FR-063) |
| Template mass delete and multi-domain footers | `delete/domain/template` with a list stops after the first template; `edit/domain/footer` applies one footer to a list of domains | One request per template and per footer (FR-062). **Deviation** |
| UI log entries | Written to the `logs` table | Written to this API's audit log (FR-057), as in feature 001. **Deviation** |
| DKIM key size | Any number the key generator accepts | 1024, 2048, 3072 or 4096, as the forms offer (FR-020). **Deviation** |
| Default DKIM selector | `dkim` upstream, `feldhost` in the fork | Server setting, default `dkim` (FR-028) |
| ISPConfig DKIM DNS push (fork) | Always attempted when the config file exists; failures ignored silently | Server setting, off by default; outcome reported (FR-025, FR-027) |
| Groupware directory placeholder password (plan-level note) | `update_sogo_static_view()` means to write a random `{SSHA256}` hash into `_sogo_static_view.c_password` to deny direct groupware logins, but builds it from undefined variables, so every row gets the same hash of an empty password | A fresh random hash per rebuild (FR-034, FR-036). **Deviation** (bug fix); the next legacy rebuild writes its own value |
| Order of the stored-mail move on deletion (plan-level note) | The move to the garbage area is requested before the rows are deleted | Requested after the rows are deleted and committed, so a refused or failed deletion never moves mail; its outcome is still recorded in the audit record (FR-039) |
| Footer exclusion spelling (plan-level note) | Stored as sent | Trimmed and lower-cased, with the domain part in ASCII, as the mail filter compares them exactly (FR-053) |

### Key Entities *(include if feature involves data)*

- **Domain** (existing mailcow data): a mail domain with its name, description, active state,
  limits, quotas, relay options, Global Address List flag, relayhost assignment, creation and
  modification times. It owns tags, aliases, alias domains, mailboxes, resources and a footer. Its
  rate limit and DKIM key are kept in mailcow's shared store.
- **Domain usage** (computed): mailboxes, resources, aliases and alias domains in use, quota
  allocated, the largest existing mailbox quota, storage and messages in use, and the quotas
  available to a new mailbox.
- **Domain tag** (existing mailcow data): a label attached to one domain, used for search and
  filtering.
- **Domain template** (existing mailcow data, in a table shared with mailbox templates): a named set
  of values that pre-fill new domains. The template named "Default" is protected.
- **Domain footer** (existing mailcow data): HTML text, plain text, reply handling and exclusions for
  one domain.
- **Domain administrator assignment** (existing mailcow data, managed in feature 007): links a domain
  administrator to a domain, with an active state.
- **Creation report**: the outcome of each follow-up step of one domain creation.
- **Bulk action** (transient): one action on up to 100 domains, each optionally with a version
  tag, with one result per domain.
- **Domain capabilities** (computed from the server configuration): the groupware state, the
  limits, and for administrators the DKIM defaults and the DNS publication setting.
- **Deletion preview** (computed): what a deletion of one domain would be blocked by, remove and
  keep, with the domain's version tag.
- **Tag summary** (computed): one distinct tag in the caller's scope and the number of domains that
  carry it.
- **Audit record** (owned by this API, defined in feature 001): extended with the target type, the
  target identifier, the attribute changes and the follow-up step outcomes.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For 100% of the domains in the parity test set, the attributes and usage figures
  returned by the API equal those the legacy UI shows for the same data.
- **SC-002**: A domain created through the API with the same input as through the legacy UI leaves
  identical stored data and shared-store entries, apart from timestamps and generated key material,
  and causes the same outbound service calls.
- **SC-003**: After a domain is deleted through the API, a scan of the mailcow data finds no row or
  shared-store entry that refers to the domain or its alias domains, apart from its retained DKIM
  key.
- **SC-004**: 100% of attempts by domain administrators to read or change a domain outside their
  scope get the same response as for a domain that does not exist, and 100% of attempts by mailbox
  users to use any domain operation are refused.
- **SC-005**: In 95% of requests, a page of 25 domains with usage figures is returned within
  1 second on a server with 5,000 domains and 100,000 mailboxes.
- **SC-006**: 100% of changes sent with an outdated version tag are refused, and no change sent with
  a current version tag overwrites a change it did not see.
- **SC-007**: Every request in FR-057 produces exactly one audit record per affected domain,
  template or footer.
- **SC-008**: An administrator can create a domain from a template in the new web app in under
  1 minute, and the domain accepts mail for a new mailbox within 1 minute of its creation.
- **SC-009**: 100% of refused creations and edits name every invalid field with a stable rule code,
  so that the web app can show each error next to its field.
- **SC-010**: A bulk action on 100 domains completes within 10 seconds (deletions within 60
  seconds) and reports exactly one result per domain.
- **SC-011**: When nothing changes between a deletion preview and the deletion, the deletion
  removes exactly what the preview listed, or is refused for exactly the blocking objects it
  listed, in 100% of test runs.
- **SC-012**: Each domain capability equals the server's configuration in 100% of test runs. This
  includes the groupware enabled and disabled, upstream and FeldHost selectors, and a changed
  tagging limit.

## Assumptions

- Domain administrator accounts and their domain assignments are created and edited in feature 007.
  This feature only reads them, apart from the cleanup on domain deletion (FR-038).
- Relayhost assignment (feature 009), rate limit changes (feature 006), DKIM key management, DNS
  diagnostics and MTA-STS (feature 010), spam allow and block lists (feature 006) and the quota
  warning BCC (feature 013) are edited in their own features. The new web app can combine them on
  one domain screen once those features exist.
- Mailbox, alias and alias domain operations, including the enforcement of domain limits when they
  are created, belong to features 003 and 004. They use the usage figures defined here.
- The groupware restart and the move of stored mail use mailcow's container service. Postfix and
  Dovecot read the domain data directly, so they need no further action.
- The Default template is seeded by mailcow on start. This API never seeds templates.
- The legacy UI never sends version tags, so it can still overwrite changes made through this API.
  Only clients of this API are protected against lost updates.
- These defaults can be changed by the operator: the tagging limit (25), the default DKIM selector
  (`dkim`) and the DKIM DNS publication (off). The list page size (25, maximum 1,000) and the bulk
  limit (100) are fixed for every list and bulk action of the API (`docs/conventions.md`, FR-011,
  FR-062). Clients read all of these values through the domain capabilities (FR-064).
- Footer exclusion candidates are not offered by this feature. The web app picks them from the
  mailbox and alias lists of features 003 and 004.
- **Out of scope**:
  - mailboxes, aliases, alias domains and resources;
  - relayhost assignment, rate limit changes, DKIM management, MTA-STS, spam policies and quota
    warning BCC;
  - domain administrator management;
  - DNS diagnostics;
  - bulk creation, and bulk actions on templates and footers;
  - restarting the groupware outside domain creation.
- **Dependencies**:
  - the upstream mailcow database schema of the supported release;
  - mailcow's shared store (domain map, rate limits, DKIM keys);
  - mailcow's container service, for the groupware restart and the move of stored mail;
  - the groupware directory view and its cache;
  - for the fork-only DKIM DNS publication, an ISPConfig server and feature 010's specification of
    the publication;
  - feature 001, for identity, roles, permission flags, API keys, errors and the audit log.
