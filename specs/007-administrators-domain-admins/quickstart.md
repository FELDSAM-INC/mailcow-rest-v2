# Quickstart and Validation Guide: Feature 007

**Feature**: `specs/007-administrators-domain-admins` | **Plan**: [plan.md](./plan.md) |
**Contract**: [`api/openapi.yaml`](../../api/openapi.yaml) (modules `admins`, `domain-admins`,
`sso-tokens`, `impersonation`)

This guide proves that the feature works. It assumes feature 001's dev stack and commands
([001 quickstart](../001-auth-sessions-api-keys/quickstart.md), Part A) and features 002, 003 and
005 implemented. Part A runs the automated suite, Part B checks the flows by hand on the dev stack,
Part C checks coexistence with the legacy UI on a real mailcow host.

## Part A: Automated suite

```bash
docker compose run --rm app composer contract:lint      # lint clean, no new warning
docker compose run --rm app composer test               # fixture, migrations, PHPUnit, coverage
docker compose run --rm app vendor/bin/phpunit --testsuite Feature --filter 'Admins|DomainAdmins|Accounts|SsoTokens|Impersonation|Webmail|Audit'
docker compose run --rm app vendor/bin/pint --test && docker compose run --rm app vendor/bin/phpstan analyse
```

**Expected**:

- `composer test` ends green, and `build/contract/coverage.json` lists the 21 operations of this
  feature as covered.
- `LastActiveAdminConcurrencyTest` (SC-003) ends with at least one active administrator in every
  one of its 1,000 rounds.
- `DomainAdminListPerformanceTest` (SC-008) reports a p95 under 1 s for a page of 25 with 5,000
  domain administrators and 5,000 domains.
- `AccountSecretScanTest` (SC-009) finds no password, hash or token in any audit record or in the
  captured log output.

## Part B: Manual checks on the dev stack

Reset the dev database (`scripts/dev/reset-dev-database.sh`, 001), which creates `admin`,
`reseller` (domain administrator of `example.org` and `example.net`) and the mailboxes
`alice@example.org` and `bob@example.org`, all with the password `Passw0rd!dev`. Set
`ALLOW_ADMIN_EMAIL_LOGIN=y` in the dev environment for the webmail checks.

```bash
B=http://localhost:8080/api/v2; J='-H Content-Type:application/json'; M='-H Content-Type:application/merge-patch+json'; C='-c jar -b jar'
T=$(curl -s $C $B/csrf-token | jq -r .data.token)
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/session -d '{"username":"admin","password":"Passw0rd!dev"}' >/dev/null

# US2: defaults of a new domain administrator, read before creating one.
curl -s $C $B/capabilities/accounts | jq '.data.domainAdminPermissionDefaults | {loginAs, unlimitedQuota}'
#   -> {"loginAs": true, "unlimitedQuota": false}

# US1: create, list with a domain filter, change domains, rename.
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/domain-admins -i \
  -d '{"username":"Reseller1","password":"Passw0rd!dev","domains":["example.org","example.net"]}' | grep -i '^location'
#   -> Location: /api/v2/domain-admins/reseller1
curl -s $C "$B/domain-admins?domain=example.org&sort=domainCount&order=desc" | jq '.meta, [.data[].username]'
E=$(curl -s $C $B/domain-admins/reseller1 | jq -r .data.etag)
curl -s $C $M -H "X-CSRF-Token: $T" -H "If-Match: \"$E\"" -X PATCH $B/domain-admins/reseller1 \
  -d '{"domains":["example.net"],"permissions":{"loginAs":false}}' | jq '.data | {domains, loginAs: .permissions.loginAs}'
curl -s $C $M -H "X-CSRF-Token: $T" -H "If-Match: \"$E\"" -X PATCH $B/domain-admins/reseller1 -d '{"active":false}' -o /dev/null -w '%{http_code}\n'
#   -> 412 (the tag changed)

# US4: the last active administrator is protected; own deletion is refused.
curl -s $C $M -H "X-CSRF-Token: $T" -X PATCH $B/admins/admin -d '{"active":false}' | jq .type
#   -> "/api/v2/problems/last-active-admin"
curl -s $C -H "X-CSRF-Token: $T" -X DELETE $B/admins/admin | jq .type
#   -> "/api/v2/problems/cannot-delete-own-account"

# US4: administrator writes refuse keys. Create a read-write key owned by admin (001), then:
curl -s -H "X-API-Key: $KEY" $J -X POST $B/admins -d '{"username":"x","password":"Passw0rd!dev"}' | jq .type
#   -> "/api/v2/problems/session-required";  GET $B/admins with the key -> 200

# US1: bulk deactivation with a stale tag for one item.
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/domain-admin-bulk-actions \
  -d '{"action":"deactivate","targets":[{"id":"reseller1"},{"id":"reseller","version":"stale"}]}' | jq '.data.results'
#   -> reseller1: done (deactivated); reseller: outdated (still active)

# US5: issue a token with the key and sign in with it in a fresh cookie jar.
TOK=$(curl -s -H "X-API-Key: $KEY" -X POST $B/domain-admins/reseller/sso-token | jq -r .data.token)
T2=$(curl -s -c jar2 -b jar2 $B/csrf-token | jq -r .data.token)
curl -s -c jar2 -b jar2 $J -H "X-CSRF-Token: $T2" -X POST $B/session/sso-token -d "{\"token\":\"$TOK\"}" | jq '.data | {role, authMethod}'
#   -> {"role":"domainAdmin","authMethod":"ssoToken"}; the same token again -> sign-in-failed

# US3: impersonate, act, return.
R=$(curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/session/impersonation -d '{"role":"mailboxUser","username":"Bob@Example.org"}')
echo "$R" | jq '.data | {target, impersonator, role: .identity.role}'
T=$(echo "$R" | jq -r .data.csrfToken.token)          # the token was renewed
curl -s $C $B/me | jq '.data.impersonation.impersonator'  # -> {"username":"admin","role":"admin",...}
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/me/passkeys/registration-options | jq .type
#   -> "/api/v2/problems/not-allowed-while-impersonating"
curl -s $C -H "X-CSRF-Token: $T" -X DELETE $B/session/impersonation -o /dev/null -w '%{http_code}\n'   # 204
T=$(curl -s $C $B/csrf-token | jq -r .data.token)

# US6: webmail on behalf (setting on), then the audit trail (US7).
curl -s $C -H "X-CSRF-Token: $T" -X POST -i $B/mailboxes/alice@example.org/webmail-sign-in | grep -i '^set-cookie'
curl -s $C "$B/audit-events?impersonatedAccount=bob@example.org" | jq '[.data[] | {eventType, actor: .actor.username, impersonatedAccount}]'
curl -s $C "$B/audit-events?targetType=domainAdmin&targetId=reseller1" | jq '[.data[] | {eventType, details}]'
```

**Expected**: each command prints the value in its comment. No response after the issue response
contains the token, and `docker compose logs app | grep -c -e Passw0rd -e "$TOK"` prints `0`.

## Part C: Coexistence on a mailcow host

1. **Rows equal legacy** (SC-001): create a domain administrator with the same input through
   `/api/v2` and through the legacy "Add domain administrator" form. `admin`, `domain_admins` and
   `da_acl` rows are equal apart from the username, timestamps and the password hash.
2. **Legacy sign-in**: the new accounts sign in to the legacy UI; a forced password change set
   through the API is enforced by the legacy login.
3. **Shared tokens** (SC-006): issue a token through `/api/v2`, open
   `https://$MAILCOW_HOSTNAME/domainadmin?sso_token=<token>`; the legacy UI signs the reseller in,
   and the same token then fails at `POST /api/v2/session/sso-token`. Repeat with a token issued
   through `/api/v1` (`add/sso/domain-admin`) and consumed by the API.
4. **Legacy edits are visible** (FR-072): change a domain administrator's domains in the legacy
   edit page; the next `GET /api/v2/domain-admins/{username}` shows them with a new `etag`.
5. **Webmail on behalf**: with `ALLOW_ADMIN_EMAIL_LOGIN=y` in `mailcow.conf`, the webmail opens
   without a sign-in prompt in the browser that made the request, and the mailbox's recent logins in
   the legacy UI show an `SSO` entry.
6. **Independence** (FR-053): an impersonation in the new web app does not change the legacy UI
   session of the same browser, and a legacy dual login does not change the API session.
