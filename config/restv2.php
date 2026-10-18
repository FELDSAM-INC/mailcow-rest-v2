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
    ],

];
