<?php

namespace Tests\Feature\Routing\Relayhosts;

use Illuminate\Support\Facades\DB;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T031 (FR-090, FR-091): applyRelayhostBulkAction.
 */
class RelayhostBulkActionTest extends TestCase
{
    use RoutingTestHelpers;

    /** @var list<int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $this->ids = [$f->relayhost(null, 'a.example'), $f->relayhost(null, 'b.example'), $f->relayhost(null, 'c.example')];
        $this->asAdmin();
    }

    public function test_three_relayhosts_are_deactivated_and_an_unknown_one_is_not_found(): void
    {
        $targets = [...array_map(fn (int $id): array => ['id' => (string) $id], $this->ids), ['id' => '999']];

        $response = $this->send('POST', '/api/v2/relayhosts/bulk-actions', ['action' => 'deactivate', 'targets' => $targets])->assertOk();

        $results = (array) $response->json('data.results');
        $this->assertSame(['done', 'done', 'done', 'notFound'], array_column($results, 'outcome'));
        $this->assertSame(['total' => 4, 'done' => 3, 'notFound' => 1, 'refused' => 0, 'outdated' => 0, 'failed' => 0], $response->json('data.summary'));

        foreach ($this->ids as $i => $id) {
            $this->assertSame(0, (int) $this->relayhostRow($id)?->active);
            $this->assertSame($this->getJson('/api/v2/relayhosts/'.$id)->headers->get('ETag'), $results[$i]['version']);
        }

        $bulkRequestId = $response->json('data.bulkRequestId');
        $records = array_merge(...array_map(fn (int $id): array => $this->auditRecords('relayhostUpdate', 'relayhost', (string) $id), $this->ids));
        $this->assertCount(3, $records);
        $this->assertSame([$bulkRequestId], array_values(array_unique(array_map(fn (object $r): string => $r->details['bulkRequestId'], $records))));
        [$missing] = $this->auditRecords('relayhostUpdate', 'relayhost', '999');
        $this->assertSame('notFound', $missing->failure_reason);
        $this->assertSame($bulkRequestId, $missing->details['bulkRequestId']);
    }

    public function test_an_outdated_version_leaves_its_target_unchanged(): void
    {
        $stale = (string) $this->getJson('/api/v2/relayhosts/'.$this->ids[0])->headers->get('ETag');
        $this->send('PATCH', '/api/v2/relayhosts/'.$this->ids[0], ['host' => 'moved.example'])->assertOk();

        $response = $this->send('POST', '/api/v2/relayhosts/bulk-actions', ['action' => 'deactivate', 'targets' => [
            ['id' => (string) $this->ids[0], 'version' => $stale],
            ['id' => (string) $this->ids[1], 'version' => trim((string) $this->getJson('/api/v2/relayhosts/'.$this->ids[1])->headers->get('ETag'), '"')],
        ]])->assertOk();

        $this->assertSame(['outdated', 'done'], array_column((array) $response->json('data.results'), 'outcome'));
        $this->assertSame(1, (int) $this->relayhostRow($this->ids[0])?->active);
        $this->assertSame(0, (int) $this->relayhostRow($this->ids[1])?->active);
    }

    public function test_a_target_named_twice_is_processed_once_and_deletion_clears_assignments(): void
    {
        $this->mailcow()->domain('example.org', attributes: ['relayhost' => (string) $this->ids[0]]);

        $response = $this->send('POST', '/api/v2/relayhosts/bulk-actions', ['action' => 'delete', 'targets' => [
            ['id' => (string) $this->ids[0]], ['id' => (string) $this->ids[0]], ['id' => (string) $this->ids[1]],
        ]])->assertOk();

        $this->assertSame(['done', 'done'], array_column((array) $response->json('data.results'), 'outcome'));
        $this->assertNull($this->relayhostRow($this->ids[0]));
        $this->assertSame('0', $this->domainRow('example.org')?->relayhost);
        [$record] = $this->auditRecords('relayhostDeletion', 'relayhost', (string) $this->ids[0]);
        $this->assertSame(['example.org'], $record->details['clearedDomains']);
        $this->assertArrayNotHasKey('version', (array) $response->json('data.results.0'));
    }

    public function test_activation(): void
    {
        $this->send('PATCH', '/api/v2/relayhosts/'.$this->ids[2], ['active' => false])->assertOk();

        $this->send('POST', '/api/v2/relayhosts/bulk-actions', ['action' => 'activate', 'targets' => [['id' => (string) $this->ids[2]]]])->assertOk()
            ->assertJsonPath('data.results.0.outcome', 'done');
        $this->assertSame(1, (int) $this->relayhostRow($this->ids[2])?->active);
    }

    public function test_invalid_bulk_requests_are_refused_as_a_whole(): void
    {
        $this->withoutRequestValidation();
        $targets = array_map(fn (int $i): array => ['id' => (string) $i], range(1, 101));

        $this->send('POST', '/api/v2/relayhosts/bulk-actions', ['action' => 'delete', 'targets' => $targets])->assertStatus(422)
            ->assertJsonPath('errors.targets.0.code', 'tooMany')->assertJsonPath('errors.targets.0.params.max', 100);
        $this->send('POST', '/api/v2/relayhosts/bulk-actions', ['action' => 'switchToSender', 'targets' => [['id' => '1']]])->assertStatus(422)
            ->assertJsonPath('errors.action.0.code', 'notInList');
        $this->assertSame(3, $this->countRelayhosts());
    }

    public function test_a_domain_administrator_and_a_read_only_key_are_refused_as_a_whole(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asRoutingDomainAdmin(['example.org'], ['domain_relayhost' => 1]);
        $this->send('POST', '/api/v2/relayhosts/bulk-actions', ['action' => 'delete', 'targets' => [['id' => (string) $this->ids[0]]]])
            ->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');

        $key = $this->apiKey('admin', 'readOnly');
        $this->send('POST', '/api/v2/relayhosts/bulk-actions', ['action' => 'delete', 'targets' => [['id' => (string) $this->ids[0]]]], $this->keyHeader($key))
            ->assertForbidden()->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $this->assertSame(3, $this->countRelayhosts());
        $refusals = $this->auditRecords('relayhostDeletion');
        $this->assertCount(2, $refusals);
        $this->assertSame([(string) $this->ids[0]], $refusals[0]->details['targetIds']);
        $this->assertNull($refusals[0]->target_type);
    }

    private function countRelayhosts(): int
    {
        return DB::table('relayhosts')->count();
    }
}
