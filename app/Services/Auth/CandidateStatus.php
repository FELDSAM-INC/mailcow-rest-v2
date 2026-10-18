<?php

namespace App\Services\Auth;

/**
 * Whether an account found for a password sign-in may sign in (R16).
 */
enum CandidateStatus
{
    /** Active account (and, for a mailbox, active domain, not a resource). */
    case Usable;

    /** Inactive account or domain, or a resource mailbox: never signs in. */
    case Inactive;

    /** A mailbox whose password lives in LDAP, Keycloak or a generic OIDC provider (FR-003). */
    case ExternalIdentitySource;
}
