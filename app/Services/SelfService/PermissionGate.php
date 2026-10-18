<?php

namespace App\Services\SelfService;

use App\Auth\PermissionFlag;
use App\Auth\Principal;
use App\Exceptions\PermissionFlagRequiredException;
use App\Services\Identity\IdentityService;

/**
 * The permission flags that gate self-service changes (feature 005 FR-006), evaluated from
 * mailcow's current data on every call through 001's effective flags (legacy acl('to_session')):
 *
 *  - a mailbox user acts with their own `user_acl` flags;
 *  - a domain administrator with every user flag set, except `appPasswds` and `pushover`, which come
 *    from their own `da_acl` flags, and `filters` (their own `da_acl` flag);
 *  - an administrator with every flag set.
 *
 * Reads never need a flag, and a value sent with its current value is not a change.
 */
class PermissionGate
{
    public function __construct(private readonly IdentityService $identity) {}

    public function has(Principal $principal, PermissionFlag $flag): bool
    {
        return $this->identity->permissions($principal)[$flag->value] ?? false;
    }

    /**
     * Refuses a change that needs a flag the caller lacks; nothing happens when $changes is false.
     *
     * @throws PermissionFlagRequiredException `403 permission-flag-required` naming the flag
     */
    public function requireChange(Principal $principal, PermissionFlag $flag, bool $changes = true): void
    {
        if ($changes && ! $this->has($principal, $flag)) {
            throw new PermissionFlagRequiredException([$flag]);
        }
    }
}
