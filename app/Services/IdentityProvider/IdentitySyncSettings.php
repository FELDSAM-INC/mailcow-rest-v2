<?php

namespace App\Services\IdentityProvider;

/** Public settings mapped to the names consumed by mailcow's identity cron. */
final class IdentitySyncSettings
{
    public const KEYS = [
        'offboarding' => 'offboarding', 'offboardingLimit' => 'offboarding_limit',
        'groupSync' => 'group_sync', 'groupAutocreate' => 'group_autocreate',
        'groupDefaultDomain' => 'group_default_domain', 'groupPaths' => 'group_paths',
        'idAttribute' => 'id_attribute', 'disabledFilter' => 'disabled_filter',
        'groupBaseDn' => 'group_basedn', 'groupFilter' => 'group_filter',
        'groupNameAttribute' => 'group_name_attribute', 'groupMemberMode' => 'group_member_mode',
        'groupMemberAttribute' => 'group_member_attribute',
    ];

    public const DEFAULTS = [
        'offboarding' => false, 'offboardingLimit' => 10, 'groupSync' => false,
        'groupAutocreate' => true, 'groupDefaultDomain' => '', 'groupPaths' => [],
        'idAttribute' => '', 'disabledFilter' => '', 'groupBaseDn' => '', 'groupFilter' => '',
        'groupNameAttribute' => 'cn', 'groupMemberMode' => 'memberof', 'groupMemberAttribute' => 'member',
    ];

    public const LDAP_FIELDS = ['idAttribute', 'disabledFilter', 'groupBaseDn', 'groupFilter',
        'groupNameAttribute', 'groupMemberMode', 'groupMemberAttribute'];

    /** @param array<string, string> $rows
     * @return array<string, mixed>
     */
    public static function read(array $rows): array
    {
        $result = self::DEFAULTS;
        foreach (self::KEYS as $member => $key) {
            if (! array_key_exists($key, $rows)) {
                continue;
            }
            $result[$member] = match (true) {
                is_bool(self::DEFAULTS[$member]) => filter_var($rows[$key], FILTER_VALIDATE_BOOLEAN),
                $member === 'offboardingLimit' => max(0, min(100, (int) $rows[$key])),
                $member === 'groupPaths' => array_values(array_filter(array_map('trim', explode(',', $rows[$key])), static fn (string $value): bool => $value !== '')),
                default => $rows[$key],
            };
        }

        return $result;
    }

    public static function encode(string $member, mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            $member === 'groupPaths' => implode(',', (array) $value),
            default => (string) $value,
        };
    }
}
