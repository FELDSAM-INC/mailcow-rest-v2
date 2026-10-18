<?php

namespace Tests\Feature\SecondFactors;

use App\Clients\Yubico\YubicoOtpVerifier;
use App\Clients\Yubico\YubicoVerificationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\Support\Yubico\FakeYubicoOtpVerifier;
use Tests\TestCase;

/**
 * T039 (feature 005 FR-032, US2 AS-4): Yubico OTP enrolment, verified with the given credentials
 * and stored as legacy set_tfa('yubi_otp') stores it, replacing an entry of the same key.
 */
class YubiOtpEnrolmentTest extends TestCase
{
    use SelfServiceTestHelpers;

    private const OTP = 'cccjgjgkhcbbirdrfdnlnghhfgrtnnlgedjlftrbdeut';

    private FakeYubicoOtpVerifier $yubico;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(YubicoOtpVerifier::class, $this->yubico = new FakeYubicoOtpVerifier);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return TestResponse<Response>
     */
    private function enrol(array $extra = []): TestResponse
    {
        return $this->postJson('/api/v2/me/second-factors', array_merge([
            'type' => 'yubiOtp',
            'name' => 'YubiKey',
            'clientId' => '12345',
            'secretKey' => 'c2VjcmV0a2V5',
            'otp' => self::OTP,
            'currentPassword' => MailcowFactory::PASSWORD,
        ], $extra), $this->csrf());
    }

    public function test_a_valid_otp_stores_the_entry_with_the_given_credentials(): void
    {
        $this->asSelfServiceUser();

        $response = $this->enrol()->assertCreated()->assertJsonPath('data.yubicoPublicId', 'cccjgjgkhcbb');

        $row = DB::table('tfa')->where('id', (int) $response->json('data.id'))->first();
        $this->assertNotNull($row);
        $this->assertSame(['yubi_otp', '12345:c2VjcmV0a2V5:cccjgjgkhcbb', 'YubiKey', 1], [$row->authmech, $row->secret, $row->key_id, (int) $row->active]);
        $this->assertSame([['clientId' => '12345', 'key' => 'c2VjcmV0a2V5', 'otp' => self::OTP]], $this->yubico->calls);
        $record = $this->onlyAuditRecord('ownSecondFactorEnrolment');
        $this->assertStringNotContainsString('c2VjcmV0a2V5', (string) json_encode($record));
        $this->assertStringNotContainsString(self::OTP, (string) json_encode($record));
    }

    public function test_enrolling_the_same_key_again_replaces_the_entry(): void
    {
        $this->asSelfServiceUser();
        $old = $this->mailcow()->tfaYubi('anna@example.org', '999', 'b2xk', 'cccjgjgkhcbb', 'Old key');
        $other = $this->mailcow()->tfaYubi('anna@example.org', '999', 'b2xk', 'vvvvvvvvvvvv', 'Other key');

        $id = (int) $this->enrol()->assertCreated()->json('data.id');

        $this->assertFalse(DB::table('tfa')->where('id', $old)->exists());
        $this->assertTrue(DB::table('tfa')->where('id', $other)->exists());
        $this->assertSame([$old], $this->onlyAuditRecord('ownSecondFactorEnrolment')->details['replacedIds']);
        $this->assertNotSame($old, $id);
    }

    public function test_a_rejected_otp_stores_nothing(): void
    {
        $this->yubico->result = YubicoVerificationResult::Invalid;
        $this->asSelfServiceUser();

        $this->enrol()->assertStatus(400)->assertJsonPath('type', '/api/v2/problems/second-factor-enrolment-failed');

        $this->assertSame(0, DB::table('tfa')->count());
    }

    public function test_an_unreachable_service_is_a_temporary_failure(): void
    {
        $this->yubico->result = YubicoVerificationResult::TemporaryFailure;
        $this->asSelfServiceUser();

        $this->enrol()
            ->assertStatus(503)
            ->assertHeader('Retry-After')
            ->assertJsonPath('type', '/api/v2/problems/temporary-verification-failure');

        $this->assertSame(0, DB::table('tfa')->count());
        $this->assertSame('verificationUnavailable', $this->onlyAuditRecord('ownSecondFactorEnrolment')->failure_reason);
    }

    public function test_invalid_fields_are_refused(): void
    {
        $this->asSelfServiceUser();

        $this->withoutRequestValidation()->enrol(['clientId' => 'abc', 'otp' => 'short'])
            ->assertStatus(422)
            ->assertJsonPath('errors.clientId.0.code', 'invalidFormat')
            ->assertJsonPath('errors.clientId.0.params.format', 'digits')
            ->assertJsonPath('errors.otp.0.code', 'invalidFormat')
            ->assertJsonPath('errors.otp.0.params.format', 'yubicoOtp');
        $this->assertSame([], $this->yubico->calls);
    }
}
