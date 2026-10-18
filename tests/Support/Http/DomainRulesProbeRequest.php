<?php

namespace Tests\Support\Http;

use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Rules\DkimSelector;
use App\Rules\WholeMebibytes;

/**
 * Form Request of ValidationRuleCodeTest for the rules of feature 002.
 */
class DomainRulesProbeRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quota' => ['bail', 'nullable', 'integer', new WholeMebibytes],
            'selector' => ['bail', 'nullable', 'string', 'max:255', new DkimSelector],
        ];
    }
}
