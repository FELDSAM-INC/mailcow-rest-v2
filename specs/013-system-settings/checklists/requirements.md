# Specification Quality Checklist: System Settings

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

- Iteration 1 (specify): the spec was written from allocation row 013 after reading the legacy code
  of the FeldHost fork and diffing it against upstream `2026-09a`. Three points were open while
  drafting: how mass mail is delivered and confirmed, whether API keys may send mass mail or set the
  Rspamd password, and how branding colours are validated. No clarification marker was left in the
  spec; they were decided in the clarify pass.
- Iteration 2 (clarify, 2026-10-18): the user could not be asked, so twelve material ambiguities
  were decided by the agent, in the order legacy behaviour, constitution, best practice. They are
  recorded under "Clarifications" and integrated:
  - mass mail is delivered directly into the mailboxes, as legacy does, not through Postfix
    (FR-076);
  - mass mail runs as a background job after a preview and a count confirmation, one at a time,
    rate-limited, cancellable, at most once per recipient (FR-072 to FR-081);
  - mass mail and the Rspamd password need a signed-in session (FR-006);
  - one brand colour per theme, contrast-checked against documented reference surfaces (FR-029 to
    FR-034);
  - logos: content-detected type, legacy limits, SVGs with active content refused (FR-014 to
    FR-016);
  - invalid values are refused instead of silently replaced (FR-011 and the field rules);
  - regex maps and settings rules are validated on the server (FR-051, FR-057);
  - global Sieve saves restart Dovecot unless deferred, and report the outcome (FR-065);
  - CORS settings govern only `/api/v1` (FR-071);
  - license verification is a server setting for the fork (FR-085);
  - plain-text versus HTML UI texts and their legacy storage (FR-020, FR-021);
  - a validate-only option replaces the legacy "Validate" buttons (FR-093).
- Iteration 3 (review): fixed the quota copy subject format (parentheses), the effect of the
  "Disable Rspamd for a domain" preset, preset listing per kind, the derivation of the effective
  dark colour (FR-031), the wording of FR-041, FR-064 and FR-086, and added acceptance scenarios for
  the effective settings map, validate-only saves and the IP check. Added SC-013 for read latency
  and the app link name rule. The spec has FR-001 to FR-093 and SC-001 to SC-013, and every
  cross-reference was checked.
- Iteration 4 (alignment with sibling specs committed meanwhile): the password policy read is
  feature 003's FR-063 (FR-037), the public address lookup is feature 011's FR-021 (FR-035), and
  "force single sign-on" is the presentation option of feature 012's FR-020 (FR-024). Feature 002
  now has bulk actions where legacy screens have mass actions; no legacy screen of this feature has
  them, so FR-092 keeps one request per object and the parity table says why. All checklist items
  still pass.
- Fork versus upstream: `functions.inc.php`, `functions.rspamd.inc.php`,
  `functions.quota_notification.inc.php`, `functions.presets.inc.php`, `functions.mailbox.inc.php`,
  `json_api.php`, the presets, `quota_notify.py` and dockerapi are identical. The fork differs in the
  default product name, the default logos, the removed GUID and license card, and branding-only
  texts. All four are listed in the parity section; the license card becomes FR-085.
- The product is an HTTP API that configures named mail services, so some names are part of the
  user-facing contract and not implementation details: Dovecot, Rspamd, Postfix, Sieve, the
  `/api/v1` legacy API, WCAG 2.2, sRGB, the problem-details format and the legacy template
  placeholders. No framework, language or storage technology is named in the requirements.
- The constitution (quality gate 3) requires legacy parity to be documented in the spec. The parity
  and coverage tables therefore cite legacy source files, shared-store keys and file paths.
  Requirements state only observable behaviour.
