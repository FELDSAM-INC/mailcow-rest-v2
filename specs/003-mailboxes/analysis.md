# Analysis: feature 003 Mailboxes

The plan agent ran `/speckit-analyze` over `spec.md`, `plan.md` and `tasks.md`. The orchestrator
recorded the results here, because the harness would not let the subagent write this file.

## Coverage

- All 67 FRs map to at least one task.
- SC-001, 002, 004, 005, 006, 007, 009 and 010 are covered by tests, and SC-008 partly.
- SC-003 can be checked only on a real host (quickstart Part C). SC-011 belongs to the web app.

## Fixed (CRITICAL or HIGH)

- Constitution II's nested-path example: the flat `/mailboxes/{mailbox}` path is justified in
  Complexity Tracking (orchestrator decision: flat).
- Spec acceptance scenario 13 contradicted the edge case where the password check fails while
  Redis is down. AS-13 and research R9 were clarified.
- The contract coverage gate would have failed, because the shared "Password policy" tag also
  holds 013's PATCH. T001 now scopes coverage by operation ID.
- Response schemas would have rejected valid legacy data: unvalidated recovery e-mails, and custom
  attributes beyond the new limits. The representation schemas were relaxed.
- Research R22 said out-of-scope `404`s are not audited, against the conventions. Fixed, with test
  T016.
- T028 assumed 004's alias limit case, but 004 is implemented after 003. Fixed.
- The conventions are applied: capabilities and tag paths, `GET_LOCK`, `permission-flag-required`,
  and `targets` as the bulk member name.
- Strengthened: SC-006 is now tested with 1,000 attempts, SC-009 timing was added, and the FR-018
  forbidden creation members and the `SKIP_SOGO` deletion case are now tested.

Spec changes were made for consistency only.

## Remaining

- **MEDIUM:**
  - SC-008's 30-second target is not measured automatically, because dockerapi is faked in tests.
  - `permission-flag-required` carries `requiredFlags` (orchestrator decision). The `flag` member
    is removed.
  - The address lock only works across features if 004 and 005 use the same lock name. It is now
    a convention (`AddressLock`, `GET_LOCK('restv2_address_<sha1>')`).
  - T024 and T077 refactor 002's code (`DomainTagSet`, `DomainBulkActions`), which carries merge
    risk.
- **LOW:**
  - SC-003 and SC-011 cannot be built in this repository.
  - The spec says "domain mailbox limit reached" while the problem type is
    `mailbox-limit-reached`.
  - An interrupted rename is recovered from the logs and the audit record. There is no journal
    table.

## Orchestrator decisions on owner questions

All accepted as the agent defaulted them:
- flat paths;
- a create with no quota whose domain quota is exhausted is refused;
- the minimum password length counts bytes, as in legacy;
- a rename is undone if its final audit write fails;
- in clusters, the maildir move is confirmed on the current node only;
- OAuth tokens that a rename revoked are not restored when it is undone.
