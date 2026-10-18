# Quickstart and Validation Guide: Feature 012

**Feature**: `specs/012-identity-providers-oauth2` | **Plan**: [plan.md](./plan.md) |
**Contract**: [`api/openapi.yaml`](../../api/openapi.yaml)

This guide proves that the feature works. Part A runs the automated suite, Part B runs manual
checks against the dev stack with a local OpenLDAP and Keycloak, and Part C checks parity on a real
mailcow host. It builds on [001's quickstart](../001-auth-sessions-api-keys/quickstart.md), whose
prerequisites, dev stack and commands apply unchanged.

## Prerequisites

- Everything of 001's quickstart (Docker with Compose v2, `curl`, `jq`).
- Features 001, 002 and 003 implemented (sign-in, domains, mailboxes and mailbox templates).
- For Part B: about 1.5 GB of free memory for the `idp` profile (OpenLDAP and Keycloak).
- For Part C: a mailcow host on upstream `2026-09a` or `feldhost-202609a` with the API installed as
  in 001's Part C, and access to a Keycloak or generic OIDC provider and an LDAP directory.

## Part A: Automated suite

```bash
# 1. Rebuild the app image: feature 012 adds the ldap extension.
docker compose build app

# 2. Install the new dependencies (league/oauth2-client, dev: bshaffer/oauth2-server-php).
docker compose run --rm app composer install

# 3. Lint the contract.
docker compose run --rm app composer contract:lint

# 4. Full suite with contract validation and coverage (all 17 new operations must be covered).
docker compose run --rm app composer test

# 5. Only this feature's tests.
docker compose run --rm app vendor/bin/phpunit tests/Feature/IdentityProvider tests/Feature/SingleSignOn \
  tests/Feature/Oauth2 tests/Feature/Auth/LdapPasswordSignInTest.php tests/Feature/Auth/KeycloakPasswordSignInTest.php \
  tests/Unit/IdentityProvider

# 6. The real LDAP adapter against OpenLDAP (group idp).
docker compose --profile idp up -d openldap
docker compose run --rm app vendor/bin/phpunit --group idp
```

**Expected**:

- `contract:lint` reports no errors.
- `composer test` ends green; `build/contract/coverage.json` lists the 17 operations of this feature.
- The `idp` group passes: lookups with special characters in the username match literally, SSL on
  port 389 connects to 636, a wrong bind password gives `wrongPassword`, a stopped container gives
  `unavailable` within 7 seconds.
- No test output, log line or audit row contains `ldap-secret`, `kc-secret` or any generated client
  secret (`IdentityProviderSecretScanTest`).

## Part B: Manual end-to-end checks against the dev stack

```bash
docker compose --profile idp up -d          # adds openldap (:389, :636) and keycloak (:8081)
docker compose run --rm app scripts/dev/reset-dev-database.sh
```

The `idp` profile loads `tests/fixtures/ldap/bootstrap.ldif` into OpenLDAP
(`dc=example,dc=org`, bind DN `cn=admin,dc=example,dc=org`, password `ldap-secret`; users
`carl@example.org` with `title: staff` and `dana@example.org`, password `Passw0rd!dev`). Keycloak
(admin `admin`/`admin`) needs one manual setup at `http://localhost:8081`: realm `example`, client
`mailcow` (confidential, standard flow, service account with `view-users`, secret `kc-secret`),
valid redirect URI `http://localhost:8080/ng/sso/return`, user `anna@example.org` with the attribute
`mailcow_template` = `staff` and a `mailcow_template` mapper on the `profile` scope. The dev stack
sets `RESTV2_SSO_RETURN_SCHEME=http`.

```bash
B=http://localhost:8080/api/v2; J='-H Content-Type:application/json'; C='-c jar -b jar'
T=$(curl -s $C $B/csrf-token | jq -r .data.token)
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/session -d '{"username":"admin","password":"Passw0rd!dev"}' >/dev/null
# Create the mailbox template "Staff" first (feature 003, POST /mailbox-templates).

# US1: no configuration yet.
curl -s $C $B/identity-provider | jq .type             # ".../identity-provider-not-configured"

# US1: test unsaved LDAP settings with a wrong bind password, then the right one.
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/identity-provider/connection-tests -d '{
  "type":"ldap","hosts":["openldap"],"baseDn":"dc=example,dc=org",
  "bindDn":"cn=admin,dc=example,dc=org","bindPassword":"wrong"}' | jq '.data | {outcome, failureCategory}'
#   -> {"outcome":"failure","failureCategory":"credentialsRejected"}

# US1: save the LDAP configuration (201), then read it: no secret, only bindPasswordSet.
curl -s -i $C $J -H "X-CSRF-Token: $T" -X PUT $B/identity-provider -d '{
  "type":"ldap","hosts":["openldap"],"baseDn":"dc=example,dc=org","bindDn":"cn=admin,dc=example,dc=org",
  "bindPassword":"ldap-secret","attributeField":"title",
  "attributeMapping":[{"attributeValue":"staff","template":"Staff"}]}' | grep -iE '^(HTTP|location|etag)'
curl -s $C $B/identity-provider | jq '.data | {type, bindPasswordSet, complete, mailboxCounts, synchronisation}'
curl -s $C $B/identity-provider | grep -c ldap-secret   # 0

# US1: change the hosts without the password -> secretRequired.
curl -s $C -H 'Content-Type: application/merge-patch+json' -H "X-CSRF-Token: $T" -X PATCH $B/identity-provider \
  -d '{"hosts":["ldap.example.net"]}' | jq '.errors.bindPassword[0] | {code, params}'

# US3: password sign-in of a directory user without a mailbox -> mailbox created from "Staff".
T2=$(curl -s -c jar2 -b jar2 $B/csrf-token | jq -r .data.token)
curl -s -c jar2 -b jar2 $J -H "X-CSRF-Token: $T2" -X POST $B/session \
  -d '{"username":"carl@example.org","password":"Passw0rd!dev"}' | jq '.data | {state, authMethod}'
#   -> {"state":"active","authMethod":"directoryPassword"}

# US3: provider stopped -> 503, no fail2ban message.
docker compose stop openldap
curl -s -c jar2 -b jar2 $J -H "X-CSRF-Token: $T2" -X POST $B/session \
  -d '{"username":"carl@example.org","password":"Passw0rd!dev"}' | jq .type   # ".../identity-provider-unavailable"
docker compose start openldap

# US1: deletion refused while LDAP mailboxes exist.
curl -s $C -H "X-CSRF-Token: $T" -X DELETE $B/identity-provider | jq '{type, blockingMailboxes, mailboxCounts}'

# US4: OAuth2 clients.
CL=$(curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/oauth2-clients \
  -d '{"redirectUris":["https://wiki.example.org/oauth/callback"]}')
echo "$CL" | jq '.data | {id, clientId, clientSecret}'     # secret shown once
ID=$(echo "$CL" | jq .data.id)
curl -s $C $B/oauth2-clients/$ID | jq '.data | has("clientSecret")'   # false
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/oauth2-clients/bulk-actions \
  -d "{\"action\":\"revokeTokens\",\"targets\":[{\"id\":\"$ID\"},{\"id\":\"999999\"}]}" | jq '.data.summary'
#   -> done 1, notFound 1

# US5: audit trail.
curl -s $C "$B/audit-events?targetType=identityProvider&limit=5" | jq '.data[] | {eventType, outcome, details}'
curl -s $C "$B/audit-events?authMethod=directoryPassword&limit=5" | jq '.data[] | {eventType, outcome, failureReason}'
```

**Single sign-on (US2)** needs a browser, because the provider's pages must be used. Switch the
configuration to Keycloak (`PUT /identity-provider` with `type: keycloak`, server URL
`http://keycloak:8080`, realm `example`, client `mailcow`, secret `kc-secret`, version `26.0.7`,
legacy return URL `http://localhost:8080/`) after deleting or switching the LDAP mailboxes, then:

1. `GET /public-config` reports `externalIdentityProvider.singleSignOnAvailable: true`.
2. In the browser console on `http://localhost:8080/api/v2/docs`, call `POST /session/single-sign-on`
   with the CSRF token and open `data.authorizationUrl`. Sign in as `anna@example.org`.
3. Keycloak returns to `/ng/sso/return?code=…&state=…`. Without the UI, copy both values and call
   `POST /session/single-sign-on/completion` from the same browser tab's console. Expect `201`,
   `state: active`, `authMethod: singleSignOn`, and a new mailbox `anna@example.org` from "Staff".
4. Repeat, but press "Cancel" at Keycloak; report `error` and `state` to
   `POST /session/single-sign-on/provider-error`: `204`, and the audit log shows
   `providerReturnedError` with `details.providerError: access_denied`.
5. Disable `anna` in Keycloak, wait 5 minutes, call `GET /me`: `401 identity-provider-session-ended`.

## Part C: Validation on a mailcow host

1. **Configuration parity (SC-002)**: save an LDAP and a Keycloak configuration through the API.
   The legacy "System › Configuration › Identity Provider" screen shows the same values (secrets
   prefilled there, as legacy renders them). `docker compose exec mysql-mailcow mariadb -e "SELECT
   \`key\`, value FROM identity_provider" $DBNAME` shows legacy's keys and encodings, and no rows of
   the previous type. An IMAP sign-in of an LDAP mailbox (`doveadm auth test`) succeeds. The next
   synchronisation run uses the settings: `docker compose logs --since 20m php-fpm-mailcow` and the
   legacy "Logs › Cron" tab show the run, and `GET /identity-provider` reports it as
   `lastCompletedAt`.
2. **Legacy round trip**: save the configuration in the legacy UI, then read it through the API:
   same values, a new version tag; a `PATCH` with the old tag answers `412`.
3. **Single sign-on in the new web app**: register `https://<host>/ng/sso/return` at the provider and
   sign in through mailcowui-ng. Check in the browser's developer tools that the completion request
   carries the `__Host-restv2_session` cookie (SameSite=Strict, research R4) and that neither the
   code nor the state stays in the address bar or in storage.
4. **fail2ban (SC-008)**: fail an LDAP password sign-in through the API as many times as mailcow's
   fail2ban `max_attempts`; the address appears in the legacy fail2ban list. Stop the LDAP server
   and repeat: no ban.
5. **OAuth2 (SC-007)**: create a client through the API, run an authorization-code flow against
   `https://<host>/oauth/authorize` and `/oauth/token`, call `/oauth/profile`; then revoke and
   delete through the API and confirm the old access token is refused at once.
6. **No secrets**: `docker compose logs restv2-mailcow | grep -c -e '<bind password>' -e
   '<client secret>'` prints `0`, and `SELECT COUNT(*) FROM restv2_audit_events WHERE details LIKE
   '%<secret>%'` is `0`.
