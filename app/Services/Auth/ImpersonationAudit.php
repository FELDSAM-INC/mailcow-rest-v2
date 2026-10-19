<?php

namespace App\Services\Auth;

use App\Audit\AuditEventType;
use App\Auth\ImpersonationEndReason;
use App\Auth\Principal;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The audit records of impersonations (feature 007 FR-067, research R22): its start and every end,
 * written for the impersonating principal, so that the impersonator is the actor and the target the
 * impersonated account. The target is the impersonation (`id` its ULID, `name` the target).
 */
class ImpersonationAudit
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function started(Principal $impersonating): void
    {
        $this->audit->record(AuditEventType::ImpersonationStart, true, $impersonating, self::target($impersonating), $impersonating->authMethod->value, null, [
            'targetRole' => $impersonating->role->value,
        ]);
    }

    /**
     * @param  string  $endedBy  `explicit`, `signOut`, `automatic` or `sessionReplaced`
     */
    public function ended(Principal $impersonating, string $endedBy, ?ImpersonationEndReason $reason = null): void
    {
        $details = ['endedBy' => $endedBy];

        if ($reason !== null) {
            $details['endReason'] = $reason->value;
        }

        $started = $impersonating->impersonationStartedAt;
        $details['durationSeconds'] = $started === null ? 0 : max(0, CarbonImmutable::now()->getTimestamp() - $started->getTimestamp());

        DB::transaction(fn () => $this->audit->record(AuditEventType::ImpersonationEnd, true, $impersonating, self::target($impersonating), $impersonating->authMethod->value, null, $details));
    }

    /**
     * @return array{type: string, id: string|null, name: string}
     */
    private static function target(Principal $impersonating): array
    {
        return ['type' => 'impersonation', 'id' => $impersonating->impersonationId, 'name' => $impersonating->username];
    }
}
