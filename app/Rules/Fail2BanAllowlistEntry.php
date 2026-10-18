<?php

namespace App\Rules;

use App\Support\Network\IpNetwork;

/**
 * An allowlist entry of the ban service (feature 011 FR-047, research R19): after trimming, an
 * address, a network in prefix notation or a host name (legacy valid_network() or
 * valid_hostname()); an empty entry is `required`, anything else `invalidFormat` with
 * `format: ipNetworkOrHostname`.
 */
class Fail2BanAllowlistEntry extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        $entry = is_string($value) ? trim($value) : '';

        if ($entry === '') {
            return $this->violation('required');
        }

        return IpNetwork::isNetwork($entry) || IpNetwork::isHostname($entry)
            ? null
            : $this->violation('invalidFormat', ['format' => 'ipNetworkOrHostname'], 'Not an address, a network or a host name.');
    }
}
