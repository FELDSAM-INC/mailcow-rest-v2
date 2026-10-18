<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Models\Mailcow\Domain;
use App\Services\Domains\DomainRules;
use App\Services\Domains\DomainScope;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Body of `updateDomain` (`DomainPatch`, JSON Merge Patch): at least one member (else `required` on
 * `body`), and the types and bounds of the editable members. Read-only members are accepted with
 * any value here and compared later (`immutable`). The role is checked first (`403`), then the
 * scope (`404`), then the body (`422`).
 */
class UpdateDomainRequest extends ApiFormRequest
{
    protected bool $requiresNonEmptyBody = true;

    private ?string $domain = null;

    public function authorize(): bool
    {
        $principal = RequestContext::requirePrincipal($this);

        if (! Gate::forUser($principal)->allows('update', Domain::class)) {
            return false;
        }

        $this->domain = app(DomainScope::class)->resolve($principal, (string) $this->route('domain'))
            ?? throw new NotFoundHttpException('The domain does not exist.');

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $quota = ['bail', 'sometimes', 'integer', 'between:1048576,'.DomainRules::MAX_QUOTA];
        $limit = ['bail', 'sometimes', 'integer', 'between:0,'.DomainRules::MAX_INT];
        $flag = ['bail', 'sometimes', 'boolean'];

        return [
            'description' => ['bail', 'sometimes', 'nullable', 'string', 'max:255'],
            'active' => $flag,
            'maxMailboxes' => $limit,
            'maxAliases' => $limit,
            'maxMailboxQuota' => $quota,
            'domainQuota' => $quota,
            'backupMx' => $flag,
            'relayAllRecipients' => $flag,
            'relayUnknownOnly' => $flag,
            'globalAddressList' => $flag,
            'tags' => ['bail', 'sometimes', 'array'],
            'tags.*' => ['bail', 'string'],
        ];
    }

    /** The resolved ASCII name of the domain in the caller's scope. */
    public function domain(): string
    {
        return (string) $this->domain;
    }

    /**
     * @return array<string, mixed>
     */
    public function members(): array
    {
        $body = json_decode((string) $this->getContent(), true);

        return is_array($body) ? $body : [];
    }
}
