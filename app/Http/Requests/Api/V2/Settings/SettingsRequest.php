<?php

namespace App\Http\Requests\Api\V2\Settings;

use App\Auth\Principal;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Policies\SettingsPolicy;

/**
 * Base of feature 013's Form Requests (data-model.md section 1): the caller is authorised before
 * any rule runs (administrators only by default; customisation writes also refuse demo mode), a
 * member the schema does not define is `unknownField` (a sent value is never ignored, FR-007), and
 * `validateOnly=true` is read from the query (FR-093).
 */
abstract class SettingsRequest extends ApiFormRequest
{
    /** @var list<string> top-level members of the body */
    protected const MEMBERS = [];

    /** @var array<string, list<string>> members of nested objects, by parent member */
    protected const NESTED = [];

    public function authorize(): bool
    {
        $this->authorizeCaller(RequestContext::requirePrincipal($this), app(SettingsPolicy::class));

        return true;
    }

    protected function authorizeCaller(Principal $principal, SettingsPolicy $policy): void
    {
        $policy->requireAdmin($principal);
    }

    protected function memberViolations(array $body): array
    {
        $violations = [];

        foreach ($body as $member => $value) {
            if (! in_array($member, static::MEMBERS, true)) {
                $violations[(string) $member] = self::make('unknownField', [], 'This member is not accepted here.');

                continue;
            }

            if (isset(static::NESTED[$member]) && is_array($value) && ! array_is_list($value)) {
                foreach (array_keys($value) as $nested) {
                    if (! in_array($nested, static::NESTED[$member], true)) {
                        $violations[$member.'.'.$nested] = self::make('unknownField', [], 'This member is not accepted here.');
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * The sent members.
     *
     * @return array<string, mixed>
     */
    public function patch(): array
    {
        return array_intersect_key($this->json()->all(), array_flip(static::MEMBERS));
    }

    public function validateOnly(): bool
    {
        return in_array($this->query('validateOnly'), ['true', '1'], true);
    }

    /**
     * The JSON body plus the `validateOnly` query flag (validated as a query boolean).
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $validateOnly = $this->query('validateOnly');

        return $this->json()->all() + ($validateOnly === null ? [] : ['validateOnly' => $validateOnly]);
    }
}
