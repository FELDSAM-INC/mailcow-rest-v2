<?php

namespace App\Http\Requests\Api\V2\Settings;

use App\Auth\Principal;
use App\Policies\QuotaNotificationRecipientsPolicy;
use App\Policies\SettingsPolicy;
use App\Rules\EmailAddress;
use App\Rules\StrictBoolean;
use App\Services\Settings\SettingsLimits;

/**
 * Body of `updateQuotaNotificationRecipients` (`QuotaNotificationRecipientsPatch`, feature 013
 * FR-047): at most 100 addresses, each trimmed and a valid e-mail address without control
 * characters (an invalid entry refuses the request and is named, `recipients.2`; legacy saves the
 * valid ones), and `active`. The role and the domain's scope are checked before the rules
 * (QuotaNotificationRecipientsPolicy: `403` for mailbox users, `404` outside the scope).
 */
class UpdateQuotaNotificationRecipientsRequest extends SettingsRequest
{
    protected const MEMBERS = ['recipients', 'active'];

    protected bool $requiresNonEmptyBody = true;

    private ?string $domain = null;

    protected function authorizeCaller(Principal $principal, SettingsPolicy $policy): void
    {
        $this->domain = app(QuotaNotificationRecipientsPolicy::class)->resolve($principal, (string) $this->route('domain'));
    }

    /** The normalised domain within the caller's scope. */
    public function domain(): string
    {
        return (string) $this->domain;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'recipients' => ['bail', 'sometimes', 'array', 'list', 'max:'.SettingsLimits::QUOTA_NOTIFICATION_RECIPIENTS_MAX],
            'recipients.*' => ['bail', 'string', 'max:255', new class extends EmailAddress
            {
                public function __construct()
                {
                    parent::__construct(false, true);
                }

                protected function check(mixed $value): ?array
                {
                    return parent::check(is_string($value) ? trim($value) : $value);
                }
            }],
            'active' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }

    /**
     * The sent members, the recipients trimmed and deduplicated case-insensitively (the first
     * spelling kept).
     *
     * @return array{recipients?: list<string>, active?: bool}
     */
    public function values(): array
    {
        $values = [];

        if (is_array($this->json('recipients'))) {
            $seen = [];
            $values['recipients'] = [];

            foreach ((array) $this->json('recipients') as $recipient) {
                $recipient = trim((string) $recipient);

                if (! isset($seen[mb_strtolower($recipient)])) {
                    $seen[mb_strtolower($recipient)] = true;
                    $values['recipients'][] = $recipient;
                }
            }
        }

        if (is_bool($this->json('active'))) {
            $values['active'] = (bool) $this->json('active');
        }

        return $values;
    }
}
