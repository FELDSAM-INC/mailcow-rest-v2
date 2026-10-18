<?php

namespace Tests\Feature\Webmail;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T071 (feature 005 FR-011, FR-025, FR-068, US5 AS-3): a webmail hand-off ends with the session of
 * this API that created it.
 */
class WebmailHandOffEndingTest extends TestCase
{
    use SelfServiceTestHelpers;

    public function test_sign_out_ends_the_hand_off_and_expires_its_cookie(): void
    {
        $this->asSelfServiceUser();
        [, $id] = $this->webmailHandOff();

        $response = $this->deleteJson('/api/v2/session', [], $this->csrf())->assertNoContent();

        $this->assertNull($this->legacySession((string) $id));
        $cleared = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'MCSESSID');
        $this->assertNotNull($cleared);
        $this->assertTrue($cleared->isCleared());
    }

    public function test_session_invalidation_ends_the_hand_off(): void
    {
        $this->asSelfServiceUser();
        [, $byAgent] = $this->webmailHandOff();
        $this->getJson('/api/v2/me', ['User-Agent' => 'Another browser'])->assertStatus(401);
        $this->assertNull($this->legacySession((string) $byAgent));

        $this->asSelfServiceUser('bob@example.org');
        [, $byDeactivation] = $this->webmailHandOff();
        DB::table('mailbox')->where('username', 'bob@example.org')->update(['active' => 0]);
        $this->getJson('/api/v2/me')->assertStatus(401);
        $this->assertNull($this->legacySession((string) $byDeactivation));
    }

    public function test_a_password_change_of_another_session_ends_it_but_not_the_own(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $other = $this->signInAs('anna@example.org', Role::MailboxUser);
        [, $otherHandOff] = $this->webmailHandOff();
        $this->signInAs('anna@example.org', Role::MailboxUser);
        [, $ownHandOff] = $this->webmailHandOff();

        $this->changeOwnPassword(['currentPassword' => MailcowFactory::PASSWORD, 'newPassword' => 'N3w!Passw0rd'])->assertOk()->assertJsonPath('data.sessionsEnded', 1);

        $this->assertNull($this->legacySession((string) $otherHandOff));
        $this->assertNotNull($this->legacySession((string) $ownHandOff));
        $this->assertFalse($this->sessionExists($other));
    }

    public function test_a_completed_reset_ends_every_hand_off(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->signInAs('anna@example.org', Role::MailboxUser);
        [, $id] = $this->webmailHandOff();
        $this->forgetCookies();
        $this->mailcow()->resetToken('anna@example.org', 'AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAAA');

        $this->postJson('/api/v2/password-resets/completion', ['token' => 'AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAAA', 'newPassword' => 'N3w!Passw0rd'], ['X-CSRF-Token' => $this->csrfToken()])->assertOk();

        $this->assertNull($this->legacySession((string) $id));
    }

    public function test_the_sweeper_ends_hand_offs_of_expired_sessions_and_forgets_expired_ones(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $expired = $this->signInAs('anna@example.org', Role::MailboxUser);
        [, $expiredHandOff] = $this->webmailHandOff();
        $this->signInAs('anna@example.org', Role::MailboxUser);
        [, $liveHandOff] = $this->webmailHandOff();
        $this->signInAs('anna@example.org', Role::MailboxUser);
        [, $goneHandOff] = $this->webmailHandOff();

        // The API session's key expires (no event), and one legacy session expired on its own.
        $this->app['session']->driver()->getHandler()->destroy($expired);
        $this->redis()->del('PHPREDIS_SESSION:'.$goneHandOff);

        $this->artisan('restv2:webmail-handoffs:sweep')->assertSuccessful();

        $this->assertNull($this->legacySession((string) $expiredHandOff));
        $this->assertNotNull($this->legacySession((string) $liveHandOff));
        $this->assertSame(0, (int) $this->restv2Redis('exists', 'webmail-handoff:'.$goneHandOff));
        $this->assertSame(0, (int) $this->restv2Redis('exists', 'webmail-handoff:'.$expiredHandOff));
        $this->assertSame(1, (int) $this->restv2Redis('exists', 'webmail-handoff:'.$liveHandOff));
    }
}
