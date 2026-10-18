<?php

namespace Tests\Unit\Routing;

use App\Support\Routing\NextHop;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Feature 009 T009 (FR-011, FR-019, research R5): the next hop grammar shared by relayhost hosts,
 * transport next hops and bracketed TLS policy destinations.
 */
class NextHopTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function accepted(): array
    {
        return [
            'bracketed host with port' => ['[smtp.example.net]:587', '[smtp.example.net]:587'],
            'bare IPv4 with port' => ['192.0.2.10:25', '[192.0.2.10]:25'],
            'bare IPv4' => ['192.0.2.10', '[192.0.2.10]'],
            'bare IPv6' => ['2001:db8::1', '[2001:db8::1]'],
            'bracketed ipv6: prefix, lower-cased' => ['[ipv6:2001:DB8::1]:25', '[ipv6:2001:db8::1]:25'],
            'bracketed IPv6 without prefix' => ['[2001:db8::1]', '[2001:db8::1]'],
            'single label' => ['postfix-relay', 'postfix-relay'],
            'Unicode host with port' => ['Bücher.Example:587', 'xn--bcher-kva.example:587'],
            'trimmed' => [' relay.example.net ', 'relay.example.net'],
            'implicit TLS port (only warned about)' => ['[smtp.example.net]:465', '[smtp.example.net]:465'],
            'host with port' => ['relay.example.net:2525', 'relay.example.net:2525'],
            'upper-case host' => ['SMTP.Example.NET', 'smtp.example.net'],
            'highest port' => ['[relay.example.net]:65535', '[relay.example.net]:65535'],
        ];
    }

    #[DataProvider('accepted')]
    public function test_accepted_next_hops_are_stored_normalised(string $input, string $stored): void
    {
        $this->assertNull(NextHop::violation($input));
        $this->assertSame($stored, NextHop::parse($input)->stored());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refused(): array
    {
        return [
            'service prefix' => ['smtp:relay.example.net'],
            'empty label' => ['relay..example.net'],
            'port zero' => ['[relay.example.net]:0'],
            'port too high' => ['relay.example.net:65536'],
            'empty brackets' => ['[]'],
            'space inside brackets' => ['[a b]'],
            'signed port' => ['host:+25'],
            'leading zero port' => ['relay.example.net:025'],
            'unbalanced bracket' => ['[relay.example.net'],
            'invalid IPv6 after prefix' => ['[ipv6:relay.example.net]'],
            'two ports' => ['[relay.example.net]:25:26'],
        ];
    }

    #[DataProvider('refused')]
    public function test_invalid_next_hops_are_refused_as_invalid_format(string $input): void
    {
        $this->assertSame(['code' => 'invalidFormat', 'params' => ['format' => 'nextHop']], self::codeOf(NextHop::violation($input)));
        $this->assertNull(NextHop::tryParse($input));
    }

    public function test_an_empty_next_hop_is_required(): void
    {
        $this->assertSame(['code' => 'required', 'params' => []], self::codeOf(NextHop::violation('')));
        $this->assertSame(['code' => 'required', 'params' => []], self::codeOf(NextHop::violation('   ')));
    }

    public function test_a_next_hop_longer_than_255_characters_is_too_long(): void
    {
        $this->assertSame(['code' => 'tooLong', 'params' => ['max' => 255]], self::codeOf(NextHop::violation(str_repeat('a', 256))));
        $this->assertNull(NextHop::violation(str_repeat('a', 63).'.'.str_repeat('b', 63)));
    }

    public function test_a_non_string_is_an_invalid_type(): void
    {
        $this->assertSame(['code' => 'invalidType', 'params' => ['expected' => 'string']], self::codeOf(NextHop::violation(25)));
    }

    public function test_the_parts_of_a_next_hop(): void
    {
        $hop = NextHop::parse('[smtp.example.net]:587');
        $this->assertTrue($hop->bracketed());
        $this->assertSame('smtp.example.net', $hop->host());
        $this->assertSame(587, $hop->port());
        $this->assertSame('smtp.example.net', $hop->cleanHost());

        $hop = NextHop::parse('relay.example.net:2525');
        $this->assertFalse($hop->bracketed());
        $this->assertSame('relay.example.net', $hop->host());
        $this->assertSame(2525, $hop->port());
        $this->assertSame('relay.example.net:2525', $hop->cleanHost());

        $hop = NextHop::parse('[ipv6:2001:db8::1]:25');
        $this->assertSame('2001:db8::1', $hop->host());
        $this->assertSame('ipv6:2001:db8::1', $hop->cleanHost());

        $hop = NextHop::parse('192.0.2.10');
        $this->assertTrue($hop->bracketed());
        $this->assertSame('192.0.2.10', $hop->host());
        $this->assertNull($hop->port());
        $this->assertSame('192.0.2.10', $hop->cleanHost());
    }

    /**
     * @param  array{code: string, params: array<string, mixed>, message: string}|null  $violation
     * @return array{code: string, params: array<string, mixed>}|null
     */
    private static function codeOf(?array $violation): ?array
    {
        return $violation === null ? null : ['code' => $violation['code'], 'params' => $violation['params']];
    }
}
