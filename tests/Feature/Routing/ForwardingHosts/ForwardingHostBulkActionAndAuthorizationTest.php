<?php

namespace Tests\Feature\Routing\ForwardingHosts;

use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T091 (FR-005, FR-082, FR-090, FR-091): forwarding host bulk actions, and the refusals
 * of domain administrators and read-only keys.
 */
class ForwardingHostBulkActionAndAuthorizationTest extends TestCase
{
    use RoutingTestHelpers;

    public function test_five_entries_have_their_spam_filter_disabled_and_are_deleted(): void
    {
        $addresses = ['192.0.2.1', '192.0.2.2', '192.0.2.0/24', '2001:db8::1', '2001:db8::/32'];

        foreach ($addresses as $address) {
            $this->mailcow()->forwardingHost($address, 'src');
        }

        $this->asAdmin();
        $stale = (string) $this->getJson('/api/v2/forwarding-hosts/192.0.2.1')->headers->get('ETag');

        $response = $this->send('POST', '/api/v2/forwarding-hosts/bulk-actions', ['action' => 'disableSpamFilter', 'targets' => array_map(fn (string $a): array => ['id' => $a], $addresses)])->assertOk();
        $this->assertSame(array_fill(0, 5, 'done'), array_column((array) $response->json('data.results'), 'outcome'));
        $this->assertCount(5, $this->redis()->hGetAll('KEEP_SPAM') ?: []);

        $this->send('POST', '/api/v2/forwarding-hosts/bulk-actions', ['action' => 'enableSpamFilter', 'targets' => [['id' => '192.0.2.1', 'version' => $stale], ['id' => '192.0.2.2'], ['id' => '203.0.113.1']]])->assertOk()
            ->assertJsonPath('data.results.0.outcome', 'outdated')->assertJsonPath('data.results.1.outcome', 'done')->assertJsonPath('data.results.2.outcome', 'notFound');
        $this->send('POST', '/api/v2/forwarding-hosts/bulk-actions', ['action' => 'delete', 'targets' => [['id' => '192.0.2.0/24'], ['id' => '2001:db8::/32']]])->assertOk()
            ->assertJsonPath('data.summary.done', 2);
        $this->assertSame(['192.0.2.1', '192.0.2.2', '2001:db8::1'], array_keys($this->redis()->hGetAll('WHITELISTED_FWD_HOST') ?: []));
    }

    public function test_an_unavailable_shared_store_fails_each_item(): void
    {
        $this->mailcow()->forwardingHost('192.0.2.1', 'src');
        $this->asAdmin();
        $this->breakSharedStore();

        $response = $this->send('POST', '/api/v2/forwarding-hosts/bulk-actions', ['action' => 'delete', 'targets' => [['id' => '192.0.2.1'], ['id' => '192.0.2.2']]])->assertOk();

        $this->assertSame(['failed', 'failed'], array_column((array) $response->json('data.results'), 'outcome'));
        $this->assertSame('/api/v2/problems/temporary-failure', $response->json('data.results.0.problem.type'));
    }

    public function test_domain_administrators_and_read_only_keys_are_refused(): void
    {
        $this->mailcow()->forwardingHost('192.0.2.1', 'src');
        $this->mailcow()->domain('example.org');
        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
        $key = $this->apiKey('admin', 'readOnly');

        $this->getJson('/api/v2/forwarding-hosts', $this->keyHeader($key))->assertOk();
        $this->send('DELETE', '/api/v2/forwarding-hosts/192.0.2.1', null, $this->keyHeader($key))->assertForbidden()->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $this->asRoutingDomainAdmin(['example.org'], ['bcc_maps' => 1]);
        $this->getJson('/api/v2/forwarding-hosts')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->send('POST', '/api/v2/forwarding-hosts', ['host' => '192.0.2.9'])->assertForbidden();
        $this->assertSame(['192.0.2.1' => 'src'], $this->redis()->hGetAll('WHITELISTED_FWD_HOST') ?: []);
    }
}
