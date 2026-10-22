<?php

namespace App\Http\Requests\Api\V2\Concerns;

use App\Rules\DkimSelector;
use App\Services\Domains\DomainRules;
use Illuminate\Validation\Rule;

/**
 * The contract types and bounds of the values that domains and domain templates share.
 */
trait DomainValueRules
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function valueRules(): array
    {
        $quota = ['bail', 'sometimes', 'integer', 'between:1048576,'.DomainRules::MAX_QUOTA];
        $limit = ['bail', 'sometimes', 'integer', 'between:0,'.DomainRules::MAX_INT];
        $flag = ['bail', 'sometimes', 'boolean'];
        $rateLimit = fn (): bool => is_array($this->input('rateLimit'));

        return [
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
            'rateLimit' => ['bail', 'nullable', 'array'],
            'rateLimit.value' => ['bail', Rule::requiredIf($rateLimit), 'integer', 'between:1,'.DomainRules::MAX_INT],
            'rateLimit.frame' => ['bail', Rule::requiredIf($rateLimit), 'string', 'in:second,minute,hour,day'],
            'dkimSelector' => ['bail', 'sometimes', 'string', 'max:255', new DkimSelector],
            'dkimKeySize' => ['bail', 'sometimes', 'integer', 'in:1024,2048,3072,4096'],
        ];
    }

    /**
     * The members that were sent (merge semantics).
     *
     * @return array<string, mixed>
     */
    public function members(): array
    {
        return $this->jsonBody();
    }
}
