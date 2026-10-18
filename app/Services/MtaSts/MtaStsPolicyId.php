<?php

namespace App\Services\MtaSts;

use App\Support\Clock\Clock;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The MTA-STS policy identifier (feature 010 FR-035, research R14): `date('YmdHis')` in mailcow's
 * time zone (TZ), as legacy writes it; when that is not greater than the previous identifier, the
 * previous identifier's time plus one second, so that two changes within one second still give
 * senders a new identifier.
 */
final class MtaStsPolicyId
{
    public static function next(Clock $clock, string $timezone, ?string $previous = null): string
    {
        $now = $clock->now()->setTimezone($timezone);
        $id = $now->format('YmdHis');

        if ($previous === null || preg_match('/^\d{14}$/', $previous) !== 1 || $id > $previous) {
            return $id;
        }

        try {
            return CarbonImmutable::createFromFormat('YmdHis', $previous, $timezone)?->addSecond()->format('YmdHis') ?? $id;
        } catch (Throwable) {
            return $id;
        }
    }
}
