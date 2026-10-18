<?php

namespace App\Services\Routing;

use App\Audit\AuditEventType;
use App\Auth\Principal;

/**
 * The item handler of one routing collection for RoutingBulkActions: the same checks, writes and
 * audit record as the single operation (FR-091).
 */
interface RoutingBulkHandler
{
    /** The audit event of an action's items. */
    public function bulkEvent(string $action): AuditEventType;

    /** The object's key for the caller, or null when it does not exist for them (`notFound`). */
    public function bulkResolve(Principal $principal, string $id): ?string;

    /**
     * Applies the action to one object; returns its new version, or null when it is gone.
     */
    public function bulkApply(Principal $principal, string $key, string $action, ?string $ifMatch, string $bulkRequestId): ?string;

    /**
     * @return array{type: string, id: string|null, name: string|null}
     */
    public function bulkAuditTarget(string $id, ?string $key): array;
}
