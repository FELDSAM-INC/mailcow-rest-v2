# Analysis: feature 008 Sync jobs

The plan agent ran `/speckit-analyze` over `spec.md`, `plan.md` and `tasks.md`. The orchestrator
recorded the results here, because the harness would not let the subagent write this file.

## Result

- CRITICAL: none.
- HIGH: five findings, all fixed:
  - **C1:** FR-025 requires an audit record for every attempted change, but refusals coming from
    the request pipeline were not audited. A `RefusalAuditor` was added (task T021).
  - **I1:** FR-017 said encryption is "required", which contradicts clarification 8's default of
    SSL. The spec was aligned.
  - **I2:** The spec's assumptions made the page size operator-changeable, against
    `docs/conventions.md` and the shared `Limit` parameter. The spec was aligned with the
    conventions.
  - **D1:** Four plan-level deviations were not in the spec. They are now "plan-level note" rows
    in its parity table (constitution IV).
  - **U1:** The edge case "audit record cannot be written" had no test. It was added to T034 and
    T047.
- MEDIUM: fixed. These were missing edge-case tests, corrected counts (the allow-list has 114
  entries, 113 without `showpasswords`; the `imapsync` table has 34 columns) and task renumbering.
- Coverage: 30 of 30 FRs and 9 of 9 SCs. SC-008 is covered only partly, because UI timing lies
  outside the API. No ambiguities and no duplications remain.

## Open findings below HIGH

- **M1:** Several tasks depend on names from features 002 and 004, which are still being planned:
  the bulk plumbing, the `is_valid_domain_name` port, the refusal hook and the alias models.
  T001 resolves them as reuse-or-create tasks.
- **M2:** Resolved by an orchestrator decision. History uses 001's generic `targetType` and
  `targetId` audit filters, and no `targetMailbox` filter is added.
- **M3:** Resolved by an orchestrator decision. Capabilities reads use the path
  `/{collection}/capabilities` project-wide (see `docs/conventions.md`).
- **M4:** Resolved. `AuditFailureReason` is a shared, append-only enum.
- **LOW (7):**
  - the bulk limit counts items as sent, duplicates included;
  - the shared outcome `refused` cannot occur for these four actions;
  - the path says `schedule-reset` while the operation is called "reschedule";
  - two tasks embed their own test, in 001's style;
  - response schemas carry legacy's value ranges;
  - the `exclude` pattern needs `\/` for a literal slash, as in legacy;
  - `modifiedAt` also changes when the runner writes its status.
