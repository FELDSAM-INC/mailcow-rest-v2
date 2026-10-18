<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\DB;

/**
 * Records a mailbox user's UI sign-in as a recent login in mailcow's `sasl_log` (FR-027, R16),
 * exactly like legacy `check_login`: one row per (service, address, username), replaced on every
 * sign-in, `app_password = 0`, `datetime` left to its NOW() default.
 */
class RecentLoginRecorder
{
    public function record(string $mailbox, string $ip): void
    {
        DB::statement(
            'REPLACE INTO `sasl_log` (`username`, `real_rip`, `service`, `app_password`) VALUES (?, ?, ?, 0)',
            [$mailbox, $ip, (string) config('restv2.sasl_log_service')],
        );
    }
}
