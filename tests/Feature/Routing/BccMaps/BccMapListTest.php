<?php

namespace Tests\Feature\Routing\BccMaps;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T067 (FR-010, FR-046–FR-048, FR-056): listBccMaps and getBccMap.
 */
class BccMapListTest extends TestCase
{
    use RoutingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('xn--bcher-kva.example');
        $f->aliasDomain('example.net', 'example.org');
    }

    public function test_every_member_with_utc_times(): void
    {
        $this->asAdmin();
        $id = $this->mailcow()->bccMap('user@example.org', 'archive@backup.net', 'example.org', 'sender');
        DB::table('bcc_maps')->where('id', $id)->update(['created' => '2026-07-01 14:00:00', 'modified' => null]);

        $response = $this->getJson('/api/v2/bcc-maps/'.$id)->assertOk();

        $this->assertSame([
            'id' => $id,
            'type' => 'sender',
            'localDestination' => 'user@example.org',
            'bccDestination' => 'archive@backup.net',
            'domain' => 'example.org',
            'orphaned' => false,
            'active' => true,
            'createdAt' => '2026-07-01T12:00:00Z',
            'modifiedAt' => null,
        ], array_diff_key((array) $response->json('data'), ['etag' => 1]));
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));
        $this->assertSame($response->json('data'), $this->getJson('/api/v2/bcc-maps')->json('data.0'));

        DB::table('bcc_maps')->where('id', $id)->update(['active' => 0]);
        $modified = $this->getJson('/api/v2/bcc-maps/'.$id)->json('data.modifiedAt');
        $this->assertNotNull($modified);
        $this->assertSame(CarbonImmutable::parse((string) DB::table('bcc_maps')->where('id', $id)->value('modified'), 'Europe/Prague')->utc()->format('Y-m-d\TH:i:s\Z'), $modified);
    }

    public function test_filters_and_search(): void
    {
        $this->asAdmin();
        $f = $this->mailcow();
        $a = $f->bccMap('@example.org', 'archive@backup.net', 'example.org');
        $b = $f->bccMap('anna@example.net', 'copy@other.example', 'example.net', 'sender', 0);
        $c = $f->bccMap('@xn--bcher-kva.example', 'archive@backup.net', 'xn--bcher-kva.example');
        $orphan = $f->bccMap('@gone.example', 'archive@backup.net', 'gone.example');

        $this->assertSame([$a, $c, $orphan], $this->ids('/api/v2/bcc-maps?type=recipient'));
        $this->assertSame([$b], $this->ids('/api/v2/bcc-maps?type=sender'));
        $this->assertSame([$b], $this->ids('/api/v2/bcc-maps?active=false'));
        $this->assertSame([$a, $b], $this->ids('/api/v2/bcc-maps?domain=example.org&domain=example.net'));
        $this->assertSame([$c], $this->ids('/api/v2/bcc-maps?domain=B%C3%BCcher.Example'));
        $this->assertSame([$orphan], $this->ids('/api/v2/bcc-maps?orphaned=true'));
        $this->assertSame([$a, $b, $c], $this->ids('/api/v2/bcc-maps?orphaned=false'));
        $this->assertSame([$b], $this->ids('/api/v2/bcc-maps?q=OTHER.example'));
        $this->assertSame([$b], $this->ids('/api/v2/bcc-maps?q=anna'));
        $this->getJson('/api/v2/bcc-maps/'.$orphan)->assertOk()->assertJsonPath('data.orphaned', true);
    }

    /**
     * @return array<string, array{string, string, list<int>}>
     */
    public static function sorts(): array
    {
        return [
            'id desc' => ['id', 'desc', [2, 1, 0]],
            'type asc' => ['type', 'asc', [0, 2, 1]],
            'localDestination asc' => ['localDestination', 'asc', [2, 0, 1]],
            'bccDestination desc' => ['bccDestination', 'desc', [1, 2, 0]],
            'domain asc' => ['domain', 'asc', [1, 0, 2]],
            'active asc' => ['active', 'asc', [2, 0, 1]],
            'createdAt asc' => ['createdAt', 'asc', [1, 2, 0]],
            'modifiedAt desc' => ['modifiedAt', 'desc', [2, 0, 1]],
        ];
    }

    /**
     * @param  list<int>  $order
     */
    #[DataProvider('sorts')]
    public function test_every_sort_key(string $sort, string $direction, array $order): void
    {
        $this->asAdmin();
        $f = $this->mailcow();
        $ids = [
            $f->bccMap('b@example.org', 'a@backup.net', 'example.org', 'rcpt', 1),
            $f->bccMap('c@example.net', 'c@backup.net', 'example.net', 'sender', 1),
            $f->bccMap('a@xn--bcher-kva.example', 'b@backup.net', 'xn--bcher-kva.example', 'rcpt', 0),
        ];
        DB::table('bcc_maps')->where('id', $ids[0])->update(['created' => '2026-07-03 10:00:00', 'modified' => '2026-07-05 10:00:00']);
        DB::table('bcc_maps')->where('id', $ids[1])->update(['created' => '2026-07-01 10:00:00', 'modified' => null]);
        DB::table('bcc_maps')->where('id', $ids[2])->update(['created' => '2026-07-02 10:00:00', 'modified' => '2026-07-06 10:00:00']);

        $this->assertSame(array_map(fn (int $i): int => $ids[$i], $order), $this->ids("/api/v2/bcc-maps?sort={$sort}&order={$direction}"));
    }

    public function test_an_orphaned_map_is_hidden_from_domain_administrators_and_unknown_maps_are_not_found(): void
    {
        $orphan = $this->mailcow()->bccMap('@gone.example', 'archive@backup.net', 'gone.example');
        $own = $this->mailcow()->bccMap('@example.org', 'archive@backup.net', 'example.org');
        $this->asRoutingDomainAdmin(['example.org']);

        $this->assertSame([$own], $this->ids('/api/v2/bcc-maps'));
        $this->assertSame([], $this->ids('/api/v2/bcc-maps?orphaned=true'));
        $this->getJson('/api/v2/bcc-maps/'.$orphan)->assertNotFound();
        $this->getJson('/api/v2/bcc-maps/9999')->assertNotFound();
    }

    /**
     * @return list<int>
     */
    private function ids(string $uri): array
    {
        return array_column((array) $this->getJson($uri)->assertOk()->json('data'), 'id');
    }
}
