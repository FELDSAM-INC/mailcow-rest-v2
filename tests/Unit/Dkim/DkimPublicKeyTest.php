<?php

namespace Tests\Unit\Dkim;

use App\Services\Dkim\DkimPublicKey;
use PHPUnit\Framework\TestCase;
use Tests\Support\Dkim\DkimFixtures;

/**
 * Feature 010 T011 (research R5): the stored public key text, its length in bits, the DNS record
 * value and its 255-character parts, and its fingerprint.
 */
class DkimPublicKeyTest extends TestCase
{
    public function test_bits_are_exact_for_every_size(): void
    {
        foreach (DkimFixtures::rsa() as $bits => $pair) {
            $this->assertSame($bits, (new DkimPublicKey($pair['public']))->bits(), "{$bits} bits");
        }
    }

    public function test_bits_are_null_for_texts_that_are_no_rsa_public_key(): void
    {
        $this->assertNull((new DkimPublicKey('not a key'))->bits());
        $this->assertNull((new DkimPublicKey(''))->bits());
        $this->assertNull((new DkimPublicKey(DkimFixtures::EC_P256_PUBLIC))->bits());
    }

    public function test_the_record_value_and_its_parts(): void
    {
        $key = new DkimPublicKey(DkimFixtures::RSA_2048_PUBLIC);
        $value = 'v=DKIM1;k=rsa;t=s;s=email;p='.DkimFixtures::RSA_2048_PUBLIC;

        $this->assertSame($value, $key->recordValue());
        $parts = $key->recordParts();
        $this->assertSame($value, implode('', $parts));
        $this->assertGreaterThan(1, count($parts));
        foreach ($parts as $part) {
            $this->assertLessThanOrEqual(255, strlen($part));
        }
        $this->assertSame(255, strlen($parts[0]));
    }

    public function test_the_fingerprint_is_the_sha256_of_the_der_bytes(): void
    {
        $key = new DkimPublicKey(DkimFixtures::RSA_1024_PUBLIC);

        $this->assertSame(hash('sha256', (string) base64_decode(DkimFixtures::RSA_1024_PUBLIC, true)), $key->sha256Fingerprint());
        $this->assertNull((new DkimPublicKey('***'))->sha256Fingerprint());
    }
}
