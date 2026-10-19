<?php

namespace App\Http\Requests\Api\V2;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Read-only members sent back in a merge patch (feature 007 FR-021): accepted when equal to the
 * current value, refused with `immutable` otherwise. Times compare as instants, so that any RFC
 * 3339 spelling of the current time is accepted.
 */
final class ReadOnlyMembers
{
    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $current
     * @param  list<string>  $members
     * @param  bool  $requirePresence  false: every sent member differs (an object that does not exist yet)
     * @return array<string, array{code: string, params: array<string, mixed>, message: string}>
     */
    public static function violations(array $body, array $current, array $members, bool $requirePresence = true): array
    {
        $violations = [];

        foreach ($members as $member) {
            if (! array_key_exists($member, $body)) {
                continue;
            }

            if (! $requirePresence || ! array_key_exists($member, $current) || ! self::equal($body[$member], $current[$member])) {
                $violations[$member] = ApiFormRequest::make('immutable', []);
            }
        }

        return $violations;
    }

    private static function equal(mixed $sent, mixed $current): bool
    {
        if ($sent === $current) {
            return true;
        }

        if (is_string($sent) && is_string($current) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $current) === 1) {
            try {
                return CarbonImmutable::parse($sent)->equalTo(CarbonImmutable::parse($current));
            } catch (Throwable) {
                return false;
            }
        }

        return false;
    }
}
