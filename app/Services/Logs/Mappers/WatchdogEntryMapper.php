<?php

namespace App\Services\Logs\Mappers;

/**
 * Health checks (watchdog.sh): a health line (service, lvl, hpnow, hptotal, hpdiff, all text) or a
 * message line.
 */
class WatchdogEntryMapper implements LogEntryMapper
{
    use MapsLogValues;

    public function map(array $line): ?array
    {
        $health = isset($line['service']);

        return [
            'time' => self::time($line['time']),
            'service' => $health ? self::text($line['service']) : null,
            'healthLevelPercent' => $health ? self::integer($line['lvl'] ?? null) : null,
            'healthPointsNow' => $health ? self::integer($line['hpnow'] ?? null) : null,
            'healthPointsTotal' => $health ? self::integer($line['hptotal'] ?? null) : null,
            'healthPointsChange' => $health ? self::integer($line['hpdiff'] ?? null) : null,
            'message' => $health ? null : self::text($line['message'] ?? null),
        ];
    }
}
