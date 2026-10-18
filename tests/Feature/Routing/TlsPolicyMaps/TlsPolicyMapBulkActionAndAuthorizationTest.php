<?php

namespace Tests\Feature\Routing\TlsPolicyMaps;

use Illuminate\Support\Facades\DB;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T080 (FR-005, FR-090, FR-091): TLS policy map bulk actions, and the refusals of
 * domain administrators, mailbox users and read-only keys (the full caller matrix is
 * RoutingAuthorizationMatrixTest).
 */
class TlsPolicyMapBulkActionAndAuthorizationTest extends TestCase
{
    use RoutingTestHelpers;

    public function test_bulk_actions_with_per_item_outcomes(): void
    {
        $f = $this->mailcow();
        $a = $f->tlsPolicyMap('a.example', 'dane');
        $b = $f->tlsPolicyMap('b.example', 'encrypt');
        $this->asAdmin();
        $stale = (string) $this->getJson('/api/v2/tls-policy-maps/'.$b)->headers->get('ETag');
        $this->send('PATCH', '/api/v2/tls-policy-maps/'.$b, ['parameters' => 'match=nexthop'])->assertOk();

        $response = $this->send('POST', '/api/v2/tls-policy-maps/bulk-actions', ['action' => 'deactivate', 'targets' => [
            ['id' => (string) $a], ['id' => (string) $a], ['id' => (string) $b, 'version' => $stale], ['id' => '999'],
        ]])->assertOk();

        $this->assertSame(['done', 'outdated', 'notFound'], array_column((array) $response->json('data.results'), 'outcome'));
        $this->assertSame(0, (int) DB::table('tls_policy_override')->where('id', $a)->value('active'));
        [$record] = $this->auditRecords('tlsPolicyMapUpdate', 'tlsPolicyMap', (string) $a);
        $this->assertSame($response->json('data.bulkRequestId'), $record->details['bulkRequestId']);

        $this->send('POST', '/api/v2/tls-policy-maps/bulk-actions', ['action' => 'activate', 'targets' => [['id' => (string) $a]]])->assertOk();
        $this->assertSame(1, (int) DB::table('tls_policy_override')->where('id', $a)->value('active'));
        $this->send('POST', '/api/v2/tls-policy-maps/bulk-actions', ['action' => 'delete', 'targets' => [['id' => (string) $a], ['id' => (string) $b]]])->assertOk()
            ->assertJsonPath('data.summary.done', 2);
        $this->assertSame(0, DB::table('tls_policy_override')->count());
    }

    public function test_refusals(): void
    {
        $f = $this->mailcow();
        $id = $f->tlsPolicyMap('a.example', 'dane');
        $f->domain('example.org');
        $f->admin('admin', 'Passw0rd!', 'SSHA256');
        $key = $this->apiKey('admin', 'readOnly');

        $this->getJson('/api/v2/tls-policy-maps', $this->keyHeader($key))->assertOk();
        $this->send('POST', '/api/v2/tls-policy-maps', ['destination' => 'b.example', 'policy' => 'may'], $this->keyHeader($key))->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $this->asRoutingDomainAdmin(['example.org'], ['domain_relayhost' => 1, 'mailbox_relayhost' => 1, 'bcc_maps' => 1]);

        foreach ([['GET', '/api/v2/tls-policy-maps', null], ['GET', '/api/v2/tls-policy-maps/'.$id, null], ['POST', '/api/v2/tls-policy-maps', ['destination' => 'b.example', 'policy' => 'may']],
            ['PATCH', '/api/v2/tls-policy-maps/'.$id, ['active' => false]], ['DELETE', '/api/v2/tls-policy-maps/'.$id, null],
            ['POST', '/api/v2/tls-policy-maps/bulk-actions', ['action' => 'delete', 'targets' => [['id' => (string) $id]]]]] as [$method, $uri, $body]) {
            $this->send($method, $uri, $body)->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
        }

        $this->assertSame(1, DB::table('tls_policy_override')->count());
    }
}
