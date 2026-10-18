<?php

namespace App\Services\DnsDiagnostics;

/**
 * The outcome of comparing found records with an expected value (feature 010 FR-043): a status,
 * the values found in comparison form, a reason and notes.
 */
final readonly class DnsComparison
{
    /**
     * @param  list<string>  $found
     * @param  list<DnsCheckMessage>  $notes
     */
    public function __construct(
        public string $status,
        public array $found = [],
        public ?DnsCheckMessage $reason = null,
        public array $notes = [],
    ) {}
}
