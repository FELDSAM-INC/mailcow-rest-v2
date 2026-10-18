<?php

namespace App\Services\Logs;

use App\Services\Logs\Mappers\AcmeEntryMapper;
use App\Services\Logs\Mappers\AutodiscoverEntryMapper;
use App\Services\Logs\Mappers\CronEntryMapper;
use App\Services\Logs\Mappers\LegacyApiRequestEntryMapper;
use App\Services\Logs\Mappers\LogEntryMapper;
use App\Services\Logs\Mappers\NetfilterEntryMapper;
use App\Services\Logs\Mappers\RateLimitEntryMapper;
use App\Services\Logs\Mappers\SyslogEntryMapper;
use App\Services\Logs\Mappers\WatchdogEntryMapper;

/**
 * The twelve logs of feature 011 (FR-022, research R12 to R14), by their path under `/logs`: where
 * mailcow keeps them, whether their entries have a priority (and so a severity filter), and their
 * type-specific filters (query parameter => entry field).
 */
enum LogType: string
{
    case Postfix = 'postfix';
    case Dovecot = 'dovecot';
    case Sogo = 'sogo';
    case Netfilter = 'netfilter';
    case Autodiscover = 'autodiscover';
    case Watchdog = 'watchdog';
    case Acme = 'acme';
    case Cron = 'cron';
    case RateLimits = 'rate-limits';
    case LegacyApiRequests = 'legacy-api-requests';
    case LegacyUiActivity = 'legacy-ui-activity';
    case Logins = 'logins';

    /** The Redis list of an in-memory log; null for the two database logs. */
    public function redisKey(): ?string
    {
        return match ($this) {
            self::Postfix => 'POSTFIX_MAILLOG',
            self::Dovecot => 'DOVECOT_MAILLOG',
            self::Sogo => 'SOGO_LOG',
            self::Netfilter => 'NETFILTER_LOG',
            self::Autodiscover => 'AUTODISCOVER_LOG',
            self::Watchdog => 'WATCHDOG_LOG',
            self::Acme => 'ACME_LOG',
            self::Cron => 'CRON_LOG',
            self::RateLimits => 'RL_LOG',
            self::LegacyApiRequests => 'API_LOG',
            self::LegacyUiActivity, self::Logins => null,
        };
    }

    public function hasSeverity(): bool
    {
        return in_array($this, [self::Postfix, self::Dovecot, self::Sogo, self::Netfilter, self::Cron], true);
    }

    /**
     * Type-specific filters: query parameter => entry field.
     *
     * @return array<string, string>
     */
    public function filters(): array
    {
        return match ($this) {
            self::Postfix, self::Dovecot => ['priority' => 'priority', 'program' => 'program'],
            self::Sogo, self::Netfilter => ['priority' => 'priority'],
            self::Autodiscover => ['service' => 'service', 'user' => 'user'],
            self::Watchdog => ['service' => 'service'],
            self::Acme => [],
            self::Cron => ['task' => 'task', 'priority' => 'priority'],
            self::RateLimits => ['rateLimitName' => 'rateLimitName', 'sender' => 'sender', 'user' => 'user'],
            self::LegacyApiRequests => ['method' => 'method', 'clientAddress' => 'clientAddress'],
            self::LegacyUiActivity => ['type' => 'type', 'user' => 'user', 'role' => 'role'],
            self::Logins => ['username' => 'username', 'service' => 'service', 'clientAddress' => 'clientAddress'],
        };
    }

    public function mapper(): ?LogEntryMapper
    {
        return match ($this) {
            self::Postfix, self::Dovecot, self::Sogo => new SyslogEntryMapper,
            self::Netfilter => new NetfilterEntryMapper,
            self::Autodiscover => new AutodiscoverEntryMapper,
            self::Watchdog => new WatchdogEntryMapper,
            self::Acme => new AcmeEntryMapper,
            self::Cron => new CronEntryMapper,
            self::RateLimits => new RateLimitEntryMapper,
            self::LegacyApiRequests => new LegacyApiRequestEntryMapper(new LegacyApiDataParser, new SecretMasker),
            self::LegacyUiActivity, self::Logins => null,
        };
    }
}
