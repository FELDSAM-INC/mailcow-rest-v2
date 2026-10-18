<?php

namespace Tests\Feature\Oauth2;

use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityProvider\Oauth2TestHelpers;
use Tests\TestCase;

/**
 * T075 (FR-045): replacing a client's redirect URIs.
 */
class UpdateOauth2ClientTest extends TestCase
{
    use Oauth2TestHelpers;

    public function test_the_redirect_uris_are_replaced(): void
    {
        $client = $this->clientWithTokens();
        $this->asAdmin();

        $response = $this->patchMerge('/api/v2/oauth2-clients/'.$client['id'], ['redirectUris' => ['https://wiki2.example.org/cb', 'https://wiki3.example.org/cb']], $this->csrf())->assertOk();

        $response->assertJsonPath('data.redirectUris', ['https://wiki2.example.org/cb', 'https://wiki3.example.org/cb']);
        $this->assertSame('https://wiki2.example.org/cb https://wiki3.example.org/cb', DB::table('oauth_clients')->where('id', $client['id'])->value('redirect_uri'));
        $this->assertSame(['accessTokens' => 2, 'refreshTokens' => 2, 'authorizationCodes' => 1], $this->tokenRows($client['clientId']), 'tokens are kept');

        $audit = $this->clientAudit('oauth2ClientUpdate');
        $this->assertSame(['redirectUris' => ['old' => ['https://wiki.example.org/oauth/callback'], 'new' => ['https://wiki2.example.org/cb', 'https://wiki3.example.org/cb']]], $audit[0]->details['changes']);
        $this->assertNotSame($this->getJson('/api/v2/oauth2-clients/'.$client['id'])->json('data.etag'), null);
    }

    public function test_the_client_id_and_scope_are_accepted_only_unchanged(): void
    {
        $client = $this->clientWithTokens();
        $this->asAdmin();

        $this->patchMerge('/api/v2/oauth2-clients/'.$client['id'], ['clientId' => $client['clientId'], 'scope' => 'profile', 'redirectUris' => ['https://x.example.org/cb']], $this->csrf())->assertOk();

        $response = $this->patchMerge('/api/v2/oauth2-clients/'.$client['id'], ['clientId' => 'ffffffffffff', 'scope' => 'openid'], $this->csrf())->assertUnprocessable();
        $response->assertJsonPath('errors.clientId.0.code', 'immutable')->assertJsonPath('errors.scope.0.code', 'immutable');
        $this->assertSame($client['clientId'], DB::table('oauth_clients')->where('id', $client['id'])->value('client_id'));
    }

    public function test_a_legacy_empty_redirect_uri_can_be_corrected(): void
    {
        $id = $this->mailcow()->oauthClient('');
        $this->asAdmin();

        $this->patchMerge('/api/v2/oauth2-clients/'.$id, ['redirectUris' => ['https://fixed.example.org/cb']], $this->csrf())->assertOk()
            ->assertJsonPath('data.redirectUris', ['https://fixed.example.org/cb']);
    }

    public function test_an_unknown_client_and_a_stale_version(): void
    {
        $client = $this->clientWithTokens();
        $this->asAdmin();
        $etag = (string) $this->getJson('/api/v2/oauth2-clients/'.$client['id'])->json('data.etag');

        $this->patchMerge('/api/v2/oauth2-clients/999999', ['redirectUris' => ['https://x.example.org/cb']], $this->csrf())->assertNotFound();
        $this->patchMerge('/api/v2/oauth2-clients/'.$client['id'], ['redirectUris' => ['https://a.example.org/cb']], $this->csrf() + ['If-Match' => $etag])->assertOk();
        $this->patchMerge('/api/v2/oauth2-clients/'.$client['id'], ['redirectUris' => ['https://b.example.org/cb']], $this->csrf() + ['If-Match' => $etag])->assertStatus(412);

        $this->assertSame('https://a.example.org/cb', DB::table('oauth_clients')->where('id', $client['id'])->value('redirect_uri'));
        $reasons = array_column(array_values(array_filter($this->clientAudit('oauth2ClientUpdate'), fn ($r) => $r->outcome === 'failure')), 'failure_reason');
        $this->assertSame(['notFound', 'versionOutdated'], $reasons);
    }
}
