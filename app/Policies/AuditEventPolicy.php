<?php

namespace App\Policies;

use App\Auth\Principal;
use App\Auth\Role;

/**
 * Who may read the audit log (FR-047, SC-010): administrators, signed in or through an
 * administrator's key. Every other role is `forbidden`.
 */
class AuditEventPolicy
{
    public function viewAny(Principal $principal): bool
    {
        return $principal->role === Role::Admin;
    }
}
