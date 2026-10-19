<?php

namespace App\Policies;

use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Support\Http\ProblemType;

/**
 * Who may use the administrator account operations of feature 007 (FR-001, FR-004, FR-005,
 * research R24): administrators only, checked before any lookup (`403 forbidden` for every other
 * role, also for the own account). Reads (`listAdmins`, `getAdmin`, `getAccountCapabilities`) accept
 * a session or a key owned by an administrator, read-only keys included. Writes need a signed-in
 * session: their routes use `restv2.auth:session`, which refuses keys with `session-required` before
 * the role is checked, as feature 001's key creation does; the policy refuses them again. Not a
 * Gate policy: the Gate cannot answer the documented problem types.
 */
class AdminPolicy
{
    public const VIEW = 'view';

    public const WRITE = 'write';

    /**
     * @throws ProblemException `forbidden` or `session-required`
     */
    public function authorize(Principal $principal, string $ability): void
    {
        if ($principal->role !== Role::Admin) {
            throw new ProblemException(ProblemType::Forbidden, 'Only administrators manage administrator and domain administrator accounts.');
        }

        if ($ability === self::WRITE && $principal->isApiKey()) {
            throw new ProblemException(ProblemType::SessionRequired, 'Administrator accounts are changed only in a signed-in administrator session.');
        }
    }
}
