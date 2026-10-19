<?php

namespace App\Http\Requests\Api\V2;

/**
 * Body of `updateMailboxQuarantineNotificationSettings` (`QuarantineNotificationSettingsPatch`,
 * feature 006 FR-036): at least one of `frequency` and `category`; the flags of a mailbox user are
 * checked by the service per member that would change.
 */
class UpdateQuarantineNotificationSettingsRequest extends ApiFormRequest
{
    protected bool $requiresNonEmptyBody = true;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'frequency' => ['bail', 'sometimes', 'string', 'in:never,hourly,daily,weekly'],
            'category' => ['bail', 'sometimes', 'string', 'in:rejected,junk,all'],
        ];
    }

    protected function memberViolations(array $body): array
    {
        $violations = [];

        foreach (array_keys($body) as $member) {
            if (! in_array($member, ['frequency', 'category'], true)) {
                $violations[(string) $member] = self::make('unknownField', [], 'This member is not accepted here.');
            }
        }

        return $violations;
    }

    /**
     * @return array{frequency?: string, category?: string}
     */
    public function patch(): array
    {
        /** @var array{frequency?: string, category?: string} */
        return $this->only(['frequency', 'category']);
    }
}
