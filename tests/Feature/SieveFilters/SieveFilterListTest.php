<?php

namespace Tests\Feature\SieveFilters;

use Illuminate\Support\Facades\DB;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T106 (feature 005 FR-047, US9 AS-6): the Sieve filters of every mailbox in scope, paginated
 * instead of legacy's per-domain loop, and the filters of one mailbox.
 */
class SieveFilterListTest extends TestCase
{
    use SelfServiceTestHelpers;

    private const URL = '/api/v2/sieve-filters';

    /** 300 filters across 120 mailboxes of two domains: 2 or 3 filters each. */
    private function seedFilters(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->domain('example.net');
        $rows = [];

        for ($i = 1; $i <= 120; $i++) {
            $domain = $i <= 60 ? 'example.org' : 'example.net';
            $mailbox = sprintf('user%03d@%s', $i, $domain);
            $this->mailcow()->mailbox($mailbox);

            for ($n = 1; $n <= ($i <= 60 ? 3 : 2); $n++) {
                $rows[] = [
                    'username' => $mailbox,
                    'script_desc' => sprintf('Filter %d of %03d', $n, $i),
                    'script_name' => $n === 1 ? 'active' : 'inactive',
                    'script_data' => 'keep;',
                    'filter_type' => $n === 2 ? 'postfilter' : 'prefilter',
                ];
            }
        }

        DB::table('sieve_filters')->insert($rows);
    }

    public function test_an_administrator_lists_every_filter_paginated(): void
    {
        $this->seedFilters();
        $this->asAdmin();

        $page = $this->getJson(self::URL.'?limit=4')->assertOk();
        $this->assertSame(['total' => 300, 'limit' => 4, 'offset' => 0], $page->json('meta'));
        // Mailbox first, then description.
        $this->assertSame(['user001@example.org', 'user001@example.org', 'user001@example.org', 'user002@example.org'], array_column($page->json('data'), 'mailbox'));
        $this->assertSame(['Filter 1 of 001', 'Filter 2 of 001'], array_slice(array_column($page->json('data'), 'description'), 0, 2));

        $this->assertSame(300, count(array_merge(...array_map(
            fn (int $offset): array => $this->getJson(self::URL.'?limit=100&offset='.$offset)->json('data'),
            [0, 100, 200],
        ))));
    }

    public function test_filters_by_mailbox_kind_and_activity(): void
    {
        $this->seedFilters();
        $this->asAdmin();

        $data = $this->getJson(self::URL.'?mailbox=USER005@example.org')->assertOk()->json('data');
        $this->assertSame(['Filter 1 of 005', 'Filter 2 of 005', 'Filter 3 of 005'], array_column($data, 'description'));

        $this->assertSame(120, $this->getJson(self::URL.'?kind=postfilter&limit=1')->json('meta.total'));
        $this->assertSame(120, $this->getJson(self::URL.'?active=true&limit=1')->json('meta.total'));
        $this->assertSame(['Filter 3 of 005'], array_column($this->getJson(self::URL.'?mailbox=user005@example.org&kind=prefilter&active=false')->json('data'), 'description'));
        $this->assertSame(0, $this->getJson(self::URL.'?mailbox=nobody@example.org')->json('meta.total'));

        // G23: one filter by its ID alone.
        $id = (int) DB::table('sieve_filters')->where('script_desc', 'Filter 2 of 100')->value('id');
        $this->assertSame([['user100@example.net', 'Filter 2 of 100']], array_map(
            fn (array $f): array => [$f['mailbox'], $f['description']],
            $this->getJson(self::URL.'?id='.$id)->assertOk()->json('data'),
        ));
    }

    public function test_search_and_sorting(): void
    {
        $this->seedFilters();
        $this->asAdmin();

        $this->assertSame(3, $this->getJson(self::URL.'?q=of 007')->json('meta.total'));
        $this->assertSame(3, $this->getJson(self::URL.'?q=user007@')->json('meta.total'));
        $this->assertSame('user120@example.net', $this->getJson(self::URL.'?order=desc&limit=1')->json('data.0.mailbox'));
        $this->assertSame('Filter 3 of 060', $this->getJson(self::URL.'?sort=description&order=desc&limit=1')->json('data.0.description'));
        $this->assertFalse($this->getJson(self::URL.'?sort=active&limit=1')->json('data.0.active'));
    }

    public function test_a_domain_administrator_sees_only_the_own_domains(): void
    {
        $this->seedFilters();
        $this->asDomainAdmin(['example.org'], 'reseller', ['filters' => 0]);

        $response = $this->getJson(self::URL.'?limit=1000')->assertOk();
        $this->assertSame(180, $response->json('meta.total'));
        $this->assertSame(['example.org'], array_values(array_unique(array_map(fn (string $m): string => substr($m, strpos($m, '@') + 1), array_column($response->json('data'), 'mailbox')))));
        $this->assertSame(0, $this->getJson(self::URL.'?mailbox=user100@example.net')->json('meta.total'));
        $foreign = (int) DB::table('sieve_filters')->where('username', 'user100@example.net')->value('id');
        $this->assertSame(0, $this->getJson(self::URL.'?id='.$foreign)->json('meta.total'));
    }

    public function test_a_mailbox_user_uses_the_mailbox_list(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->mailcow()->sieveFilterRow('anna@example.org', 'Beta', 'postfilter', 'keep;', false);
        $this->mailcow()->sieveFilterRow('anna@example.org', 'Alpha', 'prefilter');
        $this->asSelfServiceUser();

        $this->getJson(self::URL)->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');

        $data = $this->getJson('/api/v2/mailboxes/anna@example.org/sieve-filters')->assertOk()->json('data');
        $this->assertSame(['Alpha', 'Beta'], array_column($data, 'description'));
        $this->assertSame(['Beta'], array_column($this->getJson('/api/v2/mailboxes/anna@example.org/sieve-filters?kind=postfilter')->json('data'), 'description'));
        $this->assertSame(['Alpha'], array_column($this->getJson('/api/v2/mailboxes/anna@example.org/sieve-filters?active=true')->json('data'), 'description'));
        $this->assertSame(['Beta'], array_column($this->getJson('/api/v2/mailboxes/anna@example.org/sieve-filters?q=bet')->json('data'), 'description'));
    }

    public function test_invalid_filters_are_refused(): void
    {
        $this->asAdmin();
        $this->withoutRequestValidation();

        $this->getJson(self::URL.'?kind=other')->assertStatus(422)->assertJsonPath('errors.kind.0.code', 'notInList');
        $this->getJson(self::URL.'?active=yes')->assertStatus(422)->assertJsonPath('errors.active.0.code', 'invalidType');
        $this->getJson(self::URL.'?sort=script')->assertStatus(422)->assertJsonPath('errors.sort.0.code', 'notInList');
        $this->getJson(self::URL.'?id=0')->assertStatus(422)->assertJsonPath('errors.id.0.code', 'outOfRange');
    }
}
