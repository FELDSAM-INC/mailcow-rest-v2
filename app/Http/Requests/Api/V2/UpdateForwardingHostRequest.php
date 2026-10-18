<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\RoutingPolicy;
use App\Rules\StrictBoolean;

/**
 * Body of `updateForwardingHost` (`ForwardingHostPatch`, feature 009 FR-079): `spamFilter`; the
 * read-only and unknown members are compared by the service (`immutable`). Administrators only.
 */
class UpdateForwardingHostRequest extends ApiFormRequest
{
    protected bool $requiresNonEmptyBody = true;

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
        return ['spamFilter' => ['bail', 'sometimes', new StrictBoolean]];
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
