# mailcow REST API v2 Constitution

mailcow-rest-v2 is a standards-compliant REST API for mailcow-dockerized. It replaces the legacy
`/api/v1` (`json_api.php`) and the form handlers of the legacy UI, and it is the only backend of
the separate mailcowui-ng web app. It is written so that it can be proposed for inclusion in
upstream mailcow, and so that mailcow contributors can work on it.

## Core Principles

### I. Contract-First (OpenAPI Is the Source of Truth) (NON-NEGOTIABLE)

- The OpenAPI 3.1 document in `api/` is authored before the code that implements it. Paths,
  methods, parameter names, request/response bodies, status codes and security schemes in PHP
  mirror it exactly. Undocumented behaviour is a bug.
- Request and response bodies reference shared schemas in `api/components/schemas/`. Shared
  parameters (`limit`, `offset`, `sort`, `order`) and responses live in
  `api/components/parameters/` and `api/components/responses/`.
- Schemas describe the real upstream database of the supported mailcow release. No phantom
  columns, no wrong table names or keys.
- CI validates every feature test's request and response against the contract, lints the
  contract and runs breaking-change detection against the last release. Within `/api/v2`
  changes are additive only.
- The bundled contract is a release artifact, versioned with the API. mailcowui-ng generates
  its client from a pinned release.

### II. HTTP Contract

- Base path `/api/v2`. It never collides with the legacy `/api/v1`, which keeps working.
- Resources are plural nouns, nested where ownership is real (`/domains/{domain}/mailboxes`).
  No verbs in paths. Non-CRUD actions are sub-resources (e.g. `POST /quarantine/{id}/release`).
- Method semantics follow RFC 9110: GET is safe and never changes state; PUT and DELETE are
  idempotent; PATCH takes JSON Merge Patch (RFC 7396).
- Status codes: 200 for read and update, **201 with `Location`** for create, **204** for
  delete. Errors: 400, 401, 403, 404, 409, 412, 422, 429, and 500 for unexpected failures.
  Success is never reported with an error body, and errors are never reported with 200.
- **List responses**: `{ "data": [...], "meta": { "total", "limit", "offset" } }`. Lists accept
  `limit` / `offset` / `sort` / `order` plus documented filters. **Single resources** use one
  project-wide shape, defined once in `api/components/responses/`.
- **Errors** are RFC 9457 `application/problem+json` (`type`, `title`, `status`, `detail`, and
  an `errors` map on 422). Every `type` URI that clients act on is documented in
  `docs/problems.md`. Other problems use `about:blank`.
- Mutable single resources return an `ETag` and honour `If-Match` (412 on mismatch). The legacy
  UI edits the same rows, so lost updates are a real risk.
- JSON uses camelCase names, booleans instead of `0/1` or `y/n`, RFC 3339 UTC timestamps, and
  explicit units (quota in bytes). Conversion from mailcow's storage formats happens in
  Eloquent casts.

### III. Upstream Schema Is an External Contract (NON-NEGOTIABLE)

- mailcow-rest-v2 MUST NOT create, alter or drop upstream tables, views or columns. Upstream's
  `init_db.inc.php` owns them, and SOGo and Dovecot wait for its schema version at startup.
- No Laravel migration ever targets an upstream table. API-owned tables (API keys, audit log,
  sessions if stored in SQL) use a dedicated prefix that cannot collide with upstream names, and
  Laravel's migrations table carries the same prefix.
- Supported mailcow releases are listed in the README. CI runs the integration suite against
  the schema of each supported release, and a new upstream release is supported only after
  that suite passes.

### IV. Behavioural Parity with Legacy mailcow

- Before implementing business logic, read the legacy implementation in
  `source_code/data/web/inc/functions.*.inc.php`, its form handlers and its JS validation. Mirror
  its validations, defaults, limits and permission checks.
- Writes MUST reproduce every side effect the legacy code performs, so that Postfix, Dovecot,
  Rspamd and SOGo behave identically whichever API made the change. Examples:
  - Related rows in other tables.
  - Redis keys (DKIM, ratelimits, fail2ban, quarantine, `DOMAIN_MAP`).
  - Keeping `_sogo_static_view` in sync and flushing memcached.
  - dockerapi and doveadm calls.
  - Password hashes in the `MAILCOW_PASS_SCHEME` format that Dovecot verifies.
- All writes to upstream tables go through services in `app/Services/`. A service owns the
  writes and the side effects of one aggregate, and controllers never write upstream tables
  directly.
- Deviation from legacy behaviour is allowed only when the feature spec states it explicitly.
  `docs/legacy-inventory.md` lists the parity surface.

### V. Coexistence

- The API ships as its own container added to mailcow-dockerized via
  `docker-compose.override.yml`. It needs no patches to other mailcow containers or files.
- php-fpm-mailcow stays and keeps serving the legacy UI, `/api/v1`, Dovecot auth
  (`mailcowauth.php`), SOGo SSO, the Rspamd maps, autodiscover and the other internal endpoints.
  Taking over an internal endpoint requires its own feature spec and a parity test suite.
- Sessions, API keys and audit records of the two implementations are independent. Signing in
  to one does not sign in to the other unless a spec says otherwise.

### VI. Security by Default

- OWASP API Security Top 10 and OWASP ASVS level 2 are the review baseline.
- Authorisation runs on every request at **object level** for the three roles (admin, domain
  admin, mailbox user), including the `da_acl` and `user_acl` flags. A domain admin can never
  reach an object outside their domains, even with a guessed ID.
- **Machine access** uses the `X-API-Key` header (`apiKeyAuth` scheme), the same as legacy
  mailcow and ispconfig-rest.
  - Keys are stored only as SHA-256 hashes and shown once at creation.
  - Keys have an owner, a scope (read-only or read-write), an optional expiry and an optional
    list of allowed source networks.
  - Invalid keys are reported to fail2ban like legacy.
- **Browser access** uses a same-origin session cookie (`Secure`, `HttpOnly`, `SameSite`) with
  CSRF verification on **every** state-changing request, whatever its content type.
- The second factors of existing accounts (TOTP, WebAuthn, Yubi OTP) keep working without
  re-enrolment.
- Every state-changing request writes an audit record: actor, target, change, time and source
  address. Secrets never appear in logs or responses after creation.

### VII. Test-First (NON-NEGOTIABLE)

- Tests are written first and observed failing before implementation.
- Every endpoint ships with PHPUnit feature tests for the happy path, validation failures,
  authentication failures, authorisation failures (foreign domain), and its side effects (the
  resulting rows, Redis keys and outbound service calls).
- Tests run against **MariaDB with the real upstream schema**, never SQLite: the schema relies
  on MariaDB features such as views and JSON columns. Redis runs for real. dockerapi, doveadm and
  the Rspamd controller are faked behind interfaces, and the fakes assert the calls made.

### VIII. Simplicity

- Use Laravel's own features before adding packages. Every new Composer dependency is justified
  in the plan that introduces it.
- Build only what a current feature spec needs. Abstractions appear after the second concrete
  use.
- Deviations from a principle are recorded in the plan's Complexity Tracking table, together
  with the simpler alternative that was rejected.

## Technology & Architecture Constraints

- **Stack**: Laravel 13 on PHP 8.3 or newer. Data access uses Eloquent models with explicit
  `$table` and `$primaryKey`, mailcow's own `created`/`modified` columns, and casts for
  mailcow's storage formats. Upstream php-fpm-mailcow currently runs PHP 8.2, which reaches end
  of life on 2026-12-31. When proposing upstream inclusion, the PHP baseline is re-checked
  against upstream's image.
- **Runtime**: one stateless HTTP service in a non-root container with a read-only root
  filesystem. It exposes liveness and readiness endpoints and writes structured JSON logs that
  carry a request ID (W3C `traceparent`). Configuration comes from environment variables, reusing
  `mailcow.conf` names where they exist.
- **No UI in this repository**, apart from the interactive API documentation (Swagger UI or
  equivalent) rendered from the contract.

| Path | Contents | Rules |
|---|---|---|
| `api/` | `openapi.yaml`, `modules/{module}/*.yaml`, `components/{schemas,parameters,responses}/` | Source of truth. Module path files are kebab-case, schema files PascalCase, one `_index.yaml` per module |
| `app/Http/Controllers/Api/V2/` | `<Entity>Controller` with `index/show/store/update/destroy` | Thin, HTTP concerns only. Validation in Form Requests |
| `app/Services/` | Domain services per aggregate | Own all upstream writes and side effects. Never build HTTP responses |
| `app/Models/` | Eloquent models of upstream and API-owned tables | No migrations for upstream tables |
| `app/Clients/` | Interfaces and adapters for dockerapi, doveadm, Rspamd, Redis-backed subsystems | Faked in tests |
| `routes/api.php` | All routes under `/api/v2` | Specific routes before general ones |
| `tests/Feature/`, `tests/Unit/` | PHPUnit 11+ | MariaDB with the upstream schema |
| `docs/` | `legacy-inventory.md`, `problems.md`, `roadmap.md` | — |
| `source_code/` | Untracked symlink to a mailcow-dockerized checkout | Read-only reference |

## Development Workflow & Quality Gates

- Work follows Spec Kit: `/speckit-specify` → `/speckit-clarify` → `/speckit-plan` →
  `/speckit-tasks` → `/speckit-analyze` → `/speckit-implement`. Specs live in `specs/NNN-*/`.
- A feature is done when all of the following hold:
  1. The implementation matches `api/` exactly. The contract lints clean, has no breaking change
     and renders in the docs UI.
  2. Feature tests pass, with contract validation of every request and response.
  3. Legacy parity is documented in the spec, and the side effects are asserted in tests.
  4. Every upstream write goes through a service, and no migration touches an upstream table.
  5. Object-level authorisation tests cover every role that can reach the endpoint.
  6. Pint formatting and static analysis pass.
- When a feature changes the contract, the release notes list it, so that mailcowui-ng can bump
  its pinned version.
- **Git**: commit directly to `main`, with no feature branches and no pull requests. The Spec Kit
  `before_specify` branch hook is disabled, but spec directories keep the `specs/NNN-feature-name`
  numbering. Commit subjects are short and imperative.
- **License**: GPL-3.0, the same as mailcow, so the code can be proposed upstream and logic
  ported from mailcow stays license-compatible. Every dependency must be GPL-3.0-compatible.

## Governance

- This constitution supersedes other practices. Each plan has a Constitution Check that reviewers
  verify.
- Amendments are made through `/speckit-constitution` and must cite an owner decision or a
  detected convention. Versioning follows semver: MAJOR for removing or redefining a principle,
  MINOR for new or materially expanded guidance, PATCH for clarifications.
- Owner decisions on 2026-10-18:
  - Laravel and PHP, so the API can be proposed upstream and stays accessible to mailcow
    contributors.
  - Conventions shared with ispconfig-rest: modular `api/` layout, `{data, meta}` lists,
    RFC 9457 errors, `X-API-Key`.
  - The API and the Vue app live in separate repositories.
  - GPL-3.0 for both repositories, and commits go directly to `main` (added in 1.1.0).
- Agent runtime guidance lives in `CLAUDE.md` and MUST NOT contradict this document.

**Version**: 1.1.0 | **Ratified**: 2026-10-18 | **Last Amended**: 2026-10-18
