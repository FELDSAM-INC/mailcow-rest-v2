<?php

namespace App\Rules;

use App\Support\Network\IpNetwork;

/**
 * A denylist entry of the ban service (feature 011 FR-047, research R19): after trimming, an
 * address or a network in prefix notation (`invalidFormat`, `format: ipNetwork`) that would not ban
 * everything or mailcow's internal networks: not `0.0.0.0`, `0.0.0.0/0`, `::/0`, the internal IPv4
 * network (`<IPV4_NETWORK>.0/24`) or its address, or the internal IPv6 network (`reserved`, compared
 * in canonical form, so `0.0.0.0/00` is refused too).
 */
class Fail2BanDenylistEntry extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        $entry = is_string($value) ? trim($value) : '';

        if ($entry === '') {
            return $this->violation('required');
        }

        if (! IpNetwork::isNetwork($entry)) {
            return $this->violation('invalidFormat', ['format' => 'ipNetwork']);
        }

        $reserved = self::reserved();

        return in_array(IpNetwork::canonical($entry), array_map(IpNetwork::canonical(...), $reserved), true)
            ? $this->violation('reserved', ['reserved' => $reserved], 'This network would ban everything or mailcow itself.')
            : null;
    }

    /**
     * @return list<string>
     */
    public static function reserved(): array
    {
        $ipv4 = rtrim((string) config('restv2.ipv4_network', '172.22.1'), '.');

        return ['0.0.0.0', '0.0.0.0/0', '::/0', $ipv4.'.0/24', $ipv4.'.0', (string) config('restv2.ipv6_network', 'fd4d:6169:6c63:6f77::/64')];
    }
}
