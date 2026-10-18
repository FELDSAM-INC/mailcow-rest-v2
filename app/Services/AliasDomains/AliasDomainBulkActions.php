<?php

namespace App\Services\AliasDomains;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Http\RequestContext;
use App\Services\Domains\DomainAuditTargets;
use App\Services\Groupware\SogoDirectory;
use App\Support\Bulk\BulkActionRunner;
use Illuminate\Http\Request;

/**
 * The legacy mass actions of the alias domain list (feature 004 FR-058, FR-059, research R16):
 * activate, deactivate or delete up to 100 alias domains, each through the single operation's
 * service with its checks, side effects and audit record, and one groupware rebuild for the whole
 * request (002 D20). A shared-store failure fails only its target.
 */
class AliasDomainBulkActions
{
    public function __construct(
        private readonly BulkActionRunner $runner,
        private readonly AliasDomainQuery $query,
        private readonly AliasDomainUpdater $updater,
        private readonly AliasDomainDeleter $deleter,
        private readonly AliasDomainVersion $version,
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
            $action === 'delete' ? AuditEventType::AliasDomainDeletion : AuditEventType::AliasDomainUpdate,
            $targets,
            fn (string $id): ?string => $this->query->resolve($principal, $id),
            function (string $name, ?string $ifMatch) use ($principal, $action, $bulkRequestId, &$changed): ?string {
                if ($action === 'delete') {
                    $this->deleter->delete($principal, $name, $ifMatch, $bulkRequestId, true);
                    $changed = true;

                    return null;
                }

                $changed = $this->updater->update($principal, $name, ['active' => $action === 'activate'], $ifMatch, $bulkRequestId, true) || $changed;

                return $this->version->of($name);
            },
            fn (string $id, ?string $name): ?array => DomainAuditTargets::named('aliasDomain', $name ?? $id),
        );

        if ($changed) {
            $this->sogo->rebuild();
        }

        return $result;
    }
}
