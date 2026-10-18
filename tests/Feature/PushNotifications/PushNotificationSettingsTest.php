<?php

namespace Tests\Feature\PushNotifications;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T089 (feature 005 FR-054–FR-056, US7 AS-5–AS-8): a mailbox's Pushover settings, stored as legacy
 * pushover('edit') stores them, with write-only credentials.
 */
class PushNotificationSettingsTest extends TestCase
{
    use SelfServiceTestHelpers;

    private const URL = '/api/v2/mailboxes/anna@example.org/push-notifications';

    private const TOKEN = 'atokenatokenatokenatokenatoken';

    private const KEY = 'ukeyukeyukeyukeyukeyukeyukeyuk';

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function replace(array $body): TestResponse
    {
        return $this->putJson(self::URL, $body, $this->csrf());
    }

    private function row(): object
    {
        $row = DB::table('pushover')->where('username', 'anna@example.org')->first();
        $this->assertNotNull($row);

        return $row;
    }

    public function test_unconfigured_settings_show_the_defaults(): void
    {
        $this->asSelfServiceUser();

        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.configured', false)->assertJsonPath('data.active', true)
            ->assertJsonPath('data.tokenSet', false)->assertJsonPath('data.userKeySet', false)->assertJsonPath('data.sound', 'pushover')
            ->assertJsonPath('data.senders', [])->assertJsonPath('data.title', '');
    }

    public function test_settings_are_stored_as_legacy_and_credentials_are_never_returned(): void
    {
        $this->asSelfServiceUser();

        $response = $this->replace(['token' => self::TOKEN, 'userKey' => self::KEY, 'title' => 'New: {SUBJECT}', 'text' => 'From {SENDER}', 'senders' => ['john.doe@gmail.com', 'boss@example.org'], 'sound' => 'bike'])->assertOk();

        $response->assertJsonPath('data.configured', true)->assertJsonPath('data.tokenSet', true)->assertJsonPath('data.userKeySet', true)
            ->assertJsonPath('data.senders', ['johndoe@gmail.com', 'boss@example.org'])->assertJsonPath('data.sound', 'bike');
        $this->assertStringNotContainsString(self::TOKEN, (string) $response->getContent());
        $this->assertStringNotContainsString(self::KEY, (string) $response->getContent());
        $row = $this->row();
        $this->assertSame([self::KEY, self::TOKEN, 'New: {SUBJECT}', 'From {SENDER}', 'johndoe@gmail.com,boss@example.org', '', 1], [$row->key, $row->token, $row->title, $row->text, $row->senders, $row->senders_regex, (int) $row->active]);
        $this->assertSame(['evaluate_x_prio' => '0', 'only_x_prio' => '0', 'sound' => 'bike'], json_decode((string) $row->attributes, true));
        $this->assertStringNotContainsString(self::TOKEN, (string) $this->getJson(self::URL)->getContent());

        $record = $this->onlyAuditRecord('pushNotificationSettingsUpdate');
        $this->assertSame('set', $record->details['changes']['token']);
        $this->assertSame(['changed' => true, 'oldSize' => 0, 'newSize' => 14], $record->details['changes']['title']);
        $this->assertTrue($record->details['created']);
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($record));
    }

    public function test_omitted_credentials_are_kept_and_first_saves_need_them(): void
    {
        $this->asSelfServiceUser();

        $this->replace(['title' => 'x'])->assertStatus(422)->assertJsonPath('errors.token.0.code', 'secretRequired')->assertJsonPath('errors.userKey.0.code', 'secretRequired')->assertJsonPath('errors.token.0.params.reason', 'noStoredSecret');

        $this->replace(['token' => self::TOKEN, 'userKey' => self::KEY])->assertOk();
        $this->replace(['title' => 'Later'])->assertOk()->assertJsonPath('data.tokenSet', true);
        $this->assertSame([self::TOKEN, self::KEY], [$this->row()->token, $this->row()->key]);
    }

    public function test_invalid_values_are_refused(): void
    {
        $this->asSelfServiceUser();
        $this->withoutRequestValidation();

        $this->replace(['token' => str_repeat('a', 29), 'userKey' => self::KEY])->assertStatus(422)->assertJsonPath('errors.token.0.code', 'invalidFormat')->assertJsonPath('errors.token.0.params.format', 'pushoverCredential');
        $errors = $this->replace(['token' => self::TOKEN, 'userKey' => self::KEY, 'senders' => ['not-an-address']])->assertStatus(422)->json('errors');
        $this->assertSame(['code' => 'invalidFormat', 'format' => 'email'], ['code' => $errors['senders.0'][0]['code'], 'format' => $errors['senders.0'][0]['params']['format']]);
        $this->replace(['token' => self::TOKEN, 'userKey' => self::KEY, 'senders' => array_fill(0, 101, 'a@example.org')])->assertStatus(422)->assertJsonPath('errors.senders.0.code', 'tooMany');
        $this->replace(['token' => self::TOKEN, 'userKey' => self::KEY, 'senderPattern' => '/(unclosed/'])->assertStatus(422)->assertJsonPath('errors.senderPattern.0.code', 'invalidRegex');
        $this->replace(['token' => self::TOKEN, 'userKey' => self::KEY, 'sound' => 'a b'])->assertStatus(422)->assertJsonPath('errors.sound.0.code', 'invalidFormat')->assertJsonPath('errors.sound.0.params.format', 'soundName');
        $this->assertFalse(DB::table('pushover')->exists());
    }

    public function test_removal_and_the_tag(): void
    {
        $this->asSelfServiceUser();
        $this->mailcow()->pushoverSettings('anna@example.org', self::KEY, self::TOKEN);
        $before = (string) $this->getJson(self::URL)->json('data.etag');

        DB::table('pushover')->where('username', 'anna@example.org')->update(['token' => str_repeat('b', 30)]);
        $after = (string) $this->getJson(self::URL)->json('data.etag');
        $this->assertNotSame($before, $after);
        $this->assertStringNotContainsString(hash('sha256', str_repeat('b', 30)), $after);

        $this->deleteJson(self::URL, [], $this->csrf())->assertNoContent();
        $this->assertFalse(DB::table('pushover')->where('username', 'anna@example.org')->exists());
        $this->assertTrue($this->onlyAuditRecord('pushNotificationSettingsDeletion')->details['removed']);
    }

    public function test_a_domain_administrator_needs_the_own_flag(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->asDomainAdmin(['example.org'], 'reseller', ['pushover' => 0]);

        $this->getJson(self::URL)->assertOk();
        $this->replace(['token' => self::TOKEN, 'userKey' => self::KEY])->assertStatus(403)->assertJsonPath('requiredFlags', ['pushover']);
    }
}
