# Quickstart and Validation Guide: Feature 013

**Feature**: `specs/013-system-settings` | **Plan**: [plan.md](./plan.md) |
**Contract**: [`api/openapi.yaml`](../../api/openapi.yaml)

This guide proves that the feature works. It builds on feature 001's quickstart (dev stack, test
commands, dev accounts) and only adds what 013 needs. Part A is the automated suite, Part B manual
checks against the dev stack, Part C checks on a real mailcow host.

## Prerequisites

- Feature 001's prerequisites, and features 001 to 012 implemented (013 reuses their code, see
  research section 0).
- For Part C: a mailcow-dockerized host on upstream `2026-09a` (or the FeldHost fork), with the
  API container installed as in 001's quickstart Part C.

## Part A: Automated suite

```bash
docker compose run --rm app composer contract:lint      # 013 modules lint clean
docker compose run --rm app composer test                # includes tests/Feature/{Settings,Rspamd,GlobalFilters,MassMail}
docker compose run --rm app vendor/bin/pint --test
docker compose run --rm app vendor/bin/phpstan analyse
```

**Expected**:

- `build/contract/coverage.json` lists the 45 operations of this feature as covered.
- The suites assert the side effects: Redis keys and their exact bytes (compared with fixtures
  produced by legacy's functions), file contents and modification times in the shared-files
  sandbox, the calls recorded by `FakeDockerApiClient`, `FakeLmtpClient` and
  `FakeSettingsMapClient`, and one audit record per change.
- `tests/Feature/Settings/SecretScanTest.php` finds no Rspamd password in the log output, the audit
  table or any response of the run (SC-012).

## Part B: Manual checks against the dev stack

The dev stack has no mailcow, so it reads and writes copies of mailcow's shared files under
`storage/dev-mailcow/` (git-ignored; `.env.example` points `RESTV2_RSPAMD_MAP_DIR` and
`RESTV2_GLOBAL_SIEVE_DIR` there) and uses fakes for dockerapi and LMTP only in tests; manual checks
of the Dovecot restart, the Rspamd password and mass mail delivery belong to Part C. Copy the
fixtures, reset the dev database and sign in as `admin` as in 001's quickstart Part B:

```bash
mkdir -p storage/dev-mailcow/rspamd_custom_maps storage/dev-mailcow/global_sieve
cp tests/fixtures/mailcow/rspamd-custom/*.map storage/dev-mailcow/rspamd_custom_maps/
cp tests/fixtures/mailcow/global-sieve/global_sieve_before storage/dev-mailcow/global_sieve/before
cp tests/fixtures/mailcow/global-sieve/global_sieve_after storage/dev-mailcow/global_sieve/after
docker compose run --rm app scripts/dev/reset-dev-database.sh
docker compose up -d app web
```

```bash
B=https://127.0.0.1:18443/api/v2; J='-H Content-Type:application/json'
M='-H Content-Type:application/merge-patch+json'; C='-k -c jar -b jar'
T=$(curl -s $C $B/csrf-token | jq -r .data.token)
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/session -d '{"username":"admin","password":"Passw0rd!dev"}' >/dev/null

# Capabilities (FR-094)
curl -s $C $B/capabilities/settings | jq '.data | {hostname, demoMode, licenseVerificationEnabled, logos, massMail}'

# US1: logo upload by content, SVG refusal, public configuration
curl -s $C -H "X-CSRF-Token: $T" -H 'Content-Type: image/jpeg' --data-binary @tests/fixtures/logos/valid/logo-300x80.png \
  -X PUT $B/settings/logos/light | jq '.data | {mediaType, width, height, sizeBytes}'      # image/png, 300, 80
curl -s $C -H "X-CSRF-Token: $T" -H 'Content-Type: image/svg+xml' --data-binary @tests/fixtures/logos/hostile/eventHandlerAttribute--onload.svg \
  -X PUT $B/settings/logos/dark | jq '.errors.body[0] | {code, params}'                    # unsafeSvgContent, eventHandlerAttribute
curl -s $B/public-config | jq '.data | {logos, brandColors}'

# US1: plain-text product name stored escaped, colours with contrast check
curl -s $C $M -H "X-CSRF-Token: $T" -X PATCH $B/settings/ui-texts -d '{"productName":"Acme & Co Mail"}' | jq .data.productName
docker compose exec redis redis-cli -a devredispass GET MAIN_NAME                          # "Acme &amp; Co Mail"
curl -s $C $M -H "X-CSRF-Token: $T" -X PATCH $B/settings/brand-colors -d '{"light":"#FFD700"}' | jq '.errors.light[0].params'
#   -> {"ratio":1.4,"minimum":4.5,"surface":"#ffffff"}
curl -s $C $M -H "X-CSRF-Token: $T" -X PATCH $B/settings/brand-colors -d '{"light":"#1D4ED8"}' | jq .data.effective
#   -> light {color "#1d4ed8", foreground "#ffffff"}, dark null

# US2: policy change with 003's tag
E=$(curl -sI $C $B/password-policy | awk -F': ' 'tolower($1)=="etag"{print $2}' | tr -d '\r')
curl -s $C $M -H "X-CSRF-Token: $T" -H "If-Match: $E" -X PATCH $B/password-policy \
  -d '{"minLength":10,"requireDigits":true,"requireMixedCase":true}' | jq .data
curl -s $C $M -H "X-CSRF-Token: $T" -H "If-Match: $E" -X PATCH $B/password-policy -d '{"minLength":12}' -o /dev/null -w '%{http_code}\n'  # 412

# US3: rules and regex maps
curl -s $C $J -H "X-CSRF-Token: $T" -X POST "$B/settings/rspamd-rules?validateOnly=true" \
  -d '{"description":"broken","content":"rcpt = \"x\"; apply {"}' | jq '.errors.content[0]'  # unbalancedStructure, unclosedBrace
curl -s $C $J -H "X-CSRF-Token: $T" -X PUT $B/settings/rspamd-regex-maps/global_smtp_from_blacklist.map \
  -d '{"content":"# comment\n/.*@spam\\.example$/i\n"}' | jq '.data | {id, available}'
cat storage/dev-mailcow/rspamd_custom_maps/global_smtp_from_blacklist.map                  # trimmed content + newline
curl -s $C $J -H "X-CSRF-Token: $T" -X PUT $B/settings/rspamd-regex-maps/global_smtp_from_blacklist.map \
  -d '{"content":"/[unclosed/i"}' | jq '.errors.content[0].params'                        # {"line":1,"reason":"doesNotCompile"}

# US5 and US7
curl -s $C $M -H "X-CSRF-Token: $T" -X PATCH $B/domains/example.org/quota-notification-recipients \
  -d '{"recipients":["it@example.org","IT@example.org"],"active":true}' | jq .data          # stored once
curl -s $C $M -H "X-CSRF-Token: $T" -X PATCH $B/settings/legacy-api-cors \
  -d '{"allowedOrigins":["https://portal.example.org/app/"]}' | jq '.errors["allowedOrigins.0"][0].params'   # {"format":"origin"}

curl -s $C $M -H "X-CSRF-Token: $T" -X PATCH $B/settings/ip-check -d '{"enabled":true}' | jq .data.enabled  # true
curl -s $C $B/license | jq .data.lastVerification.result                                 # "neverVerified" (nothing sent)

# US4: a global Sieve script is checked with legacy's parser
curl -s $C $J -H "X-CSRF-Token: $T" -X PUT "$B/settings/global-sieve-filters/prefilter?validateOnly=true" \
  -d '{"script":"if true { keep }"}' | jq '.errors.script[0].code'                        # "sieveSyntax"

# US6: preview with search
curl -s $C $J -H "X-CSRF-Token: $T" -X POST "$B/mass-mail-jobs/recipient-preview?q=alice" -d '{"selection":{}}' | jq .meta

# nginx: a logo body above the general 5 MiB limit reaches the API, one above 16 MiB does not
head -c 11534336 /dev/zero > /tmp/11m.bin; head -c 17825792 /dev/zero > /tmp/17m.bin
curl -s $C -H "X-CSRF-Token: $T" -H 'Content-Type: image/png' --data-binary @/tmp/11m.bin -X PUT $B/settings/logos/light -o /dev/null -w '%{http_code}\n'  # 422 (from the API)
curl -s $C -H "X-CSRF-Token: $T" -H 'Content-Type: image/png' --data-binary @/tmp/17m.bin -X PUT $B/settings/logos/light -o /dev/null -w '%{http_code}\n'  # 413 (from nginx)
```

**Expected**: each command prints the value in its comment; `GET /audit-events?targetType=rspamdRegexMap`
lists the map change with its sizes.

**Walked through** on 2026-10-19 on the dev stack (compose project without host ports, the requests
sent from a `curl` container in the compose network to `https://web/api/v2`): every command printed
the value in its comment. The capabilities report the dev host name `localhost`; the 11 MiB body is
answered by the API (`unsupportedImageType`, zeros are no image) and the 17 MiB body by nginx.

## Part C: Validation on a mailcow host

1. **Install the extensions of the deployment** (research R26): merge the new volumes, environment
   variables and ofelia labels of `deploy/docker-compose.override.yml`, copy the updated
   `deploy/nginx/site.restv2.custom`, then `docker compose up -d --no-deps restv2-mailcow`,
   reload nginx only after the test passes
   (`docker exec <nginx container> nginx -t && docker exec <nginx container> nginx -s reload`), and
   run `docker compose restart ofelia-mailcow` so that the new ofelia labels are read. For the FeldHost deployment add
   `RESTV2_LICENSE_VERIFICATION=false`. If `$LOGO_LIMITS['max_size']` was raised above 15 MiB, raise
   the logo location's `client_max_body_size` as well.
2. **Permissions**: `docker compose exec restv2-mailcow id -u` prints `82`;
   `docker compose exec restv2-mailcow test -w /mailcow/rspamd_custom_maps/global_rcpt_blacklist.map && echo ok`.
3. **Branding parity (SC-001, SC-002)**: change the product name, the announcement and a logo
   through the API and reload the legacy login page: identical texts and image. Change them in the
   legacy "Customize" tab: the next `GET /api/v2/settings/ui-texts` shows them and the tag changed.
4. **Regex maps (SC-005)**: add `/.*@spam-test\.example$/i` to the envelope sender denylist through
   the API; within 2 minutes a test message from `x@spam-test.example` gets `MAILCOW_BLACK`
   (`docker compose logs rspamd-mailcow | grep MAILCOW_BLACK`), without an Rspamd restart. The legacy
   map editor shows the new content.
5. **Settings rule**: create the preset "Disable Rspamd for a domain" adapted to a test domain as
   active; within the settings map refresh the effective map (`GET /settings/rspamd-settings-map`)
   shows `additional_settings_<id>` and Rspamd applies it.
6. **Global Sieve filter (SC-007)**: save a valid pre-filter that adds a header; the response reports
   `meta.dovecotRestart.outcome = done`; the next delivered message carries the header. Save an invalid
   script: `422`, and `docker compose ps dovecot-mailcow` shows no restart.
7. **Rspamd password**: set a 12-character passphrase with a session; sign in to `/rspamd/` with it.
   `docker compose logs restv2-mailcow | grep -c '<passphrase>'` prints `0`.
8. **Mass mail (SC-008, SC-009)**: preview a test domain, send with the shown count; within seconds
   the job is `running`, each mailbox receives one message (header `X-Mailer: MooMassMail`, no
   `X-Spamd-Result`), and the job completes. Send a large job and cancel it: the counts freeze within
   5 seconds and the rest is `skipped`. Restart the API container during a job: the job continues
   and at most one recipient is `failed` with `interrupted`.
9. **License**: on an upstream host, `POST /api/v2/license/verification` returns `valid` (or
   `unreachable` without outbound access); the legacy dashboard's license card shows the same.
   With `RESTV2_LICENSE_VERIFICATION=false`, both license operations answer `feature-disabled` and
   no request leaves the host (`tcpdump` shows none to `verify.mailcow.email`).
10. **Quota copies**: as a domain administrator, set copy recipients for an own domain, push a test
    mailbox over 80%: the recipients get the copy from the user with the subject plus the username.
