<?php

namespace Tests\Feature\MassMail;

use App\Auth\AccountKind;
use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Clients\Lmtp\LmtpResult;
use App\Services\MassMail\MassMailJobService;
use App\Services\MassMail\MassMailRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\MassMail\MassMailTestHelpers;
use Tests\TestCase;

/**
 * Feature 013 T069 (FR-078, AS-6.5, SC-008): any signed-in administrator cancels a running job; no
 * further message starts, the pending recipients are skipped, and the counts at that moment are
 * audited; cancelling twice changes nothing, cancelling a completed job is a conflict.
 */
class MassMailCancellationTest extends TestCase
{
    use MassMailTestHelpers;

    /**
     * @return TestResponse<Response>
     */
    private function cancel(string $job): TestResponse
    {
        return $this->send('POST', "/api/v2/mass-mail-jobs/{$job}/cancellation");
    }

    /**
     * @param  list<string>  $addresses
     */
    private function markDelivered(string $job, array $addresses): void
    {
        DB::table('restv2_mass_mail_recipients')->where('job_id', $job)->whereIn('address', $addresses)->update(['outcome' => 'delivered', 'attempts' => 1]);
    }

    public function test_a_running_job_is_cancelled_and_its_pending_recipients_skipped(): void
    {
        $addresses = array_map(fn (int $i): string => sprintf('u%03d@example.org', $i), range(1, 100));
        $job = $this->runningJob($addresses);
        $this->markDelivered($job, array_slice($addresses, 0, 60));
        $this->asAdmin();
        $this->mailcow()->admin('other', 'Passw0rd!', 'SSHA256');
        $this->forgetCookies();
        $this->signInAs('other', Role::Admin);

        $this->cancel($job)->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelledBy', ['username' => 'other'])
            ->assertJsonPath('data.counts', ['pending' => 0, 'delivered' => 60, 'failed' => 0, 'skipped' => 40]);

        $row = DB::table('restv2_mass_mail_jobs')->where('id', $job)->first();
        $this->assertNotNull($row);
        $this->assertSame([null, 'other'], [$row->running_slot, $row->cancelled_by]);
        $this->assertNotNull($row->finished_at);
        $record = $this->successesOf('massMailJobCancellation');
        $this->assertCount(1, $record);
        $this->assertSame(['counts' => ['pending' => 0, 'delivered' => 60, 'failed' => 0, 'skipped' => 40]], $record[0]->details);

        $this->app->make(MassMailRunner::class)->run(290);
        $this->lmtp()->assertNothingDelivered();
    }

    public function test_a_delivery_in_flight_at_the_cancellation_keeps_its_outcome_or_is_skipped(): void
    {
        $job = $this->runningJob(['a@example.org', 'b@example.org', 'c@example.org']);
        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
        $this->lmtp()->replyTo('b@example.org', LmtpResult::temporaryFailure(451, '4.3.0 Try again'));
        $this->lmtp()->onDeliver(function (string $to) use ($job): void {
            if ($to === 'b@example.org') {
                $this->app->make(MassMailJobService::class)->cancel($job, new Principal('admin', Role::Admin, AccountKind::Admin, AuthMethod::Password));
            }
        });

        $this->app->make(MassMailRunner::class)->run(290);

        $this->assertSame(['a@example.org' => 'delivered', 'b@example.org' => 'skipped', 'c@example.org' => 'skipped'], $this->outcomes($job));
        $this->assertSame(['a@example.org', 'b@example.org'], array_column($this->lmtp()->deliveries(), 'to'));
        $this->assertSame([], $this->auditOf('massMailJobCompletion'));
    }

    public function test_cancelling_again_changes_nothing_and_a_completed_job_is_a_conflict(): void
    {
        $this->asAdmin();
        $job = $this->runningJob(['a@example.org']);
        $this->cancel($job)->assertOk();

        $this->cancel($job)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertCount(1, $this->successesOf('massMailJobCancellation'));

        $completed = $this->runningJob(['b@example.org']);
        DB::table('restv2_mass_mail_jobs')->where('id', $completed)->update(['status' => 'completed', 'running_slot' => null]);
        $this->cancel($completed)->assertStatus(409)->assertJsonPath('type', 'about:blank');
    }

    public function test_keys_need_a_session_and_unknown_jobs_are_not_found(): void
    {
        $job = $this->runningJob(['a@example.org']);

        $this->send('POST', "/api/v2/mass-mail-jobs/{$job}/cancellation", null, $this->adminKey())->assertForbidden()->assertJsonPath('type', '/api/v2/problems/session-required');
        $this->assertSame('running', DB::table('restv2_mass_mail_jobs')->where('id', $job)->value('status'));

        $this->asAdmin();
        $this->cancel('01JABCDEFGHJKMNPQRSTVWXYZ0')->assertNotFound();
    }
}
