<?php

namespace Tests\Feature\Admins;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T068 (FR-025, research R9): a rename moves the administrator's second factors,
 * passkeys, keys and preferences; an administrator renaming itself stays signed in under the new
 * name while its other sessions end.
 */
class RenameAdminTest extends TestCase
{
    use AccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->admin('alice', 'x');
        $this->mailcow()->admin('bob', 'x');
    }

    public function test_everything_moves_to_the_new_name(): void
    {
        $this->secondFactorsFor('bob', totpActive: 0);
        $this->preferencesFor('bob');
        $this->mailcow()->daSso('bob', 'AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAAA');
        $key = $this->apiKey('bob');
        $this->signInAs('alice', Role::Admin);

        $this->patchAdmin('bob', ['username' => 'robert'])->assertOk()->assertJsonPath('data.username', 'robert')->assertJsonPath('data.activePasskeyCount', 2);

        $this->assertSame(2, DB::table('tfa')->where('username', 'robert')->count());
        $this->assertSame(2, DB::table('fido2')->where('username', 'robert')->count());
        $this->assertSame('robert', DB::table('restv2_api_keys')->where('id', $key['id'])->value('owner_username'));
        $this->assertTrue(DB::table('restv2_preferences')->where('username', 'robert')->exists());
        foreach (['admin', 'tfa', 'fido2', 'da_sso', 'restv2_preferences'] as $table) {
            $this->assertFalse(DB::table($table)->where('username', 'bob')->exists(), $table);
        }

        $this->assertAudit('adminUpdate', 'success', 'admin', 'bob', ['changes' => ['username' => ['old' => 'bob', 'new' => 'robert']]]);
    }

    public function test_a_self_rename_keeps_the_own_session_and_ends_the_others(): void
    {
        $other = $this->signInAs('alice', Role::Admin);
        $this->signInAs('alice', Role::Admin);

        $this->patchAdmin('alice', ['username' => 'alicia'])->assertOk();

        $this->getJson('/api/v2/me')->assertOk()->assertJsonPath('data.username', 'alicia');
        $this->useSessionCookie($other);
        $this->getJson('/api/v2/me')->assertUnauthorized();
    }

    public function test_a_collision_with_a_domain_administrator_changes_nothing(): void
    {
        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->domainAdmin('reseller', ['a.example']);
        $this->signInAs('alice', Role::Admin);

        $this->patchAdmin('bob', ['username' => 'Reseller'])->assertConflict()->assertJsonPath('type', '/api/v2/problems/account-exists');

        $this->assertAdminRow('bob', 1, 1);
    }
}
