<?php

namespace App\Services\Oauth2;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Http\RequestContext;
use App\Support\Bulk\BulkActionRunner;
use Illuminate\Http\Request;

/**
 * The legacy mass actions of the "OAuth2 Apps" table (FR-060, FR-061, research R14): delete or
 * revoke the tokens of up to 100 clients, each through the single operation's service method with
 * its checks, effects and audit record (carrying the bulk request's identifier). Secret
 * regeneration has no bulk form.
 */
class Oauth2ClientBulkActions
{
    public function __construct(
        private readonly BulkActionRunner $runner,
        private readonly Oauth2ClientService $clients,
    ) {}

    /**
     * @param  list<array{id: string, version?: string|null}>  $targets
     * @return array{bulkRequestId: string, results: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function apply(Request $request, Principal $actor, string $action, array $targets): array
    {
        $bulkRequestId = RequestContext::requestId($request);
        $delete = $action === 'delete';

        return $this->runner->run(
            $request,
            $actor,
            $delete ? AuditEventType::Oauth2ClientDeletion : AuditEventType::Oauth2ClientTokenRevocation,
            $targets,
            fn (string $id): ?string => ctype_digit($id) && $this->clients->find((int) $id) !== null ? $id : null,
            function (string $id, ?string $ifMatch) use ($actor, $delete, $bulkRequestId): ?string {
                if ($delete) {
                    $this->clients->delete((int) $id, $ifMatch, $actor, $bulkRequestId);

                    return null;
                }

                $this->clients->revokeTokens((int) $id, $ifMatch, $actor, $bulkRequestId);
                $row = $this->clients->find((int) $id);

                return $row === null ? null : Oauth2ClientService::etag($row);
            },
            fn (string $id, ?string $key): array => Oauth2ClientAuditTargetResolver::target($id),
        );
    }
}
