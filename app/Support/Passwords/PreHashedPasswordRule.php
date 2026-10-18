<?php

namespace App\Support\Passwords;

use App\Services\Passwords\MailboxPasswordHasher;

/**
 * Refuses a self-service password that starts with a hash-scheme prefix legacy hash_password()
 * recognises (feature 005 FR-009, research R4): legacy would store such a value as given,
 * bypassing the password policy. Feature 003 keeps pre-hashed values for administrators.
 */
final class PreHashedPasswordRule
{
    /**
     * The recognised scheme the value starts with, upper-case, or null.
     */
    public static function scheme(string $password): ?string
    {
        if (! MailboxPasswordHasher::isPreHashed($password)) {
            return null;
        }

        preg_match('/^{([^}]+)}/', $password, $match);

        return strtoupper($match[1]);
    }
}
