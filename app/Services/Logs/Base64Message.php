<?php

namespace App\Services\Logs;

/**
 * Messages stored with a `base64,` prefix (feature 011 research R12, FR-023): decoded when the rest
 * is valid base64 of UTF-8 text, otherwise returned without the prefix, as the legacy dashboard's
 * `atob` fallback shows them. mailcow's ACME producer writes the prefix without encoding.
 */
final class Base64Message
{
    public static function decode(string $message): string
    {
        if (! str_starts_with($message, 'base64,')) {
            return $message;
        }

        $rest = substr($message, 7);
        $decoded = base64_decode($rest, true);

        return $decoded !== false && $decoded !== '' && mb_check_encoding($decoded, 'UTF-8') ? $decoded : $rest;
    }
}
