<?php

namespace App\Auth;

/**
 * State of a browser session (contract `SessionState`), derived from the session data.
 */
enum SessionState: string
{
    case Anonymous = 'anonymous';
    case PendingSecondFactor = 'pendingSecondFactor';
    case Restricted = 'restricted';
    case Active = 'active';
}
