# Analysis: feature 013 System settings

The plan agent ran `/speckit-analyze` over `spec.md`, `plan.md` and `tasks.md`. The orchestrator
recorded the results here, because the harness would not let the subagent write this file.

## Result

- No CRITICAL or HIGH findings remain. Every FR and SC is referenced by tasks.
- Fixed MEDIUM findings:
  - refused validate-only requests skip the refusal audit;
  - the app-links audit record stores the old and new lists;
  - the data model's wording about the Redis key prefix was clarified;
  - the refusal convention is reflected in spec, plan and tasks.
- Several LOW traceability gaps were also fixed.

## Remaining (below HIGH)

1. **MEDIUM:** FR-058 can only be met eventually. If Rspamd reads a map during the write, it reloads
   the complete content at its next check, as with legacy.
2. **LOW:** mass mail jobs carry no version tag. Their stored fields never change, and their status
   is runtime data.
3. **LOW:** an outdated version answers `412 about:blank` (001's convention). A container service
   failure is covered by `container-action-failed` and `outcome-unknown`.
4. **LOW:** list page sizes (25, up to 1,000) are constants (conventions).
5. **LOW:** SC-002, SC-005 and SC-007 are checked only by hand (quickstart Part C, T097).
6. **LOW:** 013's PATCH operation lives in 003's module file.

## Orchestrator decisions on owner questions

All accepted as the agent defaulted them:
- maps are written in place;
- the PATCH stays in 003's `/password-policy` path item;
- `PublicConfig.brandColors` stays optional;
- regex lines containing an inner `/` are refused, as in legacy.
