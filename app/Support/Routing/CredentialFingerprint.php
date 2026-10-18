<?php

namespace App\Support\Routing;

/**
 * The form in which a stored relayhost or transport password enters a version tag (feature 009
 * research R2, as feature 008 does for sync job passwords): an HMAC-SHA-256 keyed with the
 * application key. A tag then changes with the password (FR-008), but holding a tag never allows
 * guessing the password offline (FR-012). Rotating the key changes the tags, which only makes
 * clients read again.
 */
final class CredentialFingerprint
{
    public static function of(string $password): string
    {
        return hash_hmac('sha256', $password, (string) config('app.key'));
    }
}
