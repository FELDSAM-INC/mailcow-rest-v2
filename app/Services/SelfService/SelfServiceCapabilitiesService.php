<?php

namespace App\Services\SelfService;

use App\Auth\Principal;
use App\Clients\SharedStore\SharedStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Services\Account\AccountCapabilities;
use App\Services\Config\MailcowConfig;
use App\Support\Http\ListQuery;
use Redis;

/**
 * The self-service capabilities (feature 005 FR-080, research R19), computed from the server's
 * current configuration on every request and never cached: the reset availability (legacy requires
 * a sender and a subject of the reset message, `PW_RESET_FROM` and `PW_RESET_SUBJ`) for everyone,
 * the limits and server settings for authenticated callers, and the account for sessions.
 */
class SelfServiceCapabilitiesService
{
    public const TEMPORARY_ALIAS_MIN_HOURS = 1;

    public const TEMPORARY_ALIAS_MAX_HOURS = 87600;

    public const TEMPORARY_ALIAS_DEFAULT_HOURS = 8760;

    public const RECENT_LOGIN_MIN_DAYS = 1;

    public const RECENT_LOGIN_MAX_DAYS = 31;

    public const RECENT_LOGIN_DEFAULT_DAYS = 7;

    public const NAME_MAX_LENGTH = 255;

    public const DESCRIPTION_MAX_LENGTH = 255;

    public const SIEVE_SCRIPT_MAX_BYTES = 65535;

    public const PUSH_TITLE_MAX_LENGTH = 250;

    public const PUSH_TEXT_MAX_LENGTH = 1024;

    public const PUSH_SENDERS_MAX = 100;

    public const PUSH_SENDER_PATTERN_MAX_LENGTH = 1024;

    public const PUSH_SOUND_MAX_LENGTH = 20;

    public const WEBMAIL_URL = '/SOGo/so/';

    /** Pushover's built-in sounds, in legacy's order (user/tab-pushover.twig). */
    public const PUSHOVER_SOUNDS = [
        'pushover', 'bike', 'bugle', 'cashregister', 'classical', 'cosmic', 'falling', 'gamelan', 'incoming',
        'intermission', 'magic', 'mechanical', 'pianobar', 'siren', 'spacealarm', 'tugboat', 'alien', 'climb',
        'persistent', 'echo', 'updown', 'vibrate', 'none',
    ];

    public function __construct(
        private readonly MailcowConfig $config,
        private readonly SharedStore $store,
        private readonly AccountCapabilities $accounts,
    ) {}

    /**
     * Whether password reset works: the reset message has a sender and a subject.
     *
     * @throws SharedStoreUnavailable
     */
    public function passwordResetAvailable(): bool
    {
        return $this->resetConfigured();
    }

    /**
     * @throws SharedStoreUnavailable
     */
    private function resetConfigured(): bool
    {
        [$from, $subject] = $this->store->run(fn (Redis $redis): array => [$redis->get('PW_RESET_FROM'), $redis->get('PW_RESET_SUBJ')]);

        return is_string($from) && $from !== '' && is_string($subject) && $subject !== '';
    }

    /**
     * @return array<string, mixed> contract `SelfServiceCapabilities`
     */
    public function describe(?Principal $principal, bool $session): array
    {
        try {
            $available = $this->resetConfigured();
        } catch (SharedStoreUnavailable) {
            // Without mailcow's shared store no reset can be requested either.
            $available = false;
        }

        $capabilities = ['passwordReset' => [
            'available' => $available,
            'tokenLifetimeMinutes' => $this->config->pwResetTokenLifetime(),
        ]];

        if ($principal === null) {
            return $capabilities;
        }

        $capabilities += [
            'groupware' => ['enabled' => ! config('restv2.skip_sogo'), 'webmailUrl' => self::WEBMAIL_URL],
            'temporaryAliases' => [
                'minLifetimeHours' => self::TEMPORARY_ALIAS_MIN_HOURS,
                'maxLifetimeHours' => self::TEMPORARY_ALIAS_MAX_HOURS,
                'defaultLifetimeHours' => self::TEMPORARY_ALIAS_DEFAULT_HOURS,
            ],
            'recentLogins' => [
                'minDays' => self::RECENT_LOGIN_MIN_DAYS,
                'maxDays' => self::RECENT_LOGIN_MAX_DAYS,
                'defaultDays' => self::RECENT_LOGIN_DEFAULT_DAYS,
                'countryLookup' => (bool) config('restv2.country_lookup'),
            ],
            'bulkLimit' => (int) config('restv2.bulk_limit'),
            'pageSize' => ['default' => ListQuery::DEFAULT_LIMIT, 'maximum' => ListQuery::MAX_LIMIT],
            'limits' => [
                'nameMaxLength' => self::NAME_MAX_LENGTH,
                'descriptionMaxLength' => self::DESCRIPTION_MAX_LENGTH,
                'sieveScriptMaxBytes' => self::SIEVE_SCRIPT_MAX_BYTES,
                'pushTitleMaxLength' => self::PUSH_TITLE_MAX_LENGTH,
                'pushTextMaxLength' => self::PUSH_TEXT_MAX_LENGTH,
                'pushSendersMax' => self::PUSH_SENDERS_MAX,
                'pushSenderPatternMaxLength' => self::PUSH_SENDER_PATTERN_MAX_LENGTH,
                'pushSoundMaxLength' => self::PUSH_SOUND_MAX_LENGTH,
            ],
            'pushoverSounds' => self::PUSHOVER_SOUNDS,
            'secondFactors' => [
                'totpIssuer' => $this->config->otpLabel(),
                'totpEnrolmentLifetimeSeconds' => (int) config('restv2.totp_enrolment_ttl'),
                'totpEnrolmentMaxAttempts' => (int) config('restv2.totp_enrolment_max_attempts'),
                'webauthnOptionsLifetimeSeconds' => max(1, (int) config('restv2.challenge_ttl')),
            ],
        ];

        if ($session) {
            $capabilities['account'] = $this->accounts->describe($principal);
        }

        return $capabilities;
    }
}
