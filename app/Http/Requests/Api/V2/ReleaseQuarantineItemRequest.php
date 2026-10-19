<?php

namespace App\Http\Requests\Api\V2;

use App\Rules\StrictBoolean;

/**
 * Body of `releaseQuarantineItem` (`QuarantineReleaseRequest`, feature 006 FR-022): optional; its
 * only member `learnAsHam` defaults to `true` (legacy "Deliver to inbox"); `false` is the legacy
 * quick release. Other members are `unknownField`.
 */
class ReleaseQuarantineItemRequest extends ApiFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['learnAsHam' => ['bail', 'sometimes', new StrictBoolean]];
    }

    protected function memberViolations(array $body): array
    {
        $violations = [];

        foreach (array_keys($body) as $member) {
            if ($member !== 'learnAsHam') {
                $violations[(string) $member] = self::make('unknownField', [], 'This member is not accepted here.');
            }
        }

        return $violations;
    }

    public function learnAsHam(): bool
    {
        return $this->input('learnAsHam', true) !== false;
    }
}
