<?php

namespace App\Services\Oauth2;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Http\RequestContext;
use App\Support\Bulk\BulkActionRunner;
use Illuminate\Http\Request;

/**
 * The legacy mass actions of the "OAuth2 Apps" table (FR-060, FR-061, FR-066, research R14):
 * delete, revoke the tokens of or renew the secrets of up to 100 clients, each through the single
 * operation's service method with its checks, effects and audit record (carrying the bulk request's
 * identifier). A renewed secret is shown once, in its item's result (API gap G42).
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

        return $this->runner->run(
            $request,
            $actor,
            self::event($action),
            $targets,
            fn (string $id): ?string => ctype_digit($id) && $this->clients->find((int) $id) !== null ? $id : null,
            function (string $id, ?string $ifMatch) use ($actor, $action, $bulkRequestId): string|array|null {
                switch ($action) {
                    case 'delete':
                        $this->clients->delete((int) $id, $ifMatch, $actor, $bulkRequestId);

                        return null;
                    case 'renewSecret':
                        // G42: each new secret is shown once, in its item's result.
                        $renewed = $this->clients->regenerateSecret((int) $id, $ifMatch, $actor, $bulkRequestId);

                        return ['version' => Oauth2ClientService::etag($renewed['row']), 'clientSecret' => $renewed['secret']];
                    default:
                        $this->clients->revokeTokens((int) $id, $ifMatch, $actor, $bulkRequestId);
                        $row = $this->clients->find((int) $id);

                        return $row === null ? null : Oauth2ClientService::etag($row);
                }
            },
            fn (string $id, ?string $key): array => Oauth2ClientAuditTargetResolver::target($id),
        );
    }

    public static function event(mixed $action): AuditEventType
    {
        return match ($action) {
            'revokeTokens' => AuditEventType::Oauth2ClientTokenRevocation,
            'renewSecret' => AuditEventType::Oauth2ClientSecretRegeneration,
            default => AuditEventType::Oauth2ClientDeletion,
        };
    }
}
