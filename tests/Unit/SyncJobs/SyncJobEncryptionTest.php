<?php

namespace Tests\Unit\SyncJobs;

use App\Services\SyncJobs\SyncJobEncryption;
use PHPUnit\Framework\TestCase;
use ValueError;

/**
 * Feature 008 T008 (research R3): the encryption is stored as legacy's `enc1` value and exposed
 * as `ssl`, `starttls` (stored `TLS`, imapsync `--tls1`) or `plain`.
 */
class SyncJobEncryptionTest extends TestCase
{
    public function test_stored_and_contract_values_map_both_ways(): void
    {
        $this->assertSame('ssl', SyncJobEncryption::from('SSL')->toApi());
        $this->assertSame('starttls', SyncJobEncryption::from('TLS')->toApi());
        $this->assertSame('plain', SyncJobEncryption::from('PLAIN')->toApi());

        $this->assertSame(SyncJobEncryption::Ssl, SyncJobEncryption::fromApi('ssl'));
        $this->assertSame(SyncJobEncryption::Starttls, SyncJobEncryption::fromApi('starttls'));
        $this->assertSame(SyncJobEncryption::Plain, SyncJobEncryption::fromApi('plain'));
        $this->assertSame('TLS', SyncJobEncryption::fromApi('starttls')->value);
    }

    public function test_tls_is_not_a_contract_value(): void
    {
        $this->expectException(ValueError::class);

        SyncJobEncryption::fromApi('tls');
    }

    public function test_the_contract_values_are_listed_in_order(): void
    {
        $this->assertSame(['ssl', 'starttls', 'plain'], SyncJobEncryption::apiValues());
    }
}
