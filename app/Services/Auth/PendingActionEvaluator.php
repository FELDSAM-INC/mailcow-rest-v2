<?php

namespace App\Services\Auth;

use App\Auth\AccountKind;
use App\Auth\PendingAction;
use App\Auth\Principal;
use App\Models\Mailcow\Admin;
use App\Models\Mailcow\Fido2Credential;
use App\Models\Mailcow\Mailbox;
use App\Models\Mailcow\TfaEntry;

/**
 * Pending actions of an account (FR-014, FR-054, R16), evaluated from mailcow's current data on
 * every request, so that a restriction lifts as soon as it is resolved in either UI:
 *
 *  - `passwordChange` when `attributes.force_pw_update` is 1 (legacy `intval(...) == 1`);
 *  - `secondFactorSetup` when `attributes.force_tfa` is 1, the account has no `tfa` row at all
 *    (an inactive one counts, legacy `tfa_exists`) and no active `fido2` row.
 *
 * API keys are never restricted.
 */
class PendingActionEvaluator
{
    /**
     * @return list<PendingAction>
     *
     * @phpstan-impure
     */
    public function evaluate(Principal $principal): array
    {
        if ($principal->isApiKey()) {
            return [];
        }

        return $this->forAccount($principal->accountKind, $principal->username);
    }

    /**
     * @return list<PendingAction>
     *
     * @phpstan-impure
     */
    public function forAccount(AccountKind $kind, string $username): array
    {
        $model = $kind === AccountKind::Admin ? Admin::class : Mailbox::class;
        $raw = $model::query()->whereKey($username)->toBase()->value('attributes');
        $attributes = is_string($raw) ? (json_decode($raw, true) ?: []) : [];

        $actions = [];

        if ($this->flag($attributes, 'force_pw_update')) {
            $actions[] = PendingAction::PasswordChange;
        }

        if ($this->flag($attributes, 'force_tfa')
            && ! TfaEntry::query()->where('username', $username)->exists()
            && ! Fido2Credential::query()->where('username', $username)->where('active', 1)->exists()) {
            $actions[] = PendingAction::SecondFactorSetup;
        }

        return $actions;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function flag(array $attributes, string $name): bool
    {
        $value = $attributes[$name] ?? 0;

        return is_scalar($value) && (int) $value === 1;
    }
}
