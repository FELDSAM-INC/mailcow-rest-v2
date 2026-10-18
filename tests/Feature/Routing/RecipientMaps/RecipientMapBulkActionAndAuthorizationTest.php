<?php

namespace Tests\Feature\Routing\RecipientMaps;

use Illuminate\Support\Facades\DB;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T084 (FR-005, FR-090, FR-091): recipient map bulk actions, and the refusals of
 * domain administrators (AS-6) and read-only keys.
 */
class RecipientMapBulkActionAndAuthorizationTest extends TestCase
{
    use RoutingTestHelpers;

    public function test_bulk_actions_with_per_item_outcomes(): void
    {
        $f = $this->mailcow();
        $a = $f->recipientMap('@a.example', '@x.example');
        $b = $f->recipientMap('@b.example', '@x.example');
        $this->asAdmin();

        $response = $this->send('POST', '/api/v2/recipient-maps/bulk-actions', ['action' => 'deactivate', 'targets' => [['id' => (string) $a], ['id' => (string) $b], ['id' => '999']]])->assertOk();
        $this->assertSame(['done', 'done', 'notFound'], array_column((array) $response->json('data.results'), 'outcome'));
        $this->assertSame([0, 0], DB::table('recipient_maps')->orderBy('id')->pluck('active')->map(fn (mixed $v): int => (int) $v)->all());

        $this->send('POST', '/api/v2/recipient-maps/bulk-actions', ['action' => 'activate', 'targets' => [['id' => (string) $a]]])->assertOk();
        $this->send('POST', '/api/v2/recipient-maps/bulk-actions', ['action' => 'delete', 'targets' => [['id' => (string) $a], ['id' => (string) $b]]])->assertOk()
            ->assertJsonPath('data.summary.done', 2);
        $this->assertSame(0, DB::table('recipient_maps')->count());
    }

    public function test_domain_administrators_and_read_only_keys_are_refused(): void
    {
        $f = $this->mailcow();
        $id = $f->recipientMap('@a.example', '@x.example');
        $f->domain('example.org');
        $f->admin('admin', 'Passw0rd!', 'SSHA256');
        $key = $this->apiKey('admin', 'readOnly');

        $this->getJson('/api/v2/recipient-maps/'.$id, $this->keyHeader($key))->assertOk();
        $this->send('PATCH', '/api/v2/recipient-maps/'.$id, ['active' => false], $this->keyHeader($key))->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $this->asRoutingDomainAdmin(['example.org'], ['bcc_maps' => 1]);
        $this->getJson('/api/v2/recipient-maps')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->send('POST', '/api/v2/recipient-maps/bulk-actions', ['action' => 'delete', 'targets' => [['id' => (string) $id]]])->assertForbidden();
        $this->assertSame(1, DB::table('recipient_maps')->count());
    }
}
