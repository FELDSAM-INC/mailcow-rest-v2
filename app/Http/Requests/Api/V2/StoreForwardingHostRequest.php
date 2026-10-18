<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\RoutingPolicy;
use App\Rules\StrictBoolean;

/**
 * Body of `createForwardingHost` (`ForwardingHostCreateRequest`, feature 009 FR-076): `host`
 * (required, trimmed, at most 255 characters) and `spamFilter` (boolean, default true). The
 * address syntax and the name resolution are the resolver's. Administrators only.
 */
class StoreForwardingHostRequest extends ApiFormRequest
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
            'host' => ['bail', 'required', 'string', 'max:255'],
            'spamFilter' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }

    public function host(): string
    {
        return (string) $this->validated('host');
    }

    public function spamFilter(): bool
    {
        return (bool) ($this->validated('spamFilter') ?? true);
    }
}
