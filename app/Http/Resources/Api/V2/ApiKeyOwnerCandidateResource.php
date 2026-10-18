<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use stdClass;

/**
 * Contract `ApiKeyOwnerCandidate`, from an `admin` row (`username`, `superadmin`).
 *
 * @property stdClass $resource
 */
class ApiKeyOwnerCandidateResource extends JsonResource
{
    /**
     * @return array{username: string, role: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'username' => (string) $this->resource->username,
            'role' => (int) $this->resource->superadmin === 1 ? 'admin' : 'domainAdmin',
        ];
    }
}
