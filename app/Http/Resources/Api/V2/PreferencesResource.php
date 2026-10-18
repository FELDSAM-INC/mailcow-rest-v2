<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contract `Preferences`: the effective language and theme with the version tag.
 *
 * @property array{language: string, theme: string, etag: string} $resource
 */
class PreferencesResource extends JsonResource
{
    /**
     * @return array{language: string, theme: string, etag: string}
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }

    /**
     * The single-resource response with its `ETag`.
     */
    public function withETag(): JsonResponse
    {
        return $this->response()->header('ETag', $this->resource['etag']);
    }
}
