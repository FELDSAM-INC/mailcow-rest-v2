<?php

namespace Tests\Support\Clock;

use App\Support\Clock\Sleeper;

/**
 * Records the sign-in throttling delays instead of waiting (FR-007). Bound by Tests\TestCase for
 * every test, so that no test sleeps for real.
 */
final class FakeSleeper implements Sleeper
{
    /** @var list<float> */
    public array $slept = [];

    public function sleepSeconds(float $seconds): void
    {
        $this->slept[] = $seconds;
    }
}
