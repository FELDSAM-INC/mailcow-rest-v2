<?php

namespace App\Http\Resources\Api\V2;

use App\Auth\PendingAction;
use App\Auth\Principal;
use App\Auth\Role;
use App\Services\Identity\IdentityService;
use App\Services\Preferences\PreferencesService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contract `Identity` of a session or API key principal (FR-028). `preferences` only for
 * sessions; `authentication.apiKey` only for keys. Feature 007: `impersonation` during an
 * impersonation (the other members then describe the target, with the impersonator's
 * authentication, pending actions and preferences), and `webmailOnBehalfEnabled` for
 * administrators and domain administrators.
 *
 * @property Principal $resource
 */
class IdentityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $principal = $this->resource;
        $identity = app(IdentityService::class);
        // Feature 007 (FR-048): during an impersonation the authentication is the impersonator's.
        $acting = $principal->actingAccount();
        $authentication = ['method' => $acting->authMethod->value];

        if ($acting->identityProvider !== null) {
            $authentication['identityProvider'] = $acting->identityProvider->value;
        }

        if ($principal->apiKey !== null) {
            $authentication['apiKey'] = [
                'id' => $principal->apiKey->id,
                'name' => $principal->apiKey->name,
                'prefix' => $principal->apiKey->prefix,
                'accessLevel' => $principal->apiKey->accessLevel,
            ];
        }

        $data = [
            'username' => $principal->username,
            'role' => $principal->role->value,
            'authentication' => $authentication,
            'scope' => $identity->scope($principal)->toArray(),
            'permissions' => $identity->permissions($principal),
            'pendingActions' => array_map(fn (PendingAction $a): string => $a->value, $identity->pendingActions($principal)),
        ];

        if (! $principal->isApiKey()) {
            $data['preferences'] = app(PreferencesService::class)->effective($principal);
        }

        if ($principal->impersonator !== null) {
            $data['impersonation'] = ImpersonationResource::present($principal);
        }

        // Feature 007 (FR-056): whether webmail on a user's behalf is allowed on this server.
        if ($principal->role !== Role::MailboxUser) {
            $data['webmailOnBehalfEnabled'] = (bool) config('restv2.allow_admin_email_login');
        }

        return $data;
    }
}
