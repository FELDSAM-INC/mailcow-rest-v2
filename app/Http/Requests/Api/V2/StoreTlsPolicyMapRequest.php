<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\RoutingPolicy;
use App\Rules\StrictBoolean;
use App\Services\Routing\TlsPolicyMapService;
use App\Support\Http\FieldViolations;

/**
 * Body of `createTlsPolicyMap` (`TlsPolicyMapCreateRequest`, feature 009 FR-067–FR-070):
 * `destination` and `policy` required, `parameters` (default none) and `active` (default true),
 * every invalid field reported together. Administrators only.
 */
class StoreTlsPolicyMapRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        app(RoutingPolicy::class)->authorize(RequestContext::requirePrincipal($this), RoutingPolicy::MANAGE_ROUTING);

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'destination' => ['bail', 'required', 'string'],
            'policy' => ['bail', 'required', 'string'],
            'parameters' => ['bail', 'sometimes', 'string'],
            'active' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }

    protected function memberViolations(array $body): array
    {
        $violations = new FieldViolations;
        TlsPolicyMapService::values(null, [], array_filter($body, fn (mixed $v): bool => $v !== null && $v !== ''), $violations);

        return array_map(fn (array $list): array => $list[0], $violations->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function members(): array
    {
        return $this->validated();
    }
}
