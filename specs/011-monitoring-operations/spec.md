# Feature Specification: Monitoring and Operations

**Feature Branch**: `main` (no feature branches, constitution 1.1.0)

**Created**: 2026-10-18

**Status**: Draft

**Input**: Feature description built from `docs/roadmap.md` (feature 011 and allocation row 011):

> Monitoring and operations for mailcow REST API v2: administrators of an existing
> mailcow-dockerized server see the state of the host and of every mailcow container, start, stop
> and restart containers, read every log mailcow keeps, manage the Postfix mail queue, configure
> mailcow's fail2ban-style ban service and act on its bans, and read the Rspamd scan history and
> statistics. This is what the legacy admin dashboard ("System & Containers" and the log tabs), the
> Queue Manager and the fail2ban section of the System configuration show today.
>
> Allocated legacy surface: container status and control, host and vmail stats, version
> (`status/*`), all log types (`logs/*`), mail queue (`mailq`, `postcat`, flush, hold, delete),
> fail2ban settings and ban list (`fail2ban`, `edit/fail2ban`), Rspamd history and stats.
>
> Constraints: mostly administrator-only. Container control and queue deletion are destructive, so
> they need confirmation in the web app and an audit record. Logs and queued mail contain personal
> data, so access and filtering matter. The dashboard refreshes live, so acceptable freshness must
> be defined. Long-running operations such as a queue flush or a container restart must have
> specified behaviour. The legacy UI and `/api/v1` keep working on the same data. The feature builds
> on feature 001's identity, roles, API keys, error conventions and audit log, and follows feature
> 002's conventions.

## Clarifications

### Session 2026-10-18

- Q: Should domain administrators or mailbox users see any part of this feature, for example log
  entries about their own domain? → A: No. Every operation, including every read, is for
  administrators only. Domain administrators and mailbox users are refused as forbidden. An API key
  owned by an administrator may read everything, including the content of queued messages; only a
  read-write key may change anything. (agent decision: legacy protects the dashboard and queue pages
  with `protect_route(['admin'])`, and `get_logs`, `mailq`, `fail2ban` and `status/*` all check the
  administrator role. Keys act as their owner, as feature 001 defines.)
- Q: May an administrator stop or restart every mailcow container, including the ones the API itself
  depends on? → A: Every mailcow container can be started and restarted. Stopping is refused for the
  protected containers: the container service, the database, the shared store, the reverse proxy
  and this API's own container. This API's own container can be neither stopped nor restarted
  through itself. The list is a server setting. (agent decision: legacy `container_ctrl.php` accepts
  start, stop and restart for any `*-mailcow` service, but the legacy UI only offers restart. A
  stopped container service, database, shared store or proxy cannot be started again through the
  API, and a restart of the API's own container could never be answered.)
- Q: How do long-running operations behave, such as a container restart, a queue flush, deleting
  the whole queue or resetting the ban rules? → A: Synchronously, as in legacy. The response comes
  when the outcome is known, or after the container-service timeout (60 seconds by default, legacy
  `DOCKER_TIMEOUT`). A timeout is reported as a temporary failure whose outcome is unknown, and the
  client reads the state again. "Deliver now" and "flush" return once Postfix has accepted the
  request; delivery itself continues in the background and shows in the queue listing. There are no
  job resources. (agent decision: legacy waits for each container-service call; the constitution's
  status codes have no "accepted"; principle VIII.)
- Q: Must the API demand an explicit confirmation for destructive operations, such as stopping a
  container or deleting all queued mail? → A: No. The contract marks each destructive operation, and
  the web app must ask for confirmation and name what is affected, as its constitution requires.
  The API needs no confirmation token, and the audit log records who did it. (agent decision: legacy
  confirms in the browser only, automation clients act deliberately, and a token would add a round
  trip without adding authorisation.)
- Q: The legacy Queue Manager acts on many selected messages in one request. Should the API? → A:
  Yes. One request applies deliver now, hold, release or delete to up to 1,000 queue IDs. Each ID
  gets its own result (done or not in the queue), and the request writes one audit record that
  lists every ID with its result. (agent decision: legacy passes all selected IDs to one Postfix
  command, and a spam outbreak can leave thousands of messages to remove. Queued messages are
  transient system objects, not managed resources, so one record per request keeps the audit log
  usable. 1,000 is the largest page, and feature 003 uses the same pattern for bulk actions.)
- Q: How fresh must live dashboard data be, and does the API push updates? → A: The API does not
  push. Clients poll, and the contract recommends polling every 5 seconds while a view is visible,
  as the legacy dashboard does. Container states are at most 5 seconds old, host statistics at most
  10 seconds, container resource samples at most 15 seconds and mail storage usage at most 60
  seconds. Logs, the queue, bans and Rspamd data are read at request time. Every status response
  says when its data was collected, and collection is shared between clients, so more administrators
  do not mean more load on the container service. (agent decision: legacy polls every 5 seconds and
  the container service caches host statistics for 10 seconds; principle VIII.)
- Q: Logs and queued mail contain personal data. Should reads be audited, and should anything be
  hidden? → A: Log reads are not audited, but every view of a queued message's content is, as
  legacy does. In the legacy UI activity log and the legacy API request log, values of fields that
  hold secrets are masked before they are returned. (agent decision: legacy records `postcat` views
  in its activity log and nothing else; auditing each dashboard poll would bury the useful records.
  Legacy masks only fields named like "pass", so DKIM private keys, client secrets and Pushover
  tokens appear in its logs; constitution VI forbids secrets in responses.)
- Q: When ban service settings are out of range, or an allowlist or denylist entry is invalid,
  should the API correct and save, as legacy does, or refuse? → A: Refuse, naming each invalid
  value or entry and its rule, and change nothing. Ban rules must compile and contain a capture
  group for the address, and an empty rule list is refused. (agent decision: legacy silently raises
  values below the minimum, has a broken lower bound for the ban prefix lengths, skips invalid list
  entries and saves the rest, and accepts rules without a capture group, which stop the ban service
  when they match. Feature 002 refuses in the same situations.)
- Q: The FeldHost fork removes the version changelog and never runs the update check, and it shows
  `MAILCOWUI` logins as `WEBUI`. Are these kept? → A: Yes, as settings. The update check is a server
  setting, on by default (upstream) and turned off in the FeldHost deployment, with a configurable
  release source. The login log uses feature 001's service-label setting. (agent decision: fork-only
  behaviour is kept but configurable, per the roadmap.)
- Q: What does the version tag of the ban service settings cover, given that the ban service itself
  rewrites the stored settings? → A: The values: the parameters, both lists and the ban list ID. It
  changes when a value changes, whoever changed it, and does not change when the ban service writes
  back the same values or when bans come and go. Sending it is optional, as in feature 002. (agent
  decision: the ban service re-stores its settings on every ban and every 10 seconds, which would
  make a byte-based tag useless; constitution II.)
- Q: What does a client need to know before it polls, pages or acts, without hard-coding server
  values? → A: An administrator-only monitoring capabilities read, which read-only keys may use, on
  the pattern of feature 002's capabilities read (FR-064 there). It reports whether the IP check is
  enabled, the recommended polling interval and the maximum age of each kind of live data, the
  configured length of each in-memory log, the caller's own client address as the server sees it,
  and the bulk and list limits (FR-069). (orchestrator decision: the UI 011 spec found these gaps.
  The client address lets the web app warn before an administrator denylists their own address,
  which the edge cases leave to the client.)
- Q: Does the queue listing say how many messages each queue holds? → A: Yes. Every queue listing
  reports the number of messages in the active, deferred, hold and incoming queues and the total,
  counted over everything Postfix reported, independent of filters and paging (FR-037).
  (orchestrator decision: the UI 011 spec shows per-queue counts in the Queue Manager header, as an
  operator triages a spam outbreak or a stuck partner.)
- Q: Can a client tell what a container does without knowing mailcow's service names? → A: Yes.
  Each container carries a stable role next to its protected flag, for example proxy, database,
  key-value store, mail transfer, IMAP, webmail, API or scheduler, and `other` for containers the
  server does not know (FR-008). (orchestrator decision: the UI 011 spec groups and labels container
  cards by role and must not depend on service names, which operators can change.) Acting on all
  messages that match a filter is not added: the 1,000-ID bulk limit together with flush and delete
  all cover the legacy Queue Manager. The installed version stays administrator-only.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - See the state of the host and its containers (Priority: P1)

An administrator opens the dashboard of the new web app. As in the legacy "System & Containers"
tab, it shows:

- the server's host name, processor architecture, installed mailcow version, current time, time
  zone and uptime;
- live processor and memory charts: the number of cores, processor usage, total memory and memory
  usage;
- the disk holding the mail storage: its device, the space used against the total, and the
  percentage used;
- one card per mailcow container, with its name, image, start time and whether it is running. A
  card can be expanded to show live disk and network throughput charts.

While the dashboard is open, the charts update every few seconds. Each figure tells the app when it
was measured, so the app can show stale data as stale.

**Why this priority**: The dashboard is the administrator's landing page, and every operations task
starts with "is everything running?". It changes nothing, so it is also the safest first slice.

**Independent Test**: On a test installation with all containers running, one stopped and the
antivirus scanner disabled in the configuration, read the overview, the container list and the
resource usage of several containers through the API, and compare every value with the legacy
dashboard and the host's own tools. Poll the statistics as several administrators at once and count
the requests that reach the container service.

**Acceptance Scenarios**:

1. **Given** a running installation, **When** an administrator reads the system overview, **Then**
   it contains the host name, architecture, installed version, current time in UTC with the
   configured time zone, uptime, processor cores and usage, memory total and usage, and the time the
   figures were collected, which is at most 10 seconds earlier.
2. **Given** a mail storage disk of 100 GB with 25 GB used, **When** the administrator reads the mail
   storage usage, **Then** it reports the device, about 100 GB total, about 25 GB used and 25%
   used, in bytes.
3. **Given** a stopped SOGo container and the antivirus scanner disabled in the configuration,
   **When** the administrator lists containers, **Then** SOGo is listed as not running with its last
   start time, and the antivirus scanner is not listed, as in legacy.
4. **Given** a running Postfix container, **When** the administrator reads its resource usage twice,
   5 seconds apart, **Then** the second response contains disk read and write rates and network
   receive and send rates in bytes per second, and a sample time at most 15 seconds old.
5. **Given** a container that is not running, **When** its resource usage is requested, **Then** the
   request is refused at once with a "container not running" error.
6. **Given** a container of another project on the same host, **When** an administrator requests it
   by name, **Then** the response is "not found".
7. **Given** a domain administrator or a mailbox user, **When** they request any part of the
   overview, **Then** the request is refused as forbidden.

---

### User Story 2 - Start, stop and restart a container (Priority: P1)

An administrator sees that SOGo misbehaves and restarts it from its card, after confirming in a
dialog that names the container and warns that a graceful restart can take a while. The app waits,
then shows whether the restart succeeded and the container's new state. The same restart is offered
where legacy offers it: "Restart SOGo" in the menu and "Restart netfilter" next to the ban settings.
An administrator can also stop a container, for example the antivirus scanner on a small server, and
start it again later. A billing or deployment script with an administrator's read-write key can
restart a container after a configuration change.

**Why this priority**: Restarting a container is the most common fix in mailcow operations, and the
legacy dashboard offers it on every container.

**Independent Test**: Start, stop and restart containers in each state through the API, including
protected containers and this API's own container, with a container service that answers, fails and
times out. Compare the resulting states with the host's view, and check the audit records.

**Acceptance Scenarios**:

1. **Given** a running SOGo container, **When** an administrator restarts it, **Then** the response
   arrives after the restart completed, reports the outcome as done and shows the container running
   with a new start time.
2. **Given** a running container, **When** an administrator starts it, **Then** the request succeeds,
   reports that the container was already running, and changes nothing.
3. **Given** the database container, **When** an administrator tries to stop it, **Then** the request
   is refused with a "container protected" error that names the container, and the container keeps
   running. **When** they restart it, **Then** the restart is carried out.
4. **Given** this API's own container, **When** an administrator tries to stop or restart it, **Then**
   the request is refused with a "container protected" error.
5. **Given** a container service that does not answer within the container-service timeout, **When**
   an administrator restarts a container, **Then** the request fails with a temporary failure that
   says the outcome is unknown, and the audit record says the same.
6. **Given** a read-only key owned by an administrator, **When** it is used to restart a container,
   **Then** the request is refused with the "insufficient key scope" error of feature 001.
7. **Given** any container action, **When** it completes or is refused, **Then** exactly one audit
   record names the actor, the container, the action, the outcome and the state before and after.

---

### User Story 3 - Read and search mailcow's logs (Priority: P1)

An administrator investigates why a customer's mail bounced. They open the Postfix log, search for
the customer's address, narrow it to the last hour and to warnings and errors, and page back through
older entries. They switch to the Dovecot, SOGo, netfilter, autodiscover, watchdog, ACME, cron, rate
limit, legacy API request, legacy UI activity and login logs in the same way. Each log shows the
fields the legacy log tab shows. Encoded messages are shown decoded, and secrets that legacy wrote
into its activity and request logs are masked.

**Why this priority**: Logs are the main debugging tool, and the legacy dashboard offers 13 log
views. The web app cannot replace the legacy dashboard without them.

**Independent Test**: Fill each log of a test installation with known entries, including encoded
messages, entries with secrets, entries in different priorities and more entries than one page.
Read each log through the API with every filter, search and paging option, and compare the results
with the stored entries.

**Acceptance Scenarios**:

1. **Given** 10,000 Postfix log entries, **When** an administrator lists the Postfix log without
   parameters, **Then** the 25 newest entries are returned, newest first, with the total of 10,000.
2. **Given** Postfix entries mentioning `alice@example.org`, **When** the administrator searches for
   `ALICE@example.org`, **Then** only the entries containing that address are returned.
3. **Given** entries of priority `err`, `warning` and `info`, **When** the administrator filters by the
   error severity class, **Then** only the `err` entries, and any `emerg`, `alert` and `crit` entries,
   are returned.
4. **Given** an ACME log entry stored with a `base64,` prefix, **When** it is listed, **Then** its
   message is returned decoded.
5. **Given** a legacy API request log entry whose recorded data contains a DKIM private key and a
   password, **When** it is listed, **Then** both values are replaced with `*`.
6. **Given** a client that loaded the newest 100 entries, **When** new entries arrive and the client
   asks for the next 100 entries older than the oldest one it has, **Then** it receives them without
   duplicates.
7. **Given** a log type that does not exist, **When** it is requested, **Then** the response is "not
   found".
8. **Given** a domain administrator, **When** they request any log, **Then** the request is refused as
   forbidden.

---

### User Story 4 - Manage the mail queue (Priority: P1)

An administrator gets a complaint that mail to a partner is delayed. In the Queue Manager they
filter the deferred queue by the partner's domain, see each message's sender, recipients, size, age
and the reason delivery was deferred, and open one message to read its headers. Once the partner's
server is back, they select the messages and ask for immediate delivery, or flush the whole queue.
During a spam outbreak from a compromised mailbox, they filter by sender, put the messages on hold,
check one, and delete them, or, in an emergency, delete every queued message after confirming.

**Why this priority**: Stuck or unwanted queued mail is a frequent, time-critical operations task,
and the legacy Queue Manager is its only tool.

**Independent Test**: Fill the Postfix queue of a test installation with messages in the active,
deferred and hold queues. List, filter and sort them through the API, view a message, and apply
each action to single messages, to selections with IDs that are no longer queued, and to the whole
queue. Compare the queue afterwards with Postfix's own view and check the audit records.

**Acceptance Scenarios**:

1. **Given** 3 deferred messages to `partner.example` and 5 other queued messages, **When** an
   administrator lists the deferred queue filtered by recipient `partner.example`, **Then** exactly
   the 3 messages are returned, each with its recipients and their deferral reasons.
2. **Given** a queued message, **When** an administrator views its content, **Then** the response
   contains the complete message as Postfix shows it, and an audit record names the administrator
   and the queue ID but not the content.
3. **Given** 4 selected queue IDs of which 1 was delivered meanwhile, **When** the administrator puts
   them on hold, **Then** 3 messages move to the hold queue, the response reports them as done and
   the fourth as not in the queue, and one audit record lists all 4 IDs with their results.
4. **Given** held messages, **When** the administrator releases them, **Then** they return to the
   queue for delivery.
5. **Given** a deferred queue, **When** the administrator flushes the queue, **Then** the response
   arrives once Postfix has accepted the flush, and messages that can be delivered leave the queue
   in the following seconds. Held messages stay on hold.
6. **Given** queued messages in every queue, **When** the administrator deletes all queued mail,
   **Then** no message remains in any queue, including the hold queue.
7. **Given** a request naming 1,001 queue IDs, or an ID that is not hexadecimal, **When** it is
   submitted, **Then** it is refused with a validation error that states the limit or names the ID,
   and nothing changes.
8. **Given** a stopped Postfix container, **When** the administrator lists the queue, **Then** the
   request is refused with an error that names the stopped container.

---

### User Story 5 - Configure the ban service and act on bans (Priority: P2)

An administrator tunes mailcow's ban service, which the legacy UI calls "Fail2ban" and which bans
addresses after repeated failed logins: how long an address is banned, whether repeat
offenders are banned longer, how many failures within which window trigger a ban, and how large a
network a ban covers. They keep an allowlist of their office and monitoring networks and a denylist
of known attackers, and can let an external firewall enforce the bans through the public ban list
address. They review the active bans with their remaining time and the permanent bans from the
denylist, lift a ban that hit a customer, allowlist a partner's network straight from its ban, or
denylist an attacker. They can edit the patterns that recognise failed logins, or reset them to
mailcow's defaults.

**Why this priority**: The defaults work for most servers, and the ban service keeps working without
the web app, but lifting a wrongly banned customer is an urgent support task.

**Independent Test**: Read and change every setting, list and rule through the API, with valid,
out-of-range and invalid values. Produce bans by failed logins, lift, allowlist and denylist them,
rotate the ban list ID, and confirm with the running ban service and the firewall that each change
takes effect within the documented time.

**Acceptance Scenarios**:

1. **Given** default settings, **When** an administrator sets the ban time to 3,600 seconds and sends
   nothing else, **Then** only the ban time changes, and the response carries a new version tag.
2. **Given** a request with a ban time of 30 seconds or an IPv4 prefix length of 4, **When** it is
   submitted, **Then** it is refused with a validation error that names the field and its limits,
   and nothing changes.
3. **Given** a denylist containing `0.0.0.0/0`, or an allowlist entry `not a host!`, **When** it is
   saved, **Then** the request is refused and names the entry, and both lists stay unchanged.
4. **Given** an active ban of `203.0.113.0/24`, **When** the administrator lifts it, **Then** the ban
   is marked as queued for lifting at once, and it leaves the active bans within 15 seconds while
   the ban service runs.
5. **Given** an active ban, **When** the administrator lifts it and allowlists its network, **Then**
   the network is on the allowlist, not on the denylist, and the ban is lifted.
6. **Given** a network that is not actively banned, **When** the administrator tries to lift its ban,
   **Then** the response is "not found".
7. **Given** a rule set in which one pattern has no capture group, **When** it is saved, **Then** it is
   refused with a validation error that names the rule, and the stored rules are unchanged.
8. **Given** edited rules, **When** the administrator resets them, **Then** the ban service is
   restarted and the response contains mailcow's default rules.
9. **Given** a ban list address used by an external firewall, **When** the administrator rotates the
   ban list ID, **Then** the old address stops working and the response contains the new one.
10. **Given** two administrators who read the settings, **When** the first saves a change and the
    second saves with the version tag they read, **Then** the second save is refused as outdated.
    **Given** the ban service re-stores unchanged settings in between, **Then** a save with the tag
    read before is accepted.

---

### User Story 6 - Review Rspamd's scan history and statistics (Priority: P2)

An administrator checks why a message was rejected. In the Rspamd view of the dashboard they search
the recent scan history for the sender, see the action, score, required score and the symbols that
contributed, with their scores and options, and the message's size, scan time and authenticated
user. A chart shows how many messages Rspamd scanned and what it did with them.

**Why this priority**: It answers "why was this mail rejected or marked as spam?", but the Rspamd web
interface offers the same information to operators who need it now.

**Independent Test**: Scan known messages with a test Rspamd instance, then read the history with
each filter and sort option, and the statistics, and compare them with what Rspamd reports.

**Acceptance Scenarios**:

1. **Given** 1,000 history entries, **When** an administrator lists the history without parameters,
   **Then** the 25 newest are returned with the total of 1,000.
2. **Given** a rejected message from `spammer@example.net`, **When** the administrator searches the
   history for that sender, **Then** the entry is returned with the action `reject`, its score, the
   required score and its symbols ordered with the largest positive scores first.
3. **Given** Rspamd statistics, **When** the administrator reads them, **Then** they contain the number
   of scanned and learned messages and the count per action.
4. **Given** an unreachable Rspamd controller, **When** the history is requested, **Then** the request
   fails with a temporary failure.

---

### User Story 7 - Check for updates and look up the public addresses (Priority: P3)

An upstream mailcow administrator sees on the dashboard whether a newer mailcow release exists and
can read its release notes. When the operator has enabled the IP check in the legacy customisation
settings, the administrator can ask for the server's public IPv4 and IPv6 addresses, as seen from
outside, to compare them with the DNS records.

**Why this priority**: Both are conveniences. Updates are installed on the host, and the addresses
can be found with other tools.

**Independent Test**: With the update check enabled and disabled, and with a release source that
answers, fails or reports a newer release, read the version and update status. With the IP check
enabled and disabled, request the public addresses and count the outbound requests.

**Acceptance Scenarios**:

1. **Given** the update check enabled and a newer release published, **When** an administrator reads
   the update status, **Then** it reports an update with the newest version tag, its release page
   and its release notes.
2. **Given** the release source is unreachable, **When** the update status is read, **Then** it reports
   that the check failed, and the source is not asked again for 5 minutes.
3. **Given** the update check disabled, as in the FeldHost deployment, **When** the update status is
   read, **Then** it reports the check as disabled and no outbound request is made.
4. **Given** the IP check disabled in the customisation settings, **When** an administrator requests
   the public addresses, **Then** the request is refused with an "IP check disabled" error and no
   outbound request is made.

---

### User Story 8 - Trace operations (Priority: P3)

An administrator investigating an outage lists the audit log filtered by containers and sees who
restarted or stopped which container, when, from where and with what outcome. They can trace queue
deletions, views of queued messages, ban lifts and changes of the ban settings and rules the same
way.

**Why this priority**: The constitution requires an audit record for every change, but operations
work without reading the log.

**Independent Test**: Perform each state-changing operation of this feature, including refused ones,
and each queued message view, then list the audit log filtered by target type and target.

**Acceptance Scenarios**:

1. **Given** an administrator who stopped the antivirus container, **When** another administrator
   lists audit records for containers, **Then** the entry shows the actor, time, client address,
   container, action, outcome and the state before and after.
2. **Given** a change of the ban time and the allowlist, **When** the audit log is read, **Then** the
   entry shows the old and new ban time and the allowlist entries added and removed.
3. **Given** a ban list ID rotation, **When** the audit log is read, **Then** the entry records the
   rotation without either ID.

---

### Edge Cases

- **Container service unavailable**: container states, resource usage, mail storage usage, the queue
  and every container or queue action fail with a temporary failure, and nothing is changed.
- **Container service answers slowly**: an action is reported after the container-service timeout as
  a temporary failure with an unknown outcome. The client reads the container or the queue again.
- **Container removed or renamed between list and action**: the action is answered "not found".
- **Optional containers disabled in the configuration** (antivirus scanner, OLE scanner): they are
  hidden from the list, as in legacy, and requests for them are answered "not found".
- **Container in a transitional state** (restarting, paused, being removed): it is listed with that
  state. Start and stop decide by the container service's running flag, as legacy does.
- **Restart of the database or the shared store**: the restart is carried out. The audit record is
  written when the database is reachable again; if it still cannot be written after the
  container-service timeout, the response says the restart was done without an audit record.
- **Restart of the reverse proxy**: the restart is carried out, but the client may lose the
  connection before it receives the response. It reads the container state again.
- **Container resource usage requested for the first time**: the response comes within 5 seconds
  with one sample and no rates. Rates appear on the next request.
- **Mail storage measurement fails**: the usage is reported as unavailable, not as zeros (legacy shows
  zeros).
- **Log entry that cannot be parsed**: it is left out of the list and the total.
- **Log entry with a `base64,` prefix that does not decode**: the message is returned without the
  prefix, as the legacy dashboard shows it.
- **Log trimmed while a client pages**: entries older than the retention limit disappear. Pages ask for
  entries older than a given time, so trimming never causes duplicates.
- **Login log entries of the legacy web UI**: stored as `MAILCOWUI` upstream and `WEBUI` in the fork;
  reported with the configured label (feature 001).
- **Legacy log times without a time zone** (the login log): converted to UTC with the server's
  configured time zone.
- **Queue with more than 10,000 messages**: the first 10,000 Postfix reports are listed, and the
  response says the list is truncated. Whole-queue actions still affect every message.
- **Queue ID delivered or deleted between list and action**: reported as not in the queue; the other
  IDs are processed.
- **Content view of a message that left the queue**: "not found", and the audit record of the view
  records that outcome.
- **Releasing a message that is not on hold, or holding one that is already held**: done, without
  change, as Postfix treats it.
- **Flush with held messages**: held messages stay on hold.
- **Postfix container stopped**: queue operations are refused with an error that names the stopped
  container.
- **Ban service not running**: settings, lists and rules are saved and take effect when it runs. A
  lifted ban stays queued. Responses say that the ban service is not running.
- **Ban service settings never stored** (fresh installation before the ban service started): the
  ban service's defaults are reported, and saving stores a complete set.
- **Stored settings outside the rules**, for example from legacy: they are shown as stored, and an
  edit that does not change them is accepted.
- **Network on both lists in one request**: refused. Overlapping but different entries, such as an
  allowlisted network containing a denylisted address, are allowed; the denylist wins, as the ban
  service documents.
- **Denylist entry that covers the administrator's own address**: accepted, as in legacy. The contract
  recommends that clients warn, using the client address reported by the capabilities read (FR-069).
- **Allowlisted host name that does not resolve**: accepted, as in legacy; the ban service ignores it
  until it resolves.
- **Ban expires between listing and lifting**: "not found".
- **Rule reset while the server is idle**: the ban service stores its defaults only when it next
  receives a log line. If that does not happen within 10 seconds of its restart, the response says
  the defaults will appear with the next log line, as legacy reports.
- **Rspamd controller unreachable**: history and statistics fail with a temporary failure.
- **Update source unreachable or rate-limited**: the update status reports a failed check, cached for
  5 minutes.
- **Installed version without a published release** (for example a nightly build): the update status
  reports a failed check, as in legacy.
- **External IP check service unreachable for one address family**: that address is reported as
  unavailable and the other is returned.
- **Shared store unavailable**: in-memory logs, ban data and rules fail with a temporary failure, and
  saving settings changes nothing.
- **Audit log unavailable before an irreversible operation** (container action, queue action, rule
  reset): the operation is refused and nothing is done.

## Requirements *(mandatory)*

### Functional Requirements

#### Access and scope

- **FR-001**: Every operation of this feature, including every read, MUST be limited to
  administrators. Domain administrators and mailbox users MUST be refused as forbidden.
- **FR-002**: A request authenticated by an API key MUST act with the key owner's role (feature 001,
  FR-033). A key owned by a domain administrator MUST be refused as forbidden. A read-only key owned
  by an administrator MUST be able to use every read, including the content of a queued message,
  and MUST be refused on every state-changing operation with the "insufficient key scope" error
  (feature 001, FR-039). A restricted session (feature 001, FR-014) MUST be refused.
- **FR-003**: A request for a named object that does not exist, or is not part of this mailcow
  installation, MUST be answered "not found". This covers containers of other projects on the same
  host, hidden optional containers, queue IDs that are not in the queue, networks that are not
  actively banned and unknown log types.
- **FR-004**: The operations MUST be authorised as follows:

  | Operation | Administrator (session or read-write key) | Administrator read-only key | Domain administrator, mailbox user |
  |---|---|---|---|
  | System overview, mail storage usage, version, update status, public addresses, monitoring capabilities | Yes | Yes | Forbidden |
  | List and inspect containers, read resource usage | Yes | Yes | Forbidden |
  | Start, stop, restart a container | Yes, within FR-015 | Insufficient key scope | Forbidden |
  | Read logs, Rspamd history and statistics | Yes | Yes | Forbidden |
  | List the queue, view a queued message | Yes | Yes | Forbidden |
  | Queue actions on selected messages or the whole queue | Yes | Insufficient key scope | Forbidden |
  | Read ban settings, rules, active and permanent bans | Yes | Yes | Forbidden |
  | Change ban settings or rules, reset rules, act on bans, rotate the ban list ID | Yes | Insufficient key scope | Forbidden |
  | Read the audit log | As feature 001 defines | As feature 001 defines | Forbidden |

#### System overview

- **FR-005**: Administrators MUST be able to read a system overview containing:
  - the server's host name and the configured time zone;
  - the host's processor architecture;
  - the installed version (FR-018);
  - the host's current time as an RFC 3339 UTC timestamp and its uptime in seconds;
  - the number of processor cores and the processor usage in percent;
  - the total memory in bytes and the memory usage in percent;
  - the total and used swap space in bytes and the swap usage in percent;
  - the time these figures were collected.
- **FR-006**: Host figures MUST be at most 10 seconds old when returned. Collection MUST be shared
  between concurrent requests, so that the number of administrators polling does not change how
  often the host is measured.
- **FR-007**: Administrators MUST be able to read the usage of the disk that holds the mail storage:
  its device name, total, used and available space in bytes, the used percentage and the collection
  time, at most 60 seconds old. When the only available measurement is rounded, the converted values
  MUST be marked as approximate. When the measurement fails, the usage MUST be reported as
  unavailable, never as zeros.

#### Containers

- **FR-008**: Administrators MUST be able to list the containers of this mailcow installation,
  including stopped ones. Each container MUST be represented with:
  - its service name, for example `postfix-mailcow`, which identifies it in every operation;
  - its image;
  - its state as the container service reports it (running, restarting, paused, exited, created,
    dead or removing) and whether it is running;
  - its last start time;
  - its health status, when the container reports one;
  - whether it is protected (FR-015);
  - its role: a stable value that says what the container does (for example proxy, database,
    key-value store, mail transfer, IMAP, webmail, API or scheduler), derived by the server from the
    service name, with `other` for unknown services;
  - the actions currently available for it.

  Containers of other projects on the same host MUST NOT be listed or reachable. The antivirus
  scanner and the OLE scanner MUST be hidden when the configuration disables them, as in legacy.
- **FR-009**: The container list MUST support pagination (default 25, maximum 1,000), sorting by
  name (the default, ascending), state or start time, a filter on whether the container is running,
  and a case-insensitive search on the service name and the image. Ties MUST be broken by name.
- **FR-010**: Administrators MUST be able to inspect one container by its service name. Container
  states MUST be at most 5 seconds old when returned.
- **FR-011**: Administrators MUST be able to read the resource usage of a running container: the
  time of the newest sample, the processor usage in percent, the memory used and its limit in bytes,
  the cumulative disk bytes read and written, and the cumulative network bytes received and sent.
  When two samples at least one second apart are available, the response MUST also contain the disk
  read and write rates and the network receive and send rates in bytes per second between the two
  newest samples. While a client polls at the recommended interval, the newest sample MUST be at
  most 15 seconds old. The first request after a pause MUST be answered within 5 seconds, with rates
  absent if only one sample exists. A request for a container that is not running MUST be refused at
  once with a documented "container not running" error.
- **FR-012**: The contract MUST recommend polling the system overview and container resource usage
  every 5 seconds while they are visible, and MUST state the freshness limits of FR-006, FR-007,
  FR-010 and FR-011. The API offers no push channel.

#### Container control

- **FR-013**: Administrators MUST be able to start, stop and restart a container by its service name.
- **FR-014**: The actions MUST behave as in legacy:
  - start: a running container MUST be reported as already running, with no change. Otherwise the
    container MUST be started, retrying up to 4 attempts 1.5 seconds apart until it runs;
  - stop: a container that is not running MUST be reported as not running, with no change. Otherwise
    it MUST be stopped, with the same retries;
  - restart: the container MUST be restarted whatever its state, which also starts a stopped
    container.
- **FR-015**: A server setting MUST list the protected containers. By default they are the container
  service, the database, the shared store, the reverse proxy and this API's own container. Stopping a
  protected container MUST be refused with a documented "container protected" error that names it.
  Starting and restarting protected containers MUST be allowed, except that this API's own container
  MUST be neither stopped nor restarted through this API.
- **FR-016**: A container action MUST answer when its outcome is known, or after the
  container-service timeout, which is a server setting with a default of 60 seconds (legacy
  `DOCKER_TIMEOUT`). A successful response MUST contain the action, the outcome (done, or already in
  the requested state) and the container's resulting representation. When the container service
  reports a failure, the request MUST fail with a documented "container action failed" error that
  carries the service's message. A timeout MUST be reported as a temporary failure that says the
  outcome is unknown.
- **FR-017**: The contract MUST mark stop and restart as destructive, with the consequence that the
  container's service is interrupted, so that clients ask for confirmation and name the container.
  The API MUST NOT require a confirmation token.

#### Version, updates and public addresses

- **FR-018**: Administrators MUST be able to read the installed mailcow version as the legacy UI
  shows it: the version tag, the branch, and the commit identifier and commit date when they are
  known.
- **FR-019**: Administrators MUST be able to read the update status. A server setting MUST enable or
  disable the update check; it is enabled by default (upstream behaviour) and the FeldHost deployment
  disables it. Another setting MUST name the release source and the stable branch, by default
  mailcow's public releases and `master`. When the check is enabled and the installed branch is the
  stable branch, the system MUST compare the publication time of the newest release with that of the
  installed version's release and report one of:
  - up to date;
  - update available, with the newest version tag, its release page address, its publication time
    and its release notes;
  - check failed;
  - disabled, also for installations on another branch.

  The release notes of the installed version MUST be included when they are available. Results MUST
  be cached per installed version: successful checks for 1 hour and failed checks for 5 minutes. A
  disabled check MUST NOT make any outbound request.
- **FR-020**: The contract MUST state that release notes are externally authored Markdown that
  clients must render safely.
- **FR-021**: When the IP check of the legacy customisation settings is enabled, administrators MUST
  be able to request the server's public IPv4 and IPv6 addresses as mailcow's external check service
  sees them. Each address MUST be returned when the service answers with a valid address and reported
  as unavailable otherwise. Outbound requests MUST time out after 10 seconds without a connection or
  15 seconds in total. When the IP check is disabled, the request MUST be refused with a documented
  "IP check disabled" error and no outbound request MUST be made. Editing the setting belongs to
  feature 013.

#### Logs

- **FR-022**: Administrators MUST be able to read each log type below. Every entry MUST carry its time
  as an RFC 3339 UTC timestamp and the fields listed.

  | Log type | Content | Entry fields | Type-specific filters |
  |---|---|---|---|
  | Postfix | Mail server log | priority, program, message | priority, program |
  | Dovecot | IMAP/POP3 and delivery log | priority, program, message | priority, program |
  | SOGo | Groupware log | priority, program, message | priority |
  | Netfilter | Ban service log | priority, message | priority |
  | Autodiscover | Client auto-configuration requests | user agent, user, client address, service | service, user |
  | Watchdog | Health checks | service, health level in percent, health points now, total and change; or a message | service |
  | ACME | Certificate renewal | message | — |
  | Cron | Identity-provider sync tasks | task, priority, message | task, priority |
  | Rate limits | Messages that hit a rate limit | rate limit name, rate limit hash, sender, recipients, authenticated user, message ID, header From, subject, scanner queue ID, client address | rate limit name, sender, user |
  | Legacy API requests | Requests to `/api/v1` | URI, method, client address, request data | method, client address |
  | Legacy UI activity | Actions in the legacy UI and `/api/v1` | type, task, user, role, client address, message, call | type, user, role |
  | Logins | Mailbox logins per service | username, service, client address, app password used | username, service, client address |

- **FR-023**: Entries MUST be returned in a consistent form: numbers that legacy stores as text MUST
  be numbers, and a message stored with a `base64,` prefix MUST be returned decoded, or without the
  prefix when it does not decode. Entries with a priority MUST also carry its severity class as the
  legacy dashboard groups them: error (emerg, alert, crit, err), warning (warning, warn) or info
  (notice, info, debug).
- **FR-024**: Each log list MUST support pagination (default 25, maximum 1,000) and MUST be sorted by
  time, newest first by default, or oldest first. Ties MUST keep the order in which mailcow stored
  the entries. The total MUST count the matching retained entries.
- **FR-025**: Each log list MUST support a time range (from and to, inclusive), a case-insensitive
  substring search over every text field of the entry, a filter by severity class for types with a
  priority, and the type-specific filters of FR-022. Text filters MUST match case-insensitively as
  substrings, and several values of one filter MUST match entries with any of them.
- **FR-026**: The contract MUST document how a client loads older entries without duplicates while
  new entries arrive: by asking for entries older than the oldest one it holds.
- **FR-027**: The system MUST return only what mailcow retains: in-memory logs are trimmed to the
  configured length every minute (10,000 entries by default), the autodiscover log keeps 101 entries,
  the login log is cleaned daily by mailcow, and the legacy UI activity log is never trimmed. The
  system MUST NOT trim, delete or add legacy log entries.
- **FR-028**: In legacy UI activity entries and legacy API request entries, the value of every field
  whose name contains `pass`, `secret`, `private` or `token`, or whose name is `key`, MUST be replaced
  with `*`, wherever the field appears in the recorded call or request data, including inside nested
  structures. Searches and filters MUST operate on the masked entries, so that they cannot reveal a
  masked value. The contract MUST list these patterns.
- **FR-029**: A legacy UI activity entry MUST return its message as the message key and parameters
  legacy stored, and its call as a structured list, masked by FR-028.
- **FR-030**: Login entries MUST report the web UI service label configured in feature 001 (FR-027
  there): when it is `WEBUI`, entries stored as `MAILCOWUI` MUST be reported as `WEBUI`, as the
  FeldHost dashboard shows them.
- **FR-031**: Rate limit entries MUST carry the rate limit hash, so that a client can reset the limit
  with the operation of feature 006. Resetting it is not part of this feature.
- **FR-032**: Entries that cannot be parsed MUST be left out of the list and the total.

#### Rspamd history and statistics

- **FR-033**: Administrators MUST be able to list the scan history that Rspamd keeps (1,000 entries by
  default). Each entry MUST contain: the time, the client address, the envelope sender and the header
  sender, the envelope recipients and the header recipients, the subject, the action, the score and
  the required score, the symbols, the size in bytes, the real and virtual scan times in seconds,
  the message ID and the authenticated user, or none. Each symbol MUST carry its name, score and
  options. Symbols MUST be ordered as the legacy dashboard shows them: positive scores from largest
  to smallest, then negative scores from most negative, then zero scores.
- **FR-034**: The history list MUST support pagination (default 25, maximum 1,000), sorting by time
  (the default, newest first), score, size or scan time, filters by action, time range and score
  range, and a case-insensitive search over the senders, recipients, subject, client address,
  authenticated user and message ID.
- **FR-035**: Administrators MUST be able to read Rspamd's statistics: the numbers of scanned and
  learned messages, of spam and ham, the count per action as Rspamd names the actions, the numbers of
  connections and control connections, each statistics file (symbol, type, revision and users), the
  number of fuzzy hashes per storage, and the collection time. The contract MUST document each field.
  The action counts are returned as Rspamd reports them; the merged view legacy derives for its chart
  (`rspamd/actions`) belongs to feature 006.
- **FR-036**: When the Rspamd controller cannot be reached, history and statistics requests MUST fail
  with a temporary failure.

#### Mail queue

- **FR-037**: Administrators MUST be able to list the queued messages. Each message MUST contain: the
  queue ID, the queue (active, deferred, hold, incoming or maildrop), the arrival time, the size in
  bytes, the sender, each recipient with the reason delivery to it was deferred when there is one,
  and whether Postfix has marked the message for forced expiry, when it reports that. The list MUST
  contain at most the first 10,000 messages Postfix reports, as in legacy, and MUST say when Postfix
  reported more. Every listing MUST also report the number of messages in the active, deferred,
  hold and incoming queues and the total number of queued messages, counted over every message
  Postfix reported, independent of filters, paging and the 10,000 limit.
- **FR-038**: The queue list MUST support pagination (default 25, maximum 1,000), sorting by arrival
  time (the default, oldest first), size, sender, queue or queue ID with ties broken by queue ID, and
  filters by queue, arrival time range, sender, recipient, and a case-insensitive search over the
  queue ID, sender, recipients and deferral reasons.
- **FR-039**: Administrators MUST be able to view the complete content of one queued message as
  Postfix shows it, with its envelope records, headers and body. A queue ID that is not in the queue
  MUST be answered "not found".
- **FR-040**: Administrators MUST be able to apply one of these actions to between 1 and 1,000 queued
  messages in one request: deliver now, hold, release from hold, or delete. Each queue ID MUST consist
  of hexadecimal characters only, as legacy accepts. A request with more than 1,000 IDs or with an
  invalid ID MUST be refused with a validation error that states the limit or names each invalid ID,
  and nothing MUST change. Duplicate IDs MUST be processed once. IDs that are not in the queue when
  the request is processed MUST be reported as not in the queue and MUST NOT stop the others. The
  response MUST report one result per distinct ID: done or not in the queue.
- **FR-041**: Administrators MUST be able to flush the queue, which asks Postfix to attempt delivery
  of every queued message that is not on hold, and to delete all queued messages in every queue,
  including the hold queue.
- **FR-042**: Deliver now and flush MUST answer once Postfix has accepted the request; delivery
  continues in the background and shows in later listings, and the contract MUST say so. Hold,
  release and delete MUST complete before the response. When Postfix reports a failure, the request
  MUST fail with a documented "queue action failed" error that carries Postfix's message. Every queue
  action is bounded by the container-service timeout of FR-016, and a timeout MUST be reported as a
  temporary failure that says the outcome is unknown. The contract MUST mark delete and delete all as
  destructive and irreversible.
- **FR-043**: When the Postfix container is not running, every queue operation MUST be refused with a
  documented error that names the stopped container, and nothing MUST change.

#### Ban service

- **FR-044**: Administrators MUST be able to read the ban service settings:
  - the ban time and the maximum ban time, in seconds;
  - whether the ban time grows with each repeated ban;
  - the maximum number of failed attempts and the retry window in seconds within which they count;
  - the IPv4 and IPv6 prefix lengths a ban covers;
  - whether bans are enforced externally, in which case the ban service only maintains the ban list;
  - the allowlist and the denylist;
  - the ban list ID and the public ban list address derived from it;
  - the version tag (FR-057).

  When the ban service has never stored settings, the system MUST report its defaults: 1,800 and
  10,000 seconds, growing ban time, 10 attempts, 600 seconds, prefix lengths 32 and 128, and enforced
  by mailcow.
- **FR-045**: Administrators MUST be able to change the settings by sending only what changes.
  Omitted values MUST keep their current value. A list that is sent MUST replace the whole list. The
  ban list ID MUST NOT be editable, except by rotation (FR-055). The response MUST contain the updated
  settings and the new version tag.
- **FR-046**: The values MUST meet these rules, and a value outside them MUST be refused with a
  validation error that names the field and its limits, never corrected:
  - ban time: a whole number of seconds from 60 to 2,147,483,647;
  - maximum ban time: a whole number of seconds from 60 to 2,147,483,647, and not less than the ban
    time;
  - maximum attempts: a whole number from 1 to 2,147,483,647;
  - retry window: a whole number of seconds from 1 to 2,147,483,647;
  - IPv4 prefix length: from 8 to 32; IPv6 prefix length: from 8 to 128.

  A stored value outside these rules MUST NOT block an edit that does not change it.
- **FR-047**: Each allowlist entry MUST be an IPv4 or IPv6 address, a network in prefix notation, or a
  host name. Each denylist entry MUST be an address or a network in prefix notation, and MUST NOT be
  `0.0.0.0`, `0.0.0.0/0`, `::/0`, mailcow's internal IPv4 network or its network address, or mailcow's
  internal IPv6 network. Entries MUST be trimmed, and duplicates MUST be stored once. An entry on both
  lists MUST be refused. Any invalid entry MUST refuse the whole request with a validation error that
  names each invalid entry, and both lists MUST stay unchanged.
- **FR-048**: The contract MUST document when changes take effect, as the ban service applies them:
  the parameters at the next ban decision and at least every 10 seconds, the allowlist and denylist
  within 60 seconds, without a restart, with host names resolved each time.
- **FR-049**: Administrators MUST be able to read the ban rules: an ordered list of patterns that
  recognise failed logins in the logs, each with its number, and a version tag (FR-057).
- **FR-050**: Administrators MUST be able to replace the rules as a whole with 1 to 100 rules. Each rule
  MUST be between 1 and 1,000 characters long, MUST compile in the regular-expression dialect of
  mailcow's ban service, and MUST contain at least one capture group, whose first group captures the
  client address. The rules MUST be numbered from 1 in the order given. An invalid rule MUST refuse
  the request with a validation error that names it, and the stored rules MUST stay unchanged.
- **FR-051**: Administrators MUST be able to reset the rules to mailcow's defaults. The system MUST
  remove the stored rules, restart the ban service, and wait up to 10 seconds after the restart for
  the ban service to store its defaults. It MUST then return the restored rules. If they have not
  appeared in time, the request MUST fail with a documented error that says the defaults will be
  stored with the next log line the ban service receives. The contract MUST mark the reset as
  destructive, because it discards the custom rules and restarts the ban service.
- **FR-052**: Administrators MUST be able to list:
  - the active bans: the banned network, its first address, the time the ban ends, the remaining
    seconds, and whether the ban is queued for lifting;
  - the permanent bans, which the ban service applies for denylist entries: the network, its first
    address and the time the ban started.

  Both lists MUST support pagination (default 25, maximum 1,000), sorting by network or by time (the
  default, soonest ending or newest first), and a search on the network. The active bans MUST support
  a filter on whether the ban is queued for lifting.
- **FR-053**: Administrators MUST be able to act on an active ban, identified by its network:
  - lift it: queue it for lifting;
  - lift it and allowlist the network: add the network to the allowlist, remove it from the denylist
    and queue the ban for lifting;
  - denylist the network: add it to the denylist and remove it from the allowlist, with the denylist
    rules of FR-047.

  A network that is not actively banned MUST be answered "not found". The response MUST show the
  resulting ban and state that the ban service processes lifts within about 10 seconds.
- **FR-054**: A permanent ban MUST be lifted by removing its entry from the denylist (FR-045). The
  contract MUST say so.
- **FR-055**: Administrators MUST be able to rotate the ban list ID. The system MUST store a new random
  identifier, so that the old public ban list address stops working immediately, and MUST return the
  new identifier and address. The contract MUST mark the rotation as destructive, because external
  firewalls that use the old address stop receiving the ban list. The public ban list endpoint itself
  stays in the legacy stack.
- **FR-056**: When the ban service is not running, changes to settings, lists and rules MUST still be
  saved, lifts MUST stay queued, and the responses MUST say that the ban service is not running.

#### Version tags

- **FR-057**: The ban service settings and the ban rules MUST each carry a version tag that changes
  whenever their values change, whether the change was made through this API, the legacy UI,
  `/api/v1` or the ban service. It MUST NOT change when the ban service stores the same values again,
  nor when bans start or end. A client MAY send the version tag it read with a settings change, a
  rules replacement, a rules reset or a ban list ID rotation; when it no longer matches, the request
  MUST be refused as outdated and MUST change nothing. The contract MUST recommend that interactive
  clients always send it. Actions on an active ban MUST NOT require it, because each adds or removes
  one entry.
- **FR-058**: Containers, queued messages, log entries, bans and statistics MUST NOT carry version
  tags, because they are not edited as stored settings.

#### Reliability

- **FR-059**: Every response with status figures, resource usage, statistics or update status MUST
  carry the time its data was collected.
- **FR-060**: When the container service is unavailable, operations that need it MUST fail with a
  temporary failure. When the shared store or the database is unavailable, the logs, ban data and
  rules kept there MUST fail with a temporary failure.
- **FR-061**: A settings change MUST store the parameters and both lists together or not at all.
  (Legacy deletes both lists before writing them again, so a failure can leave them empty.)

#### Audit

- **FR-062**: Every request of this feature that changes state, or tries to, and every view of a
  queued message's content, MUST write exactly one audit record into feature 001's audit log, with
  the fields of FR-046 there and:
  - the target type (container, mail queue, queued message, ban settings, ban rules, active ban or
    ban list ID) and the target identifier;
  - the outcome, including refusals with their reason and "outcome unknown" after a timeout;
  - for a container action: the action and the state before and after;
  - for a queue action: the action and every queue ID with its result;
  - for a content view: the queue ID, never the content;
  - for a settings change: each changed parameter with its old and new value, and the list entries
    added and removed;
  - for a rules change or reset: the old and new rules;
  - for a ban action: the network and the list changes;
  - for a ban list ID rotation: the fact of the rotation, without either identifier.
- **FR-063**: Reads other than content views MUST NOT be audited. Administrators MUST be able to
  filter the audit log by these target types and identifiers, with the filters of feature 002 (FR-058
  there).
- **FR-064**: Container actions, queue actions and rule resets cannot be undone. The system MUST refuse
  them, without acting, when the audit log cannot be written before they start. When the record cannot
  be written after the operation was carried out, for example because the operation restarted the
  database, the system MUST retry until the container-service timeout, counted from the start of the
  request, has passed, and then report in the response that the operation was carried out without an
  audit record and record the failure in its operational log.

#### Cross-cutting

- **FR-065**: Errors MUST follow feature 001's conventions (FR-049 and FR-055 there). This feature MUST
  document at least these error types: container protected, container not running, container action
  failed, mail service not running, queue action failed, operation outcome unknown, ban rules not
  restored, IP check disabled, outdated version and temporary failure. Validation errors MUST use stable rule codes with their parameters, including
  the limits of FR-046, FR-050 and FR-040 and each invalid entry, rule or queue ID.
- **FR-066**: Every operation MUST be described in the published API contract before it is
  implemented, and its behaviour MUST match the contract.
- **FR-067**: Changes made through the legacy UI, `/api/v1` or mailcow's own services MUST be visible in
  the next response of this API, within the freshness limits of FR-006, FR-007, FR-010 and FR-011.
- **FR-068**: The system MUST NOT write to legacy logs. Its own operations appear in feature 001's audit
  log and in its operational log (constitution V).
- **FR-069**: Administrators MUST be able to read the monitoring capabilities, the server settings and
  limits a client needs before it polls, pages or acts:
  - whether the IP check of FR-021 is enabled;
  - the recommended polling interval and the maximum age of container states, host figures,
    container resource samples and mail storage usage (FR-006, FR-007, FR-010, FR-011, FR-012);
  - the configured retention of each log type of FR-022: the number of entries kept for in-memory
    logs, and whether a log is unbounded or cleaned by age (FR-027);
  - the caller's own client address as this API sees it, after mailcow's trusted proxies, so that
    clients can warn before a denylist entry covers it;
  - the bulk limit of FR-040, the rule limits of FR-050, the default and maximum page sizes, and the
    10,000-message listing limit of FR-037.

  The values MUST reflect the server's current configuration and MUST NOT include any secret or
  connection detail. The read MUST change nothing, MUST NOT need the container service, and
  read-only keys MAY use it.

### Legacy Coverage

Every legacy capability allocated to feature 011 in `docs/roadmap.md` maps to requirements as
follows. Actions are `/api/v1` names; *ajax* marks legacy UI endpoints under `inc/ajax/`, *page*
marks data a legacy page computes itself.

| Legacy action, form or page | Legacy implementation | Requirements |
|---|---|---|
| `get/status/host` | `docker('host_stats')` → container service `/host/stats` | FR-005, FR-006, FR-012, FR-059 |
| `get/status/vmail`, *page* dashboard disk usage | `docker('post', 'dovecot-mailcow', 'exec', system df /var/vmail)` | FR-007 |
| `get/status/containers`, *page* dashboard container cards | `docker('info')`, `admin/dashboard.php` (`SKIP_CLAMD`, `SKIP_OLEFY`) | FR-008 to FR-010 |
| `get/status/container/{id}` | `docker('container_stats')` → `/container/{id}/stats/update` | FR-011, FR-012 |
| *ajax* `container_ctrl.php` start, stop, restart; "Restart container" dialog, "Restart SOGo" menu entry, "Restart netfilter" button | `docker('post', <service>, start/stop/restart)`, `RestartContainer` modal | FR-013 to FR-017, FR-062, FR-064 |
| `get/status/version`, *page* dashboard and footer version | `MAILCOW_GIT_VERSION` and `app_info.inc.php` | FR-018 |
| *ajax* `update_check.php`, dashboard changelog dialog | GitHub releases, `MAILCOW_UPDATE_CHECK/<version>` cache | FR-019, FR-020 |
| `get/status/host/ip` | `ipv4.mailcow.email`, `ipv6.mailcow.email`; `ip_check` customisation | FR-021 |
| `get/logs/{postfix,dovecot,sogo,netfilter,autodiscover,watchdog,acme,cron,ratelimited,api}[/<n>|/<from>-<to>]` | `get_logs()` over `POSTFIX_MAILLOG`, `DOVECOT_MAILLOG`, `SOGO_LOG`, `NETFILTER_LOG`, `AUTODISCOVER_LOG`, `WATCHDOG_LOG`, `ACME_LOG`, `CRON_LOG`, `RL_LOG`, `API_LOG` | FR-022 to FR-028, FR-031, FR-032 |
| `get/logs/ui[/…]` | `get_logs('mailcow-ui')` over the `logs` table | FR-022 to FR-029 |
| `get/logs/sasl[/…]` | `get_logs('sasl')` over the `sasl_log` table | FR-022 to FR-027, FR-030 |
| `get/logs/rspamd-history[/…]`, dashboard Rspamd tab | Rspamd controller `/history` | FR-033, FR-034, FR-036 |
| `get/logs/rspamd-stats`, dashboard Rspamd chart | Rspamd controller `/stat` | FR-035, FR-036 |
| `get/mailq/all`, Queue Manager list | `mailq('get')` → `postqueue -j` | FR-037, FR-038, FR-043 |
| `get/postcat/{qid}`, "Show message" dialog | `mailq('cat')` → `postcat -q` | FR-039, FR-062 |
| `edit/mailq` deliver, hold, unhold | `mailq('edit')` → `postqueue -i`, `postsuper -h`, `postsuper -H` | FR-040, FR-042, FR-062 |
| `delete/mailq` | `mailq('delete')` → `postsuper -d` | FR-040, FR-042, FR-062 |
| `edit/mailq` flush, super_delete ("Flush queue", "Delete all") | `postqueue -f`, `postsuper -d ALL` | FR-041, FR-042, FR-062 |
| `get/fail2ban`, fail2ban section of System › Configuration | `fail2ban('get')` | FR-044, FR-049, FR-052 |
| `edit/fail2ban` (parameters, allowlist, denylist) | `fail2ban('edit')` | FR-045 to FR-048, FR-056, FR-057, FR-061 |
| `edit/fail2ban` action `edit-regex` | `F2B_REGEX` | FR-049, FR-050, FR-057 |
| `edit/fail2ban` action `reset-regex` | delete `F2B_REGEX`, restart `netfilter-mailcow` | FR-051, FR-064 |
| `edit/fail2ban` actions `unban`, `whitelist`, `blacklist` | `F2B_QUEUE_UNBAN`, `F2B_WHITELIST`, `F2B_BLACKLIST` | FR-053, FR-054; arbitrary networks through FR-045 |
| `edit/fail2ban/banlist` (refresh), ban list address | `fail2ban('banlist', 'refresh')`, `f2b-banlist.php` | FR-044, FR-055 |

Not carried over: the `logs` action of `container_ctrl.php`, which calls a container-service function
that does not exist. Parts of the legacy screens that belong to other features: the rate limit reset
button in the rate limit log and the `rspamd/actions` chart data (006), the license and GUID card that
upstream shows on the dashboard and the IP check switch (013).

### Legacy Parity and Deliberate Deviations

Parity reference: `source_code/data/web/inc/functions.docker.inc.php`, `functions.mailq.inc.php`,
`functions.fail2ban.inc.php`, `functions.rspamd.inc.php` (`rspamd_actions`), `functions.inc.php`
(`get_logs`, `valid_network`, `valid_hostname`, `logger`), `json_api.php` (`api_log`, `status`,
`logs`, `mailq`, `postcat`, `fail2ban` cases), `inc/ajax/container_ctrl.php`,
`inc/ajax/update_check.php`, `admin/dashboard.php`, `admin/queue.php`, `admin/system.php`,
`f2b-banlist.php`, `templates/dashboard.twig`, `templates/queue.twig`,
`templates/admin/tab-config-f2b.twig`, `templates/modals/footer.twig`, `js/site/dashboard.js`,
`js/site/queue.js`, `js/site/admin.js`, `js/build/013-mailcow.js`, `init_db.inc.php` (tables `logs`,
`sasl_log`), and outside the web root `Dockerfiles/dockerapi`, `Dockerfiles/netfilter/main.py`,
`Dockerfiles/dovecot/trim_logs.sh`, `Dockerfiles/phpfpm/docker-entrypoint.sh` (login log cleanup),
`conf/rspamd/local.d/history_redis.conf` and `docker-compose.yml` (`LOG_LINES`).

The reference is upstream mailcow-dockerized `2026-09a`. `source_code/` points to the FeldHost fork
(`~/Projects/mailhosting`, branch `feldhost-202609a`), so the files above were diffed against that
upstream tag. The container, queue, ban service, Rspamd and log logic is identical, including the
container service and the ban service. The fork differs in three ways:

1. The dashboard drops the link from the version to its GitHub changelog. The fork's branch is
   `feldhost` and its release source is a private GitLab, so the dashboard's update check, which runs
   only on the `master` branch, never runs (FR-019).
2. Legacy UI logins are recorded with the service `WEBUI` instead of `MAILCOWUI`, and the dashboard's
   login log shows older `MAILCOWUI` entries as `WEBUI` (FR-030, using feature 001's setting).
3. Branding only: the legacy UI activity log is titled "FeldHost™ Mailhosting UI", the logos and the
   log help text differ, and the license and GUID card is removed from the dashboard (013's surface).

| Behaviour | Legacy | This feature |
|---|---|---|
| Who may use it | Administrators only: `protect_route(['admin'])` and role checks in `get_logs`, `mailq`, `fail2ban`, `status/*` | Same (FR-001, FR-004) |
| Read-only API keys | `container_ctrl.php` is a GET endpoint without a key-scope check, so a read-only key can restart containers | Read-only keys refused on every action (FR-002). **Deviation** (security fix) |
| Empty or foreign results | `{}`, `[]` or nothing, with status 200 | "Not found" and documented errors (FR-003, FR-065) |
| Container list | All containers of `COMPOSE_PROJECT_NAME`, including stopped ones; antivirus and OLE scanner hidden when skipped; state, start time and image | Same, plus health, protected flag and available actions; addressed by service name (FR-008 to FR-010) |
| Container resource usage | By Docker ID, for any container on the host; up to 3 raw samples taken 5 s apart and kept 60 s; the browser computes rates; a stopped container blocks until the timeout | By service name, this installation only; counters and computed rates; a stopped container is refused at once (FR-011). **Deviation** |
| Container control | GET `container_ctrl.php?service=…&action=…` for any `*-mailcow` service; start and stop retry 4 times, 1.5 s apart; HTML reply; the UI offers restart only | Start, stop and restart as state-changing requests with the same retries and a structured outcome; protected containers cannot be stopped; this API's container cannot be stopped or restarted (FR-013 to FR-016). **Deviation** |
| Destructive actions | Browser confirmations (restart dialog, "Delete all" `confirm()`) | Contract marks them; the web app confirms (FR-017, FR-042) |
| Long-running actions | Synchronous, up to `DOCKER_TIMEOUT` (60 s) | Same, with timeouts reported as outcome unknown (FR-016, FR-042) |
| Host statistics | Container service measures the host and caches the figures for 10 s; local time as `dd.mm.yyyy hh:mm:ss` | Same figures and freshness; RFC 3339 UTC time plus time zone; shared collection (FR-005, FR-006) |
| Mail storage usage | `df -H` text fields (for example `25G`, `25%`); `0,0,0,0,0,0` on failure | Bytes, marked approximate when converted from rounded figures; unavailable instead of zeros (FR-007). **Deviation** |
| Public addresses | `status/host/ip` always asks the external service; only the dashboard button honours the `ip_check` setting | Refused without an outbound request when `ip_check` is off (FR-021). **Deviation** |
| Version | Version tag (branch, commit and build date for nightly and legacy builds in the footer) | Tag, branch, commit and date (FR-018) |
| Update check | Browser calls `update_check.php` on branch `master` only; GitHub releases compared by creation time; cached 1 h, failures 5 min; the changelog dialog is fetched by the browser from GitHub | Same comparison and caching, done by the API; a setting enables it and names the source (off in the fork); release notes included (FR-019, FR-020) |
| Log reading | `get/logs/<type>` returns `LOG_LINES` entries (1,000), `/<n>` the newest n, `/<from>-<to>` a range; the browser searches, sorts and pages | Pagination, filters, search and time sorting on the server (FR-024 to FR-026). **Deviation** |
| SQL log ranges | `LIMIT <from>-1, <to>` returns `to` rows instead of `to-from+1` | Exact pages (FR-024). **Deviation** (bug fix) |
| Encoded messages | Returned with the `base64,` prefix; the dashboard decodes them | Decoded (FR-023) |
| Secrets in logs | API request log masks only fields matching `pass`; the UI activity log masks passwords per function; DKIM private keys, client secrets and Pushover tokens appear in both | Masked by name patterns (FR-028). **Deviation** (security fix) |
| Login log service label | Stored `MAILCOWUI` upstream, `WEBUI` in the fork; the fork's dashboard shows `MAILCOWUI` as `WEBUI` | Feature 001's setting decides (FR-030) |
| Rspamd history | `/history` rows as Rspamd returns them; the dashboard sorts symbols and formats scores | Structured entries, the dashboard's symbol order, filters and sorting (FR-033, FR-034) |
| Rspamd statistics | `/stat` document as Rspamd returns it | Documented subset (FR-035). **Deviation**: fields not listed are not passed through |
| Queue list | `postqueue -j`, first 10,000 lines; recipients flattened to `address (reason)` | Structured recipients, pagination, filters and a truncation indicator (FR-037, FR-038) |
| Queued message content | `postcat -q`; each view logged as `queue_cat_success` | Same content; each view audited (FR-039, FR-062) |
| Queue actions | One request for all selected IDs; non-hexadecimal IDs silently dropped; overall success only; no limit | Up to 1,000 IDs; invalid IDs refused; one result per ID (FR-040). **Deviation** |
| Flush and delete all | `postqueue -f`, `postsuper -d ALL` | Same (FR-041, FR-042) |
| Ban settings read | `F2B_OPTIONS`, lists as newline-separated text, remaining ban time as `hh mm ss` text | Structured values, lists as sets, times as timestamps and seconds; ban service defaults when unset (FR-044, FR-052) |
| Ban settings edit | Values below the minimum raised silently; the IPv4 and IPv6 lower bounds never apply; "enforce externally" reset to off when omitted; invalid list entries skipped and the rest saved; lists deleted and rewritten | Out-of-range values and invalid entries refused; omitted values kept; stored together (FR-045 to FR-047, FR-061). **Deviation** |
| Maximum ban time below ban time | Accepted; the ban service then uses the ban time | Refused (FR-046). **Deviation** |
| Denylist protection | `0.0.0.0`, `0.0.0.0/0`, mailcow's internal IPv4 network and its address, and the internal IPv6 network refused | Same, plus `::/0` (FR-047). **Deviation** |
| Ban rules | Any strings, renumbered from 1; an empty list is ignored but reported as saved; a rule without a capture group stops the ban service when it matches | Validated, 1 to 100 rules of up to 1,000 characters; empty list refused (FR-050). **Deviation** |
| Ban rule reset | Delete the rules, restart the ban service, poll 10 times 1 s apart | Same (FR-051) |
| Quick ban actions | `unban`, `whitelist`, `blacklist` accepted for any valid network; unban not checked against the active bans; the UI says a denylist change "needs restart" | On active bans only, unknown networks "not found"; other networks through the settings; documented timing without a restart (FR-048, FR-053). **Deviation** |
| Ban list ID | Refreshed with `edit/fail2ban/banlist` when the request names the current ID; served by `f2b-banlist.php` | Rotation with an optional version tag; the endpoint stays in the legacy stack (FR-055, FR-057) |
| Concurrent edits | Last write wins | Version tags that ignore the ban service's rewrites (FR-057). **Deviation** |
| UI log entries | Written to the `logs` table | Written to this API's audit log (FR-062, FR-068), as in feature 001. **Deviation** |

### Key Entities *(include if feature involves data)*

- **Container** (read from mailcow's container service): a service container of this mailcow
  installation, identified by its service name, with its image, state, health, start time, protected
  flag and available actions.
- **Container resource sample**: processor, memory, disk and network counters of a running container
  at one time, and the rates derived from two samples.
- **Host statistics**: processor, memory, swap, uptime, time and architecture of the host at one time.
- **Mail storage usage**: size, use and free space of the disk holding the mail storage.
- **Installed version and update status**: the installed version tag, branch and commit, and the
  cached result of the last update check.
- **Public addresses**: the IPv4 and IPv6 addresses an external service sees.
- **Monitoring capabilities**: the IP check switch, polling interval and freshness limits, log
  retention, the caller's client address and the limits a client needs (FR-069).
- **Log entry** (existing mailcow data): one entry of one log type, with its time and type-specific
  fields. In-memory logs live in mailcow's shared store; the legacy UI activity and login logs live
  in the database.
- **Rspamd history entry and Rspamd statistics** (read from the Rspamd controller).
- **Queued message** (read from Postfix): queue ID, queue, arrival time, size, sender, recipients with
  deferral reasons and content.
- **Queue action result**: the outcome for one queue ID of one queue action.
- **Ban service settings** (existing mailcow data in the shared store): the ban parameters, the
  allowlist, the denylist and the ban list ID.
- **Ban rules** (existing mailcow data in the shared store): the numbered patterns that recognise
  failed logins.
- **Active ban and permanent ban** (maintained by mailcow's ban service): a banned network with its end
  or start time and its lifting state.
- **Audit record** (owned by this API, defined in feature 001): extended with the target types of this
  feature, container states, queue action results and settings changes.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For 100% of the parity test set, the container states, host figures, log entries, queued
  messages, bans and Rspamd data returned by the API equal what the legacy UI shows for the same data,
  apart from the documented unit, time and masking conversions.
- **SC-002**: An administrator can find why a message is stuck (filter the deferred queue by recipient
  and read the deferral reason) in under 1 minute, and restart a container in at most two interactions
  after opening its card.
- **SC-003**: In 95% of requests, the system overview, the container list and a page of 100 log
  entries out of 10,000 retained entries are returned within 1 second while mailcow's services answer
  normally.
- **SC-004**: With 10 administrators polling the dashboard at the recommended interval, the host is
  measured no more often than with one administrator, and 100% of returned host figures are at most
  10 seconds old.
- **SC-005**: 100% of requests by domain administrators, mailbox users and keys owned by domain
  administrators are refused, and 100% of state-changing requests with read-only keys are refused.
- **SC-006**: Every request in FR-062 produces exactly one audit record. A scan of every log response
  in the masking test set finds no password, private key, client secret or token.
- **SC-007**: A lifted ban leaves the active bans within 15 seconds, and a denylist entry is enforced
  within 70 seconds, in 100% of trials with a running ban service.
- **SC-008**: Over a test run that saves every accepted combination of settings and rules, the
  unmodified mailcow ban service never stops or restarts, and 100% of rule sets that would stop it are
  refused.
- **SC-009**: 100% of container and queue actions answer within the container-service timeout plus
  5 seconds, either with their outcome or with an "outcome unknown" failure.
- **SC-010**: 100% of refused settings, rule and queue requests name every invalid field, entry, rule
  or queue ID with a stable rule code, so that the web app can show each error in place.

## Assumptions

- The API can reach mailcow's container service, shared store, database and Rspamd controller, and it
  can read the installation's configuration (host name, time zone, log length, disabled optional
  containers, internal networks) and its version information. The plan decides how.
- "This mailcow installation" means the containers of the configured project name, as in legacy. The
  API's own container is added to that project by the operator (constitution V), and the plan
  identifies it.
- Postfix uses its default short, hexadecimal queue IDs in mailcow, which is what legacy accepts.
- The public ban list endpoint (`f2b-banlist.php`), the ban service, the container service and the
  log producers stay unchanged. This feature only reads and writes the data they share.
- The new web app shows this API's own audit log (feature 001) next to the legacy UI activity log,
  because actions made through this API never appear in the legacy logs.
- The ban service default values and default rules belong to mailcow and can change between releases;
  the system reads them from the ban service instead of copying them, except for the documented
  defaults it reports when nothing is stored (FR-044).
- These defaults can be changed by the operator: the container-service timeout (60 seconds), the
  protected containers, the update check (on), the release source and stable branch (mailcow's public
  releases, `master`) and the list page size (25, maximum 1,000).
- **Out of scope**:
  - container log streams from the container runtime;
  - resetting rate limits from the rate limit log (006) and the merged Rspamd action chart data (006);
  - editing the IP check switch and the license or GUID verification (013);
  - quarantine (006);
  - other container-service tasks used by other features (maildir moves, sieve, doveadm ACLs, SOGo
    renames, Rspamd worker password);
  - push updates or streaming;
  - installing updates;
  - taking over the public ban list endpoint (feature 014, if ever).
- **Dependencies**:
  - mailcow's container service, for container states, control, resource usage, mail storage usage
    and every queue operation;
  - mailcow's shared store, for in-memory logs, ban settings, rules and bans;
  - the upstream mailcow database schema of the supported release, for the legacy UI activity and
    login logs;
  - the Rspamd controller;
  - mailcow's ban service, which applies settings, lists, rules and lifts;
  - the release source and mailcow's external IP check service, when enabled;
  - feature 001, for identity, roles, API keys, errors, the web UI service label and the audit log;
    feature 002, for the audit log target filters.
