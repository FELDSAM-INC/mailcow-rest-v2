# Quickstart and Validation Guide: Feature 005

**Feature**: `specs/005-account-self-service` | **Plan**: [plan.md](./plan.md) |
**Contract**: [`api/modules/self-service/`](../../api/modules/self-service/)

This guide proves that the feature works. Part A runs the automated suite, Part B walks through the
main flows against the dev stack, Part C checks parity and coexistence on a real mailcow host. It
assumes feature 001's quickstart works (dev stack, schema fixture, `composer test`) and refers to
the contract and the data model instead of repeating them.

## Prerequisites

- Features 001 to 004 implemented and green (005 builds on their services, research R1).
- Docker with Compose v2; `curl` and `jq` on the host.
- For Part C: a mailcow-dockerized installation on upstream `2026-09a` (or the FeldHost fork),
  reachable over HTTPS, with the API container from 001's Part C, an iPhone or a Mac for the Apple
  profile, and a mail client.

## Part A: Automated suite

```bash
# Contract (lint must stay clean; the bundle feeds contract validation in the tests).
docker compose run --rm app composer contract:lint
docker compose run --rm app composer contract:bundle

# Legacy reference files used by the tests (re-run only for a new upstream release).
scripts/legacy/extract-sieve-parser.sh 2026-09a       # → resources/legacy/sieve/
scripts/legacy/extract-sogo-auth.sh 2026-09a          # → tests/fixtures/legacy/sogo-auth/2026-09a/
scripts/legacy/check-handoff-assumptions.sh 2026-09a  # must print "ok"

# Whole suite (MariaDB with the 2026-09a fixture, Redis database 15, contract validation,
# contract coverage), then the timing test of the reset acknowledgement.
docker compose run --rm app composer test
docker compose run --rm app vendor/bin/phpunit --group timing

docker compose run --rm app vendor/bin/pint --test
docker compose run --rm app vendor/bin/phpstan analyse
```

**Expected**:

- Lint reports no problems; `build/contract/coverage.json` lists all 51 operations of this feature.
- `tests/Feature/Webmail/SogoAuthCompatibilityTest.php` passes: the unmodified legacy
  `sogo-auth.php` answers `X-User: <mailbox>` for a hand-off and an empty `X-User` after sign-out,
  after a password change that ends the session, and after the sweeper.
- The timing group reports reset-acknowledgement medians within 50 ms across eligible, ineligible
  and unknown addresses (SC-005).
- No migration file was added (`git status database/migrations` is clean).

## Part B: Manual flows against the dev stack

Run `docker compose run --rm app scripts/dev/reset-dev-database.sh` first (001's seeder: `admin`,
`reseller`, `alice@example.org` with TOTP `JBSWY3DPEHPK3PXP`, `bob@example.org` with a forced
password change; password `Passw0rd!dev`). Set the reset template once:
`docker compose exec redis redis-cli -a "$REDISPASS" MSET PW_RESET_FROM noreply@example.org PW_RESET_SUBJ "Reset"`.

```bash
B=http://localhost:8080/api/v2; J='-H Content-Type:application/json'; C='-c jar -b jar'
T=$(curl -s $C $B/csrf-token | jq -r .data.token)

# Capabilities: signed out only passwordReset, signed in everything plus account.
curl -s $B/capabilities/self-service | jq '.data'                      # {"passwordReset":{"available":true,...}}

# US1: bob resolves the forced password change without the current password.
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/session -d '{"username":"bob@example.org","password":"Passw0rd!dev"}' | jq .data.state   # restricted
curl -s $C $B/me/password-policy | jq '.data'
curl -s $C $J -H "X-CSRF-Token: $T" -X PUT $B/me/password -d '{"newPassword":"short"}' | jq '.errors'           # tooShort / missingCharacterClass
curl -s $C $J -H "X-CSRF-Token: $T" -X PUT $B/me/password -d '{"newPassword":"{SSHA512}abc"}' | jq '.errors'    # preHashedPassword
curl -s $C $J -H "X-CSRF-Token: $T" -X PUT $B/me/password -d '{"newPassword":"N3w!Passw0rd"}' | jq '.data'      # sessionsEnded, activeAppPasswords
curl -s $C $B/session | jq .data.state                                                                          # active

# US4: app passwords (generated password shown once).
curl -s $C $J -H "X-CSRF-Token: $T" -X POST "$B/mailboxes/bob@example.org/app-passwords" -d '{"name":"Phone","protocols":["imap","smtp"]}' | jq '.data | {id, generatedPassword}'
curl -s $C "$B/mailboxes/bob@example.org/app-passwords" | jq '.data[] | {id, name, etag}'                       # no password anywhere

# US2: TOTP enrolment for bob (code computed in the app container from the returned secret).
S=$(curl -s $C -H "X-CSRF-Token: $T" -X POST $B/me/second-factors/totp-enrolment | jq -r .data.secret)
CODE=$(docker compose run --rm app php artisan tinker --execute "echo (new RobThree\Auth\TwoFactorAuth(new App\Support\Totp\NoQrCodeProvider()))->getCode('$S');")
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/me/second-factors -d "{\"type\":\"totp\",\"name\":\"Phone\",\"code\":\"$CODE\",\"currentPassword\":\"N3w!Passw0rd\"}" | jq '.data'
curl -s $C -H "X-CSRF-Token: $T" -X DELETE "$B/me/second-factors/$(curl -s $C $B/me/second-factors | jq '.data[0].id')" | jq .type   # last-second-factor

# US6 and US7: temporary alias, mail settings.
curl -s $C $J -H "X-CSRF-Token: $T" -X POST "$B/mailboxes/bob@example.org/temporary-aliases" -d '{"domain":"example.org","description":"shop"}' | jq '.data.address'
curl -s $C $J -H "X-CSRF-Token: $T" -H 'Content-Type: application/merge-patch+json' -X PATCH "$B/mailboxes/bob@example.org/mail-settings" -d '{"taggedMailHandling":"subfolder"}' | jq '.data'
docker compose exec redis redis-cli -a "$REDISPASS" HGET RCPT_WANTS_SUBFOLDER_TAG bob@example.org   # "1"

# US5: overview and webmail hand-off (the cookie lands in the jar).
curl -s $C $B/me/mailbox | jq '.data | {address, quota, temporaryAliasDomains}'
curl -s $C -D - -H "X-CSRF-Token: $T" -X POST $B/me/webmail-handoff -o /dev/null | grep -i '^set-cookie: MCSESSID'
docker compose exec redis redis-cli -a "$REDISPASS" --scan --pattern 'PHPREDIS_SESSION:*'              # one key

# US3: password reset (the message goes to the array mailer in local; see storage/logs).
curl -s $J -c rjar -b rjar -H "X-CSRF-Token: $(curl -s -c rjar -b rjar $B/csrf-token | jq -r .data.token)" \
  -X POST $B/password-resets -d '{"address":"nobody@example.org"}' | jq '.data'                          # same answer for any address

# US12: client configuration and Apple profile.
curl -s $C $B/me/mailbox/client-configuration | jq '.data | {imap, smtp, caldav}'
curl -s $C "$B/me/mailbox/apple-profile?variant=email" -o profile.mobileconfig && head -3 profile.mobileconfig

# Sign out: the hand-off's legacy session disappears at once.
curl -s $C -H "X-CSRF-Token: $T" -X DELETE $B/session -o /dev/null
docker compose exec redis redis-cli -a "$REDISPASS" --scan --pattern 'PHPREDIS_SESSION:*'              # none
```

**Expected**: each command prints the value in its comment. No response, log line
(`docker compose logs app`) or audit record (`GET /audit-events?targetType=appPassword` as admin)
contains a password, code, token or Pushover credential.

## Part C: On a mailcow host

1. **Install**: as 001's Part C, plus the additions of research R23 in
   `docker-compose.override.yml` (templates mount, `RESTV2_*` settings, the ofelia sweeper label),
   then `docker compose up -d restv2-mailcow`. `docker compose logs ofelia-mailcow | grep
   restv2_webmail_handoff_sweep` shows the job.
2. **Second factors in both directions (SC-001)**: enrol TOTP, a security key and a YubiKey through
   the API for an administrator, a domain administrator and a mailbox user; sign in to the legacy UI
   with each. Enrol each type in the legacy UI; list and remove it through the API.
3. **Password change (SC-002)**: change a mailbox password through the API; the legacy UI accepts
   only the new password at once; IMAP with the old password fails within 5 minutes.
4. **Reset (SC-004, US3 AS-8)**: request a reset through the API for a mailbox with a recovery
   address; the message (operator's template) arrives within a minute and links to
   `RESTV2_PW_RESET_PAGE`. A token issued by the legacy reset page completes through the API and
   vice versa.
5. **Webmail hand-off (SC-008, FR-068)**: in a browser signed in to the new web app, open webmail
   through the hand-off: no sign-in prompt, a `SSO` recent login appears. In the same browser the
   legacy UI shows its sign-in page, and `curl -b 'MCSESSID=<value>' -X POST
   https://$MAILCOW_HOSTNAME/api/v1/add/app-passwd -d '{}'` answers "API read/write access
   denied". Sign out of the new web app: webmail asks for a sign-in at its next request. Let an API
   session expire (lower `RESTV2_SESSION_LIFETIME` temporarily): webmail asks for a sign-in within a
   minute.
6. **Parity of stored data (SC-009)**: create an app password, a temporary alias, a filter and
   Pushover settings through the API and the same through the legacy UI; compare the rows (`SELECT
   … FROM app_passwd / spamalias / sieve_filters / pushover`) and the `RCPT_WANTS_*` entries; they
   differ only in generated values, timestamps and the stated deviations.
7. **Apple profile**: install the profile with an embedded app password on an iPhone; mail,
   calendars and contacts sync; the app password appears in the list, named after the device.
8. **Coexistence**: `SHOW TABLES LIKE 'restv2\_%'` lists no new table; `versions.db_schema` is
   unchanged; the legacy UI, `/api/v1` and SOGo behave as before.
