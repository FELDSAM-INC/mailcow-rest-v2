# Analysis: feature 011 Monitoring and operations

The plan agent ran `/speckit-analyze` over `spec.md`, `plan.md` and `tasks.md`, with an independent
read-only review. The orchestrator recorded the results here, because the harness would not let
the subagent write this file.

## Fixed HIGH findings

- Contract rule codes did not match the shared enum (`reserved`, `conflictsWith`, `invalidRegex`,
  `mustNotExceed`).
- The login-log cursor produced duplicates when times were equal.
- FR-002 contradicted the test for read-only domain-admin keys.
- Nightly builds were described both as "check failed" and as "disabled".
- The `Audit-Recorded` header appeared on responses that did not declare it.
- A 503 could follow a change that had already started.
- T024's cache window was wrong for containers (5 s, not 10 s).
- FR-019 said publication time where legacy compares creation time.

## Fixed MEDIUM and LOW findings

- Refused content views are audited.
- 503s before an action are audited.
- Contract-violating test cases follow 001's `…Invalid…` rule.
- 404 action tests were added.
- Rspamd names are aligned with 006.
- Queue membership covers lines past 10,000.
- The 10,000-entry list cap is in the spec.
- The update status declares 503.
- Docker health `none` maps to `null`.
- The ban list ID is kept out of audit records.
- The queue-ID race is in the deviations table.

Coverage: all 69 FRs map to tasks. SC-002 (UX speed) cannot be tested through the API. SC-007 is
checked manually (quickstart Part C).

## Remaining (below HIGH)

- **MEDIUM:** whether a ban rule's first group really captures the client address cannot be checked
  automatically. Compiling the rule and requiring a capture group are enforced.
- **LOW:** the inherited netfilter race on `F2B_OPTIONS` is documented and cannot be fixed without
  changing netfilter.
- **LOW:** 003's `outcome-unknown` section should be generalised, because 011 reuses the type with
  `step` set to the action.
- **LOW:** very large queued messages are returned whole in JSON.
- **LOW:** delivering many selected messages is slow, because dockerapi runs one command per ID. The
  contract recommends a flush instead.
- **LOW:** the API image's Python version may drift from netfilter's.

## Orchestrator decisions on owner questions

All accepted as the agent defaulted them:
- `python3` in the image, which 006 needs as well;
- a cap of 10,000 entries per allowlist and denylist;
- the ban list address uses `MAILCOW_HOSTNAME`;
- the update check supports GitHub-compatible release sources only;
- dockerapi is called without TLS verification, as in legacy and 002.
