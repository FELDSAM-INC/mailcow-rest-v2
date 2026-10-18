<?php

namespace App\Support\WebAuthn;

use App\Services\Branding\ProductName;
use App\Services\Config\MailcowConfig;
use Illuminate\Http\Request;
use lbuchs\WebAuthn\WebAuthn;

/**
 * Builds the lbuchs WebAuthn server like legacy prerequisites.inc.php does (R9):
 *
 *  - relying party ID: the request host, lower-cased, without port (legacy
 *    `parse_url('https://' . HTTP_HOST, PHP_URL_HOST)`), so credentials stay bound to their host;
 *  - relying party name: the product name (legacy: `WebAuthn Library`; display only);
 *  - attestation formats: mailcow's FIDO2_FORMATS; base64url in JSON;
 *  - with WEBAUTHN_ONLY_TRUSTED_VENDORS=y, the root certificates legacy ships.
 */
class WebAuthnServerFactory
{
    public function __construct(
        private readonly MailcowConfig $config,
        private readonly ProductName $productName,
    ) {}

    public function make(Request $request): WebAuthn
    {
        $server = new WebAuthn($this->productName->get(), self::rpId($request), $this->config->fido2Formats(), true);

        if (config('restv2.webauthn_only_trusted_vendors') === true) {
            $server->addRootCertificates(resource_path('webauthn/rootCertificates'));
        }

        return $server;
    }

    public static function rpId(Request $request): string
    {
        return strtolower($request->getHost());
    }
}
