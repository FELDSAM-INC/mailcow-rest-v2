<?php

namespace App\Rules;

/**
 * An imported DKIM private key (feature 010 FR-019, research R6), checked in this order, the first
 * failure reported: at most 16,384 bytes (`tooLong`, `unit: bytes`); line endings normalised and
 * whitespace trimmed; exactly one PEM block, `PRIVATE KEY` (PKCS #8) or `RSA PRIVATE KEY` (PKCS #1)
 * (`invalidPrivateKey`), not encrypted (`encryptedPrivateKey`), not an EC, DSA or OpenSSH key
 * (`unsupportedKeyType`, `allowed: [rsa]`); a key OpenSSL reads (`invalidPrivateKey`), of type RSA
 * (`unsupportedKeyType`), of 1,024 to 8,192 bits (`keyLengthOutOfRange`). No message or parameter
 * ever quotes the text.
 */
class DkimPrivateKey extends CodedRule
{
    /**
     * The text as stored: legacy's normalisation (`\r\n` and `\r` to `\n`), trimmed.
     */
    public static function normalize(#[\SensitiveParameter] string $text): string
    {
        return trim((string) preg_replace('~\r\n?~', "\n", $text));
    }

    protected function check(#[\SensitiveParameter] mixed $value): ?array
    {
        $max = (int) config('restv2.dkim.private_key_max_bytes', 16384);
        [$minBits, $maxBits] = array_map('intval', (array) config('restv2.dkim.import_bits', [1024, 8192]));

        if (! is_string($value)) {
            return $this->invalid();
        }

        if (strlen($value) > $max) {
            return $this->violation('tooLong', ['max' => $max, 'unit' => 'bytes'], "The private key must be at most {$max} bytes long.");
        }

        $text = self::normalize($value);

        if (preg_match_all('/^-----BEGIN ([A-Z0-9 ]+)-----$/m', $text, $labels) !== 1
            || preg_match('/^-----BEGIN ([A-Z0-9 ]+)-----\n.*\n-----END \1-----$/s', $text) !== 1) {
            return $this->invalid();
        }

        $label = $labels[1][0];

        if ($label === 'ENCRYPTED PRIVATE KEY' || preg_match('/^Proc-Type:\s*4,ENCRYPTED/mi', $text) === 1) {
            return $this->violation('encryptedPrivateKey', [], 'The private key is encrypted; import it unencrypted.');
        }

        if (in_array($label, ['EC PRIVATE KEY', 'DSA PRIVATE KEY', 'OPENSSH PRIVATE KEY'], true)) {
            return $this->unsupported();
        }

        if (! in_array($label, ['PRIVATE KEY', 'RSA PRIVATE KEY'], true)) {
            return $this->invalid();
        }

        while (openssl_error_string() !== false) {
            // Drain earlier OpenSSL errors.
        }

        $key = @openssl_pkey_get_private($text);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        while (openssl_error_string() !== false) {
            // Keep OpenSSL's error queue empty: its messages could describe the key.
        }

        if (! is_array($details)) {
            return $this->invalid();
        }

        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            return $this->unsupported();
        }

        $bits = (int) ($details['bits'] ?? 0);

        return $bits < $minBits || $bits > $maxBits
            ? $this->violation('keyLengthOutOfRange', ['min' => $minBits, 'max' => $maxBits], "The RSA key must have {$minBits} to {$maxBits} bits.")
            : null;
    }

    /**
     * @return array{code: string, params: array<string, mixed>, message: string}
     */
    private function invalid(): array
    {
        return $this->violation('invalidPrivateKey', [], 'Not exactly one PEM-encoded private key (PKCS #1 or PKCS #8).');
    }

    /**
     * @return array{code: string, params: array<string, mixed>, message: string}
     */
    private function unsupported(): array
    {
        return $this->violation('unsupportedKeyType', ['allowed' => ['rsa']], 'Only RSA keys are supported.');
    }
}
