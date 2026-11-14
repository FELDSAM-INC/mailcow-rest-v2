<?php

namespace App\Services\Accounts;

use App\Services\Auth\LegacyFactorAccountGuard;
use Illuminate\Support\Facades\DB;

/**
 * Suspends an account's second factors until its next successful password sign-in (feature 007
 * FR-027, research R13): legacy `disable_tfa`, `UPDATE tfa SET active = '0' WHERE username = ?`,
 * for every entry type. Feature 001's password sign-in re-activates them (FR-026 there), as legacy
 * does. Passkeys (`fido2`) are never touched. Runs inside the caller's transaction.
 */
class SecondFactorSuspender
{
    public function __construct(private readonly LegacyFactorAccountGuard $owners) {}

    /**
     * Returns the number of entries that were active.
     */
    public function suspend(string $username): int
    {
        $this->owners->assertUnambiguous($username);

        $active = DB::table('tfa')->where('username', $username)->where('active', 1)->lockForUpdate()->count();

        if ($active > 0) {
            DB::table('tfa')->where('username', $username)->update(['active' => '0']);
        }

        return $active;
    }
}
