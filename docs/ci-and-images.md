# Security scans and container publishing

## Codex Security

The [Codex Security workflow](../.github/workflows/codex-security.yml) uses the
[official OpenAI Action](https://learn.chatgpt.com/docs/security/cli/ci/github).
It scans changes in same-repository pull requests and scans the full repository every Monday.
A maintainer can also run it from **Actions → Codex Security → Run workflow**, selecting `main`.
Fork, Dependabot and draft pull requests are excluded; a skipped job is not a security review.

In **Settings → Secrets and variables → Actions**, add `CODEX_SECURITY_API_KEY` using an OpenAI
API key with Codex Security access. The workflow fails with a setup message if it is missing.
The key is available only to the scan step; publishing runs in a separate job without it.
Checkout credentials are not persisted, and the scanner runs with the documented Ubuntu sandbox.

Find results in the job summary, its downloadable reports (seven days), and **Security → Code
scanning**. Reports can include source excerpts and vulnerability details. Private repositories
also need GitHub Code Security enabled for SARIF uploads. Scans are initially advisory:
findings do not block publishing, but execution and reporting errors fail the scan workflow.
After reviewing coverage and findings, change `fail-on-severity` to `high` and configure the
required status check if enforcement is wanted. The official guide explains how to require a
completed scan rather than accepting excluded or empty scans.

Optional Actions variables:

| Variable | Default | Purpose |
| --- | --- | --- |
| `CODEX_SECURITY_MODEL` | `gpt-6-sol` | Model available to the scan API key |
| `CODEX_SECURITY_MAX_COST` | `5` | Estimated USD stop threshold per scan |

The cost threshold requires CLI pricing support for the model and is not a hard spending cap;
requests already running can exceed it. Review `coverage.json` when evaluating the result.

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
