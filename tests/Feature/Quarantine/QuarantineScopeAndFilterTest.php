<?php

namespace Tests\Feature\Quarantine;

use App\Auth\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T042 (FR-001, FR-009 to FR-011, AS-2.1): administrators list every item, domain
 * administrators the items whose recipient domain they manage (also of deleted mailboxes and of
 * resources); the filters, the search and every sort field.
 */
class QuarantineScopeAndFilterTest extends TestCase
{
    use QuarantineTestHelpers;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpamFilter();

        foreach (['a.example', 'b.example', 'c.example', 'd.example', 'xn--bcher-kva.example'] as $domain) {
            $this->mailcow()->domain($domain);
        }

        $this->mailcow()->domainAdmin('reseller', ['a.example', 'b.example'], ['quarantine' => 1], scheme: 'SSHA256');
        $this->quarantineMailbox('anna@a.example');
        $this->quarantineMailbox('room@a.example', kind: 'location');
        $this->quarantineMailbox('bert@b.example');
        $this->quarantineMailbox('carl@c.example');
        $this->quarantineMailbox('dora@xn--bcher-kva.example');

        $this->ids = [
            'annaReject' => $this->item('anna@a.example', overrides: ['subject' => 'Cheap Pills 100%', 'action' => 'reject', 'score' => 20.5, 'sender' => 'pharma@spam.example', 'qid' => 'AAA111', 'created' => '2026-10-01 10:00:00', 'notified' => 1]),
            'annaJunk' => $this->item('anna@a.example', overrides: ['subject' => 'Newsletter', 'action' => 'add header', 'score' => 7.0, 'sender' => 'news@shop.example', 'qid' => 'AAA222', 'created' => '2026-10-02 10:00:00']),
            'room' => $this->item('room@a.example', overrides: ['subject' => 'Booking', 'action' => 'rewrite subject', 'score' => 9.0, 'qid' => 'RRR111', 'created' => '2026-10-03 10:00:00']),
            'gone' => $this->item('gone@a.example', overrides: ['subject' => 'Old_mail', 'action' => 'reject', 'score' => null, 'qid' => 'GGG111', 'created' => '2026-10-04 10:00:00']),
            'bertVirus' => $this->item('bert@b.example', overrides: ['subject' => 'Invoice', 'action' => 'reject', 'score' => 2015.0, 'symbols' => [['name' => 'VIRUS_FOUND', 'score' => 2000]], 'qid' => 'BBB111', 'created' => '2026-10-05 10:00:00']),
            'bertOther' => $this->item('bert@b.example', overrides: ['subject' => 'Strange', 'action' => 'soft reject', 'score' => 12.0, 'qid' => 'BBB222', 'created' => '2026-10-06 10:00:00']),
            'carl' => $this->item('carl@c.example', overrides: ['subject' => 'Pills again', 'qid' => 'CCC111', 'created' => '2026-10-07 10:00:00']),
            'dora' => $this->item('dora@xn--bcher-kva.example', overrides: ['subject' => 'Bücher', 'qid' => 'DDD111', 'created' => '2026-10-08 10:00:00']),
        ];
    }

    /**
     * @return list<int>
     */
    private function idsOf(string $query = ''): array
    {
        return array_column((array) $this->getJson('/api/v2/quarantine'.$query)->assertOk()->json('data'), 'id');
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    private function expected(array $names): array
    {
        return array_map(fn (string $name): int => $this->ids[$name], $names);
    }

    public function test_a_domain_admin_gets_exactly_the_items_of_their_domains(): void
    {
        $this->signInAs('reseller', Role::DomainAdmin);

        $response = $this->getJson('/api/v2/quarantine')->assertOk();

        $this->assertSame(6, $response->json('meta.total'));
        $this->assertEqualsCanonicalizing($this->expected(['annaReject', 'annaJunk', 'room', 'gone', 'bertVirus', 'bertOther']), $this->idsOf());
        $this->getJson('/api/v2/quarantine?limit=2&offset=2')->assertOk()
            ->assertJsonPath('meta.total', 6)->assertJsonCount(2, 'data');
        $this->getJson('/api/v2/quarantine?limit=2&offset=6')->assertOk()
            ->assertJsonPath('meta.total', 6)->assertJsonPath('data', []);
    }

    public function test_an_admin_gets_every_item(): void
    {
        $this->asAdmin();

        $this->assertSame(8, $this->getJson('/api/v2/quarantine')->assertOk()->json('meta.total'));
    }

    public function test_the_filters(): void
    {
        $this->asAdmin();

        $this->assertSame($this->expected(['annaJunk', 'annaReject']), $this->idsOf('?recipient=ANNA@a.example'));
        $this->assertSame($this->expected(['bertOther', 'bertVirus']), $this->idsOf('?domain=b.example'));
        $this->assertSame($this->expected(['dora']), $this->idsOf('?domain='.rawurlencode('Bücher.example')));
        $this->assertSame($this->expected(['annaJunk']), $this->idsOf('?action=junk'));
        $this->assertSame($this->expected(['bertOther', 'room', 'annaJunk']), $this->idsOf('?action=junk&action=other&action=subjectRewritten'));
        $this->assertSame($this->expected(['bertVirus']), $this->idsOf('?virus=true'));
        $this->assertCount(7, $this->idsOf('?virus=false'));
        $this->assertSame($this->expected(['annaReject']), $this->idsOf('?notified=true'));
        $this->assertSame($this->expected(['bertOther', 'bertVirus']), $this->idsOf('?from='.rawurlencode('2026-10-05T08:00:00Z').'&to='.rawurlencode('2026-10-07T08:00:00Z')));
        $this->assertSame($this->expected(['bertOther', 'room']), $this->idsOf('?minScore=9&maxScore=12'));
        $this->assertNotContains($this->ids['gone'], $this->idsOf('?minScore=0'));
        $this->assertSame(1, $this->getJson('/api/v2/quarantine?domain=b.example&virus=true')->assertOk()->json('meta.total'));
    }

    public function test_the_search_is_case_insensitive_with_literal_wildcards(): void
    {
        $this->asAdmin();

        $this->assertSame($this->expected(['carl', 'annaReject']), $this->idsOf('?q=PILLS'));
        $this->assertSame($this->expected(['annaJunk']), $this->idsOf('?q=shop.example'));
        $this->assertSame($this->expected(['bertOther', 'bertVirus']), $this->idsOf('?q=bert%40'));
        $this->assertSame($this->expected(['annaJunk']), $this->idsOf('?q=aaa222'));
        $this->assertSame($this->expected(['annaReject']), $this->idsOf('?q=100%25'));
        $this->assertSame($this->expected(['gone']), $this->idsOf('?q=old_'));
        $this->assertSame([], $this->idsOf('?q=old%25mail'));
    }

    public function test_search_totals_cover_all_matches_even_beyond_the_last_page(): void
    {
        $this->asAdmin();

        foreach ([0 => ['carl'], 1 => ['annaReject'], 2 => []] as $offset => $names) {
            $response = $this->getJson('/api/v2/quarantine?q=PILLS&limit=1&offset='.$offset)->assertOk();
            $response->assertJsonPath('meta', ['total' => 2, 'limit' => 1, 'offset' => $offset]);
            $this->assertSame($this->expected($names), array_column((array) $response->json('data'), 'id'));
        }

        foreach ([0, 50] as $offset) {
            $this->getJson('/api/v2/quarantine?q=absent&offset='.$offset)->assertOk()
                ->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
        }
    }

    public function test_search_totals_respect_scope_and_filters_on_empty_pages(): void
    {
        $this->signInAs('reseller', Role::DomainAdmin);

        $this->getJson('/api/v2/quarantine?q=PILLS')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->ids['annaReject']);
        $this->getJson('/api/v2/quarantine?q=PILLS&offset=1')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data', []);
        $this->getJson('/api/v2/quarantine?q=PILLS&domain=b.example&offset=1')->assertOk()
            ->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function sortFields(): array
    {
        $fields = [];

        foreach (['arrivedAt', 'id', 'queueId', 'score', 'sender', 'recipient', 'subject', 'action', 'virus', 'notified'] as $field) {
            $fields[$field] = [$field, ''];
            $fields[$field.' with search'] = [$field, '&q=example'];
        }

        return $fields;
    }

    #[DataProvider('sortFields')]
    public function test_every_sort_field_in_both_orders_with_ties_by_id_newest_first(string $field, string $search): void
    {
        $this->asAdmin();
        $column = ['arrivedAt' => 'arrivedAt', 'id' => 'id', 'queueId' => 'queueId', 'score' => 'score', 'sender' => 'sender', 'recipient' => 'recipient', 'subject' => 'subject', 'action' => 'filterAction', 'virus' => 'virus', 'notified' => 'notified'][$field];

        foreach (['asc', 'desc'] as $order) {
            $data = (array) $this->getJson("/api/v2/quarantine?sort={$field}&order={$order}&limit=100{$search}")->assertOk()->json('data');
            $expected = $data;
            usort($expected, function (array $a, array $b) use ($column, $order): int {
                $x = $a[$column];
                $y = $b[$column];
                $cmp = is_string($x) && is_string($y) ? strcasecmp($x, $y) : ($x <=> $y);

                return ($order === 'asc' ? $cmp : -$cmp) ?: ($b['id'] <=> $a['id']);
            });

            $this->assertSame(array_column($expected, 'id'), array_column($data, 'id'), "sort={$field}&order={$order}");
        }
    }

    public function test_invalid_values_are_refused_with_rule_codes(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->getJson('/api/v2/quarantine?sort=foo')->assertStatus(422)->assertJsonPath('errors.sort.0.code', 'notInList');
        $this->getJson('/api/v2/quarantine?action=bar')->assertStatus(422)->assertJsonPath('errors.action.0.code', 'notInList');
        $this->getJson('/api/v2/quarantine?limit=1001')->assertStatus(422)->assertJsonPath('errors.limit.0.code', 'outOfRange');
        $this->getJson('/api/v2/quarantine?from=yesterday')->assertStatus(422)->assertJsonPath('errors.from.0.code', 'invalidFormat');
        $this->getJson('/api/v2/quarantine?virus=maybe')->assertStatus(422)->assertJsonPath('errors.virus.0.code', 'invalidType');
        $this->getJson('/api/v2/quarantine?minScore=high')->assertStatus(422)->assertJsonPath('errors.minScore.0.code', 'invalidType');
    }
}
