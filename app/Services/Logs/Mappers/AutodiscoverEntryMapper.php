<?php

namespace App\Services\Logs\Mappers;

/** Client auto-configuration requests (autodiscover.php: time, ua, user, ip, service). */
class AutodiscoverEntryMapper implements LogEntryMapper
{
    use MapsLogValues;

    public function map(array $line): ?array
    {
        return [
            'time' => self::time($line['time']),
            'userAgent' => self::optionalText($line['ua'] ?? null),
            'user' => self::optionalText($line['user'] ?? null),
            'clientAddress' => self::optionalText($line['ip'] ?? null),
            'service' => self::text($line['service'] ?? null),
        ];
    }
}
