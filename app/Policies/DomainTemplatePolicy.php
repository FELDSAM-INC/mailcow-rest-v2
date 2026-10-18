<?php

namespace App\Policies;

use App\Auth\Principal;
use App\Auth\Role;

/**
 * Domain templates are for administrators only (FR-005, spec deviation: legacy lets domain
 * administrators read them through /api/v1).
 */
class DomainTemplatePolicy
{
    public function viewAny(Principal $principal): bool
    {
        return $principal->role === Role::Admin;
    }

    public function view(Principal $principal): bool
    {
        return $this->viewAny($principal);
    }

    public function create(Principal $principal): bool
    {
        return $this->viewAny($principal);
    }

    public function update(Principal $principal): bool
    {
        return $this->viewAny($principal);
    }

    public function delete(Principal $principal): bool
    {
        return $this->viewAny($principal);
    }
}
