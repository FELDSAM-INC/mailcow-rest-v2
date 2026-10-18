# Feature Specification: Quarantine, Spam Policy and Rate Limits

**Feature Branch**: `main` (no feature branches, constitution 1.1.0)

**Created**: 2026-10-18

**Status**: Draft

**Input**: Feature description built from `docs/roadmap.md` (feature 006 and allocation row 006):

> Quarantine, spam policy and rate limits for mailcow REST API v2. Mailbox users review the
> messages that mailcow's spam filter kept in quarantine for their mailbox, inspect them, release
> them to the inbox, learn them as spam or ham, and delete them. They choose how often, and for
> which messages, they are notified about new quarantine items. Administrators and domain
> administrators do the same for the mailboxes in their scope, including in bulk. Administrators
> configure the quarantine system: retention, size and age limits, the release format, and the
> notification sender, recipients, subject and template. Spam filtering is tuned per mailbox with
> spam score thresholds, and with allow and block lists for mailboxes, domains and alias domains.
> Administrators and permitted domain administrators set sending rate limits for domains, alias
> domains and mailboxes. Administrators reset rate-limit buckets and read the spam filter's action
> statistics. Every operation is gated by the legacy permission flags.
>
> Allocated legacy surface: quarantine items and actions (`quarantine`, `qitem`, release, learn,
> delete), quarantine settings and per-mailbox notification settings (`edit/quarantine*`), spam
> score (`spam-score`), Rspamd actions (`rspamd/actions`), allow/block lists (`domain-policy`,
> `mailbox-policy`, `policy_{wl,bl}_*`), rate limits (`rl-domain`, `rl-mbox`, `edit/ratelimit`,
> `delete/rlhash`). The legacy quarantine page, its item detail view and downloads, the quarantine
> settings tab, the spam filter tabs of the user and domain pages, the rate limit tabs of the
> domain, alias domain and mailbox pages, the mailbox list's quarantine notification mass actions
> and the dashboard's action chart and rate-limit reset need the same data and actions.
>
> Constraints: every legacy side effect is reproduced, so Postfix, Dovecot and Rspamd behave the
> same whichever implementation made the change. The legacy UI and `/api/v1` keep working on the
> same data. Quarantine ingestion, notification sending, the cleanup of aged items and the quick
> release and delete pages behind notification links stay in the legacy components and keep
> working unchanged. The feature builds on feature 001's identity, roles, permission flags, API
> keys, error conventions and audit log, and on the domain and mailbox conventions of features 002
> and 003.

## Clarifications

### Session 2026-10-18

- Q: Does the `quarantine` permission flag control who may view quarantine items, or only who may
  act on them? → A: Only who may act. Every role lists and inspects the items in its scope,
  including their text. `quarantine` gates releasing, learning, deleting and downloading the whole
  message. `quarantine_attachments` gates attachment details and attachment downloads (FR-006,
  FR-013). (agent decision: legacy lets every role view items whatever its flags, and the schema
  documents `quarantine` as "quarantine actions". The legacy UI hides attachment details without
  `quarantine_attachments`, so the API does not return them either.)
- Q: May a mailbox user without the attachments permission download the whole message, which
  contains the attachments? → A: No. Downloading the whole message needs `quarantine`, because the
  legacy UI offers it only with that flag, and for mailbox users also `quarantine_attachments`
  (FR-006, FR-017). (agent decision: the legacy server checks no flag for this download, so anyone
  can bypass the attachment restriction. Constitution VI. The legacy UI's gate is kept.)
- Q: Should the API offer the legacy quick release (delivery without learning) and the hash links
  from notifications? → A: The release has a "learn as ham" option. It is on by default, as in the
  legacy "Deliver to inbox" action, and turning it off gives the legacy quick release. The
  hash-based pages stay in php-fpm, and the API never returns an item's hash (FR-015, FR-022).
  (agent decision: signed-in users get parity without the API exposing a secret that releases or
  deletes mail without sign-in. The pages are internal endpoints that constitution V keeps in
  php-fpm.)
- Q: Legacy deletes an item before asking the spam filter to learn it as spam, so a failed learning
  loses the item. Should the API do the same? → A: No. The item is removed only after spam learning
  succeeded or was answered "already learned". Otherwise it stays, and the request fails with a
  temporary failure. A release still removes the item once the message is delivered, and a later
  ham learning failure is reported without undoing the release (FR-021 to FR-023). (agent decision:
  losing mail during a temporary filter outage is a legacy defect, and constitution IV allows a
  stated deviation. A delivered message cannot be taken back, so the release keeps its order.)
- Q: Which legacy mass actions become bulk requests? → A: The quarantine item actions (release with
  or without learning, learn as spam, delete) and the mailbox notification frequency and category,
  each for up to 100 targets with one result per target, like feature 003's bulk actions (FR-027,
  FR-037). List entries and rate limits are changed one per request. (agent decision: the legacy
  quarantine page and mailbox list have these mass actions, and feature 003 set the pattern of 100
  targets. Legacy list removal accepts several entries, but lists are short, and one request per
  entry keeps the responses simple.)
- Q: Legacy silently changes invalid quarantine settings: an invalid address becomes empty, an
  unknown release format becomes raw, and a maximum age of 0 becomes 365. Should the API do the
  same? → A: No. Invalid values are refused with a validation error naming the field. Empty values
  keep their documented legacy meaning (FR-030, FR-031). (agent decision: a client must never
  believe a value was saved when it was not. Feature 002 refuses silently ignored values for the
  same reason.)
- Q: What range may a spam score have? → A: Both scores range from 0 to 5,000 with at most one
  decimal, and the junk score must not be above the reject score. Equal scores raise the reject
  score by 0.1, as in legacy (FR-040). (agent decision: these are the limits of the legacy slider.
  Legacy accepts any numbers through `/api/v1`, including a junk score above the reject score,
  which gives the filter contradictory settings.)
- Q: Can domain allow and block lists and domain rate limits be set on alias domains? → A: Yes, as
  in legacy. Domain administrators reach the alias domains of their domains (FR-001, FR-043,
  FR-053). (agent decision: legacy's domain access check accepts alias domains, the legacy alias
  domain page has a rate limit form, and the filter's settings map applies an alias domain's entries
  to that alias domain.)
- Q: Do domain administrators need the `spam_score`, `quarantine_notification`,
  `quarantine_category` and `quarantine_attachments` flags? → A: No. These flags exist only for
  mailbox users, and domain administrators always hold them, as in legacy. `quarantine`,
  `spam_policy` and `ratelimit` are checked against the domain administrator's own flags (FR-005).
  (agent decision: legacy builds a domain administrator's permissions from all mailbox flags set,
  overridden by the domain administrator flags of the same name. Feature 003 lists the same five
  overlapping flags, FR-035 there.)
- Q: What happens when two clients release the same item at the same time? → A: Exactly one request
  delivers and learns the message. The other is answered "not found" once the item is gone, or
  refused with an "item is being processed" conflict while the first runs (FR-026). (agent
  decision: legacy has no protection and can deliver a message twice, and learning it twice skews
  the filter's statistics.)
- Q: Do bulk actions check version tags, and which representations carry one? → A: Each bulk target
  may carry the version tag the client last read. An outdated tag gives that target the outcome
  "outdated" while the others proceed, and a target without a tag is not version-checked. Every list
  entry carries its version tag: quarantine items and allow or block list entries, which are never
  changed, carry a tag over their identifying stored values, which stays the same for as long as
  they exist. Single item actions and entry removals accept the tag too (FR-027, FR-037, FR-059,
  FR-060). (orchestrator decision: `docs/conventions.md` "Bulk actions" and "Version tags" apply to
  every feature and replace the earlier rule that bulk actions do not check versions.)
- Q: Since when do the spam filter's action statistics count? → A: The statistics state the start
  of the counting period when the spam filter reports it, and "unknown" otherwise. They also state
  since when the spam filter has been running, with the note that its counters survive restarts
  until they are reset (FR-058). (orchestrator decision: the UI 006 spec found that the statistics
  page cannot say what period the counts cover.)
- Q: Should the API check the notification template before saving it, and can an administrator see
  how it renders? → A: Yes. Saving a template checks it the way the notifier parses and renders it,
  with sample data, and refuses a template the notifier cannot use with a validation error naming the
  field and the reason. The settings change accepts a validate-only option that applies every rule
  but stores nothing and writes no audit record, as feature 013 FR-093 does. A preview operation
  renders a given or the stored template with sample data and returns the result as text: the
  rendered source and its plain-text version. Clients show the rendering as source and never as live
  HTML (FR-032, FR-070, FR-071). (orchestrator decision: the UI 006 spec found that an administrator
  can save a template the notifier silently replaces with the default.)
- Q: How do clients learn the limits and server settings that shape the forms before they submit?
  → A: A read-only "quarantine capabilities" operation, open to every role and to read-only keys,
  returns the bulk limit, the page sizes, the content size limit, the spam score range and step, the
  pattern rules of allow and block lists, the rate limit range and time units, the limits of the
  quarantine settings, and whether virus scanning is enabled (FR-072). (orchestrator decision: the
  pattern of feature 002 FR-064, so that clients never hard-code server limits.)

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Review and act on my own quarantine (Priority: P1)

A mailbox user opens the quarantine in the new web app. They see the messages the spam filter kept
for their mailbox: subject, sender, spam score, whether the message was rejected or moved to the
junk folder, whether a virus was found, whether a notification already mentioned it, and when it
arrived. They search and filter the list and open a message to see why it was caught (the filter's
symbols and score), who sent it, to whom it was addressed and its text, without any risk of HTML or
scripts in the message running in their browser.

If the message is wanted, they release it to their inbox. By default the filter also learns it as
ham. They can release it without learning, as the legacy "quick release" link does. If the message
is spam, they learn it as spam, which also removes it, or they simply delete it. When their
permissions allow, they see and download its attachments, or download the whole message.

**Why this priority**: Recovering a wrongly caught message is the most frequent and most urgent
quarantine task, and it is what mailbox users use the quarantine for.

**Independent Test**: Load a test copy of the mailcow database with quarantine items for several
mailboxes, including rejected and junk items, virus items, items with attachments, an item over
10 MiB and an unparseable item. As a mailbox user with each combination of the `quarantine` and
`quarantine_attachments` flags, list, inspect, download, release (with and without learning), learn
as spam and delete. Compare the delivered messages, the remaining items and the filter calls with
what the legacy UI produces for the same items.

**Acceptance Scenarios**:

1. **Given** 30 quarantine items for alice@example.org and 5 for bob@example.org, **When** alice
   lists her quarantine without parameters, **Then** she receives her 25 most recent items, newest
   first, with a total of 30, and none of bob's.
2. **Given** an item rejected because a virus was found, **When** alice inspects it, **Then** she
   sees its score, the action "rejected", the virus flag, the filter's symbols with their scores,
   the subject, the From header, the envelope sender, the header and envelope recipients, the plain
   text and the text derived from the HTML part, and no HTML.
3. **Given** alice holds the `quarantine` flag and the release format is "unmodified original",
   **When** she releases an item, **Then** the message is delivered to her inbox with its
   `X-Spam-Flag` header renamed `X-Pre-Release-Spam-Flag`, the item is removed, the filter learns
   the message as ham and stores its fuzzy hashes as wanted, and the response reports each step.
4. **Given** the same item, **When** she releases it without learning, **Then** it is delivered and
   removed, and the filter is not called.
5. **Given** a spam item, **When** she learns it as spam, **Then** the filter learns it as spam and
   stores its fuzzy hashes as unwanted, the item is removed, and the response reports each step.
6. **Given** alice lacks the `quarantine` flag, **When** she releases, learns, deletes or downloads
   the whole message, **Then** the request is refused as forbidden and the error names the flag.
   **When** she lists and inspects items, **Then** that works.
7. **Given** alice lacks the `quarantine_attachments` flag, **When** she inspects an item with two
   attachments, **Then** no attachment details are returned. **When** she downloads an attachment
   or the whole message, **Then** the request is refused as forbidden and the error names the flag.
8. **Given** the identifier of one of bob's items, **When** alice inspects, releases or deletes it,
   **Then** the response is the same "not found" as for an item that does not exist.
9. **Given** an item larger than 10 MiB, **When** alice inspects it, **Then** she receives its
   summary and the filter's results, and the content is reported as unavailable because the message
   is too large.

---

### User Story 2 - Handle the quarantine of all mailboxes in my scope (Priority: P1)

An administrator, or a domain administrator for the domains they manage, works through the
quarantine of many mailboxes. They filter by recipient, domain, action, virus flag, notification
state, arrival time and score, search by subject, sender, recipient or queue identifier, and sort
by any column the legacy list sorts by. They select many items and release them, learn them as
spam or delete them in one request, and get one result per item. A security analyst downloads a
suspicious attachment or the whole message for analysis, without the browser ever displaying or
executing it.

**Why this priority**: Administrators and resellers handle quarantine requests for their customers
and clean up large amounts of quarantined mail. Bulk actions make that practical.

**Independent Test**: As an administrator, as a domain administrator with and without the
`quarantine` flag, and with read-only and read-write keys owned by each, list, filter, search,
sort, download and run bulk actions on items across several domains. Run concurrent releases of the
same item. Make the spam filter unreachable during learning.

**Acceptance Scenarios**:

1. **Given** a domain administrator who manages two of five domains, **When** they list the
   quarantine, **Then** they receive exactly the items whose recipient mailbox belongs to those two
   domains. **When** they filter by one recipient, by the action "moved to junk" or by the virus
   flag, **Then** only matching items are returned with the matching total.
2. **Given** 40 selected items, one of which was removed in the meantime, **When** an administrator
   releases them in one request, **Then** 39 are delivered and reported as done, the removed one is
   reported as not found, and each item gets its own audit record.
3. **Given** a domain administrator without the `quarantine` flag, **When** they send a bulk
   deletion, **Then** the whole request is refused as forbidden, naming the flag, and nothing is
   removed. **When** they list and inspect items, **Then** that works.
4. **Given** an item whose attachment is an HTML file, **When** an administrator downloads it,
   **Then** it is delivered as a file to save, with its original name made safe, and it is never
   displayed or run in the web app's origin.
5. **Given** two administrators who release the same item at the same moment, **When** both
   requests are processed, **Then** the message is delivered exactly once, and the other request is
   answered "not found" or refused because the item is being processed.
6. **Given** a read-only key owned by a domain administrator with the `quarantine` flag, **When** a
   client lists items and downloads a whole message with it, **Then** both succeed within that domain administrator's scope.
   **When** it releases an item, **Then** the request is refused with the "insufficient key scope"
   error.
7. **Given** a spam filter that cannot be reached, **When** an administrator learns an item as
   spam, **Then** the item stays in the quarantine and the request is refused with a temporary
   failure.
8. **Given** a bulk release in which one target carries a version tag that no longer matches,
   **When** it is processed, **Then** that target is reported as outdated and left unchanged, and
   the other targets are released.
9. **Given** any role, including a read-only key, **When** it reads the quarantine capabilities,
   **Then** it receives the bulk limit, the page sizes, the content size limit, the spam score range,
   the list pattern rules, the rate limit range and whether virus scanning is enabled.

---

### User Story 3 - Configure the quarantine system (Priority: P2)

An administrator configures how the quarantine works: how many items are kept per mailbox, the
largest message that is quarantined, the maximum age of items, which domains are never quarantined,
whether released messages are delivered unmodified or as an attachment, and the notification
e-mails: the score above which items are left out, the sender, the subject, an optional copy (BCC)
recipient, an optional redirect recipient, and the HTML template. Mailbox users and domain
administrators see only whether the quarantine is active and its limits, as on the legacy
quarantine page.

**Why this priority**: The quarantine is inactive until an administrator sets a retention and a
maximum size, but the settings change rarely and the legacy UI can still change them.

**Independent Test**: Read the settings on a fresh server, change each setting with valid and
invalid values, restore the default template, and confirm that the legacy settings tab, the
quarantine ingestion and the notifier use the values saved through the API.

**Acceptance Scenarios**:

1. **Given** a server on which no quarantine setting was ever saved, **When** an administrator
   reads the settings, **Then** the quarantine is reported inactive, with a retention of 0, a
   maximum size of 0, a maximum age of 365 days, the release format "unmodified original", no
   notification score limit, the sender `quarantine@localhost`, the subject "Spam Quarantine
   Notification", no copy or redirect recipient, and the default template marked as the default.
2. **Given** that state, **When** an administrator sets a retention of 20 items and a maximum size
   of 15 MiB, **Then** the quarantine is reported active, and the legacy settings tab shows the same
   values.
3. **Given** a copy recipient that is not a valid e-mail address, **When** the settings are saved,
   **Then** the request is refused with a validation error naming the field, and nothing changes.
4. **Given** a custom template, **When** an administrator saves an empty template, **Then** the
   default template is restored.
5. **Given** an excluded domain that is neither a domain nor an alias domain on the server, **When**
   the settings are saved, **Then** the request is refused with a validation error naming the
   entry.
6. **Given** two administrators editing the settings, **When** the second saves with the version
   tag they read before the first saved, **Then** the save is refused as outdated.
7. **Given** a domain administrator or a mailbox user, **When** they read the settings, **Then**
   they receive only whether the quarantine is active, the retention per mailbox and the maximum
   size. **When** they change them, **Then** the request is refused as forbidden.
8. **Given** a template with an unclosed `{% for %}` block, or one that reads an attribute the
   notifier's sandbox forbids, **When** an administrator saves it, **Then** the request is refused
   with a validation error on the template field naming the reason and the line, and nothing
   changes.
9. **Given** a valid template sent with the validate-only option, **When** it is checked, **Then**
   success is reported, the stored template is unchanged and no audit record is written.
10. **Given** the default template, **When** an administrator previews it with quick actions,
   **Then** they receive the rendered HTML as text, its plain-text version and the subject, built
   from the documented sample data, including the release and delete links.

---

### User Story 4 - Choose quarantine notifications for a mailbox (Priority: P2)

A mailbox user decides how often they are told about new quarantine items (never, hourly, daily or
weekly) and for which items (rejected mail, mail moved to the junk folder, or both), as far as
their permissions allow. An administrator or domain administrator sets the same values on a
mailbox's page, or for many mailboxes selected in the mailbox list.

**Why this priority**: Notifications are how most users learn about wanted mail in the
quarantine, but sensible defaults exist from mailbox creation.

**Independent Test**: Change the frequency and category as a mailbox user with and without each
flag, as a domain administrator and as an administrator, singly and in bulk, and confirm that the
legacy pages show the values and that the notifier uses them.

**Acceptance Scenarios**:

1. **Given** a mailbox user with the `quarantine_notification` flag, **When** they choose daily
   notifications, **Then** the value is saved, the legacy user page shows "daily", and the notifier
   sends them at most one notification per day.
2. **Given** a mailbox user without the `quarantine_category` flag, **When** they change the
   category, **Then** the request is refused as forbidden and the error names the flag.
3. **Given** a domain administrator who selects 30 mailboxes of their domains and one mailbox of
   another domain, **When** they set weekly notifications in one request, **Then** the 30 mailboxes
   are reported as done and the other one as not found.
4. **Given** a mailbox user, **When** they read their own notification settings, **Then** they
   receive the frequency, the category and a version tag. **When** they read another mailbox's,
   **Then** the response is "not found".

---

### User Story 5 - Tune spam filtering for a mailbox or a domain (Priority: P2)

A mailbox user sets their spam score: the score from which mail is moved to the junk folder and
the score from which it is rejected, or returns to the server's defaults. They keep an allow list
of senders that are never treated as spam and a block list of senders that are always rejected,
with wildcards. They see the entries their domain administrator set for the whole domain, but they
cannot remove them. Administrators and domain administrators keep the same lists for domains and
alias domains, and manage mailbox lists and spam scores on behalf of users.

**Why this priority**: Spam tuning reduces both missed spam and wrongly caught mail, but mailcow's
defaults work without it.

**Independent Test**: Read and change spam scores and add and remove list entries for mailboxes,
domains and alias domains as each role, with and without the `spam_score` and `spam_policy` flags.
Confirm that the legacy pages show the same data and that the spam filter applies it within one
minute.

**Acceptance Scenarios**:

1. **Given** a mailbox without a custom spam score on a server whose filter moves mail to junk at 8
   and rejects at 15, **When** its user reads the spam score, **Then** they receive 8 and 15,
   marked as the server defaults.
2. **Given** the `spam_score` flag, **When** the user sets 6.5 and 12, **Then** the values are
   saved, and mail for the mailbox scoring 12 or more is rejected within one minute.
3. **Given** a request with a junk score of 12 and a reject score of 6, **When** it is submitted,
   **Then** it is refused with a validation error, and the stored values are unchanged.
4. **Given** a custom spam score, **When** the user resets it, **Then** the mailbox uses the server
   defaults again.
5. **Given** the `spam_policy` flag, **When** the user adds `*@spam.example` to their block list,
   **Then** the entry is created. **When** they add the same pattern to their allow list, **Then**
   the request is refused as a conflict that names the existing block list entry.
6. **Given** a domain block list entry, **When** a mailbox user of that domain lists their block
   list, **Then** the domain entry is included and marked as inherited. **When** they remove it,
   **Then** the response is "not found".
7. **Given** a domain administrator with the `spam_policy` flag, **When** they add an allow list
   entry for an alias domain of one of their domains, **Then** the entry is created and applies to
   mail for that alias domain.
8. **Given** a domain administrator without the `spam_policy` flag, **When** they add a domain
   entry, **Then** the request is refused as forbidden. **Given** a mailbox user, **When** they read
   or change a domain's lists, **Then** the request is refused as forbidden.
9. **Given** the pattern `user+tag@example.org`, **When** it is added, **Then** it is refused with a
   validation error, because legacy patterns allow only letters, digits, `@`, `_`, `.`, `-` and
   `*`.

---

### User Story 6 - Limit how much a domain or mailbox may send (Priority: P2)

An administrator, or a domain administrator with the `ratelimit` flag, limits how many messages a
domain, an alias domain or a mailbox may send per second, minute, hour or day, for example to
contain a compromised account. A mailbox's own limit overrides its domain's limit. An administrator
who sees in the rate-limited log that a sender was blocked resets that rate-limit bucket, so the
sender can send again at once.

**Why this priority**: Rate limits protect the server's reputation, but they are an exceptional
measure and the legacy UI can still set them.

**Independent Test**: Set, read, list and remove limits for domains, alias domains and mailboxes as
each role, with and without the `ratelimit` flag. Send test mail and confirm that the filter
enforces the limits. Reset buckets that exist and that do not.

**Acceptance Scenarios**:

1. **Given** a domain administrator with the `ratelimit` flag, **When** they set 100 messages per
   hour on their domain, **Then** the limit is saved, and a mailbox of that domain without its own
   limit reports an effective limit of 100 per hour coming from the domain.
2. **Given** that domain limit, **When** the domain administrator sets 10 messages per minute on a
   mailbox, **Then** the mailbox reports 10 per minute coming from the mailbox. **When** they remove
   the mailbox limit, **Then** it reports the domain limit again.
3. **Given** a limit of 0 or a negative limit, **When** it is submitted, **Then** it is refused
   with a validation error.
4. **Given** a domain administrator without the `ratelimit` flag, **When** they set a limit,
   **Then** the request is refused as forbidden. **When** they read limits, **Then** that works.
5. **Given** a mailbox user, **When** they read or change any rate limit, **Then** the request is
   refused as forbidden.
6. **Given** a bucket identifier from the rate-limited log, **When** an administrator resets it,
   **Then** the bucket's counters and its log entries are removed. **When** they reset an
   identifier that matches nothing, **Then** the response is "not found". **When** a domain
   administrator tries, **Then** the request is refused as forbidden.
7. **Given** limits on 3 domains, 1 alias domain and 4 mailboxes, **When** an administrator lists
   rate limits filtered to mailboxes, **Then** exactly the 4 mailbox limits are returned.

---

### User Story 7 - See how the spam filter handled mail (Priority: P3)

On the dashboard, an administrator sees how many messages the spam filter rejected, soft-rejected,
marked by rewriting the subject, moved to the junk folder or let through.

**Why this priority**: It is an overview for administrators. Nothing else depends on it.

**Independent Test**: Read the statistics as each role, with the spam filter reachable and
unreachable, and compare the counts with the legacy dashboard chart.

**Acceptance Scenarios**:

1. **Given** a reachable spam filter, **When** an administrator reads the statistics, **Then** they
   receive a count for each action, with greylisted messages counted as soft-rejected, as on the
   legacy dashboard.
2. **Given** an unreachable spam filter, **When** an administrator reads the statistics, **Then**
   the request is refused with a temporary failure.
3. **Given** a domain administrator, **When** they read the statistics, **Then** the request is
   refused as forbidden.
4. **Given** a spam filter that reports its uptime but not when its counters started, **When** an
   administrator reads the statistics, **Then** the counting period start is reported as unknown
   and the time since which the filter has been running is reported.

---

### User Story 8 - Trace quarantine and spam policy changes (Priority: P3)

An administrator investigating a complaint ("my mail disappeared", "who allowed this sender?")
lists the audit log filtered by a mailbox, a domain, a quarantine item or the settings, and sees
who released, learned or deleted what, who changed which list, score, limit or setting, when, from
where, and with which outcome.

**Why this priority**: The constitution requires an audit record for every change, but the
features work without reading the log.

**Independent Test**: Make each kind of change, including refused ones, and list the audit log
filtered by target.

**Acceptance Scenarios**:

1. **Given** a released item, **When** an administrator lists audit records for that item, **Then**
   the entry shows the actor, time, client address, queue identifier, recipient, envelope sender
   and the outcome of each step, and no subject, body or attachment.
2. **Given** a change of the notification template, **When** the audit record is listed, **Then**
   it records that the template changed, with its old and new sizes, and not its text.
3. **Given** a domain administrator, **When** they request the audit log, **Then** the request is
   refused as forbidden.

---

### Edge Cases

- **Quarantine inactive** (retention or maximum size is 0): existing items can still be listed and
  acted on. Activating the quarantine does not bring back mail that arrived while it was inactive.
- **Item whose recipient mailbox no longer exists**: it stays visible to administrators and to the
  domain administrators of the recipient's domain. A release fails with the "delivery refused"
  error, and the item is kept.
- **Item addressed to a resource** (location, thing or group): visible and actionable for
  administrators and domain administrators of its domain, like any item.
- **Item with no stored envelope sender**: it is released with the sender `sender-unknown@rspamd`,
  as in legacy.
- **Item without a score or subject**: reported with no score and an empty subject.
- **Item larger than 10 MiB**: summary and filter results only. Content, attachment and whole
  message downloads are refused with the "message too large" error.
- **Message whose parts cannot be extracted**: the text derived from the raw body is returned, and
  the plain-text part is reported as unavailable.
- **Attachment with a dangerous type** (HTML, SVG, script, executable): delivered only as a file to
  save, never displayed or run in the web app's origin.
- **Attachment name with path separators, control characters or no name**: the name is made safe,
  and an unnamed attachment gets a generic name with its position.
- **Unknown attachment position**: "not found".
- **Concurrent actions on one item**, through this API or the legacy UI: the message is delivered
  and learned at most once by this API. The legacy UI does not take part in that protection.
- **Mail server refuses the message during a release**: the item is kept, nothing is learned, and
  the request fails with the "delivery refused" error and the mail server's answer. **Mail server
  unreachable**: the same, with a temporary failure naming the delivery step.
- **Spam filter unreachable** during a release with learning: the message is released and removed,
  and the action report says learning failed. During learning as spam: the item is kept and the
  request fails with a temporary failure.
- **Message already learned** by the filter: reported as "already learned", which counts as success.
- **Message without content for fuzzy hashes**: the fuzzy step is reported as skipped.
- **No release format stored** (it is normally seeded by mailcow): "unmodified original" is used.
  (Legacy then removes the item without delivering it.)
- **Bulk action naming the same item or mailbox twice**: it is processed and reported once.
- **Bulk action with more than 100 targets**: refused with a validation error that states the
  limit.
- **Version tag sent with a bulk action**: checked per target. An outdated tag gives that target
  the outcome "outdated", and the other targets proceed. A target without a tag is not checked.
- **Notification for an item that was released or deleted through this API**: the item is gone, so
  later notifications skip it, and the quick links in notifications already sent stop working.
- **Mailbox without stored notification values**: reported with the frequency "never" and no
  category. It receives no notifications, as with legacy.
- **Notification template that the notifier cannot parse or render**: refused with a validation
  error naming the field and the reason (syntax, sandbox violation, rendering error or rendering
  timeout), and nothing changes. A template saved through the legacy UI is still stored as given
  there, and the notifier falls back to the default template when it cannot parse it, as in legacy.
- **Excluded domain that was later deleted**: it stays stored and is shown. It can be kept when the
  settings are saved unchanged, but it cannot be added again.
- **Spam score with only one of its two values stored**, for example by another tool: reported as
  custom, with the missing value taken from the server default. Saving a score or resetting it
  repairs it.
- **Server defaults for the spam score cannot be determined** (filter unreachable or a threshold
  not configured): 5 and 15 are reported, with an indication that they are fallback values.
- **Allow and block list pattern in upper case or with surrounding spaces**: stored trimmed and in
  lower case. A pattern of wildcards only, such as `*`, is refused.
- **Same pattern on the allow and the block list of one owner**: refused as a conflict, as in
  legacy. The same pattern may exist for different owners.
- **Mailbox entry seen in the domain's list, or domain entry seen in a mailbox's list**: shown
  read-only, and removable only through its owner.
- **Domain, alias domain or mailbox deleted in the meantime**: requests for its rate limit, lists,
  spam score or notification settings are answered "not found".
- **Rate limit value that does not match the legacy format** (written by another tool): reported as
  invalid, with the stored text, and it can be replaced or removed.
- **Removing a rate limit that is not set**: succeeds and changes nothing.
- **Rate-limit bucket that expired while its log entries remain**: the log entries are removed and
  the reset succeeds.
- **Domain administrator who loses a domain while editing**: the next request is answered "not
  found".
- **Concurrent edits from the legacy UI**: the legacy UI never sends a version, so a legacy save can
  still overwrite a change made through this API, as before.
- **Shared store unavailable**: changes to settings and rate limits and bucket resets are refused
  with a temporary failure and change nothing.
- **Audit record cannot be written**: the change it would record does not succeed. A release whose
  message the mail server already accepted cannot be taken back: the request then fails with an
  unexpected error and the complete record is written to the service log instead.

## Requirements *(mandatory)*

### Functional Requirements

#### Access and scope

- **FR-001**: Administrators MUST be able to reach every object of this feature. Domain
  administrators MUST be able to reach exactly the domains assigned to them through an active
  assignment, the alias domains of those domains, the mailboxes of those domains, and the quarantine
  items whose recipient mailbox belongs to those domains (legacy behaviour). Mailbox users MUST be
  able to reach only their own mailbox and the quarantine items addressed to it.
- **FR-002**: Scope and permission flags MUST be evaluated from the current mailcow data on every
  request, as in feature 001 (FR-029 there). An assignment or flag changed in either UI MUST apply
  to the next request.
- **FR-003**: An object that does not exist and an object outside the caller's scope MUST get the
  same "not found" response. An operation that the caller's role may never use MUST be refused as
  forbidden. An operation that the caller's role may use only with a permission flag the caller
  lacks MUST be refused as forbidden with an error that names the flag.
- **FR-004**: A request authenticated by an API key MUST act with the key owner's role, scope and
  flags (feature 001, FR-033). A read-only key MUST be refused on every operation that changes
  state, with the "insufficient key scope" error of feature 001 (FR-039). Reading and downloading
  are not changes. A restricted session (feature 001, FR-014) MUST be refused.
- **FR-005**: Permission flags MUST be taken as legacy takes them:
  - for a mailbox user, the mailbox's own flags: `quarantine` (quarantine actions),
    `quarantine_attachments`, `quarantine_notification`, `quarantine_category`, `spam_score` and
    `spam_policy`;
  - for a domain administrator, their own domain-administrator flags `quarantine`, `spam_policy`
    and `ratelimit`. The flags `quarantine_attachments`, `quarantine_notification`,
    `quarantine_category` and `spam_score` exist only for mailbox users, and domain administrators
    always hold them, as in legacy;
  - administrators hold every flag.

  The flags of the mailbox being acted on MUST NOT restrict administrators or domain
  administrators.
- **FR-006**: The operations MUST be authorised as follows:

  | Operation | Administrator | Domain administrator | Mailbox user |
  |---|---|---|---|
  | List and inspect quarantine items, including their text | All items | Items of mailboxes in own domains | Own items |
  | See attachment details and download attachments | Yes | Yes | With `quarantine_attachments` |
  | Download the whole message | Yes | With `quarantine` | With `quarantine` and `quarantine_attachments` |
  | Release, learn as spam, delete (single or bulk) | Yes | With `quarantine` | With `quarantine` |
  | Read quarantine settings | All settings | Public subset (FR-034) | Public subset (FR-034) |
  | Change quarantine settings | Yes | Forbidden | Forbidden |
  | Read a mailbox's notification settings | All mailboxes | Mailboxes of own domains | Own mailbox |
  | Change the notification frequency | Yes, also in bulk | Yes, also in bulk | Own mailbox, with `quarantine_notification` |
  | Change the notification category | Yes, also in bulk | Yes, also in bulk | Own mailbox, with `quarantine_category` |
  | Read a mailbox's spam score | All mailboxes | Mailboxes of own domains | Own mailbox |
  | Change or reset a spam score | Yes | Yes | Own mailbox, with `spam_score` |
  | Read the allow and block lists of a domain or alias domain | All | Own domains and their alias domains | Forbidden |
  | Add or remove domain and alias domain list entries | Yes | With `spam_policy` | Forbidden |
  | Read a mailbox's allow and block lists | All mailboxes | Mailboxes of own domains | Own mailbox |
  | Add or remove mailbox list entries | Yes | With `spam_policy` | Own mailbox, with `spam_policy` |
  | List and read rate limits | All | Own domains, their alias domains and mailboxes | Forbidden |
  | Set or remove a rate limit | Yes | With `ratelimit` | Forbidden |
  | Reset a rate-limit bucket | Yes | Forbidden | Forbidden |
  | Read the spam filter's action statistics | Yes | Forbidden | Forbidden |
  | Read the audit log | Yes | Forbidden | Forbidden |
  | Validate or preview the notification template | Yes | Forbidden | Forbidden |
  | Read the quarantine capabilities | Yes | Yes | Yes |

#### Quarantine items: listing and inspecting

- **FR-007**: A quarantine item MUST be represented in lists with:
  - its identifier;
  - the mail server's queue identifier;
  - its subject, empty when there is none;
  - its envelope sender;
  - its recipient mailbox;
  - its spam score, or none;
  - the spam filter's action: "rejected", "moved to junk", "subject rewritten", or the stored value
    for any other action;
  - its virus flag, set when the filter's results contain the `VIRUS_FOUND` symbol;
  - whether a notification already included it;
  - its arrival time.
- **FR-008**: Clients MUST be able to list the items in their scope with pagination. The default
  page size MUST be 25, as in legacy, and the maximum 1,000. The response MUST include the total
  number of matching items.
- **FR-009**: The list MUST support a free-text search that matches, case-insensitively and as a
  substring, the subject, the envelope sender, the recipient and the queue identifier.
- **FR-010**: The list MUST support these filters: recipient mailbox, recipient domain, action,
  virus flag, notification state, arrival time range, and spam score range.
- **FR-011**: The list MUST be sortable, ascending or descending, by arrival time, identifier, queue
  identifier, spam score, envelope sender, recipient, subject, action, virus flag and notification
  state. The default MUST be by arrival time, newest first. Ties MUST be broken by identifier,
  newest first.
- **FR-012**: A client MUST be able to inspect one item in its scope. The response MUST contain the
  list representation and:
  - the address of the server that sent the message;
  - the filter's symbols, each with its name, score and options;
  - the fuzzy hashes the filter computed;
  - the size of the stored message in bytes;
  - the parsed content (FR-013), unless the message is too large (FR-014);
  - for administrators only, the authenticated sender the filter recorded, if any.
- **FR-013**: The parsed content MUST contain:
  - the From header and the subject, decoded to UTF-8;
  - the recipients named in the To, Cc and Bcc headers, each with its header, plus the envelope
    recipient, marked as such;
  - the plain-text part;
  - the HTML part converted to plain text. HTML MUST NOT be returned;
  - when neither part can be extracted, the raw body converted to text, with the plain-text part
    reported as unavailable, as legacy does;
  - only for callers who may download attachments (FR-006): each attachment's position, file name,
    declared content type, size in bytes and SHA-256 hash. The contract MUST document that clients
    can build an external reputation lookup from the hash, as legacy links to VirusTotal.
- **FR-014**: For a stored message larger than 10 MiB (the legacy limit), the response MUST contain
  the summary and the filter's results, and MUST report the content as unavailable because the
  message is too large. Attachment and whole-message downloads of such an item MUST be refused with
  the "message too large" error.
- **FR-015**: No representation MUST contain the stored message itself, or the item's quick-action
  hash, which the legacy notification links use as a secret.

#### Quarantine items: downloads

- **FR-016**: A client permitted by FR-006 MUST be able to download one attachment of an item,
  addressed by its position in the parsed content. The file name MUST be the attachment's name with
  path separators and control characters removed. An unknown position MUST be answered "not found".
- **FR-017**: A client permitted by FR-006 MUST be able to download the whole stored message as an
  e-mail file (`message/rfc822`). The file name MUST be derived from the subject as legacy does,
  shortened to 30 characters and made safe, with the extension `.eml`, or from the queue identifier
  when the subject is empty.
- **FR-018**: Downloads MUST always be delivered as files to save. Browsers MUST NOT be allowed to
  display, sniff or run them within the web app's origin, whatever their declared type.

#### Quarantine items: actions

- **FR-019**: Releasing an item MUST deliver the stored message to the item's recipient mailbox
  through mailcow's internal mail path that bypasses the spam filter, as legacy does. The envelope
  sender MUST be the stored envelope sender, or `sender-unknown@rspamd` when none is stored.
- **FR-020**: The release MUST use the release format from the quarantine settings:
  - "unmodified original": the stored message, with only its `X-Spam-Flag` header renamed
    `X-Pre-Release-Spam-Flag`;
  - "attachment": a new message from the stored envelope sender to the recipient, with the subject
    "Potentially damaging quarantine item <queue identifier>", the body "We have attached your
    message as eml file to this message." and the stored message attached as
    `<queue identifier>.eml`. The subject and body MUST use the legacy translations in the acting
    user's language (feature 001, FR-030), the server's default language for API keys, and English
    when no translation exists.

  When no release format is stored, "unmodified original" MUST be used.
- **FR-021**: The item MUST be removed once the mail server has accepted the message. When the mail
  server refuses the message, the item MUST be kept, nothing MUST be learned, and the request MUST
  fail with the "delivery refused" error, which carries the mail server's answer. When the mail
  server cannot be reached, the same MUST apply with a temporary failure that names the delivery
  step.
- **FR-022**: A release MUST learn the message as ham unless the request turns learning off. The
  default, on, is the legacy "Deliver to inbox" action. Off is the legacy quick release. Learning
  MUST, after the release:
  - ask the spam filter to learn the message as ham;
  - then ask it to store the message's fuzzy hashes in the local "wanted" storage (legacy flag 13).

  An "already learned" answer MUST count as success, and a "no content to generate fuzzy hashes"
  answer MUST be reported as skipped. A learning failure MUST be reported in the action report
  (FR-025) and MUST NOT undo the release or bring the item back.
- **FR-023**: Learning an item as spam MUST:
  - ask the spam filter to learn the message as spam;
  - then ask it to store the message's fuzzy hashes in the local "unwanted" storage (legacy flag
    11);
  - then remove the item.

  The item MUST be removed only after the spam learning succeeded or was answered "already
  learned". When spam learning fails, the item MUST be kept and the request MUST fail with a
  temporary failure. A failure of the fuzzy step MUST be reported in the action report and MUST NOT
  keep the item.
- **FR-024**: Deleting an item MUST remove it permanently, without calling the spam filter. A
  successful deletion MUST respond with no body.
- **FR-025**: A release and a learning as spam MUST respond with an action report that gives the
  outcome of each step:
  - delivery: done, or not applicable;
  - learning: done, already learned, failed, or not requested;
  - fuzzy hashes: done, skipped, failed, or not requested.
- **FR-026**: An item MUST be released, learned or deleted by at most one request of this API.
  Concurrent requests for the same item MUST NOT cause a second delivery or learning. Exactly one
  MUST succeed. The others MUST be answered "not found" once the item is gone, or refused with the
  "item is being processed" conflict while the first one runs.
- **FR-027**: Administrators, and domain administrators and mailbox users permitted by FR-006, MUST
  be able to apply one action to up to 100 distinct items in one request: release with learning,
  release without learning, learn as spam, or delete.
  - The request MUST be refused as a whole when the caller's role, key scope or flags do not allow
    the action, and with a validation error stating the limit when it names more than 100 items.
  - Otherwise each item MUST be processed on its own, with the same checks, side effects and audit
    record as a single request for it.
  - Each item MAY carry the version tag the client read (FR-059). When it no longer matches, that
    item MUST get the outcome "outdated" and MUST NOT be changed. An item without a tag MUST NOT be
    version-checked.
  - The response MUST report one result per distinct item: done (with its action report), not
    found, refused (with the error type, for example an item being processed or a refused
    delivery), outdated, or failed (with the error type). A failure on one item MUST NOT stop or
    undo the others, as in legacy.
- **FR-028**: This API MUST NOT change an item's notification state. An item that is released,
  learned or deleted through this API is gone, so later notifications skip it, and the quick links
  in notifications already sent for it stop working, as when the legacy UI removes it.

#### Quarantine settings

- **FR-029**: Administrators MUST be able to read the quarantine settings and change them by sending
  only the settings they change. The settings are:
  - the retention per mailbox: how many of a mailbox's newest items are kept. 0 means inactive;
  - the maximum message size. Larger messages are not quarantined. 0 means inactive, not
    unlimited;
  - the notification score limit: items scoring at or above it are left out of notifications. None
    means 9999.0;
  - the maximum age of items, in days;
  - the excluded domains: domains and alias domains whose recipients' mail is never quarantined;
  - the release format: "unmodified original" or "attachment";
  - the notification sender and the notification subject;
  - the copy recipient, who receives a blind copy of every notification;
  - the redirect recipient, who receives every notification instead of the mailbox user;
  - the notification template (HTML).
- **FR-030**: The settings MUST meet these rules:
  - retention per mailbox: a whole number from 0 to 2,147,483,647;
  - maximum message size: in bytes, a whole multiple of 1 MiB, from 0 to 2,147,483,647 MiB. The
    contract MUST state that the mail pipeline hands no message larger than 10 MiB to the
    quarantine;
  - notification score limit: a decimal number, or none;
  - maximum age: a whole number of days from 1 to 2,147,483,647;
  - excluded domains: each the name of an existing domain or alias domain, normalised as in
    feature 002 (FR-006 there) and stored once. A stored entry whose domain no longer exists MAY be
    sent back unchanged;
  - release format: one of the two formats;
  - notification sender, copy recipient and redirect recipient: a valid e-mail address, or empty;
  - notification subject: at most 255 characters, or empty;
  - notification template: at most 1 MiB, or empty.

  An invalid value MUST be refused with a validation error naming the field, and nothing MUST
  change.
- **FR-031**: An unset or empty setting MUST mean what it means to the legacy components: retention
  0 and maximum size 0 (quarantine inactive), no notification score limit (9999.0), a maximum age of
  365 days, the release format "unmodified original", the sender `quarantine@localhost`, the subject
  "Spam Quarantine Notification", no copy recipient, no redirect recipient, and the default
  template. The settings MUST report whether the quarantine is active, which is the case when both
  the retention and the maximum size are above 0.
- **FR-032**: The settings MUST return the stored notification template, or the default template
  marked as the default when none is stored. Saving an empty template MUST restore the default. The
  contract MUST document the template's placeholders (`meta` with each item's `qhash`, `id`,
  `subject`, `score`, `sender`, `created` and `action`, plus `username`, `counter`, `hostname` and
  `quarantine_acl`), that the legacy notifier renders it in a sandbox, that it falls back to the
  default template when the template cannot be parsed, and that quick links built from `qhash` lead
  to the legacy quick release and delete pages. This API MUST NOT send notifications, and it renders
  the template only to validate it (FR-070) and to preview it (FR-071), with sample data.
- **FR-033**: The settings MUST be stored where legacy stores them, so that the quarantine
  ingestion, the notifier and the cleanup of aged items use them unchanged. The contract MUST state
  when a change takes effect: for new items at once, for notifications at the notifier's next run
  (every 20 minutes), for the maximum age at the next daily cleanup, and for the retention when the
  mailbox's next item arrives. A change MUST NOT remove existing items by itself.
- **FR-034**: Every role MUST be able to read the public subset of the settings: whether the
  quarantine is active, the retention per mailbox and the maximum message size, as the legacy
  quarantine page shows them.

#### Mailbox notification settings

- **FR-035**: A client MUST be able to read the notification settings of a mailbox in its scope:
  the frequency (never, hourly, daily or weekly), the category (rejected mail, mail moved to the
  junk folder, or both), and a version tag.
- **FR-036**: A client permitted by FR-006 MUST be able to change the frequency, the category, or
  both, of a mailbox in its scope. A value that is not sent MUST keep its current value. The values
  MUST be stored in the mailbox's attributes where legacy stores them. The categories MUST keep
  their legacy meaning: "rejected mail" covers items the filter rejected, "mail moved to the junk
  folder" covers items delivered to the junk folder with a copy in the quarantine, and "both" covers
  both.
- **FR-037**: Administrators and domain administrators MUST be able to set the frequency, the
  category, or both, for up to 100 distinct mailboxes in one request, as the legacy mailbox list's
  mass actions do. The request MUST be refused as a whole, or processed per mailbox with one result
  each, exactly as quarantine bulk actions are (FR-027), including the optional version tag of each
  mailbox's notification settings.
- **FR-038**: The contract MUST state that the notifier applies a change at its next run, that a
  change neither resets the time of the last notification nor the notification state of items, and
  that a notification includes only items not yet notified, scoring below the notification score
  limit, in the chosen category.

#### Spam score

- **FR-039**: A client MUST be able to read the spam score of a mailbox in its scope: the junk score
  (from which mail is moved to the junk folder) and the reject score (from which mail is rejected)
  in effect, whether they are custom, and the server defaults. The server defaults MUST be the spam
  filter's configured "add header" and "reject" thresholds. When the filter cannot be asked, or one
  of the thresholds is not configured, the defaults MUST be 5 and 15, marked as fallback values, as
  in legacy.
- **FR-040**: A client permitted by FR-006 MUST be able to set a custom spam score. Each score MUST
  be a number from 0 to 5,000 with at most one decimal, and the junk score MUST NOT be above the
  reject score. When both are equal, the reject score MUST be raised by 0.1, as in legacy. The new
  values MUST replace any custom values.
- **FR-041**: A client permitted by FR-006 MUST be able to reset a mailbox's spam score, which
  removes its custom values, so that the server defaults apply.
- **FR-042**: Custom spam scores MUST be stored where legacy stores them, so that the spam filter's
  settings map applies them within one refresh of that map (at most 1 minute): mail scoring at or
  above the reject score is rejected, and mail scoring at or above the junk score is moved to the
  junk folder. They MUST apply to the same addresses as the mailbox's lists (FR-049). Spam scores
  for domains are not offered, as in legacy.

#### Allow and block lists

- **FR-043**: A client MUST be able to read the allow list and the block list of a domain or alias
  domain in its scope. The response MUST contain the entries of that domain or alias domain and,
  read-only, the entries of the mailboxes of that domain, as legacy shows them.
- **FR-044**: A client MUST be able to read the allow list and the block list of a mailbox in its
  scope. The response MUST contain the mailbox's entries and, read-only, the entries of the
  mailbox's domain, marked as inherited.
- **FR-045**: An entry MUST be represented with its identifier, its list (allow or block), its
  owner (domain, alias domain or mailbox), its pattern, its creation time, and whether it belongs to
  the owner being viewed. The lists MUST support pagination as in FR-008, filters by list and by
  ownership (own or inherited), a substring search on the pattern, and sorting by pattern (default,
  ascending), creation time or identifier.
- **FR-046**: A client permitted by FR-006 MUST be able to add an entry to the allow or block list
  of a domain, alias domain or mailbox in its scope. The pattern MUST be trimmed and lower-cased,
  and it MUST then:
  - consist only of the letters a to z, digits, `@`, `_`, `.`, `-` and `*`;
  - contain at least one letter or digit;
  - be at most 100 characters long.

  `*` matches any sequence of characters. A successful addition MUST respond as "created", with
  the address of the new entry.
- **FR-047**: A pattern that already exists on the allow or the block list of the same owner MUST
  be refused as a conflict that names the list and the identifier of the existing entry.
- **FR-048**: A client permitted by FR-006 MUST be able to remove an entry through its owner. An
  entry addressed through another owner, such as a domain entry through a mailbox, MUST be answered
  "not found". A successful removal MUST respond with no body.
- **FR-049**: Entries MUST be stored where legacy stores them, so that the spam filter's settings
  map applies them within one refresh of that map (at most 1 minute):
  - patterns are matched case-insensitively against the envelope sender and the From header;
  - mail from an allowed sender is never classified as spam;
  - mail from a blocked sender is always classified as spam and rejected, and it is not
    quarantined;
  - a mailbox's entries apply to mail for the mailbox, its sub-addresses, the aliases whose only
    target is the mailbox (not catch-all aliases), and its addresses in alias domains;
  - a domain's entries apply to mail for every address in the domain and in its alias domains. An
    alias domain's entries apply to that alias domain only;
  - a mailbox's entries take precedence over its domain's entries.

#### Rate limits

- **FR-050**: A rate limit MUST be represented with its object (a domain, alias domain or mailbox),
  the object's kind, the limit (a number of messages per second, minute, hour or day) or none, and
  a version tag. For a mailbox it MUST also contain the effective limit and whether it comes from
  the mailbox or from its domain, as feature 003 shows it.
- **FR-051**: Administrators and domain administrators MUST be able to list the rate limits that
  are set on objects in their scope, with pagination as in FR-008, a filter by kind, a substring
  search on the object, and sorting by object (default, ascending) or kind.
- **FR-052**: Administrators and domain administrators MUST be able to read the rate limit of a
  domain, alias domain or mailbox in their scope, with none when no limit is set.
- **FR-053**: A client permitted by FR-006 MUST be able to set the rate limit of a domain, alias
  domain or mailbox in its scope, as a whole number of messages from 1 to 2,147,483,647 per second,
  minute, hour or day. It MUST replace any previous limit. The object MUST be normalised as in
  features 002 and 003 (lower case, domain in ASCII form), and the limit MUST be stored where and
  how legacy stores it.
- **FR-054**: A client permitted by FR-006 MUST be able to remove the rate limit of an object in its
  scope. Removing a limit that is not set MUST succeed and change nothing. A removal MUST respond
  with no body.
- **FR-055**: The contract MUST state how the spam filter applies a limit from the next message on.
  Limits apply only to mail sent by authenticated clients:
  - a mailbox's limit applies to mail sent by a client authenticated as that mailbox, whatever
    sender address it uses, and overrides the domain's limit;
  - a domain's or alias domain's limit applies to mail whose envelope sender is in that domain or
    alias domain, when the authenticated mailbox has no limit of its own;
  - mail whose only recipient is the authenticated mailbox itself is not counted.
- **FR-056**: A stored limit that does not match the legacy format MUST be reported as invalid,
  together with its stored text, and MUST be replaceable and removable.
- **FR-057**: Administrators MUST be able to reset a rate-limit bucket by its identifier, which is
  `RL` followed by letters, digits and `=` and appears in the rate-limited log (feature 011). A reset
  MUST delete the bucket's counters and remove the rate-limited log entries for that bucket. When
  neither exists, the response MUST be "not found". A successful reset MUST respond with no body.

#### Spam filter action statistics

- **FR-058**: Administrators MUST be able to read how many messages the spam filter handled with
  each action since its statistics were last reset: rejected, soft-rejected (including greylisted,
  which legacy merges into it), subject rewritten, moved to junk, and no action. When the filter
  cannot be reached, the request MUST fail with a temporary failure. The response MUST state the
  start of the counting period when the spam filter reports it, and report it as unknown otherwise.
  It MUST also state since when the spam filter has been running, as far as the filter reports its
  uptime, and the contract MUST explain that the filter keeps its counters across restarts until
  they are reset, so the counting period may begin before that time.

#### Version tags

- **FR-059**: The quarantine settings, each mailbox's notification settings, each mailbox's spam
  score and each object's rate limit MUST carry a version tag. The tag MUST change whenever the
  stored values change, whether the change was made through this API, the legacy UI or `/api/v1`.
  Quarantine items and list entries are never changed, only removed. They MUST carry a version tag
  over their identifying stored values, which stays the same for as long as they exist. Every list
  entry of this feature MUST carry its version tag (`docs/conventions.md`).
- **FR-060**: A client MAY send the version tag it read with a change, a reset, a removal, an item
  action or a bulk target. When the tag no longer matches, the request MUST be refused as outdated
  (for a bulk target: that target's outcome is "outdated") and MUST change nothing. The contract
  MUST recommend that interactive clients always send it.

#### Reliability

- **FR-061**: When mailcow's shared store is unavailable, changes to the quarantine settings and to
  rate limits, and bucket resets, MUST be refused with a temporary failure and MUST leave no partial
  change.
- **FR-062**: Each request to the spam filter MUST time out after 30 seconds, as in legacy, and
  then count as failed. Failures MUST be handled as FR-021 to FR-023, FR-039 and FR-058 state.

#### Audit

- **FR-063**: Every request of this feature that changes state, or tries to, MUST write one audit
  record per affected object into feature 001's audit log, with the fields of FR-046 there and:
  - the target type (quarantine item, quarantine settings, notification settings, spam score, list
    entry, rate limit or rate-limit bucket) and the target identifier;
  - the outcome, including refusals and their reason;
  - for item actions, the queue identifier, the recipient, the envelope sender, the action and the
    outcome of each step. The subject, the content, the attachments and the quick-action hash MUST
    NOT be recorded;
  - for other changes, each changed value with its old and new value. The notification template
    MUST be recorded only as changed, with its old and new sizes;
  - for list entries, the owner, the list and the pattern;
  - for a bucket reset, the identifier, whether counters existed and the number of log entries
    removed;
  - for bulk actions, the identifier of the bulk request.
- **FR-064**: Administrators MUST be able to filter the audit log by target type and target
  identifier, as in feature 002 (FR-058 there).

#### Cross-cutting

- **FR-065**: Errors MUST follow feature 001's conventions (FR-049 and FR-055 there). This feature
  MUST document at least these error types: not found, permission flag required (naming the flag),
  outdated version, list entry already exists (naming the list and entry), message too large, item
  is being processed, delivery refused (with the mail server's answer), and temporary failure
  (naming the shared store, the spam filter or the mail server). Validation errors MUST use stable
  rule codes with their parameters, including the bulk limit and a code for a template the notifier
  cannot use (FR-070).
- **FR-066**: Every operation MUST be described in the published API contract before it is
  implemented, and its behaviour MUST match the contract.
- **FR-067**: Changes made through the legacy UI or `/api/v1` MUST be visible in the next response
  of this API.
- **FR-068**: Everything this feature stores MUST keep the legacy storage format, so that the
  legacy UI, `/api/v1`, the quarantine ingestion, the notifier, the cleanup of aged items, the quick
  release and delete pages and the spam filter's maps keep working with it.
- **FR-069**: Resources (mailcow's location, thing and group kinds) MUST NOT be addressable as
  mailboxes for notification settings, spam scores, mailbox lists and mailbox rate limits; such a
  request MUST be answered "not found", as in feature 003 (FR-003 there). Quarantine items addressed
  to a resource MUST follow FR-001 like any other item.

#### Notification template validation and preview

- **FR-070**: Saving a notification template MUST check it the way the legacy notifier uses it: it
  MUST be parsed by the same template engine in the same sandbox, then rendered with sample data,
  once without and once with the quick-action links (`quarantine_acl` 0 and 1, one and three
  items). A template that cannot be parsed, that violates the sandbox, that raises any error while
  rendering, or that does not finish rendering within 5 seconds MUST be refused with a validation
  error on the template field that states the reason and, when known, the line. An empty template
  is not checked: it restores the default (FR-032). The change of the quarantine settings MUST
  accept a validate-only option that applies every rule of the change, including the version tag
  and the template check, and reports success or the same errors as a real change, but stores
  nothing and writes no audit record (as feature 013 FR-093).
- **FR-071**: Administrators MUST be able to preview a notification template: a given template, or
  the stored one (or the default) when none is given. The preview MUST render it as FR-070 does,
  with the documented sample data (sample items, user name, counter, the server's host name and the
  chosen `quarantine_acl`), and return the rendered HTML as text, the plain-text version the notifier
  would derive from it, and the subject the notifier would use. A template that fails FR-070 MUST be
  refused with the same validation error. The contract MUST state that clients show the rendering
  only as source text and never as live HTML. A preview changes nothing and MUST NOT be audited.

#### Capabilities

- **FR-072**: Every role MUST be able to read the quarantine capabilities, the limits and server
  settings a client needs before it submits:
  - the bulk limit (FR-027), and the default and maximum page sizes (FR-008);
  - the content size limit for inspection and downloads (FR-014), and the largest message the mail
    pipeline hands to the quarantine (FR-030);
  - the spam score range and step (FR-040);
  - the allow and block list pattern rules: allowed characters and maximum length (FR-046);
  - the rate limit range and time units (FR-053);
  - the limits of the quarantine settings (FR-030) and the release formats;
  - whether virus scanning is enabled on the server (mailcow's `SKIP_CLAMD`), so that clients can
    explain why no item carries the virus flag.

  The values MUST reflect the server's current configuration and MUST NOT include any secret or
  connection detail. The read MUST change nothing, and read-only keys MAY use it.

### Legacy Coverage

Every legacy capability allocated to feature 006 in `docs/roadmap.md` maps to requirements as
follows. Actions are `/api/v1` names; *form* marks legacy UI form handlers and *ajax* the legacy
AJAX endpoints.

| Legacy action or form | Legacy implementation | Requirements |
|---|---|---|
| `get/quarantine/all`, quarantine page list | `quarantine('get')`, `quarantine.twig`, `quarantine.js` | FR-001, FR-006 to FR-011 |
| `get/quarantine/{id}` | `quarantine('details')` | FR-007, FR-012, FR-015 |
| *ajax* `qitem_details.php?id=` (detail modal) | parsed recipients, From, subject, text parts, symbols, fuzzy hashes, attachments | FR-012 to FR-014 |
| *ajax* `qitem_details.php?id=&att=` | attachment download, `quarantine_attachments` check | FR-006, FR-016, FR-018 |
| *ajax* `qitem_details.php?id=&eml` | whole-message download | FR-006, FR-017, FR-018 |
| `edit/qitem` with `release` or `learnham`, "Deliver to inbox" (single and mass) | `quarantine('edit')` release branch | FR-019 to FR-022, FR-025 to FR-027 |
| `edit/qitem` with `learnspam`, "Learn as spam and delete" (single and mass) | `quarantine('edit')` learnspam branch | FR-023, FR-025 to FR-027 |
| `delete/qitem`, "Remove" (single and mass) | `quarantine('delete')` | FR-024, FR-026, FR-027 |
| *ajax* `qitem_details.php?id=&quick_release` / `&quick_delete`, *form* `quick_release` / `quick_delete` (`triggers.global.inc.php`, `qhandler.php`) | `quarantine('quick_release' / 'quick_delete')` by hash | Release without learning (FR-022) and deletion (FR-024) for signed-in users; the hash pages stay in php-fpm (see below), FR-015, FR-028 |
| `edit/quarantine` with `settings`, *form* quarantine settings tab | `quarantine('edit')` settings branch, `admin/tab-config-quarantine.twig` | FR-029 to FR-033, FR-059 to FR-061 |
| Quarantine settings shown on the quarantine page | `quarantine('settings')` | FR-034 |
| `edit/quarantine_notification`, `edit/quarantine_category` (user settings tab, edit mailbox page, mailbox list mass actions) | `mailbox('edit', 'quarantine_notification' / 'quarantine_category')` | FR-035 to FR-038 |
| Notification settings read | `mailbox('get', 'quarantine_notification' / 'quarantine_category')` | FR-035 |
| `get/spam-score/{mailbox}`, `edit/spam-score` (including `default`), user spam filter tab | `mailbox('get' / 'edit', 'spam_score')`, `user/Spamfilter.twig`, `user.js` | FR-039 to FR-042 |
| `get/rspamd/actions`, dashboard chart | `rspamd_actions()`, `dashboard.js` | FR-058 |
| `add/domain-policy`, `delete/domain-policy`, `get/policy_wl_domain/{domain}`, `get/policy_bl_domain/{domain}`, domain spam filter tab | `policy('add' / 'delete' / 'get', 'domain')`, `edit/domain.twig`, `edit.js` | FR-043, FR-045 to FR-049 |
| `add/mailbox-policy`, `delete/mailbox-policy`, `get/policy_wl_mailbox/{mailbox}`, `get/policy_bl_mailbox/{mailbox}`, user spam filter tab | `policy('add' / 'delete' / 'get', 'mailbox')`, `user/Spamfilter.twig`, `user.js` | FR-044 to FR-049 |
| `get/rl-domain/all`, `get/rl-domain/{domain}`, `edit/rl-domain` (domain and alias domain rate limit tabs) | `ratelimit('get' / 'edit', 'domain')`, `edit/domain.twig`, `edit/aliasdomain.twig` | FR-050 to FR-056 |
| `get/rl-mbox/all`, `get/rl-mbox/{mailbox}`, `edit/rl-mbox` (mailbox rate limit tab) | `ratelimit('get' / 'edit', 'mailbox')`, `edit/mailbox.twig`, `mailbox_details` `rl` and `rl_scope` | FR-050 to FR-056 |
| `delete/rlhash`, dashboard rate-limited log "Remove hash" | `ratelimit('delete')`, `dashboard.js` | FR-057 |
| Domain administrator flags `quarantine`, `spam_policy`, `ratelimit` | `da_acl` | FR-005, FR-006 |
| Mailbox user flags `quarantine`, `quarantine_attachments`, `quarantine_notification`, `quarantine_category`, `spam_score`, `spam_policy` | `user_acl` | FR-005, FR-006 |

Parts of these legacy screens that belong to other features: the rate-limited log itself and the
Rspamd history and statistics tabs (011), the Rspamd settings maps and global filters (013), and
the initial notification values, rate limits and permission flags given when a domain, mailbox or
alias domain is created (002, 003, 004).

### Legacy components that stay in place

These components are not replaced by this feature. They keep reading and writing the same data,
and this feature's storage formats (FR-068) keep them working:

- **Quarantine ingestion** (`pipe.php`, called by the spam filter's metadata exporter for rejected,
  junk and subject-rewritten mail that no block list caught): resolves each recipient through
  aliases and alias domains to its mailboxes, skips excluded domains and messages above the maximum
  size, stores one item per mailbox, computes the item's quick-action hash, and removes the
  mailbox's items beyond the retention.
- **Notifier** (`quarantine_notify.py`, every 20 minutes in the Dovecot container): for each mailbox
  whose frequency is due, sends the items not yet notified, below the score limit and in the chosen
  category, rendered with the template, from the notification sender with the notification subject,
  to the mailbox user or the redirect recipient, plus the copy recipient. It marks those items as
  notified and records the time of the last notification. Quick links are included only for
  mailboxes with the `quarantine` flag.
- **Cleanup of aged items** (`clean_q_aged.sh`, daily): removes items older than the maximum age.
- **Quick release and delete pages** (`qhandler.php`, `triggers.global.inc.php`): open without
  sign-in to anyone holding an item's hash from a notification link. They release without learning
  or delete, and only for mailboxes with the `quarantine` flag.
- **Spam filter settings map** (`settings.php`, refreshed by the filter every 30 seconds): turns the
  stored allow and block lists and spam scores into filter settings.
- **Rate limit lookup** (the filter's `DYN_RL` rule) and the **rate-limited log** (`pipe_rl.php`):
  read the stored limits, and record each rate-limited message with its bucket identifier.

### Legacy Parity and Deliberate Deviations

Parity reference: `source_code/data/web/inc/functions.quarantine.inc.php`, `functions.policy.inc.php`,
`functions.ratelimit.inc.php`, `functions.rspamd.inc.php` (`rspamd_actions`),
`functions.mailbox.inc.php` (`spam_score`, `quarantine_notification`, `quarantine_category`, and the
`rl` and `rl_scope` of `mailbox_details`), `functions.acl.inc.php` (`to_session`), `functions.inc.php`
(`hasDomainAccess`, `hasMailboxObjectAccess`, `hasACLAccess`), `json_api.php`,
`inc/ajax/qitem_details.php`, `qhandler.php`, `quarantine.php`, `inc/triggers.global.inc.php`,
`init_db.inc.php` (tables `quarantine`, `filterconf`, `user_acl`, `da_acl`, the `attributes` of
`mailbox`), the templates `quarantine.twig`, `modals/quarantine.twig`, `qhandler.twig`,
`admin/tab-config-quarantine.twig`, `edit/domain.twig`, `edit/aliasdomain.twig`,
`edit/mailbox.twig`, `mailbox/tab-mailboxes.twig`, `mailbox/rl-frame.twig`, `user/Spamfilter.twig`,
`user/tab-user-settings.twig`, the scripts `js/site/quarantine.js`, `qhandler.js`, `user.js`,
`edit.js`, `dashboard.js`, `lang/lang.en-gb.json`, and outside the web layer
`conf/rspamd/meta_exporter/pipe.php` and `pipe_rl.php`, `conf/rspamd/dynmaps/settings.php`,
`conf/rspamd/lua/rspamd.local.lua` (`DYN_RL`), `conf/rspamd/local.d/metadata_exporter.conf`,
`fuzzy_check.conf` and `options.inc`, `Dockerfiles/dovecot/quarantine_notify.py` and
`clean_q_aged.sh`, `Dockerfiles/phpfpm/docker-entrypoint.sh` (seeds the release format and the
maximum age), `assets/templates/quarantine.tpl`, and the job schedules in `docker-compose.yml`.

The reference is upstream mailcow-dockerized `2026-09a`. `source_code/` points to the FeldHost fork
(`~/Projects/mailhosting`, branch `feldhost-202609a`), so the files above were diffed against that
upstream tag. All of them are identical in the fork, so this feature has no fork-only behaviour and
needs no setting for one. The fork differs only in mail filtering configuration that this API
neither reads nor writes:

1. Extra ClamAV signature patterns (`SecuriteInfo`) in `conf/rspamd/local.d/antivirus.conf`, and PUA
   exclusions and larger scan limits in `conf/clamav/clamd.conf`. They change which messages are
   flagged as viruses and therefore quarantined, not how items are handled.
2. The `FREEMAIL_POLICY_FAILURE` composite in `conf/rspamd/local.d/composites.conf` also excludes
   `SIEVE_HOST`.
3. A daily job in `docker-compose.yml` that expunges junk folder messages older than two weeks. It
   affects the junk folder copies of "moved to junk" mail, not quarantine items.
4. Branding-only language strings.

| Behaviour | Legacy | This feature |
|---|---|---|
| Viewing items | Every role in scope lists and inspects, whatever its flags | Same (FR-006) |
| Object outside scope | `access_denied` message, empty result or `false` | "Not found" (FR-003) |
| Domain administrator scope for items | List: recipients matching `@domain$` for own domains and alias domains. Actions: recipient mailbox must exist in an own domain | Recipient mailbox domain in own domains, for listing and actions alike (FR-001) |
| Attachment details | Always returned by the server; the UI hides them without `quarantine_attachments` | Returned only with the right to download them (FR-013) |
| Attachment download | `quarantine_attachments` checked | Same (FR-006, FR-016) |
| Whole-message download | No flag checked by the server; the UI offers it only with `quarantine` | `quarantine` and, for mailbox users, `quarantine_attachments` (FR-006). **Deviation** (security fix: the file contains the attachments) |
| Download delivery | Declared content type, attachment disposition, names cut to 20 and 30 characters | File download only, never displayed or sniffed; attachment names kept and made safe (FR-016 to FR-018). **Deviation** |
| Item details through `/api/v1` | `SELECT *`, including the stored message and the quick-action hash | Neither is ever returned (FR-015). **Deviation** (security fix) |
| Content size limit | 10 MiB for parsing and downloads | Same (FR-014) |
| Release | Postfix internal submission on port 590, raw with `X-Spam-Flag` renamed, or a wrapper message with `<qid>.eml`; sender fallback `sender-unknown@rspamd`; row deleted; then Rspamd `learnham` and `fuzzyadd` with flag 13 (the `fuzzydel` call is prepared but never executed, and `fuzzyadd` is sent twice) | Same mechanisms, one fuzzy call (FR-019 to FR-022) |
| Release without learning | Only through the hash-based quick release page | Option of the release action (FR-022). The hash pages stay |
| No release format stored | Item deleted without delivery, reported as released | "Unmodified original" used (FR-020). **Deviation** (bug fix) |
| Learn as spam | Row deleted first, then Rspamd `learnspam` and `fuzzyadd` with flag 11; a failed learning loses the item | Item removed only after spam learning succeeded (FR-023). **Deviation** (bug fix) |
| Concurrent actions | No protection; an item can be delivered twice | At most once (FR-026). **Deviation** |
| Mass actions | One request with a list of IDs, continuing past failures, one message per item | Bulk of up to 100 with one result per item (FR-027, FR-037) |
| Quarantine settings storage | Redis `Q_RETENTION_SIZE`, `Q_MAX_SIZE` (MiB), `Q_MAX_SCORE`, `Q_MAX_AGE`, `Q_EXCLUDE_DOMAINS` (JSON), `Q_RELEASE_FORMAT`, `Q_SENDER`, `Q_BCC`, `Q_REDIRECT`, `Q_SUBJ`, `Q_HTML` | Same keys and formats (FR-033, FR-068). Size in bytes in the API (FR-030) |
| Quarantine settings validation | Invalid addresses silently emptied, unknown release format turned into raw, maximum age ≤ 0 turned into 365, other values unchecked | Invalid values refused with field errors; limits on subject and template (FR-030). **Deviation** |
| Settings visible to non-administrators | The quarantine page shows retention and maximum size | Same subset (FR-034) |
| Default template | Read from `/tpls/quarantine.tpl` when `Q_HTML` is empty | Same, marked as the default (FR-032) |
| Template check and preview | None: any text is stored; the notifier falls back to the default template when it cannot parse it, and skips the mailbox when rendering fails | Checked with the notifier's engine and sandbox before saving, validate-only option, preview as text (FR-070, FR-071). **Addition** |
| Notification settings | `mailbox.attributes` `quarantine_notification` (never, hourly, daily, weekly) and `quarantine_category` (reject, add_header, all), flags checked with `hasACLAccess` | Same storage, values and flags (FR-035, FR-036) |
| Spam score | `filterconf` rows `lowspamlevel` and `highspamlevel` per mailbox; `default` deletes them; equal values raise the high one by 0.1; any numbers accepted, including a junk score above the reject score; defaults from Rspamd `/actions`, fallback `5, 15` | Same storage, reset and defaults; values 0 to 5,000 with one decimal, junk ≤ reject (FR-039 to FR-041). **Deviation** for the range |
| Spam score permissions | `spam_score` from the session; domain administrators always hold it | Same (FR-005) |
| List entries | `filterconf` rows `whitelist_from` / `blacklist_from` with `object` = domain, alias domain or mailbox; pattern trimmed and lower-cased, characters `[a-z0-9@_.*-]`, unique per object across both lists | Same (FR-046, FR-047); the 100-character column limit is checked instead of failing in the database |
| List views | Domain view includes the domain's mailbox entries; mailbox view includes domain entries; only own entries removable | Same, with ownership marked (FR-043 to FR-045, FR-048) |
| Rate limit storage | Redis hash `RL_VALUE`, field = object as given, value `<n> / 1<s|m|h|d>`; value 0 or empty deletes the field; negative values stored | Same storage; objects normalised to lower case and ASCII, which the filter expects; removal is its own operation; values below 1 refused (FR-053, FR-054). **Deviation** |
| Rate limit reading | Domain limits for administrators and domain administrators; mailbox limits not for mailbox users | Same (FR-006) |
| Rate-limit bucket reset | Administrators; removes `RL_LOG` lines matching the identifier anywhere and deletes the bucket key; "hash not found" warning when the key is missing | Same effect; log entries matched on their bucket identifier; "not found" only when nothing matched (FR-057). **Deviation** |
| Rspamd action statistics | Administrators; Rspamd `/stat` actions with greylist added to soft reject; `{}` on failure | Same counts; temporary failure on failure; counting period start (or unknown) and the filter's running time (FR-058). **Deviation** |
| Server settings for the forms | Rendered into the pages (bulk sizes, slider range, pattern hints) | Readable quarantine capabilities (FR-072). **Addition** |
| Resources | Accepted wherever a mailbox address is | Not addressable for mailbox-level settings (FR-069). **Deviation**, as in feature 003 |
| Concurrent settings edits | Last write wins | Version tags (FR-059, FR-060), also per bulk target. **Deviation** |
| UI log entries | Written to the `logs` table | Written to this API's audit log (FR-063), as in feature 001. **Deviation** |
| Release envelope sender that is not an address (plan) | The stored text is sent as `MAIL FROM`, for example the column default `unknown` | `sender-unknown@rspamd`, as for an empty sender (FR-019). **Deviation** |
| Release SMTP dialogue (plan) | `HELO quarantine`, LF line endings, manual dot-stuffing | `EHLO quarantine`, CRLF line endings, the same message content. Transport detail |
| Rate limit list (plan) | Iterates existing domains, alias domains and mailboxes | Lists the stored limits of existing objects in scope; a field stored in upper case by another tool is not found, as the spam filter does not match it either (FR-051) |
| Refusals by the authentication pipeline (plan) | — | Unauthenticated, CSRF, read-only key and restricted-session refusals follow feature 001's audit rules; this feature audits the refusals of its own checks (FR-063) |

### Key Entities *(include if feature involves data)*

- **Quarantine item** (existing mailcow data): a message the spam filter kept for one recipient
  mailbox, with its queue identifier, subject, envelope sender, recipient, score, action, symbols,
  fuzzy hashes, sending server address, authenticated sender, stored message, notification state,
  arrival time and quick-action hash.
- **Parsed content** (computed): the headers, text, recipients and attachments extracted from an
  item's stored message.
- **Action report** (transient): the outcome of the delivery, learning and fuzzy steps of one
  release or learning.
- **Quarantine settings** (existing mailcow data in the shared store): retention, size, age and score
  limits, excluded domains, release format, notification sender, subject, copy and redirect
  recipients, and the notification template.
- **Notification settings** (existing mailcow data, part of a mailbox's attributes): frequency and
  category.
- **Spam score** (existing mailcow data, the mailbox's filter preferences): a junk score and a reject
  score, or the server defaults.
- **List entry** (existing mailcow data, the filter preferences): an allow or block pattern owned by
  a domain, alias domain or mailbox.
- **Rate limit** (existing mailcow data in the shared store): a number of messages per time frame for
  a domain, alias domain or mailbox.
- **Rate-limit bucket** (data of the spam filter in the shared store): the counters behind one limit,
  named by its identifier, with entries in the rate-limited log.
- **Spam filter action statistics** (read from the spam filter): message counts per action.
- **Bulk action** (transient): one action, up to 100 targets, each with an optional version tag,
  and one result per target.
- **Template preview** (transient): a template rendered with sample data, returned as text.
- **Quarantine capabilities** (computed): the limits and server settings of FR-072.
- **Audit record** (owned by this API, defined in feature 001): extended with the target type, the
  target identifier, the changes and the step outcomes.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For 100% of the items in the parity test set, the list entries, details and parsed
  content returned by the API equal what the legacy UI shows for the same data, apart from the
  stated deviations.
- **SC-002**: A release or learning through the API produces the same delivered message and the
  same spam filter calls as the legacy UI for the same item and settings, apart from the single
  fuzzy call and the learning order of FR-023.
- **SC-003**: Settings, notification settings, spam scores, list entries and rate limits saved
  through the API are shown unchanged by the legacy UI, and vice versa, in 100% of round-trip
  tests.
- **SC-004**: A spam score or list entry saved through the API is applied by the spam filter within
  1 minute, and a rate limit from the next message on.
- **SC-005**: 100% of attempts to reach an object outside the caller's scope get the same response
  as for an object that does not exist, and 100% of attempts without the required flag are refused.
  No attachment or whole message is ever downloaded by a caller without the required flags.
- **SC-006**: In 1,000 concurrent release attempts on the same items, no message is delivered or
  learned twice.
- **SC-007**: In 95% of requests, a page of 25 quarantine items is returned within 1 second on a
  server with 100,000 quarantine items.
- **SC-008**: A bulk release of 100 items completes within 60 seconds when the spam filter and the
  mail server respond normally.
- **SC-009**: A mailbox user can find a quarantined message and release it to their inbox in the
  new web app in under 1 minute.
- **SC-010**: Every request in FR-063 produces exactly one audit record per affected object, and a
  scan of all audit records after the full test run finds no message subject, content, attachment
  or quick-action hash.
- **SC-011**: 100% of the templates in the template test set that the legacy notifier cannot parse
  or render (syntax errors, sandbox violations, rendering errors, endless rendering) are refused,
  and 100% of the templates it renders, including the default template, are accepted and previewed
  with the same output as the notifier for the same sample data.

## Assumptions

- The quarantine ingestion, the notifier, the cleanup of aged items, the quick release and delete
  pages, the spam filter's settings map and rate limit lookup, and the rate-limited log stay where
  they are (constitution V). This API does not send notifications, and it renders the template only
  to check and preview it (FR-070, FR-071).
- The API can reach the spam filter's controller and mailcow's internal mail submission path in the
  same way the legacy web container does. The plan decides how.
- Item arrival times are stored in the server's configured time zone and converted to UTC.
- The rate-limited log, the Rspamd history and the Rspamd statistics tabs are listed in feature 011.
  This feature only resets buckets.
- Rspamd settings maps, global filters and forwarding hosts belong to features 013 and 009.
- The initial notification values, rate limits and permission flags of new domains, mailboxes and
  alias domains are set in features 002, 003 and 004. Their representations show the values
  managed here read-only.
- Domain administrator accounts and their flags are managed in feature 007, mailbox user flags in
  feature 003. This feature only reads them.
- The legacy UI never sends version tags, so it can still overwrite changes made through this API.
- The list page size follows the shared list convention (25 by default, at most 1,000,
  `docs/conventions.md`). These defaults can be changed by the operator and are reported by the
  capabilities read (FR-072): the bulk limit (100) and the content size limit (10 MiB).
- **Out of scope**:
  - quarantine ingestion and notification sending;
  - the hash-based quick release and delete pages;
  - learning messages that are not in the quarantine, for example from a mailbox's junk folder;
  - spam scores for domains, which legacy does not offer;
  - the rate-limited log, Rspamd history and statistics, settings maps and global filters.
- **Dependencies**:
  - the upstream mailcow database schema of the supported release;
  - mailcow's shared store (quarantine settings, rate limits, buckets and the rate-limited log);
  - the spam filter's controller, for learning, fuzzy hashes, thresholds and statistics;
  - mailcow's internal mail submission path, for releases;
  - feature 001, for identity, roles, permission flags, API keys, errors and the audit log;
  - features 002, 003 and 004, for the domain, mailbox and alias domain conventions.
