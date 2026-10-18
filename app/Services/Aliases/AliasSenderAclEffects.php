<?php

namespace App\Services\Aliases;

use Illuminate\Support\Facades\DB;

/**
 * The explicit sender permissions that follow alias changes (feature 004 FR-030, FR-032, FR-035,
 * research R8), run inside the change's transaction:
 *
 *  - afterSave(): when the alias allows sending, the permissions of its destination mailboxes to
 *    send as its address are removed, because the alias grants that right (legacy's statement,
 *    which legacy ran on every edit; kept when sending is not allowed, so that deliberate overrides
 *    survive);
 *  - moved(): an address change carries the permissions over to the new address (new; legacy left
 *    them on the old address), without duplicates;
 *  - deleted(): legacy's `DELETE FROM sender_acl WHERE send_as = :address`.
 *
 * A catch-all alias's address is `@domain`, the stored form of a domain permission, so these
 * statements act on the domain permissions for its domain, as legacy's do (plan, owner-level
 * question 1).
 */
class AliasSenderAclEffects
{
    public function afterSave(string $address, AliasDestination $destination, bool $senderAllowed): void
    {
        if (! $senderAllowed || $destination->isSpecial() || $destination->addresses === []) {
            return;
        }

        DB::table('sender_acl')->where('send_as', $address)->whereIn('logged_in_as', $destination->addresses)->delete();
    }

    public function moved(string $old, string $new): void
    {
        if (mb_strtolower($old) !== mb_strtolower($new)) {
            $holders = DB::table('sender_acl')->where('send_as', $old)->where('external', 0)->pluck('logged_in_as')->all();

            if ($holders !== []) {
                DB::table('sender_acl')->where('send_as', $new)->where('external', 0)->whereIn('logged_in_as', $holders)->delete();
            }
        }

        DB::table('sender_acl')->where('send_as', $old)->where('external', 0)->update(['send_as' => $new]);
    }

    /** Removes every sender permission for the address; returns how many were removed. */
    public function deleted(string $address): int
    {
        return DB::table('sender_acl')->where('send_as', $address)->delete();
    }
}
