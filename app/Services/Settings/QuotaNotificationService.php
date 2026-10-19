<?php

namespace App\Services\Settings;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Services\Audit\AuditLogger;
use App\Support\Http\ETag;
use Redis;

/**
 * The quota notification e-mail (feature 013 FR-043 to FR-045, research R9) in legacy's keys
 * `QW_SENDER`, `QW_SUBJ` and `QW_HTML`, which Dovecot's `quota_notify.py` reads for every
 * notification. Empty or missing values mean Dovecot's defaults, exactly as `quota_notify.py`
 * decides (`r.get(...) or default`): the sender `quota-warning@localhost`, the subject "Quota
 * warning" and mailcow's `quota.tpl` (feature 005's mounted template directory). An empty value is
 * stored as an empty string.
 */
class QuotaNotificationService
{
    public const KEYS = ['QW_SENDER', 'QW_SUBJ', 'QW_HTML'];

    public const DEFAULT_SENDER = 'quota-warning@localhost';

    public const DEFAULT_SUBJECT = 'Quota warning';

    private const MEMBERS = ['sender' => 'QW_SENDER', 'subject' => 'QW_SUBJ', 'htmlTemplate' => 'QW_HTML'];

    public function __construct(
        private readonly RedisSettingsStore $store,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Contract `QuotaNotificationSettings`.
     *
     * @return array<string, mixed>
     */
    public function read(): array
    {
        return self::present($this->store->strings(self::KEYS));
    }

    /**
     * @param  array<string, string>  $patch
     * @return array<string, mixed>
     */
    public function update(array $patch, ?string $ifMatch, Principal $actor): array
    {
        $this->store->transaction(
            self::KEYS,
            $ifMatch,
            fn (Redis $redis): array => RedisSettingsStore::stringsFrom($redis, self::KEYS),
            fn (array $raw): string => ETag::of(['quotaNotification', ...array_values($raw)]),
            function (array $raw) use ($patch): ?SettingsWrite {
                $writes = [];
                $changes = [];

                foreach (self::MEMBERS as $member => $key) {
                    $old = (string) ($raw[$key] ?? '');

                    if (! array_key_exists($member, $patch) || $patch[$member] === $old) {
                        continue;
                    }

                    $writes[$key] = $patch[$member];
                    $changes[$member] = $member === 'htmlTemplate'
                        ? ['changed' => true, 'oldBytes' => strlen($old), 'newBytes' => strlen($patch[$member])]
                        : ['old' => $old, 'new' => $patch[$member]];
                }

                if ($writes === []) {
                    return null;
                }

                return new SettingsWrite(
                    function (Redis $multi) use ($writes): void {
                        foreach ($writes as $key => $value) {
                            $multi->set($key, $value);
                        }
                    },
                    function (Redis $multi) use ($raw): void {
                        foreach ($raw as $key => $value) {
                            $value === null ? $multi->del($key) : $multi->set($key, $value);
                        }
                    },
                    $changes,
                );
            },
            fn (mixed $changes) => $this->audit->record(AuditEventType::QuotaNotificationUpdate, true, $actor, ['type' => 'quotaNotification', 'id' => null, 'name' => null], null, null, ['changes' => $changes]),
        );

        return $this->read();
    }

    /**
     * @param  array<string, string|null>  $raw
     * @return array<string, mixed>
     */
    private static function present(array $raw): array
    {
        $sender = (string) ($raw['QW_SENDER'] ?? '');
        $subject = (string) ($raw['QW_SUBJ'] ?? '');
        $template = (string) ($raw['QW_HTML'] ?? '');

        return [
            'sender' => $sender !== '' ? $sender : self::DEFAULT_SENDER,
            'senderIsDefault' => $sender === '',
            'subject' => $subject !== '' ? $subject : self::DEFAULT_SUBJECT,
            'subjectIsDefault' => $subject === '',
            'htmlTemplate' => $template !== '' ? $template : PasswordResetNotificationService::template('quota.tpl'),
            'htmlTemplateIsDefault' => $template === '',
            'etag' => ETag::of(['quotaNotification', ...array_values($raw)]),
        ];
    }
}
