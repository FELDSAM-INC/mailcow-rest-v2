<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\SpamPolicyPolicy;
use App\Rules\OneDecimal;
use Illuminate\Contracts\Validation\Validator;

/**
 * Body of `setMailboxSpamScore` (`SpamScoreUpdate`, feature 006 FR-040): `junkScore` and
 * `rejectScore`, numbers from 0 to 5000 (`outOfRange`) with at most one decimal (`notMultipleOf`,
 * `factor: 0.1`); the junk score must not exceed the reject score (`mustNotExceed` on `junkScore`,
 * `field: rejectScore`).
 */
class SetSpamScoreRequest extends ApiFormRequest
{
    /** The flag is checked before the body (research R15). */
    public function authorize(): bool
    {
        app(SpamPolicyPolicy::class)->authorize(RequestContext::requirePrincipal($this), SpamPolicyPolicy::CHANGE_SCORE);

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'junkScore' => ['bail', 'required', 'numeric', 'between:0,5000', new OneDecimal],
            'rejectScore' => ['bail', 'required', 'numeric', 'between:0,5000', new OneDecimal],
        ];
    }

    protected function memberViolations(array $body): array
    {
        $violations = [];

        foreach (array_keys($body) as $member) {
            if (! in_array($member, ['junkScore', 'rejectScore'], true)) {
                $violations[(string) $member] = self::make('unknownField', [], 'This member is not accepted here.');
            }
        }

        return $violations;
    }

    protected function additionalViolations(Validator $validator): array
    {
        $junk = $this->input('junkScore');
        $reject = $this->input('rejectScore');

        if ($validator->errors()->hasAny(['junkScore', 'rejectScore']) || ! is_numeric($junk) || ! is_numeric($reject) || (float) $junk <= (float) $reject) {
            return [];
        }

        return ['junkScore' => [self::make('mustNotExceed', ['field' => 'rejectScore', 'value' => $reject + 0], 'The junk score must not exceed the reject score.')]];
    }

    public function junkScore(): int|float
    {
        return $this->input('junkScore') + 0;
    }

    public function rejectScore(): int|float
    {
        return $this->input('rejectScore') + 0;
    }
}
