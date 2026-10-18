# Quickstart and Validation Guide: Feature 008

**Feature**: `specs/008-sync-jobs` | **Plan**: [plan.md](./plan.md) |
**Contract**: [`api/modules/sync-jobs/`](../../api/modules/sync-jobs/)

This guide proves that the sync-job feature works:

- Part A covers the automated suite;
- Part B covers manual checks against the dev stack;
- Part C covers checks on a real mailcow host, where the unchanged imapsync runner executes the jobs.

It relies on feature 001's quickstart for the stack itself and does not repeat it.

## Prerequisites

- The dev stack of feature 001 (`docker compose up -d --build`, `composer install`, `.env`), with
  features 001–007 implemented.
- For Part C: a mailcow-dockerized installation on upstream `2026-09a` (or the FeldHost fork
  `feldhost-202609a`) with the API container installed per 001's quickstart Part C. You also need a
  reachable remote IMAP test account (any provider, or a second mailcow) whose password you may use
  for testing.
- `curl` and `jq` on the host.

## Part A: Automated suite

```bash
# Contract: the sync-jobs module must lint clean.
docker compose run --rm app composer contract:lint

# Regenerate the legacy oracles (only when the upstream tag changes). Expect no diff for 2026-09a.
scripts/legacy/extract-syncjob-validation.sh 2026-09a
git diff --stat tests/fixtures/legacy/syncjob_validation.php

# Feature, unit and contract tests of this feature, then the whole suite with the coverage check.
docker compose run --rm app vendor/bin/phpunit --filter 'SyncJob|ImapsyncColumns|MailcowConfigImapsync'
docker compose run --rm app composer test

# Concurrency and performance groups (also run in CI).
docker compose run --rm app vendor/bin/phpunit --group concurrency --filter SyncJob
docker compose run --rm app vendor/bin/phpunit --group performance --filter SyncJob

docker compose run --rm app vendor/bin/pint --test
docker compose run --rm app vendor/bin/phpstan analyse
```

**Expected**:

- Lint reports no error, and no warning in `api/modules/sync-jobs/`.
- `build/contract/coverage.json` lists all 9 sync-job operations as covered.
- `CustomParametersValidatorTest` agrees with the legacy oracle on every corpus entry except the
  `showpasswords` ones.
- `CreateSyncJobRowParityTest` finds the legacy row.
- `ConcurrentCreateSyncJobTest` sees exactly one `201` and one `409`.
- `SyncJobListLatencyTest` reports p95 < 1 s for 5,000 jobs across 100,000 mailboxes.
- `SyncJobBulkLatencyTest` finishes 100 items in under 10 s.

## Part B: Manual checks against the dev stack

Run `docker compose run --rm app scripts/dev/reset-dev-database.sh`. The development seeder (001,
extended by this feature) creates the accounts of 001's quickstart plus the following:

- `alice@example.org` with `user_acl.syncjobs = 1` and two sync jobs:
  - #1: never run, active;
  - #2: last run failed with `EXIT_AUTHENTICATION_FAILURE_USER1` and a stored log.
- `bob@example.org` with `user_acl.syncjobs = 0`.
- `carol@example.net` with one sync job (#3).
- `reseller`, a domain administrator of `example.org` only, with `da_acl.syncjobs = 1`.

Every password is `Passw0rd!dev`. Sign in as in 001's quickstart (CSRF token, then
`POST /session`).

```bash
B=http://localhost:8080/api/v2; J='-H Content-Type:application/json'; C='-c jar -b jar'
M='-H Content-Type:application/merge-patch+json'
T=$(curl -s $C $B/csrf-token | jq -r .data.token)
signin() { curl -s $C $J -H "X-CSRF-Token: $T" -X POST $B/session \
  -d "{\"username\":\"$1\",\"password\":\"Passw0rd!dev\"}" | jq -r .data.state; }

# US1: the mailbox user sees only their own jobs, with run status and no password.
signin alice@example.org                                     # active
curl -s $C "$B/sync-jobs" | jq '.meta, [.data[] | {id, mailbox, remotePasswordSet, runStatus}]'
curl -s $C "$B/sync-jobs" | grep -c password1                # 0
curl -s $C "$B/sync-jobs/2/log" | jq -r .data.log | head -5  # imapsync output, no password
curl -s $C -o /dev/null -w '%{http_code}\n' "$B/sync-jobs/3" # 404 (carol's job)

# US2: capabilities, then create with only the required members (defaults apply).
curl -s $C "$B/capabilities/sync-jobs" | jq '.data | {customParameters: .customParameters.allowed, bulkLimit, defaults}'
curl -s $C $J -H "X-CSRF-Token: $T" -X POST "$B/sync-jobs" -i \
  -d '{"remoteHost":"IMAP.Example.COM","remotePort":993,"remoteUser":"alice","remotePassword":"s3cret"}' \
  | grep -i -E '^(HTTP|location|etag)'                       # 201, Location /api/v2/sync-jobs/<id>
# The same again: 409 with type sync-job-exists and syncJobId.
curl -s $C $J -H "X-CSRF-Token: $T" -X POST "$B/sync-jobs" \
  -d '{"remoteHost":"imap.example.com","remotePort":993,"remoteUser":"alice","remotePassword":"x"}' | jq '{type, syncJobId}'
# Validation: every bad member named at once.
curl -s $C $J -H "X-CSRF-Token: $T" -X POST "$B/sync-jobs" \
  -d '{"remoteHost":"2001:db8::1","remotePort":0,"remoteUser":"a","remotePassword":"x","exclude":"(","customParameters":"--showpasswords --maxsize 10"}' \
  | jq '.errors | map_values(map(.code))'
#   remoteHost [invalidFormat], remotePort [outOfRange], exclude [invalidRegex],
#   customParameters [optionNotAllowed, optionContainsSpace]

# US3: edit with If-Match, a stale tag, the immutable target and a reschedule.
E=$(curl -sI $C "$B/sync-jobs/1" | awk -F': ' 'tolower($1)=="etag"{print $2}' | tr -d '\r')
curl -s $C $M -H "X-CSRF-Token: $T" -H "If-Match: $E" -X PATCH "$B/sync-jobs/1" \
  -d '{"intervalMinutes":30}' | jq '.data | {intervalMinutes, remotePasswordSet, etag}'
curl -s $C $M -H "X-CSRF-Token: $T" -H "If-Match: $E" -X PATCH "$B/sync-jobs/1" \
  -d '{"active":false}' -o /dev/null -w '%{http_code}\n'     # 412 (the tag changed)
curl -s $C $M -H "X-CSRF-Token: $T" -X PATCH "$B/sync-jobs/1" \
  -d '{"mailbox":"carol@example.net"}' | jq '.errors.mailbox[0].code'   # "immutable"
curl -s $C -H "X-CSRF-Token: $T" -X POST "$B/sync-jobs/2/schedule-reset" \
  | jq '.data.runStatus'                                     # lastRunAt null, lastResult "unknown"

# US5: bulk deactivate with one foreign and one duplicate item.
curl -s $C $J -H "X-CSRF-Token: $T" -X POST "$B/sync-jobs/bulk-actions" \
  -d '{"action":"deactivate","items":[{"id":"1"},{"id":"2"},{"id":"3"},{"id":"1"}]}' \
  | jq '.data | {summary, results: [.results[] | {id, outcome}]}'
#   1 done, 2 done, 3 notFound; total 3

# US1 AS-6/7: callers without the permission are forbidden.
signin bob@example.org; curl -s $C "$B/sync-jobs" | jq .type   # ".../forbidden"

# US6: an administrator lists the history of a job with the generic target filters.
signin admin
curl -s $C "$B/audit-events?targetType=syncJob&targetId=1" | jq '.data[] | {eventType, outcome, details}'
curl -s $C "$B/audit-events?actor=bob@example.org&outcome=failure" | jq '[.data[] | {eventType, failureReason, target}]'
curl -s $C "$B/audit-events?targetType=syncJob" | grep -c s3cret   # 0
```

**Expected**: each command prints the value in its comment. No response, audit record or service
log contains a remote password: `docker compose logs app | grep -c s3cret` prints `0`.

## Part C: Validation on a mailcow host (with the real runner)

1. **Parity of creation (SC-002)**:
   1. In the legacy UI, add a sync job for a test mailbox with the "Add sync job" modal, leaving the
      defaults.
   2. Create the same job through `POST /api/v2/sync-jobs` for another test mailbox.
   3. Compare both rows:
      `docker compose exec mysql-mailcow mariadb -e "SELECT * FROM imapsync ORDER BY id DESC LIMIT 2\G" $DBNAME`.

   Apart from `id`, `user2`, `created` and `modified`, every column is equal.
2. **Runner pick-up (SC-008)**: within one or two minutes of creating an active job with the real
   remote account, `GET /api/v2/sync-jobs/{id}` shows `runStatus.running: true`, then
   `lastResult: succeeded` and a `lastRunAt`. `GET …/log` shows the imapsync output, in which the
   password appears masked (or not at all).
3. **Failure status (US1 AS-3)**: change the job's password to a wrong one with `PATCH`. After the
   next run, `lastResult` is `failed`, `exitStatus` is `EXIT_AUTHENTICATION_FAILURE_USER1` and the
   legacy UI shows "Wrong username or password" for the same row.
4. **Reschedule and deactivate (US3 AS-4, AS-5)**:
   1. `POST …/schedule-reset` makes the runner run the job on its next cycle (watch
      `docker compose logs -f dovecot-mailcow`).
   2. `PATCH {"active": false}` stops further runs. A run in progress finishes.
5. **Coexistence (FR-028)**:
   - Edit the interval in the legacy edit page. The next `GET` shows it, with a new `etag`.
   - Change the password in the legacy edit page. The runner uses it, the API keeps reporting only
     `remotePasswordSet: true`, and the `etag` changes.
6. **Delete during a run (US4 AS-3)**: delete a job while `running` is true. The row is gone at
   once, the run finishes, and no row reappears.
7. **Hardening**: with `RESTV2_SYNC_JOBS_CUSTOM_PARAMS_ADMIN_ONLY=y` in `mailcow.conf` (followed by
   `docker compose up -d restv2-mailcow`):
   - a mailbox user's `GET /capabilities/sync-jobs` shows `customParameters.allowed: false`;
   - a mailbox user creating a job with `--maxsize=1000` gets `customParamsNotPermitted`;
   - an administrator creating the same job succeeds.
