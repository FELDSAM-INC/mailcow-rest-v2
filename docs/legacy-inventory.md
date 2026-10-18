# Legacy mailcow web layer — inventory

Reference for parity checks in feature specs. Snapshot taken 2026-10-18 from the FeldHost fork
(`feldhost-202609a`, upstream tag `2026-09a`, `db_version = 18082026_1200`). Paths are relative
to the mailcow-dockerized repo's `data/` directory.

## Public REST API (`/api/v1`)

- nginx rewrites `^/api/v1/(.*)$` → `web/json_api.php?query=$1`
  (`conf/nginx/templates/sites-default.conf.j2`). The query is split into
  `action/category/object/extra` and dispatched through ~215 `switch` cases (2,091 lines).
- Verbs are in the URL: `get` (GET), `add` / `edit` / `delete` / `search` (POST only).
- Auth: `X-API-Key` header checked against table `api` (`access` ro|rw, `allow_from`,
  `skip_ip_check`). There is one rw key and one ro key, seeded from `API_KEY`,
  `API_KEY_READ_ONLY` and `API_ALLOW_FROM` (`Dockerfiles/phpfpm/docker-entrypoint.sh`). A valid key
  becomes a fake session user `API` with full **admin** role, with no scoping. A bad key → 401
  and is published to the fail2ban channel. Session cookie `MCSESSID` also works.
- `web/api/openapi.yaml` (OpenAPI 3.1, 6.2k lines) documents only 66 paths. Swagger UI is at `/api`.

### Standards violations to fix

- RPC-style URLs, POST for all writes, IDs passed in bodies instead of paths.
- The body shape differs per verb: `add` takes a flat object, `edit` takes `{"items":[…],"attr":{…}}`
  and `delete` takes a bare array.
- Writes always return `200` with an array of `{type: success|danger|warning, log:[…], msg}`. This
  leaks the internal call log, and generic failures use a different shape.
- GET returns an object for a single item and an array for `all`, but `{}` for an empty list.
  An unauthenticated GET → `200` with an empty body.
- `get/reset-last-login` mutates state. "Test" actions are `edit` calls.
- No pagination: `get/mailbox/all` is N+1. Only 400/401/403/404/405 are ever returned.
- A `Sec-Fetch-Dest` header other than `empty` → 403.
- CSRF token is verified only when `$_POST` is non-empty (`web/inc/sessions.inc.php`). JSON
  bodies populate `$_POST` after `session_check()` has run, so cookie-authenticated JSON writes
  skip the token check. SameSite=Lax and CORS preflight limit the exposure.
- Not exposed via API at all: API key management, Rspamd UI password, logo upload, mass mail,
  login/2FA/FIDO2 flows, quarantine quick release (form handlers in
  `web/inc/triggers.{admin,domainadmin,user,global}.inc.php`).

### Endpoint catalogue

- **add**: time_limited_alias, relayhost, transport, rsetting, mailbox[/template],
  domain[/template], resource, alias, filter, global-filter, domain-policy, mailbox-policy,
  alias-domain, fwdhost, dkim, dkim_duplicate, dkim_import, domain-admin, sso/domain-admin, admin,
  syncjob, bcc, recipient_map, tls-policy-map, app-passwd, mta-sts, oauth2-client,
  fido2-registration, webauthn-tfa-registration
- **get**: domain, mailbox (all|reduced|id|template), alias, alias-domain, resource, syncjobs,
  filters, active-user-sieve, bcc, bcc-destination-options, recipient_map, tls-policy-map,
  relayhost, transport, rsetting, rl-domain, rl-mbox, policy_{wl,bl}_{mailbox,domain},
  time_limited_aliases, spam-score, app-passwd, oauth2-client, domain-admin, admin, dkim, fwdhost,
  fail2ban, quarantine, mailq, postcat, global_filters, passwordpolicy, presets, rspamd/actions,
  last-login, reset-last-login, identity-provider, status/{containers,container,vmail,host,version},
  logs/{dovecot,postfix,sogo,netfilter,ratelimited,cron,autodiscover,ui,sasl,watchdog,acme,api,
  rspamd-history,rspamd-stats}
- **edit** (~50): the add objects plus pushover[-test], rspamd-map, fido2-fn, app_links,
  passwordpolicy, delimiter_action, tls_policy, quarantine*, qitem, quota_notification[_bcc],
  mailq, domain/footer, mailbox/custom-attribute, rename-mbox, user-acl, da-acl, spam-score,
  fail2ban[/banlist], ui_texts, ip_check, custom_login, self, cors, identity-provider[-test],
  reset-password-notification
- **delete**: the add objects plus qitem, mailq, domain/tag, mailbox/tag, eas_cache,
  sogo_profile, rlhash, identity-provider

## Features by role

- **Admin**
  - Dashboard: container start/stop/restart, host stats, vmail disk usage, 13 log tabs.
  - Mail setup: domains, domain/mailbox templates, mailboxes, resources, alias domains, aliases,
    sync jobs, sieve filters, BCC maps, recipient maps, TLS policy maps.
  - Domain settings: quotas and limits, relay/backup MX, GAL, relayhost, ratelimit,
    allow/block lists, footer, MTA-STS, quota-warning BCC, tags.
  - Mailbox settings: protocol access, user ACL, custom attributes, Pushover, rename, ratelimit,
    sender ACL, forced password change and forced 2FA.
  - System: admins, domain admins, API keys, identity provider (Keycloak, generic OIDC, LDAP with
    attribute mapping, provisioning and sync), OAuth2 clients, Rspamd UI password, routing
    (relayhosts, transports, transport check), DKIM, forwarding hosts, fail2ban, quarantine
    settings, quota notifications, Rspamd settings maps, customisation (logos, UI texts, app
    links, login page, IP check), password policy and reset templates, system/mass mail, global
    regex filters, CORS.
  - Queue: Postfix mail queue (list, flush, delete, postcat).
  - Login-as (dual login) and domain-admin SSO tokens.
- **Domain admin**: the mailbox page, gated by `da_acl` (20 flags, e.g. login_as, ratelimit,
  spam_policy, protocol_access, relayhost, alias_external_goto). Also own password,
  TOTP/WebAuthn/Yubi OTP, FIDO2 keys, and DNS diagnostics (`web/inc/ajax/dns_diagnostics.php`).
- **Mailbox user**, gated by `user_acl` (15 flags):
  - Auth: password, 2FA, FIDO2, webmail SSO, Apple mobileconfig, recent logins, recovery email.
  - Send-as details and settings: tagging, TLS in/out, quarantine notifications, EAS/SOGo reset.
  - Temporary aliases, spam filter, sync jobs, app passwords, Pushover, quarantine.
- **Fork-only (FeldHost)**: ISPConfig DKIM DNS push (`web/inc/lib/ispconfig.php`), FeldHost
  branding.
- **No UI today**: ARC (reuses the DKIM Redis keys) and full-text search.

## Backend dependencies

- **MariaDB** over a unix socket, using PDO.
- **Redis**: PHP sessions plus ~60 keys, e.g. `F2B_*`, `Q_*`, `QW_*`,
  `DKIM_{PRIV_KEYS,PUB_KEYS,SELECTORS}`, `RL_VALUE`, `DOMAIN_MAP`, `*_LOG`, `UI_*`,
  `CORS_SETTINGS`, `PASSWD_POLICY`, `WHITELISTED_FWD_HOST`. netfilter is driven only via Redis.
- **dockerapi** (`https://dockerapi:443`, no auth): container control and exec of mailq,
  doveadm ACL, maildir cleanup/move, sieve, `sogo rename_user`, rspamd worker password, df.
- **Rspamd controller socket** `/var/lib/rspamd/rspamd.sock`: stat, history, actions, learning.
- **Memcached**: flushed after SOGo-relevant changes.
- **Files**: `/global_sieve/{before,after}` and `/rspamd_custom_maps` are written; templates
  are read from `/tpls`.
- **External**: `verify.mailcow.email`, ISPConfig, Pushover, Keycloak/OIDC/LDAP.
- **ofelia** runs `/crons/{keycloak,ldap}-sync.php` every minute.
- **Business logic** lives in `web/inc/functions.*.inc.php`:
  - `mailbox` (6.4k lines): domains, mailboxes, aliases, resources, syncjobs, filters,
    templates, MTA-STS, footers, tags.
  - `inc` (3.9k lines): hashing, ACL helpers, 2FA/FIDO2, logs, IdP, reset, CORS, SOGo view.
  - `auth`, `acl`, `admin`, `domain_admin`, `app_passwd`, `oauth2`.
  - `address_rewriting`, `transports`, `tls_policy_maps`.
  - `dkim`, `fwdhost`, `fail2ban`, `policy`, `ratelimit`, `rspamd`, `quarantine`,
    `quota_notification`, `pushover`, `customize`, `mailq`, `docker`, `presets`.

## Internal endpoints other containers depend on (stay in php-fpm)

| Endpoint | Consumer |
|---|---|
| nginx:8081 `settings.php`, `aliasexp.php`, `bcc.php`, `footer.php` | Rspamd Lua dynmaps |
| nginx:8081 `forwardinghosts.php` | Rspamd greylisting, Postfix `whitelist_forwardinghosts.sh` |
| nginx:9081 `pipe.php`, `pipe_rl.php`, `pushover.php` | Rspamd metadata exporter (quarantine, ratelimit, notifications) |
| nginx:9082 `mailcowauth.php` | Dovecot passdb (`passwd-verify.lua`) — **every IMAP/POP/SMTP-SASL login**; bind-mounts `web/inc/functions{,.auth,.mailbox,.ratelimit,.acl}.inc.php` |
| `sogo-auth.php` (nginx `auth_request`) | SOGo, EAS and DAV SSO via `X-User`/`X-Auth` headers |
| `autodiscover.php`, `autodiscover-json.php`, `autoconfig.php`, `mobileconfig.php` | Mail clients |
| `/.well-known/mta-sts.txt` → `mta-sts.php` | Remote MTAs |
| `qhandler.php`, `f2b-banlist.php`, `/cache/*` → `resource.php`, `_rspamderror.php` | Users, external |
| php-fpm 9001/9002, nginx:8081 | watchdog health checks, acme readiness |

## Database schema

- `web/inc/init_db.inc.php` declares 56 tables and 6 views and converges them on php-fpm start
  (master only). The version is written to `versions.db_schema`.
- SOGo and Dovecot block at startup until `versions.db_schema` matches the version grepped from
  `init_db.inc.php` (`Dockerfiles/sogo/bootstrap-sogo.sh`). **php-fpm must stay in the stack.**
- Direct readers of the tables:
  - Postfix: 20 `mysql_*.cf` maps over mailbox, alias, alias_domain, domain, relayhosts,
    transports, bcc_maps, recipient_maps, sender_acl, spamalias, tls_policy_override.
  - Dovecot: userdb `mailbox`, `quota2`, the sieve views, `imapsync`, `quarantine`, `user_acl`.
  - SOGo: `_sogo_static_view` (denormalised, and PHP must keep it in sync) and `sogo_*`.

## Auth and sessions

- PHP sessions in Redis (`MCSESSID`, SameSite=Lax, 3 h lifetime, user-agent pinning).
- Login tries admin → domain admin → app password → mailbox user. The pending 2FA step is
  held in the session.
- 2FA: TOTP, Yubi OTP, WebAuthn. FIDO2 passwordless login.
- Identity providers: Keycloak, generic OIDC, LDAP (`authsource=ldap` mailboxes authenticate
  against LDAP).
- SOGo SSO through `sogo-auth.php`. Dual login for admin/domain-admin impersonation.
- Password hashes: `{BLF-CRYPT}` by default (`MAILCOW_PASS_SCHEME`) or SSHA/SSHA256/SSHA512.
  Verification also accepts ARGON2*, *-CRYPT, MD5, PLAIN, LDAP-MD5, PBKDF2(-SHA512).

## i18n

- `web/lang/lang.<locale>.json`: nested JSON with 23 sections and 1,382 keys in en-gb, in 33
  languages. cs and sk are complete. Backend messages are i18n keys.

## Frontend today

- Twig 3 server rendering (79 templates, 10.9k lines), jQuery 3.6, Bootstrap 5.1 (Lumen theme
  plus custom dark mode), DataTables 1.13 Responsive, Chart.js 3.7, bootstrap-select. Assets are
  minified at runtime in PHP.
- Own JS is about 8.1k lines; vendor JS about 36k.
- Mobile problems: `user-scalable=0`, huge modals (`modals/mailbox.twig` is 1,349 lines), 47
  DataTables relying on row collapse, collapsed cards used as tabs, only 14 `@media` rules.
