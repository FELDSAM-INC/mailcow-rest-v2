<?php

namespace Tests\Feature\Admins;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T070 (FR-027): an administrator's second factors are suspended until its next
 * password sign-in; only in a signed-in session.
 */
class AdminSecondFactorSuspensionTest extends TestCase
{
    use AccountTestHelpers;

    public function test_the_entries_are_suspended_in_a_session_only(): void
    {
        $this->mailcow()->admin('alice', 'x');
        $this->mailcow()->admin('bob', 'Passw0rd!');
        $this->secondFactorsFor('bob', passkeys: 1);
        $this->signInAs('alice', Role::Admin);

        $this->suspendSecondFactors('admins', 'bob')->assertOk()->assertJsonPath('data.secondFactorStatus', 'suspended')->assertJsonPath('data.activePasskeyCount', 1);
        $this->assertSame(0, DB::table('tfa')->where('username', 'bob')->where('active', 1)->count());
        $this->assertAudit('adminSecondFactorSuspension', 'success', 'admin', 'bob', ['suspendedCount' => 2]);

        $this->suspendSecondFactors('admins', 'bob', $this->keyHeader($this->apiKey('alice')))->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/session-required');

        $this->forgetCookies();
        $this->passwordSignIn('bob')->assertCreated()->assertJsonPath('data.state', 'active');
        $this->assertSame(2, DB::table('tfa')->where('username', 'bob')->where('active', 1)->count());
    }
}
