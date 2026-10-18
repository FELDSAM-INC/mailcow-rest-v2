# Specification Quality Checklist: Account Self-Service

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

- Iteration 1 (specify): the spec was written from allocation row 005 after reading the legacy
  code of the FeldHost fork and diffing it against upstream `2026-09a`. No clarification markers
  were left: every open point had a legacy answer or was queued for the clarify phase. Review found
  six gaps, which were fixed:
  - FR-004 called verifying push settings "state-changing" without saying why;
  - FR-024 did not say what happens when an account's only second factors are deprecated;
  - FR-030 did not say that several TOTP entries are allowed;
  - FR-063 removed the legacy UI sign-in records for every caller, while legacy does so only for
    administrators and the user themself;
  - FR-018 refused reading the recovery address without the flag, which contradicted FR-006 (reads
    need no flag);
  - FR-067 asked for an active mailbox and domain, which feature 001 already guarantees.
- Iteration 2 (clarify, 2026-10-18): the user could not be asked, so eleven material ambiguities
  were decided by the agent, in the order legacy behaviour, constitution, best practice. They are
  recorded under "Clarifications" and integrated:
  - webmail opens through a webmail-only hand-off (FR-067, FR-068);
  - the current password is required for credential changes by every role, except when resolving
    forced actions, and failures are throttled (FR-008, FR-012, FR-029);
  - a password change ends the account's other sessions by default, and a reset ends all of them
    (FR-011, FR-025);
  - pre-hashed values are refused for self-service and app passwords (FR-009, FR-038);
  - reset requests reveal nothing about the account (FR-019, FR-020);
  - permission flags gate changes, not reads, and are enforced server-side for app passwords
    (FR-006);
  - `pw_reset` gates only the recovery address, not the reset (FR-018, FR-020);
  - Pushover credentials are write-only (FR-054, FR-055);
  - the only batch operation is the bulk TLS change (FR-060, FR-079);
  - external-source mailboxes cannot change passwords or manage second factors here (FR-013,
    FR-028);
  - the overview and the password rules for self-service are in scope (FR-014, FR-069).
- Every reference to FR-001 to FR-079 and SC-001 to SC-012 was checked, as were the references to
  requirements of features 001, 002, 003 and 007.
- The fork differences (TOTP issuer label, web UI service code in recent logins, webmail toolbar
  label, branding texts) are listed in the parity section and resolved by FR-030 and FR-061.
- The product is an HTTP API, so some names are part of the user-facing contract and not
  implementation details: the problem-details error format, the Sieve language, TOTP, WebAuthn,
  Yubico OTP, the legacy placeholders of the reset message and of push notifications, and mailcow's
  own setting `MAILCOW_PASS_SCHEME`. No framework, language or storage technology is named in the
  requirements.
- The constitution (quality gate 3) requires legacy parity to be documented in the spec. The parity
  and coverage tables therefore cite legacy source files, tables and shared-store keys.
  Requirements state only observable behaviour.
