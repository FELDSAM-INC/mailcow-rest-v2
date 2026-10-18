<?php

namespace Tests\Feature\SecondFactors;

use App\Auth\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\Support\Totp\TestTotp;
use Tests\TestCase;

/**
 * T037 (feature 005 FR-030, US2 AS-1, AS-2): startOwnTotpEnrolment and the TOTP confirmation of
 * enrolOwnSecondFactor, stored as legacy set_tfa('totp') stores it.
 */
class TotpEnrolmentTest extends TestCase
{
    use SelfServiceTestHelpers;

    /**
     * @return TestResponse<Response>
     */
    private function start(): TestResponse
    {
        return $this->postEmpty('/api/v2/me/second-factors/totp-enrolment', $this->csrf());
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return TestResponse<Response>
     */
    private function confirm(string $code, array $extra = []): TestResponse
    {
        return $this->postJson('/api/v2/me/second-factors', array_merge(['type' => 'totp', 'name' => '  Phone  ', 'code' => $code, 'currentPassword' => MailcowFactory::PASSWORD], $extra), $this->csrf());
    }

    private function wrongCode(string $secret): string
    {
        $valid = [TestTotp::code($secret, -1), TestTotp::code($secret), TestTotp::code($secret, 1)];

        for ($candidate = 0; ; $candidate++) {
            $code = str_pad((string) $candidate, 6, '0', STR_PAD_LEFT);

            if (! in_array($code, $valid, true)) {
                return $code;
            }
        }
    }

    public function test_the_start_returns_the_secret_the_provisioning_address_and_a_qr_code_once(): void
    {
        $this->freezeTime();
        $this->asSelfServiceUser();

        $response = $this->start()->assertOk();

        $secret = (string) $response->json('data.secret');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $uri = (string) $response->json('data.provisioningUri');
        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('anna%40example.org', $uri);
        $this->assertStringContainsString('secret='.$secret, $uri);
        $this->assertStringContainsString('issuer=mailcow%20UI', $uri);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', (string) $response->json('data.qrCode'));
        $response->assertJsonPath('data.issuer', 'mailcow UI')
            ->assertJsonPath('data.digits', 6)
            ->assertJsonPath('data.period', 30)
            ->assertJsonPath('data.algorithm', 'SHA1')
            ->assertJsonPath('data.expiresAt', CarbonImmutable::now('UTC')->addSeconds(600)->format('Y-m-d\TH:i:s\Z'))
            ->assertHeader('Cache-Control');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame([], $this->auditRecords('ownSecondFactorEnrolment'));
    }

    public function test_the_issuer_follows_the_server_setting(): void
    {
        config(['restv2.vars_overrides.OTP_LABEL' => 'FeldHost™ Mailhosting UI']);
        $this->asSelfServiceUser();

        $response = $this->start()->assertOk()->assertJsonPath('data.issuer', 'FeldHost™ Mailhosting UI');
        $this->assertStringContainsString('issuer=FeldHost%E2%84%A2%20Mailhosting%20UI', (string) $response->json('data.provisioningUri'));
    }

    public function test_a_valid_code_stores_the_entry_as_legacy_and_cannot_sign_in_again(): void
    {
        $this->asSelfServiceUser();
        $secret = (string) $this->start()->json('data.secret');
        $code = TestTotp::code($secret);

        $response = $this->confirm($code)->assertCreated();

        $id = (int) $response->json('data.id');
        $response->assertHeader('Location', '/api/v2/me/second-factors/'.$id)
            ->assertJsonPath('data', ['id' => $id, 'type' => 'totp', 'name' => 'Phone', 'active' => true, 'usable' => true, 'yubicoPublicId' => null, 'usedForCurrentSignIn' => false]);
        $row = DB::table('tfa')->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertSame(['anna@example.org', 'Phone', 'totp', $secret, 1], [$row->username, $row->key_id, $row->authmech, $row->secret, (int) $row->active]);

        // The confirming code counts as used for 001's replay protection.
        $this->forgetCookies();
        $this->passwordSignIn('anna@example.org')->assertCreated()->assertJsonPath('data.state', 'pendingSecondFactor');
        $this->verifySecondFactor(['type' => 'totp', 'methodId' => $id, 'code' => $code])->assertStatus(401);

        $record = $this->onlyAuditRecord('ownSecondFactorEnrolment');
        $this->assertSame('success', $record->outcome);
        $this->assertSame('secondFactor', $record->target_type);
        $this->assertSame('anna@example.org', $record->target_id);
        $this->assertSame('Phone', $record->target_name);
        $this->assertSame(['secondFactorId' => $id, 'secondFactorType' => 'totp', 'restrictionLifted' => false], $record->details);
        $this->assertStringNotContainsString($secret, (string) json_encode($record));
        $this->assertStringNotContainsString($code, (string) json_encode($record->details));
    }

    public function test_a_code_of_the_previous_or_next_step_is_accepted(): void
    {
        $this->freezeTime();
        $this->asSelfServiceUser();
        $secret = (string) $this->start()->json('data.secret');

        $this->confirm(TestTotp::code($secret, -1))->assertCreated();
    }

    public function test_wrong_codes_count_down_and_the_fifth_cancels_the_enrolment(): void
    {
        $this->freezeTime();
        $this->asSelfServiceUser();
        $secret = (string) $this->start()->json('data.secret');
        $wrong = $this->wrongCode($secret);

        foreach ([4, 3, 2, 1, 0] as $remaining) {
            $this->confirm($wrong)
                ->assertStatus(400)
                ->assertJsonPath('type', '/api/v2/problems/second-factor-enrolment-failed')
                ->assertJsonPath('attemptsRemaining', $remaining);
        }

        $this->confirm(TestTotp::code($secret))
            ->assertStatus(400)
            ->assertJsonPath('type', '/api/v2/problems/second-factor-enrolment-expired');
        $this->assertSame(0, DB::table('tfa')->count());
        $records = $this->auditRecords('ownSecondFactorEnrolment');
        $this->assertCount(6, $records);
        $this->assertSame('registrationFailed', $records[0]->failure_reason);
        $this->assertSame(4, $records[0]->details['attemptsRemaining']);
        $this->assertSame('challengeExpired', $records[5]->failure_reason);
    }

    public function test_the_enrolment_expires_after_ten_minutes(): void
    {
        $this->asSelfServiceUser();
        $secret = (string) $this->start()->json('data.secret');

        $this->travel(601)->seconds();

        $this->confirm(TestTotp::code($secret))->assertStatus(400)->assertJsonPath('type', '/api/v2/problems/second-factor-enrolment-expired');
    }

    public function test_a_second_start_replaces_the_first(): void
    {
        $this->asSelfServiceUser();
        $first = (string) $this->start()->json('data.secret');
        $second = (string) $this->start()->json('data.secret');
        $this->assertNotSame($first, $second);

        $this->confirm(TestTotp::code($second))->assertCreated();
        $this->assertSame($second, DB::table('tfa')->value('secret'));
    }

    public function test_an_enrolment_started_in_another_session_is_expired(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->signInAs('anna@example.org', Role::MailboxUser);
        $secret = (string) $this->start()->json('data.secret');

        $this->signInAs('anna@example.org', Role::MailboxUser);

        $this->confirm(TestTotp::code($secret))->assertStatus(400)->assertJsonPath('type', '/api/v2/problems/second-factor-enrolment-expired');
    }

    public function test_a_wrong_current_password_keeps_the_enrolment_and_counts_towards_throttling(): void
    {
        $this->asSelfServiceUser();
        $secret = (string) $this->start()->json('data.secret');

        $this->confirm(TestTotp::code($secret), ['currentPassword' => 'wrong'])
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/current-password-incorrect');
        $this->confirm(TestTotp::code($secret), ['currentPassword' => 'wrong'])->assertStatus(403);

        $this->assertSame([0.0, 0.5], $this->sleeper()->slept);
        $this->confirm(TestTotp::code($secret))->assertCreated();
        $this->assertSame('currentPasswordIncorrect', $this->auditRecords('ownSecondFactorEnrolment')[0]->failure_reason);
    }

    public function test_the_current_password_is_required_without_a_forced_setup(): void
    {
        $this->asSelfServiceUser();
        $secret = (string) $this->start()->json('data.secret');

        $this->postJson('/api/v2/me/second-factors', ['type' => 'totp', 'name' => 'Phone', 'code' => TestTotp::code($secret)], $this->csrf())
            ->assertStatus(422)
            ->assertJsonPath('errors.currentPassword.0.code', 'required');
    }

    public function test_invalid_names_are_refused(): void
    {
        $this->asSelfServiceUser();
        $secret = (string) $this->start()->json('data.secret');

        $this->confirm(TestTotp::code($secret), ['name' => '   '])->assertStatus(422)->assertJsonPath('errors.name.0.code', 'required');
        $this->withoutRequestValidation();
        $this->confirm(TestTotp::code($secret), ['name' => str_repeat('a', 256)])->assertStatus(422)->assertJsonPath('errors.name.0.code', 'tooLong');
    }

    public function test_an_external_source_mailbox_is_refused(): void
    {
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->mailbox('ldap@example.org', authsource: 'ldap');
        $this->signInAs('ldap@example.org', Role::MailboxUser);

        $this->start()->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/password-managed-externally');
    }
}
