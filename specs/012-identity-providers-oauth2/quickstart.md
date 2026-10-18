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

# 4. Full suite with contract validation and coverage (all 19 new operations must be covered).
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
- `composer test` ends green; `build/contract/coverage.json` lists the 19 operations of this feature.
- The `idp` group passes: lookups with special characters in the username match literally, SSL on
  port 389 connects to 636, a wrong bind password gives `wrongPassword`, a stopped container gives
  `unavailable` within 7 seconds.
- No test output, log line or audit row contains `ldap-secret`, `kc-secret` or any generated client
  secret (`IdentityProviderSecretScanTest`).

## Part B: Manual end-to-end checks against the dev stack

```bash
docker compose --profile idp up -d          # adds openldap (no host port) and keycloak (http://localhost:8081)
docker compose run --rm app scripts/dev/reset-dev-database.sh
```

The `idp` profile loads `tests/fixtures/ldap/bootstrap.ldif` into OpenLDAP
(`dc=example,dc=org`, bind DN `cn=admin,dc=example,dc=org`, password `ldap-secret`; users
`carl@example.org` with `title: staff` and `dana@example.org`, password `Passw0rd!dev`). Keycloak
(admin `admin`/`admin`) imports `docker/keycloak/example-realm.json` on its first start (API gap
G21), so it needs no manual setup: realm `example`, confidential client `mailcow` (secret
`kc-secret`, standard flow, service account with `view-users`, a `mailcow_template` claim in the
`profile` scope), the redirect URIs `http(s)://localhost/*` and the dev stack's published ports, and
the user `anna@example.org` (password `Passw0rd!dev`, `mailcow_template` = `staff`). The import
skips a realm that already exists: after changing the JSON, recreate the container
(`docker compose --profile idp up -d --force-recreate keycloak`). The API reaches both providers by
their service names (`openldap`, `http://keycloak:8080`).

The session cookie is always Secure, so the API is called over the HTTPS listener (`-k`, see 001's
quickstart).

```bash
B=https://127.0.0.1:18443/api/v2; J='-H Content-Type:application/json'; C='-k -c jar -b jar'; C2='-k -c jar2 -b jar2'
T=$(curl -s $C $B/csrf-token | jq -r .data.token)
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/session -d '{"username":"admin","password":"Passw0rd!dev"}' >/dev/null
# The mailbox template "Staff" of the attribute mapping (feature 003): 2 GiB quota, tag "staff".
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/mailbox-templates \
  -d '{"name":"Staff","quota":2147483648,"tags":["staff"]}' | jq .data.name

# US1: no configuration yet; the capabilities already list the return addresses to register.
curl -s $C $B/identity-provider | jq .type             # ".../identity-provider-not-configured"
curl -s $C $B/capabilities/identity-provider | jq '.data | {configured, appReturnUrls, defaults}'
#   appReturnUrls: ["https://localhost/ng/sso/return"] (http with RESTV2_SSO_RETURN_SCHEME=http)
curl -s $C $B/capabilities/oauth2-clients | jq '.data | {endpoints, accessTokenLifetimeSeconds}'

# US1: test unsaved LDAP settings with a wrong bind password, then the right one.
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/identity-provider/connection-tests -d '{
  "type":"ldap","hosts":["openldap"],"baseDn":"dc=example,dc=org",
  "bindDn":"cn=admin,dc=example,dc=org","bindPassword":"wrong"}' | jq '.data | {outcome, failureCategory, providerStatus}'
#   -> {"outcome":"failure","failureCategory":"credentialsRejected","providerStatus":49}
#   With "bindPassword":"ldap-secret": "outcome":"success".

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
#   -> {"code":"secretRequired","params":{"reason":"addressChanged","field":"hosts"}}

# US3: password sign-in of a directory user without a mailbox -> mailbox created from "Staff".
T2=$(curl -s $C2 $B/csrf-token | jq -r .data.token)
curl -s $C2 $J -H "X-CSRF-Token: $T2" -X POST $B/session \
  -d '{"username":"carl@example.org","password":"Passw0rd!dev"}' | jq '.data | {state, authMethod}'
#   -> {"state":"active","authMethod":"directoryPassword"}; the mailbox has authsource ldap,
#      name "Carl Example" and the template's 2 GiB quota.

# US3: provider stopped -> 503 with Retry-After: 30, no fail2ban message.
docker compose stop openldap
T2=$(curl -s $C2 $B/csrf-token | jq -r .data.token)
curl -s $C2 $J -H "X-CSRF-Token: $T2" -X POST $B/session \
  -d '{"username":"carl@example.org","password":"Passw0rd!dev"}' | jq .type   # ".../identity-provider-unavailable"
docker compose start openldap

# US1: deletion refused while LDAP mailboxes exist.
curl -s $C -H "X-CSRF-Token: $T" -X DELETE $B/identity-provider | jq '{type, blockingMailboxes, mailboxCounts}'
#   -> mailboxCounts {"keycloak":0,"genericOidc":0,"ldap":1}

# US4: OAuth2 clients.
CL=$(curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/oauth2-clients \
  -d '{"redirectUris":["https://wiki.example.org/oauth/callback"]}')
echo "$CL" | jq '.data | {id, clientId, clientSecret}'     # secret shown once
ID=$(echo "$CL" | jq .data.id)
curl -s $C $B/oauth2-clients/$ID | jq '.data | has("clientSecret")'   # false
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/oauth2-clients/bulk-actions \
  -d "{\"action\":\"revokeTokens\",\"targets\":[{\"id\":\"$ID\"},{\"id\":\"999999\"}]}" | jq '.data.summary'
#   -> done 1, notFound 1
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/oauth2-clients/bulk-actions \
  -d "{\"action\":\"renewSecret\",\"targets\":[{\"id\":\"$ID\"}]}" | jq '.data.results[0] | {outcome, clientSecret}'
#   -> the new secret, shown once (G42)

# US5: audit trail.
curl -s $C "$B/audit-events?targetType=identityProvider&limit=5" | jq '.data[] | {eventType, outcome, details}'
curl -s $C "$B/audit-events?eventType=signIn&authMethod=directoryPassword&limit=5" | jq '.data[] | {eventType, authMethod, outcome, failureReason}'
```

**Single sign-on (US2).** Switch the configuration to Keycloak after switching the LDAP mailboxes
back to mailcow (`UPDATE mailbox SET authsource='mailcow' WHERE authsource='ldap'`, or delete
them), and test it first:

```bash
curl -s $C $J -H "X-CSRF-Token: $T" -X PUT $B/identity-provider -d '{
  "type":"keycloak","serverUrl":"http://keycloak:8080","realm":"example","clientId":"mailcow",
  "clientSecret":"kc-secret","version":"26.0.7","legacyReturnUrl":"http://localhost/","mailpasswordFlow":true,
  "attributeMapping":[{"attributeValue":"staff","template":"Staff"}]}' | jq .data.complete     # true
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/identity-provider/connection-tests -d '{
  "type":"keycloak","serverUrl":"http://keycloak:8080","realm":"example","clientId":"mailcow",
  "version":"26.0.7","legacyReturnUrl":"http://localhost/"}' | jq .data.outcome                 # "success"
curl -s -k $B/public-config | jq .data.externalIdentityProvider.singleSignOnAvailable           # true
```

The authorization address points at `http://keycloak:8080`, which only the containers resolve, so
the provider's pages are used from inside the `app` container, which has `curl` and PHP but no `jq`
(`j` below reads one member). Keycloak's cookies are posted back with the login form by hand:

```bash
docker compose exec app bash
j() { php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(explode(".",$argv[1]) as $k){$d=$d[$k]??null;} echo is_array($d)?json_encode($d):$d, PHP_EOL;' "$1"; }
B=https://localhost/api/v2; CT='--connect-to localhost:443:web:443'; C="-sk $CT -c /tmp/jar -b /tmp/jar"
T=$(curl $C $B/csrf-token | j data.token)
URL=$(curl $C -H "X-CSRF-Token: $T" -X POST $B/session/single-sign-on | j data.authorizationUrl)
curl -s -i "$URL" > /tmp/kc-page
CK=$(grep -i '^set-cookie' /tmp/kc-page | sed -E 's/^[Ss]et-[Cc]ookie: ([^;]*).*/\1/' | tr -d '\r' | paste -sd';' -)
FORM=$(grep -o 'action="http[^"]*authenticate[^"]*"' /tmp/kc-page | head -1 | sed 's/action="//; s/"$//; s/&amp;/\&/g')
LOC=$(curl -s -o /dev/null -w '%{redirect_url}' -H "Cookie: $CK" \
  --data-urlencode 'username=anna@example.org' --data-urlencode 'password=Passw0rd!dev' "$FORM")
echo "$LOC"           # https://localhost/ng/sso/return?state=…&code=…
CODE=$(php -r 'parse_str(parse_url($argv[1], PHP_URL_QUERY), $q); echo $q["code"];' "$LOC")
STATE=$(php -r 'parse_str(parse_url($argv[1], PHP_URL_QUERY), $q); echo $q["state"];' "$LOC")
curl $C -H 'Content-Type: application/json' -H "X-CSRF-Token: $T" -X POST $B/session/single-sign-on/completion \
  -d "{\"code\":\"$CODE\",\"state\":\"$STATE\"}" | j data   # state active, authMethod singleSignOn
curl $C $B/me | j data.authentication     # {"method":"singleSignOn","identityProvider":"keycloak"}
```

**Expected**:

1. The completion answers `201` with `state: active` and `authMethod: singleSignOn`, and the mailbox
   `anna@example.org` exists with authsource `keycloak`, name "Anna Example" and the 2 GiB quota of
   "Staff".
2. Start again and report `{"error":"access_denied","state":"<state of the authorization address>"}`
   to `POST /session/single-sign-on/provider-error`: `204`, and the audit log shows a failed `signIn`
   with `providerReturnedError` and `details.providerError: access_denied`.
3. Disable `anna` in Keycloak's admin console (`http://localhost:8081`, realm `example`), wait 5
   minutes, call `GET /me` in the single sign-on session: `401 identity-provider-session-ended`.
   Before disabling her, the first request after 5 minutes renews the session and answers `200`.
4. A password sign-in of `anna@example.org` (mailpassword flow) answers `authMethod:
   providerPassword`; with Keycloak stopped (`docker compose stop keycloak`) it answers `503
   identity-provider-unavailable` with `Retry-After: 30`.

A browser on the host can run the same flow when it resolves `keycloak` to `127.0.0.1` and Keycloak
is published on port 8080 (`RESTV2_KEYCLOAK_PORT=8080`); the return address then shows the code and
state in the address bar.

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
