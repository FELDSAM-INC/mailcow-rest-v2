<?php

namespace Tests\Feature\Oauth2;

use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityProvider\Oauth2TestHelpers;
use Tests\TestCase;

/**
 * T077 (FR-047, FR-048, US4 AS-4, AS-5): revocation removes the client's tokens and codes;
 * deletion removes the client with them.
 */
class RevokeAndDeleteOauth2ClientTest extends TestCase
{
    use Oauth2TestHelpers;

    public function test_revocation_removes_tokens_and_codes_of_the_client_only(): void
    {
        $client = $this->clientWithTokens();
        $other = $this->clientWithTokens('https://other.example.org/cb');
        $this->asAdmin();

        $this->postEmpty('/api/v2/oauth2-clients/'.$client['id'].'/token-revocation', $this->csrf())->assertOk()
            ->assertJsonPath('data.activeAccessTokens', 0)
            ->assertJsonPath('data.activeRefreshTokens', 0);

        $this->assertSame(['accessTokens' => 0, 'refreshTokens' => 0, 'authorizationCodes' => 0], $this->tokenRows($client['clientId']));
        $this->assertSame(['accessTokens' => 2, 'refreshTokens' => 2, 'authorizationCodes' => 1], $this->tokenRows($other['clientId']));
        $audit = $this->clientAudit('oauth2ClientTokenRevocation');
        $this->assertSame(['removed' => ['accessTokens' => 2, 'refreshTokens' => 2, 'authorizationCodes' => 1]], $audit[0]->details);
    }

    public function test_revoking_a_client_without_tokens_changes_nothing(): void
    {
        $id = $this->mailcow()->oauthClient('https://idle.example.org/cb');
        $this->asAdmin();

        $this->postEmpty('/api/v2/oauth2-clients/'.$id.'/token-revocation', $this->csrf())->assertOk();

        $this->assertSame(['removed' => ['accessTokens' => 0, 'refreshTokens' => 0, 'authorizationCodes' => 0]], $this->clientAudit('oauth2ClientTokenRevocation')[0]->details);
    }

    public function test_deletion_removes_the_client_and_its_tokens(): void
    {
        $client = $this->clientWithTokens();
        $this->asAdmin();

        $this->deleteJson('/api/v2/oauth2-clients/'.$client['id'], [], $this->csrf())->assertNoContent();

        $this->assertFalse(DB::table('oauth_clients')->where('id', $client['id'])->exists());
        $this->assertSame(['accessTokens' => 0, 'refreshTokens' => 0, 'authorizationCodes' => 0], $this->tokenRows($client['clientId']));
        $audit = $this->clientAudit('oauth2ClientDeletion');
        $this->assertSame(['oauth2Client', (string) $client['id'], $client['clientId']], [$audit[0]->target_type, $audit[0]->target_id, $audit[0]->target_name]);
        $this->assertSame(['removed' => ['accessTokens' => 2, 'refreshTokens' => 2, 'authorizationCodes' => 1]], $audit[0]->details);
    }

    public function test_unknown_clients_and_stale_versions(): void
    {
        $client = $this->clientWithTokens();
        $this->asAdmin();

        $this->postEmpty('/api/v2/oauth2-clients/999999/token-revocation', $this->csrf())->assertNotFound();
        $this->deleteJson('/api/v2/oauth2-clients/999999', [], $this->csrf())->assertNotFound();
        $this->postEmpty('/api/v2/oauth2-clients/'.$client['id'].'/token-revocation', $this->csrf() + ['If-Match' => '"stale"'])->assertStatus(412);
        $this->deleteJson('/api/v2/oauth2-clients/'.$client['id'], [], $this->csrf() + ['If-Match' => '"stale"'])->assertStatus(412);

        $this->assertSame(['accessTokens' => 2, 'refreshTokens' => 2, 'authorizationCodes' => 1], $this->tokenRows($client['clientId']));
    }
}
