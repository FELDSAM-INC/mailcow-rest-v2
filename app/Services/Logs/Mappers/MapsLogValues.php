<?php

namespace App\Services\Logs\Mappers;

/**
 * Value conversions shared by the log mappers: Unix times as RFC 3339 UTC, texts that may be
 * absent, and numbers that legacy stores as text.
 */
trait MapsLogValues
{
    private static function time(mixed $value): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', (int) $value);
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function optionalText(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private static function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
