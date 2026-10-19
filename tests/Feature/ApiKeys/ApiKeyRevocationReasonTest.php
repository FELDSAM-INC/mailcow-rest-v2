<?php

namespace Tests\Feature\ApiKeys;

use App\Services\ApiKeys\ApiKeyService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T012 (FR-034, research R10): API keys record why they were revoked, keys of a
 * deleted owner are revoked together, and a rename moves the keys to the new username.
 */
class ApiKeyRevocationReasonTest extends TestCase
{
    use AccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->domainAdmin('reseller1', ['a.example']);
        $this->asAdmin();
    }

    public function test_a_manual_revocation_records_its_reason(): void
    {
        $key = $this->apiKey('reseller1');
        $before = $this->getJson('/api/v2/api-keys/'.$key['id'])->assertOk()->assertJsonPath('data.revocationReason', null)->json('data.etag');

        $response = $this->postEmpty('/api/v2/api-keys/'.$key['id'].'/revocation', $this->csrf())->assertOk();

        $response->assertJsonPath('data.revocationReason', 'manual')->assertJsonPath('data.status', 'revoked');
        $this->assertNotSame($before, $response->json('data.etag'));
        $this->assertSame('manual', DB::table('restv2_api_keys')->where('id', $key['id'])->value('revocation_reason'));
    }

    public function test_a_new_key_is_bound_to_its_owner_creation_time(): void
    {
        $response = $this->postJson('/api/v2/api-keys', ['name' => 'billing', 'ownerUsername' => 'reseller1', 'accessLevel' => 'readWrite'], $this->csrf())->assertCreated();

        $this->assertSame(
            DB::table('admin')->where('username', 'reseller1')->value('created'),
            DB::table('restv2_api_keys')->where('id', $response->json('data.id'))->value('owner_created_at'),
        );
    }

    public function test_every_unrevoked_key_of_an_owner_is_revoked_together(): void
    {
        $first = $this->apiKey('reseller1');
        $second = $this->apiKey('reseller1', 'readOnly');
        $revoked = $this->apiKey('reseller1', revokedAt: now('UTC')->subDay()->toImmutable());
        $other = $this->apiKey('admin');

        $ids = $this->app->make(ApiKeyService::class)->revokeAllForOwner('reseller1', 'admin', 'ownerDeleted');

        $this->assertEqualsCanonicalizing([$first['id'], $second['id']], $ids);
        $rows = DB::table('restv2_api_keys')->get()->keyBy('id');
        $this->assertSame('ownerDeleted', $rows[$first['id']]->revocation_reason);
        $this->assertSame('admin', $rows[$first['id']]->revoked_by);
        $this->assertNotNull($rows[$second['id']]->revoked_at);
        $this->assertNull($rows[$revoked['id']]->revocation_reason);
        $this->assertNull($rows[$other['id']]->revoked_at);

        $this->getJson('/api/v2/api-keys/'.$first['id'])->assertOk()
            ->assertJsonPath('data.revocationReason', 'ownerDeleted')
            ->assertJsonPath('data.revokedBy.username', 'admin');
    }

    public function test_a_rename_moves_the_keys_and_keeps_the_binding(): void
    {
        $key = $this->apiKey('reseller1');
        DB::table('restv2_api_keys')->where('id', $key['id'])->update(['owner_created_at' => '2026-01-01 10:00:00']);

        $this->app->make(ApiKeyService::class)->transferOwner('reseller1', 'reseller-one');

        $row = DB::table('restv2_api_keys')->where('id', $key['id'])->first();
        $this->assertSame('reseller-one', $row->owner_username);
        $this->assertSame('2026-01-01 10:00:00', $row->owner_created_at);
    }
}
