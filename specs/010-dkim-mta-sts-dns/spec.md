# Feature Specification: DKIM, MTA-STS and DNS Diagnostics

**Feature Branch**: `main` (no feature branches, constitution 1.1.0)

**Created**: 2026-10-18

**Status**: Draft

**Input**: Feature description built from `docs/roadmap.md` (feature 010 and allocation row 010):

> DKIM, MTA-STS and DNS diagnostics for mailcow REST API v2. Administrators see every DKIM signing
> key of an existing mailcow-dockerized server: the keys of domains and alias domains, the keys
> left without a domain, and the domains that have no key. They generate, import, copy and delete
> keys, and read the DNS record each key needs. Private keys are secrets: they are exported only
> as legacy's `SHOW_DKIM_PRIV_KEYS` setting allows. In the FeldHost fork, key changes are also
> published to an ISPConfig DNS server; this stays available as a server setting, off by default.
> Administrators and domain administrators manage the MTA-STS policy of a domain (version, mode,
> MX hosts, maximum age, active state). They run DNS diagnostics for a domain or alias domain,
> which looks up every record mailcow needs (MX, host addresses and reverse names, SPF, DKIM,
> DMARC, TLSA, MTA-STS, autodiscover and service records), compares it with the expected value,
> and offers a zone file. Domain administrators reach only the domains they manage. Mailbox users
> have no access.
>
> Allocated legacy surface: `dkim`, `dkim_duplicate`, `dkim_import` (add/get/delete), the
> ISPConfig DKIM DNS push (fork-only), `mta-sts` (add/edit, and the read on the Edit domain page),
> and `ajax/dns_diagnostics.php`. The legacy "System › ARC/DKIM keys" screen, the MTA-STS tab and
> DKIM record of the "Edit domain" screen, and the DNS button of the domain and alias domain lists
> need what they show today.
>
> Constraints: every legacy side effect is reproduced, so Rspamd signs, the MTA-STS endpoint
> publishes and the certificate service requests certificates exactly as before, whichever
> implementation made the change. The legacy UI and `/api/v1` keep working on the same data. DNS
> diagnostics makes outbound lookups and needs time limits and clear "unable to resolve" states.
> The feature builds on feature 001's identity, roles, API keys, error conventions and audit log,
> and on feature 002's domains, which already trigger DKIM key generation when a domain is created.

## Clarifications

### Session 2026-10-18

- Q: Who may obtain a DKIM private key, and how? → A: Only administrators, through a separate export
  operation that is available only while the operator setting mirroring legacy's
  `SHOW_DKIM_PRIV_KEYS` is on (off by default). An export is a read, so an administrator's API key
  of either access level may export. Listings, inspections and every other response never contain
  private keys. Every export is audited. Domain administrators can never export (FR-026, FR-027).
  (agent decision: legacy follows the setting, but its `get/dkim/{domain}` also returns the private
  key to domain administrators of the domain when the setting is on. A private key lets its holder
  sign mail for the domain, so constitution VI and least privilege restrict it to administrators.)
- Q: May domain administrators generate DKIM keys? → A: Yes, for domains and alias domains in their
  scope that have no key yet, as the legacy API allows. Importing, copying, deleting, exporting,
  republishing and seeing keys without a domain stay administrator-only (FR-005, FR-013). (agent
  decision: legacy parity. `dkim('add')` checks only domain access, while import, duplicate and
  delete check the administrator role. A domain administrator cannot overwrite or remove a key, so
  the permission cannot break signing.)
- Q: With the FeldHost DKIM DNS publication enabled, which key changes update the DNS zone? → A:
  Every change of the stored key of a name: generation, import, copy to a target and deletion. When
  the selector changes, the old selector's record is removed and the new one published. The outcome
  is reported in the response and the audit record, a failure never undoes the key change, and
  administrators can ask for a record to be published again (FR-028 to FR-031). (agent decision: the
  fork pushes only on generation and deletion. Its import deletes the existing record through the
  internal delete and never publishes the new one, and a copy publishes nothing, so the zone drifts
  from the signing key and receivers fail DKIM checks. The fork also ignores every failure silently.
  Reporting outcomes follows feature 002's creation report.)
- Q: What happens when an import or a copy targets a name that already has a key? → A: The request
  is refused as a conflict unless it explicitly asks to overwrite. An overwrite removes the whole
  old key, including the private key stored under the old selector. A copy may target only an
  existing domain or alias domain, while an import may target any valid domain name, as in legacy
  (FR-020, FR-022). (agent decision: legacy import has an "overwrite existing key" flag. Legacy
  duplicate overwrites silently, leaves the old selector's private key behind, and accepts any text
  as a target, although its form offers only domains and alias domains.)
- Q: How does DNS diagnostics report its results? → A: As structured data, never HTML. Each record
  check has a status (match, mismatch, missing, lookup failed, informational, expected value
  unknown), the expected value or guidance, every value found, an "optional" flag and a reason where
  one applies. The result also carries a zone-file rendering (FR-043 to FR-047). (agent decision:
  the mailcowui-ng constitution forbids rendering API data as raw HTML. Legacy marks a missing
  record, and a DKIM record with the wrong key, with the "optional" footnote, so a missing MX record
  looks optional. These are display defects that statuses fix.)
- Q: What time limits and protections apply to DNS diagnostics? → A: Each lookup may take at most 5
  seconds and a whole run at most 30 seconds. A check that cannot complete in time reports "lookup
  failed" and the run still returns the other results. Each caller may start at most 10 runs per
  minute. The server-wide facts (public addresses, certificate digest) are reused for up to 10
  minutes (FR-048, FR-049). (agent decision: legacy sets only a 3-second connect timeout for address
  detection and 5 seconds for the certificate fetch, and leaves DNS to the system default with no
  overall limit. Each run makes about 30 outbound lookups and a connection to port 25, so OWASP API4
  calls for a bound.)
- Q: Where do the server's public addresses come from? → A: From the same address echo service
  legacy queries, asked separately over IPv4 and IPv6, unless the operator configures the addresses
  explicitly. An address that cannot be determined makes the checks that depend on it report
  "expected value unknown" (FR-042). (agent decision: legacy parity, with an operator override so
  that installations that do not want to contact the external service can avoid it.)
- Q: Can an alias domain have its own MTA-STS policy? → A: No. Policies belong to domains. An alias
  domain publishes its target domain's policy, as legacy's publication endpoint and certificate
  service already do. A policy operation addressed with an alias domain's name is answered "not
  found" (FR-032). (agent decision: legacy `add/mta-sts` accepts an alias domain's name, but such a
  row is never published or used by the certificate service, and it stays behind when the alias
  domain is deleted. Feature 002 also answers alias domain names "not found" for domain operations.)
- Q: Can an MTA-STS policy be deleted, and which values apply when a creation omits them? → A: A
  policy cannot be deleted through this API, as in legacy: it is deactivated instead, and it is
  removed together with its domain (feature 002). The version defaults to STSv1, the only valid
  value. A policy created without an active state is inactive, as in legacy. The maximum age is
  limited to 31,557,600 seconds both at creation and when changed (FR-033, FR-037). (agent decision:
  legacy has no delete. It limits the maximum age to 31,536,000 seconds at creation but to
  31,557,600 when edited; RFC 8461 allows 31,557,600, so the higher limit is used for both.)
- Q: The FeldHost fork's DNS diagnostics suggests the SPF record `v=spf1 mx a ~all` and the
  addresses to allow, while upstream only links to the SPF syntax. Which applies? → A: The result
  always carries the server's public addresses. A suggested SPF record is a server setting, empty by
  default; the FeldHost deployment sets `v=spf1 mx a ~all` (FR-050). (agent decision: fork-only
  behaviour is kept but configurable, per the roadmap.)

## User Scenarios & Testing *(mandatory)*

### User Story 1 - See DKIM keys and the DNS records they need (Priority: P1)

An administrator opens the DKIM screen of the new web app. As on the legacy "System › ARC/DKIM keys"
screen, it lists every domain and alias domain with its key state:

- **valid**: a key exists, with its selector, its length in bits and the DNS TXT record to publish;
- **missing**: the domain or alias domain has no key, so its outgoing mail is not signed;
- **unused**: a key exists for a name that is no longer a domain or alias domain, for example after
  a domain was deleted.

The administrator filters by state, searches by name and sorts the list. Opening an entry shows the
record name (`<selector>._domainkey.<domain>`) and the record value, both as one text and split into
pieces of at most 255 characters for DNS providers that need them. A domain administrator sees the
same information for the domains they manage and their alias domains, as on the legacy "Edit domain"
screen, but never keys without a domain.

**Why this priority**: Publishing the right DKIM record is the most frequent DKIM task, and every
other DKIM task starts from this list. It changes nothing, so it is the safest first slice.

**Independent Test**: Load a test copy of the mailcow data with domains and alias domains with and
without keys, keys of every size, keys without a domain and a key with a missing private part. List
and inspect them as an administrator and as a domain administrator with every filter, and compare
each value with the legacy screens.

**Acceptance Scenarios**:

1. **Given** 3 domains with keys, 1 domain without a key, 1 alias domain with a key and 1 key whose
   domain was deleted, **When** an administrator lists DKIM entries, **Then** 6 entries are
   returned: 4 valid, 1 missing and 1 unused, sorted by name.
2. **Given** a domain with a 2,048-bit key and the selector `dkim`, **When** it is inspected,
   **Then** the response shows the selector, 2048 bits, the record name `dkim._domainkey.<domain>`
   and the value `v=DKIM1;k=rsa;t=s;s=email;p=<public key>`, as the legacy screen shows it.
3. **Given** a domain administrator who manages one domain with one alias domain, **When** they list
   DKIM entries, **Then** exactly those 2 entries are returned and no unused key appears.
4. **Given** a domain administrator, **When** they inspect a domain they do not manage, or the name
   of an unused key, **Then** the response is the same "not found" as for a name that does not
   exist.
5. **Given** any caller, **When** they list or inspect DKIM entries, **Then** no response contains a
   private key.
6. **Given** a mailbox user, **When** they request DKIM entries, **Then** the request is refused as
   forbidden.

---

### User Story 2 - Generate a DKIM key (Priority: P1)

An administrator selects the domains that the list shows as "missing" and generates a key for each,
choosing a selector (pre-filled with the server's default selector) and a key size. Signing starts
with the next outgoing message, and the response shows the record to publish. A domain administrator
can do the same for a domain or alias domain they manage that has no key. Domain and alias domain
creation use the same generation.

**Why this priority**: A domain without a key sends unsigned mail, which receivers increasingly
reject. Generating keys for domains added without one, for example by the legacy UI with an empty
selector, is a routine task.

**Independent Test**: Generate keys through the API for domains and alias domains with each key size
and with default and custom selectors. Compare the stored entries with those the legacy UI produces,
send a message from each domain and confirm it carries a valid signature once the record is
published.

**Acceptance Scenarios**:

1. **Given** a domain without a key, **When** an administrator generates a key without naming a
   selector or size, **Then** a 2,048-bit key is stored with the server's default selector, the
   response is "created" and contains the entry and its record, and the next message sent from the
   domain is signed with it.
2. **Given** a domain that already has a key, **When** a key is generated for it, **Then** the
   request is refused as a conflict and the existing key is unchanged.
3. **Given** a selector containing a space or a slash, or a key size of 1536, **When** generation is
   requested, **Then** it is refused with a validation error naming the field and its rule.
4. **Given** a name that is not a domain or alias domain, **When** an administrator generates a key
   for it, **Then** the response is "not found".
5. **Given** a domain administrator, **When** they generate a key for an alias domain of a domain
   they manage, **Then** the key is created. **When** they generate one for another administrator's
   domain, **Then** the response is "not found".
6. **Given** a read-only API key owned by an administrator, **When** it is used to generate a key,
   **Then** the request is refused with the "insufficient key scope" error.

---

### User Story 3 - Check a domain's DNS setup (Priority: P1)

An administrator or domain administrator presses "DNS" next to a domain or alias domain. The API
looks up every record the domain needs, compares each with the expected value and tells them, record
by record, whether it matches, differs, is missing or could not be resolved. Records whose content
is up to the operator, such as SPF and DMARC, are shown as optional with what was found.
Administrators also see the server-wide records: the host name's addresses, their reverse names and
the TLSA record for SMTP. The result includes a zone file that the user can download and import at
their DNS provider.

**Why this priority**: Wrong DNS is the most common reason why mail from a new domain is rejected or
why mail clients cannot find the server. Domain administrators depend on this check to set up their
own domains.

**Independent Test**: Point the API at a test DNS zone with correct, wrong, missing and unresolvable
records, and with an unreachable name server. Run diagnostics as an administrator and as a domain
administrator for a domain and for an alias domain, and compare each status, expected value and
found value with the zone contents. Load the zone file into a standard zone-file checker.

**Acceptance Scenarios**:

1. **Given** a domain whose MX record points to the server's host name, **When** diagnostics runs,
   **Then** the MX check reports "match".
2. **Given** a domain without any MX record, **When** diagnostics runs, **Then** the MX check
   reports "missing", not "optional".
3. **Given** a domain whose DKIM record contains a different public key than the stored key,
   **When** diagnostics runs, **Then** the DKIM check reports "mismatch" and lists the value found.
4. **Given** a domain whose authoritative name servers do not answer, **When** diagnostics runs,
   **Then** the result arrives within 30 seconds and the affected checks report "lookup failed".
5. **Given** a domain administrator, **When** they run diagnostics for a domain they manage,
   **Then** the result contains the domain's checks and none of the server-wide checks. **When**
   they run it for a domain they do not manage, **Then** the response is "not found".
6. **Given** an alias domain whose target domain has an active MTA-STS policy, **When** diagnostics
   runs for the alias domain, **Then** the MTA-STS checks expect the target domain's policy
   identifier.
7. **Given** any diagnostics result, **When** its zone file is loaded into a standard zone-file
   checker, **Then** it is accepted.
8. **Given** a caller who started 10 runs within the last minute, **When** they start another,
   **Then** it is refused as "too many requests" with a hint on when to retry.

---

### User Story 4 - Manage a domain's MTA-STS policy (Priority: P2)

An administrator or domain administrator opens the MTA-STS settings of a domain. They choose the
mode (enforce, testing or none), list the MX host names that sending servers may deliver to, set the
maximum age for which senders may cache the policy, and activate it. Once active, the policy is
published for the domain and its alias domains, and mailcow's certificate service requests a
certificate for the `mta-sts.` host name at its next run. The screen shows the policy identifier and
the DNS records to publish, and the identifier changes whenever the policy's content changes, so
that senders fetch the new policy.

**Why this priority**: MTA-STS protects inbound mail against downgrade attacks, but only some
operators use it, and the rest of the feature works without it.

**Independent Test**: Create, change, activate and deactivate policies through the API as an
administrator and as a domain administrator. After each step, compare the stored policy and the text
the legacy publication endpoint serves with the result of the same steps in the legacy UI.

**Acceptance Scenarios**:

1. **Given** a domain without a policy, **When** a client creates one with mode "enforce", the MX
   host `mail.example.org`, a maximum age of 86,400 seconds and active, **Then** the policy is
   stored with version STSv1 and a new identifier, the response is "created", and the legacy
   endpoint serves the policy for the domain and for its alias domains.
2. **Given** an existing policy, **When** a client changes only its mode, **Then** the identifier
   changes. **When** a client changes only its active state, **Then** the identifier stays the same.
3. **Given** a policy request with a maximum age of 0, an empty MX list, an MX entry `mail..example`
   or the mode "strict", **When** it is submitted, **Then** it is refused with a validation error
   that names the field and its rule.
4. **Given** a domain that already has a policy, **When** a client creates another, **Then** the
   request is refused as a conflict.
5. **Given** two clients that read the same policy, **When** the second saves with an outdated
   version tag, **Then** its change is refused as outdated.
6. **Given** an alias domain name, **When** a client reads or creates a policy for it, **Then** the
   response is "not found".
7. **Given** a domain administrator, **When** they change the policy of a domain they manage,
   **Then** the change is saved, with no permission flag needed, as in legacy.

---

### User Story 5 - Import, copy and delete DKIM keys (Priority: P2)

An administrator migrating domains from another mail server imports each domain's existing private
key, so that the DNS records already published keep working; when a key already exists, they choose
to overwrite it. An administrator who wants several domains to share one key copies it from one
domain to others. An administrator cleaning up deletes keys left without a domain, or deletes a key
before generating a new one.

**Why this priority**: These tasks are less frequent than generating keys, and the legacy UI can
still do them.

**Independent Test**: Import valid and invalid private keys with and without overwrite, copy keys to
domains with and without keys, and delete keys of domains and unused keys. Compare the stored
entries with the legacy results and send signed messages after each change.

**Acceptance Scenarios**:

1. **Given** a domain without a key and a valid unencrypted 2,048-bit RSA private key, **When** an
   administrator imports it with the selector `mx2024`, **Then** the key is stored with that
   selector, its public key is derived from the private key, and the next message from the domain is
   signed with it.
2. **Given** a domain with a key, **When** an administrator imports a key without asking to
   overwrite, **Then** the request is refused as a conflict. **When** they ask to overwrite,
   **Then** the old key is removed completely, including its private key under the old selector, and
   the new key is stored.
3. **Given** text that is not a private key, an encrypted private key, an elliptic-curve key or a
   512-bit RSA key, **When** it is imported, **Then** it is refused with a validation error whose
   rule names the problem, and the key text appears in no response, log or audit record.
4. **Given** a domain A with a key and a domain B without one, **When** an administrator copies A's
   key to B, **Then** B has the same selector, public key and private key as A.
5. **Given** an unused key, **When** an administrator deletes it, **Then** it disappears from the
   list and the response has no body. **Given** a domain's key, **When** it is deleted, **Then** the
   entry shows "missing" and the domain's mail is no longer signed.
6. **Given** a domain administrator, **When** they try to import, copy or delete a key, **Then** the
   request is refused as forbidden.

---

### User Story 6 - Export a private key when the operator allows it (Priority: P3)

An administrator moving a domain to another server needs its DKIM private key. The operator has
enabled private key export, as legacy's `SHOW_DKIM_PRIV_KEYS` setting does. The administrator
exports the key of one name and receives the private key. The export is recorded in the audit log.
On servers where the operator has not enabled export, the request is refused.

**Why this priority**: It is rarely needed, it is off by default, and it exposes a secret.

**Independent Test**: With export disabled and enabled, export keys as an administrator, as a domain
administrator and with API keys, and check each outcome and audit record.

**Acceptance Scenarios**:

1. **Given** export enabled, **When** an administrator exports a domain's private key, **Then** the
   response contains the private key exactly as stored, and the audit log records the export without
   the key.
2. **Given** export disabled, the default, **When** an administrator exports a key, **Then** the
   request is refused with the documented "private key export disabled" error.
3. **Given** export enabled, **When** a domain administrator exports the key of a domain they
   manage, **Then** the request is refused as forbidden.

---

### User Story 7 - Keep the ISPConfig DNS zone in sync with DKIM keys (Priority: P3)

On the FeldHost deployment, the operator has enabled the DKIM DNS publication and connected it to
the ISPConfig server that hosts the customers' DNS zones. Whenever a key is generated, imported,
copied or deleted, the matching TXT record in the domain's ISPConfig zone is created, updated or
removed. The response says whether the record was published, whether no zone was found for the
domain, or whether the publication failed. After a failure, for example while ISPConfig was
unreachable, the administrator asks for the record to be published again.

**Why this priority**: It is fork-only, off by default, and DKIM works without it.

**Independent Test**: With a fake ISPConfig server, enable and disable the publication. Generate,
import with a different selector, copy and delete keys for domains with and without a zone, make the
server fail or time out, and check the zone contents and each reported outcome.

**Acceptance Scenarios**:

1. **Given** the publication enabled and an ISPConfig zone for the domain, **When** a key is
   generated, **Then** a TXT record `<selector>._domainkey` with the key's record value is created
   in the zone and the response reports "published".
2. **Given** a zone that already holds the record, **When** the key is replaced by an import with
   the same selector, **Then** the existing record is updated, not duplicated. **When** the import
   uses a different selector, **Then** the old selector's record is removed and the new one
   published.
3. **Given** no ISPConfig zone for the domain, **When** a key is generated, **Then** the key is
   stored and the response reports "no DNS zone found".
4. **Given** an unreachable ISPConfig server, **When** a key is generated, **Then** the key is
   stored and the response reports "failed" within 15 seconds. **When** a key is deleted, **Then**
   the key is deleted within 15 seconds and the audit record names the failed removal.
5. **Given** a failed publication, **When** an administrator asks for the record to be published
   again, **Then** the record is published and the outcome is "published".
6. **Given** the publication disabled, the default, **When** a key is generated, **Then** ISPConfig
   is never contacted and the outcome is "not configured".

---

### User Story 8 - Trace DKIM and MTA-STS changes (Priority: P3)

An administrator investigating why a domain's mail fails DKIM checks lists the audit log filtered by
the domain and sees every key generation, import, copy, deletion, export and publication outcome,
and every MTA-STS change: who made it, when, from where and what changed.

**Why this priority**: The constitution requires an audit record for every change, but keys and
policies can be managed without reading the log.

**Independent Test**: Make each kind of change, including refused ones, and list the audit log
filtered by target.

**Acceptance Scenarios**:

1. **Given** an administrator who imported a key with overwrite, **When** another administrator
   lists audit records for that name, **Then** the entry shows the actor, the time, the client
   address, the old and new selector and key length, and the publication outcome, and contains no
   private key.
2. **Given** a domain administrator who changed a policy's mode, **When** an administrator lists
   audit records for that domain, **Then** the entry shows the old and new mode and the old and new
   policy identifier.

---

### Edge Cases

- **Names written in another form**: names are compared and stored in lower-case ASCII (punycode)
  form (feature 002, FR-006). A key that legacy stored under an upper-case or Unicode name, which
  the legacy DKIM form allows, is never used for signing. It is listed as an unused key under its
  stored name and can be deleted.
- **Incomplete key**: a name whose public key, selector or private key is missing, for example after
  a partial legacy write, is listed with its state and marked incomplete. It may not sign. It can be
  deleted or replaced by an import with overwrite. Generation refuses it as an existing key.
- **Private key left behind by a legacy copy** under a selector that no name points to: it is not
  listed and not used for signing.
- **Domain or alias domain deleted**: its key stays (feature 002, FR-038) and is listed as unused. A
  domain created again with the same name reuses it.
- **Inactive domain**: keys can still be generated, and diagnostics still runs.
- **Copy to the source name itself**: refused with a validation error.
- **Import of a public key, a certificate, an encrypted key, a non-RSA key, or an RSA key smaller
  than 1,024 or larger than 8,192 bits**: refused with a validation error.
- **Private key text with Windows line endings**: normalised before it is stored, as in legacy.
- **Deleting the key of an existing domain**: signing stops with the next message. Unless the DKIM
  DNS publication is enabled, the published record stays until the operator removes it, and the
  contract says so.
- **DKIM DNS publication enabled, but no zone for the name** on the ISPConfig server: nothing is
  published and the outcome says so. (The fork silently skips.) A name that lies inside a parent
  zone, such as `mail.example.org` in the zone `example.org`, also counts as having no zone, as in
  the fork.
- **ISPConfig unreachable or slow**: the key change succeeds and the outcome is "failed" after at
  most 15 seconds. After a generation, import or copy, the record can be published again later.
  After a deletion, the audit record names the failed removal, and the operator removes the record
  in ISPConfig.
- **Concurrent key changes for one name**: exactly one generation or import without overwrite
  succeeds, the others are refused as conflicts.
- **MTA-STS policy changed twice within one second**: the second change still gets a new identifier.
- **MTA-STS change that changes nothing**: accepted. The identifier and version tag stay the same.
- **MTA-STS MX list that would exceed the 255 characters mailcow can store**: refused with a
  validation error that reports the limit.
- **MTA-STS policy activated**: the certificate for `mta-sts.<domain>` and for the `mta-sts.` names
  of its alias domains is requested at the certificate service's next run, which can take up to one
  day. The API does not restart that service, as legacy does not.
- **Policy of a domain with alias domains**: the alias domains publish the domain's policy. An alias
  domain never has a policy of its own.
- **Diagnostics for an alias domain**: the domain-level checks run for the alias domain's own name,
  with the alias domain's own DKIM key and its target domain's MTA-STS policy, as in legacy.
- **Server without IPv6**: the IPv6 address and reverse name checks are left out, and SPF needs to
  authorise only the IPv4 address.
- **Public address cannot be determined**: the address, reverse name and SPF checks report "expected
  value unknown" with a reason.
- **Port 25 unreachable, or the TLS handshake fails**: the TLSA check reports "expected value
  unknown" with a reason and still lists the TLSA records found.
- **Server host name published as an alias (CNAME)**: the address check reports "mismatch" with that
  reason, because mail exchangers must not be aliases.
- **Autodiscover, autoconfig or MTA-STS host names published as address records** equal to the
  server's addresses instead of an alias: reported as "match" with a note. On a server without IPv6,
  matching IPv4 addresses are enough. (Legacy reports a mismatch when neither side has IPv6.)
- **More than one SPF record, or an SPF policy needing more than 10 lookups**: the SPF check reports
  "mismatch" with that reason.
- **TXT record split into several strings**: the strings are joined before they are compared.
- **Name server answers with a validation failure or an error**: "lookup failed", not "missing".
- **POP3 or POP3S explicitly marked as not offered** with a null service record (target `.`):
  accepted as the expected value, as in legacy.
- **Shared store unavailable** during a key change: the request is refused with a temporary failure
  and changes nothing. During diagnostics, the DKIM check reports "expected value unknown".
- **Audit record cannot be written**: the change it would record does not succeed.

## Requirements *(mandatory)*

### Functional Requirements

#### Access and scope

- **FR-001**: Administrators MUST be able to reach every DKIM entry, every MTA-STS policy and the
  DNS diagnostics of every domain and alias domain. Domain administrators MUST be able to reach
  exactly the domains assigned to them through an active assignment, whether or not the domain is
  active, and the alias domains that point to those domains (legacy behaviour). Mailbox users MUST
  NOT reach any operation of this feature.
- **FR-002**: Scope and permission flags MUST be evaluated from the current mailcow data on every
  request, as in feature 001 (FR-029 there). No domain-administrator permission flag gates any
  operation of this feature, as in legacy.
- **FR-003**: A request for a name outside the caller's scope MUST get exactly the same "not found"
  response as a name that does not exist (feature 002, FR-003). A request for an operation that the
  caller's role may never use MUST be refused as forbidden (FR-005).
- **FR-004**: A request authenticated by an API key MUST act with the key owner's role, scope and
  flags (feature 001, FR-033). A read-only key MUST be refused on every operation that changes
  state, with the "insufficient key scope" error of feature 001 (FR-039). A restricted session
  (feature 001, FR-014) MUST be refused.
- **FR-005**: The operations MUST be authorised as follows:

  | Operation | Administrator | Domain administrator | Mailbox user |
  |---|---|---|---|
  | List and inspect DKIM entries, read the DKIM configuration | All names, including unused keys | Own domains and their alias domains | Forbidden |
  | Generate a DKIM key | Any domain or alias domain | Own domains and their alias domains | Forbidden |
  | Import, copy or delete a DKIM key | Yes | Forbidden | Forbidden |
  | Export a private key | Yes, only while export is enabled (FR-026) | Forbidden | Forbidden |
  | Publish a DKIM record again (FR-031) | Yes | Forbidden | Forbidden |
  | Read, list, create and change MTA-STS policies | All domains | Own domains | Forbidden |
  | Run DNS diagnostics | All domains and alias domains, with server-wide checks | Own domains and their alias domains, without server-wide checks | Forbidden |
  | Read the audit log | Yes | Forbidden | Forbidden |

- **FR-006**: Names MUST be accepted in ASCII or Unicode form and in any letter case, and MUST be
  normalised as feature 002 does (FR-006 there): trimmed, lower-cased and converted to ASCII with
  the UTS #46 rules. An unused key stored by legacy under a name that is not in this form MUST be
  reachable for inspection, copying and deletion by its exact stored name, which is compared before
  normalisation.

#### DKIM entries

- **FR-007**: A DKIM entry MUST be represented with:
  - its name, in ASCII and readable (Unicode) form;
  - what the name is: a domain, an alias domain (with its target domain), or neither;
  - its state (FR-008);
  - when a key exists: its selector, its key length in bits computed exactly from the key, its
    public key, the DNS record name `<selector>._domainkey.<name>`, and the DNS TXT record value
    `v=DKIM1;k=rsa;t=s;s=email;p=<public key>`, both as one text and split into consecutive pieces
    of at most 255 characters;
  - when a key exists: whether it is complete, meaning that its public key, selector and private key
    are all stored;
  - when a key exists: its version tag (FR-051).

  It MUST NOT contain the private key.
- **FR-008**: The state of an entry MUST be:
  - **valid** when a key exists for a domain or alias domain;
  - **missing** when a domain or alias domain has no key;
  - **unused** when a key exists for a name that is neither a domain nor an alias domain.

  A name has a key when a public key or a selector is stored for it.
- **FR-009**: Administrators MUST be able to list one entry for every domain, every alias domain and
  every unused key. Domain administrators MUST be able to list the entries of their domains and of
  the alias domains that point to them. The list MUST be paginated with a default page size of 25
  and a maximum of 1,000, and MUST include the total number of matching entries.
- **FR-010**: The list MUST support:
  - a free-text search that matches the ASCII and the readable name, case-insensitively and as a
    substring;
  - filters by state (several states match entries in any of them), by kind (domain, alias domain or
    neither), by selector, by completeness and by domain (a domain together with its alias domains);
  - sorting, ascending or descending, by name, state, selector and key length. The default MUST be
    by name, ascending. Ties MUST be broken by name.
- **FR-011**: A client MUST be able to inspect the entry of one name in its scope. A name that is
  neither a domain or alias domain in the caller's scope nor, for administrators, the name of an
  unused key MUST be answered "not found".
- **FR-012**: Administrators and domain administrators MUST be able to read the DKIM configuration
  that forms need: the default selector (feature 002, FR-028), the default key size of 2,048 bits,
  the allowed key sizes, and whether the DKIM DNS publication is enabled. Administrators MUST also
  be able to read whether private key export is enabled. Connection details and secrets MUST NOT be
  returned.

#### Generating a key

- **FR-013**: Administrators MUST be able to generate a key for any domain or alias domain, and
  domain administrators for a domain or alias domain in their scope. The request names one domain or
  alias domain and MAY give a selector and a key size. An omitted selector MUST be the default
  selector, and an omitted key size MUST be 2,048 bits.
- **FR-014**: A generation request MUST be refused:
  - as "not found" when the name is not a domain or alias domain in the caller's scope;
  - as a conflict when the name already has a key, whether complete or not;
  - with a validation error when the selector is empty, longer than 255 characters or contains
    anything but letters, digits, `-`, `_` and `.` (the rule of feature 002, FR-020);
  - with a validation error when the key size is not 1,024, 2,048, 3,072 or 4,096 bits.
- **FR-015**: A successful generation MUST create a new RSA key pair and store its public key, its
  selector and its private key where legacy stores DKIM keys, all together or not at all. Outgoing
  mail from the name MUST be signed with the key from the next message on, and forwarded mail for
  the name MUST be sealed with it (ARC), because the mail filter reads the same stored keys. The
  response MUST be "created", MUST contain the entry and the DNS publication outcome (FR-030), and
  MUST NOT contain the private key.
- **FR-016**: When domain creation (feature 002, FR-027) or alias domain creation (feature 004)
  generates a key, it MUST store it as in FR-015 and publish it as in FR-029. An existing key is
  kept, as those features specify.

#### Importing a key

- **FR-017**: Administrators MUST be able to import a key for a name. The request gives the name, a
  selector, the private key text and whether an existing key may be overwritten (default: no). An
  omitted selector MUST be the default selector.
- **FR-018**: The name of an import MUST meet the domain name syntax of feature 002 (FR-019 there:
  labels of letters, digits and inner hyphens, at most 253 characters, labels of at most 63), but it
  does not need to be a domain or alias domain, as in legacy: a key imported for a name that is
  neither is listed as unused until that domain or alias domain is created, which then reuses it.
  The selector MUST meet the rule in FR-014.
- **FR-019**: The private key text MUST be at most 16 KiB and MUST be one unencrypted RSA private
  key in PEM form (PKCS #1 or PKCS #8) of at least 1,024 and at most 8,192 bits. Line endings MUST
  be normalised to line feeds. Text that fails any of these rules MUST be refused with a validation
  error whose rule code names the failure (for example not a private key, encrypted, not RSA, too
  short, too long). The error MUST NOT echo any part of the text.
- **FR-020**: When the name already has a key, an import without overwrite MUST be refused as a
  conflict. An import with overwrite MUST remove the whole old key, including the private key stored
  under the old selector, and store the new one, as one change.
- **FR-021**: A successful import MUST store the public key derived from the private key, the
  selector and the normalised private key text, all together or not at all, with the effects of
  FR-015. The response MUST be "created" when the name had no key and "updated" when a key was
  overwritten, and MUST contain the entry and the DNS publication outcome.

#### Copying a key

- **FR-022**: Administrators MUST be able to copy the key of a source name that has a key (valid or
  unused) to one target that is an existing domain or alias domain, with the selector, public key
  and private key of the source. A source without a key MUST be answered "not found". A target equal
  to the source MUST be refused with a validation error. A target that already has a key MUST be
  refused as a conflict unless the request explicitly asks to overwrite, in which case the target's
  whole old key, including the private key under its old selector, MUST be removed first.
- **FR-023**: A successful copy MUST store the target's key all together or not at all, with the
  effects of FR-015 for the target. The source MUST stay unchanged. The response MUST be "created"
  or "updated" as in FR-021 and MUST contain the target's entry and the DNS publication outcome for
  the target.

#### Deleting a key

- **FR-024**: Administrators MUST be able to delete the key of any name, whether its state is valid
  or unused. The public key, the selector and the private key MUST be removed together. Signing and
  sealing with the key MUST stop from the next message. A name without a key MUST be answered "not
  found". A successful deletion MUST respond with no body. The DNS publication outcome MUST be
  recorded in the audit record.
- **FR-025**: Deleting a key MUST NOT change the domain or alias domain. Its entry then shows
  "missing". The entry of an unused key disappears.

#### Private keys

- **FR-026**: Administrators MUST be able to export the private key of one name through a dedicated
  operation, only while the operator setting that mirrors legacy's `SHOW_DKIM_PRIV_KEYS` is enabled.
  Its default MUST be off. While it is off, the export MUST be refused with the documented "private
  key export disabled" error. A name without a key MUST be answered "not found". The response MUST
  contain the private key text exactly as stored. An export is a read, so an API key owned by an
  administrator MAY export with either access level (feature 001, FR-033). Every export, and every
  refused attempt, MUST be audited.
- **FR-027**: Private key material, whether generated, imported, copied or exported, MUST NOT appear
  in any log, audit record, error or response other than the export response of FR-026.

#### DKIM DNS publication (FeldHost)

- **FR-028**: A server setting MUST enable the DKIM DNS publication to an ISPConfig server. Its
  default MUST be off. When it is on, the operator configures the ISPConfig remote interface
  address, the remote user and its password, and the identifier of the ISPConfig DNS server that
  holds the zones. The password MUST be treated as a secret and never returned or logged.
- **FR-029**: While the publication is on, every change of the stored key of a name (generation,
  import, copy to a target, deletion) MUST update the ISPConfig zone whose origin is exactly that
  name:
  - find a TXT record named `<selector>._domainkey`, written relative to the zone or fully
    qualified;
  - after a generation, an import or a copy, update that record, or create it when there is none,
    with the value `v=DKIM1;k=rsa;t=s;s=email;p=<public key>`, a time to live of 7,200 seconds,
    active, on the configured DNS server and on behalf of the client that owns the zone, as the fork
    does;
  - after a deletion, remove the record when it exists;
  - when an overwrite changed the selector, remove the old selector's record and publish the new
    one.
- **FR-030**: The publication MUST report one outcome: "published", "removed", "no record to
  remove", "no DNS zone found", "failed" (with a reason) or "not configured". It MUST complete or
  give up within 15 seconds. Its outcome MUST be recorded in the audit record and, except for
  deletions, which respond without a body, returned with the key change. A failed publication MUST
  NOT undo or fail the key change.
- **FR-031**: Administrators MUST be able to ask for the record of a name that has a key to be
  published again, with the same steps and outcomes. This changes the DNS zone, so it counts as a
  state change (FR-004). A name without a key MUST be answered "not found". While the publication is
  off, the request MUST be refused with the documented "DNS publication not enabled" error.

#### MTA-STS policies

- **FR-032**: Administrators MUST be able to create, read, list and change the MTA-STS policy of any
  domain, and domain administrators of a domain in their scope, with no permission flag, as in
  legacy. A domain has at most one policy. A policy operation addressed with an alias domain's name
  MUST be answered "not found"; the contract MUST say that an alias domain publishes its target
  domain's policy.
- **FR-033**: A policy MUST hold, with these rules at creation and when changed:
  - version: `STSv1`, accepted in any letter case. It MAY be omitted at creation and then defaults
    to `STSv1`;
  - mode: `enforce`, `testing` or `none`, accepted in any letter case. It is required at creation;
  - maximum age: a whole number of seconds from 1 to 31,557,600. It is required at creation;
  - MX hosts: a list of at least one host name. Each entry MUST be normalised like a domain name
    (FR-006), MAY start with `*.` for a wildcard, and MUST then meet the domain name syntax of
    FR-018. It MAY name the server's own host name or any other host. Duplicates MUST be stored
    once, keeping the first occurrence and the order. The entries joined by commas MUST NOT exceed
    255 characters;
  - active state: a policy created without one MUST be inactive, as in legacy.
- **FR-034**: Creating a policy for a domain that already has one MUST be refused as a conflict. A
  change MUST send only the attributes it changes; the others keep their values. Changing the policy
  of a domain without one MUST be answered "not found". A successful creation MUST respond as
  "created" with the address of the policy, and a change with the updated policy.
- **FR-035**: Each policy MUST carry a policy identifier, a 14-digit timestamp (year to second) as
  legacy writes it. Creation MUST assign a new identifier. A change of the version, the mode, the MX
  hosts or their order, or the maximum age MUST assign a new identifier that differs from the
  previous one. A change of only the active state, or a change that changes nothing, MUST keep it.
- **FR-036**: A policy MUST be represented with its domain (ASCII and readable), version, mode, MX
  hosts, maximum age, active state, policy identifier, the address at which it is published
  (`https://mta-sts.<domain>/.well-known/mta-sts.txt`), the policy text that is served, the DNS
  records it needs (`_mta-sts.<domain>` TXT `v=STSv1;id=<identifier>;` and `mta-sts.<domain>`
  pointing to the server's host name), and its version tag (FR-051).
- **FR-037**: Policies MUST be stored where legacy stores them, so that, without any further action:
  - mailcow's existing publication endpoint, which stays in the legacy stack, serves an active
    policy for the domain and its alias domains from the next request, and stops serving it when it
    is deactivated;
  - mailcow's certificate service requests certificates for `mta-sts.<domain>` and for the
    `mta-sts.` names of the domain's alias domains at its next run while the policy is active. The
    contract MUST say that this can take up to one day.

  Policies MUST NOT be deletable through this API. A policy is removed with its domain (feature 002,
  FR-036).
- **FR-038**: Administrators and domain administrators MUST be able to list the policies of the
  domains in their scope, paginated as in FR-009, searchable by domain, filterable by active state
  and mode, and sortable by domain, mode, maximum age and policy identifier (default: domain,
  ascending).

#### DNS diagnostics

- **FR-039**: Administrators and domain administrators MUST be able to run DNS diagnostics for a
  domain or alias domain in their scope. A run MUST NOT change any stored data, and MUST NOT write
  an audit record.
- **FR-040**: A run MUST check these records of the name, in this order, as legacy does:

  | Check | Record name | Type | Expected | When | Optional |
  |---|---|---|---|---|---|
  | Mail exchanger | `<name>` | MX | the server's host name | always | no |
  | Autodiscover | `autodiscover.<name>` | CNAME | the server's host name | always | no |
  | Autodiscover service | `_autodiscover._tcp.<name>` | SRV | host name and the web interface's HTTPS port | always | no |
  | Autoconfig | `autoconfig.<name>` | CNAME | the server's host name | always | no |
  | MTA-STS host | `mta-sts.<name>` | CNAME | the server's host name | the name's MTA-STS policy is active (for an alias domain, its target domain's) | no |
  | MTA-STS record | `_mta-sts.<name>` | TXT | `v=STSv1;id=<policy identifier>;` | as above | no |
  | TLS reporting | `_smtp._tls.<name>` | TXT | guidance only | as above | yes |
  | SPF | `<name>` | TXT | a policy that authorises the server's public addresses | always | yes |
  | DMARC | `_dmarc.<name>` | TXT | guidance only | always | yes |
  | DKIM | `<selector>._domainkey.<name>` | TXT | the key's record value (FR-007) | the name has a key | no |
  | POP3 | `_pop3._tcp.<name>` | SRV | host name and POP3 STARTTLS port | the port is not 110 | no |
  | POP3S | `_pop3s._tcp.<name>` | SRV | host name and POP3S port | the port is not 995 | no |
  | IMAP | `_imap._tcp.<name>` | SRV | host name and IMAP STARTTLS port | the port is not 143 | no |
  | IMAPS | `_imaps._tcp.<name>` | SRV | host name and IMAPS port | the port is not 993 | no |
  | Submission | `_submission._tcp.<name>` | SRV | host name and submission port | the port is not 587 | no |
  | SMTPS | `_smtps._tcp.<name>` | SRV | host name and SMTPS port | the port is not 465 | no |
  | Sieve | `_sieve._tcp.<name>` | SRV | host name and ManageSieve port | the port is not 4190 | no |

  When the name publishes a null service record (target `.`) for POP3 or POP3S, that null record
  MUST be the expected value of that check, whatever the port.
- **FR-041**: For administrators, a run MUST first check the server-wide records: the server host
  name's IPv4 address (A) and the reverse name of the public IPv4 address (PTR); when the server has
  a public IPv6 address, the host name's IPv6 address (AAAA) and its reverse name (PTR); and the
  TLSA record `_25._tcp.<host name>`. Domain administrators MUST NOT get these checks, as in legacy.
- **FR-042**: The expected values MUST be derived from the server's configuration:
  - the host name and the service ports configured for the server;
  - the public IPv4 and IPv6 addresses, determined like legacy by asking mailcow's address echo
    service once over IPv4 and once over IPv6, unless the operator configured the addresses
    explicitly. A missing IPv6 answer means the server has no public IPv6 address;
  - the TLSA value `3 1 1 <SHA-256 digest of the public key>` of the certificate that the server
    presents for SMTP on port 25 after STARTTLS;
  - the DKIM key and the MTA-STS policy stored for the name.

  When a value cannot be determined, the checks that depend on it MUST report "expected value
  unknown" with a reason.
- **FR-043**: Found values MUST be compared as follows:
  - host names case-insensitively and without a trailing dot, IPv6 addresses as addresses, TLSA data
    case-insensitively;
  - MX: a match when any MX record points to the host name;
  - host name address checks: a mismatch when the host name is an alias (CNAME);
  - CNAME checks: a match when the alias points to the host name, or, when there is no alias, when
    the name's IPv4 addresses equal the host name's and its IPv6 addresses equal the host name's or
    neither has any. A match through address records MUST carry a note saying so;
  - SRV: target and port; priority and weight are ignored;
  - TXT: the strings of one record are joined before comparing;
  - DKIM: a match when a found record carries the stored public key (`p=`), whatever the order of
    its other tags;
  - MTA-STS record: a match when a found record's `v` and `id` tags equal the expected ones,
    whatever the spaces around the separators;
  - SPF: a match when evaluating the name's SPF policy for the server's public IPv4 address, and its
    public IPv6 address when it has one, yields "pass", following `include` and `redirect`,
    honouring address ranges, and stopping at the limit of 10 lookups. More than one SPF record, or
    more than 10 lookups, is a mismatch with that reason;
  - DMARC and TLS reporting: never a mismatch; the values found are reported as informational.
- **FR-044**: Each check in the result MUST carry its record name, type, expected value or guidance,
  every value found, the optional flag of FR-040, a reason where one applies, and one status:
  - **match**: a found value equals the expected value;
  - **mismatch**: records were found, but none equals the expected value;
  - **missing**: the name has no record of that type;
  - **lookup failed**: the lookup failed, was refused, failed validation or timed out;
  - **informational**: records were found for a check that has no exact expected value (DMARC, TLS
    reporting);
  - **expected value unknown**: the expected value could not be determined (FR-042).

  Optional checks MUST report their real status; the optional flag tells clients not to treat a
  missing optional record as an error.
- **FR-045**: The result MUST also carry the checked name in ASCII and readable form, whether it is
  an alias domain and its target domain, the server host name, the public IPv4 and IPv6 addresses
  used, the configured suggested SPF record (FR-050) when there is one, and the time of the run.
- **FR-046**: Lookups MUST use the same recursive resolver as the server's mail services, so that
  the result shows what those services see. The contract MUST say that DNS changes can take up to 24
  hours to show, as the legacy screen says.
- **FR-047**: The result MUST include a zone-file rendering for the name, as legacy offers for
  download:
  - an `$ORIGIN` line for the name, and one line per checked record whose name lies inside the name;
  - for checks with an exact expected value, the expected value; for informational and SPF checks,
    the values found, or a commented placeholder when none was found;
  - valid syntax for standard DNS server software: names absolute, MX records with preference 10,
    SRV records with priority 0 and weight 1, TXT values quoted and split into strings of at most
    255 characters.
- **FR-048**: Each lookup MUST complete or give up within 5 seconds, the address echo service within
  5 seconds, and the certificate fetch within 5 seconds. A whole run MUST return within 30 seconds;
  checks still open at that point MUST report "lookup failed" with a timeout reason. A run whose
  checks failed MUST still succeed as a request and return every result.
- **FR-049**: Each caller (user or API key) MUST be able to start at most 10 runs per minute.
  Further runs MUST be refused with the "too many requests" error of feature 001 and a retry hint.
  The public addresses and the certificate digest MAY be reused for up to 10 minutes.
- **FR-050**: A server setting MAY define a suggested SPF record. Its default MUST be empty, as
  upstream shows no suggestion; the FeldHost deployment sets `v=spf1 mx a ~all`. When it is set, the
  SPF check's guidance MUST include it. The guidance MUST always include the server's public
  addresses.

#### Version tags

- **FR-051**: Every DKIM entry that has a key, and every MTA-STS policy, MUST carry a version tag.
  The tag MUST change whenever the stored key or policy changes, whether through this API, the
  legacy UI or `/api/v1`. A client MAY send the version tag it read with an import or copy that
  overwrites, a deletion, or a policy change; when the tag no longer matches, the request MUST be
  refused as outdated and MUST change nothing (feature 002, FR-042).

#### Reliability

- **FR-052**: When mailcow's shared store is unavailable, key generations, imports, copies,
  deletions and exports MUST be refused with a temporary-failure error and MUST leave no partial
  change.
- **FR-053**: Concurrent changes of the key of one name MUST be serialised: of several generations,
  or imports and copies without overwrite, for a name without a key, exactly one MUST succeed and
  the others MUST be refused as conflicts.

#### Audit

- **FR-054**: Every request of this feature that changes state, or tries to, and every private key
  export or export attempt MUST write exactly one audit record into feature 001's audit log, with
  the fields of FR-046 there and:
  - the target type (DKIM key or MTA-STS policy) and the target name;
  - the operation (generate, import, copy, delete, export, publish again, create policy, change
    policy) and the outcome, including refusals and their reason;
  - for key changes: the old and new selector and key length, a fingerprint of the old and new
    public key, whether an overwrite was requested, the source name of a copy, and the DNS
    publication outcome;
  - for policy changes: each changed attribute with its old and new value, and the old and new
    policy identifier.

  It MUST NOT contain private key material or the ISPConfig password.
- **FR-055**: Administrators MUST be able to filter the audit log by these target types and by
  target name, as feature 002 adds (FR-058 there).

#### Cross-cutting

- **FR-056**: Errors MUST follow feature 001's conventions (FR-049 and FR-055 there). This feature
  MUST document at least these error types: DKIM key already exists, MTA-STS policy already exists,
  private key export disabled, DNS publication not enabled, outdated version, too many requests and
  temporary failure. Validation errors MUST use stable rule codes with their parameters, including
  the limits of FR-019 and FR-033.
- **FR-057**: Every operation MUST be described in the published API contract before it is
  implemented, and its behaviour MUST match the contract.
- **FR-058**: Changes made through the legacy UI or `/api/v1` MUST be visible in the next response
  of this API.
- **FR-059**: This feature MUST NOT offer batch operations, as feature 002 (FR-062 there). Each key
  is generated, imported, copied to one target or deleted with its own request.

### Legacy Coverage

Every legacy capability allocated to feature 010 in `docs/roadmap.md` maps to requirements as
follows. Actions are `/api/v1` names; *form* marks legacy UI forms and screens.

| Legacy action or form | Legacy implementation | Requirements |
|---|---|---|
| `add/dkim`, *form* "Add ARC/DKIM key" (several domains, selector, key length), "Select domains with missing keys" | `dkim('add')` | FR-013 to FR-016, FR-029, FR-030, FR-053, FR-059 |
| `add/dkim_import`, *form* "Import private key" (domain, selector, private key, overwrite) | `dkim('import')` | FR-017 to FR-021, FR-027, FR-029, FR-030 |
| `add/dkim_duplicate`, *form* "Duplicate DKIM record" (from, to several) | `dkim('duplicate')` | FR-022, FR-023, FR-029, FR-030, FR-059 |
| `get/dkim/{domain}` | `dkim('details')` | FR-007, FR-011 |
| *form* System › ARC/DKIM keys: valid, unused and missing keys with filters | `admin/system.php`, `dkim('details')`, `dkim('blind')`, `tab-config-dkim.twig` | FR-007 to FR-010 |
| `delete/dkim`, *form* mass remove | `dkim('delete')` | FR-024, FR-025, FR-029, FR-030, FR-059 |
| *form* private key modal, `SHOW_DKIM_PRIV_KEYS` | `dkim('details', $domain, $privkey)`, `admin/system.php`, `modals/admin.twig` | FR-026, FR-027 |
| `SPLIT_DKIM_255` record notation | `dkim('details')` | FR-007 |
| *form* DKIM record on the Edit domain page (administrator and domain administrator) | `edit.php`, `edit/domain.twig` | FR-007, FR-011 |
| Default DKIM selector in the DKIM, add-domain and import forms (fork: `feldhost`) | `tab-config-dkim.twig`, `modals/mailbox.twig` | FR-012, FR-013, FR-017 |
| ISPConfig DKIM DNS push (fork-only) | `dkim_update_in_dns()`, `inc/lib/ispconfig.php`, `inc/ispconfig.inc.php` | FR-028 to FR-031 |
| ARC sealing with the DKIM keys (no UI) | Rspamd `arc.conf` reads the same keys | FR-015, FR-024 |
| `add/mta-sts`, *form* MTA-STS tab (first save) | `mailbox('add', 'mta_sts')` | FR-032 to FR-037 |
| `edit/mta-sts`, *form* MTA-STS tab (later saves) | `mailbox('edit', 'mta_sts')` | FR-033 to FR-035, FR-051 |
| *form* MTA-STS tab read on the Edit domain page | `mailbox('get', 'mta_sts')`, `edit.php` | FR-036, FR-038 |
| `/.well-known/mta-sts.txt` (internal, stays in php-fpm) | `mta-sts.php` | FR-037 (served from the same data) |
| `ajax/dns_diagnostics.php`, *form* DNS button in the domain and alias domain lists | `dns_diagnostics.php`, `spf.inc.php`, `generate_tlsa_digest()`, `mailbox.js`, `modals/mailbox.twig` | FR-039 to FR-050 |
| *form* zone file download in the DNS modal | `dns_diagnostics.php` | FR-047 |

Parts of other screens that belong to other features: domain creation's DKIM fields (002), alias
domain creation's DKIM fields (004), and the deletion of a domain's MTA-STS policy with the domain
(002).

### Legacy Parity and Deliberate Deviations

Parity reference: `source_code/data/web/inc/functions.dkim.inc.php` (`dkim()` cases `add`,
`duplicate`, `import`, `details`, `blind`, `delete`, and the fork's `dkim_update_in_dns()`),
`inc/lib/ispconfig.php`, `inc/ispconfig.inc.sample.php`, `functions.mailbox.inc.php` (`mailbox()`
cases `mta_sts` for add, edit and get, and the DKIM calls in the `domain` and `alias_domain` add
cases), `functions.inc.php` (`hasDomainAccess`, `is_valid_domain_name`, `generate_tlsa_digest`,
`expand_ipv6`), `inc/ajax/dns_diagnostics.php`, `inc/spf.inc.php`, `mta-sts.php`, `json_api.php`
(`add/dkim`, `add/dkim_duplicate`, `add/dkim_import`, `get/dkim`, `delete/dkim`, `add/mta-sts`,
`edit/mta-sts`), `admin/system.php`, `edit.php`, `inc/vars.inc.php` (`SHOW_DKIM_PRIV_KEYS`,
`SPLIT_DKIM_255`, `$autodiscover_config`), `init_db.inc.php` (table `mta_sts`),
`templates/admin/tab-config-dkim.twig`, `templates/edit/domain.twig`, `templates/modals/admin.twig`,
`templates/modals/mailbox.twig`, `js/site/mailbox.js`, `lang/lang.en-gb.json`, Rspamd
`local.d/dkim_signing.conf` and `local.d/arc.conf` (keys read from `DKIM_PRIV_KEYS` and
`DKIM_SELECTORS`), and `Dockerfiles/acme/acme.sh` (certificates for `mta-sts.` names of domains and
alias domains with an active policy).

The reference is upstream mailcow-dockerized `2026-09a`. `source_code/` points to the FeldHost fork
(`~/Projects/mailhosting`, branch `feldhost-202609a`), so the files above were diffed against that
upstream tag. `mta-sts.php`, the MTA-STS code in `functions.mailbox.inc.php`, `json_api.php`,
`spf.inc.php`, `functions.inc.php`, `admin/system.php`, Rspamd's DKIM and ARC configuration and the
certificate service are identical. The fork differs in four ways:

1. The default DKIM selector is `feldhost` instead of `dkim` in the fallback of `dkim('add')` and in
   the selector the DKIM and import forms pre-fill (`tab-config-dkim.twig`). The fallback of
   `dkim('import')` stays `dkim` in both (FR-012, FR-013, FR-017; the setting is feature 002's
   FR-028).
2. `dkim('add')` and `dkim('delete')` push the record to, or remove it from, an ISPConfig DNS server
   through `dkim_update_in_dns()` whenever `inc/ispconfig.inc.php` exists, and ignore every failure.
   `dkim('import')` calls `dkim('delete')` first, which removes the record, and never publishes the
   new one. `dkim('duplicate')` publishes nothing (FR-028 to FR-031).
3. DNS diagnostics shows `v=spf1 mx a ~all` and the server's IPv4 and IPv6 addresses to allow as the
   expected SPF value, where upstream shows only a link to the SPF syntax (FR-045, FR-050).
4. Branding-only texts in the language files, with no effect on behaviour.

| Behaviour | Legacy | This feature |
|---|---|---|
| Who may generate keys | Administrators for existing domains and alias domains; domain administrators for their own, through `/api/v1` only (the UI offers no form) | Same (FR-005, FR-013) |
| Who may import, copy and delete keys, and see unused keys | Administrators only | Same (FR-005) |
| Who may read a key and its record | Administrators for any name; domain administrators for own domains and alias domains | Same, with unused keys administrator-only (FR-009, FR-011) |
| Object outside scope | `get/dkim` returns `{}`; writes report "access denied" | "Not found" (FR-003) |
| Private keys | Returned base64-encoded by `get/dkim/{domain}` to anyone who may read the key, domain administrators included, when `SHOW_DKIM_PRIV_KEYS` is set; the admin screen shows them in a modal | Separate export, administrators only, off by default, audited (FR-026). **Deviation** |
| Private key in logs | `api_log` writes the whole import request, including the private key, to `API_LOG`; only fields matching "pass" are masked | Never logged or audited (FR-027). **Deviation** (security fix) |
| Name normalisation for DKIM | `dkim('add')` and `dkim('import')` validate but store the name as typed, so an upper-case or Unicode name gets a key that Rspamd never uses | Normalised to lower-case ASCII; legacy leftovers listed as unused (FR-006). **Deviation** |
| Several domains per generation, several copy targets | One request with a list | One name or target per request (FR-059). **Deviation** |
| Existing key at generation | Refused with the generic "domain or selector invalid" message | Refused as a conflict (FR-014) |
| Selector rule | Letters, digits, `-`, `_`, `.`; empty refused | Same, at most 255 characters (FR-014) |
| Key size | Any number the key generator accepts; the form offers 1024 to 4096 | 1024, 2048, 3072 or 4096, default 2048 (FR-013, FR-014). **Deviation** |
| Key storage | `DKIM_PUB_KEYS[name]`, `DKIM_SELECTORS[name]`, `DKIM_PRIV_KEYS[selector.name]`, written one after another, so a failure can leave a partial key | Same keys, written together or not at all (FR-015, FR-052). **Deviation** for atomicity |
| Key length shown | Guessed from the length of the public key text (1024, 2048, 3072, 4096, ">= 8192") | Computed exactly (FR-007). **Deviation** |
| Record notation | One text, or 255-character pieces when `SPLIT_DKIM_255` is set | Both forms always (FR-007). **Deviation**: the setting is not needed |
| Import validation | Any key the crypto library parses, of any type and size | Unencrypted RSA, 1,024 to 8,192 bits, at most 16 KiB (FR-019). **Deviation** (RFC 8301 sets 1,024 bits as the minimum) |
| Import of a name that is not a domain | Allowed; the key is shown as unused | Same (FR-018) |
| Import over an existing key | Refused unless "overwrite existing key" is set; the old key is deleted first | Same (FR-020) |
| Copy | Any text accepted as target; an existing target key is overwritten silently; the target's private key under its old selector stays behind | Targets must be domains or alias domains; overwrite only on request; the old key removed completely (FR-022). **Deviation** |
| Delete of a name without a key | Reported as removed | "Not found" (FR-024). **Deviation** |
| Effect on signing | Rspamd reads `DKIM_PRIV_KEYS` and `DKIM_SELECTORS` for DKIM (envelope sender domain) and ARC (recipient domain) | Same storage, so same effect (FR-015, FR-024) |
| ISPConfig push (fork) | Attempted on generation and deletion whenever the configuration file exists; import removes the record and publishes nothing; copy publishes nothing; failures ignored; no time limit | Server setting, off by default; every key change published, selector changes handled, outcome reported within 15 seconds, republish on demand (FR-028 to FR-031). **Deviation** |
| ISPConfig record matching | TXT record whose relative name is `<selector>._domainkey`; TTL 7200; created for the zone's client (`sys_groupid - 1`) on the configured DNS server | Same, also matching the fully qualified spelling (FR-029). **Deviation**: a fully qualified record is updated instead of duplicated |
| Who may manage MTA-STS | Administrators and the domain's domain administrators, no flag | Same (FR-032) |
| MTA-STS for alias domains | `add/mta-sts` accepts an alias domain name; the row is never published and stays after the alias domain is deleted. Publication and certificates for alias domains use the target's policy | Policies only for domains, alias domain names "not found"; alias domains publish the target's policy (FR-032, FR-037). **Deviation** |
| MTA-STS validation | Version `stsv1`; mode enforce, testing or none; maximum age 1 to 31,536,000 at creation but 1 to 31,557,600 on edit; MX entries checked as host names with an optional `*.` prefix; stored as typed; no length check on the 255-character column | Same rules, maximum age 1 to 31,557,600 for both, MX entries stored normalised and without duplicates, 255-character limit enforced (FR-033). **Deviation** |
| MTA-STS creation defaults | Active only when sent as 1; version required | Inactive unless sent; version defaults to STSv1 (FR-033) |
| MTA-STS create and edit | Separate add (a second add fails with a database error) and edit (fails "access denied" without a policy); edit keeps attributes that are not sent | Conflict on a second creation, "not found" on a change without a policy, partial changes (FR-034) |
| MTA-STS policy identifier | `date('YmdHis')` at creation; renewed when version, mode, MX or maximum age change; kept when only the active state changes | Same, and always different from the previous identifier (FR-035) |
| MTA-STS deletion | None, except with the domain | Same (FR-037) |
| MTA-STS side effects | `mta-sts.php` serves active policies of the domain and its alias domains; the certificate service adds `mta-sts.` names of active policies at its next daily run | Same, from the same data (FR-037) |
| MTA-STS read and list | Only on the Edit domain page; no `get/mta-sts` route | Read and list (FR-036, FR-038) |
| Diagnostics access | Administrators and domain administrators; server-wide checks for administrators only | Same (FR-005, FR-039, FR-041) |
| Diagnostics output | An HTML table with icons, rendered into a modal | Structured checks plus a zone file (FR-044, FR-047). **Deviation** |
| Diagnostics statuses | A missing record, and a DKIM or TLS-reporting record that does not match, show the "optional" footnote; mismatches show "?" | Real statuses with an optional flag (FR-044). **Deviation** |
| Diagnostics record set | The records of FR-040 and FR-041; the check meant to skip client-configuration records for alias domains never takes effect, so alias domains get them too | Same records, alias domains included (FR-040) |
| Public addresses | Fetched from `ip4.mailcow.email` and `ip6.mailcow.email` on every run, 3-second connect timeout | Same service, or operator-configured addresses, reused up to 10 minutes (FR-042, FR-049) |
| SPF evaluation | Collects addresses from `ip4`, `ip6`, `a`, `mx`, `include` and `redirect` of the first string of each record, without address ranges or lookup limits, and counts neutral mechanisms; requires both the IPv4 and the IPv6 address | RFC 7208 "pass" for the public addresses, with ranges and the 10-lookup limit (FR-043). **Deviation** |
| CNAME through address records | Accepted when the IPv4 addresses match; reported as a mismatch when neither side has IPv6 | Accepted when IPv4 match and IPv6 match or are absent on both sides (FR-043). **Deviation** (bug fix) |
| MTA-STS record comparison | Exact text, so spaces after `;` cause a mismatch | Compared by tags (FR-043). **Deviation** |
| Timeouts | Address echo 3 s connect, certificate fetch 5 s, DNS by system default, no overall limit | 5 s per lookup, 30 s per run, partial results, at most 10 runs per minute per caller (FR-048, FR-049). **Deviation** |
| Zone file | `$ORIGIN` plus records inside the domain; MX without preference, SRV without priority and weight, TXT values unquoted unless split, which breaks at the first `;` | Same records with valid syntax (FR-047). **Deviation** (bug fix) |
| Concurrent changes | Last write wins | Version tags (FR-051). **Deviation** |
| UI log entries | Written to the `logs` table | Written to this API's audit log (FR-054), as in feature 001. **Deviation** |

### Key Entities *(include if feature involves data)*

- **DKIM key** (existing mailcow data in the shared store): for one name, a selector, a public key
  and a private key. The mail filter signs outgoing mail and seals forwarded mail with it.
- **DKIM entry** (computed): one name (a domain, an alias domain, or the name of a key without a
  domain) with its state (valid, missing, unused), its key details when a key exists, and whether
  the key is complete.
- **DKIM configuration** (operator settings, read-only here): default selector, default and allowed
  key sizes, whether private key export is enabled, whether the DKIM DNS publication is enabled.
- **DKIM DNS publication settings** (operator settings, FeldHost): enabled flag, ISPConfig remote
  interface address, remote user, password (secret) and DNS server identifier.
- **Publication outcome**: the result of one attempt to update the ISPConfig zone of a name.
- **MTA-STS policy** (existing mailcow data): for one domain, the version, mode, MX hosts, maximum
  age, active state and policy identifier. Published for the domain and its alias domains.
- **DNS diagnostics result** (computed, never stored): the checks of one run, the server facts used
  and a zone-file rendering.
- **Record check**: one expected DNS record with its expected value or guidance, the values found, a
  status, an optional flag and a reason.
- **Audit record** (owned by this API, defined in feature 001): extended with the DKIM key and
  MTA-STS policy target types, the key and policy changes and the publication outcomes.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For 100% of the names in the parity test set, the selector, public key and record
  value returned by the API equal those the legacy screens show for the same data.
- **SC-002**: 100% of test messages sent from a domain after a key was generated, imported or copied
  through the API carry a DKIM signature that verifies against the record the API shows, and none is
  signed after the key was deleted.
- **SC-003**: After each key change and policy change made through the API with the same input as
  through the legacy UI, the stored data is identical, apart from generated key material, timestamps
  and the stated deviations.
- **SC-004**: 100% of attempts by domain administrators to reach a name outside their scope get the
  same response as for a name that does not exist, and 100% of attempts by mailbox users, and by
  domain administrators to import, copy, delete or export keys, are refused.
- **SC-005**: A scan of all logs, audit records and responses after the full test run finds private
  key material only in the responses of permitted exports.
- **SC-006**: For a test zone that covers every status, 100% of checks report the status the zone
  calls for. A run against unresponsive name servers returns within 30 seconds, and 95% of runs
  against healthy zones return within 5 seconds.
- **SC-007**: 100% of the zone files produced for the parity domains pass a standard zone-file
  syntax check.
- **SC-008**: For 100% of the policies saved through the API, the legacy publication endpoint serves
  the same policy text as for the same input saved in the legacy UI. The policy identifier changes
  on 100% of content changes and on none of the changes that only switch the active state.
- **SC-009**: With the DKIM DNS publication enabled and a zone present, the zone holds exactly one
  record with the current key after 100% of key changes, and none after deletions. Every failed
  publication is reported, and none fails the key change.
- **SC-010**: Every request in FR-054 produces exactly one audit record.
- **SC-011**: An administrator can generate a key for a domain and copy the record to publish within
  1 minute, and a domain administrator can find every DNS problem of their domain from one
  diagnostics run.
- **SC-012**: In 95% of requests, a page of 25 DKIM entries is returned within 1 second on a server
  with 5,000 domains and 5,000 alias domains.

## Assumptions

- Domains and alias domains are managed in features 002 and 004. This feature reads them and defines
  the DKIM generation they trigger.
- The operator settings of this feature come from the operator's existing configuration where legacy
  has one (`SHOW_DKIM_PRIV_KEYS`, the ISPConfig configuration file, the service ports, the host
  name) and are otherwise new settings with the stated defaults: default DKIM selector `dkim`
  (feature 002), private key export off, DKIM DNS publication off, suggested SPF record empty,
  public addresses detected through the echo service. The plan decides how the API reads them.
- The FeldHost deployment enables the DKIM DNS publication with its ISPConfig server, sets the
  default selector to `feldhost` and the suggested SPF record to `v=spf1 mx a ~all`.
- The legacy publication endpoint for MTA-STS policies, autodiscover and the certificate service
  stay in the legacy stack (constitution V). This feature only writes the data they read.
- The mail filter reads DKIM keys from the shared store on each message, so no reload is needed
  after a key change.
- DNS diagnostics runs inside the server's network and uses the server's own recursive resolver. Its
  results can lag behind DNS changes by the records' cache lifetimes.
- These limits are defaults the operator can change: the 5-second lookup and 30-second run limits,
  the 10 runs per minute, the 10-minute reuse of server facts, the 15-second publication limit, and
  the list page size (25, maximum 1,000).
- Key rotation is done as legacy does it: delete the key and generate a new one, or import a new key
  with overwrite. Publishing two selectors side by side is not supported, because mailcow stores one
  key per name.
- **Out of scope**:
  - creating, changing and deleting domains and alias domains;
  - ARC settings, which have no UI in legacy;
  - publishing MTA-STS or other records to ISPConfig, which the fork does not do;
  - changing operator settings through the API;
  - deleting MTA-STS policies;
  - checking DNSSEC or DMARC policy content;
  - batch operations;
  - taking over the MTA-STS publication endpoint (feature 014).
- **Dependencies**:
  - the upstream mailcow database schema of the supported release (table `mta_sts`);
  - mailcow's shared store (DKIM keys) and mail filter;
  - mailcow's recursive resolver, its address echo service and the server's SMTP service on port 25;
  - mailcow's MTA-STS publication endpoint and certificate service;
  - for the fork-only DKIM DNS publication, an ISPConfig server with its remote interface;
  - feature 001, for identity, roles, API keys, errors, throttling and the audit log;
  - feature 002, for domains, name rules, the default DKIM selector and the version-tag convention.
