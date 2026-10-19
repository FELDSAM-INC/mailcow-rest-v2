<?php

namespace App\Http\Requests\Api\V2\Settings;

use App\Rules\CharacterLength;
use App\Rules\EmailAddress;
use App\Rules\SingleLineText;
use App\Services\Settings\SettingsLimits;

/**
 * Body of `updateQuotaNotificationSettings` (`QuotaNotificationSettingsPatch`, feature 013 FR-043):
 * the sender empty or a valid e-mail address without control characters, the subject at most 255
 * characters on one line, the template at most 1 MiB and not checked (Dovecot falls back to its
 * default template when it cannot use it). Legacy's unused `retention_size` and `release_format`
 * inputs are not offered.
 */
class UpdateQuotaNotificationSettingsRequest extends SettingsRequest
{
    protected const MEMBERS = ['sender', 'subject', 'htmlTemplate'];

    protected bool $requiresNonEmptyBody = true;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'sender' => ['bail', 'sometimes', 'string', 'max:255', new EmailAddress(allowEmpty: true, rejectControlCharacters: true)],
            'subject' => ['bail', 'sometimes', 'string', 'max:'.SettingsLimits::SINGLE_LINE_TEXT_MAX_LENGTH, new SingleLineText],
            'htmlTemplate' => ['bail', 'sometimes', 'string', new CharacterLength(0, SettingsLimits::TEMPLATE_MAX_BYTES, bytes: true)],
        ];
    }
}
