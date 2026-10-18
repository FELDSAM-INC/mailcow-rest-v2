<?php

namespace App\Services\Domains;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Http\RequestContext;
use App\Services\Groupware\SogoDirectory;
use App\Support\Bulk\BulkActionRunner;
use Illuminate\Http\Request;

/**
 * The legacy mass actions of the domain list (FR-062, FR-063, research D20): activate, deactivate
 * or delete up to 100 domains, each through the single operation's service (DomainUpdater or
 * DomainDeleter) with its checks, side effects and audit record, and one groupware rebuild for the
 * whole request.
 */
class DomainBulkActions
{
    public function __construct(
        private readonly BulkActionRunner $runner,
        private readonly DomainScope $scope,
        private readonly DomainUpdater $updater,
        private readonly DomainDeleter $deleter,
        private readonly DomainVersion $version,
        private readonly SogoDirectory $sogo,
    ) {}

    /**
     * @param  list<array{id: string, version?: string|null}>  $targets
     * @return array{bulkRequestId: string, results: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function apply(Request $request, Principal $principal, string $action, array $targets): array
    {
        $changed = false;
        $bulkRequestId = RequestContext::requestId($request);

        $result = $this->runner->run(
            $request,
            $principal,
            $action === 'delete' ? AuditEventType::DomainDeletion : AuditEventType::DomainUpdate,
            $targets,
            fn (string $id): ?string => $this->scope->resolve($principal, $id),
            function (string $name, ?string $ifMatch) use ($principal, $action, $bulkRequestId, &$changed): ?string {
                if ($action === 'delete') {
                    $this->deleter->delete($principal, $name, $ifMatch, $bulkRequestId, true);
                    $changed = true;

                    return null;
                }

                $changed = $this->updater->update($principal, $name, ['active' => $action === 'activate'], $ifMatch, $bulkRequestId, true) || $changed;

                return $this->version->ofDomain($name);
            },
            fn (string $id, ?string $name): ?array => DomainAuditTargets::named('domain', $name ?? $id),
        );

        if ($changed) {
            $this->sogo->rebuild();
        }

        return $result;
    }
}
