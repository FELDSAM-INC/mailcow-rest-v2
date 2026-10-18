# Analysis: feature 010 DKIM, MTA-STS and DNS

The plan agent ran `/speckit-analyze` over `spec.md`, `plan.md` and `tasks.md`. The orchestrator
recorded the results here, because the harness would not let the subagent write this file.

## Resolved HIGH findings

- FR-030 named a reason code that the contract does not have.
- FR-054 contradicted the refusal-auditing convention.
- FR-059 cited 002 FR-062, which has since become a bulk-action requirement.
- The first draft duplicated 002's ISPConfig and settings design and 009's DNS stack. It now reuses
  them.
- The version tag contained a plain hash of the private key. It now uses an HMAC.
- The capabilities path did not follow `/capabilities/{area}`.
- Route stubs answered `501`, which the contract does not document.

Fixed MEDIUM findings: coverage gaps for FR-002, FR-026 and FR-052 plus several edge cases, and
drift between the plan's file tree and the tasks.

Coverage: every buildable FR (58 of 58) and every buildable SC has at least one task. No
constitution issue remains.

## Remaining

- **M1:** legacy's DKIM screen has a mass delete, but FR-059 excluded batch operations.
  Orchestrator decision: add a bulk delete per the conventions. **Applied**: FR-059,
  `POST /dkim-keys/bulk-actions` (`applyDkimKeyBulkAction`), research R28, tasks T086 and T091.
- **M2:** 002's research D10 describes three separate `HSET`s for key generation. 010's atomic Lua
  store replaces them (T017/T023, implemented together with 002).
- **M3:** 010's capabilities also return the DKIM defaults to domain administrators, who may
  generate keys. Accepted.
- **M4:** the `too-many-requests` problem section must also cover the diagnostics rate limit.
  **Applied** in `docs/problems.md`.
- **L1:** the import's name-rule error is keyed by the path parameter `dkimName`.
- **L2:** SC-002 and SC-011 are checked manually (quickstart Part C).
- **L3:** response schemas assume that stored data follows the API's rules.
- **L4:** the `RateLimit-*` header names follow an earlier IETF draft revision.
- **L5:** `dns_get_record()` cannot report timeouts, so a timeout is inferred from how long the
  failed lookup took.

## Orchestrator decisions on owner questions

1. A DKIM bulk delete is added (conventions).
2. ISPConfig is reached through its JSON remote API, as 002 decided. Operators copy the values over,
   replacing `index.php` with `json.php` in the URL.

**Delivery note:** Phase 2A of `tasks.md` (the shared DKIM core) is implemented together with
feature 002.
