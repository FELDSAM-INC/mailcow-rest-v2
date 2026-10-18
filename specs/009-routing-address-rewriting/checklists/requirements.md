# Specification Quality Checklist: Routing and Address Rewriting

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-10-18
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] No implementation details (languages, frameworks, APIs)
- [x] Focused on user value and business needs
- [x] Written for non-technical stakeholders
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain
- [x] Requirements are testable and unambiguous
- [x] Success criteria are measurable
- [x] Success criteria are technology-agnostic (no implementation details)
- [x] All acceptance scenarios are defined
- [x] Edge cases are identified
- [x] Scope is clearly bounded
- [x] Dependencies and assumptions identified

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria
- [x] User scenarios cover primary flows
- [x] Feature meets measurable outcomes defined in Success Criteria
- [x] No implementation details leak into specification

## Notes

- Iteration 1 (specify): the spec was written from allocation row 009 after reading the legacy code
  (`functions.transports.inc.php`, `functions.address_rewriting.inc.php`,
  `functions.tls_policy_maps.inc.php`, `functions.fwdhost.inc.php`, `inc/spf.inc.php`,
  `inc/ajax/transport_check.php`, the relayhost parts of `functions.mailbox.inc.php`, `json_api.php`,
  `edit.php`, the Twig templates and JavaScript of the routing, forwarding host, BCC and TLS policy
  screens, the schema in `init_db.inc.php`) and its consumers (the Postfix SQL maps in
  `Dockerfiles/postfix/postfix.sh`, `main.cf`, `master.cf`, the Rspamd BCC and keep-spam code, the
  internal `bcc.php` and `forwardinghosts.php`). No clarification markers were needed; the open
  decisions were taken in the clarify phase.
- Iteration 2 (clarify, 2026-10-18): the user could not be asked, so ten material ambiguities were
  decided by the agent, in the order legacy behaviour, constitution, best practice. They are recorded
  under "Clarifications" and integrated:
  - passwords are write-only and stored without the legacy colon escaping (FR-012 to FR-014);
  - domain administrators with `domain_relayhost` can change a domain's relayhost (FR-029);
  - assignments have their own operations; domain administrators get a reduced relayhost list
    (FR-023 to FR-028);
  - deleting an assigned relayhost clears domain and mailbox assignments (FR-021);
  - legacy mass actions are bulk actions, creation is one entry per request (FR-034, FR-090,
    FR-091);
  - transport credentials are shared per next hop with inheritance and adoption (FR-038);
  - creation defaults follow the legacy forms (FR-018, FR-034, FR-049, FR-061, FR-067, FR-076);
  - connection tests return a structured, redacted result and count as state-changing (FR-041 to
    FR-045);
  - BCC local destinations and the paginated choice list (FR-050, FR-057);
  - forwarding host resolution, atomicity, overwrite and canonical form (FR-076 to FR-078).
- Iteration 3 (review): fixed the wrong claim that `*` transports leave hosted domains alone (only
  `localhost` addresses are exempt, per `local_transport` and the legacy hint); added the contract
  notes for transports (FR-040); clarified next-hop parsing of bare IPv6 addresses (FR-019) and
  password-only creations for an existing next hop (FR-038). Feature 002 changed its mass-action
  decision to bulk actions while this spec was written; this spec was aligned (FR-005, FR-085,
  FR-090, FR-091, edge cases, parity table) with the pattern of features 002 to 004.
- The routing-related files of the FeldHost fork were diffed against upstream `2026-09a`. The logic,
  templates, JavaScript, schema and mail server and filter consumers are identical; only display texts
  differ (parity section).
- The product is an HTTP API, so some names are part of the user-facing contract and not
  implementation details: the problem-details error format, UTS #46, RFC 3339, SPF, STARTTLS and the
  TLS policy names of the mail server. No framework, language or storage technology is named in the
  requirements.
- The constitution (quality gate 3) requires legacy parity to be documented in the spec. The parity
  and coverage tables therefore cite legacy source files, tables, shared-store keys and mail server
  maps. Requirements state only observable behaviour.
