<?php

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
*/

return [

    // sasl_log.service label of UI sign-ins (FR-027). FeldHost: WEBUI.
    'sasl_log_service' => env('RESTV2_SASL_LOG_SERVICE', 'MAILCOWUI'),

    // Default page title and product name; empty means "<lower(MAILCOW_HOSTNAME)> - mail UI"
    // (FR-043). FeldHost: "FeldHost™ Mailhosting UI".
    'default_ui_name' => (string) env('RESTV2_DEFAULT_UI_NAME', ''),

    // Default logos when mailcow has none (FeldHost: /img/feldhost_logo.png and
    // /img/feldhost_logo_white.png).
    'default_logo_url' => env('RESTV2_DEFAULT_LOGO_URL', '/img/cow_mailcow.svg'),
    'default_logo_dark_url' => env('RESTV2_DEFAULT_LOGO_DARK_URL', '/img/cow_mailcow.svg'),

    // Sign-in throttling (FR-007, R17).
    'signin_max_failures' => (int) env('RESTV2_SIGNIN_MAX_FAILURES', 10),
    'signin_failure_window' => (int) env('RESTV2_SIGNIN_FAILURE_WINDOW', 900),

    // Lifetime in seconds of a pending sign-in, a WebAuthn challenge and registration options.
    'challenge_ttl' => (int) env('RESTV2_CHALLENGE_TTL', 300),

    // Audit retention (FR-048).
    'audit_retention_days' => (int) env('RESTV2_AUDIT_RETENTION_DAYS', 365),

    // Yubico OTP verification endpoint (R11).
    'yubico_verify_url' => env('RESTV2_YUBICO_VERIFY_URL', 'https://api.yubico.com/wsapi/2.0/verify'),

    // Mount point of mailcow's data/web/inc (read-only). Relative paths are resolved against the
    // application base path. Empty means "not mounted": the upstream defaults apply.
    'mailcow_inc_dir' => (string) env('RESTV2_MAILCOW_INC_DIR', '/mailcow/web/inc'),

    // versions.db_schema values of the supported mailcow releases (README).
    'supported_schema_versions' => ['18082026_1200'],

    // mailcow.conf settings.
    'mailcow_hostname' => env('MAILCOW_HOSTNAME'),
    'additional_server_names' => (string) env('ADDITIONAL_SERVER_NAMES', ''),
    'ipv4_network' => env('IPV4_NETWORK', '172.22.1'),
    'ipv6_network' => env('IPV6_NETWORK', 'fd4d:6169:6c63:6f77::/64'),
    'webauthn_only_trusted_vendors' => env('WEBAUTHN_ONLY_TRUSTED_VENDORS', 'n') === 'y',
    'skip_sogo' => env('SKIP_SOGO', 'n') === 'y',
    'timezone' => env('TZ', 'UTC'),

    // dockerapi container lookup (feature 002 research D14): the compose project of mailcow.
    'compose_project_name' => env('COMPOSE_PROJECT_NAME'),

    /*
    |----------------------------------------------------------------------
    | Outbound services of features 002 and 010 (002 research D24, 010 research R24)
    |----------------------------------------------------------------------
    */

    // mailcow's container service (legacy docker()), reached without TLS verification like legacy.
    'dockerapi' => [
        'url' => rtrim((string) env('RESTV2_DOCKERAPI_URL', 'https://dockerapi:443'), '/'),
    ],

    // The groupware cache flushed after every groupware directory rebuild (legacy flush_memcached()).
    'memcached' => [
        'host' => (string) env('RESTV2_MEMCACHED_HOST', 'memcached'),
        'port' => (int) env('RESTV2_MEMCACHED_PORT', 11211),
    ],

    'dkim' => [
        // Selector of new DKIM keys when neither the request nor the template gives one (002 FR-028).
        // Upstream: dkim. FeldHost: feldhost.
        'default_selector' => (string) env('RESTV2_DEFAULT_DKIM_SELECTOR', 'dkim'),
        'default_key_size' => 2048,
        'allowed_key_sizes' => [1024, 2048, 3072, 4096],
        // FeldHost ISPConfig DNS publication of DKIM records (002 FR-027, 010 FR-028). Off by default;
        // enabled only together with the ISPConfig URL, user and password.
        'dns_publication' => env('RESTV2_DKIM_DNS_PUBLICATION', 'n') === 'y',
    ],

    // ISPConfig JSON remote API (…/remote/json.php) for the DKIM DNS publication. The password is a
    // secret: never logged, returned or cached.
    'ispconfig' => [
        'url' => (string) env('RESTV2_ISPCONFIG_URL', ''),
        'username' => (string) env('RESTV2_ISPCONFIG_USERNAME', ''),
        'password' => (string) env('RESTV2_ISPCONFIG_PASSWORD', ''),
        'dns_server_id' => (int) env('RESTV2_ISPCONFIG_DNS_SERVER_ID', 1),
        'verify_tls' => env('RESTV2_ISPCONFIG_VERIFY_TLS', 'y') !== 'n',
        'timeout' => (int) env('RESTV2_ISPCONFIG_TIMEOUT', 15),
    ],

    /*
    |----------------------------------------------------------------------
    | Mailboxes (feature 003 research R23)
    |----------------------------------------------------------------------
    */

    // Hash scheme of new mailbox passwords (mailcow.conf; legacy hash_password()): BLF-CRYPT,
    // SSHA, SSHA256 or SSHA512; any other value falls back to BLF-CRYPT.
    'mailcow_pass_scheme' => (string) env('MAILCOW_PASS_SCHEME', 'BLF-CRYPT'),

    // Minutes mailcow keeps the mail of a deleted mailbox in the garbage area (mailcow.conf).
    'maildir_gc_time' => max(0, (int) env('MAILDIR_GC_TIME', 7200)),

    // `replication`: maildir moves are confirmed on this node and broadcast to every mail node.
    'cluster_mode' => (string) env('CLUSTERMODE', ''),

    // Usage figures come from `quota2` on the master node and from `quota2replica` otherwise
    // (legacy matches MASTER with /y|yes/i).
    'master' => preg_match('/y|yes/i', (string) env('MASTER', 'y')) === 1,

    // Custom attributes per mailbox (1 to 100; a setting can lower the limit, never raise it).
    'mailbox_custom_attribute_limit' => max(1, min(100, (int) env('RESTV2_MAILBOX_CUSTOM_ATTRIBUTE_LIMIT', 100))),

    // Targets per mailbox bulk action (1 to 100; a setting can lower the limit, never raise it).
    'bulk_limit' => max(1, min(100, (int) env('RESTV2_BULK_LIMIT', 100))),

    // Development only (APP_ENV=local): binds the dockerapi, memcached and ISPConfig fakes, because
    // the dev stack runs none of these services. Ignored in every other environment.
    'fake_outbound' => env('RESTV2_FAKE_OUTBOUND', 'n') === 'y',

    /*
    |----------------------------------------------------------------------
    | Legacy PHP configuration
    |----------------------------------------------------------------------
    |
    | Each exported vars.inc.php variable maps to its RESTV2_<NAME> server setting. Arrays are
    | given as JSON, flags as true/false or a string. Later features add their variables here and
    | in vars_defaults; App\Services\Config\MailcowConfig exports exactly these names.
    |
    */
    'vars_overrides' => [
        'SESSION_LIFETIME' => env('RESTV2_SESSION_LIFETIME'),
        'DEFAULT_LANG' => env('RESTV2_DEFAULT_LANG'),
        'AVAILABLE_LANGUAGES' => env('RESTV2_AVAILABLE_LANGUAGES'),
        'FIDO2_UV_FLAG_REGISTER' => env('RESTV2_FIDO2_UV_FLAG_REGISTER'),
        'FIDO2_UV_FLAG_LOGIN' => env('RESTV2_FIDO2_UV_FLAG_LOGIN'),
        'FIDO2_USER_PRESENT_FLAG' => env('RESTV2_FIDO2_USER_PRESENT_FLAG'),
        'FIDO2_FORMATS' => env('RESTV2_FIDO2_FORMATS'),
        'WEBAUTHN_UV_FLAG_LOGIN' => env('RESTV2_WEBAUTHN_UV_FLAG_LOGIN'),
        'WEBAUTHN_USER_PRESENT_FLAG' => env('RESTV2_WEBAUTHN_USER_PRESENT_FLAG'),
        'OTP_LABEL' => env('RESTV2_OTP_LABEL'),
        'MAILCOW_APPS' => env('RESTV2_MAILCOW_APPS'),
        'TAGGING_LIMIT' => env('RESTV2_TAGGING_LIMIT'),
        'DOCKER_TIMEOUT' => env('RESTV2_DOCKER_TIMEOUT'),
        'SHOW_LAST_LOGIN' => env('RESTV2_SHOW_LAST_LOGIN'),
        'MAILBOX_DEFAULT_ATTRIBUTES' => env('RESTV2_MAILBOX_DEFAULT_ATTRIBUTES'),
        // Feature 004: external alias destinations disabled for every role (FR-007).
        'ALIAS_DISABLE_EXTERNAL_DOMAINS' => env('RESTV2_ALIAS_DISABLE_EXTERNAL_DOMAINS'),
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
    ],

];
