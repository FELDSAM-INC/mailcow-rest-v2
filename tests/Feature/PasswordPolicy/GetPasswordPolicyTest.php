<?php

namespace Tests\Feature\PasswordPolicy;

use App\Support\Http\Base64Url;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\TestCase;

/**
 * Feature 003 T045 (FR-063, AS-11): getPasswordPolicy returns the rules legacy keeps in Redis as
 * structured values with feature 013's version tag, read on every request.
 */
class GetPasswordPolicyTest extends TestCase
{
    use ApiKeys;
    use MailboxTestHelpers;

    public function test_the_stored_rules_and_their_tag(): void
    {
        $this->redis()->hMSet('PASSWD_POLICY', ['length' => '10', 'numbers' => '1']);
        $this->asAdmin();

        $response = $this->getJson('/api/v2/password-policy')->assertOk();

        $tag = '"'.Base64Url::encode(hash('sha256', 'length=10;chars=0;special_chars=0;lowerupper=0;numbers=1', true)).'"';
        $this->assertSame(['minLength' => 10, 'requireLetters' => false, 'requireDigits' => true, 'requireSpecialCharacters' => false, 'requireMixedCase' => false, 'etag' => $tag], $response->json('data'));
        $response->assertHeader('ETag', $tag);

        $this->redis()->hSet('PASSWD_POLICY', 'chars', '1');
        $this->assertNotSame($tag, $this->getJson('/api/v2/password-policy')->assertOk()->headers->get('ETag'));
    }

    public function test_an_unsaved_policy_has_the_legacy_defaults(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asDomainAdmin(['example.org']);

        $this->getJson('/api/v2/password-policy')->assertOk()
            ->assertJsonPath('data.minLength', 6)
            ->assertJsonPath('data.requireDigits', false);
    }

    public function test_keys_may_read_and_mailbox_users_are_refused(): void
    {
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        $this->getJson('/api/v2/password-policy', $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertOk();

        $this->asMailboxUser('anna@example.org');
        $this->getJson('/api/v2/password-policy')->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');
    }

    public function test_an_unavailable_shared_store_is_a_temporary_failure(): void
    {
        $this->asAdmin();
        $this->breakSharedStore();

        $this->getJson('/api/v2/password-policy')->assertStatus(503)->assertJsonPath('type', '/api/v2/problems/temporary-failure');
    }
}
