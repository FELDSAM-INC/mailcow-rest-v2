# Feature Specification: System Settings

**Feature Branch**: `main` (no feature branches, constitution 1.1.0)

**Created**: 2026-10-18

**Status**: Draft

**Input**: Feature description built from `docs/roadmap.md` (feature 013 and allocation row 013):

> System settings for mailcow REST API v2: administrators manage the server-wide settings of an
> existing mailcow-dockerized server. They brand the web apps with light and dark logos, UI texts
> (page title, product name, apps heading, login help text, footer, announcement), login page
> options, app links and new, API-owned branding colours that keep the web app accessible (WCAG 2.2
> AA). They opt in to the external IP check. They edit the password policy, the password reset
> notification and the quota notifications, and administrators and domain administrators set the
> per-domain copy recipients of quota notifications. They manage the Rspamd settings rules, the
> global Rspamd regex maps and the global Sieve pre- and post-filters, read the Rspamd and Sieve
> presets, set the Rspamd web interface password, edit the CORS settings of the legacy API, send
> mass mail to mailboxes, and verify the server's GUID against the mailcow license service.
>
> Allocated legacy surface: customisation writes (*form* logos, `edit/ui_texts`, `edit/app_links`,
> `edit/custom_login`, `edit/ip_check`), branding colours (new, API-owned), password policy editing
> (`edit/passwordpolicy`), password reset notification template (`edit/reset-password-notification`),
> quota notifications (`quota_notification`, `_bcc`), Rspamd settings maps (`rsetting`,
> `edit/rspamd-map`), *form* Rspamd UI password, global filters (`global-filter`,
> `global_filters`), CORS (`edit/cors`), *form* mass mail, presets (`presets`), *form*
> license/GUID verification. The legacy "System › Configuration" tabs (Customize, Password
> settings, Quota notifications, Rspamd settings map, Rspamd, CORS, System mails), the global
> filter maps, the global Sieve filter forms, the quota notification copy tab of "Edit domain" and
> the GUID card of the dashboard need everything they show today.
>
> Constraints: every legacy side effect is reproduced, so Postfix, Dovecot, Rspamd and SOGo behave
> the same whichever implementation made the change. Files that other mailcow containers read
> (the global Sieve scripts and the custom Rspamd maps) are written so that those containers and
> the legacy UI keep working, and the reloads and restarts this causes are explicit. Mass mail is
> high risk: it needs a confirmation, an audit trail and a delivery rate limit. Logos have size and
> type limits and never carry active content. The legacy UI and `/api/v1` keep working on the same
> data. The feature builds on feature 001's identity, roles, API keys, error conventions, audit
> log and public configuration, and on feature 002's domains and conventions.

## Clarifications

### Session 2026-10-18

- Q: How does mass mail reach the mailboxes, through Postfix or directly into the mailboxes? → A:
  Directly into each mailbox through Dovecot's local delivery, exactly as legacy does. The
  message does not pass Postfix or Rspamd, so it is never filtered, quarantined, signed or relayed.
  (agent decision: constitution IV; legacy `sys_mail()` delivers with LMTP to `dovecot-mailcow`
  port 24, not to Postfix. The contract states the consequences.)
- Q: Legacy sends mass mail synchronously inside one form request, after a client-side "Activate
  send button" checkbox. How does the API send it safely? → A: The administrator first requests a
  recipient preview. Sending creates a mass mail job and requires the recipient count from the
  preview, so that a changed selection never sends unexpectedly. The job delivers in the
  background at a limited rate (10 messages per second by default). Only one job runs at a time,
  any administrator can cancel it, every recipient gets at most one copy, and every outcome is
  kept. (agent decision: a synchronous request cannot deliver to thousands of mailboxes. The
  roadmap demands confirmation, audit and rate limiting for mass mail.)
- Q: Can API keys use every operation of this feature? → A: API keys act as their owner and are
  limited by their access level, as in features 001 and 002, with two exceptions: sending or
  cancelling mass mail and setting the Rspamd web interface password require a signed-in
  administrator session. (agent decision: legacy offers both only as browser forms, never through
  `/api/v1`. A leaked key must not be able to mail every user or lock administrators out of the
  Rspamd interface. This follows 001's rule for key management.)
- Q: Which branding colours exist and when is a colour accepted? → A: One optional six-digit
  sRGB accent per theme. Any valid colour is accepted unchanged, with black or white foreground
  text. The dark theme inherits the light accent when unset. The UI derives readable text-only
  links separately. (Owner correction, 2026-10-23: bright yellow must not be replaced by brown.)
- Q: How are logos validated, and what happens to an SVG with scripts? → A: The type is detected
  from the file content, not from what the client declares. PNG, JPEG, GIF and SVG are accepted, as
  in legacy. The legacy size and dimension limits apply (15 MiB, 1,920 × 1,920 pixels for raster
  images), read from the operator's configuration. An SVG that contains active content (scripts,
  event handlers, script URLs, embedded HTML, external references or entity declarations) is
  refused, never silently altered. (agent decision: legacy trusts the declared type and stores SVGs
  unchecked. Refusing instead of stripping is deterministic and testable, and the operator keeps
  control over the file that is published.)
- Q: Legacy silently replaces or drops some invalid values: an invalid sender address becomes empty,
  a password length below 3 keeps the old value, invalid copy recipients are skipped, mismatched
  app link rows save nothing but report success. Does the API do the same? → A: No. Every invalid
  value is refused with a validation error that names the field, and nothing changes. Values that
  legacy treats as "empty means default" keep that meaning when they are sent empty on purpose.
  (agent decision: silent replacement makes a client believe a change was made. This follows
  feature 002's decision for domain administrators' attributes.)
- Q: Should the API validate the content of Rspamd settings rules and regex maps, which legacy
  checks only in the browser or not at all? → A: Yes. Regex maps are checked on the server with the
  rule the legacy page applies in the browser: every line is empty, a comment, or `/pattern/` with
  at most one of the flags `i`, `m` or `s`, and the pattern must compile. Settings rules must be
  structurally closed (balanced braces and brackets, closed strings), so that one rule cannot
  break the settings map that Rspamd loads for all rules. (agent decision: a broken map silently
  disables filtering features for every mailbox. Server-side checks also protect clients that
  skip the browser.)
- Q: Saving a global Sieve filter restarts Dovecot in legacy. Does the API do the same? → A: Yes, by
  default, because Dovecot reads and compiles the global scripts only when it starts. The client
  may ask to save without restarting; the script then takes effect at the next Dovecot restart.
  The response reports the restart outcome. A script that fails validation, or a shared file that
  is not available, never causes a restart. (agent decision: constitution IV for the restart.
  Deferring lets an administrator change both filters with one restart, and the restart
  interrupts IMAP, POP3 and delivery for a moment.)
- Q: Do the CORS settings also apply to this API? → A: No. They are the settings of the legacy
  `/api/v1`, stored where legacy reads them. This API is used from the same origin as the
  mailcowui-ng web app and does not answer cross-origin requests in this release. (agent decision:
  constitution VI defines same-origin browser access for this API. The legacy method list has no
  PATCH, so applying it here would be misleading. Cross-origin access can be added later.)
- Q: The FeldHost fork removes the GUID and license card from the dashboard. How is that handled?
  → A: License verification is a server setting, enabled by default as upstream; the FeldHost
  deployment disables it. When it is disabled, the license operations answer with a documented
  "feature disabled" error. Verification is never automatic: only an explicit administrator request
  sends the GUID to the license service. (agent decision: fork-only behaviour is kept but
  configurable, per the roadmap. Sending data to an external service only on request respects the
  operator's privacy choices.)
- Q: Legacy stores the page title, product name and apps heading HTML-escaped and renders them raw,
  stores the help text and footer as raw HTML, and escapes the announcement when it renders it.
  What does the API accept and return? → A: The page title, product name, apps heading and
  announcement are plain text in requests and responses; the API stores the first three in legacy's
  escaped form, so the legacy UI shows the same text. The help text and footer are operator-authored
  HTML, stored and returned as given, and clients must sanitise them before rendering. (agent
  decision: constitution IV for storage, and feature 001 FR-044 already names the help text and
  footer as the HTML fields.)
- Q: The legacy screens have "Validate" buttons for regex maps and Sieve scripts that check content
  without saving it. Does the API offer the same? → A: Yes. Replacing a global Sieve filter or a
  regex map, and creating or changing a settings rule, accept a validate-only option: the content
  is checked by the same rules and the response reports success or the field errors, but nothing is
  stored, restarted or audited. (agent decision: the new web app must cover what the legacy screens
  offer, and saving a Sieve filter restarts Dovecot, so checking must be possible without side
  effects.)

### Session 2026-10-18 (orchestrator input from UI feature 013)

- Q: How does a client learn the server settings it needs before it submits: the logo limits,
  whether license verification is enabled, whether demo mode is on, the server's host name, the
  Rspamd web interface address, the colour references and the field limits? → A: Through a
  read-only "settings capabilities" operation for administrators (FR-094), on the pattern of
  feature 002's domain capabilities. (orchestrator decision: the UI found these values missing and
  would otherwise learn them only from refused requests.)
- Q: Can the mass mail recipient preview be searched? → A: Yes. The preview takes a substring
  search on the address in addition to its paging (FR-072). (orchestrator decision: finding one
  address in a large preview by paging is impractical.)
- Q: Does this feature offer its own read of the effective app links for signed-in users? → A: No.
  Feature 001's public configuration already exposes every app link read-only, with the signed-in
  address and its `%u` placeholder that the client replaces (feature 001, FR-056). This feature only
  edits the custom app links (FR-025 to FR-028). (orchestrator decision: displaying app links
  belongs to UI feature 001, and a second read would duplicate it.)
- Q: The shared Rspamd map directory is owned by Rspamd's user and only its files are writable by
  the web containers, so a map cannot be replaced by renaming a new file into place. Must Rspamd
  still never see a partly written map? → A: The map is rewritten in place in one write, as legacy
  does, and its change marker is set again after the write, so that Rspamd reads the complete
  content at its next check even if it read the file during the write (FR-058). (agent decision,
  plan: constitution V forbids changing other containers and VI forbids a root process; legacy has
  the same constraint and the same remedy.)

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Brand the web apps (Priority: P1)

An administrator gives the server their company's look. They upload a light and a dark logo, set
the page title, the product name and the heading of the apps list, write the login help text and
the footer, and publish an announcement for all users. They choose which login links the login
page shows and whether single sign-on is forced. They maintain the app links that appear on the
login page and in the signed-in navigation. They pick a brand colour for each theme, and the API
tells them at once when a colour would make text unreadable. Signed-out visitors see the result on
the next load of the login page, in the new web app and, apart from the colours, in the legacy UI.

**Why this priority**: The mailcowui-ng constitution requires that product name, logo, colours,
links and login texts come from the API at runtime. Without this story the new web app cannot be
branded without the legacy UI, and the colours cannot be set at all.

**Independent Test**: As an administrator, upload logos of each accepted type and of rejected types
and contents, change every UI text, login page option, app link and colour, and read the public
configuration of feature 001 as a signed-out client after each change. Compare what the legacy UI
shows for the same stored data.

**Acceptance Scenarios**:

1. **Given** no logo, **When** an administrator uploads a 300 × 80 PNG as the light logo, **Then**
   the logo is stored, the administrator's read shows its type, dimensions and size, and a
   signed-out client receives it in the public configuration.
2. **Given** an SVG file that contains a `<script>` element or an `onload` attribute, **When** it is
   uploaded as the dark logo, **Then** the upload is refused with a validation error that names the
   unsafe content, and the stored dark logo is unchanged.
3. **Given** a file named `logo.png` whose content is a PDF, **When** it is uploaded, **Then** the
   upload is refused as an unsupported image type.
4. **Given** a stored light logo, **When** the administrator deletes it, **Then** the public
   configuration contains no light logo, and the web apps show the deployment's default logo.
5. **Given** a product name "Acme & Co Mail", **When** the administrator saves it, **Then** the
   public configuration returns "Acme & Co Mail" and the legacy UI shows exactly the same text.
6. **Given** an active announcement of type "warning", **When** the administrator deactivates it,
   **Then** the public configuration reports it inactive and the legacy UI no longer shows it.
7. **Given** a light brand colour `#FEBC23`, **When** it is saved, **Then** the API preserves
   `#febc23` and returns a black foreground. The UI keeps yellow fills and adapts text-only links.
8. **Given** a light brand colour `#1D4ED8` and no dark colour, **When** a client reads the public
   configuration, **Then** both themes receive `#1d4ed8` with a white foreground.
9. **Given** an app link "Helpdesk" with a user link `https://help.example.org/?u=%u` that is not
   hidden on the login page, **When** an administrator saves it and a signed-out client reads the
   public configuration of feature 001, **Then** the app links contain "Helpdesk", shown on the
   login page with its link, and with the signed-in address `https://help.example.org/?u=%u`, in
   which the client replaces `%u` with the signed-in username.
10. **Given** an app link whose link is `javascript:alert(1)`, **When** it is saved, **Then** it is
    refused with a validation error naming the entry.
11. **Given** a domain administrator, **When** they change any branding setting, **Then** the
    request is refused as forbidden.

---

### User Story 2 - Set the password policy and the password reset notification (Priority: P2)

An administrator raises the minimum password length and requires digits and mixed case. From then
on every password set through either UI or either API must meet the new policy. The administrator
also configures the password reset e-mail: the sender, the subject, and the text and HTML
templates with their placeholders, or the default templates mailcow ships. Leaving the sender or
the subject empty switches self-service password reset off.

**Why this priority**: The policy protects every account, and password reset depends on the
notification settings, but both have safe defaults and change rarely.

**Independent Test**: Change the policy and confirm that feature 003's password rules and the
legacy UI enforce it. Save notification settings with valid and invalid senders, with and without
templates, and confirm what the legacy reset flow sends and when it reports reset as unavailable.

**Acceptance Scenarios**:

1. **Given** the default policy (length 6, no character classes), **When** an administrator sets a
   length of 10 with digits and mixed case required, **Then** the response contains the new policy
   and its version tag, and a password `abcdefghij` is then refused by feature 003 and by the
   legacy UI.
2. **Given** a requested length of 2 or of 65, **When** it is saved, **Then** it is refused with a
   validation error that states the allowed range 3 to 64, and the policy is unchanged.
3. **Given** a sender `no-reply@` (invalid), **When** the reset notification is saved, **Then** it
   is refused with a validation error on the sender, and nothing is stored. (Legacy stores an empty
   sender and reports success.)
4. **Given** empty text and HTML templates, **When** an administrator reads the reset notification,
   **Then** it returns the default templates mailcow ships, marked as defaults.
5. **Given** a reset notification with an empty subject, **When** an administrator reads it,
   **Then** it reports that self-service password reset is unavailable.
6. **Given** two administrators who read the policy, **When** the first saves and the second saves
   with the version tag they read, **Then** the second save is refused as outdated.

---

### User Story 3 - Manage Rspamd settings rules, global regex maps and presets (Priority: P2)

An administrator adds an Rspamd settings rule from a preset, for example "Disable Rspamd for a
domain", adapts it, and activates it. They review the effective settings map that Rspamd receives.
They maintain the global regex maps, such as the sender denylist or the bad words list. The API
refuses a regex line that would not compile or that lacks the `/pattern/` form, and tells them
which line is wrong. Rspamd applies the change without a restart.

**Why this priority**: Spam control is a daily operational need, and a broken map silently
weakens filtering for every mailbox, so safe editing matters. The rules and maps keep working
through the legacy UI until then.

**Independent Test**: Create, list, edit, deactivate and delete settings rules, including
structurally broken content, and compare the effective settings map. Save every regex map with valid
and invalid lines and confirm with a test message that Rspamd applies the new content.

**Acceptance Scenarios**:

1. **Given** the rspamd presets, **When** an administrator lists them, **Then** each has an
   identifier, a translation key and an English headline, and its content.
2. **Given** a new rule with a description and the content of preset "Disable Rspamd for a domain"
   adapted to `example.org`, **When** it is created as active, **Then** the response points to the
   new rule, and within the settings map refresh interval Rspamd applies the rule to mail for
   `example.org`.
3. **Given** rule content with an opening brace that is never closed, **When** it is saved, **Then**
   it is refused with a validation error that names the unbalanced structure, and the stored rules
   and the effective settings map are unchanged.
4. **Given** the global sender denylist, **When** an administrator saves the lines `# comment`,
   `/.*@spam\.example$/i` and an empty line, **Then** the map is stored and Rspamd uses it without a
   restart.
5. **Given** a line `/[unclosed/i` or a line `spam.example` without slashes, **When** the map is
   saved, **Then** it is refused with a validation error that names the line number, and the map
   file is unchanged.
6. **Given** a regex map whose file does not exist on the server, **When** an administrator saves
   it, **Then** the request is refused as "map unavailable". (Legacy reports success and writes
   nothing.)
7. **Given** a domain administrator, **When** they list rspamd presets or settings rules, **Then**
   the request is refused as forbidden. **When** they list sieve presets, **Then** they receive
   them.
8. **Given** an active rule, **When** an administrator reads the effective settings map, **Then** it
   contains the rule's content in the block that mailcow generates for it, as the legacy screen
   shows it.

---

### User Story 4 - Maintain the global Sieve filters (Priority: P2)

An administrator edits the global Sieve pre-filter, which runs before every mailbox's own filters,
and the post-filter, which runs after them and, by default, files spam into the Junk folder. The
API refuses a script with a syntax error and shows where it is. A valid script is written where
Dovecot reads it, and Dovecot is restarted so that it compiles the script, unless the administrator
chooses to apply the change at the next restart.

**Why this priority**: Global filters affect every delivered message, so they need validation and an
explicit restart, but they change rarely.

**Independent Test**: Read both scripts, save valid and invalid scripts with and without deferred
restart, and confirm with test deliveries that Dovecot applies the saved script after the restart
and that invalid scripts never restart Dovecot.

**Acceptance Scenarios**:

1. **Given** the default post-filter, **When** an administrator reads the global filters, **Then**
   both scripts are returned with their version tags.
2. **Given** a pre-filter with a missing semicolon, **When** it is saved, **Then** it is refused
   with a validation error that states the line, the stored script is unchanged and Dovecot is not
   restarted.
3. **Given** a valid pre-filter, **When** it is saved, **Then** it is written to the shared file,
   Dovecot is restarted, the response reports the restart as done, and the next delivered message
   passes through the new pre-filter.
4. **Given** a valid post-filter saved with "apply at next restart", **When** the save succeeds,
   **Then** the file is written, Dovecot is not restarted, and the response reports the restart as
   deferred.
5. **Given** a container service that cannot restart Dovecot, **When** a valid script is saved,
   **Then** the script stays saved and the response and the audit record report the failed
   restart.
6. **Given** a script sent with the validate-only option, **When** it is valid, **Then** the
   response reports success, the stored script is unchanged and Dovecot is not restarted; **When**
   it is invalid, **Then** the response carries the same validation error as a real save.

---

### User Story 5 - Configure quota notifications (Priority: P2)

An administrator sets the sender, the subject and the HTML template of the e-mail that mailcow sends
to a user whose mailbox crosses 80% and 95% of its quota. A domain administrator, or an
administrator, enters the addresses that receive a copy of the quota notifications of one domain's
mailboxes, for example the customer's IT contact, and switches the copies on.

**Why this priority**: Quota notifications prevent full mailboxes, and resellers want copies, but
mailcow sends useful defaults without any configuration.

**Independent Test**: Save notification settings and per-domain copy recipients through the API,
push a test mailbox over 80% of its quota, and confirm the notification and the copies that mailcow
sends. Try copy recipients for a foreign domain as a domain administrator.

**Acceptance Scenarios**:

1. **Given** no stored template, **When** an administrator reads the quota notification settings,
   **Then** the default template is returned, marked as the default, together with the default
   sender and subject.
2. **Given** a sender `quota@example.org`, a subject and a template using `{{username}}` and
   `{{percent}}`, **When** they are saved and a mailbox crosses 80%, **Then** the user receives the
   notification from that sender with the rendered values.
3. **Given** a domain administrator of `example.org`, **When** they save the copy recipients
   `it@example.org` and `boss@example.net` as active, **Then** both receive a copy of the next quota
   notification for a mailbox in `example.org`.
4. **Given** copy recipients that contain `not-an-address`, **When** they are saved, **Then** the
   request is refused with a validation error naming that entry, and the stored recipients are
   unchanged. (Legacy saves the valid entries.)
5. **Given** an empty recipient list sent as active, **When** it is saved, **Then** the copies are
   stored as inactive, as in legacy.
6. **Given** a domain administrator, **When** they read or save copy recipients of a domain they do
   not manage, **Then** the response is "not found".

---

### User Story 6 - Send mass mail to mailboxes (Priority: P3)

An administrator announces planned maintenance to all users, or to the mailboxes of some domains.
They pick the recipients: everyone, only some domains, everyone except some mailboxes, or a list of
mailboxes. They see how many and which mailboxes will receive the message, write the subject and
the text (and optionally an HTML version), and confirm with the recipient count. The API delivers
the messages in the background at a limited rate, shows the progress, lets any administrator
cancel the job, and keeps the outcome for every recipient.

**Why this priority**: It is rarely used and high risk. The legacy form keeps working until then.

**Independent Test**: Preview each kind of selection, send jobs with matching and outdated counts,
cancel a running job, interrupt the service during a job, and compare the delivered messages and
recorded outcomes with the selection.

**Acceptance Scenarios**:

1. **Given** 3 domains with 120 receiving mailboxes, 2 inactive mailboxes and 4 resources, **When**
   an administrator previews "all mailboxes", **Then** the preview reports 120 recipients and lists
   them page by page.
2. **Given** that preview, **When** the administrator sends the job with the expected count 120,
   **Then** the job is created, its address is returned, and each of the 120 mailboxes receives one
   message addressed only to itself in its inbox, without spam filtering.
3. **Given** a mailbox created after the preview, **When** the job is sent with the expected count
   120, **Then** it is refused as "recipient count changed" and nothing is sent.
4. **Given** a running job, **When** another administrator sends a second job, **Then** it is
   refused as "mass mail already running".
5. **Given** a running job with 60 recipients delivered, **When** an administrator cancels it,
   **Then** no further message is delivered, the remaining recipients are recorded as skipped, and
   the job is reported as cancelled.
6. **Given** an exclusion list that names `ghost@example.org`, which is not a receiving mailbox,
   **When** the selection is previewed or sent, **Then** it is refused with a validation error
   naming that entry.
7. **Given** a read-write API key owned by an administrator, **When** a client uses it to send mass
   mail, **Then** the request is refused as forbidden because a signed-in session is required.
8. **Given** a completed job, **When** an administrator opens it, **Then** it shows the sender,
   subject, bodies, selection, creator, times and the count per outcome, and its recipients can be
   listed filtered by outcome with the reason of each failure.

---

### User Story 7 - Operate the remaining server settings (Priority: P3)

An administrator sets a new password for the Rspamd web interface. They edit which origins and
methods the legacy `/api/v1` allows for cross-origin browser requests. They opt in to the external
IP check, which lets the dashboard show the server's public addresses. On an upstream installation
they read the server's GUID and verify it against the mailcow license service.

**Why this priority**: Each of these settings is changed rarely, and the legacy UI keeps offering
them.

**Independent Test**: Set the Rspamd password and sign in to the Rspamd web interface with it. Save
CORS settings with valid and invalid origins and methods and confirm the legacy API's headers. Toggle
the IP check. Verify the GUID with the license service reachable, unreachable and with the feature
disabled.

**Acceptance Scenarios**:

1. **Given** a signed-in administrator, **When** they set the Rspamd web interface password to a
   12-character passphrase, **Then** the response confirms it once mailcow has stored it, Rspamd is
   restarted, and the passphrase signs in to the Rspamd web interface.
2. **Given** a 5-character password, **When** it is submitted, **Then** it is refused with a
   validation error that states the minimum of 6.
3. **Given** the allowed origins `https://portal.example.org` and `*`, and the methods GET and
   POST, **When** they are saved, **Then** the legacy API answers cross-origin requests with those
   settings.
4. **Given** an origin `https://portal.example.org/app/`, **When** it is saved, **Then** it is
   refused with a validation error naming the entry, because an origin has no path.
5. **Given** license verification enabled and the license service reachable, **When** an
   administrator verifies the GUID, **Then** the result, with customer ID, service ID and support
   level, is returned and stored where the legacy dashboard reads it.
6. **Given** the license service unreachable, **When** the GUID is verified, **Then** the result is
   "unreachable" and is stored as such.
7. **Given** license verification disabled by the server setting, **When** an administrator reads
   the license status, **Then** the request is refused with the "feature disabled" error.
8. **Given** the IP check off, **When** an administrator turns it on, **Then** the opt-in is stored
   where legacy keeps it, and the legacy dashboard offers the public address lookup.

---

### User Story 8 - Trace settings changes (Priority: P3)

An administrator investigating why spam suddenly passes lists the audit log filtered by the global
regex maps and the settings rules and sees who changed what and when. Another reviews every mass
mail job that was sent.

**Why this priority**: The constitution requires an audit record for every change, but settings can
be managed without reading the log.

**Independent Test**: Make each kind of change, including refused ones, and list the audit log
filtered by target type and identifier.

**Acceptance Scenarios**:

1. **Given** an administrator who saved the global recipient denylist, **When** another administrator
   lists audit records for that map, **Then** the entry shows the actor, time, client address and
   the old and new size of the map.
2. **Given** a Rspamd password change, **When** the audit log is listed, **Then** the entry records
   that the password was changed and never contains the password.
3. **Given** a mass mail job that completed, **When** the audit log is listed, **Then** it contains
   one record for the request that created the job and one for its completion, with the counts.

---

### Edge Cases

- **Legacy defaults**: a setting that was never saved returns the legacy default: empty texts,
  announcement inactive, no logos, all login links shown, force single sign-on off, no app links,
  IP check off, password length 6 without character classes, no reset sender or subject, quota
  sender `quota-warning@localhost` and subject "Quota warning", the default templates, CORS for the
  server's own origin with GET, POST, PUT and DELETE.
- **Logo uploaded through legacy that would now be refused**, for example an SVG with a script or
  a file over a lowered limit: it stays stored and readable. It is replaced or deleted as usual.
  Clients render logos only as images, never inline into the page.
- **SVG without width and height**: accepted; SVGs have no dimension limit, as in legacy.
- **Animated GIF**: accepted, as in legacy.
- **Declared type differs from content**, for example a PNG sent as `image/jpeg`: the content
  decides, and the logo is stored with the detected type.
- **Deleting a logo that is not set**: succeeds and changes nothing.
- **Old footer key** written by older mailcow releases: it is returned as the footer when no
  footer is stored, and it is migrated on the next save, as legacy migrates it on read.
- **Plain-text field containing `<b>`**: stored escaped, so both UIs show the characters literally.
- **Announcement active without a type**: allowed, as in legacy. Clients show it with their neutral
  style.
- **Force single sign-on without single sign-on available**: stored; it has no effect, as in
  legacy, and the contract says so.
- **Dark colour unset and light colour too bright for the dark theme**: the dark theme gets no
  brand colour, and the web app uses its own palette there.
- **Built-in app links from the operator's configuration**: shown read-only next to the custom
  links and never changed by this API.
- **Groupware disabled**: built-in app links that point to the webmail are left out of the login
  page links and of the signed-in app links, as in legacy (custom links are shown as saved).
- **Password policy changed while passwords exist**: existing passwords keep working; only the next
  password set is checked.
- **Reset notification without the `{{link}}` placeholder**: accepted, as in legacy; the contract
  warns that the e-mail is then useless.
- **Quota template with a syntax error**: accepted, as in legacy; Dovecot falls back to the
  default template when it cannot use it, and the contract says so.
- **Quota copy recipients of an alias domain**: "not found", because notifications follow the
  mailbox's domain.
- **Domain deleted and created again**: its quota copy settings reappear, because domain deletion
  (feature 002) keeps them, as in legacy.
- **Settings rule with a brace inside a quoted string or a comment**: accepted; only structure
  outside strings and comments is checked.
- **Settings rule that is structurally valid but uses an unknown option**: accepted; Rspamd decides,
  and the effective settings map shows what Rspamd receives.
- **Regex line with two flags**, such as `/x/im`: refused, as the legacy page refuses it.
- **Regex map edited in the legacy UI at the same time**: the last write wins, as before. Clients of
  this API are protected by version tags.
- **Global Sieve script that is empty or only comments**: accepted and means no global filter (the
  legacy server accepts it; the legacy form requires text).
- **Shared Sieve or map file missing** because the deployment does not provide it: the save is
  refused and nothing restarts. (Legacy reports success.)
- **Dovecot restart during a mass mail job**: deliveries that fail temporarily are retried, so
  recipients are not lost.
- **Rspamd password containing quotes or spaces**: accepted and set exactly as given.
- **Rspamd restart failing after the password was stored**: reported as a container service
  failure; the new password applies at Rspamd's next start, and the response says so.
- **CORS origin written by an older release as a bare host name**: returned as stored and must be
  corrected before the CORS settings can be saved again.
- **CORS `*` together with specific origins**: allowed, as in legacy; `*` then allows every origin.
- **Mass mail with zero recipients**: refused.
- **Mailbox deleted or deactivated between preview and delivery**: its delivery fails and is
  recorded with the reason.
- **Mailbox over quota**: its delivery fails with Dovecot's reason.
- **Mass mail service interrupted** by a restart of this API: the job continues afterwards with the
  undelivered recipients; a recipient whose delivery was in progress is recorded as failed, so no
  one receives two copies.
- **Cancelling a completed job**: refused as a conflict. Cancelling a cancelled job succeeds and
  changes nothing.
- **GUID marked invalid by mailcow**: it is shown as invalid and can still be submitted for
  verification, as in legacy.
- **Demo mode enabled by the operator**: customisation writes are refused, as in legacy.
- **Domain administrator who loses a domain while editing its copy recipients**: the next request
  is answered "not found".
- **Shared store unavailable**: reads and writes are refused with a temporary failure and leave no
  partial change.
- **Audit record cannot be written**: the change it would record does not succeed.

## Requirements *(mandatory)*

### Functional Requirements

#### Access and scope

- **FR-001**: Every operation of this feature MUST be reserved to administrators, apart from two
  exceptions: domain administrators read and replace the quota notification copy recipients of the
  domains in their scope (FR-046) and read the sieve presets (FR-061).
- **FR-002**: Scope MUST be evaluated from the current mailcow data on every request, as in feature
  001 (FR-029 there). A domain assignment changed in either UI MUST apply to the next request.
- **FR-003**: A request for the quota notification copy recipients of a domain outside the caller's
  scope MUST get exactly the same "not found" response as a domain that does not exist (feature 002,
  FR-003). A request for an operation that the caller's role may never use MUST be refused as
  forbidden.
- **FR-004**: A request authenticated by an API key MUST act with the key owner's role, scope and
  flags (feature 001, FR-033). A read-only key MUST be refused on every operation that changes
  state, with the "insufficient key scope" error of feature 001. A restricted session (feature 001,
  FR-014) MUST be refused.
- **FR-005**: The operations MUST be authorised as follows:

  | Operation | Administrator | Domain administrator | Mailbox user |
  |---|---|---|---|
  | Read and change logos, UI texts, login page options, app links, branding colours, IP check | Yes | Forbidden | Forbidden |
  | Read the settings capabilities (FR-094) | Yes | Forbidden | Forbidden |
  | Change the password policy; read and change the reset and quota notifications | Yes | Forbidden | Forbidden |
  | Read and replace quota notification copy recipients | All domains | Own domains, no permission flag needed | Forbidden |
  | Rspamd settings rules, global regex maps, effective settings map | Yes | Forbidden | Forbidden |
  | Read rspamd presets | Yes | Forbidden | Forbidden |
  | Read sieve presets | Yes | Yes | Forbidden |
  | Read and replace global Sieve filters | Yes | Forbidden | Forbidden |
  | Set the Rspamd web interface password | Signed-in session only (FR-006) | Forbidden | Forbidden |
  | Read and replace CORS settings | Yes | Forbidden | Forbidden |
  | Preview recipients, list and read mass mail jobs | Yes | Forbidden | Forbidden |
  | Send or cancel mass mail | Signed-in session only (FR-006) | Forbidden | Forbidden |
  | Read license status, verify the GUID | Yes | Forbidden | Forbidden |
  | Read the audit log | Yes | Forbidden | Forbidden |

- **FR-006**: Sending mass mail, cancelling a mass mail job and setting the Rspamd web interface
  password MUST be possible only in a signed-in administrator session. A request authenticated by
  an API key MUST be refused as forbidden with a documented "session required" error, whatever the
  key's owner and access level.

#### Common rules for settings

- **FR-007**: A client MUST be able to change a settings resource by sending only the attributes it
  changes. Attributes that are not sent MUST keep their values. A sent list MUST replace the whole
  list. A value that is sent MUST be applied or refused by the rules, never ignored. The response
  MUST contain the updated resource and its new version tag.
- **FR-008**: Every mutable resource of this feature MUST carry a version tag: each logo, the UI
  texts, the login page options, the app links, the branding colours, the IP check, the password
  policy, the reset notification, the quota notification settings, each domain's copy recipients,
  each settings rule, each regex map, each global Sieve filter and the CORS settings. The tag MUST
  change whenever the stored value changes, whether the change was made through this API, the
  legacy UI or `/api/v1`.
- **FR-009**: A client MAY send the version tag it read with a change or a deletion. When the tag no
  longer matches, the request MUST be refused as outdated and MUST change nothing (feature 002,
  FR-042). The contract MUST recommend that interactive clients always send it.
- **FR-010**: Every setting that legacy keeps MUST be stored where legacy stores it and in legacy's
  format, so that the legacy UI, `/api/v1` and the mail services see the same result whichever
  implementation saved it. A change saved through the legacy UI or `/api/v1` MUST be visible in the
  next response of this API.
- **FR-011**: A refused request MUST change nothing. Validation errors MUST name each invalid field
  or list entry with a stable rule code and its parameters (feature 001, FR-055).
- **FR-012**: When the operator's demo mode is enabled, every change to logos, UI texts, login page
  options, app links, branding colours and the IP check MUST be refused with a documented "demo
  mode" error, as legacy refuses its customisation writes.

#### Logos

- **FR-013**: Administrators MUST be able to upload a light logo and a dark logo, each on its own.
  An upload MUST replace the stored logo of that variant.
- **FR-014**: The image type MUST be detected from the file content. PNG, JPEG, GIF and SVG MUST be
  accepted, and every other content MUST be refused as an unsupported image type. A type declared by
  the client MUST NOT be trusted. The logo MUST be stored with its detected type.
- **FR-015**: A logo MUST NOT be larger than the operator's logo size limit (legacy `LOGO_LIMITS`,
  15 MiB by default). A raster logo MUST decode as a valid image and MUST NOT exceed the operator's
  maximum width and height (1,920 × 1,920 pixels by default). The validation error MUST carry the
  limit.
- **FR-016**: An SVG logo MUST be a well-formed document whose root element is `svg`. It MUST be
  refused, with a validation error that names the construct, when it contains any of: script
  elements, event-handler attributes, URLs with a script or HTML scheme, embedded HTML or other
  foreign content, references to external resources (only in-document references and embedded
  raster image data are allowed), external style sheet imports, or document type or entity
  declarations. The API MUST NOT alter an SVG to make it acceptable.
- **FR-017**: Administrators MUST be able to delete the light and the dark logo, each on its own.
  Deleting a logo that is not set MUST succeed and change nothing. Without a stored logo, the
  public configuration contains none, and clients show the deployment's default logo (feature 001,
  FR-043).
- **FR-018**: Administrators MUST be able to read each logo with its type, its file size, its pixel
  dimensions (for SVG, the declared size when there is one) and its version tag, or learn that it
  is not set.

#### UI texts

- **FR-019**: Administrators MUST be able to read and change the UI texts: page title, product
  name, apps heading, login help text, footer, announcement text, announcement type (info,
  warning, danger or none) and whether the announcement is active.
- **FR-020**: The page title, product name and apps heading MUST be plain text of at most 255
  characters without line breaks. The API MUST store them in legacy's HTML-escaped form and return
  them as plain text, so that both UIs show the same text. The announcement text MUST be plain text
  and MUST be stored as given, as legacy stores it.
- **FR-021**: The login help text and the footer MUST be stored and returned as given. They are
  operator-authored HTML, and the contract MUST state that clients sanitise them before rendering
  (feature 001, FR-044).
- **FR-022**: The help text, the footer and the announcement text MUST each be at most 65,535
  characters. Any other announcement type MUST be refused.
- **FR-023**: An empty page title or product name MUST mean the deployment default (feature 001,
  FR-043), and an empty apps heading MUST mean the web app's translated default. When no footer is
  stored but the footer key of older mailcow releases is, the API MUST return that value as the
  footer and MUST move it to the current key on the next change of the UI texts.

#### Login page options

- **FR-024**: Administrators MUST be able to read and change the login page options: hiding the
  administrator, domain-administrator and mailbox-user login links, and forcing single sign-on.
  Each is a boolean. The contract MUST state that forcing single sign-on is a presentation option
  that takes effect only while single sign-on is available, and never makes the API refuse another
  sign-in method (feature 012, FR-020), as in legacy.

#### App links

- **FR-025**: Administrators MUST be able to read and replace the list of custom app links. Each
  entry has a name, a link shown on the login page and to signed-out users, an optional user link
  shown to signed-in users, and whether it is hidden on the login page. The order MUST be kept, and
  names need not be unique, as in legacy. The list MUST have at most 50 entries.
- **FR-026**: Each entry MUST meet these rules: the name is 1 to 255 characters without line
  breaks; the link is an absolute `http` or `https` URL or a path that starts with a single `/`;
  the user link, when given, has the same form and MAY contain the placeholder `%u`. An entry
  without a user link MUST use its link for signed-in users, as legacy does.
- **FR-027**: The administrator's read MUST also return the built-in app links that the operator
  defines in the mailcow configuration, marked read-only, as the legacy screen shows them.
- **FR-028**: The custom app links saved through this feature MUST appear in the app links of
  feature 001's public configuration (FR-056 there), after the built-in links and in their saved
  order, with the signed-in address and its `%u` placeholder, which clients replace with the
  signed-in username. Built-in links that point to the webmail are left out there when the
  groupware is disabled on the server, as legacy does. This feature offers no separate read of the
  effective app links.

#### Branding colours

- **FR-029**: Administrators MUST be able to read, set and clear a brand colour for the light theme
  and one for the dark theme. Each is optional and MUST be a six-digit hexadecimal sRGB colour
  (`#RRGGBB`), accepted in either letter case and stored in lower case.
- **FR-030**: Any valid six-digit colour MUST be accepted without changing its hue or brightness.
  Surface contrast MUST NOT reject brand fills. The UI MUST derive text-only accents separately
  with at least 4.5:1 contrast against the theme surface (`#ffffff` light, `#222932` dark).
- **FR-031**: The effective light-theme colour MUST be the light colour, or none when it is not set.
  The effective dark-theme colour MUST be the dark colour when set, otherwise the light colour.
  Each effective colour MUST come with black or white foreground text, whichever has higher contrast.
- **FR-032**: The colours MUST be stored by this API. They are not visible in the legacy UI, and
  clearing them returns the web app to its own palette.

#### Public configuration additions

- **FR-033**: The public configuration of feature 001 (FR-042 there) MUST additionally contain the
  effective brand colour and its foreground for each theme (FR-031). It already contains the apps
  heading (FR-020) and the app links with their login page visibility (FR-028, feature 001 FR-056),
  from which clients show the links that are not hidden on the login page. It MUST still contain
  nothing account-specific.
- **FR-034**: A change saved through this feature MUST appear in the public configuration on its
  next read. A change saved through the legacy UI MUST appear within one minute (feature 001,
  FR-043).

#### IP check

- **FR-035**: Administrators MUST be able to read and change the IP check opt-in, which is off by
  default and stored where legacy keeps it. The public address lookup of feature 011 (FR-021 there)
  is available only while the opt-in is on, because it contacts third-party services.

#### Password policy

- **FR-036**: Administrators MUST be able to change the password policy: a minimum length, a whole
  number from 3 to 64, and whether letters, digits, special characters, and both lower- and
  upper-case letters are required. When no policy is stored, the policy MUST be length 6 with no
  required character classes, the values mailcow seeds.
- **FR-037**: Reading the policy is feature 003's operation (FR-063 there, allocation row 003). The
  change response of this feature MUST return the whole policy and its version tag, and the read of
  feature 003 MUST carry the same version tag (FR-008), so that a client can send it with a change.
- **FR-038**: A changed policy MUST apply to the next password set through either UI or either API.
  It MUST NOT affect existing passwords and needs no reload.

#### Password reset notification

- **FR-039**: Administrators MUST be able to read and change the password reset notification: the
  sender, the subject, the plain-text template and the HTML template.
- **FR-040**: The sender MUST be empty or a valid e-mail address without control characters. The
  subject MUST be at most 255 characters without line breaks. Each template MUST be at most 1 MiB.
- **FR-041**: The read MUST report whether self-service password reset is available. As in legacy,
  it is available only when both a sender and a subject are set. The contract MUST state that
  clearing either switches password reset off (feature 005).
- **FR-042**: When both templates are empty, mailcow's default templates apply. The read MUST then
  return the default templates, marked as defaults. Saving both templates empty MUST restore the
  defaults. The contract MUST document the placeholders `{{link}}`, `{{username}}`, `{{username2}}`
  (the recovery address), `{{date}}`, `{{token_lifetime}}` and `{{hostname}}`.

#### Quota notifications

- **FR-043**: Administrators MUST be able to read and change the quota notification settings: the
  sender, the subject and the HTML template. The sender MUST be empty or a valid e-mail address
  without control characters. The subject MUST be at most 255 characters without line breaks. The
  template MUST be at most 1 MiB.
- **FR-044**: Empty values MUST mean Dovecot's defaults: the sender `quota-warning@localhost`,
  the subject "Quota warning" and mailcow's default template. The read MUST return the effective
  values and mark which are defaults. The contract MUST document that notifications are sent once at
  80% and once at 95% of the quota, the template variables `{{username}}` and `{{percent}}`, that
  Dovecot falls back to the default template when it cannot use the stored one, and that copies
  carry the user as sender and the subject followed by the username in parentheses.
- **FR-045**: A change MUST apply to the next notification without any reload.

#### Quota notification copy recipients

- **FR-046**: Administrators, and domain administrators for the domains in their scope, MUST be able
  to read and replace the quota notification copy recipients of a domain: a list of addresses and
  whether copies are active. No permission flag is needed, as in legacy.
- **FR-047**: The list MUST have at most 100 entries, each a valid e-mail address without control
  characters. Entries that differ only in letter case MUST be stored once, keeping the first
  spelling. An empty list MUST be stored as inactive, as in legacy.
- **FR-048**: A domain without stored copy settings MUST return an empty, inactive list. Copy
  recipients addressed with an alias domain's name, or with a domain that does not exist or is out
  of scope, MUST be answered "not found".
- **FR-049**: The copy settings MUST be stored where legacy keeps them, so that Dovecot sends
  the copies as it does today.

#### Rspamd settings rules

- **FR-050**: Administrators MUST be able to list, create, inspect, change and delete Rspamd settings
  rules. A rule has an identifier assigned at creation, a description, its content, whether it is
  active, its creation and modification times, and a version tag.
- **FR-051**: The description MUST be 1 to 255 characters. The content MUST NOT be empty after
  trimming, MUST be at most 1 MiB and MUST be stored trimmed. Outside of comments and quoted strings,
  its braces and brackets MUST be balanced and properly nested, and every quoted string MUST be
  closed. A rule created without an active state MUST be inactive, as in legacy.
- **FR-052**: The list MUST support pagination (25 by default, at most 1,000, with the total),
  sorting by identifier, description, active state, creation time and modification time (default:
  identifier, ascending), a filter on the active state, and a search on the description and the
  content.
- **FR-053**: A change MUST follow FR-007. Sending an empty description or empty content MUST be
  refused. (Legacy keeps the old value.)
- **FR-054**: A successful creation MUST respond as "created" with the address of the new rule. A
  successful deletion MUST respond with no body. A rule that does not exist MUST be answered "not
  found".
- **FR-055**: Rules MUST be stored where legacy stores them, so that Rspamd receives the active rules
  through mailcow's existing settings map at its next refresh, without a restart. The contract MUST
  state that a change takes effect within that refresh interval.

#### Global Rspamd regex maps

- **FR-056**: Administrators MUST be able to list and read the global regex maps that the operator's
  mailcow configuration makes editable (13 by default, from "Header-From: Denylist" to "Monitoring
  Hosts"). Each map has a stable identifier (its legacy file name), its English name, its content
  and a version tag. The list MUST support pagination and sorting by name and identifier, and a
  search on the name; it MUST return summaries (size instead of content), and the single read
  returns the content (API gap G17).
- **FR-057**: Administrators MUST be able to replace a map's content. The content MUST be at most
  1 MiB. Every line MUST be empty, a comment starting with `#`, or a regular expression written as
  `/pattern/` followed by at most one of the flags `i`, `m` or `s`, whose pattern contains no
  further `/` (legacy's check refuses it) and compiles. A failing line MUST be refused with a validation error that states its line number. The content
  MUST be stored trimmed and ending with a line break, as in legacy.
- **FR-058**: The content MUST be written to the map file that Rspamd reads, only when that file
  exists. A missing file MUST be refused as "map unavailable". The whole content MUST be written in
  one write, and the change MUST then be signalled again, so that Rspamd reloads the complete map
  without a restart, as in legacy, even if it read the file while it was being written.
- **FR-059**: A map identifier that is not one of the editable maps MUST be answered "not found".

#### Effective settings map

- **FR-060**: Administrators MUST be able to read the effective Rspamd settings map as mailcow
  generates it for Rspamd, read-only, as the legacy screen shows it. When it cannot be retrieved,
  the request MUST be refused with a temporary failure.

#### Presets

- **FR-061**: Clients MUST be able to list the presets that mailcow ships, one kind at a time:
  `rspamd` (administrators) or `sieve` (administrators and domain administrators). Each preset has
  an identifier, a translation key and an English headline, and its content. The presets are
  read-only. The list MUST support pagination and sorting by identifier, its default.

#### Global Sieve filters

- **FR-062**: Administrators MUST be able to read the global Sieve pre-filter and post-filter, each
  with its version tag.
- **FR-063**: Administrators MUST be able to replace either script. Line endings MUST be normalised
  to line feeds, as in legacy. The script MUST be at most 1 MiB and MUST be valid Sieve syntax as
  legacy's parser checks it. An invalid script MUST be refused with a validation error that states
  the line and the parser's reason. An empty script MUST be accepted and means no global filter.
- **FR-064**: The script MUST be written to the shared file that Dovecot reads at start, only when
  that file exists, so that the legacy UI and Dovecot see the new script in that same file. A
  missing file MUST be refused as "filter file unavailable", without a restart.
- **FR-065**: After a successful write, the system MUST restart Dovecot through mailcow's container
  service, as legacy does, unless the client asked to apply the change at the next restart. The
  response MUST report the restart as done, failed or deferred. A failed restart MUST NOT undo the
  write. The contract MUST warn that a restart briefly interrupts IMAP, POP3 and delivery.

#### Rspamd web interface password

- **FR-066**: A signed-in administrator (FR-006) MUST be able to set the Rspamd web interface
  password. It MUST be given once, MUST be 6 to 256 characters long and MUST NOT contain control
  characters.
- **FR-067**: The password MUST be handed to mailcow's container service, which stores its hash for
  Rspamd and restarts Rspamd, as legacy does. The request MUST succeed only when the service confirms
  it. A failure MUST be refused with a documented "container service failure" error. The password
  MUST NOT appear in any data this API stores, in any log or audit record, or in any response.

#### CORS settings of the legacy API

- **FR-068**: Administrators MUST be able to read and replace the CORS settings of the legacy
  `/api/v1`: the allowed origins and the allowed methods.
- **FR-069**: Each allowed origin MUST be `*` or an origin of the form `scheme://host[:port]` with
  the scheme `http` or `https`, a valid host name or IP address (IPv6 in brackets), and no path,
  query, fragment or credentials. Origins MUST be stored in lower case and once each. The list MUST
  have at most 100 entries. An empty list MUST mean the server's own origin, as in legacy.
- **FR-070**: The allowed methods MUST be a non-empty set taken from GET, POST, PUT and DELETE. When
  nothing is stored, the settings MUST be the server's own origin with GET, POST, PUT and DELETE.
- **FR-071**: The contract MUST state that these settings govern only the legacy `/api/v1`, and that
  this API does not answer cross-origin requests.

#### Mass mail

- **FR-072**: Administrators MUST be able to request a recipient preview for a selection. The
  preview MUST return the number of recipients and the recipient addresses with pagination, sorted
  by address, with a substring search on the address, and MUST change nothing. The number of
  recipients MUST be the size of the whole selection, whatever the search.
- **FR-073**: A selection MUST start from every receiving mailbox: every mailbox that is not a
  resource and is active or allowed to receive mail. It MAY be limited to a list of domains, and MAY
  then either include only a list of mailboxes or exclude a list of mailboxes, not both. Every named
  domain and mailbox MUST belong to that starting set, or the selection MUST be refused with a
  validation error naming each failing entry.
- **FR-074**: A signed-in administrator (FR-006) MUST be able to send mass mail by creating a job
  with: a sender, a valid e-mail address without control characters; a subject of 1 to 255 characters
  without line breaks; a plain-text body, required, at most 1 MiB; an optional HTML body, at most
  1 MiB; a selection (FR-073); and the expected number of recipients. When the expected number
  differs from the number the selection yields at that moment, the request MUST be refused with the
  documented "recipient count changed" conflict, and nothing MUST be sent. A selection without
  recipients MUST be refused. The contract MUST document the legacy suggestion
  `noreply@<server host name>` for the sender.
- **FR-075**: Only one mass mail job MAY run on the server at a time. Creating a job while another
  is running MUST be refused with the documented "mass mail already running" conflict.
- **FR-076**: A job MUST deliver one message per recipient, addressed only to that recipient,
  directly into the mailbox through Dovecot's local delivery, as legacy does: not through
  Postfix and without spam filtering. The message MUST carry both bodies as alternatives when an
  HTML body is given, and the legacy mailer identification. Delivery MUST run in the background at
  a rate of at most 10 messages per second by default. A temporary refusal by Dovecot MUST
  be retried up to 3 times within 5 minutes before the recipient is recorded as failed.
- **FR-077**: A successful creation MUST respond as "created" with the address of the job. A job MUST
  show its identifier, creator, creation, start and end times, status (running, completed or
  cancelled), sender, subject, bodies, selection, and the number of recipients per outcome
  (pending, delivered, failed, skipped). Its recipients MUST be listable with pagination, sorted by
  address, filtered by outcome and searchable by address, each with its outcome and, for failures,
  Dovecot's reason and a stable failure code that clients translate (`overQuota`,
  `mailboxUnavailable`, `rejected`, `temporaryFailurePersisted`, `connectionFailed`,
  `interrupted`; API gap G16).
- **FR-078**: A signed-in administrator (FR-006) MUST be able to cancel a running job. No further
  message MUST be delivered once the cancellation is confirmed, and the pending recipients MUST be
  recorded as skipped. Cancelling a cancelled job MUST succeed and change nothing. Cancelling a
  completed job MUST be refused as a conflict.
- **FR-079**: No recipient MAY receive a job's message more than once. When this API is interrupted
  during a job, the job MUST continue afterwards with the recipients that are still pending, and a
  recipient whose delivery was in progress MUST be recorded as failed with the reason
  "interrupted".
- **FR-080**: Administrators MUST be able to list jobs with pagination, newest first by default,
  sortable by creation time and status, filtered by status, with a search on the subject. The list
  MUST return summaries without the bodies, which the single read returns (API gap G17). Jobs
  MUST be kept for the audit retention period (365 days by default, feature 001 FR-048) and MUST
  NOT be changed apart from their delivery progress and cancellation.
- **FR-081**: Previews and jobs MUST be readable by administrators by session or by an
  administrator's API key. Domain administrators and mailbox users MUST be refused.

#### License and GUID

- **FR-082**: Administrators MUST be able to read the server's GUID, which mailcow computes, or
  learn that mailcow marked it invalid, together with the last verification result: valid, invalid,
  unreachable or never verified, with the customer ID, service ID and support level when known.
- **FR-083**: Administrators MUST be able to verify the GUID now. The system MUST send only the GUID
  to mailcow's license verification service, MUST wait at most 10 seconds for a connection, and
  MUST store the result where the legacy dashboard reads it, in legacy's format. An unreachable
  service or an unreadable answer MUST produce the result "unreachable", which is stored and
  returned, not an error.
- **FR-084**: The system MUST NOT contact the license service except on an explicit verification
  request.
- **FR-085**: A server setting MUST enable or disable the license operations. Its default MUST be
  enabled, as upstream. The FeldHost deployment disables it. While disabled, both operations MUST be
  refused with a documented "feature disabled" error.

#### Audit

- **FR-086**: Every request of this feature that changes state, or tries to, MUST write exactly one
  audit record into feature 001's audit log, with the fields of FR-046 there and:
  - the target type (for example logo, UI texts, app links, branding colours, password policy,
    reset notification, quota notification, quota copy recipients, settings rule, regex map, global
    Sieve filter, Rspamd password, CORS settings, mass mail job, license) and the target
    identifier;
  - the outcome, including refusals and their reason;
  - for successful changes, each changed attribute with its old and new value. Templates, maps,
    rules, scripts, the help text and the footer MUST be recorded only as changed, with their old
    and new sizes. Logos MUST be recorded with their old and new type, dimensions and size. The
    Rspamd password MUST be recorded only as changed;
  - the outcome of each side effect: the Dovecot restart, the Rspamd confirmation;
  - for a mass mail job, the sender, the subject, a summary of the selection and the number of
    recipients.

  Previews and validate-only requests (FR-093) change nothing and MUST NOT be audited. As
  `docs/conventions.md` sets for every feature, refusals of authenticated callers are recorded
  (including read-only keys, restricted sessions and out-of-scope objects), while requests without
  authentication (401) and anti-forgery failures are not.
- **FR-087**: The completion of a mass mail job MUST write one further audit record with the number
  of recipients per outcome. A cancellation MUST also record the counts at the moment it was
  confirmed.
- **FR-088**: Administrators MUST be able to filter the audit log by the target types and target
  identifiers of this feature (feature 002, FR-058).

#### Cross-cutting

- **FR-089**: Errors MUST follow feature 001's conventions (FR-049 and FR-055 there). This feature
  MUST document at least these error types: outdated version, temporary failure, demo mode,
  session required, map unavailable, filter file unavailable, container service failure, mass mail
  already running, recipient count changed, and feature disabled. Validation errors MUST use stable
  rule codes with their parameters, including codes for an unsupported image type, unsafe SVG
  content, a too-low contrast, an invalid regex line, invalid Sieve syntax and an unbalanced settings
  rule.
- **FR-090**: Every operation MUST be described in the published API contract before it is
  implemented, and its behaviour MUST match the contract.
- **FR-091**: When mailcow's shared store is unavailable, requests that read or change settings kept
  there MUST be refused with a temporary failure and MUST leave no partial change.
- **FR-092**: This feature MUST NOT offer bulk actions. Each settings rule, map, filter and setting
  is changed with its own request, as every legacy screen of this feature does.
- **FR-093**: Replacing a global Sieve filter or a regex map, and creating or changing a settings
  rule, MUST accept a validate-only option. Such a request MUST apply every validation rule of the
  operation and report success or the validation errors exactly as a real request would, and MUST
  NOT store anything, restart anything or write an audit record.

#### Settings capabilities

- **FR-094**: Administrators MUST be able to read the settings capabilities, the server settings and
  limits a client needs before it submits:
  - the logo limits: the maximum file size, the maximum width and height of raster images, and the
    accepted image types (FR-014, FR-015);
  - whether license verification is enabled on this server (FR-085);
  - whether demo mode is enabled, which refuses the customisation writes (FR-012). Legacy has no
    other server mode that refuses writes of this feature;
  - the server's configured host name, and the sender that legacy suggests for mass mail
    (`noreply@<host name>`, FR-074);
  - the address of the Rspamd web interface, which legacy links on the same host (`/rspamd/`);
  - the colour reference surfaces and the minimum contrast ratio (FR-030);
  - the field limits of this feature: text lengths, template, rule, map and script sizes, the
    maximum numbers of app links, copy recipients, CORS origins and mass mail selection entries,
    the password policy range, the Rspamd password length, the default and maximum page sizes;
  - the mass mail delivery rate and retry policy (FR-076) and the maximum number of jobs running at
    a time (FR-075).

  The values MUST reflect the server's current configuration and MUST NOT include any secret or
  connection detail. The read MUST change nothing, and read-only keys MAY use it.

### Legacy Coverage

Every legacy capability allocated to feature 013 in `docs/roadmap.md` maps to requirements as
follows. Actions are `/api/v1` names; *form* marks legacy UI form handlers.

| Legacy action or form | Legacy implementation | Requirements |
|---|---|---|
| *form* `submit_main_logo` (light and dark logo upload) | `customize('add', 'main_logo' / 'main_logo_dark')`, `triggers.admin.inc.php` | FR-012 to FR-016 |
| *form* `reset_main_logo` | `customize('delete', ...)` for both logos | FR-017 |
| Logo preview with size, type and dimensions | `customize('get', 'main_logo_specs' / 'main_logo_dark_specs')`, `admin/customize/logo.twig` | FR-018 |
| `edit/ui_texts`, *form* UI texts | `customize('edit' / 'get', 'ui_texts')` | FR-019 to FR-023 |
| `edit/app_links`, *form* app links table | `customize('edit' / 'get', 'app_links')`, `$MAILCOW_APPS`, `header.inc.php` | FR-025 to FR-028, FR-033 |
| `edit/custom_login`, *form* login page | `customize('edit' / 'get', 'custom_login')` | FR-024 |
| `edit/ip_check`, *form* IP check | `customize('edit' / 'get', 'ip_check')`, dashboard IP display | FR-035 |
| Branding colours (new, API-owned) | none | FR-029 to FR-034 |
| `edit/passwordpolicy`, *form* password policy | `password_complexity('edit')` | FR-036 to FR-038 |
| `edit/reset-password-notification`, *form* password reset settings | `reset_password('edit_notification' / 'get_notification')` | FR-039 to FR-042 |
| `edit/quota_notification`, *form* quota notifications | `quota_notification('edit' / 'get')` | FR-043 to FR-045 |
| `edit/quota_notification_bcc`, *form* "Edit domain" quota notification copies | `quota_notification_bcc('edit' / 'get')`, `edit/domain.twig` | FR-046 to FR-049 |
| `add/rsetting`, `get/rsetting/all`, `get/rsetting/{id}`, `edit/rsetting`, `delete/rsetting`, *form* Rspamd settings map | `rsettings()` | FR-050 to FR-055, FR-093 |
| Active settings map view | `file_get_contents('http://nginx:8081/settings.php')` in `admin/system.php` | FR-060 |
| `edit/rspamd-map`, *form* global filter maps (with the browser's regex check, its "Validate" button and the "I will be careful!" gate) | `rspamd_maps('edit')`, `$RSPAMD_MAPS`, `admin.js` `validate_rspamd_regex` | FR-056 to FR-059, FR-093 |
| `get/presets/rspamd`, `get/presets/sieve` | `presets('get')`, `inc/presets/{rspamd,sieve}/*.yml` | FR-061 |
| `add/global-filter`, `get/global_filters/{all,prefilter,postfilter}`, *form* global pre- and post-filter (with the "Validate" button, `ajax/sieve_validation.php`) | `mailbox('add', 'global_filter')`, `mailbox('get', 'global_filter_details')` | FR-062 to FR-065, FR-093 |
| *form* `rspamd_ui` (Rspamd web interface password) | `rspamd_ui('edit')`, dockerapi `rspamd worker_password` | FR-006, FR-066, FR-067 |
| `edit/cors`, *form* CORS settings | `cors('edit' / 'get')` | FR-068 to FR-071 |
| *form* `mass_send` (System mails) | `sys_mail()`, `tab-sys-mails.twig`, `admin.js` (`mass_disarm`) | FR-006, FR-072 to FR-081, FR-087 |
| *form* `license_validate_now`, GUID card on the dashboard | `license('verify' / 'guid')`, `LICENSE_STATUS_CACHE` | FR-082 to FR-085 |
| Server values rendered into the legacy pages: the sender suggestion `noreply@<host name>`, the `/rspamd/` link, the logo `accept` list; `$LOGO_LIMITS` and `DEMO_MODE` applied but not shown | `tab-sys-mails.twig`, `tab-config-rspamd.twig`, `tab-config-customize.twig`, `vars.inc.php`, `prerequisites.inc.php` | FR-094 |

Parts of the legacy screens that belong to other features: the public reading of the branding
(001), the password policy read (003), password reset itself (005), mailbox Sieve filters and their
validation (005), the Rspamd actions and spam score (006), the quarantine settings and notification
template (006), fail2ban (011), the dashboard's public address lookup and Rspamd restart button
(011), and the identity provider that "force single sign-on" depends on (012).

### Legacy Parity and Deliberate Deviations

Parity reference: `source_code/data/web/inc/functions.customize.inc.php`, `functions.inc.php`
(`password_complexity`, `password_check`, `sys_mail`, `license`, `rspamd_ui`, `cors`, `valid_origin`,
`normalize_cors_origin`, `reset_password` cases `get_notification` / `edit_notification` /
`send_mail`, `hasDomainAccess`), `functions.quota_notification.inc.php`, `functions.rspamd.inc.php`,
`functions.presets.inc.php` and `inc/presets/`, `functions.mailbox.inc.php` (`global_filter`,
`global_filter_details`, `mailboxes`), `vars.inc.php` (`$LOGO_LIMITS`, `$MAILCOW_APPS`,
`$RSPAMD_MAPS`, `$PW_RESET_TOKEN_LIFETIME`), `prerequisites.inc.php` (`DEMO_MODE`),
`header.inc.php`, `json_api.php`, `triggers.admin.inc.php`, `admin/system.php`,
`admin/dashboard.php`, `ajax/sieve_validation.php`, the templates `admin/tab-config-*.twig`,
`admin/tab-globalfilter-regex.twig`, `admin/tab-sys-mails.twig`, `admin/customize/logo.twig`,
`mailbox/tab-filters.twig`, `edit/domain.twig`, `modals/admin.twig`, `base.twig`,
`user_index.twig`, `dashboard.twig`, the scripts `js/site/admin.js`, `js/presets/*.js`, and the
consumers `conf/rspamd/dynmaps/settings.php`, `conf/rspamd/local.d/multimap.conf`,
`conf/dovecot/dovecot.conf`, `Dockerfiles/dovecot/docker-entrypoint.sh` (global Sieve copy and
`sievec` at start, GUID generation), `Dockerfiles/dovecot/quota_notify.py`,
`Dockerfiles/dockerapi/modules/DockerApi.py` (`rspamd worker_password`),
`Dockerfiles/phpfpm/docker-entrypoint.sh` (`PASSWD_POLICY` seed) and `docker-compose.yml` (the
`/global_sieve/{before,after}`, `/rspamd_custom_maps` and `/tpls` mounts of php-fpm-mailcow).

The reference is upstream mailcow-dockerized `2026-09a`. `source_code/` points to the FeldHost fork
(`~/Projects/mailhosting`, branch `feldhost-202609a`), so the files above were diffed against that
upstream tag. `functions.inc.php`, `functions.rspamd.inc.php`, `functions.quota_notification.inc.php`,
`functions.presets.inc.php`, `functions.mailbox.inc.php`, `json_api.php`, the presets, `quota_notify.py`,
dockerapi and the admin configuration templates other than the customise tab are identical. The fork
differs in four ways that touch this feature:

1. The default page title and product name are `FeldHost™ Mailhosting UI` instead of
   `<MAILCOW_HOSTNAME> - mail UI` (`functions.customize.inc.php`, and the placeholders of
   `tab-config-customize.twig`). Feature 001 (FR-043) owns these defaults; this feature only stores
   empty values that fall back to them (FR-023).
2. The default logos are `/img/feldhost_logo.png` (light) and `/img/feldhost_logo_white.png` (dark)
   instead of `/img/cow_mailcow.svg` (`base.twig`, `dashboard.twig`). A deleted logo falls back to
   the deployment's default (FR-017).
3. The GUID and license card is removed from the dashboard (`dashboard.twig`), while `license()` and
   the `license_validate_now` trigger remain. This becomes the license server setting, enabled by
   default and disabled by the FeldHost deployment (FR-085).
4. Branding-only texts: the comments of the default global Sieve scripts and the language strings.
   They change no behaviour.

| Behaviour | Legacy | This feature |
|---|---|---|
| Who may change settings | Administrators; quota copy recipients also domain administrators with domain access; presets readable by administrators and domain administrators | Same, apart from the rspamd presets (FR-005). **Deviation**: rspamd presets are for administrators only |
| API keys | `/api/v1` keys act as unscoped admin; logos, mass mail, Rspamd password and license only as forms | Keys act as their owner; mass mail and the Rspamd password need a signed-in session (FR-004, FR-006) |
| Object outside scope | `access_denied` message with status 200 | "Not found" (FR-003) |
| Concurrent edits | Last write wins | Version tags (FR-008, FR-009). **Deviation** |
| Partial changes | `edit/ui_texts` overwrites every field, unsent fields become empty; other edits replace or keep values inconsistently | Unsent attributes keep their values; sent lists replace (FR-007). **Deviation** |
| Invalid values | Invalid sender stored empty; length < 3 keeps the old value; invalid copy recipients skipped; mismatched app link rows save nothing yet report success | Refused with field errors, nothing changes (FR-011, FR-036, FR-040, FR-043, FR-047, FR-026). **Deviation** |
| Demo mode | Customisation writes refused | Same, colours included (FR-012) |
| Logo type and limits | Declared MIME type in a list (gif, jpeg, pjpeg, x-png, png, svg+xml); `$LOGO_LIMITS` 15 MiB and 1920 × 1920 (raster only); Imagick must read it | Type detected from content, same four formats, same configurable limits (FR-014, FR-015). **Deviation** for content detection |
| SVG logos | Stored unchecked | Refused when they contain active content or external references (FR-016). **Deviation** (security) |
| Logo storage | `MAIN_LOGO`, `MAIN_LOGO_DARK` as `data:<type>;base64,...` | Same keys and format, with the detected type (FR-010, FR-014) |
| Logo reset | One form deletes both logos | Each logo deleted on its own (FR-017) |
| UI text storage | `TITLE_NAME`, `MAIN_NAME`, `APPS_NAME` HTML-escaped; `HELP_TEXT`, `UI_FOOTER`, `UI_ANNOUNCEMENT_TEXT` raw; type `info`/`warning`/`danger` or empty; `UI_ANNOUNCEMENT_ACTIVE` 0/1; `UI_IMPRESS` migrated to `UI_FOOTER` on read | Same storage; plain text in and out for the escaped fields; length limits added; old key migrated on the next change (FR-020 to FR-023). **Deviation** for the limits |
| Login page options | `CUSTOM_LOGIN` JSON with four 0/1 flags | Same (FR-024) |
| App links | `APP_LINKS` JSON list of `{name: {link, user_link, hide}}`; old `{name: link}` entries converted on read; no validation; built-in `$MAILCOW_APPS` merged read-only; `%u` replaced in the signed-in navigation; webmail links hidden with `SKIP_SOGO` | Same storage, conversion and merge; the links reach clients through feature 001's public configuration, where the client replaces `%u`; entries validated, at most 50, script URLs refused (FR-025 to FR-028). **Deviation** for validation |
| Branding colours | None | New, stored by this API, contrast-checked, published in the public configuration (FR-029 to FR-034) |
| IP check | `IP_CHECK` 0/1; the dashboard hides the lookup button, the lookup itself is not gated | Same storage; feature 011 offers the lookup only with the opt-in (FR-035) |
| Password policy | `PASSWD_POLICY` hash `length`, `chars`, `special_chars`, `lowerupper`, `numbers`; seeded 6/0/0/0/0; form allows 3 to 64 | Same storage and seed; 3 to 64 enforced by the API (FR-036). **Deviation**: out-of-range values refused instead of kept |
| Reset notification | `PW_RESET_FROM`, `PW_RESET_SUBJ`, `PW_RESET_TEXT`, `PW_RESET_HTML`; defaults from `/tpls/pw_reset_{text,html}.tpl` when both templates are empty; reset unavailable without sender or subject | Same storage, defaults and availability rule, reported in the read (FR-039 to FR-042) |
| Quota notification | `QW_SENDER`, `QW_SUBJ`, `QW_HTML`; Dovecot's `quota_notify.py` defaults to `quota-warning@localhost`, "Quota warning" and `/templates/quota.tpl`, falls back to it when the template does not parse; unused `retention_size` and `release_format` inputs | Same storage and defaults, reported in the read; the unused inputs are not offered (FR-043 to FR-045) |
| Quota copy recipients | `QW_BCC` hash per domain, JSON `{bcc_rcpts, active}`; split on space, comma, semicolon or line break; invalid entries skipped; case-sensitive duplicates removed; empty list forces inactive; alias domains accepted; the recipients are written as a JSON object instead of a list when entries were dropped, which Dovecot's `quota_notify.py` then iterates by key | Same storage; a list, refused on any invalid entry, case-insensitive duplicates, at most 100, always written as a JSON list; alias domains "not found" (FR-046 to FR-049). **Deviation** |
| Settings rules | `settingsmap` rows; content required at creation only; edit keeps old values for empty input and trims; no content check; `active` defaults to 0 | Same storage and default; content and description always required, trimmed and structurally checked (FR-050 to FR-053). **Deviation** |
| Settings map delivery | Rspamd polls `settings.php` (nginx:8081), which emits active rows as `additional_settings_<id>` blocks | Unchanged: php-fpm keeps serving it (FR-055, FR-060) |
| Regex maps | Written to `/rspamd_custom_maps/<file>` only when it exists, trimmed plus a line break, then touched after 1.5 s; missing file silently skipped and reported as success; regex check only in the browser (one flag `i`, `m` or `s`) | Same files and format; server-side line check; missing file refused; complete content visible to Rspamd (FR-056 to FR-059). **Deviation** |
| Validation without saving | "Validate" buttons for regex maps (browser only) and Sieve scripts (`ajax/sieve_validation.php`, which also refuses an empty script) | Validate-only option on the same operations, with the server's rules (FR-093) |
| Presets | YAML files; headline translated into the session language | Same files; translation key and English headline (FR-061). **Deviation**: translation by the client (feature 001, FR-055) |
| Global Sieve filters | Parsed with the PHP Sieve parser; CR/LF normalised; written to `/global_sieve/before` or `/after` only when the file exists; `dovecot-mailcow` restarted after every save (failure only a warning); missing file still restarts and reports success | Same parser rules, files and restart; deferred restart possible; missing file refused without restart; restart outcome reported (FR-062 to FR-065). **Deviation** |
| Rspamd web interface password | Two fields that must match; at least 6 characters; dockerapi runs `rspamadm pw`, writes `override.d/worker-controller-password.inc` and restarts Rspamd | One field; 6 to 256 characters without control characters; same dockerapi call (FR-066, FR-067). **Deviation** for the confirmation field and the limits |
| CORS | `CORS_SETTINGS` hash, origins joined with `, `; `*` or exact origins, lower-cased; methods from GET, POST, PUT, DELETE; empty origins fall back to the base URL; applied by `json_api.php` only | Same storage, rules and fallback; at most 100 origins; applied by `/api/v1` only (FR-068 to FR-071) |
| Mass mail recipients | Every non-resource mailbox of every domain, including inactive ones; include list intersected, otherwise exclude list subtracted; unknown addresses ignored | Receiving mailboxes only; optional domain limit; include or exclude, not both; unknown entries refused (FR-073). **Deviation** |
| Mass mail sending | Synchronous inside the form request after a browser checkbox; PHPMailer to `dovecot-mailcow:24` (LMTP), one message per recipient, `X-Mailer: MooMassMail`; failures reported as warnings; no record | Background job after a preview and a count confirmation; same delivery path and mailer identification; rate limit, single job, cancellation, retry of temporary failures, per-recipient outcomes kept (FR-072 to FR-081). **Deviation** |
| License | `versions` GUID; POST to `verify.mailcow.email` with a 10 s connect timeout; result cached in the session and in `LICENSE_STATUS_CACHE` | Same request, timeout and cache; result returned; disable setting (FR-082 to FR-085). Fork: card hidden |
| Lists of objects in one request | `/api/v1` accepts lists of identifiers for `edit/rsetting`, `delete/rsetting` and `edit/rspamd-map`; no legacy screen of this feature uses them | One request per rule or map (FR-092), as feature 002 does for objects without legacy mass actions. **Deviation** |
| UI log entries | Written to the `logs` table and the API log | Written to this API's audit log (FR-086), as in feature 001. **Deviation** |

### Key Entities *(include if feature involves data)*

- **Logo** (existing mailcow data): a light or dark image with its type, size and dimensions, kept in
  mailcow's shared store.
- **UI texts** (existing mailcow data): page title, product name, apps heading, help text, footer
  and the announcement with its type and active state.
- **Login page options** (existing mailcow data): three login-link visibility flags and "force single
  sign-on".
- **App link** (existing mailcow data): a name, a link, an optional user link and a login-page
  visibility flag. Custom links are editable; built-in links come from the operator's configuration.
- **Branding colours** (owned by this API): an optional colour per theme, with derived effective
  colours and foregrounds.
- **IP check opt-in** (existing mailcow data): whether third-party address lookup is allowed.
- **Password policy** (existing mailcow data): minimum length and required character classes.
- **Password reset notification** (existing mailcow data): sender, subject and templates, which
  together decide whether password reset is available.
- **Quota notification settings** (existing mailcow data): sender, subject and HTML template.
- **Quota notification copy recipients** (existing mailcow data): per domain, a list of addresses and
  an active state.
- **Rspamd settings rule** (existing mailcow data): description, content, active state and times.
- **Global regex map** (existing mailcow file): a named list of regular expressions that Rspamd reads.
- **Effective settings map** (generated by mailcow): the settings text Rspamd receives, read-only.
- **Preset** (shipped with mailcow): an example Rspamd settings rule or Sieve script.
- **Global Sieve filter** (existing mailcow file): the pre-filter or post-filter script Dovecot
  compiles at start.
- **Rspamd web interface password** (write-only): handed to mailcow's container service, never kept.
- **CORS settings** (existing mailcow data): allowed origins and methods of the legacy API.
- **Mass mail job** (owned by this API): sender, subject, bodies, selection, creator, times, status
  and per-outcome counts.
- **Mass mail recipient** (owned by this API): one address of a job with its outcome and failure
  reason.
- **License status** (existing mailcow data): the GUID and the last verification result.
- **Settings capabilities** (computed from the server configuration): the logo limits, the license and
  demo mode switches, the host name, the Rspamd web interface address, the colour references, the
  field limits and the mass mail delivery policy.
- **Audit record** (owned by this API, defined in feature 001): extended with the target types of
  this feature, the attribute changes and the side-effect outcomes.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For 100% of the settings in the parity test set, a value saved through the API leaves
  the same stored data as the legacy UI leaves for the same input, apart from the stated deviations,
  and both UIs show it identically.
- **SC-002**: A branding change saved through the API reaches signed-out clients on their next
  request in 100% of tests, and an administrator can upload a logo, set the product name and pick a
  brand colour in the new web app in under 3 minutes.
- **SC-003**: 100% of the logos in the hostile test set (SVGs with scripts, event handlers, script
  URLs, embedded HTML, external references or entity declarations, mislabelled files, oversized
  files) are refused, and none of them is stored.
- **SC-004**: Every effective colour reaches at least 4.5:1 with its black or white foreground;
  the chosen brand fill remains unchanged.
- **SC-005**: After a regex map or settings rule is saved, a test message is filtered according to the
  new content within 2 minutes, without restarting Rspamd.
- **SC-006**: 100% of invalid Sieve scripts, regex lines and unbalanced settings rules are refused
  with the failing line or construct, and none of them ever reaches Rspamd or Dovecot or causes a
  restart.
- **SC-007**: After a global Sieve filter is saved with an immediate restart, the next message
  delivered once Dovecot is back passes through the new script in 100% of tests.
- **SC-008**: In 100% of mass mail tests, every selected recipient ends with exactly one outcome,
  no recipient receives more than one copy, no message is sent when the confirmed count differs,
  and no message is delivered later than 5 seconds after a cancellation is confirmed.
- **SC-009**: A mass mail job to 10,000 mailboxes completes within 20 minutes at the default rate.
- **SC-010**: 100% of attempts by domain administrators to reach copy recipients outside their scope
  get the same response as for a domain that does not exist; 100% of attempts by non-administrators
  to use administrator-only operations, of writes with read-only keys, and of mass mail or Rspamd
  password requests with any key are refused.
- **SC-011**: 100% of changes sent with an outdated version tag are refused.
- **SC-012**: Every request in FR-086 and every job completion in FR-087 produces exactly one audit
  record, and a scan of all audit records, responses and service logs after the full test run finds
  no Rspamd password.
- **SC-013**: In 95% of requests, any settings resource, a page of 25 settings rules or a page of 25
  mass mail recipients is returned within 1 second.

## Assumptions

- The API container is added to mailcow-dockerized through `docker-compose.override.yml` with the
  same shared files and directories that php-fpm-mailcow mounts for this purpose: the custom Rspamd
  map directory, the two global Sieve files and mailcow's template directory. No other container
  changes (constitution V). The plan decides how the files are written so that every container
  keeps reading them.
- The API can read the operator's mailcow configuration, including local overrides, for the logo
  limits, the built-in app links, the editable regex maps, the demo mode and the groupware switch,
  as feature 001 assumes for its settings. The plan decides how.
- Dovecot's local delivery, mailcow's container service and mailcow's generated settings map
  are reachable from the API container on the mailcow network.
- The reference surfaces for text-only branding accents (`#FFFFFF` and `#222932`) match mailcowui-ng.
  They guide link rendering without restricting the brand fill.
- Feature 003 provides the password policy read (FR-063 there) and carries the version tag defined
  here. Features 003, 005 and 007 enforce the policy when they set passwords.
- Feature 005 implements password reset and the mailbox Sieve filters; feature 006 the quarantine
  notifications; feature 011 the public address lookup (FR-021 there) and the dashboard; feature 012
  the identity provider and single sign-on (FR-020 there).
- Feature 002's domain deletion keeps the domain's quota copy settings, as legacy does.
- The legacy UI never sends version tags, so it can still overwrite changes made through this API.
- These defaults can be changed by the operator: the logo limits (15 MiB, 1,920 × 1,920), the mass mail
  delivery rate (10 per second), the list page size (25, maximum 1,000), the audit and job retention
  (365 days) and the license setting (enabled).
- **Out of scope**:
  - per-domain branding, favicons, custom style sheets, and more than one brand colour per theme;
  - the quota thresholds (80% and 95%), which Dovecot's configuration fixes;
  - adding or removing regex maps, which the operator's configuration defines;
  - Rspamd actions, scores and quarantine settings (006), fail2ban (011);
  - password reset itself and mailbox Sieve filters (005);
  - identity-provider configuration (012);
  - cross-origin access to this API;
  - scheduled mass mail, attachments and mass mail to addresses outside the server;
  - bulk actions.
- **Dependencies**:
  - the upstream mailcow database schema of the supported release;
  - mailcow's shared store, the shared Sieve and map files, the template directory and the preset
    files;
  - mailcow's container service (Dovecot restart, Rspamd password), Dovecot's local delivery
    and mailcow's settings map endpoint;
  - mailcow's license verification service, for the license operations;
  - features 001 (identity, keys, errors, audit log, public configuration), 002 (domains and
    conventions) and 003 (password policy read).
