<?php

namespace Tests\Unit\Support;

use App\Support\Mailcow\UnicodeSearch;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature 004 T011 (FR-015, research R19): a non-ASCII search term also matches the readable
 * (Unicode) form of stored ASCII (punycode) values, which MariaDB cannot compute; an ASCII term needs
 * no conversion.
 */
class UnicodeSearchTest extends TestCase
{
    public function test_non_ascii_terms_match_the_readable_form_of_scoped_rows(): void
    {
        $f = $this->mailcow();
        $f->domain('xn--bcher-kva.example');
        $f->domain('other.example');
        $books = $f->alias('info@xn--bcher-kva.example', 'anna@other.example');
        $f->alias('info@other.example', 'anna@other.example');
        $outside = $f->alias('sales@xn--bcher-kva.example', 'anna@other.example');
        $scoped = DB::table('alias')->where('id', '<>', $outside);

        $this->assertSame([$books], UnicodeSearch::matchingKeys(clone $scoped, 'id', ['address'], 'bücher'));
        $this->assertSame([$books], UnicodeSearch::matchingKeys(clone $scoped, 'id', ['address'], 'ÜCHER'));
        $this->assertSame([], UnicodeSearch::matchingKeys(clone $scoped, 'id', ['address'], 'münchen'));
        $this->assertSame([], UnicodeSearch::matchingKeys(clone $scoped, 'id', ['address'], 'bucher'));
    }

    public function test_domain_name_columns_are_converted_as_a_whole(): void
    {
        $f = $this->mailcow();
        $f->domain('example.org');
        $f->aliasDomain('xn--bcher-kva.example', 'example.org');

        $this->assertSame(['xn--bcher-kva.example'], UnicodeSearch::matchingKeys(DB::table('alias_domain'), 'alias_domain', ['alias_domain', 'target_domain'], 'büch'));
    }
}
