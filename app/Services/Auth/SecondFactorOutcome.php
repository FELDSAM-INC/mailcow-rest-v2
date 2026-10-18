<?php

namespace App\Services\Auth;

/**
 * Result of checking a second factor of a pending sign-in (FR-021–FR-025).
 */
enum SecondFactorOutcome
{
    case Verified;

    /** A wrong or foreign factor: cancels the pending sign-in and counts towards throttling. */
    case Failed;

    /** The method was deactivated or deleted after the password step: cancels, not counted. */
    case MethodVanished;

    /** No WebAuthn challenge was issued, or it expired or was used: nothing changes. */
    case ChallengeExpired;

    /** The Yubico service is unreachable: the pending sign-in stays open, nothing is counted. */
    case ServiceUnavailable;
}
