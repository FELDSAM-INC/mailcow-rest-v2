<?php

namespace App\Policies;

use App\Auth\Principal;
use App\Auth\Role;

/**
 * Who may use the domain operations at all (FR-005). Checked before any lookup, so that a refusal
 * reveals nothing about objects (research D11 step 2). Object scope is DomainScope's; attribute
 * permissions are DomainAttributeGuard's.
 */
class DomainPolicy
{
    /** List and inspect domains, read capabilities and distinct tags. */
    public function viewAny(Principal $principal): bool
    {
        return in_array($principal->role, [Role::Admin, Role::DomainAdmin], true);
    }

    public function view(Principal $principal): bool
    {
        return $this->viewAny($principal);
    }

    public function update(Principal $principal): bool
    {
        return $this->viewAny($principal);
    }

    /** Read and replace a domain's footer (no permission flag needed, as in legacy). */
    public function updateFooter(Principal $principal): bool
    {
        return $this->viewAny($principal);
    }

    public function create(Principal $principal): bool
    {
        return $principal->role === Role::Admin;
    }

    public function delete(Principal $principal): bool
    {
        return $principal->role === Role::Admin;
    }

    public function bulk(Principal $principal): bool
    {
        return $principal->role === Role::Admin;
    }

    public function previewDeletion(Principal $principal): bool
    {
        return $principal->role === Role::Admin;
    }
}
