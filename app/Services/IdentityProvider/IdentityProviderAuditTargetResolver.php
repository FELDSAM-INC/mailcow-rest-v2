<?php

namespace App\Services\IdentityProvider;

use App\Audit\AuditEventType;
use App\Auth\IdentityProviderType;
use App\Services\Audit\RefusalAuditor;
use Illuminate\Http\Request;

/**
 * Refusal audit targets of the identity provider routes (research R15): the configuration has no
 * identifier; its name is the stored type, or the requested type when none is stored. A refused
 * whole save is recorded as an update when a configuration is stored, else as a creation.
 */
final class IdentityProviderAuditTargetResolver
{
    public static function register(RefusalAuditor $auditor): void
    {
        $auditor->resolveTargetsWith('identityProvider', self::resolve(...));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{target: array{type: string, id: string|null, name: string|null}, event?: AuditEventType}
     */
    public static function resolve(Request $request, array $config): array
    {
        $stored = app(IdentityProviderRepository::class)->current();
        $requested = is_string($request->input('type')) ? IdentityProviderType::tryFrom($request->input('type')) : null;
        $result = ['target' => ['type' => 'identityProvider', 'id' => null, 'name' => ($stored === null ? $requested : $stored->type)?->value]];

        if (($config['event'] ?? null) === AuditEventType::IdentityProviderCreation->value && $stored !== null) {
            $result['event'] = AuditEventType::IdentityProviderUpdate;
        }

        return $result;
    }
}
