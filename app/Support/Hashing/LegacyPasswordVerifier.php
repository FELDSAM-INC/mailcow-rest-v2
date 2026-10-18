<?php

namespace App\Support\Hashing;

/**
 * Port of legacy `verify_hash()` and `verify_salted_hash()` (mailcow-dockerized 2026-09a,
 * `data/web/inc/functions.inc.php`, research R12). The input is `{SCHEME}hash`, the scheme is
 * matched case-insensitively, and every scheme legacy and Dovecot accept is supported. It never
 * rehashes: signing in never changes a stored hash (FR-004).
 *
 * Differences from legacy, only where legacy would throw: malformed PBKDF2 and PLAIN-TRUNC values
 * fail instead of raising a PHP error.
 */
class LegacyPasswordVerifier
{
    /**
     * A BLF-CRYPT hash (cost 10, like legacy `hash_password()`) of a random value. The sign-in
     * verifies against it when no account matches, so that its timing does not reveal whether the
     * account exists (FR-005).
     */
    public const DUMMY_HASH = '{BLF-CRYPT}$2y$10$M.peyr/IUgtjofrXmhFbWO5gA4I5Dv8.jQPOa9m6i0Rrj6DcQDiMW';

    public function verify(string $hash, string $password): bool
    {
        if (preg_match('/^{(.+)}(.+)/i', $hash, $parts) !== 1) {
            return false;
        }

        $scheme = strtoupper($parts[1]);
        $hash = $parts[2];

        return match ($scheme) {
            'ARGON2I', 'ARGON2ID', 'BLF-CRYPT', 'CRYPT', 'DES-CRYPT', 'MD5-CRYPT', 'MD5', 'SHA256-CRYPT',
            'SHA512-CRYPT' => password_verify($password, $hash),
            // Loose comparison, as legacy does.
            'CLEAR', 'CLEARTEXT', 'PLAIN' => $password == $hash,
            'LDAP-MD5' => hash_equals(hash('md5', $password, true), (string) base64_decode($hash)),
            'PBKDF2' => $this->pbkdf2Sha1($hash, $password),
            'PBKDF2-SHA512' => $this->pbkdf2Sha512($hash, $password),
            'PLAIN-MD4' => hash_equals(hash('md4', $password), $hash),
            'PLAIN-MD5' => md5($password) == $hash,
            'PLAIN-TRUNC' => $this->plainTruncated($hash, $password),
            'SHA', 'SHA1', 'SHA256', 'SHA512' => hash_equals(
                hash($scheme === 'SHA' ? 'sha1' : strtolower($scheme), $password, true),
                (string) base64_decode($hash),
            ),
            'SMD5' => $this->salted($hash, $password, 'md5', 16),
            'SSHA' => $this->salted($hash, $password, 'sha1', 20),
            'SSHA256' => $this->salted($hash, $password, 'sha256', 32),
            'SSHA512' => $this->salted($hash, $password, 'sha512', 64),
            default => false,
        };
    }

    /**
     * Legacy `verify_salted_hash()`, including its salt extraction (`str_replace` of the digest).
     */
    private function salted(string $hash, string $password, string $algo, int $saltLength): bool
    {
        $decoded = (string) base64_decode($hash);
        $digest = substr($decoded, 0, $saltLength);
        $salt = $digest === '' ? $decoded : str_replace($digest, '', $decoded);

        return hash_equals(hash($algo, $password.$salt, true), $digest);
    }

    /**
     * Dovecot's `$1$<salt>$<rounds>$<hex sha1>` form.
     */
    private function pbkdf2Sha1(string $hash, string $password): bool
    {
        $components = explode('$', $hash);

        if (count($components) < 5 || ! ctype_digit($components[3]) || (int) $components[3] <= 0) {
            return false;
        }

        return hash_equals(hash_pbkdf2('sha1', $password, $components[2], (int) $components[3]), $components[4]);
    }

    /**
     * FreeIPA's `<iterations>$<base64 salt>$<base64 hash>` form.
     */
    private function pbkdf2Sha512(string $hash, string $password): bool
    {
        $components = explode('$', $hash);

        if (count($components) !== 3) {
            return false;
        }

        $iterations = intval($components[0]);
        $salt = base64_decode($components[1], true);
        $expected = base64_decode($components[2], true);

        if ($iterations <= 0 || $salt === false || $expected === false || $expected === '') {
            return false;
        }

        return hash_equals($expected, hash_pbkdf2('sha512', $password, $salt, $iterations, strlen($expected), true));
    }

    /**
     * `<length>-<password>`: the stored password is the first <length> characters; without a
     * hyphen, the whole value.
     */
    private function plainTruncated(string $hash, string $password): bool
    {
        $components = explode('-', $hash);

        if (count($components) <= 1) {
            return $password == $hash;
        }

        // Legacy passes the length to substr(), which accepts a leading-numeric string and throws
        // for any other.
        if (preg_match('/^\s*([+-]?\d+)/', $components[0], $length) !== 1) {
            return false;
        }

        return substr($password, 0, (int) $length[1]) == $components[1];
    }
}
