<?php

namespace Tests\Feature\Oauth2;

use Illuminate\Support\Facades\DB;
use OAuth2\GrantType\AuthorizationCode;
use OAuth2\GrantType\RefreshToken;
use OAuth2\GrantType\UserCredentials;
use OAuth2\Request as OAuth2Request;
use OAuth2\Response as OAuth2Response;
use OAuth2\Scope;
use OAuth2\Server;
use OAuth2\Storage\Memory;
use OAuth2\Storage\Pdo;
use Tests\Support\IdentityProvider\Oauth2TestHelpers;
use Tests\TestCase;

/**
 * T079 (FR-049, SC-007, research R13): clients managed through the API work with the unchanged
 * legacy `/oauth/*` endpoints, simulated with legacy's own OAuth2 server library configured as
 * data/web/inc/prerequisites.inc.php configures it.
 */
class LegacyOauth2EndpointCompatibilityTest extends TestCase
{
    use Oauth2TestHelpers;

    private const REDIRECT = 'https://wiki.example.org/oauth/callback';

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $storage = new Pdo(DB::connection()->getPdo(), ['user_table' => 'mailbox']);
        $this->server = new Server($storage, ['refresh_token_lifetime' => 2678400, 'access_lifetime' => 86400]);
        $this->server->setScopeUtil(new Scope(new Memory(['default_scope' => 'profile', 'supported_scopes' => ['profile']])));
        $this->server->addGrantType(new AuthorizationCode($storage));
        $this->server->addGrantType(new UserCredentials($storage));
        $this->server->addGrantType(new RefreshToken($storage, ['always_issue_new_refresh_token' => true]));
        $this->mailcow()->mailboxWithDomain('user@example.org');
        $this->asAdmin();
    }

    /**
     * @return array{id: int, clientId: string, clientSecret: string}
     */
    private function createClient(): array
    {
        $data = $this->postJson('/api/v2/oauth2-clients', ['redirectUris' => [self::REDIRECT]], $this->csrf())->assertCreated()->json('data');

        return ['id' => (int) $data['id'], 'clientId' => (string) $data['clientId'], 'clientSecret' => (string) $data['clientSecret']];
    }

    /**
     * The authorization code flow: authorize as the mailbox user, exchange the code.
     *
     * @param  array{clientId: string, clientSecret: string}  $client
     * @return array<string, mixed> the token response
     */
    private function authorizationCodeFlow(array $client): array
    {
        $authorize = new OAuth2Request(['response_type' => 'code', 'client_id' => $client['clientId'], 'redirect_uri' => self::REDIRECT, 'state' => 'xyz'], [], [], [], [], ['REQUEST_METHOD' => 'GET']);
        $response = $this->server->handleAuthorizeRequest($authorize, new OAuth2Response, true, 'user@example.org');
        $this->assertInstanceOf(OAuth2Response::class, $response);
        $this->assertSame(302, $response->getStatusCode(), (string) json_encode($response->getParameters()));
        parse_str((string) parse_url((string) $response->getHttpHeader('Location'), PHP_URL_QUERY), $query);
        $this->assertIsString($query['code'] ?? null);

        $token = $this->tokenResponse([
            'grant_type' => 'authorization_code',
            'code' => $query['code'],
            'redirect_uri' => self::REDIRECT,
            'client_id' => $client['clientId'],
            'client_secret' => $client['clientSecret'],
        ]);
        $this->assertSame(200, $token->getStatusCode(), (string) json_encode($token->getParameters()));

        return $token->getParameters();
    }

    /**
     * The token endpoint's answer to a form request.
     *
     * @param  array<string, mixed>  $body
     */
    private function tokenResponse(array $body): OAuth2Response
    {
        $response = $this->server->handleTokenRequest(new OAuth2Request([], $body, [], [], [], ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded']));
        $this->assertInstanceOf(OAuth2Response::class, $response);

        return $response;
    }

    private function accessTokenVerifies(string $token): bool
    {
        $request = new OAuth2Request([], [], [], [], [], ['REQUEST_METHOD' => 'GET'], null, ['AUTHORIZATION' => 'Bearer '.$token]);

        return $this->server->verifyResourceRequest($request, new OAuth2Response, 'profile');
    }

    public function test_a_created_client_completes_the_authorization_code_flow(): void
    {
        $tokens = $this->authorizationCodeFlow($this->createClient());

        $this->assertTrue($this->accessTokenVerifies((string) $tokens['access_token']));
        $this->assertSame('profile', $tokens['scope']);
    }

    public function test_after_a_secret_regeneration_the_old_secret_is_refused_and_tokens_keep_working(): void
    {
        $client = $this->createClient();
        $tokens = $this->authorizationCodeFlow($client);

        $this->postEmpty('/api/v2/oauth2-clients/'.$client['id'].'/secret-regeneration', $this->csrf())->assertOk();

        $refresh = $this->tokenResponse([
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
            'client_id' => $client['clientId'],
            'client_secret' => $client['clientSecret'],
        ]);
        $this->assertSame(400, $refresh->getStatusCode());
        $this->assertSame('invalid_client', $refresh->getParameter('error'));
        $this->assertTrue($this->accessTokenVerifies((string) $tokens['access_token']));
    }

    public function test_after_a_revocation_and_a_deletion_old_access_tokens_are_refused(): void
    {
        $revoked = $this->createClient();
        $revokedTokens = $this->authorizationCodeFlow($revoked);
        $deleted = $this->createClient();
        $deletedTokens = $this->authorizationCodeFlow($deleted);

        $this->postEmpty('/api/v2/oauth2-clients/'.$revoked['id'].'/token-revocation', $this->csrf())->assertOk();
        $this->deleteJson('/api/v2/oauth2-clients/'.$deleted['id'], [], $this->csrf())->assertNoContent();

        $this->assertFalse($this->accessTokenVerifies((string) $revokedTokens['access_token']));
        $this->assertFalse($this->accessTokenVerifies((string) $deletedTokens['access_token']));
    }
}
