<?php

namespace App\Services\Passwords;

use App\Clients\SharedStore\SharedStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use Redis;

/**
 * Reads the password policy from mailcow's Redis hash `PASSWD_POLICY` on every call, without
 * caching (feature 003 FR-063, research R4): `length` when numeric, else 6; each class rule only
 * when its value equals 1, as legacy compares `== 1`. Shared with features 005, 007 and 013.
 */
class PasswordPolicyReader
{
    public const KEY = 'PASSWD_POLICY';

    public const DEFAULT_LENGTH = 6;

    public function __construct(private readonly SharedStore $store) {}

    /**
     * @throws SharedStoreUnavailable
     */
    public function effective(): EffectivePasswordPolicy
    {
        $stored = $this->store->run(function (Redis $redis): array {
            $values = $redis->hGetAll(self::KEY);

            return is_array($values) ? $values : [];
        });

        $flag = fn (string $field): bool => is_numeric($stored[$field] ?? null) && (int) $stored[$field] === 1;
        $length = $stored['length'] ?? null;

        return new EffectivePasswordPolicy(
            is_numeric($length) ? max(0, (int) $length) : self::DEFAULT_LENGTH,
            $flag('chars'),
            $flag('numbers'),
            $flag('special_chars'),
            $flag('lowerupper'),
        );
    }
}
