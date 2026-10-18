<?php

namespace Tests\Feature\Security;

use App\Auth\Role;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\WebAuthn\VirtualAuthenticator;
use Tests\TestCase;

/**
 * T105 (SC-005, SC-010, constitution VI, quality gate 5): every operation of feature 001 for every
 * kind of caller. The expected answers are derived from the operations' security in `api/`, the
 * request pipeline of R8 and FR-014:
 *
 *  - a key is resolved first, then a read-only key is refused for unsafe methods
 *    (`insufficient-key-scope`), then the authentication mode refuses what it does not accept
 *    (`unauthenticated`, `second-factor-required`, `session-required`), then a restricted session
 *    is refused (`pending-action-required`), then the policy (`forbidden`), then the object (`404`);
 *  - an allowed caller gets the operation's own answer for the request sent here.
 */
class AuthorizationMatrixTest extends TestCase
{
    use ApiKeys;

    private const CALLERS = [
        'anonymous',
        'pendingSignIn',
        'restrictedPasswordChange',
        'restrictedSecondFactorSetup',
        'adminSession',
        'domainAdminSession',
        'mailboxSession',
        'adminKey',
        'adminReadOnlyKey',
        'domainAdminKey',
    ];

    private const UNKNOWN_KEY_ID = '01JAAAAAAAAAAAAAAAAAAAAAAA';

    /** Set up per test: another account's passkey ID (object-level checks). */
    private string $foreignPasskeyId = '';

    /** Set up per test: the TOTP method of the pending sign-in. */
    private int $totpId = 0;

    /**
     * operation => [method, path, body or null]. `{passkey}` is another account's passkey.
     *
     * @return array<string, array{string, string, array<string, mixed>|null}>
     */
    private static function requests(): array
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
            'createSession' => ['POST', '/api/v2/session', ['username' => 'nobody', 'password' => 'wrong']],
            'deleteSession' => ['DELETE', '/api/v2/session', null],
            'cancelPendingSignIn' => ['DELETE', '/api/v2/session/pending-sign-in', null],
            'createSecondFactorWebAuthnChallenge' => ['POST', '/api/v2/session/pending-sign-in/webauthn-challenge', null],
            'verifySecondFactor' => ['POST', '/api/v2/session/pending-sign-in/second-factor', ['type' => 'totp', 'methodId' => 999999, 'code' => '123456']],
            'createPasskeyChallenge' => ['POST', '/api/v2/session/passkey-challenge', null],
            'createSessionWithPasskey' => ['POST', '/api/v2/session/passkey-assertion', ['credential' => $assertion]],
            'getIdentity' => ['GET', '/api/v2/me', null],
            'getPreferences' => ['GET', '/api/v2/me/preferences', null],
            'updatePreferences' => ['PATCH', '/api/v2/me/preferences', ['theme' => 'dark']],
            'listPasskeys' => ['GET', '/api/v2/me/passkeys', null],
            'registerPasskey' => ['POST', '/api/v2/me/passkeys', ['credential' => $attestation]],
            'createPasskeyRegistrationOptions' => ['POST', '/api/v2/me/passkeys/registration-options', null],
            'getPasskey' => ['GET', '/api/v2/me/passkeys/{passkey}', null],
            'updatePasskey' => ['PATCH', '/api/v2/me/passkeys/{passkey}', ['name' => 'Mine now']],
            'deletePasskey' => ['DELETE', '/api/v2/me/passkeys/{passkey}', null],
            'listApiKeys' => ['GET', '/api/v2/api-keys', null],
            'createApiKey' => ['POST', '/api/v2/api-keys', ['name' => 'Matrix', 'ownerUsername' => 'admin', 'accessLevel' => 'readOnly']],
            'listApiKeyOwnerCandidates' => ['GET', '/api/v2/api-keys/owner-candidates', null],
            'getApiKey' => ['GET', '/api/v2/api-keys/'.self::UNKNOWN_KEY_ID, null],
            'revokeApiKey' => ['POST', '/api/v2/api-keys/'.self::UNKNOWN_KEY_ID.'/revocation', null],
            'getPublicConfig' => ['GET', '/api/v2/public-config', null],
            'getPublicConfigLogo' => ['GET', '/api/v2/public-config/logos/light', null],
            'listAuditEvents' => ['GET', '/api/v2/audit-events', null],
            'getLiveness' => ['GET', '/api/v2/health/live', null],
            'getReadiness' => ['GET', '/api/v2/health/ready', null],
            'getOpenApiDocument' => ['GET', '/api/v2/openapi.yaml', null],
            'getApiDocs' => ['GET', '/api/v2/docs', null],
            'getProblemTypeDocumentation' => ['GET', '/api/v2/problems/sign-in-failed', null],
        ];
    }

    /**
     * The expected answer: [status, problem type name or null (no problem or about:blank)].
     *
     * @return array{int, string|null}
     */
    private static function expected(string $operation, string $caller): array
    {
        $key = in_array($caller, ['adminKey', 'adminReadOnlyKey', 'domainAdminKey'], true);
        $unsafe = self::requests()[$operation][0] !== 'GET';
        $restricted = in_array($caller, ['restrictedPasswordChange', 'restrictedSecondFactorSetup'], true);

        if ($caller === 'adminReadOnlyKey' && $unsafe && ! in_array($operation, self::PUBLIC, true)) {
            return [403, 'insufficient-key-scope'];
        }

        // Browser routes: any session state, never a key.
        if (array_key_exists($operation, self::BROWSER)) {
            if ($key) {
                return [403, 'session-required'];
            }

            return self::BROWSER[$operation][$caller] ?? self::BROWSER[$operation]['*'];
        }

        // Public and system routes: everyone.
        if (in_array($operation, self::PUBLIC, true)) {
            return [200, null];
        }

        if ($caller === 'anonymous') {
            return [401, 'unauthenticated'];
        }

        if ($caller === 'pendingSignIn') {
            return [401, 'second-factor-required'];
        }

        // Who am I: sessions in any signed-in state and keys.
        if ($operation === 'getIdentity') {
            return [200, null];
        }

        // The signed-in user's own data: sessions only.
        if (array_key_exists($operation, self::OWN)) {
            if ($key) {
                return [403, 'session-required'];
            }

            $registration = in_array($operation, ['createPasskeyRegistrationOptions', 'registerPasskey'], true);

            if ($restricted && ! ($registration && $caller === 'restrictedSecondFactorSetup')) {
                return [403, 'pending-action-required'];
            }

            return self::OWN[$operation];
        }

        // API keys and the audit log: administrators; creating and revoking needs a session.
        $sessionOnly = in_array($operation, ['createApiKey', 'revokeApiKey'], true);

        if ($key && $sessionOnly) {
            return [403, 'session-required'];
        }

        if ($restricted) {
            return [403, 'pending-action-required'];
        }

        if (in_array($caller, ['domainAdminSession', 'mailboxSession', 'domainAdminKey'], true)) {
            return [403, 'forbidden'];
        }

        return self::ADMIN[$operation];
    }

    /** Operations open to everyone, keys included (the system ones ignore keys). */
    private const PUBLIC = [
        'getPublicConfig', 'getPublicConfigLogo', 'getLiveness', 'getReadiness', 'getOpenApiDocument',
        'getApiDocs', 'getProblemTypeDocumentation',
    ];

    /**
     * Browser routes: the answer per session caller (`*` for the others).
     *
     * @var array<string, array<string, array{int, string|null}>>
     */
    private const BROWSER = [
        'getCsrfToken' => ['*' => [200, null]],
        'getSession' => ['*' => [200, null]],
        'createSession' => ['*' => [401, 'sign-in-failed']],
        'deleteSession' => ['*' => [204, null]],
        'cancelPendingSignIn' => ['*' => [204, null]],
        // Only a pending sign-in has one; this one has only TOTP (409 about:blank).
        'createSecondFactorWebAuthnChallenge' => ['*' => [401, 'sign-in-expired'], 'pendingSignIn' => [409, null]],
        'verifySecondFactor' => ['*' => [401, 'sign-in-expired'], 'pendingSignIn' => [401, 'second-factor-failed']],
        'createPasskeyChallenge' => ['*' => [200, null]],
        // No challenge was issued.
        'createSessionWithPasskey' => ['*' => [401, 'sign-in-expired']],
    ];

    /**
     * The signed-in user's own data: the answer of an allowed session. Another account's passkey
     * answers 404 for every role, administrators included.
     *
     * @var array<string, array{int, string|null}>
     */
    private const OWN = [
        'getPreferences' => [200, null],
        'updatePreferences' => [200, null],
        'listPasskeys' => [200, null],
        // No registration options were issued.
        'registerPasskey' => [400, 'passkey-registration-failed'],
        'createPasskeyRegistrationOptions' => [200, null],
        'getPasskey' => [404, null],
        'updatePasskey' => [404, null],
        'deletePasskey' => [404, null],
    ];

    /**
     * Administrator operations: the answer of an allowed administrator. An unknown key ID is 404
     * for administrators (and 403 for the others, see expected()).
     *
     * @var array<string, array{int, string|null}>
     */
    private const ADMIN = [
        'listApiKeys' => [200, null],
        'createApiKey' => [201, null],
        'listApiKeyOwnerCandidates' => [200, null],
        'getApiKey' => [404, null],
        'revokeApiKey' => [404, null],
        'listAuditEvents' => [200, null],
    ];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        foreach (array_keys(self::requests()) as $operation) {
            foreach (self::CALLERS as $caller) {
                yield "{$operation} as {$caller}" => [$operation, $caller];
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // No password is verified here: a fast hash scheme keeps the 312 cases quick.
        $fast = 'SSHA256';
        $this->mailcow()->admin('admin', 'x', $fast);
        $this->mailcow()->admin('pending', 'x', $fast);
        $this->totpId = $this->mailcow()->tfaTotp('pending', 'JBSWY3DPEHPK3PXP');
        $this->mailcow()->admin('forced-pw', 'x', $fast, attributes: ['force_pw_update' => '1']);
        $this->mailcow()->admin('forced-tfa', 'x', $fast, attributes: ['force_tfa' => '1']);
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->domainAdmin('reseller', ['example.org'], password: 'x', scheme: $fast);
        $this->mailcow()->mailbox('alice@example.org', 'x', $fast);
        $this->mailcow()->mailbox('bob@example.org', 'x', $fast);
        $credential = (new VirtualAuthenticator)->createCredential('mail.example.org', 'bob@example.org');
        $this->mailcow()->fido2Credential('bob@example.org', $credential);
        $this->foreignPasskeyId = hash('sha256', $credential->id);
        Redis::connection('mailcow')->set('MAIN_LOGO', 'data:image/gif;base64,'.base64_encode('GIF89a'));
    }

    /**
     * Becomes the caller and returns the headers its requests carry.
     *
     * @return array<string, string>
     */
    private function become(string $caller): array
    {
        if (in_array($caller, ['adminKey', 'adminReadOnlyKey', 'domainAdminKey'], true)) {
            $key = match ($caller) {
                'adminKey' => $this->apiKey('admin', 'readWrite'),
                'adminReadOnlyKey' => $this->apiKey('admin', 'readOnly'),
                default => $this->apiKey('reseller', 'readWrite'),
            };

            return $this->keyHeader($key);
        }

        match ($caller) {
            'anonymous' => null,
            'pendingSignIn' => $this->signInPending('pending', Role::Admin, [$this->totpId]),
            'restrictedPasswordChange' => $this->signInAs('forced-pw', Role::Admin),
            'restrictedSecondFactorSetup' => $this->signInAs('forced-tfa', Role::Admin),
            'adminSession' => $this->signInAs('admin', Role::Admin),
            'domainAdminSession' => $this->signInAs('reseller', Role::DomainAdmin),
            'mailboxSession' => $this->signInAs('alice@example.org', Role::MailboxUser),
            default => $this->fail("Unknown caller {$caller}."),
        };

        return ['X-CSRF-Token' => $this->csrfToken()];
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function send(string $method, string $path, ?array $body, array $headers): TestResponse
    {
        if ($body === null) {
            return $method === 'POST' ? $this->postEmpty($path, $headers) : $this->json($method, $path, [], $headers);
        }

        return $method === 'PATCH' ? $this->patchMerge($path, $body, $headers) : $this->json($method, $path, $body, $headers);
    }

    #[DataProvider('cases')]
    public function test_the_operation_answers_the_caller_as_documented(string $operation, string $caller): void
    {
        [$method, $path, $body] = self::requests()[$operation];
        $path = str_replace('{passkey}', $this->foreignPasskeyId, $path);
        [$status, $type] = self::expected($operation, $caller);

        $response = $this->send($method, 'https://mail.example.org'.$path, $body, $this->become($caller));

        $this->assertSame($status, $response->getStatusCode(), "{$operation} as {$caller}: ".substr((string) $response->getContent(), 0, 300));

        if ($status >= 400) {
            $this->assertSame($type === null ? 'about:blank' : '/api/v2/problems/'.$type, $response->json('type'), "{$operation} as {$caller}");
        }
    }

    public function test_an_invalid_key_is_refused_also_on_the_public_configuration(): void
    {
        $this->getJson('/api/v2/public-config', ['X-API-Key' => 'mcv2_'.str_repeat('Z', 40)])
            ->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/api-key-invalid');
    }

    public function test_the_matrix_covers_every_operation_of_the_feature(): void
    {
        $registry = json_decode((string) file_get_contents(base_path('tests/feature-registry.json')), true);

        $this->assertEqualsCanonicalizing($registry['features']['001-auth-sessions-api-keys']['operations'], array_keys(self::requests()));
        // Seven session states and three keys.
        $this->assertCount(10, self::CALLERS);
    }
}
