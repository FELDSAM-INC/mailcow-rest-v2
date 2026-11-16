<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\IdentitySyncRules;
use App\Models\Mailcow\IdentityProviderSetting;
use App\Services\IdentityProvider\IdentityProviderRepository;
use App\Services\IdentityProvider\IdentitySyncService;
use App\Services\IdentityProvider\IdentitySyncSettings;
use Illuminate\Support\Facades\Gate;

class UpdateIdentitySyncRequest extends ApiFormRequest
{
    use IdentitySyncRules;

    protected bool $requiresNonEmptyBody = true;

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
        $type = app(IdentityProviderRepository::class)->current()?->type;

        return $type === null ? [] : $this->synchronizationRules($this->jsonBody(), $type);
    }

    /** @param array<string, mixed> $body
     * @return array<string, array{code: string, params: array<string, mixed>, message: string}>
     */
    protected function memberViolations(array $body): array
    {
        $errors = [];
        foreach ($body as $name => $value) {
            if (! array_key_exists($name, IdentitySyncSettings::KEYS)) {
                $errors[$name] = self::make('immutable', []);
            } elseif ($value === null) {
                $errors[$name] = self::make('invalidType', []);
            }
        }

        return $errors;
    }

    /** @return array<string, mixed> */
    public function patch(): array
    {
        return $this->jsonBody();
    }
}
