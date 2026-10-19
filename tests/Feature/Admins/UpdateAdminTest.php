<?php

namespace Tests\Feature\Admins;

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T067 (AS US4-6, US4-7, FR-021, FR-022, FR-024, FR-026, FR-065): merge-patch changes
 * of administrators; unrelated changes of inactive administrators are never refused.
 */
class UpdateAdminTest extends TestCase
{
    use AccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->admin('bob', 'x', active: 0);
        $this->asAdmin('alice');
    }

    public function test_the_password_of_an_inactive_administrator_changes_while_only_one_is_active(): void
    {
        $this->patchAdmin('bob', ['password' => 'N3w-Passw0rd!'])->assertOk()->assertJsonPath('data.active', false);

        $this->assertAudit('adminUpdate', 'success', 'admin', 'bob', ['passwordChanged' => true]);
    }

    public function test_a_forced_password_change_restricts_the_next_sign_in(): void
    {
        $this->patchAdmin('bob', ['active' => true, 'forcePasswordChange' => true])->assertOk();

        $this->forgetCookies();
        $this->passwordSignIn('bob', 'x')->assertCreated()
            ->assertJsonPath('data.state', 'restricted')
            ->assertJsonPath('data.pendingActions', ['passwordChange']);
    }

    public function test_merge_patch_rules_when_invalid(): void
    {
        $this->withoutRequestValidation();

        $this->patchAdmin('bob', [])->assertUnprocessable()->assertViolation('body', 'required');
        $this->patchAdmin('bob', ['domains' => ['a.example']])->assertUnprocessable()->assertViolation('domains', 'unknownField');
        $this->patchAdmin('bob', ['activePasskeyCount' => 3])->assertUnprocessable()->assertViolation('activePasskeyCount', 'immutable');
        $this->patchAdmin('bob', ['forceSecondFactorSetup' => 'yes'])->assertUnprocessable()->assertViolation('forceSecondFactorSetup', 'invalidType');
    }

    public function test_an_outdated_version_changes_nothing_and_a_no_op_is_not_audited(): void
    {
        $etag = (string) $this->getJson('/api/v2/admins/bob')->json('data.etag');

        $this->patchAdmin('bob', ['active' => false], ['If-Match' => $etag])->assertOk()->assertJsonPath('data.etag', $etag);
        $this->assertSame(0, DB::table('restv2_audit_events')->where('event_type', 'adminUpdate')->count());

        DB::table('admin')->where('username', 'bob')->update(['active' => 1]);
        $this->patchAdmin('bob', ['forcePasswordChange' => true], ['If-Match' => $etag])->assertStatus(412);
        $this->assertAdminRow('bob', 1, 1);
    }

    public function test_a_domain_administrator_is_not_found(): void
    {
        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->domainAdmin('reseller1', ['a.example']);

        $this->patchAdmin('reseller1', ['active' => false])->assertNotFound();
    }
}
