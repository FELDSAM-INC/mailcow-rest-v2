<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\ResolvesSelfServiceMailbox;
use App\Rules\CharacterLength;
use App\Rules\SieveScript;
use App\Rules\StrictBoolean;
use App\Services\SieveFilters\SieveFilterService;

/**
 * Body of `createSieveFilter` (`SieveFilterCreateRequest`, feature 005 FR-048, FR-049): the mailbox
 * in scope (`404`), then the caller's right to change filters (`403`), then the body (`422`):
 * description 1–255 characters, the kind, a script of 1–65,535 bytes that legacy's parser accepts.
 */
class StoreSieveFilterRequest extends ApiFormRequest
{
    use ResolvesSelfServiceMailbox;

    public const MEMBERS = ['description', 'kind', 'script', 'active'];

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
            'description' => ['bail', 'present', 'string', new CharacterLength(1, 255)],
            'kind' => ['bail', 'required', 'string', 'in:'.implode(',', SieveFilterService::KINDS)],
            'script' => ['bail', 'present', 'string', new CharacterLength(1, 65535, true), new SieveScript],
            'active' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }

    protected function memberViolations(array $body): array
    {
        return self::unknownMembersOf($body, self::MEMBERS);
    }

    /**
     * @param  array<mixed>  $body
     * @param  list<string>  $members
     * @return array<string, array{code: string, params: array<string, mixed>, message: string}>
     */
    public static function unknownMembersOf(array $body, array $members): array
    {
        $violations = [];

        foreach (array_keys($body) as $member) {
            if (! in_array((string) $member, $members, true)) {
                $violations[(string) $member] = self::make('immutable', [], 'This member cannot be set here.');
            }
        }

        return $violations;
    }

    /**
     * @return array{description: string, kind: string, script: string, active?: bool}
     */
    public function members(): array
    {
        /** @var array{description: string, kind: string, script: string, active?: bool} */
        return $this->only(self::MEMBERS);
    }
}
