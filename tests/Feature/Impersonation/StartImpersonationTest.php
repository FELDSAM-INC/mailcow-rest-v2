<?php

namespace Tests\Feature\Impersonation;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T051 (AS US3-1, US3-4, US3-6, FR-042 to FR-045, FR-053, research R16): starting an
 * impersonation renews the session identifier and the anti-forgery token, answers the new identity,
 * and is audited; eligibility follows legacy, with out-of-scope targets answered like missing ones.
 */
class StartImpersonationTest extends TestCase
{
    use AccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->impersonationCast();
    }

    public function test_an_administrator_impersonates_a_mailbox_user(): void
    {
        $this->signInAs('alice', Role::Admin);
        $oldToken = $this->csrfToken();
        $oldCookie = $this->sessionCookie();

        $response = $this->impersonate('mailboxUser', 'Bob@A.example')->assertCreated()->assertHeader('Location', '/api/v2/session/impersonation');

        $response->assertJsonPath('data.target', ['username' => 'bob@a.example', 'role' => 'mailboxUser'])
            ->assertJsonPath('data.impersonator', ['username' => 'alice', 'role' => 'admin', 'authMethod' => 'password'])
            ->assertJsonPath('data.identity.username', 'bob@a.example')
            ->assertJsonPath('data.identity.role', 'mailboxUser')
            ->assertJsonPath('data.csrfToken.headerName', 'X-CSRF-Token');
        $this->assertNotSame($oldCookie, $this->sessionCookie());
        $newToken = (string) $response->json('data.csrfToken.token');
        $this->assertNotSame($oldToken, $newToken);

        $this->patchMerge('/api/v2/me/preferences', ['theme' => 'dark'], ['X-CSRF-Token' => $oldToken])->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/csrf-token-mismatch');
        $this->patchMerge('/api/v2/me/preferences', ['theme' => 'dark'], ['X-CSRF-Token' => $newToken])->assertOk();

        $record = $this->assertAudit('impersonationStart', 'success', 'impersonation', 'bob@a.example', ['targetRole' => 'mailboxUser'], ['bob@a.example', 'mailboxUser']);
        $this->assertSame($response->json('data.id'), $record->target_id);
        $this->assertSame(['alice', 'admin'], [$record->actor_username, $record->actor_role]);
    }

    public function test_an_administrator_impersonates_a_domain_administrator(): void
    {
        $this->signInAs('alice', Role::Admin);

        $this->impersonate('domainAdmin', 'Reseller1')->assertCreated()
            ->assertJsonPath('data.target', ['username' => 'reseller1', 'role' => 'domainAdmin'])
            ->assertJsonPath('data.identity.role', 'domainAdmin')
            ->assertJsonPath('data.identity.scope.domains', ['a.example']);
    }

    public function test_a_domain_administrator_impersonates_mailboxes_of_its_domains_only(): void
    {
        $this->signInAs('reseller1', Role::DomainAdmin);

        $other = $this->impersonate('mailboxUser', 'dave@b.example')->assertNotFound()->json();
        $unknown = $this->impersonate('mailboxUser', 'nobody@a.example')->assertNotFound()->json();
        unset($other['requestId'], $unknown['requestId']);
        $this->assertSame($unknown, $other);

        $this->impersonate('mailboxUser', 'carol@a.example')->assertCreated()
            ->assertJsonPath('data.impersonator', ['username' => 'reseller1', 'role' => 'domainAdmin', 'authMethod' => 'password']);
    }

    public function test_resources_and_administrators_are_not_found(): void
    {
        $this->mailcow()->resource('room@a.example', 'Room');
        $this->signInAs('alice', Role::Admin);

        $this->impersonate('mailboxUser', 'room@a.example')->assertNotFound();
        $this->impersonate('domainAdmin', 'alice')->assertNotFound();
        $this->impersonate('domainAdmin', 'bob@a.example')->assertNotFound();
    }

    public function test_inactive_targets_and_mailboxes_of_inactive_domains_are_allowed(): void
    {
        $this->mailcow()->mailboxWithDomain('off@a.example', active: 0);
        $this->mailcow()->mailboxWithDomain('in@inactive.example', domainActive: 0);
        $this->signInAs('alice', Role::Admin);

        $this->impersonate('mailboxUser', 'off@a.example')->assertCreated();
        $this->endImpersonation()->assertNoContent();
        $this->impersonate('mailboxUser', 'in@inactive.example')->assertCreated();
    }

    public function test_impersonations_cannot_be_nested(): void
    {
        $this->signInAs('alice', Role::Admin);
        $this->impersonate('domainAdmin', 'reseller1')->assertCreated();

        $this->impersonate('mailboxUser', 'bob@a.example')->assertConflict()->assertJsonPath('type', '/api/v2/problems/already-impersonating');

        $this->getJson('/api/v2/me')->assertOk()->assertJsonPath('data.username', 'reseller1');
    }

    public function test_legacy_sessions_are_never_written(): void
    {
        $before = $this->legacySessions();
        $this->signInAs('alice', Role::Admin);

        $this->impersonate('mailboxUser', 'bob@a.example')->assertCreated();
        $this->endImpersonation()->assertNoContent();

        $this->assertSame($before, $this->legacySessions());
    }

    /**
     * @return list<string>
     */
    private function legacySessions(): array
    {
        $keys = $this->redis()->keys('PHPREDIS_SESSION:*');
        sort($keys);

        return $keys;
    }

    public function test_invalid_bodies_are_refused_when_invalid(): void
    {
        $this->withoutRequestValidation();
        $this->signInAs('alice', Role::Admin);

        $this->postJson('/api/v2/session/impersonation', ['role' => 'admin', 'username' => 'x'], $this->csrf())->assertUnprocessable()->assertViolation('role', 'notInList');
        $this->postJson('/api/v2/session/impersonation', ['role' => 'mailboxUser'], $this->csrf())->assertUnprocessable()->assertViolation('username', 'required');
        $this->postJson('/api/v2/session/impersonation', ['role' => 'mailboxUser', 'username' => 'bob@a.example', 'x' => 1], $this->csrf())->assertUnprocessable()->assertViolation('x', 'unknownField');
        $this->assertSame(0, DB::table('restv2_audit_events')->where('event_type', 'impersonationStart')->where('outcome', 'success')->count());
    }
}
