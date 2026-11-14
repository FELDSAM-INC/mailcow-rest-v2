<?php

namespace App\Services\Auth;

use App\Exceptions\ProblemException;
use App\Models\Mailcow\Admin;
use App\Models\Mailcow\Mailbox;
use App\Support\Http\ProblemType;

/** Legacy second factors and passkeys have a username but no account-kind binding. */
class LegacyFactorAccountGuard
{
    public function ambiguous(string $username): bool
    {
        return Admin::query()->whereKey($username)->exists()
            && $this->mailboxAccountExists($username);
    }

    public function assertUnambiguous(string $username): void
    {
        if ($this->ambiguous($username)) {
            throw new ProblemException(ProblemType::Forbidden, 'Credential ownership is ambiguous while administrator and mailbox accounts share this username.');
        }
    }

    public function assertMailboxNameAvailable(string $username): void
    {
        if (Admin::query()->whereKey($username)->exists()) {
            throw new ProblemException(ProblemType::Forbidden, 'A mailbox cannot use an administrator account name.');
        }
    }

    public function assertAdminNameAvailable(string $username): void
    {
        if ($this->mailboxAccountExists($username)) {
            throw new ProblemException(ProblemType::AccountExists, 'The username is already used by a mailbox.');
        }
    }

    private function mailboxAccountExists(string $username): bool
    {
        return Mailbox::query()->whereKey($username)
            ->whereRaw('`kind` NOT REGEXP ?', [Mailbox::RESOURCE_KIND_PATTERN])->exists();
    }
}
