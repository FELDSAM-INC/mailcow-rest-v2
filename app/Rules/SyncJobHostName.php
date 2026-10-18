<?php

namespace App\Rules;

use App\Support\Domain\DomainName;

/**
 * The remote host of a sync job (feature 008 FR-017, research R6): legacy is_valid_domain_name()
 * (002's port, App\Support\Domain\DomainName), which accepts host names and IPv4 literals and
 * refuses IPv6 literals; like legacy's empty() check, `"0"` is refused. A refusal is
 * `invalidFormat` with `format: hostName`.
 */
class SyncJobHostName extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        return is_string($value) && $value !== '0' && DomainName::isValid($value)
            ? null
            : $this->violation('invalidFormat', ['format' => 'hostName'], 'Not a valid host name or IPv4 address.');
    }
}
