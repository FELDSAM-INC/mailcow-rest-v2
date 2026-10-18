<?php

namespace App\Services\Monitoring;

use App\Clients\PublicAddress\PublicAddressLookup;
use App\Exceptions\ProblemException;
use App\Support\Http\ProblemType;
use Carbon\CarbonImmutable;

/**
 * The server's public addresses (feature 011 research R11, FR-021): only when the IP check of the
 * legacy customisation settings is enabled (`IP_CHECK` = 1); otherwise `409 ip-check-disabled`
 * without an outbound request (legacy asks the service whatever the setting says). Not cached.
 */
class PublicAddressService
{
    public function __construct(
        private readonly MonitoringCapabilitiesService $capabilities,
        private readonly PublicAddressLookup $lookup,
    ) {}

    /**
     * @return array{ipv4: string|null, ipv6: string|null, checkedAt: string}
     */
    public function addresses(): array
    {
        if (! $this->capabilities->ipCheckEnabled()) {
            throw new ProblemException(ProblemType::IpCheckDisabled);
        }

        $checkedAt = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');

        return $this->lookup->lookup() + ['checkedAt' => $checkedAt];
    }
}
