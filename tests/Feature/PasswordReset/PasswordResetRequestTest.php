<?php

namespace Tests\Feature\PasswordReset;

use App\Support\Database\NamedLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T051 (feature 005 FR-019–FR-022, US3 AS-2, AS-3, AS-7): requestPasswordReset answers every
 * well-formed request alike, and afterwards issues a legacy-compatible token and sends the message
 * built from the operator's template, only for an eligible mailbox.
 */
class PasswordResetRequestTest extends TestCase
{
    use ApiKeys;
    use SelfServiceTestHelpers;

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function request(string $address, string $host = 'https://mail.example.org', array $headers = []): TestResponse
    {
        return $this->postJson($host.'/api/v2/password-resets', ['address' => $address], ['X-CSRF-Token' => $this->csrfTokenFor($host)] + $headers);
    }

    private function csrfTokenFor(string $host): string
    {
        return (string) $this->getJson($host.'/api/v2/csrf-token')->assertOk()->json('data.token');
    }

    private function eligible(string $username = 'anna@example.org', string $recovery = 'anna.private@example.net'): void
    {
        $this->mailcow()->mailboxWithDomain($username, ['recovery_email' => $recovery]);
    }

    /**
     * @return list<object>
     */
    private function tokens(string $username = 'anna@example.org'): array
    {
        return DB::table('reset_password')->where('username', $username)->get()->all();
    }

    public function test_an_eligible_mailbox_gets_a_token_and_the_message(): void
    {
        $this->configureResetMessage('noreply@example.org', 'Reset for {{username}}', '<p>Hello {{username2}}, <a href="{{link}}">{{link}}</a> {{date}} {{token_lifetime}} {{hostname}}</p>', 'Hello {{username2}}: {{link}} ({{token_lifetime}} min, {{hostname}}, {{username}}, {{date}})');
        $this->eligible();

        $this->request('Anna@Example.org')->assertOk()->assertExactJson(['data' => ['tokenLifetimeMinutes' => 15]]);

        $tokens = $this->tokens();
        $this->assertCount(1, $tokens);
        $token = (string) $tokens[0]->token;
        $this->assertMatchesRegularExpression('/^[0-9A-F]{6}(-[0-9A-F]{6}){4}$/', $token);

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages);
        $message = $messages[0];
        $this->assertSame('noreply@example.org', $message->getFrom()[0]->getAddress());
        $this->assertSame('anna.private@example.net', $message->getTo()[0]->getAddress());
        $this->assertSame('Reset for anna@example.org', $message->getSubject());
        $link = 'https://mail.example.org/ng/reset-password#token='.$token;
        $this->assertStringContainsString('<a href="'.$link.'">'.$link.'</a>', (string) $message->getHtmlBody());
        $text = (string) $message->getTextBody();
        $this->assertStringContainsString('Hello anna.private@example.net: '.$link.' (15 min, mail.example.org, anna@example.org, ', $text);
        $this->assertStringNotContainsString('{{', $text.$message->getHtmlBody());
        $this->assertSame('MooMail', $message->getHeaders()->getHeaderBody('X-Mailer'));

        $record = $this->onlyAuditRecord('passwordResetRequest');
        $this->assertSame('success', $record->outcome);
        $this->assertSame('passwordReset', $record->target_type);
        $this->assertSame('anna@example.org', $record->target_id);
        $this->assertNull($record->actor_username);
        $this->assertStringNotContainsString($token, (string) json_encode($record));
    }

    public function test_issuance_uses_the_recovery_address_current_when_the_lock_is_acquired(): void
    {
        $this->configureResetMessage();
        $this->eligible();
        $this->mock(NamedLock::class)->shouldReceive('run')->once()->andReturnUsing(function ($names, $code) {
            // A mailbox change committed while this request waited for the address lock.
            DB::table('mailbox')->where('username', 'anna@example.org')->update([
                'attributes->recovery_email' => 'new-owner@example.net',
            ]);

            return $code();
        });

        $this->request('anna@example.org')->assertOk();

        $this->assertCount(1, $this->sentMessages());
        $this->assertSame('new-owner@example.net', $this->sentMessages()[0]->getTo()[0]->getAddress());
    }

    public function test_without_stored_bodies_the_template_files_are_used(): void
    {
        $this->configureResetMessage();
        $this->eligible();

        $this->request('anna@example.org')->assertOk();

        $message = $this->sentMessages()[0];
        $this->assertStringContainsString('Somebody requested a new password for the mail.example.org account associated with anna@example.org.', (string) $message->getTextBody());
        $this->assertStringContainsString('<html>', (string) $message->getHtmlBody());
    }

    public function test_the_link_host_follows_the_request_when_it_is_one_of_mailcows_names(): void
    {
        config(['restv2.additional_server_names' => 'webmail.example.org, mail.example.net']);
        $this->configureResetMessage('noreply@example.org', 'Reset', '', '{{link}}');
        $this->eligible();

        $this->request('anna@example.org', 'https://webmail.example.org')->assertOk();
        $this->assertStringStartsWith('https://webmail.example.org/ng/reset-password#token=', (string) $this->sentMessages()[0]->getTextBody());

        $this->request('anna@example.org', 'https://evil.example.com')->assertOk();
        $this->assertStringStartsWith('https://mail.example.org/ng/reset-password#token=', (string) $this->sentMessages()[1]->getTextBody());

        config(['restv2.pw_reset_page' => '/reset-password?token={token}']);
        $this->request('anna@example.org')->assertOk();
        $this->assertMatchesRegularExpression('#^https://mail\.example\.org/reset-password\?token=[0-9A-F-]{34}$#', (string) $this->sentMessages()[2]->getTextBody());
    }

    public function test_ineligible_requests_get_the_same_answer_and_no_message(): void
    {
        $this->configureResetMessage();
        $this->eligible();
        $reference = $this->request('anna@example.org')->assertOk();
        $this->mailcow()->mailboxWithDomain('norecovery@example.org');
        $this->mailcow()->mailboxWithDomain('invalid@example.org', ['recovery_email' => 'not-an-address']);
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->mailbox('ldap@example.org', authsource: 'ldap', attributes: ['recovery_email' => 'ldap.private@example.net']);
        $this->mailcow()->mailbox('inactive@example.org', active: 0, attributes: ['recovery_email' => 'inactive.private@example.net']);
        $this->mailcow()->mailbox('room@example.org', kind: 'location', attributes: ['recovery_email' => 'room.private@example.net']);
        $this->mailcow()->mailboxWithDomain('limit@example.org', ['recovery_email' => 'limit.private@example.net'], userAcl: ['pw_reset' => 0]);
        foreach (['AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAA1', 'AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAA2', 'AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAA3'] as $token) {
            $this->mailcow()->resetToken('limit@example.org', $token);
        }

        $cases = [
            'nobody@example.org' => 'unknownAccount',
            'norecovery@example.org' => 'noRecoveryEmail',
            'invalid@example.org' => 'noRecoveryEmail',
            'ldap@example.org' => 'accountNotEligible',
            'inactive@example.org' => 'accountNotEligible',
            'room@example.org' => 'accountNotEligible',
            'limit@example.org' => 'tokenLimitReached',
        ];

        foreach (array_keys($cases) as $address) {
            $response = $this->request($address)->assertOk();
            $this->assertSame($reference->getContent(), $response->getContent());
            $this->assertSame($reference->headers->get('Content-Type'), $response->headers->get('Content-Type'));
        }

        $this->assertCount(1, $this->sentMessages());
        $records = array_slice($this->auditRecords('passwordResetRequest'), 1);
        $this->assertSame(array_values($cases), array_map(fn (object $r): ?string => $r->failure_reason, $records));
        $this->assertSame(array_keys($cases), array_map(fn (object $r): ?string => $r->target_id, $records));
        $this->assertCount(3, $this->tokens('limit@example.org'));
    }

    public function test_the_password_reset_flag_is_not_checked(): void
    {
        $this->configureResetMessage();
        $this->mailcow()->mailboxWithDomain('anna@example.org', ['recovery_email' => 'anna.private@example.net'], userAcl: ['pw_reset' => 0]);

        $this->request('anna@example.org')->assertOk();

        $this->assertCount(1, $this->sentMessages());
    }

    public function test_a_failed_message_discards_the_token(): void
    {
        $this->configureResetMessage();
        $this->eligible();
        config(['mail.default' => 'restv2_postfix', 'mail.mailers.restv2_postfix.host' => '127.0.0.1', 'mail.mailers.restv2_postfix.port' => 1]);

        $this->request('anna@example.org')->assertOk()->assertExactJson(['data' => ['tokenLifetimeMinutes' => 15]]);

        $this->assertSame([], $this->tokens());
        $this->assertSame('messageNotSent', $this->onlyAuditRecord('passwordResetRequest')->failure_reason);
    }

    public function test_expired_tokens_of_any_account_are_removed_at_each_request(): void
    {
        $this->configureResetMessage();
        $this->mailcow()->mailboxWithDomain('carl@example.org');
        $this->mailcow()->resetToken('carl@example.org', 'BBBBBB-BBBBBB-BBBBBB-BBBBBB-BBBBBB', date('Y-m-d H:i:s', time() - 16 * 60));
        $this->mailcow()->resetToken('carl@example.org', 'CCCCCC-CCCCCC-CCCCCC-CCCCCC-CCCCCC');

        $this->request('nobody@example.org')->assertOk();

        $this->assertSame(['CCCCCC-CCCCCC-CCCCCC-CCCCCC-CCCCCC'], array_column($this->tokens('carl@example.org'), 'token'));
    }

    public function test_without_sender_or_subject_the_reset_is_unavailable(): void
    {
        $this->configureResetMessage('', 'Reset');
        $this->eligible();

        $this->request('anna@example.org')->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/password-reset-unavailable');
        $this->request('nobody@example.org')->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/password-reset-unavailable');
        $this->assertSame([], $this->tokens());
    }

    public function test_invalid_addresses_keys_and_missing_tokens_are_refused(): void
    {
        $this->configureResetMessage();

        $this->request('not an address')->assertStatus(422)->assertJsonPath('errors.address.0.code', 'invalidFormat');

        $this->mailcow()->admin('admin', 'Passw0rd!');
        $this->forgetCookies();
        $this->postJson('/api/v2/password-resets', ['address' => 'anna@example.org'], $this->keyHeader($this->apiKey('admin')))
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/session-required');

        $this->forgetCookies();
        $this->postJson('/api/v2/password-resets', ['address' => 'anna@example.org'])
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/csrf-token-mismatch');
    }

    public function test_the_mailer_uses_legacys_smtp_settings(): void
    {
        $mailer = config('mail.mailers.restv2_postfix');

        $this->assertSame('smtp', $mailer['transport']);
        $this->assertSame('postfix-mailcow', $mailer['host']);
        $this->assertSame(25, $mailer['port']);
        $this->assertNull($mailer['username']);
        $this->assertNull($mailer['password']);
        $this->assertFalse($mailer['verify_peer']);
        $this->assertSame(10, $mailer['timeout']);
    }

    public function test_concurrent_requests_never_exceed_the_token_limit(): void
    {
        $this->configureResetMessage();
        $this->eligible();
        config(['restv2.pw_reset_max_attempts' => 100]);

        for ($i = 0; $i < 10; $i++) {
            $this->request('anna@example.org')->assertOk();
        }

        $this->assertCount(3, $this->tokens());
        $this->assertCount(3, $this->sentMessages());
    }
}
