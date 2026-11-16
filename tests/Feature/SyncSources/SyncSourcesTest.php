<?php

namespace Tests\Feature\SyncSources;

use App\Clients\SyncSources\OAuthHttpClient;
use App\Models\Mailcow\SchemaVersion;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyncJobs\SyncJobTestHelpers;
use Tests\TestCase;

class SyncSourcesTest extends TestCase
{
    use SyncJobTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asAdmin();
        $this->syncMailbox('alice@example.org');
        $this->syncMailbox('foreign@other.org');
    }

    private function modern(): void
    {
        if (! SchemaVersion::hasNovember2026Features()) {
            $this->markTestSkipped('Requires the 2026-11 schema.');
        }
    }

    /** @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function create(array $extra = []): array
    {
        return $this->postJson('/api/v2/sync-sources', $extra + ['name' => 'Old provider', 'remoteHost' => 'imap.example.net', 'remotePort' => 993, 'encryption' => 'ssl'], $this->csrf())
            ->assertCreated()->json('data');
    }

    public function test_old_schema_sources_are_gated(): void
    {
        if (! SchemaVersion::hasNovember2026Features()) {
            $this->getJson('/api/v2/sync-sources')->assertConflict();
        } else {
            $this->getJson('/api/v2/sync-sources')->assertOk();
        }
    }

    public function test_sources_are_scoped_and_only_creators_or_managers_can_edit(): void
    {
        $this->modern();
        $all = $this->create(['name' => 'All', 'scope' => 'all']);
        $domain = $this->create(['name' => 'Domain', 'scope' => 'domain', 'domains' => ['example.org']]);
        $foreign = $this->create(['name' => 'Private']);
        $this->asSyncUser();
        $own = $this->create(['name' => 'Own']);
        $this->getJson('/api/v2/sync-sources?sort=name')->assertOk()->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.name', 'All')->assertJsonPath('data.0.editable', false);
        $this->getJson('/api/v2/sync-sources/'.$foreign['id'])->assertNotFound();
        $this->patchMerge('/api/v2/sync-sources/'.$all['id'], ['name' => 'Hijack'], $this->csrf())->assertForbidden();
        $this->patchMerge('/api/v2/sync-sources/'.$own['id'], ['description' => 'Mine'], $this->csrf())->assertOk();
        $this->asDomainAdmin(['example.org']);
        $this->getJson('/api/v2/sync-sources/'.$domain['id'])->assertOk()->assertJsonPath('data.editable', false);
        $this->patchMerge('/api/v2/sync-sources/'.$own['id'], ['description' => 'Managed'], $this->csrf())->assertOk();
        $this->postJson('/api/v2/sync-sources', ['name' => 'Invalid', 'remoteHost' => 'imap.example.net', 'remotePort' => 993, 'scope' => 'all'], $this->csrf())->assertForbidden();
    }

    public function test_versions_cover_targets_and_secret_fields_but_not_cron_tokens(): void
    {
        $this->modern();
        $s = $this->create();
        DB::table('imapsync_source')->where('id', $s['id'])->update(['oauth_access_token' => 'ROTATED_SECRET', 'oauth_token_expires' => time() + 1000]);
        $this->getJson('/api/v2/sync-sources/'.$s['id'])->assertJsonPath('data.etag', $s['etag'])->assertDontSee('ROTATED_SECRET');
        DB::table('imapsync_source_user')->insert(['source_id' => $s['id'], 'username' => 'alice@example.org']);
        $this->patchMerge('/api/v2/sync-sources/'.$s['id'], ['active' => false], ['If-Match' => $s['etag']] + $this->csrf())->assertStatus(412);
        $this->assertDatabaseHas('imapsync_source', ['id' => $s['id'], 'active' => 1]);
    }

    public function test_shared_connection_cannot_redirect_foreign_job_credentials_or_disable_tls_verification(): void
    {
        $this->modern();
        $this->asDomainAdmin(['example.org']);
        $s = $this->create();
        DB::table('imapsync')->insert(['source_id' => $s['id'], 'user2' => 'foreign@other.org', 'user1' => 'remote', 'password1' => 'FOREIGN_SECRET']);
        foreach ([['remoteHost' => 'attacker.example'], ['verifyCertificate' => false]] as $patch) {
            $this->patchMerge('/api/v2/sync-sources/'.$s['id'], $patch, $this->csrf())->assertConflict();
        }
        $this->patchMerge('/api/v2/sync-sources/'.$s['id'], ['description' => 'Safe metadata'], $this->csrf())->assertOk();
        $this->deleteJson('/api/v2/sync-sources/'.$s['id'], [], $this->csrf())->assertConflict();
        $this->assertDatabaseHas('imapsync_source', ['id' => $s['id'], 'host1' => 'imap.example.net', 'verify_cert' => 1]);
    }

    public function test_oauth_secrets_are_write_only_and_need_resupplying_for_endpoint_change(): void
    {
        $this->modern();
        $s = $this->create(['authMechanism' => 'xoauth2', 'oauth' => ['flow' => 'authorization_code',
            'tokenEndpoint' => 'https://oauth.example/token', 'authorizationEndpoint' => 'https://oauth.example/authorize',
            'clientId' => 'client', 'clientSecret' => 'CLIENT_SECRET', 'scope' => 'openid email']]);
        $this->assertSame(true, $s['oauth']['clientSecretSet']);
        $this->assertStringNotContainsString('CLIENT_SECRET', json_encode($s));
        $this->patchMerge('/api/v2/sync-sources/'.$s['id'], ['oauth' => ['tokenEndpoint' => 'https://other.example/token']], $this->csrf())
            ->assertUnprocessable();
        $this->patchMerge('/api/v2/sync-sources/'.$s['id'], ['oauth' => ['clientSecret' => '']], $this->csrf())->assertOk();
        $this->assertDatabaseHas('imapsync_source', ['id' => $s['id'], 'oauth_client_secret' => 'CLIENT_SECRET']);
        $this->assertStringNotContainsString('CLIENT_SECRET', DB::table('restv2_audit_events')->pluck('details')->implode(''));
    }

    public function test_invalid_body_validation_cannot_be_bypassed_by_query_parameters(): void
    {
        $this->modern();
        $s = $this->create();
        $this->withoutRequestValidation();
        $this->patchMerge('/api/v2/sync-sources/'.$s['id'].'?verifyCertificate=true', ['verifyCertificate' => 'yes'], $this->csrf())->assertUnprocessable();
        $this->patchMerge('/api/v2/sync-sources/'.$s['id'], ['oauth' => ['extraParameters' => ['a' => ['nested']]]], $this->csrf())->assertUnprocessable();
        $this->postJson('/api/v2/sync-sources', ['name' => 'No CSRF'])->assertForbidden();
        $this->asSyncUser('alice@example.org', false);
        DB::table('user_acl')->where('username', 'alice@example.org')->update(['syncjobs' => 0]);
        $this->getJson('/api/v2/sync-sources')->assertForbidden();
    }

    public function test_oauth_connection_is_bound_to_session_owner_source_version_and_is_single_use(): void
    {
        $this->modern();
        $fake = new class extends OAuthHttpClient
        {
            public int $requests = 0;

            public ?string $verifier = null;

            public function request(string $url, bool $publicOnly, ?array $post = null, ?string $bearer = null): array
            {
                $this->requests++;
                $this->verifier = $post['code_verifier'] ?? null;

                return ['access_token' => 'ACCESS_SECRET', 'refresh_token' => 'REFRESH_SECRET', 'expires_in' => 3600,
                    'id_token' => 'header.'.rtrim(strtr(base64_encode('{"email":"remote@example.net"}'), '+/', '-_'), '=').'.signature'];
            }
        };
        $this->app->instance(OAuthHttpClient::class, $fake);
        $s = $this->create(['authMechanism' => 'xoauth2', 'scope' => 'all', 'oauth' => ['flow' => 'authorization_code',
            'tokenEndpoint' => 'https://oauth.example/token', 'authorizationEndpoint' => 'https://oauth.example/authorize',
            'clientId' => 'client', 'clientSecret' => 'CLIENT_SECRET', 'scope' => 'openid email']]);
        $this->asSyncUser();
        $start = $this->postJson('/api/v2/sync-sources/'.$s['id'].'/oauth/start', ['mailbox' => 'alice@example.org'], $this->csrf())->assertOk()->json('data');
        parse_str(parse_url($start['authorizationUrl'], PHP_URL_QUERY), $params);
        $this->assertSame('S256', $params['code_challenge_method']);
        $this->postJson('/api/v2/sync-sources/oauth/complete', ['state' => $params['state'], 'code' => 'code'], $this->csrf())
            ->assertOk()->assertJsonPath('data.remoteUser', 'remote@example.net')->assertDontSee('ACCESS_SECRET');
        $this->assertSame($params['code_challenge'], rtrim(strtr(base64_encode(hash('sha256', $fake->verifier, true)), '+/', '-_'), '='));
        $this->postJson('/api/v2/sync-sources/oauth/complete', ['state' => $params['state'], 'code' => 'code'], $this->csrf())->assertConflict();
        $this->assertSame(1, $fake->requests);
        $this->assertDatabaseHas('imapsync_source_oauth_token', ['source_id' => $s['id'], 'owner' => 'alice@example.org', 'username' => 'remote@example.net', 'access_token' => 'ACCESS_SECRET']);
        $this->getJson('/api/v2/sync-sources/'.$s['id'].'/connections?mailbox=foreign@other.org')->assertNotFound();
        $this->getJson('/api/v2/sync-sources/'.$s['id'].'/connections?mailbox=alice@example.org')->assertOk()->assertDontSee('ACCESS_SECRET')->assertDontSee('REFRESH_SECRET');
        $this->deleteJson('/api/v2/sync-sources/'.$s['id'].'/connections', ['mailbox' => 'alice@example.org', 'remoteUser' => 'remote@example.net'], $this->csrf())->assertNoContent();
        $this->assertDatabaseCount('imapsync_source_oauth_token', 0);
    }

    public function test_source_edits_cannot_redirect_any_retained_job_password(): void
    {
        $this->modern();
        $s = $this->create();
        DB::table('imapsync')->insert(['source_id' => $s['id'], 'user2' => 'alice@example.org', 'user1' => 'remote', 'password1' => 'RETAINED_SECRET']);
        foreach ([['remoteHost' => 'new.example'], ['remotePort' => 1993], ['encryption' => 'plain'], ['verifyCertificate' => false]] as $patch) {
            $this->patchMerge('/api/v2/sync-sources/'.$s['id'], $patch, $this->csrf())->assertConflict()->assertDontSee('RETAINED_SECRET');
        }
        $this->patchMerge('/api/v2/sync-sources/'.$s['id'], ['description' => 'Safe metadata'], $this->csrf())->assertOk();
    }

    public function test_changed_oauth_source_or_expired_state_never_exchanges_a_code(): void
    {
        $this->modern();
        $fake = new class extends OAuthHttpClient
        {
            public function request(string $url, bool $publicOnly, ?array $post = null, ?string $bearer = null): array
            {
                throw new \LogicException('The code must never reach the token endpoint.');
            }
        };
        $this->app->instance(OAuthHttpClient::class, $fake);
        $s = $this->create(['authMechanism' => 'xoauth2', 'scope' => 'all', 'oauth' => ['flow' => 'authorization_code',
            'tokenEndpoint' => 'https://oauth.example/token', 'authorizationEndpoint' => 'https://oauth.example/authorize',
            'clientId' => 'client', 'clientSecret' => 'CLIENT_SECRET', 'scope' => 'openid email']]);
        foreach (['changed', 'expired'] as $case) {
            $start = $this->postJson('/api/v2/sync-sources/'.$s['id'].'/oauth/start', ['mailbox' => 'alice@example.org'], $this->csrf())->assertOk()->json('data');
            parse_str(parse_url($start['authorizationUrl'], PHP_URL_QUERY), $params);
            if ($case === 'changed') {
                DB::table('imapsync_source')->where('id', $s['id'])->update(['oauth_client_id' => 'changed-client']);
            } else {
                $this->travel(11)->minutes();
            }
            $this->postJson('/api/v2/sync-sources/oauth/complete', ['state' => $params['state'], 'code' => 'code'], $this->csrf())->assertConflict();
        }
        $this->assertDatabaseCount('imapsync_source_oauth_token', 0);
    }

    public function test_shared_token_refresh_and_unused_source_deletion_keep_secrets_private(): void
    {
        $this->modern();
        $this->app->instance(OAuthHttpClient::class, new class extends OAuthHttpClient
        {
            public function request(string $url, bool $publicOnly, ?array $post = null, ?string $bearer = null): array
            {
                return ['access_token' => 'SHARED_TOKEN_SECRET', 'expires_in' => 3600];
            }
        });
        $source = $this->create(['authMechanism' => 'xoauth2', 'oauth' => ['flow' => 'client_credentials',
            'tokenEndpoint' => 'https://oauth.example/token', 'clientId' => 'client', 'clientSecret' => 'CLIENT_SECRET', 'scope' => 'mail']]);
        $path = '/api/v2/sync-sources/'.$source['id'];
        $this->postEmpty($path.'/oauth/refresh', ['If-Match' => '"stale"'] + $this->csrf())->assertStatus(412);
        $this->postEmpty($path.'/oauth/refresh', ['If-Match' => $source['etag']] + $this->csrf())->assertOk()
            ->assertJsonPath('data.oauth.tokenSet', true)->assertJsonPath('data.etag', $source['etag'])
            ->assertDontSee('SHARED_TOKEN_SECRET')->assertDontSee('CLIENT_SECRET');
        $this->assertDatabaseHas('imapsync_source', ['id' => $source['id'], 'oauth_access_token' => 'SHARED_TOKEN_SECRET']);
        $this->assertStringNotContainsString('SHARED_TOKEN_SECRET', DB::table('restv2_audit_events')->pluck('details')->implode(''));
        $this->deleteJson($path, [], ['If-Match' => '"stale"'] + $this->csrf())->assertStatus(412);
        $this->deleteJson($path, [], ['If-Match' => $source['etag']] + $this->csrf())->assertNoContent();
        $this->assertDatabaseMissing('imapsync_source', ['id' => $source['id']]);
        $this->getJson('/api/v2/audit-events?targetType=syncSource&limit=100')->assertOk()->assertDontSee('SHARED_TOKEN_SECRET')->assertDontSee('CLIENT_SECRET');
    }
}
