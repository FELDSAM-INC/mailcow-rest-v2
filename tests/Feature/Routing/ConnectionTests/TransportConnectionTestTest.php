<?php

namespace Tests\Feature\Routing\ConnectionTests;

use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T060 (FR-041–FR-045): testTransportConnection.
 */
class TransportConnectionTestTest extends TestCase
{
    use RoutingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asAdmin();
    }

    public function test_a_next_hop_without_brackets_uses_its_most_preferred_mail_exchanger(): void
    {
        $id = $this->mailcow()->transport('example.com', 'partner.net');
        $this->dns()->mxRecords('partner.net', ['mx2.partner.net' => 20, 'mx1.partner.net' => 5])->aRecords('mx1.partner.net', '192.0.2.1');

        $response = $this->send('POST', '/api/v2/transports/'.$id.'/connection-test', ['recipient' => 'someone@example.com'])->assertOk()
            ->assertJsonPath('data.host', 'mx1.partner.net')
            ->assertJsonPath('data.hostSource', 'mailExchanger')
            ->assertJsonPath('data.nextHop', 'partner.net')
            ->assertJsonPath('data.recipient', 'someone@example.com');

        $this->assertContains(['direction' => 'info', 'text' => 'Using the most preferred mail exchanger of partner.net: mx1.partner.net'], $response->json('data.transcript'));
        [$record] = $this->auditRecords('transportTest', 'transport', (string) $id);
        $this->assertTrue($record->details['accepted']);
        $this->assertSame('someone@example.com', $record->details['recipient']);
    }

    public function test_a_bracketed_next_hop_connects_without_a_lookup_with_the_shared_credentials(): void
    {
        $f = $this->mailcow();
        $id = $f->transport('example.com', '[mx.partner.net]:2525', 'u1', 'p1');
        $f->transport('example.net', '[mx.partner.net]:2525', 'u1', 'p1');
        $this->dns()->aRecords('mx.partner.net', '192.0.2.7');

        $this->send('POST', '/api/v2/transports/'.$id.'/connection-test')->assertOk()->assertJsonPath('data.port', 2525)->assertJsonPath('data.hostSource', 'nextHop');

        $this->dns()->assertNotQueried('MX');
        $request = $this->smtp()->assertProbed();
        $this->assertSame(['mx.partner.net', 2525, 'u1', 'p1'], [$request->host, $request->port, $request->username, $request->password]);
    }

    public function test_refusals(): void
    {
        $id = $this->mailcow()->transport('example.com', '[192.0.2.1]');

        $this->send('POST', '/api/v2/transports/999/connection-test')->assertNotFound();
        $key = $this->apiKey('admin', 'readOnly');
        $this->send('POST', '/api/v2/transports/'.$id.'/connection-test', null, $this->keyHeader($key))->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
        $this->mailcow()->domain('example.org');
        $this->asRoutingDomainAdmin(['example.org'], ['domain_relayhost' => 1]);
        $this->send('POST', '/api/v2/transports/'.$id.'/connection-test')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->smtp()->assertNotProbed();
        $this->assertCount(3, $this->auditRecords('transportTest'));
    }
}
