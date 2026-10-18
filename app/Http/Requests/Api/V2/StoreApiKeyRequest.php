<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\TrimsName;
use App\Models\ApiKey;
use App\Rules\EligibleKeyOwner;
use App\Rules\FutureDateTime;
use App\Rules\IpNetwork;
use App\Rules\Rfc3339DateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * Body of `createApiKey` (FR-032, data-model "ApiKey › Validation"). Only a signed-in
 * administrator may create keys; the policy runs before validation (R8).
 */
class StoreApiKeyRequest extends ApiFormRequest
{
    use TrimsName;

    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('create', ApiKey::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:255'],
            'ownerUsername' => ['bail', 'required', 'string', 'max:255', new EligibleKeyOwner],
            'accessLevel' => ['bail', 'required', 'string', 'in:readOnly,readWrite'],
            'expiresAt' => ['bail', 'nullable', new FutureDateTime],
            'allowedNetworks' => ['bail', 'sometimes', 'array', 'max:100'],
            'allowedNetworks.*' => ['bail', new IpNetwork],
        ];
    }

    public function expiresAt(): ?CarbonImmutable
    {
        $value = $this->validated('expiresAt');

        return is_string($value) ? Rfc3339DateTime::parse($value) : null;
    }

    /**
     * @return list<string>
     */
    public function allowedNetworks(): array
    {
        return array_values(array_map('strval', (array) $this->validated('allowedNetworks', [])));
    }
}
