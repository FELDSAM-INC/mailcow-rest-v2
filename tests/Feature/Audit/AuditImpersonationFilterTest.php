<?php

namespace Tests\Feature\Audit;

use App\Auth\Role;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T088 (AS US7-2, FR-069): the audit log filtered by impersonated account shows every
 * impersonation of it, by whom, and what was changed on its behalf.
 */
class AuditImpersonationFilterTest extends TestCase
{
    use AccountTestHelpers;

    public function test_the_records_of_an_impersonated_account(): void
    {
        $this->impersonationCast();

        foreach ([['alice', Role::Admin], ['reseller1', Role::DomainAdmin]] as [$impersonator, $role]) {
            $this->signInAs($impersonator, $role);
            $this->impersonate('mailboxUser', 'bob@a.example')->assertCreated();
            $this->patchMerge('/api/v2/me/preferences', ['theme' => $impersonator === 'alice' ? 'dark' : 'light'], $this->csrf())->assertOk();
            $this->endImpersonation()->assertNoContent();
        }

        $this->signInAs('alice', Role::Admin);
        $events = $this->getJson('/api/v2/audit-events?impersonatedAccount=Bob@A.example&order=asc')->assertOk()->json('data');

        $this->assertSame(
            [['impersonationStart', 'alice'], ['preferencesUpdate', 'alice'], ['impersonationEnd', 'alice'], ['impersonationStart', 'reseller1'], ['preferencesUpdate', 'reseller1'], ['impersonationEnd', 'reseller1']],
            array_map(fn (array $e): array => [$e['eventType'], $e['actor']['username']], $events),
        );

        foreach ($events as $event) {
            $this->assertSame(['username' => 'bob@a.example', 'role' => 'mailboxUser'], $event['impersonatedAccount']);
        }

        $this->assertSame([], $this->getJson('/api/v2/audit-events?impersonatedAccount=carol@a.example')->json('data'));
        $all = $this->getJson('/api/v2/audit-events?eventType=signIn&eventType=signOut')->json('data');
        $this->assertSame([], array_filter(array_column($all, 'impersonatedAccount')));
    }
}
