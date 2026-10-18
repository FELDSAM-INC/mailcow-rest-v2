<?php

namespace App\Services\MtaSts;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Services\Audit\AuditLogger;

/**
 * The audit records of MTA-STS policies (feature 010 research R22), written inside the transaction
 * of the row: a creation with the stored values in contract form, a change with each changed member
 * (`{old, new}`) and the old and new policy identifier.
 */
class MtaStsAudit
{
    public function __construct(private readonly AuditLogger $logger) {}

    /**
     * @param  array{version: string, mode: string, mxHosts: list<string>, maxAge: int, active: bool, policyId: string}  $values
     */
    public function creation(Principal $actor, string $domain, array $values): void
    {
        $this->logger->record(AuditEventType::MtaStsPolicyCreation, true, $actor, MtaStsAuditTargets::target($domain), null, null, $values);
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    public function update(Principal $actor, string $domain, array $changes, string $oldPolicyId, string $newPolicyId): void
    {
        $this->logger->record(AuditEventType::MtaStsPolicyUpdate, true, $actor, MtaStsAuditTargets::target($domain), null, null, [
            'changes' => $changes,
            'oldPolicyId' => $oldPolicyId,
            'newPolicyId' => $newPolicyId,
        ]);
    }
}
