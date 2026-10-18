<?php

namespace App\Services\SyncJobs;

use App\Auth\Principal;
use App\Http\RequestContext;
use App\Support\Bulk\BulkActionRunner;
use Illuminate\Http\Request;

/**
 * The legacy mass actions of the sync-job tables (feature 008 FR-023, FR-024, research R15):
 * activate, deactivate, reschedule or delete up to the bulk limit of jobs, through 002's
 * BulkActionRunner. Every target is resolved in the caller's scope and deduplicated first; each job
 * then goes through the single operation's service method, with its checks, its own transaction and
 * its audit record carrying the request ID. An item's `version` is its `If-Match`, except for
 * `reschedule`, which changes only run status. A `done` item carries the job's new version tag (its
 * `ETag`), except after `delete`.
 */
class SyncJobBulkService
{
    public function __construct(
        private readonly BulkActionRunner $runner,
        private readonly SyncJobScope $scope,
        private readonly SyncJobService $service,
        private readonly SyncJobVersion $version,
    ) {}

    /**
     * @param  list<array{id: string, version?: string|null}>  $targets
     * @return array{bulkRequestId: string, results: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function apply(Request $request, Principal $principal, SyncJobBulkAction $action, array $targets): array
    {
        $bulkRequestId = RequestContext::requestId($request);

        return $this->runner->run(
            $request,
            $principal,
            $action->auditEvent(),
            $targets,
            fn (string $id): ?string => $this->scope->findInScope((int) $id, $principal) === null ? null : (string) (int) $id,
            function (string $key, ?string $ifMatch) use ($principal, $action, $bulkRequestId): ?string {
                $id = (int) $key;
                $ifMatch = $action->checksVersion() ? $ifMatch : null;

                if ($action === SyncJobBulkAction::Delete) {
                    $this->service->delete($principal, $id, $ifMatch, $bulkRequestId);

                    return null;
                }

                $job = $action === SyncJobBulkAction::Reschedule
                    ? $this->service->reschedule($principal, $id, $bulkRequestId)
                    : $this->service->update($principal, $id, ['active' => $action === SyncJobBulkAction::Activate], $ifMatch, $bulkRequestId);

                return $this->version->of($job);
            },
            fn (string $id, ?string $key): array => SyncJobAuditTargets::target($key ?? $id),
        );
    }
}
