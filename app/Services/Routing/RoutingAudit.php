<?php

namespace App\Services\Routing;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Services\Audit\AuditLogger;

/**
 * Success records of feature 009 (FR-085, data-model.md, docs/audit-events.md): one record per
 * targeted entry, written inside the transaction of the change so that a failed insert rolls the
 * change back, with the bulk request ID of a bulk item. Passwords appear only as `set`, `changed`
 * or `removed`; every key outside the event type's documented keys is dropped by the logger.
 * Refusals are recorded by the shared RefusalAuditor (targets: RoutingAuditTargets) and by the
 * bulk runner.
 */
class RoutingAudit
{
    public function __construct(private readonly AuditLogger $logger) {}

    /**
     * @param  array{type: string, id: string|null, name: string|null}  $target
     * @param  array<string, mixed>  $details
     */
    public function success(AuditEventType $event, Principal $actor, array $target, array $details, ?string $bulkRequestId = null): void
    {
        if ($bulkRequestId !== null) {
            $details['bulkRequestId'] = $bulkRequestId;
        }

        $this->logger->record($event, true, $actor, $target, null, null, $details);
    }

    /**
     * An audit target, its identifier and name cut to 255 characters.
     *
     * @return array{type: string, id: string|null, name: string|null}
     */
    public static function target(string $type, int|string|null $id, ?string $name): array
    {
        return [
            'type' => $type,
            'id' => $id === null ? null : mb_substr((string) $id, 0, 255),
            'name' => $name === null ? null : mb_substr($name, 0, 255),
        ];
    }

    /**
     * How a stored password changed: `set` (none before), `changed`, `removed`, or null when it
     * did not change.
     */
    public static function passwordChange(string $old, string $new): ?string
    {
        return match (true) {
            $old === $new => null,
            $old === '' => 'set',
            $new === '' => 'removed',
            default => 'changed',
        };
    }
}
