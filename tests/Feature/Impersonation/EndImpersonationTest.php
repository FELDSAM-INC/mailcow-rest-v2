<?php

namespace Tests\Feature\Impersonation;

use App\Auth\AuthMethod;
use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T053 (AS US3-3, US3-10, FR-050, FR-051, FR-067, SC-007): ending an impersonation
 * returns to the impersonator with renewed identifiers; signing out ends the whole session; a new
 * sign-in replaces it; every end is audited.
 */
class EndImpersonationTest extends TestCase
{
    use AccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->impersonationCast();
    }

    public function test_ending_returns_to_the_impersonator(): void
    {
        $this->signInAs('alice', Role::Admin, AuthMethod::PasswordAndSecondFactor);
        $started = microtime(true);
        $this->impersonate('mailboxUser', 'bob@a.example')->assertCreated();
        $token = $this->csrfToken();
        $cookie = $this->sessionCookie();

        $this->deleteJson('/api/v2/session/impersonation', [], ['X-CSRF-Token' => $token])->assertNoContent();

        $this->assertLessThan(10, microtime(true) - $started);
        $this->assertNotSame($cookie, $this->sessionCookie());
        $this->assertNotSame($token, $this->csrfToken());
        $this->getJson('/api/v2/me')->assertOk()
            ->assertJsonPath('data.username', 'alice')
            ->assertJsonPath('data.authentication.method', 'passwordAndSecondFactor');
        $this->assertArrayNotHasKey('impersonation', $this->getJson('/api/v2/me')->json('data'));
        $this->getJson('/api/v2/session/impersonation')->assertNotFound();

        $record = $this->assertAudit('impersonationEnd', 'success', 'impersonation', 'bob@a.example', ['endedBy' => 'explicit'], ['bob@a.example', 'mailboxUser']);
        $this->assertIsInt($record->details['durationSeconds']);
        $this->assertArrayNotHasKey('endReason', $record->details);
    }

    public function test_ending_without_an_impersonation_changes_nothing(): void
    {
        $this->signInAs('alice', Role::Admin);

        $this->endImpersonation()->assertNoContent();

        $this->assertSame(0, DB::table('restv2_audit_events')->where('event_type', 'impersonationEnd')->count());
        $this->getJson('/api/v2/me')->assertOk()->assertJsonPath('data.username', 'alice');
    }

    public function test_signing_out_ends_the_whole_session(): void
    {
        $this->signInAs('alice', Role::Admin);
        $this->impersonate('mailboxUser', 'bob@a.example')->assertCreated();

        $this->deleteJson('/api/v2/session', [], $this->csrf())->assertNoContent();

        $this->getJson('/api/v2/me')->assertUnauthorized();
        $this->getJson('/api/v2/session')->assertOk()->assertJsonPath('data.state', 'anonymous');
        $events = DB::table('restv2_audit_events')->whereIn('event_type', ['impersonationEnd', 'signOut'])->orderBy('occurred_at')->orderBy('id')->pluck('event_type')->all();
        $this->assertSame(['impersonationEnd', 'signOut'], $events);
        $this->assertAudit('impersonationEnd', 'success', 'impersonation', 'bob@a.example', ['endedBy' => 'signOut'], ['bob@a.example', 'mailboxUser']);
    }

    public function test_a_new_sign_in_replaces_the_impersonating_session(): void
    {
        $this->signInAs('alice', Role::Admin);
        $this->impersonate('mailboxUser', 'bob@a.example')->assertCreated();

        $this->passwordSignIn('carol@a.example')->assertCreated()->assertJsonPath('data.username', 'carol@a.example');

        $this->assertAudit('impersonationEnd', 'success', 'impersonation', 'bob@a.example', ['endedBy' => 'sessionReplaced'], ['bob@a.example', 'mailboxUser']);
        $this->getJson('/api/v2/session/impersonation')->assertNotFound();
        $this->getJson('/api/v2/me')->assertOk()->assertJsonPath('data.username', 'carol@a.example');
    }
}
