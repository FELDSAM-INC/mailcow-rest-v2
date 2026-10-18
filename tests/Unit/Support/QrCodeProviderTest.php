<?php

namespace Tests\Unit\Support;

use App\Support\Totp\QrCodeProvider;
use PHPUnit\Framework\TestCase;
use RobThree\Auth\Algorithm;
use RobThree\Auth\TwoFactorAuth;

/**
 * T035 (feature 005 research R7): the TOTP enrolment's QR code is an SVG data URI made with
 * legacy's QR library, without the imagick extension.
 */
class QrCodeProviderTest extends TestCase
{
    public function test_the_qr_code_is_an_svg_data_uri(): void
    {
        $totp = new TwoFactorAuth(new QrCodeProvider, 'mailcow UI', 6, 30, Algorithm::Sha1);

        $uri = $totp->getQRCodeImageAsDataUri('anna@example.org', 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP');

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);
        $svg = (string) base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true);
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('</svg>', $svg);
        $this->assertSame('image/svg+xml', (new QrCodeProvider)->getMimeType());
    }
}
