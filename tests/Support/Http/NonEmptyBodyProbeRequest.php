<?php

namespace Tests\Support\Http;

use App\Http\Requests\Api\V2\ApiFormRequest;

/**
 * Form Request of ValidationRuleCodeTest whose body must not be empty (error key `body`).
 */
class NonEmptyBodyProbeRequest extends ApiFormRequest
{
    protected bool $requiresNonEmptyBody = true;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['theme' => ['bail', 'nullable', 'string']];
    }
}
