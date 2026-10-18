<?php

namespace App\Policies;

use App\Auth\Principal;
use App\Auth\Role;

/**
 * Who may use the mailbox operations at all (feature 003 FR-005): administrators and domain
 * administrators. Mailbox users never reach this feature, not even for their own mailbox (their own
 * account is feature 005). Checked before any lookup, so that a refusal reveals nothing; object
 * scope is MailboxScope's and setting permissions are MailboxPermissionGuard's.
 */
class MailboxPolicy
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

    public function rename(Principal $principal): bool
    {
        return $this->viewAny($principal);
    }

    public function bulk(Principal $principal): bool
    {
        return $this->viewAny($principal);
    }

    public function previewDeletion(Principal $principal): bool
    {
        return $this->viewAny($principal);
    }

    public function viewCapabilities(Principal $principal): bool
    {
        return $this->viewAny($principal);
    }

    public function viewTags(Principal $principal): bool
    {
        return $this->viewAny($principal);
    }

    public function viewPasswordPolicy(Principal $principal): bool
    {
        return $this->viewAny($principal);
    }
}
