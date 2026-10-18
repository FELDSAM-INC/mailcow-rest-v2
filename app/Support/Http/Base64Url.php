<?php

namespace App\Support\Http;

/**
 * Base64url without padding (RFC 4648 section 5), as WebAuthn JSON and version tags use it.
 */
final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * The decoded bytes, or null when the value is not unpadded or padded base64url.
     */
    public static function decode(string $value): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]*={0,2}$/', $value) !== 1 || strlen(rtrim($value, '=')) % 4 === 1) {
            return null;
        }

        $decoded = base64_decode(strtr(rtrim($value, '='), '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
