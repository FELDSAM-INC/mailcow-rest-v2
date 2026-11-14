<?php

namespace App\Services\Passkeys;

use App\Exceptions\ProblemException;
use App\Models\Mailcow\Admin;
use App\Models\Mailcow\Mailbox;
use App\Support\Http\ProblemType;

/** Legacy passkeys have a username but no account-kind binding. */
class PasskeyAccountGuard
{
    public function ambiguous(string $username): bool
    {
        return Admin::query()->whereKey($username)->exists()
            && Mailbox::query()->whereKey($username)->exists();
    }

    public function assertUnambiguous(string $username): void
    {
        if ($this->ambiguous($username)) {
            throw new ProblemException(ProblemType::Forbidden, 'Passkeys are unavailable while administrator and mailbox accounts share this username.');
        }
    }
}
