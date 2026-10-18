<?php

namespace App\Console\Commands;

use App\Services\Health\ReadinessChecker;
use Illuminate\Console\Command;

/**
 * The container health check (R24): php-fpm speaks only FastCGI, so Docker runs
 * `php artisan restv2:health --ready` (or `--live`) instead of an HTTP request. Exit code 0 when
 * healthy, 1 when not.
 */
class HealthCommand extends Command
{
    protected $signature = 'restv2:health
        {--live : Only check that PHP and the application boot}
        {--ready : Check the database, the schema version, Redis and the migrations (the default)}';

    protected $description = 'Check liveness or readiness for the container health check';

    public function handle(ReadinessChecker $checker): int
    {
        if ($this->option('live') && ! $this->option('ready')) {
            $this->line('live');

            return self::SUCCESS;
        }

        $checks = $checker->check();

        foreach ($checks as $name => $status) {
            $this->line("{$name}: {$status}");
        }

        if (! ReadinessChecker::ready($checks)) {
            $this->error('not ready');

            return self::FAILURE;
        }

        $this->line('ready');

        return self::SUCCESS;
    }
}
