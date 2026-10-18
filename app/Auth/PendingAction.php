<?php

namespace App\Auth;

/**
 * An action an account must complete before its session is fully usable (contract
 * `PendingAction`, FR-014, FR-054).
 */
enum PendingAction: string
{
    case PasswordChange = 'passwordChange';
    case SecondFactorSetup = 'secondFactorSetup';
}
