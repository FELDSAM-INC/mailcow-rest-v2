# Analysis: feature 007 Administrators and domain administrators

The plan agent ran `/speckit-analyze` over `spec.md`, `plan.md` and `tasks.md`. The orchestrator
recorded the results here, because the harness would not let the subagent write this file.

## Fixed during analysis

- **F1 (HIGH):** the plan had exempted ending an impersonation and signing out from the
  `impersonation-ended` refusal, which contradicted FR-052 as written. The plan was aligned with
  FR-052. Later superseded by an orchestrator decision; see below.
- **F2 (HIGH):** a per-session generation check duplicated 005's `SessionRegistry`. Sessions now
  end through the registry plus the binding to `created`. The generation counter is used only for
  impersonation targets.
- **F3 (MEDIUM):** duplicate rule codes and failure reasons were replaced by existing ones
  (`immutable`, `notEligible`, `permissionDenied`, `versionOutdated`).
- **F4 (MEDIUM):** FR-002, FR-003, FR-070 to FR-073 and SC-002, SC-006, SC-007 and SC-010 had no
  explicit task reference. References were added.
- **F5 (MEDIUM):** cross-feature tasks now name the classes from the 002, 003 and 005 plans.
- **F6 (MEDIUM):** assertions were added for FR-053, and for restricted sessions on token issue
  and webmail.
- **Convention update:** the capabilities read moved to `/capabilities/accounts`. The rules for
  refusal auditing, HMAC tags and `GET_LOCK` are covered in R3, R4 and R22.

Coverage: all 77 FRs and 10 SCs have at least one task. Critical: 0. High: 0. Duplications: 0.

## Open findings below HIGH

- **R1 (MEDIUM):** a server with webmail disabled answers differently in 007 and 005. Orchestrator
  decision: 007 uses 005's `groupware-disabled` problem type.
- **R2 (MEDIUM):** the bulk path style differs from other features. Orchestrator decision: use the
  uniform `/admins/bulk-actions` and `/domain-admins/bulk-actions`. They are POST-only, and account
  paths never accept POST, so no username collides.
- **R3 (MEDIUM):** 007 depends on classes that 002, 003 and 005 have planned but not delivered.
- **R4 (MEDIUM):** 001's CSRF retry guidance could replay a write after an identity change.
  Orchestrator decision: the 001 contract now says to re-read `GET /me` and retry only if the
  identity is unchanged.
- **R5 (MEDIUM):** SC-006 and SC-007 are end-to-end UI timings. Only the API side is asserted.
- **R6 (LOW):** `404` refusals are audited only where an account or target is addressed.
- **R7 (LOW):** a sign-out that detects an invalid impersonation was refused once. Orchestrator
  decision: sign-out and ending an impersonation always succeed. They end and audit the invalid
  impersonation and are never refused.
- **R8 (LOW):** the last-administrator lock has no index on `superadmin`.
- **R9 (LOW):** a domain administrator's `modifiedAt` ignores changes to assignments and flags, as
  in legacy.
- **R10 (LOW):** page sizes follow the shared `Limit` (25, up to 1,000).
- **R11 (LOW):** one webmail hand-off per session (005).
- **R12 (LOW):** the additive changes to the 001 contract landed while 001 was being implemented.
