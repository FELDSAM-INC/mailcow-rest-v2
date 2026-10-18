# Specification Analysis Report: Feature 001

**Feature**: `specs/001-auth-sessions-api-keys` | **Date**: 2026-10-18 | **Command**: `/speckit-analyze`

**Inputs**: `spec.md`, `plan.md`, `research.md`, `data-model.md`, `quickstart.md`, `tasks.md`, the
contract in `api/`, `docs/problems.md`, `docs/conventions.md` and `.specify/memory/constitution.md`.

**Method**: Two passes, both read-only.
- Own pass, after task generation. It covered the coverage mapping, the authorisation pipeline and
  the conventions.
- Independent reviewer pass, run as a separate agent on the committed state `95eb9c6`.

Every CRITICAL and HIGH finding was then fixed in the design artifacts in commit `293b7c2` and the
follow-up. The spec was changed only for consistency, never against a recorded clarification.

## Findings

Status is **Resolved** (fixed in the artifacts named) or **Accepted** (kept as is, with the reason).

### Own pass

| ID | Category | Severity | Location(s) | Summary | Status and resolution |
|---|---|---|---|---|---|
| A1 | Inconsistency | HIGH | research R8; tasks (route registration) | Sign-in routes used auth mode `public`, which accepts API keys, while the key tests expected `session-required` there. | Resolved: new mode `browser` (anonymous or signed-in sessions, keys get `session-required`). R8 and every route task were updated. |
| A2 | Inconsistency | HIGH | tasks (CSRF and session routes) | The default pending-action mode would refuse `GET /csrf-token` in a restricted session, which then could not sign out. | Resolved: `restv2.pending:allow` on all `public` and `browser` routes. |
| A3 | Underspecification | MEDIUM | contract `Identity`; R8 | Pending actions of key principals were undefined. | Resolved: keys are never restricted, and `pendingActions` is `[]` (contract, R8, task T082). |
| A4 | Coverage | MEDIUM | FR-013 | Independence from legacy sessions was not tested. | Resolved: T076 scans Redis for keys outside `restv2:`. |
| A5 | Coverage | MEDIUM | SC-007 | No task measured sign-in latency. | Resolved: T107 `SignInLatencyTest`. |
| A6 | Coverage | LOW | Edge cases | "Different host name" and "real client address behind the proxy" were untested. | Resolved: added to T069 and T056. |
| A7 | Underspecification | LOW | T009 | The Laravel session store prefix did not give the documented `restv2:session:` keys. | Resolved: a dedicated `sessions` cache store with prefix `session:`. |
| A8 | Constitution VI | HIGH | plan; R22 | Ceremony requests (challenges, registration options, cancelling a pending sign-in) change session state but were not audited, with no justification. | Resolved: Complexity Tracking row, R22. |
| A9 | Roadmap / quality gate 3 | HIGH | spec | `docs/roadmap.md` requires a Legacy Coverage table in every spec, and spec 001 had none. | Resolved: "Legacy Coverage" table added to spec.md. |
| A10 | Conventions | MEDIUM | contract; R14; data model | Contradictions with `docs/conventions.md`: the API key ETag covered `lastUsedAt`, list entries had no version tag, lists had no `q`, `limit` max was 100 (now 1,000), and the configuration cache was time-based. | Resolved: `etag` on mutable resources, tags over stored settings only, shared `Search.yaml`, limit 25/1,000, fingerprint cache, FR-058, R29. |

### Independent reviewer pass

| ID | Category | Severity | Summary | Status and resolution |
|---|---|---|---|---|
| C1 | Constitution II | CRITICAL | `GET /csrf-token` and `GET /session` start sessions. Idle expiry and user-agent mismatch end sessions on GETs. Key GETs record last use. | Resolved: Complexity Tracking row ("incidental server state on safe methods", RFC 9110 §9.2.1). |
| C2 | Constitution path table | CRITICAL | `HealthController@live/ready` and `DocsController@openapi/ui/problem` broke the `<Entity>Controller` with `index/show/…` rule. | Resolved: single-action controllers with `show` (`Liveness`, `Readiness`, `OpenApiDocument`, `ApiDocs`, `ProblemType`) in plan and tasks. |
| H1 | Spec/contract vs research | HIGH | FR-014 and the contract gave a closed allow-list for restricted sessions, but R8 also allowed public and sign-in routes. | Resolved: FR-014, the contract "Restricted sessions" section and `docs/problems.md` now name the operations that need no signed-in account. |
| H2 | Quickstart | HIGH | A read-only key was expected to get `session-required`, but the scope check comes first. | Resolved: the expected output is `insufficient-key-scope`. |
| H3 | Tasks vs pipeline | HIGH | A domain-admin key on create and revoke was expected to get `forbidden`. | Resolved: it gets `session-required` (create, revoke) and `forbidden` (list, get, owner candidates). The spec's AS-8 wording was clarified. |
| H4 | Contract internal | HIGH | Docs, contract and problem pages went through key authentication but documented no 401/403. | Resolved: all `system.*` routes ignore `X-API-Key` (contract, R8, tasks). |
| H5 | Task not executable | HIGH | 404 and 405 probes match no operation, so the contract validator would fail them. | Resolved: an unmatched request must answer a 404/405 `Problem`, validated against `Problem.yaml` (T016). |
| H6 | Ordering | HIGH | The key test asserted behaviour of routes built in later phases. | Resolved: those assertions moved to T101 (health) and T105 (public configuration). |
| H7 | Ordering | HIGH | The Phase 1 dev stack included the nginx snippet created in Phase 9. | Resolved: T002 creates the snippet, and dev reuses it through the `restv2-mailcow` alias; T110 verifies it in mailcow's nginx image. |
| H8 | Ordering | HIGH | US2 depended on throttle and recent-login services from US1. | Resolved: moved to Phase 2 (T047, T048) with their own tests (T035). |
| H9 | Contract vs plan | HIGH | A repeated `eventType` query parameter is lost by PHP's parser, and tasks used `eventType[]`. | Resolved: parsed from the raw query string, tested with two values (data model, T096, T099). |
| M1 | Spec vs design | MEDIUM | A vanished method was exempt from throttling without spec backing. | Resolved: edge case in the spec states it. |
| M2 | Research vs tasks | MEDIUM | 400 vs 422 for unparseable bodies. | Resolved: invalid JSON gives 400, a non-JSON content type gives 422 (R26). |
| M3 | Coverage | MEDIUM | Session ID renewal after the password step was not specified or tested. | Resolved: `beginPending()` calls `migrate(true)`, asserted in T057. |
| M4 | Ambiguity | MEDIUM | Retried revocation vs `If-Match`. | Resolved: the no-op check comes before `If-Match` (contract, R19, data model, T083). |
| M5 | Research vs library | MEDIUM | lbuchs output differs from the contract's JSON shape, and the 300 s timeout was undocumented. | Resolved: `OptionsJson` mapper with test T036; parity row for the timeout. |
| M6 | Ambiguity | MEDIUM | Re-activation condition overlapped with `second-factor-unavailable`. | Resolved: "no active `tfa` row of any type", as legacy. |
| M7 | Task vs research | MEDIUM | Failure audits inside a rolled-back transaction would be lost. | Resolved: failure audits use their own transaction (T064). |
| M8 | Drift | MEDIUM | nginx variable name and "guard" mechanism. | Resolved: `$restv2_upstream`, with a dev alias instead of a guard. |
| M9 | Research vs task | MEDIUM | The CI image job ran different checks in R25 and T015. | Resolved: it runs both. |
| M10 | Data model vs contract | MEDIUM | An empty name gave `tooShort` in one place and `required` in another. | Resolved: `required` everywhere. |
| M11 | Contract | MEDIUM | Keys always have `pendingActions: []`, but this was undocumented. | Resolved: documented on `Identity.pendingActions`. |
| M12 | Coverage | MEDIUM | SC-002, SC-003, SC-004 (ban parity) and SC-006 had no task. | Partly resolved: T115 runs quickstart Part C on a real mailcow before release. These remain manual release checks. |
| M13 | Constitution runtime | MEDIUM | Configuration is also read from mounted `vars` files. | Resolved: Complexity Tracking row. `RESTV2_*` settings are authoritative, and the mount is optional. |
| M14 | Parity documentation | MEDIUM | Delay truncation in legacy, the Yubico outage behaviour, passkey throttling and the ceremony timeout had no parity rows. | Resolved: four parity rows added to the spec. |
| L1 | Drift | LOW | Throttle key names. | Resolved: `restv2:signin:` and `restv2:passkey:`. |
| L2 | Ambiguity | LOW | "Active managed domains". | Resolved: FR-028 clarified as "active assignment, as in legacy". |
| L3 | Terminology | LOW | The clarification "passkey first, TOTP second, others advanced" vs FR-020's ordering of second factors. | Accepted: the clarification covers sign-in methods overall and FR-020 covers the second-factor step. Both orders agree. |
| L4 | Spec vs contract | LOW | `appsLabel` "empty" vs `null`. | Resolved: FR-056 says null. |
| L5 | Contract vs problems.md | LOW | 409 was missing from the `about:blank` list. | Resolved. |
| L6 | Contract | LOW | The public-config ETag text mentioned `If-Match`. | Resolved: `If-None-Match`. |
| L7 | Ambiguity | LOW | "Live session" was undefined. | Resolved: defined in R7. |
| L8 | Plan structure | LOW | `AuditEventType`, `ListQuery`, `OptionsJson`, the vars fixtures and `analysis.md` were missing from the structure. | Resolved. |
| L9 | Schema edge | LOW | `retryAfter` could be 0. | Resolved: clamped to at least 1 (R17, T047). |
| L10 | Spec edge | LOW | A username over 255 characters gets 422 instead of the generic failure. | Accepted: no mailcow account can have one (`VARCHAR(255)`), and the contract bound is explicit. Documented in R16. |
| L11 | Coverage | LOW | Nothing asserted that the legacy `api` table stays unchanged. | Resolved: T082. |

## Coverage Summary

FR and SC references were extracted from `tasks.md`, ranges expanded. Requirements that the
contract itself satisfies (FR-019, FR-044, FR-049, FR-051) also have tasks.

| Requirement | Has task? | Task IDs |
|---|---|---|
| FR-001 | yes | T054, T064 |
| FR-002 | yes | T054, T064 |
| FR-003 | yes | T054, T055, T064, T069 |
| FR-004 | yes | T053, T055, T064 |
| FR-005 | yes | T054, T064 |
| FR-006 | yes | T032, T055, T064 |
| FR-007 | yes | T056, T064 |
| FR-008 | yes | T049, T054, T057, T064 |
| FR-009 | yes | T028 |
| FR-010 | yes | T061 |
| FR-011 | yes | T026, T054 |
| FR-012 | yes | T028 |
| FR-013 | yes | T076 |
| FR-014 | yes | T030, T055, T064, T105 |
| FR-015 | yes | T069, T072 |
| FR-016 | yes | T069, T072 |
| FR-017 | yes | T069, T072 |
| FR-018 | yes | T069, T072 |
| FR-019 | yes | T057, T069 |
| FR-020 | yes | T057, T066 |
| FR-021 | yes | T057, T058, T066 |
| FR-022 | yes | T058, T066 |
| FR-023 | yes | T057, T066 |
| FR-024 | yes | T057, T060, T066 |
| FR-025 | yes | T057, T066 |
| FR-026 | yes | T055, T064 |
| FR-027 | yes | T035, T055, T064, T069 |
| FR-028 | yes | T076 |
| FR-029 | yes | T028, T076 |
| FR-030 | yes | T078 |
| FR-031 | yes | T084 |
| FR-032 | yes | T083 |
| FR-033 | yes | T082, T083 |
| FR-034 | yes | T083 |
| FR-035 | yes | T083 |
| FR-036 | yes | T083 |
| FR-037 | yes | T083, T084 |
| FR-038 | yes | T082 |
| FR-039 | yes | T082 |
| FR-040 | yes | T032, T082 |
| FR-041 | yes | T082 |
| FR-042 | yes | T091 |
| FR-043 | yes | T091 |
| FR-044 | yes | T091 |
| FR-045 | yes | T055 |
| FR-046 | yes | T031, T055 |
| FR-047 | yes | T096 |
| FR-048 | yes | T031, T098 |
| FR-049 | yes | T024, T106 |
| FR-050 | yes | T097 |
| FR-051 | yes | T016, T018 |
| FR-052 | yes | T070 |
| FR-053 | yes | T071 |
| FR-054 | yes | T030, T055, T070 |
| FR-055 | yes | T024, T025 |
| FR-056 | yes | T093 |
| FR-057 | yes | T085 |
| FR-058 | yes | T025, T083 |
| SC-001 | yes | T053, T057, T069 |
| SC-002 | yes | T115 |
| SC-003 | yes | T115 |
| SC-004 | yes | T115 |
| SC-005 | yes | T082, T105 |
| SC-006 | yes | T115 |
| SC-007 | yes | T107 |
| SC-008 | yes | T091 |
| SC-009 | yes | T097 |
| SC-010 | yes | T083, T105 |

All 37 acceptance scenarios and every server-side edge case map to at least one test task.
SC-002, SC-003, SC-004 (ban-count parity on a real fail2ban) and SC-006 are covered by T115, a
manual Part C run on a mailcow host. Their automatable parts are tested too: message format and
throttled publishing in T055 and T056, the passkey round trip in T070, and key creation and use in
T083.

## Constitution Alignment

No open violation. The plan's Complexity Tracking justifies five deviations:

1. Status `503` for `temporary-verification-failure` and readiness.
2. Status `405` from the framework.
3. Ceremony requests are not audited (A8).
4. Incidental server state on safe methods (C1).
5. Configuration is also read from mounted legacy `vars` files (M13).

The parity table in the spec lists every behaviour change against legacy (constitution IV).

## Unmapped Tasks

None. Setup tasks T001–T015 and operations tasks T108–T112 have no FR, but they implement
constitution gates: dev stack, schema fixture, CI, image, override and nginx routing.

## Metrics

| Metric | Value |
|---|---|
| Functional requirements | 58 (FR-001–FR-058, including the orchestrator additions FR-056–FR-058) |
| Success criteria | 10 |
| Tasks | 115 (Setup 15, Foundational 37, US1 16, US2 7, US3 6, US4 9, US5 5, US6 5, Polish 15) |
| Contract operations | 31, lint-clean (4 `operation-4xx-response` warnings on system endpoints, ignored by T006) |
| FR coverage | 58 of 58 (100 %) |
| SC coverage | 10 of 10, 4 of them through the manual release check T115 |
| Findings found | 46: CRITICAL 2, HIGH 13, MEDIUM 18, LOW 13 |
| Findings open | 0 CRITICAL, 0 HIGH, 1 MEDIUM (M12, manual release checks), 2 LOW accepted (L3, L10) |

## Remaining Findings Below HIGH

- **M12 (MEDIUM, partly resolved)**: SC-002, SC-003, SC-004 (ban count on a real fail2ban) and
  SC-006 can only be verified on a real mailcow host. T115 performs this before the first release.
  It is not part of CI.
- **L3 (LOW, accepted)**: the clarification's overall sign-in order and FR-020's second-factor
  order are worded differently but agree.
- **L10 (LOW, accepted)**: usernames over 255 characters are a `422` contract violation rather than
  a counted, reported sign-in failure.

## Next Actions

- The artifacts are ready for `/speckit-implement`. Start with Phase 1, then Phase 2. The MVP is
  US1.
- Before the first release, run T115 and decide the owner-level question on problem type URIs
  (plan and research R27).
