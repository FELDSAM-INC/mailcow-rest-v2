<?php

namespace App\Services\Settings;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Services\Audit\AuditLogger;
use App\Support\Http\ETag;
use Redis;

/**
 * The copy recipients of a domain's quota notifications (feature 013 FR-046 to FR-049, research
 * R10) in legacy's hash `QW_BCC` (field: the domain), JSON `{"bcc_rcpts": [...], "active": 0|1}`,
 * which Dovecot's `quota_notify.py` reads. Always written as a JSON list with an integer `active`
 * (legacy writes an object after it dropped entries); an object stored by legacy is read as its
 * values. An empty list is stored inactive, as in legacy. The caller resolves the domain within its
 * scope first (002's DomainScope); the version tag covers the raw field.
 */
class QuotaNotificationRecipientsService
{
    public const KEY = 'QW_BCC';

    public function __construct(
        private readonly RedisSettingsStore $store,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Contract `QuotaNotificationRecipients`.
     *
     * @return array<string, mixed>
     */
    public function read(string $domain): array
    {
        $raw = $this->store->read(fn (Redis $redis): ?string => self::rawFrom($redis, $domain));

        return self::present($domain, $raw);
    }

    /**
     * @param  array{recipients?: list<string>, active?: bool}  $patch  validated and deduplicated
     * @return array<string, mixed>
     */
    public function update(string $domain, array $patch, ?string $ifMatch, Principal $actor): array
    {
        $this->store->transaction(
            [self::KEY],
            $ifMatch,
            fn (Redis $redis): array => ['value' => self::rawFrom($redis, $domain)],
            fn (array $raw): string => self::etag($domain, $raw['value']),
            function (array $raw) use ($domain, $patch): ?SettingsWrite {
                [$recipients, $active] = self::decode($raw['value']);
                $newRecipients = $patch['recipients'] ?? $recipients;
                $newActive = ($patch['active'] ?? $active) && $newRecipients !== [];
                $value = (string) json_encode(['bcc_rcpts' => $newRecipients, 'active' => $newActive ? 1 : 0]);

                if ($value === $raw['value']) {
                    return null;
                }

                $changes = [];

                if ($newRecipients !== $recipients) {
                    $changes['recipients'] = ['old' => $recipients, 'new' => $newRecipients];
                }

                if ($newActive !== $active) {
                    $changes['active'] = ['old' => $active, 'new' => $newActive];
                }

                return new SettingsWrite(
                    fn (Redis $multi) => $multi->hSet(self::KEY, $domain, $value),
                    fn (Redis $multi) => $raw['value'] === null ? $multi->hDel(self::KEY, $domain) : $multi->hSet(self::KEY, $domain, $raw['value']),
                    $changes,
                );
            },
            fn (mixed $changes) => $this->audit->record(AuditEventType::QuotaNotificationRecipientsUpdate, true, $actor, ['type' => 'quotaNotificationRecipients', 'id' => $domain, 'name' => $domain], null, null, ['changes' => $changes]),
        );

        return $this->read($domain);
    }

    private static function rawFrom(Redis $redis, string $domain): ?string
    {
        $value = $redis->hGet(self::KEY, $domain);

        return is_string($value) ? $value : null;
    }

    /**
     * @return array{list<string>, bool}
     */
    private static function decode(?string $raw): array
    {
        $decoded = json_decode((string) $raw, true);
        $list = is_array($decoded) && is_array($decoded['bcc_rcpts'] ?? null) ? $decoded['bcc_rcpts'] : [];
        $recipients = array_values(array_filter(array_map(fn (mixed $r): string => is_scalar($r) ? (string) $r : '', $list), fn (string $r): bool => $r !== ''));
        $active = is_array($decoded) && is_scalar($decoded['active'] ?? null) && (int) $decoded['active'] === 1;

        return [$recipients, $active && $recipients !== []];
    }

    private static function etag(string $domain, ?string $raw): string
    {
        return ETag::of(['quotaNotificationRecipients', $domain, $raw]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(string $domain, ?string $raw): array
    {
        [$recipients, $active] = self::decode($raw);

        return ['domain' => $domain, 'recipients' => $recipients, 'active' => $active, 'etag' => self::etag($domain, $raw)];
    }
}
