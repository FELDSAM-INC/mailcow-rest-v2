# Quickstart and Validation Guide: Feature 009

**Feature**: `specs/009-routing-address-rewriting` | **Plan**: [plan.md](./plan.md) |
**Contract**: [`api/openapi.yaml`](../../api/openapi.yaml)

This guide proves that the feature works. Part A covers the automated suite. Part B covers manual
checks against the dev stack. Part C covers checks on a real mailcow host, where Postfix and Rspamd
read what the API wrote. It builds on feature 001's quickstart (dev stack, accounts, sign-in) and
does not repeat it.

## Prerequisites

- Feature 001's prerequisites, and features 001 to 004 implemented (they provide sign-in, domains,
  mailboxes, aliases and alias domains).
- For Part C: a mailcow-dockerized installation on upstream `2026-09a` (or the FeldHost fork
  `feldhost-202609a`) with the API deployed as in 001's quickstart Part C, including this feature's
  `dns` entry in the override and the 90 s `fastcgi_read_timeout` in `site.restv2.custom`.

## Part A: Automated suite

```bash
docker compose run --rm app composer contract:lint
docker compose run --rm app composer test                     # whole suite, contract-validated
docker compose run --rm app vendor/bin/phpunit --filter Routing   # only this feature
docker compose run --rm app composer test:performance     # timing tests, alone on an idle machine
docker compose run --rm app vendor/bin/pint --test
docker compose run --rm app vendor/bin/phpstan analyse
```

**Expected**:

- The lint reports no new problem.
- `composer test` is green (it excludes the `performance` group), and the contract coverage check
  at its end reports every operation of this feature covered (`listRelayhosts` …
  `getRoutingCapabilities`).
- `tests/Feature/Routing/Security/RoutingAuthorizationMatrixTest.php` passes for all 46 operations
  and every kind of caller.
- `tests/Unit/Clients/StreamSmtpProbeTest.php` passes against the scripted SMTP server, including
  the STARTTLS cases.
- `tests/Feature/Routing/Security/RoutingSecretLeakScanTest.php` finds none of the test passwords in
  any response, audit record or log line.
- The performance group reports p95 < 1 s for a page of 25 out of 10,000 entries per collection.

## Part B: Manual checks against the dev stack

Start the optional SMTP test target and reset the dev database (001's seeder creates `admin`,
`reseller` as domain administrator of `example.org` and `example.net`, and mailboxes in
`example.org`):

```bash
docker compose --profile routing up -d mailpit     # SMTP on mailpit:1025, UI on localhost:8025
docker compose run --rm app scripts/dev/reset-dev-database.sh
```

Sign in as `admin` as in 001's quickstart Part B, so that `$C`, `$J`, `$T` and `$B` are set. (Any
command also works with an API key instead of the session: replace `$C … -H "X-CSRF-Token: $T"` by
`-H "X-API-Key: <key>"`. In a parallel worktree without host ports, start mailpit with
`docker compose run -d --no-deps --name <project>-mailpit mailpit`, serve the API with
`php artisan serve` in another `run` container, and use `[<project>-mailpit]:1025` as the host:
containers started with `run` are reachable by their container name only.)

```bash
# US1: create a relayhost with credentials; the password is never returned.
RH=$(curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/relayhosts \
  -d '{"host":"[mailpit]:1025","username":"acct","password":"s3cret"}' | tee /dev/stderr | jq .data.id)
#   -> {"data":{"id":1,"host":"[mailpit]:1025","username":"acct","passwordSet":true,"active":true,...}}
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/relayhosts -d '{"host":"192.0.2.10:25"}' | jq .data.host
#   -> "[192.0.2.10]:25"
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/relayhosts -d '{"host":"smtp:relay.example.net"}' \
  | jq '.errors.host[0].code'                                       # "invalidFormat"
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/relayhosts -d '{"host":"relay.example.net","username":"u:n"}' \
  | jq '.errors.username[0].code'                                   # "invalidCharacter"

# US2: assign it to a domain and a mailbox, read the effective relayhost.
ETAG=$(curl -sI $C $B/domains/example.org/relayhost-assignment | awk -F': ' 'tolower($1)=="etag"{print $2}' | tr -d '\r')
curl -s $C $J -H "X-CSRF-Token: $T" -H "If-Match: $ETAG" -X PUT $B/domains/example.org/relayhost-assignment \
  -d "{\"relayhostId\":$RH}" | jq .data.relayhost                    # {"id":1,"host":"[mailpit]:1025","state":"active"}
curl -s $C $J -H "X-CSRF-Token: $T" -H "If-Match: $ETAG" -X PUT $B/domains/example.org/relayhost-assignment \
  -d '{"relayhostId":null}' -o /dev/null -w '%{http_code}\n'        # 412 (stale version)
curl -s $C $B/mailboxes/alice@example.org/relayhost-assignment | jq '.data | {effectiveRelayhost, credentialsRelayhost}'
curl -s $C "$B/relayhosts/$RH/usage" | jq '.data'                   # [{"kind":"domain","name":"example.org"}]

# US4: test the relayhost against mailpit. The transcript redacts the login.
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/relayhosts/$RH/connection-test \
  -d '{"recipient":"someone@example.com"}' | jq '.data | {accepted, step, login, tls, transcript}'
#   -> accepted true, step "message", login "succeeded", tls false (mailpit offers no STARTTLS);
#      "AUTH PLAIN [redacted]" appears in the transcript, the password nowhere
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/relayhosts/$RH/connection-test \
  -d '{"sender":"not-an-address"}' | jq '.errors.sender[0].code'    # "invalidFormat"
curl -s $C $B/capabilities/routing | jq '.data.administration.connectionTest'
#   -> {"defaultSender":"relay@example.org","defaultRecipient":"null@hosted.mailcow.de","stepTimeoutSeconds":15,"totalTimeoutSeconds":60}
curl -s $C $B/relayhost-choices | jq '.data[0]'                     # {"id":1,"host":"[mailpit]:1025","username":"acct","active":true}

# US3: transports and shared next-hop credentials.
A=$(curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/transports \
  -d '{"destination":"example.com","nextHop":"[mx.partner.net]:25","username":"u1","password":"p1"}' | jq .data.id)
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/transports \
  -d '{"destination":"example.net","nextHop":"[mx.partner.net]:25","username":"u2"}' | jq .type
#   -> "/api/v2/problems/next-hop-credentials-differ"
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/transports \
  -d '{"destination":"example.net","nextHop":"[mx.partner.net]:25","password":"p2"}' \
  | jq '{active: .data.active, affected: .meta.affectedTransports}'  # active false, affected [A]
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/transports \
  -d '{"destination":"Example.com","nextHop":"other.net"}' | jq .type   # ".../routing-entry-exists"
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/transports \
  -d '{"destination":"x.example","nextHop":"mx.partner.net"}' | jq .type # ".../next-hop-interferes"

# US5: BCC maps as the domain administrator (sign in as reseller first).
curl -s $C "$B/bcc-destination-choices?q=example" | jq '.data[] | {kind, value}'
#   -> domains @example.net and @example.org, mailboxes alice@ and bob@example.org
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/bcc-maps \
  -d '{"localDestination":"example.org","type":"recipient","bccDestination":"archive@backup.net"}' \
  | jq '.data | {localDestination, domain, active}'                 # "@example.org", "example.org", true
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/bcc-maps \
  -d '{"localDestination":"user@not-mine.example","type":"sender","bccDestination":"a@b.net"}' \
  | jq '.errors.localDestination[0].code'                           # "notFound"

# US6–US8 (as admin): TLS policy map, recipient map, forwarding host.
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/tls-policy-maps \
  -d '{"destination":"bücher.example","policy":"Encrypt"}' | jq '.data | {destination, policy}'
#   -> "xn--bcher-kva.example", "encrypt"
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/recipient-maps \
  -d '{"originalDestination":"old.example","newDestination":"new.example"}' | jq '.data | {originalDestination, newDestination}'
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/forwarding-hosts -d '{"host":"192.0.2.5/24"}' \
  -D - | grep -i '^location'                                         # /api/v2/forwarding-hosts?source=192.0.2.5%2F24
curl -s $C "$B/forwarding-hosts/192.0.2.0%2F24" | jq .data           # spamFilter true
docker compose exec redis redis-cli -a "$REDISPASS" HGETALL WHITELISTED_FWD_HOST   # 192.0.2.0/24 -> 192.0.2.5/24

# Bulk: deactivate two relayhosts and an unknown one.
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/relayhosts/bulk-actions \
  -d "{\"action\":\"deactivate\",\"targets\":[{\"id\":\"$RH\"},{\"id\":\"999\"}]}" | jq '.data.summary'
#   -> {"total":2,"done":1,"notFound":1,...}

# US9: trace the changes.
curl -s $C "$B/audit-events?targetType=relayhost&targetId=$RH" | jq '.data[] | {eventType, outcome, details}'
```

**Expected**: Each command prints the value in its comment. No response or audit record contains
`s3cret`, `p1` or `p2` (check with `docker compose logs app | grep -c s3cret`, which must print `0`).

## Part C: Validation on a mailcow host

Run the checks on the mailcow host after creating the same entries through `/api/v2` (Part B with
real host names). They show that Postfix and Rspamd read what the API wrote (SC-002, SC-005), with
no service restarted.

1. **Relayhost assignment**: `docker compose exec postfix-mailcow postmap -q "alice@example.org"
   mysql:/opt/postfix/conf/sql/mysql_sender_dependent_default_transport_maps.cf` prints
   `smtp:[relay.host]:587`. `…mysql_sasl_passwd_maps_sender_dependent.cf` prints `acct:s3cret`.
2. **Transports**: `postmap -q "example.com" mysql:/opt/postfix/conf/sql/mysql_transport_maps.cf`
   prints `smtp_via_transport_maps:[mx.partner.net]:25` once the transport is active, and
   `…mysql_sasl_passwd_maps_transport_maps.cf` with key `[mx.partner.net]:25` prints `u1:p2`.
3. **Recipient and TLS policy maps**: `postmap -q "user@old.example"
   mysql:/opt/postfix/conf/sql/mysql_recipient_canonical_maps.cf` is empty and `postmap -q
   "@old.example" …` prints `@new.example`. `postmap -q "xn--bcher-kva.example"
   mysql:/opt/postfix/conf/sql/mysql_tls_policy_override_maps.cf` prints `encrypt `.
4. **BCC maps**: send a message to an address in `example.org`. A copy arrives at
   `archive@backup.net`, and the Rspamd log shows `BCC MAP: returning archive@backup.net`.
5. **Forwarding hosts**: `docker compose exec redis-mailcow redis-cli HGETALL WHITELISTED_FWD_HOST`
   lists the address. `curl -s http://nginx:8081/forwardinghosts.php?host=192.0.2.7` (from a
   container on the mailcow network) prints `200 PERMIT`.
6. **Legacy UI**: the "System › Routing", "Forwarding hosts", "BCC", "Recipient maps" and "TLS policy
   maps" screens show the same entries. The relayhost edit page shows no password. A change made in
   the legacy UI is returned by the next API read with a new version tag.
7. **Connection test from the API container**: test a relayhost of a real provider. The result
   arrives within 60 s, the provider's MX is used when the host has no brackets, and the audit record
   holds the sender, recipient, outcome and step, without the transcript.

**Run on 2026-10-18** (Part B, worktree `wt/009`, compose project `restv2-009` without host ports,
API keys of `admin` and `reseller`, `php artisan serve`, mailpit `v1.27.10` addressed by its
container name): every command printed the value in its comment; the connection test was accepted
by mailpit with `AUTH PLAIN [redacted]`, `s3cret` appeared in no response, audit record or log line,
and `HGETALL WHITELISTED_FWD_HOST` listed `192.0.2.0/24 -> 192.0.2.5/24`. Part C is open.

