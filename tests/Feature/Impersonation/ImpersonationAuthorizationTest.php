<?php

namespace Tests\Feature\Impersonation;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T056 (AS US3-5, US3-7, FR-042, FR-043, SC-002, research R24): who may start an
 * impersonation: never with a key, never from a restricted or pending session; a domain
 * administrator needs `loginAs` (checked before the target) and never targets another domain
 * administrator.
 */
class ImpersonationAuthorizationTest extends TestCase
{
    use AccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->impersonationCast();
    }

    public function test_no_key_can_start_an_impersonation(): void
    {
        $body = ['role' => 'mailboxUser', 'username' => 'bob@a.example'];

        $this->postJson('/api/v2/session/impersonation', $body, $this->keyHeader($this->apiKey('alice')))->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/session-required');
        $this->postJson('/api/v2/session/impersonation', $body, $this->keyHeader($this->apiKey('alice', 'readOnly')))->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
        $this->postJson('/api/v2/session/impersonation', $body, $this->keyHeader($this->apiKey('reseller1')))->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/session-required');
        $this->getJson('/api/v2/session/impersonation', $this->keyHeader($this->apiKey('alice')))->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/session-required');
        $this->deleteJson('/api/v2/session/impersonation', [], $this->keyHeader($this->apiKey('alice')))->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/session-required');

        $this->assertSame(0, DB::table('restv2_audit_events')->where('event_type', 'impersonationStart')->where('outcome', 'success')->count());
    }

    public function test_mailbox_users_cannot_impersonate(): void
    {
        $this->signInAs('bob@a.example', Role::MailboxUser);

        $this->impersonate('mailboxUser', 'carol@a.example')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
    }

    public function test_a_domain_administrator_without_login_as_gets_the_same_answer_for_any_target(): void
    {
        DB::table('da_acl')->where('username', 'reseller1')->update(['login_as' => 0]);
        $this->signInAs('reseller1', Role::DomainAdmin);

        foreach (['carol@a.example', 'dave@b.example', 'nobody@a.example'] as $target) {
            $this->impersonate('mailboxUser', $target)->assertForbidden()
                ->assertJsonPath('type', '/api/v2/problems/permission-flag-required')
                ->assertJsonPath('requiredFlags', ['loginAs']);
        }
    }

    public function test_turning_login_as_off_applies_to_the_next_attempt(): void
    {
        $this->signInAs('alice', Role::Admin);
        $this->patchDomainAdmin('reseller1', ['permissions' => ['loginAs' => false]])->assertOk();

        $this->signInAs('reseller1', Role::DomainAdmin);
        $this->impersonate('mailboxUser', 'carol@a.example')->assertForbidden()->assertJsonPath('requiredFlags', ['loginAs']);
    }

    public function test_a_domain_administrator_never_targets_a_domain_administrator(): void
    {
        $this->mailcow()->domainAdmin('reseller2', ['a.example']);
        $this->signInAs('reseller1', Role::DomainAdmin);

        $this->impersonate('domainAdmin', 'reseller2')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
    }

    public function test_restricted_pending_and_anonymous_sessions_are_refused(): void
    {
        DB::table('admin')->where('username', 'alice')->update(['attributes' => '{"force_tfa": "0", "force_pw_update": "1"}']);
        $this->signInAs('alice', Role::Admin);
        $this->impersonate('mailboxUser', 'bob@a.example')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/pending-action-required');

        $this->forgetCookies();
        $this->signInPending('reseller1', Role::DomainAdmin);
        $this->impersonate('mailboxUser', 'bob@a.example')->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/second-factor-required');

        $this->forgetCookies();
        $this->impersonate('mailboxUser', 'bob@a.example')->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/unauthenticated');
    }
}
