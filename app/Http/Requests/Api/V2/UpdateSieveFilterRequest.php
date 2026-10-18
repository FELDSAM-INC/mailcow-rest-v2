<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\ResolvesSelfServiceMailbox;
use App\Rules\CharacterLength;
use App\Rules\SieveScript;
use App\Rules\StrictBoolean;
use App\Services\SieveFilters\SieveFilterService;

/**
 * Body of `updateSieveFilter` (`SieveFilterPatch`, JSON Merge Patch, feature 005 FR-050): at least
 * one member, each with the rules of creation; the mailbox is never a member (`immutable`).
 */
class UpdateSieveFilterRequest extends ApiFormRequest
{
    use ResolvesSelfServiceMailbox;

    protected bool $requiresNonEmptyBody = true;

    protected function afterMailboxResolved(): void
    {
        app(SieveFilterService::class)->assertMayChange(RequestContext::requirePrincipal($this));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'description' => ['bail', 'sometimes', 'string', new CharacterLength(1, 255)],
            'kind' => ['bail', 'sometimes', 'filled', 'string', 'in:'.implode(',', SieveFilterService::KINDS)],
            'script' => ['bail', 'sometimes', 'string', new CharacterLength(1, 65535, true), new SieveScript],
            'active' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }

    protected function memberViolations(array $body): array
    {
        return StoreSieveFilterRequest::unknownMembersOf($body, StoreSieveFilterRequest::MEMBERS);
    }

    /**
     * @return array{description?: string, kind?: string, script?: string, active?: bool}
     */
    public function members(): array
    {
        /** @var array{description?: string, kind?: string, script?: string, active?: bool} */
        return $this->only(StoreSieveFilterRequest::MEMBERS);
    }
}
