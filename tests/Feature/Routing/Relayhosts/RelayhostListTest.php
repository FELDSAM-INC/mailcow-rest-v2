<?php

namespace Tests\Feature\Routing\Relayhosts;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T028 (FR-007, FR-008, FR-012, FR-015–FR-017, FR-089): listRelayhosts and getRelayhost.
 */
class RelayhostListTest extends TestCase
{
    use RoutingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asAdmin();
    }

    public function test_an_entry_has_every_member_and_its_etag_equals_the_single_read(): void
    {
        $f = $this->mailcow();
        $with = $f->relayhost(null, '[smtp.example.net]:587', 'acct', 's3cret');
        $without = $f->relayhost(null, 'relay.example.org', '', '', 0);

        $list = $this->getJson('/api/v2/relayhosts')->assertOk()->assertJsonPath('meta.total', 2);

        $this->assertSame([
            'id' => $with,
            'host' => '[smtp.example.net]:587',
            'username' => 'acct',
            'passwordSet' => true,
            'active' => true,
            'usage' => ['domains' => 0, 'mailboxes' => 0],
        ], array_diff_key((array) $list->json('data.0'), ['etag' => 1]));
        $this->assertSame([
            'id' => $without,
            'host' => 'relay.example.org',
            'username' => null,
            'passwordSet' => false,
            'active' => false,
            'usage' => ['domains' => 0, 'mailboxes' => 0],
        ], array_diff_key((array) $list->json('data.1'), ['etag' => 1]));

        $single = $this->getJson('/api/v2/relayhosts/'.$with)->assertOk();
        $this->assertSame($single->headers->get('ETag'), $list->json('data.0.etag'));
        $this->assertSame($single->json('data'), $list->json('data.0'));
    }

    public function test_usage_counts_domains_and_mailboxes_including_legacy_spellings_but_not_resources(): void
    {
        $f = $this->mailcow();
        $f->relayhost(3, '[smtp.example.net]:587');
        $f->domain('example.org', attributes: ['relayhost' => '3']);
        $f->domain('example.com', attributes: ['relayhost' => '03']);
        $f->domain('example.net', attributes: ['relayhost' => '0']);
        $this->mailbox('anna@example.net');
        $f->mailboxRelayhost('anna@example.net', '3');
        $f->resource('room@example.net', 'Room');
        DB::update("UPDATE `mailbox` SET `attributes` = JSON_OBJECT('relayhost', '3') WHERE `username` = 'room@example.net'");

        $this->getJson('/api/v2/relayhosts/3')->assertOk()->assertJsonPath('data.usage', ['domains' => 2, 'mailboxes' => 1]);
        $this->getJson('/api/v2/relayhosts')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.usage', ['domains' => 2, 'mailboxes' => 1]);
    }

    public function test_search_and_filters(): void
    {
        $f = $this->mailcow();
        $a = $f->relayhost(null, '[SMTP.Example.net]:587', 'acct');
        $b = $f->relayhost(null, 'relay.example.org', 'Billing', '', 0);
        $c = $f->relayhost(null, 'other.example', '');
        $f->domain('example.org', attributes: ['relayhost' => (string) $c]);

        $this->assertSame([$a], $this->ids('/api/v2/relayhosts?q=smtp.EXAMPLE'));
        $this->assertSame([$b], $this->ids('/api/v2/relayhosts?q=billing'));
        $this->assertSame([$a, $c], $this->ids('/api/v2/relayhosts?active=true'));
        $this->assertSame([$b], $this->ids('/api/v2/relayhosts?active=false'));
        $this->assertSame([$c], $this->ids('/api/v2/relayhosts?inUse=true'));
        $this->assertSame([$a, $b], $this->ids('/api/v2/relayhosts?inUse=false'));
        $this->assertSame([], $this->ids('/api/v2/relayhosts?q=100%25'));
    }

    /**
     * @return array<string, array{string, string, list<int>}>
     */
    public static function sorts(): array
    {
        return [
            'id asc' => ['id', 'asc', [0, 1, 2, 3]],
            'id desc' => ['id', 'desc', [3, 2, 1, 0]],
            'host asc' => ['host', 'asc', [2, 0, 3, 1]],
            'host desc' => ['host', 'desc', [1, 3, 0, 2]],
            'username asc, ties by id' => ['username', 'asc', [0, 3, 1, 2]],
            'username desc, ties by id' => ['username', 'desc', [2, 1, 3, 0]],
            'active asc' => ['active', 'asc', [1, 0, 2, 3]],
            'active desc' => ['active', 'desc', [3, 2, 0, 1]],
            'domainCount asc' => ['domainCount', 'asc', [1, 3, 2, 0]],
            'domainCount desc' => ['domainCount', 'desc', [0, 2, 3, 1]],
            'mailboxCount asc' => ['mailboxCount', 'asc', [0, 2, 3, 1]],
            'mailboxCount desc' => ['mailboxCount', 'desc', [1, 3, 2, 0]],
        ];
    }

    /**
     * @param  list<int>  $order  indexes into the created relayhosts
     */
    #[DataProvider('sorts')]
    public function test_every_sort_key_in_both_orders(string $sort, string $direction, array $order): void
    {
        $f = $this->mailcow();
        $ids = [
            $f->relayhost(null, 'b.example', '', '', 1),
            $f->relayhost(null, 'd.example', 'zed', '', 0),
            $f->relayhost(null, 'a.example', 'zed', '', 1),
            $f->relayhost(null, 'c.example', '', '', 1),
        ];
        $f->domain('one.example', attributes: ['relayhost' => (string) $ids[0]]);
        $f->domain('two.example', attributes: ['relayhost' => (string) $ids[0]]);
        $f->domain('three.example', attributes: ['relayhost' => (string) $ids[2]]);
        $this->mailbox('anna@one.example');
        $this->mailbox('bert@one.example');
        $this->mailbox('carl@one.example');
        $f->mailboxRelayhost('anna@one.example', (string) $ids[1]);
        $f->mailboxRelayhost('bert@one.example', (string) $ids[1]);
        $f->mailboxRelayhost('carl@one.example', (string) $ids[3]);

        $this->assertSame(array_map(fn (int $i): int => $ids[$i], $order), $this->ids("/api/v2/relayhosts?sort={$sort}&order={$direction}"));
    }

    public function test_paging_and_its_limits(): void
    {
        $f = $this->mailcow();
        $ids = array_map(fn (int $i): int => $f->relayhost(null, "r{$i}.example"), range(1, 5));

        $this->getJson('/api/v2/relayhosts?limit=2&offset=2')->assertOk()
            ->assertJsonPath('meta', ['total' => 5, 'limit' => 2, 'offset' => 2])
            ->assertJsonPath('data.0.id', $ids[2])
            ->assertJsonPath('data.1.id', $ids[3]);
        $this->getJson('/api/v2/relayhosts')->assertJsonPath('meta.limit', 25);
    }

    public function test_an_invalid_limit_is_out_of_range(): void
    {
        $this->withoutRequestValidation();

        $this->getJson('/api/v2/relayhosts?limit=1001')->assertStatus(422)->assertJsonPath('errors.limit.0.code', 'outOfRange');
    }

    public function test_no_password_or_part_of_one_appears_in_any_body(): void
    {
        $f = $this->mailcow();
        $ids = [
            $f->relayhost(null, 'a.example', 'acct', 's3cret'),
            $f->relayhost(null, 'b.example', 'acct', 'pa:ss'),
            $f->relayhost(null, 'c.example', 'u\:x', 'x\:y'),
        ];
        $bodies = [(string) $this->getJson('/api/v2/relayhosts')->getContent()];

        foreach ($ids as $id) {
            $bodies[] = (string) $this->getJson('/api/v2/relayhosts/'.$id)->getContent();
        }

        foreach ($bodies as $body) {
            foreach (['s3cret', 's3c', 'pa:ss', 'pa:', 'x\\\\:y', 'x\\:y'] as $secret) {
                $this->assertStringNotContainsString($secret, $body);
            }
        }
    }

    public function test_usage_does_not_change_the_etag_but_a_password_written_directly_does(): void
    {
        $f = $this->mailcow();
        $id = $f->relayhost(null, 'a.example', 'acct', 's3cret');
        $etag = $this->getJson('/api/v2/relayhosts/'.$id)->headers->get('ETag');

        $f->domain('example.org', attributes: ['relayhost' => (string) $id]);
        $this->assertSame($etag, $this->getJson('/api/v2/relayhosts/'.$id)->headers->get('ETag'));

        DB::table('relayhosts')->where('id', $id)->update(['password' => 'other']);
        $changed = $this->getJson('/api/v2/relayhosts/'.$id);
        $this->assertNotSame($etag, $changed->headers->get('ETag'));
        $this->assertTrue($changed->json('data.passwordSet'));
    }

    public function test_an_unknown_relayhost_is_not_found(): void
    {
        $this->getJson('/api/v2/relayhosts/999')->assertNotFound();
    }

    public function test_a_page_needs_the_same_number_of_queries_whatever_its_size(): void
    {
        $f = $this->mailcow();

        foreach (range(1, 30) as $i) {
            $f->relayhost(null, "r{$i}.example", "u{$i}", 'p');
        }

        $count = function (string $uri): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($uri)->assertOk();
            $queries = count(array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'relayhosts')));
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count('/api/v2/relayhosts?limit=2'), $count('/api/v2/relayhosts?limit=30'));
    }

    /**
     * @return list<int>
     */
    private function ids(string $uri): array
    {
        return array_column((array) $this->getJson($uri)->assertOk()->json('data'), 'id');
    }
}
