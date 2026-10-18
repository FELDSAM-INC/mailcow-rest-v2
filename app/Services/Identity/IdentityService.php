<?php

namespace App\Services\Identity;

use App\Auth\PendingAction;
use App\Auth\PermissionFlag;
use App\Auth\Principal;
use App\Auth\Role;
use App\Models\Mailcow\DaAcl;
use App\Models\Mailcow\Mailbox;
use App\Models\Mailcow\UserAcl;
use App\Services\Auth\PendingActionEvaluator;
use Illuminate\Support\Facades\DB;

/**
 * Who the caller is (FR-028, FR-029, R20), evaluated from mailcow's current data on every request,
 * for a session or an API key principal:
 *
 *  - scope: legacy `mailbox('get', 'domains')` for domain administrators (active assignments of
 *    existing domains, an inactive domain included), the mailbox and its domain for mailbox users;
 *  - permission flags: legacy `acl('to_session')`;
 *  - pending actions: PendingActionEvaluator (never any for an API key).
 */
class IdentityService
{
    public function __construct(private readonly PendingActionEvaluator $pendingActions) {}

    public function scope(Principal $principal): Scope
    {
        return match ($principal->role) {
            Role::Admin => Scope::allDomains(),
            Role::DomainAdmin => Scope::domains(DB::table('domain')
                ->whereIn('domain', fn ($query) => $query->select('domain')
                    ->from('domain_admins')
                    ->where('active', 1)
                    ->where('username', $principal->username))
                ->orderBy('domain')
                ->pluck('domain')
                ->map(fn (mixed $domain): string => (string) $domain)
                ->all()),
            Role::MailboxUser => Scope::mailbox(
                $principal->username,
                (string) (Mailbox::query()->whereKey($principal->username)->toBase()->value('domain')
                    ?? substr((string) strrchr($principal->username, '@'), 1)),
            ),
        };
    }

    /**
     * The 29 effective flags (contract `PermissionFlags`), legacy `acl('to_session')`:
     *
     *  - an administrator has every flag;
     *  - a domain administrator has every `user_acl` flag, overridden by its own `da_acl` row where
     *    the names overlap; without a `da_acl` row every `da_acl` flag is off;
     *  - a mailbox user has its `user_acl` row (every flag off without one) and never a flag that
     *    exists only in `da_acl`.
     *
     * @return array<string, bool> contract name => granted
     */
    public function permissions(Principal $principal): array
    {
        $flags = [];

        if ($principal->role === Role::Admin) {
            foreach (PermissionFlag::cases() as $flag) {
                $flags[$flag->value] = true;
            }

            return $flags;
        }

        if ($principal->role === Role::DomainAdmin) {
            $row = (array) (DaAcl::query()->whereKey($principal->username)->toBase()->first() ?? []);

            foreach (PermissionFlag::cases() as $flag) {
                $column = $flag->daAclColumn();
                $flags[$flag->value] = $column === null ? true : (int) ($row[$column] ?? 0) === 1;
            }

            return $flags;
        }

        $row = (array) (UserAcl::query()->whereKey($principal->username)->toBase()->first() ?? []);

        foreach (PermissionFlag::cases() as $flag) {
            $column = $flag->userAclColumn();
            $flags[$flag->value] = $column !== null && (int) ($row[$column] ?? 0) === 1;
        }

        return $flags;
    }

    /**
     * @return list<PendingAction>
     */
    public function pendingActions(Principal $principal): array
    {
        return $this->pendingActions->evaluate($principal);
    }
}
