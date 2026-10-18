<?php

namespace Tests\Feature\Routing\BccMaps;

use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T070 (FR-050, FR-057): listBccDestinationChoices offers exactly the local
 * destinations that createBccMap accepts, from the caller's scope, paginated and searchable.
 */
class BccDestinationChoicesTest extends TestCase
{
    use RoutingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('example.com');
        $f->domain('foreign.example');
        $f->aliasDomain('example.net', 'example.org');
        $this->mailbox('anna@example.org');
        $this->mailbox('bert@example.org');
        $this->mailbox('carl@example.com');
        $this->mailbox('anna@foreign.example');
        $f->alias('team@example.org', 'anna@example.org,bert@example.org,someone@outside.example');
        $f->alias('@example.org', 'anna@example.org');
        $f->resource('room@example.org', 'Room');
    }

    public function test_every_kind_with_its_stored_value_domain_and_mailboxes(): void
    {
        $this->asRoutingDomainAdmin(['example.org', 'example.com']);

        $data = $this->getJson('/api/v2/bcc-destination-choices?limit=100')->assertOk()->assertJsonPath('meta.total', 9)->json('data');

        $this->assertSame([
            ['kind' => 'domain', 'value' => '@example.com', 'domain' => 'example.com', 'deliversTo' => []],
            ['kind' => 'domain', 'value' => '@example.org', 'domain' => 'example.org', 'deliversTo' => []],
            ['kind' => 'aliasDomain', 'value' => '@example.net', 'domain' => 'example.net', 'deliversTo' => []],
            ['kind' => 'mailbox', 'value' => 'anna@example.org', 'domain' => 'example.org', 'deliversTo' => []],
            ['kind' => 'mailbox', 'value' => 'bert@example.org', 'domain' => 'example.org', 'deliversTo' => []],
            ['kind' => 'mailbox', 'value' => 'carl@example.com', 'domain' => 'example.com', 'deliversTo' => []],
            ['kind' => 'alias', 'value' => 'team@example.org', 'domain' => 'example.org', 'deliversTo' => ['anna@example.org', 'bert@example.org']],
            ['kind' => 'aliasDomainAddress', 'value' => 'anna@example.net', 'domain' => 'example.net', 'deliversTo' => ['anna@example.org']],
            ['kind' => 'aliasDomainAddress', 'value' => 'bert@example.net', 'domain' => 'example.net', 'deliversTo' => ['bert@example.org']],
        ], $data);
    }

    public function test_search_filters_sorting_and_paging(): void
    {
        $this->asRoutingDomainAdmin(['example.org', 'example.com']);

        $this->assertSame(['anna@example.org', 'anna@example.net'], array_column((array) $this->getJson('/api/v2/bcc-destination-choices?q=ANNA')->json('data'), 'value'));
        $this->assertSame(['@example.net', 'anna@example.net', 'bert@example.net'], array_column((array) $this->getJson('/api/v2/bcc-destination-choices?domain=example.net')->json('data'), 'value'));
        $this->assertSame(['anna@example.org', 'bert@example.org', 'carl@example.com', 'team@example.org'], array_column((array) $this->getJson('/api/v2/bcc-destination-choices?kind=mailbox&kind=alias')->json('data'), 'value'));
        $this->assertSame('@example.com', $this->getJson('/api/v2/bcc-destination-choices?sort=value')->json('data.0.value'));
        $this->assertSame('team@example.org', $this->getJson('/api/v2/bcc-destination-choices?sort=value&order=desc')->json('data.0.value'));
        $this->getJson('/api/v2/bcc-destination-choices?limit=2&offset=2')->assertJsonPath('meta', ['total' => 9, 'limit' => 2, 'offset' => 2])
            ->assertJsonPath('data.0.value', '@example.net');
    }

    public function test_an_administrator_sees_every_choice_and_every_value_is_accepted(): void
    {
        $this->asAdmin();

        $values = array_column((array) $this->getJson('/api/v2/bcc-destination-choices?limit=1000')->json('data'), 'value');
        $this->assertContains('anna@foreign.example', $values);
        $this->assertContains('@foreign.example', $values);
        $this->assertNotContains('room@example.org', $values);

        foreach ($values as $value) {
            $this->send('POST', '/api/v2/bcc-maps', ['localDestination' => $value, 'type' => 'sender', 'bccDestination' => 'archive@backup.net'])->assertCreated();
        }
    }

    public function test_a_large_scope_answers_one_page_of_matches(): void
    {
        foreach (range(1, 300) as $i) {
            $this->mailcow()->mailbox("user{$i}@example.org", scheme: 'SSHA256');
        }

        $this->mailbox('annabel@example.org');
        $this->asRoutingDomainAdmin(['example.org', 'example.com']);

        $response = $this->getJson('/api/v2/bcc-destination-choices?q=anna&limit=25')->assertOk();
        $this->assertSame(['anna@example.org', 'annabel@example.org', 'anna@example.net', 'annabel@example.net'], array_column((array) $response->json('data'), 'value'));
        $this->assertSame(4, $response->json('meta.total'));
    }
}
