<?php

namespace Tests\Unit\Dkim;

use App\Services\Dkim\DkimKeyGenerator;
use App\Services\Dkim\DkimPublicKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Feature 010 T010 (research R5): RSA key pairs exactly as legacy dkim('add') produces them.
 */
class DkimKeyGeneratorTest extends TestCase
{
    /**
     * @return array<string, array{int}>
     */
    public static function sizes(): array
    {
        return ['1024 bits' => [1024], '2048 bits' => [2048]];
    }

    #[DataProvider('sizes')]
    public function test_keys_have_the_requested_size_and_legacy_format(int $bits): void
    {
        $material = (new DkimKeyGenerator)->generate($bits);

        $this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $material->privateKey);
        $this->assertSame(trim($material->privateKey), $material->privateKey);
        $this->assertDoesNotMatchRegularExpression('/-----|\s/', $material->publicKey);
        $this->assertSame($bits, (new DkimPublicKey($material->publicKey))->bits());

        $details = openssl_pkey_get_details(openssl_pkey_get_private($material->privateKey) ?: throw new \RuntimeException('no key'));
        $this->assertIsArray($details);
        $this->assertSame($bits, $details['bits']);
        $this->assertSame($material->publicKey, (new DkimKeyGenerator)->publicKeyOf($material->privateKey));
    }

    public function test_larger_sizes_are_generated_too(): void
    {
        foreach ([3072, 4096] as $bits) {
            $this->assertSame($bits, (new DkimPublicKey((new DkimKeyGenerator)->generate($bits)->publicKey))->bits());
        }
    }

    public function test_the_public_key_equals_legacys_derivation(): void
    {
        $dir = __DIR__.'/../../fixtures/legacy/dkim';

        $this->assertSame(
            (string) file_get_contents($dir.'/generated-2048.pub'),
            (new DkimKeyGenerator)->publicKeyOf((string) file_get_contents($dir.'/generated-2048.pem')),
        );
        $this->assertNull((new DkimKeyGenerator)->publicKeyOf('not a key'));
    }

    public function test_the_private_key_is_hidden_from_debug_output(): void
    {
        $material = (new DkimKeyGenerator)->generate(1024);

        $this->assertStringNotContainsString('PRIVATE KEY', print_r($material, true));
        $this->assertStringNotContainsString('PRIVATE KEY', var_export($material->__debugInfo(), true));
    }
}
