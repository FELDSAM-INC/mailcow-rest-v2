<?php

namespace Tests\Feature\IdentityProvider;

use App\Clients\Oidc\OidcFailure;
use App\Services\IdentityProvider\IdentityProviderRepository;
use App\Services\IdentityProvider\KeycloakServiceTokenCache;
use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityProvider\FakeOidcProvider;
use Tests\Support\IdentityProvider\IdentityProviderTestHelpers;
use Tests\TestCase;

/**
 * T065 (research R3, FR-056): the Keycloak service token is shared with legacy through the
 * `access_token` row of `identity_provider`.
 */
class KeycloakServiceTokenCacheTest extends TestCase
{
    use IdentityProviderTestHelpers;

    private function token(): string
    {
        $config = $this->app->make(IdentityProviderRepository::class)->current();
        $this->assertNotNull($config);

        return $this->app->make(KeycloakServiceTokenCache::class)->token($config);
    }

    public function test_an_active_cached_token_is_reused(): void
    {
        $this->mailcow()->keycloakConfig(['access_token' => 'cached-service-token']);
        $idp = FakeOidcProvider::install()->introspection(true);

        $this->assertSame('cached-service-token', $this->token());

        $sent = $idp->last('introspect');
        $this->assertSame('https://sso.example.org/realms/example/protocol/openid-connect/token/introspect', $sent['url']);
        $this->assertSame(['token' => 'cached-service-token', 'client_id' => 'mailcow', 'client_secret' => 'kc-secret'], $sent['form']);
        $idp->assertSentCount('token:client_credentials', 0);
    }

    public function test_an_inactive_or_empty_token_is_replaced_and_stored(): void
    {
        $this->mailcow()->keycloakConfig(['access_token' => 'expired-service-token', 'ignore_ssl_error' => '1']);
        $idp = FakeOidcProvider::install()->introspection(false)->tokenIssued('client_credentials', 'new-service-token', null);
        $before = $this->getEtag();

        $this->assertSame('new-service-token', $this->token());

        $this->assertSame('new-service-token', DB::table('identity_provider')->where('key', 'access_token')->value('value'));
        $this->assertSame($before, $this->getEtag(), 'the token write does not change the version tag');
        foreach ($idp->sent() as $request) {
            $this->assertFalse($request['options']['verify']);
            $this->assertSame(7, $request['options']['timeout']);
        }

        $this->mailcow()->identityProviderRows(['access_token' => '']);
        FakeOidcProvider::install()->tokenIssued('client_credentials', 'third-service-token', null);
        $this->assertSame('third-service-token', $this->token());
    }

    public function test_a_refused_request_is_a_failure(): void
    {
        $this->mailcow()->keycloakConfig();
        FakeOidcProvider::install()->tokenRefused('client_credentials', 'unauthorized_client', 400);

        $this->expectException(OidcFailure::class);
        $this->token();
    }

    private function getEtag(): string
    {
        return $this->app->make(IdentityProviderRepository::class)->etag();
    }
}
