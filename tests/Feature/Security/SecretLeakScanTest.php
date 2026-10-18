<?php

namespace Tests\Feature\Security;

use App\Auth\Role;
use App\Clients\Yubico\YubicoOtpVerifier;
use App\Clients\Yubico\YubicoVerificationResult;
use App\Support\Totp\NoQrCodeProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use RobThree\Auth\TwoFactorAuth;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Auth\AuthRequests;
use Tests\Support\WebAuthn\VirtualAuthenticator;
use Tests\Support\Yubico\FakeYubicoOtpVerifier;
use Tests\TestCase;

/**
 * T097 (SC-009, FR-050): every audited flow runs once with known secrets (a password, TOTP codes,
 * a Yubico OTP, WebAuthn assertion and attestation bytes, an API key secret). None of them may
 * appear in an audit record, a log line or a response, apart from the key secret in the single
 * `createApiKey` response.
 */
class SecretLeakScanTest extends TestCase
{
    use AuthRequests;

    private const PASSWORD = 'S3cret-Pass!';

    private const TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

    private const HOST = 'https://mail.example.org';

    private TestHandler $logs;

    /** @var list<string> */
    private array $bodies = [];

    /** @var list<string> */
    private array $secrets = [];

    /** @var list<string> */
    private array $codes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $channel = Log::channel('stderr_json');
        $this->assertInstanceOf(\Illuminate\Log\Logger::class, $channel);
        $logger = $channel->getLogger();
        $this->assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($this->logs = new TestHandler);
    }

    /**
     * Keeps every response body for the scan.
     *
     * @param  TestResponse<Response>  $response
     * @return TestResponse<Response>
     */
    private function keep(TestResponse $response): TestResponse
    {
        $this->bodies[] = (string) $response->getContent();

        return $response;
    }

    private function totpCode(int $offset = 0): string
    {
        return (new TwoFactorAuth(new NoQrCodeProvider))->getCode(self::TOTP_SECRET, CarbonImmutable::now()->getTimestamp() + $offset);
    }

    public function test_no_secret_leaks_into_audit_records_logs_or_responses(): void
    {
        $yubico = new FakeYubicoOtpVerifier(YubicoVerificationResult::Invalid);
        $this->app->instance(YubicoOtpVerifier::class, $yubico);
        $authenticator = new VirtualAuthenticator;
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->mailbox('alice@example.org', self::PASSWORD);
        $this->mailcow()->admin('admin', self::PASSWORD);
        $totpId = $this->mailcow()->tfaTotp('alice@example.org', self::TOTP_SECRET);
        $this->mailcow()->tfaYubi('alice@example.org', '11111', base64_encode('yubico-key'), 'cccccccccccb');
        $webauthnKey = $authenticator->createCredential('mail.example.org', 'alice@example.org');
        $this->mailcow()->tfaWebAuthnCredential('alice@example.org', $webauthnKey);
        $passkey = $authenticator->createCredential('mail.example.org', 'admin');
        $this->mailcow()->fido2Credential('admin', $passkey);
        $this->secrets[] = self::PASSWORD;

        // A wrong password.
        $this->keep($this->passwordSignIn('alice@example.org', self::PASSWORD.'-wrong'))->assertUnauthorized();

        // A wrong TOTP code, then a Yubico OTP the service rejects.
        $this->keep($this->passwordSignIn('alice@example.org', self::PASSWORD))->assertCreated();
        $wrong = $this->totpCode() === '000000' ? '111111' : '000000';
        $this->codes[] = $wrong;
        $this->keep($this->verifySecondFactor(['type' => 'totp', 'methodId' => $totpId, 'code' => $wrong]))->assertUnauthorized();
        $this->keep($this->passwordSignIn('alice@example.org', self::PASSWORD))->assertCreated();
        $otp = 'cccccccccccbdteffujehknhfjbrjnlnldnhcujvddbi';
        $this->secrets[] = $otp;
        $this->keep($this->verifySecondFactor(['type' => 'yubiOtp', 'otp' => $otp]))->assertUnauthorized();

        // A WebAuthn second factor.
        $this->keep($this->passwordSignIn('alice@example.org', self::PASSWORD))->assertCreated();
        $options = $this->keep($this->postEmpty(self::HOST.'/api/v2/session/pending-sign-in/webauthn-challenge', ['X-CSRF-Token' => $this->csrfToken()]))->assertOk()->json('data.publicKey');
        $assertion = $authenticator->assert($webauthnKey, $options, self::HOST, uv: false);
        $this->secrets[] = $assertion['response']['signature'];
        $this->secrets[] = $assertion['response']['authenticatorData'];
        $this->keep($this->postJson(self::HOST.'/api/v2/session/pending-sign-in/second-factor', ['type' => 'webauthn', 'credential' => $assertion], ['X-CSRF-Token' => $this->csrfToken()]))->assertOk();

        // Preferences, sign-out, then a TOTP sign-in.
        $this->keep($this->patchMerge('/api/v2/me/preferences', ['theme' => 'dark'], ['X-CSRF-Token' => $this->csrfToken()]))->assertOk();
        $this->keep($this->json('DELETE', '/api/v2/session', [], ['X-CSRF-Token' => $this->csrfToken()]))->assertNoContent();
        $this->travel(31)->seconds();
        $this->keep($this->passwordSignIn('alice@example.org', self::PASSWORD))->assertCreated();
        $code = $this->totpCode();
        $this->codes[] = $code;
        $this->keep($this->verifySecondFactor(['type' => 'totp', 'methodId' => $totpId, 'code' => $code]))->assertOk();
        $this->forgetCookies();

        // A passkey sign-in, then a passkey registration, rename and removal.
        $options = $this->keep($this->postEmpty(self::HOST.'/api/v2/session/passkey-challenge', ['X-CSRF-Token' => $this->csrfToken()]))->assertOk()->json('data.publicKey');
        $passkeyAssertion = $authenticator->assert($passkey, $options, self::HOST);
        $this->secrets[] = $passkeyAssertion['response']['signature'];
        $this->keep($this->postJson(self::HOST.'/api/v2/session/passkey-assertion', ['credential' => $passkeyAssertion], ['X-CSRF-Token' => $this->csrfToken()]))->assertCreated();
        $creation = $this->keep($this->postEmpty(self::HOST.'/api/v2/me/passkeys/registration-options', ['X-CSRF-Token' => $this->csrfToken()]))->assertOk()->json('data.publicKey');
        $attestation = $authenticator->attest($creation, self::HOST);
        $this->secrets[] = $attestation['response']['attestationObject'];
        $registered = $this->keep($this->postJson(self::HOST.'/api/v2/me/passkeys', ['credential' => $attestation, 'name' => 'Laptop'], ['X-CSRF-Token' => $this->csrfToken()]))->assertCreated();
        $passkeyPath = '/api/v2/me/passkeys/'.$registered->json('data.id');
        $this->keep($this->patchMerge($passkeyPath, ['name' => 'Work laptop'], ['X-CSRF-Token' => $this->csrfToken()]))->assertOk();
        $this->keep($this->json('DELETE', $passkeyPath, [], ['X-CSRF-Token' => $this->csrfToken()]))->assertNoContent();

        // An API key: created (the only response with the secret), used, revoked.
        $created = $this->postJson('/api/v2/api-keys', ['name' => 'Billing', 'ownerUsername' => 'admin', 'accessLevel' => 'readOnly'], ['X-CSRF-Token' => $this->csrfToken()])->assertCreated();
        $secret = (string) $created->json('data.secret');
        $this->secrets[] = $secret;
        $this->keep($this->getJson('/api/v2/me', ['X-API-Key' => $secret]))->assertOk();
        $this->keep($this->getJson('/api/v2/api-keys/'.$created->json('data.id')))->assertOk();
        $this->keep($this->postEmpty('/api/v2/api-keys/'.$created->json('data.id').'/revocation', ['X-CSRF-Token' => $this->csrfToken()]))->assertOk();
        $this->keep($this->getJson('/api/v2/me', ['X-API-Key' => $secret]))->assertUnauthorized();
        $this->keep($this->getJson('/api/v2/audit-events?limit=1000'))->assertOk();

        // Every audited event type was written.
        $types = DB::table('restv2_audit_events')->distinct()->pluck('event_type')->all();
        $this->assertEqualsCanonicalizing(
            ['signIn', 'signOut', 'preferencesUpdate', 'passkeyRegistration', 'passkeyRename', 'passkeyRemoval', 'apiKeyCreation', 'apiKeyRevocation'],
            $types,
        );

        $audit = (string) json_encode(DB::table('restv2_audit_events')->get(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $logs = implode("\n", array_map(fn ($record): string => (string) json_encode($record->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $this->logs->getRecords()));
        $this->assertNotSame('', $logs, 'the log capture works');

        foreach (['audit records' => $audit, 'log lines' => $logs, 'responses' => implode("\n", $this->bodies)] as $where => $haystack) {
            foreach ($this->secrets as $value) {
                $this->assertStringNotContainsString($value, $haystack, "A secret leaked into the {$where}.");
            }

            foreach ($this->codes as $value) {
                $this->assertDoesNotMatchRegularExpression('/(?<![0-9A-Za-z])'.$value.'(?![0-9A-Za-z])/', $haystack, "A one-time code leaked into the {$where}.");
            }
        }

        // The key secret only in the creation response; even its non-public part nowhere else.
        $this->assertStringContainsString($secret, (string) $created->getContent());
        $this->assertStringNotContainsString(substr($secret, 12), $audit.$logs.implode("\n", $this->bodies));
        $this->assertSame(Role::Admin->value, $created->json('data.owner.role'));
    }
}
