<?php

namespace App\Clients\PublicAddress;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * mailcow's IP check service (feature 011 research R11, legacy `get/status/host/ip`): both
 * families in parallel with 10 seconds to connect and 15 seconds in total. An answer counts only
 * when its trimmed body is a valid address of its family.
 */
class HttpPublicAddressLookup implements PublicAddressLookup
{
    public function lookup(): array
    {
        $urls = [
            'ipv4' => (string) config('restv2.ip_check.ipv4_url', 'http://ipv4.mailcow.email'),
            'ipv6' => (string) config('restv2.ip_check.ipv6_url', 'http://ipv6.mailcow.email'),
        ];

        try {
            $responses = Http::pool(fn (Pool $pool): array => [
                $pool->as('ipv4')->connectTimeout(10)->timeout(15)->get($urls['ipv4']),
                $pool->as('ipv6')->connectTimeout(10)->timeout(15)->get($urls['ipv6']),
            ]);
        } catch (Throwable) {
            return ['ipv4' => null, 'ipv6' => null];
        }

        return [
            'ipv4' => self::address($responses['ipv4'] ?? null, FILTER_FLAG_IPV4),
            'ipv6' => self::address($responses['ipv6'] ?? null, FILTER_FLAG_IPV6),
        ];
    }

    private static function address(mixed $response, int $family): ?string
    {
        if (! $response instanceof Response || ! $response->successful()) {
            return null;
        }

        $address = trim($response->body());

        return filter_var($address, FILTER_VALIDATE_IP, $family) !== false ? $address : null;
    }
}
