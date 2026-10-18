<?php

namespace Tests\Feature\Oauth2;

use App\Services\Oauth2\Oauth2ClientService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IdentityProvider\Oauth2TestHelpers;
use Tests\TestCase;

/**
 * T074 (FR-043, FR-044, US4 AS-1, AS-2, AS-6): creating OAuth2 clients as legacy oauth2('add')
 * does, with the secret shown once.
 */
class CreateOauth2ClientTest extends TestCase
{
    use Oauth2TestHelpers;

    public function test_a_client_is_created_with_generated_credentials(): void
    {
        $this->asAdmin();

        $response = $this->postJson('/api/v2/oauth2-clients', ['redirectUris' => ['https://wiki.example.org/oauth/callback', 'https://wiki.example.org/oauth/callback', 'com.example.app:/cb']], $this->csrf())
            ->assertCreated();

        $id = (int) $response->json('data.id');
        $response->assertHeader('Location', '/api/v2/oauth2-clients/'.$id);
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', (string) $response->json('data.clientId'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}$/', (string) $secret = $response->json('data.clientSecret'));
        $response->assertJsonPath('data.redirectUris', ['https://wiki.example.org/oauth/callback', 'com.example.app:/cb'])
            ->assertJsonPath('data.scope', 'profile')
            ->assertJsonPath('data.activeAccessTokens', 0);

        $row = DB::table('oauth_clients')->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertSame([$response->json('data.clientId'), $secret, 'https://wiki.example.org/oauth/callback com.example.app:/cb', 'profile', null, null], [$row->client_id, $row->client_secret, $row->redirect_uri, $row->scope, $row->grant_types, $row->user_id]);

        $read = $this->getJson('/api/v2/oauth2-clients/'.$id)->assertOk();
        $this->assertArrayNotHasKey('clientSecret', (array) $read->json('data'));
        $this->assertSame($response->json('data.etag'), $read->json('data.etag'));

        $audit = $this->clientAudit('oauth2ClientCreation');
        $this->assertCount(1, $audit);
        $this->assertSame(['oauth2Client', (string) $id, $row->client_id], [$audit[0]->target_type, $audit[0]->target_id, $audit[0]->target_name]);
        $this->assertSame(['redirectUris' => ['https://wiki.example.org/oauth/callback', 'com.example.app:/cb']], $audit[0]->details);
        $this->assertStringNotContainsString((string) $secret, (string) json_encode($this->allAudit()));
    }

    /**
     * @return iterable<string, array{list<string>, string, string, array<string, mixed>}>
     */
    public static function invalidUris(): iterable
    {
        yield 'relative' => [['relative/cb'], 'redirectUris.0', 'invalidFormat', ['format' => 'redirectUri']];
        yield 'fragment' => [['https://ok.example.org/cb', 'https://x.example.org/cb#f'], 'redirectUris.1', 'invalidFormat', ['format' => 'redirectUri']];
        yield 'whitespace' => [['https://x.example.org/a b'], 'redirectUris.0', 'invalidFormat', ['format' => 'redirectUri']];
        yield 'joined too long' => [[('https://a.example.org/'.str_repeat('a', 978)), ('https://b.example.org/'.str_repeat('b', 978))], 'redirectUris', 'tooLong', ['max' => 2000]];
        yield 'empty list' => [[], 'redirectUris', 'required', []];
    }

    /**
     * @param  list<string>  $uris
     * @param  array<string, mixed>  $params
     */
    #[DataProvider('invalidUris')]
    public function test_invalid_redirect_uris_are_refused(array $uris, string $field, string $code, array $params): void
    {
        $this->asAdmin();
        $this->withoutRequestValidation();

        $response = $this->postJson('/api/v2/oauth2-clients', ['redirectUris' => $uris], $this->csrf())->assertUnprocessable();

        $error = ((array) $response->json('errors'))[$field][0] ?? null;
        $this->assertIsArray($error, (string) $response->getContent());
        $this->assertSame([$code, $params], [$error['code'], $error['params']]);
        $this->assertSame(0, DB::table('oauth_clients')->count());
        $this->assertSame('validationFailed', $this->clientAudit('oauth2ClientCreation')[0]->failure_reason);
    }

    public function test_a_client_id_collision_generates_a_new_id(): void
    {
        $this->mailcow()->oauthClient('https://taken.example.org/cb', '000000000001');
        $sequence = ['000000000001', '000000000002'];
        $this->app->bind(Oauth2ClientService::CLIENT_ID_GENERATOR, fn () => function () use (&$sequence): string {
            return (string) array_shift($sequence);
        });
        $this->asAdmin();

        $this->postJson('/api/v2/oauth2-clients', ['redirectUris' => ['https://wiki.example.org/cb']], $this->csrf())
            ->assertCreated()->assertJsonPath('data.clientId', '000000000002');
    }

    public function test_a_read_only_key_may_not_create(): void
    {
        $this->mailcow()->admin('admin', 'Passw0rd!');

        $this->postJson('/api/v2/oauth2-clients', ['redirectUris' => ['https://wiki.example.org/cb']], $this->keyHeader($this->apiKey('admin', 'readOnly')))
            ->assertForbidden()->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
        $this->assertSame(0, DB::table('oauth_clients')->count());
    }
}
