<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retention of mass mail jobs (feature 013 FR-080): deletes the jobs that are not running and were
 * created more than `RESTV2_AUDIT_RETENTION_DAYS` (365 by default) ago, with their recipients (the
 * foreign key cascades). mailcow's ofelia scheduler runs it daily.
 */
class MassMailPurgeCommand extends Command
{
    protected $signature = 'restv2:mass-mail-purge';

    protected $description = 'Delete mass mail jobs older than the retention period (RESTV2_AUDIT_RETENTION_DAYS)';

    public function handle(): int
    {
        $days = max(1, (int) config('restv2.audit_retention_days'));
        $cutoff = CarbonImmutable::now('UTC')->subDays($days)->format('Y-m-d H:i:s.u');
        $deleted = DB::table('restv2_mass_mail_jobs')->where('status', '!=', 'running')->where('created_at', '<', $cutoff)->delete();

        $this->info("Deleted {$deleted} mass mail jobs older than {$days} days.");

        return self::SUCCESS;
    }
}
