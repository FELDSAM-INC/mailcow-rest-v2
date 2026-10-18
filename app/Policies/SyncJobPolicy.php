<?php

namespace App\Policies;

use App\Auth\PermissionFlag;
use App\Auth\Principal;
use App\Exceptions\PermissionFlagRequiredException;
use App\Services\Identity\IdentityService;

/**
 * The sync-jobs permission gate (feature 008 FR-001, research R2), the gate `syncJobs.use`: the
 * effective `syncjobs` flag of legacy acl('to_session'), which administrators always hold, domain
 * administrators through `da_acl.syncjobs` and mailbox users through `user_acl.syncjobs`. Every
 * sync-job operation checks it first, before any object is looked up, so that a refusal reveals
 * nothing. A key acts with its owner's flag. Evaluated from mailcow's data on every call.
 */
class SyncJobPolicy
{
    public function __construct(private readonly IdentityService $identity) {}

    public function use(Principal $principal): bool
    {
        return $this->identity->permissions($principal)[PermissionFlag::Syncjobs->value] ?? false;
    }

    /**
     * @throws PermissionFlagRequiredException `403 permission-flag-required` with `requiredFlags: [syncjobs]`
     */
    public function authorize(Principal $principal): void
    {
        if (! $this->use($principal)) {
            throw new PermissionFlagRequiredException([PermissionFlag::Syncjobs], detail: 'Sync jobs need the syncjobs permission.');
        }
    }
}
