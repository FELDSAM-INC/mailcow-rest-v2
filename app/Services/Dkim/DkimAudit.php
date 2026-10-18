<?php

namespace App\Services\Dkim;

use App\Audit\AuditEventType;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;

/**
 * The audit records of DKIM key changes (feature 010 research R22). Details hold only the
 * whitelisted keys: selectors, key lengths, public key fingerprints, the trigger, the source of a
 * copy and the DNS publication outcome. No private key material or ISPConfig password ever enters
 * them.
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
        return $this->keyChange(AuditEventType::DkimKeyGeneration, $context, $name, $selector, $publicKey, $publication, $overwriteRequested, $old, [
            'trigger' => $context->trigger ?? 'api',
        ]);
    }

    /**
     * The record of an imported key.
     */
    public function import(AuditContext $context, string $name, string $selector, string $publicKey, DkimDnsPublicationResult $publication, bool $overwriteRequested, ?StoredDkimKey $old): string
    {
        return $this->keyChange(AuditEventType::DkimKeyImport, $context, $name, $selector, $publicKey, $publication, $overwriteRequested, $old);
    }

    /**
     * The record of a key copied onto a target, with the source's name.
     */
    public function copy(AuditContext $context, string $name, string $selector, string $publicKey, DkimDnsPublicationResult $publication, bool $overwriteRequested, ?StoredDkimKey $old, string $sourceName): string
    {
        return $this->keyChange(AuditEventType::DkimKeyCopy, $context, $name, $selector, $publicKey, $publication, $overwriteRequested, $old, [
            'sourceName' => mb_substr($sourceName, 0, 255),
        ]);
    }

    /**
     * The record of a deleted key: the removed key and the outcome of removing its record.
     */
    public function deletion(AuditContext $context, string $name, StoredDkimKey $old, DkimDnsPublicationResult $publication): string
    {
        $details = ['old' => self::describe($old->selector, $old->publicKey), 'dnsPublication' => $publication->toAudit()];

        return $this->record(AuditEventType::DkimKeyDeletion, $context, $name, $details);
    }

    /**
     * The record of a private key export (never the key itself).
     */
    public function export(AuditContext $context, string $name, string $selector, ?string $publicKey): string
    {
        $key = $publicKey === null ? null : new DkimPublicKey($publicKey);

        return $this->record(AuditEventType::DkimPrivateKeyExport, $context, $name, ['selector' => $selector, 'publicKeySha256' => $key?->sha256Fingerprint()]);
    }

    /**
     * The record of a record published again.
     */
    public function publication(AuditContext $context, string $name, string $selector, DkimDnsPublicationResult $publication): string
    {
        return $this->record(AuditEventType::DkimDnsPublication, $context, $name, ['selector' => $selector, 'dnsPublication' => $publication->toAudit()]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function keyChange(
        AuditEventType $event,
        AuditContext $context,
        string $name,
        string $selector,
        string $publicKey,
        DkimDnsPublicationResult $publication,
        bool $overwriteRequested,
        ?StoredDkimKey $old,
        array $extra = [],
    ): string {
        $key = new DkimPublicKey($publicKey);

        $details = [
            'selector' => $selector,
            'keyLength' => $key->bits(),
            'overwriteRequested' => $overwriteRequested,
            'new' => ['selector' => $selector, 'keyLength' => $key->bits(), 'publicKeySha256' => $key->sha256Fingerprint()],
            ...$extra,
            'dnsPublication' => $publication->toAudit(),
        ];

        if ($old !== null) {
            $details['old'] = self::describe($old->selector, $old->publicKey);
        }

        return $this->record($event, $context, $name, $details);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function record(AuditEventType $event, AuditContext $context, string $name, array $details): string
    {
        if ($context->bulkRequestId !== null) {
            $details['bulkRequestId'] = $context->bulkRequestId;
        }

        return $this->logger->record($event, true, $context->actor, DkimAuditTargets::target($name), null, null, $details)->id;
    }

    /**
     * @return array{selector: string|null, keyLength: int|null, publicKeySha256: string|null}
     */
    private static function describe(?string $selector, ?string $publicKey): array
    {
        $key = $publicKey === null ? null : new DkimPublicKey($publicKey);

        return ['selector' => $selector, 'keyLength' => $key?->bits(), 'publicKeySha256' => $key?->sha256Fingerprint()];
    }
}
