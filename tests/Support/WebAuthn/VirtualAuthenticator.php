<?php

namespace Tests\Support\WebAuthn;

use RuntimeException;

/**
 * A software WebAuthn authenticator for tests (research R3): EC P-256 credentials made with
 * OpenSSL, attestation format `none`, ES256 assertions. It produces the WebAuthn Level 3 JSON
 * (`RegistrationResponseJSON`, `AuthenticationResponseJSON`) that browsers send.
 */
final class VirtualAuthenticator
{
    private const FLAG_UP = 0x01;

    private const FLAG_UV = 0x04;

    private const FLAG_AT = 0x40;

    private ?VirtualCredential $last = null;

    public function __construct(private readonly string $aaguid = "\x6d\x63\x76\x32-test-aaguid") {}

    public function createCredential(string $rpId, string $userHandle): VirtualCredential
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        if ($key === false) {
            throw new RuntimeException('OpenSSL could not create an EC P-256 key.');
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false) {
            throw new RuntimeException('OpenSSL returned no key details.');
        }

        $x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        $coseKey = CborEncoder::encode([
            1 => 2,                 // kty: EC2
            3 => -7,                // alg: ES256
            -1 => 1,                // crv: P-256
            -2 => new CborBytes($x),
            -3 => new CborBytes($y),
        ]);

        return $this->last = new VirtualCredential(
            id: random_bytes(32),
            rpId: $rpId,
            userHandle: $userHandle,
            privateKey: $key,
            publicKeyPem: $details['key'],
            coseKey: $coseKey,
            aaguid: str_pad(substr($this->aaguid, 0, 16), 16, "\0"),
        );
    }

    public function lastCredential(): VirtualCredential
    {
        return $this->last ?? throw new RuntimeException('No credential was created yet.');
    }

    /**
     * Answers `PublicKeyCredentialCreationOptionsJSON` with a new credential.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed> RegistrationResponseJSON
     */
    public function attest(array $options, string $origin, bool $uv = true): array
    {
        $rpId = (string) $options['rp']['id'];
        $credential = $this->createCredential($rpId, self::base64UrlDecode((string) $options['user']['id']));

        $clientDataJson = (string) json_encode([
            'type' => 'webauthn.create',
            'challenge' => $options['challenge'],
            'origin' => $origin,
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);

        $authData = hash('sha256', $rpId, true)
            .chr(self::FLAG_UP | ($uv ? self::FLAG_UV : 0) | self::FLAG_AT)
            .pack('N', $credential->signCount)
            .$credential->aaguid
            .pack('n', strlen($credential->id))
            .$credential->id
            .$credential->coseKey;

        $attestationObject = CborEncoder::encode([
            'fmt' => 'none',
            'attStmt' => new CborMap,
            'authData' => new CborBytes($authData),
        ]);

        $id = self::base64UrlEncode($credential->id);

        return [
            'id' => $id,
            'rawId' => $id,
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::base64UrlEncode($clientDataJson),
                'attestationObject' => self::base64UrlEncode($attestationObject),
                'transports' => ['internal', 'hybrid'],
            ],
            'authenticatorAttachment' => 'platform',
            'clientExtensionResults' => new \stdClass,
        ];
    }

    /**
     * Answers `PublicKeyCredentialRequestOptionsJSON` with an ES256 assertion.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed> AuthenticationResponseJSON
     */
    public function assert(
        VirtualCredential $credential,
        array $options,
        string $origin,
        bool $uv = true,
        bool $up = true,
        ?string $userHandle = null,
    ): array {
        $rpId = (string) ($options['rpId'] ?? $credential->rpId);
        $credential->signCount++;

        $clientDataJson = (string) json_encode([
            'type' => 'webauthn.get',
            'challenge' => $options['challenge'],
            'origin' => $origin,
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);

        $authData = hash('sha256', $rpId, true)
            .chr(($up ? self::FLAG_UP : 0) | ($uv ? self::FLAG_UV : 0))
            .pack('N', $credential->signCount);

        if (! openssl_sign($authData.hash('sha256', $clientDataJson, true), $signature, $credential->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('OpenSSL could not sign the assertion.');
        }

        $id = self::base64UrlEncode($credential->id);

        return [
            'id' => $id,
            'rawId' => $id,
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::base64UrlEncode($clientDataJson),
                'authenticatorData' => self::base64UrlEncode($authData),
                'signature' => self::base64UrlEncode($signature),
                'userHandle' => self::base64UrlEncode($userHandle ?? $credential->userHandle),
            ],
            'authenticatorAttachment' => 'platform',
            'clientExtensionResults' => new \stdClass,
        ];
    }

    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            throw new RuntimeException('Invalid base64url value.');
        }

        return $decoded;
    }
}
