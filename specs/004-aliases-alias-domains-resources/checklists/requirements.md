# Specification Quality Checklist: Aliases, Alias Domains, Resources and Sender Permissions

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

- Iteration 1 (specify): three clarification markers were open (FR-027: whether destinations already
  on an alias are re-checked against the external-destination rules; FR-028: partial saving of
  invalid entries; FR-058: bulk actions, which feature 002 does not offer). The legacy coverage table
  and the parity table that constitution quality gate 3 and the roadmap require were missing, so the
  mandatory sections were incomplete. FR-028 had no requirement text, so its acceptance criteria were
  missing.
- Iteration 2 (clarify, 2026-10-18): the user could not be asked, so ten material ambiguities were
  decided by the agent, in the order legacy behaviour, constitution, best practice. They are
  recorded under "Clarifications" and integrated:
  - invalid or refused entries refuse the whole request (FR-028);
  - only destinations a request adds are checked against the external-destination rules (FR-027);
  - bulk actions for the legacy mass actions, single-object creation (FR-020, FR-040, FR-058,
    FR-059);
  - aliases are addressed by their identifier (FR-011, FR-016, FR-018);
  - sender settings are a separate view of the mailbox with set replacement (FR-053 to FR-056);
  - alias domain creation applies the rate limit and generates a DKIM key, both read-only afterwards
    (FR-040, FR-042, FR-043);
  - alias domain deletion and creation fix the legacy cleanup defects (FR-042, FR-045);
  - resource addresses are derived from the description and never change (FR-046, FR-048);
  - the alias limit also applies to moves, copies and alias domain target changes (FR-022, FR-030,
    FR-037, FR-044);
  - the `alias_domains` flag gates creation only (FR-005, FR-006).

  The legacy coverage table and the parity table with the fork differences were added.
- Iteration 3 (review): the following were fixed:
  - FR-024 pointed only to FR-033 for follow-up steps;
  - FR-029 treated the kind and domains as read-only although they follow from the address;
  - FR-054 and FR-055 did not say that one request may change both sets of sender settings, which
    acceptance scenario 5 of user story 5 needs;
  - FR-054 did not say what happens to "any address" set by an administrator when a domain
    administrator saves.

  The spec has FR-001 to FR-067 and SC-001 to SC-010, without gaps, and every cross-reference was
  checked.
- The alias, alias domain, resource and sender permission files of the FeldHost fork were diffed
  against upstream `2026-09a`. The logic is identical. The differences are the pre-filled DKIM
  selector, the ISPConfig DKIM DNS push and branding texts. The external-destination flag and
  server switch are upstream features, not fork-only ones.
- The product is an HTTP API, so some names are part of the user-facing contract and not
  implementation details. These are the problem-details error format, UTS #46, and the legacy
  permission flag names. No framework, language or storage technology is named in the requirements.
- The constitution (quality gate 3) requires legacy parity to be documented in the spec. The parity
  and coverage tables therefore cite legacy source files, tables and shared-store keys.
  Requirements state only observable behaviour.
