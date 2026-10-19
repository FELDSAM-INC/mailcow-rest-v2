<?php

namespace Tests\Feature\MassMail;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\MassMail\MassMailTestHelpers;
use Tests\TestCase;

/**
 * Feature 013 T071 (FR-080): jobs are kept for the audit retention period; the daily purge deletes
 * older jobs that are not running, with their recipients.
 */
class MassMailPurgeTest extends TestCase
{
    use MassMailTestHelpers;

    public function test_old_finished_jobs_are_purged_and_running_and_recent_ones_kept(): void
    {
        $old = CarbonImmutable::now('UTC')->subDays(400)->format('Y-m-d H:i:s.u');
        $finished = $this->runningJob(['a@example.org'], ['created_at' => $old]);
        DB::table('restv2_mass_mail_jobs')->where('id', $finished)->update(['status' => 'completed', 'running_slot' => null]);
        $cancelled = $this->runningJob(['b@example.org'], ['created_at' => $old]);
        DB::table('restv2_mass_mail_jobs')->where('id', $cancelled)->update(['status' => 'cancelled', 'running_slot' => null]);
        $recent = $this->runningJob(['c@example.org']);
        DB::table('restv2_mass_mail_jobs')->where('id', $recent)->update(['status' => 'completed', 'running_slot' => null]);
        $running = $this->runningJob(['d@example.org'], ['created_at' => $old]);

        $this->artisan('restv2:mass-mail-purge')->assertSuccessful();

        $this->assertEqualsCanonicalizing([$recent, $running], DB::table('restv2_mass_mail_jobs')->pluck('id')->all());
        $this->assertEqualsCanonicalizing(['c@example.org', 'd@example.org'], DB::table('restv2_mass_mail_recipients')->pluck('address')->all());
    }

    public function test_the_retention_setting_applies(): void
    {
        config(['restv2.audit_retention_days' => 10]);
        $job = $this->runningJob(['a@example.org'], ['created_at' => CarbonImmutable::now('UTC')->subDays(11)->format('Y-m-d H:i:s.u')]);
        DB::table('restv2_mass_mail_jobs')->where('id', $job)->update(['status' => 'completed', 'running_slot' => null]);

        $this->artisan('restv2:mass-mail-purge')->assertSuccessful();

        $this->assertSame(0, DB::table('restv2_mass_mail_jobs')->count());
    }
}
