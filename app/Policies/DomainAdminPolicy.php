<?php

namespace App\Policies;

use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Support\Http\ProblemType;

/**
 * Who may use the domain administrator operations of feature 007 (FR-001, FR-004, FR-005, research
 * R24), single sign-on token issue included: administrators only, with a session or a key owned by
 * an administrator (billing systems), checked before any lookup. Domain administrators and mailbox
 * users get `403 forbidden`, also for their own account. A read-only key is refused on writes by
 * feature 001's EnforceKeyScope (`insufficient-key-scope`) before this check.
 */
class DomainAdminPolicy
{
    /**
     * @throws ProblemException `forbidden`
     */
    public function authorize(Principal $principal): void
    {
        if ($principal->role !== Role::Admin) {
            throw new ProblemException(ProblemType::Forbidden, 'Only administrators manage domain administrators and their single sign-on tokens.');
        }
    }
}
