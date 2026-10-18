<?php

namespace Tests\Feature\Routing\Relayhosts;

use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T030 (FR-022, FR-023, G41): listRelayhostChoices and listRelayhostUsage. Choices carry
 * the username, as the legacy selectors show it (`ID n: host (username)`), never the password, its
 * state or the usage.
 */
class RelayhostChoicesAndUsageTest extends TestCase
{
    use RoutingTestHelpers;

    private int $a = 0;

    private int $b = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $this->a = $f->relayhost(null, 'z.example', 'acct', 's3cret');
        $this->b = $f->relayhost(null, 'a.example:587', '', '', 0);
        $f->domain('example.org');
    }

    public function test_an_administrator_reads_the_choices(): void
    {
        $this->asAdmin();

        $response = $this->getJson('/api/v2/relayhost-choices')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame([
            ['id' => $this->a, 'host' => 'z.example', 'username' => 'acct', 'active' => true],
            ['id' => $this->b, 'host' => 'a.example:587', 'username' => null, 'active' => false],
        ], $response->json('data'));
        $this->assertStringNotContainsString('s3cret', (string) $response->getContent());

        $this->assertSame([$this->b, $this->a], array_column((array) $this->getJson('/api/v2/relayhost-choices?sort=host')->json('data'), 'id'));
        $this->assertSame([$this->b, $this->a], array_column((array) $this->getJson('/api/v2/relayhost-choices?order=desc')->json('data'), 'id'));
        $this->assertSame([$this->b], array_column((array) $this->getJson('/api/v2/relayhost-choices?q=A.EXAMPLE')->json('data'), 'id'));
        $this->assertSame([], array_column((array) $this->getJson('/api/v2/relayhost-choices?q=acct')->json('data'), 'id'));
    }

    public function test_domain_administrators_with_either_relayhost_flag_read_the_choices(): void
    {
        foreach ([['domain_relayhost' => 1], ['mailbox_relayhost' => 1]] as $i => $flags) {
            $this->asRoutingDomainAdmin(['example.org'], $flags, 'da'.$i);

            $data = $this->getJson('/api/v2/relayhost-choices')->assertOk()->json('data');
            $this->assertSame(['id', 'host', 'username', 'active'], array_keys($data[0]));
            $this->assertSame('acct', $data[0]['username']);
        }
    }

    public function test_a_domain_administrator_without_a_relayhost_flag_is_refused(): void
    {
        $this->asRoutingDomainAdmin(['example.org'], ['bcc_maps' => 1]);

        $this->getJson('/api/v2/relayhost-choices')->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/permission-flag-required')
            ->assertJsonPath('requiredFlags', ['domainRelayhost', 'mailboxRelayhost'])
            ->assertJsonPath('requiredFlagsMatch', 'any');
        $this->getJson('/api/v2/relayhosts')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
    }

    public function test_the_usage_list_names_the_assigned_domains_and_mailboxes(): void
    {
        $f = $this->mailcow();
        $f->domain('b.example', attributes: ['relayhost' => (string) $this->a]);
        $f->domain('a.example', attributes: ['relayhost' => '0'.$this->a]);
        $this->mailbox('anna@example.org');
        $this->mailbox('carl@example.org');
        $f->mailboxRelayhost('anna@example.org', (string) $this->a);
        $f->mailboxRelayhost('carl@example.org', (string) $this->b);
        $this->asAdmin();
        $uri = '/api/v2/relayhosts/'.$this->a.'/usage';

        $this->getJson($uri)->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('data', [
            ['kind' => 'domain', 'name' => 'a.example'],
            ['kind' => 'domain', 'name' => 'b.example'],
            ['kind' => 'mailbox', 'name' => 'anna@example.org'],
        ]);
        $this->assertSame(['b.example', 'anna@example.org', 'a.example'], array_column((array) $this->getJson($uri.'?sort=name&order=desc')->json('data'), 'name'));
        $this->assertSame(['mailbox', 'domain', 'domain'], array_column((array) $this->getJson($uri.'?order=desc')->json('data'), 'kind'));
        $this->assertSame(['anna@example.org'], array_column((array) $this->getJson($uri.'?kind=mailbox')->json('data'), 'name'));
        $this->assertSame(['b.example'], array_column((array) $this->getJson($uri.'?q=B.EX')->json('data'), 'name'));
        $this->getJson($uri.'?limit=1&offset=1')->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.name', 'b.example');
        $this->getJson('/api/v2/relayhosts/999/usage')->assertNotFound();
    }
}
