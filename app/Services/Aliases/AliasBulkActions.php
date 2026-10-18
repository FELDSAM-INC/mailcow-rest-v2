<?php

namespace App\Services\Aliases;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Http\RequestContext;
use App\Services\Groupware\GroupwareCacheFlush;
use App\Support\Bulk\BulkActionRunner;
use Illuminate\Http\Request;

/**
 * The legacy mass actions of the alias list (feature 004 FR-036, FR-037, FR-058, FR-059, research
 * R16): activate, deactivate, show or hide in the groupware, delete, or copy over alias domains up
 * to 100 aliases, each through the single operation's service (AliasUpdater, AliasDeleter,
 * AliasCopier) with its checks, side effects and audit record carrying the request ID. The
 * groupware cache is flushed once for the whole request. A copy's per-alias-domain outcomes are
 * returned in `meta.copies`, keyed by the identifier as sent, for the aliases that were done.
 */
class AliasBulkActions
{
    private const FLAGS = [
        'activate' => ['active', true],
        'deactivate' => ['active', false],
        'showInGroupware' => ['groupwareVisible', true],
        'hideInGroupware' => ['groupwareVisible', false],
    ];

    public function __construct(
        private readonly BulkActionRunner $runner,
        private readonly AliasQuery $query,
        private readonly AliasUpdater $updater,
        private readonly AliasDeleter $deleter,
        private readonly AliasCopier $copier,
        private readonly AliasVersion $version,
        private readonly GroupwareCacheFlush $flush,
    ) {}

    /**
     * @param  list<array{id: string, version?: string|null}>  $targets
     * @return array{data: array<string, mixed>, meta?: array{copies: object}}
     */
    public function apply(Request $request, Principal $principal, string $action, array $targets): array
    {
        $bulkRequestId = RequestContext::requestId($request);
        $sentAs = [];
        $copies = [];

        $result = $this->flush->deferred(function () use ($request, $principal, $action, $targets, $bulkRequestId, &$sentAs, &$copies): array {
            return $this->runner->run(
                $request,
                $principal,
                match ($action) {
                    'delete' => AuditEventType::AliasDeletion,
                    'copyOverAliasDomains' => AuditEventType::AliasCreation,
                    default => AuditEventType::AliasUpdate,
                },
                $targets,
                function (string $id) use ($principal, &$sentAs): ?string {
                    $resolved = $this->query->resolve($principal, $id);

                    if ($resolved !== null) {
                        $sentAs[$resolved] ??= $id;
                    }

                    return $resolved === null ? null : (string) $resolved;
                },
                function (string $key, ?string $ifMatch) use ($principal, $action, $bulkRequestId, &$sentAs, &$copies): ?string {
                    $id = (int) $key;

                    if ($action === 'delete') {
                        $this->deleter->delete($principal, $id, $ifMatch, $bulkRequestId);

                        return null;
                    }

                    if ($action === 'copyOverAliasDomains') {
                        $copies[$sentAs[$id] ?? $key] = $this->copier->copy($principal, $id, $ifMatch, $bulkRequestId)['copies'];
                    } else {
                        [$flag, $value] = self::FLAGS[$action];
                        $this->updater->update($principal, $id, [$flag => $value], $ifMatch, $bulkRequestId);
                    }

                    return $this->version->of($id);
                },
                fn (string $id, ?string $key): array => AliasAuditTargets::aliasTarget($key ?? $id),
            );
        });

        return $action === 'copyOverAliasDomains' ? ['data' => $result, 'meta' => ['copies' => (object) $copies]] : ['data' => $result];
    }
}
