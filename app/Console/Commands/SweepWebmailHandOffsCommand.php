<?php

namespace App\Console\Commands;

use App\Services\Webmail\WebmailHandOffService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Ends the webmail hand-offs whose session of this API expired (feature 005 FR-068, research R3):
 * an idle expiry raises no event, so mailcow's ofelia runs this every minute through the container
 * labels of the deployment override.
 */
class SweepWebmailHandOffsCommand extends Command
{
    protected $signature = 'restv2:webmail-handoffs:sweep';

    protected $description = 'End the webmail hand-offs of expired API sessions';

    public function handle(WebmailHandOffService $handOffs): int
    {
        $counts = $handOffs->sweep();
        $line = "Webmail hand-offs: {$counts['ended']} ended, {$counts['forgotten']} already expired, {$counts['kept']} kept.";

        Log::info($line, $counts);
        $this->info($line);

        return self::SUCCESS;
    }
}
