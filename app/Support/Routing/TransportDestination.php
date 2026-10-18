<?php

namespace App\Support\Routing;

use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Support\Domain\DomainName;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A transport destination (feature 009 FR-035, research R6):
 *
 *  - not MX-based: `*`, a domain, `.domain` (its subdomains) or an e-mail address, trimmed, domains
 *    normalised like 002's DomainName (UTS #46 to ASCII), lower-cased as a whole
 *    (`invalidFormat`, `format: transportDestination`);
 *  - MX-based: a regular expression stored as given after trimming, valid when MariaDB can evaluate
 *    it the way Postfix's `mysql_mbr_access_maps.cf` does (`'%s' REGEXP destination`), checked with
 *    `SELECT '' REGEXP ?` (error 1139 gives `invalidRegex`); matching ignores letter case through
 *    the column's collation.
 */
final class TransportDestination
{
    public const MAX_LENGTH = 255;

    /** The stored form, or null when the value is not a valid destination of the kind. */
    public static function normalize(string $raw, bool $mxBased): ?string
    {
        $value = trim($raw);

        if ($value === '' || mb_strlen($value) > self::MAX_LENGTH) {
            return null;
        }

        if ($mxBased) {
            return self::evaluable($value) ? $value : null;
        }

        $stored = match (true) {
            $value === '*' => '*',
            str_contains($value, '@') => self::address($value),
            str_starts_with($value, '.') => ($domain = self::domain(substr($value, 1))) === null ? null : '.'.$domain,
            default => self::domain($value),
        };

        return $stored !== null && strlen($stored) <= self::MAX_LENGTH ? $stored : null;
    }

    /**
     * The violation of a submitted destination, or null.
     *
     * @return array{code: string, params: array<string, mixed>, message: string}|null
     */
    public static function violation(mixed $raw, bool $mxBased): ?array
    {
        if (! is_string($raw)) {
            return ApiFormRequest::make('invalidType', ['expected' => 'string']);
        }

        $value = trim($raw);

        return match (true) {
            $value === '' => ApiFormRequest::make('required', []),
            mb_strlen($value) > self::MAX_LENGTH => ApiFormRequest::make('tooLong', ['max' => self::MAX_LENGTH]),
            self::normalize($value, $mxBased) !== null => null,
            $mxBased => ApiFormRequest::make('invalidRegex', []),
            default => ApiFormRequest::make('invalidFormat', ['format' => 'transportDestination']),
        };
    }

    private static function domain(string $value): ?string
    {
        if (str_starts_with($value, '.')) {
            return null;
        }

        return DomainName::normalizeValid($value);
    }

    private static function address(string $value): ?string
    {
        $at = strrpos($value, '@');

        if ($at === false || $at === 0) {
            return null;
        }

        $domain = self::domain(substr($value, $at + 1));

        if ($domain === null) {
            return null;
        }

        $address = mb_strtolower(substr($value, 0, $at)).'@'.$domain;

        return filter_var($address, FILTER_VALIDATE_EMAIL) === false ? null : $address;
    }

    private static function evaluable(string $pattern): bool
    {
        try {
            DB::selectOne("SELECT '' REGEXP ? AS `matched`", [$pattern]);

            return true;
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1139) {
                return false;
            }

            throw $e;
        }
    }
}
