<?php

namespace App\Http\Requests\Api\V2;

use App\Http\Requests\Api\V2\Concerns\MtaStsPolicyRules;
use App\Http\RequestContext;
use Illuminate\Support\Facades\Gate;

/**
 * Body of `createMtaStsPolicy` (`MtaStsPolicyCreateRequest`, feature 010 FR-033): `domain` "required,
 * string, 1 to 255 characters" and the member rules of MtaStsPolicyRules, with `version`
 * defaulting to `stsv1` and `active` to false, as in legacy. Administrators and domain
 * administrators; the role is checked first.
 */
class StoreMtaStsPolicyRequest extends ApiFormRequest
{
    use MtaStsPolicyRules;

    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('mtaSts.use');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['domain' => ['bail', 'required', 'string', 'max:255'], ...$this->policyRules(true)];
    }

    /**
     * @return array{domain: string, version: string, mode: string, mxHosts: list<string>, maxAge: int, active: bool}
     */
    public function members(): array
    {
        return [
            'domain' => (string) $this->validated('domain'),
            'version' => $this->has('version') ? (string) $this->validated('version') : 'stsv1',
            'mode' => (string) $this->validated('mode'),
            'mxHosts' => $this->normalisedMxHosts() ?? [],
            'maxAge' => (int) $this->validated('maxAge'),
            'active' => $this->validated('active') === true,
        ];
    }
}
