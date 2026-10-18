# Specification Quality Checklist: Mailboxes

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

- Iteration 1 (specify): the spec was written from allocation row 003 after reading the legacy
  mailbox, ACL, hashing, ratelimit and SOGo-view code, the `json_api.php` routes, the mailbox Twig
  templates and `mailbox.js`, the schema in `init_db.inc.php`, the dockerapi commands and the en-gb
  messages. Two issues were found and fixed:
  - SC-001 named "database rows" and "shared-store entries". It now speaks of stored data and
    requests to the mail services.
  - FR-006 let a template's unlimited quota through for a domain administrator without the
    "unlimited quota" flag, which contradicted FR-023 and legacy. Unlimited quota is now gated
    whatever its source, and the matching edge case says so.
- Iteration 2 (clarify): ten decisions were recorded under "Clarifications" (session 2026-10-18)
  and integrated into the requirements, edge cases and parity table. No markers were left open.
  The taxonomy scan also found that FR-018 did not say how a template is identified (now: by its
  identifier).
- Iteration 3 (read-through): an edge case named Redis and now says "shared store". Legacy rename
  moves the `da_acl` row of a same-named domain-administrator account, which strips that account of
  its flags. FR-045 now leaves it alone, recorded as a deviation. Checklist re-validated: 16/16 →
  16/16 items passing, no state changes.
- Iteration 4: the cross-references to feature 002 were checked against its committed spec. Its tag
  requirements had been renumbered, so FR-036 now cites FR-043 and FR-044 there. FR-006, FR-008 and
  FR-009 there, and its page-size and "not found" rules (FR-011, FR-003), match.
- Iteration 5 (orchestrator decision on owner question 3): the spec now exposes the password policy
  read-only. The changes are:
  - FR-063: authorisation, legacy source and defaults, no caching, same values as FR-024,
    structured rules instead of HTML;
  - a row in the FR-005 authorisation table, a Legacy Coverage row for `get/passwordpolicy` (the
    edit stays in 013) and a parity row;
  - an eleventh clarification, recorded as an orchestrator decision;
  - acceptance scenario 11 of User Story 2, two edge cases and a key entity;
  - the Input and Assumptions now say that only editing the policy is out of scope.

  Re-validated: 16/16 → 16/16 items passing, no state changes. Spec totals: 63 FRs, 11 SCs.
- Iteration 6 (orchestrator follow-up): the FR-063 read now carries the version tag that feature
  013 edits the policy with (FR-008, FR-036 and FR-037 there), computed over the same effective
  values. Added a pointer in FR-038, a twelfth clarification (orchestrator decision) and a note in
  the parity row. Re-validated: 16/16 → 16/16 items passing, no state changes.
- Iteration 7 (orchestrator follow-up: API gaps found by the UI 003 spec, and `docs/conventions.md`):
  - FR-064: mailbox capabilities (identity provider type, groupware switch, last-login switch,
    limits, garbage-area retention);
  - FR-065: read-only deletion preview, writing no audit record;
  - FR-066: distinct mailbox tags in scope;
  - FR-067: optional per-item version tags in bulk actions, with the `outdated` result. FR-016 and
    FR-053 put the version tag on every list entry. The edge case saying that bulk actions never
    check tags was replaced.

  Alignments with the conventions:
  - creation follow-up steps that report their outcome and never remove the mailbox (FR-027);
  - "outcome unknown" on container API timeouts (FR-040, FR-048, FR-058);
  - legacy PHP settings as API server settings (FR-010, FR-022, FR-036);
  - no server-side breached-password check.

  Also added: five orchestrator-decision clarifications, coverage and parity rows, acceptance
  scenarios (User Story 2 #12 and #13, User Story 4 #6, User Story 5 #6, User Story 7 #6), edge
  cases and key entities. Re-validated: 16/16 → 16/16 items passing, no state changes. Spec
  totals: 67 FRs, 11 SCs.
- The FeldHost fork was diffed against upstream `2026-09a` for every file in the parity reference.
  Mailbox logic is identical. The only differences are display labels (authentication source
  "mailcow", `pw_reset` permission label), which do not change the contract.
- The product is an HTTP API, so some names are part of the user-facing contract or of the
  operator's existing configuration and are not implementation details: the problem-details error
  format, mailcow's configuration names (`MAILCOW_PASS_SCHEME`, `TAGGING_LIMIT`,
  `SHOW_LAST_LOGIN`, `SKIP_SOGO`, `MAILDIR_GC_TIME`, `MAILBOX_DEFAULT_ATTRIBUTES`), the stored
  permission flag names and the hash-scheme prefixes that Dovecot verifies. No framework, language
  or storage technology is chosen.
- The constitution (quality gate 3) requires legacy parity to be documented in the spec, so the
  coverage and parity tables cite legacy files, tables, keys and commands. Requirements state only
  observable behaviour.
- Items marked incomplete require spec updates before `/speckit-clarify` or `/speckit-plan`
