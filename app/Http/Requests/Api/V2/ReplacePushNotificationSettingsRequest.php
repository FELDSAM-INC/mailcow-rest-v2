<?php

namespace App\Http\Requests\Api\V2;

use App\Http\Requests\Api\V2\Concerns\ResolvesSelfServiceMailbox;
use App\Rules\DelimitedRegex;
use App\Rules\EmailAddress;
use App\Rules\MatchesFormat;
use App\Rules\StrictBoolean;
use App\Services\SelfService\SelfServiceCapabilitiesService as Limits;

/**
 * Body of `replacePushNotificationSettings` (`PushNotificationSettingsReplacement`, feature 005
 * FR-055): credentials of 30 letters or digits, the texts and senders within their limits, a
 * compiling delimited sender pattern and a sound name.
 */
class ReplacePushNotificationSettingsRequest extends ApiFormRequest
{
    use ResolvesSelfServiceMailbox;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $credential = fn (): array => ['bail', 'sometimes', 'string', new MatchesFormat('/^[A-Za-z0-9]{30}$/', 'pushoverCredential')];

        return [
            'token' => $credential(),
            'userKey' => $credential(),
            'title' => ['bail', 'sometimes', 'string', 'max:'.Limits::PUSH_TITLE_MAX_LENGTH],
            'text' => ['bail', 'sometimes', 'string', 'max:'.Limits::PUSH_TEXT_MAX_LENGTH],
            'senders' => ['bail', 'sometimes', 'array', 'list', 'max:'.Limits::PUSH_SENDERS_MAX],
            'senders.*' => ['bail', 'string', 'max:255', new EmailAddress],
            'senderPattern' => ['bail', 'sometimes', 'string', 'max:'.Limits::PUSH_SENDER_PATTERN_MAX_LENGTH, new DelimitedRegex],
            'sound' => ['bail', 'sometimes', 'string', new MatchesFormat('/^[A-Za-z0-9_-]{1,20}$/', 'soundName')],
            'active' => ['bail', 'sometimes', new StrictBoolean],
            'evaluatePriority' => ['bail', 'sometimes', new StrictBoolean],
            'onlyHighPriority' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function members(): array
    {
        return $this->only(['token', 'userKey', 'title', 'text', 'senders', 'senderPattern', 'sound', 'active', 'evaluatePriority', 'onlyHighPriority']);
    }
}
