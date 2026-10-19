<?php

namespace Tests\Feature\DomainAdmins;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T030 (AS US1-7, FR-025, research R9): a rename carries the account's second factors,
 * passkeys, assignments, permission record, API keys and preferences to the new name, together or
 * not at all, removes unused tokens and ends the account's sessions.
 */
class RenameDomainAdminTest extends TestCase
{
    use AccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->domainAdmin('reseller1', [], ['login_as' => 0]);
        $this->mailcow()->assignmentOnly('reseller1', 'a.example', 1, '2026-01-01 10:00:00');
        $this->mailcow()->tfaTotp('reseller1', 'S', 'Phone', 0);
        $this->mailcow()->tfaWebAuthn('reseller1', random_bytes(16), 'PEM', 'Key', 1);
        $this->mailcow()->fido2('reseller1', random_bytes(16), 'PEM', 'mail.example.org');
        $this->mailcow()->fido2('reseller1', random_bytes(16), 'PEM', 'mail.example.org');
        $this->mailcow()->daSso('reseller1', 'AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAAA');
        $this->preferencesFor('reseller1');
        $this->asAdmin();
    }

    public function test_everything_moves_to_the_new_name(): void
    {
        $keys = [$this->apiKey('reseller1'), $this->apiKey('reseller1', 'readOnly')];
        DB::table('restv2_api_keys')->update(['owner_created_at' => DB::table('admin')->where('username', 'reseller1')->value('created')]);
        $created = DB::table('admin')->where('username', 'reseller1')->value('created');

        $this->patchDomainAdmin('reseller1', ['username' => 'reseller-one'])->assertOk()
            ->assertJsonPath('data.username', 'reseller-one')
            ->assertJsonPath('data.permissions.loginAs', false)
            ->assertJsonPath('data.activePasskeyCount', 2)
            ->assertJsonPath('data.secondFactorStatus', 'active');

        foreach (['admin', 'domain_admins', 'da_acl', 'tfa', 'fido2', 'da_sso'] as $table) {
            $this->assertFalse(DB::table($table)->where('username', 'reseller1')->exists(), $table);
        }

        $this->assertSame($created, DB::table('admin')->where('username', 'reseller-one')->value('created'));
        $this->assertAssignments('reseller-one', [['a.example', 1, '2026-01-01 10:00:00']]);
        $this->assertDaAcl('reseller-one', ['login_as' => 0]);
        $this->assertSame(2, DB::table('tfa')->where('username', 'reseller-one')->count());
        $this->assertSame(2, DB::table('fido2')->where('username', 'reseller-one')->count());
        $this->assertSame(2, DB::table('restv2_api_keys')->where('owner_username', 'reseller-one')->whereNull('revoked_at')->count());
        $this->assertSame('de-de', DB::table('restv2_preferences')->where('username', 'reseller-one')->value('language'));
        $this->forgetCookies();
        $this->getJson('/api/v2/me', $this->keyHeader($keys[0]))->assertOk()->assertJsonPath('data.username', 'reseller-one');
    }

    public function test_the_open_sessions_of_the_account_end(): void
    {
        $session = $this->signInAs('reseller1', Role::DomainAdmin);
        $this->asAdmin();

        $this->patchDomainAdmin('reseller1', ['username' => 'reseller-one'])->assertOk();

        $this->useSessionCookie($session);
        $this->getJson('/api/v2/me')->assertUnauthorized();
    }

    public function test_a_collision_with_any_account_changes_nothing(): void
    {
        $this->mailcow()->admin('Ops', 'x');

        $this->patchDomainAdmin('reseller1', ['username' => 'ops', 'forcePasswordChange' => true])->assertConflict()
            ->assertJsonPath('type', '/api/v2/problems/account-exists');

        $this->assertAdminRow('reseller1', 0, 1);
        $this->assertSame(1, DB::table('domain_admins')->where('username', 'reseller1')->count());
        $this->assertSame(2, DB::table('tfa')->where('username', 'reseller1')->count());
        $this->assertSame(2, DB::table('fido2')->where('username', 'reseller1')->count());
        $this->assertTrue(DB::table('da_acl')->where('username', 'reseller1')->exists());
        $this->assertTrue(DB::table('restv2_preferences')->where('username', 'reseller1')->exists());
    }

    public function test_a_rename_to_the_same_name_in_another_letter_case_is_no_change(): void
    {
        $this->patchDomainAdmin('reseller1', ['username' => 'RESELLER1'])->assertOk()->assertJsonPath('data.username', 'reseller1');

        $this->assertSame(0, DB::table('restv2_audit_events')->where('event_type', 'domainAdminUpdate')->count());
    }

    public function test_earlier_audit_records_keep_the_old_name_and_a_later_account_inherits_nothing(): void
    {
        $this->patchDomainAdmin('reseller1', ['forcePasswordChange' => true])->assertOk();

        $this->patchDomainAdmin('reseller1', ['username' => 'reseller-one'])->assertOk();
        $this->createDomainAdmin(['username' => 'reseller1', 'password' => 'Passw0rd!x', 'domains' => ['a.example']])->assertCreated()
            ->assertJsonPath('data.activePasskeyCount', 0)
            ->assertJsonPath('data.secondFactorStatus', 'none')
            ->assertJsonPath('data.permissions.loginAs', true);

        $records = DB::table('restv2_audit_events')->where('event_type', 'domainAdminUpdate')->orderBy('occurred_at')->get();
        $this->assertSame(['reseller1', 'reseller1'], $records->pluck('target_id')->all());
        $this->assertSame(['old' => 'reseller1', 'new' => 'reseller-one'], json_decode((string) $records[1]->details, true)['changes']['username']);
        $this->assertFalse(DB::table('restv2_preferences')->where('username', 'reseller1')->exists());
        $this->assertSame(0, DB::table('restv2_api_keys')->where('owner_username', 'reseller1')->count());
    }
}
