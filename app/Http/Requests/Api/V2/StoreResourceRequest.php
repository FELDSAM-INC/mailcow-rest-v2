<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Models\Mailcow\Alias;
use App\Services\Aliases\AliasesCapabilitiesService;
use Illuminate\Support\Facades\Gate;

/**
 * Body of `createResource` (`ResourceCreateRequest`): the contract's types, bounds and enumerations.
 * The description, address, domain and booking rules are ResourceCreator's, reported together.
 */
class StoreResourceRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('create', Alias::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'description' => ['bail', 'required', 'string', 'max:255'],
            'domain' => ['bail', 'required', 'string', 'min:1', 'max:253'],
            'kind' => ['bail', 'required', 'string', 'in:location,thing,group'],
            'bookingRule' => ['bail', 'sometimes', 'string', 'in:alwaysFree,unlimited,limited'],
            'bookingLimit' => ['bail', 'sometimes', 'integer', 'between:1,'.AliasesCapabilitiesService::BOOKING_LIMIT_MAX],
            'active' => ['bail', 'sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function members(): array
    {
        $body = json_decode((string) $this->getContent(), true);

        return is_array($body) ? $body : [];
    }
}
