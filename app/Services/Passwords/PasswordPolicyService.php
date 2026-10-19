<?php

namespace App\Services\Passwords;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\RedisSettingsStore;
use App\Services\Settings\SettingsWrite;
use Redis;

/**
 * Feature 013's change of the password policy (FR-036 to FR-038, research R8), the write side of
 * feature 003's PasswordPolicyReader: the patch is merged into the effective policy (003's defaults
 * for rules never saved), and all five fields of `PASSWD_POLICY` are written, as legacy's
 * `password_complexity('edit')` writes them with `hMSet`. The version tag is 003's tag over the
 * effective rules, so the read and the change share it. No reload is needed: every password check
 * reads the hash.
 */
class PasswordPolicyService
{
    /** Members of the contract's `PasswordPolicy` and the legacy field each one is stored in. */
    private const FIELDS = [
        'minLength' => 'length',
        'requireLetters' => 'chars',
        'requireSpecialCharacters' => 'special_chars',
        'requireMixedCase' => 'lowerupper',
        'requireDigits' => 'numbers',
    ];

    public function __construct(
        private readonly RedisSettingsStore $store,
        private readonly AuditLogger $audit,
        private readonly PasswordPolicyReader $reader,
    ) {}

    /**
     * Applies a validated merge patch and returns the new policy.
     *
     * @param  array<string, int|bool>  $patch
     */
    public function update(array $patch, ?string $ifMatch, Principal $actor): EffectivePasswordPolicy
    {
        $this->store->transaction(
            [PasswordPolicyReader::KEY],
            $ifMatch,
            fn (Redis $redis): array => RedisSettingsStore::fieldsFrom($redis, PasswordPolicyReader::KEY, array_values(self::FIELDS)),
            fn (array $raw): string => self::effective($raw)->etag(),
            function (array $raw) use ($patch): ?SettingsWrite {
                $current = self::effective($raw)->toArray();
                $changes = [];
                $next = [];

                foreach (self::FIELDS as $member => $field) {
                    $value = array_key_exists($member, $patch) ? $patch[$member] : $current[$member];
                    $next[$field] = (string) (int) $value;

                    if ($value !== $current[$member]) {
                        $changes[$member] = ['old' => $current[$member], 'new' => $value];
                    }
                }

                if ($changes === []) {
                    return null;
                }

                return new SettingsWrite(
                    fn (Redis $multi) => $multi->hMSet(PasswordPolicyReader::KEY, $next),
                    function (Redis $multi) use ($raw): void {
                        foreach ($raw as $field => $value) {
                            $value === null ? $multi->hDel(PasswordPolicyReader::KEY, $field) : $multi->hSet(PasswordPolicyReader::KEY, $field, $value);
                        }
                    },
                    $changes,
                );
            },
            fn (mixed $changes) => $this->audit->record(AuditEventType::PasswordPolicyUpdate, true, $actor, ['type' => 'passwordPolicy', 'id' => null, 'name' => null], null, null, ['changes' => $changes]),
        );

        return $this->reader->effective();
    }

    /**
     * 003's reading of the raw fields: the length when numeric (else 6), each class only when 1.
     *
     * @param  array<string, string|null>  $raw
     */
    private static function effective(array $raw): EffectivePasswordPolicy
    {
        $flag = fn (string $field): bool => is_numeric($raw[$field] ?? null) && (int) $raw[$field] === 1;
        $length = $raw['length'] ?? null;

        return new EffectivePasswordPolicy(
            is_numeric($length) ? max(0, (int) $length) : PasswordPolicyReader::DEFAULT_LENGTH,
            $flag('chars'),
            $flag('numbers'),
            $flag('special_chars'),
            $flag('lowerupper'),
        );
    }
}
