<?php

namespace App\Policies;

use App\Auth\Principal;
use App\Auth\Role;

/**
 * Feature 012 (FR-001, FR-003): only administrators reach the OAuth2 clients and their capabilities, with a
 * session or a key (read-only keys are refused for unsafe methods by EnforceKeyScope). Domain
 * administrators and mailbox users are refused for every ability.
 */
class Oauth2ClientPolicy
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

    public function bulk(Principal $principal): bool
    {
        return $this->viewAny($principal);
    }
}
