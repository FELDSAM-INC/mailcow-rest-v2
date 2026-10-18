<?php

namespace App\Services\DnsDiagnostics;

use RuntimeException;

/**
 * A lookup of a run did not complete (feature 010 FR-048): its reason is `lookupTimeout`,
 * `lookupError` or `runTimeout`.
 */
final class LookupFailed extends RuntimeException
{
    public function __construct(public readonly DnsCheckMessage $reason)
    {
        parent::__construct($reason->message);
    }
}
