# Contracts of feature 006

The contract of this feature is not duplicated here. The OpenAPI 3.1 document in the repository's
[`api/`](../../../api/) directory is the single source of truth (constitution I):

- [`api/modules/quarantine/`](../../../api/modules/quarantine/): quarantine items, their release,
  spam learning, deletion and downloads, bulk actions, the quarantine settings and template preview,
  the quarantine capabilities, and the quarantine notification settings of mailboxes (singly and in
  bulk).
- [`api/modules/spam-policy/`](../../../api/modules/spam-policy/): allow and block lists of domains,
  alias domains and mailboxes, mailbox spam scores, and the spam filter's action statistics.
- [`api/modules/rate-limits/`](../../../api/modules/rate-limits/): rate limits of domains, alias
  domains and mailboxes, the scoped rate limit list, and bucket resets.
- Components, all named after this feature's resources:
  - `api/components/schemas/`: `Quarantine*`, `SpamPolicy*`, `SpamScore*`, `SpamFilter*`,
    `RateLimit*`;
  - `api/components/parameters/`: `QuarantineItemId`, `QuarantineAttachmentPosition`,
    `QuarantineItemSort`, `QuarantineValidateOnly`, `PolicyTargetDomain`, `PolicyTargetAliasDomain`,
    `PolicyTargetMailbox`, `SpamPolicyEntryId`, `SpamPolicyEntrySort`, `RateLimitSort`,
    `RateLimitBucketId`;
  - `api/components/responses/`: `Quarantine*`, `SpamPolicyEntry*`, `SpamScoreResponse`,
    `SpamFilter*`, `RateLimit*`.
- Shared components reused: `ListMeta`, `Problem`, `ValidationProblem`, `Limit`, `Offset`,
  `Order`, `Search`, `IfMatch`, `BulkTarget`, `BulkItemOutcome`, `BulkResultResponse`, the
  security schemes and the standard responses.
- Values appended to the shared enumerations: `KnownProblemType` (`spam-policy-entry-exists`,
  `quarantine-message-too-large`, `quarantine-item-busy`, `quarantine-delivery-refused`),
  `AuditEventType` (12 events), `AuditTarget` (7 types), `ValidationRuleCode` (`invalidTemplate`
  and the 006 description bullet).
- [`docs/problems.md`](../../../docs/problems.md): the feature's problem types and its use of the
  shared `permission-flag-required` and `temporary-failure` types.

The 42 operations, with their roles, flags and requirements, are listed in
[plan.md](../plan.md#contract-operations).

Check the contract with:

```bash
npx --yes @redocly/cli@2.64.1 lint api/openapi.yaml
npx --yes @redocly/cli@2.64.1 bundle api/openapi.yaml -o build/contract/openapi.yaml
```
