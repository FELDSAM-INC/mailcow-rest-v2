# Analysis: feature 004 Aliases, alias domains, resources and sender ACL

The plan agent ran `/speckit-analyze` over `spec.md`, `plan.md` and `tasks.md`. The orchestrator
recorded the results here, because the harness would not let the subagent write this file.

## Coverage

68 FRs and the 9 buildable SCs are all covered by at least one task. SC-003 is checked manually.
There are 89 tasks. CRITICAL 0, HIGH 3 (all fixed), duplication 0, ambiguity 0.

## Fixed HIGH

- `AliasDomainPatch` ignored derived members. It now refuses them with `immutable` (FR-029,
  FR-044).
- The invented `destination-not-permitted` type was replaced by the shared refusals. FR-006,
  FR-027, FR-065 and US2 acceptance scenario 8 were aligned.
- An operation-level flag was reported as `attribute-not-permitted`. It is now
  `permission-flag-required`.

## Fixed MEDIUM

- The spec's groupware wording now matches the design.
- FR-043 uses 002's publication outcome values.
- SC-008 and FR-045 now agree on footer exclusions and external sender addresses.
- Names are aligned with 002 and 003 (`holder`, `requiredFlags`, `dependency`, `immutable`,
  `conflictsWith`).
- Paths and bodies follow the conventions (capabilities, helper list, `targets`, the copy folded
  into bulk actions).
- Refusal auditing covers `404`s and pipeline refusals.
- 002's shared pieces are reused instead of being re-designed.
- FR-002, FR-009, the "audit write fails" edge case and FR-015's Unicode substring search are now
  tested.
- US4 and US7 depend on T036.

Fixed LOW: the `kind` filter is repeatable, the performance test path matches 001, and edge-case
tests were added.

## Remaining

- **MEDIUM:** the tasks depend on the not-yet-implemented designs of 002, 003 and 008. T006 pins
  each one with a test, and T014 creates or adapts what is missing.
- **LOW:** `SenderSettingsMailbox` duplicates 003's `MailboxAddress` parameter. Merge them once 003
  is final.
- **LOW:** Unicode substring search filters `xn--` rows in PHP. The performance test covers only
  ASCII `q`.
- **LOW:** SC-003 is checked manually (quickstart Part C).
- **LOW:** the single copy answers `200` with a report, as an action sub-resource.

## Orchestrator decisions

- Deleting a catch-all alias also deletes the explicit `@domain` sender permissions of that domain,
  as in legacy (both are stored as `@domain`).
- 002's research D16 (out-of-scope `404`s not audited) is superseded by the refusal-auditing
  convention.
- 001's problem-type and coverage gates are scoped to implemented features, through a registry.
