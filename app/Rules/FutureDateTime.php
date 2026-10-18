<?php

namespace App\Rules;

/**
 * An RFC 3339 date-time strictly after now (`mustBeInFuture`; `invalidFormat` with
 * `format: dateTime` when it is no RFC 3339 date-time).
 */
class FutureDateTime extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        $time = is_string($value) ? Rfc3339DateTime::parse($value) : null;

        if ($time === null) {
            return $this->violation('invalidFormat', ['format' => 'dateTime']);
        }

        return $time->isFuture() ? null : $this->violation('mustBeInFuture');
    }
}
