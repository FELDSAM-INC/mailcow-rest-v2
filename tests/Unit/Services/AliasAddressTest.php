<?php

namespace Tests\Unit\Services;

use App\Services\Aliases\AliasAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Feature 004 T007 (FR-008, FR-009, research R3): alias addresses are trimmed, keep the case of the
 * local part, get their domain part in lower-case ASCII, and are valid by legacy's rules; `@domain`
 * is a catch-all, also in a single-label domain.
 */
class AliasAddressTest extends TestCase
{
    public function test_an_address_is_normalised_like_legacy(): void
    {
        $address = AliasAddress::parse('  Sales@Bücher.Example ');

        $this->assertNotNull($address);
        $this->assertSame('Sales@xn--bcher-kva.example', $address->address);
        $this->assertSame('Sales', $address->localPart);
        $this->assertSame('xn--bcher-kva.example', $address->domain);
        $this->assertFalse($address->catchAll);
        $this->assertTrue($address->isValid());
        $this->assertSame('Sales@bücher.example', $address->unicode());
        $this->assertSame('sales@xn--bcher-kva.example', $address->key());
    }

    public function test_a_catch_all_is_at_and_a_valid_domain_name(): void
    {
        $catchAll = AliasAddress::parse('@Example.ORG');
        $this->assertNotNull($catchAll);
        $this->assertSame('@example.org', $catchAll->address);
        $this->assertTrue($catchAll->catchAll);
        $this->assertTrue($catchAll->isValid());

        $single = AliasAddress::parse('@localdomain');
        $this->assertTrue($single?->isValid());
        $this->assertFalse(AliasAddress::parse('info@localdomain')?->isValid());
        $this->assertFalse(AliasAddress::parse('@exa_mple.org')?->isValid());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalid(): array
    {
        return [
            'quoted with space' => ['"a b"@example.org'],
            'quoted' => ['"ab"@example.org'],
            'non-ASCII local part' => ['müller@example.org'],
            'address literal' => ['a@[192.0.2.1]'],
            '65 characters' => [str_repeat('a', 65).'@example.org'],
            'double dot' => ['a..b@example.org'],
            'leading dot' => ['.a@example.org'],
            'no at' => ['example.org'],
            'reserved special action' => ['null@localhost'],
            'numeric last label' => ['a@example.123'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalid')]
    public function test_invalid_addresses_are_refused(string $raw): void
    {
        $this->assertFalse(AliasAddress::parse($raw)?->isValid() ?? false);
    }

    public function test_64_characters_and_plus_addresses_are_valid(): void
    {
        $this->assertTrue(AliasAddress::parse(str_repeat('a', 64).'@example.org')?->isValid());
        $this->assertTrue(AliasAddress::parse('a+b@example.org')?->isValid());
        $this->assertTrue(AliasAddress::parse('a@b.c1')?->isValid());
    }

    public function test_the_last_at_splits_the_address(): void
    {
        $address = AliasAddress::parse('a@b@Example.org');

        $this->assertNotNull($address);
        $this->assertSame('example.org', $address->domain);
        $this->assertFalse($address->isValid());
    }
}
