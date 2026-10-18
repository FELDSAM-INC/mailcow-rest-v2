<?php

namespace App\Services\Dkim;

/**
 * A stored public key text (`DKIM_PUB_KEYS`): its length in bits, the DNS record value of legacy
 * and its 255-character parts (legacy SPLIT_DKIM_255), and its SHA-256 fingerprint.
 */
final readonly class DkimPublicKey
{
    public function __construct(public string $text) {}

    /** Bits of the RSA key, or null when the text is not an RSA public key. */
    public function bits(): ?int
    {
        if ($this->text === '' || base64_decode($this->text, true) === false) {
            return null;
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split($this->text, 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = @openssl_pkey_get_public($pem);

        while (openssl_error_string() !== false) {
            // Keep OpenSSL's error queue empty for later callers.
        }

        $details = $key === false ? false : openssl_pkey_get_details($key);

        if (! is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            return null;
        }

        return (int) $details['bits'];
    }

    public function recordValue(): string
    {
        return 'v=DKIM1;k=rsa;t=s;s=email;p='.$this->text;
    }

    /**
     * @return list<string>
     */
    public function recordParts(): array
    {
        return str_split($this->recordValue(), 255);
    }

    /** Hex SHA-256 of the DER bytes, or null when the text is not base64. */
    public function sha256Fingerprint(): ?string
    {
        $der = base64_decode($this->text, true);

        return $der === false || $der === '' ? null : hash('sha256', $der);
    }
}
