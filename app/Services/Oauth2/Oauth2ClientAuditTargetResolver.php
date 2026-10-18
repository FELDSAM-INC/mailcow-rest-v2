<?php

namespace App\Services\Oauth2;

use App\Audit\AuditEventType;
use App\Services\Audit\RefusalAuditor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Refusal audit targets of the OAuth2 client routes (research R15): the route's `oauth2ClientId`
 * with its client ID, looked up without scope; a refused creation has no identifier. A refused bulk
 * request is recorded once with a null target, the requested `targetIds` and the `bulkAction`
 * (feature 002's keys; the plan named them `itemIds`), its event following the action.
 */
final class Oauth2ClientAuditTargetResolver
{
    public static function register(RefusalAuditor $auditor): void
    {
        $auditor->resolveTargetsWith('oauth2Client', self::resolve(...));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{target?: array{type: string, id: string|null, name: string|null}|null, details?: array<string, mixed>, event?: AuditEventType}
     */
    public static function resolve(Request $request, array $config): array
    {
        if (($config['event'] ?? null) === 'fromBulkAction') {
            $action = $request->input('action');
            $targets = $request->input('targets');
            $ids = [];

            foreach (is_array($targets) ? array_slice($targets, 0, 100) : [] as $target) {
                if (is_array($target) && is_string($target['id'] ?? null)) {
                    $ids[] = mb_substr($target['id'], 0, 255);
                }
            }

            return [
                'event' => $action === 'revokeTokens' ? AuditEventType::Oauth2ClientTokenRevocation : AuditEventType::Oauth2ClientDeletion,
                'target' => null,
                'details' => ['targetIds' => $ids, 'bulkAction' => is_string($action) ? mb_substr($action, 0, 32) : null],
            ];
        }

        $id = $request->route('oauth2ClientId');

        return ['target' => is_string($id) && $id !== '' ? self::target($id) : ['type' => 'oauth2Client', 'id' => null, 'name' => null]];
    }

    /**
     * @return array{type: string, id: string, name: string|null}
     */
    public static function target(string $id): array
    {
        $clientId = ctype_digit($id) ? DB::table('oauth_clients')->where('id', (int) $id)->value('client_id') : null;

        return ['type' => 'oauth2Client', 'id' => mb_substr($id, 0, 255), 'name' => is_string($clientId) ? $clientId : null];
    }
}
