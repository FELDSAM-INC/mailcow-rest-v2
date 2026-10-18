<?php

namespace Tests\Feature\Dkim;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Dkim\DkimFixtures;
use Tests\Support\Dkim\DkimTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T086 (US5 AS7, FR-059, research R28): administrators delete up to 100 keys in one
 * request; each distinct name is processed, version-checked and audited on its own, with the bulk
 * request's identifier, and reports its own outcome and, while the publication is enabled, its
 * record removal, within a shared budget of RESTV2_DKIM_BULK_PUBLICATION_BUDGET seconds.
 */
class DkimKeyBulkDeleteTest extends TestCase
{
    use DkimTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['k1', 'k2', 'k3'] as $name) {
            $this->mailcow()->dkimKey("{$name}.example", 'dkim', 1024);
        }
    }

    /**
     * @param  list<array{id: string, version?: string}>  $targets
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function bulk(array $targets, array $headers = [], string $action = 'delete'): TestResponse
    {
        return $this->send('POST', '/api/v2/dkim-keys/bulk-actions', ['action' => $action, 'targets' => $targets], $headers);
    }

    private function tag(string $name): string
    {
        return (string) $this->getJson('/api/v2/dkim-keys/'.$name)->headers->get('ETag');
    }

    public function test_each_target_gets_its_own_outcome_and_audit_record(): void
    {
        $this->asAdmin();
        $tags = ['k1' => $this->tag('k1.example'), 'k2' => $this->tag('k2.example'), 'k3' => $this->tag('k3.example')];
        // k3 changes after the client read it (a legacy rotation).
        $this->mailcow()->redisHash('DKIM_SELECTORS', 'k3.example', 'rotated');

        $response = $this->bulk([
            ['id' => 'k1.example', 'version' => $tags['k1']],
            ['id' => 'k2.example', 'version' => trim($tags['k2'], '"')],
            ['id' => 'k3.example', 'version' => $tags['k3']],
            ['id' => 'nokey.example'],
        ])->assertOk();

        $this->assertSame(['k1.example', 'k2.example', 'k3.example', 'nokey.example'], array_column((array) $response->json('data.results'), 'id'));
        $this->assertSame(['done', 'done', 'outdated', 'notFound'], array_column((array) $response->json('data.results'), 'outcome'));
        $response->assertJsonPath('data.results.2.problem.status', 412)
            ->assertJsonPath('data.summary', ['total' => 4, 'done' => 2, 'notFound' => 1, 'refused' => 0, 'outdated' => 1, 'failed' => 0])
            ->assertJsonMissingPath('meta');

        $this->assertNoDkim('k1.example');
        $this->assertNoDkim('k2.example');
        $this->assertNoPrivateKeyField('dkim.k1.example');
        $this->assertSame('rotated', $this->dkimField('DKIM_SELECTORS', 'k3.example'));

        $bulkRequestId = (string) $response->json('data.bulkRequestId');
        $records = $this->auditOfType('dkimKeyDeletion');
        $this->assertSame(['k1.example', 'k2.example', 'k3.example', 'nokey.example'], array_column($records, 'target_id'));
        $this->assertSame([null, null, 'versionOutdated', 'notFound'], array_column($records, 'failure_reason'));

        foreach ($records as $record) {
            $this->assertSame($bulkRequestId, $record->details['bulkRequestId']);
        }

        $this->assertSame('notConfigured', $records[0]->details['dnsPublication']['status']);
    }

    public function test_a_name_given_twice_is_processed_once_and_legacy_names_are_exact(): void
    {
        $this->mailcow()->legacyDkimKey('Example.ORG', 'legacy');
        $this->mailcow()->domain('xn--bcher-kva.example');
        $this->mailcow()->dkimKey('xn--bcher-kva.example');
        $this->asAdmin();

        $response = $this->bulk([['id' => 'k1.example'], ['id' => 'K1.EXAMPLE'], ['id' => 'bücher.example'], ['id' => 'xn--bcher-kva.example'], ['id' => 'Example.ORG']])->assertOk();

        $this->assertSame([['k1.example', 'done'], ['bücher.example', 'done'], ['Example.ORG', 'done']], array_map(fn (array $r): array => [$r['id'], $r['outcome']], (array) $response->json('data.results')));
        $this->assertNoDkim('Example.ORG');
        $this->assertNoDkim('xn--bcher-kva.example');
        $this->assertCount(3, $this->auditOfType('dkimKeyDeletion'));
    }

    public function test_invalid_requests_are_refused_as_a_whole(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->bulk(array_map(fn (int $i): array => ['id' => "n{$i}.example"], range(1, 101)))->assertStatus(422)
            ->assertJsonPath('errors.targets.0.code', 'tooMany')
            ->assertJsonPath('errors.targets.0.params.max', 100);
        $this->bulk([['id' => 'k1.example']], [], 'activate')->assertStatus(422)->assertJsonPath('errors.action.0.code', 'notInList');
        $this->assertNotNull($this->dkimField('DKIM_SELECTORS', 'k1.example'));

        $records = $this->auditOfType('dkimKeyDeletion');
        $this->assertCount(2, $records);
        $this->assertNull($records[1]->target_type);
        $this->assertSame(['k1.example'], $records[1]->details['targetIds']);
    }

    public function test_domain_administrators_and_read_only_keys_are_refused_as_a_whole(): void
    {
        $this->mailcow()->domain('k1.example');
        $this->asDomainAdmin(['k1.example']);
        $this->bulk([['id' => 'k1.example']])->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->mailcow()->admin('admin', 'Passw0rd!');
        $this->bulk([['id' => 'k1.example']], $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $this->assertNotNull($this->dkimField('DKIM_SELECTORS', 'k1.example'));
        $records = $this->auditOfType('dkimKeyDeletion');
        $this->assertSame(['permissionDenied', 'permissionDenied'], array_column($records, 'failure_reason'));
        $this->assertSame(['k1.example'], $records[0]->details['targetIds']);
    }

    public function test_an_unavailable_store_fails_every_item_and_removes_nothing(): void
    {
        $this->asAdmin();
        $this->storeUnavailable();

        $response = $this->bulk([['id' => 'k1.example'], ['id' => 'k2.example']])->assertOk();

        $this->assertSame(['failed', 'failed'], array_column((array) $response->json('data.results'), 'outcome'));
        $response->assertJsonPath('data.results.0.problem.type', '/api/v2/problems/temporary-failure');
        $this->assertNotNull($this->dkimField('DKIM_SELECTORS', 'k1.example'));
        $this->assertSame(['temporaryFailure', 'temporaryFailure'], array_column($this->auditOfType('dkimKeyDeletion'), 'failure_reason'));
    }

    public function test_the_record_removals_are_reported_while_the_publication_is_enabled(): void
    {
        $this->enablePublication();
        $this->ispconfig()->zone('k1.example.', 10, 5);
        $this->ispconfig()->record(10, 'dkim._domainkey', 'TXT', 'v=DKIM1;k=rsa;t=s;s=email;p='.DkimFixtures::RSA_1024_PUBLIC);
        $this->ispconfig()->zone('k2.example.', 11, 5);
        $this->asAdmin();

        $response = $this->bulk([['id' => 'K1.example'], ['id' => 'k2.example'], ['id' => 'k3.example'], ['id' => 'nokey.example']])->assertOk();

        $meta = (array) $response->json('meta.dnsPublication');
        $this->assertSame(['K1.example', 'k2.example', 'k3.example'], array_keys($meta));
        $this->assertSame(['removed', 'noRecordToRemove', 'noZone'], array_column($meta, 'status'));
        $this->assertSame('dkim._domainkey', $meta['K1.example']['recordName']);
        $this->assertSame(['removed', 'noRecordToRemove', 'noZone'], array_map(fn ($r) => $r->details['dnsPublication']['status'], array_slice($this->auditOfType('dkimKeyDeletion'), 0, 3)));
    }

    public function test_the_shared_publication_budget(): void
    {
        $this->enablePublication();
        $this->ispconfig()->delay('login', 15.0);
        foreach (['k4', 'k5', 'k6'] as $name) {
            $this->mailcow()->dkimKey("{$name}.example", 'dkim', 1024);
        }
        $this->asAdmin();

        $response = $this->bulk(array_map(fn (int $i): array => ['id' => "k{$i}.example"], range(1, 6)))->assertOk();

        $this->assertSame(array_fill(0, 6, 'done'), array_column((array) $response->json('data.results'), 'outcome'));
        $outcomes = array_map(fn (array $o): array => [$o['status'], $o['reason']['code'] ?? null], array_values((array) $response->json('meta.dnsPublication')));
        $this->assertSame(array_fill(0, 6, ['failed', 'timeout']), $outcomes);
        $this->ispconfig()->assertCallCount('login', 4);
        $this->assertNoDkim('k6.example');
        $this->assertSame('timeout', $this->auditOfType('dkimKeyDeletion')[5]->details['dnsPublication']['reasonCode']);
    }
}
