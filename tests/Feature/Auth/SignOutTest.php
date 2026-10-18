<?php

namespace Tests\Feature\Auth;

use App\Auth\AuthMethod;
use App\Auth\Role;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Auth\AuthRequests;
use Tests\TestCase;

/**
 * T061 (AS-5, FR-010, R7): deleteSession ends the session in any state.
 */
class SignOutTest extends TestCase
{
    use AuthRequests;

    public function test_signing_out_ends_the_session_and_is_audited(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $sessionId = $this->signInAs('admin', Role::Admin, AuthMethod::Passkey);
        $token = $this->csrfToken();

        $this->deleteJson('/api/v2/session', [], ['X-CSRF-Token' => $token])->assertNoContent();

        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'anonymous');
        $this->assertFalse((bool) Redis::connection('mailcow')->exists('restv2:session:'.$sessionId));

        $audit = $this->onlyAuditRecord('signOut');
        $this->assertSame('success', $audit->outcome);
        $this->assertSame('admin', $audit->actor_username);
        $this->assertSame('admin', $audit->actor_role);
        $this->assertSame('passkey', $audit->auth_method);
        $this->assertSame('session', $audit->target_type);
    }

    public function test_the_old_cookie_is_anonymous_after_signing_out(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $sessionId = $this->signInAs('admin', Role::Admin);

        $this->deleteJson('/api/v2/session', [], ['X-CSRF-Token' => $this->csrfToken()])->assertNoContent();

        $this->forgetCookies();
        $this->useSessionCookie($sessionId);
        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'anonymous');
    }

    public function test_signing_out_without_a_cookie_succeeds_without_audit(): void
    {
        $this->deleteJson('/api/v2/session')->assertNoContent();

        $this->assertSame([], $this->auditRecords('signOut'));
    }

    public function test_signing_out_with_an_unknown_cookie_needs_no_token(): void
    {
        $this->useSessionCookie(str_repeat('a', 40));

        $this->deleteJson('/api/v2/session')->assertNoContent();
    }

    public function test_a_live_session_needs_the_token(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);

        $this->deleteJson('/api/v2/session')
            ->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/csrf-token-mismatch');
        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'active');
        $this->assertSame([], $this->auditRecords('signOut'));
    }

    public function test_a_new_token_is_issued_after_signing_out(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);
        $token = $this->csrfToken();

        $this->deleteJson('/api/v2/session', [], ['X-CSRF-Token' => $token])->assertNoContent();

        $this->assertNotSame($token, $this->csrfToken());
    }

    public function test_signing_out_during_a_pending_sign_in_ends_it_without_audit(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $this->mailcow()->tfaTotp('alice@example.org', 'JBSWY3DPEHPK3PXP');
        $this->passwordSignIn('alice@example.org')->assertCreated()->assertJsonPath('data.state', 'pendingSecondFactor');

        $this->deleteJson('/api/v2/session', [], ['X-CSRF-Token' => $this->csrfToken()])->assertNoContent();

        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'anonymous');
        $this->assertSame([], $this->auditRecords('signOut'));
    }

    public function test_an_anonymous_live_session_is_ended_too(): void
    {
        $token = $this->csrfToken();

        $this->deleteJson('/api/v2/session', [], ['X-CSRF-Token' => $token])->assertNoContent();

        $this->assertNotSame($token, $this->csrfToken());
        $this->assertSame([], $this->auditRecords('signOut'));
    }
}
