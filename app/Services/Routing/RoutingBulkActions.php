<?php

namespace App\Services\Routing;

use App\Auth\Principal;
use App\Http\RequestContext;
use App\Support\Bulk\BulkActionRunner;
use Illuminate\Http\Request;

/**
 * The legacy mass actions of feature 009 (FR-090, FR-091, research R20): up to 100 objects of one
 * collection, each through its service's item handler (RoutingBulkHandler) with the single
 * operation's checks, its own transaction and its own audit record carrying the request ID, on the
 * shared App\Support\Bulk\BulkActionRunner (outcomes `done`, `notFound`, `refused`, `outdated`,
 * `failed`; one failure never stops the others).
 */
class RoutingBulkActions
{
    public function __construct(private readonly BulkActionRunner $runner) {}

    /**
     * @param  list<array{id: string, version?: string|null}>  $targets
     * @return array{data: array{bulkRequestId: string, results: list<array<string, mixed>>, summary: array<string, int>}}
     */
    public function apply(Request $request, Principal $principal, RoutingBulkHandler $handler, string $action, array $targets): array
    {
        $bulkRequestId = RequestContext::requestId($request);

        return ['data' => $this->runner->run(
            $request,
            $principal,
            $handler->bulkEvent($action),
            $targets,
            fn (string $id): ?string => $handler->bulkResolve($principal, $id),
            fn (string $key, ?string $ifMatch): ?string => $handler->bulkApply($principal, $key, $action, $ifMatch, $bulkRequestId),
            fn (string $id, ?string $key): array => $handler->bulkAuditTarget($id, $key),
        )];
    }
}
