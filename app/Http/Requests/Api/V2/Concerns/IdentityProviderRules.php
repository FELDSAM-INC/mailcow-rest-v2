<?php

namespace App\Http\Requests\Api\V2\Concerns;

use App\Auth\IdentityProviderType;
use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Rules\ExistingMailboxTemplate;
use App\Rules\HostName;
use App\Rules\HttpUrl;
use App\Rules\KeycloakRealm;
use App\Rules\KeycloakVersion;
use App\Rules\LdapAttributeName;
use App\Rules\LdapFilter;
use App\Rules\PrecomputedViolation;
use App\Rules\ScopeList;
use App\Rules\UniqueAttributeValues;
use App\Services\IdentityProvider\IdentityProviderConfig;
use App\Services\IdentityProvider\SecretRetentionPolicy;

/**
 * The rules of a whole identity provider configuration of one type (FR-010 to FR-014, research
 * R10), shared by the save, the change (applied to the merged result) and the connection test (no
 * template check). Cross-field findings are attached as precomputed violations, so that every
 * violation of a request is reported together (docs/conventions.md):
 *
 *  - a member that is not a setting of the type → `conflictsWith` (`field: type`);
 *  - an empty LDAP `attributeField` while `periodicSync` or `importUsers` is on → `conflictsWith`;
 *  - an omitted secret that may not be kept → `secretRequired` (SecretRetentionPolicy).
 */
trait IdentityProviderRules
{
    /**
     * @param  array<string, mixed>  $input  the whole configuration, `type` excluded
     * @param  array<string, mixed>  $sent  the members the client sent (the foreign-member check)
     * @return array<string, list<mixed>>
     */
    protected function configurationRules(IdentityProviderType $type, array $input, array $sent, bool $checkTemplates, ?IdentityProviderConfig $stored, ?IdentityProviderConfig $candidate): array
    {
        $templateRule = $checkTemplates ? [new ExistingMailboxTemplate] : [];
        $mapping = is_array($input['attributeMapping'] ?? null) ? $input['attributeMapping'] : [];
        $values = array_values(array_map(fn (mixed $entry): mixed => is_array($entry) ? ($entry['attributeValue'] ?? null) : null, $mapping));

        $rules = [
            'attributeMapping' => ['bail', 'array', 'list', 'max:100'],
            'attributeMapping.*' => ['bail', 'array'],
            'attributeMapping.*.attributeValue' => ['bail', 'required', 'string', 'max:255', new UniqueAttributeValues($values, 'attributeMapping.*.attributeValue')],
            'attributeMapping.*.template' => ['bail', 'required', 'string', 'max:255', ...$templateRule],
            'defaultTemplate' => ['bail', 'nullable', 'string', 'min:1', 'max:255', ...$templateRule],
            'createMailboxOnSignIn' => ['bail', 'boolean'],
            'ignoreTlsErrors' => ['bail', 'boolean'],
        ];

        $secret = ['bail', 'string', 'min:1', 'max:1000'];
        $sync = [
            'importUsers' => ['bail', 'boolean'],
            'periodicSync' => ['bail', 'boolean'],
            'syncInterval' => ['bail', 'integer', 'between:1,10080'],
        ];
        $returnUrls = [
            'legacyReturnUrl' => ['bail', 'required', 'string', new HttpUrl],
            'additionalReturnUrls' => ['bail', 'array', 'list', 'max:100'],
            'additionalReturnUrls.*' => ['bail', 'string', new HttpUrl],
        ];

        $rules += match ($type) {
            IdentityProviderType::Keycloak => [
                'serverUrl' => ['bail', 'required', 'string', new HttpUrl],
                'realm' => ['bail', 'required', 'string', new KeycloakRealm],
                'clientId' => ['bail', 'required', 'string', 'max:255'],
                'clientSecret' => $secret,
                'version' => ['bail', 'required', 'string', 'max:64', new KeycloakVersion],
                'mailpasswordFlow' => ['bail', 'boolean'],
                ...$sync,
                ...$returnUrls,
            ],
            IdentityProviderType::GenericOidc => [
                'authorizationEndpoint' => ['bail', 'required', 'string', new HttpUrl],
                'tokenEndpoint' => ['bail', 'required', 'string', new HttpUrl],
                'userInfoEndpoint' => ['bail', 'required', 'string', new HttpUrl],
                'clientId' => ['bail', 'required', 'string', 'max:255'],
                'clientSecret' => $secret,
                'scopes' => ['bail', 'string', 'max:2000', new ScopeList],
                ...$returnUrls,
            ],
            IdentityProviderType::Ldap => [
                'hosts' => ['bail', 'required', 'array', 'list', 'min:1', 'max:10'],
                'hosts.*' => ['bail', 'string', 'max:253', new HostName],
                'port' => ['bail', 'integer', 'between:1,65535'],
                'useSsl' => ['bail', 'boolean'],
                'useStartTls' => ['bail', 'boolean'],
                'baseDn' => ['bail', 'required', 'string', 'max:1000'],
                'usernameField' => ['bail', 'string', new LdapAttributeName],
                'filter' => ['bail', 'nullable', 'string', new LdapFilter(allowEmpty: true)],
                'attributeField' => ['bail', 'nullable', 'string', new LdapAttributeName(allowEmpty: true)],
                'bindDn' => ['bail', 'required', 'string', 'max:1000'],
                'bindPassword' => $secret,
                ...$sync,
            ],
        };

        foreach (array_keys($sent) as $member) {
            if ($member !== 'type' && ! in_array($member, IdentityProviderConfig::MEMBERS[$type->value], true)) {
                $rules[(string) $member] = [new PrecomputedViolation(ApiFormRequest::make('conflictsWith', ['field' => 'type', 'value' => $type->value], "Not a setting of the type {$type->value}."))];
            }
        }

        if ($type === IdentityProviderType::Ldap && in_array($input['attributeField'] ?? null, [null, ''], true)) {
            $on = match (true) {
                ($input['periodicSync'] ?? false) === true => 'periodicSync',
                ($input['importUsers'] ?? false) === true => 'importUsers',
                default => null,
            };

            if ($on !== null) {
                $rules['attributeField'][] = new PrecomputedViolation(ApiFormRequest::make('conflictsWith', ['field' => $on, 'value' => true], 'An attribute field is required while '.$on.' is on.'));
            }
        }

        if ($candidate !== null) {
            $secretMember = $candidate->secretMember();
            $sentSecret = is_string($sent[$secretMember] ?? null) ? $sent[$secretMember] : null;
            $rules[$secretMember][] = new PrecomputedViolation(app(SecretRetentionPolicy::class)->violation($stored, $candidate, $sentSecret));
        }

        return $rules;
    }
}
