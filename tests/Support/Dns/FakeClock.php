<?php

namespace Tests\Support\Dns;

use App\Support\Clock\Clock;
use Carbon\CarbonImmutable;

/**
 * A clock that only moves when the test advances it (feature 010 T015). Bound for every test, so
 * that deadlines (ISPConfig publication, DNS diagnostics) are deterministic.
 */
final class FakeClock implements Clock
{
    private CarbonImmutable $now;

    public function __construct(?CarbonImmutable $start = null)
    {
        $this->now = $start ?? CarbonImmutable::now('UTC');
    }

    public function now(): CarbonImmutable
    {
        return $this->now;
    }

    public function advance(float $seconds): void
    {
        $this->now = $this->now->addMicroseconds((int) round($seconds * 1_000_000));
    }
}
