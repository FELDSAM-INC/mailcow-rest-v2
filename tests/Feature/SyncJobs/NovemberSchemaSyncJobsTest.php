<?php

namespace Tests\Feature\SyncJobs;

use App\Models\Mailcow\SchemaVersion;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyncJobs\SyncJobTestHelpers;
use Tests\TestCase;

class NovemberSchemaSyncJobsTest extends TestCase
{
    use SyncJobTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        if (! SchemaVersion::hasNovember2026Features()) {
            $this->markTestSkipped('Requires the upstream 2026-11 schema.');
        }
        $this->asSyncUser();
    }

    public function test_existing_api_creates_a_private_source_and_runner_json_and_lists_the_job(): void
    {
        $job = $this->createSyncJob(self::syncJobBody(['customParameters' => '--maxsize=1000 --nolog']))
            ->assertCreated()->json('data');
        $stored = DB::table('imapsync')->where('id', $job['id'])->first();
        $source = DB::table('imapsync_source')->where('id', $stored->source_id)->first();
        $this->assertSame('imap.old-provider.example', $source->host1);
        $this->assertSame('user', $source->scope);
        $this->assertSame(1, (int) $source->verify_cert);
        $this->assertSame('alice@example.org', $source->created_by);
        $this->assertSame(['alice@example.org'], DB::table('imapsync_source_user')->where('source_id', $source->id)->pluck('username')->all());
        $this->assertSame([['o' => 'maxsize', 'v' => '1000'], ['o' => 'nolog', 'v' => '']], json_decode($stored->custom_params, true));
        $this->getJson('/api/v2/sync-jobs?sort=remoteHost&q=old-provider')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.customParameters', '--maxsize=1000 --nolog');
    }

    public function test_endpoint_edit_preserves_the_shared_source_and_requires_the_password_again(): void
    {
        $first = $this->createSyncJob(self::syncJobBody())->assertCreated()->json('data');
        $second = $this->createSyncJob(self::syncJobBody(['remoteUser' => 'second']))->assertCreated()->json('data');
        $source = DB::table('imapsync')->where('id', $first['id'])->value('source_id');
        DB::table('imapsync')->where('id', $second['id'])->update(['source_id' => $source]);
        $this->patchSyncJob($first['id'], ['remoteHost' => 'other.example.net'])
            ->assertUnprocessable();
        $this->patchSyncJob($first['id'], ['remoteHost' => 'other.example.net', 'remotePassword' => 'replacement'])
            ->assertOk()->assertJsonPath('data.remoteHost', 'other.example.net');
        $this->assertSame('imap.old-provider.example', DB::table('imapsync_source')->where('id', $source)->value('host1'));
        $this->assertNotSame((int) $source, (int) DB::table('imapsync')->where('id', $first['id'])->value('source_id'));
        $this->getJson('/api/v2/sync-jobs/'.$second['id'])->assertOk()->assertJsonPath('data.remoteHost', 'imap.old-provider.example');
    }

    public function test_a_legacy_source_edit_invalidates_the_job_version_and_unsafe_stored_options_cannot_be_enabled(): void
    {
        $job = $this->createSyncJob(self::syncJobBody())->assertCreated()->json('data');
        $source = DB::table('imapsync')->where('id', $job['id'])->value('source_id');
        DB::table('imapsync_source')->where('id', $source)->update(['verify_cert' => 0]);
        $this->patchSyncJob($job['id'], ['active' => false], ['If-Match' => $job['etag']])->assertStatus(412);
        DB::table('imapsync')->where('id', $job['id'])->update(['active' => 0, 'custom_params' => '[{"o":"skipmess","v":"x"}]']);
        $this->patchSyncJob($job['id'], ['active' => true])->assertUnprocessable();
        $this->assertSame(0, (int) DB::table('imapsync')->where('id', $job['id'])->value('active'));
    }

    public function test_existing_password_sources_are_selected_and_a_source_switch_requires_password_reentry(): void
    {
        $source = $this->postJson('/api/v2/sync-sources', ['name' => 'Shared connection', 'remoteHost' => 'imap.source.example', 'remotePort' => 993, 'authMechanism' => 'login'], $this->csrf())->assertCreated()->json('data');
        $job = $this->createSyncJob(['sourceId' => $source['id'], 'remoteUser' => 'remote', 'remotePassword' => 'test'])->assertCreated()
            ->assertJsonPath('data.sourceAuthMechanism', 'login')->assertJsonPath('data.sourceId', $source['id'])->json('data');
        $other = $this->postJson('/api/v2/sync-sources', ['name' => 'Other', 'remoteHost' => 'imap.other.example', 'remotePort' => 993], $this->csrf())->assertCreated()->json('data');
        $this->patchSyncJob($job['id'], ['sourceId' => $other['id']])->assertUnprocessable();
        $this->patchSyncJob($job['id'], ['sourceId' => $other['id'], 'remotePassword' => 'replacement'])->assertOk()->assertJsonPath('data.sourceId', $other['id']);
        $this->assertSame(2, DB::table('imapsync_source')->where('created_by', 'alice@example.org')->count());
    }

    public function test_oauth_tokens_belong_to_the_target_mailbox_and_old_password_is_cleared_on_switch(): void
    {
        $source = DB::table('imapsync_source')->insertGetId(['name' => 'OAuth', 'created_by' => 'admin', 'scope' => 'all', 'host1' => 'imap.oauth.example', 'port1' => 993, 'auth_type' => 'XOAUTH2', 'oauth_flow' => 'authorization_code']);
        $job = $this->createSyncJob(self::syncJobBody())->assertCreated()->json('data');
        $this->patchSyncJob($job['id'], ['sourceId' => $source, 'remoteUser' => 'remote@example.net'])->assertUnprocessable();
        DB::table('imapsync_source_oauth_token')->insert(['source_id' => $source, 'owner' => 'other@example.org', 'username' => 'remote@example.net', 'access_token' => 'SECRET']);
        $this->patchSyncJob($job['id'], ['sourceId' => $source, 'remoteUser' => 'remote@example.net'])->assertUnprocessable();
        DB::table('imapsync_source_oauth_token')->insert(['source_id' => $source, 'owner' => 'alice@example.org', 'username' => 'remote@example.net', 'access_token' => 'SECRET']);
        $this->patchSyncJob($job['id'], ['sourceId' => $source, 'remoteUser' => 'remote@example.net'])->assertOk()->assertJsonPath('data.remotePasswordSet', false)->assertJsonPath('data.sourceAuthMechanism', 'xoauth2');
        $this->assertDatabaseHas('imapsync', ['id' => $job['id'], 'password1' => '', 'source_id' => $source]);
        $this->deleteSyncJob($job['id'])->assertNoContent();
        $this->assertDatabaseMissing('imapsync_source_oauth_token', ['source_id' => $source, 'owner' => 'alice@example.org']);
        $this->assertDatabaseHas('imapsync_source_oauth_token', ['source_id' => $source, 'owner' => 'other@example.org']);
    }

    public function test_only_admins_manage_global_limits_and_priority_and_running_jobs_are_preserved(): void
    {
        $job = $this->createSyncJob(self::syncJobBody())->assertCreated()->json('data');
        $this->getJson('/api/v2/sync-queue/settings')->assertForbidden();
        $this->patchSyncJob($job['id'], ['priority' => 10])->assertForbidden();
        $this->syncJobBulk('prioritize', [$job['id']])->assertForbidden();
        $this->asAdmin();
        $settings = $this->getJson('/api/v2/sync-queue/settings')->assertOk()->json('data');
        $this->patchMerge('/api/v2/sync-queue/settings', ['maxParallel' => 4, 'maxBytesPerSecond' => 1048576], ['If-Match' => $settings['etag']] + $this->csrf())
            ->assertOk()->assertJsonPath('data.maxParallel', 4);
        $this->patchMerge('/api/v2/sync-queue/settings', ['maxParallel' => 5], ['If-Match' => $settings['etag']] + $this->csrf())->assertStatus(412);
        $this->syncJobBulk('prioritize', [$job['id']])->assertOk()->assertJsonPath('data.summary.done', 1);
        $first = DB::table('imapsync')->where('id', $job['id'])->value('prio');
        $this->assertGreaterThan(0, $first);
        DB::table('imapsync')->where('id', $job['id'])->update(['is_running' => 1]);
        $this->syncJobBulk('prioritize', [$job['id']])->assertOk()->assertJsonPath('data.results.0.problem.status', 409);
        $this->assertSame($first, DB::table('imapsync')->where('id', $job['id'])->value('prio'));
        $this->getJson('/api/v2/sync-jobs?sort=priority&order=desc')->assertOk();
    }
}
