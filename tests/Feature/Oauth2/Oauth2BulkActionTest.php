<?php

namespace Tests\Feature\Oauth2;

use App\Auth\Principal;
use App\Services\Oauth2\Oauth2ClientService;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\IdentityProvider\Oauth2TestHelpers;
use Tests\TestCase;

/**
 * T078 (FR-060, FR-061, US4 AS-8): bulk deletion and bulk token revocation, one result and one
 * audit record per distinct client.
 */
class Oauth2BulkActionTest extends TestCase
{
    use Oauth2TestHelpers;

    /**
     * @param  list<array<string, string>>  $targets
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function bulk(string $action, array $targets, array $headers = []): TestResponse
    {
        return $this->postJson('/api/v2/oauth2-clients/bulk-actions', ['action' => $action, 'targets' => $targets], $headers === [] ? $this->csrf() : $headers);
    }

    public function test_revocation_reports_done_not_found_and_outdated(): void
    {
        $first = $this->clientWithTokens('https://a.example.org/cb');
        $outdated = $this->clientWithTokens('https://b.example.org/cb');
        $this->asAdmin();

        $response = $this->bulk('revokeTokens', [
            ['id' => (string) $first['id']],
            ['id' => '999999'],
            ['id' => (string) $outdated['id'], 'version' => '"stale"'],
            ['id' => (string) $first['id']],
        ])->assertOk();

        $this->assertSame(['done', 'notFound', 'outdated'], array_column((array) $response->json('data.results'), 'outcome'));
        $this->assertSame(['total' => 3, 'done' => 1, 'notFound' => 1, 'refused' => 0, 'outdated' => 1, 'failed' => 0], $response->json('data.summary'));
        $this->assertSame(['accessTokens' => 0, 'refreshTokens' => 0, 'authorizationCodes' => 0], $this->tokenRows($first['clientId']));
        $this->assertSame(['accessTokens' => 2, 'refreshTokens' => 2, 'authorizationCodes' => 1], $this->tokenRows($outdated['clientId']));

        $audit = $this->clientAudit('oauth2ClientTokenRevocation');
        $this->assertCount(3, $audit, 'one record per distinct target');
        $this->assertSame([(string) $response->json('data.bulkRequestId')], array_values(array_unique(array_map(fn ($r) => $r->details['bulkRequestId'] ?? null, $audit))));
        $this->assertEqualsCanonicalizing(['success', 'failure', 'failure'], array_column($audit, 'outcome'));
    }

    public function test_deletion_and_the_current_version(): void
    {
        $first = $this->clientWithTokens('https://a.example.org/cb');
        $second = $this->clientWithTokens('https://b.example.org/cb');
        $this->asAdmin();
        $etag = (string) $this->getJson('/api/v2/oauth2-clients/'.$second['id'])->json('data.etag');

        $response = $this->bulk('delete', [['id' => (string) $first['id']], ['id' => (string) $second['id'], 'version' => trim($etag, '"')]])->assertOk();

        $this->assertSame(['done', 'done'], array_column((array) $response->json('data.results'), 'outcome'));
        $this->assertSame(0, DB::table('oauth_clients')->count());
        $this->assertSame(0, DB::table('oauth_access_tokens')->count());
        $this->assertCount(2, $this->clientAudit('oauth2ClientDeletion'));
    }

    public function test_one_failure_does_not_stop_the_others(): void
    {
        $first = $this->clientWithTokens('https://a.example.org/cb');
        $broken = $this->clientWithTokens('https://b.example.org/cb');
        $this->asAdmin();
        $service = $this->app->make(Oauth2ClientService::class);
        $this->app->instance(Oauth2ClientService::class, new class($service, $broken['id']) extends Oauth2ClientService
        {
            public function __construct(private readonly Oauth2ClientService $inner, private readonly int $brokenId) {}

            public function revokeTokens(int $id, ?string $ifMatch, Principal $actor, ?string $bulkRequestId = null): array
            {
                if ($id === $this->brokenId) {
                    throw new RuntimeException('simulated failure');
                }

                return $this->inner->revokeTokens($id, $ifMatch, $actor, $bulkRequestId);
            }
        });

        $response = $this->bulk('revokeTokens', [['id' => (string) $broken['id']], ['id' => (string) $first['id']]])->assertOk();

        $this->assertSame(['failed', 'done'], array_column((array) $response->json('data.results'), 'outcome'));
        $this->assertSame(0, $this->tokenRows($first['clientId'])['accessTokens']);
    }

    public function test_invalid_bulk_requests(): void
    {
        $this->asAdmin();
        $this->withoutRequestValidation();

        $targets = array_map(fn (int $i): array => ['id' => (string) $i], range(1, 101));
        $this->bulk('delete', $targets)->assertUnprocessable()->assertJsonPath('errors.targets.0.code', 'tooMany');
        $this->bulk('delete', [['id' => 'abc']])->assertUnprocessable();
        $this->bulk('rotate', [['id' => '1']])->assertUnprocessable()->assertJsonPath('errors.action.0.code', 'notInList');
    }

    public function test_keys_and_roles_refused_as_a_whole(): void
    {
        $client = $this->clientWithTokens();
        $this->mailcow()->admin('admin', 'Passw0rd!');

        $this->bulk('delete', [['id' => (string) $client['id']]], $this->keyHeader($this->apiKey('admin', 'readOnly')))
            ->assertForbidden()->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $this->mailcow()->ensureDomain('example.org');
        $this->asDomainAdmin(['example.org']);
        $this->bulk('delete', [['id' => (string) $client['id']]])->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->assertTrue(DB::table('oauth_clients')->where('id', $client['id'])->exists());
        $refusals = $this->clientAudit('oauth2ClientDeletion');
        $this->assertCount(2, $refusals);
        $this->assertNull($refusals[0]->target_type);
        $this->assertSame([(string) $client['id']], $refusals[0]->details['targetIds']);
        $this->assertSame('delete', $refusals[0]->details['bulkAction']);
    }

    /**
     * G42: legacy renews the secrets of the selected clients; each new secret is returned once.
     */
    public function test_renew_secret_returns_each_new_secret_once(): void
    {
        $first = $this->clientWithTokens('https://a.example.org/cb');
        $second = $this->clientWithTokens('https://b.example.org/cb');
        $old = DB::table('oauth_clients')->pluck('client_secret', 'id')->all();
        $this->asAdmin();

        $response = $this->bulk('renewSecret', [['id' => (string) $first['id']], ['id' => (string) $second['id'], 'version' => '"stale"'], ['id' => '999999']])->assertOk();

        $results = (array) $response->json('data.results');
        $this->assertSame(['done', 'outdated', 'notFound'], array_column($results, 'outcome'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}$/', (string) $results[0]['clientSecret']);
        $this->assertSame($results[0]['clientSecret'], DB::table('oauth_clients')->where('id', $first['id'])->value('client_secret'));
        $this->assertNotSame($old[$first['id']], $results[0]['clientSecret']);
        $this->assertArrayNotHasKey('clientSecret', $results[1]);
        $this->assertSame($old[$second['id']], DB::table('oauth_clients')->where('id', $second['id'])->value('client_secret'));
        $this->assertSame(['accessTokens' => 2, 'refreshTokens' => 2, 'authorizationCodes' => 1], $this->tokenRows($first['clientId']), 'tokens are kept');
        $this->assertSame($results[0]['version'], $this->getJson('/api/v2/oauth2-clients/'.$first['id'])->json('data.etag'));

        $audit = $this->clientAudit('oauth2ClientSecretRegeneration');
        $this->assertCount(3, $audit);
        $this->assertSame([(string) $response->json('data.bulkRequestId')], array_values(array_unique(array_map(fn ($r) => $r->details['bulkRequestId'] ?? null, $audit))));
        $this->assertStringNotContainsString((string) $results[0]['clientSecret'], (string) json_encode($this->allAudit()));
    }
}
