<?php

namespace Tests\Feature\Routing\Transports;

use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T048 (FR-037, research R8): `host` and `[host]…` cannot both be next hops, in either
 * order and regardless of letter case, because the mail server looks up the credentials of `host`
 * before those of `[host]:port`.
 */
class TransportInterferenceTest extends TestCase
{
    use RoutingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asAdmin();
    }

    public function test_a_bracketed_next_hop_interferes_with_an_existing_plain_one(): void
    {
        $existing = $this->mailcow()->transport('a.example', 'relay.net');

        $this->send('POST', '/api/v2/transports', ['destination' => 'b.example', 'nextHop' => '[relay.net]:587'])->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/next-hop-interferes')
            ->assertJsonPath('nextHop', 'relay.net')
            ->assertJsonPath('transportIds', [$existing]);
        $this->send('POST', '/api/v2/transports', ['destination' => 'b.example', 'nextHop' => '[RELAY.net]'])->assertStatus(409);
    }

    public function test_a_plain_next_hop_interferes_with_an_existing_bracketed_one(): void
    {
        $f = $this->mailcow();
        $one = $f->transport('a.example', '[Relay.net]:587');
        $two = $f->transport('c.example', '[relay.net]:25');

        $this->send('POST', '/api/v2/transports', ['destination' => 'b.example', 'nextHop' => 'relay.net'])->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/next-hop-interferes')
            ->assertJsonPath('transportIds', [$one, $two]);
        $this->send('POST', '/api/v2/transports', ['destination' => 'b.example', 'nextHop' => 'relay.net:2525'])->assertCreated();
    }

    public function test_an_edit_is_checked_without_the_transport_itself(): void
    {
        $f = $this->mailcow();
        $plain = $f->transport('a.example', 'relay.net');
        $other = $f->transport('b.example', 'other.net');

        $this->send('PATCH', '/api/v2/transports/'.$plain, ['nextHop' => '[relay.net]:25'])->assertOk();
        $this->send('PATCH', '/api/v2/transports/'.$plain, ['nextHop' => '[relay.net]:25', 'active' => true])->assertOk();
        $this->send('PATCH', '/api/v2/transports/'.$other, ['nextHop' => 'relay.net'])->assertStatus(409)->assertJsonPath('transportIds', [$plain]);
    }
}
