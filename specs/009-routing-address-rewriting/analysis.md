# Analysis: feature 009 Routing and address rewriting

The plan agent ran `/speckit-analyze` over `spec.md`, `plan.md` and `tasks.md`. The orchestrator
recorded the results here, because the harness would not let the subagent write this file.

## Result

- All 93 FRs and 11 buildable SCs have tasks. SC-010 is a UI outcome for mailcowui-ng.
- CRITICAL: none.
- HIGH: five findings, all fixed:
  - the contract validator may not convert boolean and repeated query filters, so T006 now checks
    and extends it;
  - DNS lookups could push the connection test past 60 s. It is now bounded by resolver timeouts;
  - refused reads were audited, which the conventions forbid;
  - the plan invented helpers that earlier features already define;
  - the plan duplicated rule codes and problem types that already existed.
- MEDIUM: fixed. These were explicit references for FR-010, 011, 083, 087 and 088 and several SCs,
  four untested edge cases, and making forwarding-host creations findable in the audit log.

## Open findings

- **MEDIUM:**
  - Bulk request bodies used `targets` or `items`. Orchestrator decision: always `targets`.
  - A missing permission flag was reported three ways. Orchestrator decision:
    `permission-flag-required` for operations, `attribute-not-permitted` for fields, `forbidden`
    for roles (see `docs/conventions.md`).
  - Transport writes return `meta` next to `data`. Orchestrator decision: allowed where needed.
  - 009 depends on class names from the plans of 002 to 004. T001 records any renames before work
    starts.
- **LOW:**
  - the audit `targetId` filter normalises Unicode names only for domain targets;
  - US1 acceptance scenario 9 and FR-085 count the unknown ID differently;
  - choice lists carry no version tag, like 001's owner candidates;
  - the connection test can reach mailcow-internal hosts, as legacy can (orchestrator decision:
    keep it);
  - SC-010 is a UI outcome.
