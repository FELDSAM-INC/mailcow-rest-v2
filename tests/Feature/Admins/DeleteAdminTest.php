<?php

namespace Tests\Feature\Admins;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T069 (AS US4-5, FR-029, FR-030, FR-033, FR-034): administrators delete other
 * administrators with the full cascade, never their own account.
 */
class DeleteAdminTest extends TestCase
{
    use AccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->admin('alice', 'x');
        $this->mailcow()->admin('bob', 'x');
    }

    public function test_the_own_account_cannot_be_deleted(): void
    {
        $this->signInAs('alice', Role::Admin);

        $this->deleteAdmin('Alice')->assertConflict()->assertJsonPath('type', '/api/v2/problems/cannot-delete-own-account');

        $this->assertAdminRow('alice', 1, 1);
        $this->assertAudit('adminDeletion', 'failure', 'admin', 'alice', ['problemType' => '/api/v2/problems/cannot-delete-own-account']);
    }

    public function test_another_administrator_is_deleted_with_everything_of_it(): void
    {
        $this->secondFactorsFor('bob');
        $this->preferencesFor('bob');
        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->assignmentOnly('bob', 'a.example');
        $this->mailcow()->daAclOnly('bob');
        $key = $this->apiKey('bob');
        $session = $this->signInAs('bob', Role::Admin);
        $this->signInAs('alice', Role::Admin);

        $this->deleteAdmin('bob')->assertNoContent();

        $this->assertNothingLeftFor('bob');
        $this->assertSame('ownerDeleted', DB::table('restv2_api_keys')->where('id', $key['id'])->value('revocation_reason'));
        $this->assertAudit('adminDeletion', 'success', 'admin', 'bob', ['revokedApiKeyIds' => [$key['id']], 'removedSecondFactors' => 2, 'removedPasskeys' => 2]);
        $this->useSessionCookie($session);
        $this->getJson('/api/v2/me')->assertUnauthorized();
    }

    public function test_unknown_accounts_and_domain_administrators_are_not_found(): void
    {
        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->domainAdmin('reseller1', ['a.example']);
        $this->signInAs('alice', Role::Admin);

        $this->deleteAdmin('nobody')->assertNotFound();
        $this->deleteAdmin('reseller1')->assertNotFound();
    }
}
