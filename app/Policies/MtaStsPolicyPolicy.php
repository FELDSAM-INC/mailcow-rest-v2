<?php

namespace App\Policies;

use App\Auth\Principal;
use App\Auth\Role;

/**
 * Who may read, list, create and change MTA-STS policies (feature 010 FR-005, FR-032):
 * administrators and domain administrators, with no permission flag, as in legacy. The scope of a
 * domain is DomainScope's.
 */
class MtaStsPolicyPolicy
{
    public function use(Principal $principal): bool
    {
        return in_array($principal->role, [Role::Admin, Role::DomainAdmin], true);
    }
}
