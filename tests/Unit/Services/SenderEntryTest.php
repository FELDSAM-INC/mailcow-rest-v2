<?php

namespace Tests\Unit\Services;

use App\Services\SenderSettings\SenderEntry;
use PHPUnit\Framework\TestCase;

/**
 * Feature 004 T010 (FR-054, FR-055, research R14): explicit sender permissions and external sender
 * addresses are normalised like legacy stores them: domains as `@<ascii domain>`, addresses with the
 * case of their local part, `*` as "any address".
 */
class SenderEntryTest extends TestCase
{
    public function test_domains_are_stored_as_at_ascii_domain(): void
    {
        foreach (['example.org', '@Example.ORG', ' @example.org '] as $raw) {
            $entry = SenderEntry::explicit($raw);
            $this->assertSame('domain', $entry?->type);
            $this->assertSame('@example.org', $entry?->value);
            $this->assertSame('example.org', $entry?->domain());
        }

        $this->assertSame('@xn--bcher-kva.example', SenderEntry::explicit('@bücher.example')?->value);
        $this->assertSame('@bücher.example', SenderEntry::explicit('@bücher.example')?->unicode());
    }

    public function test_addresses_keep_the_case_of_their_local_part(): void
    {
        $entry = SenderEntry::explicit('Info@Example.org');

        $this->assertSame('address', $entry?->type);
        $this->assertSame('Info@example.org', $entry?->value);
        $this->assertSame('info@example.org', $entry?->key());
        $this->assertSame('example.org', $entry?->domain());
    }

    public function test_the_wildcard_is_any_address(): void
    {
        $entry = SenderEntry::explicit('*');

        $this->assertSame('anyAddress', $entry?->type);
        $this->assertSame('*', $entry?->value);
        $this->assertNull($entry?->domain());
    }

    public function test_invalid_explicit_entries_are_refused(): void
    {
        foreach (['not an address', 'a..b@example.org', '@', '**', 'exa_mple.org', '"q"@example.org', ''] as $raw) {
            $this->assertNull(SenderEntry::explicit($raw), $raw);
        }
    }

    public function test_external_entries_are_addresses_or_domains(): void
    {
        $this->assertSame('news@mailer.example', SenderEntry::external('news@Mailer.example')?->value);
        $this->assertSame('@partner.example', SenderEntry::external('partner.example')?->value);
        $this->assertSame('@partner.example', SenderEntry::external('@Partner.Example')?->value);
        $this->assertSame('domain', SenderEntry::external('partner.example')?->type);
        $this->assertNull(SenderEntry::external('*'));
        $this->assertNull(SenderEntry::external('no way'));
        $this->assertNull(SenderEntry::external('@'));
    }
}
