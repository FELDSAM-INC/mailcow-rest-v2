<?php

namespace App\Services\Routing;

use App\Support\Http\FieldViolations;

/**
 * The members of a routing merge patch that are not changeable (feature 009 FR-009): a read-only
 * member of the representation is accepted with its current value, so that a client can send back
 * what it read, and refused with `immutable` otherwise; any member the representation does not
 * have is refused with `immutable` too, because a sent value is never ignored.
 */
final class PatchMembers
{
    /**
     * @param  array<string, mixed>  $patch
     * @param  list<string>  $writable
     * @param  array<string, mixed>  $readOnly  member => current represented value
     */
    public static function check(array $patch, array $writable, array $readOnly, FieldViolations $violations): void
    {
        foreach ($patch as $member => $value) {
            $member = (string) $member;

            if (in_array($member, $writable, true)) {
                continue;
            }

            if (array_key_exists($member, $readOnly) && self::canonical($value) === self::canonical($readOnly[$member])) {
                continue;
            }

            $violations->add($member, 'immutable', [], array_key_exists($member, $readOnly) ? 'This value cannot be changed.' : 'This member cannot be set.');
        }
    }

    private static function canonical(mixed $value): string
    {
        return (string) json_encode(self::sorted($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sorted(...), $value);
    }
}
