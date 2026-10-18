<?php

namespace App\Rules;

/**
 * An IPv4 or IPv6 address, or a prefix (IPv4 /0–/32, IPv6 /0–/128), at most 49 characters
 * (`invalidFormat`, `format: ipNetwork`).
 */
class IpNetwork extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        return is_string($value) && self::normalize($value) !== null
            ? null
            : $this->violation('invalidFormat', ['format' => 'ipNetwork']);
    }

    /**
     * The canonical `address/prefix` form (`/32` or `/128` for a single address), or null when the
     * value is not an address or prefix.
     */
    public static function normalize(string $value): ?string
    {
        if (strlen($value) > 49) {
            return null;
        }

        [$address, $prefix] = array_pad(explode('/', $value, 2), 2, null);
        $packed = filter_var($address, FILTER_VALIDATE_IP) !== false ? inet_pton((string) $address) : false;

        if ($packed === false) {
            return null;
        }

        $bits = strlen($packed) === 4 ? 32 : 128;

        if ($prefix === null) {
            $prefix = (string) $bits;
        } elseif (preg_match('/^(0|[1-9]\d{0,2})$/', $prefix) !== 1 || (int) $prefix > $bits) {
            return null;
        }

        return inet_ntop($packed).'/'.$prefix;
    }
}
