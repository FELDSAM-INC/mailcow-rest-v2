<?php

namespace App\Services\SenderSettings;

use App\Support\Http\ETag;
use Illuminate\Support\Facades\DB;

/**
 * A mailbox's sender settings (feature 004 FR-053, research R14).
 */
class SenderSettingsReader
{
    /**
     * The version tag over the mailbox's stored `sender_acl` rows (explicit permissions and external
     * sender addresses), independent of row order and IDs (research R18).
     */
    public function etagFor(string $mailbox): string
    {
        $pairs = DB::table('sender_acl')->where('logged_in_as', $mailbox)->get(['external', 'send_as'])
            ->map(fn (object $row): array => [(int) $row->external, (string) $row->send_as])
            ->all();

        usort($pairs, fn (array $a, array $b): int => [$a[0], mb_strtolower($a[1]), $a[1]] <=> [$b[0], mb_strtolower($b[1]), $b[1]]);

        return ETag::of(['mailboxSenderSettings', mb_strtolower($mailbox), $pairs]);
    }
}
