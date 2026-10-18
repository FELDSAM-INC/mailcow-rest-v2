<?php

namespace Tests\Feature\ApiKeys;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\TestCase;

/**
 * T084 (FR-031, FR-037, FR-038, R8): operations that need a browser session refuse API keys with
 * `403 session-required`. A read-only key is refused with `insufficient-key-scope` on unsafe
 * methods first, because the scope check comes before the authentication mode.
 */
class SessionOnlyOperationsWithKeyTest extends TestCase
{
    use ApiKeys;

    private const PASSKEY_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * operation => [method, path, body (null: none)]
     *
     * @return array<string, array{string, string, array<string, mixed>|null}>
     */
    public static function sessionOnlyOperations(): array
    {
        $assertion = ['id' => 'AQID', 'rawId' => 'AQID', 'type' => 'public-key', 'response' => [
            'clientDataJSON' => 'e30', 'authenticatorData' => 'AQID', 'signature' => 'AQID',
        ]];
        $attestation = ['id' => 'AQID', 'rawId' => 'AQID', 'type' => 'public-key', 'response' => [
            'clientDataJSON' => 'e30', 'attestationObject' => 'oA',
        ]];

        return [
            'getCsrfToken' => ['GET', '/api/v2/csrf-token', null],
            'getSession' => ['GET', '/api/v2/session', null],
            'createSession' => ['POST', '/api/v2/session', ['username' => 'admin', 'password' => 'x']],
            'deleteSession' => ['DELETE', '/api/v2/session', null],
            'cancelPendingSignIn' => ['DELETE', '/api/v2/session/pending-sign-in', null],
            'createSecondFactorWebAuthnChallenge' => ['POST', '/api/v2/session/pending-sign-in/webauthn-challenge', null],
            'verifySecondFactor' => ['POST', '/api/v2/session/pending-sign-in/second-factor', ['type' => 'totp', 'methodId' => 1, 'code' => '123456']],
            'createPasskeyChallenge' => ['POST', '/api/v2/session/passkey-challenge', null],
            'createSessionWithPasskey' => ['POST', '/api/v2/session/passkey-assertion', ['credential' => $assertion]],
            'getPreferences' => ['GET', '/api/v2/me/preferences', null],
            'updatePreferences' => ['PATCH', '/api/v2/me/preferences', ['theme' => 'dark']],
            'listPasskeys' => ['GET', '/api/v2/me/passkeys', null],
            'createPasskeyRegistrationOptions' => ['POST', '/api/v2/me/passkeys/registration-options', null],
            'registerPasskey' => ['POST', '/api/v2/me/passkeys', ['credential' => $attestation]],
            'getPasskey' => ['GET', '/api/v2/me/passkeys/'.self::PASSKEY_ID, null],
            'updatePasskey' => ['PATCH', '/api/v2/me/passkeys/'.self::PASSKEY_ID, ['name' => 'Key']],
            'deletePasskey' => ['DELETE', '/api/v2/me/passkeys/'.self::PASSKEY_ID, null],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return TestResponse<Response>
     */
    private function send(string $method, string $path, ?array $body, string $secret): TestResponse
    {
        $headers = ['X-API-Key' => $secret];

        if ($body === null) {
            return $method === 'POST' ? $this->postEmpty($path, $headers) : $this->json($method, $path, [], $headers);
        }

        return $method === 'PATCH'
            ? $this->patchMerge($path, $body, $headers)
            : $this->json($method, $path, $body, $headers);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    #[DataProvider('sessionOnlyOperations')]
    public function test_a_read_write_key_needs_a_session(string $method, string $path, ?array $body): void
    {
        $this->mailcow()->admin('admin', 'x');
        $key = $this->apiKey('admin', 'readWrite');

        $response = $this->send($method, $path, $body, $key['secret']);

        $response->assertForbidden()->assertJsonPath('type', '/api/v2/problems/session-required');
        $this->assertSame([], $response->headers->getCookies(), 'a key request never starts a session');
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    #[DataProvider('sessionOnlyOperations')]
    public function test_a_read_only_key_is_refused_by_scope_first(string $method, string $path, ?array $body): void
    {
        $this->mailcow()->admin('admin', 'x');
        $key = $this->apiKey('admin', 'readOnly');

        $response = $this->send($method, $path, $body, $key['secret']);

        $response->assertForbidden()->assertJsonPath(
            'type',
            $method === 'GET' ? '/api/v2/problems/session-required' : '/api/v2/problems/insufficient-key-scope',
        );
    }
}
