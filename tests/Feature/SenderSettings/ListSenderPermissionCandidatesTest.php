<?php

namespace Tests\Feature\SenderSettings;

use Tests\Support\Aliases\AliasTestHelpers;
use Tests\TestCase;

/**
 * Feature 004 T058 (FR-056, US5): listSenderPermissionCandidates.
 */
class ListSenderPermissionCandidatesTest extends TestCase
{
    use AliasTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('example.com');
        $f->domain('foreign.example');
        $f->aliasDomain('example.net', 'example.org');
        $this->mailbox('anna@example.org');
        $this->mailbox('bob@example.org');
        $this->mailbox('carl@foreign.example');
        $f->alias('sales@example.org', 'anna@example.org');
        $f->alias('shared@example.org', 'bob@example.org');
        $f->alias('anna@example.net', 'bob@example.org');
        $f->alias('@example.com', 'bob@example.org');
        $f->alias('held@example.org', 'bob@example.org');
        $f->alias('x@foreign.example', 'bob@example.org');
        $f->senderAcl('anna@example.org', 'held@example.org');
        $f->senderAcl('anna@example.org', '@example.com');
    }

    /**
     * @return list<string>
     */
    private function values(string $query): array
    {
        return array_column((array) $this->getJson('/api/v2/sender-permission-candidates?mailbox=anna@example.org'.$query)->assertOk()->json('data'), 'value');
    }

    public function test_an_administrator_gets_any_address_domains_and_addresses(): void
    {
        $this->asAdmin();

        $this->assertSame(['*', '@example.net', '@example.org', '@foreign.example', 'bob@example.org', 'carl@foreign.example', 'shared@example.org', 'x@foreign.example'], $this->values(''));
        $this->assertSame(['bob@example.org', 'carl@foreign.example', 'shared@example.org', 'x@foreign.example'], $this->values('&type=address'));
        $this->assertSame(['*', '@example.net'], $this->values('&type=anyAddress&type=domain&limit=2'));
        $this->assertSame(['x@foreign.example', 'shared@example.org'], $this->values('&sort=value&order=desc&limit=2'));
        $this->assertSame(['@foreign.example', 'carl@foreign.example', 'x@foreign.example'], $this->values('&q=FOREIGN'));

        $entry = $this->getJson('/api/v2/sender-permission-candidates?mailbox=anna@example.org&limit=1')->assertJsonPath('meta.total', 8)->json('data.0');
        $this->assertSame(['value' => '*', 'valueUnicode' => '*', 'type' => 'anyAddress', 'kind' => 'anyAddress'], $entry);
        $kinds = collect((array) $this->getJson('/api/v2/sender-permission-candidates?mailbox=anna@example.org')->json('data'))->pluck('kind', 'value');
        $this->assertSame('aliasDomain', $kinds['@example.net']);
        $this->assertSame('mailbox', $kinds['bob@example.org']);
        $this->assertSame('alias', $kinds['shared@example.org']);
    }

    public function test_a_domain_administrator_gets_only_their_scope(): void
    {
        $this->asDomainAdmin(['example.org']);

        $this->assertSame(['@example.net', '@example.org', 'bob@example.org', 'shared@example.org'], $this->values(''));
    }

    public function test_the_mailbox_must_be_a_mailbox_in_scope(): void
    {
        $this->mailcow()->resource('Room1@example.org', 'Room 1');
        $this->asDomainAdmin(['example.org']);

        foreach (['carl@foreign.example', 'Room1@example.org', 'nobody@example.org', 'sales@example.org'] as $mailbox) {
            $this->getJson('/api/v2/sender-permission-candidates?mailbox='.$mailbox)->assertStatus(422)
                ->assertJsonPath('errors.mailbox.0.code', 'notFound');
        }
    }
}
