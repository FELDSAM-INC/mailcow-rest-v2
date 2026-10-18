<?php

namespace App\Http\Requests\Api\V2\Concerns;

use App\Rules\MatchesFormat;

/**
 * Validation of the WebAuthn Level 3 JSON a browser sends (`AuthenticationResponseJSON`,
 * `RegistrationResponseJSON`): the members the server reads are required and base64url.
 */
trait WebAuthnCredentialRules
{
    /**
     * @param  list<string>  $condition  rules that apply first, for example `exclude_unless:...`
     * @return array<string, list<mixed>>
     */
    protected static function authenticationResponseRules(string $field, array $condition = []): array
    {
        return self::credentialRules($field, ['clientDataJSON', 'authenticatorData', 'signature'], $condition);
    }

    /**
     * @param  list<string>  $condition
     * @return array<string, list<mixed>>
     */
    protected static function registrationResponseRules(string $field, array $condition = []): array
    {
        return self::credentialRules($field, ['clientDataJSON', 'attestationObject'], $condition);
    }

    /**
     * @param  list<string>  $members
     * @param  list<string>  $condition
     * @return array<string, list<mixed>>
     */
    private static function credentialRules(string $field, array $members, array $condition): array
    {
        $binary = fn (): array => ['bail', ...$condition, 'required', 'string', MatchesFormat::base64Url()];

        $rules = [
            $field => ['bail', ...$condition, 'required', 'array'],
            "{$field}.id" => $binary(),
            "{$field}.rawId" => $binary(),
            "{$field}.type" => ['bail', ...$condition, 'required', 'string', 'in:public-key'],
            "{$field}.response" => ['bail', ...$condition, 'required', 'array'],
        ];

        foreach ($members as $member) {
            $rules["{$field}.response.{$member}"] = $binary();
        }

        return $rules;
    }
}
