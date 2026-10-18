<?php

namespace Tests\Unit\Support;

use App\Audit\AuditEventType;
use App\Auth\AccountKind;
use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Http\RequestContext;
use App\Services\Groupware\GroupwareCacheFlush;
use App\Support\Bulk\BulkActionRunner;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Tests\TestCase;

/**
 * Feature 004 T012 (research R16; the runner is feature 002's): targets are deduplicated after
 * resolution keeping the first spelling, each item's outcome follows its problem's status, one
 * failure does not stop the others, results keep request order, the summary counts match, and the
 * groupware cache flush of the items is deferred to one flush at the end.
 */
class BulkActionRunnerTest extends TestCase
{
    public function test_outcomes_order_deduplication_and_summary(): void
    {
        $request = Request::create('/api/v2/aliases/bulk-actions', 'POST');
        RequestContext::setRequestId($request, str_repeat('ab', 16));
        $this->app->instance('request', $request);
        $actor = new Principal('admin', Role::Admin, AccountKind::Admin, AuthMethod::Password);
        $applied = [];

        $result = $this->app->make(BulkActionRunner::class)->run(
            $request,
            $actor,
            AuditEventType::AliasUpdate,
            [['id' => '1'], ['id' => '01'], ['id' => '2', 'version' => 'abc'], ['id' => '3'], ['id' => '4'], ['id' => '5'], ['id' => 'x']],
            fn (string $id): ?string => ctype_digit($id) ? (string) (int) $id : null,
            function (string $key, ?string $ifMatch) use (&$applied): string {
                $applied[] = [$key, $ifMatch];

                return match ($key) {
                    '1' => '"v1"',
                    '2' => throw new PreconditionFailedHttpException('changed'),
                    '3' => throw new ProblemException(ProblemType::AddressInUse, null, ['address' => 'a@example.org', 'holder' => 'alias']),
                    '4' => throw new NotFoundHttpException('gone'),
                    default => throw new RuntimeException('boom'),
                };
            },
            fn (string $id, ?string $key): array => ['type' => 'alias', 'id' => $key ?? $id, 'name' => null],
        );

        $this->assertSame(str_repeat('ab', 16), $result['bulkRequestId']);
        $this->assertSame(['1', '2', '3', '4', '5', 'x'], array_column($result['results'], 'id'));
        $this->assertSame(['done', 'outdated', 'refused', 'notFound', 'failed', 'notFound'], array_column($result['results'], 'outcome'));
        $this->assertSame('"v1"', $result['results'][0]['version']);
        $this->assertSame([['1', null], ['2', '"abc"'], ['3', null], ['4', null], ['5', null]], $applied);
        $this->assertSame('/api/v2/problems/address-in-use', $result['results'][2]['problem']['type']);
        $this->assertArrayNotHasKey('problem', $result['results'][3]);
        $this->assertSame(['total' => 6, 'done' => 1, 'notFound' => 2, 'refused' => 1, 'outdated' => 1, 'failed' => 1], $result['summary']);

        $records = DB::table('restv2_audit_events')->where('event_type', 'aliasUpdate')->orderBy('id')->get();
        $this->assertCount(5, $records);
        foreach ($records as $record) {
            $this->assertSame(str_repeat('ab', 16), json_decode((string) $record->details, true)['bulkRequestId']);
        }
    }

    public function test_cache_flushes_requested_during_a_bulk_action_run_once_at_the_end(): void
    {
        $flush = $this->app->make(GroupwareCacheFlush::class);

        $flush->deferred(function () use ($flush): void {
            $flush->flush();
            $flush->flush();
            $flush->deferred(fn () => $flush->flush());
            $this->assertSame(0, $this->memcached()->flushCount());
        });
        $this->assertSame(1, $this->memcached()->flushCount());

        $flush->deferred(fn () => null);
        $this->assertSame(1, $this->memcached()->flushCount(), 'Nothing requested, nothing flushed.');

        $flush->flush();
        $this->assertSame(2, $this->memcached()->flushCount(), 'Outside a bulk action the flush is immediate.');
    }

    public function test_a_flush_is_still_made_when_the_bulk_action_throws(): void
    {
        $flush = $this->app->make(GroupwareCacheFlush::class);

        try {
            $flush->deferred(function () use ($flush): void {
                $flush->flush();

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(1, $this->memcached()->flushCount());
    }
}
