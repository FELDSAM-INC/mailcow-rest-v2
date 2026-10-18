<?php

namespace App\Support\Domain;

use ValueError;

/**
 * Domain names as legacy mailcow handles them (feature 002 research D4), shared by every feature
 * that addresses domains, alias domains or the domain part of addresses:
 *
 *  - normalize(): `idn_to_ascii(strtolower(trim($name)), 0, INTL_IDNA_VARIANT_UTS46)`, the
 *    normalisation of `mailbox('add', 'domain')` and `mailbox('get', 'domain_details')`;
 *  - isValid(): `is_valid_domain_name()` of functions.inc.php (labels of letters, digits and inner
 *    hyphens; 1 to 253 characters; labels of 1 to 63; single labels allowed);
 *  - toUnicode(): the readable form, falling back to the ASCII form when a label does not decode.
 */
final class DomainName
{
    /** The mb_strtolower() + idn_to_ascii() form, or null when UTS #46 rejects the name. */
    public static function normalize(string $name): ?string
    {
        $name = mb_strtolower(trim($name));

        if ($name === '') {
            return null;
        }

        try {
            $ascii = idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        } catch (ValueError) {
            return null;
        }

        return is_string($ascii) && $ascii !== '' ? $ascii : null;
    }

    /** Legacy `is_valid_domain_name($name)` without options. */
    public static function isValid(string $name): bool
    {
        if ($name === '') {
            return false;
        }

        try {
            $ascii = idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        } catch (ValueError) {
            return false;
        }

        if (! is_string($ascii)) {
            return false;
        }

        return preg_match('/^([a-z\d](-*[a-z\d])*)(\.([a-z\d](-*[a-z\d])*))*$/i', $ascii) === 1
            && preg_match('/^.{1,253}$/', $ascii) === 1
            && preg_match('/^[^\.]{1,63}(\.[^\.]{1,63})*$/', $ascii) === 1;
    }

    /** The normalised name when it is also a valid domain name, otherwise null. */
    public static function normalizeValid(string $name): ?string
    {
        $ascii = self::normalize($name);

        return $ascii !== null && self::isValid($ascii) ? $ascii : null;
    }

    /** The readable (Unicode) form of an ASCII name; the ASCII form when it does not decode. */
    public static function toUnicode(string $ascii): string
    {
        if ($ascii === '') {
            return $ascii;
        }

        try {
            $unicode = idn_to_utf8($ascii, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        } catch (ValueError) {
            return $ascii;
        }

        return is_string($unicode) && $unicode !== '' ? $unicode : $ascii;
    }

    /**
     * A `LIKE` pattern matching addresses in the domain (`%@<domain>`), with `%`, `_` and `\`
     * escaped.
     */
    public static function addressPattern(string $domain): string
    {
        return '%@'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $domain);
    }
}
