<?php

namespace App\Services\Accounts;

use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Services\Audit\AuditLogger;
use App\Services\Passwords\MailboxPasswordHasher;
use App\Support\Http\ProblemType;
use stdClass;

/**
 * Administrators (feature 007 FR-017, FR-022, FR-028 to FR-030): the account writes of
 * AccountService, each run under the atomic last-administrator guard, and no deletion of the own
 * account (legacy `cannot_delete_self`, checked before the guard).
 */
class AdminAccountService extends AccountService
{
    public function __construct(
        AccountRepresentation $accounts,
        AccountRenamer $renamer,
        AccountDeleter $deleter,
        SecondFactorSuspender $suspender,
        MailboxPasswordHasher $hasher,
        AuditLogger $audit,
        private readonly LastAdminGuard $guard,
    ) {
        parent::__construct($accounts, $renamer, $deleter, $suspender, $hasher, $audit);
    }

    protected function role(): Role
    {
        return Role::Admin;
    }

    protected function transaction(callable $write): mixed
    {
        return $this->guard->within($write);
    }

    protected function beforeDeletion(Principal $actor, stdClass $row): void
    {
        $acting = $actor->actingAccount();

        if ($acting->role === Role::Admin && mb_strtolower($acting->username) === mb_strtolower((string) $row->username)) {
            throw new ProblemException(ProblemType::CannotDeleteOwnAccount);
        }
    }
}
