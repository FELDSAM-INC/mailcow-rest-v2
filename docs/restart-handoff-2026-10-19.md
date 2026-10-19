# Restart handoff — 2026-10-19

The owner asked to continue Claude's work in **both** `mailcow-rest-v2` and the related
`../mailcowui-ng`, then requested preparation for a system restart. Work is paused for that
restart. No reboot was initiated. Resume from these checkpoints when the owner asks.

## Saved state

| Repository/worktree | Branch | Checkpoint | State |
|---|---|---|---|
| `mailcow-rest-v2` | `main` | `f1e1df4` plus this handoff commit | API 013 integrated; validation recorded |
| `../mailcow-rest-v2-wt/013` | `wt/013` | `67f2762` | Clean; implementation already on API main |
| `../mailcowui-ng` | `main` | `990830e7` | Clean; unchanged this session |
| `../mailcowui-ng-wt/005` | `wt/005` | `e71cbef8` | Account self-service WIP saved, 33 files; not validated |
| `../mailcowui-ng-wt/006` | `wt/006` | `49b41c6a` | Quarantine/spam/rate-limit WIP saved, 99 files; not validated |
| `../mailcowui-ng-wt/011` | `wt/011` | `9fdd8d49` | Monitoring implementation, clean; rebase aborted for restart |

All UI WIP commits are **local only**. Do not push temporary worktree branches or use git stash.
No subagents were started in this session. A question about using parallel agents was pending
when restart was requested; no answer or approval was received.

## API 013 results

Claude's full suite completed successfully after its usage limit interrupted the conversation.
Recovered `full-test-3.log`: **9,785 tests, 78,684 assertions, 379/379 registered operations
covered, none pending**, elapsed 31m 10s, exit 0, at implementation commit `67f2762`.
The resumed session reran contract lint, Pint (1,841 files) and PHPStan successfully on that
same clean commit, then fast-forwarded API main and committed the validation notes in `f1e1df4`.
Do not rerun the full suite solely because the session restarted.

T095 is complete; T097 (live mailcow Part C) remains open. No staging deployment occurred.
The timing suite still needs an idle machine. The existing maildev deployment remains API
`4be8653` and UI `990830e7`. API 013 changes deployment mounts, environment variables, ofelia
labels and nginx limits; its deploy script alone does not install those changes. Prepare the
host configuration changes before asking for deployment approval. Read the existing staging
notes; never print credentials.

## Resume monitoring first

UI 011 was nearly complete when Claude stopped. Its last full browser run finished with
**3,662 passed, 40 skipped, 2 failed**:

- Mobile Chromium: `tests/e2e/audit-log/audit-log.spec.ts:91`, empty-state filter clearing.
- Desktop Chromium: `tests/e2e/api-keys/create.spec.ts:49`, preselected creation defaults.

Those failures have **not** yet been investigated or established as flaky. The prior unit run
passed 3,121 tests with 89.69% line coverage. T146 (Lighthouse on an idle machine) and T149
(real phones/screen readers) remain open; do not claim these passed.

A rebase of `wt/011` onto UI main was started. Two conflicts were resolved, preserving both
features' changes:

- `lighthouserc.cjs` and `scripts/run-lhci.mjs`: retain routing and monitoring collections.
- `scripts/smoke/stack-down.sh`: retain routing-profile cleanup and outbound-fake cleanup.

It then stopped at commit `3a9ef68f`, step 7/64, with conflicts in
`tests/mocks/handlers/index.ts`, `tests/mocks/scenarios/index.ts` and
`tests/mocks/scenarios/state.ts`. Both routing and operations registrations/state are needed.
For restart, the partial result was saved on local branch
`wt/011-rebase-checkpoint-20261019` (`5dcb66f0`), then `git rebase --abort` restored `wt/011`.
There is **no rebase in progress**. Restart the rebase on `wt/011` and use the saved patches or
checkpoint branch as references; do not merge the incomplete checkpoint branch into main.

After integration, run affected UI checks, investigate the two browser failures, then continue
the partially implemented UI 005 and 006 worktrees. Read their existing `tasks.md` and source;
the WIP checkpoints save Claude's code without asserting it is complete or tested.

## Durable local artifacts and runtime

Logs and helper scripts were copied from Claude's `/tmp` scratchpad into the ignored directory:

`/home/feldsam/Projects/mailcow-rest-v2/storage/app/restart-2026-10-19/`

It contains `a013/`, `u005/`, `u006/`, `u011/` and the two
`mailcow-ui011-*-rebase.patch` files. Notable logs: `a013/full-test-3.log`,
`u011/e2e-full4.log`, `u011/unit5.log`. Helpers retain their original absolute `/tmp` paths;
adapt them to the durable location before using them. Preserve existing volumes and fixtures.

The `restv2-013` MariaDB and Redis containers were stopped; volumes remain intact. No test
process started by this session is still running. Unrelated `crew-*` services were left alone.

Project rules: read each repo's `CLAUDE.md`, `.specify/memory/constitution.md` and
`docs/conventions.md`; contract-first and test-first; never modify `source_code`; never migrate
upstream tables; no git stash; explicit-path commits; local `wt/NNN` branches only. API PHP
commands run in Docker. UI browser checks used `mcr.microsoft.com/playwright:v1.64.1-noble`
with a dedicated Docker network and two workers. Avoid repeating completed expensive checks.

Claude's persistent handoff and decisions remain under
`/home/feldsam/.claude/projects/-home-feldsam-Projects-mailcow-rest-v2/memory/`.
The latest session is `18f49fa3-6188-4840-81f3-5a7408777062`; its older resume note describes
earlier checkpoints, so prefer the current commits above. A follow-up recorded there: the API
development seeder lacks mailbox self-alias rows, making send-as candidates empty in the dev
stack; record and fix this separately after resuming.
