<?php

namespace App\Policies;

use App\Auth\Principal;
use App\Auth\Role;

/**
 * Who may use the operations of feature 004 at all (FR-005): administrators and domain
 * administrators, for aliases, alias domains, resources, sender settings and the capabilities read.
 * Mailbox users never reach them; their own view is feature 005. Checked before any lookup, so that
 * a refusal reveals nothing. Object scope is AliasScope's, DomainScope's and MailboxScope's;
 * permission flags are checked by the services.
 */
class AliasPolicy
{
    public function viewAny(Principal $principal): bool
    {
        return in_array($principal->role, [Role::Admin, Role::DomainAdmin], true);
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
