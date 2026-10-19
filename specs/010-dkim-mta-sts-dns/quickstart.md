# Quickstart and Validation Guide: Feature 010

**Feature**: `specs/010-dkim-mta-sts-dns` | **Plan**: [plan.md](./plan.md) |
**Contract**: [`api/modules/dkim`](../../api/modules/dkim/), [`api/modules/mta-sts`](../../api/modules/mta-sts/),
[`api/modules/dns-diagnostics`](../../api/modules/dns-diagnostics/)

This guide proves that the feature works. Part A runs the automated suite, Part B makes manual
checks against the dev stack, and Part C checks a real mailcow host. It builds on
[001's quickstart](../001-auth-sessions-api-keys/quickstart.md) (dev stack, seeder, sign-in) and
refers to the contract and the [data model](./data-model.md) instead of repeating them.

## Prerequisites

- 001's prerequisites (Docker with Compose v2, `curl`, `jq`).
- For Part C: a mailcow-dockerized host on upstream `2026-09a` or the FeldHost fork, with the API
  container deployed through `deploy/docker-compose.override.yml`, and a domain whose DNS you
  control. For the ISPConfig checks: an ISPConfig 3 server with a remote user that may use the
  `dns_zone_get`, `dns_rr_get_all_by_zone` and `dns_txt_*` functions, and a zone for the domain.

## Part A: Automated suite

```bash
docker compose up -d --build                      # dev image now includes bind-tools
docker compose run --rm app composer install
docker compose run --rm app composer contract:lint
docker compose run --rm app composer test         # schema fixture, migrations, PHPUnit, coverage
docker compose run --rm app vendor/bin/pint --test
docker compose run --rm app vendor/bin/phpstan analyse
```

**Expected**:

- `contract:lint` reports nothing for `api/modules/{dkim,mta-sts,dns-diagnostics}`.
- `composer test` is green; `build/contract/coverage.json` lists the 15 operations of this feature
  as covered.
- `DkimSecretLeakTest` (SC-005) finds PEM private key armour only in export responses.
- `ZoneFileRendererTest` runs `named-checkzone` on every rendered zone file without errors (SC-007).

## Part B: Manual checks against the dev stack

Reset and seed as in 001 (`scripts/dev/reset-dev-database.sh`). The seeder also creates the alias
domain `example.com` → `example.org`, a 2,048-bit DKIM key for `example.org` and an unused key for
`old.example`. Sign in as `admin` and keep the CSRF token, as in 001 Part B. The session cookie is
`Secure`, so use the HTTPS listener (`curl -k`). The dev `.env` declares documentation addresses as
the public addresses (`RESTV2_PUBLIC_IPV4`, `RESTV2_PUBLIC_IPV6`), so diagnostics never asks the
echo service; its lookups go to the app container's resolver.

In a parallel worktree without host ports, run the commands inside the `app` container against
`php artisan serve --host=127.0.0.1 --port=8000` (`B=http://127.0.0.1:8000/api/v2`) with an
administrator's API key instead of the session: replace `$C … $H` by `-H "X-API-Key: <key>"`.

```bash
B=https://127.0.0.1:18443/api/v2; J='-H Content-Type:application/json'; C='-k -c jar -b jar'
T=$(curl -s $C $B/csrf-token | jq -r .data.token); H="-H X-CSRF-Token:$T"

# Capabilities (US1, US4): host name, DKIM, MTA-STS and diagnostics limits.
curl -s $C $B/capabilities/dns | jq .data

# US1: list with states; unused keys appear for administrators only.
curl -s $C "$B/dkim-keys?state=missing&state=unused" | jq '.meta, [.data[] | {name, state}]'
curl -s $C $B/dkim-keys/example.org | jq '.data.key.record'

# US2: generate for the alias domain, then try again (409), then rotate with overwrite (200).
curl -s $C $H $J -X POST $B/dkim-keys -d '{"name":"example.com"}' -D - | grep -i -E '^(HTTP|location|etag)'
curl -s $C $H $J -X POST $B/dkim-keys -d '{"name":"example.com"}' | jq .type
#   -> "/api/v2/problems/dkim-key-exists"
curl -s $C $H $J -X POST $B/dkim-keys -d '{"name":"example.com","selector":"s2026","overwrite":true}' \
  | jq '.data | {state, selector: .key.selector, dnsPublication}'
#   -> dnsPublication.status "notConfigured" (publication disabled by default)

# The Redis side effects that Rspamd reads (dev Redis, database 0).
docker compose exec redis redis-cli -a devredispass --no-auth-warning HGET DKIM_SELECTORS example.com
docker compose exec redis redis-cli -a devredispass --no-auth-warning HEXISTS DKIM_PRIV_KEYS s2026.example.com

# US5: import with a bad key (422, no echo), copy, delete (204).
curl -s $C $H $J -X POST $B/dkim-keys/import.example/import -d '{"privateKey":"not a key"}' | jq .errors
curl -s $C $H $J -X POST $B/dkim-keys/example.org/copy -d '{"target":"example.net"}' | jq .data.state
curl -s $C $H -X DELETE $B/dkim-keys/old.example -o /dev/null -w '%{http_code}\n'      # -> 204
curl -s $C $H $J -X POST $B/dkim-keys/bulk-actions \
  -d '{"action":"delete","targets":[{"id":"example.net"},{"id":"nokey.example"}]}' | jq '.data.summary'
#   -> done 1, notFound 1

# US6: export is refused while the setting is off.
curl -s $C $B/dkim-keys/example.org/private-key | jq .type
#   -> "/api/v2/problems/dkim-private-key-export-disabled"

# US4: MTA-STS policy; the identifier changes on content changes only.
curl -s $C $H $J -X POST $B/mta-sts-policies \
  -d '{"domain":"example.org","mode":"enforce","mxHosts":["mail.example.org"],"maxAge":86400,"active":true}' \
  | jq '.data | {policyId, policyText, dnsRecords}'
curl -s $C $H -H 'Content-Type: application/merge-patch+json' -X PATCH $B/mta-sts-policies/example.org \
  -d '{"active":false}' | jq .data.policyId                                            # unchanged
curl -s $C $B/mta-sts-policies/example.com -o /dev/null -w '%{http_code}\n'             # -> 404 (alias)

# US3: diagnostics with rate-limit headers (the dev stack resolves through the app container's DNS,
# so the checks of example.org report the real zone, mostly `mismatch` and `missing` for localhost).
curl -s $C $B/dns-diagnostics/example.org -D headers.txt | jq '.data | {summary, checks: [.checks[] | {id, status}]}'
grep -i '^ratelimit' headers.txt
#   -> RateLimit-Limit: 10, RateLimit-Remaining: 9, RateLimit-Reset: 60

# US8: audit history of a name (target filters from feature 002).
curl -s $C "$B/audit-events?targetType=dkimKey&targetId=example.com" | jq '[.data[] | {eventType, outcome, details}]'
```

**Expected**: every status and value as commented; no response except a permitted export contains
`PRIVATE KEY`; the eleventh diagnostics run within a minute answers `429` with `Retry-After` and
`RateLimit-Remaining: 0`.

## Part C: Validation on a mailcow host

1. **Signing (SC-002)**: generate a key through the API for a test domain, publish the record from
   `data.key.record`, send a message from a mailbox of the domain, and check
   `Authentication-Results: dkim=pass` at the receiver. Delete the key and check that the next
   message carries no `DKIM-Signature`.
2. **Legacy coexistence (FR-058, SC-001)**: the legacy "System › ARC/DKIM keys" screen shows the same
   selector and record as `GET /dkim-keys/{name}`; a key generated in the legacy UI appears in the
   next API response with a new `etag`.
3. **MTA-STS publication (SC-008)**: with an active policy, `curl https://mta-sts.<domain>/.well-known/mta-sts.txt`
   (and for an alias domain) returns exactly `data.policyText`. After deactivation it returns `404`.
4. **Diagnostics (SC-006)**: run diagnostics for a correctly configured domain (all required checks
   `match`, under 5 s) and for a domain whose name servers do not answer (result within 30 s,
   affected checks `lookupFailed`).
5. **FeldHost ISPConfig publication (SC-009)**: set `RESTV2_DKIM_DNS_PUBLICATION=y`,
   `RESTV2_ISPCONFIG_URL` (the fork's `soap_location` with `index.php` replaced by `json.php`),
   `RESTV2_ISPCONFIG_USERNAME`, `RESTV2_ISPCONFIG_PASSWORD` and `RESTV2_ISPCONFIG_DNS_SERVER_ID`,
   generate a key, and
   check that the zone holds exactly one TXT record `<selector>._domainkey` with the key; rotate with
   a new selector and check that the old record is gone; stop ISPConfig, generate for another domain
   and check `dnsPublication.status: failed` with `reason.code: unreachable` within 15 s; restart it
   and `POST /dkim-keys/{name}/dns-publication` → `published`.
