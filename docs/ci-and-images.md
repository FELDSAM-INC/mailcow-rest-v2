# Security scans and container publishing

## Codex Security

Full repository scans run locally using ChatGPT sign-in and plan usage. The
[GitHub workflow](../.github/workflows/codex-security.yml) uses the paid API only for changes in
trusted, non-draft pull requests from this repository. Fork and Dependabot PRs are excluded.
There are no scheduled, push-triggered or manual full-repository API scans.
Historical Actions runs retain their original workflow revision: rerunning an old full scan can
still charge the API. Use the local helper for full scans.

### Full scans with your subscription

Install Node 22.13+ (or Node 24/26) and Python 3.10+. The helper selects an available supported
Python version; use `--python /path/to/python` to select one explicitly.
Sign in with your ChatGPT account once:

```bash
npx --yes @openai/codex-security@0.6.0 login
```

From either repository, run:

```bash
node scripts/security/scan-local.mjs
```

The helper pins CLI 0.6.0, passes `--auth chatgpt --provider openai`, and removes
`OPENAI_API_KEY` and `CODEX_API_KEY` from the scanner environment. It never falls back to API
billing. Scans consume your plan's usage and remain subject to plan/model limits and Codex
Security access. ChatGPT sign-in for local scans is different from Codex Security Cloud:
[Cloud scans are billed separately](https://learn.chatgpt.com/docs/security/faq).
See the [authentication reference](https://learn.chatgpt.com/docs/security/cli/reference#select-scan-authentication).

The default model is `gpt-6-sol`, with high reasoning effort. Choose a model available to your
account with `--model MODEL`. Check the local configuration without a scan or model call:

```bash
node scripts/security/scan-local.mjs --dry-run
```

A dry run does not validate account access or consume scan quota. Real scans write their report
and each attempt's JSON output to the private temporary directory printed by the helper.
Review the findings and coverage there; copy reports elsewhere if they need to survive temporary
file cleanup. Reports may contain source code and vulnerability details.

### Waiting for a rate limit

The CLI already retries model connections. If it still exits with a structured rate-limit
failure, the local helper waits 60 seconds, then 120 seconds, with a small random delay before
restarting. It respects a longer `retryAfterSeconds` hint when the CLI exposes one. There are at
most three attempts and a shared 900-second wait budget. Set `--max-wait-seconds 0` to disable
restarts, or choose a value up to 7200. A server hint that exceeds the remaining budget stops the
helper instead of retrying too early.

A restarted scan is a new scan and consumes more subscription usage; it does not resume the
interrupted analysis. Authentication, explicit billing/budget failures, findings-policy failures,
malformed output and cancellations are not retried. Persisting throttling still exits nonzero.
CI keeps the official Action's connection retries, without paid whole-scan restart loops.

### Paid CI checks for PR changes

Add `CODEX_SECURITY_API_KEY` in **Settings → Secrets and variables → Actions**, using an OpenAI
API key with Codex Security access. The key is available only to the scan step. Checkout
credentials are not persisted, and the scanner uses the documented Ubuntu sandbox.

| Actions variable | Default | Purpose |
| --- | --- | --- |
| `CODEX_SECURITY_MODEL` | `gpt-6-sol` | Model available to the scan API key |
| `CODEX_SECURITY_PR_MAX_COST` | `5` | Estimated USD stop threshold for each PR scan |

The old `CODEX_SECURITY_MAX_COST` variable is no longer used, so a former full-scan allowance
cannot silently raise the PR allowance. The cost threshold is an estimate, not a hard spending
cap; requests already running can exceed it. If the scan stops before producing a valid final
report, "no validated artifacts" is expected. Do not upload the raw temporary results directory.

Results appear in the job summary, downloadable validated reports (seven days), and **Security →
Code scanning**. Private repositories also need GitHub Code Security for SARIF uploads. Scans
remain advisory: findings do not block publishing, but execution/reporting errors fail the scan
workflow. A skipped or failed scan is not a clean security result. See the
[official Action guide](https://learn.chatgpt.com/docs/security/cli/ci/github).

## Public container images

CI publishes `ghcr.io/feldsam-inc/mailcow-rest-v2` and the UI repository publishes
`ghcr.io/feldsam-inc/mailcowui-ng`. Images support Linux AMD64 and ARM64.
Each repository owns its image and publishes only after its required build and test jobs pass.

| Tag | Meaning |
| --- | --- |
| `latest` | Latest successfully published `main` commit; a moving development channel |
| `sha-<full-commit-sha>` | Build of that exact commit |
| `1.2.3` | Git tag `v1.2.3`, when a release tag is pushed |

Release tags do not move `latest`. For a fixed installation, pin an image digest; rebuilding a
commit can include newer base-image layers, so a commit tag alone is not a content digest.
CI records the published digest and tags in its summary. Pull requests and scheduled runs
validate images without publishing. Publication uses the repository's `GITHUB_TOKEN`, with
`packages: write` limited to the publishing job; no registry password is needed.

### One-time package visibility

GitHub creates a new GHCR package as private, even for a public repository. After the first
publish, open each package's **Package settings → Change visibility → Public**:

- [API package](https://github.com/orgs/FELDSAM-INC/packages/container/mailcow-rest-v2/settings)
- [UI package](https://github.com/orgs/FELDSAM-INC/packages/container/mailcowui-ng/settings)

The organisation must allow public package creation. If a package already exists, give its
source repository Actions access under **Manage Actions access**. CI adds the source repository
label and publishes through that repository so subsequent builds remain associated with it.
See [GitHub's registry documentation](https://docs.github.com/en/packages/working-with-a-github-packages-registry/working-with-the-container-registry).

Verify anonymous access without your normal Docker credentials:

```bash
docker --config "$(mktemp -d)" manifest inspect ghcr.io/feldsam-inc/mailcow-rest-v2:latest
```

```bash
docker --config "$(mktemp -d)" manifest inspect ghcr.io/feldsam-inc/mailcowui-ng:latest
```

Users can then follow the [installation guide](installation.md) without a GitHub account or
local image builds. Until visibility is public and these checks pass, publication is incomplete.

Performance and Lighthouse checks are opt-in with the Actions variable
`PERFORMANCE_CHECKS_ENABLED=true`. Automated browser accessibility checks are opt-in with
`ACCESSIBILITY_CHECKS_ENABLED=true`, following the maintainer's current testing preference.
