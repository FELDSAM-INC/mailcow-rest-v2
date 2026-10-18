<?php

namespace Tests\Unit\Passwords;

use App\Services\Passwords\MailboxPasswordHasher;
use App\Support\Hashing\LegacyPasswordVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

require_once __DIR__.'/../../fixtures/legacy/verify_hash.php';

/**
 * Feature 003 T006 (FR-024, research R3): new passwords are hashed in MAILCOW_PASS_SCHEME exactly as
 * legacy hash_password() does, so that Dovecot and legacy verify them, and values with a hash-scheme
 * prefix legacy recognises are stored as given.
 */
class MailboxPasswordHasherTest extends TestCase
{
    private const PASSWORD = 'Corr3ct horse!';

    /**
     * @return array<string, array{string, string}>
     */
    public static function schemes(): array
    {
        return [
            'BLF-CRYPT' => ['BLF-CRYPT', '{BLF-CRYPT}$2y$10$'],
            'SSHA' => ['SSHA', '{SSHA}'],
            'SSHA256' => ['SSHA256', '{SSHA256}'],
            'SSHA512' => ['SSHA512', '{SSHA512}'],
            'lower case' => ['ssha512', '{SSHA512}'],
            'unknown scheme falls back to BLF-CRYPT' => ['MD5-CRYPT', '{BLF-CRYPT}$2y$10$'],
        ];
    }

    #[DataProvider('schemes')]
    public function test_the_hash_uses_the_configured_scheme_and_verifies(string $scheme, string $prefix): void
    {
        config(['restv2.mailcow_pass_scheme' => $scheme]);

        $hashed = $this->app->make(MailboxPasswordHasher::class)->hash(self::PASSWORD);

        $this->assertStringStartsWith($prefix, $hashed->value);
        $this->assertFalse($hashed->preHashed);
        $this->assertTrue((new LegacyPasswordVerifier)->verify($hashed->value, self::PASSWORD));
        $this->assertTrue(legacy_verify_hash($hashed->value, self::PASSWORD));
        $this->assertFalse(legacy_verify_hash($hashed->value, 'wrong'));
    }

    public function test_salted_sha_hashes_carry_a_salt_of_sixteen_hex_characters(): void
    {
        foreach (['SSHA' => 20, 'SSHA256' => 32, 'SSHA512' => 64] as $scheme => $digestLength) {
            config(['restv2.mailcow_pass_scheme' => $scheme]);

            $value = $this->app->make(MailboxPasswordHasher::class)->hash(self::PASSWORD)->value;
            $raw = (string) base64_decode(substr($value, strlen($scheme) + 2), true);

            $this->assertSame($digestLength + 16, strlen($raw), $scheme);
            $this->assertTrue(ctype_xdigit(substr($raw, $digestLength)), $scheme);
        }
    }

    public function test_two_hashes_of_one_password_differ(): void
    {
        $hasher = $this->app->make(MailboxPasswordHasher::class);

        $this->assertNotSame($hasher->hash(self::PASSWORD)->value, $hasher->hash(self::PASSWORD)->value);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function preHashed(): array
    {
        $cases = [];

        foreach (MailboxPasswordHasher::PRE_HASHED_SCHEMES as $scheme) {
            $cases[$scheme] = ['{'.$scheme.'}c29tZWhhc2g='];
            $cases[strtolower($scheme).' (lower case)'] = ['{'.strtolower($scheme).'}c29tZWhhc2g='];
        }

        return $cases;
    }

    #[DataProvider('preHashed')]
    public function test_a_value_with_a_recognised_prefix_is_stored_as_given(string $value): void
    {
        $hashed = $this->app->make(MailboxPasswordHasher::class)->hash($value);

        $this->assertSame($value, $hashed->value);
        $this->assertTrue($hashed->preHashed);
    }

    public function test_the_recognised_schemes_are_legacys(): void
    {
        $this->assertSame([
            'ARGON2I', 'ARGON2ID', 'BLF-CRYPT', 'CLEAR', 'CLEARTEXT', 'CRYPT', 'DES-CRYPT', 'LDAP-MD5', 'MD5',
            'MD5-CRYPT', 'PBKDF2', 'PLAIN', 'PLAIN-MD4', 'PLAIN-MD5', 'PLAIN-TRUNC', 'SHA', 'SHA1', 'SHA256',
            'SHA256-CRYPT', 'SHA512', 'SHA512-CRYPT', 'SMD5', 'SSHA', 'SSHA256', 'SSHA512',
        ], MailboxPasswordHasher::PRE_HASHED_SCHEMES);
    }

    public function test_an_unrecognised_prefix_is_hashed(): void
    {
        $hasher = $this->app->make(MailboxPasswordHasher::class);

        foreach (['{PBKDF2-SHA512}abc', '{UNKNOWN}abc', ' {SSHA512}abc', 'SSHA512}abc'] as $value) {
            $hashed = $hasher->hash($value);

            $this->assertFalse($hashed->preHashed, $value);
            $this->assertStringStartsWith('{BLF-CRYPT}', $hashed->value, $value);
            $this->assertTrue((new LegacyPasswordVerifier)->verify($hashed->value, $value), $value);
        }
    }
}
