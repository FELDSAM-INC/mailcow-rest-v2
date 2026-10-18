<?php

namespace App\Services\DnsDiagnostics\Spf;

use App\Clients\Dns\DnsResolver;
use App\Services\DnsDiagnostics\LookupFailed;
use App\Services\DnsDiagnostics\LookupGuard;
use IPLib\Address\AddressInterface;
use IPLib\Factory;
use SPFLib\DNS\Resolver;
use SPFLib\Exception\DNSResolutionException;

/**
 * mlocati/spf-lib's resolver on feature 009's DnsResolver and the run's time limits (feature 010
 * research R18). A failed lookup becomes the library's DNSResolutionException (a `temperror`); the
 * adapter keeps its reason, so that the SPF check reports `lookupFailed` instead of an SPF error.
 * TXT answers given up front (the evaluator's own count of SPF records) are not asked again.
 */
final class SpfResolverAdapter implements Resolver
{
    private ?LookupFailed $failure = null;

    /**
     * @param  array<string, list<string>>  $knownTxt  name => TXT records already looked up
     */
    public function __construct(
        private readonly DnsResolver $dns,
        private readonly LookupGuard $guard,
        private array $knownTxt = [],
    ) {}

    public function failure(): ?LookupFailed
    {
        return $this->failure;
    }

    public function getTXTRecords(string $domain): array
    {
        $key = strtolower(rtrim($domain, '.'));

        return $this->knownTxt[$key] ??= $this->lookup($domain, fn (): array => $this->dns->txt($domain));
    }

    public function getIPAddressesFromDomainName(string $domain): array
    {
        $addresses = [
            ...$this->lookup($domain, fn (): array => $this->dns->a($domain)),
            ...$this->lookup($domain, fn (): array => $this->dns->aaaa($domain)),
        ];

        return array_values(array_filter(array_map(fn (string $a): ?AddressInterface => Factory::parseAddressString($a), $addresses)));
    }

    public function getMXRecords(string $domain): array
    {
        return array_values(array_map(fn (array $r): string => $r['host'], $this->lookup($domain, fn (): array => $this->dns->mx($domain))));
    }

    public function getPTRRecords(string $domain): array
    {
        return $this->lookup($domain, fn (): array => $this->dns->ptr($domain));
    }

    public function getDomainNameFromIPAddress(AddressInterface $ip): string
    {
        try {
            return $this->getPTRRecords($ip->getReverseDNSLookupName())[0] ?? '';
        } catch (DNSResolutionException) {
            return '';
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $lookup
     * @return T
     */
    private function lookup(string $domain, callable $lookup): mixed
    {
        try {
            return $this->guard->run($lookup);
        } catch (LookupFailed $e) {
            $this->failure ??= $e;

            throw new DNSResolutionException($domain, $e->getMessage());
        }
    }
}
