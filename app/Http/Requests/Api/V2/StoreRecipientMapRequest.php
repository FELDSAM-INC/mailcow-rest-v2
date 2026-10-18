<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\RoutingPolicy;
use App\Rules\StrictBoolean;
use App\Services\Routing\RecipientMapService;
use App\Support\Http\FieldViolations;

/**
 * Body of `createRecipientMap` (`RecipientMapCreateRequest`, feature 009 FR-061): both destinations
 * required, each a domain name or an address of at most 255 characters as stored; `active`
 * (default true). Every invalid field is reported together. Administrators only.
 */
class StoreRecipientMapRequest extends ApiFormRequest
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
            'originalDestination' => ['bail', 'required', 'string'],
            'newDestination' => ['bail', 'required', 'string'],
            'active' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }

    protected function memberViolations(array $body): array
    {
        $violations = new FieldViolations;
        RecipientMapService::values(null, [], array_filter($body, fn (mixed $v): bool => $v !== null && $v !== ''), $violations);

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
