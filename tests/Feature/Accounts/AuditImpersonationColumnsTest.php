<?php

namespace Tests\Feature\Accounts;

use App\Audit\AuditEventType;
use App\Auth\AccountKind;
use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T010 (FR-067, research R22): a record written for an impersonating principal names
 * the impersonator as the actor and the target as the impersonated account, by any feature.
 */
class AuditImpersonationColumnsTest extends TestCase
{
    use AccountTestHelpers;

    private function impersonating(): Principal
    {
        $alice = new Principal('alice', Role::Admin, AccountKind::Admin, AuthMethod::Password, CarbonImmutable::now('UTC'));

        return new Principal(
            username: 'bob@a.example',
            role: Role::MailboxUser,
            accountKind: AccountKind::Mailbox,
            authMethod: AuthMethod::Password,
            signedInAt: $alice->signedInAt,
            impersonator: $alice,
            impersonationId: '01JABCDEFGHJKMNPQRSTVWXYZ0',
            impersonationStartedAt: CarbonImmutable::now('UTC'),
        );
    }

    public function test_the_impersonator_is_the_actor_and_the_target_the_impersonated_account(): void
    {
        DB::transaction(fn () => $this->app->make(AuditLogger::class)->record(
            AuditEventType::PreferencesUpdate, true, $this->impersonating(), ['type' => 'preferences', 'id' => null, 'name' => null], 'password', null, ['changedFields' => ['theme']],
        ));

        $row = DB::table('restv2_audit_events')->where('event_type', 'preferencesUpdate')->sole();
        $this->assertSame(['alice', 'admin'], [$row->actor_username, $row->actor_role]);
        $this->assertSame(['bob@a.example', 'mailboxUser'], [$row->impersonated_username, $row->impersonated_role]);
    }

    public function test_a_record_outside_an_impersonation_has_no_impersonated_account(): void
    {
        $alice = new Principal('alice', Role::Admin, AccountKind::Admin, AuthMethod::Password);

        DB::transaction(fn () => $this->app->make(AuditLogger::class)->record(AuditEventType::SignOut, true, $alice, ['type' => 'session'], 'password', null, []));

        $row = DB::table('restv2_audit_events')->where('event_type', 'signOut')->sole();
        $this->assertSame(['alice', 'admin'], [$row->actor_username, $row->actor_role]);
        $this->assertNull($row->impersonated_username);
        $this->assertNull($row->impersonated_role);
    }

    public function test_the_audit_log_returns_the_impersonated_account(): void
    {
        $alice = new Principal('alice', Role::Admin, AccountKind::Admin, AuthMethod::Password);
        DB::transaction(function () use ($alice): void {
            $this->app->make(AuditLogger::class)->record(AuditEventType::PreferencesUpdate, true, $this->impersonating(), ['type' => 'preferences'], 'password', null, ['changedFields' => ['theme']]);
            $this->app->make(AuditLogger::class)->record(AuditEventType::SignOut, true, $alice, ['type' => 'session'], 'password', null, []);
        });
        $this->asAdmin();

        $events = collect((array) $this->getJson('/api/v2/audit-events')->assertOk()->json('data'))->keyBy('eventType');

        $this->assertSame(['username' => 'bob@a.example', 'role' => 'mailboxUser'], $events['preferencesUpdate']['impersonatedAccount']);
        $this->assertSame('alice', $events['preferencesUpdate']['actor']['username']);
        $this->assertNull($events['signOut']['impersonatedAccount']);
    }
}
