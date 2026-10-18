# Quickstart and Validation Guide: Feature 003

**Feature**: `specs/003-mailboxes` | **Plan**: [plan.md](./plan.md) |
**Contract**: [`api/openapi.yaml`](../../api/openapi.yaml) (modules `mailboxes`,
`mailbox-templates`, `password-policy`)

This guide proves that the feature works. Part A runs the automated suite, Part B exercises the
dev stack by hand, and Part C checks parity on a real mailcow host. The dev stack, the schema
fixture and the commands are feature 001's ([001 quickstart](../001-auth-sessions-api-keys/quickstart.md)).

## Prerequisites

- Features 001 and 002 are implemented and their suites pass.
- Docker with Compose v2; `curl` and `jq` on the host.
- For Part C: a mailcow-dockerized installation on `2026-09a` (or the FeldHost fork) with this
  API deployed as in 001's Part C.

## Part A: Automated suite

```bash
docker compose run --rm app composer contract:lint          # 0 errors (001's 4 known warnings)
docker compose run --rm app composer test                   # whole suite, contract-validated
docker compose run --rm app vendor/bin/phpunit --group concurrency   # SC-006
docker compose run --rm app vendor/bin/phpunit --group performance   # SC-002 (seeds 10,000 mailboxes)
docker compose run --rm app vendor/bin/pint --test
docker compose run --rm app vendor/bin/phpstan analyse
```

**Expected**: everything green. `build/contract/coverage.json` lists the 16 operations of this
feature as covered. The concurrency group makes 1,000 creation attempts from 20 parallel processes
against a domain with room for 10: exactly 10 mailboxes are created, the others are refused
(`mailbox-limit-reached`, or `422` for the domain quota left, which is checked first), and the
domain quota is never exceeded. The performance group (10,000 mailboxes in 500 domains) reports
p95 under 1 s for pages of 100 and under 5 s for pages of 1,000.

## Part B: Manual checks against the dev stack

Run `docker compose run --rm app scripts/dev/reset-dev-database.sh`. In addition to 001's and 002's
seed, the development seeder creates the Default mailbox template exactly as `init_db.inc.php`
seeds it, a template "Small" (1 GiB, POP3 off, sync jobs on), and the domain `example.org` with a
limit of 10 mailboxes and 5 GiB of domain quota; `reseller` lacks the `protocol_access` flag.
`bob@example.org` already exists (001's seed). dockerapi and memcached are absent in the dev
stack: `.env.example` binds their stand-ins (002's `RESTV2_FAKE_OUTBOUND=y`, development only), so
maildir moves, ACL commands and the SOGo rename report success without effect, and each command is
written to the application log (`docker compose logs app | grep dockerapi`). The session cookie
needs the dev stack's HTTPS listener (self-signed, `curl -k`).

```bash
B=https://127.0.0.1:18443/api/v2; J='-H Content-Type:application/json'; C='-k -c jar -b jar'
T=$(curl -s $C $B/csrf-token | jq -r .data.token)
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/session -d '{"username":"admin","password":"Passw0rd!dev"}' >/dev/null
T=$(curl -s $C $B/csrf-token | jq -r .data.token)                    # the sign-in renews the token

# Capabilities and password policy (US2 AS-11, AS-12)
curl -s $C $B/capabilities/mailboxes | jq '.data | {identityProviderType, authSources, bulkLimit, taggingLimit}'
curl -s $C -D - $B/password-policy | grep -i etag; curl -s $C $B/password-policy | jq .data

# Create with normalisation, from a template, and refused cases (US2)
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/mailboxes \
  -d '{"localPart":" Anna.Smith ","domain":"Example.org","password":"Passw0rd!dev"}' \
  | jq '.data | {address, quota, displayName, creationReport}'        # anna.smith@example.org, 3 GiB (domain default)
SMALL=$(curl -s $C "$B/mailbox-templates?q=Small" | jq '.data[0].id')
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/mailboxes \
  -d "{\"localPart\":\"carol\",\"domain\":\"example.org\",\"password\":\"Passw0rd!dev\",\"templateId\":$SMALL}" \
  | jq '.data | {quota, pop3: .protocolAccess.pop3, syncjobs: .userPermissions.syncjobs}'   # 1 GiB, false, true
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/mailboxes \
  -d '{"localPart":"carl","domain":"example.org","password":"short"}' | jq '.errors.password'   # tooShort, min 6
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/mailboxes \
  -d '{"localPart":"anna.smith","domain":"example.org","password":"Passw0rd!dev"}' | jq '{type, holder}'   # address-in-use, mailbox

# List, search, sort, filter (US1)
curl -s $C "$B/mailboxes?q=ANNA&sort=percentUsed&order=desc" | jq '{total: .meta.total, first: .data[0].address}'   # 1, anna.smith@example.org
curl -s $C "$B/mailboxes?tag=Sales&status=active&domain=example.org" | jq '.meta'

# Change with a version tag (US3); a stale tag gives 412
ETAG=$(curl -s $C -D - -o /dev/null $B/mailboxes/anna.smith@example.org | awk -F': ' 'tolower($1)=="etag"{print $2}' | tr -d '\r')
curl -s $C "$B/mailbox-tags?q=sal" | jq '.data'                       # [] until the change below; then Sales, 1
curl -s $C -H 'Content-Type: application/merge-patch+json' -H "X-CSRF-Token: $T" -H "If-Match: $ETAG" \
  -X PATCH $B/mailboxes/anna.smith@example.org \
  -d '{"status":"loginDisabled","tags":["Sales"],"customAttributes":{"department":"Sales"}}' | jq '.data | {status, tags, customAttributes}'
curl -s $C -H 'Content-Type: application/merge-patch+json' -H "X-CSRF-Token: $T" -H "If-Match: $ETAG" \
  -X PATCH $B/mailboxes/anna.smith@example.org -d '{"displayName":"Anna"}' -o /dev/null -w '%{http_code}\n'   # 412

# Preview, rename, delete (US4, US8)
curl -s $C $B/mailboxes/carol@example.org/deletion-preview | jq '.data | {canProceed, counts, garbageRetentionMinutes}'   # true, zeros, 7200
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/mailboxes/anna.smith@example.org/rename \
  -d '{"newLocalPart":"anna","keepOldAddressAsAlias":true}' | jq '.data | {address, previousAddress, oldAddressKeptAsAlias}'
curl -s $C -H "X-CSRF-Token: $T" -X DELETE -o /dev/null -w '%{http_code}\n' $B/mailboxes/carol@example.org   # 204
docker compose logs app | grep dockerapi # maildir cleanup, get_acl, maildir move, sogo rename_user

# Bulk action (US7)
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/mailboxes/bulk-actions \
  -d '{"action":"disallowProtocol","protocol":"pop3","targets":[{"id":"anna@example.org"},{"id":"nobody@example.org"}]}' \
  | jq '.data.summary'                   # done 1, notFound 1

# Templates (US6)
curl -s $C $B/mailbox-templates | jq '.data[] | {id, name, isDefault}'
DEF=$(curl -s $C "$B/mailbox-templates?q=Default" | jq '.data[0].id')
curl -s $C -H 'Content-Type: application/merge-patch+json' -H "X-CSRF-Token: $T" -X PATCH \
  $B/mailbox-templates/$DEF -d '{"name":"Other"}' | jq .type        # mailbox-template-protected

# Domain administrator: scope and flags
#   Sign in as "reseller" (no protocol_access flag), as above, and take a new token.
curl -s $C "$B/mailboxes/someone@other.example" -o /dev/null -w '%{http_code}\n'   # 404
curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/mailboxes \
  -d '{"localPart":"dave","domain":"example.org","password":"Passw0rd!dev","protocolAccess":{"pop3":false}}' \
  | jq '{type, requiredFlags}'           # attribute-not-permitted, protocolAccess
```

**Expected**: each command prints the value in its comment. Check the audit log:
`curl -s $C "$B/audit-events?targetType=mailbox&targetId=anna.smith@example.org" | jq '.data[].eventType'`
lists the rename, the change and the creation (records carry the address the mailbox had at the
time; the refused second creation is listed too), and `targetId=anna@example.org` the bulk change
after the rename. `docker compose logs app | grep -c Passw0rd` prints `0`.

## Part C: Parity on a mailcow host

1. **Creation parity (SC-001, SC-003)**: create `api1@<domain>` through `/api/v2` and `ui1@<domain>`
   in the legacy UI with the same values. Compare the rows:

   ```bash
   docker compose exec mysql-mailcow mariadb $DBNAME -e "
     SELECT username, name, quota, active, authsource, attributes FROM mailbox WHERE username IN ('api1@d','ui1@d');
     SELECT * FROM user_acl WHERE username IN ('api1@d','ui1@d');
     SELECT * FROM alias WHERE address IN ('api1@d','ui1@d');
     SELECT * FROM quota2 WHERE username IN ('api1@d','ui1@d');
     SELECT c_uid, aliases FROM _sogo_static_view WHERE c_uid IN ('api1@d','ui1@d');"
   ```

   Rows differ only in the address, the hash and `passwd_update`. Sign in to IMAP, SMTP and
   webmail with `api1@<domain>` within a minute, and send it a mail.
2. **Hash schemes**: repeat with `MAILCOW_PASS_SCHEME=SSHA512` and with a pre-hashed `{SSHA512}`
   value created by `docker compose exec dovecot-mailcow doveadm pw -s SSHA512`. IMAP sign-in works.
3. **Change parity**: change status, quota, protocol access and the password through the API, then
   edit the same mailbox in the legacy UI between a read and a write: the API answers `412`.
   `SELECT attributes FROM mailbox` shows string values and an empty `attribute_hash`.
4. **Deletion parity (SC-007)**: give a mailbox aliases, sender permissions, a sync job, a
   temporary alias, an app password, a sieve filter, TOTP, a passkey, quarantine items and SOGo
   data in the legacy UI. Compare `deletion-preview` with the rows, delete it through the API, and
   check that `/var/vmail/_garbage/` holds the maildir, that no row names the address (except
   other aliases that kept their other recipients), and that `HGET RL_VALUE <address>` and the
   `RCPT_WANTS_*` fields are gone. Re-create the address: it shows no mail, logins or factors.
   Stop `dockerapi-mailcow` and delete another mailbox: `503`, nothing removed.
5. **Rename (SC-008)**: rename a mailbox with shared folders in both directions and a TOTP factor,
   keeping the old address. Sign in with the new address and the same password and TOTP, see every
   folder and the shared folders, and send to the old address. Stop `sogo-mailcow` and rename
   again: `503`, and the mailbox keeps its address and data.
6. **Cluster mode** (if available): with `CLUSTERMODE=replication`, watch
   `redis-cli SUBSCRIBE MC_CHANNEL` during a deletion: one `maildir cleanup` broadcast.
