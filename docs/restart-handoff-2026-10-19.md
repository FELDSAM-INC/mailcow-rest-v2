# Resumed work handoff — updated 2026-10-20

The owner asked to continue work in both `mailcow-rest-v2` and `../mailcowui-ng` after the
system-restart checkpoint. The first continuation was local. The owner subsequently authorized
committing, pushing and deploying completed work. The API is deployed, and the owner then
explicitly requested the monitoring UI deployment despite the recorded Lighthouse failures.
The API and the later quarantine UI release below are deployed. UI 006 used no subagents;
the owner then explicitly requested parallel agents for UI 005, and three workers were used.

On 2026-10-20 the owner asked to adjust the roughly 4 KiB sign-in transfer warning and continue
unfinished features. UI 006's implemented quarantine, spam-policy, notification and rate-limit
flows are now merged, pushed and deployed as `mailcowui-ng:3de1d64a`. Sign-in transfers 208,730
script bytes, below the approved 215,040-byte allowance. The subsequent UI 005 increment delivers
US1–US4; later account features remain unfinished. The current table and latest release section
supersede the earlier chronological checkpoints below.

## Current checkpoints

| Repository/worktree | Branch | Checkpoint | State |
|---|---|---|---|
| `mailcow-rest-v2` | `main` | `2e10681` plus this handoff update | API `b3dc2ab` remains deployed and healthy; later commits are handoff notes |
| `../mailcow-rest-v2-wt/013` | `wt/013` | `67f2762` | Clean; implementation already on API main |
| `../mailcowui-ng` | `main` | `dbf920db` | UI `ddf44e59` deployed; documentation-only release notes pushed |
| `../mailcowui-ng-wt/005` | `wt/005` | `dbf920db` | US1–US4 integrated; US5–US12 and feature-wide/manual acceptance remain open |
| `../mailcowui-ng-wt/006` | `wt/006` | `d3a669bb` | Integrated and deployed; remaining acceptance/manual cases are documented |
| `../mailcowui-ng-wt/011` | `wt/011` | `5e3c1e89` | Clean historical monitoring checkpoint; current UI deployment includes it |

The UI worktrees are clean. Temporary worktree branches remain local: do not push them or use
git stash. There is no rebase in progress. UI 006 was rebased once onto `8431c4eb` before final
integration, then main was fast-forwarded. The old `wt/011-rebase-checkpoint-20261019` branch is
only a historical partial-rebase checkpoint; do not merge it. UI 005 was rebased once onto
`d3a669bb`, then fast-forwarded after its final checks; local worktree branches remain unpushed.

## UI 005: account security, recovery and app passwords

Release `ddf44e59` completes the implemented US1–US4 increment: own and forced password
changes, second-factor management and forced setup, recovery/reset, and app-password management
in own/mailbox contexts. App passwords include one-time generated secrets, protocol/status/name
editing, regeneration, deletion, bulk actions, URL state, version conflicts and read-only domain
administrator access. The approved D3/D13 legacy form map is preserved.

Independent workers implemented app passwords, exercised the real API, and reviewed security
lifecycle/reset behavior. Regressions first failed for late secret responses, security-key
cancellation, context changes during secret generation, silent Yubico errors and invisible dialog
focus. The fixes keep secrets out of caches/storage and prevent completed requests from displaying
an old secret on a newly visited mailbox page. The reset page now shows account/expiry, captures
same-route links, handles every factor's error text, and hides recovery after failed capabilities
refreshes. AG7 is resolved for generated links: both API default and maildev runtime use
`/ng/reset-password#token={token}`; legacy query links still need appropriate upstream logging.

Validation:

- **3,861 unit tests / 441 files passed**, **83.83% line coverage**. The first full run collected
  the focus regression before its source fix and failed only that case; the stable full rerun
  passed. A later unchanged query move passed another **95 focused unit checks**.
- **389 integrated browser checks**, **36 app-password checks**, and **12 final focus/navigation
  checks** passed across mobile Chromium, desktop Chromium and mobile WebKit, without retries.
  A final **68 checks** passed after moving the shared capability query out of the account barrel.
- **7 real API smoke tests passed**, followed by **2 focused checks** on the final bundle. Own
  password, emailed reset with TOTP, forced password, TOTP enrollment/removal, app-password
  creation/deletion, administrator views and an actual read-only domain-admin API refusal were
  exercised. Credentials/settings were restored and temporary records removed. Limited fixed
  password/generatedPassword marker counts were zero in app, web and storage logs. The isolated
  `u005-smoke` stack and its worktree were removed.
- Typecheck, lint, formatting, translations, contract consistency, licenses and configured audit
  pass. Runtime audit has no findings; the existing 21 noncritical development advisories remain.
  Initial JavaScript: **193,343 / 204,800 bytes gzipped**.
- Lighthouse collected **10 URLs × 3 runs**: My account **87–90 performance / 100 accessibility**,
  sign-in **86–87 / 100**, with **213,656 / 215,040 script bytes**. Nine URL assertions pass,
  including domains under the configured aggregation. **Aliases still fails at 74–77 / required
  80** under the existing owner-authorized staging exception. The command exits 1; do not call
  the full gate green. All accessibility checks pass (99–100).

The first Lighthouse attempt found sign-in above the unchanged script-transfer cap at 216,139
bytes. Collection was stopped early. Sharing the unchanged capability query directly between
account/auth removed unused account wrappers and password-change code from sign-in. No budget,
score threshold, network or CPU throttling was relaxed.

The standard deployment script installed **`mailcowui-ng:ddf44e59`** on
**https://maildev.feldhost.cz/ng/**. Only `ng-mailcow` was recreated; it is healthy, and the public
URL returns 200 with CSP. API `b3dc2ab` remains healthy (database/schema/Redis/migrations OK).
Previous UI image `3de1d64a` remains available for rollback. Live sign-in and invalid reset-link
checks passed on mobile Chromium, desktop Chromium and mobile WebKit, with no JavaScript/CSP
errors or horizontal overflow. No live credentials were submitted. UI documentation follow-up
`dbf920db` is pushed; it does not require another deployment.

The rest of UI 005 remains open: US5–US12, the complete mailbox self-service section group
(T024/T036), whole-feature secrets/visibility/parity acceptance and physical-device/screen-reader
validation. The two new app-password message keys are listed for language review in the feature
quickstart. Do not mark the entire feature done. T042/T052/T065/T072–T080 and T149 are verified.

Artifacts: `storage/app/restart-2026-10-20/u005/` (ignored), especially `unit-final-stable.log`,
`final-integrated-browser.log`, `app-passwords/browser-focus-final.log`,
`lazy-capabilities-browser.log`, `account-release-smoke.log`, `account-shared-smoke.log`,
`lighthouse-final.log` and the final deployment logs.

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
UI staging is now `3de1d64a`; its release and the two retained staging exceptions are recorded below.

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

## Monitoring: deployed with an owner-approved performance exception

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
Lighthouse without a new reason.

The owner subsequently requested a performance threshold of **80 instead of 90**. UI main
`a9333d85` records this in constitution **2.0.0**, CI assertions and normative feature documents.
Accessibility remains **90**, mobile settings and three runs per URL are unchanged, and the
initial JavaScript budget remains 204,800 bytes. Historical measurements were preserved.
The policy was merged into monitoring as `8f016219`, including its additional dashboard assertion.
One complete Lighthouse run under this new policy collected all eight URLs three times, with
no concurrent build/test workload. No application code changed since the already tested
`8a87b7af` build. Performance ranges: sign-in **87–89**, home **81–82**, domains **76–78**,
aliases **74–75**, DKIM **80–84**, mailboxes **80–84**, relayhosts **78–81**, dashboard **83–84**.
Accessibility remains **99–100**, passing throughout. The configured aggregation passes six
performance checks; domains and aliases still fail. Sign-in also fails the separate script-transfer
limit at **208,974 bytes** versus **204,800**, although the manifest-based initial gzip budget
still passes at **186,760 bytes**. At this stage the owner had changed neither size limit; the
subsequent transfer-limit adjustment is recorded below.

The command exited 1 on those three assertions. T146 remains open; monitoring was not merged,
pushed or deployed. No more tuning or repeated benchmark was started. Results are recorded in
UI checkpoint `50a001dd`. Log: `u011/policy-80-lighthouse.log`; all 24 JSON/HTML reports and
assertion results: `u011/policy-80-lighthouse-reports/`. The threshold change itself is already
pushed to UI main as `a9333d85`; no application redeployment is needed for this CI/docs-only commit.

On 2026-10-20, UI main commit `8431c4eb` raises Lighthouse's shell-page script-transfer ceiling
to **215,040 bytes (210 KiB)** at the owner's request. The manifest-based initial bundle remains
**204,800 bytes gzipped**, performance remains **80**, and accessibility remains **90**. Reasserting
the saved 24 reports clears the sign-in size failure; only domains and aliases still fail their
performance assertions. No new Lighthouse measurements or application redeployment were needed.
Log: `u011/transfer-210-assert.log` under the 2026-10-19 artifact root.

The final retained patch passes **3,267 unit tests / 369 files**, with **88.98% line coverage**
(`u011/final-unit.log`). Lint, type checking, formatting, i18n, contract consistency, licenses,
build and bundle budgets pass. Initial JavaScript is **186,760 bytes gzipped** (limit 204,800).
Dependencies and the lockfile are unchanged; the previously recorded audit remains applicable.
The final production-build browser run passes **231 tests** across mobile Chromium, desktop
Chromium and mobile WebKit, covering the dashboard and delayed loading, shell bootstrap,
password sign-in, sign-in screens, SSO round trips, preferences and legacy translations including
hostile markup. Log: `u011/final-browser.log`; artifacts: `u011/final-browser-results/`.

After these failures were reported, the owner explicitly requested **"so, deploy to maildev"**.
This authorizes this staging release with the known failures; the configured thresholds and
T146's open status remain unchanged. The exception is recorded in the monitoring plan's
Complexity Tracking and quickstart. UI main was fast-forwarded to `5e3c1e89` and pushed, and the
standard deployment script built and installed **`mailcowui-ng:5e3c1e89`** on maildev. Only
`ng-mailcow` was recreated; API `b3dc2ab` was not replaced. Compose validation passed, the new
container is healthy, and `/ng/` answers HTTP 200 with the required CSP. Previous UI image
`mailcowui-ng:990830e7` remains on the host for rollback.

A browser check against the real administrator sign-in page at 360 px passed: visible username
and password fields, enabled submit button, HTTP 200, no JavaScript errors and no CSP violations.
No credentials were submitted. API readiness confirms database, schema, Redis and migrations
healthy. Logs: `u011/deploy-maildev.log` and `u011/deploy-maildev-browser.log`. UI deployment notes
are committed and pushed as `6a3ee2ca`; that documentation-only follow-up needs no redeployment.

T149 (real phones, VoiceOver/TalkBack) and T150 Part D (authenticated live-host parity) remain
open. The earlier six local smoke tests at API `4be8653` remain recorded in the feature quickstart.
Deployment health and public sign-in checks do not replace these feature-specific checks.

## UI 005: reset and recovery hardening (earlier checkpoint)

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

Continuation `7effcca1` adds explicit bulk selection with the server limit, first-N select-all,
view-change resets (including browser history), 44 px controls, and the shared sticky action bar.
The four actions confirm subjects/recipients and listed version tags; deletion/learning require
the typed count, and virus deliveries require acknowledgement. Per-message results preserve
learning warnings. Only refused/failed/outdated IDs remain selected, and a retry refreshes and
confirms again without including unrelated new selections. A lost response refreshes the list
and never resends; a whole-request refusal preserves selection.

The quarantine feature suites pass **94 tests in 13 files**, with **21** existing mock-handler
tests passing. Typecheck, changed-file lint/format, production build, and bundle budget pass
(**188,897 / 204,800 bytes gzipped**). The full i18n gate now passes because the bulk dialog uses
the saved virus-count translation; no new strings were added.

All **45 distinct browser checks** pass across the initial and focused runs: nine bulk scenarios
and six list scenarios on mobile Chromium, desktop Chromium and mobile WebKit. The initial run
passed 37/42; the failures were three incorrect fixture-subject expectations and two WebKit
dark-dialog contrast failures, fixed with explicit text colors. The follow-up passed 29/30; the
remaining test raced the search debounce and used an absent fixture phrase. Synchronizing on
URL/list transitions with a matching phrase passed in all three projects (3/3). Accessibility in
both themes, reflow, touch targets and CSP checks remain enabled; no timeout/retry was increased.

At this earlier checkpoint T059, T061, T063, T065–T067 and T069 were recorded as implemented,
with item/download/hostile-content coverage, host integration, smoke and final release checks
still pending. The release continuation below supersedes that local checkpoint. The task file
remains authoritative for acceptance gaps; its legacy-form adjustments override older wording.
The performance-80 and transfer-210-KiB policies were preserved during integration.

## UI 006: integrated and deployed on 2026-10-20

UI main through `3de1d64a` is pushed and deployed at **https://maildev.feldhost.cz/ng/**.
Documentation follow-up `d3a669bb` is pushed and also fast-forwarded into `wt/006`. The release
includes:

- Quarantine list, filtering/sorting/paging, explicit selection, confirmed single and bulk
  actions, version conflicts, per-message outcomes and safe retry handling.
- Item details, inert message text, byte-preserving attachments and `.eml` downloads, bounded
  previews for very long metadata, and permission-sensitive controls.
- Mailbox notification fields saved through the existing mailbox Save action, a bulk notification
  menu and history; mailbox/domain/alias-domain spam scores, policies and rate-limit sections.
- Quarantine settings, template validation, statistics, and the rate-limit overview/reset flow.

The rebase preserves monitoring's shared plain-text element handling, bounded audit details,
and reset-bucket behavior. Confirmation paragraphs and notification list items have explicit
colors in both themes. Host browser tests now scope Save and History to the correct sections.

Final validation is recorded in UI 006's quickstart. The full integration unit run passed
**3,633 tests in 415 files**, with **85.07% line coverage**. The later final component/budget run
passes **19 tests**. Earlier integration runs cover **518 distinct selected browser checks**
with four skips; the final repackaged production build separately passes **259 checks** with
two viewport-specific skips. The final Docker image passes all **three real-API smoke cases**
together, covering message bytes/delivery, saved notification/score/policy/rate settings,
invalid-template rejection and API-matching dev statistics. Type checking, lint, formatting,
contract, i18n, licenses, configured dependency audit and bundle checks pass. The full unit,
contract and security checks were not needlessly repeated after packaging/layout-only changes.

The initial integrated measurement exposed a 217,621-byte sign-in transfer and quarantine
performance 71–73. Direct declarative route entries keep form exports out of the initial shell;
shared collection controls, form feedback and the four co-loaded feature message files now have
appropriate lazy bundles. The quarantine notice reserves its loading space, the footer stays
below the viewport, and collapsed mobile metadata mounts on disclosure. Initial JavaScript is
**189,635 / 204,800 bytes gzipped**. Final Lighthouse ran nine scenarios three times:

- Sign-in **88/88/88**, accessibility 100, script transfer **208,730 / 215,040 bytes**.
- Quarantine **80/79/79**, accessibility 100, layout shift **0.001** (initially 0.112). The existing
  LHCI aggregation passes its performance-80 gate; two individual runs remain at 79.
- Home, dashboard, relayhosts, mailboxes and DKIM pass their configured assertions.
- The only failures remain **domains 76/77/78** and **aliases 77/75/74**. Every accessibility
  and shell-transfer assertion passes. The already authorized maildev staging exception for
  these two list pages is retained; no threshold or aggregation was changed. Do not describe
  the full Lighthouse run as green.

The standard deployment script built a clean archive of `3de1d64a`, recreated only `ng-mailcow`,
and verified compose, container health, HTTP 200 and CSP. API image `b3dc2ab` remains healthy.
Previous UI image `5e3c1e89` is retained on maildev for rollback. Live public administrator sign-in
checks pass on mobile Chromium, desktop Chromium and mobile WebKit: visible fields, enabled
submit, reflow, CSP recorder, no JavaScript errors and API readiness. No live credentials were
submitted or mailboxes/messages changed. The standalone probe was aligned with the existing
CSP fixture's WebKit native-control handling and token-aware `username webauthn` autofill;
its initial helper failures are preserved. No application change was required for them.

Physical-device, VoiceOver/TalkBack, moderated-user and live authenticated parity checks remain
open, as do the acceptance cases still unchecked in the original task list. This is an
implemented-feature release, not a claim that every checklist item or UI feature is complete.
Continue next with `../mailcowui-ng-wt/005` (`9ac9f886`): account/self-service stories beyond the
saved password-reset/recovery fixes, including the unresolved upstream reset-token logging
observation AG7. Preserve the shared confirmation, plaintext and host integrations now on main,
and the performance-80 policy when that older worktree is eventually integrated.

Logs and reports are under `storage/app/restart-2026-10-20/u006/`: `integrated-*`,
`host-regression.log`, `final-focused-browser.log`, `release-*`, `release-lighthouse-reports/`,
`deploy-maildev*.log` and `cleanup-smoke.log`. Earlier failing runs and experimental measurements
are retained for evidence; the failed prefetch and offscreen CSS experiments are not in the
source. The isolated `u006-smoke` fixture stack and its clone/volumes are removed after validation.
No test containers remain running; unrelated `crew-*` services and existing dev volumes remain
untouched.

## Artifacts and operating rules

Durable logs and helpers are under the API checkout's ignored directory:

`/home/feldsam/Projects/mailcow-rest-v2/storage/app/restart-2026-10-19/`

Subdirectories are `a013/`, `u005/`, `u006/`, `u011/`. Continuation logs use `resume-*`; the later
UI diagnostics use `perf-*`, and the retained patch's final checks use `final-*`.
Monitoring's original failure artifacts are in `u011/original-browser-failures/`, its focused
regression artifacts in `u011/resume-regression-results/`, and quiet-machine Lighthouse JSON/HTML
reports plus assertion results in `u011/resume-lighthouse-reports/`. Older helper scripts retain
obsolete `/tmp` paths: adapt them before use. Preserve all existing volumes and fixtures.

The 2026-10-20 bulk continuation logs and retained failure artifacts are under
`storage/app/restart-2026-10-20/u006/`: `u006-bulk-{unit,mocks,type2,lint*,build2,budget,i18n}.log`,
the red-test logs, and the three browser logs. `browser-first-results/` and
`browser-second-results/` retain the failure screenshots/traces; the final focused run passed.

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
