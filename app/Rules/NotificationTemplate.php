<?php

namespace App\Rules;

use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Services\Quarantine\QuarantineTemplateRenderer;
use App\Services\Quarantine\TemplateCheckResult;

/**
 * A quarantine notification template the legacy notifier can use (feature 006 research R7,
 * FR-070): at most 1 MiB of UTF-8 (`tooLong` with `max` and `unit: bytes`), then parsed and rendered
 * with both validation datasets by the notifier's engine (`invalidTemplate` with `reason` and, when
 * known, `line`). The empty string is not checked: it restores the default template.
 */
class NotificationTemplate extends CodedRule
{
    public const MAX_BYTES = 1048576;

    protected function check(mixed $value): ?array
    {
        if (! is_string($value)) {
            return $this->violation('invalidType', ['expected' => 'string']);
        }

        if (strlen($value) > self::MAX_BYTES) {
            return $this->violation('tooLong', ['max' => self::MAX_BYTES, 'unit' => 'bytes']);
        }

        if ($value === '') {
            return null;
        }

        return self::violationOf(app(QuarantineTemplateRenderer::class)->check($value));
    }

    /**
     * The `invalidTemplate` violation of a failed check, or null.
     *
     * @return array{code: string, params: array<string, mixed>, message: string}|null
     */
    public static function violationOf(TemplateCheckResult $result): ?array
    {
        if ($result->ok) {
            return null;
        }

        return ApiFormRequest::make(
            'invalidTemplate',
            ['reason' => (string) $result->reason] + ($result->line === null ? [] : ['line' => $result->line]),
            'The legacy notifier cannot use this template ('.$result->reason.($result->line === null ? '' : ', line '.$result->line).'): '.mb_substr((string) $result->message, 0, 300),
        );
    }
}
