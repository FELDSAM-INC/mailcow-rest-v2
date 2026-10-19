<?php

namespace App\Services\Accounts;

use App\Auth\Principal;
use App\Auth\Role;
use App\Http\RequestContext;
use App\Rules\AccountUsername;
use App\Support\Bulk\BulkActionRunner;
use Illuminate\Http\Request;

/**
 * Bulk actions on administrators or domain administrators (feature 007 FR-075, FR-076, research
 * R15), through feature 002's BulkActionRunner: targets are normalised (trimmed, lower-cased),
 * resolved and de-duplicated in request order, then each account runs the single operation for it
 * (the status change of `PATCH`, the second-factor suspension or the deletion) in its own
 * transaction, with the same checks, side effects and audit record carrying `bulkRequestId`. The
 * whole request is refused only by the route's pipeline and the policy.
 */
class AccountBulkActionRunner
{
    public function __construct(private readonly BulkActionRunner $runner) {}

    /**
     * @param  list<array{id: string, version?: string|null}>  $targets
     * @return array{bulkRequestId: string, results: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function run(Request $request, Principal $actor, Role $role, AccountBulkAction $action, array $targets): array
    {
        $service = $role === Role::Admin ? app(AdminAccountService::class) : app(DomainAdminAccountService::class);
        // The runner reports the request ID as the bulk request's identifier.
        $bulkRequestId = RequestContext::requestId($request);

        return $this->runner->run(
            $request,
            $actor,
            $action->auditEvent($role),
            $targets,
            fn (string $id): ?string => $service->resolve(AccountUsername::normalize($id)),
            function (string $username, ?string $ifMatch) use ($service, $actor, $action, $bulkRequestId): ?string {
                match ($action) {
                    AccountBulkAction::Activate, AccountBulkAction::Deactivate => $service->update($actor, $username, ['active' => $action === AccountBulkAction::Activate], $ifMatch, null, $bulkRequestId),
                    AccountBulkAction::SuspendSecondFactors => $service->suspendSecondFactors($actor, $username, $ifMatch, $bulkRequestId),
                    AccountBulkAction::Delete => $service->delete($actor, $username, $ifMatch, $bulkRequestId),
                };

                return $action === AccountBulkAction::Delete ? null : $service->version($username);
            },
            fn (string $id, ?string $username): array => $service->target($username ?? mb_substr(AccountUsername::normalize($id), 0, 255)),
        );
    }
}
