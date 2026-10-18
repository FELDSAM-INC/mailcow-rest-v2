<?php

namespace App\Support\Mailcow;

use App\Services\Aliases\AliasAddress;
use App\Support\Domain\DomainName;
use App\Support\Http\ListQuery;
use Illuminate\Database\Query\Builder;

/**
 * The readable-form part of a list search (feature 004 FR-015, research R19). Domains are stored in
 * ASCII (punycode) form and MariaDB cannot decode them, so a search term with a non-ASCII character
 * is also matched against the readable form of the scoped rows that hold an `xn--` label: those rows
 * are read with their key, decoded with `idn_to_utf8` and compared case-insensitively. An ASCII term
 * needs nothing: the readable form of an ASCII label is the label itself.
 */
final class UnicodeSearch
{
    /**
     * The keys of the rows of the (scoped) query whose readable form of any column contains the
     * term. Columns holding an `@` are decoded as addresses, others as domain names.
     *
     * @param  list<string>  $columns
     * @return list<int|string>
     */
    public static function matchingKeys(Builder $query, string $keyColumn, array $columns, string $search): array
    {
        $search = trim($search);

        if ($search === '' || mb_check_encoding($search, 'ASCII')) {
            return [];
        }

        $pattern = ListQuery::likePattern('xn--');
        $query->where(function (Builder $inner) use ($columns, $pattern): void {
            foreach ($columns as $column) {
                $inner->orWhere($column, 'like', $pattern);
            }
        });

        $keys = [];
        $needle = mb_strtolower($search);

        foreach ($query->get([$keyColumn.' as __key', ...$columns]) as $row) {
            foreach ($columns as $column) {
                $name = str_contains($column, '.') ? substr($column, (int) strrpos($column, '.') + 1) : $column;
                $value = (string) ($row->{$name} ?? '');
                $readable = str_contains($value, '@') ? AliasAddress::unicodeOf($value) : DomainName::toUnicode($value);

                if (mb_stripos(mb_strtolower($readable), $needle) !== false) {
                    $keys[] = $row->__key;

                    break;
                }
            }
        }

        return array_values(array_unique($keys));
    }
}
