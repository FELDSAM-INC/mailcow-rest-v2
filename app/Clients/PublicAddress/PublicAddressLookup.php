<?php

namespace App\Clients\PublicAddress;

/**
 * mailcow's external IP check service (feature 011 research R11, legacy `get/status/host/ip`): the
 * server's public addresses as seen from outside. Faked in tests.
 */
interface PublicAddressLookup
{
    /**
     * Asks for both address families in parallel. Each address is null when the service did not
     * answer with a valid address of that family within the timeouts.
     *
     * @return array{ipv4: string|null, ipv6: string|null}
     */
    public function lookup(): array;
}
