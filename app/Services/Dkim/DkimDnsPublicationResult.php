<?php

namespace App\Services\Dkim;

/**
 * One publication attempt: the outcome, the record name relative to the zone, and for `failed`
 * the reason code (contract `DkimDnsPublicationReasonCode`) and a detail without secrets.
 */
final readonly class DkimDnsPublicationResult
{
    public function __construct(
        public DkimDnsOutcome $outcome,
        public ?string $recordName,
        public ?string $reasonCode = null,
        public ?string $detail = null,
    ) {}

    /**
     * The contract's `DkimDnsPublicationOutcome`.
     *
     * @return array{status: string, recordName: string|null, reason: array{code: string, detail: string|null}|null}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->outcome->value,
            'recordName' => $this->recordName,
            'reason' => $this->reasonCode === null ? null : ['code' => $this->reasonCode, 'detail' => $this->detail],
        ];
    }

    /**
     * The audit form of research R22: `{status, reasonCode, detail}`.
     *
     * @return array{status: string, reasonCode: string|null, detail: string|null}
     */
    public function toAudit(): array
    {
        return ['status' => $this->outcome->value, 'reasonCode' => $this->reasonCode, 'detail' => $this->detail];
    }
}
