<?php

namespace App\Console\Commands;

use App\Services\MassMail\MassMailRunner;
use Illuminate\Console\Command;

/**
 * Delivers the running mass mail job (feature 013 research R17). mailcow's ofelia scheduler runs it
 * every 10 seconds without overlap (deploy/docker-compose.override.yml); a run ends after
 * `--max-time` seconds and the next one continues. Without a running job it exits at once.
 */
class MassMailRunCommand extends Command
{
    protected $signature = 'restv2:mass-mail-run {--max-time=290 : Seconds after which no further delivery is started}';

    protected $description = 'Deliver the running mass mail job at the configured rate (RESTV2_MASS_MAIL_RATE)';

    public function handle(MassMailRunner $runner): int
    {
        $runner->run(max(1, (int) $this->option('max-time')));

        return self::SUCCESS;
    }
}
