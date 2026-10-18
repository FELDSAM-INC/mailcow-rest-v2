<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\RoutingPolicy;
use App\Rules\RoutingEmail;

/**
 * Body of `testRelayhostConnection` and `testTransportConnection` (`RoutingConnectionTestRequest`,
 * optional, feature 009 FR-041): `sender` and `recipient`, each a valid e-mail address of at most
 * 255 characters when given (`invalidFormat` with `format: email`), never replaced by the default.
 * Administrators only; the entry is looked up after the role check (`404`).
 */
class RoutingConnectionTestRequest extends ApiFormRequest
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
            'sender' => ['bail', 'sometimes', 'string', new RoutingEmail],
            'recipient' => ['bail', 'sometimes', 'string', new RoutingEmail],
        ];
    }

    public function sender(): ?string
    {
        $value = $this->validated('sender');

        return is_string($value) ? RoutingEmail::normalize($value) : null;
    }

    public function recipient(): ?string
    {
        $value = $this->validated('recipient');

        return is_string($value) ? RoutingEmail::normalize($value) : null;
    }
}
