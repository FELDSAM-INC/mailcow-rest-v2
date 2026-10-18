<?php

namespace Tests\Feature\SecondFactors;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\Support\WebAuthn\VirtualAuthenticator;
use Tests\TestCase;

/**
 * T040 (feature 005 FR-034, FR-035, US2 AS-5, AS-6): removing one own second factor and all of them.
 */
class SecondFactorRemovalTest extends TestCase
{
    use SelfServiceTestHelpers;

    /**
     * @return TestResponse<Response>
     */
    private function remove(int $id): TestResponse
    {
        return $this->deleteJson('/api/v2/me/second-factors/'.$id, [], $this->csrf());
    }

    /**
     * @return TestResponse<Response>
     */
    private function removeAll(string $currentPassword = MailcowFactory::PASSWORD): TestResponse
    {
        return $this->postJson('/api/v2/me/second-factors/removal', ['currentPassword' => $currentPassword], $this->csrf());
    }

    public function test_one_of_two_active_entries_is_removed(): void
    {
        $this->asSelfServiceUser();
        $first = $this->mailcow()->tfaTotp('anna@example.org', 'JBSWY3DPEHPK3PXP', 'Phone');
        $second = $this->mailcow()->tfaTotp('anna@example.org', 'KRSXG5CTMVRXEZLU', 'Tablet');

        $this->remove($first)->assertNoContent();

        $this->assertFalse(DB::table('tfa')->where('id', $first)->exists());
        $record = $this->onlyAuditRecord('ownSecondFactorRemoval');
        $this->assertSame('Phone', $record->target_name);
        $this->assertSame(['secondFactorId' => $first, 'secondFactorType' => 'totp'], $record->details);

        $this->remove($second)->assertStatus(409)->assertJsonPath('type', '/api/v2/problems/last-second-factor');
        $this->assertTrue(DB::table('tfa')->where('id', $second)->exists());
        $this->assertSame('lastSecondFactor', $this->auditRecords('ownSecondFactorRemoval')[1]->failure_reason);
    }

    public function test_an_inactive_entry_may_be_removed_while_one_entry_is_active(): void
    {
        $this->asSelfServiceUser();
        $this->mailcow()->tfaTotp('anna@example.org', 'JBSWY3DPEHPK3PXP');
        $inactive = $this->mailcow()->tfaHotp('anna@example.org', 0);

        $this->remove($inactive)->assertNoContent();
    }

    public function test_a_forced_second_factor_blocks_removals(): void
    {
        foreach (["'1'", '1'] as $i => $value) {
            $username = "user{$i}@example.org";
            $this->mailcow()->mailboxWithDomain($username);
            DB::table('mailbox')->where('username', $username)->update(['attributes' => DB::raw("JSON_SET(`attributes`, '$.force_tfa', {$value})")]);
            $id = $this->mailcow()->tfaTotp($username, 'JBSWY3DPEHPK3PXP');
            $this->mailcow()->tfaTotp($username, 'KRSXG5CTMVRXEZLU');
            $this->signInAs($username, Role::MailboxUser);

            $this->remove($id)->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/second-factor-required-by-policy');
            $this->removeAll()->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/second-factor-required-by-policy');
            $this->assertSame(2, DB::table('tfa')->where('username', $username)->count());
        }
    }

    public function test_all_entries_are_removed_with_the_current_password_and_passkeys_stay(): void
    {
        $this->asSelfServiceUser();
        $ids = [
            $this->mailcow()->tfaTotp('anna@example.org', 'JBSWY3DPEHPK3PXP'),
            $this->mailcow()->tfaU2f('anna@example.org'),
            $this->mailcow()->tfaHotp('anna@example.org', 0),
        ];
        $this->mailcow()->fido2Credential('anna@example.org', (new VirtualAuthenticator)->createCredential('mail.example.org', 'anna@example.org'));

        $this->removeAll()->assertNoContent();

        $this->assertSame(0, DB::table('tfa')->where('username', 'anna@example.org')->count());
        $this->assertSame(1, DB::table('fido2')->where('username', 'anna@example.org')->count());
        $this->assertSame(['removedIds' => $ids], $this->onlyAuditRecord('ownSecondFactorRemoval')->details);
    }

    public function test_removing_all_with_a_wrong_password_is_refused_and_throttled(): void
    {
        $this->asSelfServiceUser();
        $this->mailcow()->tfaTotp('anna@example.org', 'JBSWY3DPEHPK3PXP');

        $this->removeAll('wrong')->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/current-password-incorrect');
        $this->removeAll('wrong')->assertStatus(403);

        $this->assertSame([0.0, 0.5], $this->sleeper()->slept);
        $this->assertSame(1, DB::table('tfa')->where('username', 'anna@example.org')->count());
    }

    public function test_another_accounts_entry_is_not_found(): void
    {
        $this->mailcow()->mailboxWithDomain('carl@example.org');
        $foreign = $this->mailcow()->tfaTotp('carl@example.org', 'JBSWY3DPEHPK3PXP');
        $this->asSelfServiceUser();

        $this->remove($foreign)->assertNotFound();
        $this->assertTrue(DB::table('tfa')->where('id', $foreign)->exists());
    }

    public function test_removals_are_serialised_with_the_accounts_named_lock(): void
    {
        $this->asSelfServiceUser();
        $id = $this->mailcow()->tfaTotp('anna@example.org', 'JBSWY3DPEHPK3PXP');
        $this->mailcow()->tfaTotp('anna@example.org', 'KRSXG5CTMVRXEZLU');
        config(['database.connections.lockcheck' => config('database.connections.mysql')]);
        $other = DB::connection('lockcheck');
        $name = 'restv2_tfa_'.sha1('anna@example.org');
        $other->select('SELECT GET_LOCK(?, 0)', [$name]);

        try {
            // A second removal waits for the lock that a first one holds; here it never gets it.
            $this->remove($id)->assertStatus(500);
        } finally {
            $other->select('SELECT RELEASE_LOCK(?)', [$name]);
            DB::purge('lockcheck');
        }

        $this->assertTrue(DB::table('tfa')->where('id', $id)->exists());
        $this->remove($id)->assertNoContent();
    }
}
