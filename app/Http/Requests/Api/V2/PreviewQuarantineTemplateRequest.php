<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\QuarantinePolicy;
use App\Rules\CodedRule;
use App\Rules\NotificationTemplate;
use App\Rules\StrictBoolean;

/**
 * Body of `previewQuarantineTemplate` (`QuarantineTemplatePreviewRequest`, feature 006 FR-071):
 * optional; `template` a non-empty string of at most 1 MiB or `null` (the stored or default
 * template), `quarantineAcl` a boolean (default `true`). Administrators only (`forbidden`).
 */
class PreviewQuarantineTemplateRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        app(QuarantinePolicy::class)->authorize(RequestContext::requirePrincipal($this), QuarantinePolicy::PREVIEW_TEMPLATE);

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'template' => ['bail', 'nullable', 'string', 'min:1', new class extends CodedRule
            {
                protected function check(mixed $value): ?array
                {
                    return is_string($value) && strlen($value) > NotificationTemplate::MAX_BYTES
                        ? $this->violation('tooLong', ['max' => NotificationTemplate::MAX_BYTES, 'unit' => 'bytes'])
                        : null;
                }
            }],
            'quarantineAcl' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }

    protected function memberViolations(array $body): array
    {
        $violations = [];

        foreach (array_keys($body) as $member) {
            if (! in_array($member, ['template', 'quarantineAcl'], true)) {
                $violations[(string) $member] = self::make('unknownField', [], 'This member is not accepted here.');
            }
        }

        return $violations;
    }

    public function template(): ?string
    {
        $template = $this->json('template');

        return is_string($template) ? $template : null;
    }

    public function quarantineAcl(): bool
    {
        return $this->json('quarantineAcl', true) !== false;
    }
}
