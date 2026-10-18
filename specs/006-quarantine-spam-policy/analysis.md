# Analysis: feature 006 Quarantine, spam policy and rate limits

The plan agent ran `/speckit-analyze` over `spec.md`, `plan.md` and `tasks.md`. The orchestrator
recorded the results here, because the harness would not let the subagent write this file.

## Fixed during analysis

- **HIGH:** validate-only answered `204`, which conflicted with 013's pattern and with the rule
  that `204` is for deletes. It now answers `200 {"data":{"valid":true}}`.
- **HIGH:** the concurrency test made only 100 attempts, while SC-006 requires 1,000.
- **MEDIUM:** tests were added for Redis being down during a bucket reset, and for resources in
  list entry paths.
- **MEDIUM:** files that the tasks used but the plan did not list were added, and the data model's
  lifecycle diagram was corrected.

## Remaining (below HIGH)

- **MEDIUM:** SC-003 (round trip with the legacy UI) and SC-004 (a filter applies within one
  minute) are checked only by hand (quickstart Part C). The automated tests assert the storage
  formats.
- **MEDIUM:** newer versions of the parser libraries may produce output that differs from the
  legacy goldens. T024 will show it, and any difference must then be added to the parity table.
- **MEDIUM:** 002's `temporary-failure` section says "Extension members: none". T083 adds a
  pointer to 006's `dependency` and `step` members.
- **MEDIUM:** T001 confirms the unmerged hand-offs: the refusal hook, a `WATCH` variant of
  `SharedStore`, and `permission-flag-required`.
- **LOW:** "allow/block list entry" in the spec versus `SpamPolicyEntry` in the contract; some task
  lines are long; SC-009 is a UI measure.

## Orchestrator decisions on owner questions

1. Python 3 with Jinja2 in the API image, for the exact template check: accepted.
2. Flag names: `requiredFlags` uses 001's `PermissionFlags` property names (camelCase), not
   legacy column names. 006 is being aligned.
3. A release whose audit write fails after delivery answers `500` and logs the full record:
   accepted.
4. A stored envelope sender that is not an address is replaced by `sender-unknown@rspamd`:
   accepted.
