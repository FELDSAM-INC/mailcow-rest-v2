# Quickstart and Validation Guide: Feature 004

**Feature**: `specs/004-aliases-alias-domains-resources` | **Plan**: [plan.md](./plan.md) |
**Contract**: [`api/openapi.yaml`](../../api/openapi.yaml)

This guide proves that the feature works. Part A runs the automated suite in the dev stack of
feature 001. Part B makes manual calls against the dev stack. Part C checks the mail flow, the
sender check and the groupware on a real mailcow host. It refers to the contract and the
[data model](./data-model.md) instead of repeating them.

## Prerequisites

- Features 001, 002 and 003 are implemented (this feature builds on their code; see
  [plan.md](./plan.md#dependencies-on-earlier-features)).
- Docker with Compose v2; `curl` and `jq` on the host.
- For Part C: a mailcow-dockerized installation on upstream `2026-09a` (or the FeldHost fork
  `feldhost-202609a`) with the API container deployed as in 001's quickstart, `swaks` on a client
  machine, and a test domain whose MX points to the host.

## Part A: Automated suite

```bash
docker compose up -d --build
docker compose run --rm app composer contract:lint
docker compose run --rm app composer test                       # whole suite, coverage check included
docker compose run --rm app vendor/bin/phpunit --testsuite Feature --filter 'Aliases|AliasDomains|Resources|SenderSettings|Groupware|AliasFeatureAudit'
docker compose run --rm app vendor/bin/phpunit --group performance --filter AliasListPerformance
docker compose run --rm app vendor/bin/pint --test
docker compose run --rm app vendor/bin/phpstan analyse
```

**Expected**:

- The lint reports no error and no warning in `api/modules/{aliases,alias-domains,resources,sender-settings}`.
- `composer test` is green and `build/contract/coverage.json` lists all 23 operations of this
  feature as covered.
- The performance test reports a p95 below 1 s for a page of 25 aliases with 200,000 aliases,
  100,000 mailboxes and 5,000 domains (SC-005), and a bulk deactivation of 100 aliases below
  10 s (SC-010).
- `AliasGroupwareRefreshTest` passes: after alias, resource and external sender changes (including
  aliases with external destinations), `_sogo_static_view` equals the result of 002's
  `SogoDirectory::rebuild()` apart from `c_password`.

## Part B: Manual checks against the dev stack

Seed the development database (001's `DevelopmentSeeder` plus two domains, `example.org` with the
alias domain candidate `example.net`, mailboxes `anna@example.org` and `bob@example.org`, and a
domain administrator `da1` for `example.org`), then sign in as an administrator:

```bash
B=http://localhost:8080/api/v2
J=/tmp/restv2.jar
T=$(curl -s -c $J -b $J $B/csrf-token | jq -r .data.token)
curl -s -c $J -b $J -H "X-CSRF-Token: $T" -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"moohoo"}' $B/session | jq .data.state      # "active"
H=(-c $J -b $J -H "X-CSRF-Token: $T" -H 'Content-Type: application/json')
```

1. **Capabilities**: `curl -s "${H[@]}" $B/capabilities/aliases | jq .data` shows
   `groupwareEnabled`, `externalDestinationsDisabled: false`, `bulkLimit: 100`,
   `commentMaxBytes: 160` and `dkimDefaults.selector` (`dkim` upstream).
2. **Create an alias** (US2):
   `curl -si "${H[@]}" -d '{"address":"sales@example.org","destinations":["anna@example.org","bob@example.org"]}' $B/aliases`
   answers `201` with `Location: /api/v2/aliases/<id>` and an `ETag`; the body shows
   `active`, `senderAllowed`, `groupwareVisible` all `true` and `internal: false`.
   `SELECT aliases FROM _sogo_static_view WHERE c_uid='anna@example.org'` contains
   `sales@example.org`.
3. **Conflicts and limits**: creating `sales@example.org` again gives `409` with type
   `/api/v2/problems/address-in-use` and `holder: alias`; setting `domain.aliases` to the
   current count and creating another alias gives `409 domain-alias-limit-reached` with `count`
   and `limit`.
4. **Validation**: `{"address":"x@example.org","destinations":["anna@example.org","not an address"]}`
   gives `422` with `errors["destinations.1"][0].code = invalidFormat`; a 161-byte
   `privateComment` gives `tooLong` with `max: 160, unit: bytes`.
5. **List, filter, search** (US1): `GET $B/aliases` returns the newest first with `meta.total`;
   `GET "$B/aliases?destination=anna@example.org"` returns exactly the aliases delivering to Anna;
   `GET "$B/aliases?q=SALES"` finds `sales@example.org`.
6. **Change with a version** (US3): read the `ETag`, change the alias in the legacy UI, then
   `curl -si "${H[@]}" -X PATCH -H 'Content-Type: application/merge-patch+json' -H "If-Match: <old etag>" -d '{"active":false}' $B/aliases/<id>`
   answers `412` and nothing changes; with the new tag it answers `200`.
7. **Alias domain** (US4):
   `curl -si "${H[@]}" -d '{"name":"example.net","targetDomain":"example.org","rateLimit":{"value":500,"frame":"hour"}}' $B/alias-domains`
   answers `201`; `data.creationReport` is `{rateLimit: applied, dkimKey: created,
   dkimDnsPublication: notConfigured, groupwareDirectoryUpdate: done}`. In Redis:
   `HGET DOMAIN_MAP example.net` = `1`, `HGET RL_VALUE example.net` = `500 / 1h`,
   `HGET DKIM_SELECTORS example.net` = `dkim`. Stop Redis and create another alias domain: `503`
   with type `/api/v2/problems/temporary-failure` and no `alias_domain` row.
8. **Copy over alias domains** (US6): `POST $B/aliases/<sales id>/alias-domain-copies` answers
   `200` with one entry `{aliasDomain: example.net, outcome: created}`.
9. **Delete the alias domain**: `DELETE $B/alias-domains/example.net` answers `204`; the copied
   alias, `DOMAIN_MAP` and `RL_VALUE` entries are gone, `DKIM_SELECTORS` still has `example.net`.
10. **Sender settings** (US5): `GET $B/mailboxes/anna@example.org/sender-settings` lists her own
    address and `sales@example.org` (`source: alias`, `senderAllowed: true`);
    `PATCH … -d '{"explicitPermissions":["@example.org","*"]}'` stores only `*`
    (`senderCheckDisabled: true`). `SELECT send_as FROM sender_acl WHERE logged_in_as='anna@example.org'`
    shows one row `*`.
11. **Domain administrator** (US1, US4, US5): sign in as `da1`; listing aliases shows only
    `example.org` and its alias domains; `GET $B/aliases/<id of another domain's alias>` answers
    `404`; creating an alias domain without `alias_domains` gives `403 permission-flag-required`
    with `requiredFlags: [aliasDomains]`; an external destination without
    `alias_external_goto` gives `403 attribute-not-permitted` with `attributes: [destinations.0]`;
    `GET $B/sender-permission-candidates?mailbox=anna@example.org` lists only entries of
    `example.org` and no `*`.
12. **Resources** (US7): `POST $B/resources -d '{"description":"Meeting Room 1","domain":"example.org","kind":"location"}'`
    answers `201` with `address: MeetingRoom1@example.org`, `bookingRule: unlimited`; the same with
    `"Meeting-Room 1"` gives `409 address-in-use` with `holder: resource`.
13. **Bulk** (US6): `POST $B/aliases/bulk-actions -d '{"action":"deactivate","targets":[{"id":"<id1>"},{"id":"<id2>","version":"stale"}]}'`
    answers `200` with `done` and `outdated`; 101 targets give `422` (`tooMany`, `max: 100`);
    `{"action":"copyOverAliasDomains","targets":[{"id":"<sales id>"}]}` answers `done` with the
    per-alias-domain outcomes in `meta.copies`.
14. **Audit** (US8): `GET "$B/audit-events?targetType=alias&targetId=<id>"` lists the creation,
    the change with `details.changes`, the refused `412` attempt (`failureReason: versionOutdated`)
    and the bulk deactivation with `details.bulkRequestId`.

## Part C: Validation on a mailcow host

Run after deploying the API container (001 quickstart Part C) and repeating the calls of Part B
against `https://<MAILCOW_HOSTNAME>/api/v2` with an administrator API key (`-H "X-API-Key: …"`,
no CSRF token).

1. **Delivery** (SC-003): within a minute of creating `sales@<domain>`, send mail to it with
   `swaks --to sales@<domain> --server <host>`; both destination mailboxes receive it. Mail to
   `noreply@<domain>` with `destinationType: discard` is accepted and not delivered.
2. **Alias domain**: after creating `<alias domain>` for `<domain>`, mail to
   `anna@<alias domain>` reaches `anna@<domain>`; deactivating the alias domain makes Postfix
   reject `anna@<alias domain>` with "Recipient address rejected".
3. **Sender check**: with the alias allowing sending, `swaks --auth-user anna@<domain> --from sales@<domain> --to x@<external>`
   is accepted; after `PATCH {"senderAllowed": false}` it is rejected ("Sender address rejected:
   not owned by user"); after granting Anna `sales@<domain>` explicitly it is accepted again.
   An external sender address `news@mailer.example` is accepted as sender after it is set.
4. **Groupware**: in SOGo, Anna's identity list offers `sales@<domain>` while the alias is active,
   visible and not a catch-all, and `news@mailer.example` after it is set as an external sender
   address; the resource `MeetingRoom1@<domain>` can be booked and disappears after it is
   deactivated.
5. **Parity**: create the same alias, alias domain and resource through the legacy UI on a second
   domain and compare the rows (`alias`, `alias_domain`, `mailbox`, `sender_acl`,
   `_sogo_static_view`) and Redis hashes; they are identical apart from timestamps, key material,
   the placeholder password and the stated deviations (SC-002).
