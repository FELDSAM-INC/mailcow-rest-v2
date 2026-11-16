<?php

namespace App\Http\Requests\Api\V2;

use App\Auth\Role;
use App\Http\RequestContext;
use App\Rules\EmailAddress;
use App\Rules\StrictBoolean;
use App\Rules\StrictInteger;
use App\Services\MailboxGroups\GroupScope;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class WriteMailboxGroupRequest extends ApiFormRequest
{
    protected bool $requiresNonEmptyBody = true;

    public const FIELDS = ['domain', 'name', 'description', 'address', 'active', 'senderAllowed', 'sogoVisible', 'internal',
        'members', 'sendAs', 'imapGrants', 'sogoGrants', 'templateId', 'priority', 'identityReference', 'reapplyTemplate'];

    public function authorize(): bool
    {
        $actor = RequestContext::requirePrincipal($this);
        app(GroupScope::class)->authorize($actor);
        if ($actor->role !== Role::Admin && array_intersect(array_keys($this->jsonBody()), ['templateId', 'priority', 'identityReference', 'reapplyTemplate']) !== []) {
            throw new AccessDeniedHttpException('Only administrators can set group templates, priority and identity links.');
        }

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
            'domain' => ['bail', $required, 'string', 'max:255', Rule::exists('domain', 'domain')],
            'name' => ['bail', $required, 'filled', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/'],
            'description' => ['sometimes', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', new EmailAddress(rejectControlCharacters: true)],
            'templateId' => ['bail', 'sometimes', 'nullable', new StrictInteger, 'integer', 'min:1', Rule::exists('templates', 'id')->where('type', 'mailbox')],
            'priority' => ['bail', 'sometimes', new StrictInteger, 'integer', 'between:-1000000,1000000'],
            'identityReference' => ['sometimes', 'nullable', 'filled', 'string', 'max:255'],
            'members' => ['sometimes', 'array', 'list', 'max:10000'],
            'members.*' => ['bail', 'required', new EmailAddress(rejectControlCharacters: true), 'distinct:ignore_case'],
            'sendAs' => ['sometimes', 'array', 'list', 'max:1000'],
            'sendAs.*' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
            'imapGrants' => ['sometimes', 'array', 'list', 'max:1000'],
            'imapGrants.*' => ['required', 'array:owner,rights,subscribe'],
            'imapGrants.*.owner' => ['required', new EmailAddress(rejectControlCharacters: true), 'distinct:ignore_case'],
            'imapGrants.*.rights' => ['required', Rule::in(['read', 'write', 'full'])],
            'imapGrants.*.subscribe' => ['sometimes', new StrictBoolean],
            'sogoGrants' => ['sometimes', 'array', 'list', 'max:1000'],
            'sogoGrants.*' => ['required', 'array:owner,type,folder,access,subscribe'],
            'sogoGrants.*.owner' => ['required', new EmailAddress(rejectControlCharacters: true)],
            'sogoGrants.*.type' => ['required', Rule::in(['Calendar', 'Contacts'])],
            'sogoGrants.*.folder' => ['required', 'string', 'regex:/\A[A-Za-z0-9._-]{1,100}\z/'],
            'sogoGrants.*.access' => ['required', Rule::in(['read', 'write'])],
            'sogoGrants.*.subscribe' => ['sometimes', new StrictBoolean],
        ];
        foreach (['active', 'senderAllowed', 'sogoVisible', 'internal', 'reapplyTemplate'] as $field) {
            $rules[$field] = ['sometimes', new StrictBoolean];
        }

        return $rules;
    }

    /** @param array<string, mixed> $body
     * @return array<string, array{code: string, params: array<string, mixed>, message: string}>
     */
    protected function memberViolations(array $body): array
    {
        $errors = [];
        foreach ($body as $name => $value) {
            if (! in_array($name, self::FIELDS, true) || ($name === 'domain' && ! $this->isMethod('POST'))) {
                $errors[$name] = self::make('immutable', []);
            } elseif ($value === null && ! in_array($name, ['address', 'templateId', 'identityReference'], true)) {
                $errors[$name] = self::make('invalidType', []);
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
