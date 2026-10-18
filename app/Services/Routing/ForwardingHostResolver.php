<?php

namespace App\Services\Routing;

use App\Clients\Dns\DnsResolver;
use App\Clients\Dns\DnsTemporaryFailure;
use App\Exceptions\ProblemException;
use App\Support\Domain\DomainName;
use App\Support\Http\FieldViolations;
use App\Support\Http\ProblemType;
use App\Support\Routing\IpNetworkLiteral;

/**
 * The addresses of a forwarding host request (feature 009 FR-076, FR-077, research R16): the input
 * is trimmed (it becomes the source); text with `/` or `:`, or of digits and dots only, must be an
 * address or prefix (`invalidFormat`, `format: ipNetwork`), stored canonically; anything else is a
 * host name, resolved as legacy does: the hosts its SPF record permits (SpfHostCollector, at most
 * the configured number of lookups), else the addresses of its mail exchangers, else its own
 * addresses. A name without addresses is `notResolvable`; a temporary DNS failure answers
 * `503 temporary-failure` (`dependency: dns`). Legacy stored strings such as `cafe` as IPv6 addresses
 * and canonicalised nothing.
 */
class ForwardingHostResolver
{
    public function __construct(
        private readonly DnsResolver $dns,
        private readonly RoutingSettings $settings,
    ) {}

    /**
     * @return array{source: string, addresses: list<string>} canonical, de-duplicated addresses in request order
     */
    public function resolve(string $raw): array
    {
        $source = trim($raw);
        $violations = new FieldViolations;

        if (IpNetworkLiteral::isLiteralLike($source)) {
            $literal = IpNetworkLiteral::parse($source);

            if ($literal === null) {
                $violations->add('host', 'invalidFormat', ['format' => 'ipNetwork']);
                $violations->throwIfAny();
            }

            return ['source' => $source, 'addresses' => [(string) $literal?->canonical()]];
        }

        $name = DomainName::normalizeValid($source);

        if ($name === null) {
            $violations->add('host', 'invalidFormat', ['format' => 'domainName']);
            $violations->throwIfAny();
        }

        try {
            $found = (new SpfHostCollector($this->dns, $this->settings->spfLookupLimit()))->collect((string) $name);

            if (self::canonical($found) === []) {
                $found = [];

                foreach (array_slice(array_column($this->dns->mx((string) $name), 'host'), 0, 10) as $exchanger) {
                    $found = [...$found, ...$this->dns->a($exchanger), ...$this->dns->aaaa($exchanger)];
                }
            }

            if (self::canonical($found) === []) {
                $found = [...$this->dns->a((string) $name), ...$this->dns->aaaa((string) $name)];
            }
        } catch (DnsTemporaryFailure) {
            throw new ProblemException(ProblemType::TemporaryFailure, "The host name {$source} could not be resolved: DNS failed temporarily. Nothing was changed; retry later.", ['dependency' => 'dns'], ['Retry-After' => '5']);
        }

        $addresses = self::canonical($found);

        if ($addresses === []) {
            $violations->add('host', 'notResolvable');
            $violations->throwIfAny();
        }

        return ['source' => $source, 'addresses' => $addresses];
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function canonical(array $values): array
    {
        $result = [];

        foreach ($values as $value) {
            $literal = IpNetworkLiteral::parse($value);

            if ($literal !== null && ! in_array($literal->canonical(), $result, true)) {
                $result[] = $literal->canonical();
            }
        }

        return $result;
    }
}
