# mailcow 2026-11 adaptation

Maintainer scope: full support, including the new features, while retaining support for
2026-09a and 2026-10. No release is published merely by completing this implementation.

Upstream: tag `2026-11`, commit `88fbaa459a5cd5843c2c7cb06ee2928e2a78b9e6`.
Schema: `02112026_1600` (now supplied through Compose's `DB_VERSION`). Previous supported
schema: `18082026_1200`. The real upstream initializer generated the new SQL fixture.

Sources:

- https://mailcow.email/posts/2026/release-2026-11/
- https://github.com/mailcow/mailcow-dockerized/compare/2026-10...2026-11
- Tagged implementation in `data/web/inc/lib/mailcow/{MailboxGroup,Imapsync,Identity,ApiKey}`.

## Implementation checklist

- [x] Generate real upstream schema fixture and support Compose-provided DB_VERSION in harness.
- [x] Complete compatibility layer and test both schema versions.
  - Source-based sync reads/writes, JSON custom parameters, source-aware ETags.
  - Preserve group aliases and sender grants, account rename/delete references and OAuth ownership.
  - Shared-mailbox authentication exclusion; stable IdP identity links and offboarding guards.
  - Preserve new IdP settings; prefer effective group templates; read sync status from the database.
- [x] Sync sources: scoped CRUD, provider presets, TLS verification, password and OAuth sources,
  authorization-code connection/disconnection, shared token refresh, source selection in jobs.
- [x] Sync jobs: priority, global parallelism/bandwidth settings, migration warnings, typed custom options.
- [x] Mailbox groups: ACL, scoped CRUD, distribution address, membership, send-as, IMAP and SOGo
  shares, subscriptions, templates/priority and per-mailbox memberships.
- [x] Shared mailboxes: create/edit controls, no independent login, correct detail/list display.
- [x] Identity sync: offboarding and group settings, LDAP group modes/attributes, Keycloak paths,
  status/conflicts/preview, request runs, clear identity links; stable identity checks during login.
- [x] Legacy API keys: multiple keys and locked service keys, separate from v2 owner-bound keys.
- [x] Fail2ban: unban all with confirmation and audit, retain existing ban management.
- [x] UI: reuse existing table/filter/action/form/tab components, capability-gate new features,
  maintain desktop/mobile layouts and translations.
- [x] Installer/nginx: test new upstream template, preserve internal endpoints at `/` and `/ng/`,
  respect upstream body limits and trusted client-IP handling.
- [x] Update API contract, vendored UI contract, documentation and compatibility matrix.
- [x] Run functional/contract/static/build checks; commit and push the completed changes.

Release publication, if requested, additionally requires fresh sequential full Codex Security
scans of both final application commits, following the root AGENTS.md rule.

## Validation (not a release handoff)

The initial full API runs executed 10,013 tests on schema `02112026_1600` and 10,015 on
`18082026_1200`. They exposed old fixture/representation expectations and compatibility
issues, which were corrected and checked through targeted regression runs. The initial
runs are not recorded as clean full-suite passes. The November follow-up exercised 946
cases; its only remaining failure was the monitoring-operation count (42 → 43), fixed
and checked separately. The corresponding older-schema 946-case run passes, as does the
684-case older-schema feature regression. The final 36 older-schema impersonation, access, public-config
and legacy webmail checks also pass. CI runs each complete suite again from the commit.

Combined full-run and follow-up contract coverage reaches all 409 registered operations on
November, and all 380 applicable operations on September/October. The 29 November-only
operations are capability-gated; their refusal behavior is also tested on the older schema.

The complete UI unit run covered 610 files / 4,873 tests: 4,866 passed. Five expectations
needed updates for permission options, audit events and unban-all; the regression run now
passes (189 tests / 36 files), followed by 427 audit/i18n cases. The unrelated sender-field
timing failure passed on rerun; a 50ms performance assertion remains outside the requested
scope. English, Czech and Slovak have no missing contract labels (738 keys).

Both production images build; API liveness and nginx configuration checks pass. PHP static
analysis, formatting, selected UI lint/type checks, OpenAPI lint and compatibility checks
pass. The compatibility check retains closed response enums and explicitly refuses removal
of existing audit codes while allowing additions. Semgrep's pinned source checks complete
with zero findings in both repositories (15 API rules, 12 UI rules); these are limited static
checks, not the mandatory pre-release Codex Security scans.

Installer validation passes 35 unit tests and eight Ansible fixture scenarios, including
idempotence, root and `/ng/` activation, and preserved keys. Both upstream nginx templates
(2026-09a and 2026-11) preserve 33 legacy routes and four internal listeners, body limits,
rollback, trusted client-IP handling and callback-log suppression. Webmail SSO hand-off
assumptions hold for upstream 2026-11. No production server was changed.

Implementation commits pushed to `main`: API `cd9a5acc256d4aefa8257ba19ddb774602652b5e`,
UI `b0992c4b`, installer `a43c8a58`. The UI contract is pinned to that exact API implementation
commit; regeneration, type checks, translations and the final production build pass. No release tags, release image promotion, server deployment or release
security scan has run for this implementation. Release publication still requires the two
fresh full subscription scans in AGENTS.md.
