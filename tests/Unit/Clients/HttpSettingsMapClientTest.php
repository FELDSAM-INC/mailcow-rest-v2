<?php

namespace Tests\Unit\Clients;

use App\Clients\Rspamd\HttpSettingsMapClient;
use App\Clients\Rspamd\RspamdUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature 013 T049 (research R12): the effective settings map is fetched from mailcow's
 * `settings.php` without `If-Modified-Since`, so that the endpoint always answers with the whole
 * map; any failure is RspamdUnavailable.
 */
class HttpSettingsMapClientTest extends TestCase
{
    public function test_the_map_is_fetched_from_the_configured_address(): void
    {
        config(['restv2.rspamd_settings_map_url' => 'http://nginx:8081/settings.php']);
        Http::fake(['nginx:8081/*' => Http::response("settings {\n}\n", 200)]);

        $this->assertSame("settings {\n}\n", (new HttpSettingsMapClient)->fetch());

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://nginx:8081/settings.php'
            && $request->method() === 'GET'
            && ! $request->hasHeader('If-Modified-Since'));
    }

    public function test_a_status_other_than_200_is_unavailable(): void
    {
        Http::fake(['*' => Http::response('', 304)]);

        $this->expectException(RspamdUnavailable::class);

        (new HttpSettingsMapClient)->fetch();
    }

    public function test_a_server_error_is_unavailable(): void
    {
        Http::fake(['*' => Http::response('oops', 500)]);

        $this->expectException(RspamdUnavailable::class);

        (new HttpSettingsMapClient)->fetch();
    }

    public function test_a_connection_failure_is_unavailable(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('refused')]);

        $this->expectException(RspamdUnavailable::class);

        (new HttpSettingsMapClient)->fetch();
    }
}
