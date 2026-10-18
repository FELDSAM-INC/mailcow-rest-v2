<?php

namespace App\Policies;

use App\Auth\Principal;
use App\Auth\Role;

/**
 * Mailbox templates (feature 003 FR-005, FR-049): administrators manage them; domain administrators
 * read them, because they pick one when creating a mailbox.
 */
class MailboxTemplatePolicy
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
        return $principal->role === Role::Admin;
    }

    public function update(Principal $principal): bool
    {
        return $this->create($principal);
    }

    public function delete(Principal $principal): bool
    {
        return $this->create($principal);
    }
}
