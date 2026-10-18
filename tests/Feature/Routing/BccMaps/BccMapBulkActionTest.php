<?php

namespace Tests\Feature\Routing\BccMaps;

use Illuminate\Support\Facades\DB;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T071 (FR-090, FR-091): applyBccMapBulkAction.
 */
class BccMapBulkActionTest extends TestCase
{
    use RoutingTestHelpers;

    public function test_switching_types_activation_deletion_scope_and_versions(): void
    {
        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('foreign.example');
        $a = $f->bccMap('a@example.org', 'x@backup.net', 'example.org', 'sender');
        $b = $f->bccMap('b@example.org', 'x@backup.net', 'example.org', 'sender');
        $c = $f->bccMap('c@example.org', 'x@backup.net', 'example.org', 'sender');
        $f->bccMap('c@example.org', 'y@backup.net', 'example.org', 'rcpt');
        $foreign = $f->bccMap('@foreign.example', 'x@backup.net', 'foreign.example', 'sender');
        $this->asRoutingDomainAdmin(['example.org'], ['bcc_maps' => 1]);

        $response = $this->send('POST', '/api/v2/bcc-maps/bulk-actions', ['action' => 'switchToRecipient', 'targets' => [
            ['id' => (string) $a], ['id' => (string) $b], ['id' => (string) $c], ['id' => (string) $foreign],
        ]])->assertOk();

        $results = (array) $response->json('data.results');
        $this->assertSame(['done', 'done', 'refused', 'notFound'], array_column($results, 'outcome'));
        $this->assertSame('/api/v2/problems/routing-entry-exists', $results[2]['problem']['type']);
        $this->assertSame(['rcpt', 'rcpt', 'sender'], DB::table('bcc_maps')->whereIn('id', [$a, $b, $c])->orderBy('id')->pluck('type')->all());
        $this->assertSame('sender', DB::table('bcc_maps')->where('id', $foreign)->value('type'));

        $stale = (string) $this->getJson('/api/v2/bcc-maps/'.$a)->headers->get('ETag');
        $this->send('POST', '/api/v2/bcc-maps/bulk-actions', ['action' => 'switchToSender', 'targets' => [['id' => (string) $a]]])->assertOk();
        $this->send('POST', '/api/v2/bcc-maps/bulk-actions', ['action' => 'deactivate', 'targets' => [['id' => (string) $a, 'version' => $stale], ['id' => (string) $b]]])->assertOk()
            ->assertJsonPath('data.results.0.outcome', 'outdated')->assertJsonPath('data.results.1.outcome', 'done');
        $this->send('POST', '/api/v2/bcc-maps/bulk-actions', ['action' => 'activate', 'targets' => [['id' => (string) $b]]])->assertOk();
        $this->assertSame(1, (int) DB::table('bcc_maps')->where('id', $b)->value('active'));
        $this->send('POST', '/api/v2/bcc-maps/bulk-actions', ['action' => 'delete', 'targets' => [['id' => (string) $a], ['id' => (string) $b]]])->assertOk()
            ->assertJsonPath('data.summary.done', 2);
        $this->assertSame(0, DB::table('bcc_maps')->whereIn('id', [$a, $b])->count());
    }

    public function test_a_domain_administrator_without_the_flag_is_refused_as_a_whole(): void
    {
        $this->mailcow()->domain('example.org');
        $id = $this->mailcow()->bccMap('a@example.org', 'x@backup.net', 'example.org', 'sender');
        $this->asRoutingDomainAdmin(['example.org']);

        $this->send('POST', '/api/v2/bcc-maps/bulk-actions', ['action' => 'delete', 'targets' => [['id' => (string) $id]]])->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/permission-flag-required')
            ->assertJsonPath('requiredFlags', ['bccMaps']);
        $this->assertTrue(DB::table('bcc_maps')->where('id', $id)->exists());
    }
}
