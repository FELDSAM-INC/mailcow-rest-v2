<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retention of the audit log (FR-048, R22): deletes the records older than
 * `RESTV2_AUDIT_RETENTION_DAYS` (365 by default) in batches of 1,000. mailcow's ofelia scheduler
 * runs it daily through the container labels of the deployment override.
 */
class AuditPurgeCommand extends Command
{
    private const BATCH = 1000;

    protected $signature = 'restv2:audit-purge';

    protected $description = 'Delete audit records older than the retention period (RESTV2_AUDIT_RETENTION_DAYS)';

    public function handle(): int
    {
        $days = max(1, (int) config('restv2.audit_retention_days'));
        $cutoff = CarbonImmutable::now('UTC')->subDays($days)->format('Y-m-d H:i:s.u');
        $total = 0;

        do {
            // The query builder, not the model: records are immutable through the application.
            $deleted = DB::table('restv2_audit_events')
                ->where('occurred_at', '<', $cutoff)
                ->orderBy('occurred_at')
                ->limit(self::BATCH)
                ->delete();
            $total += $deleted;
        } while ($deleted === self::BATCH);

        $this->info("Deleted {$total} audit records older than {$days} days.");

        return self::SUCCESS;
    }
}
