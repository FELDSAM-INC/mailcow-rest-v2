<?php

namespace Tests\Feature\Mailboxes;

use App\Support\Hashing\LegacyPasswordVerifier;
use Database\Factories\MailcowRows;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

/**
 * Feature 003 T041 (FR-024, FR-025, AS-8–AS-11): passwords follow the password policy in mailcow's
 * shared store and mailcow's hash scheme, pre-hashed values are stored as given, and external
 * authentication sources take no password and no forced password change.
 */
class CreateMailboxPasswordTest extends TestCase
{
    use MailboxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function create(array $body): TestResponse
    {
        return $this->createMailbox(['localPart' => 'anna', 'domain' => 'example.org'] + $body);
    }

    public function test_every_unmet_policy_rule_is_reported(): void
    {
        $this->redis()->hMSet('PASSWD_POLICY', ['length' => '10', 'numbers' => '1']);
        $this->asAdmin();

        $errors = $this->create(['password' => 'short'])->assertStatus(422)->json('errors.password');

        $this->assertEquals(['code' => 'tooShort', 'params' => ['min' => 10]], array_intersect_key($errors[0], ['code' => 1, 'params' => 1]));
        $this->assertEquals(['code' => 'missingCharacterClass', 'params' => ['characterClass' => 'digit']], array_intersect_key($errors[1], ['code' => 1, 'params' => 1]));
        $this->create(['password' => 'longenough1'])->assertCreated();
    }

    public function test_a_pre_hashed_password_is_stored_as_given(): void
    {
        $this->redis()->hMSet('PASSWD_POLICY', ['length' => '64']);
        $hash = MailcowRows::hash('SSHA512', 'Original1!');
        $this->asAdmin();

        $this->create(['password' => $hash])->assertCreated();

        $this->assertSame($hash, $this->mailboxRow('anna@example.org')?->password);
        $this->assertTrue((new LegacyPasswordVerifier)->verify($hash, 'Original1!'));
        $this->assertTrue($this->auditOf('mailboxCreation')[0]->details['preHashed']);
    }

    public function test_the_configured_scheme_is_used(): void
    {
        config(['restv2.mailcow_pass_scheme' => 'SSHA256']);
        $this->asAdmin();

        $this->create(['password' => 'Passw0rd!dev'])->assertCreated();

        $stored = (string) $this->mailboxRow('anna@example.org')?->password;
        $this->assertStringStartsWith('{SSHA256}', $stored);
        $this->assertTrue((new LegacyPasswordVerifier)->verify($stored, 'Passw0rd!dev'));
    }

    public function test_external_sources_take_no_password_and_no_forced_change(): void
    {
        $this->mailcow()->identityProvider('ldap');
        $template = $this->mailcow()->mailboxTemplate('Forced', ['force_pw_update' => 1, 'force_tfa' => 1]);
        $this->asAdmin();

        $this->create(['authSource' => 'ldap', 'templateId' => $template])->assertCreated()
            ->assertJsonPath('data.authSource', 'ldap')
            ->assertJsonPath('data.forcePasswordChange', false)
            ->assertJsonPath('data.forceSecondFactorSetup', true);
        $this->assertSame('', $this->mailboxRow('anna@example.org')?->password);
        $this->assertSame('ldap', $this->mailboxRow('anna@example.org')?->authsource);
        $this->assertSame('0', $this->mailboxAttributes('anna@example.org')['force_pw_update']);
        $this->assertFalse($this->auditOf('mailboxCreation')[0]->details['passwordSet']);

        $this->createMailbox(['localPart' => 'bob', 'domain' => 'example.org', 'authSource' => 'ldap', 'password' => 'Passw0rd!dev'])->assertStatus(422)
            ->assertJsonPath('errors.password.0.code', 'conflictsWith')
            ->assertJsonPath('errors.password.0.params', ['field' => 'authSource', 'value' => 'ldap']);
        $this->createMailbox(['localPart' => 'bob', 'domain' => 'example.org', 'authSource' => 'ldap', 'forcePasswordChange' => true])->assertStatus(422)
            ->assertJsonPath('errors.forcePasswordChange.0.code', 'conflictsWith');
        $this->createMailbox(['localPart' => 'bob', 'domain' => 'example.org', 'authSource' => 'keycloak'])->assertStatus(422)
            ->assertJsonPath('errors.authSource.0.code', 'notInList')
            ->assertJsonPath('errors.authSource.0.params.allowed', ['mailcow', 'ldap']);
    }

    public function test_generic_oidc_also_turns_off_the_forced_second_factor(): void
    {
        $this->mailcow()->identityProvider('generic-oidc');
        $this->mailcow()->defaultMailboxTemplate(['force_tfa' => 1]);
        $this->asAdmin();

        $this->create(['authSource' => 'genericOidc'])->assertCreated()->assertJsonPath('data.forceSecondFactorSetup', false);
        $this->assertSame('generic-oidc', $this->mailboxRow('anna@example.org')?->authsource);
        $this->createMailbox(['localPart' => 'bob', 'domain' => 'example.org', 'authSource' => 'genericOidc', 'forceSecondFactorSetup' => true])->assertStatus(422)
            ->assertJsonPath('errors.forceSecondFactorSetup.0.code', 'conflictsWith')
            ->assertJsonPath('errors.forceSecondFactorSetup.0.params', ['field' => 'authSource', 'value' => 'genericOidc']);
    }

    public function test_a_mailcow_mailbox_needs_a_password(): void
    {
        $this->asAdmin();

        $this->create([])->assertStatus(422)->assertJsonPath('errors.password.0.code', 'required');
    }

    public function test_an_unavailable_shared_store_refuses_a_plain_password_before_anything_is_stored(): void
    {
        $this->asAdmin();
        $csrf = $this->csrf();
        $this->breakSharedStore();

        $this->postJson('/api/v2/mailboxes', ['localPart' => 'anna', 'domain' => 'example.org', 'password' => 'Passw0rd!dev'], $csrf)
            ->assertStatus(503)
            ->assertJsonPath('dependency', 'sharedStore');
        $this->assertSame(0, DB::table('mailbox')->count());
        $this->assertSame('temporaryFailure', $this->auditOf('mailboxCreation')[0]->failure_reason);
    }
}
