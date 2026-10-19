<?php

namespace App\Policies;

use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Support\Http\ProblemType;

/**
 * Who may list the presets (feature 013 FR-005, FR-061): administrators every kind; domain
 * administrators the sieve presets (legacy also shows them the rspamd presets, a deliberate
 * deviation); mailbox users none.
 */
class PresetPolicy
{
    /**
     * @throws ProblemException `403 forbidden`
     */
    public function authorize(Principal $principal, string $kind): void
    {
        $allowed = $principal->role === Role::Admin || ($principal->role === Role::DomainAdmin && $kind === 'sieve');

        if (! $allowed) {
            throw new ProblemException(ProblemType::Forbidden);
        }
    }
}
