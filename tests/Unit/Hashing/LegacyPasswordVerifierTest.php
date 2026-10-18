<?php

namespace Tests\Unit\Hashing;

use App\Support\Hashing\LegacyPasswordVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../fixtures/legacy/verify_hash.php';

/**
 * T053 (FR-004, SC-001, R12): the port of legacy verify_hash() accepts every scheme legacy
 * accepts, with exactly legacy's result.
 *
 * The vectors were generated with Dovecot of mailcow-dockerized 2026-09a:
 *
 *     docker run --rm --entrypoint doveadm ghcr.io/mailcow/dovecot:2.3.21.1-2 \
 *         pw -s <SCHEME> -p 'Kor3ct H0rse!42'
 *
 * Dovecot has no FreeIPA-style PBKDF2-SHA512 scheme, so that vector was computed with
 * `hash_pbkdf2('sha512', $password, $salt, 10000, 64, true)` in the FreeIPA form
 * `iterations$base64(salt)$base64(hash)`. `{PLAIN-TRUNC}<length>-<password>` is written by hand
 * in the format Dovecot documents (doveadm emits the plain form).
 */
class LegacyPasswordVerifierTest extends TestCase
{
    private const PASSWORD = 'Kor3ct H0rse!42';

    private const VECTORS = [
        'ARGON2I' => '{ARGON2I}$argon2i$v=19$m=32768,t=4,p=1$EuJ8z8kJUfb6h3My1Q+4uA$aeInuJHiKKjDgqgAInEEIowj7kavL81MJEmCBBbOawk',
        'ARGON2ID' => '{ARGON2ID}$argon2id$v=19$m=65536,t=3,p=1$WEnFbngbw6cpWLqVINuSAw$BhY8quSroUR1YKLiRIIoKkmaR52QspdvwLLKNNX2DrQ',
        'BLF-CRYPT' => '{BLF-CRYPT}$2y$05$JOTzHTBCJCFFaR4JOI8mV.OIpl/XuFB8363xLkpxzzEmuEaaArYJO',
        'CRYPT' => '{CRYPT}$2y$05$AnqJ4jko5t.W0wrGEwORp.1KIwL.2k7sBzn5yeIUameWHUbjPCwqu',
        'DES-CRYPT' => '{DES-CRYPT}Et5NdP5YGoLSc',
        'MD5-CRYPT' => '{MD5-CRYPT}$1$NUA2GgHJ$5EsOfcXAjio7d.6Ga10vW1',
        'MD5' => '{MD5}$1$pBkf0uy.$EHaBMs22FUNX1NQMoVyxO/',
        'SHA256-CRYPT' => '{SHA256-CRYPT}$5$LTh.La0hkcA4MFkl$2zqOZRCtN1Gwj9ZnHJc.UbbftGaux7zO1jBRHWzORt8',
        'SHA512-CRYPT' => '{SHA512-CRYPT}$6$bu76Zq6oyYsAuyC2$ghJlTgLvncnsJw8o0/NojuDvlC.3ERvdzQRzcXm49lc6WPBQVwf5lV7iEGqjMYgTHDL8i1sNwZbiEwJE1J0Py/',
        'CLEAR' => '{CLEAR}Kor3ct H0rse!42',
        'CLEARTEXT' => '{CLEARTEXT}Kor3ct H0rse!42',
        'PLAIN' => '{PLAIN}Kor3ct H0rse!42',
        'LDAP-MD5' => '{LDAP-MD5}wLKDrvrT+52r/hbk0ou2UA==',
        'PBKDF2' => '{PBKDF2}$1$.5Pbmqk4HRGWU0fx$5000$babf80ac940a772975df69c5af28fe50c07cf276',
        'PBKDF2-SHA512' => '{PBKDF2-SHA512}10000$6BPdUU5zd9fNA/iFAlwf6A==$AfpVm1SG+INYE0uWcUoO0mKCGPdqWDVYmglR3uS5KfxGoqynTByDrJDAppFsqarVl7GkgCONajf1Ax3jCzuEdQ==',
        'PLAIN-MD4' => '{PLAIN-MD4}92433e392cca5f36aaa7a4bbf9549308',
        'PLAIN-MD5' => '{PLAIN-MD5}c0b283aefad3fb9dabfe16e4d28bb650',
        'PLAIN-TRUNC' => '{PLAIN-TRUNC}Kor3ct H0rse!42',
        'SHA' => '{SHA}1AlCQ9xX+yB3Nqtctof67PRwt3E=',
        'SHA1' => '{SHA1}1AlCQ9xX+yB3Nqtctof67PRwt3E=',
        'SHA256' => '{SHA256}Rc7XXQYmTFjnxJq4DIEw+bCqExANG+g4NIwEEZ4ePXo=',
        'SHA512' => '{SHA512}9a6gvvaI1dagywJWK7kDDP0U5HRjjisvOZpUxDZ/PrngR9qEPMlEGH5Xq377gjzvRB2txE3Kj6ALXdlL/CBasQ==',
        'SMD5' => '{SMD5}47U6Q279ELD/q85aMMuTgCv+Oio=',
        'SSHA' => '{SSHA}6fzYiL72Gh8wIn3vxRkPEayqddfXkE0K',
        'SSHA256' => '{SSHA256}0hmGlt0JUA619Yp1LbEo0UfqjrXg/e8NThhqXevrmG944TSo',
        'SSHA512' => '{SSHA512}c3K3xAHeLCmGb5cp4SBXghI4nlFQDPN7Mau3xpHKYbIpD2bMy5PdSsFYwXkX1GQva5xs74AqoMZMOHS3OJeDt3VxIzc=',
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function schemes(): iterable
    {
        foreach (self::VECTORS as $scheme => $hash) {
            yield $scheme => [$hash];
        }
    }

    #[DataProvider('schemes')]
    public function test_the_correct_password_passes_and_a_wrong_one_fails(string $hash): void
    {
        $verifier = new LegacyPasswordVerifier;

        $this->assertTrue($verifier->verify($hash, self::PASSWORD));
        // DES-CRYPT only uses the first 8 characters, so the wrong passwords differ at the start.
        $this->assertFalse($verifier->verify($hash, 'x'.self::PASSWORD));
        $this->assertFalse($verifier->verify($hash, 'kor3ct h0rse!42'));
    }

    #[DataProvider('schemes')]
    public function test_the_result_equals_legacy_verify_hash(string $hash): void
    {
        $verifier = new LegacyPasswordVerifier;

        foreach ([self::PASSWORD, self::PASSWORD.'x', 'x'.self::PASSWORD, '', 'Passw0rd!'] as $password) {
            $this->assertSame((bool) legacy_verify_hash($hash, $password), $verifier->verify($hash, $password), "{$hash} with '{$password}'");
        }
    }

    public function test_the_truncated_plain_form(): void
    {
        $verifier = new LegacyPasswordVerifier;
        $hash = '{PLAIN-TRUNC}6-Kor3ct';

        $this->assertTrue($verifier->verify($hash, self::PASSWORD));
        $this->assertFalse($verifier->verify($hash, 'Kor3cX H0rse!42'));
        $this->assertSame((bool) legacy_verify_hash($hash, self::PASSWORD), $verifier->verify($hash, self::PASSWORD));
    }

    public function test_a_truncated_plain_form_without_a_numeric_length_fails_instead_of_throwing(): void
    {
        // Legacy throws a TypeError for this value (substr() with a non-numeric length).
        $this->assertFalse((new LegacyPasswordVerifier)->verify('{PLAIN-TRUNC}abc-def', 'abc-def'));
    }

    public function test_a_lower_case_scheme_name_works(): void
    {
        $verifier = new LegacyPasswordVerifier;

        $this->assertTrue($verifier->verify(str_replace('{BLF-CRYPT}', '{blf-crypt}', self::VECTORS['BLF-CRYPT']), self::PASSWORD));
        $this->assertTrue($verifier->verify(str_replace('{SSHA256}', '{Ssha256}', self::VECTORS['SSHA256']), self::PASSWORD));
    }

    public function test_an_unknown_scheme_or_a_missing_scheme_fails(): void
    {
        $verifier = new LegacyPasswordVerifier;

        $this->assertFalse($verifier->verify('{SCRAM-SHA-256}whatever', self::PASSWORD));
        $this->assertFalse($verifier->verify('{UNKNOWN}'.self::PASSWORD, self::PASSWORD));
        $this->assertFalse($verifier->verify(substr(self::VECTORS['BLF-CRYPT'], strlen('{BLF-CRYPT}')), self::PASSWORD));
        $this->assertFalse($verifier->verify(self::PASSWORD, self::PASSWORD));
        $this->assertFalse($verifier->verify('', ''));
        $this->assertFalse($verifier->verify('{PLAIN}', ''));
    }

    public function test_a_malformed_salted_or_pbkdf2_hash_fails_without_errors(): void
    {
        $verifier = new LegacyPasswordVerifier;

        $this->assertFalse($verifier->verify('{SSHA}not base64!', self::PASSWORD));
        $this->assertFalse($verifier->verify('{PBKDF2}$1$salt', self::PASSWORD));
        $this->assertFalse($verifier->verify('{PBKDF2-SHA512}0$AAAA$AAAA', self::PASSWORD));
        $this->assertFalse($verifier->verify('{PBKDF2-SHA512}10000$!!$AAAA', self::PASSWORD));
    }

    public function test_the_dummy_hash_is_a_blf_crypt_hash_that_no_password_matches(): void
    {
        $this->assertStringStartsWith('{BLF-CRYPT}$2y$10$', LegacyPasswordVerifier::DUMMY_HASH);
        $this->assertFalse((new LegacyPasswordVerifier)->verify(LegacyPasswordVerifier::DUMMY_HASH, self::PASSWORD));
    }
}
