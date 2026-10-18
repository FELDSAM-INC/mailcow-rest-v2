# Implementation Plan: Identity Providers and OAuth2 Clients

**Branch**: `main` (no feature branches, constitution 1.1.0) | **Date**: 2026-10-18 |
**Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/012-identity-providers-oauth2/spec.md`

## Summary

Feature 012 covers four areas:

- the administrator's management of the server's single external identity provider (Keycloak,
  generic OIDC or LDAP): read, whole save, change, delete and connection test, with write-only
  secrets, attribute mapping to mailbox templates, mailbox counts per external source and a
  synchronisation status derived from the legacy jobs' log;
- single sign-on of mailbox users into the new web app through Keycloak or generic OIDC, with a
  session-bound single-use state, PKCE, mailbox creation and template updates on sign-in, provider
  token renewal every 5 minutes, and the reporting of provider-side errors;
- password sign-in of LDAP and Keycloak (mailpassword flow) mailboxes through feature 001's
  `POST /session`, including creation on first sign-in, with provider outages kept away from
  fail2ban and throttling;
- the clients of mailcow's own OAuth2 provider: list, create, change redirect URIs, regenerate the
  secret, revoke tokens, delete, and bulk delete or revoke.

The technical approach:

- The contract was authored first: 19 new operations in three modules (`identity-provider`,
  `oauth2-clients`, `single-sign-on`), including two capabilities reads, plus additive changes to
  feature 001's components. It lints clean.
- The configuration is stored in upstream `identity_provider` with legacy's keys and byte-identical
  encodings, so the legacy UI, `mailcowauth.php` and the legacy synchronisation jobs keep working
  on it (research R9).
- OIDC uses legacy's own `league/oauth2-client` with PKCE, on a Guzzle client built by Laravel's
  HTTP client so that `Http::fake()` works (R1). LDAP uses the `ldap` PHP extension through a thin
  adapter that reproduces legacy's LdapRecord behaviour (R2). The Keycloak service token is shared
  with legacy through its cache row (R3).
- Mailboxes are created and updated only through feature 003's mailbox service, with legacy's
  template marker (R6).
- OAuth2 clients are managed on the legacy `oauth_*` tables; compatibility with the unchanged
  `/oauth/*` endpoints is proven in tests with legacy's OAuth2 server library (R13).
- No API-owned table is added. Short-lived data lives in 001's session and in `restv2:` Redis keys;
  audit records go to 001's audit log.

## Technical Context

**Language/Version**: PHP 8.4 (constitution: 8.3 or newer), Laravel 13.x, as feature 001.

**Primary Dependencies**: feature 001's stack (001 plan) plus `league/oauth2-client` ^2.8 (MIT,
runtime) and the PHP extension `ldap`; dev: `bshaffer/oauth2-server-php` ^1.11 (MIT). Justified in
[research R18](./research.md#r18-composer-dependencies-constitution-viii).

**Storage**: mailcow's MariaDB 10.11 with the upstream `2026-09a` schema. Read and written through
services: `identity_provider`, `oauth_clients`, `oauth_access_tokens`, `oauth_refresh_tokens`,
`oauth_authorization_codes`. Read: `mailbox`, `domain`, `templates`. Written through feature 003's
mailbox service: the mailbox rows of a creation or template update. Written through feature 001's
services: `sasl_log`, `tfa`, `restv2_audit_events`. Redis: 001's session, `restv2:sso-link:*` and
two lock keys; mailcow's `CRON_LOG` is read. No migration.

**Testing**: PHPUnit 12 feature and unit tests, written first, on MariaDB with the upstream schema
fixture and a real Redis, with contract validation of every request and response (001 R3, R13).
Providers are faked: OIDC and Keycloak HTTP with `Http::fake()` (R1, R3), LDAP with
`tests/Support/Fakes/FakeLdapDirectory.php` (R2). The real LDAP adapter is tested against OpenLDAP
in the dev stack's `idp` profile. OAuth2 compatibility is tested with `bshaffer/oauth2-server-php`.

**Target Platform**: feature 001's container (`php:8.4-fpm-alpine`, non-root, read-only root
filesystem), with `ext-ldap` added to the image.

**Project Type**: web service (REST API).

**Performance Goals**: SC-003: the API's own steps of a single sign-on add < 2 s at p95; they are
two provider calls (token exchange and user info, 7 s time-out each) plus a few indexed queries, and
a creation through 003. Configuration and OAuth2 reads take p95 < 150 ms, including the
`CRON_LOG` scan, which normally reads one chunk of 500 entries. Session renewal adds at most two
provider calls per 5 minutes per single sign-on session.

**Constraints**:

- No change to upstream tables, containers or files; the legacy UI, `/api/v1`, the legacy single
  sign-on, `mailcowauth.php`, the synchronisation jobs and `/oauth/*` keep working on the same data.
- Secrets (client secret, bind password, service token, provider tokens, codes, state, PKCE
  verifier, OAuth2 client secrets after creation or regeneration) never appear in responses, logs
  or audit records.
- Provider calls time out after 7 s and verify TLS unless the configuration ignores TLS errors.
- Provider outages are never reported to fail2ban or counted towards throttling.

**Scale/Scope**: one provider per server; up to about 10,000 mailboxes; up to a few hundred OAuth2
clients with up to about 100,000 tokens. 19 new operations in 3 modules, 5 user stories, no new
table.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design (below).*

| Principle | Gate | Status |
|---|---|---|
| I. Contract-first | All 19 operations are authored under `api/modules/{identity-provider,oauth2-clients,single-sign-on}/` with new components named after their resource. Feature 001's components get additive changes only (new enum values, new optional or new required response members, a `503` on `createSession`). `npx @redocly/cli@2.64.1 lint` passes with 0 errors. Every test request and response is validated (001 R13). | PASS |
| II. HTTP contract | Plural nouns; actions are sub-resources (`/connection-tests`, `/secret-regeneration`, `/token-revocation`, `/bulk-actions`, `/session/single-sign-on/completion`). `PUT` answers `201` with `Location` when it creates, `200` when it replaces; `PATCH` is JSON Merge Patch; `DELETE` answers `204`. `{data, meta}` list with `limit`, `offset`, `sort`, `order`, `q`. RFC 9457 problems with six documented types. `ETag` and `If-Match` on the configuration and on clients; every list entry carries `etag`. `503` justified below. | PASS (justified) |
| III. Upstream schema | No migration at all. Upstream tables are written only by the services listed in the data model. | PASS |
| IV. Parity | Legacy code was read (`identity_provider()`, `user_login()`, `keycloak_mbox_login_rest()`, `ldap_mbox_login()`, `mailbox_from_template`, `oauth2()`, the jobs, the `/oauth/*` endpoints and libraries). Every write goes through a service and reproduces legacy's side effects: settings upsert, service token clearing, mailbox creation and update through 003, `sasl_log`, `tfa` re-activation. Deviations are those of the spec's parity table, including the four plan-level rows (research R19). | PASS |
| V. Coexistence | The synchronisation jobs and `/oauth/*` stay in php-fpm, unchanged. The configuration and the service token cache are shared with legacy by design (spec). Sessions stay independent. | PASS |
| VI. Security | Administrator-only policies; read-only keys refused for writes and tests; single sign-on never with a key. Session-bound single-use state, PKCE, encrypted provider tokens in Redis, renewal every 5 minutes. Write-only secrets with the address rule (FR-014). Every change and test audited without secrets; a secret-scan test. LDAP filter escaping. Provider outages kept away from fail2ban. | PASS (justified) |
| VII. Test-first | `tasks.md` orders tests before implementation in every phase. MariaDB with the real schema, real Redis; HTTP providers faked with `Http::fake()`, LDAP behind an interface, OAuth2 endpoints simulated with legacy's own library. | PASS |
| VIII. Simplicity | One runtime package (legacy's own OAuth 2.0 client) and one dev package (legacy's OAuth2 server). No LDAP package. No API-owned table. Interfaces only where a fake is needed (`OidcClient`, `LdapDirectory`, `KeycloakAdminClient`). | PASS |
| Cross-feature conventions | Capabilities reads at `/capabilities/identity-provider` and `/capabilities/oauth2-clients`. History through the generic `targetType`/`targetId` filters only (no own audit filter). Refusals by authenticated callers audited through the shared `RefusalAuditor` (read-only keys and restricted sessions included; not CSRF failures or `401`s). Version tags carry secrets only as an HMAC keyed with the app key. Check-and-insert races guarded with MariaDB `GET_LOCK()`. Out-of-scope or missing clients answer `404`; `403` only for roles and key scope. Invalid input refused with field errors and shared rule codes (`immutable`, `conflictsWith`, plus `secretRequired`, `duplicate`). Bulk actions on OAuth2 clients follow the conventions (100 items, per-item version, one audit record per client, `BulkTarget`, `BulkResultResponse`). Secrets write-only or shown once. Version tags over stored settings only. No time-based caching. Settings owned by 003 (mailbox values) are applied through 003. | PASS |

**Post-design re-check (after Phase 1)**: PASS. The decisions that touched gates:

- Provider tokens outside the session payload, encrypted, with a renewal lock: VI (R7).
- Sharing legacy's service token row, excluded from the version tag: IV, V (R3, R9).
- Synchronisation status derived read-only from `CRON_LOG`: V, no legacy change (R12).
- Bulk actions added by the orchestrator, replacing the spec's earlier exclusion: conventions (R14).
- Capabilities reads, refusal auditing, HMAC version tags and `GET_LOCK()` from the conventions'
  "Capabilities, history and refusals" section: I, VI (R9, R13, R15, R20).

## Contract Operations

| Method and path | operationId | Auth | Story / FR |
|---|---|---|---|
| GET `/capabilities/identity-provider` | getIdentityProviderCapabilities | admin session or admin key (read-only too) | US1, FR-064 |
| GET `/identity-provider` | getIdentityProvider | admin session or admin key (read-only too) | US1, FR-001–FR-008, FR-018 (documented), FR-059, FR-062 |
| PUT `/identity-provider` | saveIdentityProvider | admin session + CSRF, or admin read-write key | US1, FR-009–FR-016, FR-050–FR-052 |
| PATCH `/identity-provider` | updateIdentityProvider | as PUT | US1, FR-009–FR-016, FR-050–FR-052 |
| DELETE `/identity-provider` | deleteIdentityProvider | as PUT | US1, FR-017, FR-051, FR-052 |
| POST `/identity-provider/connection-tests` | testIdentityProviderConnection | as PUT | US1, FR-019, FR-052 |
| POST `/session/single-sign-on` | startSingleSignOn | browser session + CSRF | US2, FR-020, FR-021 |
| DELETE `/session/single-sign-on` | cancelSingleSignOn | browser session + CSRF | US2, edge case "user cancels" |
| POST `/session/single-sign-on/completion` | completeSingleSignOn | browser session + CSRF | US2, FR-022–FR-028, FR-034–FR-040 |
| POST `/session/single-sign-on/provider-error` | reportSingleSignOnProviderError | browser session + CSRF | US2, FR-063 |
| GET `/capabilities/oauth2-clients` | getOauth2ClientsCapabilities | admin session or admin key (read-only too) | US4, FR-049, FR-064 |
| GET `/oauth2-clients` | listOauth2Clients | admin session or admin key | US4, FR-041, FR-042 |
| POST `/oauth2-clients` | createOauth2Client | admin session + CSRF, or admin read-write key | US4, FR-043, FR-044, FR-049 (endpoints documented in the tag) |
| POST `/oauth2-clients/bulk-actions` | bulkOauth2ClientAction | as create | US4, FR-060, FR-061 |
| GET `/oauth2-clients/{oauth2ClientId}` | getOauth2Client | admin session or admin key | US4, FR-041, FR-042 |
| PATCH `/oauth2-clients/{oauth2ClientId}` | updateOauth2Client | as create | US4, FR-044, FR-045 |
| DELETE `/oauth2-clients/{oauth2ClientId}` | deleteOauth2Client | as create | US4, FR-048 |
| POST `/oauth2-clients/{oauth2ClientId}/secret-regeneration` | regenerateOauth2ClientSecret | as create | US4, FR-046 |
| POST `/oauth2-clients/{oauth2ClientId}/token-revocation` | revokeOauth2ClientTokens | as create | US4, FR-047 |

**Changes to feature 001's operations and components** (additive):

| Component | Change | FR |
|---|---|---|
| `POST /session` (`createSession`) | description of provider password sign-in; new `503` (`IdentityProviderUnavailable`) | FR-029–FR-033 |
| `PublicConfig.externalIdentityProvider` | new required `singleSignOnAvailable` | FR-020 |
| `LoginPageOptions.forceSingleSignOn` | documented as display-only, passkeys kept | FR-020 |
| `AuthMethod`, `Session.authMethod` | `singleSignOn`, `directoryPassword[AndSecondFactor]`, `providerPassword[AndSecondFactor]` | FR-024, FR-053 |
| `IdentityAuthentication` | optional `identityProvider` | FR-024 |
| `Unauthorized` | type `identity-provider-session-ended` | FR-026 |
| Shared enums | `KnownProblemType` (6), `AuditEventType` (10), `AuditTarget` (2), `AuditFailureReason` (12), `ValidationRuleCode` (2) | FR-052–FR-057 |

Problem types are documented in [`docs/problems.md`](../../docs/problems.md).

## Project Structure

### Documentation (this feature)

```text
specs/012-identity-providers-oauth2/
├── spec.md              # Feature specification (clarifications, parity table)
├── plan.md              # This file
├── research.md          # Phase 0: decisions R1–R19
├── data-model.md        # Phase 1: upstream storage, Redis, audit values, state machines
├── quickstart.md        # Phase 1: validation guide
├── contracts/README.md  # Phase 1: pointer to api/ (the contract is not duplicated)
├── checklists/requirements.md
├── tasks.md             # Phase 2 (/speckit-tasks)
└── analysis.md          # /speckit-analyze summary
```

### Source Code (repository root)

Only the paths this feature adds or changes are listed; everything else is feature 001's layout
(001 plan) and feature 003's mailbox service.

```text
api/
├── openapi.yaml                                   # tags + 12 path refs added
├── modules/identity-provider/{_index,identity-provider,identity-provider-connection-tests,
│                              identity-provider-capabilities}.yaml
├── modules/oauth2-clients/{_index,oauth2-clients,oauth2-clients-capabilities,oauth2-client,oauth2-client-bulk-actions,
│                           oauth2-client-secret-regeneration,oauth2-client-token-revocation}.yaml
├── modules/single-sign-on/{_index,session-single-sign-on,session-single-sign-on-completion,
│                           session-single-sign-on-provider-error}.yaml
└── components/
    ├── schemas/IdentityProvider*.yaml, SingleSignOn*.yaml, Oauth2Client*.yaml
    ├── parameters/{Oauth2ClientId,Oauth2ClientSort}.yaml
    └── responses/IdentityProvider*.yaml, SingleSignOn*.yaml, Oauth2Client*.yaml

app/
├── Auth/{IdentityProviderType,PasswordSource}.php          # new enums; AuthMethod, Principal extended
├── Clients/
│   ├── Ldap/{LdapDirectory,ExtLdapDirectory,LdapConnectionSettings,LdapResult}.php
│   ├── Oidc/{OidcClient,LeagueOidcClient,OidcEndpoints,OidcTokens,OidcFailure}.php
│   └── Keycloak/{KeycloakAdminClient,HttpKeycloakAdminClient}.php
├── Http/
│   ├── Controllers/Api/V2/{IdentityProviderController,IdentityProviderConnectionTestController,
│   │                       IdentityProviderCapabilitiesController,Oauth2ClientCapabilitiesController,
│   │                       SingleSignOnController,SingleSignOnCompletionController,
│   │                       SingleSignOnProviderErrorController,Oauth2ClientController,
│   │                       Oauth2ClientSecretRegenerationController,Oauth2ClientTokenRevocationController,
│   │                       Oauth2ClientBulkActionController}.php
│   ├── Middleware/RequireAuth.php                          # 001; calls IdentityProviderSessionGuard
│   ├── Requests/Api/V2/Concerns/IdentityProviderRules.php      # shared per-type rule sets
│   ├── Requests/Api/V2/{SaveIdentityProviderRequest,UpdateIdentityProviderRequest,
│   │                    TestIdentityProviderConnectionRequest,CompleteSingleSignOnRequest,
│   │                    ReportSingleSignOnProviderErrorRequest,StoreOauth2ClientRequest,
│   │                    UpdateOauth2ClientRequest,Oauth2ClientBulkActionRequest,
│   │                    ListOauth2ClientsRequest}.php
│   └── Resources/Api/V2/{IdentityProviderResource,IdentityProviderConnectionTestResource,
│                         IdentityProviderCapabilitiesResource,Oauth2ClientCapabilitiesResource,
│                         SingleSignOnStartResource,Oauth2ClientResource,Oauth2ClientWithSecretResource}.php
├── Models/Mailcow/{Oauth2Client,Oauth2AccessToken,Oauth2RefreshToken,Oauth2AuthorizationCode}.php
├── Policies/{IdentityProviderPolicy,Oauth2ClientPolicy}.php
├── Rules/{HttpUrl,KeycloakRealm,KeycloakVersion,ScopeList,HostName,LdapAttributeName,LdapFilter,
│          ExistingMailboxTemplate,UniqueAttributeValues,RedirectUri}.php
├── Logging/RedactSecrets.php                               # 001; more redacted keys
├── Services/
│   ├── IdentityProvider/
│   │   ├── IdentityProviderConfig.php, IdentityProviderSettingsCodec.php
│   │   ├── IdentityProviderRepository.php                  # reads rows, ETag, modifiedAt, counts
│   │   ├── IdentityProviderConfigService.php               # save, change, delete (upstream writes)
│   │   ├── SecretRetentionPolicy.php, IdentityProviderUsage.php, AppReturnUrls.php
│   │   ├── SynchronisationStatusReader.php, SynchronisationStatus.php
│   │   ├── ConnectionTester.php, ConnectionTestResult.php
│   │   ├── KeycloakServiceTokenCache.php                   # access_token row (upstream write)
│   │   ├── ProviderPasswordVerifier.php, ProviderVerification.php
│   │   ├── SingleSignOnService.php, IdentityProviderSessionGuard.php, SsoLinkStore.php
│   │   ├── MailboxProvisioner.php, TemplateMarker.php
│   │   └── IdentityProviderAuditTargetResolver.php         # for 002/008's RefusalAuditor
│   ├── Oauth2/{Oauth2ClientService,Oauth2ClientAuditTargetResolver}.php  # oauth_* writes; refusal targets
│   ├── Config/MailcowConfig.php                            # 001; token lifetimes added
│   └── Auth/{AccountResolver,PasswordSignInService,PendingActionEvaluator,SessionManager}.php  # 001, extended
└── Support/Http/{ProblemType,BulkResult}.php               # 001: six cases added; BulkResult unless 002 has it

config/restv2.php                                            # RESTV2_IDP_*, RESTV2_SSO_* settings
docker/php/Dockerfile                                        # ext-ldap
compose.yaml                                                 # profile idp: openldap, keycloak
deploy/docker-compose.override.yml                           # ADDITIONAL_SERVER_NAMES, optional settings
scripts/legacy/extract-sync-log-texts.sh                     # cron log texts of a tag (R12)
resources/bin/export-mailcow-vars.php                        # 001; exports ACCESS/REFRESH_TOKEN_LIFETIME
routes/api.php                                               # 19 routes

tests/
├── Support/
│   ├── Fakes/FakeLdapDirectory.php
│   ├── IdentityProvider/{IdentityProviderFixtures,FakeOidcProvider}.php
│   └── Mailcow/MailcowFactory.php                          # IdP configs, oauth rows, cronLog
├── Feature/IdentityProvider/*Test.php
├── Feature/SingleSignOn/*Test.php
├── Feature/Auth/{LdapPasswordSignIn,KeycloakPasswordSignIn,ProviderPasswordFailures,
│                ProviderPasswordProvisioning}Test.php
├── Feature/Oauth2/*Test.php
├── Feature/{PublicConfig/PublicConfigSingleSignOn,Audit/IdentityProviderAudit,
│            Performance/SingleSignOnLatency}Test.php
├── Feature/Security/{IdentityProviderAuthorizationMatrix,IdentityProviderSecretScan}Test.php
├── Unit/{IdentityProvider,Rules,Clients}/*Test.php
├── Integration/Ldap/ExtLdapDirectoryTest.php               # group idp, OpenLDAP container
└── fixtures/{cron-log/2026-09a.json,cron-log/2026-09a-messages.json,ldap/bootstrap.ldif}
```

**Structure Decision**: The single Laravel project of feature 001. Business logic lives in
`app/Services/IdentityProvider/` (configuration, sign-in through the provider, provisioning) and
`app/Services/Oauth2/`. External systems sit behind `app/Clients/` (OIDC, LDAP, Keycloak admin).
Controllers are thin and one per resource or action. Feature 001's sign-in classes are extended in
place, and feature 003's mailbox service is called, never duplicated.

**Dependencies on earlier features' code** (features are implemented in number order):

- **001**: `ApiFormRequest`, `ListQuery`, `ProblemType`, `Problem`, `ETag`, `AuditLogger`,
  `SessionManager`, `RequireAuth`, `EnforceKeyScope`, `PasswordSignInService`, `AccountResolver`,
  `PendingActionEvaluator`, `SecondFactorService`, `RecentLoginRecorder`, `SignInThrottle`,
  `Fail2BanReporter`, `LegacyPasswordVerifier`, `PublicConfigService`, `IdentityService`,
  `MailcowFactory`, `OpenApiContract`, `AuthorizationMatrixTest` conventions.
- **002**: the audit filters `targetType` and `targetId`; domain rows in fixtures; the bulk result
  builder, if 002's plan creates one (`App\Support\Http\BulkResult`), otherwise this feature
  creates it.
- **002 or 008**: the shared `App\Services\Audit\RefusalAuditor` and its route default
  `restv2.audit` (whichever is implemented first creates it; this feature registers two target
  resolvers).
- **003**: the mailbox creation and change services (named in 003's plan; here
  `MailboxService::create()` and `::update()`), the `MailboxTemplate` model, mailbox fixtures and
  templates in `MailcowFactory`, and 003's clearing of `attribute_hash` on edits, to which this
  feature adds a "record marker" option.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Status `503` (not in constitution II's list) for `identity-provider-unavailable` | FR-033 and FR-022 require a distinct, retryable failure when the provider is unreachable, so that clients do not present it as a wrong password and fail2ban is not involved. RFC 9110 defines `503` with `Retry-After` for this, as 001 does for Yubico. | `500` reads as a bug; `401 sign-in-failed` hides the outage and would push users to retry passwords. |
| Ceremony requests not audited (constitution VI): `POST /session/single-sign-on` and `DELETE /session/single-sign-on` | They change only the caller's own short-lived session data and are anonymous. The outcome they lead to is always audited: the completion, its failure, or the provider-error report. Same reasoning as 001's challenges. | Auditing every start would let anonymous clients write unbounded audit rows without recording anything the completion does not. |
