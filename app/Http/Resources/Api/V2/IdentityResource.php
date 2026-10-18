<?php

namespace App\Http\Resources\Api\V2;

use App\Auth\PendingAction;
use App\Auth\Principal;
use App\Services\Identity\IdentityService;
use App\Services\Preferences\PreferencesService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contract `Identity` of a session or API key principal (FR-028). `preferences` only for
 * sessions; `authentication.apiKey` only for keys.
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
        $authentication = ['method' => $principal->authMethod->value];

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

        return $data;
    }
}
