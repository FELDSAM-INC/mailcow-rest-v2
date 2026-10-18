<?php

namespace Tests\Support\Yubico;

use App\Clients\Yubico\YubicoOtpVerifier;
use App\Clients\Yubico\YubicoVerificationResult;

/**
 * Stands in for the Yubico verification service (R3, R11): answers a fixed result and records the
 * credentials and OTPs it was asked to verify.
 */
final class FakeYubicoOtpVerifier implements YubicoOtpVerifier
{
    /** @var list<array{clientId: string, key: string, otp: string}> */
    public array $calls = [];

    public function __construct(public YubicoVerificationResult $result = YubicoVerificationResult::Valid) {}

    public function verify(string $clientId, string $key, string $otp): YubicoVerificationResult
    {
        $this->calls[] = ['clientId' => $clientId, 'key' => $key, 'otp' => $otp];

        return $this->result;
    }
}
