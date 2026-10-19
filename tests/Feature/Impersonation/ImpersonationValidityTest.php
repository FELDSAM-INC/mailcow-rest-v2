<?php

namespace Tests\Feature\Impersonation;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T054 (AS US3-8, FR-052, research R17): an impersonation ends automatically when its
 * target is deleted, renamed or replaced, leaves the impersonator's scope, or the impersonating
 * domain administrator loses `login_as`; the detecting request is refused and not carried out,
 * except ending the impersonation and signing out, which complete normally.
 */
class ImpersonationValidityTest extends TestCase
{
    use AccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->impersonationCast();
    }

    /**
     * Starts the impersonation of a cause, invalidates it, and returns the expected reason.
     */
    private function invalidate(string $cause): string
    {
        $key = $this->apiKey('alice');

        if (in_array($cause, ['assignmentDeactivated', 'loginAsRevoked'], true)) {
            $this->signInAs('reseller1', Role::DomainAdmin);
            $this->impersonate('mailboxUser', 'bob@a.example')->assertCreated();
        } elseif ($cause === 'domainAdminDeleted') {
            $this->signInAs('alice', Role::Admin);
            $this->impersonate('domainAdmin', 'reseller1')->assertCreated();
        } else {
            $this->signInAs('alice', Role::Admin);
            $this->impersonate('mailboxUser', 'bob@a.example')->assertCreated();
        }

        $admin = $this->keyHeader($key);

        match ($cause) {
            'mailboxDeleted' => $this->deleteJson('/api/v2/mailboxes/bob@a.example', [], $admin)->assertNoContent(),
            'mailboxRowDeleted' => DB::table('mailbox')->where('username', 'bob@a.example')->delete(),
            'mailboxRecreated' => $this->recreateWithinTheSameSecond(),
            'domainAdminDeleted' => $this->deleteJson('/api/v2/domain-admins/reseller1', [], $admin)->assertNoContent(),
            'assignmentDeactivated' => DB::table('domain_admins')->where('username', 'reseller1')->update(['active' => 0]),
            'loginAsRevoked' => $this->patchMerge('/api/v2/domain-admins/reseller1', ['permissions' => ['loginAs' => false]], $admin)->assertOk(),
            default => throw new \LogicException($cause),
        };

        return match ($cause) {
            'assignmentDeactivated' => 'targetOutOfScope',
            'loginAsRevoked' => 'loginAsRevoked',
            default => 'targetDeletedOrRenamed',
        };
    }

    private function recreateWithinTheSameSecond(): void
    {
        $row = (array) DB::table('mailbox')->where('username', 'bob@a.example')->first();
        $this->deleteJson('/api/v2/mailboxes/bob@a.example', [], $this->keyHeader($this->apiKey('alice')))->assertNoContent();
        DB::table('mailbox')->insert($row);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function causes(): array
    {
        $causes = ['mailboxDeleted', 'mailboxRowDeleted', 'mailboxRecreated', 'domainAdminDeleted', 'assignmentDeactivated', 'loginAsRevoked'];

        return array_combine($causes, array_map(fn (string $c): array => [$c], $causes));
    }

    #[DataProvider('causes')]
    public function test_a_read_detects_it_and_is_refused(string $cause): void
    {
        $reason = $this->invalidate($cause);
        $impersonator = $cause === 'assignmentDeactivated' || $cause === 'loginAsRevoked' ? 'reseller1' : 'alice';
        $token = $this->csrfTokenWithoutRequest();

        $this->getJson('/api/v2/me')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/impersonation-ended');

        $this->getJson('/api/v2/me')->assertOk()->assertJsonPath('data.username', $impersonator);
        $this->assertNotSame($token, $this->csrfToken());
        $record = DB::table('restv2_audit_events')->where('event_type', 'impersonationEnd')->sole();
        $this->assertSame(['endedBy' => 'automatic', 'endReason' => $reason], array_intersect_key(json_decode((string) $record->details, true), ['endedBy' => 1, 'endReason' => 1]));
        $this->assertSame($impersonator, $record->actor_username);
    }

    #[DataProvider('causes')]
    public function test_a_write_that_detects_it_is_not_carried_out(string $cause): void
    {
        $this->invalidate($cause);
        $token = $this->csrfTokenWithoutRequest();

        $this->patchMerge('/api/v2/me/preferences', ['theme' => 'dark'], ['X-CSRF-Token' => $token])->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/impersonation-ended');

        $this->assertFalse(DB::table('restv2_preferences')->where('theme', 'dark')->exists());
        $this->assertSame(1, DB::table('restv2_audit_events')->where('event_type', 'impersonationEnd')->count());
    }

    public function test_a_deactivated_target_does_not_end_it(): void
    {
        $this->signInAs('alice', Role::Admin);
        $this->impersonate('mailboxUser', 'bob@a.example')->assertCreated();
        DB::table('mailbox')->where('username', 'bob@a.example')->update(['active' => 0]);
        DB::table('domain')->where('domain', 'a.example')->update(['active' => 0]);

        $this->getJson('/api/v2/me')->assertOk()->assertJsonPath('data.username', 'bob@a.example');
    }

    public function test_a_deactivated_impersonator_ends_the_whole_session(): void
    {
        $this->signInAs('alice', Role::Admin);
        $this->impersonate('mailboxUser', 'bob@a.example')->assertCreated();
        DB::table('admin')->where('username', 'alice')->update(['active' => 0]);

        $this->getJson('/api/v2/me')->assertUnauthorized();
    }

    public function test_ending_it_completes_normally_when_it_detects_it(): void
    {
        $this->invalidate('mailboxRowDeleted');

        $this->deleteJson('/api/v2/session/impersonation', [], ['X-CSRF-Token' => $this->csrfTokenWithoutRequest()])->assertNoContent();

        $this->assertAudit('impersonationEnd', 'success', 'impersonation', 'bob@a.example', ['endedBy' => 'automatic', 'endReason' => 'targetDeletedOrRenamed'], ['bob@a.example', 'mailboxUser']);
        $this->getJson('/api/v2/me')->assertOk()->assertJsonPath('data.username', 'alice');
    }

    public function test_signing_out_completes_normally_when_it_detects_it(): void
    {
        $this->invalidate('mailboxRowDeleted');

        $this->deleteJson('/api/v2/session', [], ['X-CSRF-Token' => $this->csrfTokenWithoutRequest()])->assertNoContent();

        $this->assertAudit('impersonationEnd', 'success', 'impersonation', 'bob@a.example', ['endedBy' => 'automatic', 'endReason' => 'targetDeletedOrRenamed'], ['bob@a.example', 'mailboxUser']);
        $this->assertSame(1, DB::table('restv2_audit_events')->where('event_type', 'signOut')->count());
        $this->getJson('/api/v2/me')->assertUnauthorized();
    }

    public function test_a_flag_change_of_the_impersonating_domain_administrator_applies_at_once(): void
    {
        $this->signInAs('reseller1', Role::DomainAdmin);
        $this->impersonate('mailboxUser', 'bob@a.example')->assertCreated();
        $this->getJson('/api/v2/me')->assertOk()->assertJsonPath('data.permissions.quarantine', true);

        DB::table('da_acl')->where('username', 'reseller1')->update(['quarantine' => 0]);

        $this->getJson('/api/v2/me')->assertOk()->assertJsonPath('data.permissions.quarantine', false);
    }

    /**
     * The current anti-forgery token, read from the session store without a request (a request
     * would already detect the invalid impersonation).
     */
    private function csrfTokenWithoutRequest(): string
    {
        $handler = $this->app['session']->driver()->getHandler();
        $data = json_decode((string) $handler->read((string) $this->sessionCookie()), true);

        return (string) ($data['_token'] ?? '');
    }
}
