<?php

namespace App\Services\Logs\Mappers;

use App\Services\Logs\LogSeverity;

/** Identity-provider sync tasks (keycloak-sync.php, ldap-sync.php: time, priority, task, message). */
class CronEntryMapper implements LogEntryMapper
{
    use MapsLogValues;

    public function map(array $line): ?array
    {
        $priority = self::text($line['priority'] ?? null);

        return [
            'time' => self::time($line['time']),
            'task' => self::text($line['task'] ?? null),
            'priority' => $priority,
            'severity' => LogSeverity::of($priority),
            'message' => self::text($line['message'] ?? null),
        ];
    }
}
