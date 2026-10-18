<?php

namespace App\Http\Resources\Api\V2;

use App\Models\Mailcow\Fido2Credential;
use App\Services\Passkeys\PasskeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contract `Passkey`, from a `fido2` row selected by PasskeyService (with `cid`).
 *
 * @property Fido2Credential $resource
 */
class PasskeyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $passkey = $this->resource;
        $registeredAt = $passkey->getAttribute('created');

        return [
            'id' => (string) $passkey->getAttribute('cid'),
            'name' => $passkey->friendlyName,
            'authenticatorDescription' => $passkey->certificateSubject === '' ? null : $passkey->certificateSubject,
            'registeredAt' => $registeredAt?->format('Y-m-d\TH:i:s\Z'),
            'hostName' => $passkey->rpId,
            'active' => (bool) $passkey->active,
            'etag' => PasskeyService::etag($passkey),
        ];
    }

    /**
     * The single-resource response with its `ETag`.
     */
    public function withETag(int $status = 200): JsonResponse
    {
        return $this->response()->setStatusCode($status)->header('ETag', PasskeyService::etag($this->resource));
    }
}
