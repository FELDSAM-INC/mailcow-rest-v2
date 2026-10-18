# Research: Routing and Address Rewriting

**Feature**: `specs/009-routing-address-rewriting` | **Date**: 2026-10-18 | **Plan**: [plan.md](./plan.md)

This document records only the decisions that feature 009 adds. Everything shared is decided in
feature 001 and reused unchanged:

- the stack: Laravel 13 on PHP 8.4, and the Docker dev stack (001 R1, R4);
- the schema fixture and the MariaDB/Redis test setup (001 R2, R3);
- the single `mysql` connection, the `restv2_` table prefix and the `restv2:` Redis prefix (001 R5);
- the request pipeline, CSRF, API keys and key scopes (001 R7, R8, R19);
- identity, scope and permission flags (001 R20);
- the audit logger (001 R22);
- contract validation in tests and problem URIs (001 R13, R27);
- `MailcowConfig` and the server settings (001 R14).

The order of precedence is the owner's: legacy behaviour (constitution IV) first, then the
constitution and `docs/conventions.md`, then best practice. Legacy references are paths in upstream
mailcow-dockerized `2026-09a` (`git -C ~/Projects/mailhosting show 2026-09a:<path>`). The FeldHost
fork is identical for every file of this feature (spec, "Legacy Parity").

---

## 1. Contract shape

### R1. Modules, paths and identifiers

- **Decision**: The feature has 46 operations in eight modules under `api/modules/`:

  | Module | Paths |
  |---|---|
  | `relayhosts` | `/relayhosts`, `/relayhosts/bulk-actions`, `/relayhosts/{relayhostId}`, `/relayhosts/{relayhostId}/usage`, `/relayhosts/{relayhostId}/connection-test`, and the helper list `/relayhost-choices` |
  | `relayhost-assignments` | `/domains/{domain}/relayhost-assignment`, `/mailboxes/{mailbox}/relayhost-assignment` |
  | `transports` | `/transports`, `/transports/bulk-actions`, `/transports/{transportId}`, `/transports/{transportId}/connection-test` |
  | `bcc-maps` | `/bcc-maps`, `/bcc-maps/bulk-actions`, `/bcc-maps/{bccMapId}`, and the helper list `/bcc-destination-choices` |
  | `recipient-maps` | `/recipient-maps`, `/recipient-maps/bulk-actions`, `/recipient-maps/{recipientMapId}` |
  | `tls-policy-maps` | `/tls-policy-maps`, `/tls-policy-maps/bulk-actions`, `/tls-policy-maps/{tlsPolicyMapId}` |
  | `forwarding-hosts` | `/forwarding-hosts`, `/forwarding-hosts/bulk-actions`, `/forwarding-hosts/{forwardingHostAddress}` |
  | `routing-capabilities` | `/capabilities/routing` (operation `getRoutingCapabilities`) |

  - Relayhosts, transports, BCC maps, recipient maps and TLS policy maps are addressed by their
    upstream integer `id` (`^[1-9][0-9]*$`). A non-numeric identifier never matches the route and
    answers `404`.
  - A forwarding host is addressed by its stored address, percent-encoded in the path
    (`/forwarding-hosts/192.0.2.0%2F24`, `/forwarding-hosts/2001%3Adb8%3A%3A%2F32`). Laravel matches
    routes against the decoded path, so the route constraint for `{forwardingHostAddress}` is `.+`.
    It is registered after the literal `bulk-actions` route.
  - A domain is addressed by its name and a mailbox by its full address, normalised as features 002
    (FR-006) and 003 (FR-007) do. The assignment paths hang below 002's `/domains/{domain}` and
    003's `/mailboxes/{mailbox}`, and reuse their path parameters (`DomainName`, `MailboxAddress`).
  - Relayhost choices (`/relayhost-choices`) and BCC destination choices
    (`/bcc-destination-choices`) are read-only helper lists on top-level paths, and the routing
    capabilities read is `/capabilities/routing`, as `docs/conventions.md` ("Capabilities, history
    and refusals") requires.
  - The connection test is a non-CRUD action, so it is a sub-resource:
    `POST …/{id}/connection-test` (constitution II). It answers `200` with the result.
  - Bulk actions are one operation per collection: `POST /<collection>/bulk-actions` with
    `{action, targets[]}`. `targets` reuses `BulkTarget`, and the answer is `BulkResultResponse`
    (`docs/conventions.md`, shared contract components).
  - Assignments are replaced with `PUT` (`{"relayhostId": 5}` or `{"relayhostId": null}`), because
    the client replaces the whole value. Every other edit is a `PATCH` with JSON Merge Patch.
- **Rationale**: These are the spec's resources with plural nouns and no verbs (constitution II).
  The upstream identifiers are stable and are the identifiers that legacy, `/api/v1` and the
  administrators already use. Addressing forwarding hosts by address satisfies FR-075 literally and
  keeps automation readable. Generated clients percent-encode path parameters (`/` becomes `%2F`).
- **Alternatives considered**:
  - An opaque base64url identifier for forwarding hosts was rejected. It hides the address that
    administrators recognise, and the address is already unique.
  - A query parameter (`GET /forwarding-hosts/entry?address=`) was rejected as not RESTful.
  - One bulk path per action (`/relayhosts/activation`) was rejected: it multiplies operations
    without adding clarity.
  - Writing assignments through the domain and mailbox `PATCH` of 002 and 003 was rejected by the
    spec's clarification (own operations).

### R2. Version tags

- **Decision**: Every entry carries a strong ETag (`etag` in each representation and list entry,
  `ETag` on single reads and writes). It is computed with 001's `App\Support\Http\ETag` over the
  canonical serialisation of the **stored settings** only:

  | Resource | Inputs |
  |---|---|
  | Relayhost | `id`, `hostname`, `username`, `password`, `active` |
  | Transport | `id`, `destination`, `nexthop`, `username`, `password`, `is_mx_based`, `active` |
  | BCC map | `id`, `local_dest`, `bcc_dest`, `domain`, `type`, `active` |
  | Recipient map | `id`, `old_dest`, `new_dest`, `active` |
  | TLS policy map | `id`, `dest`, `policy`, `parameters`, `active` |
  | Forwarding host | stored address, source, presence in `KEEP_SPAM` |
  | Domain assignment | `domain.domain`, `domain.relayhost` |
  | Mailbox assignment | `mailbox.username`, `attributes.relayhost` |

  Usage counts, `nextHopSharedWith`, the orphaned flag, the effective relayhost and the times are
  derived and excluded (`docs/conventions.md`, version tags).

  **Credentials enter only as an HMAC.** The relayhost and transport tags must change when the
  password changes (FR-008). A plain hash over known fields plus the password would let anyone
  holding a tag guess weak passwords offline. As feature 008 does for sync job passwords, the
  password enters the serialisation only as `hash_hmac('sha256', password, config('app.key'))`, and
  001's `ETag::strong()` hashes the result. Rotating `APP_KEY` changes these tags, which only makes
  clients re-read.

  A transport's tag changes when another transport changes the shared credentials of its next hop,
  because its own row changes (FR-038).

  The domain and mailbox version tags of features 002 and 003 already cover the stored
  `relayhost` values, so they change with an assignment (FR-028). This feature adds a cross-check
  test only.
- **Rationale**: Constitution II and `docs/conventions.md`. The HMAC keeps FR-012 ("no part of a
  password") true for derived values too.
- **Alternatives considered**: A separate keyed-hash helper in `ETag` was rejected in favour of
  008's convention, so that every feature computes credential-bearing tags the same way. Hashing
  `modified` was rejected: relayhosts, transports and the
  Redis entries have no such column. Leaving the password out of the tag was rejected: FR-008
  requires the tag to change with credentials.

### R3. Single-resource responses with request effects

- **Decision**: Transport creations and edits answer `{ "data": Transport, "meta": {
  "affectedTransports": [...] } }`. `meta` names the other transports whose credentials the request
  changed, with their new version tags (FR-038). Forwarding host creation answers a
  `ForwardingHostCreationResult` in `data` (FR-078). Every other single resource answers
  `{ "data": … }` only.
- **Rationale**: FR-038 requires the response to name the affected transports. Putting them into the
  transport representation would make a request-specific value part of the resource and its tag.
  `meta` already exists in list responses.
- **Alternatives considered**: A response header was rejected as invisible to generated clients.
  A separate "effects" member was rejected because `meta` already exists. The project-wide single
  resource shape gains an optional `meta`, which is listed as an owner-level question in the plan.

---

## 2. Credentials and next hops

### R4. Write-only credentials

- **Decision**:
  - Representations show `username` (`null` when the stored value is empty) and `passwordSet`
    (`password != ''`). The password is never selected into an API model attribute that a resource
    could serialise. `Relayhost` and `Transport` models put `password` in `$hidden`. The services
    read it explicitly for the connection test only.
  - Input is trimmed (FR-013). A username longer than 255 characters gives `tooLong`, and a username
    containing `:` gives `invalidCharacter` (`{"characters": [":"]}`). A password longer than 255
    characters gives `tooLong` without echoing the value. A password that would be stored with an
    empty resulting username gives `required` on `username` (`{"because": "password"}`).
  - Storage is verbatim: no `\:` escaping. Values stored by legacy with escaped colons are never
    rewritten unless the request sends a new username or password (spec edge case).
  - `""` as `password` removes it. `""` as `username` without a password removes both (FR-014).
    `null` is refused (`invalidType`), as in 001's merge patches.
  - Logs: 001's `RedactSecrets` already drops context keys matching `pass`. Request bodies are
    never logged. Audit details record `password: set|changed|removed` only (FR-085).
  - Errors never echo a submitted value: `FieldViolation.message` texts are fixed strings.
- **Rationale**: FR-012 to FR-014, constitution VI, `docs/conventions.md` (secrets). Postfix splits
  `CONCAT_WS(':', username, password)` at the first colon and has no unescaping, so verbatim storage
  is what makes the credentials work.
- **Alternatives considered**: Encrypting passwords at rest was rejected: Postfix reads the column
  directly and needs clear text.

### R5. Next hop grammar (`App\Support\Routing\NextHop`)

- **Decision**: One value object parses relayhost hosts and transport next hops (FR-019), and TLS
  policy map destinations in brackets (FR-068):
  1. Trim. Empty → `required`. Longer than 255 → `tooLong`.
  2. `[inner]` or `[inner]:port`: `inner` is an IPv4 address, an IPv6 address optionally prefixed
     with `ipv6:` (case-insensitive), or a domain name.
  3. Otherwise, a bare IPv4 address with or without `:port`, or a bare IPv6 address (always without a
     port), is stored in brackets (`192.0.2.10:25` → `[192.0.2.10]:25`, `2001:db8::1` →
     `[2001:db8::1]`), as legacy does for transport next hops.
  4. Otherwise `host` or `host:port`, where `host` is a domain name.
  5. A domain name is normalised by FR-011 (trim, lower-case, UTS #46 to ASCII with
     `idn_to_ascii(…, IDNA_NONTRANSITIONAL_TO_ASCII … UTS46)`). It must then match legacy's
     `is_valid_domain_name()` (labels of letters, digits and inner hyphens, 1–63 characters, at most
     253 in total). Single labels are allowed (`postfix-relay`).
  6. `port` is decimal, 1–65535, without leading `+` or sign.
  7. IP literals keep their spelling, lower-cased. They are not compressed, because legacy stores
     them as typed and the interference check compares strings.

  Any other input gives `invalidFormat` with `{"format": "nextHop"}` (`smtp:relay.example.net`,
  `relay..example.net`, `[relay.example.net]:0`). The value object exposes `stored()` (the string to
  store), `bracketed()`, `host()` (the part inside the brackets, without `ipv6:`), `port()` (or
  `null`) and `cleanHost()`, the legacy `next_hop_clean` used by the interference check.
- **Rationale**: FR-019 and FR-037 need one grammar. Postfix itself accepts exactly these forms for
  `smtp:` next hops.
- **Alternatives considered**: Storing as typed (legacy relayhosts) was rejected by FR-019.

### R6. Transport destinations

- **Decision**:
  - **Not MX-based**: trimmed, then `*`, `.domain`, `domain` or an e-mail address (local part as
    legacy's `FILTER_VALIDATE_EMAIL` accepts it, domain normalised by FR-011), lower-cased as a
    whole. `..example.com`, `exa mple.com` and `.` give `invalidFormat` with
    `{"format": "transportDestination"}`.
  - **MX-based**: stored as given after trimming. It is valid when MariaDB can evaluate it the way
    Postfix's `mysql_mbr_access_maps.cf` does (`'%s' REGEXP destination`). The service runs
    `SELECT '' REGEXP ?` on the `mysql` connection. MariaDB 10.11 uses PCRE2 and raises error 1139
    for an invalid pattern, which gives `invalidRegex` (the code feature 008 added).
    Matching is case-insensitive because the column collation (`utf8mb4_general_ci`) is.
  - **Uniqueness** (FR-036): `SELECT id FROM transports WHERE destination = ?`, MX-based or not,
    excluding the edited transport. The column's collation makes the comparison case-insensitive.
    A duplicate gives `409 routing-entry-exists` with `field: destination` and the existing id.
- **Rationale**: Validating with the database that evaluates the pattern guarantees FR-035 ("a
  regular expression that the mail server's lookup can evaluate"). PHP's `preg_match` (legacy) uses
  a different dialect and delimiters, so a pattern can pass in PHP and fail in MariaDB.
- **Alternatives considered**: `preg_match('/'.$dest.'/')` (legacy) was rejected for that reason.

### R7. Shared transport credentials (FR-038)

- **Decision**: `App\Services\Routing\TransportService` implements one algorithm for creation and
  edit. "Same next hop" is `nexthop = ?` with the column collation, which is case-insensitive like
  Postfix's `mysql_sasl_passwd_maps_transport_maps.cf` lookup.
  - **Creation**, next hop already used by transports `T`:
    - no `username` and no `password` sent: inherit the credentials of `T`. The source row is the
      first active row of `T` with a non-empty username by `id`, else the first row by `id`, which is
      the row Postfix most likely uses (`LIMIT 1` without `ORDER BY`).
    - `username` sent and different from any stored username of `T` (a stored empty username
      counts): `409 next-hop-credentials-differ`, naming the next hop and the ids of `T`, as legacy
      `invalid_nexthop_authenticated` does.
    - `password` sent, with the next hop's username or without a username: the new password is
      written to the new transport and to every row of `T`.
  - **Edit**: let `N` be the resulting next hop and `O` the other transports with next hop `N`.
    - The base credentials are those of `O` when the next hop changes and `O` is not empty
      (adoption), otherwise the transport's own.
    - The resulting username is the sent one, else the base. The resulting password is the sent one,
      else the base. An empty resulting username empties the password (legacy and FR-014).
    - When the request sends `username` or `password`, the resulting credentials are written to the
      transport and to every row of `O`. Otherwise only the transport changes.
  - **Effects**: the ids of the rows of `O` or `T` whose username or password actually changed are
    returned in `meta.affectedTransports` and recorded in the audit details (`affectedTransportIds`).
  - **Atomicity**: all writes of one request run in one transaction with the audit record, under the
    transport lock (R9).
- **Rationale**: This is the spec's clarification. It removes legacy's two defects: a creation
  without a password wiped the shared password, and a moved transport overwrote the target's
  credentials.
- **Alternatives considered**: A separate "next hop credentials" resource was rejected: the spec
  keeps credentials on each transport, as stored.

### R8. Next hop interference (FR-037)

- **Decision**: With `nexthop` written as `N` and its `cleanHost()` as `h`:
  - when `N` is bracketed, refuse if any other transport's `nexthop` equals `h`;
  - when `N` is not bracketed, refuse if any other transport's bracketed `nexthop` has
    `cleanHost() = N`.

  Comparisons ignore letter case. The refusal is `409 next-hop-interferes`, with `nextHop` (the
  interfering stored value) and `transportIds`. The edited transport is excluded.
- **Rationale**: Legacy's `next_hop_interferes` and `next_hop_interferes_any`, made symmetric and
  case-insensitive as FR-037 requires.

### R9. Uniqueness under concurrency (FR-084) and atomicity (FR-083)

- **Decision**:
  - `transports`, `bcc_maps` and `recipient_maps` have no unique index on the keys the spec makes
    unique, and upstream tables cannot get one (constitution III). Writes to these tables take a
    MariaDB named lock for the table, `GET_LOCK('restv2:routing:<table>', 10)`, through a new helper
    `App\Support\Database\NamedLock`. The helper acquires the lock before the transaction, runs the
    check, the writes and the audit insert in one `DB::transaction()`, and releases the lock in a
    `finally` block. A lock that cannot be acquired within 10 s answers `503 temporary-failure`.
  - `tls_policy_override.dest` has a unique index. The service checks first (to answer with the
    existing id) and also maps MariaDB error 1062 to `409 routing-entry-exists`.
  - Relayhost deletion and the assignment clean-up run in one transaction (FR-021).
  - Bulk items each run in their own transaction (FR-091: one failure does not undo the others).
- **Rationale**: A named lock is deterministic and needs no schema change. Writes are rare
  administrator actions, so serialising them per table costs nothing. Legacy writes still bypass
  the lock, as they bypass every check today (spec edge case "Concurrent edits from the legacy UI").
- **Alternatives considered**: `SELECT … FOR UPDATE` gap locks were rejected: both requests take
  compatible gap locks and then deadlock on insert, so correctness would depend on deadlock retries.
  A Redis lock was rejected: it adds a dependency to SQL-only operations.

---

## 3. Relayhosts and assignments

### R10. Relayhost lists, usage and choices

- **Decision**:
  - Usage counts come from two derived tables joined once per query, never one lookup per row
    (FR-007, SC-006):
    - `SELECT relayhost, COUNT(*) FROM domain GROUP BY relayhost`, joined with a numeric comparison
      (`d.relayhost = r.id`), as Postfix compares;
    - `SELECT JSON_UNQUOTE(JSON_VALUE(attributes, '$.relayhost')) AS rh, COUNT(*) FROM mailbox
      WHERE kind = '' GROUP BY rh`, joined the same way.

    The join groups by `r.id`, so legacy spellings such as `03` still count once.
  - `inUse=true` is `domains + mailboxes > 0`. Sorting by `domainCount` and `mailboxCount` sorts by
    these columns, ties broken by `id`.
  - Usage list (`/relayhosts/{id}/usage`): `SELECT 'domain', domain FROM domain WHERE relayhost =
    ? UNION ALL SELECT 'mailbox', username FROM mailbox WHERE JSON_UNQUOTE(JSON_VALUE(attributes,
    '$.relayhost')) = ? AND kind = ''`, with `kind`, `q` and paging applied around the union. The
    default sort is `kind` (domains first), then name.
  - Choices: `SELECT id, hostname, active FROM relayhosts`, searched on `hostname`.
- **Rationale**: FR-015, FR-016, FR-022, FR-023. A `GROUP BY` over 10,000 mailboxes takes a few
  milliseconds, and no index can be added to `domain.relayhost` or the JSON attribute.

### R11. Assignment semantics (FR-024 to FR-030)

- **Decision**:
  - **Stored value**: `domain.relayhost` (`VARCHAR`, `'0'` = none) and
    `mailbox.attributes.relayhost` (JSON string, `"0"` = none, as legacy writes it). A missing
    attribute, `""`, `"0"` and `0` all mean none. A write stores the decimal id as a string, or
    `'0'`.
  - **Writes**: `UPDATE domain SET relayhost = ? WHERE domain = ?` and `UPDATE mailbox SET
    attributes = JSON_SET(attributes, '$.relayhost', ?) WHERE username = ?`. Nothing else changes.
    `modified` updates itself (`ON UPDATE CURRENT_TIMESTAMP`). No `_sogo_static_view` update, no
    memcached flush and no `attribute_hash` reset (spec parity table, FR-028).
  - **Reference state**: `active` and `inactive` follow `relayhosts.active`. `dangling` means no
    relayhost row has the stored id. A dangling reference has `host: null` and no effect (FR-030).
  - **Effective relayhost** of a mailbox (FR-025): the mailbox's relayhost if it exists and is
    active, otherwise the domain's relayhost if it exists and is active, otherwise `null` (direct
    delivery). This mirrors `mysql_sender_dependent_default_transport_maps.cf`.
  - **Credentials** of a mailbox (spec edge case): `mysql_sasl_passwd_maps_sender_dependent.cf`
    takes the domain's active relayhost first, then the mailbox's, and sends credentials only when
    the chosen row has a username. `credentialsRelayhost` reports that row, or `null` when no login
    happens. A domain relayhost without a username therefore suppresses the mailbox relayhost's
    login. The contract documents this.
  - **Addressing**: an alias domain name or a resource address answers `404`, as in 002 and 003.
  - **Validation**: `relayhostId` must be `null` or the id of an existing relayhost (`notFound`
    otherwise). Inactive relayhosts are accepted (FR-026).
  - **Authorisation order** for domain administrators: role, then the flag (`403
    permission-flag-required` with `requiredFlags: ["domainRelayhost"]` or `["mailboxRelayhost"]`),
    then scope (`404`). The flag check comes first, so a refusal never reveals whether an
    out-of-scope object exists.
- **Rationale**: The Postfix SQL maps of `2026-09a` are the behaviour the API must preserve
  (SC-002). Reporting the credentials row makes the upstream lookup order visible instead of
  surprising.

### R12. Relayhost deletion (FR-021)

- **Decision**: In one transaction: `DELETE FROM relayhosts WHERE id = ?`; `UPDATE domain SET
  relayhost = '0' WHERE relayhost = ?`; `UPDATE mailbox SET attributes = JSON_SET(attributes,
  '$.relayhost', '0') WHERE JSON_UNQUOTE(JSON_VALUE(attributes, '$.relayhost')) = ?`. The cleared
  domain and mailbox names are selected first and recorded in the audit details (`clearedDomains`,
  `clearedMailboxes`).
- **Rationale**: Legacy resets domains only. The spec's clarification also clears mailboxes, so
  that a reused identifier never silently re-activates an old assignment.

---

## 4. Connection test

### R13. Where and how the test runs

- **Decision**: The test runs synchronously inside the API's php-fpm worker, as legacy runs it
  inside php-fpm-mailcow. There is no dockerapi call and no Postfix involvement (FR-045).
  - **Client**: a small hand-written SMTP client, `App\Clients\Smtp\StreamSmtpProbe`, behind the
    interface `App\Clients\Smtp\SmtpProbe`. It uses `stream_socket_client()` and
    `stream_socket_enable_crypto()` only.
  - **Target resolution** (`App\Services\Routing\ConnectionTestService`, FR-042), legacy
    `transport_check.php`:
    1. Parse the stored next hop with `NextHop`. A stored value that does not parse, for example a
       legacy relayhost typed as `smtp:host`, is still tested the way legacy would: brackets are
       removed and a trailing `:digits` is taken as the port.
    2. Without brackets, look up MX records of the host (`DnsResolver::mx()`), sort by preference and
       take the first. Equal preferences keep the order returned, as in legacy. Record an `info`
       transcript line naming the exchanger. With no MX, or a failed lookup, use the host itself, as
       legacy `getmxrr()` does.
    3. The port is the next hop's port, or 25.
    4. Resolve the chosen host to addresses (`DnsResolver::a()` and `aaaa()`). None → step
       `resolve`, reason `nameNotResolved`. A temporary failure → step `resolve`, reason
       `dnsTemporaryFailure`.
  - **Dialogue**, in steps (FR-043):
    - `connect`: TCP to the host and port. PHP tries every resolved address.
    - `greeting`: a `220` reply, then `EHLO <MAILCOW_HOSTNAME>`, falling back to `HELO` on a 5xx.
      The name is what PHPMailer sends from php-fpm-mailcow (`SERVER_NAME`).
    - `tls`: when `STARTTLS` is advertised, `STARTTLS`, then a TLS handshake with
      `verify_peer = false`, `verify_peer_name = false` and `allow_self_signed = true`, then `EHLO`
      again. Implicit TLS is never used, so port 465 fails at `greeting` and the contract warns.
    - `login`: only when a username is stored. Mechanisms in the order PLAIN, then LOGIN, among
      those the server advertises. That is the mail server's own `smtp_sasl_mechanism_filter =
      plain, login`, so the test checks what Postfix will do. Without `AUTH` or a common mechanism,
      the reason is `loginNotOffered` or `loginMechanismNotSupported`.
    - `sender`: `MAIL FROM:<sender>`.
    - `recipient`: `RCPT TO:<recipient>`.
    - `message`: `DATA`, a short message (`From: Mailer <sender>`, `To: Joe Null <recipient>`,
      `Subject: A subject for a SMTP test`, `Date`, `Message-ID`, body `This is our test body`, the
      legacy content) with dot-stuffing, `.`. `250` means `accepted: true`.
    - `QUIT` is sent best-effort and is not a step.
  - **Timeouts**: every connect and every read waits at most `min(15 s, time left)`. The whole test
    has a deadline of 60 s, measured from the start of the request, including DNS. On expiry, the
    current step fails with reason `timeout`. The values are server settings (R21).
    `set_time_limit(90)` is called before the test. nginx's `fastcgi_read_timeout` is raised from
    60 s to 90 s in `deploy/nginx/site.restv2.custom`, so that a 60 s test is never cut off by the
    proxy.
  - **Transcript**: one entry per line, `{direction: client|server|info, text}`.
    - Redaction (FR-043): the argument of every `AUTH` command, every client line sent in reply to
      a `334` challenge, and the text of every `334` server challenge become `[redacted]`
      (`AUTH PLAIN [redacted]`, `334 [redacted]`, `[redacted]`). The redaction happens when the line
      is recorded, so the secret never enters the result object.
    - The message content is recorded as one client line, `[message: N bytes]`.
    - Server text has control characters replaced by `?`. Each line is cut to 1,000 characters, and
      the transcript is cut to 500 lines with a final `info` line saying so.
  - **Result**: `accepted`, `step`, `failure` (`reason`, English `message`), `nextHop`, `host`,
    `port`, `hostSource` (`nextHop` or `mailExchanger`), `tls`, `login` (`notConfigured`,
    `notReached`, `succeeded`, `failed`), `sender`, `recipient`, `lastReply` (`code`, `text`),
    `transcript`, `startedAt` and `durationMs`. A test that ran answers `200` whatever its outcome
    (FR-043).
  - **Addresses**: `sender` and `recipient` are optional. A given value must pass
    `FILTER_VALIDATE_EMAIL` after FR-011 normalisation of the domain, at most 255 characters,
    otherwise `invalidFormat` with `{"format": "email"}` (FR-041). Omitted values take the
    server settings `RESTV2_ROUTING_TEST_SENDER` (`relay@example.org`) and
    `RESTV2_ROUTING_TEST_RECIPIENT` (`null@hosted.mailcow.de`).
  - **Reach**: like legacy, an administrator may test any host and port the API container can reach,
    including mailcow-internal names (a `postfix-relay` container is a legitimate next hop). The
    test is administrator-only, audited and bounded, and its transcript shows only what the remote
    end answered, capped. No further restriction is added, because it would break legitimate
    internal relays (spec edge case "single-label hosts").
  - **Audit**: one record (`relayhostTest` or `transportTest`) with `sender`, `recipient`,
    `accepted`, `step` and `failureReason`, never the transcript (FR-085). It is written after the
    dialogue, in its own transaction. A read-only key is refused by 001's `EnforceKeyScope`, because
    the operation is a `POST` (FR-044).
- **Rationale**: Only a hand-written client gives exact steps, per-step deadlines and redaction at
  the source, which FR-042 and FR-043 require. It is about 300 lines with no dependency
  (constitution VIII). The connection behaviour (MX lookup, port 25, opportunistic STARTTLS without
  verification, login only with a username, 15 s per step) is legacy's.
- **Alternatives considered**:
  - PHPMailer (legacy's library, LGPL-2.1, GPL-compatible) was rejected. Its debug output contains
    the base64 login exchange, which would have to be scrubbed after the fact. Its errors do not say
    which step failed, and its CRAM-MD5 preference differs from the mail server.
  - Symfony Mailer (already present through Laravel) was rejected for the same reasons: auto-TLS and
    authenticators are internal, and its debug log contains the credentials.
  - Running `swaks` or Postfix's `smtp` client in postfix-mailcow through dockerapi was rejected. It
    needs a tool that upstream does not ship, and the spec places the test in the API's container.
  - A background job with polling was rejected: the spec bounds the test to 60 s and treats it as one
    request (SC-007).

### R14. Test doubles for SMTP and DNS

- **Decision**:
  - Feature tests bind `Tests\Support\Smtp\FakeSmtpProbe` and `Tests\Support\Dns\FakeDnsResolver`
    in the container. The fakes record the calls (host, port, EHLO name, credentials, sender,
    recipient) and return scripted results, so feature tests never open sockets.
  - `StreamSmtpProbe` itself is tested in `tests/Unit/Clients/StreamSmtpProbeTest.php` against
    `tests/Support/Smtp/ScriptedSmtpServer.php`, a PHP CLI process started with Symfony `Process`.
    It listens on `127.0.0.1:<random port>`, plays a JSON script and records what it receives. A
    self-signed certificate is generated with `openssl_pkey_new()` and `openssl_csr_sign()` in the
    test's temporary directory for STARTTLS. The scripts cover acceptance with PLAIN and LOGIN, a
    rejected login, a rejected sender, a rejected recipient, a rejected message, no STARTTLS, a
    failed handshake, a server that never greets (step timeout set to 1 s in the test), a closed
    port, and a server that closes the connection.
- **Rationale**: Constitution VII fakes external services behind interfaces and asserts the calls.
  A real socket test proves the client and the redaction byte by byte.

### R15. DNS client (`App\Clients\Dns\DnsResolver`)

- **Decision**: The interface `DnsResolver` has `txt(string)`, `mx(string)`, `a(string)` and
  `aaaa(string)`. The implementation `NativeDnsResolver` wraps `dns_get_record()`:
  - `[]` means the name has no such records (NXDOMAIN or NODATA);
  - `false` with the warning "A temporary server error occurred." or any other resolver warning
    throws `DnsTemporaryFailure`. The warning is captured with a temporary error handler and never
    reaches the log as a PHP warning.

  TXT records are returned with their character strings concatenated (RFC 7208 §3.3). The API
  container uses mailcow's resolver, as php-fpm-mailcow does: `deploy/docker-compose.override.yml`
  gets `dns: ["${IPV4_NETWORK:-172.22.1}.254"]` (unbound-mailcow). `dns_get_record()` cannot be
  interrupted, so the same service gets `dns_opt: ["timeout:2", "attempts:2"]`, which bounds one
  lookup to about 4 s and keeps the connection test's MX and address lookups well inside its 60 s
  deadline (SC-007).
- **Rationale**: PHP's resolver distinguishes "no records" (empty array) from failures (`false`
  plus a warning). That is exactly what FR-076 and the spec edge case "DNS temporarily
  unavailable" need, without a DNS library. Using unbound-mailcow satisfies the spec's assumption
  that the API resolves like the legacy web container.
- **Alternatives considered**: `net_dns2` (BSD) was rejected as an unneeded dependency. Laravel has
  no DNS client.

---

## 5. Forwarding hosts

### R16. Input and resolution (FR-076, FR-077)

- **Decision**: `App\Services\Routing\ForwardingHostResolver`:
  1. Trim the input, which becomes the source. Empty → `required`. Longer than 255 → `tooLong`.
  2. Classify:
     - contains `/` → must be `address/prefix`;
     - contains `:` → must be an IPv6 address;
     - only digits and dots → must be an IPv4 address;
     - otherwise → a domain name (FR-011), which is resolved.

     An address literal that does not parse (`999.1.1.1`, `2001:db8::/129`) gives
     `invalidFormat` with `{"format": "ipNetwork"}`. `cafe` is a domain name (spec AS-5).
  3. Canonical form: `inet_ntop(inet_pton(address))` (lower-case, compressed IPv6), and for a
     prefix the network address with the host bits cleared plus `/prefix` (IPv4 0–32, IPv6 0–128).
     A single address is stored without a prefix, as legacy stores it.
  4. A name is resolved like legacy's `get_outgoing_hosts_best_guess()`:
     - **SPF**: the TXT records starting with `v=spf1` (case-insensitive). Terms are evaluated left
       to right. A mechanism with the qualifier `-` or `~` ends the evaluation of its record (legacy
       `continue 2`). `+` and `?` are stripped.
       - `ip4:` and `ip6:` add their address or prefix;
       - `a[:domain][/len4][//len6]` adds the domain's A and AAAA addresses with the prefix length;
       - `mx[:domain][/len4][//len6]` adds the A and AAAA addresses of each exchanger, at most 10
         exchangers;
       - `include:domain` adds the included record's hosts (skipped when it names the record's own
         domain, as legacy does);
       - `redirect=domain` replaces the result with the redirected record's hosts, as legacy does;
       - other terms (`all`, `ptr`, `exists`, `exp`) are ignored.

       `include`, `redirect`, `a` and `mx` count as DNS lookups. At 10 lookups, evaluation stops and
       keeps what was found (spec edge case).
     - When SPF gives no address: the A and AAAA addresses of the MX hosts.
     - When that gives none: the domain's own A and AAAA addresses.
  5. All results are canonicalised and de-duplicated. No address → `422` with `notResolvable` on
     `host`. A `DnsTemporaryFailure` anywhere → `503 temporary-failure`, whose `detail` names DNS.
- **Rationale**: FR-076 states legacy's order and the lookup limit. `a/24` and dual CIDR (`//64`)
  are part of the SPF syntax that legacy's parser ignores. Supporting them, and concatenating TXT
  strings, reads SPF records correctly without changing the order. These two parsing details are
  added to the spec's parity table as plan-level rows.
- **Alternatives considered**: A full RFC 7208 evaluator was rejected. The feature collects
  permitted hosts, it does not evaluate a sender.

### R17. Redis storage (FR-078 to FR-082)

- **Decision**: `App\Services\Routing\ForwardingHostStore` works on mailcow's Redis (database 0,
  no prefix) through 002's `App\Clients\SharedStore\SharedStore`, which provides `ping()`,
  `MULTI`/`EXEC` and the compensation pattern for every feature that writes mailcow's Redis:
  - `WHITELISTED_FWD_HOST`: hash `address → source`. `KEEP_SPAM`: hash `address → 1`. An address in
    `KEEP_SPAM` has the spam filter **disabled** (legacy `filter_spam = 0` → `HSET KEEP_SPAM`).
    `spamFilter = !HEXISTS KEEP_SPAM address`.
  - **List and read**: `HGETALL WHITELISTED_FWD_HOST` plus `HGETALL KEEP_SPAM`, two calls per
    request whatever the page size. Filters, search, natural address sorting (IPv4 before IPv6,
    numeric, then prefix length) and paging run in PHP. That is fine at 10,000 entries (SC-006).
  - **Create**: read both hashes, match each canonical address against the canonical form of every
    stored address (spec edge case: stored non-canonical entries are updated in their stored form),
    then write in one `MULTI`/`EXEC`: `HSET WHITELISTED_FWD_HOST <address> <source>` and either
    `HDEL KEEP_SPAM` (filter on) or `HSET KEEP_SPAM <address> 1` (filter off).
  - **Edit**: `HSET` or `HDEL` on `KEEP_SPAM` only. **Delete**: `HDEL` on both hashes in one
    `MULTI`/`EXEC` (FR-080).
  - **Atomicity with the audit record**: the audit record is inserted inside a database
    transaction; the Redis `EXEC` runs inside the same transaction callback, last. If `EXEC` fails,
    the transaction rolls back, the request answers `503 temporary-failure` (the shared store) and a failure record is written. If the commit fails after `EXEC`, the store
    restores the previous field values, captured before the write, in a compensating `MULTI`/`EXEC`.
  - **Unavailable Redis** (FR-082): any `RedisException`, including a refused connection, answers
    `503 temporary-failure` before anything is written, with `Retry-After: 5`.
  - The SQL table `forwarding_hosts` stays unused, as in legacy.
- **Rationale**: Reusing 002's `SharedStore` keeps one Redis write discipline across features.
  These are legacy's keys and meanings, which Rspamd (`multimap`, `force_actions`,
  `KEEP_SPAM` prefilter), greylisting and Postfix postscreen (`forwardinghosts.php`) read.
  `MULTI`/`EXEC` gives the all-or-nothing that legacy lacks.

---

## 6. BCC maps, recipient maps and TLS policy maps

### R18. BCC maps (FR-046 to FR-058)

- **Decision**:
  - **Type mapping**: stored `sender` and `rcpt` are API `sender` and `recipient`.
  - **Scope**: administrators see every row. Domain administrators see rows whose `domain` is a
    domain of their scope (001 R20: active assignment, existing domain) or an alias domain whose
    `target_domain` is in their scope. As SQL: `domain IN (:scope) OR domain IN (SELECT
    alias_domain FROM alias_domain WHERE target_domain IN (:scope))`.
  - **Orphaned**: `domain NOT IN (SELECT domain FROM domain) AND domain NOT IN (SELECT alias_domain
    FROM alias_domain)`. Such rows can never match a domain administrator's scope, so they are
    reported `false` for them by construction (FR-056).
  - **Local destination** (`App\Services\Routing\BccDestinationResolver`, FR-050): trim and
    lower-case, then:
    1. if it is a domain name, optionally with a leading `@`: an existing domain or alias domain in
       scope, stored as `@<ascii>`, `domain = <ascii>`;
    2. if it is an e-mail address (domain part normalised): a mailbox with `kind = ''`, or an alias
       row with `address NOT LIKE '@%'` and `goto != address` whose address is not a mailbox, or
       `<local part>@<alias domain>` for a mailbox whose domain is the alias domain's target. Each
       must be in scope. Stored with its ASCII domain, `domain` = the domain part.
    3. anything else, unknown or out of scope → `notFound` on `localDestination` (one error for
       all, FR-050).
  - **BCC destination** (FR-052): trim; when it contains `,`, `;` or whitespace and each piece is an
    address → `tooMany` (`{"max": 1}`); otherwise it must pass `FILTER_VALIDATE_EMAIL` after domain
    normalisation (`invalidFormat`, `email`) and be at most 255 characters.
  - **Uniqueness** (FR-053): `local_dest = ? AND type = ?`, at creation and on a type change, under
    the `bcc_maps` lock → `409 routing-entry-exists` with `field: type` or `localDestination`.
  - **Writes**: `INSERT INTO bcc_maps (local_dest, bcc_dest, domain, active, type)`, `UPDATE …
    SET bcc_dest, active, type`, `DELETE`. `created` and `modified` are left to their defaults.
  - **Choices** (FR-057): one `UNION ALL` query, filtered to the scope in SQL, with `kind`, `domain`
    and `q` applied around it:
    - `domain`: `@domain` from `domain`;
    - `aliasDomain`: `@alias_domain` from `alias_domain`;
    - `mailbox`: `username` from `mailbox WHERE kind = ''`;
    - `alias`: `address` from `alias` (not a catch-all, `goto != address`, address not a mailbox);
    - `aliasDomainAddress`: `CONCAT(local_part, '@', alias_domain)` from `mailbox JOIN alias_domain
      ON target_domain = mailbox.domain WHERE kind = ''`.

    `deliversTo` is filled for the page in one more query: the alias's `goto` split at commas,
    intersected with the mailboxes in scope, and for an alias-domain address its mailbox.
- **Rationale**: Legacy's checks (`bcc('add')`, `mailbox('get', 'shared_aliases' |
  'direct_aliases')`, `hasDomainAccess()`), with the spec's deviations: alias-domain addresses are
  accepted, and one error covers unknown and out-of-scope values.

### R19. Recipient maps and TLS policy maps

- **Decision**:
  - **Recipient maps** (FR-061 to FR-064): each destination is trimmed, lower-cased and stripped of
    one leading `@`, then must be a domain name (stored `@<ascii>`) or an e-mail address (domain
    part to ASCII), at most 255 characters as stored. Errors are `invalidFormat` with
    `{"format": "recipientMapDestination"}`. The same rules apply on edit (a deviation from legacy,
    stated in the spec). Uniqueness of `old_dest` holds under the `recipient_maps` lock → `409
    routing-entry-exists`.
  - **TLS policy maps** (FR-068 to FR-070):
    - the destination is trimmed and lower-cased, then must be a domain name, `.domain`, or
      `[host]` / `[host]:port` parsed by `NextHop`, and is converted to ASCII. `.`, `*`, `@` and
      `example.org:587` (a port without brackets) give `invalidFormat` with
      `{"format": "tlsPolicyDestination"}`;
    - the policy is matched case-insensitively against the eight values of the upstream `ENUM`
      (`notInList` with `allowed`) and stored lower-case;
    - the parameters are split on runs of whitespace, each item must match `^[^=\s]+=\S+$`
      (`invalidFormat`, `tlsPolicyParameters`), and they are stored joined by single spaces, at most
      255 characters (`tooLong`). Empty means `''` (the column default).
- **Rationale**: The spec's rules. Postfix looks the destination up verbatim (`dest = '%s'`), so
  normalised storage is what makes an entry apply.

---

## 7. Cross-cutting

### R20. Bulk actions (FR-090, FR-091)

- **Decision**:
  - `POST /<collection>/bulk-actions` with `{"action": …, "targets": [BulkTarget, …]}`, 1 to 100
    targets (`tooMany` with `{"max": 100}` above). Actions: `activate`, `deactivate`, `delete`
    (relayhosts, transports, recipient maps, TLS policy maps); `activate`, `deactivate`,
    `switchToSender`, `switchToRecipient`, `delete` (BCC maps); `enableSpamFilter`,
    `disableSpamFilter`, `delete` (forwarding hosts).
  - The whole request is refused only for role, flag or key scope (`403`). Each distinct target is
    then processed by the same service method as the single operation: `done`, `notFound`,
    `refused` (with the problem, for example `routing-entry-exists` for a type switch), `outdated`
    (its `version` no longer matches) or `failed` (unexpected error or `temporary-failure`). Each item
    has its own transaction and audit record carrying `bulkRequestId` (the request ID).
  - Duplicate targets are processed once and reported once (first occurrence).
  - The runner is the shared `App\Support\Bulk\BulkActionRunner`, created by feature 004 at the
    latest (or earlier by 002, 003 or 008). 009 reuses it and adds only its per-collection item
    handlers in `App\Services\Routing\RoutingBulkActions`.
- **Rationale**: `docs/conventions.md` (bulk actions) and the spec's clarification.

### R21. Server settings and routing capabilities (FR-093)

- **Decision**: New `RESTV2_*` server settings in `config/restv2.php`, with legacy defaults:

  | Variable | Default | Meaning |
  |---|---|---|
  | `RESTV2_ROUTING_TEST_SENDER` | `relay@example.org` | Default test sender (legacy `transport_check.php`) |
  | `RESTV2_ROUTING_TEST_RECIPIENT` | `null@hosted.mailcow.de` | Default test recipient |
  | `RESTV2_ROUTING_TEST_STEP_TIMEOUT` | `15` | Seconds per network step (legacy PHPMailer `Timeout`) |
  | `RESTV2_ROUTING_TEST_TOTAL_TIMEOUT` | `60` | Seconds for the whole test (FR-042) |

  Invalid values (a non-address, a timeout below 1 or a total below the step) fall back to the
  default with a logged warning. `GET /capabilities/routing` reads them on every request, together
  with the fixed limits: bulk limit 100, page sizes 25 and 1,000 from 001's `ListQuery`, field
  limits 255, the eight TLS policies and the SPF lookup limit 10. Domain administrators receive
  `administration: null` (FR-093). The response has no version tag, because no operation changes
  it.
- **Rationale**: `docs/conventions.md` (legacy configuration as server settings, capabilities
  read). The legacy defaults live in a PHP file that the API cannot read, so they are server
  settings.

### R22. Problem types, rule codes and audit vocabulary

- **Decision**:
  - **Problem types**. Reused: `temporary-failure` (503 with `Retry-After`, introduced by feature 002)
    for an unavailable Redis, a temporary DNS failure or a named lock that cannot be acquired; the
    `detail` names the dependency. An outdated version tag stays `412 about:blank`, per the
    conventions of `docs/problems.md`. New, appended to `docs/problems.md` and `KnownProblemType`:
    - `routing-entry-exists` (409): a unique key of this feature is taken. Extensions `field` and
      `conflictingId`.
    - `next-hop-credentials-differ` (409). Extensions `nextHop` and `transportIds`.
    - `next-hop-interferes` (409). Extensions `nextHop` and `transportIds`.
    - `permission-flag-required` (403): the domain administrator lacks a permission flag that the
      operation needs. Extension `requiredFlags` (camelCase names of `PermissionFlags`). It is
      generic: a later feature with the same meaning reuses it. It differs from 002's
      `attribute-not-permitted`, which names attributes of an otherwise permitted edit.
  - **Validation rule codes**. Reused: `immutable` (002: a read-only member sent back with a
    different value, FR-009), `invalidRegex` (008: an MX-based destination MariaDB cannot evaluate),
    `notFound`, `tooMany`, `tooLong`, `notInList`, `required`, `invalidType`, and `invalidFormat`
    with the formats `domainName`, `email` (002) and `ipNetwork` (001). New, appended to
    `ValidationRuleCode`: `notResolvable` (a forwarding host name without addresses, FR-076) and
    `invalidCharacter` (`{"characters": [":"]}`, FR-013). New `format` values of `invalidFormat`:
    `nextHop`, `transportDestination`, `recipientMapDestination`, `tlsPolicyDestination` and
    `tlsPolicyParameters`.
  - **Audit** (001 R22): event types `relayhostCreation`, `relayhostUpdate`, `relayhostDeletion`,
    `relayhostTest`, `relayhostAssignmentUpdate`, `transportCreation`, `transportUpdate`,
    `transportDeletion`, `transportTest`, `bccMapCreation`, `bccMapUpdate`, `bccMapDeletion`,
    `recipientMapCreation`, `recipientMapUpdate`, `recipientMapDeletion`, `tlsPolicyMapCreation`,
    `tlsPolicyMapUpdate`, `tlsPolicyMapDeletion`, `forwardingHostCreation`,
    `forwardingHostUpdate` and `forwardingHostDeletion`. Target types `relayhost`,
    `domainRelayhostAssignment`, `mailboxRelayhostAssignment`, `transport`, `bccMap`,
    `recipientMap`, `tlsPolicyMap` and `forwardingHost`. The target id of an assignment is the
    domain name or mailbox address, so that 002's `targetId` filter finds it by domain or mailbox
    (FR-086). Failure reasons reuse values of the shared,
    append-only `AuditFailureReason`: `permissionDenied` (role, missing flag, read-only key or
    restricted session, told apart by `details.problemType`), `notFound`, `validationFailed`,
    `conflict`, `versionOutdated` and `temporaryFailure`. This feature appends none.
  - **Refusals are audited** (FR-085, "or tries to"), with the mechanism `docs/conventions.md`
    prescribes: 008's `App\Services\Audit\RefusalAuditor`, called from 001's problem rendering.
    Every state-changing route of this feature (creations, edits, deletions, assignment
    replacements, tests and bulk actions) declares `->defaults('restv2.audit', ['event' => …,
    'target' => …])`, and this feature registers one target resolver per target type (`relayhost`,
    `transport`, `bccMap`, `recipientMap`, `tlsPolicyMap`, `forwardingHost`, and the two assignment
    types, whose target id is the normalised domain name or address as sent). A `4xx` problem of an
    authenticated caller is then recorded once, whether this feature's code raised it or 001's
    pipeline did (read-only key, restricted session). `401` and CSRF failures are not recorded, and
    neither are refused reads. A whole-request bulk refusal gives one record with a `null` target
    and `details.itemIds`; per-item refusals are recorded by the bulk item handlers. Failure details
    follow 008: `problemType`, `status`, `errors` (member → rule codes, never values), plus
    `requiredFlags` for `permission-flag-required`.
- **Rationale**: FR-087 lists the error types clients act on. Validation errors keep the 422 form
  where the spec says "validation error" (host not resolvable, read-only attributes).

### R23. Performance (SC-006)

- **Decision**: Every list is one `SELECT` with `LIMIT/OFFSET` plus one `COUNT(*)` over the same
  filters, with derived-table joins for counts (R10, R18). Usable upstream indexes: `relayhosts`
  `hostname`, `transports` `destination` and `nexthop`, `bcc_maps` `local_dest`, `recipient_maps`
  `old_dest`, `tls_policy_override` `dest`. Sorting by other columns over 10,000 rows is a filesort
  of a few milliseconds. `tests/Feature/Routing/Performance/RoutingListLatencyTest.php` seeds 10,000
  rows per collection (and 10,000 mailboxes for relayhost usage) and asserts p95 < 1 s for a page of
  25, in the `performance` group.

### R24. Dependencies

- **Decision**: No new Composer package. The SMTP client, the DNS wrapper and the SPF parser use
  PHP built-ins (`stream_socket_client`, `stream_socket_enable_crypto`, `dns_get_record`,
  `inet_pton`, `idn_to_ascii`). The dev stack gains an optional `mailpit` service (`axllent/mailpit`,
  MIT, profile `routing`) as a manual test target for quickstart Part B. It is a development image,
  not a runtime dependency.

### R25. Dependencies on earlier features' code

- **Decision**: Features are implemented in number order. 009 relies on:
  - **008**: `App\Services\Audit\RefusalAuditor` (the refusal hook; created by 002 if it was
    first, per `docs/conventions.md`);
  - **001**: `ProblemException`, `ProblemType`, `ETag`, `ListQuery`, `ApiFormRequest`, the
    middleware (`restv2.auth:authenticated`, `restv2.csrf`, `restv2.pending`, `EnforceKeyScope`),
    `Principal`, `PermissionFlag`, `IdentityService`/`Scope`, `AuditLogger`, `MailcowConfig`,
    `OpenApiContract`, `MailcowFactory` and the models `Domain`, `Mailbox`,
    `DomainAdminAssignment` and `DaAcl`;
  - **002**: `App\Support\Domain\DomainName` (normalisation, FR-006 there), `App\Services\Domains\DomainScope`
    (domain administrator scope and the `404` for alias domains), the domain version tag
    (`DomainVersion`), `App\Clients\SharedStore\SharedStore` (Redis ping, `MULTI`, compensation),
    the models `Relayhost`, `AliasDomain` and `Alias` (read models that 009 extends), the
    `temporary-failure` problem type, and the `targetType`/`targetId` filters of `listAuditEvents`
    (FR-058 there), whose `targetType` already references `AuditTarget.type`;
  - **003**: `App\Services\Mailboxes\MailboxAddress` (normalisation, FR-007 there),
    `MailboxScope` (scope, resources answer `404`) and `MailboxVersion` (the mailbox version tag);
  - **004**: `App\Support\Bulk\BulkActionRunner` (at the latest);
  - **008**: the convention of an HMAC-keyed password inside version tags.

  When a named class does not exist yet, the task that needs it creates it in the location given
  here, and the earlier feature's plan wins on naming.
