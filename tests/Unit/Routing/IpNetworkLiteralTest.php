<?php

namespace Tests\Unit\Routing;

use App\Support\Routing\IpNetworkLiteral;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Feature 009 T010 (FR-077, research R16): forwarding host address literals, their canonical form
 * (compressed lower-case IPv6, prefixes with the host bits cleared), their classification and
 * their natural sort order.
 */
class IpNetworkLiteralTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function canonical(): array
    {
        return [
            'expanded IPv6' => ['2001:0DB8::0001', '2001:db8::1'],
            'IPv4 prefix with host bits' => ['192.0.2.5/24', '192.0.2.0/24'],
            'IPv6 prefix with host bits' => ['2001:db8::1/32', '2001:db8::/32'],
            'IPv4 address without prefix' => ['203.0.113.7', '203.0.113.7'],
            'IPv4 /32 keeps its prefix' => ['203.0.113.7/32', '203.0.113.7/32'],
            'IPv4 /0' => ['10.1.2.3/0', '0.0.0.0/0'],
            'IPv6 /128' => ['2001:db8::1/128', '2001:db8::1/128'],
            'IPv6 /127 odd bits' => ['2001:db8::3/127', '2001:db8::2/127'],
            'IPv4 /23 crossing an octet' => ['198.51.101.200/23', '198.51.100.0/23'],
        ];
    }

    #[DataProvider('canonical')]
    public function test_the_canonical_form(string $input, string $canonical): void
    {
        $literal = IpNetworkLiteral::parse($input);

        $this->assertNotNull($literal);
        $this->assertSame($canonical, $literal->canonical());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalid(): array
    {
        return [
            'octet above 255' => ['999.1.1.1'],
            'IPv4 prefix above 32' => ['192.0.2.0/33'],
            'IPv6 prefix above 128' => ['2001:db8::/129'],
            'three octets' => ['1.2.3'],
            'empty prefix' => ['192.0.2.0/'],
            'negative prefix' => ['192.0.2.0/-1'],
            'prefix with a leading zero' => ['192.0.2.0/024'],
            'IPv6 with too many groups' => ['1:2:3:4:5:6:7:8:9'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_invalid_literals(string $input): void
    {
        $this->assertTrue(IpNetworkLiteral::isLiteralLike($input));
        $this->assertNull(IpNetworkLiteral::parse($input));
    }

    public function test_the_classification_of_literals_and_names(): void
    {
        $this->assertFalse(IpNetworkLiteral::isLiteralLike('cafe'));
        $this->assertFalse(IpNetworkLiteral::isLiteralLike('example.org'));
        $this->assertFalse(IpNetworkLiteral::isLiteralLike('forwarder.example'));
        $this->assertTrue(IpNetworkLiteral::isLiteralLike('192.0.2.0/24'));
        $this->assertTrue(IpNetworkLiteral::isLiteralLike('2001:db8::1'));
        $this->assertTrue(IpNetworkLiteral::isLiteralLike('1.2.3'));
        $this->assertTrue(IpNetworkLiteral::isLiteralLike('a/b'));
        $this->assertNull(IpNetworkLiteral::parse('cafe'));
    }

    public function test_a_stored_non_canonical_entry_equals_its_canonical_form(): void
    {
        $stored = IpNetworkLiteral::parse('2001:0db8::0001');
        $added = IpNetworkLiteral::parse('2001:db8::1');

        $this->assertNotNull($stored);
        $this->assertNotNull($added);
        $this->assertTrue($stored->equals($added));
        $this->assertTrue(IpNetworkLiteral::parse('192.0.2.5/24')?->equals(IpNetworkLiteral::parse('192.0.2.0/24') ?? $added));
        $this->assertFalse($added->equals(IpNetworkLiteral::parse('2001:db8::2') ?? $added));
    }

    public function test_the_natural_sort_order(): void
    {
        $values = ['2001:db8::/32', '10.0.0.0/8', '192.0.2.10', '192.0.2.0/24', '9.9.9.9', '192.0.2.0/25', '2001:db8::1', '::1'];
        usort($values, fn (string $a, string $b): int => strcmp(IpNetworkLiteral::sortKeyOf($a), IpNetworkLiteral::sortKeyOf($b)));

        $this->assertSame(['9.9.9.9', '10.0.0.0/8', '192.0.2.0/24', '192.0.2.0/25', '192.0.2.10', '::1', '2001:db8::/32', '2001:db8::1'], $values);
    }

    public function test_an_unparseable_stored_value_sorts_after_every_address(): void
    {
        $this->assertGreaterThan(IpNetworkLiteral::sortKeyOf('2001:db8::1'), IpNetworkLiteral::sortKeyOf('cafe'));
    }
}
