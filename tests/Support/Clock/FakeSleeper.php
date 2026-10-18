<?php

namespace Tests\Support\Clock;

use App\Support\Clock\Sleeper;
use Tests\Support\Dns\FakeClock;

/**
 * Records the sign-in throttling delays instead of waiting (FR-007). Bound by Tests\TestCase for
 * every test, so that no test sleeps for real. advancing() lets each sleep move the test clock,
 * for loops bounded by a deadline (feature 011: retries and polls).
 */
final class FakeSleeper implements Sleeper
{
    /** @var list<float> */
    public array $slept = [];

    private ?FakeClock $clock = null;

    public function sleepSeconds(float $seconds): void
    {
        $this->slept[] = $seconds;
        $this->clock?->advance($seconds);
    }

    /** Every later sleep advances the given clock by its duration. */
    public function advancing(FakeClock $clock): self
    {
        $this->clock = $clock;

        return $this;
    }
}
