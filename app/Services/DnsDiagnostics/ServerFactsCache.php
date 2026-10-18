<?php

namespace App\Services\DnsDiagnostics;

use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * The server-wide facts of DNS diagnostics, reused for RESTV2_DNS_FACTS_TTL seconds (feature 010
 * FR-049, research R19): `restv2:dns-facts:addresses` (JSON `{ipv4, ipv6, detectedAt}`) and
 * `restv2:dns-facts:tlsa:<host>` (`3 1 1 <hex>`) on the API's own Redis connection. Callers store
 * only successful answers, so that a failure is retried by the next run. When Redis is unavailable,
 * nothing is reused.
 */
class ServerFactsCache
{
    public function get(string $fact): ?string
    {
        try {
            $value = Redis::connection('restv2')->get('dns-facts:'.$fact);
        } catch (Throwable) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    public function put(string $fact, string $value): void
    {
        try {
            Redis::connection('restv2')->setex('dns-facts:'.$fact, max(1, (int) config('restv2.dns.facts_ttl', 600)), $value);
        } catch (Throwable) {
            // Not reused: the next run asks again.
        }
    }
}
