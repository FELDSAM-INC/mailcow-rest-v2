# Quickstart and Validation Guide: Feature 002

**Feature**: `specs/002-domains` | **Plan**: [plan.md](./plan.md) |
**Contract**: [`api/modules/domains/`](../../api/modules/domains/),
[`api/modules/domain-templates/`](../../api/modules/domain-templates/)

This guide proves that the domain feature works. Part A runs the automated suite, Part B walks
through the main flows against the dev stack, and Part C checks parity on a real mailcow host. It
builds on [feature 001's quickstart](../001-auth-sessions-api-keys/quickstart.md) (dev stack,
sign-in, CSRF token, API keys) and does not repeat it.

## Prerequisites

- Feature 001 implemented and its quickstart Part A green.
- Docker with Compose v2; `curl` and `jq` on the host.
- For Part C: a mailcow-dockerized installation on upstream `2026-09a` (or the FeldHost fork
  `feldhost-202609a`) with the API container from 001's quickstart Part C.

## Part A: Automated suite

```bash
docker compose run --rm app composer contract:lint      # zero errors, 001's 4 accepted warnings
docker compose run --rm app composer test               # includes tests/Feature/Domains*, coverage check
docker compose run --rm app vendor/bin/phpunit --group performance --filter DomainListLatency
docker compose run --rm app vendor/bin/pint --test
docker compose run --rm app vendor/bin/phpstan analyse
```

**Expected**: all green; `build/contract/coverage.json` lists the 16 domain operations (and every
earlier one) as covered; the latency test reports a 95th percentile under 1 s for a page of 25
domains on 5,000 domains and 100,000 mailboxes.

## Part B: Manual flows against the dev stack

Reset the dev database as in 001's quickstart. `DevelopmentSeeder` additionally creates
`example.org` and `example.net` (managed by `reseller`), the Default domain template, and a
mailbox `alice@example.org`. Sign in as `admin` and keep the CSRF token in `$T` (001 Part B).
dockerapi, memcached and ISPConfig are absent in the dev stack: `.env.example` binds their fakes
(`RESTV2_FAKE_OUTBOUND=y`, development only), so restarts and maildir moves report `done`
without effect.

```bash
B=http://localhost:8080/api/v2; J='-H Content-Type:application/json'; C='-c jar -b jar'
M='-H Content-Type:application/merge-patch+json'

# US2: capabilities, then create a domain from the Default template.
curl -s $C $B/capabilities/domains | jq '.data | {groupwareEnabled, taggingLimit, dkim}'
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/domains -D - \
  -d '{"name":"München.de","tags":["customer-a"],"restartGroupware":true}' | tee created.txt
#   -> 201, Location: /api/v2/domains/xn--mnchen-3ya.de, creationReport.dkimKey "created"
docker compose exec redis redis-cli -a "$REDISPASS" HGET DOMAIN_MAP xn--mnchen-3ya.de   # "1"
docker compose exec redis redis-cli -a "$REDISPASS" HGET DKIM_SELECTORS xn--mnchen-3ya.de  # "dkim"

# Creating it again (any spelling) is a conflict.
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/domains -d '{"name":"MÜNCHEN.de"}' | jq .type
#   -> "/api/v2/problems/domain-exists"

# US1: list with search, filter and sort; inspect by Unicode name; tags in scope.
curl -s $C "$B/domains?q=customer&sort=name&limit=25" | jq '.meta, [.data[].name]'
curl -s $C "$B/domains?tag=CUSTOMER-A" | jq '[.data[].name]'
curl -s $C -D - "$B/domains/m%C3%BCnchen.de" | grep -i etag
curl -s $C "$B/domain-tags" | jq '.data'

# US3: edit with the version tag; a stale tag is refused.
ETAG=$(curl -sI $C "$B/domains/example.org" | awk -F': ' 'tolower($1)=="etag"{print $2}' | tr -d '\r')
curl -s $C $M -H "X-CSRF-Token: $T" -H "If-Match: $ETAG" -X PATCH $B/domains/example.org \
  -d '{"maxMailboxes":20,"tags":["b","c"]}' | jq '.data | {maxMailboxes, tags, etag}'
curl -s $C $M -H "X-CSRF-Token: $T" -H "If-Match: $ETAG" -X PATCH $B/domains/example.org \
  -d '{"maxMailboxes":21}' -o /dev/null -w '%{http_code}\n'                  # 412
curl -s $C $M -H "X-CSRF-Token: $T" -X PATCH $B/domains/example.org \
  -d '{"maxMailboxes":0}' | jq '.errors.maxMailboxes'                        # belowInUse, inUse 1

# US7: footer.
curl -s $C $J -H "X-CSRF-Token: $T" -X PUT $B/domains/example.org/footer \
  -d '{"html":"<p>{= from_name =}</p>","plain":"{= from_name =}","skipReplies":true,
       "excludedAddresses":["alice@example.org"]}' | jq '.data'

# US5: templates.
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/domain-templates -d '{"name":"Small","maxMailboxes":5}' | jq '.data'
DEF=$(curl -s $C "$B/domain-templates?q=default" | jq '.data[0].id')
curl -s $C -H "X-CSRF-Token: $T" -X DELETE $B/domain-templates/$DEF | jq .type   # domain-template-protected

# US4: preview, then delete; a domain with a mailbox is refused.
curl -s $C "$B/domains/example.org/deletion-preview" | jq '.data | {allowed, blockers}'
curl -s $C -H "X-CSRF-Token: $T" -X DELETE $B/domains/example.org | jq '{type, mailboxes}'  # domain-not-empty
curl -s $C -H "X-CSRF-Token: $T" -X DELETE -o /dev/null -w '%{http_code}\n' $B/domains/xn--mnchen-3ya.de  # 204

# US6: bulk deactivation with a version tag and an unknown name.
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/domains/bulk-actions \
  -d '{"action":"deactivate","targets":[{"id":"example.net"},{"id":"nope.example"}]}' | jq '.data.summary'
#   -> {"total":2,"done":1,"notFound":1,...}

# US8: audit filtered by target.
curl -s $C "$B/audit-events?targetType=domain&targetId=example.org" | jq '.data[] | {eventType, outcome, details}'

# Domain administrators: own domains only, forbidden attributes named.
#   (Sign in as reseller.)
curl -s $C "$B/domains" | jq '[.data[].name], (.data[0] | has("domainAdmins"))'   # false
curl -s $C $M -H "X-CSRF-Token: $T" -X PATCH $B/domains/example.org -d '{"active":false}' | jq '{type, attributes}'
```

**Expected**: each command prints the value in its comment. Responses never contain a DKIM
private key or the ISPConfig password.

## Part C: Parity on a mailcow host

1. **Configuration**: add `COMPOSE_PROJECT_NAME` and, for the FeldHost fork,
   `RESTV2_DEFAULT_DKIM_SELECTOR=feldhost`, `RESTV2_DKIM_DNS_PUBLICATION=y` and the
   `RESTV2_ISPCONFIG_*` settings (research D24) to the API service; copy the updated
   `deploy/nginx/site.restv2.custom` and restart `nginx-mailcow`.
2. **Figures (SC-001)**: for ten domains of different shapes, compare every figure of
   `GET /api/v2/domains/{domain}` with the legacy "Mail setup › Domains" row and "Edit domain" page.
3. **Creation (SC-002, SC-008)**: create `parity-a.example` through the legacy UI and
   `parity-b.example` through the API with the same input. Compare the `domain` and `tags_domain`
   rows, `DOMAIN_MAP`, `RL_VALUE` and the DKIM hashes (apart from key material), and confirm the
   `_sogo_static_view` rebuild and, with a restart, the `sogo-mailcow` restart in
   `docker compose logs dockerapi-mailcow`. Create a mailbox in `parity-b.example` in the legacy UI
   and send it a test message within a minute.
4. **Coexistence**: edit a domain in the legacy UI, then send a `PATCH` with the version read before
   (`412`). Edit a template and a footer through the API and open them in the legacy UI.
5. **Deletion (SC-003)**: delete a domain with an alias domain, aliases, a footer, BCC maps of both
   forms and a domain administrator who manages only it. Then search the database dump and Redis
   for both names: only the DKIM keys remain. `docker compose exec dovecot-mailcow ls
   /var/vmail/_garbage` shows the moved mail directory.
6. **FeldHost DNS (fork only)**: create a domain whose zone exists in ISPConfig and check the
   `<selector>._domainkey` TXT record; create one without a zone and see `noZone` in the report.
7. **Groupware disabled**: with `SKIP_SOGO=y`, `GET /capabilities/domains` reports
   `groupwareEnabled: false`, and a creation with `restartGroupware: true` reports
   `groupwareDisabled` for both groupware steps.
