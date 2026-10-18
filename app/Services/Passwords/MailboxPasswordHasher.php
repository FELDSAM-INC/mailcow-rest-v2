<?php

namespace App\Services\Passwords;

/**
 * Legacy hash_password() (feature 003 FR-024, research R3), shared by features 005, 007 and 012:
 *
 *  - a value that starts with a hash-scheme prefix legacy recognises (`{SSHA512}…`, any letter case)
 *    is stored as given, for migrations from other mail servers;
 *  - otherwise the password is hashed in mailcow's scheme (`MAILCOW_PASS_SCHEME`): `SSHA`, `SSHA256`
 *    or `SSHA512` with a salt of 16 hexadecimal characters used as the salt bytes, exactly as legacy,
 *    or `BLF-CRYPT` (bcrypt, cost 10) for that and every other value.
 *
 * Dovecot and legacy verify both. Nothing here is ever logged.
 */
class MailboxPasswordHasher
{
    /** The prefixes of legacy's pre-hash pattern, in its order (G31). */
    public const PRE_HASHED_SCHEMES = [
        'ARGON2I', 'ARGON2ID', 'BLF-CRYPT', 'CLEAR', 'CLEARTEXT', 'CRYPT', 'DES-CRYPT', 'LDAP-MD5', 'MD5',
        'MD5-CRYPT', 'PBKDF2', 'PLAIN', 'PLAIN-MD4', 'PLAIN-MD5', 'PLAIN-TRUNC', 'SHA', 'SHA1', 'SHA256',
        'SHA256-CRYPT', 'SHA512', 'SHA512-CRYPT', 'SMD5', 'SSHA', 'SSHA256', 'SSHA512',
    ];

    public function hash(string $password): HashedPassword
    {
        if (self::isPreHashed($password)) {
            return new HashedPassword($password, true);
        }

        $scheme = strtoupper((string) config('restv2.mailcow_pass_scheme', 'BLF-CRYPT'));
        $digest = ['SSHA' => 'sha1', 'SSHA256' => 'sha256', 'SSHA512' => 'sha512'][$scheme] ?? null;

        if ($digest !== null) {
            $salt = bin2hex(random_bytes(8));

            return new HashedPassword('{'.$scheme.'}'.base64_encode(hash($digest, $password.$salt, true).$salt), false);
        }

        return new HashedPassword('{BLF-CRYPT}'.password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]), false);
    }

    /**
     * Whether a value starts with a hash-scheme prefix legacy recognises.
     */
    public static function isPreHashed(string $value): bool
    {
        return preg_match('/^{('.implode('|', array_map(fn (string $s): string => preg_quote($s, '/'), self::PRE_HASHED_SCHEMES)).')}/i', $value) === 1;
    }
}
