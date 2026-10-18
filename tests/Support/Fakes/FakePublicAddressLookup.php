<?php

namespace Tests\Support\Fakes;

use App\Clients\PublicAddress\PublicAddressLookup;

/**
 * mailcow's external IP check service without network (feature 011 T008): scripted answers per
 * address family and a call counter.
 */
final class FakePublicAddressLookup implements PublicAddressLookup
{
    private int $calls = 0;

    public function __construct(private ?string $ipv4 = '198.51.100.7', private ?string $ipv6 = '2001:db8::7') {}

    public function lookup(): array
    {
        $this->calls++;

        return ['ipv4' => $this->ipv4, 'ipv6' => $this->ipv6];
    }

    public function withAnswers(?string $ipv4, ?string $ipv6): self
    {
        $this->ipv4 = $ipv4;
        $this->ipv6 = $ipv6;

        return $this;
    }

    public function callCount(): int
    {
        return $this->calls;
    }
}
