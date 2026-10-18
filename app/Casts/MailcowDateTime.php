<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * mailcow's DATETIME columns hold local time of the mailcow server (TZ), because MariaDB's NOW()
 * uses the container's time zone (research R16). This cast reads them in `restv2.timezone` and
 * returns UTC; it writes local time back.
 *
 * @implements CastsAttributes<CarbonImmutable|null, DateTimeInterface|string|null>
 */
class MailcowDateTime implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null || $value === '' || str_starts_with((string) $value, '0000-00-00')) {
            return null;
        }

        return CarbonImmutable::parse((string) $value, self::zone())->utc();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $time = $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse((string) $value, 'UTC');

        return $time->setTimezone(self::zone())->format('Y-m-d H:i:s');
    }

    /**
     * A raw mailcow DATETIME value as an RFC 3339 UTC string (`Y-m-d\TH:i:s\Z`), or null for an
     * empty or zero value; for query-builder rows that bypass the cast (feature 005).
     */
    public static function toUtcString(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        return CarbonImmutable::parse($value, self::zone())->utc()->format('Y-m-d\TH:i:s\Z');
    }

    public static function zone(): string
    {
        return (string) (config('restv2.timezone') ?: 'UTC');
    }
}
