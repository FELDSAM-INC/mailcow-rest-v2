<?php

namespace Tests\Feature\SyncJobs;

use App\Auth\Role;
use Tests\Support\SyncJobs\SyncJobTestHelpers;
use Tests\TestCase;

/**
 * Feature 008 T050 (FR-006, spec edge case): with custom parameters restricted to administrators, a
 * mailbox user may still change a job whose parameters an administrator set, as long as the
 * parameters are omitted or sent unchanged.
 */
class UpdateSyncJobCustomParamsRestrictionTest extends TestCase
{
    use SyncJobTestHelpers;

    public function test_unsafe_stored_parameters_cannot_be_activated_or_rescheduled_but_can_be_removed(): void
    {
        $this->syncMailbox('alice@example.org');
        $job = $this->mailcow()->syncJob('alice@example.org', [
            'active' => 0,
            'custom_params' => '--folder=INBOX +debug',
            'last_run' => '2026-10-18 08:00:00',
            'success' => 1,
        ]);
        $this->asSyncUser();

        $this->patchSyncJob($job, ['active' => true])->assertUnprocessable()
            ->assertJsonPath('errors.customParameters.0.code', 'optionNotAllowed');
        $this->syncJobBulk('activate', [$job])->assertOk()
            ->assertJsonPath('data.results.0.outcome', 'refused')
            ->assertJsonPath('data.results.0.problem.status', 422);
        $this->rescheduleSyncJob($job)->assertUnprocessable()
            ->assertJsonPath('errors.customParameters.0.code', 'optionNotAllowed');
        $this->syncJobBulk('reschedule', [$job])->assertOk()
            ->assertJsonPath('data.results.0.outcome', 'refused');
        $this->assertSame(0, (int) $this->syncJobRow($job)?->active);
        $this->assertSame('2026-10-18 08:00:00', $this->syncJobRow($job)?->last_run);
        $this->assertSame(1, (int) $this->syncJobRow($job)?->success);

        $this->patchSyncJob($job, ['customParameters' => '', 'active' => true])->assertOk();
        $this->rescheduleSyncJob($job)->assertOk();
        $this->assertSame('', $this->syncJobRow($job)?->custom_params);
        $this->assertSame(1, (int) $this->syncJobRow($job)?->active);
        $this->assertNull($this->syncJobRow($job)?->last_run);
    }

    public function test_unsafe_jobs_can_be_deactivated_and_deleted_with_custom_parameters_restricted(): void
    {
        config(['restv2.sync_jobs.custom_params_admin_only' => true]);
        $this->syncMailbox('alice@example.org');
        $job = $this->mailcow()->syncJob('alice@example.org', ['custom_params' => '--showpasswords']);
        $this->asSyncUser();

        $this->patchSyncJob($job, ['intervalMinutes' => 30])->assertUnprocessable();
        $this->syncJobBulk('deactivate', [$job])->assertOk()
            ->assertJsonPath('data.results.0.outcome', 'done');
        $this->assertSame(0, (int) $this->syncJobRow($job)?->active);
        $this->deleteSyncJob($job)->assertNoContent();
        $this->assertNull($this->syncJobRow($job));
    }

    public function test_only_a_changed_value_of_another_role_is_refused(): void
    {
        config(['restv2.sync_jobs.custom_params_admin_only' => true]);
        $this->syncMailbox('alice@example.org');
        $job = $this->mailcow()->syncJob('alice@example.org', ['custom_params' => '--maxsize=1000']);
        $this->signInAs('alice@example.org', Role::MailboxUser);

        $this->patchSyncJob($job, ['intervalMinutes' => 30])->assertOk();
        $this->patchSyncJob($job, ['customParameters' => '--maxsize=1000', 'intervalMinutes' => 31])->assertOk();
        $this->patchSyncJob($job, ['customParameters' => '--maxsize=2000'])->assertUnprocessable()
            ->assertJsonPath('errors.customParameters.0.code', 'customParamsNotPermitted');
        $this->assertSame('--maxsize=1000', $this->syncJobRow($job)?->custom_params);

        $this->asAdmin();
        $this->patchSyncJob($job, ['customParameters' => '--maxsize=2000'])->assertOk();
        $this->assertSame('--maxsize=2000', $this->syncJobRow($job)?->custom_params);
    }
}
