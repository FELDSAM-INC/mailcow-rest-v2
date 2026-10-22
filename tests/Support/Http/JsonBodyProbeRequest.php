<?php

namespace Tests\Support\Http;

use App\Http\Requests\Api\V2\ApiFormRequest;

class JsonBodyProbeRequest extends ApiFormRequest
{
    /** @var array<string, mixed> */
    public array $seenDuringValidation = [];

    public function prepareForValidation(): void
    {
        parent::prepareForValidation();
    }

    /** @return array<string, mixed> */
    public function members(): array
    {
        return $this->jsonBody();
    }

    protected function memberViolations(array $body): array
    {
        $this->seenDuringValidation = $this->jsonBody();

        return [];
    }
}
