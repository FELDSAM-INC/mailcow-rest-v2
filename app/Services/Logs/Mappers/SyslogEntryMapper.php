<?php

namespace App\Services\Logs\Mappers;

use App\Services\Logs\Base64Message;
use App\Services\Logs\LogSeverity;

/** Postfix, Dovecot and SOGo (syslog-ng `format-json`: time, priority, program, message). */
class SyslogEntryMapper implements LogEntryMapper
{
    use MapsLogValues;

    public function map(array $line): ?array
    {
        $priority = self::text($line['priority'] ?? null);

        return [
            'time' => self::time($line['time']),
            'priority' => $priority,
            'severity' => LogSeverity::of($priority),
            'program' => self::text($line['program'] ?? null),
            'message' => Base64Message::decode(self::text($line['message'] ?? null)),
        ];
    }
}
