<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Models\Mailcow\IdentityProviderSetting;
use App\Rules\LdapAttributeName;
use App\Rules\LdapFilter;
use App\Rules\StrictBoolean;
use App\Rules\StrictInteger;
use App\Services\IdentityProvider\IdentitySyncService;
use App\Services\IdentityProvider\IdentitySyncSettings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateIdentitySyncRequest extends ApiFormRequest
{
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
        return [
            'offboarding' => ['sometimes', new StrictBoolean],
            'offboardingLimit' => ['bail', 'sometimes', new StrictInteger, 'integer', 'between:0,100'],
            'groupSync' => ['sometimes', new StrictBoolean],
            'groupAutocreate' => ['sometimes', new StrictBoolean],
            'groupDefaultDomain' => ['bail', 'sometimes', 'string', 'max:255', Rule::exists('domain', 'domain')],
            'groupPaths' => ['bail', 'sometimes', 'array', 'list', 'max:100'],
            'groupPaths.*' => ['bail', 'string', 'min:1', 'max:1000', 'regex:~^/[^,\x00-\x1F]*$~', 'distinct'],
            'idAttribute' => ['sometimes', new LdapAttributeName(allowEmpty: true)],
            'disabledFilter' => ['sometimes', new LdapFilter(allowEmpty: true)],
            'groupBaseDn' => ['sometimes', 'string', 'max:1000', 'not_regex:/[\x00-\x1F]/'],
            'groupFilter' => ['sometimes', new LdapFilter(allowEmpty: true)],
            'groupNameAttribute' => ['sometimes', new LdapAttributeName],
            'groupMemberMode' => ['sometimes', Rule::in(['memberof', 'member', 'ad_chain'])],
            'groupMemberAttribute' => ['sometimes', new LdapAttributeName],
        ];
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
