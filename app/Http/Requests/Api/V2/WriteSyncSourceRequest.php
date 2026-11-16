<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Rules\EmailAddress;
use App\Rules\StrictBoolean;
use App\Rules\StrictInteger;
use App\Rules\SyncJobHostName;
use App\Services\SyncSources\SourceScope;

class WriteSyncSourceRequest extends ApiFormRequest
{
    protected bool $requiresNonEmptyBody = true;

    public function authorize(): bool
    {
        app(SourceScope::class)->authorize(RequestContext::requirePrincipal($this));

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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $rules = [
            'name' => ['bail', $required, 'filled', 'string', 'max:100', 'not_regex:/[\x00-\x1f\x7f]/'],
            'description' => ['sometimes', 'string', 'max:255'],
            'remoteHost' => ['bail', $required, 'filled', 'string', 'max:253', new SyncJobHostName],
            'remotePort' => ['bail', $required, new StrictInteger, 'integer', 'between:1,65535'],
            'encryption' => ['sometimes', 'in:ssl,starttls,plain'],
            'authMechanism' => ['sometimes', 'in:plain,login,cram-md5,xoauth2'],
            'verifyCertificate' => ['sometimes', new StrictBoolean], 'active' => ['sometimes', new StrictBoolean],
            'scope' => ['sometimes', 'in:all,domain,user'],
            'domains' => ['sometimes', 'array', 'list', 'max:1000'],
            'domains.*' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
            'users' => ['sometimes', 'array', 'list', 'max:1000'],
            'users.*' => ['required', new EmailAddress(rejectControlCharacters: true), 'distinct:ignore_case'],
            'oauth' => ['sometimes', 'array:flow,tokenEndpoint,authorizationEndpoint,userInfoEndpoint,clientId,clientSecret,scope,extraParameters'],
            'oauth.flow' => ['sometimes', 'in:client_credentials,authorization_code'],
            'oauth.extraParameters' => ['sometimes', 'array', 'max:50'],
        ];
        foreach (['tokenEndpoint', 'authorizationEndpoint', 'userInfoEndpoint', 'clientId', 'scope', 'clientSecret'] as $key) {
            $rules['oauth.'.$key] = ['sometimes', 'string', 'max:500'];
        }

        return $rules;
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
        $extra = $body['oauth']['extraParameters'] ?? null;
        if (is_array($extra)) {
            foreach ($extra as $key => $value) {
                if (! is_string($key) || $key === '' || (! is_scalar($value) && $value !== null)) {
                    $errors['oauth.extraParameters'] = self::make('invalidType', []);
                }
            }
            if (strlen((string) json_encode($extra)) > 4000) {
                $errors['oauth.extraParameters'] = self::make('tooLong', ['max' => 4000]);
            }
        }

        return $errors;
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return $this->jsonBody();
    }
}
