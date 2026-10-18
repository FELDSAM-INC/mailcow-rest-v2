<?php

namespace Tests\Feature\Security;

use App\Clients\Pushover\PushoverClient;
use App\Clients\Yubico\YubicoOtpVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Fakes\FakePushoverClient;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\Support\Totp\TestTotp;
use Tests\Support\Yubico\FakeYubicoOtpVerifier;
use Tests\TestCase;

/**
 * T116 (feature 005 FR-076, SC-006): a scripted pass over the self-service operations with known
 * secrets (passwords, the TOTP secret after the start, codes, the Yubico key and OTP, a reset token,
 * app passwords, the Pushover token and user key), including refusals. None of them appears in a
 * response body or header after its creation (the TOTP start and a generated app password are the
 * documented exceptions), in a log line, in an audit record, or as a plain hash in a version tag.
 */
class SelfServiceSecretLeakTest extends TestCase
{
    use SelfServiceTestHelpers;

    private const NEW_PASSWORD = 'Leak-N3w!Passw0rd';

    private const RESET_PASSWORD = 'Leak-R3set!Passw0rd';

    private const RECOVERY_PASSWORD = 'Wrong-Curr3nt!Passw0rd';

    private const APP_PASSWORD = 'Leak-App!Passw0rd-42';

    private const YUBICO_KEY = 'bGVha3llbGVha3lr';

    private const YUBICO_OTP = 'cccjgjgkhcbbirdrfdnlnghhfgrtnnlgedjlftrbdeut';

    private const PUSHOVER_TOKEN = 'leaktokenleaktokenleaktokenlea';

    private const PUSHOVER_KEY = 'leakuserkeyleakuserkeyleakuser';

    private const RESET_TOKEN = '7C1E9A-2B4D6F-8A0C1E-3F5B7D-9E1A2C';

    private TestHandler $logs;

    /** @var list<string> */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();

        $channel = Log::channel('stderr_json');
        $this->assertInstanceOf(\Illuminate\Log\Logger::class, $channel);
        $logger = $channel->getLogger();
        $this->assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($this->logs = new TestHandler);
        $this->app->instance(PushoverClient::class, new FakePushoverClient);
        $this->app->instance(YubicoOtpVerifier::class, new FakeYubicoOtpVerifier);
    }

    /**
     * Keeps a response's body and headers for the scan.
     *
     * @param  TestResponse<Response>  $response
     * @return TestResponse<Response>
     */
    private function keep(TestResponse $response): TestResponse
    {
        $this->responses[] = (string) $response->getContent().json_encode($response->headers->all());

        return $response;
    }

    public function test_no_secret_leaves_where_it_belongs_even_with_invalid_requests(): void
    {
        // Refusals of invalid values must not echo them either.
        $this->withoutRequestValidation();
        $base = '/api/v2/mailboxes/anna@example.org';
        $this->asSelfServiceUser();

        // TOTP: the start shows the secret once (documented); nothing after it may.
        $secret = (string) $this->postEmpty('/api/v2/me/second-factors/totp-enrolment', $this->csrf())->assertOk()->json('data.secret');
        $code = TestTotp::code($secret);
        $this->keep($this->postJson('/api/v2/me/second-factors', ['type' => 'totp', 'name' => 'Phone', 'code' => '000000', 'currentPassword' => self::RECOVERY_PASSWORD], $this->csrf()));
        $this->keep($this->postJson('/api/v2/me/second-factors', ['type' => 'totp', 'name' => 'Phone', 'code' => $code, 'currentPassword' => MailcowFactory::PASSWORD], $this->csrf()))->assertCreated();
        $this->keep($this->postJson('/api/v2/me/second-factors', ['type' => 'yubiOtp', 'name' => 'Key', 'clientId' => '12345', 'secretKey' => self::YUBICO_KEY, 'otp' => self::YUBICO_OTP, 'currentPassword' => MailcowFactory::PASSWORD], $this->csrf()))->assertCreated();
        $this->keep($this->getJson('/api/v2/me/second-factors'))->assertOk();

        // Recovery address and password, with a wrong current password first.
        $this->keep($this->putJson('/api/v2/me/recovery-email', ['address' => 'anna.private@example.net', 'currentPassword' => self::RECOVERY_PASSWORD], $this->csrf()))->assertStatus(403);
        $this->keep($this->putJson('/api/v2/me/recovery-email', ['address' => 'anna.private@example.net', 'currentPassword' => MailcowFactory::PASSWORD], $this->csrf()))->assertOk();

        // App passwords: a given one, a generated one (shown once, documented), a change.
        $given = $this->keep($this->postJson($base.'/app-passwords', ['name' => 'Given', 'password' => self::APP_PASSWORD], $this->csrf()))->assertCreated();
        $this->assertNull($given->json('data.generatedPassword'));
        $generated = $this->postJson($base.'/app-passwords', ['name' => 'Generated'], $this->csrf())->assertCreated();
        $generatedPassword = (string) $generated->json('data.generatedPassword');
        $this->keep($this->patchMerge($base.'/app-passwords/'.$given->json('data.id'), ['password' => self::APP_PASSWORD.'x'], $this->csrf()))->assertOk();
        $this->keep($this->postJson($base.'/app-passwords', ['name' => 'Short', 'password' => 'x'], $this->csrf()))->assertStatus(422);
        $this->keep($this->getJson($base.'/app-passwords'))->assertOk();
        $this->keep($this->getJson($base.'/app-passwords/'.$given->json('data.id')))->assertOk();

        // Push notifications.
        $this->keep($this->putJson($base.'/push-notifications', ['token' => self::PUSHOVER_TOKEN, 'userKey' => self::PUSHOVER_KEY], $this->csrf()))->assertOk();
        $this->keep($this->putJson($base.'/push-notifications', ['token' => self::PUSHOVER_TOKEN.'x', 'userKey' => self::PUSHOVER_KEY], $this->csrf()))->assertStatus(422);
        $this->keep($this->getJson($base.'/push-notifications'))->assertOk();
        $this->keep($this->postEmpty($base.'/push-notifications/verification', $this->csrf()))->assertOk();

        // The own password, with a wrong current password first.
        $this->keep($this->putJson('/api/v2/me/password', ['currentPassword' => self::RECOVERY_PASSWORD, 'newPassword' => self::NEW_PASSWORD], $this->csrf()))->assertStatus(403);
        $this->keep($this->putJson('/api/v2/me/password', ['currentPassword' => MailcowFactory::PASSWORD, 'newPassword' => self::NEW_PASSWORD], $this->csrf()))->assertOk();
        $this->keep($this->getJson('/api/v2/me/mailbox'))->assertOk();

        // The reset flow with a known token.
        $this->forgetCookies();
        $this->mailcow()->resetToken('anna@example.org', self::RESET_TOKEN);
        $csrf = ['X-CSRF-Token' => (string) $this->getJson('/api/v2/csrf-token')->json('data.token')];
        $this->keep($this->postJson('/api/v2/password-resets/token-check', ['token' => self::RESET_TOKEN], $csrf));
        $this->keep($this->postJson('/api/v2/password-resets/completion', ['token' => self::RESET_TOKEN, 'newPassword' => 'short'], $csrf));
        $this->keep($this->postJson('/api/v2/password-resets/completion', ['token' => self::RESET_TOKEN, 'newPassword' => self::RESET_PASSWORD, 'secondFactor' => ['type' => 'totp', 'code' => TestTotp::code($secret, 1)]], $csrf));

        $secrets = [
            MailcowFactory::PASSWORD, self::NEW_PASSWORD, self::RESET_PASSWORD, self::RECOVERY_PASSWORD, self::APP_PASSWORD,
            // Six-digit codes are left out: digit runs occur in tags and times by chance.
            $generatedPassword, $secret, self::YUBICO_KEY, self::YUBICO_OTP, self::PUSHOVER_TOKEN, self::PUSHOVER_KEY,
            self::RESET_TOKEN,
        ];
        $logged = implode("\n", array_map(fn ($record): string => (string) json_encode($record->toArray()), $this->logs->getRecords()));
        $audit = (string) json_encode(DB::table('restv2_audit_events')->get());

        foreach ($secrets as $value) {
            $hashes = [md5($value), sha1($value), hash('sha256', $value)];

            foreach ($this->responses as $index => $text) {
                $this->assertStringNotContainsString($value, $text, "Response {$index} contains a secret");

                foreach ($hashes as $hash) {
                    $this->assertStringNotContainsString($hash, $text, "Response {$index} contains a plain hash of a secret");
                }
            }

            $this->assertStringNotContainsString($value, $logged);
            $this->assertStringNotContainsString($value, $audit);
        }

        $this->assertNotEmpty(DB::table('restv2_audit_events')->get());
    }
}
