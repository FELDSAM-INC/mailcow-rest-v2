<?php

namespace Tests\Unit\Dkim;

use App\Rules\DkimPrivateKey;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Dkim\DkimFixtures;
use Tests\TestCase;

/**
 * Feature 010 T081 (FR-019, research R6): an imported private key is at most 16,384 bytes, exactly
 * one unencrypted RSA private key in PEM form (PKCS #1 or PKCS #8) of 1,024 to 8,192 bits; line
 * endings are normalised and surrounding whitespace trimmed. The first failure is reported with its
 * rule code, and no message or parameter echoes the key text.
 */
class DkimPrivateKeyRuleTest extends TestCase
{
    /**
     * @return array{code: string, params: array<string, mixed>, message: string}|null
     */
    private static function violation(mixed $value): ?array
    {
        $rule = new DkimPrivateKey;
        $passes = Validator::make(['privateKey' => $value], ['privateKey' => [$rule]])->passes();

        return $passes ? null : $rule->violationFor('privateKey');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function acceptedKeys(): array
    {
        $cases = [];

        foreach (DkimFixtures::rsa() as $bits => $pair) {
            $cases["PKCS #8 {$bits}"] = [$pair['private']];
        }

        foreach (DkimFixtures::rsaPkcs1() as $bits => $pair) {
            $cases["PKCS #1 {$bits}"] = [$pair['private']];
        }

        return $cases;
    }

    #[DataProvider('acceptedKeys')]
    public function test_rsa_keys_of_every_allowed_length_are_accepted(string $pem): void
    {
        $this->assertNull(self::violation($pem));
    }

    public function test_line_endings_are_normalised_and_whitespace_trimmed(): void
    {
        $crlf = "  \r\n".str_replace("\n", "\r\n", DkimFixtures::RSA_1024_PKCS1_PRIVATE)."\r\n\r\n";
        $cr = str_replace("\n", "\r", DkimFixtures::RSA_1024_PRIVATE);

        $this->assertNull(self::violation($crlf));
        $this->assertNull(self::violation($cr));
        $this->assertSame(DkimFixtures::RSA_1024_PKCS1_PRIVATE, DkimPrivateKey::normalize($crlf));
        $this->assertSame(DkimFixtures::RSA_1024_PRIVATE, DkimPrivateKey::normalize($cr));
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function refusedKeys(): array
    {
        return [
            '17 KiB' => [DkimFixtures::seventeenKibText(), 'tooLong', ['max' => 16384, 'unit' => 'bytes']],
            'garbage' => ["not a key\nat all", 'invalidPrivateKey', []],
            'damaged body' => ["-----BEGIN PRIVATE KEY-----\nAAAAnotbase64!!!\n-----END PRIVATE KEY-----", 'invalidPrivateKey', []],
            'public key' => [DkimFixtures::RSA_2048_PUBLIC_PEM, 'invalidPrivateKey', []],
            'certificate' => [DkimFixtures::CERTIFICATE, 'invalidPrivateKey', []],
            'two keys' => [DkimFixtures::TWO_PRIVATE_KEYS, 'invalidPrivateKey', []],
            'encrypted PKCS #8' => [DkimFixtures::ENCRYPTED_PKCS8_PRIVATE, 'encryptedPrivateKey', []],
            'encrypted PKCS #1' => [DkimFixtures::ENCRYPTED_PKCS1_PRIVATE, 'encryptedPrivateKey', []],
            'EC key' => [DkimFixtures::EC_P256_PRIVATE, 'unsupportedKeyType', ['allowed' => ['rsa']]],
            'EC key in PKCS #8' => [DkimFixtures::EC_P256_PKCS8_PRIVATE, 'unsupportedKeyType', ['allowed' => ['rsa']]],
            '512 bits' => [DkimFixtures::RSA_512_PRIVATE, 'keyLengthOutOfRange', ['min' => 1024, 'max' => 8192]],
            '9216 bits' => [DkimFixtures::RSA_9216_PRIVATE, 'keyLengthOutOfRange', ['min' => 1024, 'max' => 8192]],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    #[DataProvider('refusedKeys')]
    public function test_refused_keys_name_their_rule_and_never_echo_the_text(string $text, string $code, array $params): void
    {
        $violation = self::violation($text);

        $this->assertNotNull($violation);
        $this->assertSame($code, $violation['code']);
        $this->assertSame($params, $violation['params']);

        $serialised = (string) json_encode($violation);
        foreach (array_filter(array_map('trim', explode("\n", $text)), fn (string $line): bool => strlen($line) >= 16) as $line) {
            $this->assertStringNotContainsString($line, $serialised);
        }
        $this->assertStringNotContainsString('BEGIN', $serialised);
    }

    public function test_a_non_string_is_refused(): void
    {
        $this->assertSame('invalidPrivateKey', self::violation(['x'])['code'] ?? null);
    }
}
