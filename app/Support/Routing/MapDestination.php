<?php

namespace App\Support\Routing;

use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Support\Domain\DomainName;

/**
 * Destinations and parameters of recipient maps and TLS policy maps (feature 009 FR-061, FR-068,
 * FR-070, research R19), normalised the same way on creation and edit (legacy normalised only on
 * creation):
 *
 *  - recipient map destinations: trimmed, lower-cased, one leading `@` removed, then a domain name
 *    (stored `@<ascii>`) or an e-mail address (domain in ASCII), at most 255 characters as stored;
 *  - TLS policy destinations: trimmed, lower-cased, then a domain name, `.domain`, or a bracketed
 *    next hop with an optional port, in ASCII; `.`, `*`, `@` and a port without brackets are
 *    refused, because Postfix never looks them up;
 *  - TLS policy parameters: `name=value` items separated by whitespace, stored joined by single
 *    spaces, at most 255 characters.
 */
final class MapDestination
{
    public const MAX_LENGTH = 255;

    public const TLS_POLICIES = ['none', 'may', 'encrypt', 'dane', 'dane-only', 'fingerprint', 'verify', 'secure'];

    public static function recipientMap(string $raw): ?string
    {
        $value = mb_strtolower(trim($raw));
        $value = str_starts_with($value, '@') ? substr($value, 1) : $value;

        if ($value === '') {
            return null;
        }

        if (str_contains($value, '@')) {
            $at = (int) strrpos($value, '@');
            $domain = DomainName::normalizeValid(substr($value, $at + 1));

            if ($at === 0 || $domain === null) {
                return null;
            }

            $address = substr($value, 0, $at).'@'.$domain;

            // An address longer than the column is returned as is, so that it is refused as too long.
            return strlen($address) > self::MAX_LENGTH || filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : null;
        }

        $domain = DomainName::normalizeValid($value);

        return $domain === null ? null : '@'.$domain;
    }

    /**
     * @return array{code: string, params: array<string, mixed>, message: string}|null
     */
    public static function recipientMapViolation(mixed $raw): ?array
    {
        if (! is_string($raw)) {
            return ApiFormRequest::make('invalidType', ['expected' => 'string']);
        }

        if (trim($raw) === '') {
            return ApiFormRequest::make('required', []);
        }

        $stored = self::recipientMap($raw);

        return match (true) {
            $stored === null => ApiFormRequest::make('invalidFormat', ['format' => 'recipientMapDestination']),
            strlen($stored) > self::MAX_LENGTH => ApiFormRequest::make('tooLong', ['max' => self::MAX_LENGTH]),
            default => null,
        };
    }

    public static function tlsPolicy(string $raw): ?string
    {
        $value = mb_strtolower(trim($raw));

        if ($value === '' || $value === '.' || $value === '*' || str_contains($value, '@')) {
            return null;
        }

        if (str_starts_with($value, '[')) {
            $hop = NextHop::tryParse($value);

            return $hop !== null && $hop->bracketed() ? $hop->stored() : null;
        }

        if (str_starts_with($value, '.')) {
            $domain = str_starts_with(substr($value, 1), '.') ? null : DomainName::normalizeValid(substr($value, 1));

            return $domain === null ? null : '.'.$domain;
        }

        return DomainName::normalizeValid($value);
    }

    /**
     * @return array{code: string, params: array<string, mixed>, message: string}|null
     */
    public static function tlsPolicyViolation(mixed $raw): ?array
    {
        if (! is_string($raw)) {
            return ApiFormRequest::make('invalidType', ['expected' => 'string']);
        }

        if (trim($raw) === '') {
            return ApiFormRequest::make('required', []);
        }

        $stored = self::tlsPolicy($raw);

        return match (true) {
            $stored === null => ApiFormRequest::make('invalidFormat', ['format' => 'tlsPolicyDestination']),
            strlen($stored) > self::MAX_LENGTH => ApiFormRequest::make('tooLong', ['max' => self::MAX_LENGTH]),
            default => null,
        };
    }

    /** The stored parameters (`""` for none), or null when an item is not `name=value`. */
    public static function tlsParameters(string $raw): ?string
    {
        $items = preg_split('/\s+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($items as $item) {
            if (preg_match('/^[^=\s]+=\S+$/', $item) !== 1) {
                return null;
            }
        }

        return implode(' ', $items);
    }

    /**
     * @return array{code: string, params: array<string, mixed>, message: string}|null
     */
    public static function tlsParametersViolation(mixed $raw): ?array
    {
        if (! is_string($raw)) {
            return ApiFormRequest::make('invalidType', ['expected' => 'string']);
        }

        $stored = self::tlsParameters($raw);

        return match (true) {
            $stored === null => ApiFormRequest::make('invalidFormat', ['format' => 'tlsPolicyParameters']),
            strlen($stored) > self::MAX_LENGTH => ApiFormRequest::make('tooLong', ['max' => self::MAX_LENGTH]),
            default => null,
        };
    }

    /**
     * @return array{code: string, params: array<string, mixed>, message: string}|null
     */
    public static function tlsPolicyNameViolation(mixed $raw): ?array
    {
        if (! is_string($raw)) {
            return ApiFormRequest::make('invalidType', ['expected' => 'string']);
        }

        return in_array(strtolower(trim($raw)), self::TLS_POLICIES, true) ? null : ApiFormRequest::make('notInList', ['allowed' => self::TLS_POLICIES]);
    }
}
