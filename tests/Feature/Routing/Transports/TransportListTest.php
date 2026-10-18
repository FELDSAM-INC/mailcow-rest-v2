<?php

namespace Tests\Feature\Routing\Transports;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T045 (FR-031–FR-033, G35): listTransports and getTransport.
 */
class TransportListTest extends TestCase
{
    use RoutingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asAdmin();
    }

    public function test_every_member_and_the_next_hop_sharing(): void
    {
        $f = $this->mailcow();
        $a = $f->transport('example.com', '[h.example]:25', 'u1', 'p1', 0, 1);
        $b = $f->transport('example.net', '[h.example]:25', 'u1', 'p1', 0, 0);
        $c = $f->transport('.*\.google\.com', '[H.example]:25', 'u1', 'p1', 1, 1);
        $d = $f->transport('*', 'other.example');

        $list = $this->getJson('/api/v2/transports')->assertOk()->assertJsonPath('meta.total', 4);

        $this->assertSame([
            'id' => $a,
            'destination' => 'example.com',
            'nextHop' => '[h.example]:25',
            'mxBased' => false,
            'username' => 'u1',
            'passwordSet' => true,
            'active' => true,
            'nextHopSharedWith' => 2,
        ], array_diff_key((array) $list->json('data.0'), ['etag' => 1]));
        $this->assertSame([2, 2, 2, 0], array_column((array) $list->json('data'), 'nextHopSharedWith'));
        $this->assertSame(['id' => $d, 'mxBased' => false, 'username' => null, 'passwordSet' => false], array_intersect_key((array) $list->json('data.3'), ['id' => 1, 'username' => 1, 'passwordSet' => 1, 'mxBased' => 1]));
        $this->assertTrue($list->json('data.2.mxBased'));

        $single = $this->getJson('/api/v2/transports/'.$c)->assertOk();
        $this->assertSame($single->headers->get('ETag'), $list->json('data.2.etag'));
        $this->assertSame($single->json('data'), $list->json('data.2'));
        $this->assertStringNotContainsString('p1', (string) $list->getContent());
        $this->getJson('/api/v2/transports/'.($b + 100))->assertNotFound();
    }

    public function test_search_and_filters(): void
    {
        $f = $this->mailcow();
        $a = $f->transport('example.com', '[mx.partner.net]:25', 'Billing', '', 0, 1);
        $b = $f->transport('partner.example', '[MX.partner.net]:25', 'Billing', '', 0, 0);
        $c = $f->transport('.*\.google\.com', 'relay.example', '', '', 1, 1);
        $d = $f->transport('*', '[192.0.2.10]:25');
        $e = $f->transport('bücher.example', 'xn--bcher-kva.example');

        $this->assertSame([$a, $b], $this->ids('/api/v2/transports?q=PARTNER.net'));
        $this->assertSame([$b], $this->ids('/api/v2/transports?q=partner.example'));
        $this->assertSame([$a, $b], $this->ids('/api/v2/transports?q=billing'));
        $this->assertSame([$a, $c, $d, $e], $this->ids('/api/v2/transports?active=true'));
        $this->assertSame([$c], $this->ids('/api/v2/transports?mxBased=true'));
        $this->assertSame([$a, $b, $d, $e], $this->ids('/api/v2/transports?mxBased=false'));
        $this->assertSame([$a, $b], $this->ids('/api/v2/transports?nextHop=%5Bmx.partner.net%5D%3A25'));
        $this->assertSame([$a, $b], $this->ids('/api/v2/transports?nextHop=%5BMX.PARTNER.NET%5D%3A25'));
        $this->assertSame([], $this->ids('/api/v2/transports?nextHop=mx.partner.net'));
        // G35: the filter is normalised like a stored next hop.
        $this->assertSame([$d], $this->ids('/api/v2/transports?nextHop=192.0.2.10%3A25'));
        $this->assertSame([$e], $this->ids('/api/v2/transports?nextHop=B%C3%BCcher.Example'));
    }

    /**
     * @return array<string, array{string, string, list<int>}>
     */
    public static function sorts(): array
    {
        return [
            'id asc' => ['id', 'asc', [0, 1, 2]],
            'id desc' => ['id', 'desc', [2, 1, 0]],
            'destination asc' => ['destination', 'asc', [1, 2, 0]],
            'destination desc' => ['destination', 'desc', [0, 2, 1]],
            'nextHop asc' => ['nextHop', 'asc', [2, 0, 1]],
            'nextHop desc' => ['nextHop', 'desc', [1, 0, 2]],
            'username asc' => ['username', 'asc', [1, 0, 2]],
            'username desc' => ['username', 'desc', [2, 0, 1]],
            'active asc' => ['active', 'asc', [2, 0, 1]],
            'active desc' => ['active', 'desc', [1, 0, 2]],
            'mxBased asc' => ['mxBased', 'asc', [0, 2, 1]],
            'mxBased desc' => ['mxBased', 'desc', [1, 2, 0]],
        ];
    }

    /**
     * @param  list<int>  $order
     */
    #[DataProvider('sorts')]
    public function test_every_sort_key_in_both_orders(string $sort, string $direction, array $order): void
    {
        $f = $this->mailcow();
        $ids = [
            $f->transport('c.example', 'b.example', 'm', '', 0, 1),
            $f->transport('a.example', 'c.example', '', '', 1, 1),
            $f->transport('b.example', 'a.example', 'z', '', 0, 0),
        ];

        $this->assertSame(array_map(fn (int $i): int => $ids[$i], $order), $this->ids("/api/v2/transports?sort={$sort}&order={$direction}"));
    }

    public function test_paging(): void
    {
        $f = $this->mailcow();
        $ids = array_map(fn (int $i): int => $f->transport("d{$i}.example", 'hop.example'), range(1, 4));

        $this->getJson('/api/v2/transports?limit=2&offset=1')->assertOk()
            ->assertJsonPath('meta', ['total' => 4, 'limit' => 2, 'offset' => 1])
            ->assertJsonPath('data.0.id', $ids[1])
            ->assertJsonPath('data.0.nextHopSharedWith', 3);
    }

    /**
     * @return list<int>
     */
    private function ids(string $uri): array
    {
        return array_column((array) $this->getJson($uri)->assertOk()->json('data'), 'id');
    }
}
