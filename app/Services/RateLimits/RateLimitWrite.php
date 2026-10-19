<?php

namespace App\Services\RateLimits;

/**
 * The outcome of a compare-and-set write of a rate limit (feature 006 research R12).
 */
enum RateLimitWrite
{
    /** The field was written or deleted. */
    case Changed;

    /** A removal of a field that was not set: nothing to do. */
    case Unchanged;

    /** The stored value was not the expected one: nothing was changed. */
    case Outdated;
}
