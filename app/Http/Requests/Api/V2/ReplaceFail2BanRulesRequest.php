<?php

namespace App\Http\Requests\Api\V2;

/**
 * Body of `replaceFail2BanRules` (feature 011 FR-050, `Fail2BanRulesReplaceRequest`): 1 to 100 rules
 * (`required`, `tooMany`) of 1 to 1,000 characters (`tooShort`, `tooLong`). Whether each rule
 * compiles in Python `re` with a capture group is the rules service's check.
 */
class ReplaceFail2BanRulesRequest extends ApiFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'rules' => ['bail', 'required', 'array', 'list', 'max:'.(int) config('restv2.monitoring.rules_max', 100)],
            'rules.*' => ['bail', 'required', 'string', 'min:1', 'max:'.(int) config('restv2.monitoring.rule_max_length', 1000)],
        ];
    }

    /**
     * @return list<string>
     */
    public function patterns(): array
    {
        return array_values(array_map('strval', (array) $this->validated('rules')));
    }
}
