# Resumed work handoff — 2026-10-19

The owner asked to continue work in both `mailcow-rest-v2` and `../mailcowui-ng` after the
system-restart checkpoint. The first continuation was local. The owner subsequently authorized
committing, pushing and deploying completed work; the API deployment below is complete. No
subagents were used.

## Current checkpoints

| Repository/worktree | Branch | Checkpoint | State |
|---|---|---|---|
| `mailcow-rest-v2` | `main` | `b3dc2ab` plus this handoff update | API 013 integrated; seeder and quarantine performance fixed; pushed and deployed |
| `../mailcow-rest-v2-wt/013` | `wt/013` | `67f2762` | Clean; implementation already on API main |
| `../mailcowui-ng` | `main` | `990830e7` | Clean; unchanged because monitoring's performance gate fails |
| `../mailcowui-ng-wt/005` | `wt/005` | `9ac9f886` | Reset/recovery fixes committed; broader account work unfinished |
| `../mailcowui-ng-wt/006` | `wt/006` | `a239986f` | Quarantine list and stale-action refresh checked; broader feature unfinished |
| `../mailcowui-ng-wt/011` | `wt/011` | `8a87b7af` | Rebased; retained loading/rendering fixes tested and committed locally; Lighthouse gate open |

The UI worktrees are clean. Temporary worktree branches remain local: do not push them or use
git stash. There is no rebase in progress. The old `wt/011-rebase-checkpoint-20261019` branch is
only a historical partial-rebase checkpoint; do not merge it.

## API results

The already recovered full suite at implementation commit `67f2762` passed **9,785 tests,
78,684 assertions, 379/379 operations covered, none pending**, in 31m 10s. Contract lint, Pint
(1,841 files) and PHPStan also passed, recorded in `f1e1df4`. Do not repeat that full suite merely
because a session restarted.

Commit `85791c1` fixes the development seeder's missing mailbox self-aliases. The four seeded
mailboxes now have the rows legacy mailbox creation creates, making send-as candidates available.
The added request-level regression failed before the fix; the focused seeder/sender-settings
run then passed **19 tests, 162 assertions**, with targeted Pint and PHPStan passing. The shared
mailcow row factory was not changed and no live development database was reset.

The deferred `composer test:performance` completed in 12m 00s: **22 tests, 3,364 assertions,
one error and three failures**. Three problems were missing packages in main's stale local
`vendor/` (`symfony/yaml`, `mlocati/spf-lib`, `league/oauth2-client`). `composer install` restored
the locked dependencies without changing the lockfile. The YAML checker and the DNS/SSO timing
tests then passed. The other timing tests, including API 013 settings and mass mail, passed in
the original run. Do not describe that original full timing run as green.

The real timing failures were quarantine search (p95 **1.261 s**) and, once search was fixed,
domain-admin listing (**1.484 s**). Commit `cba8fe1` uses a window count for these lists to avoid
duplicate table scans, with a scoped fallback count for empty later pages. No upstream schema
changed. The final 100,000-item benchmark passed **1 test, 111 assertions**, including a newly
added broad search matching every item. p95 seconds: default **0.801**, selective search
**0.579**, broad search **0.801**, score sort **0.712**, domain admin **0.854** (budget 1.0 each).
The final focused list/scope run passed **31 tests, 196 assertions**, with targeted Pint and
PHPStan passing. The earlier combined list/scope/YAML run passed **49 tests, 208 assertions**.

Logs: `a013/resume-performance.log` (original), `resume-composer-install.log`,
`resume-performance-recheck.log` (DNS/SSO pass; domain-admin failure),
`resume-quarantine-performance.log` (all quarantine cases pass), and
`resume-{search,domain}-{functional,format,analyse}*.log`, under the artifact directory below.

T097 (full live mailcow Part C parity) remains open. After the owner's explicit deployment
authorization, API main through `b3dc2ab` was pushed and deployed to `maildev.feldhost.cz`.
UI staging remains `990830e7` until monitoring's performance gate passes.

API 013 host configuration was synchronized before deployment: four additional mounts, five
environment entries (including `RESTV2_LICENSE_VERIFICATION=false` for FeldHost), mass-mail
runner/purge scheduler labels, and the 16 MiB logo upload nginx location. Other services and
existing API `env_file`, image/pull settings were preserved. Compose validation and nginx syntax
checks passed. The deploy script recreated only `restv2-mailcow`; nginx was reloaded and ofelia
restarted to register the new jobs. Readiness reports database, schema, Redis and migrations all
healthy. UID 82 has write access to the regex map and both Sieve mounts, and can read the language
files. Scheduler logs show the new runner completing without errors. The old API image
`4be8653` remains available for rollback.

Deployment logs are `a013/deploy-main.log` and `a013/deploy-postflight.log`; the protected local
`a013/deploy-preparation/` directory holds the reviewed configuration candidates and originals.
Host rollback copies are `/root/mailhosting/restv2/{override.previous-013.yml,nginx.previous-013.conf}`.
This deployment verification does not replace the remaining feature-specific Part C parity checks.

## Monitoring: rebase complete, performance gate open

UI 011 was rebased once onto `990830e7`, preserving routing and operations routes, navigation,
translations, mock state/handlers, smoke cleanup and Lighthouse collections. Three test fixes:

- API-key defaults: scope heading assertions to `main`, because navigation now has System too.
- Placeholder navigation: use System settings; Routing is now implemented.
- Dashboard rates: await each polling response before advancing the fake clock again, so two
  non-null rate samples arrive before asserting the chart.

The old audit-log empty-filter failure did not reproduce in the initial focused run, ten mobile
repetitions or the three-browser integration run. Its cause remains unproven; no production
change was made for it.

Validation on the rebased code:

- **3,263 unit tests / 369 files passed**, line coverage **88.97%**.
- Affected browser integration: **1,449 passed, 11 skipped, 4 failed**. The four failures were
  the placeholder assertion in all three projects and the desktop chart timing assertion.
  Their corrected versions and the API-key assertion passed **18 focused runs** (three projects,
  repeated twice). No unexplained failure remains in that affected run.
- Typecheck, lint, formatting, i18n, contract consistency, licenses, build and budgets pass.
  Initial JavaScript is **186,459 bytes gzipped** against 204,800. Runtime audit: no findings;
  the configured development audit passes with 21 noncritical advisories (2 low, 4 moderate,
  15 high).
- **Lighthouse failed on a quiet machine**, all eight collections actually ran three times.
  Accessibility: **99–100**. Performance: sign-in 85–89, home 82–84, domains 75–78, aliases
  72–74, DKIM 80–84, mailboxes 81–82, relayhosts 80–81, dashboard **68–71**. Required: 90.
  Best dashboard run: CLS **0.294**, LCP **3.3 s**, TBT **190 ms**. Best sign-in: CLS 0,
  LCP **3.2 s**, TBT **120 ms**; the largest element is the announcement after startup.
  Thresholds/throttling were not relaxed.

UI checkpoint `8a87b7af` now reserves the dashboard's overview rows and CPU/memory area during loading, and downloads
the first route while the existing authenticated bootstrap runs. A delayed-response browser
regression verifies that the container list moves less than 4 px when the overview arrives. An
isolated diagnostic with these changes improved dashboard CLS from **0.294 to 0.0225** and its
performance score to **86**; sign-in scored **88**. These are individual diagnostics, not a passed
Lighthouse gate or a measurement of the final checkpoint.

Two small rendering fixes remain: number/list/relative-time formatting no longer constructs a
calendar formatter merely to check the time zone, and repeated short legacy HTML translations
reuse sanitized strings in a bounded cache. Calendar dates still account for time-zone changes;
the HTML allowlist and sanitization rules are unchanged. Regression tests failed before each fix.

After the owner questioned the effort spent optimizing for slow mobile connections, further
startup, bootstrap-request, lazy-menu and bundler experiments were removed. The retained patch
uses the original authentication request order and build configuration. Experiments are preserved
in `u011/perf-experiments-before-trim.{tgz,patch}`; do not resume tuning or repeatedly rerun
Lighthouse without a new reason. The owner did not explicitly waive the performance gate.

The final retained patch passes **3,267 unit tests / 369 files**, with **88.98% line coverage**
(`u011/final-unit.log`). Lint, type checking, formatting, i18n, contract consistency, licenses,
build and bundle budgets pass. Initial JavaScript is **186,760 bytes gzipped** (limit 204,800).
Dependencies and the lockfile are unchanged; the previously recorded audit remains applicable.
The final production-build browser run passes **231 tests** across mobile Chromium, desktop
Chromium and mobile WebKit, covering the dashboard and delayed loading, shell bootstrap,
password sign-in, sign-in screens, SSO round trips, preferences and legacy translations including
hostile markup. Log: `u011/final-browser.log`; artifacts: `u011/final-browser-results/`.

**Do not fast-forward UI main until T146 passes or the owner explicitly changes that gate.**
T149 (real phones, VoiceOver/TalkBack) and live-host parity still need the owner/environment.
The earlier six local smoke tests at API `4be8653` remain recorded in the feature quickstart;
monitoring has not been deployed to staging. The API deployment is recorded above.

## UI 005: reset and recovery hardening

Commit `9ac9f886` builds on the saved WIP `e71cbef8`:

- Invalid/duplicate/oversized/malformed reset tokens are removed from the URL and clear any old
  in-memory token; malformed percent encoding no longer throws.
- Failed token checks offer a manual retry. Completion throttling disables requests until the
  advertised retry time, including second-factor steps.
- The UI nginx access log excludes query strings and Referer values. CI's container check now
  verifies that a reset token is absent from logs. **Upstream nginx-mailcow logging (AG7) remains
  separate and unresolved.**

Focused unit tests: **17 passed**. Reset/request/recovery browser tests: **54 passed** across
three projects. Typecheck, build, changed-file lint/format and a live local nginx logging check
pass. T154 is marked complete; broader US3 and later account features, smoke and full merge gates
remain pending. The branch has not been rebased or merged. See its quickstart validation log.

## UI 006: quarantine list restored

Commit `48e2ed70` builds on saved WIP `49b41c6a`. Its registered list page was missing and prevented
builds and a rate-limit test suite from loading. The new list has responsive cards/table, legacy
labels, inert message values, search and draft filters, sort/paging in the URL, status notices,
refresh/count announcements, error recovery, and versioned single removal with confirmation.
Show item links open the existing item page. No new translation was introduced.

The initial saved-feature run passed 108 tests but could not load the rate-limit section suite.
After the page was added, those **14** tests passed; **ten** new page/filter unit tests pass.
**18 browser checks** (six scenarios, three projects) pass across the initial run and focused
follow-ups. A too-small `-` subject link was caught and fixed. Coverage includes both-theme axe,
reflow, touch targets, hostile text, read-only/empty/inactive states, retries, removal, and admin
recipient filtering/sorting/reload. Typecheck, build and changed-file lint/format pass.

Follow-up `a239986f` fixes list actions after `412`: both the detail and active lists refresh before
the mutation finishes, so the next confirmation submits the current version tag. The regression
failed first, then the page/action suites passed together (**19 tests**). Lint and type checking
also passed after this change.

The full i18n gate still fails on the saved unused `quarantineUi.bulk.virusCount` key, whose bulk
UI remains unfinished. This is not a finished UI 006: continue bulk selection/actions, remaining
item/download/hostile-content tests, entry-point integration, smoke and performance checks. T053
is recorded as implemented; other story tasks remain open. Do not rebase this branch repeatedly
while monitoring is unresolved. See its tasks and quickstart; the legacy-form adjustments at the
start of `tasks.md` override older task wording.

## Artifacts and operating rules

Durable logs and helpers are under the API checkout's ignored directory:

`/home/feldsam/Projects/mailcow-rest-v2/storage/app/restart-2026-10-19/`

Subdirectories are `a013/`, `u005/`, `u006/`, `u011/`. Continuation logs use `resume-*`; the later
UI diagnostics use `perf-*`, and the retained patch's final checks use `final-*`.
Monitoring's original failure artifacts are in `u011/original-browser-failures/`, its focused
regression artifacts in `u011/resume-regression-results/`, and quiet-machine Lighthouse JSON/HTML
reports plus assertion results in `u011/resume-lighthouse-reports/`. Older helper scripts retain
obsolete `/tmp` paths: adapt them before use. Preserve all existing volumes and fixtures.

All tests started by this continuation have finished. The `restv2-013` MariaDB and Redis
containers are stopped, with their volumes preserved; the UI test/Lighthouse containers have
been removed. Unrelated `crew-*` services were left running and remain outside this work.

Read each repository's `CLAUDE.md`, constitution and conventions. Contract-first and test-first;
never modify `source_code` or migrate upstream tables. PHP runs in Docker. Browser tests use
`mcr.microsoft.com/playwright:v1.64.1-noble`, dedicated networks and two workers, with at most two
browser runs at once. Timing suites and Lighthouse run alone. Avoid repeating passed expensive
checks without changed code or new evidence. Commit explicit paths to local worktree branches.

Older Claude decisions remain under
`/home/feldsam/.claude/projects/-home-feldsam-Projects-mailcow-rest-v2/memory/`; prefer the current
commits and measured results above over stale resume notes.
