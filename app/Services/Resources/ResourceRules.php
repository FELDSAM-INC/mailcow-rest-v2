<?php

namespace App\Services\Resources;

use App\Support\Http\FieldViolations;

/**
 * The rules on a resource's description and booking rule (feature 004 FR-046, FR-049, FR-051,
 * research R13): the description is trimmed and must have 1 to 255 characters; the booking rule is
 * stored as `multiple_bookings` (0 always free, -1 unlimited, N from 1 limited), `bookingLimit`
 * required for `limited` and refused with the other rules.
 */
class ResourceRules
{
    public const DESCRIPTION_MAX = 255;

    /** The trimmed description, or null after recording its violation. */
    public static function description(mixed $raw, FieldViolations $violations): ?string
    {
        $description = is_string($raw) ? trim($raw) : '';

        if ($description === '') {
            $violations->add('description', 'required', [], 'The description must contain more than whitespace.');

            return null;
        }

        if (mb_strlen($description) > self::DESCRIPTION_MAX) {
            $violations->add('description', 'tooLong', ['max' => self::DESCRIPTION_MAX]);

            return null;
        }

        return $description;
    }

    /**
     * The stored booking value of a rule and limit, or null after recording a violation.
     * `$currentLimit` is the limit a resource already has, kept when a change sends `limited` alone.
     */
    public static function bookings(string $rule, mixed $limit, bool $limitSent, ?int $currentLimit, FieldViolations $violations): ?int
    {
        if ($rule !== 'limited') {
            if ($limitSent && $limit !== null) {
                $violations->add('bookingLimit', 'conflictsWith', ['field' => 'bookingRule'], 'A booking limit needs the booking rule limited.');

                return null;
            }

            return $rule === 'alwaysFree' ? 0 : -1;
        }

        $limit = $limitSent ? $limit : $currentLimit;

        if (! is_int($limit)) {
            $violations->add('bookingLimit', 'required', [], 'The booking rule limited needs a booking limit.');

            return null;
        }

        return $limit;
    }
}
