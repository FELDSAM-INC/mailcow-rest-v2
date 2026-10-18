<?php

namespace App\Services\Account;

use App\Auth\AccountKind;
use App\Auth\Principal;
use App\Auth\Role;
use Illuminate\Support\Facades\DB;

/**
 * What the signed-in account may do with its own credentials (feature 005 FR-013, FR-028, FR-034,
 * FR-080), read from mailcow's current data: whether an administrator requires it to keep a second
 * factor (`attributes.force_tfa` 1 or "1"), and whether its password and second factors are
 * managed here (administrators, domain administrators and mailcow-source mailboxes; not mailboxes
 * of an external identity provider).
 */
class AccountCapabilities
{
    /**
     * @phpstan-impure
     */
    public function secondFactorRequired(Principal $principal): bool
    {
        $attributes = $this->attributes($principal);
        $value = $attributes['force_tfa'] ?? 0;

        return is_scalar($value) && (int) $value === 1;
    }

    /**
     * Whether the account's password and second factors are managed by this API: false for a
     * mailbox whose authentication source is an external identity provider.
     *
     * @phpstan-impure
     */
    public function managedHere(Principal $principal): bool
    {
        if ($principal->accountKind === AccountKind::Admin) {
            return true;
        }

        return DB::table('mailbox')->where('username', $principal->username)->value('authsource') === 'mailcow';
    }

    /**
     * The contract's `SelfServiceCapabilities.account`.
     *
     * @return array{role: string, secondFactorRequired: bool, passwordChangeAvailable: bool, secondFactorManagementAvailable: bool}
     */
    public function describe(Principal $principal): array
    {
        $managed = $this->managedHere($principal);

        return [
            'role' => $principal->role->value,
            'secondFactorRequired' => $this->secondFactorRequired($principal),
            'passwordChangeAvailable' => $managed,
            'secondFactorManagementAvailable' => $managed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Principal $principal): array
    {
        $table = $principal->role === Role::MailboxUser ? 'mailbox' : 'admin';
        $raw = DB::table($table)->where('username', $principal->username)->value('attributes');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
