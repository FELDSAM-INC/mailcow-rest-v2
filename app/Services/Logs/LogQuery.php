<?php

namespace App\Services\Logs;

use Carbon\CarbonImmutable;

/**
 * One log list request (feature 011 FR-024, FR-025): paging, order, search, time range, the
 * `before` cursor, severity classes and the type-specific filters (field => values; any value
 * matches as a case-insensitive substring).
 */
final readonly class LogQuery
{
    /**
     * @param  list<string>  $severities
     * @param  array<string, list<string>>  $filters
     */
    public function __construct(
        public LogType $type,
        public int $limit = 25,
        public int $offset = 0,
        public string $order = 'desc',
        public ?string $search = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public ?string $before = null,
        public array $severities = [],
        public array $filters = [],
    ) {}
}
