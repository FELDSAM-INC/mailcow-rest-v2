# API gaps found by UI plans

UI plans found these gaps after the API feature was planned. Each one is fixed while its API
feature is implemented: the implementer adds the FR to the spec, the contract change and the tests,
then marks the entry done here. Gaps that are a contract-wide rule are also recorded in
`docs/conventions.md`.

| # | Found by | API feature | Gap | Fix | Status |
|---|---|---|---|---|---|
| G1 | UI 001 | 001 | `KnownProblemType` was unreferenced, so bundles dropped it. | Registered under `components.schemas` in `api/openapi.yaml`. | done (`3207925`) |
| G2 | UI 001 | 001 | `/ng` without a trailing slash returned 404. | `location = /ng { return 301 /ng/; }` in the nginx snippet. | done (001 Phase 1–2) |
| G3 | UI 010 | all | The members of `AuditEvent.details` are not documented in the contract, so the UI cannot label them. | Each feature documents its detail keys per event type in `docs/audit-events.md` (see `docs/conventions.md`). The `AuditEvent.details` description points there. | open: every feature as it is implemented, starting with 001 |
| G4 | UI 010 | 010 | `/capabilities/dns` has no DKIM bulk limit, unlike other capabilities reads. | Add `dkim.bulkLimit` (100) to `DnsCapabilities`. | open |
| G5 | UI 001 | release | `info.version` stays `0.1.0` while features merge. | Tag a contract release per implemented feature (`contract-NNN`), and document how UI pins it. | open: at release |
