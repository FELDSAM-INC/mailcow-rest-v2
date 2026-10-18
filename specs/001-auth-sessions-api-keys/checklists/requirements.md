# Specification Quality Checklist: Authentication, Sessions, API Keys, Identity and Branding Configuration

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

- Iteration 1: two clarification markers were open: Q1 (API key ownership) and Q2 (passwordless
  FIDO2 sign-in).
- Iteration 2: both were resolved by the owner on 2026-10-18 and recorded under "Clarifications".
  - Q1: administrators or domain administrators can own a key, and the key acts as its owner
    (FR-032, FR-033).
  - Q2: passwordless sign-in is in scope as passkeys, and methods are presented in the order
    passkey, then TOTP, then advanced options (User Story 2, FR-015 to FR-020).

  The spec was renumbered to FR-001 to FR-051 and SC-001 to SC-010. All cross-references were
  checked.
- Iteration 3: the auth-related legacy files of the FeldHost fork were diffed against upstream
  `2026-09a`. The two differences found (the recent-login service label and the default product
  name) are now explicit in FR-027 and FR-043.
- The product is an HTTP API, so some names are part of the user-facing contract and not
  implementation details. These are the `X-API-Key` header, the problem-details error format and
  the fail2ban message text that mailcow's existing ban rules match. No framework, language or
  storage technology is named.
- The constitution (quality gate 3) requires legacy parity to be documented in the spec. The parity
  table therefore cites legacy source files and describes legacy behaviour. Requirements state only
  observable behaviour.
- Items marked incomplete require spec updates before `/speckit-clarify` or `/speckit-plan`
