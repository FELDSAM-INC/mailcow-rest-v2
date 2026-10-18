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
