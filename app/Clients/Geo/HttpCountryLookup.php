<?php

namespace App\Clients\Geo;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * legacy's lookup (`GET https://dfdata.bella.network/country/<ip>`, `User-Agent: Moocow`, answer
 * `{shortcountry}`), as one pool of concurrent requests whose timeouts are the budget
 * (`RESTV2_COUNTRY_LOOKUP_URL`, `RESTV2_COUNTRY_LOOKUP_BUDGET_MS`).
 */
class HttpCountryLookup implements CountryLookup
{
    public function lookup(array $addresses, int $budgetMs): array
    {
        if ($addresses === []) {
            return [];
        }

        $url = (string) config('restv2.country_lookup_url');
        $seconds = max(1, $budgetMs) / 1000;

        try {
            $responses = Http::pool(fn (Pool $pool): array => array_map(
                fn (string $address) => $pool->as($address)
                    ->withUserAgent('Moocow')
                    ->acceptJson()
                    ->timeout($seconds)
                    ->connectTimeout($seconds)
                    ->get($url.$address),
                $addresses,
            ));
        } catch (Throwable) {
            return [];
        }

        $countries = [];

        foreach ($responses as $address => $response) {
            if (! $response instanceof Response || ! $response->successful()) {
                continue;
            }

            try {
                $country = $response->json('shortcountry');
            } catch (Throwable) {
                continue;
            }

            if (is_string($country) && preg_match('/^[A-Za-z]{2}$/', $country) === 1) {
                $countries[(string) $address] = strtoupper($country);
            }
        }

        return $countries;
    }
}
