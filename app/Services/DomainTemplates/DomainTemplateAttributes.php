<?php

namespace App\Services\DomainTemplates;

/**
 * The `attributes` JSON of domain templates (research D22), with the legacy keys that legacy's add
 * form reads: quotas in bytes, `rl_value` a numeric string as legacy's form stores it (`""` for no
 * rate limit), flags as 0/1. Reading is tolerant of what legacy and earlier API versions have
 * stored (numeric strings or integers, `tags` as a JSON string, missing keys); writing produces
 * exactly the legacy keys. Values use the contract's names.
 *
 * The default mailbox quota (`def_quota_for_mbox`) is not one of the values (owner decision
 * 2026-10-18): storedDefaultQuota() reads it and encode() takes it separately, so that legacy's
 * add-domain form keeps finding it.
 */
class DomainTemplateAttributes
{
    private const MIB = 1048576;

    /** Legacy rate limit unit => contract frame. */
    private const FRAMES = ['s' => 'second', 'm' => 'minute', 'h' => 'hour', 'd' => 'day'];

    /**
     * The built-in values of FR-046 (and FR-022), the seeded Default template with the configured
     * default DKIM selector.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'tags' => [],
            'maxMailboxes' => 10,
            'maxAliases' => 400,
            'maxMailboxQuota' => 10240 * self::MIB,
            'domainQuota' => 10240 * self::MIB,
            'rateLimit' => null,
            'active' => true,
            'globalAddressList' => true,
            'backupMx' => false,
            'relayAllRecipients' => false,
            'relayUnknownOnly' => false,
            'dkimSelector' => (string) config('restv2.dkim.default_selector', 'dkim'),
            'dkimKeySize' => (int) config('restv2.dkim.default_key_size', 2048),
        ];
    }

    /**
     * @return array<string, mixed> contract names => values
     */
    public function decode(?string $json): array
    {
        $values = $this->defaults();
        $stored = $json === null ? null : json_decode($json, true);

        if (! is_array($stored)) {
            return $values;
        }

        $tags = $stored['tags'] ?? [];
        $tags = is_string($tags) ? json_decode($tags, true) : $tags;
        $values['tags'] = is_array($tags) ? array_values(array_map(fn (bool|float|int|string $t): string => (string) $t, array_filter($tags, 'is_scalar'))) : [];

        $values['maxAliases'] = self::int($stored, 'max_num_aliases_for_domain', $values['maxAliases']);
        $values['maxMailboxes'] = self::int($stored, 'max_num_mboxes_for_domain', $values['maxMailboxes']);
        $values['maxMailboxQuota'] = self::int($stored, 'max_quota_for_mbox', $values['maxMailboxQuota']);
        $values['domainQuota'] = self::int($stored, 'max_quota_for_domain', $values['domainQuota']);

        $rlValue = $stored['rl_value'] ?? '';
        $rlFrame = self::FRAMES[(string) ($stored['rl_frame'] ?? 's')] ?? 'second';
        $values['rateLimit'] = is_numeric($rlValue) && (int) $rlValue >= 1 ? ['value' => (int) $rlValue, 'frame' => $rlFrame] : null;

        $values['active'] = self::bool($stored, 'active', $values['active']);
        $values['globalAddressList'] = self::bool($stored, 'gal', $values['globalAddressList']);
        $values['backupMx'] = self::bool($stored, 'backupmx', $values['backupMx']);
        $values['relayAllRecipients'] = self::bool($stored, 'relay_all_recipients', $values['relayAllRecipients']);
        $values['relayUnknownOnly'] = self::bool($stored, 'relay_unknown_only', $values['relayUnknownOnly']);

        if (isset($stored['dkim_selector']) && is_scalar($stored['dkim_selector'])) {
            $values['dkimSelector'] = (string) $stored['dkim_selector'];
        }

        $values['dkimKeySize'] = self::int($stored, 'key_size', $values['dkimKeySize']);

        return $values;
    }

    /**
     * The stored default mailbox quota in bytes, or null when the key is missing or not a number.
     */
    public function storedDefaultQuota(?string $json): ?int
    {
        $stored = $json === null ? null : json_decode($json, true);

        return is_array($stored) && isset($stored['def_quota_for_mbox']) && is_numeric($stored['def_quota_for_mbox']) ? (int) $stored['def_quota_for_mbox'] : null;
    }

    /**
     * The legacy JSON of contract values (as decode() returns them) and the default mailbox quota
     * kept for legacy (bytes).
     *
     * @param  array<string, mixed>  $values
     */
    public function encode(array $values, int $defaultMailboxQuota): string
    {
        $rate = $values['rateLimit'] ?? null;

        return (string) json_encode([
            'tags' => array_values($values['tags'] ?? []),
            'max_num_aliases_for_domain' => (int) $values['maxAliases'],
            'max_num_mboxes_for_domain' => (int) $values['maxMailboxes'],
            'def_quota_for_mbox' => $defaultMailboxQuota,
            'max_quota_for_mbox' => (int) $values['maxMailboxQuota'],
            'max_quota_for_domain' => (int) $values['domainQuota'],
            'rl_frame' => is_array($rate) ? (string) (array_search($rate['frame'], self::FRAMES, true) ?: 's') : 's',
            'rl_value' => is_array($rate) ? (string) (int) $rate['value'] : '',
            'active' => (int) (bool) $values['active'],
            'gal' => (int) (bool) $values['globalAddressList'],
            'backupmx' => (int) (bool) $values['backupMx'],
            'relay_all_recipients' => (int) (bool) $values['relayAllRecipients'],
            'relay_unknown_only' => (int) (bool) $values['relayUnknownOnly'],
            'dkim_selector' => (string) $values['dkimSelector'],
            'key_size' => (int) $values['dkimKeySize'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    private static function int(array $stored, string $key, int $default): int
    {
        return isset($stored[$key]) && is_numeric($stored[$key]) ? (int) $stored[$key] : $default;
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    private static function bool(array $stored, string $key, bool $default): bool
    {
        return isset($stored[$key]) && is_scalar($stored[$key]) ? (bool) (int) $stored[$key] : $default;
    }
}
