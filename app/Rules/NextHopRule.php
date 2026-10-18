<?php

namespace App\Rules;

use App\Support\Routing\NextHop;

/**
 * A relayhost host or a transport next hop (feature 009 FR-019): `required`, `tooLong` (`max: 255`)
 * or `invalidFormat` (`format: nextHop`). The stored form is App\Support\Routing\NextHop's.
 */
class NextHopRule extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        return NextHop::violation($value);
    }
}
