<?php

namespace Tests\Feature\Aliases;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Support\Bulk\BulkActionRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

class BulkOwnershipChangeTest extends TestCase
{
    use RoutingTestHelpers;

    /** @return iterable<string, array{string, string, string}> */
    public static function cases(): iterable
    {
        foreach (['aliases', 'alias-domains', 'bcc-maps'] as $resource) {
            foreach (['deactivate', 'delete'] as $action) {
                foreach (['transfer', 'revoke'] as $change) {
                    yield "$resource $action $change" => [$resource, $action, $change];
                }
            }
        }

        yield 'alias moved directly' => ['aliases', 'delete', 'move'];
        yield 'copy source moved directly' => ['aliases', 'copyOverAliasDomains', 'move'];
    }

    #[DataProvider('cases')]
    public function test_later_bulk_items_recheck_current_locked_ownership(string $resource, string $action, string $change): void
    {
        $f = $this->mailcow();
        foreach (['safe.example', 'example.org', 'foreign.example'] as $domain) {
            $f->domain($domain);
        }
        $f->aliasDomain('safe.net', 'safe.example');
        $f->aliasDomain('moving.net', 'example.org');
        $firstAlias = $f->alias('first@safe.example', 'target@external.example');
        $secondAlias = $f->alias('second@moving.net', 'target@external.example');
        $firstMap = $f->bccMap('safe.example', 'copy@external.example', 'safe.example', 'sender');
        $secondMap = $f->bccMap('moving.net', 'copy@external.example', 'moving.net', 'sender');
        $this->asRoutingDomainAdmin(['safe.example', 'example.org'], ['bcc_maps' => 1]);
        [$ids, $table, $column] = match ($resource) {
            'aliases' => [[$firstAlias, $secondAlias], 'alias', 'id'],
            'alias-domains' => [['safe.net', 'moving.net'], 'alias_domain', 'alias_domain'],
            default => [[$firstMap, $secondMap], 'bcc_maps', 'id'],
        };

        // Interleave an ownership change after all targets were resolved, before their
        // individual writes. Keep the first item owned to verify that only the moved item fails.
        $real = $this->app->make(BulkActionRunner::class);
        $runner = Mockery::mock(BulkActionRunner::class);
        $runner->shouldReceive('run')->once()->andReturnUsing(function (Request $request, Principal $actor, AuditEventType $event, array $targets, callable $resolve, callable $apply, callable $auditTarget) use ($real, $change, $secondAlias): array {
            $changed = false;

            return $real->run($request, $actor, $event, $targets, $resolve, function (string $key, ?string $version) use ($apply, $change, $secondAlias, &$changed): mixed {
                if (! $changed) {
                    $changed = true;
                    match ($change) {
                        'revoke' => DB::table('domain_admins')->where('username', 'reseller')->where('domain', 'example.org')->update(['active' => 0]),
                        'move' => DB::table('alias')->where('id', $secondAlias)->update(['address' => 'second@foreign.example', 'domain' => 'foreign.example']),
                        default => DB::table('alias_domain')->where('alias_domain', 'moving.net')->update(['target_domain' => 'foreign.example']),
                    };
                }

                return $apply($key, $version);
            }, $auditTarget);
        });
        $this->app->instance(BulkActionRunner::class, $runner);

        $this->send('POST', '/api/v2/'.$resource.'/bulk-actions', [
            'action' => $action,
            'targets' => array_map(fn (int|string $id): array => ['id' => (string) $id], $ids),
        ])->assertOk()->assertJsonPath('data.results.0.outcome', 'done')
            ->assertJsonPath('data.results.1.outcome', 'notFound');

        $this->assertSame(1, (int) DB::table($table)->where($column, $ids[1])->value('active'));
    }
}
