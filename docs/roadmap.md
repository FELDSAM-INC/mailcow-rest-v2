# mailcow-rest-v2 roadmap

## Architecture

```
              ┌──────────────── nginx-mailcow ────────────────┐
 browser ───► │ /ng/        → mailcowui-ng static assets       │
 automation ► │ /api/v2/    → mailcow-rest-v2 (this repo)      │──► MariaDB, Redis, dockerapi,
              │ /api/v1, legacy UI, internal endpoints         │    Rspamd, doveadm, memcached
              │             → php-fpm-mailcow (unchanged)      │
              └────────────────────────────────────────────────┘
```

The UI and the API share one origin, so the browser can use a cookie session with CSRF
protection. The exact routing (an nginx include versus the API container proxying for itself)
is decided in the plan of feature 001.

## Features (one Spec Kit feature each)

The matching mailcowui-ng feature starts once this repo has merged the contract for that
feature. The UI can build against a mock server generated from the contract before the
implementation is finished.

| # | Feature | Replaces in legacy |
|---|---|---|
| 001 | Authentication, sessions, API keys, identity, branding config | Login for all roles with existing 2FA, `X-API-Key` table, `UI_*` customisation reads |
| 002 | Domains | Domain CRUD, limits, quotas, tags, domain templates |
| 003 | Mailboxes | Mailbox CRUD, quota, protocol access, password, ACL flags, custom attributes, rename, mailbox templates |
| 004 | Aliases, alias domains, resources | Aliases, alias domains, sender ACL / send-as, resources |
| 005 | Mailbox self-service | Password, 2FA/FIDO2 enrolment, app passwords, temporary aliases, recovery e-mail, user settings |
| 006 | Quarantine & spam policy | Quarantine items, release/learn, notifications, spam score, allow/block lists |
| 007 | Domain administrators | Domain-admin accounts, `da_acl`, login-as |
| 008 | Sync jobs | imapsync jobs |
| 009 | Routing & address rewriting | Relayhosts, transports, TLS policy maps, BCC maps, recipient maps, forwarding hosts |
| 010 | DKIM, MTA-STS, DNS | DKIM keys (incl. ISPConfig DNS push), MTA-STS, DNS diagnostics |
| 011 | Monitoring & operations | Container status/control, logs, mail queue, fail2ban, host/vmail stats |
| 012 | Identity providers & OAuth2 | Keycloak/OIDC/LDAP configuration and login, OAuth2 clients |
| 013 | System settings | Branding, UI texts, password policy, quota notifications, Rspamd settings maps, global filters, CORS, mass mail |
| 014 | Internal endpoints (optional) | Taking over php-fpm internal endpoints, each with a parity suite |

## Input for the first `/speckit-specify`

```
/speckit-specify Authentication, sessions, API keys, identity and branding configuration for
mailcow REST API v2: a standards-compliant REST API (base path /api/v2) for an existing
mailcow-dockerized server. It runs next to the legacy mailcow UI and /api/v1, which keep working
unchanged, and it is the backend of the separate mailcowui-ng web app.

Actors: server administrators, domain administrators, mailbox users, and automation clients
(billing systems, scripts). Users already exist in the mailcow database with password hashes,
and some have TOTP, WebAuthn or Yubi OTP second factors enrolled.

User stories:
1 (P1) A browser client signs a user in with username and password. If the account has second
  factors, the client completes one of them. The client then holds a session and can sign out.
  Admins, domain admins and mailbox users all sign in this way. The order in which legacy
  resolves account types, its throttling and its fail2ban reporting are preserved.
2 (P1) A signed-in client asks "who am I" and gets the role, the scope (for a domain admin, the
  managed domains; for a mailbox user, the mailbox), the effective ACL flags and the user's
  preferences (language, theme). The client can update the preferences.
3 (P1) An administrator creates, lists, inspects and revokes API keys. Each key has a name, an
  owner, a read-only or read-write scope, an optional expiry and optional allowed source
  networks. The secret is shown only once. An automation client authenticates with the key in
  the X-API-Key header and gets RFC 9457 errors when the key is missing, invalid, expired,
  revoked, out of scope or used from a disallowed network. Invalid keys are reported to fail2ban.
4 (P2) Any client, before sign-in, reads the public branding configuration (product name, logo,
  colours, enabled languages, login page texts). It is taken from the existing mailcow
  customisation settings, so the operator configures it in one place.
5 (P2) Every sign-in, failed sign-in, sign-out and API key change is recorded in an audit log
  that administrators can list with filters.

Out of scope: managing any mail objects, enrolling new second factors, identity-provider
(OIDC/LDAP/Keycloak) login, replacing legacy internal endpoints, and the legacy /api/v1 keys
(they keep working in legacy).

Constraints: existing accounts, password hashes and second factors MUST keep working unchanged
in both implementations. See docs/legacy-inventory.md (Auth and sessions, Public REST API) for
current behaviour.
```

## Legacy surface allocation

Every legacy capability belongs to exactly one feature. A feature spec MUST cover everything
allocated to it, with a coverage table in the spec that maps each legacy action or form handler
to requirements. Actions are `/api/v1` names unless marked *form*. Fork-only (FeldHost)
capabilities are included and configurable.

| # | Allocated legacy surface |
|---|---|
| 001 | Login for all roles, 2FA verification step, FIDO2/passkey login, registration (`fido2-registration`), rename (`edit/fido2-fn`) and removal of own passkeys, logout, session, *form* API key management, identity, branding read (`UI_*`, `CUSTOM_LOGIN`, logos, languages) |
| 002 | `domain` add/get/edit/delete, `domain/template` add/get/edit/delete, `edit/domain/footer`, `delete/domain/tag`, domain quotas and limits, relay/backup MX flags, GAL, domain tags |
| 003 | `mailbox` add/get (all, reduced, id)/edit/delete, `mailbox/template`, `edit/mailbox/custom-attribute`, `edit/rename-mbox`, `edit/user-acl`, `delete/mailbox/tag`, quota, protocol access, admin-set password, forced password change and forced 2FA flags |
| 004 | `alias`, `alias-domain`, `resource` add/get/edit/delete, sender ACL / send-as (`extend_sender_acl`, external sender addresses) |
| 005 | Account self-service for every role: own password (`edit/self`, user password), forced password change, password reset via recovery e-mail (*form* `pw_reset_request`/`pw_reset`), recovery e-mail, TOTP/WebAuthn/Yubi OTP enrolment and removal (`webauthn-tfa-registration`, *form* `set_tfa`/`unset_tfa_key`), forced 2FA setup, app passwords (`app-passwd`), temporary aliases (`time_limited_alias`), mailbox sieve filters (`filter`, `filters`, `active-user-sieve`), Pushover (`edit/pushover`, `pushover-test`), tagging (`delimiter_action`), user TLS policy (`edit/tls_policy`), recent logins (`last-login`, `reset-last-login`), EAS cache and SOGo profile reset (`delete/eas_cache`, `delete/sogo_profile`), webmail SSO from the new web app |
| 006 | Quarantine items and actions (`quarantine`, `qitem`, release, learn, delete), quarantine settings and per-mailbox notification settings (`edit/quarantine*`), spam score (`spam-score`), Rspamd actions (`rspamd/actions`), allow/block lists (`domain-policy`, `mailbox-policy`, `policy_{wl,bl}_*`), rate limits (`rl-domain`, `rl-mbox`, `edit/ratelimit`, `delete/rlhash`) |
| 007 | Administrator accounts (`admin`), domain administrators (`domain-admin`), `edit/da-acl`, login-as / dual login, domain-admin SSO tokens (`sso/domain-admin`) |
| 008 | Sync jobs (`syncjob`, `syncjobs`) for admins, domain admins and users |
| 009 | Relayhosts (incl. test), transports (incl. transport check), relayhost assignment to domains and mailboxes, forwarding hosts (`fwdhost`), BCC maps (`bcc`, `bcc-destination-options`), recipient maps, TLS policy maps |
| 010 | DKIM (`dkim`, `dkim_duplicate`, `dkim_import`, ISPConfig DNS push — fork-only), MTA-STS (`mta-sts`), DNS diagnostics (`ajax/dns_diagnostics.php`) |
| 011 | Container status and control, host and vmail stats, version (`status/*`), all log types (`logs/*`), mail queue (`mailq`, `postcat`, flush, hold, delete), fail2ban settings and ban list (`fail2ban`, `edit/fail2ban`), Rspamd history and stats |
| 012 | Identity providers (`identity-provider` incl. test, Keycloak, generic OIDC, LDAP, mapping, provisioning), IdP sign-in for the new web app, OAuth2 clients (`oauth2-client`) |
| 013 | Customisation writes (*form* logos, `edit/ui_texts`, `edit/app_links`, `edit/custom_login`, `edit/ip_check`), branding colours (new, API-owned), password policy (`passwordpolicy`), password reset notification template (`edit/reset-password-notification`), quota notifications (`quota_notification`, `_bcc`), Rspamd settings maps (`rsetting`, `edit/rspamd-map`), *form* Rspamd UI password, global filters (`global-filter`, `global_filters`), CORS (`edit/cors`), *form* mass mail, presets (`presets`), *form* license/GUID verification |
| 014 | Optional: taking over php-fpm internal endpoints, each with a parity suite. Decided by the owner after 013 |
