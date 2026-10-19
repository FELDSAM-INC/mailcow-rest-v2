<?php

namespace App\Policies;

use App\Auth\PermissionFlag;
use App\Auth\Principal;
use App\Exceptions\PermissionFlagRequiredException;
use App\Services\Identity\IdentityService;

/**
 * The permission flags of feature 006's policies (FR-005), from 001's effective flags (legacy
 * acl('to_session')), evaluated from mailcow's current data on every call (FR-002): a mailbox user
 * has its `user_acl` row; a domain administrator its own `da_acl` flags `quarantine`, `spam_policy`
 * and `ratelimit` and always the user-only flags; an administrator every flag. The flags of the
 * mailbox acted on never restrict administrators or domain administrators.
 */
abstract class SpamFilterFlags
{
    public function __construct(protected readonly IdentityService $identity) {}

    public function has(Principal $principal, PermissionFlag $flag): bool
    {
        return $this->identity->permissions($principal)[$flag->value] ?? false;
    }

    /**
     * Refuses unless every flag is held, naming the first missing one (the whole-message download
     * names `quarantine` before `quarantineAttachments`, spec clarification).
     *
     * @param  list<PermissionFlag>  $flags
     */
    protected function require(Principal $principal, array $flags, string $detail): void
    {
        $granted = $this->identity->permissions($principal);

        foreach ($flags as $flag) {
            if (! ($granted[$flag->value] ?? false)) {
                throw new PermissionFlagRequiredException([$flag], 'all', $detail);
            }
        }
    }

    public function allows(Principal $principal, string $ability): bool
    {
        try {
            $this->authorize($principal, $ability);

            return true;
        } catch (\App\Exceptions\ProblemException) {
            return false;
        }
    }

    abstract public function authorize(Principal $principal, string $ability): void;
}
