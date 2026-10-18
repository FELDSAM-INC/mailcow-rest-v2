<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contract `PublicConfig` (FR-042, FR-056), as built by App\Services\Branding\PublicConfigService.
 *
 * @property array<string, mixed> $resource
 */
class PublicConfigResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
