<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\MtaStsPolicyRules;
use Illuminate\Support\Facades\Gate;

/**
 * JSON Merge Patch of `updateMtaStsPolicy` (`MtaStsPolicyPatch`, feature 010 FR-033, FR-034): at
 * least one member (`required` on `body`), no member `null` (`invalidType`), and the member rules of
 * MtaStsPolicyRules for each member sent. Administrators and domain administrators.
 */
class UpdateMtaStsPolicyRequest extends ApiFormRequest
{
    use MtaStsPolicyRules;

    protected bool $requiresNonEmptyBody = true;

    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('mtaSts.use');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->policyRules(false);
    }

    /**
     * The members sent, with the MX hosts normalised.
     *
     * @return array{version?: string, mode?: string, mxHosts?: list<string>, maxAge?: int, active?: bool}
     */
    public function members(): array
    {
        $members = [];

        foreach (['version', 'mode'] as $name) {
            if ($this->has($name)) {
                $members[$name] = (string) $this->validated($name);
            }
        }

        if ($this->has('maxAge')) {
            $members['maxAge'] = (int) $this->validated('maxAge');
        }

        if ($this->has('mxHosts')) {
            $members['mxHosts'] = $this->normalisedMxHosts() ?? [];
        }

        if ($this->has('active')) {
            $members['active'] = $this->validated('active') === true;
        }

        return $members;
    }
}
