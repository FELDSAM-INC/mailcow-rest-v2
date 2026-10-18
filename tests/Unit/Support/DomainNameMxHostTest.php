<?php

namespace Tests\Unit\Support;

use App\Rules\MtaStsMxHost;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Feature 010 T025 (FR-033, research R15): an MTA-STS MX host is normalised like a domain name
 * (trim, lower case, UTS #46), may start with `*.`, and the rest must be a valid domain name (002's
 * domainName rule, legacy is_valid_domain_name() with `allow_wildcard`). Invalid hosts are
 * `invalidFormat` with `format: mxHost`.
 */
class DomainNameMxHostTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function valid(): array
    {
        return [
            'host' => ['mail.example.org', 'mail.example.org'],
            'wildcard' => ['*.example.org', '*.example.org'],
            'upper case' => ['MAIL.Example.ORG', 'mail.example.org'],
            'surrounding blanks' => ['  mail.example.org ', 'mail.example.org'],
            'idn' => ['mail.bücher.example', 'mail.xn--bcher-kva.example'],
            'idn wildcard' => ['*.BÜCHER.example', '*.xn--bcher-kva.example'],
            'single label' => ['localhost', 'localhost'],
            'label of 63' => [str_repeat('a', 63).'.example', str_repeat('a', 63).'.example'],
        ];
    }

    #[DataProvider('valid')]
    public function test_valid_hosts_are_normalised(string $input, string $expected): void
    {
        $this->assertSame($expected, MtaStsMxHost::normalize($input));
        $this->assertTrue(Validator::make(['mx' => $input], ['mx' => [new MtaStsMxHost]])->passes());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalid(): array
    {
        return [
            'empty label' => ['mail..example'],
            'leading hyphen' => ['-mail.example.org'],
            'wildcard alone' => ['*.'],
            'partial wildcard' => ['a*.example.org'],
            'inner wildcard' => ['mail.*.example.org'],
            'label of 64' => [str_repeat('a', 64).'.example'],
            'name of 254' => [str_repeat('abcdefghi.', 25).'abc.example'],
            'underscore' => ['mail_1.example.org'],
            'empty' => [''],
            'blank' => ['   '],
        ];
    }

    #[DataProvider('invalid')]
    public function test_invalid_hosts_are_refused_with_the_mx_host_format(string $input): void
    {
        $rule = new MtaStsMxHost;

        $this->assertNull(MtaStsMxHost::normalize($input));
        $this->assertTrue(Validator::make(['mx' => $input], ['mx' => [$rule]])->fails());
        $this->assertSame(['code' => 'invalidFormat', 'params' => ['format' => 'mxHost'], 'message' => 'Not a valid MX host name: use a domain name, optionally starting with "*.".'], $rule->violationFor('mx'));
    }

    public function test_a_non_string_is_refused(): void
    {
        $this->assertTrue(Validator::make(['mx' => 42], ['mx' => [new MtaStsMxHost]])->fails());
    }
}
