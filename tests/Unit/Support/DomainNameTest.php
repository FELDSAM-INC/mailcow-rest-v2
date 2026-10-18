<?php

namespace Tests\Unit\Support;

use App\Support\Domain\DomainName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Feature 002 T007 (FR-006, FR-019, research D4): legacy name normalisation and
 * is_valid_domain_name(), shared with features 003, 004 and 010.
 */
class DomainNameTest extends TestCase
{
    public function test_names_are_trimmed_lower_cased_and_converted_to_ascii(): void
    {
        $this->assertSame('xn--mnchen-3ya.de', DomainName::normalize(' MÜNCHEN.de '));
        $this->assertSame('xn--mnchen-3ya.de', DomainName::normalize('münchen.de'));
        $this->assertSame('xn--mnchen-3ya.de', DomainName::normalize('XN--MNCHEN-3YA.DE'));
        $this->assertSame('example.org', DomainName::normalize('Example.ORG'));
    }

    public function test_names_that_uts46_rejects_normalise_to_null(): void
    {
        $this->assertNull(DomainName::normalize('a..b'));
        $this->assertNull(DomainName::normalize("\u{FFFD}.de"));
        $this->assertNull(DomainName::normalize(''));
        $this->assertNull(DomainName::normalize('   '));
        $this->assertNull(DomainName::normalize('xn--a'));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function validity(): array
    {
        return [
            'single label' => ['localdomain', true],
            'inner hyphen' => ['a-b.example', true],
            'double hyphen' => ['a--b.example', true],
            'idn' => ['münchen.de', true],
            'leading hyphen' => ['-a.example', false],
            'trailing hyphen' => ['a-.example', false],
            'underscore' => ['a_b.example', false],
            'empty label' => ['a..b', false],
            'label of 64' => [str_repeat('a', 64).'.example', false],
            'label of 63' => [str_repeat('a', 63).'.example', true],
            'name of 254' => [implode('.', array_fill(0, 50, 'abcd')).'.abcd', false],
            'name of 253' => [implode('.', array_fill(0, 50, 'abcd')).'.abc', true],
            'empty' => ['', false],
            'trailing dot' => ['example.org.', false],
        ];
    }

    #[DataProvider('validity')]
    public function test_validity_equals_legacy_is_valid_domain_name(string $name, bool $valid): void
    {
        $this->assertSame($valid, DomainName::isValid($name));
    }

    public function test_the_readable_form_falls_back_to_ascii(): void
    {
        $this->assertSame('münchen.de', DomainName::toUnicode('xn--mnchen-3ya.de'));
        $this->assertSame('example.org', DomainName::toUnicode('example.org'));
        $this->assertSame('xn--a.de', DomainName::toUnicode('xn--a.de'));
    }

    public function test_a_valid_normalised_name_or_null(): void
    {
        $this->assertSame('xn--mnchen-3ya.de', DomainName::normalizeValid('MÜNCHEN.de'));
        $this->assertNull(DomainName::normalizeValid('exa_mple.org'));
        $this->assertNull(DomainName::normalizeValid('a..b'));
    }
}
