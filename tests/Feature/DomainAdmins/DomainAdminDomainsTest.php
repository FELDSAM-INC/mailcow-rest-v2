<?php

namespace Tests\Feature\DomainAdmins;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T029 (AS US1-4, FR-023): a sent domain set replaces the whole set; assignments that
 * stay keep their row, creation time and status; new ones get the account's status.
 */
class DomainAdminDomainsTest extends TestCase
{
    use AccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['a.example', 'b.example', 'c.example'] as $domain) {
            $this->mailcow()->ensureDomain($domain);
        }

        $this->mailcow()->domainAdmin('reseller1', []);
        $this->mailcow()->assignmentOnly('reseller1', 'a.example', 1, '2026-01-01 10:00:00');
        $this->mailcow()->assignmentOnly('reseller1', 'b.example', 0, '2026-01-02 10:00:00');
        $this->asAdmin();
    }

    public function test_the_set_is_replaced_and_staying_rows_are_kept(): void
    {
        $b = DB::table('domain_admins')->where('username', 'reseller1')->where('domain', 'b.example')->first();

        $this->patchDomainAdmin('reseller1', ['domains' => ['b.example', 'C.example']])->assertOk()
            ->assertJsonPath('data.domains.0.domain', 'b.example')
            ->assertJsonPath('data.domains.0.active', false)
            ->assertJsonPath('data.domains.1.domain', 'c.example')
            ->assertJsonPath('data.domains.1.active', true);

        $after = DB::table('domain_admins')->where('username', 'reseller1')->where('domain', 'b.example')->first();
        $this->assertSame([$b->id, $b->created, 0], [$after->id, $after->created, (int) $after->active]);
        $this->assertFalse(DB::table('domain_admins')->where('username', 'reseller1')->where('domain', 'a.example')->exists());
        $this->assertSame(1, (int) DB::table('domain_admins')->where('username', 'reseller1')->where('domain', 'c.example')->value('active'));

        $record = $this->assertAudit('domainAdminUpdate', 'success', 'domainAdmin', 'reseller1', ['domainsAdded' => ['c.example'], 'domainsRemoved' => ['a.example']]);
        $this->assertArrayNotHasKey('changes', $record->details);
    }

    public function test_the_next_request_of_the_domain_administrator_sees_the_new_scope(): void
    {
        $session = $this->signInAs('reseller1', Role::DomainAdmin);
        $this->asAdmin();

        $this->patchDomainAdmin('reseller1', ['domains' => ['c.example', 'a.example']])->assertOk();

        $this->useSessionCookie($session);
        $this->getJson('/api/v2/me')->assertOk()->assertJsonPath('data.scope.domains', ['a.example', 'c.example']);
    }

    public function test_an_inactive_account_gets_inactive_new_assignments(): void
    {
        DB::table('admin')->where('username', 'reseller1')->update(['active' => 0]);

        $this->patchDomainAdmin('reseller1', ['domains' => ['a.example', 'c.example']])->assertOk();

        $this->assertAssignments('reseller1', [['a.example', 1], ['c.example', 0]]);
    }

    public function test_an_empty_set_is_refused_when_invalid(): void
    {
        $this->withoutRequestValidation();

        $this->patchDomainAdmin('reseller1', ['domains' => []])->assertUnprocessable()->assertViolation('domains', 'required');
        $this->assertSame(2, DB::table('domain_admins')->where('username', 'reseller1')->count());
    }

    public function test_one_invalid_entry_refuses_the_whole_request(): void
    {
        $this->patchDomainAdmin('reseller1', ['domains' => ['c.example', 'nope.example'], 'forcePasswordChange' => true])->assertUnprocessable()
            ->assertViolation('domains.1', 'notFound');

        $this->assertAssignments('reseller1', [['a.example', 1], ['b.example', 0]]);
        $this->assertAdminRow('reseller1', 0, 1);
    }

    public function test_assignments_to_names_that_are_not_primary_domains_are_dropped(): void
    {
        $this->mailcow()->assignmentOnly('reseller1', 'ALL');

        $this->patchDomainAdmin('reseller1', ['domains' => ['a.example', 'b.example']])->assertOk()->assertJsonCount(2, 'data.domains');

        $this->assertAssignments('reseller1', [['a.example', 1], ['b.example', 0]]);
        $this->assertAudit('domainAdminUpdate', 'success', 'domainAdmin', 'reseller1', ['domainsAdded' => [], 'domainsRemoved' => ['ALL']]);
    }

    public function test_assignments_may_be_sent_back_as_read(): void
    {
        $domains = $this->getJson('/api/v2/domain-admins/reseller1')->json('data.domains');

        $this->patchDomainAdmin('reseller1', ['domains' => [$domains[0], 'c.example']])->assertOk();
        $this->assertAssignments('reseller1', [['a.example', 1], ['c.example', 1]]);

        $changed = $domains[0];
        $changed['active'] = false;
        $changed['createdAt'] = '2020-01-01T00:00:00Z';
        $this->patchDomainAdmin('reseller1', ['domains' => [$changed]])->assertUnprocessable()
            ->assertViolation('domains.0.active', 'immutable')
            ->assertViolation('domains.0.createdAt', 'immutable');
        $this->patchDomainAdmin('reseller1', ['domains' => [['domain' => 'b.example', 'active' => true]]])->assertUnprocessable()
            ->assertViolation('domains.0.active', 'immutable');
    }
}
