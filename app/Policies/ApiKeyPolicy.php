<?php

namespace App\Policies;

use App\Auth\Principal;
use App\Auth\Role;

/**
 * Who may manage API keys (FR-035–FR-037, FR-057, SC-010). Listing and reading keys and their
 * possible owners needs an administrator, signed in or through an administrator's key (read-only
 * keys included). Creating and revoking keys needs a signed-in administrator: the routes refuse
 * keys with `session-required` before this policy runs (R8). Every other role is `forbidden`.
 */
class ApiKeyPolicy
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
        return $principal->role === Role::Admin && ! $principal->isApiKey();
    }

    public function revoke(Principal $principal): bool
    {
        return $this->create($principal);
    }
}
