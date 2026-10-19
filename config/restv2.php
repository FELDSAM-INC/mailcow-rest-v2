<?php

use App\Support\Config\Env;

// The port of a mailcow.conf port variable: its last `:`-separated part (`127.0.0.1:993` gives
// 993), as legacy's $autodiscover_config derives it; the mailcow default when unset or not a port.
$mailcowPort = function (string $name, int $default): int {
    $value = Env::get($name);
    $parts = explode(':', trim((string) $value));
    $port = (int) filter_var(end($parts), FILTER_SANITIZE_NUMBER_INT);

    return $port >= 1 && $port <= 65535 ? $port : $default;
};

/*
|--------------------------------------------------------------------------
| mailcow REST API v2 settings (research R14)
|--------------------------------------------------------------------------
|
| Settings of mailcow.conf keep their mailcow names (MAILCOW_HOSTNAME, TZ, SKIP_SOGO, ...). Settings
| that are new to this API use the RESTV2_ prefix. Values of legacy's PHP configuration
| (vars.inc.php / vars.local.inc.php) are read by App\Services\Config\MailcowConfig: an explicit
| RESTV2_<NAME> server setting (vars_overrides) wins, then the mounted legacy files, then the
| upstream default (vars_defaults).
|
| Every setting is read with Env::get: an empty or whitespace-only value means unset and gives the
| default, because mailcow's compose override passes optional settings as NAME=${NAME:-}
| (docs/conventions.md "Configuration"). The one setting whose empty value means something,
| RESTV2_MAILCOW_INC_DIR, keeps env().
|
*/

return [

    // sasl_log.service label of UI sign-ins (FR-027). FeldHost: WEBUI.
    'sasl_log_service' => Env::get('RESTV2_SASL_LOG_SERVICE', 'MAILCOWUI'),

    // Default page title and product name; empty means "<lower(MAILCOW_HOSTNAME)> - mail UI"
    // (FR-043). FeldHost: "FeldHost™ Mailhosting UI".
    'default_ui_name' => (string) Env::get('RESTV2_DEFAULT_UI_NAME', ''),

    // Default logos when mailcow has none (FeldHost: /img/feldhost_logo.png and
    // /img/feldhost_logo_white.png).
    'default_logo_url' => Env::get('RESTV2_DEFAULT_LOGO_URL', '/img/cow_mailcow.svg'),
    'default_logo_dark_url' => Env::get('RESTV2_DEFAULT_LOGO_DARK_URL', '/img/cow_mailcow.svg'),

    // Sign-in throttling (FR-007, R17).
    'signin_max_failures' => (int) Env::get('RESTV2_SIGNIN_MAX_FAILURES', 10),
    'signin_failure_window' => (int) Env::get('RESTV2_SIGNIN_FAILURE_WINDOW', 900),

    // Lifetime in seconds of a pending sign-in, a WebAuthn challenge and registration options.
    'challenge_ttl' => (int) Env::get('RESTV2_CHALLENGE_TTL', 300),

    // Audit retention (FR-048).
    'audit_retention_days' => (int) Env::get('RESTV2_AUDIT_RETENTION_DAYS', 365),

    // Yubico OTP verification endpoint (R11).
    'yubico_verify_url' => Env::get('RESTV2_YUBICO_VERIFY_URL', 'https://api.yubico.com/wsapi/2.0/verify'),

    // Mount point of mailcow's data/web/inc (read-only). Relative paths are resolved against the
    // application base path. Empty means "not mounted": the upstream defaults apply. Read with
    // env(), not Env::get, so that the empty value keeps this meaning (the dev stack's .env uses
    // it); the deploy example defaults an empty value to /mailcow/web/inc itself.
    'mailcow_inc_dir' => (string) env('RESTV2_MAILCOW_INC_DIR', '/mailcow/web/inc'),

    // versions.db_schema values of the supported mailcow releases (README).
    'supported_schema_versions' => ['18082026_1200'],

    // mailcow.conf settings.
    'mailcow_hostname' => Env::get('MAILCOW_HOSTNAME'),
    'additional_server_names' => (string) Env::get('ADDITIONAL_SERVER_NAMES', ''),
    'ipv4_network' => Env::get('IPV4_NETWORK', '172.22.1'),
    'ipv6_network' => Env::get('IPV6_NETWORK', 'fd4d:6169:6c63:6f77::/64'),
    'webauthn_only_trusted_vendors' => Env::get('WEBAUTHN_ONLY_TRUSTED_VENDORS', 'n') === 'y',
    'skip_sogo' => Env::get('SKIP_SOGO', 'n') === 'y',
    // Feature 007: administrators, and domain administrators with `login_as`, may open a mailbox's
    // webmail on the user's behalf (mailcow.conf ALLOW_ADMIN_EMAIL_LOGIN, legacy sogo-auth.php's
    // pattern, default off).
    'allow_admin_email_login' => preg_match('/^([yY][eE][sS]|[yY])+$/', (string) Env::get('ALLOW_ADMIN_EMAIL_LOGIN', 'n')) === 1,
    'timezone' => Env::get('TZ', 'UTC'),

    // dockerapi container lookup (feature 002 research D14): the compose project of mailcow.
    'compose_project_name' => Env::get('COMPOSE_PROJECT_NAME'),

    /*
    |----------------------------------------------------------------------
    | Outbound services of features 002 and 010 (002 research D24, 010 research R24)
    |----------------------------------------------------------------------
    */

    // mailcow's container service (legacy docker()), reached without TLS verification like legacy.
    'dockerapi' => [
        'url' => rtrim((string) Env::get('RESTV2_DOCKERAPI_URL', 'https://dockerapi:443'), '/'),
    ],

    // The groupware cache flushed after every groupware directory rebuild (legacy flush_memcached()).
    'memcached' => [
        'host' => (string) Env::get('RESTV2_MEMCACHED_HOST', 'memcached'),
        'port' => (int) Env::get('RESTV2_MEMCACHED_PORT', 11211),
    ],

    'dkim' => [
        // Selector of new DKIM keys when neither the request nor the template gives one (002 FR-028).
        // Upstream: dkim. FeldHost: feldhost.
        'default_selector' => (string) Env::get('RESTV2_DEFAULT_DKIM_SELECTOR', 'dkim'),
        'default_key_size' => 2048,
        'allowed_key_sizes' => [1024, 2048, 3072, 4096],
        // Feature 010 (FR-019): limits of an imported private key, not settings.
        'private_key_max_bytes' => 16384,
        'import_bits' => [1024, 8192],
        // FeldHost ISPConfig DNS publication of DKIM records (002 FR-027, 010 FR-028). Off by default;
        // enabled only together with the ISPConfig URL, user and password.
        'dns_publication' => Env::get('RESTV2_DKIM_DNS_PUBLICATION', 'n') === 'y',
        // Seconds shared by the record removals of one bulk deletion (010 research R28).
        'bulk_publication_budget' => max(1, (int) Env::get('RESTV2_DKIM_BULK_PUBLICATION_BUDGET', 60)),
        // Targets per DKIM bulk deletion (010 FR-059, G4); fixed.
        'bulk_limit' => 100,
    ],

    /*
    |----------------------------------------------------------------------
    | Feature 010: MTA-STS and DNS diagnostics (010 research R24)
    |----------------------------------------------------------------------
    */

    'mta_sts' => [
        // RFC 8461's maximum, at creation and when changed (FR-033); fixed.
        'max_age_max' => 31557600,
        // Width of mailcow's `mta_sts.mx` column (FR-033); fixed.
        'mx_max_length' => 255,
    ],

    'dns' => [
        // Seconds reserved per lookup by the run deadline, and the limit of the address echo and
        // the certificate fetch (FR-048). The resolver's own bound is the container's dns_opt
        // (about 4 s), which must stay below it.
        'lookup_timeout' => max(1, (int) Env::get('RESTV2_DNS_LOOKUP_TIMEOUT', 5)),
        // Seconds per run (FR-048).
        'run_timeout' => max(1, (int) Env::get('RESTV2_DNS_RUN_TIMEOUT', 30)),
        // Runs per caller per minute (FR-049).
        'runs_per_minute' => max(1, (int) Env::get('RESTV2_DNS_DIAGNOSTICS_PER_MINUTE', 10)),
        // Seconds the public addresses and the certificate digest are reused (FR-049).
        'facts_ttl' => max(1, (int) Env::get('RESTV2_DNS_FACTS_TTL', 600)),
        // The server's public addresses; empty: asked from the echo service. `none` for IPv6
        // declares no public IPv6 address without asking.
        'public_ipv4' => trim((string) Env::get('RESTV2_PUBLIC_IPV4', '')),
        'public_ipv6' => trim((string) Env::get('RESTV2_PUBLIC_IPV6', '')),
        // mailcow's address echo service (legacy dns_diagnostics.php).
        'echo_url_ipv4' => (string) Env::get('RESTV2_IP_ECHO_URL_IPV4', 'http://ip4.mailcow.email'),
        'echo_url_ipv6' => (string) Env::get('RESTV2_IP_ECHO_URL_IPV6', 'http://ip6.mailcow.email'),
        // The SPF record suggested in the SPF guidance (FR-050). Upstream: none. FeldHost:
        // v=spf1 mx a ~all.
        'suggested_spf_record' => trim((string) Env::get('RESTV2_SUGGESTED_SPF_RECORD', '')),
    ],

    // mailcow.conf port variables (last `:`-separated part), from which legacy's
    // $autodiscover_config derives the service ports (010 research R17).
    'mailcow_ports' => [
        'IMAP_PORT' => $mailcowPort('IMAP_PORT', 143),
        'IMAPS_PORT' => $mailcowPort('IMAPS_PORT', 993),
        'POP_PORT' => $mailcowPort('POP_PORT', 110),
        'POPS_PORT' => $mailcowPort('POPS_PORT', 995),
        'SMTPS_PORT' => $mailcowPort('SMTPS_PORT', 465),
        'SUBMISSION_PORT' => $mailcowPort('SUBMISSION_PORT', 587),
        'SIEVE_PORT' => $mailcowPort('SIEVE_PORT', 4190),
    ],

    // ISPConfig JSON remote API (…/remote/json.php) for the DKIM DNS publication. The password is a
    // secret: never logged, returned or cached.
    'ispconfig' => [
        'url' => (string) Env::get('RESTV2_ISPCONFIG_URL', ''),
        'username' => (string) Env::get('RESTV2_ISPCONFIG_USERNAME', ''),
        'password' => (string) Env::get('RESTV2_ISPCONFIG_PASSWORD', ''),
        'dns_server_id' => (int) Env::get('RESTV2_ISPCONFIG_DNS_SERVER_ID', 1),
        'verify_tls' => Env::get('RESTV2_ISPCONFIG_VERIFY_TLS', 'y') !== 'n',
        'timeout' => (int) Env::get('RESTV2_ISPCONFIG_TIMEOUT', 15),
    ],

    /*
    |----------------------------------------------------------------------
    | Mailboxes (feature 003 research R23)
    |----------------------------------------------------------------------
    */

    // Hash scheme of new mailbox passwords (mailcow.conf; legacy hash_password()): BLF-CRYPT,
    // SSHA, SSHA256 or SSHA512; any other value falls back to BLF-CRYPT.
    'mailcow_pass_scheme' => (string) Env::get('MAILCOW_PASS_SCHEME', 'BLF-CRYPT'),

    // Minutes mailcow keeps the mail of a deleted mailbox in the garbage area (mailcow.conf).
    'maildir_gc_time' => max(0, (int) Env::get('MAILDIR_GC_TIME', 7200)),

    // `replication`: maildir moves are confirmed on this node and broadcast to every mail node.
    // Empty, the default, means no cluster.
    'cluster_mode' => (string) Env::get('CLUSTERMODE', ''),

    // Usage figures come from `quota2` on the master node and from `quota2replica` otherwise
    // (legacy matches MASTER with /y|yes/i). Empty means y, as in mailcow's compose file.
    'master' => preg_match('/y|yes/i', (string) Env::get('MASTER', 'y')) === 1,

    // Custom attributes per mailbox (1 to 100; a setting can lower the limit, never raise it).
    'mailbox_custom_attribute_limit' => max(1, min(100, (int) Env::get('RESTV2_MAILBOX_CUSTOM_ATTRIBUTE_LIMIT', 100))),

    // Targets per mailbox bulk action (1 to 100; a setting can lower the limit, never raise it).
    'bulk_limit' => max(1, min(100, (int) Env::get('RESTV2_BULK_LIMIT', 100))),

    // Feature 007: targets per administrator or domain administrator bulk action (docs/conventions.md
    // "Bulk actions"), and the lifetime of a domain administrator's single sign-on token in seconds,
    // which the legacy domain administrator login enforces on the same tokens. Not settings.
    'account_bulk_limit' => 100,
    'sso_token_lifetime' => 30,

    /*
    |----------------------------------------------------------------------
    | Sync jobs (feature 008 research R18)
    |----------------------------------------------------------------------
    */

    'sync_jobs' => [
        // `y` restricts custom imapsync parameters to administrators (FR-006). Legacy: unrestricted.
        'custom_params_admin_only' => Env::get('RESTV2_SYNC_JOBS_CUSTOM_PARAMS_ADMIN_ONLY', 'n') === 'y',
        // Targets per sync-job bulk action (1 to 100, docs/conventions.md "Bulk actions"; a setting
        // can lower the limit, never raise it).
        'bulk_limit' => max(1, min(100, (int) Env::get('RESTV2_SYNC_JOBS_BULK_LIMIT', 100))),
    ],

    /*
    |----------------------------------------------------------------------
    | Identity providers and single sign-on (feature 012 research R17)
    |----------------------------------------------------------------------
    |
    | The host names of the new web app's sign-in return addresses are MAILCOW_HOSTNAME and the
    | mailcow.conf setting ADDITIONAL_SERVER_NAMES (`additional_server_names` above).
    */
    'identity_provider' => [
        // Seconds per call to the provider (FR-056).
        'timeout' => max(1, (int) Env::get('RESTV2_IDP_TIMEOUT', 7)),
        // Retry-After of `identity-provider-unavailable`.
        'retry_after' => max(1, (int) Env::get('RESTV2_IDP_RETRY_AFTER', 30)),
        // Lifetime of a started single sign-on, in seconds.
        'sso_pending_ttl' => max(1, (int) Env::get('RESTV2_SSO_PENDING_TTL', 600)),
        // Seconds after which a single sign-on session is renewed with the provider.
        'sso_renewal_interval' => max(1, (int) Env::get('RESTV2_SSO_RENEWAL_INTERVAL', 300)),
        // Path of the new web app's sign-in return address (a mailcowui-ng route).
        'sso_return_path' => (string) Env::get('RESTV2_SSO_RETURN_PATH', '/ng/sso/return'),
        // Scheme of the return address; `http` is for development only.
        'sso_return_scheme' => Env::get('RESTV2_SSO_RETURN_SCHEME', 'https') === 'http' ? 'http' : 'https',
    ],

    /*
    |----------------------------------------------------------------------
    | Routing and address rewriting (feature 009 research R21)
    |----------------------------------------------------------------------
    |
    | Raw values: App\Services\Routing\RoutingSettings validates them and falls back to the
    | default, with one logged warning, when a value is invalid (a non-address, a timeout below 1,
    | a total below the step timeout).
    */
    'routing' => [
        // Default sender and recipient of connection tests (legacy transport_check.php).
        'test_sender' => Env::get('RESTV2_ROUTING_TEST_SENDER', 'relay@example.org'),
        'test_recipient' => Env::get('RESTV2_ROUTING_TEST_RECIPIENT', 'null@hosted.mailcow.de'),
        // Seconds per network step of a connection test, and for the whole test (FR-042).
        'test_step_timeout' => Env::get('RESTV2_ROUTING_TEST_STEP_TIMEOUT', 15),
        'test_total_timeout' => Env::get('RESTV2_ROUTING_TEST_TOTAL_TIMEOUT', 60),
        // Seconds a write waits for a routing table's named lock (research R9); not a setting.
        'lock_timeout' => 10,
        // DNS lookups of a forwarding host's SPF evaluation (FR-076); fixed.
        'spf_lookup_limit' => 10,
    ],

    /*
    |----------------------------------------------------------------------
    | Account self-service (feature 005 research R23)
    |----------------------------------------------------------------------
    */

    // Path of the password reset page in the reset message's link; `{token}` is replaced by the
    // token. The web app's page by default, with the token in the fragment so that it never reaches
    // a server log (G27); `/reset-password?token={token}` keeps the legacy page.
    'pw_reset_page' => (string) Env::get('RESTV2_PW_RESET_PAGE', '/ng/reset-password#token={token}'),

    // Reset requests, token checks and completions per client address and window (seconds).
    'pw_reset_max_attempts' => max(1, (int) Env::get('RESTV2_PW_RESET_MAX_ATTEMPTS', 10)),
    'pw_reset_window' => max(1, (int) Env::get('RESTV2_PW_RESET_WINDOW', 900)),

    // Mount point of mailcow's data/assets/templates (read-only): the default reset message
    // bodies, used when none is saved in mailcow's shared store. Relative paths are resolved
    // against the application base path.
    'mailcow_tpls_dir' => (string) Env::get('RESTV2_MAILCOW_TPLS_DIR', '/mailcow/tpls'),

    // mailcow's mail server, which sends the reset messages without authentication (legacy
    // PHPMailer settings of send_mail()).
    'smtp_host' => (string) Env::get('RESTV2_SMTP_HOST', 'postfix-mailcow'),
    'smtp_port' => (int) Env::get('RESTV2_SMTP_PORT', 25),

    // TOTP enrolment: lifetime in seconds and wrong codes before it is cancelled.
    'totp_enrolment_ttl' => max(1, (int) Env::get('RESTV2_TOTP_ENROLMENT_TTL', 600)),
    'totp_enrolment_max_attempts' => max(1, (int) Env::get('RESTV2_TOTP_ENROLMENT_MAX_ATTEMPTS', 5)),

    // Countries of recent-login addresses not in mailcow's cache: looked up with legacy's service
    // (on by default, as legacy), within a time budget per page.
    'country_lookup' => filter_var(Env::get('RESTV2_COUNTRY_LOOKUP', true), FILTER_VALIDATE_BOOL),
    'country_lookup_url' => (string) Env::get('RESTV2_COUNTRY_LOOKUP_URL', 'https://dfdata.bella.network/country/'),
    'country_lookup_budget_ms' => max(1, (int) Env::get('RESTV2_COUNTRY_LOOKUP_BUDGET_MS', 1500)),

    // Pushover's credential validation endpoint.
    'pushover_validate_url' => (string) Env::get('RESTV2_PUSHOVER_VALIDATE_URL', 'https://api.pushover.net/1/users/validate.json'),

    // Development only (APP_ENV=local): binds the dockerapi, memcached and ISPConfig fakes, because
    // the dev stack runs none of these services. Ignored in every other environment. Feature 011
    // adds its stand-ins (Rspamd, release source, IP check) and recorded monitoring payloads.
    'fake_outbound' => Env::get('RESTV2_FAKE_OUTBOUND', 'n') === 'y',

    /*
    |----------------------------------------------------------------------
    | Feature 011: monitoring and operations (011 research R27)
    |----------------------------------------------------------------------
    */

    // mailcow.conf: optional scanners hidden when skipped (legacy dashboard.php matches
    // ^([yY][eE][sS]|[yY])+$), and the in-memory log length (lists keep LOG_LINES + 1 entries).
    'skip_clamd' => (string) Env::get('SKIP_CLAMD', 'n'),
    'skip_olefy' => (string) Env::get('SKIP_OLEFY', 'n'),
    'log_lines' => (int) Env::get('LOG_LINES', 9999),

    'monitoring' => [
        // This API's own compose service; also any container whose ID starts with this process's
        // host name (HOSTNAME, set by Docker to the short container ID).
        'self_service' => (string) Env::get('RESTV2_SELF_SERVICE', 'restv2-mailcow'),
        'hostname' => (string) Env::get('HOSTNAME', ''),
        // Containers that cannot be stopped (comma-separated service names).
        'protected_containers' => (string) Env::get('RESTV2_PROTECTED_CONTAINERS', 'dockerapi-mailcow,mysql-mailcow,redis-mailcow,nginx-mailcow,restv2-mailcow'),
        // JSON map of extra or overriding service => ContainerRole entries, for renamed services.
        'container_roles' => (string) Env::get('RESTV2_CONTAINER_ROLES', '{}'),
        // Containers this feature execs into or restarts.
        'postfix_container' => (string) Env::get('RESTV2_POSTFIX_CONTAINER', 'postfix-mailcow'),
        'dovecot_container' => (string) Env::get('RESTV2_DOVECOT_CONTAINER', 'dovecot-mailcow'),
        'ban_service_container' => (string) Env::get('RESTV2_BAN_SERVICE_CONTAINER', 'netfilter-mailcow'),
        // The interpreter that checks ban rules in the ban service's dialect (Python `re`).
        'python' => (string) Env::get('RESTV2_PYTHON', 'python3'),

        // Constants reported by GET /capabilities/monitoring (not settings).
        'poll_interval' => 5,
        'max_age' => [
            'containers' => 5,
            'host' => 10,
            'usage_sample' => 15,
            'usage_cache' => 5,
            'mail_storage' => 60,
        ],
        'queue_listing_max' => 10000,
        'queue_bulk_max' => 1000,
        'rules_max' => 100,
        'rule_max_length' => 1000,
        'ban_list_max_entries' => 10000,
        'ban_list_entry_max_length' => 253,
    ],

    // Update check (legacy update_check.php). Upstream: on; FeldHost: RESTV2_UPDATE_CHECK=n. An
    // empty source URL means the GitHub releases API of MAILCOW_GIT_OWNER/MAILCOW_GIT_REPO. `false`
    // arrives as a boolean (Laravel's env conversion).
    'update_check' => [
        'enabled' => ! in_array(($check = Env::get('RESTV2_UPDATE_CHECK', 'y')) === false ? 'false' : strtolower((string) $check), ['n', 'no', 'false', '0', 'off'], true),
        'source_url' => rtrim((string) Env::get('RESTV2_UPDATE_SOURCE_URL', ''), '/'),
        'stable_branch' => (string) Env::get('RESTV2_UPDATE_STABLE_BRANCH', 'master'),
    ],

    // mailcow's external IP check service (legacy get/status/host/ip).
    'ip_check' => [
        'ipv4_url' => (string) Env::get('RESTV2_IP_CHECK_IPV4_URL', 'http://ipv4.mailcow.email'),
        'ipv6_url' => (string) Env::get('RESTV2_IP_CHECK_IPV6_URL', 'http://ipv6.mailcow.email'),
    ],

    // Rspamd controller socket (feature 006 research R2, created with feature 011): HTTP over the
    // Unix socket of the mounted rspamd-vol-1, which Rspamd trusts without a password.
    'rspamd' => [
        'socket' => (string) Env::get('RESTV2_RSPAMD_SOCKET', '/var/lib/rspamd/rspamd.sock'),
        'timeout' => (int) Env::get('RESTV2_RSPAMD_TIMEOUT', 30),
    ],

    /*
    |----------------------------------------------------------------------
    | Feature 006: quarantine, spam policy and rate limits (006 research R20)
    |----------------------------------------------------------------------
    */

    // Postfix's quarantine submission (port 590: it bypasses the spam filter and accepts mailcow's
    // own networks only), which receives released messages without TLS, as legacy sends them.
    'release_smtp' => [
        'host' => (string) Env::get('RESTV2_RELEASE_SMTP_HOST', 'postfix'),
        'port' => (int) Env::get('RESTV2_RELEASE_SMTP_PORT', 590),
        'timeout' => max(1, (int) Env::get('RESTV2_RELEASE_SMTP_TIMEOUT', 30)),
    ],

    'quarantine' => [
        // Largest stored message, in bytes, that is parsed and downloadable (legacy: 10 MiB).
        'content_limit' => max(1, (int) Env::get('RESTV2_QUARANTINE_CONTENT_LIMIT', 10485760)),
        // The default notification template (legacy /tpls/quarantine.tpl): mailcow's
        // data/assets/templates, mounted read-only for feature 005 at RESTV2_MAILCOW_TPLS_DIR. When
        // the file cannot be read, the bundled 2026-09a copy (resources/legacy/quarantine.tpl) is used.
        'default_template' => (string) Env::get('RESTV2_QUARANTINE_DEFAULT_TEMPLATE', rtrim((string) Env::get('RESTV2_MAILCOW_TPLS_DIR', '/mailcow/tpls'), '/').'/quarantine.tpl'),
        // Seconds a notification template check or preview may take (FR-070).
        'template_timeout' => max(1, (int) Env::get('RESTV2_TEMPLATE_RENDER_TIMEOUT', 5)),
        // The interpreter of the template check (the notifier's Jinja2 sandbox); shared with
        // feature 011's ban rule check.
        'python' => (string) Env::get('RESTV2_PYTHON', 'python3'),
        // Seconds an item lock lives at most (research R4); it exceeds the worst case of a release
        // (delivery, learning and fuzzy hashes, 30 s each). Tests lower it.
        'lock_ttl' => max(1, (int) Env::get('RESTV2_QUARANTINE_LOCK_TTL', 300)),
    ],

    /*
    |----------------------------------------------------------------------
    | Legacy PHP configuration
    |----------------------------------------------------------------------
    |
    | Each exported vars.inc.php variable maps to its RESTV2_<NAME> server setting. Arrays are
    | given as JSON, flags as true/false or a string. Later features add their variables here and
    | in vars_defaults; App\Services\Config\MailcowConfig exports exactly these names. An empty
    | setting is null and means "read mailcow's vars files" (then the upstream default).
    |
    */
    'vars_overrides' => [
        'SESSION_LIFETIME' => Env::get('RESTV2_SESSION_LIFETIME'),
        'DEFAULT_LANG' => Env::get('RESTV2_DEFAULT_LANG'),
        'AVAILABLE_LANGUAGES' => Env::get('RESTV2_AVAILABLE_LANGUAGES'),
        'FIDO2_UV_FLAG_REGISTER' => Env::get('RESTV2_FIDO2_UV_FLAG_REGISTER'),
        'FIDO2_UV_FLAG_LOGIN' => Env::get('RESTV2_FIDO2_UV_FLAG_LOGIN'),
        'FIDO2_USER_PRESENT_FLAG' => Env::get('RESTV2_FIDO2_USER_PRESENT_FLAG'),
        'FIDO2_FORMATS' => Env::get('RESTV2_FIDO2_FORMATS'),
        'WEBAUTHN_UV_FLAG_LOGIN' => Env::get('RESTV2_WEBAUTHN_UV_FLAG_LOGIN'),
        'WEBAUTHN_USER_PRESENT_FLAG' => Env::get('RESTV2_WEBAUTHN_USER_PRESENT_FLAG'),
        'OTP_LABEL' => Env::get('RESTV2_OTP_LABEL'),
        'MAILCOW_APPS' => Env::get('RESTV2_MAILCOW_APPS'),
        'TAGGING_LIMIT' => Env::get('RESTV2_TAGGING_LIMIT'),
        'DOCKER_TIMEOUT' => Env::get('RESTV2_DOCKER_TIMEOUT'),
        'SHOW_LAST_LOGIN' => Env::get('RESTV2_SHOW_LAST_LOGIN'),
        'MAILBOX_DEFAULT_ATTRIBUTES' => Env::get('RESTV2_MAILBOX_DEFAULT_ATTRIBUTES'),
        // Feature 004: external alias destinations disabled for every role (FR-007).
        'ALIAS_DISABLE_EXTERNAL_DOMAINS' => Env::get('RESTV2_ALIAS_DISABLE_EXTERNAL_DOMAINS'),
        // Feature 011 (research R9): the installed version, from mailcow's generated
        // app_info.inc.php when it is mounted next to the vars files.
        'MAILCOW_GIT_VERSION' => Env::get('RESTV2_MAILCOW_GIT_VERSION'),
        'MAILCOW_GIT_OWNER' => Env::get('RESTV2_MAILCOW_GIT_OWNER'),
        'MAILCOW_GIT_REPO' => Env::get('RESTV2_MAILCOW_GIT_REPO'),
        'MAILCOW_GIT_URL' => Env::get('RESTV2_MAILCOW_GIT_URL'),
        'MAILCOW_GIT_COMMIT' => Env::get('RESTV2_MAILCOW_GIT_COMMIT'),
        'MAILCOW_GIT_COMMIT_DATE' => Env::get('RESTV2_MAILCOW_GIT_COMMIT_DATE'),
        'MAILCOW_BRANCH' => Env::get('RESTV2_MAILCOW_BRANCH'),
        // Feature 008: the custom imapsync parameter allow-list, JSON {"whitelist": [...],
        // "blacklist": [...]} (research R7). `showpasswords` is always removed.
        'IMAPSYNC_OPTIONS' => Env::get('RESTV2_IMAPSYNC_OPTIONS'),
        'ACCESS_TOKEN_LIFETIME' => Env::get('RESTV2_ACCESS_TOKEN_LIFETIME'),
        'REFRESH_TOKEN_LIFETIME' => Env::get('RESTV2_REFRESH_TOKEN_LIFETIME'),
        // Feature 010: private key export (FR-026) and the service ports of DNS diagnostics, JSON
        // {"imap": {"server", "port", "tlsport"}, "pop3": ..., "smtp": ..., "sieve": ...} (R17).
        'SHOW_DKIM_PRIV_KEYS' => Env::get('RESTV2_SHOW_DKIM_PRIV_KEYS'),
        'autodiscover_config' => Env::get('RESTV2_AUTODISCOVER_CONFIG'),
        // Feature 005: password reset tokens, WebAuthn second-factor registration, the legacy
        // session of the webmail hand-off (research R23); the autodiscover settings are feature 010's.
        'PW_RESET_TOKEN_LIFETIME' => Env::get('RESTV2_PW_RESET_TOKEN_LIFETIME'),
        'PW_RESET_TOKEN_LIMIT' => Env::get('RESTV2_PW_RESET_TOKEN_LIMIT'),
        'WEBAUTHN_UV_FLAG_REGISTER' => Env::get('RESTV2_WEBAUTHN_UV_FLAG_REGISTER'),
        'SESSION_NAME' => Env::get('RESTV2_SESSION_NAME'),
        'SESSION_SAMESITE_POLICY' => Env::get('RESTV2_SESSION_SAMESITE_POLICY'),
    ],

    // Upstream defaults of mailcow-dockerized 2026-09a (data/web/inc/vars.inc.php).
    'vars_defaults' => [
        'SESSION_LIFETIME' => 10800,
        'DEFAULT_LANG' => 'en-gb',
        'AVAILABLE_LANGUAGES' => [
            'bg-bg' => 'Български (Bulgarian)',
            'cs-cz' => 'Čeština (Czech)',
            'da-dk' => 'Dansk (Danish)',
            'de-de' => 'Deutsch (German)',
            'en-gb' => 'English',
            'es-es' => 'Español (Spanish)',
            'fi-fi' => 'Suomi (Finish)',
            'fr-fr' => 'Français (French)',
            'gr-gr' => 'Ελληνικά (Greek)',
            'hu-hu' => 'Magyar (Hungarian)',
            'it-it' => 'Italiano (Italian)',
            'ja-jp' => '日本語 (Japanese)',
            'ko-kr' => '한국어 (Korean)',
            'lv-lv' => 'latviešu (Latvian)',
            'lt-lt' => 'Lietuvių (Lithuanian)',
            'nb-no' => 'Norsk (Norwegian)',
            'nl-nl' => 'Nederlands (Dutch)',
            'pl-pl' => 'Język Polski (Polish)',
            'pt-br' => 'Português brasileiro (Brazilian Portuguese)',
            'pt-pt' => 'Português (Portuguese)',
            'ro-ro' => 'Română (Romanian)',
            'ru-ru' => 'Pусский (Russian)',
            'si-si' => 'Slovenščina (Slovenian)',
            'sk-sk' => 'Slovenčina (Slovak)',
            'sv-se' => 'Svenska (Swedish)',
            'tr-tr' => 'Türkçe (Turkish)',
            'uk-ua' => 'Українська (Ukrainian)',
            'uz-uz' => 'Oʻzbekcha (Uzbek)',
            'vi-vn' => 'Tiếng Việt (Vietnamese)',
            'zh-cn' => '简体中文 (Simplified Chinese)',
            'zh-tw' => '繁體中文 (Traditional Chinese)',
        ],
        'FIDO2_UV_FLAG_REGISTER' => 'preferred',
        'FIDO2_UV_FLAG_LOGIN' => 'preferred',
        'FIDO2_USER_PRESENT_FLAG' => true,
        'FIDO2_FORMATS' => ['apple', 'android-key', 'android-safetynet', 'fido-u2f', 'none', 'packed', 'tpm'],
        'WEBAUTHN_UV_FLAG_LOGIN' => false,
        'WEBAUTHN_USER_PRESENT_FLAG' => true,
        'OTP_LABEL' => 'mailcow UI',
        'MAILCOW_APPS' => [
            [
                'name' => 'Webmail',
                'link' => '/SOGo/so',
                'user_link' => '/SOGo/so',
                'hide' => true,
            ],
        ],
        'TAGGING_LIMIT' => 25,
        'DOCKER_TIMEOUT' => 60,
        // Feature 012: token lifetimes of mailcow's OAuth2 provider (seconds).
        'ACCESS_TOKEN_LIFETIME' => 86400,
        'REFRESH_TOKEN_LIFETIME' => 2678400,
        'SHOW_LAST_LOGIN' => true,
        // Merged over the built-in mailbox defaults (feature 003 research R5).
        'MAILBOX_DEFAULT_ATTRIBUTES' => [
            'tls_enforce_in' => false,
            'tls_enforce_out' => false,
            'force_pw_update' => false,
            'force_tfa' => false,
            'sogo_access' => true,
            'tagged_mail_handler' => 'none',
            'quarantine_notification' => 'hourly',
            'imap_access' => true,
            'pop3_access' => true,
            'smtp_access' => true,
            'sieve_access' => true,
            'eas_access' => true,
            'dav_access' => true,
            'quarantine_category' => 'reject',
            'mailbox_format' => 'maildir:',
        ],
        'ALIAS_DISABLE_EXTERNAL_DOMAINS' => false,
        // Feature 010: private keys are never shown (FR-026). $autodiscover_config has no fixed
        // default: App\Services\Mailcow\AutodiscoverConfig derives it from the port variables.
        'SHOW_DKIM_PRIV_KEYS' => false,
        'autodiscover_config' => [],
        // Feature 011: unknown unless app_info.inc.php or a server setting gives them.
        'MAILCOW_GIT_VERSION' => null,
        'MAILCOW_GIT_OWNER' => null,
        'MAILCOW_GIT_REPO' => null,
        'MAILCOW_GIT_URL' => null,
        'MAILCOW_GIT_COMMIT' => null,
        'MAILCOW_GIT_COMMIT_DATE' => null,
        'MAILCOW_BRANCH' => null,
        // $IMAPSYNC_OPTIONS of vars.inc.php at 2026-09a, verbatim: 114 allowed and 8 blocked
        // options (feature 008 research R7). Legacy checks only the whitelist.
        'IMAPSYNC_OPTIONS' => [
            'whitelist' => [
                'abort', 'authmd51', 'authmd52', 'authmech1', 'authmech2', 'authuser1', 'authuser2',
                'debug', 'debugcontent', 'debugcrossduplicates', 'debugflags', 'debugfolders',
                'debugimap', 'debugimap1', 'debugimap2', 'debugmemory', 'debugssl',
                'delete1emptyfolders', 'delete2folders', 'disarmreadreceipts', 'domain1', 'domain2',
                'domino1', 'domino2', 'dry', 'errorsmax', 'exchange1', 'exchange2', 'exitwhenover',
                'expunge1', 'f1f2', 'filterbuggyflags', 'folder', 'folderfirst', 'folderlast',
                'folderrec', 'gmail1', 'gmail2', 'idatefromheader', 'include', 'inet4', 'inet6',
                'justconnect', 'justfolders', 'justfoldersizes', 'justlogin', 'keepalive1',
                'keepalive2', 'log', 'logdir', 'logfile', 'maxbytesafter', 'maxlinelength',
                'maxmessagespersecond', 'maxsize', 'maxsleep', 'minage', 'minsize', 'noabletosearch',
                'noabletosearch1', 'noabletosearch2', 'noexpunge1', 'noexpunge2',
                'nofoldersizesatend', 'noid', 'nolog', 'nomixfolders', 'noresyncflags', 'nossl1',
                'nossl2', 'nosyncacls', 'notls1', 'notls2', 'nouidexpunge2', 'nousecache',
                'oauthaccesstoken1', 'oauthaccesstoken2', 'oauthdirect1', 'oauthdirect2', 'office1',
                'office2', 'pidfile', 'pidfilelocking', 'prefix1', 'prefix2', 'proxyauth1',
                'proxyauth2', 'resyncflags', 'resynclabels', 'search', 'search1', 'search2', 'sep1',
                'sep2', 'showpasswords', 'skipemptyfolders', 'ssl2', 'sslargs1', 'sslargs2',
                'subfolder1', 'subscribe', 'subscribed', 'syncacls', 'syncduplicates',
                'syncinternaldates', 'synclabels', 'tests', 'testslive', 'testslive6', 'tls2',
                'truncmess', 'usecache', 'useheader', 'useuid',
            ],
            'blacklist' => [
                'skipmess', 'delete2foldersonly', 'delete2foldersbutnot', 'regexflag', 'regexmess',
                'pipemess', 'regextrans2', 'maxlinelengthcmd',
            ],
        ],
        'PW_RESET_TOKEN_LIFETIME' => 15,
        'PW_RESET_TOKEN_LIMIT' => 3,
        'WEBAUTHN_UV_FLAG_REGISTER' => false,
        'SESSION_NAME' => 'MCSESSID',
        'SESSION_SAMESITE_POLICY' => 'Lax',
    ],

];
