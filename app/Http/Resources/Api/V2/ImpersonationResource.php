<?php

namespace App\Http\Resources\Api\V2;

use App\Auth\Principal;
use App\Exceptions\ProblemException;
use App\Support\Http\ProblemType;
use Carbon\CarbonImmutable;

/**
 * Contract `Impersonation` (feature 007 FR-048): the impersonation of an impersonating principal,
 * with the impersonator, its sign-in method, the target and the start time.
 */
final class ImpersonationResource
{
    /**
     * @return array{id: string, impersonator: array{username: string, role: string, authMethod: string}, target: array{username: string, role: string}, startedAt: string}
     */
    public static function present(Principal $impersonating): array
    {
        $impersonator = $impersonating->impersonator ?? throw new ProblemException(ProblemType::Forbidden);

        return [
            'id' => (string) $impersonating->impersonationId,
            'impersonator' => [
                'username' => $impersonator->username,
                'role' => $impersonator->role->value,
                'authMethod' => $impersonator->authMethod->value,
            ],
            'target' => ['username' => $impersonating->username, 'role' => $impersonating->role->value],
            'startedAt' => ($impersonating->impersonationStartedAt ?? CarbonImmutable::now('UTC'))->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
