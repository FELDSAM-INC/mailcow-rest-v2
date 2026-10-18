# Feature Specification: Sync Jobs

**Feature Branch**: `main` (no feature branches, constitution 1.1.0)

**Created**: 2026-10-18

**Status**: Draft

**Input**: Feature description built from `docs/roadmap.md` (feature 008 and allocation row 008):

> Sync jobs for mailcow REST API v2: administrators, domain administrators and mailbox users
> create, list, search, inspect, edit, activate, deactivate and delete the imapsync jobs that pull
> mail from a remote IMAP account into a local mailbox on an existing mailcow-dockerized server.
> Each job stores the remote connection (host, port, encryption, remote user and password), the
> sync options (exclude pattern, polling interval, delete flags, destination subfolder, maximum
> message age, speed limit, connection timeouts, automap, skip-cross-duplicates, subscribe-all,
> dry run, custom imapsync parameters with their safety filtering) and an active flag. Clients also
> read the last run time, the last result, whether a run is in progress and the run log, all
> produced by the unchanged dovecot imapsync runner.
>
> Allocated legacy surface: `syncjob` add/get/edit/delete and `syncjobs` get, gated by
> `user_acl.syncjobs` and `da_acl.syncjobs`. The legacy mailbox "Sync jobs" tab (admin and domain
> administrator) and user "Sync jobs" tab need their list, the per-job edit form, the run status
> and the log viewer.
>
> Constraints: every legacy side effect is reproduced, so the imapsync runner, Postfix and Dovecot
> behave the same whichever implementation made the change. The imapsync runner stays exactly as it
> is; this API only manages rows and reads status and logs. The legacy UI and `/api/v1` keep
> working on the same data. The feature builds on feature 001's identity, roles, permission flags,
> API keys, error conventions and audit log, and on feature 003's mailboxes.

## Clarifications

### Session 2026-10-18

- Q: A sync job stores the remote account's password in plain text, which the imapsync runner needs
  to log in to the remote server. How is it shown or redacted? → A: The remote password is
  write-only. It is required at creation and is never returned by any representation (creation
  response, inspection or list). On an edit, omitting it keeps the stored password and sending it
  replaces it. Legacy's internal "with password" read mode, which `/api/v1` exposes, is not offered.
  (agent decision: legacy's default get already redacts `password1`; constitution VI forbids
  returning a secret after it was set, and exposing the plaintext remote password over the API is a
  needless leak. Not exposing the "with password" mode is a **Deviation** / security fix.)
- Q: When a caller lacks the sync-jobs permission, or addresses a job or mailbox outside their
  scope, is the answer "forbidden" or "not found"? → A: A role or account that does not hold the
  sync-jobs permission flag (a domain administrator without `da_acl.syncjobs`, a mailbox user
  without `user_acl.syncjobs`) is refused as **forbidden** on every sync-job operation, because the
  capability is one they may not use. A job or target mailbox that exists but lies outside the
  caller's scope is answered **not found**, exactly as one that does not exist. (agent decision:
  feature 002's convention; legacy returns `access_denied` for both, which reveals nothing either,
  and constitution VI requires that a guessed identifier reveals nothing.)
- Q: Custom imapsync parameters are a security risk. How are they filtered and hardened? → A: The
  API mirrors legacy's allow-list exactly: each parameter token must be a known imapsync option
  from the allow-list (an API server setting whose default is upstream's `IMAPSYNC_OPTIONS.whitelist`
  in `vars.inc.php`, kept aligned with legacy by the operator, per `docs/conventions.md`) and must
  contain no space; anything else is refused with a validation error naming the offending option. In addition, the
  option `showpasswords` is removed from the accepted set, so the remote password can never be
  written into the run log (**Deviation**, hardening). A server setting MAY further restrict custom
  parameters to administrators; its default reproduces legacy (any role holding the sync-jobs
  permission may set them). (agent decision: constitution VI, OWASP ASVS L2; the allow-list is the
  legacy mechanism, and `showpasswords` would defeat the runner's own password masking.)
- Q: Legacy validates the remote host name only on edit, not on create (the create check is
  commented out). What does the API do? → A: The API validates the remote host on both create and
  edit, as a host name (the legacy `is_valid_domain_name` rules: letter/digit labels with inner
  hyphens, at most 253 characters, labels at most 63) or an IPv4 literal. (agent decision:
  constitution IV allows a stated deviation to fix a legacy gap; accepting an unvalidated host on
  create is a defect. **Deviation**.)
- Q: Legacy silently clamps out-of-range `maxage`, `maxbytespersecond`, `timeout1` and `timeout2`
  to their defaults, but refuses out-of-range `port1` and `mins_interval`. What does the API do? →
  A: The API refuses every out-of-range numeric value with a validation error that names the field
  and its range, and never silently coerces it. (agent decision: silent coercion makes a client
  believe a value was stored when it was not; a validation error is testable and consistent.
  **Deviation** for the four clamped fields.)
- Q: Does the sync-job list support pagination, sorting, filtering and search? → A: Yes. The list
  is paginated (default page size 25, maximum 1,000, per `docs/conventions.md`), sortable, filterable by
  target mailbox, domain, active state, running state and last result, and searchable by a
  case-insensitive substring over the local mailbox, the remote user and the remote host. (agent
  decision: the legacy tables load every row and page client-side; server-side paging is required
  by constitution II and scales to many jobs. Enhancement over legacy.)
- Q: What does a sync job's version tag (ETag) cover? → A: It covers the stored configuration (the
  remote connection, the sync options and the active flag), not the runner-updated status (last
  run, last result, exit status, running flag and log). Resetting the schedule and the last result
  changes only status and therefore does not change the version tag. (agent decision: the status
  fields change on every run, so including them would make every edit stale; feature 002 treats
  usage figures the same way.)
- Q: What are a sync job's defaults when a field is omitted at creation? → A: The legacy add-form
  defaults: active, "subscribe all folders", "delete duplicates on destination" and "automap
  folders" on; a 20-minute interval; SSL encryption; `600`-second timeouts; `0` for maximum age
  (ignore age) and speed limit (unlimited); the exclusion pattern `(?i)spam|(?i)junk`; an empty
  destination subfolder and custom parameters; and every other flag off. An exclusion pattern sent
  explicitly empty means "no exclusion". The remote host, port, remote user and remote password are
  always required. (orchestrator decision: the UI 008 spec found that the earlier default of an empty
  exclusion contradicted the legacy add form, which pre-fills `(?i)spam|(?i)junk`. These values match
  what the legacy "Add sync job" modal pre-fills, so the web app and automation produce the same job
  legacy would.)
- Q: The run log is produced by the imapsync runner and may be large. Who may read it, and what is
  redacted? → A: Anyone who may read the job may read its log. The log is the remote server's and
  imapsync's own output; the runner masks passwords in it, and because `showpasswords` is refused
  the remote password never appears. The log is treated as operator-authored text that clients
  render safely. (agent decision: legacy exposes the log to anyone with object access; parity, with
  the `showpasswords` hardening above.)
- Q: Legacy activates, deactivates, reschedules ("Schedule next") and deletes several selected sync
  jobs at once. Should these mass actions be one bulk request, and what happens when some jobs
  fail? → A: One request applies one action (activate, deactivate, reschedule or delete) to up to
  100 sync jobs. Each job is checked, processed and audited on its own, failures do not stop the
  others, and the response reports one result per job: `done`, `notFound`, `refused`, `outdated` or
  `failed` (FR-023, FR-024). Each item may carry its own version tag: an outdated tag gives that item
  `outdated` and the others proceed, and untagged items are not version-checked. A reschedule item's
  tag is not checked, because rescheduling changes only run status (FR-021). Creation and other
  edits stay single-job requests. (orchestrator decision: cross-feature consistency with features
  003 and 004 and with `docs/conventions.md`. Legacy `edit/syncjob` and `delete/syncjob` take arrays
  and continue past per-job access failures, both legacy sync-job tabs offer these mass actions, and
  the web app needs them. 100 covers a large page.)
- Q: The server setting that restricts custom imapsync parameters to administrators is invisible to
  clients. How does a client learn which limits apply to it, and how is a refused custom-parameter
  request reported? → A: A sync-job capabilities read tells the caller whether custom parameters
  are allowed for them, the allow-list in effect, the bulk limit, the page sizes, every field's
  limits and the creation defaults (FR-030). When the restriction applies and a caller sends a
  custom-parameter value that differs from the stored one (on creation: any non-empty value), the
  request is refused with a field validation error on the custom-parameter field, with the rule code
  `customParamsNotPermitted`. (orchestrator decision: the UI 008 spec found this gap. Feature 002
  FR-064 sets the pattern of a capabilities read, and `docs/conventions.md` sends clients to the
  owning feature's capabilities read for server settings they need before they submit.)
- Q: Can an existing job's target mailbox be changed on edit? → A: No. The target mailbox is fixed
  at creation. An edit that sends a different target is refused with a field validation error on the
  target-mailbox field, with the rule code `immutable`. Sending the current target is accepted, so a
  client can send back what it read. To sync into another mailbox, the client creates a new job.
  (orchestrator decision: the UI 008 spec found this gap. Legacy `edit/syncjob` never reads the
  target, so it cannot be changed there either. Refusing instead of ignoring a sent value follows
  feature 002 and `docs/conventions.md`.)

## User Scenarios & Testing *(mandatory)*

### User Story 1 - List, inspect sync jobs and read their run status and logs (Priority: P1)

A mailbox user opens the "Sync jobs" tab in the new web app and sees their own sync jobs. Each row
shows what the legacy tab shows today: the remote server as `user@host:port`, the last run time,
the last result (success, failure or "waiting"), a human-readable exit status, whether a run is in
progress, the active state, the polling interval and the exclude pattern, and a link to open the
run log. An administrator sees the same tab for a mailbox and, from the mailbox list, a global view
of every sync job of every mailbox in their scope. A domain administrator with the sync-jobs
permission sees the jobs of mailboxes in the domains they manage.

The remote password is never shown. Opening a job shows its full configuration, its version tag
and the same run status, and opening the log shows the latest imapsync output for that job.

**Why this priority**: Every other sync-job task starts from this list, and reading the run status
and the log is how a user finds out whether a migration is working. It is also the safest first
slice, because it changes nothing.

**Independent Test**: Load a test copy of the mailcow database with sync jobs on mailboxes across
several domains, in every run state (never run, running, last run succeeded, last run failed, with
and without a stored log). List and inspect them as an administrator, as a domain administrator
with and without the sync-jobs permission, and as a mailbox user with and without it, and compare
every figure and the log with what the legacy UI shows for the same rows. Confirm no response
contains the remote password.

**Acceptance Scenarios**:

1. **Given** a mailbox with three sync jobs, **When** its owner lists their sync jobs, **Then** all
   three are returned with their remote server, interval, active state, last run, last result, exit
   status and running flag, and none contains the remote password.
2. **Given** sync jobs across ten mailboxes in five domains, **When** an administrator lists all
   sync jobs, **Then** the first 25 sorted by identifier are returned together with the total.
3. **Given** a job whose last run failed with an authentication error, **When** it is inspected,
   **Then** the last result is "failed", the exit status is the authentication-failure code and the
   log is available; **When** its log is read, **Then** the imapsync output is returned and it
   contains no password.
4. **Given** a job that has never run, **When** it is inspected, **Then** the last run is empty, the
   last result is "unknown" and the running flag is false.
5. **Given** a domain administrator who holds the sync-jobs permission and manages two of ten
   domains, **When** they list sync jobs, **Then** only the jobs of mailboxes in those two domains
   are returned. **When** they request a job of a mailbox in another domain by its identifier,
   **Then** the response is the same "not found" as for a job that does not exist.
6. **Given** a domain administrator who does not hold the sync-jobs permission, **When** they list
   or inspect sync jobs, **Then** the request is refused as forbidden.
7. **Given** a mailbox user who does not hold the sync-jobs permission, **When** they list sync
   jobs, **Then** the request is refused as forbidden.
8. **Given** a read-only API key owned by a domain administrator with the sync-jobs permission,
   **When** a client lists sync jobs with it, **Then** it receives exactly that domain
   administrator's jobs.

---

### User Story 2 - Create a sync job (Priority: P1)

A mailbox user, or an administrator on a user's behalf, adds a sync job to pull mail from a remote
IMAP account. They give the remote host, port, encryption, remote user and remote password, choose
a polling interval and the sync options, and optionally add an exclude pattern, a destination
subfolder and custom imapsync parameters. The administrator additionally chooses which local
mailbox the job delivers into; a mailbox user's job always delivers into their own mailbox. The new
job is picked up by the imapsync runner on its next cycle if it is active.

**Why this priority**: Pulling mail from an old provider is the reason sync jobs exist, and it is
the most common write for migrations.

**Independent Test**: Create sync jobs through the API as an administrator (choosing the target
mailbox) and as a mailbox user (own mailbox), with minimal input and with every option set, with
valid and invalid custom parameters, with a duplicate remote account, and with out-of-range
values. Compare the stored rows with those the legacy "Add sync job" modal produces for the same
input, and confirm the remote password is stored but never returned.

**Acceptance Scenarios**:

1. **Given** a mailbox user with the sync-jobs permission, **When** they create a job with only the
   remote host, port, remote user and remote password, **Then** the job is created for their own
   mailbox with the documented defaults (active, SSL, 20-minute interval, subscribe-all,
   delete-duplicates and automap on, timeouts 600, maximum age and speed limit 0, exclusion pattern
   `(?i)spam|(?i)junk`), and the response points to the new job, says a remote password is set and
   never contains the password.
2. **Given** an administrator, **When** they create a job and name a target mailbox in their scope,
   **Then** the job is created for that mailbox.
3. **Given** an existing job for the same local mailbox, remote user and remote host, **When** an
   identical job is created, **Then** creation is refused as a conflict and nothing is stored.
4. **Given** a custom-parameters value containing an option that is not on the allow-list, or a
   token containing a space, **When** the job is created, **Then** it is refused with a validation
   error naming the offending option, and nothing is stored.
5. **Given** a custom-parameters value containing `showpasswords`, **When** the job is created,
   **Then** it is refused with a validation error, because that option is not accepted.
6. **Given** an exclude value that is not a valid regular expression, **When** the job is created,
   **Then** it is refused with a validation error naming the field.
7. **Given** a polling interval of 0 or above 43800, a port outside 1–65535, a maximum age above
   32000, or a speed limit above 125000000, **When** the job is created, **Then** it is refused
   with a validation error naming the field and its range, and nothing is stored.
8. **Given** a remote host that is neither a valid host name nor an IPv4 literal (for example an
   IPv6 literal), **When** the job is created, **Then** it is refused with a validation error.
9. **Given** an administrator, **When** they create a job for a mailbox that does not exist or lies
   outside their scope, **Then** the response is "not found", and **When** the target is a resource
   or an alias rather than a mailbox, the request is refused with a validation error.
10. **Given** a mailbox user without the sync-jobs permission, or a read-only API key, **When**
    they try to create a job, **Then** the request is refused (forbidden, or insufficient key scope
    for the key).
11. **Given** a server that restricts custom parameters to administrators, **When** a mailbox user
    reads the sync-job capabilities, **Then** they are told that custom parameters are not allowed
    for them, together with the bulk limit and the field limits. **When** they create a job with a
    custom-parameter value, **Then** it is refused with a field validation error on the
    custom-parameter field with the rule code `customParamsNotPermitted`, and nothing is stored.
    **When** an administrator creates the same job, **Then** it is created.

---

### User Story 3 - Edit, activate, deactivate and reschedule a sync job (Priority: P1)

A user changes a sync job: the remote connection, the options, the custom parameters, or the active
state. They activate a paused job or deactivate a running one, and they reset a job's schedule and
last result so the runner picks it up on its next cycle. If someone changed the same job in the
meantime, in the new app, in the legacy UI or through `/api/v1`, the API refuses the stale change
instead of overwriting it. The remote password is changed only when a new one is sent; otherwise it
is kept.

**Why this priority**: Fixing a wrong password or host, pausing a finished migration and forcing an
immediate re-run are routine during a migration.

**Independent Test**: Edit each field, activate and deactivate, and reset the schedule, as an
administrator and as a mailbox user, with and without a new password, with a stale version tag, and
with invalid values. Compare the stored rows and the runner's selection with those of the legacy
edit form for the same input.

**Acceptance Scenarios**:

1. **Given** a job, **When** a client changes its interval and sends only that field, **Then** only
   the interval changes and every other field keeps its value, including the stored password.
2. **Given** a job, **When** a client sends a new remote password, **Then** it replaces the stored
   one; **When** a client sends an empty password, **Then** the stored password is kept.
3. **Given** two clients that read the same job, **When** the first saves a change and the second
   then saves with the version it read, **Then** the second save is refused as outdated and nothing
   changes.
4. **Given** an active job, **When** a client deactivates it, **Then** the runner no longer selects
   it; **When** the client reactivates it, **Then** the runner selects it again on its next cycle.
5. **Given** a job that last ran an hour ago, **When** a client resets its schedule and last result,
   **Then** the last run and last result become empty, the runner selects the job on its next
   cycle, and the job's version tag is unchanged.
6. **Given** a job whose edit would set an unknown custom option, an invalid exclude regular
   expression, an invalid host, or an out-of-range numeric value, **When** it is saved, **Then** it
   is refused with a validation error naming the field, and nothing changes.
7. **Given** a mailbox user without the sync-jobs permission, or a read-only API key, **When** they
   try to edit, activate, deactivate or reschedule a job, **Then** the request is refused.
8. **Given** a job of a mailbox outside the caller's scope, **When** the caller edits it, **Then**
   the response is "not found".
9. **Given** a job targeting `a@example.org`, **When** an administrator edits it and sends the
   target `b@example.org`, **Then** the edit is refused with a field validation error on the target
   mailbox with the rule code `immutable`, and nothing changes. **When** the edit sends the target
   `a@example.org` unchanged together with a new interval, **Then** the interval is saved.

---

### User Story 4 - Delete a sync job (Priority: P2)

A user who has finished a migration deletes a sync job. The job stops running immediately, and the
API tells the user the deletion succeeded.

**Why this priority**: Clearing finished jobs keeps the list tidy, but it is less frequent than
creating and editing, and the legacy UI can still do it.

**Independent Test**: Delete jobs that have never run, that last succeeded, that last failed and
that are in progress, as an administrator and as a mailbox user, and confirm the row is gone and
that a job outside the caller's scope is answered "not found".

**Acceptance Scenarios**:

1. **Given** a job, **When** its owner deletes it, **Then** the job is gone, the runner no longer
   selects it, and the response has no body.
2. **Given** a job that does not exist, or one of a mailbox outside the caller's scope, **When** a
   client deletes it, **Then** the response is "not found".
3. **Given** a job whose run is in progress, **When** a client deletes it, **Then** the row is
   removed at once; the runner's in-progress process is not interrupted, and its final status write
   for the removed job has no effect.
4. **Given** a mailbox user without the sync-jobs permission, or a read-only API key, **When** they
   try to delete a job, **Then** the request is refused.

---

### User Story 5 - Apply an action to many sync jobs at once (Priority: P2)

An administrator, a domain administrator or a mailbox user selects sync jobs in the list and
activates, deactivates, reschedules or deletes them, as the legacy mass actions do. They get one
result per sync job.

**Why this priority**: Operators pause, restart and clear many migrations at once during a
cut-over. One request per job would be slow and error-prone for the web app.

**Independent Test**: Apply each action to a selection that mixes jobs inside and outside a domain
administrator's scope, with one job that disappears before it is processed, and compare the per-job
results, stored rows and audit records with the same mass action in the legacy UI.

**Acceptance Scenarios**:

1. **Given** 50 active sync jobs, **When** an administrator deactivates them in one request,
   **Then** all 50 are inactive, the response has 50 successful results, and the audit log has 50
   records.
2. **Given** a domain administrator with the sync-jobs permission and a selection with three jobs of
   their mailboxes and two of another domain, **When** they reschedule the selection, **Then** the
   three jobs have their last run and last result cleared, the other two are reported "not found",
   and nothing else changes.
3. **Given** a domain administrator without the sync-jobs permission, or a read-only API key,
   **When** they submit any bulk action, **Then** the whole request is refused (forbidden, or
   insufficient key scope for the key).
4. **Given** a selection of 101 sync jobs, **When** it is submitted, **Then** the request is refused
   with a validation error that states the limit of 100.
5. **Given** a bulk deletion of five jobs, one of which another client deleted a moment earlier,
   **When** it is processed, **Then** that job is reported "not found", the other four are deleted,
   and the response has five results.
6. **Given** a mailbox user with the sync-jobs permission, **When** they activate a selection of
   their own jobs, **Then** each is activated and reported on its own.
7. **Given** a bulk deactivation of three jobs, each sent with the version tag the client listed,
   and one of them changed in the legacy UI after the list was read, **When** it is processed,
   **Then** that job is reported `outdated` and stays unchanged, and the other two are deactivated.
   **When** the same three jobs are sent without version tags, **Then** all three are deactivated.

---

### User Story 6 - Trace sync-job changes (Priority: P3)

An administrator investigating a migration lists the audit log filtered by a sync job or a mailbox
and sees every change: who created, edited, activated, deactivated, rescheduled or deleted a job,
when, from where, and what changed. No audit record ever contains the remote password.

**Why this priority**: The constitution requires an audit record for every change, but sync jobs
can be managed without reading the log.

**Independent Test**: Make each kind of sync-job change, including refused ones, then list the
audit log filtered by target and by mailbox, and confirm each change appears once with its actor,
time, source address and changed attributes, and that no record contains the remote password.

**Acceptance Scenarios**:

1. **Given** a job whose interval an administrator changed, **When** another administrator lists
   audit records for that job, **Then** the entry shows the actor, time, source address and the old
   and new interval, and the password is shown only as "changed" when it was changed.
2. **Given** a mailbox user whose edit was refused because it lacked the permission, **When** an
   administrator lists audit records for that mailbox, **Then** the refusal is listed with its
   reason.
3. **Given** a domain administrator or a mailbox user, **When** they request the audit log, **Then**
   the request is refused as forbidden (the audit log is administrators-only, as in feature 001).

---

### Edge Cases

- **Target that is not a real mailbox**: a sync job can target only a mailbox (`kind` empty). A
  resource, an alias, an alias domain or a domain is refused with a validation error, as it cannot
  receive synced mail.
- **Target mailbox deactivated or over quota**: the job is still created, edited and listed; whether
  it runs and whether delivery succeeds is the runner's and Dovecot's concern, unchanged.
- **Remote host as an IPv4 literal**: accepted. **IPv6 literal**: refused, because the legacy host
  check does not accept colons.
- **Encryption PLAIN**: accepted (no transport encryption to the remote), as in legacy.
- **Interval, port, maximum age, speed limit or timeout out of range**: refused with a validation
  error naming the field and its range. Maximum age `0` means "ignore age" and speed limit `0`
  means "unlimited"; both are accepted.
- **Empty exclude, subfolder or custom parameters**: accepted when sent explicitly; they mean "no
  exclude", "no subfolder" and "no extra parameters". An omitted exclusion at creation takes the
  default `(?i)spam|(?i)junk`.
- **Target mailbox sent with an edit**: a different target is refused with the `immutable` field
  error; the current target is accepted.
- **Custom parameters restricted to administrators, and a non-administrator edits a job whose
  parameters an administrator set**: an edit that omits the parameters, or sends them unchanged, is
  accepted; any other value is refused with the `customParamsNotPermitted` field error.
- **Custom parameters joined form**: tokens are the imapsync options the legacy form accepts, in the
  form `--option` or `--option=value`, concatenated as legacy stores them. An option not on the
  allow-list, an option containing a space, or `showpasswords`, is refused.
- **Duplicate job**: a job with the same local mailbox, remote user and remote host already exists →
  conflict on create. An edit that would make a job a duplicate of another is refused the same way.
- **Reset schedule while a run is in progress**: accepted; it clears the last run and last result.
  The in-progress run finishes and writes its own status; the next cycle re-runs the job.
- **Edit or deactivate while a run is in progress**: accepted; the change takes effect from the next
  cycle. The in-progress run is not interrupted.
- **Runner not running** (a non-master node, or the runner container stopped): jobs are still
  created, edited and deleted; they simply do not run until a runner processes them. The running
  flag of a job left set by an interrupted run is reset by the runner when its container restarts,
  not by this API.
- **Very large log**: the log is returned as stored; the contract notes it can be large.
- **Concurrent edits from the legacy UI**: the legacy UI never sends a version tag, so a legacy save
  can still overwrite a change made through this API, as before. Only clients of this API are
  protected against lost updates.
- **Concurrent creation of the same job**: exactly one succeeds; the other is refused as a conflict.
- **Version tags in a bulk action**: each item may carry its own tag. An outdated tag gives that
  item `outdated` and leaves it unchanged, and the other items proceed. Untagged items, and the tags
  of reschedule items, are not checked.
- **Bulk action naming the same sync job twice**: it is processed once and reported once.
- **Mailbox renamed or deleted** (feature 003): feature 003 moves this job's target to the new
  address on a rename and removes the job on a mailbox deletion. This feature does not duplicate
  that behaviour.
- **Audit record cannot be written**: the change it would record does not succeed.

## Requirements *(mandatory)*

### Functional Requirements

#### Access and scope

- **FR-001**: The sync-jobs capability MUST be gated by the per-account permission flag legacy
  stores as `syncjobs`. An administrator always holds it. A domain administrator holds it when their
  `da_acl.syncjobs` flag is set (legacy default: set). A mailbox user holds it when their
  `user_acl.syncjobs` flag is set (legacy default: unset). A caller who does not hold the flag MUST
  be refused as forbidden on every operation of this feature.
- **FR-002**: Within the capability, object access MUST follow legacy's mailbox access rule: an
  administrator reaches every sync job; a domain administrator reaches the sync jobs of mailboxes in
  the domains they manage through an active assignment; a mailbox user reaches only the sync jobs
  whose target is their own mailbox. The permission flag and the scope MUST be evaluated from the
  current mailcow data on every request, as in feature 001 (FR-029 there).
- **FR-003**: A request for a sync job, or for a target mailbox, that lies outside the caller's scope
  MUST get exactly the same "not found" response as one that does not exist. A request by a caller
  who does not hold the sync-jobs permission MUST be refused as forbidden (FR-001).
- **FR-004**: A request authenticated by an API key MUST act with the key owner's role, scope and
  flags (feature 001, FR-033). A read-only key MUST be refused on every operation that changes
  state, with the "insufficient key scope" error of feature 001 (FR-039). A restricted session
  (feature 001, FR-014) MUST be refused.
- **FR-005**: The operations MUST be authorised as follows:

  | Operation | Administrator | Domain administrator (with `syncjobs`) | Mailbox user (with `syncjobs`) | Caller without `syncjobs` |
  |---|---|---|---|---|
  | List and inspect sync jobs, read run status and log | All jobs | Jobs of mailboxes in their domains | Jobs targeting their own mailbox | Forbidden |
  | Create a sync job | Any mailbox | Mailboxes in their domains | Own mailbox only | Forbidden |
  | Edit, activate, deactivate, reschedule a sync job | All jobs | Jobs of mailboxes in their domains | Jobs targeting their own mailbox | Forbidden |
  | Delete a sync job | All jobs | Jobs of mailboxes in their domains | Jobs targeting their own mailbox | Forbidden |
  | Bulk actions: activate, deactivate, reschedule, delete (FR-023, FR-024) | All jobs | Jobs of mailboxes in their domains | Jobs targeting their own mailbox | Forbidden |
  | Read sync-job capabilities (FR-030) | Yes | Yes | Yes | Forbidden |
  | Read the audit log | Yes | Forbidden | Forbidden | Forbidden |

  A mailbox user MUST NOT be able to change their own `syncjobs` permission (feature 003 owns the
  permission flags).
- **FR-006**: A server setting MAY restrict the custom imapsync parameters of FR-017 to
  administrators. Its default MUST reproduce legacy: any caller holding the sync-jobs permission may
  set custom parameters. When the restriction applies to a caller, a custom-parameter value that
  differs from the stored one (on creation: any non-empty value) MUST be refused with a field
  validation error on the custom-parameter field, with the rule code `customParamsNotPermitted`, and
  nothing MUST change. Omitting the field, or sending the stored value unchanged, MUST be accepted.
  Whether the restriction applies MUST be readable through the capabilities read (FR-030).

#### Sync-job representation

- **FR-007**: A sync job MUST be addressed by a stable identifier. Its representation MUST contain:
  - the target local mailbox (the address mail is synced into);
  - the remote connection: host, port, encryption (SSL, STARTTLS or PLAIN) and remote user;
  - the sync options: exclude pattern, destination subfolder, polling interval in minutes, maximum
    message age in days, maximum bytes per second, connection timeout to the remote host and to the
    local host, and the boolean options delete-on-source (`delete1`), delete-on-destination
    (`delete2`), delete-duplicates-on-destination (`delete2duplicates`), automap, skip-cross-
    duplicates, subscribe-all and dry run;
  - the custom imapsync parameters;
  - the active state;
  - the run status (FR-008);
  - the creation and last modification times;
  - its version tag (FR-020).

  The representation MUST say whether a remote password is set, and MUST NOT contain the remote
  password itself (FR-018), nor the internal columns legacy
  never exposes through the forms (`authmech1`, which is always `PLAIN`; `regextrans2`; `domain2`;
  `authmd51`). The authentication mechanism MUST be reported as `PLAIN` and MUST NOT be settable.
- **FR-008**: The run status MUST be read from the columns the imapsync runner maintains and MUST
  report:
  - the last run time, or empty when the job has never run;
  - the last result: succeeded, failed, or unknown when the job has never run or its result was
    cleared;
  - the exit status, a short machine-readable code the runner writes (for example `EX_OK`,
    `EXIT_AUTHENTICATION_FAILURE_USER1`), or empty when there is none;
  - whether a run is in progress.

  The run status is read-only except for the reschedule operation (FR-016), which clears the last
  run time and the last result.
- **FR-009**: The run log MUST be readable as a separate part of the job (the imapsync output stored
  for the last run), as a value that MAY be empty. The contract MUST state that the log is produced
  by the remote server and the imapsync runner, that passwords in it are masked by the runner and
  that clients render it as plain text. Reading the log requires the same access as inspecting the
  job.
- **FR-010**: Times MUST be RFC 3339 UTC timestamps. The polling interval and the maximum age MUST
  be expressed in their legacy units (minutes and days). The speed limit MUST be expressed in bytes
  per second.

#### Listing and inspecting

- **FR-011**: Callers holding the sync-jobs permission MUST be able to list the sync jobs in their
  scope with pagination. The default page size MUST be 25 and the maximum 1,000, per
  `docs/conventions.md`.
  The response MUST include the total number of matching jobs. The list MUST be scopeable to a
  single mailbox and MUST otherwise span the caller's whole scope.
- **FR-012**: The list MUST support a free-text search that matches, case-insensitively and as a
  substring, the target mailbox, the remote user and the remote host.
- **FR-013**: The list MUST support these filters: target mailbox, domain of the target mailbox,
  active state, running state, and last result (succeeded, failed or unknown).
- **FR-014**: The list MUST be sortable, ascending or descending, by: identifier, target mailbox,
  remote host, remote user, polling interval, active state, last run time, last result, creation
  time and modification time. The default MUST be by identifier, ascending. Ties MUST be broken by
  identifier.
- **FR-015**: Each list entry MUST carry the sync-job representation of FR-007, including its
  version tag (FR-020), and its run status (FR-008), so that a client can send per-item version
  tags with a bulk action (FR-024) without inspecting each job. It MUST NOT include the log, so that
  a list of many jobs stays small. A client reads a job's log explicitly (FR-009).

#### Creating, editing, deleting and rescheduling

- **FR-016**: Callers holding the sync-jobs permission MUST be able to:
  - create a sync job; an administrator or a domain administrator MUST name the target mailbox, and
    a mailbox user's job MUST always target their own mailbox;
  - edit a sync job, sending only the attributes that change; unsent attributes MUST keep their
    values, including the remote password. The target mailbox MUST NOT be changeable: an edit that
    sends a different target MUST be refused with a field validation error on the target-mailbox
    field, with the rule code `immutable`, and nothing MUST change. Sending the current target MUST
    be accepted. To sync into another mailbox, a client creates a new job;
  - activate and deactivate a sync job (a change of the active flag), each as an ordinary edit;
  - reschedule a sync job: clear its last run time and last result so the runner selects it on its
    next cycle. Rescheduling MUST change only the run status and MUST NOT change the version tag
    (FR-020);
  - delete a sync job.

  A successful create MUST respond as "created" with the address of the new job. A successful edit,
  activate, deactivate or reschedule MUST respond with the updated job and its version tag. A
  successful delete MUST respond with no body. Activating, deactivating, rescheduling and deleting
  many jobs at once are covered by the bulk actions (FR-023, FR-024).
- **FR-017**: The attributes MUST meet these rules, at creation and on every edit:
  - target mailbox (at creation only; fixed afterwards, FR-016): an existing, in-scope mailbox
    (`kind` empty). A resource, alias, alias domain or domain MUST be refused with a validation
    error. A mailbox user MUST NOT be able to target any mailbox but their own;
  - remote host: required; a valid host name (letter/digit labels with inner hyphens, at most 253
    characters, labels at most 63) or an IPv4 literal (FR, clarification 4). At most 255 characters;
  - port: required; a whole number from 1 to 65535;
  - remote user: required; at most 255 characters;
  - remote password: required at creation; at most 255 characters; write-only (FR-018);
  - encryption: one of SSL, STARTTLS or PLAIN; default SSL (clarification 8);
  - polling interval: a whole number of minutes from 1 to 43800; default 20;
  - maximum age: a whole number of days from 0 to 32000, where 0 means "ignore age"; default 0;
  - maximum bytes per second: a whole number from 0 to 125000000, where 0 means "unlimited";
    default 0;
  - timeout to the remote host and timeout to the local host: whole numbers of seconds from 1 to
    32000; default 600 each;
  - destination subfolder: at most 255 characters; may be empty; default empty;
  - exclude: at most 500 characters; must be a valid regular expression (rule code
    `invalidRegex`); may be sent empty to mean "no exclusion"; default when omitted at creation:
    `(?i)spam|(?i)junk`, as the legacy add form pre-fills;
  - custom parameters: at most 512 characters; each option token MUST be on the allow-list and MUST
    contain no space, and the option `showpasswords` MUST be refused (FR-019); subject to the
    restriction of FR-006; may be empty; default empty;
  - the boolean options and the active flag: default per clarification 8 (active, subscribe-all,
    delete-duplicates-on-destination and automap on; delete-on-source, delete-on-destination,
    skip-cross-duplicates and dry run off).

  An out-of-range or malformed value MUST be refused with a validation error naming the field and
  its rule, and MUST NOT be silently coerced (clarification 5). A create whose target mailbox,
  remote user and remote host match an existing job MUST be refused as a conflict; an edit that
  would create such a duplicate MUST be refused the same way.
- **FR-018**: The remote password MUST be stored as plain text, because the imapsync runner needs it
  to authenticate to the remote server, but MUST be treated as a write-only secret: it MUST NOT
  appear in any representation, list, log or audit record, and the API MUST NOT offer a mode that
  returns it (the legacy "with password" read is not exposed). Responses MUST say only whether a
  remote password is set. On an edit, an omitted or empty password MUST keep the stored value and a
  non-empty password MUST replace it.
- **FR-019**: Custom imapsync parameters MUST be validated exactly as legacy validates them, using
  the allow-list. The allow-list MUST be an API server setting whose default is upstream's
  `IMAPSYNC_OPTIONS.whitelist`, which the operator keeps aligned with legacy (`docs/conventions.md`).
  The value is split into option tokens as legacy splits it, each token's option name MUST be on the
  allow-list (rule code `optionNotAllowed`, with the option as parameter), and no token may contain
  a space (rule code `optionContainsSpace`, with the token as parameter). Every option on the legacy
  blacklist (`skipmess`, `pipemess`, `regexmess`, `regexflag`, `regextrans2`, `maxlinelengthcmd`,
  `delete2foldersonly`, `delete2foldersbutnot`) is absent from the allow-list and is therefore
  refused. In addition, `showpasswords` MUST be refused even if the configured allow-list contains
  it, so that the remote password can never be written into the run log (**Deviation**, hardening).
  Every refusal MUST be a field validation error on the custom-parameter field. The server setting
  of FR-006 MAY further restrict who may set custom parameters.

#### Version tags

- **FR-020**: Every sync job MUST carry a version tag. The tag MUST change whenever the stored
  configuration changes — the remote connection, the sync options, the custom parameters or the
  active flag — whether the change was made through this API, the legacy UI or `/api/v1`. The tag
  MUST NOT change with the runner-updated status (last run time, last result, exit status, running
  flag and log), so that a run does not make an open editor's version stale.
- **FR-021**: A client MAY send the version tag it read with an edit, an activate/deactivate or a
  delete. When the tag no longer matches the stored configuration, the request MUST be refused as
  outdated and MUST change nothing. The contract MUST recommend that interactive clients always send
  it. The reschedule operation MUST NOT require or consume the version tag, because it changes only
  status (FR-016, FR-020).

#### The imapsync runner

- **FR-022**: This feature MUST NOT change the dovecot imapsync runner, its schedule or the
  `imapsync` table's columns. The API only inserts, updates and deletes rows and reads the
  runner-maintained status columns and log. The contract MUST state that an active job runs on the
  runner's own schedule (legacy: every minute on the master node, when the interval since the last
  run has elapsed), that this API never starts a run directly, and that rescheduling only makes the
  job eligible on the runner's next cycle.

#### Bulk actions

- **FR-023**: Callers holding the sync-jobs permission MUST be able to apply one of these actions to
  up to 100 sync jobs in one request, as the legacy mass actions do: activate, deactivate,
  reschedule or delete. A request with more than 100 sync jobs MUST be refused with a validation
  error that states the limit. Creation and other edits MUST NOT be offered as bulk actions.
- **FR-024**: A bulk action MUST be refused as a whole when the caller's role, key scope or
  permission flag (FR-001, FR-004) does not allow the action. Otherwise each named sync job MUST be
  processed on its own, with the same checks, side effects and audit record as a single request for
  it, and the response MUST report one result per distinct sync job: `done`, `notFound`, `refused`
  (with the error type), `outdated` or `failed` (with the error type). A failure on one sync job
  MUST NOT stop or undo the others, as in legacy. Each item MAY carry its own version tag: when the
  tag no longer matches, that item MUST get `outdated` and stay unchanged, and the other items MUST
  proceed. Items without a tag MUST NOT be version-checked, and the tag of a reschedule item MUST NOT
  be checked (FR-021). A sync job named twice MUST be processed once and reported once.

#### Reliability and cross-cutting

- **FR-025**: Every request of this feature that changes state, or tries to, MUST write one audit
  record per affected sync job into feature 001's audit log, with the fields of FR-046 there and:
  - the target type (sync job) and the target identifier, and the target mailbox;
  - the outcome, including refusals and their reason;
  - for successful changes, each changed attribute with its old and new value. The remote password
    MUST be recorded only as "changed", never as a value;
  - for a reschedule, that the schedule and last result were cleared;
  - for bulk actions, the identifier of the bulk request.
- **FR-026**: Administrators MUST be able to filter the audit log by target type, target identifier
  and target mailbox, in addition to the filters of feature 001 (FR-047 there). Domain
  administrators and mailbox users MUST be refused the audit log.
- **FR-027**: Errors MUST follow feature 001's conventions (FR-049 and FR-055 there). This feature
  MUST document at least these error types: sync job not found, sync job already exists (duplicate),
  outdated version, forbidden (permission not held), and validation failed with field-level rule
  codes and parameters. The rule codes MUST include `optionNotAllowed`, `optionContainsSpace` and
  `customParamsNotPermitted` on the custom-parameter field, `invalidRegex` on the exclude field,
  `immutable` on the target-mailbox field, each numeric range rule with its minimum and maximum, and
  the bulk limit.
- **FR-028**: Changes made through the legacy UI or `/api/v1` MUST be visible in the next response of
  this API. The remote password changed through the legacy UI MUST remain usable by the runner and
  MUST stay hidden from this API's responses.
- **FR-029**: Every operation MUST be described in the published API contract before it is
  implemented, and its behaviour MUST match the contract.

#### Capabilities

- **FR-030**: Every caller holding the sync-jobs permission MUST be able to read the sync-job
  capabilities, the server settings and limits a client needs before it submits:
  - whether custom imapsync parameters are allowed for this caller (FR-006), and the allow-list in
    effect, without `showpasswords` (FR-019);
  - the bulk limit (FR-023), and the default and maximum page sizes (FR-011);
  - each field's limits from FR-017: the numeric ranges of the port, polling interval, maximum age,
    speed limit and both timeouts, the maximum lengths of the remote host, remote user, remote
    password, destination subfolder, exclusion pattern and custom parameters, and the allowed
    encryption values;
  - the creation defaults of FR-017, including the exclusion pattern `(?i)spam|(?i)junk`.

  The values MUST reflect the server's current configuration and MUST NOT include any secret. The
  read MUST change nothing, and read-only keys MAY use it. A caller without the sync-jobs permission
  MUST be refused as forbidden.

### Legacy Coverage

Every legacy capability allocated to feature 008 in `docs/roadmap.md` maps to requirements as
follows. Actions are `/api/v1` names; *form* marks legacy UI form handlers.

| Legacy action or form | Legacy implementation | Requirements |
|---|---|---|
| `add/syncjob`, *form* "Add sync job" (admin/domain-admin modal with mailbox select, and user modal for own mailbox) | `mailbox('add', 'syncjob')` | FR-001 to FR-006, FR-016 to FR-019, FR-025 |
| `get/syncjobs/{mailbox}`, `get/syncjobs/all`, with the `no_log` and (internal) `with_password` variants | `mailbox('get', 'syncjobs')` + `mailbox('get', 'syncjob_details')`, `json_api.php` syncjobs case | FR-007 to FR-015, FR-018 |
| `get/syncjob_details` log field, *form* `inc/ajax/syncjob_logs.php` log viewer | `syncjob_details` `returned_text` → `log` | FR-008, FR-009 |
| `edit/syncjob`, *form* edit page, mass activate/deactivate, "Schedule next" (last-run reset) | `mailbox('edit', 'syncjob')`, `templates/edit/syncjob.twig`, `tab-syncjobs.twig`, `user/Syncjobs.twig` | FR-016 to FR-021, FR-023 to FR-025 |
| `delete/syncjob`, single and mass | `mailbox('delete', 'syncjob')` | FR-016, FR-023 to FR-025 |
| Sync-job table mass actions: activate, deactivate and delete (administrator, domain-administrator and user tabs), "Schedule next" (administrator and domain-administrator tab) | Multi-select `edit_selected` / `delete_selected` in `tab-syncjobs.twig` and `user/Syncjobs.twig`, `js/site/mailbox.js`, `js/site/user.js` | FR-023, FR-024 |
| `user_acl.syncjobs`, `da_acl.syncjobs` permission gate | `acl('to_session')`, `$_SESSION['acl']['syncjobs']` check in every syncjob case | FR-001, FR-005 |
| Custom-parameter allow-list | `IMAPSYNC_OPTIONS.whitelist` in `vars.inc.php` | FR-019, FR-030 |
| Form limits and hints (`min`/`max` attributes and range hints, `custom_params_hint`, pre-filled defaults) | `templates/modals/mailbox.twig`, `templates/modals/user.twig`, `templates/edit/syncjob.twig` | FR-017, FR-030 |
| Run status columns and log maintained by the runner | `imapsync_runner.pl`, `imapsync` columns `is_running`, `last_run`, `success`, `exit_status`, `returned_text` | FR-008, FR-009, FR-022 |

### Legacy Parity and Deliberate Deviations

Parity reference: `source_code/data/web/inc/functions.mailbox.inc.php` (`mailbox()` cases
`syncjob` under `add`, `edit` and `delete`, and `syncjobs` and `syncjob_details` under `get`),
`functions.inc.php` (`hasMailboxObjectAccess`, `is_valid_domain_name`), `functions.acl.inc.php`
(`acl('to_session')`), `json_api.php` (the `add/edit/delete/get` syncjob cases),
`inc/vars.inc.php` (`IMAPSYNC_OPTIONS`), `inc/init_db.inc.php` (tables `imapsync`, `user_acl`,
`da_acl`), `inc/ajax/syncjob_logs.php`, `templates/edit/syncjob.twig`,
`templates/mailbox/tab-syncjobs.twig`, `templates/user/Syncjobs.twig`,
`templates/modals/mailbox.twig` and `templates/modals/user.twig` (the add modals),
`js/site/mailbox.js` and `js/site/user.js` (the sync-job tables), and
`Dockerfiles/dovecot/imapsync_runner.pl` and `imapsync` (the runner, which this API does not
change).

The reference is upstream mailcow-dockerized `2026-09a`. `source_code/` points to the FeldHost fork
(`~/Projects/mailhosting`, branch `feldhost-202609a`), so the files above were diffed against that
upstream tag. **The sync-job surface is identical between the fork and upstream.**
`functions.mailbox.inc.php`, `json_api.php`, the `IMAPSYNC_OPTIONS` whitelist, the `imapsync` table
schema, the runner and the sync-job templates are unchanged in the fork. The only fork differences
anywhere near this surface are branding labels with no effect on sync jobs (the `OTP_LABEL`, the
`authsource` badge text in `mailbox.js`, and the `sasl` service label `WEBUI` in `user.js`), the
seeded domain DKIM selector, and unrelated docker-compose and SOGo theme entries. There are no
fork-only sync-job capabilities to make configurable.

| Behaviour | Legacy | This feature |
|---|---|---|
| Who may manage sync jobs | Any caller whose session `acl.syncjobs` is 1: admins always, domain admins per `da_acl.syncjobs` (default 1), users per `user_acl.syncjobs` (default 0) | Same (FR-001, FR-005) |
| Object access | `hasMailboxObjectAccess` on the target mailbox: own mailbox, or a domain the admin/DA manages | Same (FR-002) |
| Caller lacking the permission | `access_denied` | Forbidden (FR-001, FR-003) |
| Object outside scope | `access_denied` / empty result | "Not found" (FR-003) |
| Remote password in reads | Redacted by default; returned by the internal `with_password` mode, which `/api/v1` can request | Never returned; the `with_password` mode is not offered (FR-018). **Deviation** (security) |
| `showpasswords` custom option | Allowed (would unmask the password in the log) | Refused (FR-019). **Deviation** (hardening) |
| Other custom options | Allow-list in `vars.inc.php`; non-listed options and tokens with a space refused | Same checks, against an API server setting with the upstream allow-list as default; field errors with rule codes (FR-019) |
| Restricting custom parameters | Not possible; any caller with `syncjobs` may set them | Server setting, default as legacy; a refusal is the `customParamsNotPermitted` field error, and clients see the restriction in the capabilities read (FR-006, FR-030) |
| Target mailbox on edit | `edit/syncjob` never reads it, so a sent value is silently ignored | Fixed; a different value is refused with the `immutable` field error (FR-016). **Deviation**: refused instead of ignored |
| Form limits and defaults | Hard-coded in the add and edit forms | Readable through the capabilities read (FR-030) |
| Remote host validation | Not validated on create (check commented out); validated as a host name on edit | Validated as a host name or IPv4 literal on both create and edit (FR-017). **Deviation** (bug fix) |
| `maxage`, `maxbytespersecond`, `timeout1`, `timeout2` out of range | Silently clamped to the default | Refused with a validation error naming the field and range (FR-017). **Deviation** |
| `port1`, `mins_interval` out of range | Refused (generic error) | Refused with a field-level validation error (FR-017) |
| Authentication mechanism | Column `authmech1` exists (PLAIN/LOGIN/CRAM-MD5); add always stores `PLAIN`, edit never changes it | Always `PLAIN`, reported read-only, not settable (FR-007) |
| Unused columns | `regextrans2`, `domain2`, `authmd51` kept at defaults | Not exposed (FR-007) |
| Defaults at creation | The add modal pre-fills active, SSL, interval 20, timeouts 600, maxage 0, maxbytespersecond 0, exclusion `(?i)spam|(?i)junk`, delete-duplicates/automap/subscribe-all on | Same defaults applied when a field is omitted (FR-017, clarification 8) |
| Duplicate job | `(user2, user1, host1)` must be unique → `object_exists` on create | Same, as a conflict, on create and on an edit that would duplicate (FR-017) |
| Run status and log | Read from `is_running`, `last_run`, `success`, `exit_status`, `returned_text`; `returned_text` surfaced as `log` | Same, read-only (FR-008, FR-009) |
| Last-run reset ("Schedule next") | `edit/syncjob` with `last_run` and `success` set to empty → stored as NULL | A dedicated reschedule that clears both and does not change the version tag (FR-016) |
| Activate / deactivate | `edit/syncjob` with `active` 1/0, often as a mass action | An ordinary per-job edit of the active flag, or a bulk action (FR-016, FR-023) |
| Runner | Every minute on the master, selects `active = 1 AND is_running = 0` whose interval elapsed, runs imapsync, writes status and log | Unchanged; this API only manages rows and reads status (FR-022) |
| List | Both tables load every row and page client-side | Server-side pagination, sorting, filters and search (FR-011 to FR-015). **Deviation** (enhancement) |
| Concurrent edits | Last write wins | Version tags (FR-020, FR-021). **Deviation** |
| Mass actions | `edit/syncjob` and `delete/syncjob` with arrays; processing continues past per-job access failures, but a custom-parameter validation failure on one job stops the jobs after it | Bulk action with one result per job; optional per-item version tags (`outdated` result); no failure stops the others (FR-023, FR-024). **Deviation** for the stop on a validation failure |
| UI log entries | Written to the `logs` table | Written to this API's audit log (FR-025), as in feature 001. **Deviation** |
| SOGo / memcached side effects | None for sync jobs (the closing `update_sogo_static_view` block skips the `syncjob` type) | None (FR-022) |
| Re-validation of stored values on edit (plan-level note) | `edit/syncjob` re-validates every stored value, so a job whose host legacy add never validated cannot even be deactivated | Only members whose value changes are validated; a member sent with its stored value, and every member not sent, is accepted as stored (FR-006's "unchanged" rule applied to every member). **Deviation** |
| Target mailbox spelling (plan-level note) | `user2` is stored as typed, and the mailbox user's scope compares it case-sensitively | The target is stored as the mailbox's canonical username. **Deviation** (normalisation) |
| Custom-parameter errors (plan-level note) | Stops at the first bad option; edit does not trim the value and then refuses a leading space as an unknown option | Every bad option is reported at once (FR-019); the value is trimmed on create and on edit. **Deviation** |
| Columns written by an edit (plan-level note) | Rewrites the whole row, including `last_run` and `success`, and can overwrite a status the runner wrote after the job was read | Writes only the changed configuration columns, so the runner's status is never overwritten (FR-022). **Deviation** (bug fix) |

### Key Entities *(include if feature involves data)*

- **Sync job** (existing mailcow data, the `imapsync` table): one imapsync task that pulls mail from
  a remote IMAP account into a local mailbox. Attributes: target local mailbox, remote host, port,
  encryption, remote user, remote password (write-only), exclude pattern, destination subfolder,
  polling interval, maximum message age, maximum bytes per second, two connection timeouts, the
  delete/automap/skip-cross-duplicates/subscribe-all/dry-run booleans, custom imapsync parameters,
  active flag, creation and modification times.
- **Run status** (existing mailcow data, maintained by the imapsync runner): last run time, last
  result, exit status, whether a run is in progress, and the run log. Read-only to this API except
  for the reschedule operation, which clears the last run time and last result.
- **Sync-jobs permission** (existing mailcow data, owned by feature 003/007): the `syncjobs` flag in
  `user_acl` for a mailbox user and in `da_acl` for a domain administrator, gating the whole
  capability.
- **Mailbox** (existing mailcow data, feature 003): the sync job's target; this feature reads it to
  resolve the target and the caller's scope.
- **Bulk action** (transient): one action (activate, deactivate, reschedule or delete), up to 100
  sync jobs, each with an optional version tag, and one result per sync job.
- **Sync-job capabilities** (computed): whether custom parameters are allowed for the caller, the
  allow-list in effect, the bulk limit, page sizes, field limits and creation defaults.
- **Audit record** (owned by this API, defined in feature 001): extended with the sync-job target
  type, the target identifier, the target mailbox and the attribute changes, with the remote
  password recorded only as "changed".

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For 100% of the sync jobs in the parity test set, the configuration and run status the
  API returns equal those the legacy UI shows for the same rows, and no response, list, log or audit
  record contains the remote password.
- **SC-002**: A sync job created through the API with the same input as through the legacy "Add sync
  job" modal leaves an identical `imapsync` row, apart from the generated identifier and timestamps,
  and the imapsync runner selects and runs it identically.
- **SC-003**: 100% of attempts by a domain administrator or a mailbox user who lacks the sync-jobs
  permission to use any sync-job operation are refused as forbidden, and 100% of attempts to read or
  change a sync job of a mailbox outside the caller's scope get the same response as for a job that
  does not exist.
- **SC-004**: 100% of custom-parameter values containing a non-allow-listed option, a token with a
  space, or `showpasswords` are refused with a validation error naming the offending option, and
  100% of out-of-range numeric values are refused with a field-level validation error.
- **SC-005**: 100% of changes sent with an outdated version tag are refused, and no change sent with
  a current version tag overwrites a configuration change it did not see. In bulk actions, 100% of
  items with an outdated tag are reported `outdated` and left unchanged while the other items
  proceed. Rescheduling a job never fails on a version check.
- **SC-006**: In 95% of requests, a page of 25 sync jobs with run status is returned within 1 second
  on a server with 5,000 sync jobs across 100,000 mailboxes.
- **SC-007**: Every request in FR-025 produces exactly one audit record per affected sync job, and no
  audit record contains the remote password.
- **SC-008**: A mailbox user with the sync-jobs permission can create a sync job in the new web app
  in under 1 minute, and the imapsync runner begins the job within one runner cycle of it becoming
  active.
- **SC-009**: A bulk action on 100 sync jobs completes within 10 seconds and reports exactly one
  result per sync job.

## Assumptions

- The sync-jobs permission flags (`user_acl.syncjobs`, `da_acl.syncjobs`) are created and edited in
  features 003 and 007. This feature only reads them to gate the capability.
- The imapsync runner in the dovecot container is unchanged. It runs every minute on the master
  node, processes active jobs whose interval has elapsed, and is the sole writer of the run-status
  columns and the log. This API never starts a run directly and never writes those columns, except
  that the reschedule operation clears the last run time and last result.
- The target mailbox must exist before a sync job is created for it. Mailbox rename and deletion,
  which move or remove a job's target, are handled in feature 003.
- The remote password is stored in plain text because imapsync requires it; this is an inherent
  property of sync jobs, not a choice of this feature. The API keeps it hidden from every response.
- These defaults can be changed by the operator: the bulk limit (100), the custom-parameter
  allow-list (default: upstream's `IMAPSYNC_OPTIONS.whitelist`) and whether custom imapsync
  parameters are restricted to administrators (default: not restricted, as in legacy). The list page
  size (25, maximum 1,000) is the same for every list (`docs/conventions.md`) and is not a
  per-feature setting. Clients read the values in effect through the capabilities read (FR-030).
- Creating a sync job has no effect on Postfix, Dovecot delivery, SOGo, Redis or memcached beyond
  the runner eventually connecting to the remote server; the legacy code performs no such side
  effect for sync jobs.
- **Out of scope**:
  - the imapsync runner itself, its schedule, and any change to the `imapsync` table's columns;
  - starting, pausing or killing an in-progress run directly;
  - sync-job permission management (features 003 and 007);
  - mailbox creation, rename and deletion (feature 003);
  - outbound relay and address rewriting (feature 009);
  - bulk creation and bulk edits other than activate, deactivate, reschedule and delete.
- **Dependencies**:
  - the upstream mailcow database schema of the supported release (the `imapsync`, `mailbox`,
    `user_acl` and `da_acl` tables);
  - the dovecot container's imapsync runner, for running jobs and maintaining their status and log;
  - feature 001, for identity, roles, permission flags, API keys, errors and the audit log;
  - feature 003, for mailboxes and the sync-jobs permission flag, and for rename/delete cascades.
