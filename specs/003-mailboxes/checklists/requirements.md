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
