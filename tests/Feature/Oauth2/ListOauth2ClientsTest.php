<?php

namespace Tests\Feature\Oauth2;

use Tests\Support\IdentityProvider\Oauth2TestHelpers;
use Tests\TestCase;

/**
 * T073 (FR-041, FR-042, US4 AS-6, AS-7): listing and reading OAuth2 clients, without secrets.
 */
class ListOauth2ClientsTest extends TestCase
{
    use Oauth2TestHelpers;

    public function test_the_list_pages_sorts_and_never_contains_a_secret(): void
    {
        $ids = [];

        for ($i = 1; $i <= 30; $i++) {
            $ids[] = $this->mailcow()->oauthClient("https://app{$i}.example.org/cb", sprintf('%012x', 1000 - $i), str_repeat('a', 24));
        }

        $this->asAdmin();

        $page = $this->getJson('/api/v2/oauth2-clients')->assertOk();
        $this->assertCount(25, (array) $page->json('data'));
        $this->assertSame(['total' => 30, 'limit' => 25, 'offset' => 0], $page->json('meta'));
        $this->assertSame(array_slice($ids, 0, 25), array_column((array) $page->json('data'), 'id'));
        $this->assertStringNotContainsString(str_repeat('a', 24), (string) $page->getContent());
        $this->assertStringNotContainsString('clientSecret', (string) $page->getContent());

        $this->getJson('/api/v2/oauth2-clients?limit=1000')->assertOk();
        $this->getJson('/api/v2/oauth2-clients?sort=clientId&limit=1')->assertOk()->assertJsonPath('data.0.clientId', sprintf('%012x', 970));
        $this->getJson('/api/v2/oauth2-clients?sort=redirectUri&order=desc&limit=1')->assertOk()->assertJsonPath('data.0.redirectUris', ['https://app9.example.org/cb']);

        foreach ((array) $page->json('data') as $entry) {
            $this->assertSame($this->getJson('/api/v2/oauth2-clients/'.$entry['id'])->headers->get('ETag'), $entry['etag']);
        }
    }

    public function test_an_invalid_limit_is_refused(): void
    {
        $this->asAdmin();
        $this->withoutRequestValidation();

        $this->getJson('/api/v2/oauth2-clients?limit=1001')->assertUnprocessable()->assertJsonPath('errors.limit.0.code', 'outOfRange');
    }

    public function test_search_matches_the_client_id_and_the_redirect_uris(): void
    {
        for ($i = 1; $i <= 37; $i++) {
            $this->mailcow()->oauthClient("https://app{$i}.example.org/cb");
        }

        $this->mailcow()->oauthClient('https://WIKI.example.org/cb');
        $this->mailcow()->oauthClient('https://chat.example.org/cb https://docs.example.org/wiki/cb');
        $this->mailcow()->oauthClient('https://other.example.org/cb', 'abcwiki00000');
        $this->asAdmin();

        $response = $this->getJson('/api/v2/oauth2-clients?q=wiki')->assertOk();

        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(['https://chat.example.org/cb', 'https://docs.example.org/wiki/cb'], $response->json('data.1.redirectUris'));
    }

    public function test_token_counts_and_the_active_token_filter(): void
    {
        $withTokens = $this->clientWithTokens();
        $without = $this->mailcow()->oauthClient('https://idle.example.org/cb');
        $this->asAdmin();

        $this->getJson('/api/v2/oauth2-clients/'.$withTokens['id'])->assertOk()
            ->assertJsonPath('data.clientId', $withTokens['clientId'])
            ->assertJsonPath('data.scope', 'profile')
            ->assertJsonPath('data.activeAccessTokens', 1)
            ->assertJsonPath('data.activeRefreshTokens', 1);

        $this->assertSame([$withTokens['id']], array_column((array) $this->getJson('/api/v2/oauth2-clients?hasActiveTokens=true')->json('data'), 'id'));
        $this->assertSame([$without], array_column((array) $this->getJson('/api/v2/oauth2-clients?hasActiveTokens=false')->json('data'), 'id'));
    }

    public function test_an_unknown_client_is_not_found(): void
    {
        $this->asAdmin();

        $this->getJson('/api/v2/oauth2-clients/999999')->assertNotFound();
    }

    public function test_a_legacy_stored_empty_redirect_uri_reads_as_an_empty_list(): void
    {
        $id = $this->mailcow()->oauthClient('');
        $this->asAdmin();

        $this->getJson('/api/v2/oauth2-clients/'.$id)->assertOk()->assertJsonPath('data.redirectUris', []);
    }

    public function test_read_only_keys_list_and_domain_administrators_may_not(): void
    {
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $this->mailcow()->oauthClient('https://wiki.example.org/cb');

        $this->getJson('/api/v2/oauth2-clients', $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertOk()->assertJsonPath('meta.total', 1);

        $this->mailcow()->ensureDomain('example.org');
        $this->asDomainAdmin(['example.org']);
        $this->getJson('/api/v2/oauth2-clients')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
    }
}
