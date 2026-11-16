<?php

namespace App\Http\Requests\Api\V2\Concerns;

use App\Auth\IdentityProviderType;
use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Rules\LdapAttributeName;
use App\Rules\LdapFilter;
use App\Rules\PrecomputedViolation;
use App\Rules\StrictBoolean;
use App\Rules\StrictInteger;
use App\Services\IdentityProvider\IdentitySyncSettings;
use Illuminate\Validation\Rule;

/** The same field and provider checks for initial setup and later synchronization edits. */
trait IdentitySyncRules
{
    /** @param array<string, mixed> $body
     * @return array<string, list<mixed>>
     */
    protected function synchronizationRules(array $body, IdentityProviderType $type, string $prefix = ''): array
    {
        $rules = [
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
        foreach ($body as $member => $value) {
            if (! array_key_exists($member, IdentitySyncSettings::KEYS)) {
                $rules[$member] = [new PrecomputedViolation(ApiFormRequest::make('immutable', []))];
            } elseif ($value === null) {
                $rules[$member] = [new PrecomputedViolation(ApiFormRequest::make('invalidType', []))];
            } elseif (($type !== IdentityProviderType::Ldap && in_array($member, IdentitySyncSettings::LDAP_FIELDS, true))
                || ($type !== IdentityProviderType::Keycloak && $member === 'groupPaths')) {
                $rules[$member] = [new PrecomputedViolation(ApiFormRequest::make('conflictsWith', ['field' => 'type', 'value' => $type->value]))];
            }
        }

        return array_combine(array_map(fn (string $key): string => $prefix.$key, array_keys($rules)), array_values($rules));
    }
}
