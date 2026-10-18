# Implementation Plan: Routing and Address Rewriting

**Branch**: `main` (no feature branches, constitution 1.1.0) | **Date**: 2026-10-18 |
**Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/009-routing-address-rewriting/spec.md`

## Summary

Feature 009 lets administrators manage how mailcow sends and rewrites mail, and lets domain
administrators do the parts their permission flags allow. It covers:

- relayhosts (legacy "sender-dependent transports") with write-only credentials, usage and choices;
- relayhost assignments of domains and mailboxes, with a mailbox's effective relayhost;
- transport maps with credentials shared per next hop;
- connection tests of relayhosts and transports, with a redacted transcript;
- BCC maps and their local destination choices;
- recipient maps and TLS policy maps;
- forwarding hosts in mailcow's Redis, resolved through SPF, MX and A/AAAA records;
- bulk actions for every legacy mass action, and a routing capabilities read.

The technical approach:

- The contract (46 operations in 8 modules) was authored first under `api/modules/` and lints
  clean.
- The legacy functions are ported in services under `app/Services/Routing/`. They write exactly the
  upstream columns and Redis hashes that Postfix and Rspamd read: `relayhosts`, `transports`,
  `bcc_maps`, `recipient_maps`, `tls_policy_override`, `domain.relayhost`,
  `mailbox.attributes.relayhost`, `WHITELISTED_FWD_HOST` and `KEEP_SPAM`. No service is reloaded,
  as in legacy.
- The spec's deviations are implemented as stated: write-only and unescaped credentials,
  syntax-checked next hops, atomic relayhost deletion, inheritance instead of wiping of shared
  transport credentials, saved domain administrator assignments, the orphaned BCC flag and
  all-or-nothing forwarding hosts.
- Uniqueness that upstream does not index is enforced with MariaDB named locks.
- The connection test is a small SMTP client of this project, with step deadlines and redaction at
  the source.
- DNS goes through a thin `dns_get_record()` wrapper that tells "no records" from a temporary
  failure.
- Tests come first, on MariaDB with the `2026-09a` schema fixture and on a real Redis. The SMTP
  client is tested against a scripted local server. Feature tests fake SMTP and DNS behind
  interfaces, and every request and response is validated against the contract.

## Technical Context

**Language/Version**: PHP 8.4, Laravel 13.x (decided in feature 001, research R1 and R4).

**Primary Dependencies**: Those of feature 001. No new Composer package (research R24): the SMTP
client, the DNS wrapper and the SPF parser use PHP built-ins (`stream_socket_client`,
`stream_socket_enable_crypto`, `dns_get_record`, `inet_pton`, `idn_to_ascii` from `intl`). The dev
stack gains an optional `axllent/mailpit` service (MIT, profile `routing`) for manual tests.

**Storage**:

- mailcow's MariaDB 10.11 (schema `2026-09a`, `db_version 18082026_1200`): reads and writes of
  `relayhosts`, `transports`, `bcc_maps`, `recipient_maps`, `tls_policy_override`, the `relayhost`
  column of `domain` and the `$.relayhost` attribute of `mailbox`; reads of `alias`,
  `alias_domain`, `domain_admins` and `da_acl`.
- mailcow's Redis (connection `mailcow`, database 0): the hashes `WHITELISTED_FWD_HOST` and
  `KEEP_SPAM`.
- No API-owned table or Redis key. Audit records go to 001's `restv2_audit_events`.

See [data-model.md](./data-model.md).

**Testing**: PHPUnit 12 feature and unit tests, written first, inside the dev container, on MariaDB
with `tests/fixtures/schema/2026-09a.sql` and on a real Redis (001 R3). `OpenApiContract` validates
every request and response, and the coverage check must list all 46 operations.
`FakeSmtpProbe` and `FakeDnsResolver` are bound in feature tests. `StreamSmtpProbe` is tested against
`tests/Support/Smtp/ScriptedSmtpServer.php`, and the SPF resolver against `FakeDnsResolver` record
sets.

**Target Platform**: The API container of feature 001 (`php:8.4-fpm-alpine`, non-root, read-only
root filesystem). Two deployment changes:

- `deploy/docker-compose.override.yml` gets `dns: ["${IPV4_NETWORK:-172.22.1}.254"]`, mailcow's
  unbound, as php-fpm-mailcow has, and `dns_opt: ["timeout:2", "attempts:2"]` (research R15).
- `deploy/nginx/site.restv2.custom` raises `fastcgi_read_timeout` to 90 s for the 60 s connection
  test (research R13).

**Project Type**: web service (REST API).

**Performance Goals**:

- SC-006: a page of 25 entries in under 1 s at p95 for 10,000 entries per collection, through one
  query plus one count with derived-table joins, never a lookup per row (research R10, R23).
- SC-007: connection tests answer within 60 s (step deadline 15 s, overall deadline 60 s).
- SC-005: changes apply to the next message, because Postfix and Rspamd read the tables and hashes
  per lookup.

**Constraints**:

- No upstream table, view or column is created or altered. No unique index can be added, hence the
  named locks (research R9).
- Passwords never leave the database through the API, logs or audit records, and they enter
  version tags only as an HMAC (research R2, R4).
- Legacy UI and `/api/v1` keep working on the same rows, and changes made there show in the next
  response.
- Every dependency must be GPL-3.0-compatible.

**Scale/Scope**: Up to about 10,000 entries per routing collection and 10,000 mailboxes. The feature
has 46 operations in 8 modules, 9 user stories and 93 functional requirements.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design (below).*

| Principle | Gate | Status |
|---|---|---|
| I. Contract-first | All 46 operations are described under `api/modules/{relayhosts,relayhost-assignments,transports,bcc-maps,recipient-maps,tls-policy-maps,forwarding-hosts,routing-capabilities}/` before any code. `redocly lint` passes. New components carry their resource's name. The shared enumerations were appended to, never rewritten. Every test validates against the bundle, and the coverage check includes the new operations. | PASS |
| II. HTTP contract | Plural nouns. Actions are sub-resources (`…/connection-test`, `…/bulk-actions`). PATCH is JSON Merge Patch; assignments use PUT (whole-value replacement). Codes: 201 with `Location` for creations (forwarding hosts: 201, or 200 when every address already existed), 200 for reads, updates and tests, 204 for deletions. `{data, meta}` lists. RFC 9457 problems, with `errors` on 422. `ETag` and `If-Match` on every entry. camelCase, booleans and UTC times. The optional `meta` on transport writes and the `503` are recorded under Complexity Tracking. | PASS (justified) |
| III. Upstream schema | No migration. Uniqueness without upstream indexes uses `GET_LOCK` (research R9). The `forwarding_hosts` table stays unused, as in legacy. | PASS |
| IV. Parity | The legacy code (`functions.transports.inc.php`, `functions.address_rewriting.inc.php`, `functions.tls_policy_maps.inc.php`, `functions.fwdhost.inc.php`, `spf.inc.php`, `transport_check.php`, the relayhost parts of `functions.mailbox.inc.php`) and its consumers (the Postfix SQL maps of `postfix.sh`, `main.cf`, the Rspamd BCC and keep-spam code, `forwardinghosts.php`) were read. Every write goes through a service in `app/Services/Routing/`. The deviations are those of the spec's parity table, plus the plan-level rows this plan adds (see "Parity notes added to the spec"). | PASS |
| V. Coexistence | No change to other mailcow containers. The override gains only the `dns` entry of the API's own service. No internal endpoint is taken over: Rspamd keeps reading `bcc.php` and `forwardinghosts.php`. | PASS |
| VI. Security | Object-level authorisation for admins and domain admins on every operation, with the flag check before the scope lookup. Out-of-scope objects answer `404`. Read-only keys are refused on tests and writes. Passwords are write-only, enter version tags only as an HMAC, and are redacted from transcripts at the source. Every state change and test is audited in the same transaction; refused attempts by authenticated callers are recorded through 008's `RefusalAuditor` (route default `restv2.audit`). | PASS |
| VII. Test-first | `tasks.md` orders tests first in every phase. Tests run on MariaDB with the real schema and a real Redis. SMTP and DNS are faked behind interfaces, and the fakes assert the calls. | PASS |
| VIII. Simplicity | No new package. Two small built-in-based clients (`SmtpProbe`, `DnsResolver`). Reused from earlier features: 002's `SharedStore`, `DomainName`, `DomainScope`; 003's `MailboxAddress`, `MailboxScope`; the shared `BulkActionRunner`. `NamedLock` exists because there are three concrete uses (transports, BCC maps, recipient maps). | PASS |
| Cross-feature conventions | Capabilities at `/capabilities/routing` and helper lists on top-level paths (`/relayhost-choices`, `/bcc-destination-choices`). History through the generic `targetType`/`targetId` filters, with eight `AuditTarget` values and no own filter. `AuditFailureReason` values reused. HMAC for passwords inside version tags. `GET_LOCK` for uniqueness upstream does not index. `404` outside scope. Bulk actions of at most 100 with per-item version tags and outcomes. Strong ETags over stored settings, on every list entry. Write-only secrets. Legacy defaults as `RESTV2_*` server settings, read through the capabilities operation. Shared components (`BulkTarget`, `BulkResultResponse`, `Search`, `Limit`, `Offset`, `Order`, `ListMeta`) and 002/003's `DomainName` and `MailboxAddress` parameters are reused. Shared codes are reused: `immutable`, `invalidRegex`, `temporary-failure`. | PASS |

**Post-design re-check (after Phase 1)**: PASS. The contract, data model and research introduce no
unjustified violation. The design decisions that touched gates:

- Passwords enter version tags only as an HMAC (as in 008): VI.
- Named locks instead of indexes: III.
- The connection test inside the API container, with no dockerapi: V and the spec's assumption.
- The `dns` entry and the nginx timeout in the API's own deployment files: V.

## Parity notes added to the spec

The spec's parity table gains these plan-level rows (constitution IV requires every deviation to
be stated in the spec):

1. **Test login mechanisms**: the connection test logs in with PLAIN, then LOGIN, as the mail server
   does (`smtp_sasl_mechanism_filter = plain, login`). Legacy's PHPMailer prefers CRAM-MD5.
2. **Test transcript**: the message content is one line `[message: N bytes]`, server text is
   sanitised, and the transcript is cut to 500 lines of at most 1,000 characters.
3. **SPF parsing**: all character strings of a TXT record are concatenated, and `a` and `mx`
   accept their CIDR forms (`a/24`, `mx//64`). Legacy reads only the first string and ignores those
   forms.

## Contract Operations

| Method and path | operationId | Auth (admin / domain admin) | Story / FR |
|---|---|---|---|
| GET `/relayhosts` | listRelayhosts | admin | US1, FR-015, FR-016 |
| POST `/relayhosts` | createRelayhost | admin, write | US1, FR-012–FR-014, FR-018, FR-019 |
| GET `/relayhost-choices` | listRelayhostChoices | admin / DA with `domainRelayhost` or `mailboxRelayhost` | US1, US2, FR-023 |
| POST `/relayhosts/bulk-actions` | applyRelayhostBulkAction | admin, write | US1, FR-090, FR-091 |
| GET `/relayhosts/{relayhostId}` | getRelayhost | admin | US1, FR-017 |
| PATCH `/relayhosts/{relayhostId}` | updateRelayhost | admin, write | US1, FR-009, FR-020 |
| DELETE `/relayhosts/{relayhostId}` | deleteRelayhost | admin, write | US1, FR-021 |
| GET `/relayhosts/{relayhostId}/usage` | listRelayhostUsage | admin | US1, FR-022 |
| POST `/relayhosts/{relayhostId}/connection-test` | testRelayhostConnection | admin, write | US4, FR-041–FR-045 |
| GET `/domains/{domain}/relayhost-assignment` | getDomainRelayhostAssignment | admin / DA own domains | US2, FR-024, FR-030 |
| PUT `/domains/{domain}/relayhost-assignment` | replaceDomainRelayhostAssignment | admin / DA with `domainRelayhost`, write | US2, FR-026–FR-029 |
| GET `/mailboxes/{mailbox}/relayhost-assignment` | getMailboxRelayhostAssignment | admin / DA own mailboxes | US2, FR-024, FR-025, FR-030 |
| PUT `/mailboxes/{mailbox}/relayhost-assignment` | replaceMailboxRelayhostAssignment | admin / DA with `mailboxRelayhost`, write | US2, FR-026–FR-029 |
| GET `/transports` | listTransports | admin | US3, FR-031, FR-032 |
| POST `/transports` | createTransport | admin, write | US3, FR-034–FR-038 |
| POST `/transports/bulk-actions` | applyTransportBulkAction | admin, write | US3, FR-090, FR-091 |
| GET `/transports/{transportId}` | getTransport | admin | US3, FR-033 |
| PATCH `/transports/{transportId}` | updateTransport | admin, write | US3, FR-038, FR-039 |
| DELETE `/transports/{transportId}` | deleteTransport | admin, write | US3, FR-040 |
| POST `/transports/{transportId}/connection-test` | testTransportConnection | admin, write | US4, FR-041–FR-045 |
| GET `/bcc-maps` | listBccMaps | admin / DA scope | US5, FR-046, FR-047, FR-056 |
| POST `/bcc-maps` | createBccMap | admin / DA with `bccMaps`, write | US5, FR-049–FR-053 |
| GET `/bcc-destination-choices` | listBccDestinationChoices | admin / DA scope | US5, FR-057 |
| POST `/bcc-maps/bulk-actions` | applyBccMapBulkAction | admin / DA with `bccMaps`, write | US5, FR-090, FR-091 |
| GET `/bcc-maps/{bccMapId}` | getBccMap | admin / DA scope | US5, FR-048 |
| PATCH `/bcc-maps/{bccMapId}` | updateBccMap | admin / DA with `bccMaps`, write | US5, FR-053, FR-054 |
| DELETE `/bcc-maps/{bccMapId}` | deleteBccMap | admin / DA with `bccMaps`, write | US5, FR-055 |
| GET `/recipient-maps` | listRecipientMaps | admin | US7, FR-059 |
| POST `/recipient-maps` | createRecipientMap | admin, write | US7, FR-061, FR-062 |
| POST `/recipient-maps/bulk-actions` | applyRecipientMapBulkAction | admin, write | US7, FR-090 |
| GET `/recipient-maps/{recipientMapId}` | getRecipientMap | admin | US7, FR-060 |
| PATCH `/recipient-maps/{recipientMapId}` | updateRecipientMap | admin, write | US7, FR-063 |
| DELETE `/recipient-maps/{recipientMapId}` | deleteRecipientMap | admin, write | US7, FR-064 |
| GET `/tls-policy-maps` | listTlsPolicyMaps | admin | US6, FR-065 |
| POST `/tls-policy-maps` | createTlsPolicyMap | admin, write | US6, FR-067–FR-070 |
| POST `/tls-policy-maps/bulk-actions` | applyTlsPolicyMapBulkAction | admin, write | US6, FR-090 |
| GET `/tls-policy-maps/{tlsPolicyMapId}` | getTlsPolicyMap | admin | US6, FR-066 |
| PATCH `/tls-policy-maps/{tlsPolicyMapId}` | updateTlsPolicyMap | admin, write | US6, FR-071 |
| DELETE `/tls-policy-maps/{tlsPolicyMapId}` | deleteTlsPolicyMap | admin, write | US6, FR-072 |
| GET `/forwarding-hosts` | listForwardingHosts | admin | US8, FR-074 |
| POST `/forwarding-hosts` | createForwardingHost | admin, write | US8, FR-076–FR-078, FR-082 |
| POST `/forwarding-hosts/bulk-actions` | applyForwardingHostBulkAction | admin, write | US8, FR-090 |
| GET `/forwarding-hosts/{forwardingHostAddress}` | getForwardingHost | admin | US8, FR-075 |
| PATCH `/forwarding-hosts/{forwardingHostAddress}` | updateForwardingHost | admin, write | US8, FR-079 |
| DELETE `/forwarding-hosts/{forwardingHostAddress}` | deleteForwardingHost | admin, write | US8, FR-080, FR-082 |
| GET `/capabilities/routing` | getRoutingCapabilities | admin (all) / DA (reduced) | US4, FR-093 |

"Admin" means a signed-in administrator or a key owned by one; reads accept read-only keys. "Write"
means a session with the anti-forgery token or a read-write key. Mailbox users get
`403 forbidden` everywhere. US9 (tracing) uses 001's `listAuditEvents` with 002's target filters,
fed by the audit records of every operation above (FR-085, FR-086).

## Project Structure

### Documentation (this feature)

```text
specs/009-routing-address-rewriting/
├── spec.md              # Feature specification (with clarifications and parity table)
├── plan.md              # This file
├── research.md          # Phase 0: decisions R1–R25
├── data-model.md        # Phase 1: upstream tables, Redis hashes, audit events
├── quickstart.md        # Phase 1: validation guide
├── contracts/README.md  # Phase 1: pointer to api/ (the contract is not duplicated)
├── checklists/requirements.md
├── tasks.md             # Phase 2 (/speckit-tasks)
└── analysis.md          # /speckit-analyze summary
```

### Source Code (repository root)

The layout is 001's. This feature adds:

```text
api/
├── modules/{relayhosts,relayhost-assignments,transports,bcc-maps,recipient-maps,
│            tls-policy-maps,forwarding-hosts,routing-capabilities}/   # 46 operations
└── components/
    ├── parameters/   # RelayhostId, TransportId, BccMapId, RecipientMapId, TlsPolicyMapId,
    │                 # ForwardingHostAddress, *Sort
    ├── responses/    # Relayhost*, Transport*, BccMap*, BccDestinationChoice*, RecipientMap*,
    │                 # TlsPolicyMap*, ForwardingHost*, RoutingConnectionTest*, RoutingCapabilities*,
    │                 # RoutingConflict, RoutingForbidden, RoutingTemporaryFailure, …Assignment*
    └── schemas/      # Relayhost*, RelayhostReference, Domain/MailboxRelayhostAssignment, Transport*,
                      # BccMap*, BccDestinationChoice*, RecipientMap*, TlsPolicy*, ForwardingHost*,
                      # RoutingConnectionTest*, RoutingCapabilities, RoutingConflictProblem,
                      # RoutingPermissionFlagProblem

app/
├── Clients/
│   ├── Smtp/{SmtpProbe,StreamSmtpProbe,SmtpProbeRequest,SmtpProbeResult,SmtpTranscript}.php
│   └── Dns/{DnsResolver,NativeDnsResolver,DnsTemporaryFailure}.php
├── Http/
│   ├── Controllers/Api/V2/
│   │   ├── RelayhostController.php            # index, show, store, update, destroy
│   │   ├── RelayhostChoiceController.php      # index
│   │   ├── RelayhostUsageController.php       # index
│   │   ├── RelayhostBulkActionController.php  # store
│   │   ├── RelayhostConnectionTestController.php
│   │   ├── DomainRelayhostAssignmentController.php   # show, update
│   │   ├── MailboxRelayhostAssignmentController.php  # show, update
│   │   ├── TransportController.php, TransportBulkActionController.php,
│   │   │   TransportConnectionTestController.php
│   │   ├── BccMapController.php, BccMapBulkActionController.php,
│   │   │   BccDestinationChoiceController.php
│   │   ├── RecipientMapController.php, RecipientMapBulkActionController.php
│   │   ├── TlsPolicyMapController.php, TlsPolicyMapBulkActionController.php
│   │   ├── ForwardingHostController.php, ForwardingHostBulkActionController.php
│   │   └── RoutingCapabilitiesController.php
│   ├── Requests/Api/V2/
│   │   ├── List{Relayhosts,RelayhostChoices,RelayhostUsage,Transports,BccMaps,
│   │   │        BccDestinationChoices,RecipientMaps,TlsPolicyMaps,ForwardingHosts}Request.php
│   │   ├── Store{Relayhost,Transport,BccMap,RecipientMap,TlsPolicyMap,ForwardingHost}Request.php
│   │   ├── Update{Relayhost,Transport,BccMap,RecipientMap,TlsPolicyMap,ForwardingHost}Request.php
│   │   ├── ReplaceRelayhostAssignmentRequest.php
│   │   ├── RoutingConnectionTestRequest.php
│   │   └── RoutingBulkActionRequest.php        # one class, per-collection action list
│   └── Resources/Api/V2/
│       ├── {Relayhost,RelayhostChoice,RelayhostUsageEntry,DomainRelayhostAssignment,
│       │    MailboxRelayhostAssignment,Transport,BccMap,BccDestinationChoice,RecipientMap,
│       │    TlsPolicyMap,ForwardingHost,ForwardingHostCreationResult,
│       │    RoutingConnectionTestResult,RoutingCapabilities}Resource.php
├── Models/Mailcow/{Transport,BccMap,RecipientMap,TlsPolicyOverride}.php   # + Relayhost (002's, extended)
├── Policies/RoutingPolicy.php                  # role, flag and scope decisions of FR-005
├── Rules/{NextHopRule,TransportDestinationRule,SmtpUsername,RoutingEmail,
│          RecipientMapDestinationRule,TlsPolicyDestinationRule,TlsPolicyParametersRule}.php
├── Services/Routing/
│   ├── RelayhostService.php, RelayhostQuery.php
│   ├── RelayhostAssignmentService.php, RelayhostReference.php
│   ├── TransportService.php, TransportQuery.php
│   ├── ConnectionTestService.php
│   ├── BccMapService.php, BccMapQuery.php, BccDestinationResolver.php, BccDestinationChoiceQuery.php
│   ├── RecipientMapService.php, TlsPolicyMapService.php
│   ├── ForwardingHostService.php, ForwardingHostStore.php (on 002's SharedStore),
│   │   ForwardingHostResolver.php, SpfHostCollector.php
│   ├── BccScope.php                            # domain administrator scope condition for BCC maps
│   ├── RoutingAudit.php                        # event, target and details builders, refusal audit
│   ├── RoutingBulkActions.php                  # per-collection item handlers for Support/Bulk/BulkActionRunner
│   └── RoutingCapabilitiesService.php
└── Support/
    ├── Database/NamedLock.php
    └── Routing/{NextHop,TransportDestination,IpNetworkLiteral,CredentialFingerprint}.php

config/restv2.php                               # gains routing.* settings (research R21)
routes/api.php                                  # gains the 46 routes, literals before {id}
deploy/docker-compose.override.yml              # gains dns and dns_opt (research R15)
deploy/nginx/site.restv2.custom                 # fastcgi_read_timeout 90s (research R13)
compose.yaml                                    # gains optional mailpit (profile routing)
scripts/legacy/extract-routing-maps.sh          # Postfix/Rspamd parity queries of a tag

tests/
├── Support/
│   ├── Smtp/{FakeSmtpProbe,ScriptedSmtpServer}.php, Smtp/scripted-smtp-server.php
│   ├── Dns/FakeDnsResolver.php
│   └── Mailcow/MailcowFactory.php              # gains relayhost(), transport(), bccMap(), …
├── fixtures/legacy/routing-maps/2026-09a/*.sql  # Postfix and Rspamd queries for parity tests
├── Unit/
│   ├── Routing/{NextHopTest,IpNetworkLiteralTest,CredentialFingerprintTest,RoutingRulesTest,
│   │            RoutingBulkActionRequestTest,SpfHostCollectorTest}.php
│   └── Clients/{StreamSmtpProbeTest,NativeDnsResolverTest}.php
└── Feature/Routing/
    ├── Support/ (NamedLock, RoutingPolicy, RoutingAudit), Relayhosts/, RelayhostAssignments/,
    ├── Transports/, ConnectionTests/, BccMaps/, RecipientMaps/, TlsPolicyMaps/, ForwardingHosts/,
    └── Capabilities/, Audit/, Security/, Performance/, LegacyCoexistenceTest.php
```

**Structure Decision**: One Laravel project, as in 001. Business rules live in
`app/Services/Routing/`, one service per aggregate, and each owns its writes and audit records.
Read queries with derived counts sit next to them as `*Query` classes. Parsing and normalisation
without side effects live in `app/Support/Routing/`. Outbound SMTP and DNS, and the Redis hashes,
sit behind `app/Clients/`. Controllers stay thin.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Optional `meta` next to `data` in single-resource responses of `createTransport` and `updateTransport` (constitution II: "one project-wide shape" for single resources) | FR-038 requires the response to name the other transports whose credentials changed. That is an effect of the request, not a property of the transport, and must not enter its version tag. | Adding the list to `Transport` would make a request-specific value part of the resource. A response header is invisible to generated clients. A follow-up read cannot tell which transports this request changed. |
| Status `503` (not in constitution II's error list) for `temporary-failure` | FR-082 and the DNS edge case need a distinct, retryable error when Redis or DNS is temporarily unavailable, or a write lock cannot be acquired. 002 introduced the same type. | `500` cannot be told apart from a bug, so clients would not retry. A `422` would wrongly blame the input. |
| Forwarding host creation answers `200` when every address already existed | FR-078 requires "created" only when at least one entry was added, and otherwise the updated entries. | Always `201` would claim a creation that did not happen. |
| Connection tests hold a php-fpm worker for up to 60 s | FR-042 and SC-007 make the test one request bounded by 60 s, as in legacy. | A background job with polling adds state and an endpoint the spec does not ask for. Tests are rare administrator actions. |
