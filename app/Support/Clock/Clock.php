<?php

namespace App\Support\Clock;

use Carbon\CarbonImmutable;

/**
 * The current time, injectable so that deadlines are testable (feature 010 T015). Never sleeps.
 */
interface Clock
{
    public function now(): CarbonImmutable;
}
