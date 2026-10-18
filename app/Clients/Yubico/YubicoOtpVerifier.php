<?php

namespace App\Clients\Yubico;

/**
 * Verifies a Yubico OTP against the Yubico verification service with the per-key API credentials
 * stored in mailcow's `tfa.secret` (FR-022, R11). Faked in tests.
 */
interface YubicoOtpVerifier
{
    /**
     * @param  string  $clientId  the Yubico API client ID
     * @param  string  $key  the base64 API key
     * @param  string  $otp  the 44-character OTP
     */
    public function verify(string $clientId, string $key, string $otp): YubicoVerificationResult;
}
