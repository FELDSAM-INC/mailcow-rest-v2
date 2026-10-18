# Specification Quality Checklist: Domains

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

- Iteration 1 (specify): two clarification markers were open (FR-003: response for objects outside
  the caller's scope; FR-024: how follow-up step outcomes of a creation are reported). The legacy
  coverage table and the parity table that constitution quality gate 3 requires were missing.
  FR-016 pointed to the wrong requirement for version tags.
- Iteration 2 (clarify, 2026-10-18): the user could not be asked, so ten material ambiguities were
  decided by the agent, in the order legacy behaviour, constitution, best practice. They are
  recorded under "Clarifications" and integrated:
  - out-of-scope objects are "not found" (FR-003);
  - creation reports follow-up outcomes, with the domain, tags and domain map stored atomically
    (FR-024, FR-025);
  - relayhost and rate limit are read-only until features 009 and 006 (FR-007, FR-031);
  - the fork-only ISPConfig DKIM DNS push and the default DKIM selector are settings (FR-027,
    FR-028);
  - domain administrators' non-permitted changes are refused and named (FR-032);
  - tags use set replacement with a total limit (FR-043, FR-044);
  - version tags cover stored settings only and are optional to send (FR-041, FR-042);
  - deletion also cleans up alias domains and the footer (FR-037);
  - templates are administrator-only (FR-005, FR-045);
  - one request per object (FR-062); iteration 4 superseded this with bulk actions.

  The legacy coverage table, the parity table with fork differences, and the creation report were
  added. The spec was renumbered to FR-001 to FR-062 and SC-001 to SC-009, and every cross-reference
  was checked.
- Iteration 3: three contradictions found on review were fixed:
  - the description rule against "omitted values are kept" (FR-020);
  - "sent values are never ignored" against relay normalisation (FR-030);
  - the missing rule for read-only attributes sent by administrators (FR-031).
- Iteration 4 (orchestrator alignment with features 003 and 004): legacy offers mass actions on
  domains in `tab-domains.twig` (activate, deactivate, delete, administrators only). Clarification
  10 is now an orchestrator decision. The spec adds:
  - User Story 6, bulk actions (P2). The footer and audit stories are renumbered to 7 and 8;
  - FR-062 and FR-063, following the 003 pattern: up to 100 domains, refused as a whole only for
    role or key scope, otherwise processed and audited per domain with one result each, no version
    checks;
  - a bulk row in the FR-005 matrix and two edge cases;
  - per-object audit records (FR-057, SC-007), SC-010, the "Bulk action" entity, and the bulk limit
    among the operator defaults.

  The template list's mass delete stops after the first template in legacy, so templates and
  footers stay one request each. This is stated in the coverage and parity tables. All checklist
  items still pass, and every FR reference resolves.
- Iteration 5 (orchestrator, gaps found by the UI 002 spec): five additive changes, each with an
  orchestrator-decision clarification, acceptance scenarios, matrix rows, and coverage and parity
  notes:
  - domain capabilities, FR-064: the groupware state, the limits, and the DKIM defaults for
    administrators;
  - the resource count and the largest existing mailbox quota in the usage figures (FR-008);
  - a read-only deletion preview (FR-065);
  - version tags on list entries, and optional per-item version tags in bulk actions (FR-015,
    FR-063);
  - a distinct tag list in scope (FR-066).

  SC-011 and SC-012 and three entities were added. Footer exclusion candidates are left to features
  003 and 004. The spec now has FR-001 to FR-066 and SC-001 to SC-012. All checklist items pass, and
  every FR reference resolves.
- Iteration 6 (orchestrator): FR-062 already defined the bulk actions. The only leftover "no batch"
  wording was in this checklist, and it is now reworded. BCC maps: legacy `bcc` add stores domain
  BCC maps as `@<domain>`, while legacy deletion matches the bare name, so they survive (found by
  feature 009 and confirmed in `functions.address_rewriting.inc.php`). Domain BCC maps moved from
  FR-036 (as legacy) to FR-037 (unlike legacy). FR-037 now removes both forms, for the domain and
  its alias domains, with wording compatible with feature 004. The spec gained an
  orchestrator-decision clarification, a **Deviation** (bug fix) parity row, and a corrected
  "Delete side effects" row. All checklist items pass, and every FR reference resolves.
- The domain-related legacy files of the FeldHost fork were diffed against upstream `2026-09a`. The
  domain logic is identical. The differences are the default DKIM selector and the ISPConfig DKIM DNS
  push, both now explicit (FR-027, FR-028 and the parity table).
- The product is an HTTP API, so some names are part of the user-facing contract and not
  implementation details. These are the problem-details error format, UTS #46 and RFC 3339, and the
  legacy footer placeholders. No framework, language or storage technology is named in the
  requirements.
- The constitution (quality gate 3) requires legacy parity to be documented in the spec. The parity
  and coverage tables therefore cite legacy source files, tables and shared-store keys.
  Requirements state only observable behaviour.
