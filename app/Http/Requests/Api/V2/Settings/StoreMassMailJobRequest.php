<?php

namespace App\Http\Requests\Api\V2\Settings;

use App\Rules\CharacterLength;
use App\Rules\EmailAddress;
use App\Rules\SingleLineText;
use App\Rules\StrictInteger;
use App\Services\Settings\SettingsLimits;
use Illuminate\Contracts\Validation\Validator;

/**
 * Body of `createMassMailJob` (`MassMailJobCreateRequest`, feature 013 FR-074): a valid sender
 * without control characters, a subject of 1 to 255 characters on one line, a plain-text body
 * (required, at most 1 MiB), an optional HTML body (at most 1 MiB), the selection and the confirmed
 * recipient count (at least 1). A signed-in administrator only (the route refuses keys).
 */
class StoreMassMailJobRequest extends SettingsRequest
{
    use MassMailSelectionRules;

    protected const MEMBERS = ['sender', 'subject', 'textBody', 'htmlBody', 'selection', 'expectedRecipients'];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'sender' => ['bail', 'required', 'string', 'max:255', new EmailAddress(rejectControlCharacters: true)],
            'subject' => ['bail', 'required', 'string', 'max:'.SettingsLimits::SINGLE_LINE_TEXT_MAX_LENGTH, new SingleLineText],
            'textBody' => ['bail', 'required', 'string', new CharacterLength(1, SettingsLimits::MASS_MAIL_BODY_MAX_BYTES, bytes: true)],
            'htmlBody' => ['bail', 'nullable', 'string', new CharacterLength(0, SettingsLimits::MASS_MAIL_BODY_MAX_BYTES, bytes: true)],
            'expectedRecipients' => ['bail', 'required', new StrictInteger, 'integer', 'min:1'],
        ] + $this->selectionRules();
    }

    protected function additionalViolations(Validator $validator): array
    {
        return $this->selectionViolations($validator);
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->json()->all();
    }

    /**
     * @return array{sender: string, subject: string, textBody: string, htmlBody: string|null, expectedRecipients: int}
     */
    public function values(): array
    {
        $html = $this->json('htmlBody');

        return [
            'sender' => (string) $this->json('sender'),
            'subject' => (string) $this->json('subject'),
            'textBody' => (string) $this->json('textBody'),
            'htmlBody' => is_string($html) && $html !== '' ? $html : null,
            'expectedRecipients' => (int) $this->json('expectedRecipients'),
        ];
    }
}
