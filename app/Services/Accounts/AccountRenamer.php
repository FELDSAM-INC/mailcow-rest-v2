<?php

namespace App\Services\Accounts;

use App\Auth\AccountKind;
use App\Auth\Principal;
use App\Exceptions\ProblemException;
use App\Services\ApiKeys\ApiKeyService;
use App\Services\Auth\SessionManager;
use App\Services\Auth\SessionRegistry;
use App\Services\Preferences\PreferencesService;
use App\Support\Http\ProblemType;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;

/**
 * Renames an administrator or domain administrator (feature 007 FR-025, research R9), inside the
 * caller's transaction, so that the rename and the change it belongs to happen together or not at
 * all. What legacy deletes with an account moves with it: the `admin` row with its forced flags,
 * the assignments (keeping their `id` and creation time), the permission record, every `tfa` and
 * `fido2` entry, active or not, the API keys (feature 001) and the preferences. Unused single
 * sign-on tokens of the old name are removed. Rows that no account owns under the new name (left by
 * legacy) are removed first, so that the renamed account inherits nothing.
 *
 * After the commit the account's other sessions end (feature 005's SessionRegistry) and its
 * generation is bumped, which ends every impersonation of it at its next request. A caller that
 * renamed its own account continues under the new name.
 */
class AccountRenamer
{
    public function __construct(
        private readonly ApiKeyService $keys,
        private readonly PreferencesService $preferences,
        private readonly SessionRegistry $registry,
        private readonly SessionManager $sessions,
        private readonly AccountGeneration $generations,
    ) {}

    /**
     * @param  Session|null  $callerSession  the caller's session, kept when it renamed its own account
     *
     * @throws ProblemException account-exists
     */
    public function rename(string $old, string $new, Principal $actor, ?Session $callerSession): void
    {
        if (DB::table('admin')->where('username', $new)->lockForUpdate()->exists()) {
            throw new ProblemException(ProblemType::AccountExists, "An administrator or domain administrator named {$new} already exists.");
        }

        foreach (['domain_admins', 'da_acl', 'tfa', 'fido2', 'da_sso'] as $table) {
            DB::table($table)->where('username', $new)->delete();
        }

        DB::table('da_sso')->where('username', $old)->delete();
        DB::table('admin')->where('username', $old)->update(['username' => $new]);

        foreach (['domain_admins', 'da_acl', 'tfa', 'fido2'] as $table) {
            DB::table($table)->where('username', $old)->update(['username' => $new]);
        }

        $this->keys->transferOwner($old, $new);
        $this->preferences->renameAccount(AccountKind::Admin, $old, $new);

        $own = ! $actor->isApiKey() && $actor->impersonator === null
            && $actor->accountKind === AccountKind::Admin && mb_strtolower($actor->username) === mb_strtolower($old);
        $ownSession = $own ? $callerSession : null;

        DB::afterCommit(function () use ($old, $new, $ownSession): void {
            $exceptHandle = $ownSession === null ? null : $this->sessions->handle($ownSession);
            $this->registry->endAll(AccountKind::Admin, mb_strtolower($old), $exceptHandle);

            if ($ownSession !== null) {
                $this->sessions->rebindPrincipal($ownSession, $new);
            }

            $this->generations->bump('admin', $old);
        });
    }
}
