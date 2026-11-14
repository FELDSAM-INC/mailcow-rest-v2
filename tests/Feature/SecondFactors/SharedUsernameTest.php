<?php

namespace Tests\Feature\SecondFactors;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

class SharedUsernameTest extends TestCase
{
    use SelfServiceTestHelpers;

    private const USERNAME = 'same@example.org';

    public function test_neither_account_can_list_enrol_or_remove_ambiguously_owned_factors(): void
    {
        $this->mailcow()->mailboxWithDomain(self::USERNAME);
        $this->mailcow()->admin(self::USERNAME, 'different-admin-password');
        $first = $this->mailcow()->tfaTotp(self::USERNAME, 'JBSWY3DPEHPK3PXP', 'Admin phone');
        $this->mailcow()->tfaTotp(self::USERNAME, 'KRSXG5CTMVRXEZLU', 'Admin tablet');
        $before = DB::table('tfa')->where('username', self::USERNAME)->get()->toArray();

        foreach ([Role::MailboxUser, Role::Admin] as $role) {
            $this->signInAs(self::USERNAME, $role);
            $this->getJson('/api/v2/me/second-factors')->assertForbidden();
            $this->deleteJson('/api/v2/me/second-factors/'.$first, [], $this->csrf())->assertForbidden();
            $this->postJson('/api/v2/me/second-factors/removal', [
                'currentPassword' => $role === Role::Admin ? 'different-admin-password' : MailcowFactory::PASSWORD,
            ], $this->csrf())->assertForbidden();
            $this->postEmpty('/api/v2/me/second-factors/totp-enrolment', $this->csrf())->assertForbidden();
        }

        $this->assertEquals($before, DB::table('tfa')->where('username', self::USERNAME)->get()->toArray());
    }

    public function test_password_sign_in_does_not_use_or_reactivate_shared_factors(): void
    {
        $this->mailcow()->mailboxWithDomain(self::USERNAME);
        $this->mailcow()->admin(self::USERNAME, MailcowFactory::PASSWORD);
        $id = $this->mailcow()->tfaTotp(self::USERNAME, 'JBSWY3DPEHPK3PXP');

        foreach ([1, 0] as $active) {
            DB::table('tfa')->where('id', $id)->update(['active' => $active]);
            $this->passwordSignIn(self::USERNAME)->assertForbidden()
                ->assertJsonPath('type', '/api/v2/problems/second-factor-unavailable');
            $this->assertSame($active, (int) DB::table('tfa')->where('id', $id)->value('active'));
        }
    }

    public function test_a_collision_created_after_the_password_step_cannot_complete_mfa(): void
    {
        $this->mailcow()->mailboxWithDomain(self::USERNAME);
        $id = $this->mailcow()->tfaTotp(self::USERNAME, 'JBSWY3DPEHPK3PXP');
        $this->passwordSignIn(self::USERNAME)->assertCreated();
        $this->mailcow()->admin(self::USERNAME, 'different-admin-password');

        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'anonymous');
        $this->verifySecondFactor(['type' => 'totp', 'methodId' => $id, 'code' => '123456'])
            ->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-expired');
        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'anonymous');
    }

    public function test_mailbox_lifecycle_changes_cannot_move_or_delete_an_administrators_factors(): void
    {
        $this->mailcow()->mailboxWithDomain(self::USERNAME);
        $this->mailcow()->admin(self::USERNAME, 'different-admin-password');
        $id = $this->mailcow()->tfaTotp(self::USERNAME, 'JBSWY3DPEHPK3PXP');
        $this->asDomainAdmin(['example.org']);

        $this->deleteMailbox(self::USERNAME)->assertForbidden();
        $this->postJson('/api/v2/mailboxes/'.self::USERNAME.'/rename', [
            'newLocalPart' => 'renamed', 'keepOldAddressAsAlias' => false,
        ], $this->csrf())->assertForbidden();
        $this->assertSame(self::USERNAME, DB::table('tfa')->where('id', $id)->value('username'));
        $this->assertTrue(DB::table('mailbox')->where('username', self::USERNAME)->exists());
    }

    public function test_a_mailbox_cannot_be_renamed_to_an_administrator_username(): void
    {
        $this->mailcow()->mailboxWithDomain('other@example.org');
        $this->mailcow()->admin(self::USERNAME, 'different-admin-password');
        $id = $this->mailcow()->tfaTotp(self::USERNAME, 'JBSWY3DPEHPK3PXP');
        $this->asDomainAdmin(['example.org']);

        $this->postJson('/api/v2/mailboxes/other@example.org/rename', [
            'newLocalPart' => 'same', 'keepOldAddressAsAlias' => false,
        ], $this->csrf())->assertForbidden();
        $this->assertTrue(DB::table('mailbox')->where('username', 'other@example.org')->exists());
        $this->assertSame(1, (int) DB::table('tfa')->where('id', $id)->value('active'));
    }

    public function test_a_domain_administrator_cannot_create_a_mailbox_with_an_existing_admin_name(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->admin(self::USERNAME, 'different-admin-password');
        $id = $this->mailcow()->tfaTotp(self::USERNAME, 'JBSWY3DPEHPK3PXP');
        $this->asDomainAdmin(['example.org']);

        $this->createMailbox(['localPart' => 'same', 'domain' => 'example.org', 'password' => MailcowFactory::PASSWORD])->assertForbidden();
        $this->assertFalse(DB::table('mailbox')->where('username', self::USERNAME)->exists());
        $this->assertSame(1, (int) DB::table('tfa')->where('id', $id)->value('active'));
    }

    public function test_admin_creation_does_not_delete_a_mailboxs_existing_factors(): void
    {
        $this->mailcow()->mailboxWithDomain(self::USERNAME);
        $id = $this->mailcow()->tfaTotp(self::USERNAME, 'JBSWY3DPEHPK3PXP');
        $this->asAdmin();

        $this->postJson('/api/v2/admins', ['username' => self::USERNAME, 'password' => 'Str0ng-Passw0rd!'], $this->csrf())
            ->assertUnprocessable();
        $this->assertFalse(DB::table('admin')->where('username', self::USERNAME)->exists());
        $this->assertSame(1, (int) DB::table('tfa')->where('id', $id)->value('active'));
    }

    public function test_a_calendar_resource_is_not_an_authenticating_account_collision(): void
    {
        $this->mailcow()->mailboxWithDomain(self::USERNAME);
        DB::table('mailbox')->where('username', self::USERNAME)->update(['kind' => 'location']);
        $this->mailcow()->admin(self::USERNAME, MailcowFactory::PASSWORD);
        $id = $this->mailcow()->tfaTotp(self::USERNAME, 'JBSWY3DPEHPK3PXP');

        $this->passwordSignIn(self::USERNAME)->assertCreated()
            ->assertJsonPath('data.pendingSignIn.methods.0.id', $id);
    }
}
