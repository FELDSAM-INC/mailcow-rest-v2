<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\RoutingPolicy;
use App\Rules\NextHopRule;
use App\Rules\SmtpPassword;
use App\Rules\SmtpUsername;
use App\Rules\StrictBoolean;
use App\Support\Routing\TransportDestination;

/**
 * Body of `createTransport` (`TransportCreateRequest`, feature 009 FR-034–FR-038): `destination`
 * (required, trimmed, at most 255 characters, valid for its kind), `nextHop` (required, the next hop
 * grammar), `mxBased` and `active` (booleans, default false), and the credentials as for
 * relayhosts. Every invalid field is reported together; uniqueness, interference and the shared
 * credentials are the service's. Administrators only.
 */
class StoreTransportRequest extends ApiFormRequest
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
            'nextHop' => ['bail', 'required', new NextHopRule],
            'mxBased' => ['bail', 'sometimes', new StrictBoolean],
            'active' => ['bail', 'sometimes', new StrictBoolean],
            'username' => ['bail', 'sometimes', new SmtpUsername],
            'password' => ['bail', 'sometimes', new SmtpPassword],
        ];
    }

    protected function memberViolations(array $body): array
    {
        if (! array_key_exists('destination', $body) || ! is_string($body['destination']) || trim($body['destination']) === '') {
            return [];
        }

        $violation = TransportDestination::violation($body['destination'], ($body['mxBased'] ?? false) === true);

        return $violation === null ? [] : ['destination' => $violation];
    }

    /**
     * @return array<string, mixed>
     */
    public function members(): array
    {
        return $this->validated();
    }
}
