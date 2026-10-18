# Quickstart and Validation Guide: Feature 011

**Feature**: `specs/011-monitoring-operations` | **Plan**: [plan.md](./plan.md) |
**Contract**: [`api/openapi.yaml`](../../api/openapi.yaml) (modules listed in
[contracts/README.md](./contracts/README.md))

This guide proves that the feature works. Part A covers the automated suite in feature 001's dev
stack, Part B manual checks against the dev stack with fakes, and Part C checks on a real mailcow
host, where dockerapi, netfilter, Postfix and Rspamd are real. It references the contract, the
data model and the research instead of repeating them.

## Prerequisites

- Feature 001's dev stack and prerequisites (Docker with Compose v2; `curl` and `jq` on the host).
- Features 001 to 010 implemented (011 reuses 002's dockerapi client and 006's Rspamd client,
  [R1](./research.md#r1-code-this-feature-reuses)).
- For Part C: mailcow-dockerized `2026-09a` (or the FeldHost fork `feldhost-202609a`) with the API
  container installed as in 001's quickstart Part C, and a test domain that can send and receive
  mail.

## Part A: Automated suite

```bash
docker compose build app                       # the dev image now contains python3 (R20)
docker compose run --rm app composer contract:lint
docker compose run --rm app composer test      # schema fixture, restv2_ migrations, PHPUnit, coverage
docker compose run --rm app vendor/bin/phpunit --group performance   # SC-003 latency check
docker compose run --rm app vendor/bin/pint --test
docker compose run --rm app vendor/bin/phpstan analyse
```

**Expected**:

- The lint shows only 001's four known `operation-4xx-response` warnings.
- `composer test` is green and `build/contract/coverage.json` lists all 42 operations of this
  feature as covered.
- `tests/Feature/Security/MonitoringAuthorizationTest.php` shows every operation refused for
  domain administrators, mailbox users and domain-administrator keys, every state-changing
  operation refused for read-only keys, and every read allowed for read-only administrator keys.
- `tests/Feature/Logs/LegacyLogMaskingTest.php` finds no planted secret in any response.
- The fakes report the exact dockerapi and Rspamd calls; `MonitoringSharedCollectionTest` shows one
  dockerapi host call for ten concurrent overview requests.

## Part B: Manual checks against the dev stack

The dev stack has no dockerapi, Postfix or Rspamd. Set `RESTV2_FAKE_OUTBOUND=y` in `.env`
(local environment only; `AppServiceProvider` refuses it elsewhere) to bind the fakes with the
recorded payloads of `tests/fixtures/monitoring/`, then sign in as the dev seeder's `admin`
(001 quickstart Part B).

```bash
B=http://localhost:8080/api/v2; C='-c jar -b jar'; J='-H Content-Type:application/json'
T=$(curl -s $C $B/csrf-token | jq -r .data.token)
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/session -d '{"username":"admin","password":"Passw0rd!dev"}' >/dev/null

# US1: overview, storage, containers, capabilities
curl -s $C $B/host | jq '.data | {hostName, timeZone, cpu, collectedAt}'
curl -s $C $B/host/mail-storage | jq '.data | {measured, totalBytes, usedPercent, approximate}'
curl -s $C "$B/containers?sort=name&limit=50" | jq '.data[] | {name, state, role, protected, availableActions}'
curl -s $C $B/capabilities/monitoring | jq '.data | {pollIntervalSeconds, maxAgeSeconds, clientAddress, limits}'

# US2: a protected container cannot be stopped
curl -s $C -H "X-CSRF-Token: $T" -X POST $B/containers/mysql-mailcow/stop | jq '{type, container}'
#   -> "/api/v2/problems/container-protected", "mysql-mailcow"

# US3: logs, newest first, then the next page without duplicates
docker compose exec redis redis-cli -a "$REDISPASS" -n 0 LPUSH POSTFIX_MAILLOG \
  '{"time":"1760800000","priority":"err","program":"postfix/smtp","message":"to=<alice@example.org> bounced"}'
curl -s $C "$B/logs/postfix?q=ALICE@example.org&severity=error" | jq '.meta.total, .data[0].id'
OLDEST=$(curl -s $C "$B/logs/postfix?limit=100" | jq -r '.data[-1].id')
curl -s $C "$B/logs/postfix?limit=100&before=$OLDEST" | jq '.data | length'

# US5: ban settings with version tag
ETAG=$(curl -sI $C $B/fail2ban/settings | awk -F': ' 'tolower($1)=="etag"{print $2}' | tr -d '\r')
curl -s $C -H "X-CSRF-Token: $T" -H "If-Match: $ETAG" -H 'Content-Type: application/merge-patch+json' \
  -X PATCH $B/fail2ban/settings -d '{"banTime":3600}' | jq '.data | {banTime, etag}'
curl -s $C -H "X-CSRF-Token: $T" -H 'Content-Type: application/merge-patch+json' \
  -X PATCH $B/fail2ban/settings -d '{"denylist":["0.0.0.0/0"]}' | jq '.errors'
#   -> {"denylist.0": [{"code": "reserved", ...}]}
docker compose exec redis redis-cli -a "$REDISPASS" -n 0 GET F2B_OPTIONS   # legacy JSON shape

# US8: the audit trail of the above
curl -s $C "$B/audit-events?targetType=container&limit=5" | jq '.data[] | {eventType, outcome, failureReason, target}'
```

**Expected**: each command prints the value in its comment. No response contains a container
environment variable, a masked secret or message content in an audit record.

## Part C: Validation on a mailcow host

1. **Install**: update the API container (001 quickstart Part C) and merge the new lines of
   `deploy/docker-compose.override.yml` (environment `COMPOSE_PROJECT_NAME`, `SKIP_CLAMD`,
   `SKIP_OLEFY`, `LOG_LINES`; volume `rspamd-vol-1:/var/lib/rspamd:ro`). Copy the updated
   `deploy/nginx/site.restv2.custom` (`fastcgi_read_timeout 90s`) and restart `nginx-mailcow`.
   FeldHost only: add `RESTV2_UPDATE_CHECK=n` to `mailcow.conf`.
2. **Parity with the dashboard (SC-001)**: compare `GET /host`, `/host/mail-storage` and
   `/containers` with the legacy "System & Containers" tab and with `docker compose ps` and
   `df -H` on the host. With `SKIP_CLAMD=y`, `clamd-mailcow` is absent from the list and
   `GET /containers/clamd-mailcow` answers `404`.
3. **Shared collection (SC-004)**: poll `GET /host` from ten shells every 5 s for a minute and count
   `GET /host/stats` lines in `docker compose logs dockerapi-mailcow`; the count equals one
   poller's.
4. **Container control (US2, SC-009)**: restart `sogo-mailcow` and check the new `startedAt`; stop
   and start `clamd-mailcow`; try to stop `mysql-mailcow` and `restv2-mailcow` (both `409`).
   `docker compose stop dockerapi-mailcow` and repeat a restart: `503 temporary-failure`. Each
   action appears once in `GET /audit-events?targetType=container`.
5. **Logs (US3)**: compare every log type with the legacy tab, including an ACME entry and a legacy
   API request that carried a password; the API shows `*`.
6. **Mail queue (US4)**: send mail to an unreachable domain to fill the deferred queue; filter by
   recipient, view one message (audited), hold, release, delete a selection that includes a
   delivered ID (`notFound`), flush, then delete all. Compare with `postqueue -p` in
   `postfix-mailcow`. Stop Postfix and list the queue: `409 mail-service-not-running`.
7. **Ban service (US5, SC-007, SC-008)**: fail logins from a test address until it is banned; lift
   the ban and see it leave `GET /fail2ban/active-bans` within 15 s; allowlist and denylist a
   network and confirm with `nft list ruleset` (or `iptables -S MAILCOW`) within 70 s; replace the
   rules with a valid set and confirm netfilter keeps running (`docker compose ps netfilter-mailcow`);
   reset the rules; rotate the ban list ID and check that the old `f2b-banlist?id=` address answers
   `404`.
8. **Rspamd (US6)**: compare `GET /rspamd/history` and `/rspamd/statistics` with the Rspamd web
   interface.
9. **Update check and addresses (US7)**: with `RESTV2_UPDATE_CHECK=y` on `master`, `GET
   /installation/update-status` reports `upToDate` or `updateAvailable`; with `n` it reports
   `disabled` and the container makes no outbound request (`tcpdump` or the proxy log). With the IP
   check off in the legacy customisation, `GET /host/public-addresses` answers `409`.

### Operator notes

- The ban service rewrites `F2B_OPTIONS` every 10 seconds without a transaction. A settings change
  saved in exactly that instant can be overwritten, as with the legacy UI; reading the settings
  after saving shows it ([R18](./research.md#r18-settings-storage-version-tag-and-atomic-writes)).
- `DOCKER_TIMEOUT` set in `vars.local.inc.php` is honoured. nginx's `fastcgi_read_timeout` (90 s)
  and php-fpm's `request_terminate_timeout` (100 s) fit the default of 60 s; when raising it, keep
  them at least `DOCKER_TIMEOUT + 30` and `DOCKER_TIMEOUT + 40`
  ([R28](./research.md#r28-deployment-and-runtime-limits)).
