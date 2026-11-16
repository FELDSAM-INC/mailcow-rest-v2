<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Rules\IpNetwork;
use App\Rules\StrictBoolean;
use App\Services\LegacyApiKeys\LegacyKeyService;

class WriteLegacyApiKeyRequest extends ApiFormRequest
{
    protected bool $requiresNonEmptyBody = true;

    public function authorize(): bool
    {
        app(LegacyKeyService::class)->authorize(RequestContext::requirePrincipal($this), true);

        return true;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->jsonBody();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['description' => ['sometimes', 'string', 'max:255'],
            'accessLevel' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'in:readOnly,readWrite'],
            'allowedNetworks' => ['sometimes', 'array', 'list', 'max:100'],
            'allowedNetworks.*' => ['required', new IpNetwork],
            'skipIpCheck' => ['sometimes', new StrictBoolean], 'active' => ['sometimes', new StrictBoolean]];
    }

    /** @param array<string, mixed> $body
     * @return array<string, array{code: string, params: array<string, mixed>, message: string}>
     */
    protected function memberViolations(array $body): array
    {
        $errors = [];
        foreach ($body as $field => $value) {
            if (! array_key_exists($field, $this->rules())) {
                $errors[$field] = self::make('immutable', []);
            } elseif ($value === null) {
                $errors[$field] = self::make('invalidType', []);
            }
        }

        return $errors;
    }
}
