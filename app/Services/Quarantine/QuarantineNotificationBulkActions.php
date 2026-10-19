<?php

namespace App\Services\Quarantine;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Http\RequestContext;
use App\Services\Access\SpamFilterScope;
use App\Support\Bulk\BulkActionRunner;
use Illuminate\Http\Request;

/**
 * Notification settings of several mailboxes at once (feature 006 research R18, FR-037), as the
 * legacy mailbox list's mass actions: each distinct mailbox the caller reaches (resources never)
 * is changed by QuarantineNotificationService with its `version` as `If-Match`, audited with the
 * `bulkRequestId`, and reported with its new version tag.
 */
class QuarantineNotificationBulkActions
{
    public function __construct(
        private readonly BulkActionRunner $runner,
        private readonly QuarantineNotificationService $settings,
        private readonly SpamFilterScope $scope,
    ) {}

    /**
     * @param  array{frequency?: string, category?: string}  $patch
     * @param  list<array{id: string, version: string|null}>  $targets
     * @return array{bulkRequestId: string, results: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function apply(Request $request, Principal $principal, array $patch, array $targets): array
    {
        $bulkRequestId = RequestContext::requestId($request);

        return $this->runner->run(
            $request,
            $principal,
            AuditEventType::QuarantineNotificationSettingsUpdate,
            $targets,
            fn (string $id): ?string => $this->scope->findMailbox($principal, $id),
            fn (string $mailbox, ?string $ifMatch): string => $this->settings->update($principal, $mailbox, $patch, $ifMatch, $bulkRequestId)['etag'],
            fn (string $id, ?string $mailbox): ?array => SpamFilterAuditTargets::mailboxTarget('quarantineNotificationSettings', $mailbox ?? $id),
        );
    }
}
