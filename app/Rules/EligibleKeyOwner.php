<?php

namespace App\Rules;

use App\Models\Mailcow\Admin;
use App\Models\Mailcow\Mailbox;

/**
 * The owner of an API key must be an administrator or domain administrator (a row of mailcow's
 * `admin` table, FR-032): `notFound` when the username is unknown, `notEligible` with
 * `allowedRoles` when it is only a mailbox.
 */
class EligibleKeyOwner extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        if (! is_string($value)) {
            return $this->violation('notFound');
        }

        if (Admin::query()->whereKey($value)->exists()) {
            return null;
        }

        if (Mailbox::query()->whereKey($value)->exists()) {
            return $this->violation('notEligible', ['allowedRoles' => ['admin', 'domainAdmin']], 'A mailbox user cannot own an API key.');
        }

        return $this->violation('notFound', [], 'No administrator or domain administrator has this username.');
    }
}
