<?php

namespace Tests\Feature\Auth;

use App\Auth\AuthMethod;
use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * T028 (FR-009, FR-012, FR-014, FR-029): getSession reports the browser session's state and never
 * fails for a missing or ended session.
 */
class GetSessionTest extends TestCase
{
    public function test_without_a_cookie_the_session_is_anonymous(): void
    {
        $this->getJson('/api/v2/session')
            ->assertOk()
            ->assertExactJson(['data' => [
                'state' => 'anonymous',
                'pendingActions' => [],
                // SESSION_LIFETIME of tests/fixtures/mailcow-inc/vars.local.inc.php
                'idleTimeout' => 7200,
            ]]);
    }

    public function test_a_signed_in_admin_is_active(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);

        $response = $this->getJson('/api/v2/session')->assertOk();

        $response->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.username', 'admin')
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.authMethod', 'password')
            ->assertJsonPath('data.pendingActions', [])
            ->assertJsonPath('data.idleTimeout', 7200);
        $this->assertNotNull($response->json('data.signedInAt'));
        $this->assertArrayNotHasKey('pendingSignIn', $response->json('data'));
    }

    public function test_signed_in_at_is_utc(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin, AuthMethod::Passkey);

        $response = $this->getJson('/api/v2/session')->assertOk();

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $response->json('data.signedInAt'));
        $response->assertJsonPath('data.authMethod', 'passkey');
    }

    public function test_a_forced_password_change_restricts_the_session(): void
    {
        $this->mailcow()->mailboxWithDomain('bob@example.org', ['force_pw_update' => '1']);
        $this->signInAs('bob@example.org', Role::MailboxUser);

        $this->getJson('/api/v2/session')
            ->assertOk()
            ->assertJsonPath('data.state', 'restricted')
            ->assertJsonPath('data.role', 'mailboxUser')
            ->assertJsonPath('data.pendingActions', ['passwordChange']);
    }

    public function test_an_idle_session_ends(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $sessionId = $this->signInAs('admin', Role::Admin);
        $this->assertTrue((bool) Redis::connection('mailcow')->exists('restv2:session:'.$sessionId));

        $this->travel(7201)->seconds();

        $this->getJson('/api/v2/session')->assertOk()->assertJsonPath('data.state', 'anonymous');
        $this->assertFalse((bool) Redis::connection('mailcow')->exists('restv2:session:'.$sessionId));
        $this->assertNotSame($sessionId, $this->sessionCookie());
    }

    public function test_a_session_used_within_the_lifetime_stays_active(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);

        $this->travel(7000)->seconds();
        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'active');
        $this->travel(7000)->seconds();
        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'active');
    }

    public function test_a_different_user_agent_ends_the_session(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $sessionId = $this->signInAs('admin', Role::Admin);

        $this->getJson('/api/v2/session', ['User-Agent' => 'Mozilla/5.0 (other device)'])
            ->assertOk()
            ->assertJsonPath('data.state', 'anonymous');
        $this->assertFalse((bool) Redis::connection('mailcow')->exists('restv2:session:'.$sessionId));

        // The old session does not come back with the original user agent either.
        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'anonymous');
    }

    public function test_a_deactivated_account_ends_the_session(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);
        DB::table('admin')->where('username', 'admin')->update(['active' => 0]);

        $this->getJson('/api/v2/session')->assertOk()->assertJsonPath('data.state', 'anonymous');
    }

    public function test_a_deleted_or_demoted_account_ends_the_session(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);
        DB::table('admin')->where('username', 'admin')->update(['superadmin' => 0]);

        $this->getJson('/api/v2/session')->assertOk()->assertJsonPath('data.state', 'anonymous');

        $this->mailcow()->mailboxWithDomain('carol@example.org');
        $this->signInAs('carol@example.org', Role::MailboxUser);
        DB::table('mailbox')->where('username', 'carol@example.org')->delete();

        $this->getJson('/api/v2/session')->assertOk()->assertJsonPath('data.state', 'anonymous');
    }

    public function test_a_mailbox_in_an_inactive_domain_ends_the_session(): void
    {
        $this->mailcow()->mailboxWithDomain('dave@example.org');
        $this->signInAs('dave@example.org', Role::MailboxUser);
        DB::table('domain')->where('domain', 'example.org')->update(['active' => 0]);

        $this->getJson('/api/v2/session')->assertOk()->assertJsonPath('data.state', 'anonymous');
    }
}
