<?php

namespace App\Support\WebAuthn;

use App\Support\Http\Base64Url;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use stdClass;

/**
 * Converts lbuchs's argument objects to the contract's WebAuthn Level 3 JSON (R9):
 * `PublicKeyCredentialCreationOptionsJSON` (WebAuthnCreationOptions) and
 * `PublicKeyCredentialRequestOptionsJSON` (WebAuthnRequestOptions).
 *
 *  - every binary member becomes unpadded base64url, whatever lbuchs's encoding mode;
 *  - `allowCredentials` and `excludeCredentials` are always present (lbuchs omits empty lists);
 *  - registration requires a resident key (`residentKey: "required"`, `requireResidentKey: true`);
 *  - lbuchs's private `extensions.exts` is dropped; the second-factor challenge sets `appid`;
 *  - registration keeps lbuchs's attestation conveyance, as legacy sends it: `none` when only the
 *    `none` format is accepted, `direct` with root certificates (WEBAUTHN_ONLY_TRUSTED_VENDORS=y),
 *    `indirect` otherwise, which shows no browser privacy prompt (contract
 *    `WebAuthnCreationOptions.attestation`);
 *  - `timeout` is 300000 ms (legacy sends 30 s; parity row in the spec).
 */
final class OptionsJson
{
    public const TIMEOUT_MS = 300000;

    /**
     * @return array<string, mixed>
     */
    public static function creation(stdClass $args): array
    {
        /** @var array<string, mixed> $options */
        $options = self::normalize($args->publicKey);

        $options['authenticatorSelection'] = array_merge((array) ($options['authenticatorSelection'] ?? []), [
            'residentKey' => 'required',
            'requireResidentKey' => true,
        ]);
        $options['excludeCredentials'] ??= [];
        $options['timeout'] = self::TIMEOUT_MS;

        return self::cleanExtensions($options);
    }

    /**
     * @param  string|null  $appId  the FIDO AppID extension of the second-factor challenge
     * @return array<string, mixed>
     */
    public static function request(stdClass $args, ?string $appId = null): array
    {
        /** @var array<string, mixed> $options */
        $options = self::normalize($args->publicKey);

        $options['allowCredentials'] ??= [];
        $options['timeout'] = self::TIMEOUT_MS;

        if ($appId !== null) {
            $options['extensions'] = ['appid' => $appId] + (array) ($options['extensions'] ?? []);
        }

        return self::cleanExtensions($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function cleanExtensions(array $options): array
    {
        if (isset($options['extensions']) && is_array($options['extensions'])) {
            unset($options['extensions']['exts']);

            if ($options['extensions'] === []) {
                unset($options['extensions']);
            }
        }

        return $options;
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof ByteBuffer) {
            return Base64Url::encode($value->getBinaryString());
        }

        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }

        if (is_array($value)) {
            return array_map(self::normalize(...), $value);
        }

        return $value;
    }
}
