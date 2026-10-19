<?php

namespace App\Services\Quarantine;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Clients\SharedStore\SharedStore;
use App\Services\Audit\AuditLogger;
use App\Support\Http\ETag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Redis;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * The quarantine settings in mailcow's shared store (feature 006 research R8, FR-029 to FR-034,
 * FR-059 to FR-061), the same eleven `Q_*` keys and formats as legacy's quarantine('edit',
 * 'settings'), so that the ingestion (`pipe.php`), the notifier and the daily cleanup use them
 * unchanged.
 *
 *  - Read: one `MGET`; unset or empty values mean what they mean to the legacy components
 *    (inactive, no score limit, 365 days, the original format, `quarantine@localhost`, the default
 *    subject, no copy or redirect recipient, the default template). The version tag covers the
 *    eleven raw values (a missing key distinct from an empty one), so a legacy save changes it.
 *  - Change: WATCH the keys, re-read, compare `If-Match` (`412`), write only the members whose
 *    value changes, in legacy's formats (`Q_MAX_SIZE` in MiB, the excluded domains as a JSON list,
 *    empty strings for defaults, `raw` for the original format), with the audit record written in
 *    the database transaction before EXEC; a concurrent change makes the plan run once more on the
 *    fresh values. `validateOnly` stops before writing. An unavailable store answers `503`.
 */
class QuarantineSettingsService
{
    public const KEYS = ['Q_RETENTION_SIZE', 'Q_MAX_SIZE', 'Q_MAX_SCORE', 'Q_MAX_AGE', 'Q_EXCLUDE_DOMAINS', 'Q_RELEASE_FORMAT', 'Q_SENDER', 'Q_SUBJ', 'Q_BCC', 'Q_REDIRECT', 'Q_HTML'];

    public const DEFAULT_SENDER = 'quarantine@localhost';

    public const DEFAULT_SUBJECT = 'Spam Quarantine Notification';

    public const MIB = 1048576;

    /** API member => key. */
    private const MEMBERS = [
        'retentionPerMailbox' => 'Q_RETENTION_SIZE',
        'maxMessageSize' => 'Q_MAX_SIZE',
        'notificationScoreLimit' => 'Q_MAX_SCORE',
        'maxAgeDays' => 'Q_MAX_AGE',
        'excludedDomains' => 'Q_EXCLUDE_DOMAINS',
        'releaseFormat' => 'Q_RELEASE_FORMAT',
        'notificationSender' => 'Q_SENDER',
        'notificationSubject' => 'Q_SUBJ',
        'copyRecipient' => 'Q_BCC',
        'redirectRecipient' => 'Q_REDIRECT',
        'notificationTemplate' => 'Q_HTML',
    ];

    public function __construct(
        private readonly SharedStore $store,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The full settings (contract `QuarantineSettings`, `visibility: full`).
     *
     * @return array<string, mixed>
     */
    public function read(): array
    {
        return $this->present($this->raw());
    }

    /**
     * The public subset every role may read (FR-034).
     *
     * @return array{visibility: string, active: bool, retentionPerMailbox: int, maxMessageSize: int, etag: string}
     */
    public function readPublic(): array
    {
        $settings = $this->read();

        return [
            'visibility' => 'public',
            'active' => $settings['active'],
            'retentionPerMailbox' => $settings['retentionPerMailbox'],
            'maxMessageSize' => $settings['maxMessageSize'],
            'etag' => $settings['etag'],
        ];
    }

    /**
     * The stored excluded domains (normalised as stored), for the rule that a stored entry may be
     * sent back although its domain no longer exists.
     *
     * @return list<string>
     */
    public function storedExcludedDomains(): array
    {
        return self::excludedDomains($this->store->run(fn (Redis $redis): mixed => $redis->get('Q_EXCLUDE_DOMAINS')));
    }

    /**
     * The stored template, or the default template when none is stored.
     */
    public function effectiveTemplate(): string
    {
        $stored = $this->store->run(fn (Redis $redis): mixed => $redis->get('Q_HTML'));

        return is_string($stored) && $stored !== '' ? $stored : self::defaultTemplate();
    }

    /** The notification subject the notifier uses. */
    public function effectiveSubject(): string
    {
        $stored = $this->store->run(fn (Redis $redis): mixed => $redis->get('Q_SUBJ'));

        return is_string($stored) && $stored !== '' ? $stored : self::DEFAULT_SUBJECT;
    }

    /**
     * Applies a validated merge patch and returns the new settings, or null with `validateOnly`.
     *
     * @param  array<string, mixed>  $patch  API members, already validated and normalised
     * @return array<string, mixed>|null
     */
    public function update(Principal $principal, array $patch, ?string $ifMatch, bool $validateOnly = false): ?array
    {
        $this->store->transactionWatching(self::KEYS, function (Redis $redis) use ($patch, $ifMatch, $validateOnly): array {
            $raw = self::rawFrom($redis);
            $current = $this->present($raw);

            if (ETag::preconditionFails($ifMatch, $current['etag'])) {
                throw new PreconditionFailedHttpException('The quarantine settings have changed since they were read.');
            }

            if ($validateOnly) {
                return [null, null];
            }

            $writes = [];
            $changes = [];

            foreach ($patch as $member => $value) {
                $next = self::apiValue($member, $value);

                if ($next === $current[$member]) {
                    continue;
                }

                $writes[self::MEMBERS[$member]] = self::storedValue($member, $value);
                $changes[$member] = $member === 'notificationTemplate'
                    ? ['changed' => true, 'oldSize' => strlen((string) $current[$member]), 'newSize' => strlen((string) $next)]
                    : ['old' => $current[$member], 'new' => $next];
            }

            if ($writes === []) {
                return [null, $changes];
            }

            return [function (Redis $multi) use ($writes): void {
                foreach ($writes as $key => $value) {
                    $multi->set($key, $value);
                }
            }, $changes];
        }, function (mixed $changes) use ($principal): void {
            $this->audit->record(AuditEventType::QuarantineSettingsUpdate, true, $principal, ['type' => 'quarantineSettings', 'id' => null, 'name' => null], null, null, ['changes' => $changes]);
        });

        return $validateOnly ? null : $this->read();
    }

    /**
     * @return array<string, string|null> key => raw value (null when missing)
     */
    private function raw(): array
    {
        return $this->store->run(fn (Redis $redis): array => self::rawFrom($redis));
    }

    /**
     * @return array<string, string|null>
     */
    private static function rawFrom(Redis $redis): array
    {
        /** @var array<int, string|false>|false $values */
        $values = $redis->mget(self::KEYS);
        $raw = [];

        foreach (self::KEYS as $i => $key) {
            $value = is_array($values) ? ($values[$i] ?? false) : false;
            $raw[$key] = is_string($value) ? $value : null;
        }

        return $raw;
    }

    /**
     * @param  array<string, string|null>  $raw
     * @return array<string, mixed>
     */
    private function present(array $raw): array
    {
        $retention = max(0, (int) ($raw['Q_RETENTION_SIZE'] ?? 0));
        $size = max(0, (int) ($raw['Q_MAX_SIZE'] ?? 0)) * self::MIB;
        $age = (int) ($raw['Q_MAX_AGE'] ?? 0);
        $excluded = self::excludedDomains($raw['Q_EXCLUDE_DOMAINS']);
        sort($excluded);
        $sender = (string) $raw['Q_SENDER'];
        $subject = (string) $raw['Q_SUBJ'];
        $template = (string) $raw['Q_HTML'];

        return [
            'visibility' => 'full',
            'active' => $retention > 0 && $size > 0,
            'retentionPerMailbox' => $retention,
            'maxMessageSize' => $size,
            'notificationScoreLimit' => is_numeric($raw['Q_MAX_SCORE']) ? (float) $raw['Q_MAX_SCORE'] : null,
            'maxAgeDays' => $age > 0 ? $age : 365,
            'excludedDomains' => $excluded,
            'missingExcludedDomains' => self::missing($excluded),
            'releaseFormat' => $raw['Q_RELEASE_FORMAT'] === 'attachment' ? 'attachment' : 'original',
            'notificationSender' => $sender !== '' ? $sender : self::DEFAULT_SENDER,
            'notificationSenderIsDefault' => $sender === '',
            'notificationSubject' => $subject !== '' ? $subject : self::DEFAULT_SUBJECT,
            'notificationSubjectIsDefault' => $subject === '',
            'copyRecipient' => (string) $raw['Q_BCC'] !== '' ? (string) $raw['Q_BCC'] : null,
            'redirectRecipient' => (string) $raw['Q_REDIRECT'] !== '' ? (string) $raw['Q_REDIRECT'] : null,
            'notificationTemplate' => $template !== '' ? $template : self::defaultTemplate(),
            'notificationTemplateIsDefault' => $template === '',
            'etag' => ETag::of(['quarantineSettings', ...array_values($raw)]),
        ];
    }

    /** A patch member's value as the read would present it after the change. */
    private static function apiValue(string $member, mixed $value): mixed
    {
        return match ($member) {
            'notificationScoreLimit' => $value === null || $value === '' ? null : (float) $value,
            'excludedDomains' => (function () use ($value): array {
                $list = array_values((array) $value);
                sort($list);

                return $list;
            })(),
            'notificationSender' => $value === null || $value === '' ? self::DEFAULT_SENDER : $value,
            'notificationSubject' => $value === null || $value === '' ? self::DEFAULT_SUBJECT : $value,
            'copyRecipient', 'redirectRecipient' => $value === null || $value === '' ? null : $value,
            'notificationTemplate' => $value === null || $value === '' ? self::defaultTemplate() : $value,
            default => $value,
        };
    }

    /** A patch member's value in legacy's storage format. */
    private static function storedValue(string $member, mixed $value): string
    {
        return match ($member) {
            'maxMessageSize' => (string) intdiv((int) $value, self::MIB),
            'notificationScoreLimit' => $value === null || $value === '' ? '' : (string) (float) $value,
            'excludedDomains' => (string) json_encode(array_values((array) $value)),
            'releaseFormat' => $value === 'attachment' ? 'attachment' : 'raw',
            default => $value === null ? '' : (string) (is_bool($value) ? (int) $value : $value),
        };
    }

    /**
     * @return list<string>
     */
    private static function excludedDomains(mixed $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? array_values(array_filter(array_map(fn (mixed $v): ?string => is_string($v) ? $v : null, $decoded), fn (?string $v): bool => $v !== null)) : [];
    }

    /**
     * The entries that are no longer a domain or an alias domain.
     *
     * @param  list<string>  $domains
     * @return list<string>
     */
    private static function missing(array $domains): array
    {
        if ($domains === []) {
            return [];
        }

        $existing = DB::table('domain')->whereIn('domain', $domains)->pluck('domain')
            ->merge(DB::table('alias_domain')->whereIn('alias_domain', $domains)->pluck('alias_domain'))
            ->map(fn (mixed $d): string => mb_strtolower((string) $d))->all();

        return array_values(array_filter($domains, fn (string $d): bool => ! in_array(mb_strtolower($d), $existing, true)));
    }

    /**
     * The default notification template (legacy /tpls/quarantine.tpl): the file of mailcow's
     * templates mount, or the bundled 2026-09a copy when it cannot be read.
     */
    public static function defaultTemplate(): string
    {
        $path = (string) config('restv2.quarantine.default_template');
        $path = str_starts_with($path, '/') ? $path : base_path($path);
        $content = is_readable($path) ? @file_get_contents($path) : false;

        if (! is_string($content) || $content === '') {
            Log::warning("The default quarantine notification template {$path} cannot be read; the bundled copy is used.");
            $content = (string) file_get_contents(resource_path('legacy/quarantine.tpl'));
        }

        return $content;
    }
}
