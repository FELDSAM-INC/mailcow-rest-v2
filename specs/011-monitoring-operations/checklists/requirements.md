# Specification Quality Checklist: Monitoring and Operations

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

- Iteration 1 (specify): the draft built from allocation row 011 had three open questions: which
  containers may be stopped, how long-running operations report their outcome, and whether queue
  actions take several messages. The legacy coverage table and the parity table that constitution
  quality gate 3 requires were added from the legacy code (`functions.docker`, `functions.mailq`,
  `functions.fail2ban`, `get_logs`, `json_api.php`, `container_ctrl.php`, `update_check.php`, the
  dashboard, queue and fail2ban templates and scripts, the container service and the ban service).
- Iteration 2 (clarify, 2026-10-18): the user could not be asked, so ten material ambiguities were
  decided by the agent, in the order legacy behaviour, constitution, best practice. They are
  recorded under "Clarifications" and integrated:
  - administrators only, read-only admin keys may read everything (FR-001, FR-002, FR-004);
  - protected containers cannot be stopped, and the API's own container cannot be stopped or
    restarted (FR-015);
  - long-running operations are synchronous, bounded by the container-service timeout (FR-016,
    FR-042, FR-051);
  - destructive operations are marked in the contract and confirmed by the web app (FR-017, FR-042,
    FR-051, FR-055);
  - queue actions take up to 1,000 IDs with one result per ID and one audit record (FR-040, FR-062);
  - freshness limits, collection times and the 5-second polling recommendation, with no push channel
    (FR-006, FR-007, FR-010 to FR-012, FR-059);
  - log reads are not audited, queued message views are, and secrets in legacy logs are masked
    (FR-028, FR-062, FR-063);
  - ban settings, list entries and rules are validated and refused instead of corrected (FR-046,
    FR-047, FR-050);
  - the fork-only update check behaviour and login label are settings (FR-019, FR-030);
  - the ban settings version tag covers values, not the ban service's rewrites (FR-057).
- Iteration 3 (review): fixed the contradiction between "content views are audited" and the edge
  case for a view of a message that left the queue; bounded the audit retry of FR-064 by the
  container-service timeout so that SC-009 holds; marked the rule reset and the ban list rotation as
  destructive; made searches operate on masked log entries so that they cannot reveal secrets
  (FR-028); anchored the term "ban service" to the legacy "Fail2ban" name.
- The FeldHost fork was diffed against upstream `2026-09a` for every file in the parity reference.
  The logic is identical. The differences are the removed changelog link and the update check that
  never runs on the fork's branch (FR-019), the `WEBUI` login label (FR-030), and branding.
- The product is an HTTP API, so some names are part of the user-facing contract and not
  implementation details: the problem-details error format, RFC 3339, Markdown, the `base64,` log
  prefix, Postfix queue names and Rspamd action names. No framework, language or storage technology
  is named in the requirements. The parity and coverage tables cite legacy files, shared-store keys
  and commands, because constitution quality gate 3 requires legacy parity to be documented in the
  spec.
