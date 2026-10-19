<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\RateLimitPolicy;
use App\Services\RateLimits\RateLimitStore;

/**
 * Body of the rate limit changes (`RateLimitUpdate`, feature 006 FR-053): `value`, a whole number
 * from 1 to 2147483647 (`invalidType`, `outOfRange`), and `frame`, `second`, `minute`, `hour` or
 * `day` (`notInList`).
 */
class SetRateLimitRequest extends ApiFormRequest
{
    /** The role and the flag are checked before the body (research R15). */
    public function authorize(): bool
    {
        app(RateLimitPolicy::class)->authorize(RequestContext::requirePrincipal($this), RateLimitPolicy::CHANGE);

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'value' => ['bail', 'required', 'integer:strict', 'between:1,'.RateLimitStore::MAX_VALUE],
            'frame' => ['bail', 'required', 'string', 'in:'.implode(',', array_keys(RateLimitStore::FRAMES))],
        ];
    }

    protected function memberViolations(array $body): array
    {
        $violations = [];

        foreach (array_keys($body) as $member) {
            if (! in_array($member, ['value', 'frame'], true)) {
                $violations[(string) $member] = self::make('unknownField', [], 'This member is not accepted here.');
            }
        }

        return $violations;
    }

    public function limitValue(): int
    {
        return (int) $this->validated('value');
    }

    public function frame(): string
    {
        return (string) $this->validated('frame');
    }
}
