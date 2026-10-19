<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\QuarantinePolicy;

/**
 * Body of `applyQuarantineNotificationBulkAction` (`QuarantineNotificationBulkRequest`, feature 006
 * FR-037): `frequency`, `category` or both (otherwise `required` on `body`) for 1 to 100 targets.
 * Administrators and domain administrators only (`forbidden` for mailbox users, before validation).
 */
class QuarantineNotificationBulkRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        app(QuarantinePolicy::class)->authorize(RequestContext::requirePrincipal($this), QuarantinePolicy::BULK_NOTIFICATIONS);

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'frequency' => ['bail', 'sometimes', 'string', 'in:never,hourly,daily,weekly'],
            'category' => ['bail', 'sometimes', 'string', 'in:rejected,junk,all'],
            'targets' => ['bail', 'required', 'array', 'list', 'min:1', 'max:'.QuarantineBulkActionRequest::LIMIT],
            'targets.*' => ['bail', 'array'],
            'targets.*.id' => ['bail', 'required', 'string', 'min:1'],
            'targets.*.version' => ['bail', 'sometimes', 'string'],
        ];
    }

    protected function memberViolations(array $body): array
    {
        $violations = [];

        foreach (array_keys($body) as $member) {
            if (! in_array($member, ['frequency', 'category', 'targets'], true)) {
                $violations[(string) $member] = self::make('unknownField', [], 'This member is not accepted here.');
            }
        }

        if (! array_key_exists('frequency', $body) && ! array_key_exists('category', $body)) {
            $violations['body'] = self::make('required', [], 'Send the frequency, the category or both.');
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

    /**
     * @return list<array{id: string, version: string|null}>
     */
    public function targets(): array
    {
        return array_values(array_map(
            fn (array $t): array => ['id' => (string) $t['id'], 'version' => isset($t['version']) ? (string) $t['version'] : null],
            (array) $this->validated('targets'),
        ));
    }
}
