<?php

namespace Tests\Feature\Oauth2;

use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityProvider\Oauth2TestHelpers;
use Tests\TestCase;

/**
 * T076 (FR-046, US4 AS-3): a new secret, returned once; tokens and codes are kept.
 */
class RegenerateOauth2ClientSecretTest extends TestCase
{
    use Oauth2TestHelpers;

    public function test_the_secret_is_replaced_and_tokens_are_kept(): void
    {
        $client = $this->clientWithTokens();
        $old = (string) DB::table('oauth_clients')->where('id', $client['id'])->value('client_secret');
        $this->asAdmin();
        $etag = (string) $this->getJson('/api/v2/oauth2-clients/'.$client['id'])->json('data.etag');

        $response = $this->postEmpty('/api/v2/oauth2-clients/'.$client['id'].'/secret-regeneration', $this->csrf() + ['If-Match' => $etag])->assertOk();

        $new = (string) $response->json('data.clientSecret');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $new);
        $this->assertNotSame($old, $new);
        $this->assertSame($new, DB::table('oauth_clients')->where('id', $client['id'])->value('client_secret'));
        $this->assertNotSame($etag, $response->json('data.etag'));
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));
        $this->assertSame(['accessTokens' => 2, 'refreshTokens' => 2, 'authorizationCodes' => 1], $this->tokenRows($client['clientId']));

        $audit = $this->clientAudit('oauth2ClientSecretRegeneration');
        $this->assertCount(1, $audit);
        $this->assertSame([], $audit[0]->details);
        $all = (string) json_encode($this->allAudit());
        $this->assertStringNotContainsString($old, $all);
        $this->assertStringNotContainsString($new, $all);
    }

    public function test_a_stale_version_and_an_unknown_client(): void
    {
        $client = $this->clientWithTokens();
        $this->asAdmin();

        $this->postEmpty('/api/v2/oauth2-clients/'.$client['id'].'/secret-regeneration', $this->csrf() + ['If-Match' => '"stale"'])->assertStatus(412);
        $this->postEmpty('/api/v2/oauth2-clients/999999/secret-regeneration', $this->csrf())->assertNotFound();

        $this->assertCount(2, $this->clientAudit('oauth2ClientSecretRegeneration'));
    }
}
