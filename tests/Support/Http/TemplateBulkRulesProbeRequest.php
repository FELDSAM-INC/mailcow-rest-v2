<?php

namespace Tests\Support\Http;

use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Http\Requests\Api\V2\Concerns\TemplateBulkActionRules;

class TemplateBulkRulesProbeRequest extends ApiFormRequest
{
    use TemplateBulkActionRules;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return $this->templateBulkRules(['delete'], 2);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, array{code: string, params: array<string, mixed>, message: string}>
     */
    public function violationsFor(array $body): array
    {
        return $this->memberViolations($body);
    }
}
