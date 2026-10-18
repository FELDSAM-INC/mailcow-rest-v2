<?php

namespace Tests\Feature\Domains;

use App\Clients\SharedStore\SharedStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Feature 002 T081 (FR-062, FR-063, SC-010, US6): applyDomainBulkAction.
 */
class DomainBulkActionTest extends TestCase
{
    use ApiKeys;
    use DomainTestHelpers;

    /**
     * @param  list<array<string, string>>  $targets
     * @return TestResponse<Response>
     */
    private function bulk(string $action, array $targets): TestResponse
    {
        return $this->postJson('/api/v2/domains/bulk-actions', ['action' => $action, 'targets' => $targets], $this->csrf());
    }

    public function test_fifty_domains_are_deactivated_with_one_groupware_rebuild(): void
    {
        $targets = [];
        for ($i = 1; $i <= 50; $i++) {
            $this->mailcow()->domain("d{$i}.example");
            $targets[] = ['id' => "d{$i}.example"];
        }
        $this->asAdmin();

        $data = $this->bulk('deactivate', $targets)->assertOk()->json('data');

        $this->assertCount(50, $data['results']);
        $this->assertSame(['total' => 50, 'done' => 50, 'notFound' => 0, 'refused' => 0, 'outdated' => 0, 'failed' => 0], $data['summary']);
        $this->assertSame(0, DB::table('domain')->where('active', 1)->count());
        foreach ($data['results'] as $result) {
            $this->assertSame('done', $result['outcome']);
            $this->assertSame($this->getJson('/api/v2/domains/'.$result['id'])->headers->get('ETag'), $result['version']);
        }
        $records = $this->auditOf('domainUpdate');
        $this->assertCount(50, $records);
        $this->assertSame([$data['bulkRequestId']], array_values(array_unique(array_map(fn (object $r): string => $r->details['bulkRequestId'], $records))));
        $this->assertSame(1, $this->memcached()->flushCount());
    }

    public function test_deleting_a_selection_with_a_non_empty_domain(): void
    {
        foreach (['a', 'b', 'c', 'full'] as $d) {
            $this->mailcow()->domain("{$d}.example");
        }
        $this->mailcow()->mailbox('user@full.example');
        $this->asAdmin();

        $data = $this->bulk('delete', [['id' => 'a.example'], ['id' => 'full.example'], ['id' => 'b.example'], ['id' => 'c.example']])->assertOk()->json('data');

        $this->assertSame(['done', 'refused', 'done', 'done'], array_column($data['results'], 'outcome'));
        $this->assertSame('/api/v2/problems/domain-not-empty', $data['results'][1]['problem']['type']);
        $this->assertSame(1, $data['results'][1]['problem']['mailboxes']);
        $this->assertArrayNotHasKey('version', $data['results'][0]);
        $this->assertSame(['full.example'], DB::table('domain')->pluck('domain')->all());
        $this->assertCount(3, array_filter($this->dockerApi()->calls(), fn (array $c): bool => $c['method'] === 'exec'));
        $this->assertSame(1, $this->memcached()->flushCount());
        $failures = array_values(array_filter($this->auditOf('domainDeletion'), fn (object $r): bool => $r->outcome === 'failure'));
        $this->assertCount(1, $failures);
        $this->assertSame('conflict', $failures[0]->failure_reason);
        $this->assertSame($data['bulkRequestId'], $failures[0]->details['bulkRequestId']);
    }

    public function test_unknown_and_invalid_names_are_not_found_and_the_others_proceed(): void
    {
        $this->mailcow()->domain('a.example', 0);
        $this->mailcow()->domain('b.example', 0);
        $this->asAdmin();

        $data = $this->bulk('activate', [['id' => 'a.example'], ['id' => 'missing.example'], ['id' => 'exa_mple.org'], ['id' => 'b.example']])->assertOk()->json('data');

        $this->assertSame(['done', 'notFound', 'notFound', 'done'], array_column($data['results'], 'outcome'));
        $this->assertSame(2, DB::table('domain')->where('active', 1)->count());
    }

    public function test_the_whole_request_is_refused_for_other_roles_and_read_only_keys(): void
    {
        $this->mailcow()->domain('a.example');
        $this->asDomainAdmin(['a.example']);
        $this->bulk('deactivate', [['id' => 'a.example']])->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->forgetCookies();
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $key = $this->apiKey('admin', 'readOnly');
        $this->postJson('/api/v2/domains/bulk-actions', ['action' => 'deactivate', 'targets' => [['id' => 'a.example']]], $this->keyHeader($key))
            ->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $this->assertSame(1, (int) $this->domainRow('a.example')?->active);
        $refusals = $this->auditOf('domainUpdate');
        $this->assertCount(2, $refusals);
        $this->assertNull($refusals[0]->target_type);
        $this->assertSame(['a.example'], $refusals[0]->details['targetIds']);
        $this->assertSame('deactivate', $refusals[0]->details['bulkAction']);
    }

    public function test_more_than_100_targets_are_invalid(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();
        $targets = array_map(fn (int $i): array => ['id' => "d{$i}.example"], range(1, 101));

        $this->bulk('delete', $targets)->assertStatus(422)
            ->assertJsonPath('errors.targets.0.code', 'tooMany')
            ->assertJsonPath('errors.targets.0.params', ['max' => 100]);
    }

    public function test_an_outdated_item_is_left_unchanged_and_the_others_proceed(): void
    {
        $this->mailcow()->domain('a.example');
        $this->mailcow()->domain('b.example');
        $this->asAdmin();
        $a = (string) $this->getJson('/api/v2/domains/a.example')->headers->get('ETag');
        $b = (string) $this->getJson('/api/v2/domains/b.example')->headers->get('ETag');
        DB::table('domain')->where('domain', 'b.example')->update(['mailboxes' => 99]);

        // Versions may be sent with or without the quotes of the ETag header (G33).
        $data = $this->bulk('deactivate', [['id' => 'a.example', 'version' => trim($a, '"')], ['id' => 'b.example', 'version' => $b]])->assertOk()->json('data');

        $this->assertSame(['done', 'outdated'], array_column($data['results'], 'outcome'));
        $this->assertSame(412, $data['results'][1]['problem']['status']);
        $this->assertSame(1, (int) $this->domainRow('b.example')?->active);
        $this->assertSame(0, (int) $this->domainRow('a.example')?->active);
    }

    public function test_a_name_given_twice_is_processed_once_in_request_order(): void
    {
        $this->mailcow()->domain('xn--mnchen-3ya.de', 0);
        $this->mailcow()->domain('a.example');
        $this->asAdmin();

        $data = $this->bulk('activate', [['id' => 'münchen.de'], ['id' => 'a.example'], ['id' => 'xn--mnchen-3ya.de'], ['id' => 'MÜNCHEN.de']])->assertOk()->json('data');

        $this->assertSame(['münchen.de', 'a.example'], array_column($data['results'], 'id'));
        $this->assertSame(['done', 'done'], array_column($data['results'], 'outcome'));
        $this->assertSame(2, $data['summary']['total']);
        // Activating an active domain changes nothing, but is audited.
        $records = $this->auditOf('domainUpdate');
        $this->assertCount(2, $records);
        $this->assertSame([], $records[1]->details['changes']);
        $this->assertSame(['old' => false, 'new' => true], $records[0]->details['changes']['active']);
    }

    public function test_one_deletion_with_the_shared_store_down_fails_and_the_others_proceed(): void
    {
        $this->mailcow()->domain('a.example');
        $this->mailcow()->domain('down.example');
        $this->mailcow()->domain('c.example');
        $this->app->instance(SharedStore::class, new class($this->app->make(RedisFactory::class)) extends SharedStore
        {
            public bool $down = false;

            public function deleteFields(array $keyFields): \Closure
            {
                if (in_array('down.example', $keyFields['DOMAIN_MAP'] ?? [], true)) {
                    throw new SharedStoreUnavailable('connection lost');
                }

                return parent::deleteFields($keyFields);
            }
        });
        $this->asAdmin();

        $data = $this->bulk('delete', [['id' => 'a.example'], ['id' => 'down.example'], ['id' => 'c.example']])->assertOk()->json('data');

        $this->assertSame(['done', 'failed', 'done'], array_column($data['results'], 'outcome'));
        $this->assertSame('/api/v2/problems/temporary-failure', $data['results'][1]['problem']['type']);
        $this->assertSame(['down.example'], DB::table('domain')->pluck('domain')->all());
        $failure = array_values(array_filter($this->auditOf('domainDeletion'), fn (object $r): bool => $r->outcome === 'failure'))[0];
        $this->assertSame('temporaryFailure', $failure->failure_reason);
    }
}
