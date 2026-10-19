<?php

namespace App\Http\Requests\Api\V2\Settings;

use App\Rules\CharacterLength;
use App\Rules\EmailAddress;
use App\Rules\SingleLineText;
use App\Services\Settings\SettingsLimits;

/**
 * Body of `updatePasswordResetNotification` (`PasswordResetNotificationPatch`, feature 013 FR-040):
 * the sender empty or a valid e-mail address without control characters (legacy stores an invalid
 * one empty), the subject at most 255 characters on one line, each template at most 1 MiB.
 */
class UpdatePasswordResetNotificationRequest extends SettingsRequest
{
    protected const MEMBERS = ['sender', 'subject', 'textTemplate', 'htmlTemplate'];

    protected bool $requiresNonEmptyBody = true;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $template = ['bail', 'sometimes', 'string', new CharacterLength(0, SettingsLimits::TEMPLATE_MAX_BYTES, bytes: true)];

        return [
            'sender' => ['bail', 'sometimes', 'string', 'max:255', new EmailAddress(allowEmpty: true, rejectControlCharacters: true)],
            'subject' => ['bail', 'sometimes', 'string', 'max:'.SettingsLimits::SINGLE_LINE_TEXT_MAX_LENGTH, new SingleLineText],
            'textTemplate' => $template,
            'htmlTemplate' => $template,
        ];
    }
}
