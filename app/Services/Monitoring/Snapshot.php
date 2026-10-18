<?php

namespace App\Services\Monitoring;

use Carbon\CarbonImmutable;

/**
 * Live data collected once for all clients (feature 011 research R5): the payload and when it was
 * measured, which decides its freshness.
 */
final readonly class Snapshot
{
    public function __construct(
        public mixed $payload,
        public CarbonImmutable $collectedAt,
    ) {}

    /** Seconds since the data was measured (never negative). */
    public function age(): float
    {
        return max(0.0, (CarbonImmutable::now('UTC')->getTimestampMs() - $this->collectedAt->getTimestampMs()) / 1000);
    }

    public function collectedAtString(): string
    {
        return $this->collectedAt->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
