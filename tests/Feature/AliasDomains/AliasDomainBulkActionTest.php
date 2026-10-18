<?php

namespace Tests\Feature\AliasDomains;

use App\Clients\SharedStore\SharedStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\TestCase;

/**
 * Feature 004 T067 (FR-058, FR-059, US6): applyAliasDomainBulkAction.
 */
class AliasDomainBulkActionTest extends TestCase
{
    use AliasTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('foreign.example');
        foreach (['a.net', 'b.net', 'c.net'] as $name) {
            $f->aliasDomain($name, 'example.org');
            $f->alias("info@{$name}", 'anna@example.org');
            $f->redisHash('DOMAIN_MAP', $name, '1');
        }
        $f->aliasDomain('foreign.net', 'foreign.example');
        $this->mailbox('anna@example.org');
    }

    /**
     * @param  list<array<string, string>>  $targets
     * @return TestResponse<Response>
     */
    private function bulk(string $action, array $targets): TestResponse
    {
        return $this->postJson('/api/v2/alias-domains/bulk-actions', ['action' => $action, 'targets' => $targets], $this->csrf());
    }

    public function test_activation_and_deactivation_rebuild_the_groupware_once(): void
    {
        $this->asDomainAdmin(['example.org']);

        $data = $this->bulk('deactivate', [['id' => 'a.net'], ['id' => 'B.NET'], ['id' => 'foreign.net'], ['id' => 'unknown.net']])->assertOk()->json('data');

        $this->assertSame(['done', 'done', 'notFound', 'notFound'], array_column($data['results'], 'outcome'));
        $this->assertSame(2, DB::table('alias_domain')->where('active', 0)->count());
        $this->assertSame(1, $this->memcached()->flushCount());
        $this->assertSame($this->getJson('/api/v2/alias-domains/a.net')->headers->get('ETag'), $data['results'][0]['version']);
        $this->assertCount(2, array_filter($this->auditOf('aliasDomainUpdate'), fn (object $r): bool => $r->outcome === 'success' && $r->details['bulkRequestId'] === $data['bulkRequestId']));
    }

    public function test_deletion_cleans_up_each_target_and_versions_are_checked(): void
    {
        $this->asAdmin();
        $old = (string) $this->getJson('/api/v2/alias-domains/c.net')->headers->get('ETag');
        DB::table('alias_domain')->where('alias_domain', 'c.net')->update(['active' => 0]);

        $data = $this->bulk('delete', [['id' => 'a.net'], ['id' => 'b.net'], ['id' => 'c.net', 'version' => $old]])->assertOk()->json('data');

        $this->assertSame(['done', 'done', 'outdated'], array_column($data['results'], 'outcome'));
        $this->assertArrayNotHasKey('version', $data['results'][0]);
        $this->assertSame(['c.net', 'foreign.net'], DB::table('alias_domain')->orderBy('alias_domain')->pluck('alias_domain')->all());
        $this->assertSame(0, DB::table('alias')->whereIn('domain', ['a.net', 'b.net'])->count());
        $this->assertFalse($this->redis()->hGet('DOMAIN_MAP', 'a.net'));
        $this->assertSame(1, $this->memcached()->flushCount());
    }

    public function test_a_shared_store_failure_fails_only_that_target(): void
    {
        $real = $this->app->make(SharedStore::class);
        $this->app->instance(SharedStore::class, new class($real) extends SharedStore
        {
            private int $deletes = 0;

            public function __construct(private readonly SharedStore $inner)
            {
                parent::__construct(app('redis'));
            }

            public function deleteFields(array $keyFields): \Closure
            {
                if (++$this->deletes === 2) {
                    throw new SharedStoreUnavailable('connection lost');
                }

                return $this->inner->deleteFields($keyFields);
            }
        });
        $this->asAdmin();

        $data = $this->bulk('delete', [['id' => 'a.net'], ['id' => 'b.net'], ['id' => 'c.net']])->assertOk()->json('data');

        $this->assertSame(['done', 'failed', 'done'], array_column($data['results'], 'outcome'));
        $this->assertSame('/api/v2/problems/temporary-failure', $data['results'][1]['problem']['type']);
        $this->assertSame(['b.net', 'foreign.net'], DB::table('alias_domain')->orderBy('alias_domain')->pluck('alias_domain')->all());
        $this->assertSame(1, DB::table('alias')->where('domain', 'b.net')->count());
    }
}
