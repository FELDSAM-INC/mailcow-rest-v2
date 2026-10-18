<?php

namespace Tests\Feature\Aliases;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\TestCase;

/**
 * Feature 004 T066 (FR-058, FR-059, US6): applyAliasBulkAction.
 */
class AliasBulkActionTest extends TestCase
{
    use AliasTestHelpers;
    use ApiKeys;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('foreign.example');
        $f->aliasDomain('example.net', 'example.org');
        $this->mailbox('anna@example.org');
    }

    /**
     * @param  list<array<string, string>>  $targets
     * @return TestResponse<Response>
     */
    private function bulk(string $action, array $targets): TestResponse
    {
        return $this->postJson('/api/v2/aliases/bulk-actions', ['action' => $action, 'targets' => $targets], $this->csrf());
    }

    public function test_thirty_aliases_are_deactivated_with_one_record_each_and_one_cache_flush(): void
    {
        $targets = [];
        for ($i = 1; $i <= 30; $i++) {
            $targets[] = ['id' => (string) $this->mailcow()->alias("bulk{$i}@example.org", 'anna@example.org')];
        }
        $this->asAdmin();

        $data = $this->bulk('deactivate', $targets)->assertOk()->json('data');

        $this->assertSame(['total' => 30, 'done' => 30, 'notFound' => 0, 'refused' => 0, 'outdated' => 0, 'failed' => 0], $data['summary']);
        $this->assertSame(0, DB::table('alias')->where('address', 'like', 'bulk%')->where('active', 1)->count());
        $records = $this->auditOf('aliasUpdate');
        $this->assertCount(30, $records);
        $this->assertSame([$data['bulkRequestId']], array_values(array_unique(array_map(fn (object $r): string => $r->details['bulkRequestId'], $records))));
        $this->assertSame($this->getJson('/api/v2/aliases/'.$targets[0]['id'])->headers->get('ETag'), $data['results'][0]['version']);
        $this->assertSame(1, $this->memcached()->flushCount());
    }

    public function test_a_domain_administrator_gets_not_found_for_foreign_aliases(): void
    {
        $f = $this->mailcow();
        $own = [$f->alias('a@example.org', 'anna@example.org'), $f->alias('b@example.org', 'anna@example.org'), $f->alias('c@example.net', 'anna@example.org')];
        $foreign = [$f->alias('x@foreign.example', 'anna@example.org'), $f->alias('y@foreign.example', 'anna@example.org')];
        $this->asDomainAdmin(['example.org']);

        $data = $this->bulk('hideInGroupware', array_map(fn (int $id): array => ['id' => (string) $id], [...$own, ...$foreign]))->assertOk()->json('data');

        $this->assertSame(['done', 'done', 'done', 'notFound', 'notFound'], array_column($data['results'], 'outcome'));
        $this->assertSame(3, DB::table('alias')->where('sogo_visible', 0)->count());
    }

    public function test_more_than_100_targets_are_invalid(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->bulk('delete', array_map(fn (int $i): array => ['id' => (string) $i], range(1, 101)))->assertStatus(422)
            ->assertJsonPath('errors.targets.0.code', 'tooMany')
            ->assertJsonPath('errors.targets.0.params', ['max' => 100]);
    }

    public function test_outdated_items_stay_unchanged_and_duplicates_are_processed_once(): void
    {
        $f = $this->mailcow();
        $ids = [$f->alias('a@example.org', 'anna@example.org'), $f->alias('b@example.org', 'anna@example.org'), $f->alias('c@example.org', 'anna@example.org')];
        $this->asAdmin();
        $versions = array_map(fn (int $id): string => (string) $this->getJson("/api/v2/aliases/{$id}")->headers->get('ETag'), $ids);
        DB::table('alias')->where('id', $ids[1])->update(['public_comment' => 'changed in the legacy UI']);

        $data = $this->bulk('deactivate', [
            ['id' => (string) $ids[0], 'version' => trim($versions[0], '"')],
            ['id' => (string) $ids[1], 'version' => $versions[1]],
            ['id' => (string) $ids[2], 'version' => $versions[2]],
            ['id' => '0'.$ids[2]],
        ])->assertOk()->json('data');

        $this->assertSame(['done', 'outdated', 'done'], array_column($data['results'], 'outcome'));
        $this->assertSame(1, (int) $this->aliasRow($ids[1])?->active);
        $this->assertNotSame($versions[0], $data['results'][0]['version']);
    }

    public function test_delete_show_in_groupware_and_copy(): void
    {
        $f = $this->mailcow();
        $sales = $f->alias('sales@example.org', 'anna@example.org');
        $f->senderAcl('bob@example.org', 'sales@example.org');
        $hidden = $f->alias('hidden@example.org', 'anna@example.org', sogoVisible: 0);
        $inAliasDomain = $f->alias('x@example.net', 'anna@example.org');
        config(['restv2.skip_sogo' => true]);
        $this->asAdmin();

        $this->bulk('showInGroupware', [['id' => (string) $hidden]])->assertOk()->assertJsonPath('data.results.0.outcome', 'done');
        $this->assertSame(1, (int) $this->aliasRow($hidden)?->sogo_visible);

        $response = $this->bulk('copyOverAliasDomains', [['id' => (string) $sales], ['id' => (string) $inAliasDomain]])->assertOk();
        $this->assertSame(['done', 'refused'], array_column((array) $response->json('data.results'), 'outcome'));
        $this->assertSame('/api/v2/problems/not-primary-domain-alias', $response->json('data.results.1.problem.type'));
        $this->assertSame([(string) $sales], array_map('strval', array_keys((array) $response->json('meta.copies'))));
        $this->assertSame('created', $response->json("meta.copies.{$sales}.0.outcome"));
        $this->assertSame($response->json('data.bulkRequestId'), collect($this->auditOf('aliasCreation'))->firstWhere('outcome', 'success')->details['bulkRequestId']);

        $this->bulk('delete', [['id' => (string) $sales]])->assertOk()->assertJsonPath('data.results.0.outcome', 'done');
        $this->assertNull($this->aliasRow($sales));
        $this->assertSame([], $this->senderAcls());
    }

    public function test_the_whole_request_is_refused_for_mailbox_users_and_read_only_keys(): void
    {
        $id = $this->mailcow()->alias('a@example.org', 'anna@example.org');
        $this->asMailboxUser('anna@example.org');
        $this->bulk('deactivate', [['id' => (string) $id]])->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->forgetCookies();
        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
        $this->postJson('/api/v2/aliases/bulk-actions', ['action' => 'delete', 'targets' => [['id' => (string) $id]]], $this->keyHeader($this->apiKey('admin', 'readOnly')))
            ->assertForbidden()->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $this->assertSame(1, (int) $this->aliasRow($id)?->active);
        $update = $this->auditOf('aliasUpdate')[0];
        $this->assertNull($update->target_type);
        $this->assertSame([(string) $id], $update->details['targetIds']);
        $this->assertSame('deactivate', $update->details['bulkAction']);
        $this->assertCount(1, $this->auditOf('aliasDeletion'));
    }
}
