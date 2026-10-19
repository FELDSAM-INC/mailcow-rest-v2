<?php

namespace Tests\Feature\Admins;

use Database\Factories\MailcowRows;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T066 (AS US4-1, US4-2, FR-014 to FR-017, FR-020, research R6): a signed-in
 * administrator creates administrators; usernames share one namespace with domain administrators.
 */
class CreateAdminTest extends TestCase
{
    use AccountTestHelpers;

    private const PASSWORD = 'Adm1n-Passw0rd!';

    protected function setUp(): void
    {
        parent::setUp();

        $this->asAdmin();
    }

    public function test_an_administrator_is_created(): void
    {
        $this->captureLog();

        $response = $this->createAdmin(['username' => 'Bob', 'password' => self::PASSWORD])->assertCreated()
            ->assertHeader('Location', '/api/v2/admins/bob')
            ->assertJsonPath('data.username', 'bob')
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.forcePasswordChange', false);
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));

        $this->assertAdminRow('bob', 1, 1);
        $this->assertSame(['force_tfa' => '0', 'force_pw_update' => '0'], $this->adminAttributes('bob'));
        $this->assertAudit('adminCreation', 'success', 'admin', 'bob', ['active' => true, 'forcePasswordChange' => false, 'forceSecondFactorSetup' => false, 'passwordPreHashed' => false]);
        $this->assertNoSecretIn(self::PASSWORD, (string) DB::table('admin')->where('username', 'bob')->value('password'));

        $this->forgetCookies();
        $this->passwordSignIn('bob', self::PASSWORD)->assertCreated()->assertJsonPath('data.role', 'admin');
    }

    public function test_status_and_forced_flags_can_be_given(): void
    {
        $this->createAdmin(['username' => 'carol', 'password' => self::PASSWORD, 'active' => false, 'forcePasswordChange' => true])->assertCreated();

        $this->assertAdminRow('carol', 1, 0, true, false);
    }

    public function test_the_password_policy_names_every_unmet_rule(): void
    {
        $this->redis()->hMSet('PASSWD_POLICY', ['length' => '10', 'chars' => '1', 'special_chars' => '1']);

        $response = $this->createAdmin(['username' => 'bob', 'password' => '1234'])->assertUnprocessable();

        $this->assertSame(['tooShort', 'missingCharacterClass', 'missingCharacterClass'], array_column($response->json('errors.password'), 'code'));
        $this->assertFalse(DB::table('admin')->where('username', 'bob')->exists());
    }

    public function test_a_pre_hashed_password_is_stored_as_given(): void
    {
        $hash = MailcowRows::hash('SSHA512', 'Whatever-1');

        $this->createAdmin(['username' => 'bob', 'password' => $hash])->assertCreated();

        $this->assertSame($hash, DB::table('admin')->where('username', 'bob')->value('password'));
    }

    public function test_a_username_of_any_account_in_any_letter_case_is_a_conflict(): void
    {
        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->domainAdmin('Reseller', ['a.example']);

        $this->createAdmin(['username' => 'ADMIN', 'password' => self::PASSWORD])->assertConflict()->assertJsonPath('type', '/api/v2/problems/account-exists');
        $this->createAdmin(['username' => 'reseller', 'password' => self::PASSWORD])->assertConflict()->assertJsonPath('type', '/api/v2/problems/account-exists');
        $this->assertSame(2, DB::table('admin')->count());
    }

    public function test_orphaned_rows_of_the_name_are_removed(): void
    {
        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->assignmentOnly('bob', 'a.example');
        $this->mailcow()->daAclOnly('bob');
        $this->mailcow()->daSso('bob', 'AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAAA');
        $this->mailcow()->tfaTotp('bob', 'S');

        $this->createAdmin(['username' => 'bob', 'password' => self::PASSWORD])->assertCreated()->assertJsonPath('data.secondFactorStatus', 'none');

        foreach (['domain_admins', 'da_acl', 'da_sso', 'tfa'] as $table) {
            $this->assertFalse(DB::table($table)->where('username', 'bob')->exists(), $table);
        }
    }
}
