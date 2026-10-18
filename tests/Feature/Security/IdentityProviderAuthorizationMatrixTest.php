<?php

namespace Tests\Feature\Security;

use App\Auth\Role;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\FeatureRegistry;
use Tests\Support\IdentityProvider\FakeOidcProvider;
use Tests\Support\IdentityProvider\IdentityProviderFixtures;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\TestCase;

/**
 * T088 (constitution VI, quality gate 5, FR-001 to FR-003, SC-005): every operation of feature 012
 * for every kind of caller, derived from the contract, research R15 and FR-003.
 */
class IdentityProviderAuthorizationMatrixTest extends TestCase
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

    /** The sign-in ceremonies of single sign-on (browser sessions only). */
    private const SINGLE_SIGN_ON = ['startSingleSignOn', 'cancelSingleSignOn', 'completeSingleSignOn', 'reportSingleSignOnProviderError'];

    private int $clientId = 0;

    /** @var array<string, string> */
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->mailboxTemplate('Staff');
        $f->keycloakConfig();
        $f->admin('admin', MailcowFactory::PASSWORD);
        $f->admin('restricted', MailcowFactory::PASSWORD, attributes: ['force_pw_update' => '1']);
        $f->admin('setup', MailcowFactory::PASSWORD, attributes: ['force_tfa' => '1']);
        $f->domain('example.org');
        $f->domainAdmin('reseller', ['example.org']);
        $f->mailbox('user@example.org');
        $this->clientId = $f->oauthClient('https://wiki.example.org/cb');
        $this->keys = [
            'adminKey' => $this->apiKey('admin', 'readWrite')['secret'],
            'adminReadOnlyKey' => $this->apiKey('admin', 'readOnly')['secret'],
            'domainAdminKey' => $this->apiKey('reseller', 'readWrite')['secret'],
        ];
        FakeOidcProvider::install()->tokenIssued('client_credentials', 'service-token', null);
    }

    /**
     * operation => [method, path, body or null, success status]. `{client}` is a client ID.
     *
     * @return array<string, array{string, string, array<string, mixed>|null, int}>
     */
    private static function requests(): array
    {
        return [
            'getIdentityProviderCapabilities' => ['GET', '/api/v2/capabilities/identity-provider', null, 200],
            'getIdentityProvider' => ['GET', '/api/v2/identity-provider', null, 200],
            'saveIdentityProvider' => ['PUT', '/api/v2/identity-provider', IdentityProviderFixtures::keycloak(), 200],
            'updateIdentityProvider' => ['PATCH', '/api/v2/identity-provider', ['syncInterval' => 20], 200],
            'deleteIdentityProvider' => ['DELETE', '/api/v2/identity-provider', null, 204],
            'testIdentityProviderConnection' => ['POST', '/api/v2/identity-provider/connection-tests', IdentityProviderFixtures::keycloak(), 200],
            'startSingleSignOn' => ['POST', '/api/v2/session/single-sign-on', null, 200],
            'cancelSingleSignOn' => ['DELETE', '/api/v2/session/single-sign-on', null, 204],
            'completeSingleSignOn' => ['POST', '/api/v2/session/single-sign-on/completion', ['code' => 'c', 'state' => 's'], 401],
            'reportSingleSignOnProviderError' => ['POST', '/api/v2/session/single-sign-on/provider-error', ['error' => 'access_denied', 'state' => 's'], 401],
            'getOauth2ClientsCapabilities' => ['GET', '/api/v2/capabilities/oauth2-clients', null, 200],
            'listOauth2Clients' => ['GET', '/api/v2/oauth2-clients', null, 200],
            'createOauth2Client' => ['POST', '/api/v2/oauth2-clients', ['redirectUris' => ['https://x.example.org/cb']], 201],
            'bulkOauth2ClientAction' => ['POST', '/api/v2/oauth2-clients/bulk-actions', ['action' => 'revokeTokens', 'targets' => [['id' => '{client}']]], 200],
            'getOauth2Client' => ['GET', '/api/v2/oauth2-clients/{client}', null, 200],
            'updateOauth2Client' => ['PATCH', '/api/v2/oauth2-clients/{client}', ['redirectUris' => ['https://y.example.org/cb']], 200],
            'deleteOauth2Client' => ['DELETE', '/api/v2/oauth2-clients/{client}', null, 204],
            'regenerateOauth2ClientSecret' => ['POST', '/api/v2/oauth2-clients/{client}/secret-regeneration', null, 200],
            'revokeOauth2ClientTokens' => ['POST', '/api/v2/oauth2-clients/{client}/token-revocation', null, 200],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $cases = [];

        foreach (array_keys(self::requests()) as $operation) {
            foreach (self::CALLERS as $caller) {
                $cases["{$operation} as {$caller}"] = [$operation, $caller];
            }
        }

        return $cases;
    }

    /**
     * @return array{int, string|null} status and problem type name (null: none or about:blank)
     */
    private static function expected(string $operation, string $caller): array
    {
        [$method, , , $success] = self::requests()[$operation];

        if ($caller === 'adminReadOnlyKey' && $method !== 'GET') {
            return [403, 'insufficient-key-scope'];
        }

        if (in_array($operation, self::SINGLE_SIGN_ON, true)) {
            // Browser sessions in any state start and complete sign-ins; keys never (FR-002).
            return match (true) {
                str_ends_with($caller, 'Key') => [403, 'session-required'],
                $success === 401 => [401, 'sign-in-state-invalid'],
                default => [$success, null],
            };
        }

        return match ($caller) {
            'anonymous' => [401, 'unauthenticated'],
            'pendingSignIn' => [401, 'second-factor-required'],
            'restrictedPasswordChange', 'restrictedSecondFactorSetup' => [403, 'pending-action-required'],
            'domainAdminSession', 'mailboxSession', 'domainAdminKey' => [403, 'forbidden'],
            default => [$success, null],
        };
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return TestResponse<Response>
     */
    private function send(string $caller, string $method, string $path, ?array $body): TestResponse
    {
        $headers = [];

        match ($caller) {
            'anonymous' => null,
            'pendingSignIn' => $this->signInPending('admin', Role::Admin),
            'restrictedPasswordChange' => $this->signInAs('restricted', Role::Admin),
            'restrictedSecondFactorSetup' => $this->signInAs('setup', Role::Admin),
            'adminSession' => $this->signInAs('admin', Role::Admin),
            'domainAdminSession' => $this->signInAs('reseller', Role::DomainAdmin),
            'mailboxSession' => $this->signInAs('user@example.org', Role::MailboxUser),
            default => $headers['X-API-Key'] = $this->keys[$caller],
        };

        if (! isset($headers['X-API-Key']) && $method !== 'GET') {
            $headers['X-CSRF-Token'] = $this->csrfToken();
        }

        if ($body === null) {
            return $method === 'POST' ? $this->postEmpty($path, $headers) : $this->json($method, $path, [], $headers);
        }

        $body = json_decode(str_replace('{client}', (string) $this->clientId, (string) json_encode($body)), true);

        return $method === 'PATCH' ? $this->patchMerge($path, (array) $body, $headers) : $this->json($method, $path, (array) $body, $headers);
    }

    #[DataProvider('cases')]
    public function test_every_operation_for_every_caller(string $operation, string $caller): void
    {
        [$method, $path, $body] = self::requests()[$operation];
        [$status, $type] = self::expected($operation, $caller);

        $response = $this->send($caller, $method, str_replace('{client}', (string) $this->clientId, $path), $body);

        $response->assertStatus($status);

        if ($type !== null) {
            $response->assertJsonPath('type', '/api/v2/problems/'.$type);
        }
    }

    public function test_the_matrix_covers_every_operation_of_the_feature(): void
    {
        $this->assertEqualsCanonicalizing(FeatureRegistry::load()->features['012-identity-providers-oauth2']['operations'], array_keys(self::requests()));
    }

    /**
     * @return array<string, array{string, int, string|null}>
     */
    public static function unknownClients(): array
    {
        return [
            'admin session' => ['adminSession', 404, null],
            'admin key' => ['adminKey', 404, null],
            'domain administrator' => ['domainAdminSession', 403, 'forbidden'],
            'mailbox user' => ['mailboxSession', 403, 'forbidden'],
        ];
    }

    #[DataProvider('unknownClients')]
    public function test_an_unknown_client_is_not_found_only_for_administrators(string $caller, int $status, ?string $type): void
    {
        foreach ([['GET', '/api/v2/oauth2-clients/999999', null], ['PATCH', '/api/v2/oauth2-clients/999999', ['redirectUris' => ['https://z.example.org/cb']]], ['DELETE', '/api/v2/oauth2-clients/999999', null], ['POST', '/api/v2/oauth2-clients/999999/token-revocation', null]] as [$method, $path, $body]) {
            $this->forgetCookies();
            $response = $this->send($caller, $method, $path, $body)->assertStatus($status);

            if ($type !== null) {
                $response->assertJsonPath('type', '/api/v2/problems/'.$type);
            }
        }
    }
}
