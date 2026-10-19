# Quickstart and Validation Guide: Feature 006

**Feature**: `specs/006-quarantine-spam-policy` | **Plan**: [plan.md](./plan.md) |
**Contract**: [`api/modules/quarantine/`](../../api/modules/quarantine/),
[`spam-policy/`](../../api/modules/spam-policy/), [`rate-limits/`](../../api/modules/rate-limits/)

This guide proves that the feature works. Part A runs the automated suite, Part B exercises the
API on the dev stack, and Part C checks the parity with legacy on a real mailcow host. It relies on
feature 001's dev stack and guide ([001 quickstart](../001-auth-sessions-api-keys/quickstart.md))
and refers to the contract and the data model instead of repeating them.

## Prerequisites

- Features 001–005 implemented (006 relies on their code, research R1).
- Docker with Compose v2; `curl` and `jq` on the host.
- For Part C: a mailcow-dockerized installation on upstream `2026-09a` (or the FeldHost fork) with
  the API container deployed per 001, and this feature's override additions
  (`rspamd-vol-1:/var/lib/rspamd:ro`, `./data/assets/templates:/mailcow/assets/templates:ro,z`,
  `SKIP_CLAMD`).

## Part A: Automated suite

```bash
# Rebuild the dev image: it now contains mailparse, python3, py3-jinja2 and py3-html2text.
docker compose build app
docker compose run --rm app composer install

# The renderer works in the image (prints {"ok": true, ...}).
echo '{"template":"Hi {{ username }}","datasets":[{"meta":[],"username":"u","counter":0,"hostname":"h","quarantine_acl":0}]}' \
  | docker compose run --rm -T app python3 -I -B resources/bin/quarantine-template.py

# Lint the contract, then run the whole suite (contract validation and coverage included).
docker compose run --rm app composer contract:lint
docker compose run --rm app composer test

# Only this feature's tests, then the performance group (SC-007: 100,000 items).
docker compose run --rm app vendor/bin/phpunit tests/Feature/Quarantine tests/Feature/SpamPolicy \
  tests/Feature/RateLimits tests/Unit/Quarantine tests/Unit/Clients
docker compose run --rm app vendor/bin/phpunit --group performance --filter Quarantine
```

**Expected**:

- `contract:lint` reports no error and no warning in the three 006 modules.
- The suite is green; `build/contract/coverage.json` lists all 42 operations of 006 as covered.
- The scan test (`tests/Feature/Quarantine/AuditContentScanTest.php`) finds no subject, body,
  attachment name, `qhash` or template text in any audit record (SC-010).
- The concurrency test releases the same items from parallel processes and observes exactly one
  delivery and one learning per item (SC-006).
- The performance test reports p95 < 1 s for a page of 25 items (SC-007).

### Regenerating the parity goldens (only for a new upstream release)

```bash
scripts/legacy/generate-qitem-goldens.sh 2026-09a          # legacy parser in the php-fpm image
scripts/legacy/generate-template-goldens.sh 2026-09a       # notifier's Jinja2 in the Dovecot image
scripts/legacy/extract-quarantine-release-strings.sh 2026-09a
git diff --stat tests/fixtures/quarantine resources/legacy  # expect no change for 2026-09a
```

## Part B: Manual checks on the dev stack

`scripts/dev/reset-dev-database.sh` loads the schema and runs `DevelopmentSeeder`, which this
feature extends with: 30 quarantine items for `alice@example.org` (rejected, junk, virus, with
attachments, one over 10 MiB, one unparseable) and 5 for `bob@example.org`; a domain block list
entry `*@spam.example` on `example.org`; a rate limit `100 / 1h` on `example.org`; and the default
quarantine settings (retention 20, maximum size 15 MiB). Releases go to the optional `mailpit` SMTP
sink (`docker compose --profile mail up -d mailpit`, `RESTV2_RELEASE_SMTP_HOST=mailpit`,
`RESTV2_RELEASE_SMTP_PORT=1025`; web UI on `http://localhost:8025`). The dev stack has no Rspamd,
so learning reports `failed` and the statistics answer `503`, which exercises the failure paths.

```bash
B=http://localhost:8080/api/v2; J='-H Content-Type:application/json'; C='-c jar -b jar'
M='-H Content-Type:application/merge-patch+json'

# Sign in as alice (001 quickstart, Part B), then:
curl -s $C "$B/quarantine" | jq '.meta, (.data[0] | {id, subject, action, virus, etag})'   # total 30, newest first
ID=$(curl -s $C "$B/quarantine?action=rejected&limit=1" | jq -r '.data[0].id')
curl -s $C "$B/quarantine/$ID" | jq '.data | {contentStatus, symbols: (.symbols|length), attachments: .content.attachments}'
curl -s $C -OJ "$B/quarantine/$ID/attachments/0"; ls -l               # saved as a file, never displayed
BOB=$(docker compose exec -T mariadb mariadb -umailcow -pmailcow mailcow -Nse \
  "SELECT id FROM quarantine WHERE rcpt='bob@example.org' LIMIT 1")
curl -s -o /dev/null -w '%{http_code}\n' $C "$B/quarantine/$BOB"      # 404

# US1: release without learning (mailpit receives it with X-Pre-Release-Spam-Flag), then with learning.
curl -s $C $J -H "X-CSRF-Token: $T" -X POST "$B/quarantine/$ID/release" -d '{"learnAsHam":false}' | jq .data
curl -s -o /dev/null -w '%{http_code}\n' $C "$B/quarantine/$ID"        # 404: removed
ID2=$(curl -s $C "$B/quarantine?limit=1" | jq -r '.data[0].id')
curl -s $C $J -H "X-CSRF-Token: $T" -X POST "$B/quarantine/$ID2/release" | jq '.data | {delivery, learning, fuzzy}'
#   -> delivery done, learning failed (no Rspamd in dev), fuzzy notRequested; the item is gone anyway.

# US1: learning as spam without Rspamd keeps the item.
ID3=$(curl -s $C "$B/quarantine?limit=1" | jq -r '.data[0].id')
curl -s $C -H "X-CSRF-Token: $T" -X POST "$B/quarantine/$ID3/spam-learning" | jq '{type, dependency, step}'
curl -s -o /dev/null -w '%{http_code}\n' $C "$B/quarantine/$ID3"       # 200: kept

# US3 (as admin): settings, template check, validate-only, preview.
curl -s $C "$B/quarantine/settings" | jq '.data | {active, retentionPerMailbox, maxMessageSize, notificationTemplateIsDefault}'
ET=$(curl -sI $C "$B/quarantine/settings" | awk -F': ' 'tolower($1)=="etag"{print $2}' | tr -d '\r')
curl -s $C $M -H "X-CSRF-Token: $T" -H "If-Match: $ET" -X PATCH "$B/quarantine/settings" \
  -d '{"notificationTemplate":"{% for x in meta %}"}' | jq '.errors.notificationTemplate'
#   -> [{"code":"invalidTemplate","params":{"reason":"syntax","line":1},...}]
curl -s $C $M -H "X-CSRF-Token: $T" -X PATCH \
  "$B/quarantine/settings?validateOnly=true" -d '{"maxAgeDays":30}' | jq .data   # {"valid": true}, nothing stored
curl -s $C $J -H "X-CSRF-Token: $T" -X POST "$B/quarantine/settings/template-preview" -d '{}' \
  | jq -r '.data.text' | head -20                                        # the default template as text
docker compose exec redis redis-cli -a "$REDISPASS" MGET Q_RETENTION_SIZE Q_MAX_SIZE Q_MAX_AGE

# US4/US5/US6 (as admin or reseller).
curl -s $C $M -H "X-CSRF-Token: $T" -X PATCH "$B/mailboxes/alice@example.org/quarantine-notification-settings" \
  -d '{"frequency":"daily"}' | jq .data
curl -s $C $J -H "X-CSRF-Token: $T" -X PUT "$B/mailboxes/alice@example.org/spam-score" \
  -d '{"junkScore":6.5,"rejectScore":12}' | jq .data
curl -s $C $J -H "X-CSRF-Token: $T" -X POST "$B/mailboxes/alice@example.org/spam-policy-entries" \
  -d '{"list":"allow","pattern":"*@spam.example"}' | jq '.data | {id, own}'   # 201: the domain entry has another owner
curl -s $C "$B/mailboxes/alice@example.org/spam-policy-entries" | jq '.data[] | {pattern, list, own, owner}'
curl -s $C $J -H "X-CSRF-Token: $T" -X PUT "$B/mailboxes/alice@example.org/rate-limit" \
  -d '{"value":10,"frame":"minute"}' | jq '.data | {limit, effectiveLimit, effectiveSource}'
docker compose exec redis redis-cli -a "$REDISPASS" HGETALL RL_VALUE   # alice@example.org -> "10 / 1m"
docker compose exec redis redis-cli -a "$REDISPASS" HSET RLtestbucket x 1
curl -s -o /dev/null -w '%{http_code}\n' $C -H "X-CSRF-Token: $T" -X DELETE "$B/rate-limit-buckets/RLtestbucket"  # 204
curl -s $C "$B/capabilities/quarantine" | jq .data

# US8: audit trail of the item released above.
curl -s $C "$B/audit-events?targetType=quarantineItem&targetId=$ID" | jq '.data[0] | {eventType, outcome, details}'
```

**Expected**: each command prints the value in its comment. No response contains `qhash`, `msg` or
HTML from a message. `docker compose logs app | grep -c qhash` prints `0`.

### Walkthrough of 2026-10-19

Part B ran on the dev stack of compose project `restv2-006` without host ports: `web` and `mailpit`
ran with `ports: !reset []`, the API container had `RESTV2_RELEASE_SMTP_HOST=mailpit` and
`RESTV2_RELEASE_SMTP_PORT=1025`, and the commands ran in a second `app` container against
`https://web` (`curl -k --connect-to localhost:443:web:443`) and `http://mailpit:8025`. Every check
gave the expected result: 30 items for alice, newest first; bob's item `404`; the attachment
download `application/octet-stream` with `Content-Disposition: attachment` and `nosniff`; the
large item `contentStatus: tooLarge` and its message `409 quarantine-message-too-large`; the
unparseable item `parserFallback: true`, `textPlain: null`; a release without learning delivered to
mailpit and removed; a release with learning `delivery: done, learning: failed, fuzzy:
notRequested`; learning as spam `503 temporary-failure` (`spamFilter`, `learning`) with the item
kept; the settings read back from the seeded `Q_*` keys (`raw` reported as `original`); the
template check `invalidTemplate` (`syntax`, line 1); `validateOnly` `{"valid": true}`; the preview;
the notification, spam score, list entry and rate limit changes; the bucket reset `204`, then
`404`; the statistics `503 spamFilter`; the audit record of the released item without its subject.
No response and no log line contained `qhash`.

Deviations from the commands above (the guide is kept as written; read it with these):

- The dev stack serves the API on `https://127.0.0.1:18443/api/v2` (the session cookie is
  `Secure`), not `http://localhost:8080`.
- alice has a TOTP second factor (feature 001's seed): signing in needs
  `POST /session/pending-sign-in/second-factor` with a code for the secret `JBSWY3DPEHPK3PXP`.
- `X-Pre-Release-Spam-Flag` appears only on a released message that carried `X-Spam-Flag`: of the
  seeded items, those built from `spam-flag.eml` (`q=spam-flag`). The first rejected item has no
  such header, so its delivered copy has none either.
- An attachment's name is `content.attachments[].fileName`.

## Part C: Parity on a mailcow host

1. **Quarantine round trip**: send a GTUBE message to a test mailbox so that it is rejected into
   the quarantine. Compare `GET /quarantine/{id}` with the legacy detail modal (symbols, score,
   recipients, text). Release it through the API: the message arrives in the inbox with
   `X-Pre-Release-Spam-Flag`, the legacy quarantine page no longer lists it, and Rspamd's history
   shows the `learnham`/`fuzzyadd` calls (`docker compose logs rspamd-mailcow | grep -i learn`).
2. **Learning as spam**: stop `rspamd-mailcow`, learn an item as spam: `503`, the item is still in
   the legacy list. Start Rspamd again and repeat: `200`, the item is gone.
3. **Settings**: change the retention and the template through the API; the legacy settings tab
   shows the same values. Save an invalid template through the legacy UI; `GET` returns it, and the
   preview of the stored template answers `422 invalidTemplate`.
4. **Notifications**: set `hourly` through the API. After the notifier's next run (every 20
   minutes), the mailbox receives a notification rendered from the template, and the legacy user
   page shows "hourly".
5. **Spam policy**: add a block list entry through the API. Within one minute, mail from the
   blocked sender is rejected (and not quarantined), and the legacy spam filter tab lists the entry.
6. **Rate limits**: set `1 / 1m` on a mailbox, send two messages with an authenticated client: the
   second is deferred, the rate-limited log (legacy dashboard) shows its bucket; reset the bucket
   through the API and send again at once.
7. **Statistics**: `GET /spam-filter-action-statistics` equals the legacy dashboard chart counts;
   `spamFilterRunningSince` matches the Rspamd container's start time.
