<?php

namespace Tests\Feature\Webmail;

use App\Auth\AccountKind;
use App\Auth\Role;
use App\Services\Auth\SessionRegistry;
use App\Support\LegacySession\PhpSessionEncoder;
use Tests\Support\Legacy\LegacyFpmRunner;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T072 (feature 005 research R3): legacy mailcow's unmodified sogo-auth.php, run under php-fpm,
 * grants webmail to the hand-off's legacy session and refuses it once the hand-off ended. This
 * proves that the legacy script reads the API's session encoding.
 */
class SogoAuthCompatibilityTest extends TestCase
{
    use SelfServiceTestHelpers;

    private LegacyFpmRunner $legacy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->legacy = new LegacyFpmRunner;
        $this->legacy->start();
    }

    protected function tearDown(): void
    {
        $this->legacy->stop();

        parent::tearDown();
    }

    private function webmailUser(string $id): string
    {
        return $this->legacy->webmailAuthRequest(['MCSESSID' => $id])['x-user'] ?? '';
    }

    public function test_the_legacy_script_grants_webmail_until_sign_out(): void
    {
        $this->asSelfServiceUser();
        [, $id] = $this->webmailHandOff();

        $this->assertSame('anna@example.org', $this->webmailUser((string) $id));
        $this->assertSame('', $this->webmailUser('0123456789abcdef0123456789abcdef'));

        $this->deleteJson('/api/v2/session', [], $this->csrf())->assertNoContent();
        $this->assertSame('', $this->webmailUser((string) $id));
    }

    public function test_ending_all_sessions_and_the_sweeper_end_it(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->signInAs('anna@example.org', Role::MailboxUser);
        [, $id] = $this->webmailHandOff();
        $this->assertSame('anna@example.org', $this->webmailUser((string) $id));

        $this->app->make(SessionRegistry::class)->endAll(AccountKind::Mailbox, 'anna@example.org');
        $this->assertSame('', $this->webmailUser((string) $id));

        $this->signInAs('anna@example.org', Role::MailboxUser);
        [, $second] = $this->webmailHandOff();
        $this->assertSame('anna@example.org', $this->webmailUser((string) $second));
        $this->app['session']->driver()->getHandler()->destroy((string) $this->sessionCookie());
        $this->artisan('restv2:webmail-handoffs:sweep')->assertSuccessful();
        $this->assertSame('', $this->webmailUser((string) $second));
    }

    public function test_a_pending_action_or_a_tampered_session_is_refused(): void
    {
        $base = [
            'mailcow_cc_username' => 'anna@example.org',
            'sogo-sso-user-allowed' => ['anna@example.org'],
            'mailcow_cc_api_access' => 'ro',
            'SESS_REMOTE_UA' => 'Symfony',
            'LAST_ACTIVITY' => time(),
            'CSRF' => ['TOKEN' => str_repeat('a', 64)],
        ];

        $this->redis()->set('PHPREDIS_SESSION:'.str_repeat('1', 32), PhpSessionEncoder::encode($base + ['pending_pw_update' => true]));
        $this->assertSame('', $this->webmailUser(str_repeat('1', 32)));

        $this->redis()->set('PHPREDIS_SESSION:'.str_repeat('2', 32), PhpSessionEncoder::encode(['sogo-sso-user-allowed' => ['carl@example.org']] + $base));
        $this->assertSame('', $this->webmailUser(str_repeat('2', 32)));

        $this->redis()->set('PHPREDIS_SESSION:'.str_repeat('3', 32), PhpSessionEncoder::encode($base));
        $this->assertSame('anna@example.org', $this->webmailUser(str_repeat('3', 32)));
    }
}
