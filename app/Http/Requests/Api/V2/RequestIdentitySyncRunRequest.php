<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Models\Mailcow\IdentityProviderSetting;
use App\Rules\StrictBoolean;
use App\Services\IdentityProvider\IdentitySyncService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RequestIdentitySyncRunRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->jsonBody();
    }

    public function authorize(): bool
    {
        Gate::forUser(RequestContext::requirePrincipal($this))->authorize('update', IdentityProviderSetting::class);
        app(IdentitySyncService::class)->assertAvailable();

        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(['preview', 'run', 'force'])],
            'confirmForce' => ['sometimes', new StrictBoolean],
        ];
    }

    /** @param array<string, mixed> $body
     * @return array<string, array{code: string, params: array<string, mixed>, message: string}>
     */
    protected function memberViolations(array $body): array
    {
        $errors = [];
        foreach (array_diff(array_keys($body), ['mode', 'confirmForce']) as $name) {
            $errors[$name] = self::make('immutable', []);
        }
        if (($body['mode'] ?? null) === 'force' && ($body['confirmForce'] ?? null) !== true) {
            $errors['confirmForce'] = self::make('required', [], 'Confirm bypassing the offboarding and membership-removal limits.');
        }

        return $errors;
    }

    public function mode(): string
    {
        return (string) ($this->jsonBody()['mode'] ?? '');
    }
}
