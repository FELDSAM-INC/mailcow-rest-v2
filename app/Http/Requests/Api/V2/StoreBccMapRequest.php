<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\RoutingPolicy;
use App\Rules\StrictBoolean;
use App\Services\Routing\BccDestinationResolver;
use App\Services\Routing\BccMapService;

/**
 * Body of `createBccMap` (`BccMapCreateRequest`, feature 009 FR-049–FR-052): `localDestination`
 * (required, at most 255 characters, in the caller's scope, else `notFound`), `type` (`sender` or
 * `recipient`), `bccDestination` (exactly one address) and `active` (boolean, default true), every
 * invalid field reported together. Administrators, and domain administrators with `bccMaps`.
 */
class StoreBccMapRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        app(RoutingPolicy::class)->authorize(RequestContext::requirePrincipal($this), RoutingPolicy::CHANGE_BCC_MAPS);

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'localDestination' => ['bail', 'required', 'string', 'max:255'],
            'type' => ['bail', 'required', 'string', 'in:sender,recipient'],
            'bccDestination' => ['bail', 'required', 'string'],
            'active' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }

    protected function memberViolations(array $body): array
    {
        $violations = [];
        $principal = RequestContext::principal($this);

        if ($principal !== null && is_string($body['localDestination'] ?? null) && trim($body['localDestination']) !== '' && mb_strlen($body['localDestination']) <= 255
            && app(BccDestinationResolver::class)->resolve($principal, $body['localDestination']) === null) {
            $violations['localDestination'] = self::make('notFound', [], 'No domain, alias domain, mailbox or alias with this value exists in your scope.');
        }

        if (is_string($body['bccDestination'] ?? null) && trim($body['bccDestination']) !== '' && ($violation = BccMapService::bccViolation($body['bccDestination'])) !== null) {
            $violations['bccDestination'] = $violation;
        }

        return $violations;
    }

    /**
     * @return array<string, mixed>
     */
    public function members(): array
    {
        return $this->validated();
    }
}
