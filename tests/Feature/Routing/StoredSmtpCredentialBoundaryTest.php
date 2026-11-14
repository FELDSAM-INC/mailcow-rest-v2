<?php

namespace Tests\Feature\Routing;

use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

class StoredSmtpCredentialBoundaryTest extends TestCase
{
    use RoutingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asAdmin();
    }

    public function test_relayhost_changes_cannot_redirect_a_stored_password(): void
    {
        $id = $this->mailcow()->relayhost(null, '[smtp.example.net]:25', 'original', 'private-secret');
        foreach ([['host' => '[other.example.net]:25'], ['host' => '[smtp.example.net]:587'], ['host' => 'smtp.example.net'], ['username' => 'other-user']] as $patch) {
            $this->send('PATCH', "/api/v2/relayhosts/{$id}", $patch)->assertStatus(422)
                ->assertJsonPath('errors.password.0.code', 'required');
            $this->assertSame('[smtp.example.net]:25', $this->relayhostRow($id)?->hostname);
            $this->assertSame('original', $this->relayhostRow($id)?->username);
            $this->assertSame('private-secret', $this->relayhostRow($id)?->password);
        }

        $this->send('PATCH', "/api/v2/relayhosts/{$id}", ['host' => '[SMTP.EXAMPLE.NET]', 'active' => false])->assertOk();
        $this->assertSame('private-secret', $this->relayhostRow($id)?->password);
        $this->send('PATCH', "/api/v2/relayhosts/{$id}", ['host' => '[other.example.net]', 'password' => 'fresh-secret'])->assertOk();
        $this->assertSame('fresh-secret', $this->relayhostRow($id)?->password);
        $this->send('PATCH', "/api/v2/relayhosts/{$id}", ['host' => '[third.example.net]', 'password' => ''])->assertOk();
        $this->assertSame('', $this->relayhostRow($id)?->password);
    }

    public function test_transport_changes_cannot_redirect_original_or_inherited_credentials(): void
    {
        $id = $this->mailcow()->transport('a.example', '[smtp.example.net]:25', 'original', 'private-secret');
        foreach ([['nextHop' => '[other.example.net]:25'], ['nextHop' => '[smtp.example.net]:587'], ['nextHop' => 'smtp.example.net'], ['username' => 'other-user']] as $patch) {
            $this->send('PATCH', "/api/v2/transports/{$id}", $patch)->assertStatus(422)
                ->assertJsonPath('errors.password.0.code', 'required');
            $this->assertSame('[smtp.example.net]:25', $this->transportRow($id)?->nexthop);
            $this->assertSame('original', $this->transportRow($id)?->username);
            $this->assertSame('private-secret', $this->transportRow($id)?->password);
        }

        $target = $this->mailcow()->transport('b.example', '[approved.example.net]:25', 'target-user', 'target-secret');
        $this->send('PATCH', "/api/v2/transports/{$id}", ['nextHop' => '[approved.example.net]:25'])->assertOk();
        $this->assertSame('target-secret', $this->transportRow($id)?->password);
        $this->send('PATCH', "/api/v2/transports/{$id}", ['nextHop' => '[attacker.example.net]:25'])->assertStatus(422);
        $this->send('PATCH', "/api/v2/transports/{$id}", ['username' => 'another-user'])->assertStatus(422);
        $this->assertSame('target-secret', $this->transportRow($target)?->password);
        $this->assertSame('target-user', $this->transportRow($target)?->username);

        $this->send('PATCH', "/api/v2/transports/{$id}", ['nextHop' => '[APPROVED.EXAMPLE.NET]', 'active' => false])->assertOk();
        $this->assertSame('target-secret', $this->transportRow($id)?->password);
        $this->send('PATCH', "/api/v2/transports/{$id}", ['nextHop' => '[new.example.net]', 'password' => 'new-secret'])->assertOk();
        $this->assertSame('new-secret', $this->transportRow($id)?->password);
        $this->send('PATCH', "/api/v2/transports/{$id}", ['nextHop' => '[final.example.net]', 'username' => ''])->assertOk();
        $this->assertSame('', $this->transportRow($id)?->password);
    }
}
