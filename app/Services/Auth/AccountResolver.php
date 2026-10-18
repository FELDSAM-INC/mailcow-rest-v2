<?php

namespace App\Services\Auth;

use App\Auth\AccountKind;
use App\Auth\Principal;
use App\Auth\Role;
use App\Models\Mailcow\Admin;
use App\Models\Mailcow\Mailbox;

/**
 * Read-only lookups of mailcow accounts (R16, data-model), including the account resolution of a
 * password sign-in (legacy `check_login`, `admin_login`, `domainadmin_login`, `user_login`).
 */
class AccountResolver
{
    /**
     * The accounts a password sign-in tries, in legacy order: administrator, domain administrator,
     * mailbox user. With a role, only that type. A malformed username (neither an e-mail address
     * nor alphanumeric apart from `_.-`) resolves to nothing, as in legacy.
     *
     * Unlike legacy, inactive accounts, resource mailboxes and mailboxes of external identity
     * sources are returned too, tagged with their status, so that the audit can record why the
     * sign-in failed. They never sign in.
     *
     * @param  string  $username  trimmed and lower-cased
     * @return list<AccountCandidate>
     */
    public function resolveForPassword(string $username, ?Role $role): array
    {
        if (self::isMalformed($username)) {
            return [];
        }

        $candidates = [];

        if ($role !== Role::MailboxUser) {
            $admin = Admin::query()->whereKey($username)->toBase()->first(['password', 'superadmin', 'active']);

            if ($admin !== null) {
                $adminRole = (int) $admin->superadmin === 1 ? Role::Admin : Role::DomainAdmin;

                if ($role === null || $role === $adminRole) {
                    $candidates[] = new AccountCandidate(
                        $username,
                        $adminRole,
                        (string) $admin->password,
                        (int) $admin->active === 1 ? CandidateStatus::Usable : CandidateStatus::Inactive,
                    );
                }
            }
        }

        if ($role === null || $role === Role::MailboxUser) {
            $mailbox = Mailbox::query()
                ->join('domain', 'mailbox.domain', '=', 'domain.domain')
                ->where('mailbox.username', $username)
                ->toBase()
                ->first(['mailbox.password', 'mailbox.kind', 'mailbox.authsource', 'mailbox.active', 'domain.active as d_active']);

            if ($mailbox !== null) {
                $candidates[] = new AccountCandidate($username, Role::MailboxUser, (string) $mailbox->password, match (true) {
                    preg_match('/'.Mailbox::RESOURCE_KIND_PATTERN.'/i', (string) $mailbox->kind) === 1 => CandidateStatus::Inactive,
                    ($mailbox->authsource ?? 'mailcow') !== 'mailcow' => CandidateStatus::ExternalIdentitySource,
                    (int) $mailbox->active !== 1 || (int) $mailbox->d_active !== 1 => CandidateStatus::Inactive,
                    default => CandidateStatus::Usable,
                });
            }
        }

        return $candidates;
    }

    /**
     * Legacy's username check: neither a valid e-mail address nor alphanumeric after removing
     * `_`, `.` and `-`.
     */
    public static function isMalformed(string $username): bool
    {
        return filter_var($username, FILTER_VALIDATE_EMAIL) === false
            && ! ctype_alnum(str_replace(['_', '.', '-'], '', $username));
    }

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
