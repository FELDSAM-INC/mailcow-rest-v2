<?php

namespace App\Rules;

use App\Services\Logs\LogCursor;
use App\Services\Logs\LogType;

/**
 * A `before` cursor of the log's source (feature 011 research R12 to R14): otherwise
 * `invalidFormat` with `format: logCursor`.
 */
class LogCursorFormat extends CodedRule
{
    public function __construct(private readonly LogType $type) {}

    protected function check(mixed $value): ?array
    {
        $valid = is_string($value) && match ($this->type) {
            LogType::LegacyUiActivity => LogCursor::parseRow($value) !== null,
            LogType::Logins => LogCursor::parseLogin($value) !== null,
            default => LogCursor::parseRedis($value) !== null,
        };

        return $valid ? null : $this->violation('invalidFormat', ['format' => 'logCursor'], 'Not a cursor of this log.');
    }
}
