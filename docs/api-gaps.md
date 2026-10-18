# API gaps found by UI plans

UI plans found these gaps after the API feature was planned. Each one is fixed while its API
feature is implemented: the implementer adds the FR to the spec, the contract change and the tests,
then marks the entry done here. Gaps that are a contract-wide rule are also recorded in
`docs/conventions.md`.

| # | Found by | API feature | Gap | Fix | Status |
|---|---|---|---|---|---|
| G1 | UI 001 | 001 | `KnownProblemType` was unreferenced, so bundles dropped it. | Registered under `components.schemas` in `api/openapi.yaml`. | done (`3207925`) |
| G2 | UI 001 | 001 | `/ng` without a trailing slash returned 404. | `location = /ng { return 301 /ng/; }` in the nginx snippet. | done (001 Phase 1–2) |
| G3 | UI 010 | all | The members of `AuditEvent.details` are not documented in the contract, so the UI cannot label them. | Each feature documents its detail keys per event type in `docs/audit-events.md` (see `docs/conventions.md`). The `AuditEvent.details` description points there. | open: every feature as it is implemented. 001 done: all of its event types (`signIn`, `signOut`, `preferencesUpdate`, the passkey and the API key events) are documented, and the description points to the file. 002 done: its seven event types, `dkimKeyGeneration` (010's DKIM core) and the keys of refusal records |
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
| G18 | UI 012 | 001 | `GET /audit-events` has no sign-in method filter. | Add a generic `authMethod` filter (repeatable, values of `AuthMethod`). | done (001 Phase 8, FR-059) |
| G19 | UI 012 | 001 | `GET /audit-events` has no `requestId` filter, so related records can't be linked. | Add a `requestId` filter. | done (001 Phase 8, FR-060) |
| G20 | UI 012 | 012 | `PUT /identity-provider` has no create-only precondition, so simultaneous first setups overwrite each other. | Honour `If-None-Match: *` (412 when a configuration exists). | open |
| G21 | UI 012 | 012 | There is no identity provider in the API dev stack for SSO smoke tests. | Add an optional Keycloak service with a realm import to the dev compose stack. | open |
| G22 | UI 005 | 005 | `applyMailboxMailSettingsBulkAction` compares `version` with the mail-settings tag, but mailbox list entries expose only the mailbox `etag`, so bulk TLS can't send per-item versions. | Add `mailSettingsVersion` to the `Mailbox` list representation (003 schema, owned by 005). | open |
| G23 | UI 005 | 005 | No operation reads a Sieve filter by its ID alone (needed by UI 014 mapping M31). | Add `GET /sieve-filters/{sieveFilterId}`, or an `id` filter on `GET /sieve-filters`. | open |
| G24 | UI 005 | 005 | Recent-login services are an open string. | List the known values in the contract (enum, with clients tolerating unknown values). | open |
| G25 | UI 005 | 001/005 | The `PendingAction` and `pending-action-required` texts still say the password change happens in the legacy UI. | Update them when 005 lands (005 T029). | open |
| G26 | UI 005 | 005 | The SVG element subset of the TOTP `qrCode` is undocumented. | Document the subset (or return the otpauth URI for client-side rendering). | open |
| G27 | UI 005 | 005 | Reset links carry the token in the query string, so it can reach server logs. | Default `RESTV2_PW_RESET_PAGE` to `/ng/reset-password#token={token}`. | open |
| G28 | UI 002 | 002 | `Domain` has no footer summary, so the domain page fetches the full footer (up to 2 × 1 MiB). | Add a footer summary (whether set, its size, the exclusion counts) to `Domain`. | done (002 FR-067: `Domain.footer`, `DomainFooterSummary`) |
| G29 | UI 002 | 002 | The template defaults are only in prose. | Expose them in `/capabilities/domains`, or as schema `default` keywords. | done (002 FR-068: `DomainCapabilities.templateDefaults` for administrators, `default` keywords in `DomainTemplateCreateRequest`) |
| G30 | UI 002 | 002 | There is no bulk deletion preview, so the bulk delete confirmation can't name the orphaned domain admins. | Optional: a bulk preview operation, or the orphaned accounts in the per-item results. | open (optional) |
| G31 | UI 003 | 003 | The recognised password hash-scheme prefixes (pre-hashed passwords) are given only as examples. | List them exactly (an enum in the schema description, or in `/capabilities/mailboxes`). | done (003: `MailboxCapabilities.preHashedPasswordSchemes`, listed in its description and referenced by the `password` members) |
| G32 | UI 003 | 003 | The built-in mailbox template defaults can't be read. | Expose them in `/capabilities/mailboxes`, or as schema `default` keywords (like G29). | done (003: `MailboxCapabilities.templateDefaults`, `MailboxTemplateDefaults`) |
| G33 | UI 003 | 002 (first bulk feature) | Whether `etag` members and `BulkTarget.version` carry quotes is unclear. | `etag` members hold the exact `ETag` header value (quoted); `BulkTarget.version` accepts the value with or without quotes. Document it in `BulkTarget.yaml` and in the conventions. | done (002 US6: `BulkTarget.yaml`, `docs/conventions.md`, `App\Support\Bulk\BulkActionRunner::ifMatch()`) |
| G34 | UI 009 | 001 (shared) | `requiredFlagsMatch` is declared in no problem schema (for example `RoutingPermissionFlagProblem`). | Declare `requiredFlags` and `requiredFlagsMatch` in one shared `PermissionFlagRequiredProblem` schema that every feature's variant extends. | done (002: `PermissionFlagRequiredProblem.yaml`, extended by every `*PermissionFlag*Problem`; PHP `App\Exceptions\PermissionFlagRequiredException`) |
| G35 | UI 009 | 009 | The `nextHop` filter on `GET /transports` is not normalised like a stored next hop (bare IP, Unicode host). | Normalise the filter value with the same rules as stored next hops. | open |
| G36 | UI 009 | 009 | `/capabilities/routing` lacks maximum lengths for the forwarding host `host` and the test `sender`/`recipient`. | Add them. | open |
| G37 | UI 009 | 009 | `RelayhostPatch` says `readOnly` where every other schema uses the rule code `immutable`. | Use `immutable`. | open |
| G38 | UI 007 | 007 | The one-time sign-in landing cannot name the link's account before the exchange, because the only token read consumes it. | Optional: a non-consuming token read (`GET /session/sso-token/{token}` returning the account name only, rate limited). Default without it: the landing names only the current account. | open (optional) |
