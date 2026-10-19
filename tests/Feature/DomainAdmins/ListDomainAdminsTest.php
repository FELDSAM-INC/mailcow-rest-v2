<?php

namespace Tests\Feature\DomainAdmins;

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T026 (AS US1-9, FR-010, FR-012, research R14): the domain administrator list with
 * paging, search, filters and sorts; every account is listed, also without assignments.
 */
class ListDomainAdminsTest extends TestCase
{
    use AccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['a.example', 'b.example', 'xn--bcher-kva.example'] as $domain) {
            $this->mailcow()->ensureDomain($domain);
        }

        $this->asAdmin();
    }

    /**
     * @return list<string>
     */
    private function usernames(string $query = ''): array
    {
        return array_column($this->getJson('/api/v2/domain-admins'.$query)->assertOk()->json('data'), 'username');
    }

    public function test_pages_carry_the_total_and_full_representations(): void
    {
        foreach (range(1, 30) as $i) {
            $this->mailcow()->domainAdmin(sprintf('r%02d', $i), ['a.example']);
        }

        $page = $this->getJson('/api/v2/domain-admins')->assertOk();
        $page->assertJsonPath('meta', ['total' => 30, 'limit' => 25, 'offset' => 0])->assertJsonCount(25, 'data');
        $this->getJson('/api/v2/domain-admins?limit=10&offset=25')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('data.0.username', 'r26');

        $single = $this->getJson('/api/v2/domain-admins/r01')->assertOk()->json('data');
        $this->assertSame($single, $page->json('data.0'));
    }

    public function test_the_page_size_is_limited_when_invalid(): void
    {
        $this->getJson('/api/v2/domain-admins?limit=1000')->assertOk();
        $this->withoutRequestValidation();
        $this->getJson('/api/v2/domain-admins?limit=1001')->assertUnprocessable()->assertViolation('limit', 'outOfRange');
    }

    public function test_accounts_without_assignments_and_with_non_domain_assignments_are_listed(): void
    {
        $this->mailcow()->orphanedDomainAdmin('lonely');
        $this->mailcow()->domainAdmin('odd', ['a.example']);
        $this->mailcow()->aliasDomain('alias.example', 'a.example');
        $this->mailcow()->assignmentOnly('odd', 'ALL');
        $this->mailcow()->assignmentOnly('odd', 'alias.example', 0);
        $this->mailcow()->assignmentOnly('odd', 'removed.example');
        $this->mailcow()->admin('admin2', 'x');

        $data = collect((array) $this->getJson('/api/v2/domain-admins')->assertOk()->json('data'))->keyBy('username');

        $this->assertSame(['lonely', 'odd'], $data->keys()->all());
        $this->assertSame([], $data['lonely']['domains']);
        $domains = collect((array) $data['odd']['domains'])->keyBy('domain');
        $this->assertTrue($domains['a.example']['domainExists']);
        $this->assertFalse($domains['ALL']['domainExists']);
        $this->assertFalse($domains['alias.example']['domainExists']);
        $this->assertFalse($domains['alias.example']['active']);
        $this->assertFalse($domains['removed.example']['domainExists']);
    }

    public function test_the_search_matches_usernames_and_domain_names_in_both_forms(): void
    {
        $this->mailcow()->domainAdmin('anna', ['a.example']);
        $this->mailcow()->domainAdmin('books', ['xn--bcher-kva.example']);
        $this->mailcow()->domainAdmin('per_cent', ['b.example']);

        $this->assertSame(['anna'], $this->usernames('?q=ANN'));
        $this->assertSame(['per_cent'], $this->usernames('?q=b.exam'));
        $this->assertSame(['books'], $this->usernames('?q=b%C3%BCcher'));
        $this->assertSame(['books'], $this->usernames('?q=xn--bcher'));
        $this->assertSame(['per_cent'], $this->usernames('?q=_cent'));
        $this->assertSame([], $this->usernames('?q=%25'));
    }

    public function test_the_filters(): void
    {
        $this->mailcow()->domainAdmin('active1', ['a.example']);
        $this->mailcow()->domainAdmin('inactive1', ['b.example'], active: 0, attributes: ['force_pw_update' => '1']);
        $this->mailcow()->domainAdmin('noacl', ['a.example', 'b.example'], withDaAcl: false, attributes: ['force_tfa' => 1]);
        $this->mailcow()->domainAdmin('flags', ['xn--bcher-kva.example'], ['login_as' => 0]);
        $this->mailcow()->tfaTotp('active1', 'S', 'Phone', 1);
        $this->mailcow()->tfaTotp('inactive1', 'S', 'Phone', 0);
        $this->mailcow()->tfaU2f('flags');

        $this->assertSame(['inactive1'], $this->usernames('?active=false'));
        $this->assertSame(['active1', 'flags', 'noacl'], $this->usernames('?active=true'));
        $this->assertSame(['active1'], $this->usernames('?secondFactorStatus=active'));
        $this->assertSame(['inactive1'], $this->usernames('?secondFactorStatus=suspended'));
        $this->assertSame(['flags', 'noacl'], $this->usernames('?secondFactorStatus=none'));
        $this->assertSame(['inactive1'], $this->usernames('?forcePasswordChange=true'));
        $this->assertSame(['noacl'], $this->usernames('?forceSecondFactorSetup=true'));
        $this->assertSame(['active1', 'noacl'], $this->usernames('?domain=A.example'));
        $this->assertSame(['flags', 'inactive1', 'noacl'], $this->usernames('?domain=b.example&domain=b%C3%BCcher.example'));
        $this->assertSame(['active1', 'inactive1'], $this->usernames('?permission=loginAs'));
        $this->assertSame(['flags', 'noacl'], $this->usernames('?permission=loginAs&permissionValue=false'));
    }

    public function test_a_permission_value_needs_a_permission_when_invalid(): void
    {
        $this->withoutRequestValidation();

        $this->getJson('/api/v2/domain-admins?permissionValue=true')->assertUnprocessable()->assertViolation('permission', 'required');
        $this->getJson('/api/v2/domain-admins?permission=nope')->assertUnprocessable()->assertViolation('permission', 'notInList');
        $this->getJson('/api/v2/domain-admins?active=1')->assertUnprocessable()->assertViolation('active', 'invalidType');
    }

    public function test_the_sorts_break_ties_by_username(): void
    {
        $this->mailcow()->domainAdmin('c', ['a.example', 'b.example', 'xn--bcher-kva.example'], created: '2026-01-03 00:00:00');
        $this->mailcow()->domainAdmin('a', ['a.example'], active: 0, created: '2026-01-02 00:00:00');
        $this->mailcow()->domainAdmin('b', ['a.example', 'b.example'], created: '2026-01-02 00:00:00');
        $this->mailcow()->domainAdmin('d', ['a.example'], created: '2026-01-01 00:00:00');
        DB::table('admin')->where('username', 'b')->update(['modified' => '2026-02-01 00:00:00']);

        $this->assertSame(['a', 'b', 'c', 'd'], $this->usernames());
        $this->assertSame(['d', 'c', 'b', 'a'], $this->usernames('?order=desc'));
        $this->assertSame(['a', 'b', 'c', 'd'], $this->usernames('?sort=active'));
        $this->assertSame(['d', 'a', 'b', 'c'], $this->usernames('?sort=createdAt'));
        $this->assertSame(['c', 'a', 'b', 'd'], $this->usernames('?sort=createdAt&order=desc'));
        $this->assertSame(['a', 'c', 'd', 'b'], $this->usernames('?sort=modifiedAt'));
        $this->assertSame(['a', 'd', 'b', 'c'], $this->usernames('?sort=domainCount'));
        $this->assertSame(['c', 'b', 'a', 'd'], $this->usernames('?sort=domainCount&order=desc'));
    }
}
