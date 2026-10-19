<?php

namespace Tests\Feature\Settings;

use Tests\Support\Redis\UnavailableSharedStore;
use Tests\Support\Settings\SettingsTestHelpers;
use Tests\TestCase;

/**
 * Feature 013 T040 (FR-036 to FR-038, SC-011, AS-2.1, AS-2.2, AS-2.6): administrators change the
 * password policy in legacy's `PASSWD_POLICY` hash (all five fields, as legacy's `hMSet` writes
 * them), with the version tag of feature 003's read; the next password set follows it.
 */
class PasswordPolicyUpdateTest extends TestCase
{
    use SettingsTestHelpers;

    /**
     * @return array<string, string>
     */
    private function stored(): array
    {
        $values = $this->redis()->hGetAll('PASSWD_POLICY');

        return is_array($values) ? $values : [];
    }

    public function test_a_change_writes_all_five_fields_and_returns_the_policy_with_003_s_tag(): void
    {
        $this->asAdmin();

        $response = $this->send('PATCH', '/api/v2/password-policy', ['minLength' => 10, 'requireDigits' => true, 'requireMixedCase' => true])->assertOk();

        $this->assertSame(['minLength' => 10, 'requireLetters' => false, 'requireDigits' => true, 'requireSpecialCharacters' => false, 'requireMixedCase' => true], array_diff_key((array) $response->json('data'), ['etag' => true]));
        $this->assertSame(['length' => '10', 'chars' => '0', 'special_chars' => '0', 'lowerupper' => '1', 'numbers' => '1'], $this->stored());
        $read = $this->getJson('/api/v2/password-policy')->assertOk();
        $this->assertSame($response->headers->get('ETag'), $read->headers->get('ETag'));
        $this->assertSame($response->json('data.etag'), $read->json('data.etag'));
    }

    public function test_the_next_password_set_follows_the_new_policy(): void
    {
        $this->asAdmin();
        $this->mailcow()->domain('example.org');
        $this->send('PATCH', '/api/v2/password-policy', ['minLength' => 10, 'requireDigits' => true, 'requireMixedCase' => true])->assertOk();

        $errors = $this->send('POST', '/api/v2/mailboxes', ['localPart' => 'anna', 'domain' => 'example.org', 'password' => 'abcdefghij'])->assertStatus(422)->json('errors.password');

        $this->assertSame(['missingCharacterClass', 'missingCharacterClass'], array_column((array) $errors, 'code'));
        $this->send('POST', '/api/v2/mailboxes', ['localPart' => 'anna', 'domain' => 'example.org', 'password' => 'Abcdefghi1'])->assertCreated();
    }

    public function test_an_unsaved_policy_is_merged_from_the_legacy_defaults(): void
    {
        $this->asAdmin();

        $this->send('PATCH', '/api/v2/password-policy', ['requireLetters' => true])->assertOk()->assertJsonPath('data.minLength', 6);

        $this->assertSame(['length' => '6', 'chars' => '1', 'special_chars' => '0', 'lowerupper' => '0', 'numbers' => '0'], $this->stored());
    }

    public function test_an_invalid_length_is_out_of_range_and_changes_nothing(): void
    {
        $this->asAdmin();
        $this->withoutRequestValidation();
        $this->redis()->hMSet('PASSWD_POLICY', ['length' => '8']);

        $this->send('PATCH', '/api/v2/password-policy', ['minLength' => 2])->assertStatus(422)
            ->assertJsonPath('errors.minLength.0.code', 'outOfRange')
            ->assertJsonPath('errors.minLength.0.params', ['min' => 3, 'max' => 64]);
        $this->send('PATCH', '/api/v2/password-policy', ['minLength' => 65])->assertStatus(422)->assertJsonPath('errors.minLength.0.code', 'outOfRange');
        $this->send('PATCH', '/api/v2/password-policy', ['minLength' => '10'])->assertStatus(422)->assertJsonPath('errors.minLength.0.code', 'invalidType');
        $this->send('PATCH', '/api/v2/password-policy', ['requireDigits' => 1])->assertStatus(422);
        $this->send('PATCH', '/api/v2/password-policy', [])->assertStatus(422);

        $this->assertSame(['length' => '8'], $this->stored());
    }

    public function test_two_administrators_with_the_same_tag_the_second_is_refused(): void
    {
        $this->asAdmin();
        $etag = (string) $this->getJson('/api/v2/password-policy')->headers->get('ETag');

        $this->send('PATCH', '/api/v2/password-policy', ['minLength' => 12], ['If-Match' => $etag])->assertOk();
        $this->send('PATCH', '/api/v2/password-policy', ['minLength' => 8], ['If-Match' => $etag])->assertStatus(412);

        $this->assertSame('12', $this->stored()['length']);
    }

    public function test_an_unavailable_shared_store_is_a_temporary_failure(): void
    {
        $this->asAdmin();
        UnavailableSharedStore::bind($this->app);

        $this->send('PATCH', '/api/v2/password-policy', ['minLength' => 12])->assertStatus(503)->assertJsonPath('type', '/api/v2/problems/temporary-failure');
    }

    public function test_a_change_is_audited_with_each_changed_rule(): void
    {
        $this->asAdmin();

        $this->send('PATCH', '/api/v2/password-policy', ['minLength' => 10, 'requireDigits' => true, 'requireLetters' => false])->assertOk();
        $this->send('PATCH', '/api/v2/password-policy', ['minLength' => 10])->assertOk();

        $records = $this->successesOf('passwordPolicyUpdate');
        $this->assertCount(1, $records, 'A change that changes nothing is not audited.');
        $this->assertSame(['minLength' => ['old' => 6, 'new' => 10], 'requireDigits' => ['old' => false, 'new' => true]], $records[0]->details['changes']);
        $this->assertSame('passwordPolicy', $records[0]->target_type);
    }

    public function test_domain_administrators_are_forbidden_but_still_read(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asDomainAdmin(['example.org']);

        $this->send('PATCH', '/api/v2/password-policy', ['minLength' => 10])->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->getJson('/api/v2/password-policy')->assertOk();
        $this->assertSame([], $this->stored());
    }
}
