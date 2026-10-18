<?php

namespace App\Support\Clock;

class UsleepSleeper implements Sleeper
{
    public function sleepSeconds(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }
}
