<?php

namespace App\Services\Logs;

/**
 * A page of log entries and the number of matching retained entries (after `before` and the
 * filters).
 */
final readonly class LogPage
{
    /**
     * @param  list<array<string, mixed>>  $entries
     */
    public function __construct(public array $entries, public int $total) {}
}
