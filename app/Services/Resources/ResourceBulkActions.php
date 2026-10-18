<?php

namespace App\Services\Resources;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Http\RequestContext;
use App\Services\Aliases\AliasAuditTargets;
use App\Services\Groupware\GroupwareCacheFlush;
use App\Support\Bulk\BulkActionRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The legacy mass actions of the resource list (feature 004 FR-058, FR-059): activate, deactivate
 * or delete up to 100 resources, each through the single operation's service with its checks, side
 * effects and audit record, and one groupware cache flush for the whole request.
 */
class ResourceBulkActions
{
    public function __construct(
        private readonly BulkActionRunner $runner,
        private readonly ResourceQuery $query,
        private readonly ResourceUpdater $updater,
        private readonly ResourceDeleter $deleter,
        private readonly ResourceVersion $version,
        private readonly GroupwareCacheFlush $flush,
    ) {}

    /**
     * @param  list<array{id: string, version?: string|null}>  $targets
     * @return array{bulkRequestId: string, results: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function apply(Request $request, Principal $principal, string $action, array $targets): array
    {
        $bulkRequestId = RequestContext::requestId($request);

        return $this->flush->deferred(fn (): array => $this->runner->run(
            $request,
            $principal,
            $action === 'delete' ? AuditEventType::ResourceDeletion : AuditEventType::ResourceUpdate,
            $targets,
            fn (string $id): ?string => $this->query->resolve($principal, $id),
            function (string $address, ?string $ifMatch) use ($principal, $action, $bulkRequestId): ?string {
                if ($action === 'delete') {
                    $this->deleter->delete($principal, $address, $ifMatch, $bulkRequestId);

                    return null;
                }

                $this->updater->update($principal, $address, ['active' => $action === 'activate'], $ifMatch, $bulkRequestId);
                $row = DB::table('mailbox')->where('username', $address)->first();

                return $row === null ? null : $this->version->forRow($row);
            },
            fn (string $id, ?string $address): array => AliasAuditTargets::resourceTarget($address ?? $id),
        ));
    }
}
