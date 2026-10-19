<?php

namespace Tests\Feature\Impersonation;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T057 (AS US3-2, FR-067, SC-005): every record written during an impersonation, by
 * any feature, names the impersonator as the actor and the target as the impersonated account.
 */
class ImpersonationAuditTest extends TestCase
{
    use AccountTestHelpers;

    public function test_changes_during_an_impersonation_name_both_accounts(): void
    {
        $this->impersonationCast();
        $this->signInAs('alice', Role::Admin);
        $this->impersonate('mailboxUser', 'bob@a.example')->assertCreated();
        $this->patchMerge('/api/v2/me/preferences', ['theme' => 'dark'], $this->csrf())->assertOk();
        $this->endImpersonation()->assertNoContent();

        $this->impersonate('domainAdmin', 'reseller1')->assertCreated();
        $this->patchMerge('/api/v2/mailboxes/carol@a.example', ['displayName' => 'Carol'], $this->csrf())->assertOk();
        $this->endImpersonation()->assertNoContent();

        $preference = DB::table('restv2_audit_events')->where('event_type', 'preferencesUpdate')->sole();
        $this->assertSame(['alice', 'admin', 'bob@a.example', 'mailboxUser'], [$preference->actor_username, $preference->actor_role, $preference->impersonated_username, $preference->impersonated_role]);
        $mailbox = DB::table('restv2_audit_events')->where('event_type', 'mailboxUpdate')->sole();
        $this->assertSame(['alice', 'admin', 'reseller1', 'domainAdmin'], [$mailbox->actor_username, $mailbox->actor_role, $mailbox->impersonated_username, $mailbox->impersonated_role]);

        $during = DB::table('restv2_audit_events')->whereIn('event_type', ['impersonationStart', 'impersonationEnd', 'preferencesUpdate', 'mailboxUpdate'])->get();
        $this->assertCount(6, $during);

        foreach ($during as $record) {
            $this->assertSame('alice', $record->actor_username);
            $this->assertNotNull($record->impersonated_username);
        }
    }
}
