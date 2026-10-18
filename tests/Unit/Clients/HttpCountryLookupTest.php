<?php

namespace Tests\Unit\Clients;

use App\Clients\Geo\HttpCountryLookup;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T099 (feature 005 FR-062, research R16): the country lookup legacy uses, as concurrent requests.
 */
class HttpCountryLookupTest extends TestCase
{
    private const URL = 'https://dfdata.bella.network/country/';

    public function test_pooled_requests_with_legacys_user_agent(): void
    {
        config(['restv2.country_lookup_url' => self::URL]);
        Http::fake([
            self::URL.'198.51.100.1' => Http::response(['shortcountry' => 'CZ', 'country' => 'Czechia']),
            self::URL.'2001:db8::1' => Http::response(['shortcountry' => 'at']),
            self::URL.'198.51.100.3' => Http::response(['country' => 'Unknown']),
            self::URL.'198.51.100.4' => Http::response('not json'),
            self::URL.'198.51.100.5' => Http::response(['shortcountry' => 'XYZ']),
            self::URL.'198.51.100.6' => Http::response(['shortcountry' => 'DE'], 500),
            self::URL.'198.51.100.7' => Http::failedConnection(),
        ]);

        $countries = (new HttpCountryLookup)->lookup(
            ['198.51.100.1', '2001:db8::1', '198.51.100.3', '198.51.100.4', '198.51.100.5', '198.51.100.6', '198.51.100.7'],
            1500,
        );

        $this->assertSame(['198.51.100.1' => 'CZ', '2001:db8::1' => 'AT'], $countries);
        // A refused connection is not recorded as sent.
        Http::assertSentCount(6);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === self::URL.'198.51.100.1'
            && $request->header('User-Agent') === ['Moocow']);
    }

    public function test_no_addresses_send_nothing(): void
    {
        Http::fake();

        $this->assertSame([], (new HttpCountryLookup)->lookup([], 1500));
        Http::assertNothingSent();
    }

    public function test_the_budget_is_the_timeout_of_every_request(): void
    {
        $options = [];
        Http::fake(function (Request $request, array $requestOptions) use (&$options) {
            $options[] = $requestOptions;

            return Http::response(['shortcountry' => 'CZ']);
        });

        (new HttpCountryLookup)->lookup(['198.51.100.1', '198.51.100.2'], 1500);

        $this->assertCount(2, $options);
        $this->assertSame([1.5, 1.5], array_column($options, 'timeout'));
        $this->assertSame([1.5, 1.5], array_column($options, 'connect_timeout'));
    }
}
