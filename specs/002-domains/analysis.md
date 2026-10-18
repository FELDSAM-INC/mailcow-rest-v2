# Analysis: feature 002 Domains

The plan agent ran `/speckit-analyze` over `spec.md`, `plan.md` and `tasks.md`. The orchestrator
recorded the results here, because the harness would not let the subagent write this file.

## Result

- CRITICAL: none.
- All 66 FRs and 12 SCs map to at least one task.
- HIGH: three findings, all fixed:
  - **F1:** FR-063 and US6 acceptance scenario 6 said "refused" where the shared outcome set has
    `outdated`.
  - **F2:** FR-062 fixes the bulk limit at 100, but the assumptions said it was
    operator-changeable. It is now fixed at 100: `maxItems: 100` is in the contract and the
    setting was removed.
  - **F3:** FR-057 ("or tries to") requires audit records that the plan skipped. Unchanged edits
    and no-op activations are now audited with empty changes.
- MEDIUM: fixed:
  - **F4:** FR-025 contradicted the `skipped` outcome.
  - **F5:** FR-001, 002, 004, 005, 028, 060 and 061 and SC-007, 008 and 009 had no traces. T097
    was added for quickstart Part C.
  - **F6:** the plan structure was missing a few files.
  - **F7:** FR-037 was aligned with 004.

## Open findings

- **MEDIUM:**
  - **R1:** 001's coverage and problem-type gates cannot pass while `api/` holds every feature's
    contract. T004 scopes them to implemented tags (orchestrator decision: accepted).
  - **R2:** the audit record completion exception. It is justified in the plan and accepted only
    for `creationReport` and `maildirCleanup`.
  - **R3:** 010 must reuse 002's ISPConfig and DKIM design and settings.
- **LOW:**
  - **R4:** the rule-code style differs from 003 (`outOfRange` with `limit`).
  - **R5:** an outdated version stays `412 about:blank`.
  - **R6:** a `SKIP_SOGO` change applies only after a container restart.
  - **R7:** role-level refusals are not audited. Superseded by the refusal-auditing convention in
    `docs/conventions.md`.
  - **R8:** legacy deletes every orphaned domain admin (orchestrator decision: keep legacy).
  - **R9:** a removed footer is recorded in the domain's deletion record.
  - **R10:** sending `usage` back gets `immutable`.
  - **R11:** there is no test for "relay target of alias domains".
  - **R12:** the concurrency test depends on timing.
  - **R13:** some lines in `tasks.md` are long.

## Orchestrator decisions after analysis

- The capabilities read moves to `/capabilities/domains` (see `docs/conventions.md`).
- ISPConfig is reached through its JSON remote API, not SOAP.
