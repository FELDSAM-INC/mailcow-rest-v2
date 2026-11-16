<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Rules\EmailAddress;
use App\Services\SyncSources\SourceScope;

class SyncSourceOAuthRequest extends ApiFormRequest
{
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
        if ($this->routeIs('sync-source-oauth.complete')) {
            return ['state' => ['required', 'string', 'max:200'], 'code' => ['required', 'string', 'max:8192']];
        }

        return ['mailbox' => ['required', new EmailAddress(rejectControlCharacters: true)],
            ...($this->isMethod('DELETE') ? ['remoteUser' => ['required', 'string', 'max:255']] : [])];
    }

    public function value(string $field): string
    {
        return (string) ($this->jsonBody()[$field] ?? '');
    }
}
