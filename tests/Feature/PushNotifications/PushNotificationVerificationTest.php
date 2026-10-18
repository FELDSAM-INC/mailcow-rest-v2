<?php

namespace Tests\Feature\PushNotifications;

use App\Clients\Pushover\PushoverClient;
use App\Clients\Pushover\PushoverVerdict;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Fakes\FakePushoverClient;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T090 (feature 005 FR-057, US7 AS-5): verifyPushNotificationSettings asks Pushover about the stored
 * credentials, without sending a notification.
 */
class PushNotificationVerificationTest extends TestCase
{
    use ApiKeys;
    use SelfServiceTestHelpers;

    private const URL = '/api/v2/mailboxes/anna@example.org/push-notifications/verification';

    private FakePushoverClient $pushover;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PushoverClient::class, $this->pushover = new FakePushoverClient);
    }

    public function test_the_verdict_of_pushover(): void
    {
        $this->asSelfServiceUser();
        $this->mailcow()->pushoverSettings('anna@example.org', str_repeat('k', 30), str_repeat('t', 30));

        $this->postEmpty(self::URL, $this->csrf())->assertOk()->assertExactJson(['data' => ['accepted' => true, 'reason' => null]]);
        $this->assertSame([['token' => str_repeat('t', 30), 'user' => str_repeat('k', 30)]], $this->pushover->calls);
        $this->assertTrue($this->onlyAuditRecord('pushNotificationVerification')->details['accepted']);

        $this->pushover->verdict = PushoverVerdict::rejected('user key is invalid');
        $this->postEmpty(self::URL, $this->csrf())->assertOk()->assertExactJson(['data' => ['accepted' => false, 'reason' => 'user key is invalid']]);

        $this->pushover->verdict = PushoverVerdict::unavailable();
        $this->postEmpty(self::URL, $this->csrf())->assertStatus(503)->assertJsonPath('dependency', 'pushover');
    }

    public function test_unconfigured_settings_and_read_only_keys_are_refused(): void
    {
        $this->asSelfServiceUser();
        $this->postEmpty(self::URL, $this->csrf())->assertStatus(409)->assertJsonPath('type', '/api/v2/problems/push-notifications-not-configured');
        $this->assertSame('notConfigured', $this->onlyAuditRecord('pushNotificationVerification')->failure_reason);

        $this->mailcow()->admin('admin', 'Passw0rd!');
        $this->forgetCookies();
        $this->postEmpty(self::URL, $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
        $this->assertSame([], $this->pushover->calls);
    }
}
