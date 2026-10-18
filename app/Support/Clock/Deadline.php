<?php

namespace App\Support\Clock;

use Carbon\CarbonImmutable;

/**
 * A time budget that starts when it is created (feature 010 research R11): for example the 15
 * seconds of one ISPConfig publication, shared by all of its calls.
 */
final class Deadline
{
    private readonly CarbonImmutable $start;

    public function __construct(private readonly Clock $clock, public readonly float $limitSeconds)
    {
        $this->start = $clock->now();
    }

    /** Seconds left, never below zero. */
    public function remaining(): float
    {
        $elapsed = ($this->clock->now()->getTimestampMs() - $this->start->getTimestampMs()) / 1000;

        return max(0.0, $this->limitSeconds - $elapsed);
    }

    public function expired(): bool
    {
        return $this->remaining() <= 0.0;
    }
}
