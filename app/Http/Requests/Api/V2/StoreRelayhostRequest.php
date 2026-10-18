<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\RoutingPolicy;
use App\Rules\NextHopRule;
use App\Rules\SmtpPassword;
use App\Rules\SmtpUsername;
use App\Rules\StrictBoolean;
use App\Services\Routing\RelayhostService;
use App\Support\Http\FieldViolations;

/**
 * Body of `createRelayhost` (`RelayhostCreateRequest`, feature 009 FR-013, FR-018, FR-019): `host`
 * (required, trimmed, at most 255 characters, the next hop grammar), `username` (trimmed, at most
 * 255, no colon), `password` (trimmed, at most 255; it needs a username), `active` (boolean,
 * default true). Every invalid field is reported together. Administrators only.
 */
class StoreRelayhostRequest extends ApiFormRequest
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
            'host' => ['bail', 'required', new NextHopRule],
            'username' => ['bail', 'sometimes', new SmtpUsername],
            'password' => ['bail', 'sometimes', new SmtpPassword],
            'active' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }

    protected function memberViolations(array $body): array
    {
        $username = is_string($body['username'] ?? null) ? trim($body['username']) : '';
        $password = is_string($body['password'] ?? null) ? trim($body['password']) : '';
        $violations = new FieldViolations;
        RelayhostService::credentialViolations($username, $password, $violations);

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
