<?php

namespace Tests\Support\Http;

use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Rules\EligibleKeyOwner;
use App\Rules\EnabledLanguage;
use App\Rules\FutureDateTime;
use App\Rules\IpNetwork;
use App\Rules\Rfc3339DateTime;
use Illuminate\Validation\Rule;

/**
 * Form Request of ValidationRuleCodeTest: one field per rule whose code is asserted.
 */
class ValidationProbeRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'min:3', 'max:10'],
            'count' => ['bail', 'nullable', 'integer', 'between:1,1000'],
            'tags' => ['bail', 'nullable', 'array', 'max:2'],
            'tags.*' => ['bail', 'string'],
            'level' => ['bail', 'nullable', Rule::in(['readOnly', 'readWrite'])],
            'until' => ['bail', 'nullable', new Rfc3339DateTime, new FutureDateTime],
            'since' => ['bail', 'nullable', 'date_format:Y-m-d'],
            'networks' => ['bail', 'nullable', 'array'],
            'networks.*' => ['bail', 'string', new IpNetwork],
            'owner' => ['bail', 'nullable', 'string', new EligibleKeyOwner],
            'language' => ['bail', 'nullable', 'string', app(EnabledLanguage::class)],
        ];
    }
}
