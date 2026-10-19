<?php

namespace App\Services\Settings;

use App\Support\Http\ListQuery;

/**
 * The field limits of the system settings (contract `SettingsLimits`, research R23): the single
 * source of the Form Requests and of `GET /capabilities/settings`. Sizes are bytes of UTF-8,
 * lengths are characters.
 */
final class SettingsLimits
{
    public const SINGLE_LINE_TEXT_MAX_LENGTH = 255;

    public const HTML_TEXT_MAX_LENGTH = 65535;

    public const TEMPLATE_MAX_BYTES = 1048576;

    public const RSPAMD_RULE_CONTENT_MAX_BYTES = 1048576;

    public const RSPAMD_REGEX_MAP_MAX_BYTES = 1048576;

    public const SIEVE_SCRIPT_MAX_BYTES = 1048576;

    public const APP_LINKS_MAX = 50;

    public const QUOTA_NOTIFICATION_RECIPIENTS_MAX = 100;

    public const CORS_ORIGINS_MAX = 100;

    public const PASSWORD_POLICY_MIN_LENGTH = 3;

    public const PASSWORD_POLICY_MAX_LENGTH = 64;

    public const RSPAMD_UI_PASSWORD_MIN_LENGTH = 6;

    public const RSPAMD_UI_PASSWORD_MAX_LENGTH = 256;

    public const MASS_MAIL_SELECTION_ENTRIES_MAX = 1000;

    public const MASS_MAIL_BODY_MAX_BYTES = 1048576;

    /** Retries of a delivery that Dovecot refused temporarily, and their period (FR-076). */
    public const MASS_MAIL_MAX_RETRIES = 3;

    public const MASS_MAIL_RETRY_WINDOW_SECONDS = 300;

    /** Delays before the retries of attempts 1 to 3 (research R17). */
    public const MASS_MAIL_RETRY_DELAYS = [30, 60, 120];

    public const MASS_MAIL_MAX_RUNNING_JOBS = 1;

    /**
     * Contract `SettingsLimits`.
     *
     * @return array<string, int>
     */
    public static function toArray(): array
    {
        return [
            'singleLineTextMaxLength' => self::SINGLE_LINE_TEXT_MAX_LENGTH,
            'htmlTextMaxLength' => self::HTML_TEXT_MAX_LENGTH,
            'templateMaxBytes' => self::TEMPLATE_MAX_BYTES,
            'rspamdRuleContentMaxBytes' => self::RSPAMD_RULE_CONTENT_MAX_BYTES,
            'rspamdRegexMapMaxBytes' => self::RSPAMD_REGEX_MAP_MAX_BYTES,
            'sieveScriptMaxBytes' => self::SIEVE_SCRIPT_MAX_BYTES,
            'appLinksMax' => self::APP_LINKS_MAX,
            'quotaNotificationRecipientsMax' => self::QUOTA_NOTIFICATION_RECIPIENTS_MAX,
            'corsOriginsMax' => self::CORS_ORIGINS_MAX,
            'passwordPolicyMinLength' => self::PASSWORD_POLICY_MIN_LENGTH,
            'passwordPolicyMaxLength' => self::PASSWORD_POLICY_MAX_LENGTH,
            'rspamdUiPasswordMinLength' => self::RSPAMD_UI_PASSWORD_MIN_LENGTH,
            'rspamdUiPasswordMaxLength' => self::RSPAMD_UI_PASSWORD_MAX_LENGTH,
            'defaultPageSize' => ListQuery::DEFAULT_LIMIT,
            'maxPageSize' => ListQuery::MAX_LIMIT,
        ];
    }
}
