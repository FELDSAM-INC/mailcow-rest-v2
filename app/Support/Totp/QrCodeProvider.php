<?php

namespace App\Support\Totp;

use RobThree\Auth\Providers\Qr\BaconQrCodeProvider;

/**
 * The QR code of a TOTP enrolment (feature 005 FR-030, research R7): legacy's library
 * (bacon/bacon-qr-code) through robthree's provider, as SVG, which needs no imagick extension.
 * The image contains only `svg`, `rect` and `path` elements (G26). 001's NoQrCodeProvider stays for
 * verification-only uses.
 */
final class QrCodeProvider extends BaconQrCodeProvider
{
    public function __construct()
    {
        parent::__construct(4, '#ffffff', '#000000', 'svg');
    }
}
