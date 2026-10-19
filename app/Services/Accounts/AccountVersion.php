<?php

namespace App\Services\Accounts;

use App\Support\Http\ETag;
use stdClass;

/**
 * The version tag of an administrator or domain administrator account (feature 007 FR-064,
 * research R3): feature 001's strong ETag (an HMAC of the canonical values, keyed with the app key)
 * over the stored settings:
 *
 *  - administrators: the role, `username`, `created`, `active` and the forced flags
 *    (`force_pw_update`, `force_tfa`);
 *  - domain administrators: the same, plus the assignments ordered by domain as
 *    `[domain, active, created]` and the 19 `da_acl` values in column order, or null without a row.
 *
 * It never covers `modified`, the password hash, `tfa` or `fido2`: neither the second-factor status
 * nor the passkey count changes it. A change made by either UI changes it, and `created` keeps a tag
 * read from a deleted account from matching a later account with the same name and values.
 */
final class AccountVersion
{
    public static function forAdmin(stdClass $row): string
    {
        return ETag::of(['admin', ...self::account($row)]);
    }

    /**
     * @param  iterable<stdClass>  $assignments  `domain_admins` rows (`domain`, `active`, `created`)
     */
    public static function forDomainAdmin(stdClass $row, iterable $assignments, ?stdClass $daAcl): string
    {
        $rows = [];

        foreach ($assignments as $assignment) {
            $rows[] = [(string) $assignment->domain, (int) $assignment->active === 1, (string) $assignment->created];
        }

        usort($rows, fn (array $a, array $b): int => [$a[0], $a[2]] <=> [$b[0], $b[2]]);

        $flags = null;

        if ($daAcl !== null) {
            $flags = [];

            foreach (DomainAdminFlags::COLUMNS as $column) {
                $flags[] = (int) ($daAcl->{$column} ?? 0);
            }
        }

        return ETag::of(['domainAdmin', ...self::account($row), $rows, $flags]);
    }

    /**
     * Whether a forced flag of the raw `admin.attributes` is set (legacy `intval(...) == 1`; a
     * missing attribute reads as off).
     */
    public static function attribute(mixed $attributes, string $name): bool
    {
        $decoded = is_string($attributes) ? json_decode($attributes, true) : $attributes;
        $value = is_array($decoded) ? ($decoded[$name] ?? 0) : 0;

        return is_scalar($value) && (int) $value === 1;
    }

    /**
     * @return list<mixed>
     */
    private static function account(stdClass $row): array
    {
        return [
            (string) $row->username,
            (string) $row->created,
            (int) $row->active === 1,
            self::attribute($row->attributes ?? null, 'force_pw_update'),
            self::attribute($row->attributes ?? null, 'force_tfa'),
        ];
    }
}
