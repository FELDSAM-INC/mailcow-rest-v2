<?php

namespace App\Support\LegacySession;

use InvalidArgumentException;

/**
 * PHP's `php` session serialiser (`session.serialize_handler = php`, the legacy default), which
 * legacy's sessions.inc.php decodes (feature 005 research R3): `<key>|<serialize(value)>` for each
 * top-level key, concatenated. PHP itself cannot encode a key that contains `|`, so neither can
 * this. Used only for the webmail hand-off's legacy session.
 */
final class PhpSessionEncoder
{
    /**
     * @param  array<string, mixed>  $session
     */
    public static function encode(array $session): string
    {
        $encoded = '';

        foreach ($session as $key => $value) {
            $key = (string) $key;

            if (str_contains($key, '|')) {
                throw new InvalidArgumentException('A PHP session key cannot contain "|".');
            }

            $encoded .= $key.'|'.serialize($value);
        }

        return $encoded;
    }
}
