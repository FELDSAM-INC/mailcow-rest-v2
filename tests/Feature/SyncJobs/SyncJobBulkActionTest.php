<?php

namespace Tests\Feature\SyncJobs;

use App\Auth\Principal;
use App\Auth\Role;
use App\Services\SyncJobs\SyncJobService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\SyncJobs\SyncJobTestHelpers;
use Tests\TestCase;

/**
 * Feature 008 T057 (FR-023, FR-024, US5 AS-1 to AS-7, SC-005): activate, deactivate, reschedule or
 * delete up to the bulk limit of jobs in one request, each processed, version-checked and audited
 * on its own, with one result per distinct job; only the permission flag, the key scope and the
 * body refuse the whole request.
 */
class SyncJobBulkActionTest extends TestCase
{
    use ApiKeys;
    use SyncJobTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->syncMailbox('alice@example.org');
        $this->syncMailbox('carol@example.net');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return list<int>
     */
    private function jobs(int $count, string $mailbox = 'alice@example.org', array $overrides = []): array
    {
        static $n = 0;
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->mailcow()->syncJob($mailbox, ['user1' => 'remote'.(++$n)] + $overrides);
        }

        return $ids;
    }

    public function test_an_administrator_deactivates_fifty_jobs_in_one_request(): void
    {
        $ids = $this->jobs(50);
        $this->asAdmin();

        $response = $this->syncJobBulk('deactivate', $ids)->assertOk();

        $this->assertSame(array_map('strval', $ids), array_column((array) $response->json('data.results'), 'id'));
        $this->assertSame(['done'], array_values(array_unique(array_column((array) $response->json('data.results'), 'outcome'))));
        $this->assertSame(['total' => 50, 'done' => 50, 'notFound' => 0, 'refused' => 0, 'outdated' => 0, 'failed' => 0], $response->json('data.summary'));
        $this->assertSame(0, DB::table('imapsync')->whereIn('id', $ids)->where('active', 1)->count());
        $records = $this->auditOf('syncJobUpdate');
        $this->assertCount(50, $records);
        $this->assertSame([$response->json('data.bulkRequestId')], array_values(array_unique(array_map(fn (object $r): string => $r->details['bulkRequestId'], $records))));
        $this->assertSame($response->json('data.bulkRequestId'), $records[0]->request_id);
    }

    public function test_a_domain_administrator_s_foreign_jobs_are_not_found_and_stay_unchanged(): void
    {
        $own = $this->jobs(3, overrides: ['success' => 1, 'last_run' => '2026-10-18 08:00:00']);
        $foreign = $this->jobs(2, 'carol@example.net', ['success' => 1, 'last_run' => '2026-10-18 08:00:00']);
        $before = DB::table('imapsync')->whereIn('id', $foreign)->get()->toArray();
        $this->asDomainAdmin(['example.org']);

        $response = $this->syncJobBulk('reschedule', [...$own, ...$foreign])->assertOk();

        $this->assertSame(['done', 'done', 'done', 'notFound', 'notFound'], array_column((array) $response->json('data.results'), 'outcome'));
        $this->assertSame(0, DB::table('imapsync')->whereIn('id', $own)->whereNotNull('success')->count());
        $this->assertEquals($before, DB::table('imapsync')->whereIn('id', $foreign)->get()->toArray());
    }

    public function test_the_whole_request_is_refused_without_the_flag_or_with_a_read_only_key(): void
    {
        $ids = $this->jobs(2);
        $this->mailcow()->domainAdmin('noflag', ['example.org'], ['syncjobs' => 0]);
        $this->signInAs('noflag', Role::DomainAdmin);
        $this->syncJobBulk('delete', $ids)->assertForbidden()->assertJsonPath('type', '/api/v2/problems/permission-flag-required');
        $this->forgetCookies();

        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
        $this->syncJobBulk('delete', $ids, $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $this->assertSame(2, $this->syncJobCount());
        $refusals = $this->auditOf('syncJobDeletion');
        $this->assertCount(2, $refusals);
        $this->assertSame(array_map('strval', $ids), $refusals[0]->details['targetIds']);
    }

    public function test_an_invalid_selection_over_the_limit_is_refused(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();
        $targets = array_map(fn (int $i): array => ['id' => (string) $i], range(1, 101));

        $this->syncJobBulk('activate', $targets)->assertUnprocessable()
            ->assertJsonPath('errors.targets.0.code', 'tooMany')
            ->assertJsonPath('errors.targets.0.params.max', 100);

        config(['restv2.sync_jobs.bulk_limit' => 50]);
        $this->syncJobBulk('activate', array_slice($targets, 0, 51))->assertUnprocessable()->assertJsonPath('errors.targets.0.params.max', 50);
        $this->syncJobBulk('activate', array_slice($targets, 0, 50))->assertOk();
    }

    public function test_a_job_deleted_just_before_is_not_found_and_the_others_are_deleted(): void
    {
        $ids = $this->jobs(5);
        DB::table('imapsync')->where('id', $ids[2])->delete();
        $this->asAdmin();

        $response = $this->syncJobBulk('delete', $ids)->assertOk();

        $this->assertSame(['done', 'done', 'notFound', 'done', 'done'], array_column((array) $response->json('data.results'), 'outcome'));
        $this->assertSame(5, $response->json('data.summary.total'));
        $this->assertSame(0, $this->syncJobCount());
        $this->assertArrayNotHasKey('version', (array) $response->json('data.results.0'));
    }

    public function test_a_mailbox_user_activates_their_own_jobs(): void
    {
        $ids = $this->jobs(2, overrides: ['active' => 0]);
        $this->asSyncUser('alice@example.org');

        $response = $this->syncJobBulk('activate', $ids)->assertOk();

        $this->assertSame(['done', 'done'], array_column((array) $response->json('data.results'), 'outcome'));
        $this->assertSame(2, DB::table('imapsync')->whereIn('id', $ids)->where('active', 1)->count());
    }

    public function test_an_item_with_an_outdated_tag_is_outdated_and_the_others_proceed(): void
    {
        $ids = $this->jobs(3);
        $this->asAdmin();
        $tags = array_column((array) $this->getJson('/api/v2/sync-jobs')->json('data'), 'etag', 'id');
        DB::table('imapsync')->where('id', $ids[1])->update(['mins_interval' => 99]);

        $response = $this->syncJobBulk('deactivate', array_map(fn (int $id): array => ['id' => (string) $id, 'version' => $tags[$id]], $ids))->assertOk();

        $this->assertSame(['done', 'outdated', 'done'], array_column((array) $response->json('data.results'), 'outcome'));
        $this->assertSame(412, $response->json('data.results.1.problem.status'));
        $this->assertSame(1, (int) $this->syncJobRow($ids[1])?->active);
        $this->assertSame(0, (int) $this->syncJobRow($ids[0])?->active);

        DB::table('imapsync')->whereIn('id', $ids)->update(['active' => 1]);
        $this->syncJobBulk('deactivate', $ids)->assertOk()->assertJsonPath('data.summary.done', 3);
    }

    public function test_versions_with_or_without_quotes_reschedule_ignores_them_and_done_carries_the_new_tag(): void
    {
        $ids = $this->jobs(3);
        $this->asAdmin();
        $tags = array_column((array) $this->getJson('/api/v2/sync-jobs')->json('data'), 'etag', 'id');

        $response = $this->syncJobBulk('deactivate', [
            ['id' => (string) $ids[0], 'version' => $tags[$ids[0]]],
            ['id' => (string) $ids[1], 'version' => trim($tags[$ids[1]], '"')],
        ])->assertOk();
        $this->assertSame(['done', 'done'], array_column((array) $response->json('data.results'), 'outcome'));
        $this->assertSame($this->getJson('/api/v2/sync-jobs/'.$ids[0])->headers->get('ETag'), $response->json('data.results.0.version'));

        $this->syncJobBulk('reschedule', [['id' => (string) $ids[2], 'version' => '"stale"']])->assertOk()->assertJsonPath('data.results.0.outcome', 'done');
    }

    public function test_a_job_named_twice_is_processed_once(): void
    {
        $ids = $this->jobs(1);
        $this->asAdmin();

        $response = $this->syncJobBulk('deactivate', [$ids[0], $ids[0]])->assertOk();

        $this->assertCount(1, (array) $response->json('data.results'));
        $this->assertCount(1, $this->auditOf('syncJobUpdate'));
    }

    public function test_an_invalid_target_or_action_refuses_the_whole_request(): void
    {
        $this->withoutRequestValidation();
        $ids = $this->jobs(1);
        $this->asAdmin();

        $errors = (array) $this->syncJobBulk('activate', [['id' => 'abc'], ['id' => (string) $ids[0]], ['id' => '0']])->assertUnprocessable()->json('errors');
        $this->assertSame(['targets.0.id', 'targets.2.id'], array_keys($errors));
        $this->assertSame('invalidFormat', $errors['targets.0.id'][0]['code'] ?? null);
        $this->syncJobBulk('activate', [])->assertUnprocessable()->assertJsonPath('errors.targets.0.code', 'required');
        $this->postJson('/api/v2/sync-jobs/bulk-actions', ['action' => 'pause', 'targets' => [['id' => (string) $ids[0]]]], $this->csrf())->assertUnprocessable()
            ->assertJsonPath('errors.action.0.code', 'notInList');
        $this->assertSame(1, (int) $this->syncJobRow($ids[0])?->active);
    }

    public function test_one_failing_item_never_stops_the_others(): void
    {
        $ids = $this->jobs(3);
        $failing = $ids[1];
        $this->app->bind(SyncJobService::class, fn ($app) => new class($app->make(\App\Services\SyncJobs\SyncJobDuplicateGuard::class), $app->make(\App\Services\Audit\AuditLogger::class), $app->make(\App\Services\SyncJobs\SyncJobScope::class), $failing) extends SyncJobService
        {
            public function __construct(\App\Services\SyncJobs\SyncJobDuplicateGuard $guard, \App\Services\Audit\AuditLogger $audit, \App\Services\SyncJobs\SyncJobScope $scope, private readonly int $failing)
            {
                parent::__construct($guard, $audit, $scope);
            }

            public function update(Principal $principal, int $id, array $patch, ?string $ifMatch = null, ?string $bulkRequestId = null): \App\Models\Mailcow\SyncJob
            {
                if ($id === $this->failing) {
                    throw new RuntimeException('the database went away');
                }

                return parent::update($principal, $id, $patch, $ifMatch, $bulkRequestId);
            }
        });
        $this->asAdmin();

        $response = $this->syncJobBulk('deactivate', $ids)->assertOk();

        $this->assertSame(['done', 'failed', 'done'], array_column((array) $response->json('data.results'), 'outcome'));
        $this->assertSame('about:blank', $response->json('data.results.1.problem.type'));
        $this->assertSame(500, $response->json('data.results.1.problem.status'));
        $this->assertSame(1, (int) $this->syncJobRow($failing)?->active);
    }
}
