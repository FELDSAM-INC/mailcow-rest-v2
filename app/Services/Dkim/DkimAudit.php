<?php

namespace App\Services\Dkim;

use App\Audit\AuditEventType;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Support\Domain\DomainName;

/**
 * The audit records of DKIM key changes (feature 010 research R22). Details hold only the
 * whitelisted keys: selectors, key lengths, public key fingerprints, the trigger and the DNS
 * publication outcome. No private key material or ISPConfig password ever enters them.
 *
 * Refusals (4xx and the shared store's 503) of DKIM routes are recorded by the shared
 * App\Services\Audit\RefusalAuditor.
 */
class DkimAudit
{
    public function __construct(private readonly AuditLogger $logger) {}

    /**
     * The record of a generated key (trigger `api`, `domainCreation` or `aliasDomainCreation`).
     */
    public function generation(
        AuditContext $context,
        string $name,
        string $selector,
        string $publicKey,
        DkimDnsPublicationResult $publication,
        bool $overwriteRequested = false,
        ?StoredDkimKey $old = null,
    ): string {
        $key = new DkimPublicKey($publicKey);

        $details = [
            'selector' => $selector,
            'keyLength' => $key->bits(),
            'overwriteRequested' => $overwriteRequested,
            'new' => ['selector' => $selector, 'keyLength' => $key->bits(), 'publicKeySha256' => $key->sha256Fingerprint()],
            'trigger' => $context->trigger ?? 'api',
            'dnsPublication' => $publication->toAudit(),
        ];

        if ($old !== null) {
            $oldKey = $old->publicKey === null ? null : new DkimPublicKey($old->publicKey);
            $details['old'] = ['selector' => $old->selector, 'keyLength' => $oldKey?->bits(), 'publicKeySha256' => $oldKey?->sha256Fingerprint()];
        }

        if ($context->bulkRequestId !== null) {
            $details['bulkRequestId'] = $context->bulkRequestId;
        }

        return $this->logger->record(
            AuditEventType::DkimKeyGeneration,
            true,
            $context->actor,
            ['type' => 'dkimKey', 'id' => $name, 'name' => DomainName::toUnicode($name)],
            null,
            null,
            $details,
        )->id;
    }
}
