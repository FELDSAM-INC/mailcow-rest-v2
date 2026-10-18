<?php

namespace Tests\Feature\Routing\ForwardingHosts;

use App\Clients\SharedStore\SharedStore;
use App\Services\Routing\ForwardingHostStore;
use RuntimeException;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T090 (FR-082, edge cases): an unavailable shared store or DNS refuses forwarding host
 * changes with `503 temporary-failure` and changes nothing; a failure after the Redis transaction
 * restores the previous fields.
 */
class ForwardingHostFailureTest extends TestCase
{
    use RoutingTestHelpers;

    public function test_an_unavailable_shared_store_answers_temporary_failure(): void
    {
        $this->mailcow()->forwardingHost('192.0.2.10', 'b.example');
        $this->asAdmin();
        $this->breakSharedStore();

        foreach ([['POST', '/api/v2/forwarding-hosts', ['host' => '192.0.2.0/24']], ['PATCH', '/api/v2/forwarding-hosts/192.0.2.10', ['spamFilter' => false]], ['DELETE', '/api/v2/forwarding-hosts/192.0.2.10', null]] as [$method, $uri, $body]) {
            $this->send($method, $uri, $body)->assertStatus(503)->assertHeader('Retry-After')->assertJsonPath('type', '/api/v2/problems/temporary-failure');
        }

        $this->assertCount(1, $this->auditRecords('forwardingHostCreation'));
        $this->assertSame('temporaryFailure', $this->auditRecords('forwardingHostDeletion')[0]->failure_reason);
    }

    public function test_a_temporary_dns_failure_answers_temporary_failure(): void
    {
        $this->asAdmin();
        $this->dns()->failing('flaky.example');

        $this->send('POST', '/api/v2/forwarding-hosts', ['host' => 'flaky.example'])->assertStatus(503)
            ->assertJsonPath('type', '/api/v2/problems/temporary-failure')->assertJsonPath('dependency', 'dns');
        $this->assertSame([], $this->redis()->hGetAll('WHITELISTED_FWD_HOST') ?: []);
    }

    public function test_a_failure_after_the_redis_transaction_restores_the_previous_fields(): void
    {
        $this->mailcow()->forwardingHost('203.0.113.7', 'other.example', true);
        $this->dns()->txtRecords('forwarder.example', 'v=spf1 ip4:198.51.100.0/24 ip4:203.0.113.7 -all');
        $this->app->instance(ForwardingHostStore::class, new class(app(SharedStore::class)) extends ForwardingHostStore
        {
            public function apply(array $sources, array $keepSpam, array $deleted = []): void
            {
                parent::apply($sources, $keepSpam, $deleted);

                throw new RuntimeException('the commit failed');
            }
        });
        $this->asAdmin();

        $this->send('POST', '/api/v2/forwarding-hosts', ['host' => 'forwarder.example'])->assertStatus(500);

        $this->assertSame(['203.0.113.7' => 'other.example'], $this->redis()->hGetAll('WHITELISTED_FWD_HOST') ?: []);
        $this->assertSame(['203.0.113.7' => '1'], $this->redis()->hGetAll('KEEP_SPAM') ?: []);
        $this->assertSame([], $this->auditRecords('forwardingHostCreation', 'forwardingHost', 'forwarder.example') === [] ? [] : array_filter($this->auditRecords('forwardingHostCreation', 'forwardingHost', 'forwarder.example'), fn (object $r): bool => $r->outcome === 'success'));
    }
}
