<?php

namespace Tests\Unit\Support;

use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;
use PHPUnit\Framework\TestCase;
use Tests\Support\WebAuthn\VirtualAuthenticator;

/**
 * T020 (R3, R9): the software authenticator produces registrations and assertions that the real
 * WebAuthn library accepts, with the storage format legacy mailcow uses.
 */
class VirtualAuthenticatorTest extends TestCase
{
    private const RP_ID = 'mail.example.org';

    private const ORIGIN = 'https://mail.example.org';

    public function test_registration_is_accepted_by_lbuchs_and_stores_the_same_key(): void
    {
        $server = new WebAuthn('Test', self::RP_ID, ['none'], true);
        $options = $this->publicKey($server->getCreateArgs('alice@example.org', 'alice@example.org', 'alice@example.org', 300, true, 'preferred'));
        $authenticator = new VirtualAuthenticator;

        $response = $authenticator->attest($options, self::ORIGIN);
        $credential = $authenticator->lastCredential();

        $this->assertSame('public-key', $response['type']);
        $this->assertSame($response['id'], $response['rawId']);
        $this->assertSame(self::RP_ID, $credential->rpId);
        $this->assertSame('alice@example.org', $credential->userHandle);

        $data = $server->processCreate(
            VirtualAuthenticator::base64UrlDecode($response['response']['clientDataJSON']),
            VirtualAuthenticator::base64UrlDecode($response['response']['attestationObject']),
            $server->getChallenge(),
            true,
            true,
        );

        $this->assertSame('none', $data->attestationFormat);
        $this->assertSame($credential->id, $data->credentialId);
        $this->assertSame($credential->publicKeyPem, $data->credentialPublicKey);
        $this->assertSame($credential->aaguid, $data->AAGUID);
        $this->assertTrue($data->userVerified);
    }

    public function test_assertion_is_accepted_by_lbuchs(): void
    {
        $authenticator = new VirtualAuthenticator;
        $credential = $authenticator->createCredential(self::RP_ID, 'alice@example.org');
        $server = new WebAuthn('Test', self::RP_ID, ['none'], true);
        $options = $this->publicKey($server->getGetArgs([], 300, true, true, true, true, true, 'preferred'));

        $response = $authenticator->assert($credential, $options, self::ORIGIN);

        $this->assertSame(VirtualAuthenticator::base64UrlEncode($credential->id), $response['rawId']);
        $this->assertSame(VirtualAuthenticator::base64UrlEncode('alice@example.org'), $response['response']['userHandle']);
        $this->assertTrue($server->processGet(
            VirtualAuthenticator::base64UrlDecode($response['response']['clientDataJSON']),
            VirtualAuthenticator::base64UrlDecode($response['response']['authenticatorData']),
            VirtualAuthenticator::base64UrlDecode($response['response']['signature']),
            $credential->publicKeyPem,
            $server->getChallenge(),
            null,
            true,
            true,
        ));
    }

    public function test_flags_and_signature_are_enforced_by_lbuchs(): void
    {
        $authenticator = new VirtualAuthenticator;
        $credential = $authenticator->createCredential(self::RP_ID, 'bob@example.org');
        $server = new WebAuthn('Test', self::RP_ID, ['none'], true);
        $options = $this->publicKey($server->getGetArgs([$credential->id], 300));

        $withoutUv = $authenticator->assert($credential, $options, self::ORIGIN, uv: false);
        $this->assertThrows(fn () => $server->processGet(
            VirtualAuthenticator::base64UrlDecode($withoutUv['response']['clientDataJSON']),
            VirtualAuthenticator::base64UrlDecode($withoutUv['response']['authenticatorData']),
            VirtualAuthenticator::base64UrlDecode($withoutUv['response']['signature']),
            $credential->publicKeyPem,
            $server->getChallenge(),
            null,
            true,
        ));

        $other = $authenticator->createCredential(self::RP_ID, 'bob@example.org');
        $wrongKey = $authenticator->assert($other, $options, self::ORIGIN);
        $this->assertThrows(fn () => $server->processGet(
            VirtualAuthenticator::base64UrlDecode($wrongKey['response']['clientDataJSON']),
            VirtualAuthenticator::base64UrlDecode($wrongKey['response']['authenticatorData']),
            VirtualAuthenticator::base64UrlDecode($wrongKey['response']['signature']),
            $credential->publicKeyPem,
            $server->getChallenge(),
        ));

        $wrongOrigin = $authenticator->assert($credential, $options, 'https://evil.example.net');
        $this->assertThrows(fn () => $server->processGet(
            VirtualAuthenticator::base64UrlDecode($wrongOrigin['response']['clientDataJSON']),
            VirtualAuthenticator::base64UrlDecode($wrongOrigin['response']['authenticatorData']),
            VirtualAuthenticator::base64UrlDecode($wrongOrigin['response']['signature']),
            $credential->publicKeyPem,
            $server->getChallenge(),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function publicKey(\stdClass $args): array
    {
        return json_decode((string) json_encode($args->publicKey), true);
    }

    private function assertThrows(callable $callback): void
    {
        try {
            $callback();
        } catch (WebAuthnException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('lbuchs accepted an assertion it must refuse.');
    }
}
