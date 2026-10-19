<?php

namespace App\Auth;

/**
 * Why an impersonation ended automatically (feature 007 FR-052, research R17), recorded as
 * `endReason` of its `impersonationEnd` audit record.
 */
enum ImpersonationEndReason: string
{
    /** The target was deleted or renamed, or replaced by a later account with the same name. */
    case TargetDeletedOrRenamed = 'targetDeletedOrRenamed';

    /** The target mailbox is no longer in an active assignment of the impersonating domain administrator. */
    case TargetOutOfScope = 'targetOutOfScope';

    /** The impersonating domain administrator's `login_as` flag was turned off. */
    case LoginAsRevoked = 'loginAsRevoked';
}
