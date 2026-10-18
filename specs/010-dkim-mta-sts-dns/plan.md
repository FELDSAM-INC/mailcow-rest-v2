# Implementation Plan: DKIM, MTA-STS and DNS Diagnostics

**Branch**: `main` (no feature branches, constitution 1.1.0) | **Date**: 2026-10-18 |
**Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/010-dkim-mta-sts-dns/spec.md`

## Summary

Feature 010 gives administrators and domain administrators the DKIM, MTA-STS and DNS tools of the
legacy "System › ARC/DKIM keys" screen, the MTA-STS tab and the DNS button:

- DKIM entries of every domain, alias domain and unused key, with the record to publish;
  generation (including atomic rotation with overwrite), import, copy, deletion, gated private key
  export, and the FeldHost publication of key changes to an ISPConfig DNS zone;
- MTA-STS policies of domains (create, read, list, change), stored where mailcow's publication
  endpoint and certificate service read them;
- DNS diagnostics of a domain or alias domain: structured, machine-readable checks with stable
  identifiers and coded reasons, a zone file, time limits and a visible rate limit;
- a capabilities read with the server host name and every default and limit the forms need.

The technical approach:

- The OpenAPI 3.1 contract was authored first: 14 operations in the modules `dkim`, `mta-sts` and
  `dns-diagnostics`; they lint clean.
- DKIM keys stay in mailcow's Redis hashes `DKIM_PUB_KEYS`, `DKIM_SELECTORS` and `DKIM_PRIV_KEYS`,
  which Rspamd reads for DKIM signing and ARC sealing. Every change is one atomic Lua script with a
  compare-and-set on the key's fingerprint, which gives atomicity, the version tag and the
  serialisation of concurrent changes.
- One `DkimKeyService` owns every DKIM write. Features 002 and 004 call its `generateForNewName()`
  when they create domains and alias domains, so the DKIM core (store, generator, publisher,
  ISPConfig client) is delivered together with feature 002.
- The FeldHost ISPConfig publication calls the same remoting functions as the fork through
  ISPConfig's JSON remote API (feature 002's adapter), behind a faked interface, bounded to 15
  seconds, and reports coded outcomes.
- MTA-STS policies are rows of the upstream `mta_sts` table, written in the same transaction as
  their audit record.
- Diagnostics queries mailcow's own unbound resolver through feature 009's DNS client, extended
  with CNAME, SRV, PTR and TLSA lookups (about 4 s per lookup, 30 s per run), reads the SMTP
  certificate through 009's SMTP client, evaluates SPF with `mlocati/spf-lib`, reads the SMTP certificate for TLSA, and
  limits each caller to 10 runs per minute with `RateLimit-*` headers.
- Tests are written first, run on MariaDB with the `2026-09a` schema and on a real Redis, assert the
  `DKIM_*` hashes and `mta_sts` rows, and fake ISPConfig, DNS, the SMTP probe and the echo service.

## Technical Context

**Language/Version**: PHP 8.4, Laravel 13.x (001)

**Primary Dependencies**: 001's set, plus `mlocati/spf-lib` ^3.3 (MIT, SPF, with `mlocati/ip-lib`
and `mlocati/idna`). DNS and SMTP use feature 009's clients. No new PHP extension. Dev image:
`bind-tools` for `named-checkzone`. Justified in
[research R25](./research.md#r25-dependencies-constitution-viii).

**Storage**: mailcow's MariaDB 10.11 (`2026-09a`): `mta_sts` read and written, `domain`,
`alias_domain` and `domain_admins` read. mailcow's Redis 7.4: `DKIM_PUB_KEYS`, `DKIM_SELECTORS` and
`DKIM_PRIV_KEYS` read and written (connection `mailcow`), and API-owned `restv2:` keys for the
diagnostics rate limit and the 10-minute server facts. No API-owned table and no migration. Audit
records in 001's `restv2_audit_events`.

**Testing**: PHPUnit 12 feature and unit tests, written first, on MariaDB with
`tests/fixtures/schema/2026-09a.sql` and a real Redis (database 15). Contract validation of every
request and response (001 R13). Fakes with call assertions for ISPConfig, the DNS resolver, the
SMTP certificate probe and the echo service (`Http::fake()`). Adapter tests against local stubs.

**Target Platform**: 001's Linux container, reached over FastCGI at `/api/v2/`. 009's override
already sets `dns: ${IPV4_NETWORK:-172.22.1}.254` and `dns_opt: [timeout:2, attempts:2]`; this
feature adds the port variables of `mailcow.conf` to the API service.

**Project Type**: web service (REST API).

**Performance Goals**:

- SC-012: a page of 25 DKIM entries within 1 s at p95 with 5,000 domains and 5,000 alias domains
  (names from two indexed queries and `HKEYS`, values only for the page; research R8).
- SC-006: diagnostics within 30 s always (run deadline) and within 5 s at p95 for healthy zones.
- Key generation is dominated by RSA generation (4,096 bits: up to about 2 s).

**Constraints**:

- No change to upstream tables, Redis formats, Rspamd configuration or containers.
- Legacy UI and `/api/v1` keep working on the same data, in both directions.
- Private key material appears only in the export response (SC-005).
- The ISPConfig publication never takes longer than 15 s and never undoes a key change.
- Outbound lookups are bounded per lookup, per run and per caller.
- All dependencies are GPL-3.0-compatible.

**Scale/Scope**: Up to about 10,000 domains and alias domains and their keys; 14 operations in 3
modules; 8 user stories; no API-owned table.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design (below).*

| Principle | Gate | Status |
|---|---|---|
| I. Contract-first | All 14 operations are in `api/modules/{dkim,mta-sts,dns-diagnostics}/` with resource-named components, before any code. `npx @redocly/cli@2.64.1 lint` reports nothing for these modules. Every test request and response is validated; the coverage check includes the 14 operations. Schemas describe the real `mta_sts` columns and `DKIM_*` hashes. | PASS |
| II. HTTP contract | Plural nouns; actions are sub-resources (`/import`, `/copy`, `/private-key`, `/dns-publication`); `201` with `Location` for generation, import, copy and policy creation; `200` for overwrite, republish and patch; `204` for deletion. `{data, meta}` lists, `{data}` resources. RFC 9457 problems with documented types. `ETag` and `If-Match` on keys and policies; every list entry carries `etag`. PATCH is JSON Merge Patch. `503` (temporary failure) and `429` with `RateLimit-*` headers are justified in Complexity Tracking. | PASS (justified) |
| III. Upstream schema | No migration. `mta_sts` and the `DKIM_*` hashes are written in exactly legacy's format (data model). | PASS |
| IV. Parity | Legacy `functions.dkim.inc.php`, the fork's `dkim_update_in_dns()` and `inc/lib/ispconfig.php`, `mailbox()` `mta_sts` cases, `mta-sts.php`, `dns_diagnostics.php`, `spf.inc.php`, `generate_tlsa_digest()` and Rspamd's `dkim_signing.conf`/`arc.conf` were read. Every write goes through `DkimKeyService` or `MtaStsPolicyService` and reproduces the side effects (Redis hashes that Rspamd reads, the row that `mta-sts.php` and acme read, the ISPConfig record). Deviations are those of the spec's parity table plus the plan-level notes below. | PASS |
| V. Coexistence | Same container; mailcow's publication endpoint, certificate service, Rspamd and unbound are used unchanged; nothing is patched. | PASS |
| VI. Security | Object-level authorisation for admin, domain admin and mailbox user, and for read-only and read-write keys of each, on every operation. Out-of-scope names answer `404`. Private keys are write-only except the gated, audited export with `Cache-Control: no-store`; the import never echoes key text; version tags carry the private key only as an HMAC keyed with the app key; the ISPConfig password is never returned, logged or cached. Outbound lookups are rate-limited and time-bounded (OWASP API4). Every state change, every export and every refusal of an authenticated caller is audited. | PASS |
| VII. Test-first | `tasks.md` puts tests before implementation in every phase. MariaDB and Redis are real; ISPConfig, DNS, SMTP probe and echo service are faked with asserting fakes. | PASS |
| VIII. Simplicity | One runtime package, replacing an SPF evaluator that would otherwise be hand-written. The ISPConfig client and settings (002) and the DNS and SMTP clients (009) are reused and extended, not duplicated. Interfaces exist only for faked external services (`IspconfigDnsClient`, `DnsResolver`, `SmtpProbe`); the echo service uses Laravel's HTTP client directly. | PASS |
| Cross-feature conventions | `404` for scope, `403` for roles; keys act as their owner; lists 25/1,000 with `q`; capabilities at `/capabilities/dns` (`getDnsCapabilities`); history through the generic `targetType`/`targetId` filters (no own filter parameter); refusal auditing through `RefusalAuditor` with the shared `AuditFailureReason` values; strong ETags over stored values with secrets only as HMAC; secrets write-only; legacy settings through 001's reader with `RESTV2_*` precedence; FeldHost behaviour as settings with upstream defaults; creation follow-ups for 002/004 report outcomes; one audit record per affected object. Bulk deletion is not offered because spec FR-059 excludes it (owner question below). | PASS (owner question) |

**Post-design re-check (after Phase 1)**: PASS. The contract, data model and research add no new
violation. Decisions that touched gates:

- Lua compare-and-set instead of separate `HSET`s: III (format unchanged) and IV (atomicity
  deviation already in the spec). The script compares an internal fingerprint that never leaves the
  server; the public version tag is 001's ETag over an HMAC of the private key (conventions).
- Audit after publication with compensation on audit failure: VI (spec edge case), research R13.
- No literal path segment under `/dkim-keys/`: II, because single-label domain names exist.

## Parity notes added by this plan

These are plan-level behaviours not spelled out in the spec's parity table. None contradicts a
clarification.

1. The TLSA probe sends `EHLO` instead of legacy's `HELO` before `STARTTLS`, because servers
   advertise `STARTTLS` only after `EHLO` (research R19).
2. The ISPConfig adapter uses the JSON remote API instead of SOAP, and signs in once per publication
   and signs out afterwards; the fork signs in on every call and never signs out (research R11).
3. A copy whose source key is incomplete is refused with `409`; legacy copies the broken key
   (research R23).
4. Domain and alias domain creation (002, 004) write their own `dkimKeyGeneration` audit record
   besides the creation record, so that the key's history is complete (research R10).
5. Refusals by authenticated callers are audited through the shared `RefusalAuditor`, read-only
   keys and restricted sessions included; `401`s and anti-forgery failures are not
   (`docs/conventions.md`, research R22). Spec FR-054 states it.

## Contract Operations

| Method and path | operationId | Auth | Story / FR |
|---|---|---|---|
| GET `/dkim-keys` | listDkimKeys | admin or domain admin, session or key (read-only too) | US1, FR-007–FR-010 |
| POST `/dkim-keys` | generateDkimKey | admin or domain admin (overwrite: admin), session + CSRF or read-write key | US2, FR-013–FR-016, FR-029, FR-030, FR-053 |
| GET `/dkim-keys/{dkimName}` | getDkimKey | admin or domain admin, session or key | US1, FR-007, FR-011 |
| DELETE `/dkim-keys/{dkimName}` | deleteDkimKey | admin, session + CSRF or read-write key | US5, FR-024, FR-025, FR-029, FR-030 |
| POST `/dkim-keys/{dkimName}/import` | importDkimKey | admin, session + CSRF or read-write key | US5, FR-017–FR-021, FR-027 |
| POST `/dkim-keys/{dkimName}/copy` | copyDkimKey | admin, session + CSRF or read-write key | US5, FR-022, FR-023 |
| GET `/dkim-keys/{dkimName}/private-key` | exportDkimPrivateKey | admin, session or key (read-only too), setting on | US6, FR-026, FR-027 |
| POST `/dkim-keys/{dkimName}/dns-publication` | publishDkimDnsRecord | admin, session + CSRF or read-write key, publication on | US7, FR-031 |
| GET `/capabilities/dns` | getDnsCapabilities | admin or domain admin, session or key | US1, US4, FR-012 |
| GET `/mta-sts-policies` | listMtaStsPolicies | admin or domain admin, session or key | US4, FR-038 |
| POST `/mta-sts-policies` | createMtaStsPolicy | admin or domain admin, session + CSRF or read-write key | US4, FR-032–FR-037 |
| GET `/mta-sts-policies/{policyDomain}` | getMtaStsPolicy | admin or domain admin, session or key | US4, FR-036 |
| PATCH `/mta-sts-policies/{policyDomain}` | updateMtaStsPolicy | admin or domain admin, session + CSRF or read-write key | US4, FR-033–FR-035, FR-051 |
| GET `/dns-diagnostics/{diagnosticsName}` | runDnsDiagnostics | admin or domain admin, session or key | US3, FR-039–FR-050 |

Mailbox users get `403 forbidden` on every operation. Audit filtering (US8, FR-055) uses
`listAuditEvents` with the target filters that feature 002 adds.

## Project Structure

### Documentation (this feature)

```text
specs/010-dkim-mta-sts-dns/
├── spec.md              # Feature specification (with clarifications and parity table)
├── plan.md              # This file
├── research.md          # Phase 0: decisions R1–R27
├── data-model.md        # Phase 1: mta_sts, DKIM_* hashes, restv2: keys
├── quickstart.md        # Phase 1: validation guide
├── contracts/README.md  # Phase 1: pointer to api/modules/{dkim,mta-sts,dns-diagnostics}
├── checklists/requirements.md
├── tasks.md             # Phase 2 (/speckit-tasks)
└── analysis.md          # /speckit-analyze summary
```

### Source Code (repository root)

Only the files this feature adds or extends; everything else is 001's layout.

```text
api/
├── modules/dkim/{_index,dkim-keys,dkim-key,dkim-key-import,dkim-key-copy,
│                 dkim-key-private-key,dkim-key-dns-publication,dns-capabilities}.yaml
├── modules/mta-sts/{_index,mta-sts-policies,mta-sts-policy}.yaml
├── modules/dns-diagnostics/{_index,dns-diagnostics}.yaml
└── components/
    ├── parameters/{DkimName,DkimKeySort,MtaStsPolicyDomain,MtaStsPolicySort,DnsDiagnosticsName}.yaml
    ├── schemas/{DkimKey,DkimKeyDetails,DkimKeyState,DkimKeyKind,DkimDnsRecord,DkimKeyGenerateRequest,
    │            DkimKeyImportRequest,DkimKeyCopyRequest,DkimKeyChangeResult,DkimDnsPublicationOutcome,
    │            DkimDnsPublicationStatus,DkimDnsPublicationReasonCode,DkimPrivateKey,DnsCapabilities,
    │            MtaStsPolicy,MtaStsMode,MtaStsDnsRecord,MtaStsPolicyCreateRequest,MtaStsPolicyPatch,
    │            DnsDiagnosticsResult,DnsCheck,DnsCheckStatus,DnsCheckMessage,DnsCheckMessageCode}.yaml
    └── responses/{DkimKeyListResponse,DkimKeyResponse,DkimKeyCreatedResponse,DkimKeyChangedResponse,
                   DkimPrivateKeyResponse,DkimKeyConflict,DkimTemporaryFailure,DkimPrivateKeyExportForbidden,
                   DkimDnsPublicationForbidden,DnsCapabilitiesResponse,MtaStsPolicyListResponse,
                   MtaStsPolicyResponse,MtaStsPolicyCreatedResponse,MtaStsPolicyConflict,
                   DnsDiagnosticsResponse,DnsDiagnosticsTooManyRequests}.yaml

app/
├── Clients/
│   ├── SharedStore/DkimKeyStore.php                 # Lua compare-and-set on DKIM_*, next to 002's SharedStore
│   ├── Ispconfig/{IspconfigDnsClient,JsonIspconfigDnsClient,IspconfigZone,IspconfigRecord,
│   │              IspconfigException}.php              # created with 002 (D10), extended here
│   ├── Dns/{DnsResolver,NativeDnsResolver}.php      # 009's, extended (cname, srv, ptr, tlsa)
│   ├── Dns/PublicAddressDetector.php
│   └── Smtp/{SmtpProbeRequest,SmtpProbeResult,StreamSmtpProbe}.php   # 009's, extended (TLS-only mode)
├── Http/
│   ├── Controllers/Api/V2/{DkimKeyController,DkimKeyImportController,DkimKeyCopyController,
│   │                        DkimPrivateKeyController,DkimDnsPublicationController,
│   │                        DnsCapabilitiesController,MtaStsPolicyController,
│   │                        DnsDiagnosticsController}.php
│   ├── Requests/Api/V2/{ListDkimKeysRequest,GenerateDkimKeyRequest,ImportDkimKeyRequest,
│   │                     CopyDkimKeyRequest,ListMtaStsPoliciesRequest,StoreMtaStsPolicyRequest,
│   │                     UpdateMtaStsPolicyRequest}.php
│   └── Resources/Api/V2/{DkimKeyResource,DkimKeyChangeResource,DkimPrivateKeyResource,
│                          DnsCapabilitiesResource,MtaStsPolicyResource,DnsDiagnosticsResource}.php
├── Models/Mailcow/{MtaStsPolicy,AliasDomain}.php
├── Policies/{DkimKeyPolicy,MtaStsPolicyPolicy,DnsDiagnosticsPolicy}.php
├── Rules/{DkimPrivateKey,MtaStsMxHost}.php            # DkimSelector is 002's, reused
├── Services/
│   ├── Dkim/{DkimKeyService,DkimKeyGenerator,DkimPublicKey,DkimNameResolver,DkimEntryQuery,
│   │         DkimDnsPublisher,DkimDnsOutcome,DkimAudit,IspconfigSettings,
│   │         value objects}.php                       # generator, publisher, outcome shared with 002
│   ├── MtaSts/{MtaStsPolicyService,MtaStsPolicyId,MtaStsPolicyText,MtaStsAudit}.php
│   ├── DnsDiagnostics/{DnsDiagnosticsService,DnsDiagnosticsReport,DnsCheckResult,DnsCheckMessage,
│   │                   ExpectedRecordSet,RecordComparator,ServerFactsCache,ZoneFileRenderer,
│   │                   TlsaDigest,DiagnosticsRateLimiter,Spf/{SpfEvaluator,SpfResolverAdapter}}.php
│   └── Mailcow/AutodiscoverConfig.php
└── Support/Clock/{Clock,SystemClock,Deadline}.php        # 002's App\Support\Domain\DomainName is reused

app/Services/Audit/RefusalAuditor.php                # existing (002/008); resolvers for dkimKey and mtaStsPolicy registered

resources/redis/dkim-key-store.lua                    # the atomic DKIM script
resources/bin/export-mailcow-vars.php                 # 001's script, extended (SHOW_DKIM_PRIV_KEYS,
                                                      #   autodiscover_config)
config/restv2.php                                     # 001's file, extended with research R24
routes/api.php                                        # 001's file, extended
deploy/docker-compose.override.yml                    # port variables added (dns: and dns_opt from 009)
docker/php/Dockerfile                                 # bind-tools in the dev stage

tests/
├── Support/{Fakes/FakeIspconfigDnsClient (002, extended),Dkim/{DkimFixtures,DkimAssertions},Dns/{FakeDnsResolver,
│            FakeClock},Smtp/FakeSmtpProbe (009, extended)}
├── Feature/Dkim/{ListDkimKeysTest,GetDkimKeyTest,DnsCapabilitiesTest,DkimReadAuthorizationTest,
│                 GenerateDkimKeyTest,DkimConcurrencyTest,DkimCreationStepTest,ImportDkimKeyTest,
│                 CopyDkimKeyTest,DeleteDkimKeyTest,DkimWriteAuthorizationTest,
│                 ExportDkimPrivateKeyTest,DkimDnsPublicationTest,DkimAuditTrailTest,
│                 DkimSecretLeakTest,DkimListPerformanceTest}.php
├── Feature/MtaSts/{CreateMtaStsPolicyTest,UpdateMtaStsPolicyTest,ListMtaStsPoliciesTest,
│                   GetMtaStsPolicyTest,MtaStsAuthorizationMatrixTest,MtaStsAuditTrailTest}.php
├── Feature/DnsDiagnostics/{RunDnsDiagnosticsTest,DnsDiagnosticsStatusesTest,DnsDiagnosticsTimeoutTest,
│                           DnsDiagnosticsRateLimitTest,DnsDiagnosticsAuthorizationTest,
│                           DnsDiagnosticsPerformanceTest}.php
├── Feature/Audit/DkimMtaStsAuditCountTest.php
├── Unit/Support/{MailcowConfigDkimTest,DomainNameMxHostTest}.php
├── Unit/Dkim/{DkimKeyStoreTest,DkimKeyGeneratorTest,DkimPublicKeyTest,DkimPrivateKeyRuleTest,
│              DkimDnsPublisherTest,JsonIspconfigDnsClientTest,DkimNameResolverTest}.php
├── Unit/MtaSts/{MtaStsPolicyIdTest,MtaStsPolicyTextTest}.php
├── Unit/Clients/{NativeDnsResolverTest,StreamSmtpProbeTest}.php   # 009's, extended
├── Unit/Audit/MailDnsRefusalResolverTest.php
├── Unit/DnsDiagnostics/{RecordComparatorTest,SpfEvaluatorTest,ZoneFileRendererTest,
│                        TlsaDigestTest,PublicAddressDetectorTest,
│                        ExpectedRecordSetTest}.php
└── fixtures/legacy/dkim/{generated-2048.pem,generated-2048.pub,README.md}
```

**Structure Decision**: The single Laravel project of 001. Three services own the writes and side
effects: `DkimKeyService` (DKIM keys and their ISPConfig records, also used by 002 and 004),
`MtaStsPolicyService` (MTA-STS rows), and `DnsDiagnosticsService` (read-only, outbound lookups).
External systems sit behind `app/Clients/` (ISPConfig from 002, DNS and SMTP from 009, extended): the DKIM Redis store (real in tests), ISPConfig, the
DNS resolver and the SMTP probe (faked in tests). Controllers stay thin, one per resource or action
sub-resource.

## Dependencies on other features

- **001**: identity, scope, roles, API keys, pipeline, problem rendering, `MailcowConfig`,
  `AuditLogger`, contract validation. Required before anything here.
- **002**: domains (`domain` table rows in tests through `MailcowFactory`), the default DKIM
  selector setting (FR-028 there), the audit target filters (FR-058 there) and the
  `temporary-failure` problem type. **002 also depends on this feature**: its domain creation calls
  `DkimKeyService::generateForNewName()` (FR-027 there), so Phase 2 of `tasks.md` (the DKIM core)
  is implemented together with feature 002, before 002's creation tasks.
- **004**: alias domain creation calls `generateForNewName()` too (FR-042 there); by then Phase 2
  exists.
- **008**: the shared `RefusalAuditor` (route defaults `restv2.audit`), unless an earlier feature
  created the equivalent.
- **009**: `App\Clients\Dns\DnsResolver` with `NativeDnsResolver` and `DnsTemporaryFailure`,
  `App\Clients\Smtp\SmtpProbe` with `StreamSmtpProbe`, their fakes and the override's `dns:` and
  `dns_opt`; this feature extends them (research R16, R19).

## Owner-level questions (defaults applied)

1. **Bulk deletion of DKIM keys**: `docs/conventions.md` calls for bulk actions where legacy has
   mass actions (the legacy DKIM screen can remove several keys at once), but spec FR-059 excludes
   batch operations and the UI 010 spec is built on one request per name. Default: no bulk
   operation; it can be added later as an additive change.
2. **ISPConfig endpoint**: the fork configures a SOAP endpoint in `inc/ispconfig.inc.php`; this
   API (with feature 002) uses ISPConfig's JSON remote API with the same remote user and functions,
   configured through `RESTV2_ISPCONFIG_*`. Default: JSON, with the operator copying the values.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Status `503` (not in constitution II's list) for `temporary-failure` when mailcow's Redis is unavailable | FR-052 requires a distinct, retryable temporary failure that leaves no partial change; RFC 9110 defines `503` with `Retry-After` for it, and 001 and 002 use the same. | `500` cannot be told apart from a bug, so clients would not retry. |
| `RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset` headers from an IETF draft | Orchestrator decision (spec FR-049): clients must see the limit before they reach it. The draft's names are the de-facto standard. | Laravel's `X-RateLimit-*` headers are non-standard; the final RFC's structured `RateLimit` field is not yet supported by common clients. |
| Lua script on mailcow's Redis | FR-015, FR-020, FR-052 and FR-053 require all-or-nothing changes and exactly one winner among concurrent changes, also against legacy writers. | Separate `HSET`s (legacy) leave partial keys; `WATCH`/`MULTI` aborts on any change to the shared hashes and retries in a loop. |
| The audit record of a DKIM change is not in the same transaction as the change | The change lives in Redis and the audit record in MariaDB; the publication outcome must be in the record. | One transaction is impossible across the two stores; the compensating restore (research R13) keeps "no audit record, no change". |
