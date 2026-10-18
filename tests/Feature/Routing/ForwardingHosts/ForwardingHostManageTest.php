<?php

namespace Tests\Feature\Routing\ForwardingHosts;

use Illuminate\Support\Facades\Redis;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T089 (FR-074, FR-075, FR-079, FR-080): listForwardingHosts, getForwardingHost,
 * updateForwardingHost and deleteForwardingHost.
 */
class ForwardingHostManageTest extends TestCase
{
    use RoutingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->forwardingHost('2001:db8::/32', 'v6.example');
        $f->forwardingHost('192.0.2.10', 'b.example', true);
        $f->forwardingHost('10.0.0.0/8', 'a.example');
        $f->forwardingHost('192.0.2.0/24', 'a.example');
        $f->forwardingHost('2001:0db8::0001', '');
        $this->asAdmin();
    }

    public function test_the_list_in_natural_address_order_with_filters_search_and_paging(): void
    {
        $list = $this->getJson('/api/v2/forwarding-hosts')->assertOk()->assertJsonPath('meta.total', 5);
        $this->assertSame(['10.0.0.0/8', '192.0.2.0/24', '192.0.2.10', '2001:db8::/32', '2001:0db8::0001'], array_column((array) $list->json('data'), 'address'));
        $this->assertSame(['address' => '192.0.2.10', 'source' => 'b.example', 'spamFilter' => false], array_diff_key((array) $list->json('data.2'), ['etag' => 1]));

        $this->assertSame(['192.0.2.10'], $this->addresses('/api/v2/forwarding-hosts?spamFilter=false'));
        $this->assertSame(['10.0.0.0/8', '192.0.2.0/24'], $this->addresses('/api/v2/forwarding-hosts?source=a.example'));
        $this->assertSame(['2001:0db8::0001'], $this->addresses('/api/v2/forwarding-hosts?source='));
        $this->assertSame(['2001:db8::/32'], $this->addresses('/api/v2/forwarding-hosts?q=V6.EX'));
        $this->assertSame(['192.0.2.0/24', '192.0.2.10'], $this->addresses('/api/v2/forwarding-hosts?q=192.0.2'));
        $this->assertSame(['b.example', 'v6.example'], array_slice(array_column((array) $this->getJson('/api/v2/forwarding-hosts?sort=source')->json('data'), 'source'), 3));
        $this->assertSame('192.0.2.10', $this->getJson('/api/v2/forwarding-hosts?sort=spamFilter')->json('data.0.address'));
        $this->getJson('/api/v2/forwarding-hosts?limit=2&offset=1')->assertJsonPath('meta', ['total' => 5, 'limit' => 2, 'offset' => 1])->assertJsonPath('data.0.address', '192.0.2.0/24');
    }

    public function test_reads_by_percent_encoded_address(): void
    {
        $prefix = $this->getJson('/api/v2/forwarding-hosts/192.0.2.0%2F24')->assertOk()->assertJsonPath('data.source', 'a.example');
        $this->assertSame($prefix->headers->get('ETag'), $prefix->json('data.etag'));
        $this->assertSame($prefix->json('data'), $this->getJson('/api/v2/forwarding-hosts?source=a.example')->json('data.1'));
        $this->getJson('/api/v2/forwarding-hosts/2001%3Adb8%3A%3A%2F32')->assertOk()->assertJsonPath('data.address', '2001:db8::/32');
        $this->getJson('/api/v2/forwarding-hosts/2001%3A0db8%3A%3A0001')->assertOk()->assertJsonPath('data.source', '');
        $this->getJson('/api/v2/forwarding-hosts/203.0.113.1')->assertNotFound();
    }

    public function test_the_spam_filter_is_switched_and_read_only_members_are_checked(): void
    {
        $etag = (string) $this->getJson('/api/v2/forwarding-hosts/192.0.2.0%2F24')->headers->get('ETag');

        $this->send('PATCH', '/api/v2/forwarding-hosts/192.0.2.0%2F24', ['spamFilter' => false, 'address' => '192.0.2.0/24', 'source' => 'a.example'])->assertOk()
            ->assertJsonPath('data.spamFilter', false);
        $this->assertSame('1', Redis::connection('mailcow')->client()->hGet('KEEP_SPAM', '192.0.2.0/24'));
        $this->send('PATCH', '/api/v2/forwarding-hosts/192.0.2.0%2F24', ['spamFilter' => true], ['If-Match' => $etag])->assertStatus(412);
        $this->send('PATCH', '/api/v2/forwarding-hosts/192.0.2.0%2F24', ['source' => 'other'])->assertStatus(422)->assertJsonPath('errors.source.0.code', 'immutable');
        $this->send('PATCH', '/api/v2/forwarding-hosts/2001%3A0db8%3A%3A0001', ['spamFilter' => false])->assertOk()->assertJsonPath('data.source', '');

        [$record] = $this->auditRecords('forwardingHostUpdate', 'forwardingHost', '192.0.2.0/24');
        $this->assertSame(['changes' => ['spamFilter' => ['old' => true, 'new' => false]]], $record->details);
    }

    public function test_deletion_removes_both_fields(): void
    {
        $this->send('DELETE', '/api/v2/forwarding-hosts/192.0.2.10')->assertNoContent();

        $this->assertFalse((bool) Redis::connection('mailcow')->client()->hExists('WHITELISTED_FWD_HOST', '192.0.2.10'));
        $this->assertFalse((bool) Redis::connection('mailcow')->client()->hExists('KEEP_SPAM', '192.0.2.10'));
        [$record] = $this->auditRecords('forwardingHostDeletion', 'forwardingHost', '192.0.2.10');
        $this->assertSame(['source' => 'b.example'], $record->details);
        $this->send('DELETE', '/api/v2/forwarding-hosts/192.0.2.10')->assertNotFound();
    }

    /**
     * @return list<string>
     */
    private function addresses(string $uri): array
    {
        return array_column((array) $this->getJson($uri)->assertOk()->json('data'), 'address');
    }
}
