<?php

namespace App\Services\Auth;

use App\Auth\AccountKind;
use App\Auth\Principal;
use App\Auth\Role;
use App\Models\Mailcow\Admin;
use App\Models\Mailcow\Mailbox;

/**
 * Read-only lookups of mailcow accounts (R16, data-model). The password resolution of the sign-in
 * is added by PasswordSignInService (T063).
 */
class AccountResolver
{
    /**
     * Whether the principal's account still exists and may sign in (FR-029): an admin row with
     * `active = 1` and the same `superadmin` value, or a mailbox with `active = 1` in an active
     * domain that is not a resource.
     */
    public function accountStillActive(Principal $principal): bool
    {
        if ($principal->accountKind === AccountKind::Admin) {
            return Admin::query()
                ->whereKey($principal->username)
                ->where('active', 1)
                ->where('superadmin', $principal->role === Role::Admin ? 1 : 0)
                ->exists();
        }

        $mailbox = $this->findMailboxAccount($principal->username);

        return $mailbox !== null && (int) $mailbox->getAttribute('active') === 1 && (int) $mailbox->getAttribute('d_active') === 1;
    }

    /**
     * The `admin` row of the username, whatever its status.
     */
    public function findAdminAccount(string $username): ?Admin
    {
        return Admin::query()->whereKey($username)->first();
    }

    /**
     * The mailbox of the username with its domain's status as `d_active`, excluding resources
     * (legacy `user_login`: `SELECT mailbox.*, domain.active AS d_active FROM mailbox INNER JOIN
     * domain ... WHERE kind NOT REGEXP 'location|thing|group' AND username = ?`).
     */
    public function findMailboxAccount(string $username): ?Mailbox
    {
        return Mailbox::query()
            ->select('mailbox.*', 'domain.active as d_active')
            ->join('domain', 'mailbox.domain', '=', 'domain.domain')
            ->whereRaw('`mailbox`.`kind` NOT REGEXP ?', [Mailbox::RESOURCE_KIND_PATTERN])
            ->where('mailbox.username', $username)
            ->first();
    }
}
