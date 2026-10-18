<?php

namespace App\Services\Logs\Mappers;

/**
 * Maps one decoded line of an in-memory log to its contract entry, without `id` (feature 011
 * research R12). The line is a JSON object with a numeric `time`; null means the line cannot be
 * mapped and is left out of the list and the total (FR-032).
 */
interface LogEntryMapper
{
    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>|null
     */
    public function map(array $line): ?array;
}
