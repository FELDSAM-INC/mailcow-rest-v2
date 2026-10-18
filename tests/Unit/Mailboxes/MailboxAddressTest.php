<?php

namespace Tests\Unit\Mailboxes;

use App\Services\Mailboxes\MailboxAddress;
use App\Support\Http\FieldViolations;
use PHPUnit\Framework\TestCase;

/**
 * Feature 003 T008 (FR-007, FR-019, research R7): addresses are trimmed, lower-cased and their
 * domain converted to ASCII as legacy does; local parts follow the rules of FR-019.
 */
class MailboxAddressTest extends TestCase
{
    public function test_an_address_is_normalised(): void
    {
        $address = MailboxAddress::normalize(' Anna.Smith@Example.org ');

        $this->assertNotNull($address);
        $this->assertSame('anna.smith@example.org', $address->address);
        $this->assertSame('anna.smith', $address->localPart);
        $this->assertSame('example.org', $address->domain);
        $this->assertSame('example.org', $address->domainUnicode);
    }

    public function test_unicode_and_ascii_domains_give_the_same_address(): void
    {
        $unicode = MailboxAddress::normalize('Anna@Bücher.example');
        $ascii = MailboxAddress::normalize('anna@xn--bcher-kva.example');

        $this->assertNotNull($unicode);
        $this->assertNotNull($ascii);
        $this->assertSame('anna@xn--bcher-kva.example', $unicode->address);
        $this->assertSame($unicode->address, $ascii->address);
        $this->assertSame('bücher.example', $ascii->domainUnicode);
    }

    public function test_invalid_addresses_do_not_normalise(): void
    {
        foreach (['', 'anna', '@example.org', 'anna@', 'an na@example.org', '.anna@example.org', 'anna@exa mple.org', 'a@b@example.org'] as $raw) {
            $this->assertNull(MailboxAddress::normalize($raw), $raw);
        }
    }

    public function test_every_allowed_special_character_passes(): void
    {
        $violations = new FieldViolations;
        $address = MailboxAddress::fromParts("a!#\$%&'*+/=?^_`{|}~-.b", 'example.org', $violations);

        $this->assertTrue($violations->isEmpty(), json_encode($violations->all()) ?: '');
        $this->assertSame("a!#\$%&'*+/=?^_`{|}~-.b@example.org", $address?->address);
    }

    public function test_the_local_part_is_trimmed_and_lower_cased(): void
    {
        $violations = new FieldViolations;

        $this->assertSame('anna.smith@example.org', MailboxAddress::fromParts(' Anna.Smith ', 'Example.org', $violations)?->address);
    }

    public function test_invalid_local_parts_are_refused(): void
    {
        $cases = [
            '.anna' => ['invalidFormat', ['format' => 'localPart']],
            'anna.' => ['invalidFormat', ['format' => 'localPart']],
            'an..na' => ['invalidFormat', ['format' => 'localPart']],
            'an na' => ['invalidFormat', ['format' => 'localPart']],
            'änna' => ['invalidFormat', ['format' => 'localPart']],
            'an"na' => ['invalidFormat', ['format' => 'localPart']],
            str_repeat('a', 65) => ['tooLong', ['max' => 64]],
            '   ' => ['required', []],
        ];

        foreach ($cases as $localPart => [$code, $params]) {
            $violations = new FieldViolations;

            $this->assertNull(MailboxAddress::fromParts((string) $localPart, 'example.org', $violations), (string) $localPart);
            $error = $violations->all()['localPart'][0] ?? null;
            $this->assertNotNull($error, (string) $localPart);
            $this->assertSame($code, $error['code'], (string) $localPart);
            $this->assertSame($params, $error['params'], (string) $localPart);
        }
    }

    public function test_a_local_part_of_64_characters_passes(): void
    {
        $violations = new FieldViolations;

        $this->assertNotNull(MailboxAddress::fromParts(str_repeat('a', 64), 'example.org', $violations));
    }

    public function test_an_address_longer_than_255_characters_is_refused(): void
    {
        $domain = implode('.', [str_repeat('b', 63), str_repeat('c', 63), str_repeat('d', 63), 'example']);
        $violations = new FieldViolations;

        $this->assertNull(MailboxAddress::fromParts(str_repeat('a', 64), $domain, $violations));
        $this->assertSame(['code' => 'tooLong', 'params' => ['max' => 255]], array_intersect_key($violations->all()['localPart'][0] ?? [], ['code' => 1, 'params' => 1]));
    }

    public function test_the_violation_field_can_be_named(): void
    {
        $violations = new FieldViolations;

        MailboxAddress::fromParts('.x', 'example.org', $violations, 'newLocalPart');

        $this->assertTrue($violations->has('newLocalPart'));
    }
}
