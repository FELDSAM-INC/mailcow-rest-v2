<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Models\Mailcow\Domain;
use App\Rules\DkimSelector;
use App\Services\Domains\DomainRules;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Body of `createDomain` (`DomainCreateRequest`): the types, bounds, enumerations and patterns of
 * the contract. The business rules (name syntax, whole MiB, quota order, tags, template) are
 * DomainRules', so that all of them are reported together. Administrators only; the policy runs
 * before validation.
 */
class StoreDomainRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('create', Domain::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $quota = ['bail', 'sometimes', 'integer', 'between:1048576,'.DomainRules::MAX_QUOTA];
        $limit = ['bail', 'sometimes', 'integer', 'between:0,'.DomainRules::MAX_INT];
        $flag = ['bail', 'sometimes', 'boolean'];
        $rateLimit = fn (): bool => is_array($this->input('rateLimit'));

        return [
            'name' => ['bail', 'required', 'string', 'max:255'],
            'templateId' => ['bail', 'sometimes', 'integer', 'min:1'],
            'description' => ['bail', 'sometimes', 'string', 'max:255'],
            'active' => $flag,
            'maxMailboxes' => $limit,
            'maxAliases' => $limit,
            'defaultMailboxQuota' => $quota,
            'maxMailboxQuota' => $quota,
            'domainQuota' => $quota,
            'backupMx' => $flag,
            'relayAllRecipients' => $flag,
            'relayUnknownOnly' => $flag,
            'globalAddressList' => $flag,
            'restartGroupware' => $flag,
            'tags' => ['bail', 'sometimes', 'array'],
            'tags.*' => ['bail', 'string'],
            'rateLimit' => ['bail', 'nullable', 'array'],
            'rateLimit.value' => ['bail', Rule::requiredIf($rateLimit), 'integer', 'between:1,'.DomainRules::MAX_INT],
            'rateLimit.frame' => ['bail', Rule::requiredIf($rateLimit), 'string', 'in:second,minute,hour,day'],
            'dkimSelector' => ['bail', 'sometimes', 'string', 'max:255', new DkimSelector],
            'dkimKeySize' => ['bail', 'sometimes', 'integer', 'in:1024,2048,3072,4096'],
        ];
    }

    /**
     * The members that were sent: an absent member takes the template's value, an explicit `null`
     * rate limit means none.
     *
     * @return array<string, mixed>
     */
    public function members(): array
    {
        $body = json_decode((string) $this->getContent(), true);

        return is_array($body) ? $body : [];
    }
}
