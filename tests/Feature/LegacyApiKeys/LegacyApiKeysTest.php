<?php

namespace Tests\Feature\LegacyApiKeys;

use App\Models\Mailcow\SchemaVersion;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\SyncJobs\SyncJobTestHelpers;
use Tests\TestCase;

class LegacyApiKeysTest extends TestCase
{
    use ApiKeys;
    use SyncJobTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asAdmin();
    }

    private function modern(): void
    {
        if (! SchemaVersion::hasNovember2026Features()) {
            $this->markTestSkipped('Requires the 2026-11 schema.');
        }
    }

    /** @return array<string, mixed> */
    private function create(): array
    {
        return $this->postJson('/api/v2/legacy-api-keys', ['description' => 'Backup', 'accessLevel' => 'readOnly', 'allowedNetworks' => ['192.0.2.1', '2001:db8::/32']], $this->csrf())->assertCreated()->json('data');
    }

    public function test_old_schema_is_gated_before_querying_new_columns(): void
    {
        $this->getJson('/api/v2/legacy-api-keys')->assertStatus(SchemaVersion::hasNovember2026Features() ? 200 : 409);
    }

    public function test_multiple_keys_are_independent_and_secrets_are_only_disclosed_once(): void
    {
        $this->modern();
        $one = $this->create();
        $two = $this->create();
        $this->assertNotSame($one['id'], $two['id']);
        $this->assertNotSame($one['secret'], $two['secret']);
        $this->getJson('/api/v2/legacy-api-keys')->assertOk()->assertJsonPath('meta.total', 2)->assertDontSee($one['secret'])->assertDontSee($two['secret']);
        $this->getJson('/api/v2/legacy-api-keys/'.$one['id'])->assertOk()->assertDontSee($one['secret']);
        $this->assertDatabaseCount('restv2_api_keys', 0);
        $this->assertStringNotContainsString($one['secret'], DB::table('restv2_audit_events')->get()->toJson());
        $rotated = $this->postEmpty('/api/v2/legacy-api-keys/'.$one['id'].'/regenerate', ['If-Match' => $one['etag']] + $this->csrf())->assertOk()->json('data');
        $this->assertNotSame($one['secret'], $rotated['secret']);
        $this->assertDatabaseMissing('api', ['api_key' => $one['secret']]);
        $this->assertDatabaseHas('api', ['api_key' => $two['secret']]);
        $this->deleteJson('/api/v2/legacy-api-keys/'.$one['id'], [], ['If-Match' => $rotated['etag']] + $this->csrf())->assertNoContent();
    }

    public function test_service_keys_cannot_be_changed_deleted_or_regenerated(): void
    {
        $this->modern();
        $id = DB::table('api')->insertGetId(['api_key' => 'SERVICE-SECRET', 'description' => 'API_KEY', 'allow_from' => '192.0.2.0/24', 'access' => 'rw', 'service' => 1]);
        $this->getJson('/api/v2/legacy-api-keys/'.$id)->assertOk()->assertJsonPath('data.service', true)->assertDontSee('SERVICE-SECRET');
        $this->patchMerge('/api/v2/legacy-api-keys/'.$id, ['active' => false], $this->csrf())->assertConflict();
        $this->postEmpty('/api/v2/legacy-api-keys/'.$id.'/regenerate', $this->csrf())->assertConflict();
        $this->deleteJson('/api/v2/legacy-api-keys/'.$id, [], $this->csrf())->assertConflict();
        $this->assertDatabaseHas('api', ['id' => $id, 'api_key' => 'SERVICE-SECRET', 'active' => 1, 'service' => 1]);
    }

    public function test_updates_preserve_the_secret_and_restrict_access_with_a_fresh_version(): void
    {
        $this->modern();
        $key = $this->create();
        $path = '/api/v2/legacy-api-keys/'.$key['id'];
        $updated = $this->patchMerge($path, [
            'description' => 'Paused backup',
            'allowedNetworks' => ['192.0.2.0/24'],
            'active' => false,
        ], ['If-Match' => $key['etag']] + $this->csrf())->assertOk()
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.allowedNetworks', ['192.0.2.0/24'])
            ->assertDontSee($key['secret'])->json('data');
        $this->assertNotSame($key['etag'], $updated['etag']);
        $this->assertDatabaseHas('api', ['id' => $key['id'], 'api_key' => $key['secret'], 'active' => 0]);
        $this->patchMerge($path, ['active' => true], ['If-Match' => $key['etag']] + $this->csrf())->assertStatus(412);
        $this->getJson($path)->assertOk()->assertJsonPath('data.active', false);
        $this->assertStringNotContainsString($key['secret'], DB::table('restv2_audit_events')->get()->toJson());
    }

    public function test_stale_updates_require_reload_and_mutations_require_an_admin_session(): void
    {
        $this->modern();
        $one = $this->create();
        DB::table('api')->where('id', $one['id'])->update(['description' => 'Changed elsewhere']);
        $this->patchMerge('/api/v2/legacy-api-keys/'.$one['id'], ['active' => false], ['If-Match' => $one['etag']] + $this->csrf())->assertStatus(412);
        $this->postJson('/api/v2/legacy-api-keys', ['accessLevel' => 'readOnly', 'skipIpCheck' => true], ['X-CSRF-TOKEN' => 'invalid'])->assertForbidden();
        $headers = $this->keyHeader($this->apiKey());
        $this->getJson('/api/v2/legacy-api-keys', $headers)->assertOk()->assertDontSee($one['secret']);
        $this->postJson('/api/v2/legacy-api-keys', ['accessLevel' => 'readWrite', 'skipIpCheck' => true], $headers)->assertForbidden();
        $this->postEmpty('/api/v2/legacy-api-keys/'.$one['id'].'/regenerate', $headers)->assertForbidden();
        $this->asDomainAdmin(['example.org']);
        $this->getJson('/api/v2/legacy-api-keys')->assertForbidden();
        $this->asSyncUser();
        $this->getJson('/api/v2/legacy-api-keys/'.$one['id'])->assertForbidden();
    }

    public function test_invalid_requests_cannot_bypass_network_or_service_protection(): void
    {
        $this->modern();
        $this->withoutRequestValidation();
        foreach ([['skipIpCheck' => false], ['allowedNetworks' => ['not-an-ip']], ['skipIpCheck' => 'true'], ['service' => true, 'skipIpCheck' => true], ['api_key' => 'chosen', 'skipIpCheck' => true]] as $body) {
            $this->postJson('/api/v2/legacy-api-keys', ['accessLevel' => 'readWrite'] + $body, $this->csrf())->assertUnprocessable();
        }
        $this->postJson('/api/v2/legacy-api-keys?accessLevel=readWrite&skipIpCheck=1', [], $this->csrf())->assertUnprocessable();
        $this->assertDatabaseCount('api', 0);
    }
}
