<?php

namespace App\Clients\Dns;

use App\Services\DnsDiagnostics\ServerFactsCache;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The server's public addresses for DNS diagnostics (feature 010 FR-042, research R19): mailcow's
 * address echo service, asked once over IPv4 and once over IPv6 (Guzzle's `force_ip_resolve`), as
 * legacy dns_diagnostics.php does, with 3 seconds to connect and RESTV2_DNS_LOOKUP_TIMEOUT (5 s) in
 * total. An answer counts only when its trimmed body is an address of its family; a missing IPv6
 * answer means the server has no public IPv6 address. RESTV2_PUBLIC_IPV4 and RESTV2_PUBLIC_IPV6
 * (`none` for no IPv6) replace the questions. Answers are reused through ServerFactsCache unless the
 * IPv4 address could not be determined.
 */
class PublicAddressDetector
{
    public function __construct(private readonly ServerFactsCache $cache) {}

    /**
     * @return array{ipv4: string|null, ipv6: string|null}
     */
    public function detect(): array
    {
        $ipv4 = self::configured((string) config('restv2.dns.public_ipv4'), FILTER_FLAG_IPV4);
        $ipv6Setting = (string) config('restv2.dns.public_ipv6');
        $ipv6 = strtolower($ipv6Setting) === 'none' ? false : self::configured($ipv6Setting, FILTER_FLAG_IPV6);

        if ($ipv4 !== null && $ipv6 !== null) {
            return ['ipv4' => $ipv4, 'ipv6' => $ipv6 === false ? null : $ipv6];
        }

        $cached = json_decode((string) $this->cache->get('addresses'), true);

        if (is_array($cached) && is_string($cached['ipv4'] ?? null)) {
            return [
                'ipv4' => $ipv4 ?? $cached['ipv4'],
                'ipv6' => $ipv6 === false ? null : ($ipv6 ?? (is_string($cached['ipv6'] ?? null) ? $cached['ipv6'] : null)),
            ];
        }

        $detected = [
            'ipv4' => $ipv4 ?? $this->ask((string) config('restv2.dns.echo_url_ipv4'), 'v4', FILTER_FLAG_IPV4),
            'ipv6' => $ipv6 === false ? null : ($ipv6 ?? $this->ask((string) config('restv2.dns.echo_url_ipv6'), 'v6', FILTER_FLAG_IPV6)),
        ];

        if ($detected['ipv4'] !== null) {
            $this->cache->put('addresses', (string) json_encode($detected + ['detectedAt' => CarbonImmutable::now('UTC')->toIso8601ZuluString()]));
        }

        return $detected;
    }

    private function ask(string $url, string $family, int $flag): ?string
    {
        try {
            $response = Http::withOptions(['force_ip_resolve' => $family])
                ->connectTimeout(min(3, max(1, (int) config('restv2.dns.lookup_timeout', 5))))
                ->timeout(max(1, (int) config('restv2.dns.lookup_timeout', 5)))
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $response instanceof Response || ! $response->successful()) {
            return null;
        }

        $address = trim($response->body());

        return filter_var($address, FILTER_VALIDATE_IP, $flag) !== false ? $address : null;
    }

    private static function configured(string $value, int $flag): ?string
    {
        $value = trim($value);

        return $value !== '' && filter_var($value, FILTER_VALIDATE_IP, $flag) !== false ? $value : null;
    }
}
