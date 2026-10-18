<?php

namespace App\Policies;

use App\Auth\Principal;
use App\Auth\Role;

/**
 * Who may run DNS diagnostics and read the capabilities of feature 010 (FR-005, FR-039, FR-041,
 * FR-012): administrators and domain administrators; the server-wide checks only for
 * administrators, as in legacy.
 */
class DnsDiagnosticsPolicy
{
    public function run(Principal $principal): bool
    {
        return in_array($principal->role, [Role::Admin, Role::DomainAdmin], true);
    }

    public function serverChecks(Principal $principal): bool
    {
        return $principal->role === Role::Admin;
    }
}
