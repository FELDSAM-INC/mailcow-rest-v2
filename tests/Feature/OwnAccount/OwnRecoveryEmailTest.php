<?php

namespace Tests\Feature\OwnAccount;

use App\Auth\Role;
use App\Services\PasswordReset\PasswordResetService;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T050 (feature 005 FR-018, US3 AS-1): getOwnRecoveryEmail and replaceOwnRecoveryEmail, stored where
 * legacy edit_user_account() stores the recovery address.
 */
class OwnRecoveryEmailTest extends TestCase
{
    use ApiKeys;
    use SelfServiceTestHelpers;

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function replace(mixed $address, string $currentPassword = MailcowFactory::PASSWORD, array $headers = []): TestResponse
    {
        return $this->putJson('/api/v2/me/recovery-email', ['address' => $address, 'currentPassword' => $currentPassword], $this->csrf() + $headers);
    }

    public function test_the_address_is_read_with_its_tag(): void
    {
        $this->asSelfServiceUser('anna@example.org', ['recovery_email' => 'anna.private@example.net']);

        $response = $this->getJson('/api/v2/me/recovery-email')->assertOk()->assertJsonPath('data.address', 'anna.private@example.net');
        $response->assertHeader('ETag', (string) $response->json('data.etag'));

        $this->asSelfServiceUser('bob@example.org');
        $this->getJson('/api/v2/me/recovery-email')->assertOk()->assertJsonPath('data.address', null);
    }

    public function test_the_address_is_set_changed_and_removed(): void
    {
        $this->asSelfServiceUser('anna@example.org', userAcl: ['pw_reset' => 1]);
        $before = (string) $this->getJson('/api/v2/me/recovery-email')->headers->get('ETag');

        $response = $this->replace('anna.private@example.net')->assertOk()->assertJsonPath('data.address', 'anna.private@example.net');
        $this->assertSame('anna.private@example.net', $this->mailboxAttributes('anna@example.org')['recovery_email']);
        $this->assertNotSame($before, $response->headers->get('ETag'));
        $record = $this->onlyAuditRecord('ownRecoveryEmailChange');
        $this->assertSame(['changes' => ['recoveryEmail' => ['old' => '', 'new' => 'anna.private@example.net']]], $record->details);
        $this->assertSame('recoveryEmail', $record->target_type);
        $this->assertSame('anna@example.org', $record->target_id);

        $this->replace('')->assertOk()->assertJsonPath('data.address', null);
        $this->assertSame('', $this->mailboxAttributes('anna@example.org')['recovery_email']);
        $this->replace('anna.private@example.net')->assertOk();
        $this->replace(null)->assertOk()->assertJsonPath('data.address', null);
        $this->assertSame('', $this->mailboxAttributes('anna@example.org')['recovery_email']);
    }

    public function test_changed_or_removed_recovery_addresses_revoke_only_the_owners_tokens(): void
    {
        $this->asSelfServiceUser('anna@example.org', ['recovery_email' => 'old@example.net'], ['pw_reset' => 1]);
        $other = PasswordResetService::newToken();
        DB::table('reset_password')->insert(['username' => 'other@example.org', 'token' => $other]);

        foreach (['new@example.net', null] as $address) {
            $token = PasswordResetService::newToken();
            $password = DB::table('mailbox')->where('username', 'anna@example.org')->value('password');
            DB::table('reset_password')->insert(['username' => 'anna@example.org', 'token' => $token]);
            $this->postJson('/api/v2/password-resets/token-check', ['token' => $token], $this->csrf())->assertOk();
            $this->replace($address)->assertOk();
            $this->postJson('/api/v2/password-resets/token-check', ['token' => $token], $this->csrf())
                ->assertStatus(400)->assertJsonPath('type', '/api/v2/problems/invalid-reset-token');
            $this->postJson('/api/v2/password-resets/completion', ['token' => $token, 'newPassword' => 'N3w!Passw0rd'], $this->csrf())
                ->assertStatus(400)->assertJsonPath('type', '/api/v2/problems/invalid-reset-token');
            $this->assertSame($password, DB::table('mailbox')->where('username', 'anna@example.org')->value('password'));
        }

        $this->assertSame([$other], DB::table('reset_password')->pluck('token')->all());
    }

    public function test_unchanged_or_rejected_recovery_updates_preserve_reset_tokens(): void
    {
        $this->asSelfServiceUser('anna@example.org', ['recovery_email' => 'old@example.net'], ['pw_reset' => 1]);
        $token = PasswordResetService::newToken();
        DB::table('reset_password')->insert(['username' => 'anna@example.org', 'token' => $token]);

        $this->replace('old@example.net')->assertOk();
        $this->replace('new@example.net', 'wrong-password')->assertForbidden();
        $this->replace('new@example.net', headers: ['If-Match' => '"outdated"'])->assertStatus(412);
        $this->postJson('/api/v2/password-resets/token-check', ['token' => $token], $this->csrf())->assertOk();
    }

    public function test_the_own_address_is_accepted(): void
    {
        $this->asSelfServiceUser('anna@example.org', userAcl: ['pw_reset' => 1]);

        $this->replace('anna@example.org')->assertOk()->assertJsonPath('data.address', 'anna@example.org');
    }

    public function test_invalid_addresses_are_refused(): void
    {
        $this->asSelfServiceUser('anna@example.org', userAcl: ['pw_reset' => 1]);
        $this->withoutRequestValidation();

        $this->replace('not an address')->assertStatus(422)->assertJsonPath('errors.address.0.code', 'invalidFormat')->assertJsonPath('errors.address.0.params.format', 'email');
        $this->replace(str_repeat('a', 244).'@example.org')->assertStatus(422)->assertJsonPath('errors.address.0.code', 'tooLong');
        $this->assertArrayNotHasKey('recovery_email', $this->mailboxAttributes('anna@example.org'));
    }

    public function test_a_change_needs_the_password_reset_permission_unless_the_address_stays(): void
    {
        $this->asSelfServiceUser('anna@example.org', ['recovery_email' => 'anna.private@example.net'], ['pw_reset' => 0]);

        $this->replace('other@example.net')
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/permission-flag-required')
            ->assertJsonPath('requiredFlags', ['pwReset']);
        $this->replace('anna.private@example.net')->assertOk();
        $this->assertSame(['requiredFlags' => ['pwReset']], array_intersect_key((array) $this->auditRecords('ownRecoveryEmailChange')[0]->details, ['requiredFlags' => 1]));
    }

    public function test_an_outdated_tag_is_refused(): void
    {
        $this->asSelfServiceUser('anna@example.org', userAcl: ['pw_reset' => 1]);
        $tag = (string) $this->getJson('/api/v2/me/recovery-email')->headers->get('ETag');
        DB::table('mailbox')->where('username', 'anna@example.org')->update(['attributes' => DB::raw("JSON_SET(`attributes`, '$.recovery_email', 'legacy@example.net')")]);

        $this->replace('new@example.net', headers: ['If-Match' => $tag])->assertStatus(412);
        $this->assertSame('legacy@example.net', $this->mailboxAttributes('anna@example.org')['recovery_email']);
    }

    public function test_a_wrong_current_password_is_refused_and_throttled(): void
    {
        $this->asSelfServiceUser('anna@example.org', userAcl: ['pw_reset' => 1]);

        $this->replace('new@example.net', 'wrong')->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/current-password-incorrect');
        $this->replace('new@example.net', 'wrong')->assertStatus(403);

        $this->assertSame([0.0, 0.5], $this->sleeper()->slept);
        $this->assertSame('currentPasswordIncorrect', $this->auditRecords('ownRecoveryEmailChange')[0]->failure_reason);
    }

    public function test_other_roles_external_sources_keys_and_restricted_sessions_are_refused(): void
    {
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        $this->signInAs('admin', Role::Admin);
        $this->getJson('/api/v2/me/recovery-email')->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->replace('x@example.net')->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->mailcow()->domainAdmin('reseller', ['example.org']);
        $this->signInAs('reseller', Role::DomainAdmin);
        $this->getJson('/api/v2/me/recovery-email')->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->mailbox('ldap@example.org', authsource: 'ldap');
        $this->signInAs('ldap@example.org', Role::MailboxUser);
        $this->getJson('/api/v2/me/recovery-email')->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/password-managed-externally');

        $this->asSelfServiceUser('bob@example.org', ['force_pw_update' => '1']);
        $this->getJson('/api/v2/me/recovery-email')->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/pending-action-required');

        $this->forgetCookies();
        $this->getJson('/api/v2/me/recovery-email', $this->keyHeader($this->apiKey('admin')))->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/session-required');
    }
}
