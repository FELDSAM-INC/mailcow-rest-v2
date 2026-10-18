---
description: "Task list for feature 012: identity providers and OAuth2 clients"
---

# Tasks: Identity Providers and OAuth2 Clients

**Input**: Design documents from `specs/012-identity-providers-oauth2/`: [plan.md](./plan.md),
[spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md),
[quickstart.md](./quickstart.md). The contract is in `api/` (modules `identity-provider`,
`oauth2-clients`, `single-sign-on`, already authored and lint-clean). Problem types are in
`docs/problems.md`.

**Tests**: Mandatory (constitution VII, test-first). In every phase the test tasks come first and
must be observed **failing** before that phase's implementation starts. Feature tests run on
MariaDB with the upstream `2026-09a` schema fixture and a real Redis, and every request and response
is validated against the contract by `tests/TestCase.php` (001 T017). Providers are faked: OIDC and
Keycloak HTTP with `Http::fake()` through `tests/Support/IdentityProvider/FakeOidcProvider.php`, LDAP
with `tests/Support/Fakes/FakeLdapDirectory.php`. fail2ban publications are asserted with 001's
`tests/Support/Redis/ChannelSpy.php`.

**Prerequisites in code** (features are implemented in number order):

- **001** complete: the test toolkit (`TestCase`, `OpenApiContract`, `MailcowFactory`,
  `ChannelSpy`, `signInAs()`), `ApiFormRequest`, `ListQuery`, `ProblemType`, `Problem`, `ETag`,
  `AuditLogger`, `SessionManager`, `RequireAuth`, `EnforceKeyScope`, `EnforcePendingActions`,
  `AccountResolver`, `PasswordSignInService`, `SecondFactorService`, `PendingActionEvaluator`,
  `RecentLoginRecorder`, `SignInThrottle`, `Fail2BanReporter`, `LegacyPasswordVerifier`,
  `PublicConfigService`, `IdentityService`, `MailcowConfig`, `AuditEventController`.
- **002** complete: domain fixtures, the audit filters `targetType` and `targetId`, and the bulk
  result builder if 002 created one (otherwise T013 creates it).
- **002 or 008** complete: the shared `App\Services\Audit\RefusalAuditor` with its route default
  `restv2.audit` and target resolver registry (`docs/conventions.md`, "Refusal auditing").
- **003** complete: the mailbox creation and change services (called `MailboxService::create()` and
  `MailboxService::update()` here; use the names of 003's plan), the `MailboxTemplate` model,
  `MailcowFactory::mailboxTemplate()`, and the clearing of `attributes.attribute_hash` on edits.

**Organisation**: Phase 1 setup, Phase 2 foundation (blocks every story), Phases 3–7 one user story
each in spec priority order (US1–US3 P1, US4 P2, US5 P3), Phase 8 polish.

**Commands**: every PHP, Composer and Node command runs in the dev container:
`docker compose run --rm app <command>`.

## Format: `- [ ] [ID] [P?] [Story?] Description with file path`

- **[P]**: can run in parallel with the other [P] tasks of the same block (different files, no
  dependency on unfinished tasks).
- **[USn]**: the user story; Setup, Foundational and Polish tasks have none.
- FR, SC and AS refer to `spec.md`, R to `research.md`.

---

## Phase 1: Setup (shared infrastructure)

**Purpose**: Extension, packages, settings, dev services. No business logic.

- [X] T001 Add the `ldap` extension to `docker/php/Dockerfile` (R2): in the `base` stage install
  `openldap-dev` as a build dependency, run `docker-php-ext-install ldap`, keep `libldap` (and
  `ca-certificates`) at runtime and remove the build dependency in the same layer. Rebuild the `dev`
  and `production` targets and check `php -m | grep ldap` in both.
- [X] T002 Add the packages (R18): `composer require league/oauth2-client:^2.8` and
  `composer require --dev bshaffer/oauth2-server-php:^1.11`, updating `composer.json` and
  `composer.lock`. Confirm both licences are MIT in `composer licenses`.
- [X] T003 [P] Add the settings of R17 to `config/restv2.php` under the key `identity_provider`:
  `timeout` (`RESTV2_IDP_TIMEOUT`, 7), `retry_after` (`RESTV2_IDP_RETRY_AFTER`, 30),
  `sso_pending_ttl` (`RESTV2_SSO_PENDING_TTL`, 600), `sso_renewal_interval`
  (`RESTV2_SSO_RENEWAL_INTERVAL`, 300), `sso_return_path` (`RESTV2_SSO_RETURN_PATH`,
  `/ng/sso/return`), `sso_return_scheme` (`RESTV2_SSO_RETURN_SCHEME`, `https`), and
  `additional_server_names` (`ADDITIONAL_SERVER_NAMES`, comma-separated, default empty) if 001 did
  not add it. Add `RESTV2_SSO_RETURN_SCHEME=http` to `.env.example`, and
  `ADDITIONAL_SERVER_NAMES=webmail.example.org,mail.example.net` to `.env.testing`. Pass
  `ADDITIONAL_SERVER_NAMES` and the optional `RESTV2_IDP_*`/`RESTV2_SSO_*` variables through in
  `deploy/docker-compose.override.yml`.
- [X] T004 [P] Add the `idp` profile to `compose.yaml`: service `openldap`
  (`osixia/openldap:1.5.0`, `LDAP_ORGANISATION=Example`, `LDAP_DOMAIN=example.org`,
  `LDAP_ADMIN_PASSWORD=ldap-secret`, `LDAP_TLS=true` with its self-signed certificate, seeded from
  `tests/fixtures/ldap/bootstrap.ldif`) and service `keycloak` (`quay.io/keycloak/keycloak:26.0.7`,
  `start-dev`, admin `admin`/`admin`, port `8081:8080`). Create `tests/fixtures/ldap/bootstrap.ldif`
  with `ou=people,dc=example,dc=org`, the users `carl@example.org` (`title: staff`,
  `displayName: Carl Example`), `dana@example.org` (no `title`) and `a*b@example.org` (for escaping),
  all with password `Passw0rd!dev`.
- [X] T005 [P] Configure test groups: in `phpunit.xml` add the suite `Integration`
  (`tests/Integration`) and exclude the group `idp` by default. In `.github/workflows/ci.yml` add an
  `osixia/openldap:1.5.0` service to the `tests` job and a step `vendor/bin/phpunit --group idp`.
- [X] T006 [P] Create `scripts/legacy/extract-sync-log-texts.sh <tag>`, which reads
  `data/conf/phpfpm/crons/keycloak-sync.php` and `ldap-sync.php` of the tag with `git -C
  "$MAILCOW_REPO" show`, extracts every `logMsg(priority, message)` call with its priority and
  literal prefix, and writes `tests/fixtures/cron-log/<tag>-messages.json`. Run it for `2026-09a`
  and commit the fixture, plus a hand-written log sample `tests/fixtures/cron-log/2026-09a.json`
  (runs that complete, wait, fail with `Received HTTP 401`, and are in progress) (R12).

---

## Phase 2: Foundational (blocking prerequisites)

**Purpose**: Test doubles, value objects, the settings codec, clients, rules, models, policies and
problem types that every story needs. **No user story can start before this phase is complete.**

### Test toolkit and foundation tests (write first, observe failing) ⚠️

- [X] T007 [P] Extend `database/factories/MailcowRows.php` and
  `tests/Support/Mailcow/MailcowFactory.php` (001 T019) with rows written exactly as legacy writes
  them (data-model "Settings, contract members and encodings"):
  - `identityProviderRows(array $keyValues)` (raw rows, for legacy-stored cases);
  - `ldapConfig(array $overrides = [])`, `keycloakConfig(...)`, `genericOidcConfig(...)`, which write
    the full key set with legacy encodings (`'1'`/`''` booleans, `'0'`/`'1'` flags, JSON lists),
    secrets `ldap-secret` and `kc-secret`;
  - `oauthClient(redirectUri, clientId = null, secret = null)`, `oauthAccessToken(clientId,
    expires)`, `oauthRefreshToken(clientId, expires)`, `oauthAuthorizationCode(clientId, expires)`;
  - `cronLog(array $entries)` pushing JSON `{time, priority, task, message}` onto `CRON_LOG` on the
    `mailcow` Redis connection (newest last pushed);
  - reuse 003's `mailboxTemplate(name, attributes)` and 001's `mailbox(..., authsource)`.
- [X] T008 [P] Create `tests/Support/Fakes/FakeLdapDirectory.php` implementing
  `App\Clients\Ldap\LdapDirectory`: scripted entries per filter, scripted bind results per DN
  (`ok`, code 49, unavailable, TLS failure, timeout), recorded calls (connection settings, filter
  strings, bind DNs, never passwords in assertion messages) and assertions `assertSearched(filter)`,
  `assertBoundAs(dn)`, `assertNotContacted()`.
- [X] T009 [P] Create `tests/Support/IdentityProvider/FakeOidcProvider.php` on `Http::fake()`:
  helpers for the Keycloak and generic OIDC token endpoint (authorization code, refresh token,
  client credentials, invalid code), the user info endpoint, Keycloak introspection and
  `admin/realms/<realm>/users`, each answering success, an OAuth error (`invalid_grant`,
  `invalid_client`, `unauthorized_client`), a status code, a connection error with a cURL error
  number (6, 7, 28, 60) or a redirect; plus assertions on sent form fields (`code_verifier`,
  `redirect_uri`, `client_id`, `client_secret`, `grant_type`), on the `verify` option and on
  "nothing sent". Create `tests/Support/IdentityProvider/IdentityProviderFixtures.php` with the
  standard Keycloak, generic OIDC and LDAP request bodies and user-info payloads.
- [X] T010 [P] Write `tests/Unit/IdentityProvider/IdentityProviderSettingsCodecTest.php` (R9):
  - reading: FR-007 defaults for missing keys; `'1'`, `''`, `'0'` booleans; `redirect_url_extra`,
    `mappers`, `templates` as a JSON list and as a legacy JSON object (`{"0":"a","2":"b"}`);
    mapping lists of different lengths pair only the shorter length; rows of another type ignored;
    empty strings read as `null`; `effectivePort` 636 for SSL on 389;
  - completeness and `missingSettings` per type (FR-008), including a legacy row set with empty
    `client_secret`;
  - writing: the exact key set and byte-identical values per type for a reference input (assert the
    row arrays), `server_url` trailing slashes removed, `host` joined without spaces,
    `username_field`/`attribute_field` lower-cased, StartTLS stored `''` with SSL, `access_token`
    `''`, `default_template` `''` for none, `json_encode` default flags (`"https:\/\/…"`);
  - the version tag ignores `access_token` and changes when any other value, including a secret,
    changes; secrets enter only as `hash_hmac('sha256', value, config('app.key'))`, so the tag input
    never contains the secret or its plain SHA-256 (`docs/conventions.md`).
- [X] T011 [P] Write `tests/Unit/IdentityProvider/TemplateMarkerTest.php`: for template attribute
  strings with `/`, non-ASCII characters and nested JSON, `TemplateMarker::for($text)` equals
  `sha1(json_encode($text))` as computed by a verbatim copy of legacy's expression kept in the test
  with its source reference (`functions.mailbox.inc.php`, `mailbox_from_template`) (R6).
- [X] T012 [P] Write `tests/Unit/IdentityProvider/AppReturnUrlsTest.php` (R4):
  `MAILCOW_HOSTNAME=mail.example.org`, `ADDITIONAL_SERVER_NAMES=" webmail.example.org,MAIL.example.net,,bad host"`
  → list `https://mail.example.org/ng/sso/return`, `https://webmail.example.org/ng/sso/return`,
  `https://mail.example.net/ng/sso/return`; selection for host `Mail.Example.NET:443` → the third;
  unknown host → the first; scheme and path from config.
- [X] T013 [P] Write `tests/Unit/Rules/IdentityProviderRulesTest.php`, one data provider per rule
  with valid and invalid values and the expected rule code and params (R10): `HttpUrl`
  (`ftp://x`, `/relative`, 2,001 characters → `invalidFormat` `httpUrl` or `tooLong`),
  `KeycloakRealm` (`a/b`), `KeycloakVersion` (`26.0.7` ok, `26.0.7.1.2`, `v26`), `ScopeList`
  (double space, `"`, 51 names), `HostName` (IPv6 ok, `bad host`), `LdapAttributeName` (`1mail`),
  `LdapFilter` (`(a=b`, `a=b`, `(a=\29)` ok, 1,001 characters), `RedirectUri`
  (`https://x/cb#f`, `x y`, `relative/cb`, `com.example.app:/cb` ok), `UniqueAttributeValues`
  (`duplicate` with `duplicateOf`). If 002 has no bulk result builder, also write
  `tests/Unit/Support/BulkResultTest.php` for `App\Support\Http\BulkResult` (summary counts, request
  order, `version` only for `done`).
- [X] T014 [P] Write `tests/Unit/Clients/ExtLdapDirectoryTest.php` for the pure parts of the
  adapter (R2): `uris(hosts, port, ssl)` (`ldap://a:389 ldap://b:389`; SSL 389 → `ldaps://a:636`;
  SSL 1636 stays; IPv6 → `ldap://[2001:db8::1]:389`), `userFilter(filter, field, username)`
  (`(&(objectClass=person)(mail=carl@example.org))`, no filter → `(mail=…)`, `a*b(c)\` escaped as
  `a\2ab\28c\29\5c`), option list with and without `ignoreTlsErrors`.
- [X] T015 [P] Write `tests/Unit/Clients/OidcEndpointsTest.php`: Keycloak endpoints from server URL
  and realm; scopes `profile email` for version `19.0.3`, `profile email openid` for `20.0.0` and
  `26.0.7`; generic OIDC endpoints and the scope string unchanged (R1).
- [X] T016 [P] Write `tests/Unit/IdentityProvider/SynchronisationLogParserTest.php` (R12): every
  message of `tests/fixtures/cron-log/2026-09a-messages.json` is classified (completed, tick,
  progress, failure); the sample `tests/fixtures/cron-log/2026-09a.json` yields the expected
  `lastCompletedAt`, `lastFailedAt`, `state` and `nextRunExpectedAt` at fixed clock times (waiting,
  running, overdue, failed, unknown), including the minute rounding of the next run.

### Implementation of the foundation

- [X] T017 [P] Create `app/Auth/IdentityProviderType.php` (enum `keycloak`, `genericOidc`, `ldap`
  with `fromLegacy('generic-oidc')` and `legacy()`), `app/Auth/PasswordSource.php` (`mailcow`,
  `ldap`, `keycloak`), add the cases `SingleSignOn`, `DirectoryPassword`,
  `DirectoryPasswordAndSecondFactor`, `ProviderPassword`, `ProviderPasswordAndSecondFactor` to
  `app/Auth/AuthMethod.php`, and the optional properties `identityProvider`, `passwordSource` and
  `ssoLinkId` to `app/Auth/Principal.php` (001 T040).
- [X] T018 [P] Add the six cases of FR-057 `IdentityProviderNotConfigured` (404), `IdentityProviderInUse`
  (409), `SingleSignOnNotAvailable` (409), `SignInStateInvalid` (401), `IdentityProviderUnavailable`
  (503), `IdentityProviderSessionEnded` (401) to `app/Support/Http/ProblemType.php` with the titles of
  `docs/problems.md`. 001's `tests/Unit/Support/ProblemTypeConsistencyTest.php` then passes again.
- [X] T019 Create (FR-005, FR-007, FR-008) `app/Services/IdentityProvider/IdentityProviderConfig.php` (readonly value object:
  type and typed settings, `isComplete()`, `missingSettings()`, `secret()`, `secretAddress()`,
  `withPatch(array)`) and `IdentityProviderSettingsCodec.php` (`fromRows(array): ?IdentityProviderConfig`,
  `toRows(IdentityProviderConfig): array<string,string>`, `keysFor(type)`, `etag(rows)` with the
  secrets as an HMAC keyed with `config('app.key')`) per R9 and
  the data model. This makes T010 pass.
- [X] T020 [P] Create `app/Services/IdentityProvider/TemplateMarker.php`, `AppReturnUrls.php`
  (`all()`, `forHost(string)`, reading `config('restv2.mailcow_hostname')` and
  `config('restv2.identity_provider.*')`) and `IdentityProviderUsage.php` (`counts(): array{keycloak,
  genericOidc, ldap}` with the query of the data model, `blocking(newType|null)`). This makes T011
  and T012 pass.
- [X] T021 Create `app/Services/IdentityProvider/IdentityProviderRepository.php`: `rows()` (all rows
  of `identity_provider`), `current(): ?IdentityProviderConfig`, `etag()`, `modifiedAt()`
  (`MAX(COALESCE(modified, created))` without `access_token`, through `MailcowDateTime`) and
  `templatesExist(array $names): array<string,bool>` (one query on `templates`, `type = 'mailbox'`).
- [X] T022 [P] Create the rules `app/Rules/{HttpUrl,KeycloakRealm,KeycloakVersion,ScopeList,HostName,LdapAttributeName,LdapFilter,ExistingMailboxTemplate,UniqueAttributeValues,RedirectUri}.php`,
  each reporting its rule code and params through 001's mechanism (001 T036), with the limits quoted
  in the data model ("absolute https/http URL, at most 2,000 characters"; "1 to 255 characters,
  without `/`"; "one to four whole numbers separated by dots"; "1 to 50 scope names separated by
  single spaces, printable characters other than space, `\"` and `\\`"; "a letter followed by
  letters, digits or hyphens, at most 255 characters"; "at most 1,000 characters, starting with `(`,
  ending with `)`, balanced parentheses"; "absolute URI with a scheme, no fragment, no whitespace").
  If 002 did not create it, create `app/Support/Http/BulkResult.php`. This makes T013 pass.
- [X] T023 [P] Create `app/Clients/Oidc/OidcClient.php` (interface: `authorizationUrl(OidcEndpoints,
  clientId, returnUrl, state): array{url, codeVerifier}`, `exchangeCode(…, code, codeVerifier,
  returnUrl): OidcTokens`, `refresh(…, refreshToken): OidcTokens`, `userInfo(…, accessToken): array`),
  `OidcEndpoints.php`, `OidcTokens.php`, `OidcFailure.php` (`refused`, `unavailable` with the cURL
  number or status) and `LeagueOidcClient.php` on `League\OAuth2\Client\Provider\GenericProvider`
  with `pkceMethod: S256`, scope separator space and the Guzzle client from
  `Http::timeout(config(...timeout))->connectTimeout(...)->withOptions(['verify' => !$ignoreTls,
  'allow_redirects' => false])->buildClient()` (R1, FR-056: 7 s, TLS verified unless ignored). Bind it in
  `app/Providers/AppServiceProvider.php`. This makes T015 pass.
- [X] T024 [P] Create `app/Clients/Ldap/LdapDirectory.php` (interface: `testBind(LdapConnectionSettings):
  LdapResult`, `findAndBind(LdapConnectionSettings, string $username, string $password,
  array $attributes): LdapResult`), `LdapConnectionSettings.php`, `LdapResult.php` and
  `ExtLdapDirectory.php` on `ldap_connect`, `ldap_set_option` (protocol 3, referrals 0, network
  timeout and time limit 7 s, TLS require-cert never plus new context when ignoring TLS errors),
  `ldap_start_tls`, `ldap_bind`, `ldap_search` (size limit 1), `ldap_get_entries`, `ldap_escape`
  and the result mapping of R2 and R11 (49 → wrong credentials; -1 with a certificate diagnostic or
  a StartTLS failure → TLS; timeout; unreachable). Never call `ldap_bind` with an empty password.
  Bind it in `AppServiceProvider`. This makes T014 pass.
- [X] T025 [P] Create `app/Clients/Keycloak/KeycloakAdminClient.php` (interface: `introspect`,
  `serviceToken`, `findUsersByEmail`) and `HttpKeycloakAdminClient.php` on Laravel's HTTP client with
  the timeout and TLS options of T023 (R3). Bind it in `AppServiceProvider`.
- [X] T026 [P] Create the read-only models `app/Models/Mailcow/{Oauth2Client,Oauth2AccessToken,Oauth2RefreshToken,Oauth2AuthorizationCode}.php`
  (`$table` `oauth_clients`, `oauth_access_tokens`, `oauth_refresh_tokens`,
  `oauth_authorization_codes`; `$timestamps = false`; `$guarded = ['*']`; `Oauth2Client` keyed by
  `id` for route binding; `expires` cast to `CarbonImmutable` in the server time zone).
- [X] T027 [P] Create `app/Policies/IdentityProviderPolicy.php` and `app/Policies/Oauth2ClientPolicy.php`
  (administrators only, for every ability, from 001's `Principal` role), registered in
  `AppServiceProvider`.
- [X] T028 [P] Extend `app/Logging/RedactSecrets.php` (001 T037) so that context keys matching
  `state`, `verifier`, `bind` and `cookie` are dropped too, and extend its test
  `tests/Unit/Logging/RedactSecretsTest.php` with `clientSecret`, `bindPassword`, `code`, `state`,
  `codeVerifier`, `refreshToken` and `access_token` keys (R15).
- [X] T029 Create `app/Services/IdentityProvider/SynchronisationStatus.php` (value object) and
  `SynchronisationStatusReader.php` (`read(IdentityProviderConfig, CarbonImmutable $now)`: chunked
  `LRANGE CRON_LOG` of 500 on the `mailcow` connection up to 10,000 entries, the classification and
  derivation of R12, `disabled` without import and periodic synchronisation, `null` for generic
  OIDC). The jobs themselves are never run or triggered (FR-018). This makes T016 pass.

**Checkpoint**: Foundation tests green; `ProblemTypeConsistencyTest` green.

---

## Phase 3: User Story 1 — Configure and test the identity provider (P1) 🎯 MVP

**Goal**: Administrators read, save, change, test and remove the configuration; the legacy UI, the
mail clients' sign-in check and the synchronisation jobs use what is saved.

**Independent test**: AS-1 to AS-10 of US1 against faked providers; stored rows compared with the
legacy reference rows of T010.

### Tests for User Story 1 (write first, observe failing) ⚠️

- [X] T030 [P] [US1] Write `tests/Feature/IdentityProvider/ReadIdentityProviderTest.php` (FR-004 to
  FR-008, FR-059):
  - no rows → `404 identity-provider-not-configured`;
  - one representation per type from `MailcowFactory` configs, with FR-007 defaults for a minimal
    legacy row set, the `ETag` header equal to `data.etag`, `modifiedAt` in UTC;
  - legacy rows of a previous type are not shown; mapping entries flag `templateExists: false` for
    a deleted template, and `defaultTemplateExists`;
  - an incomplete legacy configuration: `complete: false`, `missingSettings: ["clientSecret"]`;
  - `appReturnUrls` for the two host settings of `.env.testing` (Keycloak and generic OIDC only);
  - `mailboxCounts` with 12 LDAP and 3 Keycloak mailboxes (AS-10 first half);
  - the body never contains `ldap-secret`, `kc-secret` or the cached `access_token` value;
  - a row changed directly in the database (as the legacy UI does) is visible in the next response
    with a new tag (FR-059);
  - a read-only administrator key reads it (AS-8).
- [X] T031 [P] [US1] Write `tests/Feature/IdentityProvider/SaveIdentityProviderTest.php`:
  - first `PUT` of an LDAP configuration → `201`, `Location: /api/v2/identity-provider`, `ETag`,
    `bindPasswordSet: true`, no password in the body, and the rows of `identity_provider` equal the
    legacy reference rows of T010 (AS-1, FR-005, SC-002);
  - `PUT` of the same type → `200`; settings omitted take their defaults (FR-009);
  - Keycloak and generic OIDC saves with byte-identical rows; normalisations (trailing slash, hosts,
    lower-casing, StartTLS off with SSL shown in the response) (FR-010–FR-012);
  - a save over legacy rows of another type removes them (FR-015); `access_token` is `''` after
    every save (AS-2, FR-016);
  - no `mailbox` row changes (FR-016);
  - one audit record `identityProviderCreation` / `identityProviderUpdate` with `changes` old/new
    values and `{changed: true}` for secrets, never their text (FR-052).
- [X] T032 [P] [US1] Write `tests/Feature/IdentityProvider/SaveIdentityProviderInvalidTest.php`
  (table-driven, `withoutRequestValidation()` where the body violates the schema): every rule of
  FR-010 to FR-013 with its rule code and params; a mapping entry naming a missing template "Staff" →
  `notFound` with `resource: mailboxTemplate` on `attributeMapping.0.template` (AS-5); duplicate
  attribute values → `duplicate`; a Keycloak member in an LDAP body → `conflictsWith`
  (`field: type`); `attributeField` empty with `periodicSync` on → `conflictsWith`; 101 mapping
  entries → `tooMany`; several violations reported together; nothing stored; audited
  `validationFailed`.
- [X] T033 [P] [US1] Write `tests/Feature/IdentityProvider/IdentityProviderSecretTest.php` (FR-014):
  first save without a secret → `secretRequired` `noStoredSecret`; type change without → 
  `typeChanged`; a later save without the secret keeps it (rows unchanged, AS-2); a changed Keycloak
  `serverUrl`, generic OIDC `tokenEndpoint` or LDAP `hosts` without the secret → `secretRequired`
  `addressChanged` with `field`, nothing changed (AS-3); trailing-slash-only changes of `serverUrl`
  do not count as a change; with the secret sent, the save succeeds and the tag changes.
- [X] T034 [P] [US1] Write `tests/Feature/IdentityProvider/PatchIdentityProviderTest.php`: a merge
  patch of `syncInterval` keeps everything else and clears `access_token` (AS-2); `null` clears
  `defaultTemplate`, `filter`, `attributeField`; arrays replace; `type` different → `immutable`;
  a member of another type → `conflictsWith`; `{}` → `422`; no configuration → `404
  identity-provider-not-configured`; a stale `If-Match` → `412` and nothing changes (AS-9);
  audited.
- [X] T035 [P] [US1] Write `tests/Feature/IdentityProvider/TypeChangeAndDeletionTest.php`: with 12
  LDAP mailboxes, a Keycloak `PUT` and a `DELETE` → `409 identity-provider-in-use` with
  `blockingMailboxes: 12` and `mailboxCounts`, nothing changed (AS-4); a type change is allowed
  when only mailboxes of the new type exist; `DELETE` without external mailboxes → `204`, every row
  including `access_token` gone, `GET /public-config` reports `singleSignOnAvailable: false` (AS-7);
  `DELETE` with none stored → `404`; stale `If-Match` → `412`; audited `identityProviderDeletion`
  (refusals with `conflict`).
- [X] T036 [P] [US1] Write `tests/Feature/IdentityProvider/ConnectionTestTest.php` (FR-019):
  - Keycloak: client credentials grant to `…/realms/<realm>/protocol/openid-connect/token` →
    success; `401`/`invalid_client` → `credentialsRejected`; `400 unauthorized_client` →
    `unexpectedResponse` with `providerStatus: 400`; cURL 28 → `timedOut`; cURL 60 →
    `tlsCertificateRejected`; cURL 7 → `unreachable`; a `302` → `unexpectedResponse`;
  - generic OIDC: invalid-code exchange → `invalid_grant` success, `invalid_client` →
    `credentialsRejected`;
  - LDAP through `FakeLdapDirectory`: bind code 49 → `credentialsRejected` (AS-6), TLS failure,
    timeout, unreachable; SSL on 389 tested on 636; `testedAddress` as the URI list;
  - an omitted secret uses the stored one only for the same type and address, otherwise
    `secretRequired`; templates are not checked; `ignoreTlsErrors: true` sends `verify: false`;
  - nothing is stored; one audit record `identityProviderConnectionTest` with type, address and
    category, never the secret; a read-only key gets `403 insufficient-key-scope` (AS-8).
- [X] T037 [P] [US1] Write `tests/Feature/IdentityProvider/SynchronisationStatusTest.php` (FR-062):
  with `cronLog()` fixtures and `travelTo()`: periodic synchronisation on, completed run 4 minutes
  ago, interval 15 → `waiting`, `lastCompletedAt`, `nextRunExpectedAt` 11 minutes ahead (AS-10);
  progress 30 s ago → `running`; `Received HTTP 401` after the last completion → `lastFailedAt` and
  the next minute; nothing for 30 minutes → `overdue`; empty log → `unknown`; import and sync off →
  `disabled`; the other job's entries are ignored; the `ETag` does not change when the log changes.
- [X] T038 [P] [US1] Write `tests/Feature/IdentityProvider/IdentityProviderAccessTest.php` (FR-001 to
  FR-003, FR-052, AS-8): domain-administrator and mailbox-user sessions get `403 forbidden` on all
  six operations of the module; anonymous `401`; restricted session `403 pending-action-required`;
  domain-administrator key `403 forbidden`; read-only administrator key: the two reads `200`, the
  four others `403 insufficient-key-scope`; read-write administrator key: all allowed. Every refusal
  of a write or test by an authenticated caller (role, read-only key, restricted session) writes one
  failure record through `RefusalAuditor` with target type `identityProvider`; a CSRF failure and a
  `401` write none.
- [X] T039 [P] [US1] Write `tests/Feature/IdentityProvider/IdentityProviderCapabilitiesTest.php`
  (FR-064): with no configuration `GET /capabilities/identity-provider` → `200` with
  `configured: false`, `configuredType: null`, the three `appReturnUrls` of `.env.testing`, the
  types and what each offers, the FR-007 defaults and the FR-010 to FR-013 limits,
  `providerTimeoutSeconds: 7` and the single sign-on lifetimes 600 and 300; after an LDAP save
  `configured: true`, `configuredType: ldap`; no secret in the body; not audited; a read-only
  administrator key reads it; a domain administrator gets `403 forbidden`.

### Implementation for User Story 1

- [X] T040 [US1] Create `app/Services/IdentityProvider/SecretRetentionPolicy.php`
  (`resolve(?IdentityProviderConfig $stored, IdentityProviderConfig $candidate, ?string $sentSecret):
  string` or a `secretRequired` violation with `reason` and `field`, R9) and use it in T041 and T043.
- [X] T041 [US1] Create `app/Services/IdentityProvider/IdentityProviderConfigService.php`:
  `save(IdentityProviderConfig, ?string $secret, ?string $ifMatch, Principal)`,
  `change(array $patch, ?string $secret, ?string $ifMatch, Principal)` and
  `delete(?string $ifMatch, Principal)`. Each runs under `GET_LOCK('restv2_identity_provider', 5)`
  (so that two first saves cannot both create; `docs/conventions.md`) and in one `DB::transaction`:
  the `If-Match` check (`412`), the in-use check through
  `IdentityProviderUsage` (`409` with counts), the writes of the data model (delete other keys,
  upsert, `access_token` `''`; or `DELETE FROM identity_provider`), and the audit record through
  `AuditLogger` with the changed settings (secrets as `{changed: true}`). Refusals (`409`, `412`,
  `404`, `422`) are thrown as problems and recorded by `RefusalAuditor`, never twice. FR-009 to
  FR-017, FR-050 to FR-052.
- [X] T042 [US1] Create `app/Http/Requests/Api/V2/Concerns/IdentityProviderRules.php` (rule sets per
  type built from the T022 rules and the limits of FR-010 to FR-013; the type-scoped member check →
  `conflictsWith`; `attributeField` with import or sync; all violations collected) and the requests
  `SaveIdentityProviderRequest.php` (whole body, `ExistingMailboxTemplate` on),
  `UpdateIdentityProviderRequest.php` (merge patch: at least one member, `type` → `immutable`,
  validated against the merged result) and `TestIdentityProviderConnectionRequest.php`
  (`ExistingMailboxTemplate` off), all extending `ApiFormRequest`.
- [X] T043 [US1] Create `app/Services/IdentityProvider/ConnectionTestResult.php` and
  `ConnectionTester.php` (`test(IdentityProviderConfig $candidate, string $secret)`) with the
  per-type checks and failure classification of R11, using `KeycloakAdminClient::serviceToken()`,
  `OidcClient` (invalid-code exchange through the token endpoint) and `LdapDirectory::testBind()`,
  and the audit record `identityProviderConnectionTest`.
- [X] T044 [US1] Create `app/Http/Resources/Api/V2/IdentityProviderResource.php` (per type, contract
  `IdentityProvider*`: settings, `clientSecretSet`/`bindPasswordSet`, template flags, `complete`,
  `missingSettings`, `mailboxCounts`, `appReturnUrls`, `synchronisation` from T029, `modifiedAt`,
  `etag`) and `IdentityProviderConnectionTestResource.php`.
- [X] T045 [US1] Create `app/Services/IdentityProvider/IdentityProviderAuditTargetResolver.php`
  (target `identityProvider`, `id` null, `name` = the stored type or the requested one) and register
  it with `RefusalAuditor` for the target type `identityProvider` in `AppServiceProvider` (R15).
- [X] T046 [US1] Create `app/Http/Controllers/Api/V2/IdentityProviderController.php` (`show`,
  `save` for `PUT` with `201` + `Location` or `200`, `update` for `PATCH`, `destroy` → `204`; `ETag`
  header on every representation; policy checks) and `IdentityProviderConnectionTestController.php`
  (`store` → `200`). Register in `routes/api.php`: `GET|PUT|PATCH|DELETE /identity-provider` and
  `POST /identity-provider/connection-tests`, with `restv2.auth:authenticated`, `restv2.csrf` and
  `restv2.pending:enforce`, and the route default `restv2.audit` (`identityProviderCreation` for
  `PUT`, resolved to `identityProviderUpdate` when a configuration exists; `identityProviderUpdate`,
  `identityProviderDeletion`, `identityProviderConnectionTest`; target `identityProvider`).
- [X] T047 [US1] Create `app/Http/Controllers/Api/V2/IdentityProviderCapabilitiesController.php`
  (`show`) and `app/Http/Resources/Api/V2/IdentityProviderCapabilitiesResource.php` (data model,
  "Capabilities"), and register `GET /capabilities/identity-provider` with
  `restv2.auth:authenticated` and `restv2.pending:enforce` and the policy check. This and T046 make
  T030–T039 pass.

**Checkpoint**: US1 works end to end; quickstart Part B's configuration commands give the expected
results.

---

## Phase 4: User Story 2 — Sign in with single sign-on (P1)

**Goal**: Mailbox users sign in to the new web app through Keycloak or generic OIDC, with creation
and template updates, renewal every 5 minutes and provider-error reporting.

**Independent test**: AS-1 to AS-10 of US2 against `FakeOidcProvider`.

### Tests for User Story 2 (write first, observe failing) ⚠️

- [X] T048 [P] [US2] Write `tests/Feature/PublicConfig/PublicConfigSingleSignOnTest.php` (FR-020):
  `singleSignOnAvailable` is `true` for a complete Keycloak or generic OIDC configuration and
  `false` for LDAP, none or an incomplete one (AS-9); with `CUSTOM_LOGIN` `force_sso=1`, a password
  sign-in of a mailcow mailbox and a passkey sign-in still succeed (the option is display-only).
- [X] T049 [P] [US2] Write `tests/Feature/SingleSignOn/StartSingleSignOnTest.php` (FR-021):
  `409 single-sign-on-not-available` for LDAP, none and incomplete (AS-9); `200` with
  `authorizationUrl` carrying `client_id`, `response_type=code`, `redirect_uri` for the request's
  host (`Host: mail.example.net` → that host; an unknown host → `MAILCOW_HOSTNAME`), Keycloak scopes
  by version and generic OIDC scopes, `state`, `code_challenge` and `code_challenge_method=S256`;
  `expiresAt` 10 minutes ahead; the session holds `restv2.sso` with the state's SHA-256 only; a new
  start replaces the old one; no CSRF token → `403 csrf-token-mismatch`; an API key →
  `403 session-required`; not audited.
- [X] T050 [P] [US2] Write `tests/Feature/SingleSignOn/CompleteSingleSignOnTest.php` (FR-022 to
  FR-024, FR-028):
  - AS-1: Keycloak mailbox `anna@example.org` with a TOTP entry → `201`, `Location: /api/v2/session`,
    `state: active`, `authMethod: singleSignOn`, no pending second factor, `GET /me` reports method
    `singleSignOn` and `identityProvider: keycloak`, a `sasl_log` row with 001's service label, a
    renewed session identifier, `tfa` rows unchanged, an audit `signIn` success;
  - the token request carries `code`, `code_verifier`, `redirect_uri` (the stored return URL) and
    the client credentials; the user info request carries the bearer token;
  - e-mail `  Anna@Example.org ` matches; a resource mailbox with that address does not match;
  - AS-6: unknown, expired (`travel(601)->seconds()`), already used and other-session states →
    `401 sign-in-state-invalid`, `Http::assertNothingSent()`, audited `signInStateInvalid`;
  - AS-5: a `mailcow` mailbox → `401 sign-in-failed` (`mailboxNotLinked`); a `generic-oidc` mailbox
    with Keycloak configured → the same; inactive mailbox or domain → `accountInactive`;
  - user details without `email` → `noEmailAddress`; token endpoint `invalid_grant` →
    `sign-in-failed` (`providerRefused`); connection error or `502` → `503
    identity-provider-unavailable` with `Retry-After: 30`;
  - configuration deleted between start and completion → `409 single-sign-on-not-available`;
  - forced password change and forced second-factor setup flags do not restrict the session;
  - no fail2ban publication (`ChannelSpy::assertNothingPublished()`) and no throttle count for any
    failure.
- [X] T051 [P] [US2] Write `tests/Feature/SingleSignOn/SingleSignOnProvisioningTest.php` (FR-034 to
  FR-040): AS-2 mapped template "Staff" → mailbox created through 003 with the template's values,
  `authsource keycloak`, no password, name from `name`, `attributes.attribute_hash` equal to
  `TemplateMarker`, audit `mailboxCreation` with `trigger: identityProviderSignIn`, `template` and
  `signInRequestId`, and the sign-in succeeds; the stored `mailbox`, `alias`, `quota2`, `user_acl` and
  tag rows equal those of 003's creation-from-template test for the same template, apart from the
  authentication source, the empty password and the marker (SC-001); `mailcow_template` sent as a
  list → first value;
  AS-3 default template used, and without one → `noMatchingMapping`; AS-4 creation off →
  `autoCreationOff`; domain not hosted → `mailboxCreationFailed` (`domainNotFound`); domain limit
  reached → `mailboxLimitReached`; address used by an alias → `addressInUse`; mapped template
  deleted → `templateMissing`; no partial mailbox after a failure; generic OIDC creation has forced
  second-factor setup off even when the template sets it; empty name → local part.
- [X] T052 [P] [US2] Write `tests/Feature/SingleSignOn/ConcurrentFirstSignInTest.php` (FR-040):
  (a) hold `GET_LOCK('restv2_idp_' . sha1('ben@example.org'), 10)` from a second database
  connection, create the mailbox, release the lock after 1 s, and assert the completion continues as an existing mailbox
  with exactly one `mailbox` row; (b) replace 003's creation service with a double that throws the
  duplicate-key error once and assert the sign-in succeeds as an existing mailbox.
- [X] T053 [P] [US2] Write `tests/Feature/SingleSignOn/TemplateUpdateOnSignInTest.php` (FR-025):
  AS-7 a changed template → `MailboxService::update()` applies its values (quota, protocol access,
  TLS enforcement, quarantine settings, rate limit, user ACL) and the provider name, records the new
  marker (not cleared), audit `mailboxUpdate` with `changes`; equal marker and name → no update and
  no audit record; the default template never updates; a missing template → update skipped, sign-in
  succeeds, audit failure; a failing update does not stop the sign-in; the update is not limited by
  domain-administrator flags.
- [X] T054 [P] [US2] Write `tests/Feature/SingleSignOn/ProviderErrorTest.php` (FR-063, AS-10):
  `access_denied` with the valid state → `204`, pending removed, audit `signIn` failure
  `providerReturnedError` with `details.providerError: access_denied`, a later completion with the
  state → `401 sign-in-state-invalid`; an invalid state → `401`; an `error` with `"` or 65
  characters, or an `error_description` member → `422` (test names contain `Invalid`).
- [X] T055 [P] [US2] Write `tests/Feature/SingleSignOn/CancelSingleSignOnTest.php`: `DELETE
  /session/single-sign-on` → `204`, then a completion → `401 sign-in-state-invalid`; with nothing
  pending → `204`; not audited.
- [X] T056 [P] [US2] Write `tests/Feature/SingleSignOn/SingleSignOnSessionRenewalTest.php` (FR-026,
  SC-009): after 4 minutes `GET /me` sends nothing to the provider; after 6 minutes it sends a
  refresh grant and a user info request and stores the new tokens; refused refresh → `401
  identity-provider-session-ended`, session gone, audit `identityProviderSessionEnd`
  (`providerRefused`) (AS-8); unreachable → ended (`providerUnavailable`); another e-mail → ended
  (`emailChanged`); configuration deleted, type changed or incomplete → ended at the next request
  (`configurationChanged`); mailbox switched to `mailcow` → ended (`authSourceChanged`); no refresh
  token at sign-in → never renewed, local checks still run; a renewal answer without a refresh token
  keeps the old one; with the renewal lock held and the link already renewed, a request sends
  nothing; `GET /session` and `DELETE /session` never renew; the raw Redis value of
  `restv2:sso-link:*` contains no token text.
- [X] T057 [P] [US2] Write `tests/Feature/SingleSignOn/SingleSignOnSignOutTest.php` (FR-027):
  `DELETE /session` ends the session, deletes `restv2:sso-link:<id>`, sends nothing to the provider,
  and audits `signOut`.

### Implementation for User Story 2

- [X] T058 [P] [US2] Create `app/Services/IdentityProvider/SsoLinkStore.php` (`create(OidcTokens,
  username, type): linkId`, `get(linkId)`, `put(linkId, …)`, `delete(linkId)`, `touch(linkId)`;
  `Crypt::encryptString` values, TTL `SESSION_LIFETIME`) (R7).
- [X] T059 [US2] Create `app/Services/IdentityProvider/MailboxProvisioner.php` (R6):
  `provision(IdentityProviderConfig, email, ?mappingValue, ?displayName, trigger, signInRequestId)`
  → `created|existing|failure(reason, cause)` under `GET_LOCK('restv2_idp_' . sha1($email), 10)`
  with re-check and duplicate-key fallback (`docs/conventions.md`), calling 003's `MailboxService::create()` as the system actor; and
  `updateFromTemplate(IdentityProviderConfig, Mailbox, ?mappingValue, ?displayName, trigger,
  signInRequestId)` with the marker and name comparison, calling 003's `MailboxService::update()`
  with the new option "record template marker". Add that option to 003's change service (it sets
  `attributes.attribute_hash` to the given marker instead of `''`; 003 FR-034) with a test case in
  003's change test file.
- [X] T060 [US2] Create `app/Services/IdentityProvider/SingleSignOnService.php` with `start(Request)`,
  `complete(Request, code, state)`, `reportProviderError(Request, error, state)` and
  `cancel(Request)` per R4 and R5, using `IdentityProviderRepository`, `AppReturnUrls`, `OidcClient`,
  `MailboxProvisioner`, `RecentLoginRecorder`, `SessionManager`, `SsoLinkStore` and `AuditLogger`;
  never touching `SignInThrottle` or `Fail2BanReporter`.
- [X] T061 [US2] Create `app/Services/IdentityProvider/IdentityProviderSessionGuard.php` (`check(Request,
  Principal)`: local checks of FR-026 on every call; renewal under `restv2:lock:sso-link:<id>` when due;
  ending through `SessionManager::end()`, `SsoLinkStore::delete()`, audit and a `ProblemException`
  `IdentityProviderSessionEnded`) (R7). Call it from `app/Http/Middleware/RequireAuth.php` (001) for
  the modes `session` and `authenticated` when the principal's method is `singleSignOn`.
- [X] T062 [US2] Extend feature 001's classes: `app/Services/Auth/SessionManager.php` (principal
  fields `identityProvider`, `passwordSource`, `ssoLinkId`; `end()` deletes the link);
  `app/Services/Auth/PendingActionEvaluator.php` (no pending actions for `singleSignOn`, FR-024);
  `app/Http/Resources/Api/V2/SessionResource.php` and the identity resource of 001 (`authMethod`,
  `authentication.identityProvider`); `app/Services/Branding/PublicConfigService.php` and
  `app/Http/Resources/Api/V2/PublicConfigResource.php` (`singleSignOnAvailable` from
  `IdentityProviderRepository::current()`). This makes T048 pass.
- [X] T063 [US2] Create `app/Http/Requests/Api/V2/CompleteSingleSignOnRequest.php` (`code` "required,
  string, 1–4096", `state` "required, string, 1–512", no other members) and
  `ReportSingleSignOnProviderErrorRequest.php` (`error` "required, regex
  `^[\x20-\x21\x23-\x5B\x5D-\x7E]{1,64}$`", `state` as above, no other members), and
  `app/Http/Resources/Api/V2/SingleSignOnStartResource.php`.
- [X] T064 [US2] Create `app/Http/Controllers/Api/V2/SingleSignOnController.php` (`store` → `200`,
  `destroy` → `204`), `SingleSignOnCompletionController.php` (`store` → `201` with `Location`) and
  `SingleSignOnProviderErrorController.php` (`store` → `204`). Register `POST|DELETE
  /session/single-sign-on`, `POST /session/single-sign-on/completion` and
  `POST /session/single-sign-on/provider-error` in `routes/api.php` with `restv2.auth:browser`,
  `restv2.csrf` and `restv2.pending:allow`, before 001's general `/session` routes. This makes
  T049–T057 pass.

**Checkpoint**: Single sign-on works against the faked providers; quickstart Part B's browser steps
work against the dev Keycloak.

---

## Phase 5: User Story 3 — Sign in with an LDAP or Keycloak password (P1)

**Goal**: LDAP and Keycloak (mailpassword flow) mailboxes sign in through `POST /session` with
001's second factors, throttling and fail2ban, and get a mailbox on first sign-in.

**Independent test**: AS-1 to AS-8 of US3 with `FakeLdapDirectory` and `FakeOidcProvider`.

### Tests for User Story 3 (write first, observe failing) ⚠️

- [X] T065 [P] [US3] Write `tests/Feature/IdentityProvider/KeycloakServiceTokenCacheTest.php` (R3):
  an active cached `access_token` is introspected and reused; an inactive or empty one leads to a
  client credentials request and the new token is stored in the `access_token` row; a refused
  request → unavailable; every call carries the 7 s timeout and the `verify` option of
  `ignoreTlsErrors` (FR-056); the configuration's version tag is unchanged by the token write.
- [X] T066 [P] [US3] Write `tests/Feature/Auth/LdapPasswordSignInTest.php` (FR-029 to FR-031):
  AS-1 an LDAP mailbox with the directory password → `201`, `authMethod: directoryPassword`,
  `sasl_log` row; with a TOTP entry → `pendingSecondFactor`, then after the code `active` with
  `directoryPasswordAndSecondFactor`; `FakeLdapDirectory` searched
  `(&(objectClass=inetOrgPerson)(mail=carl@example.org))` below the base DN and bound as the entry's
  DN; `tfa` rows re-activated when no usable factor is active; AS-7 `force_tfa` without factors →
  `restricted`; AS-8 a username that is also an administrator → the administrator account is tried
  first; a malformed username → `sign-in-failed` before any directory call; the template update
  (T053's checks) happens before the second-factor step; the connection settings carry port 636 for
  SSL on 389.
- [X] T067 [P] [US3] Write `tests/Feature/Auth/KeycloakPasswordSignInTest.php` (FR-029, AS-5): the
  users lookup `GET /admin/realms/example/users?email=anna%40example.org&exact=true` with the bearer
  token; `mailcow_password` in `{BLF-CRYPT}` and `{SSHA512}` verified through 001's
  `LegacyPasswordVerifier` → `201 providerPassword`; a `401` from the admin API is retried once with
  a new token; the mailpassword flow off → `sign-in-failed` without fail2ban
  (`externalIdentitySource`); display name for creation from `firstName` and `lastName`.
- [X] T068 [P] [US3] Write `tests/Feature/Auth/ProviderPasswordFailuresTest.php` (FR-033, SC-008):
  wrong directory password and unknown directory user → `401 sign-in-failed`, the exact fail2ban
  message `mailcow UI: Invalid password for <user> by <ip>` (`ChannelSpy`), a throttle count and an
  audit `invalidCredentials` (AS-2); directory unreachable, timeout, refused service bind, Keycloak
  token refused and `403` from the admin API → `503 identity-provider-unavailable` with
  `Retry-After`, nothing published, no throttle count, audit `identityProviderUnavailable` (AS-4);
  Keycloak user without `mailcow_password` → `sign-in-failed`, nothing published, no count
  (`passwordNotVerifiable`); a generic OIDC mailbox → `sign-in-failed` without fail2ban (AS-6); an
  incomplete configuration → 001's `externalIdentitySource` handling; a throttled username and
  address → `429` and `FakeLdapDirectory::assertNotContacted()` / `Http::assertNothingSent()`; ten
  wrong passwords give the same number of fail2ban messages as legacy would.
- [X] T069 [P] [US3] Write `tests/Feature/Auth/ProviderPasswordProvisioningTest.php` (FR-032, FR-034 to
  FR-039): AS-3 `carl@example.org` without a mailbox, LDAP verifies → mailbox created from "Staff"
  (`attributeField` `title`), name `Carl Example`, stored rows as in T051's SC-001 comparison, then
  `201`; creation off → `sign-in-failed`
  without fail2ban or count (`autoCreationOff`); no mapping and no default → `noMatchingMapping`;
  creation refused by 003 → `mailboxCreationFailed`, no partial mailbox; a template with forced
  second-factor setup → the new session is `restricted` (FR-039); Keycloak mailpassword creation
  with the first and last name; with `role: admin` the provider is never contacted; with
  `role: mailboxUser` and no mailbox the provider path runs.

### Implementation for User Story 3

- [X] T070 [US3] Create `app/Services/IdentityProvider/KeycloakServiceTokenCache.php` (`token(config):
  string` with introspection, fetch and the `access_token` upsert of the data model; `invalidate()`)
  (R3). This makes T065 pass.
- [X] T071 [US3] Create `app/Services/IdentityProvider/ProviderVerification.php` and
  `ProviderPasswordVerifier.php` (`verify(IdentityProviderConfig, string $username, string $password):
  ProviderVerification` with `verifyLdap()` through `LdapDirectory::findAndBind()` and
  `verifyKeycloak()` through the token cache, `KeycloakAdminClient::findUsersByEmail()` and
  `LegacyPasswordVerifier`, R2 and R3).
- [X] T072 [US3] Extend feature 001's `app/Services/Auth/AccountResolver.php` (mailbox candidates carry
  `authsource`) and `app/Services/Auth/PasswordSignInService.php` with the provider branch and the
  outcome table of R8, with every provider call made before 001's sign-in transaction opens so that no
  database transaction waits on the provider: verification for `ldap`/`keycloak` candidates, the no-account path (FR-032)
  with `MailboxProvisioner::provision()`, the template update before the second-factor step, the
  `503` problem with `Retry-After` (`config('restv2.identity_provider.retry_after')`), no fail2ban
  and no throttle count where the table says so, `passwordSource` in the pending sign-in and the
  principal. Extend `app/Services/Auth/SecondFactorService.php` so the promoted principal's method is
  `directoryPasswordAndSecondFactor` or `providerPasswordAndSecondFactor`. This makes T066–T069 pass.

**Checkpoint**: LDAP and Keycloak mailboxes sign in through `POST /session`; quickstart Part B's
password commands give the expected results.

---

## Phase 6: User Story 4 — Manage OAuth2 clients (P2)

**Goal**: Administrators list, create, change, regenerate, revoke, delete and bulk delete or revoke
OAuth2 clients, and applications keep using the legacy `/oauth/*` endpoints.

**Independent test**: AS-1 to AS-8 of US4, and the legacy OAuth2 server library accepting the
results (SC-007).

### Tests for User Story 4 (write first, observe failing) ⚠️

- [X] T073 [P] [US4] Write `tests/Feature/Oauth2/ListOauth2ClientsTest.php` (FR-041, FR-042): default
  25 per page, `limit=1000` accepted and `1001` → `422`; sort by `id` (default asc), `clientId`,
  `redirectUri`; AS-7 with 40 clients `q=wiki` returns exactly those whose client ID or redirect URIs
  contain `wiki`, case-insensitively; `hasActiveTokens=true|false`; `activeAccessTokens` and
  `activeRefreshTokens` count unexpired rows only; every entry has `etag`; no `clientSecret`
  anywhere; AS-6 a read-only administrator key lists, a domain administrator gets `403`.
- [X] T074 [P] [US4] Write `tests/Feature/Oauth2/CreateOauth2ClientTest.php` (FR-043, FR-044, AS-1,
  AS-2): `201` with `Location: /api/v2/oauth2-clients/{id}`, `ETag`, `clientId` `^[0-9a-f]{12}$`,
  `clientSecret` `^[0-9a-f]{24}$`; the `oauth_clients` row has the URIs joined by single spaces,
  `scope profile`, `grant_types` and `user_id` `NULL`; `GET` afterwards has no secret; duplicate URIs
  stored once; relative, fragment and whitespace URIs → `422` `invalidFormat` (`redirectUri`) on
  `redirectUris.<n>` (test name contains `Invalid`); joined length 2,001 → `tooLong` on
  `redirectUris`; a client ID collision (random source replaced by a sequence double) generates a
  new ID; audit `oauth2ClientCreation` without the secret; a read-only key → `403
  insufficient-key-scope` (AS-6).
- [X] T075 [P] [US4] Write `tests/Feature/Oauth2/UpdateOauth2ClientTest.php` (FR-045): `PATCH`
  replaces the URIs; `clientId` or `scope` with the current value accepted, a different value →
  `immutable`; a legacy-stored empty `redirect_uri` reads as `[]` and a `PATCH` with valid URIs
  succeeds; unknown id → `404`; stale `If-Match` → `412`; audited with old and new URIs.
- [X] T076 [P] [US4] Write `tests/Feature/Oauth2/RegenerateOauth2ClientSecretTest.php` (FR-046,
  AS-3): `200` with a new secret differing from the old one, tokens and codes kept, a new `ETag`;
  stale `If-Match` → `412`; audit without either secret.
- [X] T077 [P] [US4] Write `tests/Feature/Oauth2/RevokeAndDeleteOauth2ClientTest.php` (FR-047,
  FR-048, AS-4, AS-5): revocation removes the client's access tokens, refresh tokens and
  authorization codes and leaves other clients' rows; without tokens → `200`, nothing changed,
  audited with zero counts; deletion → `204`, client and its three kinds of rows gone, audited with
  the counts; unknown id → `404`; stale `If-Match` → `412`.
- [X] T078 [P] [US4] Write `tests/Feature/Oauth2/Oauth2BulkActionTest.php` (FR-060, FR-061, AS-8):
  `revokeTokens` on three clients (one deleted elsewhere, one with an outdated `version`) → results
  `done`, `notFound`, `outdated` in request order, only the first client's tokens removed, and one
  audit record per distinct target (the `notFound` and `outdated` ones too, as their single requests
  would be audited), each carrying `bulkRequestId`; `delete` likewise; a client named twice processed once; a failure on one
  client (service double throwing for one id) → `failed` and the others `done`; 101 targets → `422
  tooMany`; a non-numeric id → `422`; a read-only key → `403 insufficient-key-scope`; a domain
  administrator → `403 forbidden`.
- [X] T079 [P] [US4] Write `tests/Feature/Oauth2/LegacyOauth2EndpointCompatibilityTest.php` (FR-049,
  SC-007, R13): build `OAuth2\Server` on `DB::connection()->getPdo()` with legacy's storage table
  names, scope `profile`, the grant types `authorization_code`, `user_credentials` and
  `refresh_token` (`always_issue_new_refresh_token`), and lifetimes 86400/2678400; with a client
  created through the API: an authorization request for a mailbox user, a code exchange with the
  client secret and a resource request succeed; after a secret regeneration a refresh with the old
  secret fails while the access token still verifies; after a revocation and after a deletion the
  old access token no longer verifies.

- [X] T080 [P] [US4] Write `tests/Feature/Oauth2/Oauth2ClientCapabilitiesTest.php` (FR-049,
  FR-064): `GET /capabilities/oauth2-clients` → the endpoint paths `/oauth/authorize`,
  `/oauth/token`, `/oauth/profile`, scope `profile`, grant types, lifetimes 86400 and 2678400 by
  default, the values of a `vars.local.inc.php` in `tests/fixtures/mailcow-inc` when present, and
  `RESTV2_ACCESS_TOKEN_LIFETIME` overriding both; `redirectUrisMaxLength: 2000`, `bulkLimit: 100`,
  page sizes 25 and 1000; a read-only administrator key reads it; a domain administrator gets
  `403 forbidden`; not audited.

### Implementation for User Story 4

- [X] T081 [US4] Create `app/Services/Oauth2/Oauth2ClientService.php` (R13): `list(ListQuery,
  ?bool $hasActiveTokens)` with grouped count queries per page; `create(array $redirectUris,
  Principal)` with the generator and collision retry; `updateRedirectUris(...)`;
  `regenerateSecret(...)`; `revokeTokens(...)`; `delete(...)`; each write in one transaction with
  the `If-Match` check (version tag with the secret as an HMAC keyed with `config('app.key')`, R13)
  and its audit record, refusals thrown as problems for `RefusalAuditor`; and `bulk(string $action, array $targets, Principal,
  string $bulkRequestId): BulkResult` processing each distinct target in its own transaction
  (FR-061). Use a `ClientIdGenerator` closure bound in the container so tests can replace the random
  source.
- [X] T082 [US4] Create `app/Http/Requests/Api/V2/ListOauth2ClientsRequest.php` (001's `ListQuery`
  rules plus `sort` in `id, clientId, redirectUri` and `hasActiveTokens` boolean),
  `StoreOauth2ClientRequest.php` and `UpdateOauth2ClientRequest.php` (`redirectUris` "a list of 1 or
  more absolute URIs, each with a scheme, without a fragment and without whitespace; joined by single
  spaces at most 2,000 characters"; `clientId`/`scope` only with the current value → `immutable`),
  `Oauth2ClientBulkActionRequest.php` (`action` in `delete, revokeTokens`; 1–100 `targets` with
  `id` `^[1-9][0-9]{0,9}$` and optional `version`), and the resources
  `app/Http/Resources/Api/V2/Oauth2ClientResource.php` and `Oauth2ClientWithSecretResource.php`.
- [X] T083 [US4] Extend 001's `resources/bin/export-mailcow-vars.php` and
  `app/Services/Config/MailcowConfig.php` with `accessTokenLifetime()` and `refreshTokenLifetime()`
  (`ACCESS_TOKEN_LIFETIME`, `REFRESH_TOKEN_LIFETIME`; server settings
  `RESTV2_ACCESS_TOKEN_LIFETIME` and `RESTV2_REFRESH_TOKEN_LIFETIME`; defaults 86400 and 2678400;
  001 R14 precedence) (R20).
- [X] T084 [US4] Create `app/Services/Oauth2/Oauth2ClientAuditTargetResolver.php` (target
  `oauth2Client`: the route's `oauth2ClientId` and its client ID looked up without scope; for
  `POST /oauth2-clients/bulk-actions` a `null` target with `details.itemIds` and
  `details.bulkAction`) and register it with `RefusalAuditor` in `AppServiceProvider` (R15).
- [X] T085 [US4] Create `app/Http/Controllers/Api/V2/Oauth2ClientController.php` (`index`, `store`
  → `201`, `show`, `update`, `destroy` → `204`), `Oauth2ClientSecretRegenerationController.php`,
  `Oauth2ClientTokenRevocationController.php` and `Oauth2ClientBulkActionController.php`. Register in
  `routes/api.php`, with `POST /oauth2-clients/bulk-actions` before `/oauth2-clients/{oauth2ClientId}`
  and `oauth2ClientId` constrained to digits, all with `restv2.auth:authenticated`, `restv2.csrf`
  and `restv2.pending:enforce`; every write route declares `restv2.audit` with its event type
  (`oauth2ClientCreation`, `oauth2ClientUpdate`, `oauth2ClientSecretRegeneration`,
  `oauth2ClientTokenRevocation`, `oauth2ClientDeletion`; the bulk route derives it from `action`)
  and target `oauth2Client`. Also create `Oauth2ClientCapabilitiesController.php` and
  `app/Http/Resources/Api/V2/Oauth2ClientCapabilitiesResource.php` for
  `GET /capabilities/oauth2-clients`. This makes T073–T080 pass.

- [X] T095 [US4] API gap G42 (FR-066, added during implementation): the bulk action `renewSecret` on
  `POST /oauth2-clients/bulk-actions` regenerates each named client's secret through
  `Oauth2ClientService::regenerateSecret()` and returns it once in the item result
  (`Oauth2ClientBulkResult`, `Oauth2ClientBulkItemResult`); the shared `BulkActionRunner` accepts
  extra result members from an item's action. Test first in
  `tests/Feature/Oauth2/Oauth2BulkActionTest.php`; mark G42 done in `docs/api-gaps.md`.

**Checkpoint**: OAuth2 management works; quickstart Part B's OAuth2 commands give the expected
results.

---

## Phase 7: User Story 5 — Trace identity provider and OAuth2 changes (P3)

**Goal**: The audit log shows every change, test and sign-in of this feature, filterable by target
type and sign-in method.

**Independent test**: AS-1 to AS-3 of US5.

### Tests for User Story 5 (write first, observe failing) ⚠️

- [ ] T086 [P] [US5] Write `tests/Feature/Audit/IdentityProviderAuditTest.php` (FR-052 to FR-054,
  SC-010): AS-1 changing the bind password and the sync interval → one `identityProviderUpdate`
  record with `syncInterval` old and new and `bindPassword: {changed: true}`, no password text;
  AS-2 a mailbox created on single sign-on → `targetType=mailbox&targetId=<address>` lists the
  `mailboxCreation` record with `template` and `signInRequestId` equal to the sign-in's request ID;
  AS-3 a failed single sign-on without a mapping → `outcome=failure&eventType=signIn` lists it with
  the provider's e-mail address as actor and `noMatchingMapping`; sign-in records carry
  `authMethod` `singleSignOn`, `directoryPassword` or `providerPassword`;
  `targetType=identityProvider` and `targetType=oauth2Client&targetId=<id>` filter correctly, and no
  feature-specific audit filter parameter exists (FR-054); a data provider over
  every request kind of FR-052 asserts exactly one record per request, and one per distinct client
  for a bulk action.

### Implementation for User Story 5

- [ ] T087 [US5] Extend `app/Services/Audit/AuditLogger.php` (001 T043) with the `details` whitelists
  of this feature's event types (data model, audit table) and the new failure reasons and methods.
  No filter parameter is added: 002's `targetType` and `targetId` filters accept this feature's
  target types through the shared `AuditTarget` enumeration. This makes T086 pass.

**Checkpoint**: All five stories are complete.

---

## Phase 8: Polish, operations and cross-cutting concerns

- [ ] T088 [P] Write `tests/Feature/Security/IdentityProviderAuthorizationMatrixTest.php` (constitution
  VI, quality gate 5, SC-005), following 001's `AuthorizationMatrixTest`: all 19 operations × anonymous,
  pending sign-in, restricted (`passwordChange`), restricted (`secondFactorSetup`), administrator
  session, domain-administrator session, mailbox session, administrator read-write key,
  administrator read-only key and domain-administrator read-write key, with the expected status and
  problem type from a table derived from the contract, R15 and FR-003. Object level: an unknown
  `oauth2ClientId` → `404` for administrators and `403 forbidden` for other roles; single sign-on
  operations with a key → `403 session-required`.
- [ ] T089 [P] Write `tests/Feature/Security/IdentityProviderSecretScanTest.php` (SC-004, FR-055): run
  a save, change, test, single sign-on with renewal, provider password sign-ins, OAuth2 creation,
  regeneration, revocation and bulk deletion with distinctive secrets, codes, states, verifiers and
  tokens; capture the log channel; assert that no response (apart from the OAuth2 creation and
  regeneration secrets), log line, audit row or session payload contains any of them.
- [X] T090 [P] Write `tests/Integration/Ldap/ExtLdapDirectoryTest.php` (group `idp`, R2) against the
  `openldap` service: lookup and bind of `carl@example.org`, literal match of `a*b@example.org`, a
  filter that excludes the entry, wrong password → code 49, wrong bind DN password → unavailable,
  `ldaps://` with the self-signed certificate refused unless `ignoreTlsErrors`, StartTLS, a stopped
  server → unavailable within 7 s.
- [ ] T091 [P] Write `tests/Feature/Performance/SingleSignOnLatencyTest.php` (SC-003): over 20 faked
  single sign-ons (start and completion, with creation in 5 of them), the API's own time at p95 is
  below 2 s, measured without the faked provider latency.
- [ ] T092 [P] Document the feature in `README.md`: identity provider setup, the return addresses to
  register (`https://<host>/ng/sso/return`), the `RESTV2_IDP_*` and `RESTV2_SSO_*` settings,
  `ADDITIONAL_SERVER_NAMES`, private CAs for LDAP (`LDAPTLS_CACERT`), and that the synchronisation
  jobs stay in php-fpm (FR-018). Add a `CHANGELOG.md` entry listing the 19 operations and the additive
  changes to feature 001's contract for mailcowui-ng.
- [ ] T093 Run `composer test` (contract coverage must list all 19 operations of this feature, FR-058),
  `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` and `vendor/bin/phpunit --group idp`, and
  fix every finding.
- [ ] T094 Run quickstart.md Part A and Part B against the dev stack and confirm every expected
  result; record deviations in `specs/012-identity-providers-oauth2/quickstart.md`.

---

## Dependencies and execution order

### Phase dependencies

- **Setup (Phase 1)**: T001 → T002; T003–T006 in parallel after T002.
- **Foundational (Phase 2)**: depends on Setup and on features 001–003 being implemented. Tests
  T007–T016 first (T008 and T009 before any test that uses them), then T017–T029. T017 and T018
  first; T019 before T020, T021 and T029; T022–T028 in parallel. **Blocks every story.**
- **US1 (Phase 3)**: Foundational and the shared `RefusalAuditor` (002 or 008). T040 before T041 and
  T043; T042, T044 and T045 before T046; T046 before T047.
- **US2 (Phase 4)**: Foundational (T021's repository). Independent of US1's
  controllers: tests seed configurations with `MailcowFactory`. T058 before T060; T059 before T060;
  T060 before T064; T061 needs T058.
- **US3 (Phase 5)**: Foundational and T059 (`MailboxProvisioner`, from US2). If US3 is built before
  US2, implement T059 first.
- **US4 (Phase 6)**: Foundational (T026, T027, T022's `RedirectUri` and `BulkResult`) and the shared
  `RefusalAuditor`. T081, T082, T083 and T084 before T085.
- **US5 (Phase 7)**: uses the flows of US1–US4 in its test, so it runs after them; T087 can be
  built earlier.
- **Polish (Phase 8)**: after every story. T088 needs all 19 operations; T089 needs US1–US4.

### Within each story

Tests first and failing; then value objects and services; then requests, resources and
controllers; then routes. Stop at each checkpoint and run the story's tests.

### Parallel opportunities

- Setup: T003, T004, T005, T006.
- Foundation tests: T007–T016 (different files).
- Foundation implementation: T020, T022–T028 in parallel after T019.
- After Phase 2 with several developers: US1 (Phase 3), US2 (Phase 4) and US4 (Phase 6) in
  parallel; US3 after T059; US5 last.

## Parallel example: User Story 1

```bash
# All US1 test tasks together (different files):
Task: "T030 ReadIdentityProviderTest", "T031 SaveIdentityProviderTest",
      "T032 SaveIdentityProviderInvalidTest", "T033 IdentityProviderSecretTest",
      "T034 PatchIdentityProviderTest", "T035 TypeChangeAndDeletionTest",
      "T036 ConnectionTestTest", "T037 SynchronisationStatusTest", "T038 IdentityProviderAccessTest",
      "T039 IdentityProviderCapabilitiesTest"

# Then: T040 SecretRetentionPolicy → T041 IdentityProviderConfigService and T043 ConnectionTester,
# with T042 requests, T044 resources and T045 the audit target resolver in parallel, then T046
# controllers and routes and T047 the capabilities read.
```

## Implementation strategy

### MVP first (User Story 1)

1. Phase 1 and Phase 2.
2. Phase 3 (US1): administrators manage and test the configuration in the API, and the legacy UI,
   mail clients and jobs use it.
3. **Stop and validate**: quickstart Part B's configuration commands and Part C step 1.

### Incremental delivery

1. US2 (single sign-on) and US3 (provider passwords): mailbox users of external providers can use
   the new web app.
2. US4 (OAuth2 clients), then US5 (audit filters and checks).
3. Polish: authorisation matrix, secret scan, LDAP integration, latency, documentation.

Each story ends with its checkpoint green and contract coverage growing towards all 19 operations.

## Notes

- Never use `RefreshDatabase`, `migrate:fresh`, `migrate:refresh` or `db:wipe` (001 R3). This
  feature adds no migration.
- Every upstream write goes through `app/Services/`: `IdentityProviderConfigService` and
  `KeycloakServiceTokenCache` (`identity_provider`), `Oauth2ClientService` (`oauth_*`),
  `MailboxProvisioner` through 003's mailbox service (`mailbox` and related rows), and 001's
  `RecentLoginRecorder` and `PasswordSignInService` (`sasl_log`, `tfa`). Controllers never write.
- Commit after each task or logical group, with short imperative subjects and no AI attribution.
