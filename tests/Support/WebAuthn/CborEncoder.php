<?php

namespace Tests\Support\WebAuthn;

use InvalidArgumentException;

/**
 * Minimal CBOR encoder (RFC 8949) for WebAuthn attestation objects and COSE keys in tests:
 * unsigned and negative integers, byte strings (CborBytes), text strings, arrays (PHP lists) and
 * maps (PHP associative arrays, or CborMap for an empty or explicit map).
 */
final class CborEncoder
{
    private const MAJOR_UNSIGNED = 0;

    private const MAJOR_NEGATIVE = 1;

    private const MAJOR_BYTES = 2;

    private const MAJOR_TEXT = 3;

    private const MAJOR_ARRAY = 4;

    private const MAJOR_MAP = 5;

    public static function encode(mixed $value): string
    {
        return match (true) {
            is_int($value) && $value >= 0 => self::head(self::MAJOR_UNSIGNED, $value),
            is_int($value) => self::head(self::MAJOR_NEGATIVE, -1 - $value),
            $value instanceof CborBytes => self::head(self::MAJOR_BYTES, strlen($value->bytes)).$value->bytes,
            is_string($value) => self::head(self::MAJOR_TEXT, strlen($value)).$value,
            $value instanceof CborMap => self::map($value->entries),
            is_array($value) && array_is_list($value) && $value !== [] => self::list($value),
            is_array($value) && $value === [] => self::head(self::MAJOR_ARRAY, 0),
            is_array($value) => self::map($value),
            default => throw new InvalidArgumentException('Unsupported CBOR value of type '.get_debug_type($value)),
        };
    }

    /**
     * @param  list<mixed>  $items
     */
    private static function list(array $items): string
    {
        $out = self::head(self::MAJOR_ARRAY, count($items));

        foreach ($items as $item) {
            $out .= self::encode($item);
        }

        return $out;
    }

    /**
     * @param  array<int|string, mixed>  $entries
     */
    private static function map(array $entries): string
    {
        $out = self::head(self::MAJOR_MAP, count($entries));

        foreach ($entries as $key => $value) {
            $out .= self::encode($key).self::encode($value);
        }

        return $out;
    }

    private static function head(int $major, int $argument): string
    {
        $type = $major << 5;

        return match (true) {
            $argument < 24 => chr($type | $argument),
            $argument <= 0xFF => chr($type | 24).chr($argument),
            $argument <= 0xFFFF => chr($type | 25).pack('n', $argument),
            $argument <= 0xFFFFFFFF => chr($type | 26).pack('N', $argument),
            default => chr($type | 27).pack('J', $argument),
        };
    }
}
