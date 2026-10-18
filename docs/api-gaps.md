# API gaps found by UI plans

UI plans found these gaps after the API feature was planned. Each one is fixed while its API
feature is implemented: the implementer adds the FR to the spec, the contract change and the tests,
then marks the entry done here. Gaps that are a contract-wide rule are also recorded in
`docs/conventions.md`.

| # | Found by | API feature | Gap | Fix | Status |
|---|---|---|---|---|---|
| G1 | UI 001 | 001 | `KnownProblemType` was unreferenced, so bundles dropped it. | Registered under `components.schemas` in `api/openapi.yaml`. | done (`3207925`) |
| G2 | UI 001 | 001 | `/ng` without a trailing slash returned 404. | `location = /ng { return 301 /ng/; }` in the nginx snippet. | done (001 Phase 1–2) |
| G3 | UI 010 | all | The members of `AuditEvent.details` are not documented in the contract, so the UI cannot label them. | Each feature documents its detail keys per event type in `docs/audit-events.md` (see `docs/conventions.md`). The `AuditEvent.details` description points there. | open: every feature as it is implemented. 001: `signIn`, `signOut`, `preferencesUpdate` and the passkey events documented, and the description points to the file; the API key events follow with 001 Phase 6 |
| G4 | UI 010 | 010 | `/capabilities/dns` has no DKIM bulk limit, unlike other capabilities reads. | Add `dkim.bulkLimit` (100) to `DnsCapabilities`. | open |
| G5 | UI 001 | release | `info.version` stays `0.1.0` while features merge. | Tag a contract release per implemented feature (`contract-NNN`), and document how UI pins it. | open: at release |
| G6 | UI 011 | 011 | The meaning of the `before` cursor with `order=asc` is not documented for logs. | Document it, or refuse `before` with `asc`. | open |
| G7 | UI 011 | 011 | The ban (fail2ban) parameter ranges are prose only. | Add `minimum`/`maximum` to the schema properties. | open |
| G8 | UI 011 | 011 | `Audit-Recorded` is not declared on the queue bulk responses. | Declare the header wherever it can be sent. | open |
| G9 | UI 011 | 011 | `meta.total` together with the `before` cursor is unspecified. | Document whether `total` counts all matches or the remaining ones. | open |
| G10 | UI 011 | 006 | `rateLimitHash` has no pattern, while `bucketId` has one. | Add the same pattern. | open |
| G11 | UI 011 | 003/011 | The `outcome-unknown` `step` extension member is prose only. | Add `step` to the problem schema (shared by 003 and 011). | open |
| G12 | UI 004 | 004 | `MailboxSenderPermissionCandidate` and `MailboxSenderPermission` have no `kind` (domain, alias domain, alias, mailbox, any address), so FR-050's labels are impossible. | Add a `kind` enum to both. | open |
| G13 | UI 004 | 004 | `Alias.destinations`, `AliasCopyOutcome.address` and `externalSenderAddresses` have no readable (Unicode) form. | Add Unicode display forms next to the ASCII values, as for domains. | open |
| G14 | UI 004 | 004 | `MailboxSenderSettingsPatch` documents `reason: localDomain`, while `ValidationRuleCode` uses `kind`. | Align on `kind`. | open |
| G15 | UI 006 | 006 | Allow/block list audit records use the entry ID as their target, with the owner only in `details`, so History can't be filtered to one domain's or mailbox's list. | Target the owner (`domain`, `aliasDomain` or `mailbox`, with its ID), and record the entry ID and value in `details`. | open |
| G16 | UI 013 | 013 | A mass-mail recipient's `failureReason` is free text, so clients can't translate it. | Add a stable `failureCode` enum next to the text. | open |
| G17 | UI 013 | 013 | The regex-map list returns every map's content, and the mass-mail job list every job's bodies, which is heavy on phones. | Lists return summaries; the content comes from the single-item reads. | open |
