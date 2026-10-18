<?php

namespace App\Services\Auth;

use App\Auth\AccountKind;
use App\Auth\Role;

/**
 * An account a password sign-in may resolve to (R16): its role, its stored hash and whether it may
 * sign in. Never carries the password.
 */
final readonly class AccountCandidate
{
    public function __construct(
        public string $username,
        public Role $role,
        public string $passwordHash,
        public CandidateStatus $status,
        // Feature 012: mailcow's authentication source of a mailbox (`mailcow`, `ldap`, `keycloak`,
        // `generic-oidc`); null for administrators.
        public ?string $authsource = null,
    ) {}

    public function accountKind(): AccountKind
    {
        return $this->role->accountKind();
    }
}
