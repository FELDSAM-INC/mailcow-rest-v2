<?php

namespace App\Services\Logs\Mappers;

use App\Services\Logs\LogSeverity;

/** The ban service log (netfilter's Logger: time, priority `crit`/`warn`/`info`, message). */
class NetfilterEntryMapper implements LogEntryMapper
{
    use MapsLogValues;

    public function map(array $line): ?array
    {
        $priority = self::text($line['priority'] ?? null);

        return [
            'time' => self::time($line['time']),
            'priority' => $priority,
            'severity' => LogSeverity::of($priority),
            'message' => self::text($line['message'] ?? null),
        ];
    }
}
