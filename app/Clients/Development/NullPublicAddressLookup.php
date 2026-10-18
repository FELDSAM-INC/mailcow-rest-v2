<?php

namespace App\Clients\Development;

use App\Clients\PublicAddress\PublicAddressLookup;

/**
 * Development stand-in for mailcow's IP check service (RESTV2_FAKE_OUTBOUND=y with APP_ENV=local):
 * documentation addresses, no outbound request.
 */
final class NullPublicAddressLookup implements PublicAddressLookup
{
    public function lookup(): array
    {
        return ['ipv4' => '198.51.100.7', 'ipv6' => '2001:db8::7'];
    }
}
