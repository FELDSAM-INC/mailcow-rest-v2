# mailcow REST API v2

A standards-compliant REST API for [mailcow-dockerized](https://github.com/mailcow/mailcow-dockerized),
served at `/api/v2`. It runs next to the legacy mailcow UI and `/api/v1`, which keep working
unchanged, and it is the only backend of the separate [mailcowui-ng](https://github.com/FELDSAM-INC/mailcowui-ng) web app.

The OpenAPI 3.1 contract in [`api/`](api/openapi.yaml) is the source of truth. A running server
serves it at `/api/v2/openapi.yaml` and renders it at `/api/v2/docs`. Problem types are documented
in [`docs/problems.md`](docs/problems.md) and served at `/api/v2/problems/{name}`.

The current implementation includes the feature areas 001–013: authentication and account
self-service; domains, mailboxes, aliases and resources; quarantine and spam policy;
administrators; sync jobs; routing; DKIM, MTA-STS and DNS diagnostics; operations and logs;
identity providers and OAuth2; and system settings. This is broader than the original Feature 001
release. See the [roadmap](docs/roadmap.md) for the scope map,
[`tests/feature-registry.json`](tests/feature-registry.json) for registered operations, and each
feature's `specs/NNN-*/tasks.md` and `quickstart.md` for remaining work and validation evidence.
Registration is not a claim that every release or manual acceptance check has passed.

For contributors, start with [Development](#development), the
[constitution](.specify/memory/constitution.md) and [conventions](docs/conventions.md).
Deployment is separate from local development; see [Installation](#installation) and
[Deploying a committed revision to maildev](#deploying-a-committed-revision-to-maildev).

## Supported mailcow releases

Use **mailcow `2026-09a` or newer**, including **`2026-10`**.
Older releases remain untested. `2026-09` has the same schema definition as `2026-09a`
and `2026-10`; releases through `2026-07b` currently fail the database schema readiness
check and lack the `da_acl.alias_external_goto` column used by domain-administrator
permission queries and writes. See the [schema history](https://github.com/FELDSAM-INC/mailcow-modern-ui/blob/main/docs/compatibility.md#schema-history).

| mailcow-dockerized | `versions.db_schema` | Schema fixture |
|---|---|---|
| `2026-09a` (and the FeldHost fork `feldhost-202609a`) | `18082026_1200` | `tests/fixtures/schema/2026-09a.sql` |
| `2026-10` | `18082026_1200` | Same accepted schema; live installation verified on `2026-10` |

Compatibility is checked by database schema rather than an exact mailcow release name.
The readiness check (`/api/v2/health/ready`, `restv2:health --ready`) fails on any other schema
version. A future upstream release that changes the schema needs validation against that schema
(`scripts/schema/generate-fixture.sh <tag>`, then add its `db_version` to
`config/restv2.php` `supported_schema_versions`).

## Architecture

- **One container** (`ghcr.io/feldsam-inc/mailcow-rest-v2`): Laravel 13 on PHP 8.4 under php-fpm,
  as the non-root user `www-data`, with a read-only root filesystem. It joins mailcow through
  `docker-compose.override.yml`; no other mailcow container or file is patched.
- **Routing**: nginx-mailcow reaches it over FastCGI through mailcow's `site.*.custom` include
  (`deploy/nginx/site.restv2.custom`), which also proxies `/ng/` to the mailcowui-ng container.
  The client address is nginx-mailcow's `REMOTE_ADDR`; no `X-Forwarded-*` header is trusted.
- **Data**: mailcow's MariaDB and Redis. Upstream tables are never migrated; the API reads them and
  writes only through services in `app/Services/`, reproducing legacy's side effects (second-factor
  re-activation, `sasl_log` recent logins, `fido2` passkeys, the fail2ban channel). The API's own
  tables and Redis keys have the `restv2_` and `restv2:` prefixes respectively; only API-owned
  tables have migrations in [`database/migrations/`](database/migrations).
- **Sessions** are Redis sessions with a `__Host-` cookie and CSRF protection on every
  state-changing request. **API keys** are stored as SHA-256 hashes, act as their owner and can be
  read-only, expiring and network-restricted.
- **Configuration** comes from environment variables with `mailcow.conf` names; legacy PHP settings
  are read from mailcow's mounted `data/web/inc/vars{,.local}.inc.php`, and every one of them can be
  overridden with a `RESTV2_<NAME>` setting.

## Installation

**Start here:** [Install the API and new UI, one command at a time](docs/installation.md).
The guide uses prebuilt public GHCR images on an existing mailcow server; no local build is needed.
See [security scans and image publishing](docs/ci-and-images.md) for CI setup, tags and package visibility.

On a mailcow-dockerized host (see also `specs/001-auth-sessions-api-keys/quickstart.md` Part C):

1. Merge [`deploy/docker-compose.override.yml`](deploy/docker-compose.override.yml) into the
   `docker-compose.override.yml` of your mailcow directory.
2. Add to `mailcow.conf` (or keep these settings in `restv2/restv2.env`, see below):
   - `RESTV2_APP_KEY=base64:…`, the output of
     `docker compose run --rm restv2-mailcow php artisan key:generate --show`;
   - for the FeldHost fork: `RESTV2_SASL_LOG_SERVICE=WEBUI`,
     `RESTV2_DEFAULT_UI_NAME=FeldHost™ Mailhosting UI`,
     `RESTV2_DEFAULT_LOGO_URL=/img/feldhost_logo.png` and
     `RESTV2_DEFAULT_LOGO_DARK_URL=/img/feldhost_logo_white.png`.
3. Copy [`deploy/nginx/site.restv2.custom`](deploy/nginx/site.restv2.custom) to
   `data/conf/nginx/site.restv2.custom`.
4. Start the API with `docker compose up -d --no-deps restv2-mailcow`. Without `--no-deps`,
   Compose may also start or recreate dependencies such as netfilter.
5. Load the nginx snippet with
   `docker exec <nginx container> nginx -t && docker exec <nginx container> nginx -s reload`
   (`<nginx container>` is the output of `docker compose ps -q nginx-mailcow`). The reload happens
   only when the test passes.
6. Restart ofelia with `docker compose restart ofelia-mailcow`. `mcuadros/ofelia` reads container
   labels only when it starts, so the audit retention job (`restv2:audit-purge`) is not scheduled
   until then. Check it with `docker compose logs ofelia-mailcow | grep restv2`.
7. Check `docker compose ps restv2-mailcow` (healthy) and
   `curl -s https://$MAILCOW_HOSTNAME/api/v2/health/ready`.

**Alternative to editing `mailcow.conf`**: keep the API's own settings (`APP_KEY`, `RESTV2_*`) in
`./restv2/restv2.env` (mode 600) with a `restv2/.gitignore` containing `*`. mailcow's `update.sh`
runs `git add -A` on its merge-conflict path, which would commit a top-level secrets file. Then
uncomment `env_file: ./restv2/restv2.env` in the override and remove its `APP_KEY` and `RESTV2_*`
`environment` lines (`environment` wins over `env_file`). When the image is loaded locally
(`docker save | docker load`) instead of pulled from a registry, set `pull_policy: never` on the
service.

The container applies its own migrations at start (`migrate --isolated`, only `restv2_` tables).
mailcow's ofelia scheduler runs the audit retention purge daily through the container's labels
(after the restart of step 6).

## Configuration

`mailcow.conf` variables used as they are: `MAILCOW_HOSTNAME`, `ADDITIONAL_SERVER_NAMES`, `TZ`,
`DBNAME`, `DBUSER`, `DBPASS`, `REDISPASS`, `REDIS_SLAVEOF_IP`, `REDIS_SLAVEOF_PORT`,
`IPV4_NETWORK`, `IPV6_NETWORK`, `WEBAUTHN_ONLY_TRUSTED_VENDORS`, `SKIP_SOGO` and
`ALLOW_ADMIN_EMAIL_LOGIN` (feature 007: administrators, and domain administrators with the
`loginAs` permission, may open a mailbox's webmail on the user's behalf; `y` or `yes` enables it,
default `n`, as in legacy `sogo-auth.php`). `DBHOST` (default `mysql-mailcow`) and `REDISHOST`
(default `redis-mailcow`) locate the services.

An empty or whitespace-only value counts as unset and takes the default listed here, so the
`NAME=${NAME:-}` lines of the deploy example are safe. The only exception is
`RESTV2_MAILCOW_INC_DIR`, where empty means "not mounted".

Settings of this API:

| Variable | Default | Meaning | FeldHost |
|---|---|---|---|
| `RESTV2_APP_KEY` | (required) | Laravel application key (sessions, version tags) | |
| `RESTV2_SASL_LOG_SERVICE` | `MAILCOWUI` | Service label of the recent-login entries of web sign-ins | `WEBUI` |
| `RESTV2_DEFAULT_UI_NAME` | `<MAILCOW_HOSTNAME> - mail UI` | Default page title and product name | `FeldHost™ Mailhosting UI` |
| `RESTV2_DEFAULT_LOGO_URL` | `/img/cow_mailcow.svg` | Default light logo | `/img/feldhost_logo.png` |
| `RESTV2_DEFAULT_LOGO_DARK_URL` | `/img/cow_mailcow.svg` | Default dark logo | `/img/feldhost_logo_white.png` |
| `RESTV2_SIGNIN_MAX_FAILURES` | `10` | Failed sign-ins (and failed second factors) per username and address before `429` | |
| `RESTV2_SIGNIN_FAILURE_WINDOW` | `900` | Throttling window in seconds | |
| `RESTV2_CHALLENGE_TTL` | `300` | Lifetime in seconds of pending sign-ins, WebAuthn challenges and registration options | |
| `RESTV2_AUDIT_RETENTION_DAYS` | `365` | Audit retention | |
| `RESTV2_YUBICO_VERIFY_URL` | `https://api.yubico.com/wsapi/2.0/verify` | Yubico OTP verification service | |
| `RESTV2_MAILCOW_INC_DIR` | `/mailcow/web/inc` | Mount point of mailcow's `data/web/inc` (read-only) | |

Legacy PHP settings, read from mailcow's `vars.inc.php` and `vars.local.inc.php` when mounted, with
the upstream default otherwise; a non-empty `RESTV2_<NAME>` always wins (arrays as JSON):
`RESTV2_SESSION_LIFETIME`, `RESTV2_DEFAULT_LANG`, `RESTV2_AVAILABLE_LANGUAGES`,
`RESTV2_FIDO2_UV_FLAG_REGISTER`, `RESTV2_FIDO2_UV_FLAG_LOGIN`, `RESTV2_FIDO2_USER_PRESENT_FLAG`,
`RESTV2_FIDO2_FORMATS`, `RESTV2_WEBAUTHN_UV_FLAG_LOGIN`, `RESTV2_WEBAUTHN_USER_PRESENT_FLAG`,
`RESTV2_OTP_LABEL`, `RESTV2_MAILCOW_APPS`, `RESTV2_TAGGING_LIMIT` (25), `RESTV2_DOCKER_TIMEOUT`
(60 seconds), `RESTV2_SHOW_LAST_LOGIN` (`true`), `RESTV2_MAILBOX_DEFAULT_ATTRIBUTES` (merged over the
upstream defaults of new mailboxes, feature 003) and `RESTV2_ALIAS_DISABLE_EXTERNAL_DOMAINS`
(`false`; `true` refuses external alias destinations for every role, administrators included,
feature 004).

Domains (feature 002) and the DKIM keys of new domains (feature 010's DKIM core) also use
`COMPOSE_PROJECT_NAME` from `mailcow.conf` (to find mailcow's containers) and these settings:

| Variable | Default | Meaning | FeldHost |
|---|---|---|---|
| `RESTV2_DOCKERAPI_URL` | `https://dockerapi:443` | mailcow's container service, for the groupware restart and the move of a deleted domain's mail (TLS is not verified, as in legacy) | |
| `RESTV2_MEMCACHED_HOST`, `RESTV2_MEMCACHED_PORT` | `memcached`, `11211` | The groupware cache flushed after each groupware directory update | |
| `RESTV2_DEFAULT_DKIM_SELECTOR` | `dkim` | DKIM selector of new domains without one from the request or template | `feldhost` |
| `RESTV2_DKIM_DNS_PUBLICATION` | `n` | Publish the DKIM record of new keys to an ISPConfig DNS server | `y` |
| `RESTV2_ISPCONFIG_URL` | (empty) | ISPConfig JSON remote API, for example `https://ispconfig.example:8080/remote/json.php` (the fork's `soap_location` with `index.php` replaced by `json.php`) | set |
| `RESTV2_ISPCONFIG_USERNAME`, `RESTV2_ISPCONFIG_PASSWORD` | (empty) | ISPConfig remote user; the password is never logged or returned | set |
| `RESTV2_ISPCONFIG_DNS_SERVER_ID` | `1` | ISPConfig DNS server of new records | |
| `RESTV2_ISPCONFIG_VERIFY_TLS` | `y` | TLS verification of the ISPConfig endpoint | |
| `RESTV2_ISPCONFIG_TIMEOUT` | `15` | Seconds one publication may take | |

The publication counts as enabled only with `RESTV2_DKIM_DNS_PUBLICATION=y` and the URL, user and
password set. The API container must reach `dockerapi:443` and `memcached:11211` on
`mailcow-network` (it joins it through the override) and, for the publication, the ISPConfig
server. `RESTV2_FAKE_OUTBOUND=y` replaces dockerapi, memcached and ISPConfig with stand-ins in the
dev stack only (`APP_ENV=local`); it is ignored everywhere else.

Mailboxes (feature 003) also use these `mailcow.conf` variables: `MAILCOW_PASS_SCHEME` (hash scheme
of new passwords, `BLF-CRYPT` by default, or `SSHA`, `SSHA256`, `SSHA512`), `MAILDIR_GC_TIME`
(minutes the mail of a deleted mailbox stays in the garbage area, 7200), `CLUSTERMODE`
(`replication`: maildir moves are confirmed on this node and broadcast to every mail node) and
`MASTER` (`y`: usage figures from `quota2`, otherwise from `quota2replica`), and these settings:

| Variable | Default | Meaning | FeldHost |
|---|---|---|---|
| `RESTV2_BULK_LIMIT` | `100` | Targets per mailbox bulk action (1 to 100) | |
| `RESTV2_MAILBOX_CUSTOM_ATTRIBUTE_LIMIT` | `100` | Custom attributes per mailbox (1 to 100) | |

A mailbox rename or a bulk deletion can make several container calls of up to `DOCKER_TIMEOUT` each;
`deploy/nginx/site.restv2.custom` therefore allows FastCGI answers of up to 900 seconds.

Monitoring and operations (feature 011) also use `COMPOSE_PROJECT_NAME`, `SKIP_CLAMD`,
`SKIP_OLEFY` (hide the optional scanners) and `LOG_LINES` (in-memory logs keep `LOG_LINES` + 1
entries) from `mailcow.conf`, the Rspamd controller socket of the read-only `rspamd-vol-1` mount,
and these settings:

| Variable | Default | Meaning | FeldHost |
|---|---|---|---|
| `RESTV2_SELF_SERVICE` | `restv2-mailcow` | This API's own compose service, which can be neither stopped nor restarted through the API | |
| `RESTV2_PROTECTED_CONTAINERS` | `dockerapi-mailcow,mysql-mailcow,redis-mailcow,nginx-mailcow,restv2-mailcow` | Containers that cannot be stopped (they can be restarted) | |
| `RESTV2_CONTAINER_ROLES` | (empty) | JSON map of extra or overriding service => `ContainerRole` entries, for renamed services | |
| `RESTV2_POSTFIX_CONTAINER`, `RESTV2_DOVECOT_CONTAINER`, `RESTV2_BAN_SERVICE_CONTAINER` | `postfix-mailcow`, `dovecot-mailcow`, `netfilter-mailcow` | Containers the queue, the mail storage measurement and the rule reset use | |
| `RESTV2_UPDATE_CHECK` | `y` | Whether the update check asks the release source | `n` |
| `RESTV2_UPDATE_SOURCE_URL` | (empty: GitHub's API for the installation's owner and repository) | GitHub-compatible release source | |
| `RESTV2_UPDATE_STABLE_BRANCH` | `master` | The branch the update check runs on (other branches report it as disabled) | |
| `RESTV2_IP_CHECK_IPV4_URL`, `RESTV2_IP_CHECK_IPV6_URL` | `http://ipv4.mailcow.email`, `http://ipv6.mailcow.email` | mailcow's external IP check service, asked only when the IP check is enabled | |
| `RESTV2_RSPAMD_SOCKET` | `/var/lib/rspamd/rspamd.sock` | Rspamd's controller socket (shared with feature 006) | |
| `RESTV2_PYTHON` | `python3` | The interpreter that checks ban rules in the ban service's dialect (Python `re`; the image ships Alpine's `python3`, PSF-2.0) | |

Container and queue actions and the ban rule reset are synchronous and bounded by
`DOCKER_TIMEOUT` (60 seconds by default). nginx's `fastcgi_read_timeout` (900 s in
`deploy/nginx/site.restv2.custom`) covers them; when raising `DOCKER_TIMEOUT`, keep
`fastcgi_read_timeout` at least `DOCKER_TIMEOUT + 30` and any php-fpm `request_terminate_timeout`
at least `DOCKER_TIMEOUT + 40` (the image sets none).

Sync jobs (feature 008) use these settings:

| Variable | Default | Meaning | FeldHost |
|---|---|---|---|
| `RESTV2_IMAPSYNC_OPTIONS` | (empty: mailcow's vars files, else upstream's list) | Legacy `$IMAPSYNC_OPTIONS` as JSON, `{"whitelist": [...], "blacklist": [...]}`: the imapsync options custom parameters may use. `showpasswords` is never accepted, whatever the list says | |
| `RESTV2_SYNC_JOBS_CUSTOM_PARAMS_ADMIN_ONLY` | `n` | `y` lets only administrators set custom imapsync parameters (legacy: anyone with the `syncjobs` permission) | |
| `RESTV2_SYNC_JOBS_BULK_LIMIT` | `100` | Targets per sync-job bulk action (1 to 100) | |

The imapsync runner in mailcow's dovecot container is unchanged: it runs active jobs on its own
schedule, and the API never starts a run.

Routing and address rewriting (feature 009) use these settings:

| Variable | Default | Meaning | FeldHost |
|---|---|---|---|
| `RESTV2_ROUTING_TEST_SENDER` | `relay@example.org` | Envelope sender of a relayhost or transport connection test when the request gives none | |
| `RESTV2_ROUTING_TEST_RECIPIENT` | `null@hosted.mailcow.de` | Envelope recipient of a connection test when the request gives none | |
| `RESTV2_ROUTING_TEST_STEP_TIMEOUT` | `15` | Seconds each network step of a connection test may take | |
| `RESTV2_ROUTING_TEST_TOTAL_TIMEOUT` | `60` | Seconds a whole connection test may take (never below the step timeout) | |

Invalid values fall back to these defaults, with one warning in the log. Connection tests connect
from the API's container and resolve names, like forwarding host names, through mailcow's unbound:
the override sets `dns: [${IPV4_NETWORK}.254]` with `dns_opt: [timeout:2, attempts:2]` for the API's
service, as php-fpm-mailcow resolves. A test takes up to 60 seconds, within the 900 s
`fastcgi_read_timeout` of `deploy/nginx/site.restv2.custom`. The dev stack offers an SMTP test target:
`docker compose --profile routing up -d mailpit` (SMTP on `mailpit:1025` inside the stack, any login
accepted).

### DKIM, MTA-STS and DNS diagnostics (feature 010)

DKIM keys stay in mailcow's Redis hashes `DKIM_PUB_KEYS`, `DKIM_SELECTORS` and `DKIM_PRIV_KEYS`,
which Rspamd reads for signing and ARC sealing; every change is one atomic script. MTA-STS policies
are rows of mailcow's `mta_sts` table, which `mta-sts.php` and the certificate service read. The
selector, the ISPConfig publication and its settings are those of the domains section above; each
key change (generation, import, copy, deletion) is published when it is enabled. DNS diagnostics
resolves through mailcow's unbound (the override's `dns` and `dns_opt`, as for feature 009) and
reads the certificate on port 25 of the SMTP host. The `mailcow.conf` port variables `IMAP_PORT`,
`IMAPS_PORT`, `POP_PORT`, `POPS_PORT`, `SMTPS_PORT`, `SUBMISSION_PORT` and `SIEVE_PORT` give the
service ports that diagnostics expects in SRV records (the override passes them).

| Variable | Default | Meaning | FeldHost |
|---|---|---|---|
| `RESTV2_SHOW_DKIM_PRIV_KEYS` | (empty: mailcow's vars files, upstream `false`) | Legacy `$SHOW_DKIM_PRIV_KEYS`: administrators may export private keys | |
| `RESTV2_AUTODISCOVER_CONFIG` | (empty: mailcow's vars files, then the port variables) | Legacy `$autodiscover_config` as JSON: `imap`, `pop3`, `smtp`, `sieve` with `server`, `port`, `tlsport` | |
| `RESTV2_DKIM_BULK_PUBLICATION_BUDGET` | `60` | Seconds the record removals of one bulk deletion may take together | |
| `RESTV2_DNS_LOOKUP_TIMEOUT` | `5` | Seconds reserved per lookup, and the limit of the echo request and the certificate fetch | |
| `RESTV2_DNS_RUN_TIMEOUT` | `30` | Seconds a diagnostics run may take | |
| `RESTV2_DNS_DIAGNOSTICS_PER_MINUTE` | `10` | Diagnostics runs per caller (user or API key) per minute | |
| `RESTV2_DNS_FACTS_TTL` | `600` | Seconds the public addresses and the certificate digest are reused | |
| `RESTV2_PUBLIC_IPV4`, `RESTV2_PUBLIC_IPV6` | (empty: asked from the echo service) | The server's public addresses; `RESTV2_PUBLIC_IPV6=none` declares no IPv6 | |
| `RESTV2_IP_ECHO_URL_IPV4`, `RESTV2_IP_ECHO_URL_IPV6` | `http://ip4.mailcow.email`, `http://ip6.mailcow.email` | mailcow's address echo service, as legacy asks it | |
| `RESTV2_SUGGESTED_SPF_RECORD` | (empty) | The SPF record suggested in the SPF guidance | `v=spf1 mx a ~all` |

### Identity providers and OAuth2 clients (feature 012)

The API reads and writes mailcow's single identity provider configuration (Keycloak, a generic
OpenID Connect provider or an LDAP directory, table `identity_provider`) with legacy's keys, so the
legacy UI, `/api/v1`, the mail clients' sign-in check and the synchronisation jobs keep using it.
Secrets are write-only. Mailbox users sign in to the new web app with single sign-on (Keycloak,
generic OIDC) or with their LDAP or Keycloak (mailpassword flow) password through
`POST /api/v2/session`; a provider user without a mailbox gets one from the mapped template.

- **Return addresses to register at the provider**: `https://<host>/ng/sso/return` for
  `MAILCOW_HOSTNAME` and each host name of `ADDITIONAL_SERVER_NAMES`, in addition to the legacy
  return URLs. `GET /api/v2/capabilities/identity-provider` lists them, also before the first save.
- **Synchronisation**: the user import and the periodic full synchronisation stay in mailcow's
  php-fpm container (`crons/keycloak-sync.php`, `crons/ldap-sync.php`, run every minute by ofelia).
  The API never runs them; it derives their status from mailcow's scheduled-job log (`CRON_LOG`).
- **Verified SSO email**: Keycloak and generic OIDC must return the JSON boolean
  `email_verified: true` from UserInfo, both at sign-in and token renewal. Missing, false or
  string values are refused before selecting or creating a mailbox. Configure the provider's
  email verification and claim mapping before enabling SSO; an unverified address cannot be
  used to claim an existing mailbox.
- **LDAP with a private certificate authority**: mount the CA certificate into the API container and
  set `LDAPTLS_CACERT` to its path (the container uses its system bundle otherwise), or set
  "ignore TLS certificate errors" in the configuration.
- **OAuth2 clients**: applications keep using mailcow's `/oauth/authorize`, `/oauth/token` and
  `/oauth/profile` in php-fpm; the API manages the clients (`/api/v2/oauth2-clients`). A client
  secret is shown once, when the client is created or its secret renewed.

| Variable | Default | Meaning |
|---|---|---|
| `RESTV2_IDP_TIMEOUT` | `7` | Seconds per call to the identity provider |
| `RESTV2_IDP_RETRY_AFTER` | `30` | `Retry-After` of `identity-provider-unavailable` |
| `RESTV2_SSO_PENDING_TTL` | `600` | Lifetime in seconds of a started single sign-on |
| `RESTV2_SSO_RENEWAL_INTERVAL` | `300` | Seconds after which a single sign-on session is renewed with the provider |
| `RESTV2_SSO_RETURN_PATH` | `/ng/sso/return` | Path of the new web app's sign-in return address |
| `RESTV2_SSO_RETURN_SCHEME` | `https` | Scheme of the return address (`http` for development only) |
| `RESTV2_ACCESS_TOKEN_LIFETIME`, `RESTV2_REFRESH_TOKEN_LIFETIME` | from `vars.inc.php`, else `86400`, `2678400` | OAuth2 token lifetimes reported by `GET /api/v2/capabilities/oauth2-clients` |

The dev stack's `idp` profile (`docker compose --profile idp up -d`) adds OpenLDAP (the people of
`tests/fixtures/ldap/bootstrap.ldif`, used by `vendor/bin/phpunit --group idp`) and Keycloak with
the realm of `docker/keycloak/example-realm.json` on `http://localhost:8081` (admin `admin`/`admin`).

## Project structure

| Path                                                             | Purpose                                                                      |
| ---------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| [`api/`](api/openapi.yaml)                                       | OpenAPI source: module paths and shared schemas, parameters and responses    |
| [`routes/api.php`](routes/api.php)                               | Registered HTTP routes and middleware                                        |
| `app/Http/`, `app/Policies/`                                     | Request validation, controllers, resources and access checks                 |
| `app/Services/`                                                  | Business rules, upstream writes and their side effects                       |
| `app/Clients/`                                                   | Adapters for mailcow and external services, faked in tests                   |
| `app/Services/Config/`, [`config/restv2.php`](config/restv2.php) | Legacy configuration reader and API settings                                 |
| `database/`, `tests/fixtures/schema/`                            | API-owned migrations and supported upstream schema fixtures                  |
| `tests/{Unit,Feature,Integration,Support}`                       | Tests, contract validation and fake dependencies                             |
| `docker/`, [`compose.yaml`](compose.yaml), `deploy/`             | Local toolchain, production image and mailcow integration                    |
| `scripts/`, `docs/`, `specs/`                                    | Maintenance tools, shared guidance and feature plans/evidence                |
| `source_code/`                                                   | Optional, untracked, read-only checkout/symlink for legacy source inspection |

Request JSON parsing is centralized in
[`ApiFormRequest`](app/Http/Requests/Api/V2/ApiFormRequest.php); endpoint requests supply validation
and any field-specific normalization. Legacy configuration consumers use the typed
[`MailcowConfig`](app/Services/Config/MailcowConfig.php) facade; subprocess loading, cache and
fingerprint handling live in [`LegacyConfigLoader`](app/Services/Config/LegacyConfigLoader.php).
Extend these boundaries rather than introducing another body decoder or configuration loader.

## Development

The supported development path needs Docker with Compose v2 and Git. PHP, Composer, Node and the
required extensions run in the `app` container; the current image uses PHP 8.4. Run commands from
this repository's root. [`compose.yaml`](compose.yaml) binds source into the container and uses
`RESTV2_UID`/`RESTV2_GID` (default 1000) for generated-file ownership.

```bash
docker compose up -d --build
docker compose run --rm app composer install
docker compose run --rm app sh -c 'test -f .env || cp .env.example .env'
docker compose run --rm app php artisan key:generate
```

Generate the key once for a new local environment; retain an existing environment's key. To
populate a **disposable local database**, run:

```bash
docker compose run --rm app scripts/dev/reset-dev-database.sh
```

That reset recreates the dev database and seeds the accounts documented in
[Feature 001's quickstart](specs/001-auth-sessions-api-keys/quickstart.md#part-b-manual-end-to-end-checks-against-the-dev-stack).
Do not use it on a shared or deployed mailcow database. The dev API is served at
`https://127.0.0.1:18443/api/v2` with a self-signed certificate (`curl -k` locally);
`http://127.0.0.1:18080/api/v2` is also available for health checks. Session cookies are Secure,
so use HTTPS for browser authentication. Ports are configurable in `compose.yaml`.

Optional Compose profiles supply OpenLDAP/Keycloak (`idp`) and Mailpit (`mail` or `routing`).
Their feature quickstarts explain the required fixtures and settings.

### Build and checks

```bash
docker compose run --rm app composer contract:lint
docker compose run --rm app composer test
docker compose run --rm app vendor/bin/pint --test
docker compose run --rm app composer analyse
docker build -f docker/php/Dockerfile --target production -t mailcow-rest-v2:local .
```

`composer test` bundles the contract, **recreates `restv2_test`**, loads the supported upstream
schema and API migrations, runs PHPUnit, and checks operation coverage. It uses MariaDB and Redis,
not SQLite. Coordinate runs sharing that database/Redis instance; a concurrent reset invalidates
another run. After preparation, a focused test can run directly:

```bash
docker compose run --rm app vendor/bin/phpunit tests/Unit/Logging/RedactSecretsTest.php
```

That particular test is database-free. Many tests under `tests/Unit/` extend the application's
`Tests\TestCase` and still use MariaDB/Redis; the directory name alone does not imply isolation.
A focused run is not a replacement for full contract-operation coverage.

Timing tests are a separate category: `composer test:performance` runs the `performance` group
on an otherwise idle machine. The default suite excludes it and the `idp` group; run the latter
with `vendor/bin/phpunit --group idp` after starting its profile. See
[CI](.github/workflows/ci.yml) for the automated checks.

`composer docs:assets` installs the bundled Swagger UI assets for local `/api/v2/docs`.
[`scripts/dev/verify-nginx-snippet.sh`](scripts/dev/verify-nginx-snippet.sh) checks the integration
snippet in mailcow's nginx image without changing a deployed host.

### API contract workflow

1. Read the feature's `spec.md`, `plan.md`, legacy form/action mapping and the shared conventions.
2. Change the OpenAPI source in `api/` first. Keep changes within `/api/v2` additive; document new
   problem types in [`docs/problems.md`](docs/problems.md).
3. Write failing validation, authorization and side-effect tests, then implement the request,
   policy and service behavior. Update [`tests/feature-registry.json`](tests/feature-registry.json)
   when operations become implemented.
4. Run contract lint, the relevant tests and the full applicable gates. `composer contract:bundle`
   produces `build/contract/openapi.yaml` and `openapi.json`; do not edit these outputs by hand.
   CI checks breaking changes against the latest `v*` release tag when one exists.
5. After the API contract revision is committed, pin that explicit revision in the frontend with
   its `scripts/contract/bump.sh <tag-or-commit>`. Generated frontend types must follow the pin.

The [legacy inventory](docs/legacy-inventory.md), [API gap record](docs/api-gaps.md),
[audit event reference](docs/audit-events.md), and `specs/NNN-*/quickstart.md` provide the detailed
behavior and validation recipes. Old quickstart counts and planning prompts describe their
original checkpoint, not the current whole-project test count.

## Deploying a committed revision to maildev

The host must already have the API service and nginx include installed as described above.
The checked-in deployment helper accepts an SSH host/alias and the mailcow directory:

```bash
REF='<reviewed-commit-or-tag>' scripts/deploy/push-to-mailcow.sh '<maildev-ssh-host>' '<mailcow-directory>'
```

[`push-to-mailcow.sh`](scripts/deploy/push-to-mailcow.sh) exports the selected Git revision
(`REF` defaults to `main`), builds a production image, streams it over SSH, updates only the
`restv2-mailcow` image and runs `docker compose up -d --no-deps restv2-mailcow`. It waits for
container health and `/api/v2/health/ready`. Uncommitted files are excluded. `PUBLIC_URL` overrides
the public check URL and `HEALTH_TIMEOUT` changes the container wait (default 180 seconds).
For locally loaded images, configure `pull_policy: never` on the service.

This command changes the target host; it is not a test or dry run. It does not run the test suite,
install the initial nginx integration, or deploy the UI. The old image is retained and its tag is
printed for manual rollback; there is no automatic rollback. Keep SSH access and host settings
outside the repository. Deploy a compatible API before a UI revision that requires its operations.

## Licence

GPL-3.0-only, the same as mailcow. See [`LICENSE`](LICENSE). The documentation page bundles
Swagger UI (`swagger-ui-dist`, Apache-2.0), and the WebAuthn and TOTP libraries are MIT. DNS
diagnostics (feature 010) evaluates SPF with `mlocati/spf-lib` (MIT), which brings `mlocati/ip-lib`
and `mlocati/idna` (both MIT): RFC 7208 evaluation with the lookup limits and an injectable resolver,
instead of a hand-written evaluator (feature 010 research R25). The development image adds BIND's
`named-checkzone` (`bind-tools`, MPL-2.0) for the zone-file test; it is not part of the production
image.

Quarantine inspection (feature 006) parses messages with legacy's own libraries:
`php-mime-mail-parser/php-mime-mail-parser` 10.0 (MIT) on PECL `mailparse` 3.2 (PHP-3.01) and
`soundasleep/html2text` 2.1 (MIT). The notification template check runs the legacy notifier's
engine: Alpine's `python3` (PSF-2.0) with `py3-jinja2`, `py3-markupsafe` (BSD-3-Clause) and
`py3-html2text` (GPL-3.0) (feature 006 research R23).
