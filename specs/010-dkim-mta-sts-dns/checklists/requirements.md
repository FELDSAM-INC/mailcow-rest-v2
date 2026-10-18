# Specification Quality Checklist: DKIM, MTA-STS and DNS Diagnostics

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

- Iteration 1 (specify): the spec was written from allocation row 010 after reading
  `functions.dkim.inc.php`, `inc/lib/ispconfig.php`, the MTA-STS cases of `functions.mailbox.inc.php`,
  `mta-sts.php`, `inc/ajax/dns_diagnostics.php`, `spf.inc.php`, the `json_api.php` routes, the DKIM,
  domain and modal templates, `mailbox.js`, `admin/system.php`, the `mta_sts` schema, Rspamd's DKIM and
  ARC configuration and the certificate service. No clarification markers were needed, because each
  open point had a legacy answer or was decided in the clarify pass below. The legacy coverage table
  and the parity table that constitution quality gate 3 requires were included from the start.
- Iteration 2 (clarify, 2026-10-18): the user could not be asked, so ten material ambiguities were
  decided by the agent, in the order legacy behaviour, constitution, best practice. They are recorded
  under "Clarifications" and integrated:
  - private key export: administrators only, behind the `SHOW_DKIM_PRIV_KEYS` setting, audited
    (FR-026, FR-027);
  - domain administrators may generate keys for their own names, as the legacy API allows (FR-005,
    FR-013);
  - the fork's ISPConfig publication covers every key change, reports outcomes and can be repeated
    (FR-028 to FR-031);
  - import and copy overwrite only on request and remove the old key completely (FR-020, FR-022);
  - diagnostics returns structured statuses and a zone file, not HTML (FR-043 to FR-047);
  - diagnostics time limits and per-caller throttling (FR-048, FR-049);
  - public addresses from the echo service or operator configuration (FR-042);
  - MTA-STS policies only for domains; alias domains publish their target's policy (FR-032);
  - no MTA-STS deletion, creation defaults, one maximum-age limit (FR-033, FR-037);
  - the fork's SPF suggestion becomes a setting (FR-050).
- Iteration 3 (review): fixed a contradiction between "deletions respond without a body" and "the
  publication outcome is returned with the key change" (FR-024, FR-030, User Story 7 scenario 4);
  restricted the domain-name rule reused for import names and MTA-STS MX hosts to feature 002's syntax
  rules, so that existence and host-name checks do not apply (FR-018, FR-033); aligned the MTA-STS TXT
  spelling (FR-036, FR-040); defined "informational" for found records only (FR-044); added "not
  found" rules for export and republish (FR-026, FR-031), the API-key rule for export, and a list
  performance criterion (SC-012).
- The fork-relevant legacy files were diffed against upstream `2026-09a`. The differences are the
  default selector `feldhost`, the ISPConfig push in `dkim('add')` and `dkim('delete')`, and the SPF
  suggestion in DNS diagnostics; all three are explicit settings now (parity table).
- The product is an HTTP API that manages DNS and DKIM data, so some names are part of the
  user-facing contract and not implementation details: DNS record types, the DKIM and MTA-STS record
  syntax, RSA and PEM key formats, UTS #46 and the RFCs cited. No framework, language or storage
  technology is named in the requirements. As in feature 002, the parity and coverage tables cite
  legacy source files and shared-store keys, because constitution quality gate 3 requires parity to
  be documented in the spec.
