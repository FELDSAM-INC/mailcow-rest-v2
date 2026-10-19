<?php

namespace App\Services\Accounts;

use App\Audit\AuditEventType;
use App\Auth\Role;

/**
 * The bulk actions of the administrator and domain administrator lists (feature 007 FR-075,
 * contract `AccountBulkActionRequest`), legacy's mass actions.
 */
enum AccountBulkAction: string
{
    case Activate = 'activate';
    case Deactivate = 'deactivate';
    case SuspendSecondFactors = 'suspendSecondFactors';
    case Delete = 'delete';

    /**
     * The audit event of the single operation each item runs.
     */
    public function auditEvent(Role $role): AuditEventType
    {
        $admin = $role === Role::Admin;

        return match ($this) {
            self::Activate, self::Deactivate => $admin ? AuditEventType::AdminUpdate : AuditEventType::DomainAdminUpdate,
            self::SuspendSecondFactors => $admin ? AuditEventType::AdminSecondFactorSuspension : AuditEventType::DomainAdminSecondFactorSuspension,
            self::Delete => $admin ? AuditEventType::AdminDeletion : AuditEventType::DomainAdminDeletion,
        };
    }
}
