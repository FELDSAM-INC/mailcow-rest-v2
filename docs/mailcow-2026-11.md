# mailcow 2026-11 support

This implementation targets upstream tag `2026-11`, commit
`88fbaa459a5cd5843c2c7cb06ee2928e2a78b9e6`, and database schema `02112026_1600`.
The `2026-09a` / `2026-10` schema (`18082026_1200`) remains supported. New controls
are enabled by server capabilities; updating this API does not migrate mailcow's database.
Run the ordinary upstream mailcow update separately.

These changes are on `main`. Community Preview 5 and earlier do not support the
November schema; an existing pinned installation needs the updated API and UI images.
A new preview release has not been published by this implementation.

## New administration controls

| Feature | API | UI |
| --- | --- | --- |
| Mailbox groups | `/mailbox-groups`, `/mailboxes/{mailbox}/groups` | Email configuration → Mailboxes → Groups |
| Shared mailboxes | `shared` in mailbox create/edit/read | Shared-mailbox option and membership details |
| Identity synchronization | `/identity-provider/synchronisation`, `/runs`, `/links/{mailbox}` | Access → Identity provider → Synchronization |
| Sync sources | `/sync-sources`, source OAuth connection endpoints | Sync jobs → Sources |
| Sync queue | `/sync-queue/settings`, `prioritize` bulk action | Sync jobs → Queue; priority in job forms/lists |
| Legacy API keys | `/legacy-api-keys` | Access → API v1 keys |
| Unban all | `/fail2ban/active-bans/lift-all` | Fail2ban ban controls, with confirmation |

The OpenAPI contract served at `/api/v2/docs` documents exact paths, request bodies,
role restrictions, and version headers.

Groups support distribution addresses, manual and identity-managed membership, send-as,
IMAP permissions, SOGo calendar/address-book permissions and subscriptions, and mailbox
templates with priorities. Domain administrators need the mailbox-groups permission;
foreign-domain grants can make a group read-only to them. Identity-managed membership
remains managed by the provider. External Dovecot/SOGo work may complete asynchronously;
the UI reports pending work and errors instead of claiming success prematurely.

Shared mailboxes have no independent sign-in, passkey sign-in, or direct webmail SSO.
Administrators may still use the existing impersonation workflow. Group template application
preserves manual suspension and identity-provider offboarding. Changing group/template
membership uses the effective template with the highest priority; leaving all templates does
not undo the mailbox's current settings.

Identity synchronization exposes LDAP/AD and Keycloak group settings, offboarding limits,
preview/run requests, conflicts and recent runs. A forced run requires explicit confirmation.
The upstream worker executes the requested run. Stable provider identities prevent an email
address reassignment from silently taking over an existing linked mailbox.

## Sync-source migration and OAuth

Existing REST v2 clients may still create jobs with `remoteHost`, `remotePort`, and
`remotePassword`; on 2026-11 the API creates a private source automatically. Clients may
instead send `sourceId`. Reads retain the familiar connection fields and add source metadata,
priority, and migration information. The legacy `authMechanism: plain` compatibility field remains unchanged;
`sourceAuthMechanism` identifies the actual authentication method on 2026-11. Unlike upstream's
legacy API change, v2 clients do not need to switch all existing integrations to source IDs immediately.

Sources can be private, available to selected mailboxes/domains, or available globally.
New connections verify TLS certificates by default. Changing a job's source or password
endpoint requires a newly entered password. A source with password-bearing jobs cannot
redirect those saved credentials through an endpoint change or weaker TLS settings: create
another source and select it on each job. Metadata changes remain available.

For Microsoft 365 or Google OAuth, select a provider preset, enter your tenant/client settings,
and register the callback URI displayed on the source at the provider. The URI follows the
configured host and supports `/ng/` and root UI routing. Provider presets do not create or
consent to applications at Microsoft or Google.

- **Authorization code:** connect the remote account for the selected destination mailbox,
  complete provider consent in the new tab, then refresh/select that account on the job form.
  Tokens belong to the source, destination mailbox, and remote username together. Sharing a
  source does not share another mailbox's tokens.
- **Client credentials:** a manager configures the application credentials and shared token.
  The manual refresh action reports status; normal renewal is handled by upstream mailcow.

OAuth state is session-bound, expires after ten minutes, uses PKCE, and is single-use.
Source changes invalidate an outstanding connection. Tokens and client secrets are never
returned by reads or written to audit details. Non-administrator OAuth endpoints must resolve
to public addresses; requests pin the resolved address, verify TLS, and refuse redirects.

The new runner stores custom options as typed JSON pairs. The UI uses the server allowlist
and displays migration leftovers as inert text. Unconverted legacy options are ignored by
upstream; add only supported options that are still needed. Execution and credential-disclosure
options remain blocked even when configured in an allowlist.

## Legacy keys and networking

Legacy `/api/v1` keys are separate from REST v2 keys. They grant global administrator
access with read-only/read-write and IP restrictions; they are not tied to a mailbox or
domain administrator. Only administrator browser sessions can create, edit, regenerate,
or delete them. A newly generated value is shown once. Keys marked as service keys are
managed in `mailcow.conf`; the UI cannot change or delete them.

The installer preserves mailcow's internal endpoints and supports either UI URL mode.
On 2026-11 it uses upstream's trusted `$mailcow_client_ip` map; it never trusts an external
`X-Real-IP` header directly. The new upstream 64 MiB request limit and configurable SOGo/DAV/
ActiveSync limit remain in effect. REST v2 retains its existing tighter 5 MiB limit and 16 MiB
logo-upload limit. OAuth/reset callback URLs are excluded from proxy access/error logging.

## Validation and releases

CI discovers both SQL fixtures and runs the contract-validated API suite against each.
Installer integration tests exercise both upstream nginx templates, preserved legacy routes,
internal listeners, root-mode activation and rollback, client-IP spoof resistance, and callback
log protection. Functional UI tests cover version conflicts, scoped read-only controls,
credential re-entry, and one-time-secret handling.

The compatibility check permits additions to audit event and target names, matching the published
requirement that clients tolerate unknown events. Removing existing codes still fails the check. Other response enums remain closed; new sync
authentication modes use the additional `sourceAuthMechanism` field.

Release publication additionally requires the fresh sequential full-repository Codex Security
scans of both final application commits specified in [AGENTS.md](../AGENTS.md).
