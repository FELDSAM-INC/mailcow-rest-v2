<?php

namespace App\Http\Requests\Api\V2\Concerns;

use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Rules\AccountUsername;
use App\Rules\StrictBoolean;
use App\Services\Accounts\DomainAdminFlags;
use App\Services\Passwords\MailboxPasswordHasher;
use App\Services\Passwords\PasswordPolicyChecker;
use App\Services\Passwords\PasswordPolicyReader;
use Illuminate\Contracts\Validation\Validator;

/**
 * Value rules shared by the administrator and domain administrator requests of feature 007
 * (FR-014, FR-016, FR-017, FR-037): the username rule, a password of 1 to 4096 characters checked
 * against the password policy with feature 003's codes unless it carries a hash-scheme prefix,
 * strict booleans, the permission flags, and `unknownField` for members the schema does not define.
 */
trait AccountValueRules
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function accountRules(bool $partial): array
    {
        $sometimes = $partial ? ['sometimes'] : [];

        return [
            'username' => ['bail', ...($partial ? ['sometimes'] : ['required']), new AccountUsername],
            'password' => ['bail', ...($partial ? ['sometimes'] : ['required']), 'string', 'min:1', 'max:4096'],
            'active' => ['bail', ...$sometimes, new StrictBoolean],
            'forcePasswordChange' => ['bail', ...$sometimes, new StrictBoolean],
            'forceSecondFactorSetup' => ['bail', ...$sometimes, new StrictBoolean],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function permissionRules(): array
    {
        $rules = ['permissions' => ['bail', 'sometimes', 'array']];

        foreach (array_keys(DomainAdminFlags::COLUMNS) as $name) {
            $rules['permissions.'.$name] = ['bail', 'sometimes', new StrictBoolean];
        }

        return $rules;
    }

    /**
     * `unknownField` for top-level members outside $allowed and for unknown permission flags;
     * `invalidType` for a permissions member that is not an object.
     *
     * @param  array<string, mixed>  $body
     * @param  list<string>  $allowed
     * @return array<string, array{code: string, params: array<string, mixed>, message: string}>
     */
    protected function unknownMembers(array $body, array $allowed): array
    {
        $violations = [];

        foreach (array_keys($body) as $member) {
            if (! in_array($member, $allowed, true)) {
                $violations[(string) $member] = ApiFormRequest::make('unknownField', [], 'This member is not accepted here.');
            }
        }

        $permissions = $body['permissions'] ?? null;

        if (is_array($permissions) && array_is_list($permissions) && $permissions !== []) {
            $violations['permissions'] = ApiFormRequest::make('invalidType', ['expected' => 'object']);
        } elseif (is_array($permissions)) {
            foreach (array_keys($permissions) as $name) {
                if (DomainAdminFlags::column((string) $name) === null) {
                    $violations['permissions.'.$name] = ApiFormRequest::make('unknownField', [], 'This is not a permission flag of domain administrators.');
                }
            }
        }

        return $violations;
    }

    /**
     * Every unmet rule of the password policy (feature 003's codes); none for a pre-hashed value.
     *
     * @return array<string, list<array{code: string, params: array<string, mixed>, message: string}>>
     */
    protected function passwordViolations(Validator $validator): array
    {
        $password = $this->input('password');

        if (! is_string($password) || $password === '' || $validator->errors()->has('password') || MailboxPasswordHasher::isPreHashed($password)) {
            return [];
        }

        $violations = [];

        foreach (app(PasswordPolicyChecker::class)->violations($password, app(PasswordPolicyReader::class)->effective()) as $violation) {
            $violations[] = ApiFormRequest::make($violation['code'], $violation['params']);
        }

        return $violations === [] ? [] : ['password' => $violations];
    }

    /**
     * The validated account values: the username normalised, booleans as given.
     *
     * @return array<string, mixed>
     */
    protected function accountValues(): array
    {
        $body = $this->jsonBody();
        $values = [];

        if (array_key_exists('username', $body)) {
            $values['username'] = AccountUsername::normalize((string) $body['username']);
        }

        if (array_key_exists('password', $body)) {
            $values['password'] = (string) $body['password'];
        }

        foreach (['active', 'forcePasswordChange', 'forceSecondFactorSetup'] as $member) {
            if (array_key_exists($member, $body)) {
                $values[$member] = (bool) $body[$member];
            }
        }

        if (is_array($body['permissions'] ?? null)) {
            $values['permissions'] = array_map(fn (mixed $value): bool => (bool) $value, $body['permissions']);
        }

        return $values;
    }
}
