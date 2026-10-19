<?php

namespace Tests\Feature\DomainAdmins;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T031 (AS US1-8, FR-031, FR-033, FR-034, FR-036, research R10): a deletion removes
 * the account and everything legacy removes with it, revokes its keys, ends its sessions, removes
 * its preferences and unused tokens, and a later account with the same name inherits nothing.
 */
class DeleteDomainAdminTest extends TestCase
{
    use AccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->ensureDomain('b.example');
        $this->mailcow()->domainAdmin('reseller1', ['a.example', 'b.example'], ['login_as' => 0]);
        $this->asAdmin();
    }

    public function test_the_deletion_removes_everything_of_the_account(): void
    {
        $this->secondFactorsFor('reseller1');
        $this->mailcow()->tfaU2f('reseller1');
        $this->mailcow()->daSso('reseller1', 'AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAAA');
        $this->preferencesFor('reseller1');
        $key = $this->apiKey('reseller1');
        $session = $this->signInAs('reseller1', Role::DomainAdmin);
        $this->asAdmin();

        $this->deleteDomainAdmin('reseller1')->assertNoContent();

        $this->assertNothingLeftFor('reseller1');
        $row = DB::table('restv2_api_keys')->where('id', $key['id'])->first();
        $this->assertSame(['ownerDeleted', 'admin'], [$row->revocation_reason, $row->revoked_by]);
        $this->getJson('/api/v2/api-keys/'.$key['id'])->assertOk()->assertJsonPath('data.revocationReason', 'ownerDeleted');
        $this->assertAudit('domainAdminDeletion', 'success', 'domainAdmin', 'reseller1', [
            'revokedApiKeyIds' => [$key['id']],
            'removedSecondFactors' => 3,
            'removedPasskeys' => 2,
            'removedSsoTokens' => 1,
            'domains' => ['a.example', 'b.example'],
            'trigger' => 'request',
        ]);

        $this->useSessionCookie($session);
        $this->getJson('/api/v2/me')->assertUnauthorized();
    }

    public function test_an_account_without_assignments_is_deletable(): void
    {
        $this->mailcow()->orphanedDomainAdmin('lonely');

        $this->deleteDomainAdmin('Lonely')->assertNoContent();

        $this->assertNothingLeftFor('lonely');
    }

    public function test_unknown_accounts_and_administrators_are_not_found(): void
    {
        $this->deleteDomainAdmin('nobody')->assertNotFound();
        $this->deleteDomainAdmin('admin')->assertNotFound();
        $this->assertAdminRow('admin', 1, 1);
    }

    public function test_an_outdated_version_changes_nothing(): void
    {
        $etag = $this->getJson('/api/v2/domain-admins/reseller1')->json('data.etag');
        DB::table('da_acl')->where('username', 'reseller1')->update(['login_as' => 1]);

        $this->deleteDomainAdmin('reseller1', ['If-Match' => $etag])->assertStatus(412);

        $this->assertAdminRow('reseller1', 0, 1);
    }

    public function test_a_later_account_with_the_same_name_inherits_nothing(): void
    {
        $this->secondFactorsFor('reseller1');
        $this->preferencesFor('reseller1');
        $key = $this->apiKey('reseller1');

        $this->deleteDomainAdmin('reseller1')->assertNoContent();
        $this->createDomainAdmin(['username' => 'reseller1', 'password' => 'Passw0rd!x', 'domains' => ['a.example']])->assertCreated()
            ->assertJsonPath('data.secondFactorStatus', 'none')
            ->assertJsonPath('data.activePasskeyCount', 0)
            ->assertJsonPath('data.permissions.loginAs', true);

        $this->assertFalse(DB::table('restv2_preferences')->where('username', 'reseller1')->exists());
        $this->forgetCookies();
        $this->getJson('/api/v2/me', $this->keyHeader($key))->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/api-key-revoked');
    }
}
