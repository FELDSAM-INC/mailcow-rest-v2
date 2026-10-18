<?php

namespace Tests\Unit\Clients;

use App\Clients\Pushover\HttpPushoverClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T091 (feature 005 FR-057, research R14): the HTTP adapter of Pushover's credential validation.
 */
class HttpPushoverClientTest extends TestCase
{
    public function test_an_accepted_verdict(): void
    {
        Http::fake(['https://api.pushover.net/*' => Http::response(['status' => 1, 'request' => 'x'], 200)]);

        $verdict = (new HttpPushoverClient)->validate('token123', 'user456');

        $this->assertTrue($verdict->accepted);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.pushover.net/1/users/validate.json'
            && $request->method() === 'POST'
            && $request['token'] === 'token123'
            && $request['user'] === 'user456');
    }

    public function test_a_rejection_carries_pushovers_reason(): void
    {
        Http::fake(['*' => Http::response(['status' => 0, 'errors' => ['user key is invalid', 'device name is not valid']], 400)]);

        $verdict = (new HttpPushoverClient)->validate('t', 'u');

        $this->assertFalse($verdict->accepted);
        $this->assertSame('user key is invalid; device name is not valid', $verdict->reason);
        $this->assertFalse($verdict->unavailable);
    }

    public function test_server_and_connection_errors_are_temporary(): void
    {
        Http::fake(['*' => Http::response('', 503)]);
        $this->assertTrue((new HttpPushoverClient)->validate('t', 'u')->unavailable);

        Http::fake(['*' => fn () => throw new ConnectionException('timeout')]);
        $this->assertTrue((new HttpPushoverClient)->validate('t', 'u')->unavailable);
    }

    public function test_the_url_is_configurable(): void
    {
        config(['restv2.pushover_validate_url' => 'https://pushover.test/validate']);
        Http::fake(['https://pushover.test/*' => Http::response(['status' => 1], 200)]);

        $this->assertTrue((new HttpPushoverClient)->validate('t', 'u')->accepted);
    }
}
