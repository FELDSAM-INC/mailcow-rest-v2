<?php

namespace App\Services\DnsDiagnostics;

/**
 * The TLSA value of a certificate (feature 010 FR-042, research R19), as legacy
 * generate_tlsa_digest($host, 25, 1) computes it: `3 1 1` (DANE-EE, SubjectPublicKeyInfo, SHA-256)
 * and the hex SHA-256 of the DER SubjectPublicKeyInfo.
 */
final class TlsaDigest
{
    public static function fromCertificate(string $pem): ?string
    {
        self::drainOpenSslErrors();
        $key = @openssl_pkey_get_public($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        // Drain the errors of a text that is not a certificate.
        self::drainOpenSslErrors();

        if (! is_array($details) || ! is_string($details['key'] ?? null)) {
            return null;
        }

        $body = implode('', array_slice(array_filter(explode("\n", trim($details['key']))), 1, -1));
        $der = base64_decode($body, true);

        return $der === false || $der === '' ? null : '3 1 1 '.hash('sha256', $der);
    }

    private static function drainOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // Keep OpenSSL's error queue empty for later callers.
        }
    }
}
