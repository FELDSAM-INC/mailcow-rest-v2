<?php

namespace App\Support\Totp;

use LogicException;
use RobThree\Auth\Providers\Qr\IQRCodeProvider;

/**
 * robthree/twofactorauth 3 requires a QR code provider, but feature 001 only verifies codes (R10).
 * Enrolment with QR codes arrives with feature 005.
 */
final class NoQrCodeProvider implements IQRCodeProvider
{
    public function getQRCodeImage(string $qrText, int $size): string
    {
        throw new LogicException('QR codes are not available in this feature.');
    }

    public function getMimeType(): string
    {
        throw new LogicException('QR codes are not available in this feature.');
    }
}
