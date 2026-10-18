<?php

namespace App\Services\Logs;

/**
 * The severity class of a log priority, as the legacy dashboard groups them (contract
 * `LogSeverity`, feature 011 FR-023).
 */
final class LogSeverity
{
    public const VALUES = ['error', 'warning', 'info'];

    public static function of(?string $priority): ?string
    {
        return match (strtolower((string) $priority)) {
            'emerg', 'alert', 'crit', 'err', 'error' => 'error',
            'warning', 'warn' => 'warning',
            'notice', 'info', 'debug' => 'info',
            default => null,
        };
    }
}
