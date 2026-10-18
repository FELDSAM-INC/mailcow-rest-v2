<?php

namespace App\Support\Clock;

/**
 * Waits before a response (sign-in throttling delays, FR-007). Faked in tests.
 */
interface Sleeper
{
    public function sleepSeconds(float $seconds): void;
}
