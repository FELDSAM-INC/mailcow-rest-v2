<?php

namespace App\Services\Mailboxes;

use App\Services\RateLimits\RateLimitStore;

/**
 * The mappings between mailcow's stored mailbox values and the contract (feature 003 research R1,
 * R2): statuses, authentication sources, protocol attributes, quarantine values and rate limit
 * frames, and the tolerant reading of legacy flags (`"1"`, `1`, `true`).
 */
final class MailboxValues
{
    /** Contract status => `mailbox.active`. */
    public const STATUSES = ['active' => 1, 'loginDisabled' => 2, 'inactive' => 0];

    /** Contract authentication source => `mailbox.authsource`. */
    public const AUTH_SOURCES = ['mailcow' => 'mailcow', 'keycloak' => 'keycloak', 'genericOidc' => 'generic-oidc', 'ldap' => 'ldap'];

    /** Contract protocol => `attributes` key. */
    public const PROTOCOLS = [
        'imap' => 'imap_access',
        'pop3' => 'pop3_access',
        'smtp' => 'smtp_access',
        'sieve' => 'sieve_access',
        'activeSync' => 'eas_access',
        'dav' => 'dav_access',
    ];

    /** Contract quarantine category => stored value. */
    public const QUARANTINE_CATEGORIES = ['rejected' => 'reject', 'junk' => 'add_header', 'all' => 'all'];

    public const QUARANTINE_NOTIFICATIONS = ['never', 'hourly', 'daily', 'weekly'];

    public const TAGGED_MAIL_HANDLING = ['none', 'subfolder', 'subject'];

    public static function status(mixed $active): string
    {
        return match ((int) $active) {
            1 => 'active',
            2 => 'loginDisabled',
            default => 'inactive',
        };
    }

    public static function authSource(mixed $stored): string
    {
        $source = array_search((string) $stored, self::AUTH_SOURCES, true);

        return $source === false ? 'mailcow' : $source;
    }

    public static function isExternal(string $authSource): bool
    {
        return $authSource !== 'mailcow';
    }

    /** A legacy flag (`"1"`, `1`, `true`, `"0"`, ...) as a boolean; null when it is not one. */
    public static function flag(mixed $value): ?bool
    {
        return match (true) {
            is_bool($value) => $value,
            is_int($value), is_float($value) => (int) $value === 1,
            is_string($value) && is_numeric(trim($value)) => (int) trim($value) === 1,
            default => null,
        };
    }

    public static function quarantineCategory(mixed $stored): ?string
    {
        $category = array_search((string) $stored, self::QUARANTINE_CATEGORIES, true);

        return $category === false ? null : $category;
    }

    /**
     * A rate limit from legacy template keys (`rl_value` empty or 0 means none).
     *
     * @return array{value: int, frame: string}|null
     */
    public static function rateLimitFromTemplate(mixed $value, mixed $frame): ?array
    {
        if (! is_numeric($value) || (int) $value < 1) {
            return null;
        }

        $name = array_search((string) $frame, RateLimitStore::FRAMES, true);

        return ['value' => (int) $value, 'frame' => $name === false ? 'second' : $name];
    }
}
