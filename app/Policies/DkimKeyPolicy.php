<?php

namespace App\Policies;

use App\Auth\Principal;
use App\Auth\Role;

/**
 * Who may use the DKIM operations at all (feature 010 FR-005), checked before any lookup so that a
 * refusal reveals nothing about names. The scope of a name is DkimNameResolver's. Domain
 * administrators may read and generate keys of their names; overwriting, importing, copying,
 * deleting, exporting and publishing again are for administrators. Mailbox users get nothing.
 */
class DkimKeyPolicy
{
    public function viewAny(Principal $principal): bool
    {
        return in_array($principal->role, [Role::Admin, Role::DomainAdmin], true);
    }

    public function view(Principal $principal): bool
    {
        return $this->viewAny($principal);
    }

    public function generate(Principal $principal): bool
    {
        return $this->viewAny($principal);
    }

    public function overwrite(Principal $principal): bool
    {
        return $principal->role === Role::Admin;
    }

    public function import(Principal $principal): bool
    {
        return $principal->role === Role::Admin;
    }

    public function copy(Principal $principal): bool
    {
        return $principal->role === Role::Admin;
    }

    public function delete(Principal $principal): bool
    {
        return $principal->role === Role::Admin;
    }

    public function export(Principal $principal): bool
    {
        return $principal->role === Role::Admin;
    }

    public function publish(Principal $principal): bool
    {
        return $principal->role === Role::Admin;
    }
}
