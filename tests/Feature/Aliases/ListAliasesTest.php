<?php

namespace Tests\Feature\Aliases;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\TestCase;

/**
 * Feature 004 T023 (FR-011–FR-017, FR-019, US1): listAliases.
 */
class ListAliasesTest extends TestCase
{
    use AliasTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('example.com');
        $f->aliasDomain('example.net', 'example.org');
        $this->mailbox('anna@example.org');
        $this->mailbox('bob@example.org');
    }

    public function test_the_newest_25_aliases_are_listed_without_mailboxes_own_rows(): void
    {
        for ($i = 1; $i <= 38; $i++) {
            $this->mailbox("user{$i}@example.com");
        }

        $ids = [];
        for ($i = 1; $i <= 60; $i++) {
            $ids[] = $this->mailcow()->alias("alias{$i}@example.com", 'anna@example.org');
        }
        $this->asAdmin();

        $response = $this->getJson('/api/v2/aliases')->assertOk();

        $this->assertSame(['total' => 60, 'limit' => 25, 'offset' => 0], $response->json('meta'));
        $this->assertSame(array_slice(array_reverse($ids), 0, 25), array_column((array) $response->json('data'), 'id'));
    }

    public function test_an_alias_in_an_alias_domain_shows_both_domains(): void
    {
        $id = $this->mailcow()->alias('info@example.net', 'anna@example.org');
        $this->mailcow()->alias('sales@example.org', 'anna@example.org');
        $this->asAdmin();

        $entry = collect((array) $this->getJson('/api/v2/aliases')->json('data'))->firstWhere('id', $id);
        $this->assertSame('example.net', $entry['domain']);
        $this->assertTrue($entry['inAliasDomain']);
        $this->assertSame('example.org', $entry['primaryDomain']);
        $this->assertSame('example.org', $entry['primaryDomainUnicode']);
        $this->assertFalse($entry['orphaned']);

        $this->assertSame(['sales@example.org', 'info@example.net'], $this->addressesOf($this->getJson('/api/v2/aliases?primaryDomain=example.org')));
        $this->assertSame(['sales@example.org'], $this->addressesOf($this->getJson('/api/v2/aliases?domain=example.org')));
        $this->assertSame(['info@example.net'], $this->addressesOf($this->getJson('/api/v2/aliases?domain=EXAMPLE.NET')));
    }

    public function test_the_search_matches_addresses_destinations_and_comments_case_insensitively(): void
    {
        $f = $this->mailcow();
        $f->alias('invoice@example.org', 'anna@example.org');
        $f->alias('billing@example.org', 'invoices@external.example');
        $f->alias('a@example.org', 'anna@example.org', publicComment: 'Monthly Invoice run');
        $f->alias('b@example.org', 'anna@example.org', privateComment: 'invoice archive');
        $f->alias('c@example.org', 'anna@example.org');
        $f->domain('xn--bcher-kva.example');
        $f->alias('info@xn--bcher-kva.example', 'anna@example.org');
        $this->asAdmin();

        $this->assertEqualsCanonicalizing(['invoice@example.org', 'billing@example.org', 'a@example.org', 'b@example.org'], $this->addressesOf($this->getJson('/api/v2/aliases?q=INVOICE')));

        foreach (['bücher', 'ücher', 'xn--bcher', 'BÜCHER.example'] as $q) {
            $this->assertSame(['info@xn--bcher-kva.example'], $this->addressesOf($this->getJson('/api/v2/aliases?q='.rawurlencode($q))), $q);
        }

        $entry = $this->getJson('/api/v2/aliases?q=bücher')->json('data.0');
        $this->assertSame('info@bücher.example', $entry['addressUnicode']);
        $this->assertSame('bücher.example', $entry['domainUnicode']);
    }

    public function test_special_actions_are_shown_without_destinations(): void
    {
        $this->mailcow()->alias('spam@example.org', 'spam@localhost');
        $this->mailcow()->alias('drop@example.org', 'null@localhost');
        $this->asAdmin();

        $data = collect((array) $this->getJson('/api/v2/aliases')->json('data'))->keyBy('address');

        $this->assertSame('learnSpam', $data['spam@example.org']['destinationType']);
        $this->assertSame([], $data['spam@example.org']['destinations']);
        $this->assertSame('discard', $data['drop@example.org']['destinationType']);
    }

    public function test_the_destination_filter_finds_the_aliases_delivering_to_a_mailbox(): void
    {
        $f = $this->mailcow();
        $f->alias('one@example.org', 'anna@example.org');
        $f->alias('two@example.org', 'bob@example.org,anna@example.org');
        $f->alias('three@example.org', 'x@external.example,anna@example.org,bob@example.org');
        $f->alias('four@example.org', 'bob@example.org');
        $f->alias('five@example.org', 'hanna@example.org');
        $this->asAdmin();

        foreach (['anna@example.org', 'ANNA@Example.org'] as $value) {
            $this->assertEqualsCanonicalizing(['one@example.org', 'two@example.org', 'three@example.org'], $this->addressesOf($this->getJson('/api/v2/aliases?destination='.rawurlencode($value))));
        }

        $this->assertEqualsCanonicalizing(['two@example.org', 'three@example.org', 'four@example.org', 'one@example.org'], $this->addressesOf($this->getJson('/api/v2/aliases?destination=anna@example.org&destination=bob@example.org')));
    }

    public function test_the_other_filters(): void
    {
        $f = $this->mailcow();
        $f->alias('@example.org', 'anna@example.org');
        $f->alias('off@example.org', 'anna@example.org', active: 0);
        $f->alias('nosend@example.org', 'anna@example.org', senderAllowed: 0);
        $f->alias('hidden@example.org', 'anna@example.org', sogoVisible: 0);
        $f->alias('internal@example.org', 'anna@example.org', internal: 1);
        $f->alias('drop@example.org', 'null@localhost');
        $f->alias('ham@example.org', 'ham@localhost');
        $this->asAdmin();

        $this->assertSame(['@example.org'], $this->addressesOf($this->getJson('/api/v2/aliases?kind=catchAll')));
        $this->assertCount(7, $this->getJson('/api/v2/aliases?kind=catchAll&kind=regular')->json('data'));
        $this->assertSame(['off@example.org'], $this->addressesOf($this->getJson('/api/v2/aliases?active=false')));
        $this->assertSame(['nosend@example.org'], $this->addressesOf($this->getJson('/api/v2/aliases?senderAllowed=false')));
        $this->assertSame(['hidden@example.org'], $this->addressesOf($this->getJson('/api/v2/aliases?groupwareVisible=false')));
        $this->assertSame(['internal@example.org'], $this->addressesOf($this->getJson('/api/v2/aliases?internal=true')));
        $this->assertSame(['ham@example.org', 'drop@example.org'], $this->addressesOf($this->getJson('/api/v2/aliases?destinationType=discard&destinationType=learnHam')));
        $this->assertCount(5, $this->getJson('/api/v2/aliases?destinationType=addresses')->json('data'));
        $this->assertSame(['nosend@example.org', 'off@example.org'], $this->addressesOf($this->getJson('/api/v2/aliases?address=OFF@example.org&address=nosend@EXAMPLE.org')));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sorts(): array
    {
        return array_combine(
            ['id', 'address', 'domain', 'active', 'groupwareVisible', 'internal', 'senderAllowed', 'createdAt', 'modifiedAt'],
            [['id'], ['address'], ['domain'], ['active'], ['groupwareVisible'], ['internal'], ['senderAllowed'], ['createdAt'], ['modifiedAt']],
        );
    }

    #[DataProvider('sorts')]
    public function test_every_sort_both_ways_with_ties_broken_by_id(string $sort): void
    {
        $f = $this->mailcow();
        $rows = [
            ['b@example.org', 'example.org', 1, 1, 0, 1, '2026-10-01 10:00:00', null],
            ['a@example.com', 'example.com', 0, 1, 1, 0, '2026-10-02 10:00:00', '2026-10-05 10:00:00'],
            ['c@example.net', 'example.net', 1, 0, 0, 1, '2026-10-01 10:00:00', '2026-10-03 10:00:00'],
            ['a@example.org', 'example.org', 1, 1, 0, 0, '2026-10-03 10:00:00', null],
        ];
        $ids = [];
        foreach ($rows as [$address, $domain, $active, $visible, $internal, $sender, $created, $modified]) {
            $id = $f->alias($address, 'anna@example.org', $domain, $active, $sender, $visible, $internal);
            DB::table('alias')->where('id', $id)->update(['created' => $created, 'modified' => $modified]);
            $ids[$address] = $id;
        }
        $this->asAdmin();

        $column = ['id' => null, 'address' => 0, 'domain' => 1, 'active' => 2, 'groupwareVisible' => 3, 'internal' => 4, 'senderAllowed' => 5, 'createdAt' => 6, 'modifiedAt' => 7][$sort];

        foreach (['asc', 'desc'] as $order) {
            $expected = $rows;
            usort($expected, function (array $x, array $y) use ($column, $order, $ids): int {
                $cmp = $column === null ? 0 : (strcasecmp((string) $x[$column], (string) $y[$column]));
                $cmp = $cmp !== 0 ? $cmp : $ids[$x[0]] <=> $ids[$y[0]];

                return $order === 'asc' ? $cmp : -$cmp;
            });

            $this->assertSame(array_column($expected, 0), $this->addressesOf($this->getJson("/api/v2/aliases?sort={$sort}&order={$order}")), "{$sort} {$order}");
        }
    }

    public function test_paging_bounds(): void
    {
        $f = $this->mailcow();
        $f->alias('one@example.org', 'anna@example.org');
        $f->alias('two@example.org', 'anna@example.org');
        $this->asAdmin();

        $this->assertSame(['two@example.org'], $this->addressesOf($this->getJson('/api/v2/aliases?limit=1')));
        $this->assertSame(['one@example.org'], $this->addressesOf($this->getJson('/api/v2/aliases?limit=1&offset=1')));
        $this->assertCount(2, $this->getJson('/api/v2/aliases?limit=1000')->json('data'));
        $response = $this->getJson('/api/v2/aliases?offset=10')->assertOk();
        $this->assertSame([], $response->json('data'));
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_orphaned_aliases_are_listed_for_administrators(): void
    {
        $id = $this->mailcow()->alias('old@gone.example', 'anna@example.org');
        $this->asAdmin();

        $entry = $this->getJson('/api/v2/aliases')->json('data.0');

        $this->assertSame($id, $entry['id']);
        $this->assertTrue($entry['orphaned']);
        $this->assertFalse($entry['inAliasDomain']);
        $this->assertSame('gone.example', $entry['primaryDomain']);
    }

    public function test_every_entry_carries_the_tag_of_the_single_read(): void
    {
        $f = $this->mailcow();
        $f->alias('one@example.org', 'anna@example.org');
        $f->alias('two@example.net', 'bob@example.org', publicComment: 'x');
        $this->asAdmin();

        foreach ((array) $this->getJson('/api/v2/aliases')->json('data') as $entry) {
            $this->assertSame($this->getJson('/api/v2/aliases/'.$entry['id'])->headers->get('ETag'), $entry['etag']);
        }
    }

    public function test_invalid_list_parameters_are_refused_with_rule_codes(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->getJson('/api/v2/aliases?sort=goto')->assertStatus(422)->assertJsonPath('errors.sort.0.code', 'notInList');
        $this->getJson('/api/v2/aliases?limit=1001')->assertStatus(422)->assertJsonPath('errors.limit.0.code', 'outOfRange');
        $this->getJson('/api/v2/aliases?q='.str_repeat('a', 256))->assertStatus(422)->assertJsonPath('errors.q.0.code', 'tooLong');
        $this->assertSame('notInList', $this->getJson('/api/v2/aliases?kind=other')->assertStatus(422)->json('errors')['kind.0'][0]['code']);
        $this->getJson('/api/v2/aliases?active=yes')->assertStatus(422)->assertJsonPath('errors.active.0.code', 'invalidType');
    }
}
