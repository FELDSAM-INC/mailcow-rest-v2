<?php

namespace Tests\Feature\Mailboxes;

use Illuminate\Support\Facades\DB;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

/**
 * Feature 003 T060 (US4 AS-3, SC-007): a mailbox created again under a deleted address inherits
 * nothing of the old one.
 */
class DeleteMailboxReuseTest extends TestCase
{
    use MailboxTestHelpers;

    public function test_a_reset_token_cannot_reset_a_reassigned_mailbox(): void
    {
        $token = '3F9A1C-0B7E22-9D4F10-A6C3E8-51B2D7';
        $this->mailcow()->domain('example.org');
        $this->mailcow()->legacyMailbox('anna@example.org');
        $this->mailcow()->legacyMailbox('bob@example.org');
        $this->mailcow()->resetToken('anna@example.org', $token);
        $this->mailcow()->resetToken('bob@example.org', 'AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAAA');
        $this->asAdmin();

        $this->deleteMailbox('anna@example.org')->assertNoContent();
        $this->createMailbox(['localPart' => 'anna', 'domain' => 'example.org', 'password' => '{PLAIN}new-owner'])->assertCreated();
        $password = $this->mailboxRow('anna@example.org')->password;
        $this->forgetCookies();
        $csrf = $this->getJson('https://mail.example.org/api/v2/csrf-token')->assertOk()->json('data.token');

        $this->postJson('https://mail.example.org/api/v2/password-resets/completion', [
            'token' => $token,
            'newPassword' => 'N3w!Passw0rd',
        ], ['X-CSRF-Token' => $csrf])->assertStatus(400)
            ->assertJsonPath('type', '/api/v2/problems/invalid-reset-token');

        $this->assertSame($password, $this->mailboxRow('anna@example.org')->password);
        $this->assertFalse(DB::table('reset_password')->where('username', 'anna@example.org')->exists());
        $this->assertTrue(DB::table('reset_password')->where('username', 'bob@example.org')->exists());
    }

    public function test_a_recreated_address_starts_empty(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->legacyMailbox('anna@example.org');
        $this->mailcow()->usage('anna@example.org', 5000, 12);
        $this->mailcow()->saslLog('anna@example.org', 'imap', '2026-10-01 10:00:00');
        $this->mailcow()->tfaTotp('anna@example.org', 'JBSWY3DPEHPK3PXP');
        $this->mailcow()->fido2('anna@example.org', random_bytes(16), '-----BEGIN PUBLIC KEY-----', 'example.org');
        DB::table('restv2_preferences')->insert(['account_kind' => 'mailbox', 'username' => 'anna@example.org', 'theme' => 'dark', 'created_at' => now(), 'updated_at' => now()]);
        $this->redis()->hSet('RCPT_WANTS_SUBJECT_TAG', 'anna@example.org', '1');
        $this->redis()->hSet('RL_VALUE', 'anna@example.org', '10 / 1h');
        $this->asAdmin();

        $this->deleteMailbox('anna@example.org')->assertNoContent();
        $data = $this->createMailbox(['localPart' => 'anna', 'domain' => 'example.org', 'password' => '{PLAIN}x'])->assertCreated()->json('data');

        $this->assertSame([0, 0], [$data['quotaUsed'], $data['messageCount']]);
        $this->assertSame(['imap' => null, 'pop3' => null, 'smtp' => null, 'webmailSso' => null], $data['lastLogins']);
        $this->assertSame('none', $data['taggedMailHandling']);
        $this->assertNull($data['rateLimit']);
        $this->assertSame(0, DB::table('sasl_log')->where('username', 'anna@example.org')->count());
        $this->assertSame(0, DB::table('tfa')->where('username', 'anna@example.org')->count());
        $this->assertSame(0, DB::table('fido2')->where('username', 'anna@example.org')->count());
        $this->assertSame(0, DB::table('restv2_preferences')->where('username', 'anna@example.org')->count());
    }
}
