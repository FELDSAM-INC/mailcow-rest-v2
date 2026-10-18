<?php

namespace Tests\Feature\OwnAccount;

use App\Auth\Role;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T031 (feature 005 FR-014): getOwnPasswordPolicy gives every role the rules of its new password,
 * with the values and the version tag of feature 003's getPasswordPolicy.
 */
class OwnPasswordPolicyTest extends TestCase
{
    use ApiKeys;
    use SelfServiceTestHelpers;

    public function test_every_role_reads_the_policy_with_the_shared_tag(): void
    {
        $this->passwordPolicy(10, chars: true, numbers: true);
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        $this->signInAs('admin', Role::Admin);
        $shared = $this->getJson('/api/v2/password-policy')->assertOk();

        $own = $this->getJson('/api/v2/me/password-policy')
            ->assertOk()
            ->assertJsonPath('data.minLength', 10)
            ->assertJsonPath('data.requireLetters', true)
            ->assertJsonPath('data.requireDigits', true)
            ->assertJsonPath('data.requireSpecialCharacters', false);
        $this->assertSame($shared->headers->get('ETag'), $own->headers->get('ETag'));

        $this->mailcow()->domainAdmin('reseller', ['example.org']);
        $this->signInAs('reseller', Role::DomainAdmin);
        $this->getJson('/api/v2/me/password-policy')->assertOk()->assertHeader('ETag', (string) $shared->headers->get('ETag'));

        $this->asSelfServiceUser();
        $this->getJson('/api/v2/me/password-policy')->assertOk()->assertHeader('ETag', (string) $shared->headers->get('ETag'));
    }

    public function test_a_session_restricted_by_a_forced_password_change_may_read_it(): void
    {
        $this->asSelfServiceUser('bob@example.org', ['force_pw_update' => '1', 'force_tfa' => '1']);

        $this->getJson('/api/v2/me/password-policy')->assertOk();
    }

    public function test_a_session_restricted_only_by_second_factor_setup_may_not(): void
    {
        $this->asSelfServiceUser('bob@example.org', ['force_tfa' => '1']);

        $this->getJson('/api/v2/me/password-policy')
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/pending-action-required');
    }

    public function test_api_keys_are_refused(): void
    {
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);

        $this->getJson('/api/v2/me/password-policy', $this->keyHeader($this->apiKey('admin', 'readOnly')))
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/session-required');
    }
}
