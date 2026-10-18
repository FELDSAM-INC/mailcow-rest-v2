<?php

namespace App\Services\Dkim;

use RuntimeException;

/**
 * RSA key pairs exactly as legacy dkim('add') creates them (feature 010 research R5): PKCS #8 PEM,
 * trimmed, and the public key as the PEM body joined without armour or line breaks.
 */
class DkimKeyGenerator
{
    public function generate(int $bits): DkimKeyMaterial
    {
        $key = openssl_pkey_new([
            'digest_alg' => 'sha256',
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false || ! openssl_pkey_export($key, $privateKey)) {
            throw new RuntimeException('OpenSSL could not generate a DKIM key.');
        }

        $details = openssl_pkey_get_details($key);

        if (! is_array($details) || ! is_string($details['key'] ?? null)) {
            throw new RuntimeException('OpenSSL could not read the generated DKIM key.');
        }

        return new DkimKeyMaterial(self::publicKeyText($details['key']), trim((string) $privateKey));
    }

    /** Legacy's public key text of a private key PEM, or null when it does not parse. */
    public function publicKeyOf(#[\SensitiveParameter] string $privatePem): ?string
    {
        while (openssl_error_string() !== false) {
            // Drain earlier OpenSSL errors.
        }

        $key = @openssl_pkey_get_private($privatePem);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        return is_array($details) && is_string($details['key'] ?? null) ? self::publicKeyText($details['key']) : null;
    }

    /**
     * `implode(array_slice(array_filter(explode(PHP_EOL, $pem)), 1, -1))`, legacy's derivation.
     */
    private static function publicKeyText(string $publicPem): string
    {
        return implode(array_slice(array_filter(explode(PHP_EOL, $publicPem)), 1, -1));
    }
}
