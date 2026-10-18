<?php

namespace App\Services\SyncJobs;

use App\Audit\AuditEventType;

/**
 * The legacy mass actions of the sync-job tables (contract `SyncJobBulkAction`, FR-023).
 */
enum SyncJobBulkAction: string
{
    case Activate = 'activate';
    case Deactivate = 'deactivate';
    case Reschedule = 'reschedule';
    case Delete = 'delete';

    /** The audit event of each item (and of a refused request). */
    public function auditEvent(): AuditEventType
    {
        return match ($this) {
            self::Activate, self::Deactivate => AuditEventType::SyncJobUpdate,
            self::Reschedule => AuditEventType::SyncJobReschedule,
            self::Delete => AuditEventType::SyncJobDeletion,
        };
    }

    /**
     * Whether an item's version tag is checked: rescheduling changes only run status (FR-021).
     */
    public function checksVersion(): bool
    {
        return $this !== self::Reschedule;
    }
}
